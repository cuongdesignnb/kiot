<?php

namespace App\Support\Reports;

use App\Models\Invoice;
use App\Models\OrderReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for financial report metrics.
 *
 * Conventions (see metric_dictionary_reports.md):
 *  - gross_revenue = SUM(invoices.subtotal)  [NOT invoices.total]
 *  - return_value  = SUM(returns.subtotal)
 *  - cogs_sold     = SUM(items.qty * COALESCE(NULLIF(items.cost_price,0), products.cost_price, 0))
 *  - cogs_returned = SUM(return_items.qty * COALESCE(return_items.import_price, 0))
 *  - cogs_net      = cogs_sold - cogs_returned
 *  - net_revenue   = gross_revenue - invoice_discount - return_value
 *  - gross_profit  = net_revenue - cogs_net
 *
 * DO NOT inline these formulas in controllers. Always go through this service.
 */
class MetricService
{
    /**
     * Build the base invoice query (status != 'Đã hủy', branch scope, date range).
     */
    public static function invoiceScope(Carbon $from, Carbon $to, $branchId = null): Builder
    {
        // Step 24.3: use transaction_date (business date) fallback created_at for revenue reporting
        $dateExpr = DB::raw('COALESCE(transaction_date, created_at)');
        $q = Invoice::where($dateExpr, '>=', $from)
            ->where($dateExpr, '<=', $to)
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        return $q;
    }

    /**
     * Build the base return query.
     */
    public static function returnScope(Carbon $from, Carbon $to, $branchId = null): Builder
    {
        $q = OrderReturn::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        return $q;
    }

    /**
     * Compute all core metrics for a given date range & optional branch.
     *
     * @return array{gross_revenue: float, invoice_discount: float, return_value: float,
     *   net_revenue: float, cogs_sold: float, cogs_returned: float, cogs_net: float,
     *   gross_profit: float, invoice_count: int, return_count: int}
     */
    public static function compute(Carbon $from, Carbon $to, $branchId = null): array
    {
        $invQ = self::invoiceScope($from, $to, $branchId);
        $retQ = self::returnScope($from, $to, $branchId);

        $hasInvoiceSubtotal = Schema::hasColumn('invoices', 'subtotal');
        $hasReturnSubtotal  = Schema::hasColumn('returns', 'subtotal');
        $hasItemCost        = Schema::hasColumn('invoice_items', 'cost_price');
        $hasReturnItemCost  = Schema::hasTable('return_items')
            ? (Schema::hasColumn('return_items', 'import_price') ? 'import_price'
              : (Schema::hasColumn('return_items', 'cost_price') ? 'cost_price' : null))
            : null;

        $grossRevenue    = (float) (clone $invQ)->sum($hasInvoiceSubtotal ? 'subtotal' : 'total');
        $invoiceDiscount = (float) (clone $invQ)->sum('discount');
        $invoiceCount    = (clone $invQ)->count();
        $invoiceIds      = (clone $invQ)->pluck('id');

        $returnValue  = (float) (clone $retQ)->sum($hasReturnSubtotal ? 'subtotal' : 'total');
        $returnCount  = (clone $retQ)->count();
        $returnIds    = (clone $retQ)->pluck('id');

        $costExpr = $hasItemCost
            ? 'invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)'
            : 'invoice_items.quantity * COALESCE(products.cost_price, 0)';

        $cogsSold = $invoiceIds->isEmpty() ? 0.0 : (float) DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->sum(DB::raw($costExpr));

        $cogsReturned = 0.0;
        if ($returnIds->isNotEmpty() && $hasReturnItemCost) {
            $cogsReturned = (float) DB::table('return_items')
                ->whereIn('return_id', $returnIds)
                ->sum(DB::raw("return_items.quantity * COALESCE(return_items.$hasReturnItemCost, 0)"));
        }

        $cogsNet     = $cogsSold - $cogsReturned;
        $netRevenue  = $grossRevenue - $invoiceDiscount - $returnValue;
        $grossProfit = $netRevenue - $cogsNet;

        return [
            'gross_revenue'    => $grossRevenue,
            'invoice_discount' => $invoiceDiscount,
            'return_value'     => $returnValue,
            'net_revenue'      => $netRevenue,
            'cogs_sold'        => $cogsSold,
            'cogs_returned'    => $cogsReturned,
            'cogs_net'         => $cogsNet,
            'gross_profit'     => $grossProfit,
            'invoice_count'    => $invoiceCount,
            'return_count'     => $returnCount,
        ];
    }
}
