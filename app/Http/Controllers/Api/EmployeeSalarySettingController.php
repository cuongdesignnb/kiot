<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeSalarySetting;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeSalarySettingController extends Controller
{
    public function show($employeeId)
    {
        $employee = Employee::findOrFail($employeeId);
        $setting = EmployeeSalarySetting::where('employee_id', $employeeId)
            ->with('template.bonuses', 'template.commissions', 'template.allowances', 'template.deductions')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }

    public function upsert(Request $request, $employeeId)
    {
        $employee = Employee::findOrFail($employeeId);

        $data = $request->validate([
            'salary_type' => 'required|in:fixed,hourly',
            'base_salary' => 'required|numeric|min:0',
            'salary_template_id' => 'nullable|integer|exists:salary_templates,id',
            'advanced_salary' => 'boolean',
            'holiday_rate' => 'nullable|numeric|min:0|max:999',
            'tet_rate' => 'nullable|numeric|min:0|max:999',
            'has_overtime' => 'boolean',
            'overtime_rate' => 'nullable|numeric|min:0|max:999',
            'has_bonus' => 'boolean',
            'has_commission' => 'boolean',
            'has_allowance' => 'boolean',
            'has_deduction' => 'boolean',
            'bonus_type' => 'nullable|string|in:personal_revenue,branch_revenue,personal_gross_profit',
            'bonus_calculation' => 'nullable|string|in:total_revenue,progressive',
            'custom_bonuses' => 'nullable|array',
            'custom_bonuses.*.role_type' => 'required|string',
            'custom_bonuses.*.revenue_from' => 'required|numeric|min:0',
            'custom_bonuses.*.bonus_value' => 'required|numeric|min:0',
            'custom_bonuses.*.bonus_is_percentage' => 'boolean',
            'custom_commissions' => 'nullable|array',
            'custom_commissions.*.role_type' => 'required|string',
            'custom_commissions.*.revenue_from' => 'required|numeric|min:0',
            'custom_commissions.*.commission_table_id' => 'nullable|integer',
            'custom_commissions.*.commission_value' => 'nullable|numeric|min:0',
            'custom_commissions.*.commission_is_percentage' => 'boolean',
            'custom_allowances' => 'nullable|array',
            'custom_allowances.*.name' => 'required|string|max:255',
            'custom_allowances.*.allowance_type' => 'required|string|in:fixed_per_day,fixed_per_month,percentage',
            'custom_allowances.*.amount' => 'required|numeric|min:0',
            'custom_deductions' => 'nullable|array',
            'custom_deductions.*.name' => 'required|string|max:255',
            'custom_deductions.*.deduction_category' => 'required|string|in:late,early_leave,absence,violation',
            'custom_deductions.*.calculation_type' => 'required|string|in:per_occurrence,per_minute,fixed_per_month',
            'custom_deductions.*.amount' => 'required|numeric|min:0',
        ]);

        $setting = EmployeeSalarySetting::updateOrCreate(
            ['employee_id' => $employeeId],
            $data
        );

        $setting->load('template.bonuses', 'template.commissions', 'template.allowances', 'template.deductions');

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }
}
