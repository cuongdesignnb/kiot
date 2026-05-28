<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * HOTFIX 24.26 — Refactored to use SellerResolver for all seller-related logic.
 * All aggregation now uses prefixed seller keys (employee:N, user:N, orphan:Name)
 * to avoid ambiguity between employees.id and users.id.
 */
class EmployeeReportController extends Controller
{
    private SellerResolver $sellers;

    public function __construct()
    {
        $this->sellers = new SellerResolver();
    }

    public function index(Request $request)
    {
        $concern      = $request->input('concern', 'sales');       // sales | profit | items
        $period       = $request->input('period', 'this_month');
        $dateFrom     = $request->input('date_from');
        $dateTo       = $request->input('date_to');
        $branchId     = $request->input('branch_id');
        $employeeId   = $request->input('employee_id');
        $salesChannel = $request->input('sales_channel');
        $viewMode     = $request->input('view', 'chart');

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // Base invoice query
        $invoiceQ = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $invoiceQ->where('branch_id', $branchId);
        if ($salesChannel) $invoiceQ->where('sales_channel', $salesChannel);

        // HOTFIX 24.26 — filter by seller using SellerResolver
        if ($employeeId) {
            $invoiceQ = $this->sellers->filterBySeller($invoiceQ, $employeeId);
        }

        // Returns query
        $returnQ = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnQ->where('branch_id', $branchId);

        // HOTFIX 24.31 — scope returns by the same seller and sales_channel
        // as the invoice query. Otherwise returns of seller B leak into a
        // report filtered for seller A and create phantom negative rows.
        if ($employeeId) {
            $returnQ = $this->sellers->filterReturnsBySeller($returnQ, $employeeId);
        }
        if ($salesChannel) {
            $returnQ = $this->sellers->filterReturnsByInvoiceSalesChannel($returnQ, $salesChannel);
        }

        $filters = [
            'branch_id'     => $branchId,
            'sales_channel' => $salesChannel,
            'date_from'     => $startDate->format('Y-m-d'),
            'date_to'       => $endDate->format('Y-m-d'),
        ];

        // Build data
        switch ($concern) {
            case 'profit':
                $chartData  = $this->buildProfitData($invoiceQ, $returnQ);
                $reportRows = $this->buildProfitReportRows($invoiceQ, $returnQ);
                break;
            case 'items':
                $chartData  = $this->buildItemsData($invoiceQ);
                $reportRows = $this->buildItemsReportRows($invoiceQ);
                break;
            default: // sales
                $chartData  = $this->buildSalesData($invoiceQ, $returnQ);
                $reportRows = $this->buildSalesReportRows($invoiceQ, $returnQ, $filters);
        }

        // Summary
        $summary = $this->buildSummary($reportRows);

        // Filter options (dynamic)
        $branches      = Branch::orderBy('name')->get(['id', 'name']);
        $employees     = $this->sellers->buildSellerFilterOptions();
        $salesChannels = Invoice::whereNotNull('sales_channel')
            ->distinct()->pluck('sales_channel')->filter()->values();
        $branchName    = $branchId ? (Branch::find($branchId)?->name ?? 'N/A') : 'Tất cả chi nhánh';

        return Inertia::render('Reports/EmployeeReport', [
            'filters' => [
                'concern'       => $concern,
                'period'        => $period,
                'date_from'     => $startDate->format('Y-m-d'),
                'date_to'       => $endDate->format('Y-m-d'),
                'branch_id'     => $branchId,
                'employee_id'   => $employeeId,
                'sales_channel' => $salesChannel,
                'view'          => $viewMode,
            ],
            'periodLabel'      => $periodLabel,
            'chartData'        => $chartData,
            'reportRows'       => $reportRows,
            'summary'          => $summary,
            'branchName'       => $branchName,
            'branches'         => $branches,
            'employees'        => $employees,
            'salesChannels'    => $salesChannels,
            'dateFromDisplay'  => $startDate->format('d/m/Y'),
            'dateToDisplay'    => $endDate->format('d/m/Y'),
        ]);
    }

