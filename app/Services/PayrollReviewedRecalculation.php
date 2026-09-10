<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\TimekeepingRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollReviewedRecalculation
{
    private const FIELDS = ['base_salary', 'work_units', 'bonus', 'commission', 'allowances', 'deductions', 'ot_pay', 'total_salary', 'remaining'];

    public static function operation(string $sha, int $sheetId, array $ids, int $delta): string
    {
        sort($ids, SORT_NUMERIC);

        return hash('sha256', json_encode([$sha, $sheetId, $ids, $delta], JSON_THROW_ON_ERROR));
    }

    public function execute(array $report, string $sha, int $sheetId, array $ids, int $delta, string $operator, string $backup, bool $apply): array
    {
        $this->check(($report['contract_version'] ?? '') === 'payroll-confirmed-units-audit-v1', 'Unknown audit contract');
        $this->check($ids && count($ids) <= 100 && count(array_unique($ids)) === count($ids) && min($ids) > 0, 'Invalid exact payslip selection');
        $this->check(trim($operator) !== '' && (! $apply || trim($backup) !== ''), 'Operator and backup reference required');
        sort($ids, SORT_NUMERIC);
        $operation = self::operation($sha, $sheetId, $ids, $delta);
        $rows = collect($report['rows'] ?? [])->whereIn('payslip_id', $ids);
        $this->check($rows->count() === count($ids) && $rows->pluck('payslip_id')->unique()->count() === count($ids), 'Missing or duplicate audit rows');
        $reviewed = $rows->keyBy('payslip_id');

        return DB::transaction(function () use ($reviewed, $sha, $sheetId, $ids, $delta, $operator, $backup, $apply, $operation) {
            $sheet = Paysheet::whereKey($sheetId)->lockForUpdate()->firstOrFail();
            $this->check($sheet->status === 'calculated' && $sheet->locked_at === null, 'Only unlocked calculated sheets are eligible');
            $slips = $sheet->payslips()->orderBy('id')->lockForUpdate()->get();
            $selected = $slips->whereIn('id', $ids);
            $this->check($selected->count() === count($ids), 'Payslips do not belong to selected sheet');
            $beforeSheet = $sheet->getRawOriginal();
            $protectedBefore = $this->protectedHashes($sheetId, $ids);
            $marked = $selected->filter(fn ($s) => ($s->details['reviewed_recalculation']['operation'] ?? '') === $operation)->count();
            $this->check($marked === 0 || $marked === count($ids), 'Partial replay; manual investigation required');
            $replay = $marked > 0;
            $updates = [];
            $before = [];
            foreach ($selected as $slip) {
                $row = $reviewed[$slip->id];
                $this->check((int) $row['paysheet_id'] === $sheetId && (int) $row['employee_id'] === (int) $slip->employee_id
                    && $row['paysheet_code'] === $sheet->code && $row['period_start'] === $sheet->period_start->toDateString()
                    && $row['period_end'] === $sheet->period_end->toDateString() && $row['action'] === 'RECALCULATE_AFTER_BACKUP', 'Audit identity or eligibility mismatch');
                $this->check((int) $slip->paid_amount === 0 && (int) $slip->applied_advance === 0 && ! $slip->payments()->exists() && ! $slip->advanceApplications()->exists(), 'Payments or advances exist');
                Employee::whereKey($slip->employee_id)->lockForUpdate()->get();
                EmployeeSalarySetting::where('employee_id', $slip->employee_id)->lockForUpdate()->get();
                TimekeepingRecord::where('employee_id', $slip->employee_id)->whereBetween('work_date', [$row['period_start'], $row['period_end']])->lockForUpdate()->get();
                $slip->adjustments()->lockForUpdate()->get();
                $preview = app(PayrollPayslipCalculator::class)->preview($sheet, $slip);
                $this->check($preview['details']['validation']['status'] === 'ready' && ! empty($row['input_fingerprint'])
                    && $preview['details']['input_fingerprint'] === $row['input_fingerprint'], 'Calculation source changed or blocked');
                foreach (self::FIELDS as $field) {
                    $this->equal($preview[$field], $row['preview'][$field] ?? null, 'Preview mismatch: '.$field);
                    $this->equal($slip->$field, $row[$replay ? 'preview' : 'before'][$field] ?? null, 'Stored data changed or already applied: '.$field);
                }
                foreach (['bonus', 'commission', 'allowances', 'deductions', 'ot_pay'] as $field) {
                    $this->equal($preview[$field], $slip->$field, 'Manual adjustment changed');
                }
                $before[$slip->id] = $slip->getRawOriginal();
                $updates[$slip->id] = $preview;
            }
            $plannedDelta = $reviewed->sum(fn ($r) => $r['preview']['total_salary'] - $r['before']['total_salary']);
            $this->equal($plannedDelta, $delta, 'Expected delta mismatch');
            if ($replay) {
                $logs = ActivityLog::where('action', 'payroll_reviewed_recalculation')->where('subject_id', $sheetId)
                    ->where('properties->operation', $operation)->get();
                $this->check($logs->count() === 1, 'Replay audit record missing or duplicated');
                foreach ($selected as $slip) {
                    $this->check($this->digest(Arr::except($slip->details, ['reviewed_recalculation'])) === $this->digest($updates[$slip->id]['details']), 'Replay calculation details changed');
                }
            }
            if ($apply && ! $replay) {
                foreach ($selected as $slip) {
                    $values = $updates[$slip->id];
                    $values['details']['reviewed_recalculation'] = ['operation' => $operation, 'operator' => $operator, 'backup_reference' => $backup, 'report_sha256' => $sha];
                    $slip->update($values);
                    $fresh = $slip->fresh();
                    foreach ($values as $field => $value) {
                        if ($field === 'details') {
                            $this->check($this->digest($fresh->$field) === $this->digest($value), 'Saved details mismatch');
                        } else {
                            $this->equal($fresh->$field, $value, 'Saved value mismatch');
                        }
                    }
                    $this->check($this->digest(app(PayrollPayslipCalculator::class)->preview($sheet, $fresh)) === $this->digest($updates[$slip->id]), 'Repeated calculation mismatch');
                }
                $sheet->recalculateTotals();
                $freshSheet = $sheet->fresh();
                foreach ($beforeSheet as $field => $value) {
                    if (! in_array($field, ['total_salary', 'total_remaining', 'updated_at'], true)) {
                        $this->check($freshSheet->getRawOriginal($field) === $value, 'Unexpected sheet change: '.$field);
                    }
                }
                $this->equal($freshSheet->total_salary, $slips->sum('total_salary'), 'Sheet total mismatch');
                $this->equal($freshSheet->total_remaining, $slips->sum('remaining'), 'Sheet remaining mismatch');
                ActivityLog::create(['action' => 'payroll_reviewed_recalculation', 'description' => 'Reviewed payroll recalculation; no posting or payment', 'subject_type' => Paysheet::class, 'subject_id' => $sheetId,
                    'properties' => ['operation' => $operation, 'operator' => $operator, 'backup_reference' => $backup, 'report_sha256' => $sha, 'payslip_ids' => $ids,
                        'before_sheet' => $beforeSheet, 'after_sheet' => $freshSheet->getRawOriginal(), 'before_slips' => $before, 'after_slips' => $selected->map(fn ($s) => $s->fresh()->getRawOriginal())->all(), 'total_salary_delta' => $delta]]);
            }
            $this->check($protectedBefore === $this->protectedHashes($sheetId, $ids), 'Protected payroll, source or payment rows changed');

            return ['result' => $replay ? 'REPLAY' : ($apply ? 'APPLIED' : 'DRY_RUN'), 'operation' => $operation, 'paysheet_id' => $sheetId, 'payslip_ids' => $ids,
                'lines_changed' => $apply && ! $replay ? count($ids) : 0, 'expected_total_salary_delta' => $delta, 'applied_total_salary_delta' => $apply && ! $replay ? $delta : 0,
                'protected_rows_unchanged' => true, 'confirmation_code' => 'APPLY-PAYROLL-'.substr($operation, 0, 16), 'post_gate' => 'PASS', 'production_business_data_mutation' => $apply && ! $replay ? 'YES_APPROVED_SELECTED_PAYSLIPS_SHEET_TOTALS_AND_AUDIT' : 'NO'];
        });
    }

    private function protectedHashes(int $sheetId, array $ids): array
    {
        $hashes = [];
        foreach (['paysheets', 'payslips', 'paysheet_payments', 'employee_salary_ledger_entries', 'cash_flows', 'employees', 'employee_salary_settings', 'timekeeping_records', 'payslip_adjustments', 'salary_advance_applications'] as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($table === 'paysheets') {
                $query->where('id', '<>', $sheetId);
            }
            if ($table === 'payslips') {
                $query->whereNotIn('id', $ids);
            }
            $hashes[$table] = $this->digest($query->get());
        }

        return $hashes;
    }

    private function digest($value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function equal($actual, $expected, string $message): void
    {
        $this->check(is_numeric($actual) && is_numeric($expected) && abs((float) $actual - (float) $expected) < 0.01, $message);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    }
}
