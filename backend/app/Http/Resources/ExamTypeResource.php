<?php

namespace App\Http\Resources;

use App\Services\HospitalBookingPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hospital_id' => $this->hospital_id,
            'name' => $this->name,
            'price' => HospitalBookingPolicy::EXAM_FEE,
            'service_fee' => HospitalBookingPolicy::SERVICE_FEE,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id, 'specialty_id' => $this->department->specialty_id,
                'name' => $this->department->specialty->name, 'max_shift_capacity' => $this->department->max_shift_capacity,
                'instructions' => $this->department->instructions, 'room_number' => $this->department->room_number,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
