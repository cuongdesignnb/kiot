<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CustomerReportController extends Controller
{
    public function index(Request $request)
    {
        $concern   = $request->input('concern', 'sales');     // sales|profit|returns|debt
        $period    = $request->input('period', 'this_month');
        $dateFrom  = $request->input('date_from');
        $dateTo    = $request->input('date_to');
        $branchId  = $request->input('branch_id');
        $group     = $request->input('customer_group');
        $viewMode  = $request->input('view', 'chart');

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // ── Invoice aggregation per customer ──
        $invoiceQuery = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy')
            ->whereNotNull('customer_id');
        if ($branchId) $invoiceQuery->where('branch_id', $branchId);

        $invoiceData = (clone $invoiceQuery)
            ->select(
                'customer_id',
                DB::raw('COALESCE(SUM(total), 0) as total_revenue'),
                DB::raw('COUNT(*) as invoice_count')
            )
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        // ── Return aggregation per customer ──
        $returnsQuery = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy')
            ->whereNotNull('customer_id');
        if ($branchId) $returnsQuery->where('branch_id', $branchId);

        $returnData = (clone $returnsQuery)
            ->select(
                'customer_id',
                DB::raw('COALESCE(SUM(total), 0) as total_return')
            )
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        // ── Build customer rows ──
        $customerIds = $invoiceData->keys()->merge($returnData->keys())->unique();
        $customers = Customer::whereIn('id', $customerIds)
            ->when($group, fn($q) => $q->where('customer_group', $group))
            ->get();

        $rows = [];
        foreach ($customers as $c) {
            $inv = $invoiceData[$c->id] ?? null;
            $ret = $returnData[$c->id] ?? null;
            $revenue = (float) ($inv->total_revenue ?? 0);
            $returnVal = (float) ($ret->total_return ?? 0);
            $net = $revenue - $returnVal;

            $rows[] = [
                'id'        => $c->id,
                'code'      => $c->code,
                'name'      => $c->name,
                'revenue'   => $revenue,
                'returns'   => $returnVal,
                'net'       => $net,
                'count'     => (int) ($inv->invoice_count ?? 0),
            ];
        }

        // Sort by net revenue desc
        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);

        // ── Chart data (Top 10) ──
        $chartData = $this->buildChartData($rows, $concern);

        // ── Summary ──
        $totalRevenue = array_sum(array_column($rows, 'revenue'));
        $totalReturns = array_sum(array_column($rows, 'returns'));
        $totalNet     = array_sum(array_column($rows, 'net'));

        // ── Filter options ──
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $customerGroups = Customer::whereNotNull('customer_group')
            ->distinct()->pluck('customer_group')->filter()->values();
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/CustomerReport', [
            'filters' => [
                'concern'        => $concern,
                'period'         => $period,
                'date_from'      => $startDate->format('Y-m-d'),
                'date_to'        => $endDate->format('Y-m-d'),
                'branch_id'      => $branchId,
                'customer_group' => $group,
                'view'           => $viewMode,
            ],
            'periodLabel'    => $periodLabel,
            'chartData'      => $chartData,
            'reportRows'     => $rows,
            'summary'        => [
                'count'        => count($rows),
                'totalRevenue' => $totalRevenue,
                'totalReturns' => $totalReturns,
                'totalNet'     => $totalNet,
            ],
            'branchName'     => $branchName,
            'branches'       => $branches,
            'customerGroups' => $customerGroups,
            'dateFromDisplay' => $startDate->format('d/m/Y'),
            'dateToDisplay'   => $endDate->format('d/m/Y'),
        ]);
    }

    private function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        switch ($period) {
            case 'this_week':  return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'Tuần này'];
            case 'this_month': return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
            case 'this_year':  return [Carbon::now()->startOfYear(), Carbon::now()->endOfDay(), 'Năm nay'];
            case 'last_year':  return [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear(), 'Năm trước'];
            case 'custom':
                $s = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->startOfMonth();
                $e = $to   ? Carbon::parse($to)->endOfDay()     : Carbon::now()->endOfDay();
                return [$s, $e, 'Tùy chỉnh'];
            default: return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
        }
    }

    private function buildChartData(array $rows, string $concern): array
    {
        $top10 = array_slice($rows, 0, 10);
        $labels = array_column($top10, 'name');

        switch ($concern) {
            case 'sales':
                return [
                    'title' => 'Top 10 khách hàng mua nhiều nhất (đã trừ trả hàng)',
                    'labels' => $labels,
                    'datasets' => [['label' => 'Doanh thu thuần', 'data' => array_column($top10, 'net')]],
                    'type' => 'horizontal_bar',
                ];
            case 'returns':
                $sorted = $rows;
                usort($sorted, fn($a, $b) => $b['returns'] <=> $a['returns']);
                $top = array_slice($sorted, 0, 10);
                return [
                    'title' => 'Top 10 khách hàng trả hàng nhiều nhất',
                    'labels' => array_column($top, 'name'),
                    'datasets' => [['label' => 'Giá trị trả hàng', 'data' => array_column($top, 'returns')]],
                    'type' => 'horizontal_bar',
                ];
            case 'debt':
                $debtCustomers = Customer::where('debt_amount', '>', 0)
                    ->orderByDesc('debt_amount')->take(10)->get();
                return [
                    'title' => 'Top 10 khách hàng công nợ cao nhất',
                    'labels' => $debtCustomers->pluck('name')->toArray(),
                    'datasets' => [['label' => 'Công nợ', 'data' => $debtCustomers->pluck('debt_amount')->map(fn($v) => (float)$v)->toArray()]],
                    'type' => 'horizontal_bar',
                ];
            default:
                return [
                    'title' => 'Top 10 khách hàng mua nhiều nhất',
                    'labels' => $labels,
                    'datasets' => [['label' => 'Doanh thu thuần', 'data' => array_column($top10, 'net')]],
                    'type' => 'horizontal_bar',
                ];
        }
    }
}
