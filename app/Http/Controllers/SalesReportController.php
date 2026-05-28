<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
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
        }

        // ── Filter options ──
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $salesChannels = Invoice::whereNotNull('sales_channel')
            ->distinct()->pluck('sales_channel')->filter()->values();
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/SalesReport', [
            'filters' => [
                'concern' => $concern,
                'period' => $period,
                'date_from' => $startDate->format('Y-m-d'),
                'date_to' => $endDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'sales_channel' => $salesChannel,
                'view' => $viewMode,
            ],
            'periodLabel' => $periodLabel,
            'chartData' => $chartData,
            'branchName' => $branchName,
            'branches' => $branches,
            'salesChannels' => $salesChannels,
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
