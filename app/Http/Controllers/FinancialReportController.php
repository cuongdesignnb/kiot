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
        // REVENUE / COGS / GROSS PROFIT via MetricService
        // (see metric_dictionary_reports.md for formula invariants)
        // Chuẩn KiotViet (theo file PDF BCKQHDKD):
        //   (1) Doanh thu bán hàng          = gross_revenue
        //   (2) Giảm trừ doanh thu          = (2.1) + (2.2)
        //   (2.1) Chiết khấu hóa đơn        = invoice_discount
        //   (2.2) Giá trị hàng bán trả lại  = return_value
        //   (3) Doanh thu thuần             = (1) - (2)
        //   (4) Giá vốn hàng bán            = cogs_net (đã trừ giá vốn trả hàng)
        //   (5) Lợi nhuận gộp               = (3) - (4)
        // ══════════════════════════════════════
        $metrics          = \App\Support\Reports\MetricService::compute($startDate, $endDate, $branchId);
        $totalSales       = $metrics['gross_revenue'];        // (1)
        $invoiceDiscounts = $metrics['invoice_discount'];     // (2.1)
        $salesReturns     = $metrics['return_value'];         // (2.2)
        $revenueDeductions = $invoiceDiscounts + $salesReturns; // (2)
        $netRevenue       = $totalSales - $revenueDeductions; // (3)
        $cogs             = $metrics['cogs_net'];             // (4)
        $grossProfit      = $netRevenue - $cogs;              // (5)

        // ══════════════════════════════════════
        // (6) CHI PHÍ — từ phiếu chi (cash_flows type=payment)
        // Loại trừ: chi trả NCC (đã vào giá vốn), điều chỉnh công nợ
        // ══════════════════════════════════════
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';

        $expenseQ = CashFlow::where('type', 'payment')
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->whereNotIn('category', [
                'Chi tiền trả NCC',
                'Chi thanh toan NCC',
                'Điều chỉnh công nợ',
                'Chuyển/Rút',
            ]);

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

        // ══════════════════════════════════════
        // (8) THU NHẬP KHÁC — từ phiếu thu (cash_flows type=receipt)
        // Loại trừ: thu nợ KH (đã vào doanh thu), chuyển/rút, điều chỉnh công nợ
        // ══════════════════════════════════════
        $otherIncomeQ = CashFlow::where('type', 'receipt')
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->whereNotIn('category', [
                'Thu tiền khách trả',
                'Thu nợ khách hàng',
                'Điều chỉnh công nợ',
                'Chuyển/Rút',
                '',
            ]);

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

        $totalOtherIncome = array_sum(array_column($otherIncomeByCategory, 'amount'));

        // ══════════════════════════════════════
        // (9) CHI PHÍ KHÁC — cash_flows category chứa "khác"
        // Nếu chưa có, để 0 — user có thể phân nhóm lại sau
        // ══════════════════════════════════════
        $otherExpenseQ = CashFlow::where('type', 'payment')
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->where(function ($q) {
                $q->where('category', 'LIKE', '%khác%')
                  ->orWhere('category', 'LIKE', '%Khác%');
            });

        $otherExpensesByCategory = (clone $otherExpenseQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Chi phí khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();

        $totalOtherExpenses = array_sum(array_column($otherExpensesByCategory, 'amount'));

        // (7) Lợi nhuận từ hoạt động kinh doanh = (5) - (6)
        $operatingProfit = $grossProfit - $totalExpenses;

        // (10) Lợi nhuận thuần = (7) + (8) - (9)
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
                // (1) Doanh thu bán hàng
                'totalSales'        => $totalSales,
                // (2) Giảm trừ doanh thu = (2.1) + (2.2)
                'revenueDeductions' => $revenueDeductions,
                'invoiceDiscounts'  => $invoiceDiscounts, // (2.1)
                'salesReturns'      => $salesReturns,     // (2.2)
                // (3) Doanh thu thuần = (1) - (2)
                'netRevenue'        => $netRevenue,
                // (4) Giá vốn hàng bán
                'cogs'              => $cogs,
                // (5) Lợi nhuận gộp = (3) - (4)
                'grossProfit'       => $grossProfit,

                // (6) Chi phí
                'expensesByCategory' => $expensesByCategory,
                'totalExpenses'      => $totalExpenses,

                // (7) Lợi nhuận HĐKD = (5) - (6)
                'operatingProfit'    => $operatingProfit,

                // (8) Thu nhập khác
                'otherIncomeItems'   => $otherIncomeByCategory,
                'totalOtherIncome'   => $totalOtherIncome,

                // (9) Chi phí khác
                'otherExpensesItems' => $otherExpensesByCategory,
                'totalOtherExpenses' => $totalOtherExpenses,

                // (10) Lợi nhuận thuần = (7) + (8) - (9)
                'netProfit'          => $netProfit,
            ],
        ]);
    }
}
