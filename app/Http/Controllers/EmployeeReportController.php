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

class EmployeeReportController extends Controller
{
    public function index(Request $request)
    {
        $concern     = $request->input('concern', 'sales');       // sales | profit | items
        $period      = $request->input('period', 'this_month');
        $dateFrom    = $request->input('date_from');
        $dateTo      = $request->input('date_to');
        $branchId    = $request->input('branch_id');
        $employeeId  = $request->input('employee_id');
        $salesChannel = $request->input('sales_channel');
        $viewMode    = $request->input('view', 'chart');

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // Base invoice query
        $invoiceQ = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $invoiceQ->where('branch_id', $branchId);
        if ($salesChannel) $invoiceQ->where('sales_channel', $salesChannel);
        if ($employeeId) {
            $invoiceQ->where('created_by', $employeeId);
        }

        // Returns query
        $returnQ = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnQ->where('branch_id', $branchId);

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
                $reportRows = $this->buildSalesReportRows($invoiceQ, $returnQ);
        }

        // Summary
        $summary = $this->buildSummary($reportRows);

        // Filter options (dynamic)
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $employees    = Employee::orderBy('name')->get(['id', 'name', 'code']);
        $salesChannels = Invoice::whereNotNull('sales_channel')
            ->distinct()->pluck('sales_channel')->filter()->values();
        $branchName   = $branchId ? (Branch::find($branchId)?->name ?? 'N/A') : 'Tất cả chi nhánh';

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
        $empRevenue = $this->getRevenueByEmployee(clone $invoiceQ);
        $empReturns = $this->getReturnsByEmployee(clone $returnQ);

        $merged = [];
        foreach ($empRevenue as $empId => $rev) {
            $ret = $empReturns[$empId] ?? 0;
            $merged[$empId] = $rev - $ret;
        }
        arsort($merged);
        $top = array_slice($merged, 0, 10, true);

        $labels = [];
        $data   = [];
        $empNames = Employee::whereIn('id', array_keys($top))->pluck('name', 'id');
        foreach ($top as $empId => $net) {
            $labels[] = $empNames[$empId] ?? "NV #{$empId}";
            $data[]   = $net;
        }

