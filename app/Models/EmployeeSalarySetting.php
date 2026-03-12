<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSalarySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'salary_template_id',
        'base_salary',
        'salary_type',
        'advanced_salary',
        'holiday_rate',
        'tet_rate',
        'has_overtime',
        'overtime_rate',
        'has_bonus',
        'has_commission',
        'has_allowance',
        'has_deduction',
        'bonus_type',
        'bonus_calculation',
        'custom_bonuses',
        'custom_commissions',
        'custom_allowances',
        'custom_deductions',
    ];

    protected $casts = [
        'base_salary' => 'integer',
        'advanced_salary' => 'boolean',
        'holiday_rate' => 'integer',
        'tet_rate' => 'integer',
        'has_overtime' => 'boolean',
        'overtime_rate' => 'integer',
        'has_bonus' => 'boolean',
        'has_commission' => 'boolean',
        'has_allowance' => 'boolean',
        'has_deduction' => 'boolean',
        'custom_bonuses' => 'array',
        'custom_commissions' => 'array',
        'custom_allowances' => 'array',
        'custom_deductions' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function template()
    {
        return $this->belongsTo(SalaryTemplate::class, 'salary_template_id');
    }
}
