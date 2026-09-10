<?php

namespace App\Console\Commands;

use App\Models\Paysheet;
use App\Services\PayrollPayslipCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPayrollConfirmedUnits extends Command
{
    protected $signature = 'payroll:audit-confirmed-units {--paysheet= : Optional numeric paysheet ID} {--details : Print all rows} {--save : Save full private report}';

    protected $description = 'Read-only payroll preview against recorded attendance; never recalculates or saves payroll.';

    public function handle(PayrollPayslipCalculator $calculator): int
    {
        if ($this->option('paysheet') !== null && ! ctype_digit((string) $this->option('paysheet'))) {
            $this->error('paysheet must be a numeric ID.');

            return 2;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION READ ONLY');
        }
        DB::beginTransaction();
        try {
            $sheets = Paysheet::with('payslips.employee')->when($this->option('paysheet'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
            if ($sheets->isEmpty()) {
                throw new \RuntimeException('No paysheets found.');
            }
            $rows = [];
            $errors = 0;
            foreach ($sheets as $sheet) {
                foreach ($sheet->payslips as $slip) {
                    $fields = ['base_salary', 'work_units', 'bonus', 'commission', 'allowances', 'deductions', 'ot_pay', 'total_salary', 'remaining'];
                    $before = $slip->only($fields);
                    try {
                        $preview = $calculator->preview($sheet, $slip);
                        $after = array_intersect_key($preview, array_flip($fields));
                        $validation = $preview['details']['validation'];
                        $changed = collect($fields)->contains(fn ($field) => abs((float) $before[$field] - (float) $after[$field]) > 0.01);
                        $action = match (true) {
                            $sheet->status === 'cancelled' => 'CANCELLED_KEEP_HISTORY',
                            $sheet->status === 'locked' => $changed ? 'LOCKED_COMPARE_WITH_HISTORICAL_EVIDENCE' : 'LOCKED_UNCHANGED',
                            $validation['status'] !== 'ready' => 'ACCOUNTING_REVIEW',
                            $changed => 'RECALCULATE_AFTER_BACKUP',
                            default => 'UNCHANGED',
                        };
                        $rows[] = [
                            'paysheet_id' => $sheet->id, 'paysheet_code' => $sheet->code, 'status' => $sheet->status,
                            'period_start' => $sheet->period_start->toDateString(), 'period_end' => $sheet->period_end->toDateString(),
                            'employee_id' => $slip->employee_id, 'employee_code' => $slip->employee?->code,
                            'payslip_id' => $slip->id, 'before' => $before, 'preview' => $after,
                            'paid_amount' => $slip->paid_amount, 'applied_advance' => $slip->applied_advance,
                            'validation' => $validation, 'action' => $action,
                            'base_delta' => $after['base_salary'] - $before['base_salary'],
                            'total_delta' => $after['total_salary'] - $before['total_salary'],
                            'input_fingerprint' => $preview['details']['input_fingerprint'] ?? null,
                            'historical_warning' => $sheet->status === 'locked' ? 'Current settings are not proof of historical entitlement. No automatic correction.' : null,
                        ];
                    } catch (\Throwable $e) {
                        $errors++;
                        $rows[] = ['paysheet_id' => $sheet->id, 'employee_id' => $slip->employee_id, 'action' => 'AUDIT_ERROR', 'error' => $e->getMessage()];
                    }
                }
            }
            $report = [
                'contract_version' => 'payroll-confirmed-units-audit-v1',
                'summary' => ['paysheets' => $sheets->count(), 'payslips' => count($rows), 'errors' => $errors,
                    'actions' => collect($rows)->countBy('action')->all(), 'production_business_data_mutation' => 'NO'],
                'rows' => $rows,
            ];
        } finally {
            DB::rollBack();
        }
        $output = ['summary' => $report['summary']];
        if ($this->option('save')) {
            $directory = storage_path('app/audits');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $file = $directory.'/payroll-confirmed-units-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
            file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($file, 0600);
            $output['report_file'] = $file;
            $output['report_sha256'] = hash_file('sha256', $file);
        }
        if ($this->option('details')) {
            $output['rows'] = $rows;
        }
        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $errors ? 2 : 0;
    }
}