        return [
            'title'    => 'Top 10 người bán nhiều nhất (đã trừ trả hàng)',
            'labels'   => $labels,
            'datasets' => [['label' => 'Doanh thu thuần', 'data' => $data]],
            'type'     => 'horizontal_bar',
        ];
    }

    private function buildSalesReportRows($invoiceQ, $returnQ)
    {
        $empRevenue = $this->getRevenueByEmployee(clone $invoiceQ);
        $empReturns = $this->getReturnsByEmployee(clone $returnQ);

        $allIds = array_unique(array_merge(array_keys($empRevenue), array_keys($empReturns)));
        $employees = Employee::whereIn('id', $allIds)->get(['id', 'name', 'code'])->keyBy('id');

        $rows = [];
        foreach ($allIds as $empId) {
            $emp = $employees[$empId] ?? null;
            $rev = $empRevenue[$empId] ?? 0;
            $ret = $empReturns[$empId] ?? 0;
            $rows[] = [
                'id'      => $empId,
                'code'    => $emp?->code ?? "NV{$empId}",
                'name'    => $emp?->name ?? "Nhân viên #{$empId}",
                'revenue' => $rev,
                'returns' => $ret,
                'net'     => $rev - $ret,
            ];
        }
        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);
        return $rows;
    }

    // ═══════════════════════════════════════
    // Profit: Revenue minus cost per employee
    // ═══════════════════════════════════════
    private function buildProfitData($invoiceQ, $returnQ)
    {
        $empRevenue = $this->getRevenueByEmployee(clone $invoiceQ);
        $empCosts   = $this->getCostByEmployee(clone $invoiceQ);
        $empReturns = $this->getReturnsByEmployee(clone $returnQ);

        $merged = [];
        $allIds = array_unique(array_merge(array_keys($empRevenue), array_keys($empCosts)));
        foreach ($allIds as $empId) {
            $rev  = $empRevenue[$empId] ?? 0;
            $cost = $empCosts[$empId] ?? 0;
            $ret  = $empReturns[$empId] ?? 0;
            $merged[$empId] = ($rev - $ret) - $cost;
        }
        arsort($merged);
        $top = array_slice($merged, 0, 10, true);

        $labels = [];
        $data   = [];
        $empNames = Employee::whereIn('id', array_keys($top))->pluck('name', 'id');
        foreach ($top as $empId => $profit) {
            $labels[] = $empNames[$empId] ?? "NV #{$empId}";
            $data[]   = $profit;
        }

        return [
            'title'    => 'Top 10 nhân viên lợi nhuận cao nhất',
            'labels'   => $labels,
            'datasets' => [['label' => 'Lợi nhuận', 'data' => $data]],
            'type'     => 'horizontal_bar',
        ];
    }

    private function buildProfitReportRows($invoiceQ, $returnQ)
    {
        $empRevenue = $this->getRevenueByEmployee(clone $invoiceQ);
        $empCosts   = $this->getCostByEmployee(clone $invoiceQ);
        $empReturns = $this->getReturnsByEmployee(clone $returnQ);

        $allIds = array_unique(array_merge(array_keys($empRevenue), array_keys($empCosts)));
        $employees = Employee::whereIn('id', $allIds)->get(['id', 'name', 'code'])->keyBy('id');

        $rows = [];
        foreach ($allIds as $empId) {
            $emp  = $employees[$empId] ?? null;
            $rev  = $empRevenue[$empId] ?? 0;
            $ret  = $empReturns[$empId] ?? 0;
            $cost = $empCosts[$empId] ?? 0;
            $rows[] = [
                'id'      => $empId,
                'code'    => $emp?->code ?? "NV{$empId}",
                'name'    => $emp?->name ?? "Nhân viên #{$empId}",
                'revenue' => $rev - $ret,
                'returns' => $cost,
                'net'     => ($rev - $ret) - $cost,
            ];
        }
        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);
        return $rows;
    }

    // ═══════════════════════════════════════
    // Items sold per employee
    // ═══════════════════════════════════════
    private function buildItemsData($invoiceQ)
    {
        $invoiceIds = (clone $invoiceQ)->pluck('id');

        $empItems = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->select(
                DB::raw('invoices.created_by as emp_id'),
                DB::raw('SUM(invoice_items.quantity) as total_qty')
            )
            ->whereNotNull('invoices.created_by')
            ->groupBy('emp_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        $empNames = Employee::whereIn('id', $empItems->pluck('emp_id'))->pluck('name', 'id');
        $labels = [];
        $data   = [];
        foreach ($empItems as $row) {
            $labels[] = $empNames[$row->emp_id] ?? "NV #{$row->emp_id}";
            $data[]   = (int) $row->total_qty;
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
        $invoiceIds = (clone $invoiceQ)->pluck('id');

        $empItems = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->select(
                DB::raw('invoices.created_by as emp_id'),
                DB::raw('SUM(invoice_items.quantity) as total_qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as total_value')
            )
            ->whereNotNull('invoices.created_by')
            ->groupBy('emp_id')
            ->orderByDesc('total_qty')
            ->get();

        $empNames = Employee::whereIn('id', $empItems->pluck('emp_id'))
            ->get(['id', 'name', 'code'])->keyBy('id');

        $rows = [];
        foreach ($empItems as $row) {
            $emp = $empNames[$row->emp_id] ?? null;
            $rows[] = [
                'id'      => $row->emp_id,
                'code'    => $emp?->code ?? "NV{$row->emp_id}",
                'name'    => $emp?->name ?? "Nhân viên #{$row->emp_id}",
                'revenue' => (float) $row->total_value,
                'returns' => (int) $row->total_qty,
                'net'     => (float) $row->total_value,
            ];
        }
        return $rows;
    }

    // ═══════════════════════════════════════
    // Summary builder
    // ═══════════════════════════════════════
    private function buildSummary(array $rows): array
    {
        return [
            'count'        => count($rows),
            'totalRevenue' => array_sum(array_column($rows, 'revenue')),
            'totalReturns' => array_sum(array_column($rows, 'returns')),
            'totalNet'     => array_sum(array_column($rows, 'net')),
        ];
    }

    // ═══════════════════════════════════════
    // Helper: Revenue by employee
    // ═══════════════════════════════════════
    private function getRevenueByEmployee($query): array
    {
        $byCreatedBy = (clone $query)->whereNotNull('created_by')
            ->select('created_by as emp_id', DB::raw('SUM(total) as total'))
            ->groupBy('created_by')
            ->pluck('total', 'emp_id')
            ->toArray();

        $byEmployeeId = [];

        $merged = [];
        foreach (array_merge(array_keys($byCreatedBy), array_keys($byEmployeeId)) as $id) {
            $merged[$id] = ($byCreatedBy[$id] ?? 0) + ($byEmployeeId[$id] ?? 0);
        }
        return $merged;
    }

    // ═══════════════════════════════════════
    // Helper: Returns by employee
    // ═══════════════════════════════════════
    private function getReturnsByEmployee($query): array
    {
        return (clone $query)->whereNotNull('created_by')
            ->select('created_by as emp_id', DB::raw('SUM(total) as total'))
            ->groupBy('created_by')
            ->pluck('total', 'emp_id')
            ->toArray();
    }

    // ═══════════════════════════════════════
    // Helper: Cost by employee
    // ═══════════════════════════════════════
    private function getCostByEmployee($query): array
    {
        $invoiceIds = (clone $query)->pluck('id');

        $costs = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->select(
                DB::raw('invoices.created_by as emp_id'),
                DB::raw('SUM(invoice_items.quantity * products.cost_price) as total_cost')
            )
            ->whereNotNull('invoices.created_by')
            ->groupBy('emp_id')
            ->pluck('total_cost', 'emp_id')
            ->toArray();

        return $costs;
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
