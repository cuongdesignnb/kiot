<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'has_bonus',
        'has_commission',
        'has_allowance',
        'has_deduction',
        'bonus_type',
        'bonus_calculation',
    ];

    protected $casts = [
        'has_bonus' => 'boolean',
        'has_commission' => 'boolean',
        'has_allowance' => 'boolean',
        'has_deduction' => 'boolean',
    ];

    public function bonuses()
    {
        return $this->hasMany(SalaryTemplateBonus::class)->orderBy('sort_order');
    }

    public function commissions()
    {
        return $this->hasMany(SalaryTemplateCommission::class)->orderBy('sort_order');
    }

    public function allowances()
    {
        return $this->hasMany(SalaryTemplateAllowance::class)->orderBy('sort_order');
    }

    public function deductions()
    {
        return $this->hasMany(SalaryTemplateDeduction::class)->orderBy('sort_order');
    }

    public function employeeSettings()
    {
        return $this->hasMany(EmployeeSalarySetting::class, 'salary_template_id');
    }

    public function getEmployeeCountAttribute(): int
    {
        return $this->employeeSettings()->count();
    }
}
