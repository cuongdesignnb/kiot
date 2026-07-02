<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Status\BusinessStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LegacyOrderAmountPaidAuditService
{
    private const EPSILON = 0.01;

    public function audit(int $limit = 100): array
    {
        $limit = max(1, $limit);
        $aggregateQuery = $this->invoiceAggregateQuery();
        $baseQuery = $this->baseOrderQuery($aggregateQuery);
        $suspectedQuery = $this->applySuspectedFilter(clone $baseQuery);

        $items = $suspectedQuery
            ->orderBy('orders.id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => $this->formatOrder($order))
            ->values()
            ->all();

        return [
            'summary' => [
                'orders_checked' => Order::query()->count(),
                'orders_amount_paid_positive' => Order::query()
                    ->where('amount_paid', '>', 0)
                    ->count(),
                'orders_with_invoices' => $this->ordersWithInvoicesCount(),
                'orders_with_paid_invoices' => $this->ordersWithPaidInvoicesCount(),
                'deposit_only_or_no_invoice' => $this->depositOnlyOrNoInvoiceCount(),
                'suspected_legacy_cumulative_amount_paid' => $this
                    ->applySuspectedFilter(clone $baseQuery)
                    ->count(),
            ],
            'items' => $items,
            'suggested_action' => 'manual_review_before_production_migration',
            'read_only' => true,
        ];
    }

    public function inspectOrder(Order $order): array
    {
        $auditedOrder = $this->baseOrderQuery($this->invoiceAggregateQuery())
            ->whereKey($order->id)
            ->firstOrFail();

        return $this->formatOrder($auditedOrder);
    }

    private function baseOrderQuery(Builder $aggregateQuery): Builder
    {
        return Order::query()
            ->select([
                'orders.id',
                'orders.code',
                'orders.customer_id',
                'orders.total_payment',
                'orders.amount_paid',
            ])
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoinSub($aggregateQuery, 'invoice_audit', function ($join) {
                $join->on('invoice_audit.order_id', '=', 'orders.id');
            })
            ->addSelect([
                'customers.name as customer_name',
                DB::raw('COALESCE(invoice_audit.invoice_count, 0) AS invoice_count'),
                DB::raw('COALESCE(invoice_audit.paid_invoice_count, 0) AS paid_invoice_count'),
                DB::raw(
                    'COALESCE(invoice_audit.paid_invoice_without_deposit_count, 0)'
                    .' AS paid_invoice_without_deposit_count'
                ),
                DB::raw(
                    'COALESCE(invoice_audit.sum_invoice_customer_paid, 0)'
                    .' AS sum_invoice_customer_paid'
                ),
                DB::raw(
                    'COALESCE(invoice_audit.sum_order_deposit_applied_amount, 0)'
                    .' AS sum_order_deposit_applied_amount'
                ),
                DB::raw(
                    'COALESCE(invoice_audit.paid_after_deposit, 0)'
                    .' AS paid_after_deposit'
                ),
            ]);
    }

    private function invoiceAggregateQuery(): Builder
    {
        $paidAfterDeposit = app(OrderPaymentSummaryService::class)->nonNegativeSql(
            'COALESCE(customer_paid, 0) - COALESCE(order_deposit_applied_amount, 0)'
        );

        $query = \App\Models\Invoice::query()
            ->select('order_id')
            ->selectRaw('COUNT(*) AS invoice_count')
            ->selectRaw(
                'SUM(CASE WHEN COALESCE(customer_paid, 0) > 0 THEN 1 ELSE 0 END)'
                .' AS paid_invoice_count'
            )
            ->selectRaw(
                'SUM(CASE WHEN COALESCE(customer_paid, 0) > 0'
                .' AND COALESCE(order_deposit_applied_amount, 0) <= 0'
                .' THEN 1 ELSE 0 END) AS paid_invoice_without_deposit_count'
            )
            ->selectRaw(
                'COALESCE(SUM(COALESCE(customer_paid, 0)), 0)'
                .' AS sum_invoice_customer_paid'
            )
            ->selectRaw(
                'COALESCE(SUM(COALESCE(order_deposit_applied_amount, 0)), 0)'
                .' AS sum_order_deposit_applied_amount'
            )
            ->selectRaw("COALESCE(SUM({$paidAfterDeposit}), 0) AS paid_after_deposit")
            ->whereNotNull('order_id')
            ->groupBy('order_id');

        BusinessStatus::scopeNotCancelled($query, 'status');

        return $query;
    }

    private function applySuspectedFilter(Builder $query): Builder
    {
        return $query
            ->where('orders.amount_paid', '>', 0)
            ->whereRaw('COALESCE(invoice_audit.paid_invoice_count, 0) > 0')
            ->whereRaw('COALESCE(invoice_audit.paid_invoice_without_deposit_count, 0) > 0');
    }

    private function ordersWithInvoicesCount(): int
    {
        return Order::query()
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->whereRaw(BusinessStatus::notCancelledSql('invoices.status'));
            })
            ->count();
    }

    private function ordersWithPaidInvoicesCount(): int
    {
        return Order::query()
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->where('invoices.customer_paid', '>', 0)
                    ->whereRaw(BusinessStatus::notCancelledSql('invoices.status'));
            })
            ->count();
    }

    private function depositOnlyOrNoInvoiceCount(): int
    {
        return Order::query()
            ->where('orders.amount_paid', '>', 0)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->whereRaw(BusinessStatus::notCancelledSql('invoices.status'));
            })
            ->count();
    }

    private function formatOrder(Order $order): array
    {
        $orderTotal = (float) ($order->total_payment ?? 0);
        $orderAmountPaid = max(0.0, (float) ($order->amount_paid ?? 0));
        $invoiceCount = (int) ($order->invoice_count ?? 0);
        $paidInvoiceWithoutDepositCount = (int) (
            $order->paid_invoice_without_deposit_count ?? 0
        );
        $invoicePaid = (float) ($order->sum_invoice_customer_paid ?? 0);
        $depositApplied = (float) ($order->sum_order_deposit_applied_amount ?? 0);
        $paidAfterDeposit = (float) ($order->paid_after_deposit ?? 0);
        $computedPaidTotal = $orderAmountPaid + $paidAfterDeposit;
        $suspected = $orderAmountPaid > 0
            && (int) ($order->paid_invoice_count ?? 0) > 0
            && $paidInvoiceWithoutDepositCount > 0;

        $classification = $suspected
            ? 'legacy_order_requires_manual_review'
            : ($orderAmountPaid > 0 && $invoiceCount === 0
                ? 'deposit_only_or_no_invoice'
                : 'option_a_consistent');

        return [
            'order_id' => (int) $order->id,
            'order_code' => (string) $order->code,
            'customer_id' => $order->customer_id ? (int) $order->customer_id : null,
            'customer_name' => $order->customer_name,
            'order_total' => $orderTotal,
            'order_amount_paid' => $orderAmountPaid,
            'invoice_count' => $invoiceCount,
            'paid_invoice_without_deposit_count' => $paidInvoiceWithoutDepositCount,
            'sum_invoice_customer_paid' => $invoicePaid,
            'sum_order_deposit_applied_amount' => $depositApplied,
            'computed_order_paid_total' => $computedPaidTotal,
            'computed_remaining_debt' => max(0.0, $orderTotal - $computedPaidTotal),
            'classification' => $classification,
            'reason' => $this->primaryReason(
                $suspected,
                $orderTotal,
                $orderAmountPaid,
                $invoicePaid
            ),
            'signals' => $this->signals(
                $suspected,
                $orderTotal,
                $orderAmountPaid,
                $invoicePaid
            ),
        ];
    }

    private function primaryReason(
        bool $suspected,
        float $orderTotal,
        float $orderAmountPaid,
        float $invoicePaid
    ): string {
        if (! $suspected) {
            return $orderAmountPaid > 0 && $invoicePaid <= self::EPSILON
                ? 'deposit_only_or_no_invoice'
                : 'option_a_consistent';
        }
        if ($orderAmountPaid > $orderTotal + self::EPSILON) {
            return 'amount_paid_greater_than_order_total';
        }
        if ($orderAmountPaid + $invoicePaid > $orderTotal + self::EPSILON) {
            return 'amount_paid_plus_invoice_paid_exceeds_order_total';
        }
        if (abs($orderAmountPaid - $invoicePaid) <= self::EPSILON) {
            return 'amount_paid_equals_or_close_to_invoice_paid';
        }

        return 'amount_paid_positive_and_invoice_paid_but_no_deposit_provenance';
    }

    private function signals(
        bool $suspected,
        float $orderTotal,
        float $orderAmountPaid,
        float $invoicePaid
    ): array {
        if (! $suspected) {
            return [];
        }

        $signals = ['amount_paid_positive_and_invoice_paid_but_no_deposit_provenance'];
        if (abs($orderAmountPaid - $invoicePaid) <= self::EPSILON) {
            $signals[] = 'amount_paid_equals_or_close_to_invoice_paid';
        }
        if ($orderAmountPaid > $orderTotal + self::EPSILON) {
            $signals[] = 'amount_paid_greater_than_order_total';
        }
        if ($orderAmountPaid + $invoicePaid > $orderTotal + self::EPSILON) {
            $signals[] = 'amount_paid_plus_invoice_paid_exceeds_order_total';
        }

        return $signals;
    }
}
