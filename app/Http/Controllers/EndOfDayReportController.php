<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EndOfDayReportController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $branchId = $request->input('branch_id');
        $customerId = $request->input('customer_id');
        $employeeId = $request->input('employee_id');
        $createdBy = $request->input('created_by');
        $paymentMethod = $request->input('payment_method');
        $salesChannel = $request->input('sales_channel');

        // Build base invoice query with all filters
        $invoiceQuery = Invoice::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', '!=', 'Đã hủy');

        if ($branchId) $invoiceQuery->where('branch_id', $branchId);
        if ($customerId) $invoiceQuery->where('customer_id', $customerId);
        if ($employeeId) $invoiceQuery->where('employee_id', $employeeId);
        if ($createdBy) $invoiceQuery->where('created_by', $createdBy);
        if ($paymentMethod) $invoiceQuery->where('payment_method', $paymentMethod);
        if ($salesChannel) $invoiceQuery->where('sales_channel', $salesChannel);

        // Get matching invoice IDs for filtering items and returns
        $invoiceIds = (clone $invoiceQuery)->pluck('id');

        // Daily aggregation
        $dailyInvoices = (clone $invoiceQuery)
            ->select(
                DB::raw('DATE(created_at) as report_date'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('COALESCE(SUM(total), 0) as revenue'),
                DB::raw('COALESCE(SUM(other_fees), 0) as other_income'),
                DB::raw('COALESCE(SUM(customer_paid), 0) as customer_paid'),
            )
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get();

        // Daily product quantities
        $dailyQty = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->select(
                DB::raw('DATE(invoices.created_at) as report_date'),
                DB::raw('COALESCE(SUM(invoice_items.quantity), 0) as total_qty')
            )
            ->groupBy('report_date')
            ->pluck('total_qty', 'report_date');

        // Daily returns
        $returnsQuery = OrderReturn::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnsQuery->where('branch_id', $branchId);
        if ($customerId) $returnsQuery->where('customer_id', $customerId);

        $dailyReturns = (clone $returnsQuery)
            ->select(
                DB::raw('DATE(created_at) as report_date'),
                DB::raw('COALESCE(SUM(fee), 0) as return_fee'),
                DB::raw('COALESCE(SUM(total), 0) as return_total')
            )
            ->groupBy('report_date')
            ->get()
            ->keyBy('report_date');

        // Build report rows
        $rows = [];
        $totals = [
            'productQty' => 0,
            'revenue' => 0,
            'otherIncome' => 0,
            'vat' => 0,
            'rounding' => 0,
            'returnFee' => 0,
            'netReceived' => 0,
        ];

        foreach ($dailyInvoices as $day) {
            $date = $day->report_date;
            $qty = (int) ($dailyQty[$date] ?? 0);
            $revenue = (float) $day->revenue;
            $otherIncome = (float) $day->other_income;
            $returnData = $dailyReturns[$date] ?? null;
            $returnFee = $returnData ? (float) $returnData->return_fee : 0;
            $netReceived = $revenue + $otherIncome - $returnFee;

            $rows[] = [
                'date' => Carbon::parse($date)->format('d/m/Y'),
                'productQty' => $qty,
                'revenue' => $revenue,
                'otherIncome' => $otherIncome,
                'vat' => 0,
                'rounding' => 0,
                'returnFee' => $returnFee,
                'netReceived' => $netReceived,
            ];

            $totals['productQty'] += $qty;
            $totals['revenue'] += $revenue;
            $totals['otherIncome'] += $otherIncome;
            $totals['returnFee'] += $returnFee;
            $totals['netReceived'] += $netReceived;
        }

        // Filter options for dropdowns
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $employees = Employee::orderBy('name')->get(['id', 'name']);
        $paymentMethods = Invoice::whereNotNull('payment_method')
            ->distinct()
            ->pluck('payment_method')
            ->filter()
            ->values();
        $salesChannels = Invoice::whereNotNull('sales_channel')
            ->distinct()
            ->pluck('sales_channel')
            ->filter()
            ->values();

        // Get selected branch name for report header
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/EndOfDayReport', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'employee_id' => $employeeId,
                'created_by' => $createdBy,
                'payment_method' => $paymentMethod,
                'sales_channel' => $salesChannel,
            ],
            'rows' => $rows,
            'totals' => $totals,
            'branchName' => $branchName,
            'branches' => $branches,
            'employees' => $employees,
            'paymentMethods' => $paymentMethods,
            'salesChannels' => $salesChannels,
        ]);
    }
}
