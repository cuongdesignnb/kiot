<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\Status\BusinessStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrderPaymentSummaryService
{
    public function addSummarySelects(Builder $query): Builder
    {
        $paidAfterDeposit = $this->paidAfterDepositSql('orders.id');
        $depositApplied = $this->depositAppliedSql('orders.id');
        $originalDeposit = $this->nonNegativeSql('COALESCE(orders.amount_paid, 0)');
        $paidTotal = "({$originalDeposit} + ({$paidAfterDeposit}))";

        return $query->addSelect([
            DB::raw('COALESCE(orders.total_payment, 0) AS order_total'),
            DB::raw("{$originalDeposit} AS original_deposit"),
            DB::raw("({$depositApplied}) AS deposit_applied"),
            DB::raw(
                $this->nonNegativeSql("{$originalDeposit} - ({$depositApplied})")
                . ' AS deposit_remaining'
            ),
            DB::raw("({$paidAfterDeposit}) AS paid_after_deposit"),
            DB::raw("{$paidTotal} AS order_paid_total"),
            DB::raw(
                $this->nonNegativeSql("COALESCE(orders.total_payment, 0) - {$paidTotal}")
                . ' AS order_remaining_debt'
            ),
            DB::raw(
                $this->nonNegativeSql("{$paidTotal} - COALESCE(orders.total_payment, 0)")
                . ' AS order_credit_total'
            ),
            DB::raw(
                "CASE"
                . " WHEN {$paidTotal} <= 0 THEN 'unpaid'"
                . " WHEN {$paidTotal} < COALESCE(orders.total_payment, 0) THEN 'partial'"
                . " WHEN {$paidTotal} > COALESCE(orders.total_payment, 0) THEN 'overpaid'"
                . " ELSE 'paid' END AS payment_status"
            ),
        ]);
    }

    public function applyHasDebtFilter(Builder $query, bool $hasDebt): Builder
    {
        $operator = $hasDebt ? '<' : '>=';

        return $query->whereRaw(
            $this->paidTotalSql('orders.id')
            . " {$operator} COALESCE(orders.total_payment, 0)"
        );
    }

    public function applyPaymentStatusFilter(Builder $query, ?string $status): Builder
    {
        if (!$status) {
            return $query;
        }

        $paidTotal = $this->paidTotalSql('orders.id');
        $total = 'COALESCE(orders.total_payment, 0)';

        return match ($status) {
            'unpaid' => $query->whereRaw("{$paidTotal} <= 0"),
            'partial' => $query
                ->whereRaw("{$paidTotal} > 0")
                ->whereRaw("{$paidTotal} < {$total}"),
            'paid' => $query->whereRaw("{$paidTotal} = {$total}"),
            'overpaid' => $query->whereRaw("{$paidTotal} > {$total}"),
            default => $query,
        };
    }

    public function summary(Order $order): array
    {
        $activeInvoices = Invoice::query()->where('order_id', $order->id);
        BusinessStatus::scopeNotCancelled($activeInvoices, 'status');

        $originalDeposit = max(0.0, (float) ($order->amount_paid ?? 0));
        $depositApplied = (float) (clone $activeInvoices)->sum('order_deposit_applied_amount');
        $paidAfterDeposit = (float) (clone $activeInvoices)
            ->selectRaw(
                'COALESCE(SUM('
                . $this->nonNegativeSql(
                    'COALESCE(customer_paid, 0) - COALESCE(order_deposit_applied_amount, 0)'
                )
                . '), 0) AS aggregate'
            )
            ->value('aggregate');
        $orderTotal = (float) ($order->total_payment ?? 0);
        $orderPaidTotal = $originalDeposit + $paidAfterDeposit;

        return [
            'order_total' => $orderTotal,
            'original_deposit' => $originalDeposit,
            'deposit_applied' => $depositApplied,
            'deposit_remaining' => max(0.0, $originalDeposit - $depositApplied),
            'paid_after_deposit' => $paidAfterDeposit,
            'order_paid_total' => $orderPaidTotal,
            'order_remaining_debt' => max(0.0, $orderTotal - $orderPaidTotal),
            'order_credit_total' => max(0.0, $orderPaidTotal - $orderTotal),
            'payment_status' => $this->paymentStatus($orderTotal, $orderPaidTotal),
        ];
    }

    public function hydrateOrder(Order $order): Order
    {
        foreach ($this->summary($order) as $key => $value) {
            $order->setAttribute($key, $value);
        }

        return $order;
    }

    public function nonNegativeSql(string $expression): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "MAX({$expression}, 0)"
            : "GREATEST({$expression}, 0)";
    }

    private function paidAfterDepositSql(string $orderIdExpression): string
    {
        $validStatusSql = BusinessStatus::notCancelledSql('invoices.status');
        $payment = $this->nonNegativeSql(
            'COALESCE(invoices.customer_paid, 0) - COALESCE(invoices.order_deposit_applied_amount, 0)'
        );

        return "SELECT COALESCE(SUM({$payment}), 0)"
            . " FROM invoices"
            . " WHERE invoices.order_id = {$orderIdExpression}"
            . " AND {$validStatusSql}";
    }

    private function depositAppliedSql(string $orderIdExpression): string
    {
        $validStatusSql = BusinessStatus::notCancelledSql('invoices.status');

        return 'SELECT COALESCE(SUM(COALESCE(invoices.order_deposit_applied_amount, 0)), 0)'
            . ' FROM invoices'
            . " WHERE invoices.order_id = {$orderIdExpression}"
            . " AND {$validStatusSql}";
    }

    private function paidTotalSql(string $orderIdExpression): string
    {
        $originalDeposit = $this->nonNegativeSql('COALESCE(orders.amount_paid, 0)');

        return "({$originalDeposit} + ({$this->paidAfterDepositSql($orderIdExpression)}))";
    }

    private function paymentStatus(float $total, float $paid): string
    {
        if ($paid <= 0.0) {
            return 'unpaid';
        }
        if ($paid < $total) {
            return 'partial';
        }
        if ($paid > $total) {
            return 'overpaid';
        }

        return 'paid';
    }
}
