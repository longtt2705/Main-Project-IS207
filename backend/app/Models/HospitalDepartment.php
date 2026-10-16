<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HospitalDepartment extends Model
{
    protected $fillable = ['hospital_id', 'specialty_id', 'max_shift_capacity', 'room_number', 'instructions'];

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }
}