    // ═══════════════════════════════════════
    // Sales: Top employees by net revenue
    // ═══════════════════════════════════════
    private function buildSalesData($invoiceQ, $returnQ)
    {
        $empRevenue = $this->sellers->aggregateBySeller(clone $invoiceQ, 'SUM(total)');
        $empReturns = $this->sellers->aggregateReturnsBySeller(clone $returnQ, 'SUM(total)');

        $merged = [];
        $allKeys = array_unique(array_merge(array_keys($empRevenue), array_keys($empReturns)));
        foreach ($allKeys as $key) {
            $merged[$key] = ($empRevenue[$key] ?? 0) - ($empReturns[$key] ?? 0);
        }
        arsort($merged);
        $top = array_slice($merged, 0, 10, true);

        $sellerMeta = $this->sellers->sellerMeta(array_keys($top));
        $labels = [];
        $data   = [];
        foreach ($top as $key => $net) {
            $labels[] = $sellerMeta[$key]['display_name'] ?? $sellerMeta[$key]['name'] ?? $key;
            $data[]   = $net;
        }

        return [
            'title'    => 'Top 10 người bán nhiều nhất (đã trừ trả hàng)',
            'labels'   => $labels,
            'datasets' => [['label' => 'Doanh thu thuần', 'data' => $data]],
            'type'     => 'horizontal_bar',
        ];
    }

    private function buildSalesReportRows($invoiceQ, $returnQ, array $filters = [])
    {
        $empRevenue = $this->sellers->aggregateBySeller(clone $invoiceQ, 'SUM(total)');
        $empReturns = $this->sellers->aggregateReturnsBySeller(clone $returnQ, 'SUM(total)');

        $allKeys   = array_unique(array_merge(array_keys($empRevenue), array_keys($empReturns)));
        $sellerMeta = $this->sellers->sellerMeta($allKeys);

        $childrenBySeller = $this->buildSalesDailyChildren($invoiceQ, $returnQ, $filters);

        $rows = [];
        foreach ($allKeys as $key) {
            $meta = $sellerMeta[$key] ?? null;
            $rev  = round($empRevenue[$key] ?? 0, 2);
            $ret  = round($empReturns[$key] ?? 0, 2);
            $rows[] = [
                'id'           => $key,
                'seller_key'   => $key,
                'code'         => $meta['code'] ?? 'UNK',
                'name'         => $meta['display_name'] ?? $meta['name'] ?? $key,
                'seller_type'  => $meta['type'] ?? 'unknown',
                'seller_code'  => $meta['code'] ?? 'UNK',
                'seller_name'  => $meta['name'] ?? $key,
                'revenue'      => $rev,
                'returns'      => $ret,
                'net'          => $rev - $ret,
                'children'     => $childrenBySeller[$key] ?? [],
            ];
        }
        usort($rows, fn ($a, $b) => $b['net'] <=> $a['net']);
        return $rows;
    }

    private function buildSalesDailyChildren($invoiceQ, $returnQ, array $filters): array
    {
        $sellerMap = $this->sellers->invoiceSellerMap(clone $invoiceQ);
        $children = [];

        if (!empty($sellerMap)) {
            $invoiceIds = array_keys($sellerMap);
            $invoiceRows = \Illuminate\Support\Facades\DB::table('invoices')
                ->whereIn('id', $invoiceIds)
                ->selectRaw('id, DATE(created_at) as report_date, total')
                ->get();

            foreach ($invoiceRows as $row) {
                $key = $sellerMap[$row->id] ?? 'unknown';
                $date = $row->report_date;
                if (!isset($children[$key][$date])) {
                    $children[$key][$date] = [
                        'date' => $date,
                        'date_display' => Carbon::parse($date)->format('d/m/Y'),
                        'revenue' => 0.0,
                        'returns' => 0.0,
                        'net' => 0.0,
                        'invoice_count' => 0,
                        'return_count' => 0,
                    ];
                }
                $children[$key][$date]['revenue'] += (float) $row->total;
                $children[$key][$date]['invoice_count'] += 1;
            }
        }

        $returnRows = (clone $returnQ)
            ->select('id', 'invoice_id', 'created_at', 'total')
            ->get();

        if ($returnRows->isNotEmpty()) {
            $returnInvoiceIds = $returnRows->pluck('invoice_id')->filter()->unique()->values()->all();
            
            $returnInvoiceSellerMap = !empty($returnInvoiceIds)
                ? $this->sellers->invoiceSellerMap(Invoice::whereIn('id', $returnInvoiceIds))
                : [];

            foreach ($returnRows as $ret) {
                $sellerKey = $returnInvoiceSellerMap[$ret->invoice_id] ?? 'unknown';
                $date = Carbon::parse($ret->created_at)->toDateString();

                if (!isset($children[$sellerKey][$date])) {
                    $children[$sellerKey][$date] = [
                        'date' => $date,
                        'date_display' => Carbon::parse($date)->format('d/m/Y'),
                        'revenue' => 0.0,
                        'returns' => 0.0,
                        'net' => 0.0,
                        'invoice_count' => 0,
                        'return_count' => 0,
                    ];
                }
                $children[$sellerKey][$date]['returns'] += (float) $ret->total;
                $children[$sellerKey][$date]['return_count'] += 1;
            }
        }

        foreach ($children as $sellerKey => &$dates) {
            foreach ($dates as $date => &$dayData) {
                $dayData['revenue'] = round($dayData['revenue'], 2);
                $dayData['returns'] = round($dayData['returns'], 2);
                $dayData['net'] = round($dayData['revenue'] - $dayData['returns'], 2);

                $params = [
                    'date_filter' => 'custom',
                    'date_from' => $date,
                    'date_to' => $date,
                    'seller_key' => $sellerKey,
                ];
                if (!empty($filters['branch_id'])) {
                    $params['branch_id'] = $filters['branch_id'];
                }
                if (!empty($filters['sales_channel'])) {
                    $params['sales_channel'] = $filters['sales_channel'];
                }
                $params['sort_by'] = 'created_at';
                $params['sort_direction'] = 'desc';

                $dayData['invoice_url'] = '/invoices?' . http_build_query($params);
                $dayData['return_url'] = '/returns?' . http_build_query(array_merge($params, ['date_filter' => 'custom', 'date_from' => $date, 'date_to' => $date]));
            }
            // Sort dates descending
            uksort($dates, fn($a, $b) => strcmp($b, $a));
            // Convert to sequential list
            $dates = array_values($dates);
        }
        unset($dates);

        return $children;
    }

