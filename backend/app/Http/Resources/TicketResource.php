<?php

namespace App\Http\Resources;

use App\Services\HospitalBookingPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $booking = $this->booking;
        $patient = $booking->patientProfile;
        $phone = $patient->phone;

        return [
            'id' => $this->id, 'booking_id' => $booking->id, 'ticket_code' => $this->ticket_code,
            'queue_number' => $this->queue_number, 'status' => $this->status,
            'qr_token' => $this->status === 'issued' && now()->lt($this->expires_at) ? $this->qr_token : null,
            'appointment_date' => $this->appointment_date->toDateString(),
            'shift' => HospitalBookingPolicy::shift($booking->slot),
            'estimated_time' => substr($booking->slot->start_time, 0, 5),
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'room_number' => $this->room_number,
            'instructions' => $this->instructions,
            'hospital' => ['id' => $booking->hospital_id, 'name' => $booking->hospital?->name, 'address' => $booking->hospital?->address],
            'specialty' => $this->department->specialty->name,
            'patient' => [
                'full_name' => $patient->full_name, 'birth_year' => $patient->dob?->year,
                'national_id' => $patient->national_id ? substr($patient->national_id, 0, 3).'******'.substr($patient->national_id, -3) : null,
                'gender' => $patient->gender,
                'phone' => strlen($phone ?? '') > 6 ? substr($phone, 0, 3).'****'.substr($phone, -3) : '****',
            ],
            'payment' => $booking->payment?->only(['exam_fee', 'service_fee', 'total_amount', 'status']),
        ];
    }
}
