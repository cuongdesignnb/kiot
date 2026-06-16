<?php

namespace App\Console\Commands;

use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Payslip;
use Illuminate\Console\Command;

class AuditPaidPayslipLedger extends Command
{
    protected $signature = 'payroll:audit-paid-payslip-ledger
        {--employee-code= : Limit audit to one employee code}
        {--paysheet-code= : Limit audit to one paysheet code}
        {--format=table : table|json}';

    protected $description = 'Audit active paysheet payments that are missing salary_payment ledger entries';

    public function handle(): int
    {
        $rows = $this->auditRows();

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'summary' => [
                    'payment_count' => count($rows),
                    'issue_count' => collect($rows)->where('missing_salary_payment_ledger', '>', 0)->count(),
                    'missing_total' => collect($rows)->sum('missing_salary_payment_ledger'),
                ],
                'data' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table([
            'Paysheet',
            'Payslip',
            'Employee',
            'Total salary',
            'Paid amount',
            'Remaining',
            'Payment sum',
            'Salary payment ledger',
            'Missing salary payment ledger',
            'CashFlow status',
        ], collect($rows)->map(fn (array $row) => [
            $row['paysheet_code'],
            $row['payslip_code'],
            "{$row['employee_code']} - {$row['employee_name']}",
            $row['total_salary'],
            $row['paid_amount'],
            $row['remaining_amount'],
            $row['payment_sum'],
            $row['salary_payment_ledger_sum'],
            $row['missing_salary_payment_ledger'],
            $row['cash_flow_status'],
        ])->all());

        $missingTotal = collect($rows)->sum('missing_salary_payment_ledger');
        $this->line(json_encode([
            'payment_count' => count($rows),
            'issue_count' => collect($rows)->where('missing_salary_payment_ledger', '>', 0)->count(),
            'missing_total' => $missingTotal,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    public function auditRows(): array
    {
        return Payslip::query()
            ->where('paid_amount', '>', 0)
            ->with(['paysheet', 'employee', 'payments.cashFlow'])
            ->when($this->option('employee-code'), fn ($query, $code) => $query->whereHas('employee', fn ($employee) => $employee->where('code', $code)))
            ->when($this->option('paysheet-code'), fn ($query, $code) => $query->whereHas('paysheet', fn ($paysheet) => $paysheet->where('code', $code)))
            ->orderBy('paysheet_id')
            ->orderBy('id')
            ->get()
            ->map(function (Payslip $slip) {
                $payments = $slip->payments->where('status', 'active');
                $ledgerSum = abs((int) EmployeeSalaryLedgerEntry::query()
                    ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
                    ->where('is_effective', true)
                    ->where('payslip_id', $slip->id)
                    ->sum('amount'));
                $paidAmount = (int) $slip->paid_amount;
                $missing = max($paidAmount - $ledgerSum, 0);

                return [
                    'paysheet_code' => $slip->paysheet?->code,
                    'payslip_code' => $slip->code,
                    'employee_code' => $slip->employee?->code,
                    'employee_name' => $slip->employee?->name,
                    'total_salary' => (int) $slip->total_salary,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => (int) $slip->remaining,
                    'payment_id' => $payments->pluck('id')->implode('|'),
                    'payment_sum' => (int) $payments->sum('amount'),
                    'salary_payment_ledger_sum' => $ledgerSum,
                    'missing_salary_payment_ledger' => $missing,
                    'cash_flow_status' => $this->cashFlowStatus($payments),
                ];
            })
            ->values()
            ->all();
    }

    private function cashFlowStatus($payments): string
    {
        if ($payments->isEmpty()) {
            return 'no_payment';
        }

        return $payments
            ->map(fn ($payment) => $payment->cashFlow?->status ?? ($payment->cash_flow_id ? 'missing_cash_flow' : 'no_cash_flow_link'))
            ->unique()
            ->values()
            ->implode('|');
    }
}
