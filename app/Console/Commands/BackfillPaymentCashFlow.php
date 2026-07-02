<?php

namespace App\Console\Commands;

use App\Models\PaysheetPayment;
use App\Services\PayrollPaymentCashFlowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPaymentCashFlow extends Command
{
    protected $signature = 'payroll:backfill-payment-cashflow
        {--dry-run : Preview missing payroll CashFlow vouchers without writing}
        {--apply : Create missing payroll CashFlow vouchers}
        {--paysheet= : Limit backfill to one paysheet code}';

    protected $description = 'Backfill missing CashFlow vouchers for active salary payments';

    public function handle(PayrollPaymentCashFlowService $cashFlows): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::INVALID;
        }
        if (! $apply && ! $this->option('dry-run')) {
            $this->warn('No write option provided. Running as --dry-run.');
        }

        $rows = [];
        $created = 0;
        $skipped = 0;

        foreach ($this->payments() as $payment) {
            $reason = $this->skipReason($payment, $cashFlows);
            if ($reason !== null) {
                $skipped++;
                $rows[] = $this->row($payment, 'SKIPPED', $reason, null);
                continue;
            }

            if (! $apply) {
                $rows[] = $this->row($payment, 'WOULD_CREATE', 'missing_cash_flow', $cashFlows->idempotencyKey($payment));
                continue;
            }

            $cashFlow = DB::transaction(fn () => $cashFlows->ensureForPayment(
                PaysheetPayment::query()->lockForUpdate()->findOrFail($payment->id)
            ));
            $created += $cashFlow ? 1 : 0;
            $rows[] = $this->row($payment->fresh(), 'CREATED', 'missing_cash_flow', $cashFlow?->idempotency_key);
        }

        $this->table(['Paysheet', 'Payment', 'Employee', 'Amount', 'Result', 'Reason', 'Idempotency key'], $rows);
        $this->line(json_encode([
            'mode' => $apply ? 'APPLY' : 'DRY-RUN',
            'candidate_count' => count($rows),
            'created_count' => $created,
            'skipped_count' => $skipped,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function payments()
    {
        return PaysheetPayment::query()
            ->with(['paysheet', 'payslip', 'employee'])
            ->when($this->option('paysheet'), fn ($query, $code) => $query->whereHas('paysheet', fn ($paysheet) => $paysheet->where('code', $code)))
            ->orderBy('paysheet_id')
            ->orderBy('id')
            ->get();
    }

    private function skipReason(PaysheetPayment $payment, PayrollPaymentCashFlowService $cashFlows): ?string
    {
        if ($payment->status !== 'active') {
            return 'skipped_cancelled';
        }
        if ((int) $payment->amount <= 0) {
            return 'invalid_amount';
        }
        if ($cashFlows->validCashFlowForPayment($payment)) {
            return 'ok';
        }

        return null;
    }

    private function row(PaysheetPayment $payment, string $result, string $reason, ?string $idempotencyKey): array
    {
        return [
            $payment->paysheet?->code,
            $payment->code,
            trim(($payment->employee?->code ?? '').' - '.($payment->employee?->name ?? '')),
            (int) $payment->amount,
            $result,
            $reason,
            $idempotencyKey,
        ];
    }
}