    // ═══════════════════════════════════════
    // Profit: KiotViet 8-column report per employee
    // ═══════════════════════════════════════
    private function buildProfitData($invoiceQ, $returnQ)
    {
        $rows = $this->buildProfitReportRows($invoiceQ, $returnQ);

        usort($rows, fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
        $top = array_slice($rows, 0, 10);

        $labels = [];
        $data   = [];
        foreach ($top as $row) {
            $labels[] = $row['name']; // already display_name from buildProfitReportRows
            $data[]   = $row['gross_profit'];
        }

        return [
            'title'    => 'Top 10 nhân viên lợi nhuận cao nhất',
            'labels'   => $labels,
            'datasets' => [['label' => 'Lợi nhuận gộp', 'data' => $data]],
            'type'     => 'horizontal_bar',
        ];
    }

    private function buildProfitReportRows($invoiceQ, $returnQ)
    {
        $empGrossRevenue     = $this->sellers->aggregateBySeller(clone $invoiceQ, 'SUM(subtotal)');
        $empInvoiceDiscount  = $this->sellers->aggregateBySeller(clone $invoiceQ, 'SUM(discount)');
        $empReturnSubtotal   = $this->sellers->aggregateReturnsBySeller(clone $returnQ, 'SUM(subtotal)');
        $empCogsSold         = $this->sellers->cogsSoldBySeller(clone $invoiceQ);
        $empCogsReturned     = $this->sellers->cogsReturnedBySeller(clone $returnQ);

        $allKeys = array_unique(array_merge(
            array_keys($empGrossRevenue), array_keys($empInvoiceDiscount),
            array_keys($empCogsSold), array_keys($empReturnSubtotal),
            array_keys($empCogsReturned)
        ));
        $sellerMeta = $this->sellers->sellerMeta($allKeys);

        $rows = [];
        foreach ($allKeys as $key) {
            $meta = $sellerMeta[$key] ?? null;

            $grossRevenue          = $empGrossRevenue[$key] ?? 0;
            $invoiceDiscount       = $empInvoiceDiscount[$key] ?? 0;
            $revenueAfterDiscount  = $grossRevenue - $invoiceDiscount;
            $returnValue           = $empReturnSubtotal[$key] ?? 0;
            $netRevenue            = $revenueAfterDiscount - $returnValue;
            $cogsSold              = $empCogsSold[$key] ?? 0;
            $cogsReturned          = $empCogsReturned[$key] ?? 0;
            $totalCogs             = $cogsSold - $cogsReturned;
            $grossProfit           = $netRevenue - $totalCogs;

            $rows[] = [
                'id'                     => $key,
                'seller_key'             => $key,
                'code'                   => $meta['code'] ?? 'UNK',
                'name'                   => $meta['display_name'] ?? $meta['name'] ?? $key,
                'seller_type'            => $meta['type'] ?? 'unknown',
                'seller_code'            => $meta['code'] ?? 'UNK',
                'seller_name'            => $meta['name'] ?? $key,
                // 8-field KiotViet profit row
                'gross_revenue'          => $grossRevenue,
                'invoice_discount'       => $invoiceDiscount,
                'revenue_after_discount' => $revenueAfterDiscount,
                'return_value'           => $returnValue,
                'net_revenue'            => $netRevenue,
                'total_cogs'             => $totalCogs,
                'gross_profit'           => $grossProfit,
                // Backward compatibility aliases
                'revenue'                => $netRevenue,
                'returns'                => $totalCogs,
                'net'                    => $grossProfit,
            ];
        }
        usort($rows, fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
        return $rows;
    }

    // ═══════════════════════════════════════
    // Items sold per employee
    // ═══════════════════════════════════════
    private function buildItemsData($invoiceQ)
    {
        $byKey = $this->sellers->aggregateItemsBySeller(clone $invoiceQ, 'SUM(invoice_items.quantity)');
        arsort($byKey);
        $top = array_slice($byKey, 0, 10, true);

        $sellerMeta = $this->sellers->sellerMeta(array_keys($top));
        $labels = [];
        $data   = [];
        foreach ($top as $key => $qty) {
            $labels[] = $sellerMeta[$key]['display_name'] ?? $sellerMeta[$key]['name'] ?? $key;
            $data[]   = (int) $qty;
        }

        return [
            'title'    => 'Top 10 nhân viên bán nhiều sản phẩm nhất',
            'labels'   => $labels,
            'datasets' => [['label' => 'Số lượng', 'data' => $data]],
            'type'     => 'horizontal_bar',
        ];
    }

    private function buildItemsReportRows($invoiceQ)
    {
        $qtyByKey   = $this->sellers->aggregateItemsBySeller(clone $invoiceQ, 'SUM(invoice_items.quantity)');
        $valueByKey = $this->sellers->aggregateItemsBySeller(clone $invoiceQ, 'SUM(invoice_items.quantity * invoice_items.price)');

        $allKeys    = array_unique(array_merge(array_keys($qtyByKey), array_keys($valueByKey)));
        $sellerMeta = $this->sellers->sellerMeta($allKeys);

        $rows = [];
        foreach ($allKeys as $key) {
            $meta = $sellerMeta[$key] ?? null;
            $rows[] = [
                'id'           => $key,
                'seller_key'   => $key,
                'code'         => $meta['code'] ?? 'UNK',
                'name'         => $meta['display_name'] ?? $meta['name'] ?? $key,
                'seller_type'  => $meta['type'] ?? 'unknown',
                'seller_code'  => $meta['code'] ?? 'UNK',
                'seller_name'  => $meta['name'] ?? $key,
                'revenue'      => (float) ($valueByKey[$key] ?? 0),
                'returns'      => (int) ($qtyByKey[$key] ?? 0),
                'net'          => (float) ($valueByKey[$key] ?? 0),
            ];
        }
        usort($rows, fn ($a, $b) => $b['returns'] <=> $a['returns']);
        return $rows;
    }

    // ═══════════════════════════════════════
    // Summary builder
    // ═══════════════════════════════════════
    private function buildSummary(array $rows): array
    {
        $summary = [
            'count'        => count($rows),
            'totalRevenue' => array_sum(array_column($rows, 'revenue')),
            'totalReturns' => array_sum(array_column($rows, 'returns')),
            'totalNet'     => array_sum(array_column($rows, 'net')),
        ];

        // Extended profit summary (8-field KiotViet)
        if (!empty($rows) && array_key_exists('gross_revenue', $rows[0])) {
            $summary['gross_revenue']          = array_sum(array_column($rows, 'gross_revenue'));
            $summary['invoice_discount']       = array_sum(array_column($rows, 'invoice_discount'));
            $summary['revenue_after_discount'] = array_sum(array_column($rows, 'revenue_after_discount'));
            $summary['return_value']           = array_sum(array_column($rows, 'return_value'));
            $summary['net_revenue']            = array_sum(array_column($rows, 'net_revenue'));
            $summary['total_cogs']             = array_sum(array_column($rows, 'total_cogs'));
            $summary['gross_profit']           = array_sum(array_column($rows, 'gross_profit'));
        }

        return $summary;
    }

    // ═══════════════════════════════════════
    // Period resolver
    // ═══════════════════════════════════════
    private function resolvePeriod(string $period, ?string $customFrom, ?string $customTo): array
    {
        switch ($period) {
            case 'this_week':
                return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'Tuần này'];
            case 'this_month':
                return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
            case 'this_year':
                return [Carbon::now()->startOfYear(), Carbon::now()->endOfDay(), 'Năm nay'];
            case 'last_year':
                return [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear(), 'Năm trước'];
            case 'custom':
                $s = $customFrom ? Carbon::parse($customFrom)->startOfDay() : Carbon::now()->startOfMonth();
                $e = $customTo ? Carbon::parse($customTo)->endOfDay() : Carbon::now()->endOfDay();
                return [$s, $e, 'Tùy chỉnh'];
            default:
                return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
        }
    }
}
