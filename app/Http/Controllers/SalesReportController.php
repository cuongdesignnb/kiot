<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Support\Customers\CustomerGroupSnapshot;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SalesReportController extends Controller
{
    public function index(Request $request)
    {
        // ── Filters ──
        $concern = $request->input('concern', 'time');       // time|profit|discount|returns|employee
        $period  = $request->input('period', 'this_month');  // this_week|this_month|this_year|last_year|custom
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $branchId = $request->input('branch_id');
        $salesChannel = $request->input('sales_channel');
        $customerGroup = $request->input('customer_group');
        $viewMode = $request->input('view', 'chart');        // chart|report

        // ── Resolve date range from period preset ──
        [$startDate, $endDate, $periodLabel, $groupBy] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // ── Base invoice query ──
        $invoiceQuery = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $invoiceQuery->where('branch_id', $branchId);
        if ($salesChannel) $invoiceQuery->where('sales_channel', $salesChannel);

        // ── Returns query ──
        $returnsQuery = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnsQuery->where('branch_id', $branchId);

        // ── Build data based on concern ──
        $chartData = [];
        $reportRows = [];

        switch ($concern) {
            case 'time':
                $chartData = $this->buildTimeSeries($invoiceQuery, $returnsQuery, $startDate, $endDate, $groupBy, $branchId);
                break;
            case 'profit':
                $chartData = $this->buildProfitSeries($invoiceQuery, $startDate, $endDate, $groupBy, $branchId);
                break;
            case 'discount':
                $chartData = $this->buildDiscountSeries($invoiceQuery, $startDate, $endDate, $groupBy);
                break;
            case 'returns':
                $chartData = $this->buildReturnsSeries($returnsQuery, $startDate, $endDate, $groupBy);
                break;
            case 'employee':
                $chartData = $this->buildEmployeeSeries($invoiceQuery, $returnsQuery, $branchId, $salesChannel);
                break;
            case 'customer_group_revenue':
                $chartData = $this->buildCustomerGroupSeries($startDate, $endDate, $branchId, $salesChannel, $customerGroup, false);
                break;
            case 'customer_group_profit':
                $chartData = $this->buildCustomerGroupSeries($startDate, $endDate, $branchId, $salesChannel, $customerGroup, true);
                break;
        }

        // ── Filter options ──
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $salesChannels = Invoice::whereNotNull('sales_channel')
            ->distinct()->pluck('sales_channel')->filter()->values();
        $customerGroups = $this->customerGroupOptions();
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/SalesReport', [
            'filters' => [
                'concern' => $concern,
                'period' => $period,
                'date_from' => $startDate->format('Y-m-d'),
                'date_to' => $endDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'sales_channel' => $salesChannel,
                'customer_group' => $customerGroup,
                'view' => $viewMode,
            ],
            'periodLabel' => $periodLabel,
            'chartData' => $chartData,
            'branchName' => $branchName,
            'branches' => $branches,
            'salesChannels' => $salesChannels,
            'customerGroups' => $customerGroups,
        ]);
    }

    // ═══════════════════════════════════════
    // Period resolver
    // ═══════════════════════════════════════
    private function resolvePeriod(string $period, ?string $customFrom, ?string $customTo): array
    {
        switch ($period) {
            case 'this_week':
                $start = Carbon::now()->startOfWeek();
                $end = Carbon::now()->endOfWeek();
                $label = 'Tuần này';
                $group = 'day';
                break;
            case 'this_month':
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfDay();
                $label = 'Tháng này';
                $group = 'day';
                break;
            case 'this_year':
                $start = Carbon::now()->startOfYear();
                $end = Carbon::now()->endOfDay();
                $label = 'Năm nay';
                $group = 'month';
                break;
            case 'last_year':
                $start = Carbon::now()->subYear()->startOfYear();
                $end = Carbon::now()->subYear()->endOfYear();
                $label = 'Năm trước';
                $group = 'month';
                break;
            case 'custom':
                $start = $customFrom ? Carbon::parse($customFrom)->startOfDay() : Carbon::now()->startOfMonth();
                $end = $customTo ? Carbon::parse($customTo)->endOfDay() : Carbon::now()->endOfDay();
                $diffDays = $start->diffInDays($end);
                $group = $diffDays > 90 ? 'month' : 'day';
                $label = 'Tùy chỉnh';
                break;
            default:
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfDay();
                $label = 'Tháng này';
                $group = 'day';
        }
        return [$start, $end, $label, $group];
    }

    // ═══════════════════════════════════════
    // Concern: Thời gian → Doanh thu thuần
    // ═══════════════════════════════════════
    private function buildTimeSeries($invoiceQuery, $returnsQuery, $start, $end, $groupBy, $branchId)
    {
        $format = $groupBy === 'month' ? '%m-%Y' : '%d-%m-%Y';
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';

        $revenue = (clone $invoiceQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COALESCE(SUM(total), 0) as total'),
                DB::raw('COALESCE(SUM(discount), 0) as discount_sum')
            )
            ->groupBy('period')
            ->pluck('total', 'period');

        $returns = (clone $returnsQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COALESCE(SUM(total), 0) as total')
            )
            ->groupBy('period')
            ->pluck('total', 'period');

        // Generate all periods
        $labels = [];
        $netRevenue = [];
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format($phpFormat);
            $labels[] = $groupBy === 'month' ? $current->format('m/Y') : $current->format('d/m');
            $rev = (float) ($revenue[$key] ?? 0);
            $ret = (float) ($returns[$key] ?? 0);
            $netRevenue[] = $rev - $ret;
            $groupBy === 'month' ? $current->addMonth() : $current->addDay();
        }

        $totalNet = array_sum($netRevenue);

        return [
            'title' => 'Doanh thu thuần',
            'labels' => $labels,
            'datasets' => [
                ['label' => $branchId ? Branch::find($branchId)?->name : 'Tất cả', 'data' => $netRevenue],
            ],
            'total' => $totalNet,
            'type' => 'bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Lợi nhuận (via MetricService — single source of truth)
    // ═══════════════════════════════════════
    private function buildProfitSeries($invoiceQuery, $start, $end, $groupBy, $branchId)
    {
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';
        $labels = [];
        $profitData = [];
        $current = $start->copy();
        while ($current <= $end) {
            if ($groupBy === 'month') {
                $bucketStart = $current->copy()->startOfMonth();
                $bucketEnd   = $current->copy()->endOfMonth();
                $labels[] = $current->format('m/Y');
                $current->addMonth();
            } else {
                $bucketStart = $current->copy()->startOfDay();
                $bucketEnd   = $current->copy()->endOfDay();
                $labels[] = $current->format('d/m');
                $current->addDay();
            }
            $m = \App\Support\Reports\MetricService::compute($bucketStart, $bucketEnd, $branchId);
            $profitData[] = $m['gross_profit'];
        }

        return [
            'title' => 'Lợi nhuận gộp',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Lợi nhuận gộp', 'data' => $profitData],
            ],
            'total' => array_sum($profitData),
            'type' => 'bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Giảm giá HĐ
    // ═══════════════════════════════════════
    private function buildDiscountSeries($invoiceQuery, $start, $end, $groupBy)
    {
        $format = $groupBy === 'month' ? '%m-%Y' : '%d-%m-%Y';
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';

        $discounts = (clone $invoiceQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COALESCE(SUM(discount), 0) as total_discount')
            )
            ->groupBy('period')
            ->pluck('total_discount', 'period');

        $labels = [];
        $data = [];
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format($phpFormat);
            $labels[] = $groupBy === 'month' ? $current->format('m/Y') : $current->format('d/m');
            $data[] = (float) ($discounts[$key] ?? 0);
            $groupBy === 'month' ? $current->addMonth() : $current->addDay();
        }

        return [
            'title' => 'Giảm giá hóa đơn',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Giảm giá HĐ', 'data' => $data],
            ],
            'total' => array_sum($data),
            'type' => 'bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Trả hàng
    // ═══════════════════════════════════════
    private function buildReturnsSeries($returnsQuery, $start, $end, $groupBy)
    {
        $format = $groupBy === 'month' ? '%m-%Y' : '%d-%m-%Y';
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';

        $returnsData = (clone $returnsQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COALESCE(SUM(total), 0) as total_return')
            )
            ->groupBy('period')
            ->pluck('total_return', 'period');

        $labels = [];
        $data = [];
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format($phpFormat);
            $labels[] = $groupBy === 'month' ? $current->format('m/Y') : $current->format('d/m');
            $data[] = (float) ($returnsData[$key] ?? 0);
            $groupBy === 'month' ? $current->addMonth() : $current->addDay();
        }

        return [
            'title' => 'Trả hàng',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Giá trị trả hàng', 'data' => $data],
            ],
            'total' => array_sum($data),
            'type' => 'bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Nhân viên
    // ═══════════════════════════════════════
    private function buildEmployeeSeries($invoiceQuery, $returnsQuery, $branchId, $salesChannel = null)
    {
        $sellers = new SellerResolver();

        // Scope returns by sales channel through invoice if provided
        if ($salesChannel) {
            $returnsQuery = $sellers->filterReturnsByInvoiceSalesChannel(clone $returnsQuery, $salesChannel);
        }

        $empRevenue = $sellers->aggregateBySeller(clone $invoiceQuery, 'SUM(total)');
        $empReturns = $sellers->aggregateReturnsBySeller(clone $returnsQuery, 'SUM(total)');

        $allKeys = array_unique(array_merge(array_keys($empRevenue), array_keys($empReturns)));
        $sellerMeta = $sellers->sellerMeta($allKeys);

        $filters = [
            'branch_id' => $branchId,
            'sales_channel' => $salesChannel,
        ];
        $childrenBySeller = $this->buildSalesDailyChildren($invoiceQuery, $returnsQuery, $filters);

        $rows = [];
        foreach ($allKeys as $key) {
            $meta = $sellerMeta[$key] ?? null;
            $children = $childrenBySeller[$key] ?? [];

            $rev = round(array_sum(array_column($children, 'revenue')), 2);
            $ret = round(array_sum(array_column($children, 'returns')), 2);

            $rows[] = [
                'id'            => $key,
                'seller_key'    => $key,
                'name'          => $meta['display_name'] ?? $meta['name'] ?? $key,
                'revenue'       => $rev,
                'returns'       => $ret,
                'net'           => round($rev - $ret, 2),
                'children'      => $children,
            ];
        }

        // Sort parent rows by revenue descending
        usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        // Build chartData: labels and datasets for the chart view
        $labels      = [];
        $revenueData = [];
        foreach ($rows as $row) {
            if ($row['revenue'] <= 0) continue;
            $labels[]      = $row['name'];
            $revenueData[] = $row['revenue'];
        }

        // Summary builder
        $summary = [
            'count'   => count($rows),
            'revenue' => array_sum(array_column($rows, 'revenue')),
            'returns' => array_sum(array_column($rows, 'returns')),
            'net'     => array_sum(array_column($rows, 'net')),
        ];

        return [
            'title' => 'Doanh thu theo nhân viên',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Doanh thu', 'data' => $revenueData],
            ],
            'total' => array_sum($revenueData),
            'type' => 'bar',
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    private function buildCustomerGroupSeries(Carbon $startDate, Carbon $endDate, $branchId, $salesChannel, ?string $customerGroup, bool $profitMode): array
    {
        $groupExpr = CustomerGroupSnapshot::invoiceGroupExpression();

        $invoiceBase = DB::table('invoices')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereBetween('invoices.created_at', [$startDate, $endDate])
            ->where('invoices.status', '!=', 'Đã hủy');

        if ($branchId) $invoiceBase->where('invoices.branch_id', $branchId);
        if ($salesChannel) $invoiceBase->where('invoices.sales_channel', $salesChannel);
        if ($customerGroup) $invoiceBase->whereRaw($groupExpr . ' = ?', [$customerGroup]);

        $invoiceRows = (clone $invoiceBase)
            ->selectRaw("$groupExpr as group_name")
            ->selectRaw('COUNT(DISTINCT invoices.customer_id) as customer_count')
            ->selectRaw('COUNT(invoices.id) as invoice_count')
            ->selectRaw('COALESCE(SUM(invoices.subtotal), 0) as gross_revenue')
            ->selectRaw('COALESCE(SUM(invoices.discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) as revenue_after_discount')
            ->selectRaw('COALESCE(SUM(invoices.customer_paid), 0) as customer_paid')
            ->selectRaw('COALESCE(SUM(invoices.total - invoices.customer_paid), 0) as debt')
            ->groupBy('group_name')
            ->get()
            ->keyBy('group_name');

        $costExpr = Schema::hasColumn('invoice_items', 'cost_price')
            ? 'invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)'
            : 'invoice_items.quantity * COALESCE(products.cost_price, 0)';

        $soldRows = (clone $invoiceBase)
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('products', 'products.id', '=', 'invoice_items.product_id')
            ->selectRaw("$groupExpr as group_name")
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as sold_quantity')
            ->selectRaw("COALESCE(SUM($costExpr), 0) as cogs_sold")
            ->groupBy('group_name')
            ->get()
            ->keyBy('group_name');

        $returnBase = DB::table('returns')
            ->leftJoin('invoices', 'invoices.id', '=', 'returns.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereBetween('returns.created_at', [$startDate, $endDate])
            ->where('returns.status', '!=', 'Đã hủy');

        if ($branchId) $returnBase->where('returns.branch_id', $branchId);
        if ($salesChannel) $returnBase->where('invoices.sales_channel', $salesChannel);
        if ($customerGroup) $returnBase->whereRaw($groupExpr . ' = ?', [$customerGroup]);

        $returnRows = (clone $returnBase)
            ->selectRaw("$groupExpr as group_name")
            ->selectRaw('COUNT(returns.id) as return_count')
            ->selectRaw('COALESCE(SUM(returns.total), 0) as return_value')
            ->groupBy('group_name')
            ->get()
            ->keyBy('group_name');

        $returnCostColumn = Schema::hasColumn('return_items', 'cost_price') ? 'cost_price' : 'import_price';
        $returnCostRows = (clone $returnBase)
            ->join('return_items', 'return_items.return_id', '=', 'returns.id')
            ->selectRaw("$groupExpr as group_name")
            ->selectRaw("COALESCE(SUM(return_items.quantity * COALESCE(return_items.$returnCostColumn, 0)), 0) as cogs_returned")
            ->groupBy('group_name')
            ->get()
            ->keyBy('group_name');

        $groupNames = $invoiceRows->keys()
            ->merge($returnRows->keys())
            ->merge($soldRows->keys())
            ->merge($returnCostRows->keys())
            ->unique()
            ->sort()
            ->values();

        $rows = $groupNames->map(function ($groupName) use ($invoiceRows, $returnRows, $soldRows, $returnCostRows, $profitMode) {
            $invoice = $invoiceRows[$groupName] ?? null;
            $return = $returnRows[$groupName] ?? null;
            $sold = $soldRows[$groupName] ?? null;
            $returnCost = $returnCostRows[$groupName] ?? null;

            $revenueAfterDiscount = (float) ($invoice->revenue_after_discount ?? 0);
            $returnValue = (float) ($return->return_value ?? 0);
            $netRevenue = $revenueAfterDiscount - $returnValue;
            $cogsSold = (float) ($sold->cogs_sold ?? 0);
            $cogsReturned = (float) ($returnCost->cogs_returned ?? 0);
            $cogsNet = $cogsSold - $cogsReturned;
            $grossProfit = $netRevenue - $cogsNet;

            $row = [
                'id' => (string) $groupName,
                'name' => (string) $groupName,
                'customer_count' => (int) ($invoice->customer_count ?? 0),
                'invoice_count' => (int) ($invoice->invoice_count ?? 0),
                'gross_revenue' => round((float) ($invoice->gross_revenue ?? 0), 2),
                'discount' => round((float) ($invoice->discount ?? 0), 2),
                'revenue_after_discount' => round($revenueAfterDiscount, 2),
                'customer_paid' => round((float) ($invoice->customer_paid ?? 0), 2),
                'debt' => round((float) ($invoice->debt ?? 0), 2),
                'return_value' => round($returnValue, 2),
                'net_revenue' => round($netRevenue, 2),
                'invoice_quantity' => round((float) ($sold->sold_quantity ?? 0), 2),
                'cogs_sold' => round($cogsSold, 2),
                'cogs_returned' => round($cogsReturned, 2),
                'cogs_net' => round($cogsNet, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin' => $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0,
            ];
            $row['value'] = $profitMode ? $row['gross_profit'] : $row['net_revenue'];

            return $row;
        })->values()->all();

        return [
            'title' => $profitMode ? 'Lợi nhuận theo nhóm khách hàng' : 'Doanh thu theo nhóm khách hàng',
            'labels' => array_column($rows, 'name'),
            'datasets' => [
                ['label' => $profitMode ? 'Lợi nhuận gộp' : 'Doanh thu thuần', 'data' => array_column($rows, 'value')],
            ],
            'total' => array_sum(array_column($rows, 'value')),
            'type' => 'bar',
            'rows' => $rows,
            'summary' => [
                'count' => count($rows),
                'net_revenue' => array_sum(array_column($rows, 'net_revenue')),
                'gross_profit' => array_sum(array_column($rows, 'gross_profit')),
                'return_value' => array_sum(array_column($rows, 'return_value')),
            ],
            'groupMode' => $profitMode ? 'profit' : 'revenue',
        ];
    }

    private function customerGroupOptions()
    {
        $snapshotGroups = Schema::hasColumn('invoices', 'customer_group_name')
            ? Invoice::query()
                ->whereNotNull('customer_group_name')
                ->where('customer_group_name', '!=', '')
                ->distinct()
                ->pluck('customer_group_name')
            : collect();

        $customerGroups = \App\Models\Customer::query()
            ->whereNotNull('customer_group')
            ->where('customer_group', '!=', '')
            ->distinct()
            ->pluck('customer_group');

        return $snapshotGroups
            ->concat($customerGroups)
            ->push(CustomerGroupSnapshot::UNGROUPED)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function buildSalesDailyChildren($invoiceQuery, $returnsQuery, array $filters): array
    {
        $sellers = new SellerResolver();
        $sellerMap = $sellers->invoiceSellerMap(clone $invoiceQuery);
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

        $returnRows = (clone $returnsQuery)
            ->select('id', 'invoice_id', 'created_at', 'total')
            ->get();

        if ($returnRows->isNotEmpty()) {
            $returnInvoiceIds = $returnRows->pluck('invoice_id')->filter()->unique()->values()->all();
            
            $returnInvoiceSellerMap = !empty($returnInvoiceIds)
                ? $sellers->invoiceSellerMap(Invoice::whereIn('id', $returnInvoiceIds))
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
                $dayData['return_url'] = '/returns?' . http_build_query($params);

                $dayData['has_invoices'] = $dayData['invoice_count'] > 0;
                $dayData['has_returns'] = $dayData['return_count'] > 0;

                if ($dayData['has_invoices']) {
                    $dayData['drilldown_type'] = 'invoices';
                    $dayData['drilldown_url'] = $dayData['invoice_url'];
                    $dayData['drilldown_label'] = 'Xem hóa đơn';
                } elseif ($dayData['has_returns']) {
                    $dayData['drilldown_type'] = 'returns';
                    $dayData['drilldown_url'] = $dayData['return_url'];
                    $dayData['drilldown_label'] = 'Xem phiếu trả hàng';
                } else {
                    $dayData['drilldown_type'] = null;
                    $dayData['drilldown_url'] = null;
                    $dayData['drilldown_label'] = null;
                }
            }
            // Sort dates descending
            uksort($dates, fn($a, $b) => strcmp($b, $a));
            // Convert to sequential list
            $dates = array_values($dates);
        }
        unset($dates);

        return $children;
    }
}
