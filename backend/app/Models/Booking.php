<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'patient_profile_id',
        'booking_type',
        'doctor_id',
        'hospital_id',
        'exam_type_id',
        'slot_id',
        'symptoms',
        'status',
        'slot_hold_expires_at',
        'note',
        'cancel_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_hold_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if ($booking->code) {
                return;
            }

            $booking->code = 'BK'.now()->format('Ymd').strtoupper(bin2hex(random_bytes(5)));
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patientProfile()
    {
        return $this->belongsTo(PatientProfile::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class)->withTrashed();
    }

    public function examType()
    {
        return $this->belongsTo(ExamType::class)->withTrashed();
    }

    public function slot()
    {
        return $this->belongsTo(Slot::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Scope a query to only include bookings for a given user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get booking list by user_id.
     */
    public static function getByUserId(int $userId)
    {
        return static::where('user_id', $userId)->get();
    }
}
