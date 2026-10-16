<?php

namespace App\Services;

use App\Exceptions\Hospital\HospitalNotFoundException;
use App\Models\Hospital;
use App\Services\Payment\SlotHoldService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class HospitalService
{
    /**
     * Tìm kiếm và lọc danh sách bệnh viện theo từ khóa hoặc thành phố (đồng nhất pattern với M4).
     *
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters = []): LengthAwarePaginator
    {
        $query = Hospital::query()->with('examTypes.department.specialty');

        $keyword = $filters['keyword'] ?? $filters['q'] ?? null;
        if (! empty($keyword)) {
            $query->search($keyword);
        }

        $city = $filters['city'] ?? null;
        if (! empty($city)) {
            $query->inCity($city);
        }

        $perPage = (int) ($filters['per_page'] ?? 10);

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Lấy thông tin chi tiết của bệnh viện kèm các loại dịch vụ khám và khung giờ trống khả dụng.
     *
     *
     * @throws HospitalNotFoundException
     */
    public function getDetail(int $id): Hospital
    {
        app(SlotHoldService::class)->expireOverdueHolds();
        $hospital = Hospital::with([
            'examTypes.department.specialty',
            'slots' => function ($query) {
                $query->where('status', 'available')
                    ->whereColumn('booked_count', '<', 'capacity')
                    ->whereBetween('work_date', [today()->addDay()->toDateString(), today()->addDays(30)->toDateString()])
                    ->orderBy('work_date')
                    ->orderBy('start_time');
            },
        ])->find($id);

        if (! $hospital) {
            throw new HospitalNotFoundException;
        }

        $hospital->setRelation('slots', $hospital->slots->filter(function ($slot) use ($hospital) {
            if (! $hospital->accepting_bookings) {
                return false;
            }
            try {
                HospitalBookingPolicy::validateSlot($slot);

                return true;
            } catch (\DomainException) {
                return false;
            }
        })->values());

        $active = $hospital->bookings()->with(['slot', 'examType'])
            ->whereNotIn('status', ['cancelled', 'rejected', 'no_show'])
            ->where(fn ($q) => $q->where('status', '!=', 'pending_payment')->orWhere('slot_hold_expires_at', '>', now()))
            ->whereHas('slot', fn ($q) => $q->whereBetween('work_date', [today()->addDay(), today()->addDays(30)]))->get();
        $availability = [];
        foreach ($hospital->examTypes->pluck('department')->filter()->unique('id') as $department) {
            foreach ($hospital->slots as $slot) {
                $shift = HospitalBookingPolicy::shift($slot);
                $date = $slot->work_date->toDateString();
                $key = $department->id.':'.$date.':'.$shift;
                if (isset($availability[$key])) {
                    continue;
                }
                $used = $active->filter(fn ($booking) => $booking->examType?->hospital_department_id === $department->id
                    && $booking->slot->work_date->toDateString() === $date
                    && (substr($booking->slot->start_time, 0, 2) < '12' ? 'morning' : 'afternoon') === $shift)->count();
                $availability[$key] = ['department_id' => $department->id, 'date' => $date, 'shift' => $shift,
                    'remaining' => max(0, $department->max_shift_capacity - $used)];
            }
        }
        $hospital->setAttribute('shift_availability', array_values($availability));

        return $hospital;
    }
}
