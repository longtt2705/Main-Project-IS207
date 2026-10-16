<?php

namespace App\Services;

use App\Exceptions\Booking\BookingAlreadyExistsException;
use App\Exceptions\Booking\BookingCannotBeCancelledException;
use App\Exceptions\Booking\BookingNotFoundException;
use App\Exceptions\Booking\ExamTypeMismatchException;
use App\Exceptions\Booking\InvalidSlotException;
use App\Exceptions\Booking\PatientProfileNotFoundException;
use App\Exceptions\Booking\SlotUnavailableException;
use App\Exceptions\Hospital\HospitalNotFoundException;
use App\Models\Booking;
use App\Models\ExamType;
use App\Models\Hospital;
use App\Models\PatientProfile;
use App\Models\Payment;
use App\Models\Slot;
use App\Models\User;
use App\Services\Payment\SlotHoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BookingService
{
    // Constants định danh trạng thái và loại booking
    public const TYPE_HOSPITAL = 'hospital';

    public const TYPE_DOCTOR = 'doctor';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const SLOT_AVAILABLE = 'available';

    public const SLOT_FULL = 'full';

    public const SLOT_BLOCKED = 'blocked';

    /**
     * Lấy danh sách lịch sử booking của một user có phân trang và bộ lọc.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getUserBookings(
        int $userId,
        array $filters = []
    ): LengthAwarePaginator {
        app(SlotHoldService::class)->expireOverdueHolds();
        app(TicketService::class)->expireUnused();
        $query = Booking::query()
            ->forUser($userId)
            ->with([
                'patientProfile',
                'doctor.specialty',
                'hospital',
                'examType',
                'slot',
                'payment',
                'ticket',
            ]);

        $status = $filters['status'] ?? null;
        if ($status === 'upcoming') {
            $query->whereIn('status', ['pending', 'pending_payment', 'confirmed', 'checked_in']);
        } elseif ($status === 'cancelled') {
            $query->whereIn('status', ['cancelled', 'rejected', 'no_show']);
        } elseif (! empty($status) && $status !== 'all') {
            $query->where('status', $status);
        }

        $keyword = $filters['keyword'] ?? $filters['q'] ?? null;
        if (! empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                    ->orWhereHas('ticket', fn ($t) => $t->where('ticket_code', 'like', "%{$keyword}%"))
                    ->orWhereHas('patientProfile', function ($pQuery) use ($keyword) {
                        $pQuery->where('full_name', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('doctor', function ($dQuery) use ($keyword) {
                        $dQuery->where('name', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('hospital', function ($hQuery) use ($keyword) {
                        $hQuery->where('name', 'like', "%{$keyword}%");
                    });
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Tạo lịch khám tại bệnh viện với Pessimistic Locking, DB Transaction và giữ chỗ thanh toán.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PatientProfileNotFoundException
     * @throws HospitalNotFoundException
     * @throws ExamTypeMismatchException
     * @throws InvalidSlotException
     * @throws SlotUnavailableException
     * @throws BookingAlreadyExistsException
     */
    public function createHospitalBooking(int $userId, array $data): Booking
    {
        return DB::transaction(function () use ($userId, $data) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            // Acquire the hospital lock before any snapshot read (InnoDB REPEATABLE READ).
            // 2. Kiểm tra bệnh viện tồn tại
            $hospital = Hospital::whereKey($data['hospital_id'])->lockForUpdate()->first();
            if (! $hospital) {
                throw new HospitalNotFoundException;
            }
            if (! $hospital->accepting_bookings) {
                throw new SlotUnavailableException('Bệnh viện đang ngừng tiếp nhận đặt khám.');
            }

            // Serialize bookings across slots for account and department/shift limits.
            // 1. Kiểm tra hồ sơ bệnh nhân phải thuộc tài khoản user hiện tại
            $patientProfile = PatientProfile::where('id', $data['patient_profile_id'])
                ->where('user_id', $userId)
                ->first();

            if (! $patientProfile) {
                throw new PatientProfileNotFoundException;
            }

            // 3. Kiểm tra loại hình dịch vụ khám phải thuộc về bệnh viện này
            $examType = ExamType::where('id', $data['exam_type_id'])
                ->where('hospital_id', $hospital->id)
                ->first();

            if (! $examType) {
                throw new ExamTypeMismatchException;
            }

            // 4. Khóa bi quan dòng slot:
            $slot = Slot::where('id', $data['slot_id'])
                ->where('owner_type', self::TYPE_HOSPITAL)
                ->where('owner_id', $data['hospital_id'])
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw new InvalidSlotException;
            }

            // Tự động kiểm tra và giải phóng giữ chỗ hết hạn khi có người khác thử đặt
            app(SlotHoldService::class)->releaseOverdueHoldsForSlot($slot);
            $slot->refresh();

            try {
                HospitalBookingPolicy::validateSlot($slot);
            } catch (\DomainException $e) {
                throw new SlotUnavailableException($e->getMessage());
            }
            $this->validateHospitalLimits($userId, $patientProfile->id, $examType, $slot);

            // Kiểm tra trạng thái và sức chứa của slot
            if ($slot->booked_count >= $slot->capacity || $slot->status !== self::SLOT_AVAILABLE) {
                throw new SlotUnavailableException;
            }

            // Kiểm tra ngày khám không được ở trong quá khứ
            if (! $slot->work_date || now()->greaterThanOrEqualTo(
                $slot->work_date->copy()->setTimeFromTimeString($slot->start_time)
            )) {
                throw new SlotUnavailableException('Khung giờ khám đã qua hạn đặt');
            }

            // 5. Xử lý dứt điểm Bug P0 (Re-book an toàn + Chống race condition):
            // Khóa dòng và kiểm tra booking active
            $existingBooking = Booking::where('patient_profile_id', $data['patient_profile_id'])
                ->where('slot_id', $slot->id)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->lockForUpdate()
                ->first();

            if ($existingBooking) {
                if (
                    $existingBooking->status === self::STATUS_PENDING_PAYMENT
                    && $existingBooking->slot_hold_expires_at
                    && now()->greaterThanOrEqualTo($existingBooking->slot_hold_expires_at)
                ) {
                    app(SlotHoldService::class)->releaseIfOverdue(
                        app(SlotHoldService::class)->lockBooking($existingBooking)
                    );
                    $slot->refresh();
                } else {
                    throw new BookingAlreadyExistsException('Hồ sơ này đã có lịch hẹn active cho khung giờ này.');
                }
            }

            // 6. Tính thời hạn giữ chỗ tạm thời (mặc định 15 phút)
            $holdMinutes = 15;
            $slotHoldExpiresAt = now()->addMinutes($holdMinutes);

            // 7. Tạo bản ghi Booking
            $booking = Booking::create([
                'user_id' => $userId,
                'patient_profile_id' => $patientProfile->id,
                'booking_type' => self::TYPE_HOSPITAL,
                'doctor_id' => null,
                'hospital_id' => $hospital->id,
                'exam_type_id' => $examType->id,
                'slot_id' => $slot->id,
                'symptoms' => $data['symptoms'] ?? null,
                'status' => self::STATUS_PENDING_PAYMENT,
                'slot_hold_expires_at' => $slotHoldExpiresAt,
                'note' => $data['note'] ?? null,
            ]);

            // 8. Tăng $slot->increment('booked_count'), cập nhật full nếu chạm capacity
            $slot->increment('booked_count');
            if ($slot->booked_count >= $slot->capacity) {
                $slot->update(['status' => self::SLOT_FULL]);
            }

            // 9. Snapshot the agreed Sprint 3 hospital fees at reservation.
            $paymentMethod = $data['payment_method'] ?? 'qr_pay';
            Payment::create([
                'booking_id' => $booking->id,
                'method' => $paymentMethod,
                'exam_fee' => HospitalBookingPolicy::EXAM_FEE,
                'service_fee' => HospitalBookingPolicy::SERVICE_FEE,
                'total_amount' => HospitalBookingPolicy::EXAM_FEE + HospitalBookingPolicy::SERVICE_FEE,
                'status' => 'pending',
            ]);

            return $booking->load(['patientProfile', 'hospital', 'examType', 'slot', 'payment']);
        });
    }

    private function validateHospitalLimits(int $userId, int $profileId, ExamType $exam, Slot $slot): void
    {
        $department = $exam->department;
        if (! $department || (int) $department->hospital_id !== (int) $exam->hospital_id) {
            throw new SlotUnavailableException('Dịch vụ chưa được cấu hình chuyên khoa bệnh viện.');
        }
        $upcoming = Booking::where('user_id', $userId)->where('status', 'confirmed')
            ->whereHas('slot', fn ($q) => $q->whereDate('work_date', '>', today())
                ->orWhere(fn ($q) => $q->whereDate('work_date', today())->where('start_time', '>', now()->format('H:i:s'))))->count();
        if ($upcoming >= 3) {
            throw new SlotUnavailableException('Tài khoản đã có 3 lịch khám xác nhận chưa diễn ra.');
        }
        $active = Booking::whereNotIn('status', ['cancelled', 'rejected', 'no_show'])
            ->where(fn ($q) => $q->where('status', '!=', 'pending_payment')->orWhere('slot_hold_expires_at', '>', now()));
        $sameSpecialty = (clone $active)->where('patient_profile_id', $profileId)
            ->whereHas('slot', fn ($q) => $q->whereDate('work_date', $slot->work_date))
            ->where(fn ($q) => $q->whereHas('examType.department', fn ($q) => $q->where('specialty_id', $department->specialty_id))
                ->orWhereHas('doctor', fn ($q) => $q->where('specialty_id', $department->specialty_id)))->exists();
        if ($sameSpecialty) {
            throw new BookingAlreadyExistsException('Hồ sơ đã có lịch cùng chuyên khoa trong ngày này.');
        }
        $morning = HospitalBookingPolicy::shift($slot) === 'morning';
        $count = (clone $active)->where('hospital_id', $exam->hospital_id)
            ->whereHas('examType', fn ($q) => $q->where('hospital_department_id', $department->id))
            ->whereHas('slot', fn ($q) => $q->whereDate('work_date', $slot->work_date)
                ->whereBetween('start_time', $morning ? ['07:00:00', '11:29:59'] : ['13:00:00', '16:29:59']))->count();
        if ($count >= $department->max_shift_capacity) {
            throw new SlotUnavailableException('Ca khám đã hết chỗ. Vui lòng chọn ca hoặc ngày khác.');
        }
    }

    /**
     * Tạo lịch khám với bác sĩ kèm Pessimistic Locking và DB Transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PatientProfileNotFoundException
     * @throws SlotUnavailableException
     * @throws BookingAlreadyExistsException
     */
    public function createDoctorBooking(array $data, int $userId): Booking
    {
        return DB::transaction(function () use ($data, $userId) {
            $slotId = $data['slot_id'] ?? null;
            if (! $slotId) {
                throw new SlotUnavailableException('Khung giờ khám không hợp lệ.');
            }

            // 1. Kiểm tra hồ sơ bệnh nhân: tồn tại và thuộc đúng user hiện tại
            $patientProfileId = $data['patient_profile_id'] ?? null;
            if (! $patientProfileId) {
                throw new PatientProfileNotFoundException('Vui lòng chọn hồ sơ người khám.');
            }

            $patientProfile = PatientProfile::where('id', $patientProfileId)
                ->where('user_id', $userId)
                ->first();

            if (! $patientProfile) {
                throw new PatientProfileNotFoundException;
            }

            // 2. Khóa bi quan dòng slot (Pessimistic Locking):
            $slot = Slot::where('id', $slotId)
                ->where('owner_type', self::TYPE_DOCTOR)
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                throw new SlotUnavailableException('Khung giờ khám không tồn tại.');
            }

            // Kiểm tra tính nhất quán nếu client có gửi doctor_id lên
            if (! empty($data['doctor_id']) && (int) $data['doctor_id'] !== (int) $slot->owner_id) {
                throw new SlotUnavailableException('Khung giờ khám không thuộc về bác sĩ đã chọn.');
            }

            // Kiểm tra ngày khám không được ở trong quá khứ
            if ($slot->work_date && $slot->work_date->isPast() && ! $slot->work_date->isToday()) {
                throw new SlotUnavailableException('Khung giờ khám đã qua hạn đặt');
            }

            // 3. Kiểm tra booking trùng theo (patient_profile_id, slot_id)
            $existingBooking = Booking::where('patient_profile_id', $patientProfile->id)
                ->where('slot_id', $slot->id)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->lockForUpdate()
                ->first();

            if ($existingBooking) {
                throw new BookingAlreadyExistsException('Hồ sơ này đã có lịch hẹn active cho khung giờ này.');
            }

            // 4. Kiểm tra sức chứa và trạng thái khả dụng của slot
            if ($slot->status !== self::SLOT_AVAILABLE || $slot->booked_count >= $slot->capacity) {
                throw new SlotUnavailableException;
            }

            // 5. doctor_id: bắt buộc lấy từ slot (owner_id) vì slot owner_type = doctor
            $doctorId = $slot->owner_id;

            // 6. Tạo record Booking mới (mã code do Model booted tự sinh)
            $booking = Booking::create([
                'user_id' => $userId,
                'patient_profile_id' => $patientProfile->id,
                'booking_type' => self::TYPE_DOCTOR,
                'doctor_id' => $doctorId,
                'hospital_id' => null,
                'exam_type_id' => null,
                'slot_id' => $slot->id,
                'symptoms' => $data['symptoms'] ?? null,
                'status' => self::STATUS_PENDING,
                'note' => $data['note'] ?? null,
            ]);

            // 7. Tăng booked_count của slot lên 1, cập nhật full nếu đạt capacity
            $slot->increment('booked_count');
            if ($slot->booked_count >= $slot->capacity) {
                $slot->update(['status' => self::SLOT_FULL]);
            }

            return $booking->load(['patientProfile', 'doctor.specialty', 'slot']);
        });
    }

    /**
     * Hủy lịch khám với bác sĩ của user trong DB Transaction.
     *
     *
     * @throws BookingNotFoundException
     * @throws AuthorizationException
     * @throws BookingCannotBeCancelledException
     */
    public function cancelDoctorBooking(
        int $bookingId,
        int $userId,
        ?string $reason = null
    ): Booking {
        return DB::transaction(function () use ($bookingId, $userId, $reason) {
            // 1. Tìm booking
            $booking = Booking::lockForUpdate()->find($bookingId);

            if (! $booking) {
                throw new BookingNotFoundException;
            }

            // 2. Kiểm tra booking phải thuộc đúng user_id hiện tại (403 nếu không thuộc)
            if ((int) $booking->user_id !== $userId) {
                throw new AuthorizationException('Bạn không có quyền hủy phiếu khám này.');
            }

            // 3. Kiểm tra loại booking phải là doctor
            if ($booking->booking_type !== self::TYPE_DOCTOR) {
                throw new BookingCannotBeCancelledException('Phiếu khám không phải lịch khám với bác sĩ.');
            }

            // 4. Chỉ cho phép hủy nếu status hiện tại là pending (422 nếu là confirmed, completed, cancelled...)
            if ($booking->status !== self::STATUS_PENDING) {
                throw new BookingCannotBeCancelledException('Chỉ có thể hủy phiếu khám ở trạng thái chờ duyệt (pending).');
            }

            // 5. Cập nhật status = cancelled (và cancel_reason nếu tồn tại trong schema)
            $booking->status = self::STATUS_CANCELLED;
            if (Schema::hasColumn('bookings', 'cancel_reason')) {
                $booking->cancel_reason = $reason;
            }
            $booking->save();

            // 6. Lấy slot tương ứng và giảm booked_count xuống 1 (tránh bị âm)
            if ($booking->slot_id) {
                $slot = Slot::where('id', $booking->slot_id)->lockForUpdate()->first();
                if ($slot) {
                    if ($slot->booked_count > 0) {
                        $slot->decrement('booked_count');
                    }

                    // 7. Nếu slot đang full và sau khi giảm còn chỗ thì chuyển về available
                    if ($slot->status === self::SLOT_FULL && $slot->booked_count < $slot->capacity) {
                        $slot->update(['status' => self::SLOT_AVAILABLE]);
                    }
                }
            }

            return $booking;
        });
    }

    /**
     * Lấy thông tin chi tiết một lịch khám của user kèm các quan hệ liên quan.
     *
     *
     * @throws BookingNotFoundException
     * @throws AuthorizationException
     */
    public function getBookingDetail(
        int $bookingId,
        int $userId
    ): Booking {
        $booking = Booking::with([
            'patientProfile',
            'doctor.specialty',
            'hospital',
            'examType',
            'slot',
            'payment',
        ])
            ->where('id', $bookingId)
            ->where('user_id', $userId)
            ->first();

        if (! $booking) {
            // Kiểm tra xem booking có tồn tại trong hệ thống (nhưng thuộc user khác) để trả 403
            if (Booking::where('id', $bookingId)->exists()) {
                throw new AuthorizationException('Bạn không có quyền xem thông tin phiếu khám này.');
            }

            throw new BookingNotFoundException;
        }

        return $booking;
    }
}
