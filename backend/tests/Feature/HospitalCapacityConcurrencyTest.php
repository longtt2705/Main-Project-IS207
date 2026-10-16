<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ExamType;
use App\Models\Hospital;
use App\Models\PatientProfile;
use App\Models\Slot;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HospitalCapacityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_waiting_reservation_sees_capacity_committed_in_another_slot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression requires InnoDB REPEATABLE READ.');
        }
        $hospital = Hospital::factory()->create();
        $exam = ExamType::factory()->for($hospital)->create();
        $exam->department->update(['max_shift_capacity' => 1]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstProfile = PatientProfile::factory()->create(['user_id' => $firstUser->id]);
        $secondProfile = PatientProfile::factory()->create(['user_id' => $secondUser->id]);
        $firstSlot = Slot::factory()->forHospital($hospital, 10)->create(['start_time' => '08:00:00', 'end_time' => '08:30:00']);
        $secondSlot = Slot::factory()->forHospital($hospital, 10)->create(['work_date' => $firstSlot->work_date, 'start_time' => '09:00:00', 'end_time' => '09:30:00']);
        $data = ['hospital_id' => $hospital->id, 'exam_type_id' => $exam->id, 'patient_profile_id' => $secondProfile->id, 'slot_id' => $secondSlot->id];

        $script = tempnam(sys_get_temp_dir(), 'medsi-capacity-');
        file_put_contents($script, <<<'PHP'
<?php
$input = json_decode(stream_get_contents(STDIN), true);
require $input['base'].'/vendor/autoload.php';
$app = require $input['base'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['database']]);
Illuminate\Support\Facades\DB::purge('mysql');
Illuminate\Support\Carbon::setTestNow($input['now']);
Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) {
    if (str_contains($sql, 'from `hospitals`') && str_contains($sql, 'for update')) {
        echo "WAITING_FOR_HOSPITAL\n";
        flush();
    }
});
try {
    app(App\Services\BookingService::class)->createHospitalBooking($input['user'], $input['data']);
    echo 'OVERBOOKED';
    exit(2);
} catch (App\Exceptions\Booking\SlotUnavailableException $e) {
    echo $e->getMessage();
}
PHP);
        $process = new Process([PHP_BINARY, $script], base_path(), timeout: 15);
        $process->setInput(json_encode(['base' => base_path(), 'database' => config('database.connections.mysql'), 'now' => now()->toIso8601String(), 'user' => $secondUser->id, 'data' => $data]));
        try {
            DB::beginTransaction();
            Hospital::whereKey($hospital->id)->lockForUpdate()->firstOrFail();
            $process->start();
            // The signal occurs before the blocking hospital SELECT, after any earlier reads.
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'WAITING_FOR_HOSPITAL')));
            app(BookingService::class)->createHospitalBooking($firstUser->id, array_replace($data, ['patient_profile_id' => $firstProfile->id, 'slot_id' => $firstSlot->id]));
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertStringContainsString('Ca khám đã hết chỗ', $process->getOutput());
            $this->assertSame(1, Booking::count());
            $this->assertSame(0, $secondSlot->fresh()->booked_count);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $process->stop();
            unlink($script);
        }
    }
}
