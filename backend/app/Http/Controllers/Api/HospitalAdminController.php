<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\HospitalDepartment;
use App\Models\Slot;
use App\Services\HospitalBookingPolicy;
use App\Services\HospitalCancellationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HospitalAdminController extends Controller
{
    public function createSlot(Request $request, Hospital $hospital)
    {
        $this->authorizeHospital($request, $hospital->id);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $slot = new Slot($data + [
            'owner_type' => 'hospital', 'owner_id' => $hospital->id,
            'end_time' => Carbon::parse($data['start_time'])->addMinutes(30)->format('H:i:s'),
            'booked_count' => 0, 'status' => 'available',
        ]);
        HospitalBookingPolicy::validateSlot($slot);
        DB::transaction(function () use ($hospital, $slot) {
            Hospital::whereKey($hospital->id)->lockForUpdate()->firstOrFail();
            $overlaps = $hospital->slots()->whereDate('work_date', $slot->work_date)
                ->where('start_time', '<', $slot->end_time)->where('end_time', '>', $slot->start_time)->exists();
            if ($overlaps) {
                throw new \DomainException('Khung giờ đã tồn tại hoặc chồng lấn lịch khác.');
            }
            $slot->save();
        });

        return response()->json(['success' => true, 'data' => $slot], 201);
    }

    public function department(Request $request, HospitalDepartment $department)
    {
        $this->authorizeHospital($request, $department->hospital_id);
        $data = $request->validate([
            'max_shift_capacity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            'room_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        DB::transaction(function () use ($department, $data) {
            Hospital::whereKey($department->hospital_id)->lockForUpdate()->firstOrFail();
            $department->update($data);
        });

        return response()->json(['success' => true, 'data' => $department->fresh()]);
    }

    public function availability(Request $request, Hospital $hospital, HospitalCancellationService $cancellations)
    {
        $this->authorizeHospital($request, $hospital->id);
        $data = $request->validate(['accepting_bookings' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($hospital, $data) {
            Hospital::whereKey($hospital->id)->lockForUpdate()->firstOrFail()->update(['accepting_bookings' => $data['accepting_bookings']]);
        });
        $count = 0;
        if (! $data['accepting_bookings']) {
            $hospital->bookings()->whereIn('status', ['pending_payment', 'confirmed'])
                ->whereHas('slot', fn ($q) => $q->whereDate('work_date', '>=', today()))
                ->chunkById(100, function ($bookings) use ($cancellations, $data, &$count) {
                    foreach ($bookings as $booking) {
                        $result = $cancellations->cancel($booking, $data['reason'], true);
                        $count += (int) ($result->status === 'cancelled');
                    }
                });
        }

        return response()->json(['success' => true, 'data' => ['cancelled_count' => $count, 'accepting_bookings' => (bool) $data['accepting_bookings']]]);
    }

    private function authorizeHospital(Request $request, int $hospitalId): void
    {
        abort_unless($request->user()->role === 'admin' || ($request->user()->role === 'hospital_admin'
            && (int) $request->user()->hospital_id === $hospitalId), 403);
    }
}
