<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'attendance_code',
        'name',
        'phone',
        'email',
        'cccd',
        'branch_id',
        'department_id',
        'job_title_id',
        'is_active',
        'balance',
        'salary_balance_cache',
        'salary_balance_calculated_at',
        'notes',
        'avatar',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:2',
        'salary_balance_cache' => 'integer',
        'salary_balance_calculated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle()
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function schedules()
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function timekeepingRecords()
    {
        return $this->hasMany(TimekeepingRecord::class);
    }

    public function salarySetting()
    {
        return $this->hasOne(EmployeeSalarySetting::class);
    }

    public function salaryLedgerEntries()
    {
        return $this->hasMany(EmployeeSalaryLedgerEntry::class);
    }

    public function salaryAdvances()
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'assigned_employee_id');
    }

    public function taskAssignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    /**
     * @deprecated Use tasks() instead
     */
    public function deviceRepairs()
    {
        return $this->tasks();
    }

    /**
     * Tính tổng số công trong khoảng ngày
     */
    public function getWorkUnitsForRange($from, $to): float
    {
        return $this->timekeepingRecords()
            ->whereBetween('work_date', [$from, $to])
            ->where('attendance_type', 'work')
            ->sum('work_units');
    }

    /**
     * Tính lương dự kiến theo chấm công + mẫu lương
     */
    public function calculateSalaryForRange($from, $to): array
    {
        $service = new \App\Services\SalaryCalculationService;

        return $service->calculateForEmployee($this, \Carbon\Carbon::parse($from), \Carbon\Carbon::parse($to));
    }
}
