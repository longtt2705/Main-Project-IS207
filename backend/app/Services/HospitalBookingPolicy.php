<?php

namespace App\Services;

use App\Models\Slot;
use Carbon\Carbon;
use DomainException;

class HospitalBookingPolicy
{
    public const EXAM_FEE = 200000;

    public const SERVICE_FEE = 20000;

    public static function shift(Slot $slot): string
    {
        $start = substr($slot->start_time, 0, 5);
        $end = substr($slot->end_time, 0, 5);
        if ($start >= '07:00' && $start < '11:30' && $end <= '11:30') {
            return 'morning';
        }
        if ($start >= '13:00' && $start < '16:30' && $end <= '16:30') {
            return 'afternoon';
        }
        throw new DomainException('Khung giờ phải thuộc ca sáng 07:00–11:30 hoặc ca chiều 13:00–16:30.');
    }

    public static function shiftStart(Slot $slot): Carbon
    {
        return $slot->work_date->copy()->setTimeFromTimeString(self::shift($slot) === 'morning' ? '07:00' : '13:00');
    }

    public static function expiresAt(Slot $slot): Carbon
    {
        return $slot->work_date->copy()->setTimeFromTimeString(self::shift($slot) === 'morning' ? '11:30' : '16:30')->addMinutes(120);
    }

    public static function validateSlot(Slot $slot): void
    {
        if (! $slot->work_date || $slot->work_date->lt(today()->addDay()) || $slot->work_date->gt(today()->addDays(30))) {
            throw new DomainException('Vui lòng chọn ngày khám từ ngày mai đến 30 ngày tới.');
        }
        self::shift($slot);
        $start = $slot->work_date->copy()->setTimeFromTimeString($slot->start_time);
        $end = $slot->work_date->copy()->setTimeFromTimeString($slot->end_time);
        if (! in_array($start->minute, [0, 30], true) || $start->second !== 0 || $start->diffInMinutes($end, false) !== 30.0) {
            throw new DomainException('Khung giờ tiếp nhận phải dài 30 phút, bắt đầu vào phút 00 hoặc 30.');
        }
    }
}
