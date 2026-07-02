<?php

namespace App\Console\Commands;

use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Services\PayrollPaymentCashFlowService;
use Illuminate\Console\Command;

class AuditPaymentCashFlow extends Command
{
    protected $signature = 'payroll:audit-payment-cashflow
        {--paysheet= : Limit audit to one paysheet code}
        {--format=table : table|json}';

    protected $description = 'Audit active salary payments that are missing payroll CashFlow vouchers';

    public function handle(PayrollPaymentCashFlowService $cashFlows): int
    {
        $rows = $this->rows($cashFlows);
        $issues = collect($rows)->filter(fn (array $row) => $row['can_backfill'])->values();

        $payload = [
            'summary' => [
                'payment_count' => collect($rows)->where('payment_status', 'active')->count(),
                'issue_count' => $issues->count(),
                'missing_cash_flow_total' => (int) $issues->sum('missing_cash_flow_amount'),
            ],
            'data' => $rows,
            'issues' => $issues->all(),
        ];

        if ($this->option('format') === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table([
            'Paysheet',
            'Payment',
            'Employee',
            'Amount',
            'Status',
            'Ledger',
            'CashFlow count',
            'CashFlow sum',
            'Missing',
            'Reason',
        ], collect($rows)->map(fn (array $row) => [
            $row['paysheet_code'],
            $row['payment_code'],
            "{$row['employee_code']} - {$row['employee_name']}",
            $row['payment_amount'],
            $row['payment_status'],
            $row['ledger_salary_payment_sum'],
            $row['cash_flow_count'],
            $row['cash_flow_sum'],
            $row['missing_cash_flow_amount'],
            $row['reason'],
        ])->all());
        $this->line(json_encode($payload['summary'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    public function rows(PayrollPaymentCashFlowService $cashFlows): array
    {
        return PaysheetPayment::query()
            ->with(['paysheet', 'payslip', 'employee'])
            ->when($this->option('paysheet'), fn ($query, $code) => $query->whereHas('paysheet', fn ($paysheet) => $paysheet->where('code', $code)))
            ->orderBy('paysheet_id')
            ->orderBy('id')
            ->get()
            ->map(function (PaysheetPayment $payment) use ($cashFlows) {
                $ledgerSum = (int) EmployeeSalaryLedgerEntry::query()
                    ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
                    ->where('reference_type', 'paysheet_payment')
                    ->where('reference_id', $payment->id)
                    ->where('is_effective', true)
                    ->sum('amount');

                $validCashFlow = $cashFlows->validCashFlowForPayment($payment);
                $cashFlowRows = $cashFlows->cashFlowsForPayment($payment)->active()->get();
                $cashFlowCount = $validCashFlow ? max(1, $cashFlowRows->count()) : 0;
                $cashFlowSum = $validCashFlow ? (int) ($cashFlowRows->sum('amount') ?: $validCashFlow->amount) : 0;
                $reason = $this->reason($payment, $ledgerSum, $cashFlowCount);
                $canBackfill = in_array($reason, ['missing_cash_flow', 'missing_ledger_and_cash_flow'], true);

                return [
                    'paysheet_code' => $payment->paysheet?->code,
                    'payment_id' => $payment->id,
                    'payment_code' => $payment->code,
                    'employee_code' => $payment->employee?->code,
                    'employee_name' => $payment->employee?->name,
                    'payment_amount' => (int) $payment->amount,
                    'payment_status' => $payment->status,
                    'ledger_salary_payment_sum' => $ledgerSum,
                    'cash_flow_count' => $cashFlowCount,
                    'cash_flow_sum' => $cashFlowSum,
                    'missing_cash_flow_amount' => $canBackfill ? (int) $payment->amount : 0,
                    'can_backfill' => $canBackfill,
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }

    private function reason(PaysheetPayment $payment, int $ledgerSum, int $cashFlowCount): string
    {
        if ($payment->status !== 'active') {
            return 'skipped_cancelled';
        }
        if ((int) $payment->amount <= 0) {
            return 'invalid_amount';
        }
        if ($cashFlowCount > 0) {
            return 'ok';
        }
        if ($ledgerSum === 0) {
            return 'missing_ledger_and_cash_flow';
        }

        return 'missing_cash_flow';
    }
}
