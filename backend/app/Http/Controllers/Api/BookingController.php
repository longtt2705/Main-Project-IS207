<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\HospitalCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $booking = $this->bookingService->getBookingDetail($id, $request->user()->id);

        return response()->json(['success' => true, 'data' => new BookingResource($booking->load('ticket'))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $booking = Booking::where('user_id', $request->user()->id)->findOrFail($id);
        $result = $booking->booking_type === 'hospital'
            ? app(HospitalCancellationService::class)->cancel($booking, $data['reason'] ?? '')
            : $this->bookingService->cancelDoctorBooking($id, $request->user()->id, $data['reason'] ?? null);

        return response()->json(['success' => true, 'message' => 'Đã hủy lịch khám.', 'data' => $result]);
    }

    public function __construct(
        protected BookingService $bookingService
    ) {}

    /**
     * Lấy danh sách lịch khám của user đang đăng nhập.
     *
     * GET /api/bookings
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $filters = $request->only(['q', 'keyword', 'status', 'per_page']);

        $bookings = $this->bookingService->getUserBookings($userId, $filters);

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách lịch khám thành công',
            'data' => BookingResource::collection($bookings->items()),
            'pagination' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }
}
