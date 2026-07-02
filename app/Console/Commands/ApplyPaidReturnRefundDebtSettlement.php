<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\OrderReturn;
use App\Services\CustomerDebtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyPaidReturnRefundDebtSettlement extends Command
{
    protected $signature = 'returns:apply-paid-refund-debt-settlement
        {--dry-run : Preview corrections without changing data}
        {--code= : Optional return code filter}
        {--confirm= : Required confirmation phrase for apply}';

    protected $description = 'Apply positive customer debt adjustment settlement for paid legacy returns.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $confirm = $this->option('confirm');

        if (!$dryRun) {
            if ($confirm !== 'CONFIRM_UPDATE_RETURN_REFUND_DEBT') {
                $this->error('Apply requires --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT');

                return self::FAILURE;
            }
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
        $appliedCount = 0;
        $skippedCount = 0;
        $totalApplied = 0.0;
        $newCustomerDebtIds = [];
        $rollbackUpdates = [];

        foreach ($query->get() as $return) {
            $status = (string) $return->status;
            if (in_array(trim($status), ['cancelled', 'canceled', 'void', 'deleted', 'Đã hủy', 'Đã huỷ'], true)) {
                $skippedCount++;
                continue;
            }

            $returnTotal = (float) $return->total;
            $paidToCustomer = (float) $return->paid_to_customer;

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

            $suggestedMissing = max($paidToCustomer - $settlementAdjustmentAmount, 0.0);

            if ($suggestedMissing <= 0.01) {
                continue;
            }

            // Safety check pre-validations
            $canApply = true;
            $reasons = [];

            if ($paidToCustomer <= 0) {
                $canApply = false;
                $reasons[] = 'paid_to_customer <= 0';
            }

            if ($cashflowPaidAmount + 0.01 < $paidToCustomer) {
                $canApply = false;
                $reasons[] = "cashflow_paid ({$cashflowPaidAmount}) < paid_to_customer ({$paidToCustomer})";
            }

            if ($returnLedgerAmount >= 0) {
                $canApply = false;
                $reasons[] = "return_ledger ({$returnLedgerAmount}) >= 0";
            }

            if (abs(abs($returnLedgerAmount) - $returnTotal) > 0.01) {
                $canApply = false;
                $reasons[] = 'return_ledger_abs (' . abs($returnLedgerAmount) . ') != return_total (' . $returnTotal . ')';
            }

            if (!$canApply) {
                $skippedCount++;
                $this->warn("Skipping return {$return->code}: " . implode(', ', $reasons));
                continue;
            }

            if ($dryRun) {
                $rows[] = [
                    $return->id,
                    $return->code,
                    $return->customer_id,
                    $return->customer?->name ?? 'Khach le',
                    $this->money($returnTotal),
                    $this->money($paidToCustomer),
                    $this->money($returnLedgerAmount),
                    $this->money($settlementAdjustmentAmount),
                    $this->money($cashflowPaidAmount),
                    $this->money($suggestedMissing),
                    'PENDING_CORRECTION',
                ];
                $totalMissing += $suggestedMissing;
            } else {
                // Execute correction inside database transaction with fresh recheck
                $debtRecord = DB::transaction(function () use ($return, &$suggestedMissing) {
                    $return = $return->fresh();
                    if (!$return) {
                        return null;
                    }

                    $debtQueryInner = CustomerDebt::query()
                        ->where(function ($q) use ($return) {
                            $q->where('order_return_id', $return->id)
                                ->orWhere('ref_code', $return->code);
                        });

                    $returnLedgerAmountInner = (float) (clone $debtQueryInner)
                        ->where('type', 'return')
                        ->sum('amount');

                    $settlementAdjustmentAmountInner = (float) (clone $debtQueryInner)
                        ->where('type', 'adjustment')
                        ->where('amount', '>', 0)
                        ->sum('amount');

                    $cashflowPaidAmountInner = (float) CashFlow::query()
                        ->where('reference_type', 'OrderReturn')
                        ->where('reference_code', $return->code)
                        ->where('type', 'payment')
                        ->where(function ($q) {
                            $q->whereNull('status')
                                ->orWhere('status', '!=', 'cancelled');
                        })
                        ->whereNull('deleted_at')
                        ->sum('amount');

                    $suggestedMissingInner = max((float) $return->paid_to_customer - $settlementAdjustmentAmountInner, 0.0);

                    if ($suggestedMissingInner <= 0.01) {
                        return null;
                    }

                    // Inner strict safety checks
                    if ($return->paid_to_customer <= 0) {
                        return null;
                    }

                    if ($cashflowPaidAmountInner + 0.01 < (float) $return->paid_to_customer) {
                        return null;
                    }

                    if ($returnLedgerAmountInner >= 0) {
                        return null;
                    }

                    if (abs(abs($returnLedgerAmountInner) - (float) $return->total) > 0.01) {
                        return null;
                    }

                    $suggestedMissing = $suggestedMissingInner;

                    return app(CustomerDebtService::class)->recordAdjustment(
                        $return->customer_id,
                        $suggestedMissingInner,
                        "Bo sung tat toan tien da tra khach cho phieu tra {$return->code}",
                        [
                            'order_return_id' => $return->id,
                            'ref_code' => $return->code,
                        ]
                    );
                });

                if ($debtRecord) {
                    $appliedCount++;
                    $totalApplied += $suggestedMissing;
                    $newCustomerDebtIds[] = $debtRecord->id;
                    $rollbackUpdates[] = "UPDATE customers SET debt_amount = debt_amount - " . $this->money($suggestedMissing) . " WHERE id = {$return->customer_id};";

                    $rows[] = [
                        $return->id,
                        $return->code,
                        $return->customer_id,
                        $return->customer?->name ?? 'Khach le',
                        $this->money($suggestedMissing),
                        $debtRecord->id,
                        $this->money((float) $debtRecord->debt_total),
                        'APPLIED',
                    ];
                } else {
                    $skippedCount++;
                    $this->warn("Skipped return {$return->code} inside transaction.");
                }
            }
        }

        if ($dryRun) {
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
                'return_ledger',
                'settlement_adjusted',
                'cashflow_paid',
                'suggested_missing',
                'action',
            ], $rows);

            $this->line('Rows: ' . count($rows));
            $this->line('Suggested missing adjustment total: ' . $this->money($totalMissing));
            $this->warn('Dry-run only. No customer debt, cashflow, or return data was changed.');
        } else {
            if ($appliedCount === 0) {
                $this->info('No corrections applied.');
                $this->line('Skipped: ' . $skippedCount);
                $this->line('Applied: 0');

                return self::SUCCESS;
            }

            $this->table([
                'return_id',
                'code',
                'customer_id',
                'customer',
                'applied_amount',
                'customer_debt_id',
                'debt_total_after',
                'action',
            ], $rows);

            $this->info("Successfully applied corrections to {$appliedCount} returns.");
            $this->line("Skipped count: {$skippedCount}");
            $this->line('Total applied amount: ' . $this->money($totalApplied));
            $this->warn('Run php artisan returns:audit-paid-refund-debt --dry-run to verify Rows: 0');

            // Output rollback helper SQL block
            $this->line('');
            $this->info('-- Rollback helper. Verify backup before executing.');
            $idsList = implode(',', $newCustomerDebtIds);
            $this->line("DELETE FROM customer_debts WHERE id IN ({$idsList});");
            foreach ($rollbackUpdates as $updateSql) {
                $this->line($updateSql);
            }
        }

        return self::SUCCESS;
    }

    private function money(float $value): string
    {
        return number_format($value, 0, '.', '');
    }
}
