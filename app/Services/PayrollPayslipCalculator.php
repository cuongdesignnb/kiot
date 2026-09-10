<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\Payslip;
use Carbon\Carbon;

class PayrollPayslipCalculator
{
    /** Pure preview: never rebuild attendance or write payroll/ledger records. */
    public function preview(Paysheet $sheet, Payslip $slip): array
    {
        $employee = Employee::with('salarySetting')->findOrFail($slip->employee_id);
        $calc = app(SalaryCalculationService::class)->calculateForEmployee(
            $employee, Carbon::parse($sheet->period_start), Carbon::parse($sheet->period_end),
            $sheet->standard_working_days ? (float) $sheet->standard_working_days : null
        );
        // HOTFIX 24.12B — Preserve manual_overrides set by bulkSaveAdjustments
        // across performRecalculation (otherwise $calc overwrites details and
        // the user's "phụ cấp = 0" intent silently reverts to auto).
        $oldDetails = is_array($slip->details) ? $slip->details : [];
        if (isset($oldDetails['manual_overrides']) && is_array($oldDetails['manual_overrides'])) {
            $calc['manual_overrides'] = $oldDetails['manual_overrides'];
        }
        if (($oldDetails['zero_salary_confirmation']['input_fingerprint'] ?? null) === ($calc['input_fingerprint'] ?? '')) {
            $calc['zero_salary_confirmation'] = $oldDetails['zero_salary_confirmation'];
            if (($calc['validation']['status'] ?? '') === 'zero_requires_reason' && ! empty($oldDetails['zero_salary_confirmation']['reason'])) {
                $calc['validation'] = ['status' => 'ready', 'issues' => []];
            }
        }
        $manualOverrides = $calc['manual_overrides'] ?? [];
        $commissionOverride = (bool) ($manualOverrides['commission'] ?? false);
        $allowanceOverride = (bool) ($manualOverrides['allowance'] ?? false);
        $bonusOverride = (bool) ($manualOverrides['bonus'] ?? false);
        $deductionOverride = (bool) ($manualOverrides['deduction'] ?? false);

        // Merge manual adjustments (giữ qua recalculate)
        $adjs = $slip->adjustments()->get();
        $adjCommission = $adjs->where('type', 'commission')->sum('amount');
        $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
        $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
        $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
        $adjOt = $adjs->where('type', 'ot')->sum('amount');

        $autoOt = ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0);
        $autoLatePenalty = $calc['late_penalty'] ?? 0;

        // 24.12C — commission/bonus/allowance/deduction: adjustments OR
        // manual override REPLACE auto. OT: adjustments ADD to auto.
        // late_penalty is included only in the no-override deduction path
        // (a row-based addition); when the user explicitly overrides
        // deduction, their total is the final number.
        $totalCommission = ($commissionOverride || $adjs->where('type', 'commission')->count() > 0)
            ? $adjCommission
            : ($calc['commission'] ?? 0);
        $totalBonus = ($bonusOverride || $adjs->where('type', 'bonus')->count() > 0)
            ? $adjBonus
            : ($calc['bonus'] ?? 0);
        $totalAllowance = ($allowanceOverride || $adjs->where('type', 'allowance')->count() > 0)
            ? $adjAllowance
            : ($calc['allowances'] ?? 0);
        if ($deductionOverride) {
            $totalDeduction = $adjDeduction;
        } elseif ($adjs->where('type', 'deduction')->count() > 0) {
            $totalDeduction = $adjDeduction + $autoLatePenalty;
        } else {
            $totalDeduction = $calc['deductions'] ?? 0;
        }
        $totalOt = $autoOt + $adjOt;
        $direct = $oldDetails['direct_overrides'] ?? [];
        $calcBase = $direct['base_salary'] ?? $calc['base'];
        $totalBonus = $direct['bonus'] ?? $totalBonus;
        $totalCommission = $direct['commission'] ?? $totalCommission;
        $totalAllowance = $direct['allowances'] ?? $totalAllowance;
        $totalDeduction = $direct['deductions'] ?? $totalDeduction;
        $totalOt = $direct['ot_pay'] ?? $totalOt;
        $calc['direct_overrides'] = $direct;
        $totalSalary = max(0, $calcBase + $totalBonus + $totalCommission + $totalAllowance + $totalOt - $totalDeduction);

        return [
            'base_salary' => $calcBase,
            'bonus' => $totalBonus,
            'commission' => $totalCommission,
            'allowances' => $totalAllowance,
            'deductions' => $totalDeduction,
            'ot_pay' => $totalOt,
            'total_salary' => $totalSalary,
            'remaining' => max(0, $totalSalary - $slip->paid_amount - $slip->applied_advance),
            'work_units' => $calc['work_units'],
            'paid_leave_units' => $calc['paid_leave_units'] ?? 0,
            'ot_minutes' => $calc['ot_minutes'] ?? 0,
            'details' => $calc,
        ];
    }
}
