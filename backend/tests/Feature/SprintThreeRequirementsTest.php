<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ExamType;
use App\Models\Hospital;
use App\Models\PatientProfile;
use App\Models\Slot;
use App\Models\User;
use App\Services\BookingService;
use App\Services\HospitalBookingPolicy;
use App\Services\PaymentService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SprintThreeRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;

    private ExamType $exam;

    private Slot $slot;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(7, 0));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'payment.demo_gateway.enabled' => true]);
        $this->patient = User::factory()->create();
        Sanctum::actingAs($this->patient);
        $hospital = Hospital::factory()->create();
        $this->exam = ExamType::factory()->for($hospital)->create(['price' => 750000]);
        $this->exam->department->update(['instructions' => 'Mang theo sổ tiêm chủng.', 'room_number' => 'Phòng 204']);
        $profile = PatientProfile::factory()->create(['user_id' => $this->patient->id]);
        $this->slot = Slot::factory()->forHospital($hospital, 20)->create([
            'work_date' => today()->addDays(2), 'start_time' => '08:00:00', 'end_time' => '08:30:00',
        ]);
        $this->data = ['hospital_id' => $hospital->id, 'exam_type_id' => $this->exam->id, 'patient_profile_id' => $profile->id, 'slot_id' => $this->slot->id];
    }

    private function book(array $changes = []): Booking
    {
        return app(BookingService::class)->createHospitalBooking($this->patient->id, array_replace($this->data, $changes));
    }

    private function paid(array $changes = []): Booking
    {
        $booking = $this->book($changes);
        app(PaymentService::class)->processPayment($booking->payment->id, $this->patient->id);

        return $booking->fresh(['ticket', 'payment', 'slot']);
    }

    public static function invalidSchedules(): array
    {
        return [[0, '08:00:00', '08:30:00'], [31, '08:00:00', '08:30:00'],
            [2, '12:00:00', '12:30:00'], [2, '16:30:00', '17:00:00'],
            [2, '08:15:00', '08:45:00'], [2, '08:00:00', '09:00:00']];
    }

    #[DataProvider('invalidSchedules')]
    public function test_date_window_shifts_and_thirty_minute_slots_are_enforced(int $days, string $start, string $end): void
    {
        $this->slot->update(['work_date' => today()->addDays($days), 'start_time' => $start, 'end_time' => $end]);
        $this->postJson('/api/bookings/hospital', $this->data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame(0, $this->slot->fresh()->booked_count);
        $this->getJson('/api/hospitals/'.$this->data['hospital_id'])->assertOk()->assertJsonCount(0, 'data.slots');
    }

    public function test_day_one_and_day_thirty_are_valid_and_fees_and_hold_are_server_owned(): void
    {
        foreach ([1, 30] as $days) {
            $this->slot->update(['work_date' => today()->addDays($days)]);
            $booking = $this->book(['hold_minutes' => 999, 'total_amount' => 1, 'doctor_id' => 999]);
            $this->assertNull($booking->doctor_id);
            $this->assertTrue($booking->slot_hold_expires_at->equalTo(now()->addMinutes(15)));
            $this->assertEquals(200000, $booking->payment->exam_fee);
            $this->assertEquals(20000, $booking->payment->service_fee);
            $this->assertEquals(220000, $booking->payment->total_amount);
            $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertOk();
        }
    }

    public function test_duplicate_specialty_on_different_slots_and_services_is_rejected(): void
    {
        $this->book();
        $anotherExam = ExamType::factory()->create(['hospital_id' => $this->data['hospital_id'], 'hospital_department_id' => $this->exam->hospital_department_id]);
        $anotherSlot = $this->slot->replicate();
        $anotherSlot->start_time = '14:00:00';
        $anotherSlot->end_time = '14:30:00';
        $anotherSlot->save();
        $this->postJson('/api/bookings/hospital', array_replace($this->data, ['slot_id' => $anotherSlot->id, 'exam_type_id' => $anotherExam->id]))->assertStatus(409);
    }

    public function test_shift_capacity_applies_across_slots_and_patients(): void
    {
        $this->exam->department->update(['max_shift_capacity' => 1]);
        $this->book();
        $this->getJson('/api/hospitals/'.$this->data['hospital_id'])->assertOk()
            ->assertJsonPath('data.shift_availability.0.remaining', 0);
        $other = PatientProfile::factory()->create(['user_id' => $this->patient->id]);
        $slot = $this->slot->replicate();
        $slot->start_time = '09:00:00';
        $slot->end_time = '09:30:00';
        $slot->save();
        $this->postJson('/api/bookings/hospital', array_replace($this->data, ['slot_id' => $slot->id, 'patient_profile_id' => $other->id]))
            ->assertUnprocessable()->assertJsonPath('message', 'Ca khám đã hết chỗ. Vui lòng chọn ca hoặc ngày khác.');
        $slot->update(['start_time' => '13:00:00', 'end_time' => '13:30:00']);
        $this->postJson('/api/bookings/hospital', array_replace($this->data, ['slot_id' => $slot->id, 'patient_profile_id' => $other->id]))->assertCreated();
    }

    public function test_three_upcoming_confirmed_bookings_prevent_more_holds(): void
    {
        foreach (range(1, 3) as $i) {
            $profile = PatientProfile::factory()->create(['user_id' => $this->patient->id]);
            $this->paid(['patient_profile_id' => $profile->id]);
        }
        $this->postJson('/api/bookings/hospital', $this->data)->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_expiry_command_releases_holds_once_without_another_customer_request(): void
    {
        $booking = $this->book();
        $this->travelTo($booking->slot_hold_expires_at);
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, $this->slot->fresh()->booked_count);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_multiple_holds_cannot_bypass_three_confirmed_limit_at_payment(): void
    {
        $holds = [];
        foreach (range(1, 4) as $i) {
            $profile = PatientProfile::factory()->create(['user_id' => $this->patient->id]);
            $holds[] = $this->book(['patient_profile_id' => $profile->id]);
        }
        foreach (array_slice($holds, 0, 3) as $booking) {
            app(PaymentService::class)->processPayment($booking->payment->id, $this->patient->id);
        }
        $this->postJson('/api/payments/process', ['payment_id' => $holds[3]->payment->id])
            ->assertStatus(409);
        $this->assertSame('pending_payment', $holds[3]->fresh()->status);
        $this->assertSame('pending', $holds[3]->payment->fresh()->status->value);
        $this->assertDatabaseCount('tickets', 3);
    }

    public function test_payment_rechecks_hospital_availability_after_reservation(): void
    {
        $booking = $this->book();
        $booking->hospital->update(['accepting_bookings' => false]);
        $this->postJson('/api/payments/process', ['payment_id' => $booking->payment->id])
            ->assertStatus(409);
        $this->assertSame('pending_payment', $booking->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_historical_ticket_retains_hospital_after_catalog_soft_deletion(): void
    {
        $booking = $this->paid();
        $name = $booking->hospital->name;
        $booking->hospital->delete();
        $booking->examType->delete();
        $this->getJson('/api/bookings/'.$booking->id.'/ticket')->assertOk()
            ->assertJsonPath('data.hospital.name', $name);
    }

    public function test_optional_national_id_is_validated_and_masked_on_ticket(): void
    {
        $url = '/api/patient-profiles/'.$this->data['patient_profile_id'];
        $this->patchJson($url, ['national_id' => '123'])->assertUnprocessable();
        $this->patchJson($url, ['national_id' => '000000000001'])->assertOk();
        $booking = $this->paid();
        $this->getJson('/api/bookings/'.$booking->id.'/ticket')->assertOk()
            ->assertJsonPath('data.patient.national_id', '000******001')
            ->assertDontSee('000000000001');
    }

    public function test_payment_atomically_issues_one_signed_ticket_with_instructions(): void
    {
        $booking = $this->book();
        $this->assertDatabaseCount('tickets', 0);
        app(PaymentService::class)->processPayment($booking->payment->id, $this->patient->id);
        app(PaymentService::class)->processPayment($booking->payment->id, $this->patient->id);
        $ticket = $booking->ticket()->firstOrFail();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertMatchesRegularExpression('/^TKT-20261018-[A-HJ-NP-Z2-9]{6}$/', $ticket->ticket_code);
        $this->assertStringEndsWith('-001', $ticket->queue_number);
        $payload = app(TicketService::class)->verify($ticket->qr_token);
        $this->assertSame($ticket->ticket_code, $payload['tid']);
        $this->assertSame($this->data['hospital_id'], $payload['fid']);
        $this->getJson('/api/bookings/'.$booking->id.'/ticket')->assertOk()
            ->assertJsonPath('data.room_number', 'Phòng 204')->assertJsonPath('data.status', 'issued');
        $this->assertStringContainsString('sổ tiêm chủng', $ticket->instructions);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/bookings/'.$booking->id.'/ticket')->assertNotFound();
        $this->getJson('/api/bookings?q='.$ticket->ticket_code)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_patient_cancellation_at_exactly_twenty_four_hours_refunds_exam_fee_and_invalidates_qr(): void
    {
        $booking = $this->paid();
        $this->travelTo(HospitalBookingPolicy::shiftStart($booking->slot)->subHours(24));
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel', ['reason' => 'Đổi kế hoạch'])->assertOk();
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertOk();
        $this->assertSame(0, $this->slot->fresh()->booked_count);
        $this->assertDatabaseHas('refund_requests', ['payment_id' => $booking->payment->id, 'amount' => 200000, 'status' => 'pending']);
        $this->assertDatabaseCount('refund_requests', 1);
        $this->getJson('/api/bookings/'.$booking->id.'/ticket')->assertOk()->assertJsonPath('data.qr_token', null);
        $this->travelTo($booking->slot->work_date->copy()->setTime(8, 0));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/tickets/check-in', ['qr_token' => $booking->ticket->qr_token])->assertUnprocessable();
    }

    public function test_cancellation_under_twenty_four_hours_is_rejected_from_shift_start(): void
    {
        $booking = $this->paid();
        $this->travelTo(HospitalBookingPolicy::shiftStart($booking->slot)->subHours(24)->addSecond());
        $this->patchJson('/api/bookings/'.$booking->id.'/cancel')->assertUnprocessable();
        $this->assertDatabaseCount('refund_requests', 0);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_hospital_closure_refunds_all_fees_and_blocks_new_bookings(): void
    {
        $booking = $this->paid();
        $url = '/api/hospitals/'.$this->data['hospital_id'].'/availability';
        $data = ['accepting_bookings' => false, 'reason' => 'Ngừng tiếp nhận'];
        $this->putJson($url, $data)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'hospital_admin', 'hospital_id' => $this->data['hospital_id']]));
        $this->putJson($url, $data)->assertOk()->assertJsonPath('data.cancelled_count', 1);
        $this->assertDatabaseHas('refund_requests', ['payment_id' => $booking->payment->id, 'amount' => 220000]);
        $this->assertSame('cancelled', $booking->ticket->fresh()->status);
        Sanctum::actingAs($this->patient);
        $this->postJson('/api/bookings/hospital', $this->data)->assertUnprocessable();
    }

    public function test_queue_numbers_are_never_reused_and_reset_by_appointment_day(): void
    {
        $first = $this->paid();
        $this->patchJson('/api/bookings/'.$first->id.'/cancel')->assertOk();
        $second = $this->paid();
        $this->assertStringEndsWith('-002', $second->ticket->queue_number);
        $otherSlot = $this->slot->replicate();
        $otherSlot->work_date = today()->addDays(3);
        $otherSlot->save();
        $third = $this->paid(['slot_id' => $otherSlot->id]);
        $this->assertStringEndsWith('-001', $third->ticket->queue_number);
    }

    public function test_check_in_requires_staff_correct_hospital_valid_signature_and_correct_date(): void
    {
        $booking = $this->paid();
        $token = $booking->ticket->qr_token;
        $this->postJson('/api/tickets/check-in', ['qr_token' => $token])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'staff', 'hospital_id' => Hospital::factory()->create()->id]));
        $this->postJson('/api/tickets/check-in', ['qr_token' => $token])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'staff', 'hospital_id' => $this->data['hospital_id']]));
        $this->postJson('/api/tickets/check-in', ['qr_token' => $token])->assertUnprocessable();
        $this->travelTo($booking->slot->work_date->copy()->setTime(8, 0));
        $this->postJson('/api/tickets/check-in', ['qr_token' => substr($token, 0, -2).'xx'])->assertUnprocessable();
        $this->postJson('/api/tickets/check-in', ['qr_token' => $token])->assertOk()->assertJsonPath('data.status', 'checked_in');
        $firstTime = $booking->ticket->fresh()->check_in_at;
        $this->postJson('/api/tickets/check-in', ['qr_token' => $token])->assertUnprocessable();
        $this->assertTrue($booking->ticket->fresh()->check_in_at->equalTo($firstTime));
        $this->assertSame('checked_in', $booking->fresh()->status);
        $this->postJson('/api/tickets/'.$booking->ticket->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('completed', $booking->fresh()->status);
    }

    public function test_no_show_after_shift_end_plus_two_hours_and_history_groups_and_ticket_search(): void
    {
        $booking = $this->paid();
        $this->getJson('/api/bookings?status=upcoming&q='.$booking->ticket->ticket_code)->assertOk()->assertJsonCount(1, 'data');
        $this->travelTo($booking->ticket->expires_at->copy()->subSecond());
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('issued', $booking->ticket->fresh()->status);
        $this->travel(1)->seconds();
        $this->artisan('bookings:expire')->assertSuccessful();
        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('expired', $booking->ticket->fresh()->status);
        $this->assertDatabaseCount('refund_requests', 0);
        $this->getJson('/api/bookings?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/bookings?status=upcoming')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_department_settings_require_authorized_hospital_admin(): void
    {
        $url = '/api/hospital-departments/'.$this->exam->hospital_department_id;
        $this->patchJson($url, ['max_shift_capacity' => 2])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson($url, ['max_shift_capacity' => 0])->assertUnprocessable();
        $this->patchJson($url, ['max_shift_capacity' => 2])->assertOk();
        $this->assertSame(2, $this->exam->department->fresh()->max_shift_capacity);
    }

    public function test_admin_can_create_valid_slots_but_not_overlaps_or_same_day_slots(): void
    {
        $url = '/api/hospitals/'.$this->data['hospital_id'].'/slots';
        $data = ['work_date' => today()->addDays(2)->toDateString(), 'start_time' => '09:00', 'capacity' => 10];
        $this->postJson($url, $data)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('data.end_time', '09:30:00');
        $this->postJson($url, $data)->assertUnprocessable();
        $this->postJson($url, array_replace($data, ['work_date' => today()->toDateString()]))->assertUnprocessable();
    }

    public function test_existing_paid_bookings_can_be_backfilled_idempotently(): void
    {
        $booking = $this->book();
        $booking->update(['status' => 'confirmed']);
        $booking->payment->update(['status' => 'paid']);
        $this->artisan('tickets:issue-missing')->assertSuccessful();
        $this->artisan('tickets:issue-missing')->assertSuccessful();
        $this->assertDatabaseCount('tickets', 1);
    }
}
