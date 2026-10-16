<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', fn (Blueprint $table) => $table->boolean('accepting_bookings')->default(true));
        Schema::create('hospital_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('max_shift_capacity')->default(50);
            $table->string('room_number', 50)->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
            $table->unique(['hospital_id', 'specialty_id']);
        });
        Schema::table('exam_types', function (Blueprint $table) {
            $table->foreignId('hospital_department_id')->nullable()->constrained()->restrictOnDelete();
        });
        // Preserve existing services; each becomes a department until an administrator groups them.
        foreach (DB::table('exam_types')->get() as $exam) {
            $specialtyId = DB::table('specialties')->where('name', $exam->name)->value('id');
            $specialtyId ??= DB::table('specialties')->insertGetId([
                'name' => $exam->name, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('hospital_departments')->insertOrIgnore([
                'hospital_id' => $exam->hospital_id, 'specialty_id' => $specialtyId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $departmentId = DB::table('hospital_departments')->where('hospital_id', $exam->hospital_id)
                ->where('specialty_id', $specialtyId)->value('id');
            DB::table('exam_types')->where('id', $exam->id)->update(['hospital_department_id' => $departmentId]);
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('patient')->change();
            $table->foreignId('hospital_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_department_id')->constrained()->restrictOnDelete();
            $table->date('appointment_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['hospital_department_id', 'appointment_date']);
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('hospital_department_id')->constrained()->restrictOnDelete();
            $table->date('appointment_date');
            $table->string('ticket_code', 32)->unique();
            $table->text('qr_token');
            $table->string('queue_number', 20);
            $table->string('room_number', 50)->nullable();
            $table->string('status', 20)->default('issued');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('expires_at');
            $table->text('instructions');
            $table->timestamps();
            $table->unique(['hospital_department_id', 'appointment_date', 'queue_number'], 'tickets_queue_unique');
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('hospital_id'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['cancel_reason', 'cancelled_at']));
        Schema::table('exam_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('hospital_department_id'));
        Schema::dropIfExists('hospital_departments');
        Schema::table('hospitals', fn (Blueprint $table) => $table->dropColumn('accepting_bookings'));
    }
};
