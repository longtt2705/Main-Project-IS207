<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['appointment_date' => 'date', 'check_in_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function department()
    {
        return $this->belongsTo(HospitalDepartment::class, 'hospital_department_id');
    }
}
