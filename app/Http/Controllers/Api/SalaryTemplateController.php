<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryTemplate;
use App\Models\CommissionTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryTemplateController extends Controller
{
    public function index()
    {
        $templates = SalaryTemplate::query()
            ->withCount('employeeSettings as employee_count')
            ->with(['bonuses', 'commissions.commissionTable', 'allowances', 'deductions'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function show(SalaryTemplate $salaryTemplate)
    {
        $salaryTemplate->load(['bonuses', 'commissions.commissionTable', 'allowances', 'deductions']);
        $salaryTemplate->loadCount('employeeSettings as employee_count');

        return response()->json([
            'success' => true,
            'data' => $salaryTemplate,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'has_bonus' => ['boolean'],
            'has_commission' => ['boolean'],
            'has_allowance' => ['boolean'],
            'has_deduction' => ['boolean'],
            'bonus_type' => ['nullable', 'string', 'in:personal_revenue,branch_revenue,personal_gross_profit'],
            'bonus_calculation' => ['nullable', 'string', 'in:total_revenue,progressive'],
            'bonuses' => ['nullable', 'array'],
            'bonuses.*.role_type' => ['required', 'string'],
            'bonuses.*.revenue_from' => ['required', 'numeric', 'min:0'],
            'bonuses.*.bonus_value' => ['required', 'numeric', 'min:0'],
            'bonuses.*.bonus_is_percentage' => ['boolean'],
            'commissions' => ['nullable', 'array'],
            'commissions.*.role_type' => ['required', 'string'],
            'commissions.*.revenue_from' => ['required', 'numeric', 'min:0'],
            'commissions.*.commission_table_id' => ['nullable', 'integer', 'exists:commission_tables,id'],
            'commissions.*.commission_value' => ['nullable', 'numeric', 'min:0'],
            'commissions.*.commission_is_percentage' => ['boolean'],
            'allowances' => ['nullable', 'array'],
            'allowances.*.name' => ['required', 'string', 'max:255'],
            'allowances.*.allowance_type' => ['required', 'string', 'in:fixed_per_day,fixed_per_month,percentage'],
            'allowances.*.amount' => ['required', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required', 'string', 'max:255'],
            'deductions.*.deduction_category' => ['required', 'string', 'in:late,early_leave,absence,violation'],
            'deductions.*.calculation_type' => ['required', 'string', 'in:per_occurrence,per_minute,fixed_per_month'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $template = DB::transaction(function () use ($data) {
            $template = SalaryTemplate::create([
                'name' => $data['name'],
                'has_bonus' => $data['has_bonus'] ?? false,
                'has_commission' => $data['has_commission'] ?? false,
                'has_allowance' => $data['has_allowance'] ?? false,
                'has_deduction' => $data['has_deduction'] ?? false,
                'bonus_type' => $data['bonus_type'] ?? 'personal_revenue',
                'bonus_calculation' => $data['bonus_calculation'] ?? 'total_revenue',
            ]);

            $this->syncChildren($template, $data);

            return $template;
        });

        $template->load(['bonuses', 'commissions.commissionTable', 'allowances', 'deductions']);
        $template->loadCount('employeeSettings as employee_count');

        return response()->json([
            'success' => true,
            'message' => 'Tạo mẫu lương thành công.',
            'data' => $template,
        ]);
    }

    public function update(Request $request, SalaryTemplate $salaryTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'has_bonus' => ['boolean'],
            'has_commission' => ['boolean'],
            'has_allowance' => ['boolean'],
            'has_deduction' => ['boolean'],
            'bonus_type' => ['nullable', 'string', 'in:personal_revenue,branch_revenue,personal_gross_profit'],
            'bonus_calculation' => ['nullable', 'string', 'in:total_revenue,progressive'],
            'bonuses' => ['nullable', 'array'],
            'bonuses.*.role_type' => ['required', 'string'],
            'bonuses.*.revenue_from' => ['required', 'numeric', 'min:0'],
            'bonuses.*.bonus_value' => ['required', 'numeric', 'min:0'],
            'bonuses.*.bonus_is_percentage' => ['boolean'],
            'commissions' => ['nullable', 'array'],
            'commissions.*.role_type' => ['required', 'string'],
            'commissions.*.revenue_from' => ['required', 'numeric', 'min:0'],
            'commissions.*.commission_table_id' => ['nullable', 'integer', 'exists:commission_tables,id'],
            'commissions.*.commission_value' => ['nullable', 'numeric', 'min:0'],
            'commissions.*.commission_is_percentage' => ['boolean'],
            'allowances' => ['nullable', 'array'],
            'allowances.*.name' => ['required', 'string', 'max:255'],
            'allowances.*.allowance_type' => ['required', 'string', 'in:fixed_per_day,fixed_per_month,percentage'],
            'allowances.*.amount' => ['required', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required', 'string', 'max:255'],
            'deductions.*.deduction_category' => ['required', 'string', 'in:late,early_leave,absence,violation'],
            'deductions.*.calculation_type' => ['required', 'string', 'in:per_occurrence,per_minute,fixed_per_month'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($salaryTemplate, $data) {
            $salaryTemplate->update([
                'name' => $data['name'],
                'has_bonus' => $data['has_bonus'] ?? false,
                'has_commission' => $data['has_commission'] ?? false,
                'has_allowance' => $data['has_allowance'] ?? false,
                'has_deduction' => $data['has_deduction'] ?? false,
                'bonus_type' => $data['bonus_type'] ?? 'personal_revenue',
                'bonus_calculation' => $data['bonus_calculation'] ?? 'total_revenue',
            ]);

            $this->syncChildren($salaryTemplate, $data);
        });

        $salaryTemplate->load(['bonuses', 'commissions.commissionTable', 'allowances', 'deductions']);
        $salaryTemplate->loadCount('employeeSettings as employee_count');

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật mẫu lương thành công.',
            'data' => $salaryTemplate,
        ]);
    }

    public function destroy(SalaryTemplate $salaryTemplate)
    {
        $employeeCount = $salaryTemplate->employeeSettings()->count();
        if ($employeeCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Không thể xóa mẫu lương đang áp dụng cho {$employeeCount} nhân viên.",
            ], 422);
        }

        $salaryTemplate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa mẫu lương thành công.',
        ]);
    }

    public function commissionTables()
    {
        $tables = CommissionTable::with('tiers')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $tables,
        ]);
    }

    private function syncChildren(SalaryTemplate $template, array $data): void
    {
        // Sync bonuses
        $template->bonuses()->delete();
        if (!empty($data['bonuses'])) {
            foreach ($data['bonuses'] as $i => $bonus) {
                $template->bonuses()->create([
                    'role_type' => $bonus['role_type'],
                    'revenue_from' => $bonus['revenue_from'],
                    'bonus_value' => $bonus['bonus_value'],
                    'bonus_is_percentage' => $bonus['bonus_is_percentage'] ?? true,
                    'sort_order' => $i,
                ]);
            }
        }

        // Sync commissions
        $template->commissions()->delete();
        if (!empty($data['commissions'])) {
            foreach ($data['commissions'] as $i => $commission) {
                $template->commissions()->create([
                    'role_type' => $commission['role_type'],
                    'revenue_from' => $commission['revenue_from'],
                    'commission_table_id' => $commission['commission_table_id'] ?? null,
                    'commission_value' => $commission['commission_value'] ?? 0,
                    'commission_is_percentage' => $commission['commission_is_percentage'] ?? false,
                    'sort_order' => $i,
                ]);
            }
        }

        // Sync allowances
        $template->allowances()->delete();
        if (!empty($data['allowances'])) {
            foreach ($data['allowances'] as $i => $allowance) {
                $template->allowances()->create([
                    'name' => $allowance['name'],
                    'allowance_type' => $allowance['allowance_type'],
                    'amount' => $allowance['amount'],
                    'sort_order' => $i,
                ]);
            }
        }

        // Sync deductions
        $template->deductions()->delete();
        if (!empty($data['deductions'])) {
            foreach ($data['deductions'] as $i => $deduction) {
                $template->deductions()->create([
                    'name' => $deduction['name'],
                    'deduction_category' => $deduction['deduction_category'],
                    'calculation_type' => $deduction['calculation_type'],
                    'amount' => $deduction['amount'],
                    'sort_order' => $i,
                ]);
            }
        }
    }
}