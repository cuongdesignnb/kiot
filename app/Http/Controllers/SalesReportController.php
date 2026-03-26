<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                $chartData = $this->buildEmployeeSeries($invoiceQuery, $returnsQuery, $branchId);
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
                DB::raw("strftime('{$format}', created_at) as period"),
                DB::raw('COALESCE(SUM(total), 0) as total'),
                DB::raw('COALESCE(SUM(discount), 0) as discount_sum')
            )
            ->groupBy('period')
            ->pluck('total', 'period');

        $returns = (clone $returnsQuery)
            ->select(
                DB::raw("strftime('{$format}', created_at) as period"),
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
    // Concern: Lợi nhuận
    // ═══════════════════════════════════════
    private function buildProfitSeries($invoiceQuery, $start, $end, $groupBy, $branchId)
    {
        $format = $groupBy === 'month' ? '%m-%Y' : '%d-%m-%Y';
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';

        // Revenue per period
        $revenue = (clone $invoiceQuery)
            ->select(
                DB::raw("strftime('{$format}', created_at) as period"),
                DB::raw('COALESCE(SUM(total), 0) as total')
            )
            ->groupBy('period')
            ->pluck('total', 'period');

        // Cost per period (from invoice_items * product cost_price)
        $invoiceIds = (clone $invoiceQuery)->pluck('id');
        $costs = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(
                DB::raw("strftime('{$format}', invoices.created_at) as period"),
                DB::raw('COALESCE(SUM(invoice_items.quantity * products.cost_price), 0) as total_cost')
            )
            ->groupBy('period')
            ->pluck('total_cost', 'period');

        $labels = [];
        $profitData = [];
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format($phpFormat);
            $labels[] = $groupBy === 'month' ? $current->format('m/Y') : $current->format('d/m');
            $rev = (float) ($revenue[$key] ?? 0);
            $cost = (float) ($costs[$key] ?? 0);
            $profitData[] = $rev - $cost;
            $groupBy === 'month' ? $current->addMonth() : $current->addDay();
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
                DB::raw("strftime('{$format}', created_at) as period"),
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
                DB::raw("strftime('{$format}', created_at) as period"),
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
    private function buildEmployeeSeries($invoiceQuery, $returnsQuery, $branchId)
    {
        $employees = Employee::orderBy('name')->get(['id', 'name']);

        $labels = [];
        $revenueData = [];

        foreach ($employees as $emp) {
            $rev = (float) (clone $invoiceQuery)->where('employee_id', $emp->id)->sum('total');
            if ($rev > 0) {
                $labels[] = $emp->name;
                $revenueData[] = $rev;
            }
        }

        // If no employee data, also check created_by
        if (empty($labels)) {
            foreach ($employees as $emp) {
                $rev = (float) (clone $invoiceQuery)->where('created_by', $emp->id)->sum('total');
                if ($rev > 0) {
                    $labels[] = $emp->name;
                    $revenueData[] = $rev;
                }
            }
        }

        return [
            'title' => 'Doanh thu theo nhân viên',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Doanh thu', 'data' => $revenueData],
            ],
            'total' => array_sum($revenueData),
            'type' => 'bar',
        ];
    }
}
