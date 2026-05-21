<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\OrderReturn;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;

class AuditPaidReturnRefundDebt extends Command
{
    protected $signature = 'returns:audit-paid-refund-debt
        {--dry-run : Read-only audit; no data changes}
        {--code= : Optional return code filter}';

    protected $description = 'Audit paid return refunds that may be missing customer debt settlement ledger rows.';

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only in this hotfix. Re-run with --dry-run.');

            return self::FAILURE;
        }

        $query = OrderReturn::query()
            ->with('customer')
            ->where('paid_to_customer', '>', 0)
            ->orderByDesc('id');

        if ($code = trim((string) $this->option('code'))) {
            $query->where('code', $code);
        }

        $rows = [];
        $totalMissing = 0.0;

        foreach ($query->get() as $return) {
            if (BusinessStatus::isCancelled((string) $return->status)) {
                continue;
            }

            $returnTotal = (float) $return->total;
            $paidToCustomer = (float) $return->paid_to_customer;
            $expectedRemainingCredit = max($returnTotal - $paidToCustomer, 0);

            $debtQuery = CustomerDebt::query()
                ->where(function ($q) use ($return) {
                    $q->where('order_return_id', $return->id)
                        ->orWhere('ref_code', $return->code);
                });

            $returnLedgerAmount = (float) (clone $debtQuery)
                ->where('type', 'return')
                ->sum('amount');

            $settlementAdjustmentAmount = (float) (clone $debtQuery)
                ->where('type', 'adjustment')
                ->where('amount', '>', 0)
                ->sum('amount');

            $cashflowPaidAmount = (float) CashFlow::query()
                ->where('reference_type', 'OrderReturn')
                ->where('reference_code', $return->code)
                ->where('type', 'payment')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', 'cancelled');
                })
                ->whereNull('deleted_at')
                ->sum('amount');

            $suggestedMissingAdjustment = max($paidToCustomer - $settlementAdjustmentAmount, 0);

            if ($suggestedMissingAdjustment <= 0.01) {
                continue;
            }

            $totalMissing += $suggestedMissingAdjustment;
            $rows[] = [
                $return->id,
                $return->code,
                $return->customer_id,
                $return->customer?->name ?? 'Khach le',
                $this->money($returnTotal),
                $this->money($paidToCustomer),
                $this->money($expectedRemainingCredit),
                $this->money($returnLedgerAmount),
                $this->money($settlementAdjustmentAmount),
                $this->money($cashflowPaidAmount),
                $this->money($suggestedMissingAdjustment),
            ];
        }

        if (empty($rows)) {
            $this->info('No paid returns with missing refund debt settlement were found.');
            $this->line('Rows: 0');
            $this->line('Suggested missing adjustment total: 0');

            return self::SUCCESS;
        }

        $this->table([
            'return_id',
            'code',
            'customer_id',
            'customer',
            'return_total',
            'paid_to_customer',
            'expected_credit',
            'return_ledger',
            'settlement_adjusted',
            'cashflow_paid',
            'suggested_missing',
        ], $rows);

        $this->line('Rows: ' . count($rows));
        $this->line('Suggested missing adjustment total: ' . $this->money($totalMissing));
        $this->warn('Dry-run only. No customer debt, cashflow, or return data was changed.');

        return self::SUCCESS;
    }

    private function money(float $value): string
    {
        return number_format($value, 0, '.', '');
    }
}
