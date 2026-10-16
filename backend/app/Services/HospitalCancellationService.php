<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payment\SlotHoldService;
use DomainException;
use Illuminate\Support\Facades\DB;

class HospitalCancellationService
{
    public function cancel(Booking $reference, string $reason, bool $byHospital = false): Booking
    {
        return DB::transaction(function () use ($reference, $reason, $byHospital) {
            $booking = app(SlotHoldService::class)->lockBooking($reference);
            if ($booking->booking_type !== 'hospital') {
                throw new DomainException('Chức năng này dành cho lịch khám bệnh viện.');
            }
            if ($booking->status === 'cancelled') {
                return $booking;
            }
            if ($byHospital && in_array($booking->status, ['checked_in', 'completed', 'no_show'], true)) {
                return $booking;
            }
            if (! in_array($booking->status, ['pending_payment', 'confirmed'], true)) {
                throw new DomainException('Không thể hủy lịch đã check-in hoặc đã kết thúc.');
            }
            if (! $byHospital && $booking->status === 'confirmed'
                && now()->gt(HospitalBookingPolicy::shiftStart($booking->slot)->subHours(24))) {
                throw new DomainException('Còn dưới 24 giờ trước ca khám. Vui lòng liên hệ trực tiếp bệnh viện.');
            }
            $payment = Payment::where('booking_id', $booking->id)->lockForUpdate()->first();
            if ($payment?->isPaid()) {
                // Durable handoff to PAY, not a claim that a bank refund has completed.
                DB::table('refund_requests')->insertOrIgnore([
                    'payment_id' => $payment->id,
                    'amount' => (int) ($byHospital ? $payment->total_amount : $payment->exam_fee),
                    'reason' => $reason ?: ($byHospital ? 'Bệnh viện ngừng tiếp nhận' : 'Bệnh nhân hủy trước 24 giờ'),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ($payment?->isPending()) {
                $payment->update(['status' => PaymentStatus::CANCELLED]);
            }
            $booking->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'slot_hold_expires_at' => null]);
            $booking->ticket()->update(['status' => 'cancelled']);
            $slot = $booking->slot;
            $slot->booked_count = max(0, $slot->booked_count - 1);
            if ($slot->status === 'full' && $slot->booked_count < $slot->capacity) {
                $slot->status = 'available';
            }
            $slot->save();

            return $booking;
        }, 3);
    }
}
