<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimekeepingInterval extends Model
{
    use HasFactory;

    protected $fillable = [
        'timekeeping_record_id',
        'employee_work_schedule_id',
        'employee_id',
        'work_date',
        'slot',
        'scheduled_start_at',
        'scheduled_end_at',
        'check_in_at',
        'check_out_at',
        'worked_minutes',
        'source',
        'status',
        'raw',
    ];

    protected $casts = [
        'work_date' => 'date',
        'slot' => 'integer',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'worked_minutes' => 'integer',
        'raw' => 'array',
    ];

    public function record()
    {
        return $this->belongsTo(TimekeepingRecord::class, 'timekeeping_record_id');
    }

    public function schedule()
    {
        return $this->belongsTo(EmployeeWorkSchedule::class, 'employee_work_schedule_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
