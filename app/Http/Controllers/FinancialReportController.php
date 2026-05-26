<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\Paysheet;
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
        $otherExpenseCategories = [
            'Chi phí khác',
            'Chi phi khac',
            'Khác',
            'Khac',
        ];

        // (6) CHI PHÍ
        $expenseQ = $this->pnlCashFlowBaseQuery('payment', $startDate, $endDate, $branchId)
            ->where(function ($q) use ($otherExpenseCategories) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', $otherExpenseCategories);
            });

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
 
        $payrollExpense = $this->payrollExpenseAmount($startDate, $endDate, $branchId);
        if ($payrollExpense > 0) {
            $expensesByCategory[] = [
                'name' => 'Chi lương nhân viên',
                'amount' => $payrollExpense,
            ];
        }

        $totalExpenses = array_sum(array_column($expensesByCategory, 'amount'));

        // (8) THU NHẬP KHÁC
        $otherIncomeQ = $this->pnlCashFlowBaseQuery('receipt', $startDate, $endDate, $branchId)
            ->where(function ($q) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', [
                      'Thu tiền khách trả',
                      'Thu tien khach tra',
                      'Thu nợ khách hàng',
                      'Thu no khach hang',
                      'Bán hàng',
                      'Ban hang',
                      '',
                  ]);
            });

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

        // (9) CHI PHÍ KHÁC
        $otherExpenseQ = $this->pnlCashFlowBaseQuery('payment', $startDate, $endDate, $branchId)
            ->whereIn('category', $otherExpenseCategories);

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

    private function pnlCashFlowBaseQuery(string $type, Carbon $startDate, Carbon $endDate, $branchId = null)
    {
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';

        $excludedReferenceTypes = [
            'OrderReturn',
            'PurchaseReturn',
            'DebtOffset',
            'DebtOffsetCancel',
            'paysheet',
            'Paysheet',
            'PaysheetPayment',
        ];

        $excludedCategories = [
            'Chi tiền trả hàng khách',
            'Chi tien tra hang khach',
            'Chi trả hàng khách',
            'Chi tra hang khach',

            'Thu tiền NCC trả hàng',
            'Thu tien NCC tra hang',
            'NCC hoàn tiền trả hàng',
            'NCC hoan tien tra hang',

            'Đối trừ công nợ',
            'Doi tru cong no',
            'Hủy đối trừ công nợ',
            'Huy doi tru cong no',

            'Chi lương nhân viên',
            'Chi luong nhan vien',
            'Lương nhân viên',
            'Luong nhan vien',
            'Thanh toán lương',
            'Thanh toan luong',

            'Chi tiền trả NCC',
            'Chi thanh toan NCC',
            'Chi tiền trả NCC hàng',
            'Chi tien tra NCC hang',

            'Điều chỉnh công nợ',
            'Dieu chinh cong no',
            'Chuyển/Rút',
            'Chuyen/Rut',
            '',
        ];

        $query = CashFlow::active()
            ->where('type', $type)
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->where(function ($q) use ($excludedReferenceTypes) {
                $q->whereNull('reference_type')
                  ->orWhereNotIn('reference_type', $excludedReferenceTypes);
            })
            ->where(function ($q) use ($excludedCategories) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', $excludedCategories);
            });

        if (Schema::hasColumn('cash_flows', 'accounting_result')) {
            $query->where('accounting_result', true);
        }

        if ($branchId && Schema::hasColumn('cash_flows', 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function payrollExpenseQuery(Carbon $startDate, Carbon $endDate, $branchId = null)
    {
        $query = Paysheet::query()
            ->whereIn('status', ['calculated', 'locked'])
            ->whereDate('period_start', '>=', $startDate->toDateString())
            ->whereDate('period_end', '<=', $endDate->toDateString());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function payrollExpenseAmount(Carbon $startDate, Carbon $endDate, $branchId = null): float
    {
        return (float) $this->payrollExpenseQuery($startDate, $endDate, $branchId)
            ->sum('total_salary');
    }
}
