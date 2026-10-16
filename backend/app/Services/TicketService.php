<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Ticket;
use App\Services\Payment\SlotHoldService;
use DomainException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /** Upgrade path for paid, confirmed appointments created before tickets existed. */
    public function issueMissing(): int
    {
        $count = 0;
        Booking::where('booking_type', 'hospital')->where('status', 'confirmed')
            ->whereHas('payment', fn ($q) => $q->where('status', 'paid'))->whereDoesntHave('ticket')
            ->chunkById(100, function ($bookings) use (&$count) {
                foreach ($bookings as $reference) {
                    $count += DB::transaction(function () use ($reference) {
                        $booking = app(SlotHoldService::class)->lockBooking($reference);
                        if ($booking->status !== 'confirmed' || $booking->ticket()->exists()) {
                            return 0;
                        }
                        $this->issue($booking);

                        return 1;
                    }, 3);
                }
            });

        return $count;
    }

    /** Caller holds the booking locks inside the payment transaction. */
    public function issue(Booking $booking): Ticket
    {
        if ($booking->booking_type !== 'hospital' || $booking->status !== 'confirmed' || ! $booking->payment?->isPaid()) {
            throw new DomainException('Phiếu chỉ được phát hành sau khi xác nhận thanh toán.');
        }
        if ($ticket = Ticket::where('booking_id', $booking->id)->lockForUpdate()->first()) {
            return $ticket;
        }
        $department = $booking->examType->department;
        if (! $department || (int) $department->hospital_id !== (int) $booking->hospital_id) {
            throw new DomainException('Dịch vụ chưa được cấu hình chuyên khoa bệnh viện.');
        }
        $date = $booking->slot->work_date->toDateString();
        DB::table('ticket_sequences')->insertOrIgnore([
            'hospital_department_id' => $department->id, 'appointment_date' => $date, 'last_number' => 0,
        ]);
        $sequence = DB::table('ticket_sequences')->where('hospital_department_id', $department->id)
            ->where('appointment_date', $date)->lockForUpdate()->first();
        $number = $sequence->last_number + 1;
        DB::table('ticket_sequences')->where('id', $sequence->id)->update(['last_number' => $number]);
        do {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = 'TKT-'.$booking->slot->work_date->format('Ymd').'-'.$suffix;
        } while (Ticket::where('ticket_code', $code)->exists());

        $expiry = HospitalBookingPolicy::expiresAt($booking->slot);
        $payload = [
            'tid' => $code, 'fid' => $booking->hospital_id, 'spid' => $department->specialty_id,
            'date' => $date, 'iat' => now()->timestamp, 'exp' => $expiry->timestamp,
        ];
        $header = $this->encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $header.'.'.$this->encode(json_encode($payload));
        $token = $body.'.'.$this->encode(hash_hmac('sha256', $body, $this->key(), true));

        return Ticket::create([
            'booking_id' => $booking->id, 'hospital_department_id' => $department->id,
            'appointment_date' => $date, 'ticket_code' => $code, 'qr_token' => $token,
            'queue_number' => 'CK'.str_pad($department->specialty_id, 2, '0', STR_PAD_LEFT).'-'.str_pad($number, 3, '0', STR_PAD_LEFT),
            'room_number' => $department->room_number, 'status' => 'issued', 'expires_at' => $expiry,
            'instructions' => trim('Có mặt trước giờ tiếp nhận 15 phút. Mang CCCD hoặc giấy tờ tùy thân gốc. '.($department->instructions ?? '')),
        ]);
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new DomainException('Mã QR không hợp lệ.');
        }
        [$header, $body, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $header.'.'.$body, $this->key(), true));
        $algorithm = json_decode(base64_decode(strtr($header, '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        if (! hash_equals($expected, $signature) || ($algorithm['alg'] ?? null) !== 'HS256'
            || ! is_array($payload) || ! isset($payload['tid'], $payload['date'], $payload['exp'])
            || $payload['exp'] <= now()->timestamp) {
            throw new DomainException('Mã QR không hợp lệ hoặc đã hết hạn.');
        }

        return $payload;
    }

    public function checkIn(Ticket $reference, string $token): Ticket
    {
        $payload = $this->verify($token);

        return DB::transaction(function () use ($reference, $token, $payload) {
            $booking = app(SlotHoldService::class)->lockBooking($reference->booking);
            $ticket = Ticket::whereKey($reference->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($ticket->qr_token, $token) || $payload['tid'] !== $ticket->ticket_code) {
                throw new DomainException('Mã QR không khớp phiếu khám.');
            }
            if ($ticket->status === 'checked_in') {
                throw new DomainException('Phiếu đã check-in lúc '.$ticket->check_in_at->format('d/m/Y H:i:s').'.');
            }
            if ($ticket->status !== 'issued' || $booking->status !== 'confirmed' || now()->gte($ticket->expires_at)) {
                throw new DomainException('Phiếu đã hủy, hoàn tất hoặc hết hạn.');
            }
            if ($ticket->appointment_date->toDateString() !== today()->toDateString()) {
                throw new DomainException('Không đúng ngày hẹn. Vui lòng liên hệ quầy tiếp đón.');
            }
            $ticket->update(['status' => 'checked_in', 'check_in_at' => now()]);
            $booking->update(['status' => 'checked_in']);

            return $ticket;
        }, 3);
    }

    public function complete(Ticket $reference): Ticket
    {
        return DB::transaction(function () use ($reference) {
            $booking = app(SlotHoldService::class)->lockBooking($reference->booking);
            $ticket = Ticket::whereKey($reference->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'checked_in' || $booking->status !== 'checked_in') {
                throw new DomainException('Chỉ có thể hoàn tất phiếu đã check-in.');
            }
            $ticket->update(['status' => 'completed']);
            $booking->update(['status' => 'completed']);

            return $ticket;
        }, 3);
    }

    public function expireUnused(): int
    {
        $count = 0;
        Ticket::where('status', 'issued')->where('expires_at', '<=', now())->chunkById(100, function ($tickets) use (&$count) {
            foreach ($tickets as $reference) {
                $count += DB::transaction(function () use ($reference) {
                    $booking = app(SlotHoldService::class)->lockBooking($reference->booking);
                    $ticket = Ticket::whereKey($reference->id)->lockForUpdate()->firstOrFail();
                    if ($ticket->status !== 'issued' || $booking->status !== 'confirmed') {
                        return 0;
                    }
                    $ticket->update(['status' => 'expired']);
                    $booking->update(['status' => 'no_show']);

                    return 1;
                }, 3);
            }
        });

        return $count;
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \LogicException('APP_KEY is required to sign tickets.');
        }

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
