<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class FinancialReportController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->input('branch_id');
        $year     = $request->input('year', now()->year);
        $mode     = $request->input('time_mode', 'custom'); // month | custom
        $month    = $request->input('month');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        // Resolve date range
        if ($mode === 'month' && $month) {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate   = Carbon::createFromDate($year, $month, 1)->endOfMonth();
        } elseif ($dateFrom && $dateTo) {
            $startDate = Carbon::parse($dateFrom)->startOfDay();
            $endDate   = Carbon::parse($dateTo)->endOfDay();
        } else {
            $startDate = Carbon::now()->startOfMonth();
            $endDate   = Carbon::now()->endOfDay();
        }

        // ══════════════════════════════════════
        // REVENUE SECTION
        // ══════════════════════════════════════

        // (1) Total Sales Revenue
        $invoiceQ = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $invoiceQ->where('branch_id', $branchId);
        $totalSales = (float) (clone $invoiceQ)->sum('total');

        // (2) Cost of Goods Sold (COGS)
        // Use invoice_items.cost_price snapshot if available, otherwise fallback to products.cost_price
        $invoiceIds = (clone $invoiceQ)->pluck('id');
        $hasItemCostCol = Schema::hasColumn('invoice_items', 'cost_price');
        $costExpr = $hasItemCostCol
            ? 'invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)'
            : 'invoice_items.quantity * COALESCE(products.cost_price, 0)';
        $cogs = (float) DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->sum(DB::raw($costExpr));

        // (3) Sales Returns
        $returnsQ = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnsQ->where('branch_id', $branchId);
        $salesReturns = (float) (clone $returnsQ)->sum('total');

        // (4) Invoice Discounts
        $invoiceDiscounts = (float) (clone $invoiceQ)->sum('discount');

        // (5) Gross Profit = (1) - (2) - (3) - (4)
        $grossProfit = $totalSales - $cogs - $salesReturns - $invoiceDiscounts;

        // ══════════════════════════════════════
        // EXPENSES SECTION (from cash_flows)
        // ══════════════════════════════════════
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';

        $expenseQ = CashFlow::where('type', 'payment')
            ->where(function ($q) use ($timeColumn, $startDate, $endDate) {
                $q->whereBetween($timeColumn, [$startDate, $endDate]);
            })
            ->where('category', '!=', 'Chi tiền trả NCC'); // Exclude NCC payments (already in COGS)

        // Breakdown expenses by category
        $expensesByCategory = (clone $expenseQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Chi khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();

        $totalExpenses = array_sum(array_column($expensesByCategory, 'amount'));

        // (7) Operating Profit = (5) - (6)
        $operatingProfit = $grossProfit - $totalExpenses;

        // ══════════════════════════════════════
        // OTHER INCOME (from cash_flows receipts)
        // ══════════════════════════════════════
        $otherIncomeQ = CashFlow::where('type', 'receipt')
            ->where(function ($q) use ($timeColumn, $startDate, $endDate) {
                $q->whereBetween($timeColumn, [$startDate, $endDate]);
            })
            ->whereNotIn('category', ['Thu tiền khách trả', 'Thu nợ khách hàng', 'Điều chỉnh công nợ', 'Chuyển/Rút', '']);

        $otherIncomeByCategory = (clone $otherIncomeQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Thu nhập khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();

        // Also add return-related income items
        $otherIncomeItems = [];

        // Phí trả hàng (return fees collected)
        $otherIncomeItems[] = ['name' => 'Phí trả hàng', 'amount' => 0];
        // Chênh lệch làm tròn nhập hàng
        $otherIncomeItems[] = ['name' => 'Chênh lệch làm tròn nhập hàng', 'amount' => 0];
        // Chênh lệch làm tròn bán hàng
        $otherIncomeItems[] = ['name' => 'Chênh lệch làm tròn bán hàng', 'amount' => 0];
        // Chiết khấu thanh toán từ NCC
        $otherIncomeItems[] = ['name' => 'Chiết khấu thanh toán từ NCC', 'amount' => 0];

        $mergedOtherIncome = array_merge($otherIncomeByCategory, $otherIncomeItems);
        $totalOtherIncome = array_sum(array_column($mergedOtherIncome, 'amount'));

        // ══════════════════════════════════════
        // OTHER EXPENSES
        // ══════════════════════════════════════
        $totalOtherExpenses = 0;

        // (10) Net Profit = (7) + (8) - (9)
        $netProfit = $operatingProfit + $totalOtherIncome - $totalOtherExpenses;

        // ══════════════════════════════════════
        // FILTER OPTIONS
        // ══════════════════════════════════════
        $branches  = Branch::orderBy('name')->get(['id', 'name', 'address']);
        $branchObj = $branchId ? Branch::find($branchId) : null;
        $branchName = $branchObj ? $branchObj->name : 'Tất cả chi nhánh';
        $branchAddress = $branchObj ? $branchObj->address : '';

        return Inertia::render('Reports/FinancialReport', [
            'filters' => [
                'branch_id' => $branchId,
                'year'      => (int) $year,
                'time_mode' => $mode,
                'month'     => $month,
                'date_from' => $startDate->format('Y-m-d'),
                'date_to'   => $endDate->format('Y-m-d'),
            ],
            'dateFromDisplay' => $startDate->format('d/m/Y'),
            'dateToDisplay'   => $endDate->format('d/m/Y'),
            'branchName'      => $branchName,
            'branchAddress'   => $branchAddress,
            'branches'        => $branches,
            'report' => [
                // Revenue
                'totalSales'       => $totalSales,
                'cogs'             => $cogs,
                'salesReturns'     => $salesReturns,
                'invoiceDiscounts' => $invoiceDiscounts,
                'grossProfit'      => $grossProfit,

                // Expenses
                'expensesByCategory' => $expensesByCategory,
                'totalExpenses'      => $totalExpenses,

                // Operating profit
                'operatingProfit'    => $operatingProfit,

                // Other income
                'otherIncomeItems'   => $mergedOtherIncome,
                'totalOtherIncome'   => $totalOtherIncome,

                // Other expenses
                'totalOtherExpenses' => $totalOtherExpenses,

                // Net profit
                'netProfit'          => $netProfit,
            ],
        ]);
    }
}
