<?php

namespace App\Services;

use App\Models\Paysheet;
use Illuminate\Validation\ValidationException;

class PayrollCalculationGuard
{
    public function assertReady(Paysheet $sheet): void
    {
        $errors = [];
        if ($sheet->needs_recalc) {
            $errors['paysheet'] = 'Dữ liệu đã thay đổi. Hãy tính lại bảng lương trước khi chốt.';
        }
        foreach ($sheet->payslips as $slip) {
            // Serialize source edits while the posting transaction validates and commits.
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \App\Models\Employee::whereKey($slip->employee_id)->lockForUpdate()->get();
                \App\Models\EmployeeSalarySetting::where('employee_id', $slip->employee_id)->lockForUpdate()->get();
                \App\Models\TimekeepingRecord::where('employee_id', $slip->employee_id)
                    ->whereBetween('work_date', [$sheet->period_start, $sheet->period_end])->lockForUpdate()->get();
                $slip->adjustments()->lockForUpdate()->get();
            }
            $preview = app(PayrollPayslipCalculator::class)->preview($sheet, $slip);
            $current = $preview['details'];
            $saved = $slip->details ?? [];
            $issues = [];
            if (($current['validation']['status'] ?? '') === 'blocked') {
                $issues = $current['validation']['issues'];
            }
            if (empty($saved['input_fingerprint']) || $saved['input_fingerprint'] !== ($current['input_fingerprint'] ?? null)) {
                $issues[] = 'Dữ liệu nguồn đã thay đổi hoặc phiếu dùng cách tính cũ. Hãy tính lại.';
            }
            foreach (['base_salary', 'bonus', 'commission', 'allowances', 'deductions', 'ot_pay', 'total_salary', 'work_units'] as $field) {
                if (abs((float) $slip->$field - (float) $preview[$field]) > 0.01) {
                    $issues[] = 'Số tiền hoặc ngày công không khớp dữ liệu tính. Hãy tính lại.';
                    break;
                }
            }
            if ((int) $slip->base_salary === 0 && (
                empty($saved['zero_salary_confirmation']['reason'])
                || ($saved['zero_salary_confirmation']['input_fingerprint'] ?? null) !== ($current['input_fingerprint'] ?? null)
            )) {
                $issues[] = 'Cần ghi lý do xác nhận lương chính bằng 0.';
            }
            if ($issues) {
                $errors['payslips.'.$slip->id] = $slip->code.': '.implode(' ', array_unique($issues));
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
