<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimekeepingRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'employee_work_schedule_id',
        'branch_id',
        'shift_id',
        'work_date',
        'slot',
        'scheduled_start_at',
        'scheduled_end_at',
        'check_in_at',
        'check_out_at',
        'source',
        'attendance_type',
        'manual_override',
        'late_minutes',
        'early_minutes',
        'ot_minutes',
        'worked_minutes',
        'work_units',
        'is_holiday',
        'holiday_multiplier',
        'raw',
        'notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'manual_override' => 'boolean',
        'is_holiday' => 'boolean',
        'work_units' => 'decimal:2',
        'holiday_multiplier' => 'decimal:2',
        'raw' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function schedule()
    {
        return $this->belongsTo(EmployeeWorkSchedule::class, 'employee_work_schedule_id');
    }
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
