<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Services\PayrollDocumentParityService;
use App\Services\PayrollLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateSalaryLedger extends Command
{
    protected $signature = 'payroll:migrate-salary-ledger
        {--apply : Write ledger entries}
        {--backfill-documents : Backfill locked payslips and active payments}
        {--legacy-balance=report : report or opening}
        {--go-live-date= : Required when legacy-balance=opening}
        {--employee-code= : Limit migration to one employee code}';

    protected $description = 'Audit or migrate legacy payroll data into the employee salary ledger';

    public function handle(PayrollLedgerService $ledger, PayrollDocumentParityService $parity): int
    {
        $mode = (string) $this->option('legacy-balance');
        if (! in_array($mode, ['report', 'opening'], true)) {
            $this->error('--legacy-balance must be report or opening.');

            return self::FAILURE;
        }

        $goLiveDate = null;
        if ($mode === 'opening') {
            if (! $this->option('go-live-date')) {
                $this->error('--go-live-date is required for opening mode.');

                return self::FAILURE;
            }

            try {
                $goLiveDate = Carbon::createFromFormat('Y-m-d', (string) $this->option('go-live-date'))->startOfDay();
            } catch (\Throwable) {
                $this->error('--go-live-date must use YYYY-MM-DD format.');

                return self::FAILURE;
            }
        }

        $backfillDocuments = (bool) $this->option('backfill-documents');
        if ($mode === 'opening' && $backfillDocuments) {
            $this->error('Do not combine document backfill with opening balance; it can double-count legacy debt.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $employeeCode = trim((string) $this->option('employee-code'));
        $employeeQuery = Employee::query()->orderBy('id');
        if ($employeeCode !== '') {
            $employeeQuery->where('code', $employeeCode);
        }

        if ($employeeCode !== '' && ! (clone $employeeQuery)->exists()) {
            $this->error("Employee code {$employeeCode} was not found.");

            return self::FAILURE;
        }

        $schemaReady = Schema::hasTable('employee_salary_ledger_entries')
            && Schema::hasColumn('employees', 'salary_balance_cache');
        if (! $schemaReady) {
            $message = 'Payroll ledger table or employees.salary_balance_cache is missing. Run payroll schema migrations on the target restore database first.';
            if ($apply) {
                $this->error($message);

                return self::FAILURE;
            }
            $this->warn($message);
        }

        $cashFlowsBefore = CashFlow::count();
        $paymentsBefore = PaysheetPayment::count();
        $stats = [
            'accruals_created' => 0,
            'accruals_skipped' => 0,
            'payments_created' => 0,
            'payments_skipped' => 0,
            'openings_created' => 0,
            'openings_skipped' => 0,
            'anomalies' => 0,
            'opening_total' => 0,
            'accrual_missing_count' => 0,
            'accrual_missing_total' => 0,
            'accrual_amount_mismatch_count' => 0,
            'accrual_duplicate_count' => 0,
            'accrual_zero_salary_count' => 0,
            'payment_missing_count' => 0,
            'payment_missing_total' => 0,
            'payment_amount_mismatch_count' => 0,
            'payment_duplicate_count' => 0,
            'cancelled_payment_exact_count' => 0,
            'cancelled_payment_anomaly_count' => 0,
            'cancelled_payment_anomaly_total' => 0,
            'backfill_ambiguous' => 0,
        ];
        $rows = [];

        if ($backfillDocuments && $schemaReady) {
            if (! $this->backfillDocuments($ledger, $parity, $apply, $employeeCode, $stats)) {
                return self::FAILURE;
            }
        }

        (clone $employeeQuery)
            ->where('balance', '!=', 0)
            ->each(function (Employee $employee) use (
                $ledger,
                $mode,
                $apply,
                $schemaReady,
                $goLiveDate,
                &$stats,
                &$rows
            ) {
                if ($mode === 'report') {
                    $stats['anomalies']++;
                    $rows[] = [$employee->code, (int) $employee->balance, '-', 'REPORTED', '-', (int) ($employee->salary_balance_cache ?? 0), 0, '-'];

                    return;
                }

                $amount = (int) $employee->balance;
                $key = $this->openingBalanceIdempotencyKey($employee->id, $amount, $goLiveDate);
                $duplicateCount = $schemaReady
                    ? EmployeeSalaryLedgerEntry::query()->where('idempotency_key', $key)->count()
                    : 0;
                $result = 'WOULD CREATE';

                if ($duplicateCount > 0) {
                    $stats['openings_skipped']++;
                    $result = 'SKIPPED';
                } else {
                    $stats['opening_total'] += $amount;
                    if ($apply) {
                        $ledger->appendEntry([
                            'employee_id' => $employee->id,
                            'code' => "SDDK-{$employee->code}-{$goLiveDate->format('Ymd')}",
                            'type' => EmployeeSalaryLedgerEntry::TYPE_OPENING_BALANCE,
                            'reference_type' => 'employee',
                            'reference_id' => $employee->id,
                            'amount' => $amount,
                            'event_at' => $goLiveDate,
                            'note' => 'Số dư lương chuyển đổi từ hệ thống KiotViet',
                            'idempotency_key' => $key,
                        ]);
                        $stats['openings_created']++;
                        $result = 'CREATED';
                    }
                }

                $effectiveBalance = $schemaReady ? (int) $ledger->currentBalance($employee->id) : 0;
                $cache = $apply ? (int) $employee->fresh()->salary_balance_cache : (int) ($employee->salary_balance_cache ?? 0);
                $rows[] = [
                    $employee->code,
                    $amount,
                    $goLiveDate->toDateString(),
                    $result,
                    $effectiveBalance,
                    $cache,
                    $duplicateCount,
                    $key,
                ];
            });

        $this->newLine();
        $this->info('Mode: '.($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Go-live date: '.($goLiveDate?->toDateString() ?? '-'));
        $this->table(
            ['Employee code', 'Legacy balance', 'Go-live date', 'Opening balance', 'Effective balance', 'Cache after rebuild', 'Duplicate count', 'Idempotency key'],
            $rows
        );
        $this->table(['Metric', 'Value'], [
            ['Opening balance created', $stats['openings_created']],
            ['Opening balance skipped', $stats['openings_skipped']],
            ['Opening balance total', $stats['opening_total']],
            ['Payroll accrual created', $stats['accruals_created']],
            ['Payroll accrual skipped', $stats['accruals_skipped']],
            ['Payroll accrual missing candidates', $stats['accrual_missing_count']],
            ['Payroll accrual missing total', $stats['accrual_missing_total']],
            ['Payroll accrual amount mismatches', $stats['accrual_amount_mismatch_count']],
            ['Payroll accrual duplicates', $stats['accrual_duplicate_count']],
            ['Payroll accrual zero salary', $stats['accrual_zero_salary_count']],
            ['Salary payment created', $stats['payments_created']],
            ['Salary payment skipped', $stats['payments_skipped']],
            ['Salary payment missing candidates', $stats['payment_missing_count']],
            ['Salary payment missing total', $stats['payment_missing_total']],
            ['Salary payment amount mismatches', $stats['payment_amount_mismatch_count']],
            ['Salary payment duplicates', $stats['payment_duplicate_count']],
            ['Cancelled payment exact lifecycles', $stats['cancelled_payment_exact_count']],
            ['Cancelled payment lifecycle anomalies', $stats['cancelled_payment_anomaly_count']],
            ['Cancelled payment anomaly total', $stats['cancelled_payment_anomaly_total']],
            ['Anomalies', $stats['anomalies']],
            ['CashFlow created', CashFlow::count() - $cashFlowsBefore],
            ['Payment created', PaysheetPayment::count() - $paymentsBefore],
        ]);
        $this->line('employees.balance was not modified.');

        return self::SUCCESS;
    }

    private function backfillDocuments(
        PayrollLedgerService $ledger,
        PayrollDocumentParityService $parity,
        bool $apply,
        string $employeeCode,
        array &$stats
    ): bool {
        $filters = $employeeCode !== '' ? ['employee-code' => $employeeCode] : [];
        $missingAccruals = [];
        $missingPayments = [];

        foreach ($parity->lockedPayslips($filters) as $slip) {
            $classification = $parity->classifyAccrual($slip);
            switch ($classification['classification']) {
                case 'EXACT':
                    $stats['accruals_skipped']++;
                    break;
                case 'MISSING':
                    $stats['accrual_missing_count']++;
                    $stats['accrual_missing_total'] += $classification['expected_amount'];
                    $missingAccruals[] = $slip;
                    break;
                case 'AMOUNT_MISMATCH':
                    $stats['accrual_amount_mismatch_count']++;
                    break;
                case 'DUPLICATE':
                    $stats['accrual_duplicate_count']++;
                    break;
                case 'ZERO_SALARY':
                    $stats['accrual_zero_salary_count']++;
                    $stats['accruals_skipped']++;
                    break;
                case 'EXACT_ZERO':
                    $stats['accruals_skipped']++;
                    break;
            }
        }

        foreach ($parity->activePayments($filters) as $payment) {
            if (! $payment->employee || ! $payment->payslip || ! $payment->paysheet) {
                $stats['anomalies']++;

                continue;
            }
            $classification = $parity->classifyPayment($payment);
            switch ($classification['classification']) {
                case 'EXACT':
                    $stats['payments_skipped']++;
                    break;
                case 'MISSING':
                    $stats['payment_missing_count']++;
                    $stats['payment_missing_total'] += (int) $payment->amount;
                    $missingPayments[] = $payment;
                    break;
                case 'AMOUNT_MISMATCH':
                    $stats['payment_amount_mismatch_count']++;
                    break;
                case 'DUPLICATE':
                    $stats['payment_duplicate_count']++;
                    break;
            }
        }

        foreach ($parity->cancelledPayments($filters) as $payment) {
            $lifecycle = $parity->classifyCancelledPayment($payment);
            if ($lifecycle['anomalies'] === []) {
                $stats['cancelled_payment_exact_count']++;

                continue;
            }

            $stats['cancelled_payment_anomaly_count'] += count($lifecycle['anomalies']);
            $stats['cancelled_payment_anomaly_total'] += (int) $payment->amount;
        }

        $ambiguous = $stats['accrual_amount_mismatch_count']
            + $stats['accrual_duplicate_count']
            + $stats['payment_amount_mismatch_count']
            + $stats['payment_duplicate_count']
            + $stats['cancelled_payment_anomaly_count'];
        $stats['backfill_ambiguous'] = $ambiguous;
        $this->line('Semantic backfill candidates: accruals='.$stats['accrual_missing_count'].'/'.$stats['accrual_missing_total']
            .', payments='.$stats['payment_missing_count'].'/'.$stats['payment_missing_total']);
        $this->line('Semantic mismatches or duplicates: '.$ambiguous);

        if ($apply && $ambiguous > 0) {
            $this->error('Backfill stopped: semantic mismatches, duplicate document identities, or cancelled payment lifecycle anomalies require manual review. No backfill rows were written.');

            return false;
        }
        if (! $apply) {
            return true;
        }

        DB::transaction(function () use ($ledger, $parity, $missingAccruals, $missingPayments, &$stats) {
            foreach ($missingAccruals as $slip) {
                $ledger->appendEntry([
                    'employee_id' => $slip->employee_id,
                    'paysheet_id' => $slip->paysheet_id,
                    'payslip_id' => $slip->id,
                    'code' => $slip->code,
                    'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
                    'reference_type' => 'payslip',
                    'reference_id' => $slip->id,
                    'amount' => (int) $slip->total_salary,
                    'event_at' => $slip->paysheet->locked_at ?? $slip->updated_at,
                    'note' => 'Backfill semantic payroll accrual lịch sử',
                    'idempotency_key' => $parity->accrualIdempotencyKey($slip),
                ]);
                $stats['accruals_created']++;
            }

            foreach ($missingPayments as $payment) {
                $ledger->appendEntry([
                    'employee_id' => $payment->employee_id,
                    'paysheet_id' => $payment->paysheet_id,
                    'payslip_id' => $payment->payslip_id,
                    'code' => $payment->code ?: "TTPL-LEGACY-{$payment->id}",
                    'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
                    'reference_type' => 'paysheet_payment',
                    'reference_id' => $payment->id,
                    'amount' => -(int) $payment->amount,
                    'event_at' => $payment->paid_at ?? $payment->created_at,
                    'payment_method' => $payment->method,
                    'note' => 'Backfill semantic salary payment lịch sử',
                    'idempotency_key' => $parity->paymentIdempotencyKey($payment),
                ]);
                $stats['payments_created']++;
            }
        });

        return true;
    }

    private function openingBalanceIdempotencyKey(int $employeeId, int $amount, Carbon $date): string
    {
        return "payroll-opening-balance:employee:{$employeeId}:legacy-balance:{$amount}:go-live:{$date->toDateString()}";
    }
}
