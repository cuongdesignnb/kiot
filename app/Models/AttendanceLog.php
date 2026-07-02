<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_device_id',
        'employee_id',
        'device_user_id',
        'punched_at',
        'event_type',
        'raw',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
        'raw' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
