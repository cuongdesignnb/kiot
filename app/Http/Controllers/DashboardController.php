<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        // ═══════════════════════════════════════
        // 1. KEY METRICS
        // ═══════════════════════════════════════

        // Doanh thu hôm nay (từ hóa đơn)
        $todayRevenue = Invoice::whereDate('created_at', $today)->sum('total');
        $yesterdayRevenue = Invoice::whereDate('created_at', $today->copy()->subDay())->sum('total');

        // Đơn hàng hôm nay
        $todayOrders = Invoice::whereDate('created_at', $today)->count();
        $yesterdayOrders = Invoice::whereDate('created_at', $today->copy()->subDay())->count();

        // Doanh thu tháng này
        $thisMonthRevenue = Invoice::whereBetween('created_at', [$startOfMonth, Carbon::now()])->sum('total');
        $lastMonthRevenue = Invoice::whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])->sum('total');

        // Tổng tồn kho
        $totalProductsInStock = Product::sum('stock_quantity');
        $totalProductCount = Product::count();

        // Lợi nhuận gộp tháng này (doanh thu - giá vốn)
        $thisMonthCost = 0;
        $invoiceItemsThisMonth = InvoiceItem::whereHas('invoice', function ($q) use ($startOfMonth) {
            $q->where('created_at', '>=', $startOfMonth);
        })->with('product')->get();

        foreach ($invoiceItemsThisMonth as $item) {
            $costPrice = $item->product->cost_price ?? 0;
            $thisMonthCost += $item->quantity * $costPrice;
        }
        $thisMonthProfit = $thisMonthRevenue - $thisMonthCost;

        // Nhập hàng tháng này
        $thisMonthPurchase = Purchase::where('created_at', '>=', $startOfMonth)->sum('total_amount');

        // Trả hàng tháng này
        $thisMonthReturn = OrderReturn::where('created_at', '>=', $startOfMonth)->sum('total');

        // Khách hàng mới tháng này
        $newCustomersThisMonth = Customer::where('created_at', '>=', $startOfMonth)->count();
        $totalCustomers = Customer::count();

        // Nợ phải thu (khách nợ)
        try {
            $totalCustomerDebt = Customer::where('debt', '>', 0)->sum('debt');
        } catch (\Exception $e) {
            $totalCustomerDebt = 0;
        }

        // Nợ phải trả (nợ NCC)
        $totalSupplierDebt = Purchase::where('status', 'completed')
            ->whereRaw('total_amount > paid_amount')
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as total_debt')
            ->value('total_debt') ?? 0;

        // ═══════════════════════════════════════
        // 2. BIỂU ĐỒ DOANH THU 30 NGÀY
        // ═══════════════════════════════════════
        $revenueChart = ['labels' => [], 'revenue' => [], 'orders' => []];
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $revenueChart['labels'][] = $date->format('d/m');
            $revenueChart['revenue'][] = (float) Invoice::whereDate('created_at', $date)->sum('total');
            $revenueChart['orders'][] = (int) Invoice::whereDate('created_at', $date)->count();
        }

        // ═══════════════════════════════════════
        // 3. BIỂU ĐỒ THU CHI THÁNG NÀY (theo tuần)
        // ═══════════════════════════════════════
        $cashFlowChart = ['labels' => [], 'receipts' => [], 'payments' => []];
        $weeksInMonth = ceil($today->day / 7);
        for ($w = 1; $w <= min($weeksInMonth + 1, 5); $w++) {
            $weekStart = $startOfMonth->copy()->addDays(($w - 1) * 7);
            $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
            if ($weekStart->gt(Carbon::now())) break;

            $cashFlowChart['labels'][] = 'Tuần ' . $w;
            $cashFlowChart['receipts'][] = (float) CashFlow::where('type', 'receipt')
                ->whereBetween('created_at', [$weekStart, $weekEnd])->sum('amount');
            $cashFlowChart['payments'][] = (float) CashFlow::where('type', 'payment')
                ->whereBetween('created_at', [$weekStart, $weekEnd])->sum('amount');
        }

        // ═══════════════════════════════════════
        // 4. TOP 10 SẢN PHẨM BÁN CHẠY THÁNG NÀY
        // ═══════════════════════════════════════
        $topProducts = InvoiceItem::select('product_id', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(quantity * price) as total_revenue'))
            ->whereHas('invoice', function ($q) use ($startOfMonth) {
                $q->where('created_at', '>=', $startOfMonth);
            })
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->with('product:id,name,sku')
            ->get()
            ->map(fn($item) => [
                'name' => $item->product->name ?? 'N/A',
                'sku' => $item->product->sku ?? '',
                'qty' => (int) $item->total_qty,
                'revenue' => (float) $item->total_revenue,
            ]);

        // ═══════════════════════════════════════
        // 5. SẢN PHẨM SẮP HẾT HÀNG (< 5)
        // ═══════════════════════════════════════
        $lowStockProducts = Product::where('stock_quantity', '<=', 5)
            ->where('stock_quantity', '>', 0)
            ->where('is_active', true)
            ->orderBy('stock_quantity')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price']);

        $outOfStockCount = Product::where('stock_quantity', '<=', 0)
            ->where('is_active', true)->count();

        // ═══════════════════════════════════════
        // 6. HOẠT ĐỘNG GẦN ĐÂY
        // ═══════════════════════════════════════
        $recentInvoices = Invoice::with('employee:id,name')
            ->orderByDesc('created_at')->limit(5)
            ->get(['id', 'code', 'total', 'created_at', 'employee_id']);

        $recentPurchases = Purchase::with('supplier:id,name')
            ->orderByDesc('created_at')->limit(5)
            ->get(['id', 'code', 'total_amount', 'created_at', 'supplier_id', 'status']);

        $recentReturns = OrderReturn::orderByDesc('created_at')->limit(3)
            ->get(['id', 'code', 'total', 'created_at']);

        // ═══════════════════════════════════════
        // 7. ĐƠN HÀNG THEO TRẠNG THÁI
        // ═══════════════════════════════════════
        $ordersByStatus = Order::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')->get()
            ->pluck('total', 'status')->toArray();

        return Inertia::render('Dashboard/Index', [
            // Key metrics
            'todayRevenue' => (float) $todayRevenue,
            'yesterdayRevenue' => (float) $yesterdayRevenue,
            'todayOrders' => (int) $todayOrders,
            'yesterdayOrders' => (int) $yesterdayOrders,
            'thisMonthRevenue' => (float) $thisMonthRevenue,
            'lastMonthRevenue' => (float) $lastMonthRevenue,
            'thisMonthProfit' => (float) $thisMonthProfit,
            'thisMonthPurchase' => (float) $thisMonthPurchase,
            'thisMonthReturn' => (float) $thisMonthReturn,
            'totalProductsInStock' => (int) $totalProductsInStock,
            'totalProductCount' => (int) $totalProductCount,
            'newCustomersThisMonth' => (int) $newCustomersThisMonth,
            'totalCustomers' => (int) $totalCustomers,
            'totalCustomerDebt' => (float) $totalCustomerDebt,
            'totalSupplierDebt' => (float) $totalSupplierDebt,
            'outOfStockCount' => (int) $outOfStockCount,

            // Charts
            'revenueChart' => $revenueChart,
            'cashFlowChart' => $cashFlowChart,

            // Lists
            'topProducts' => $topProducts,
            'lowStockProducts' => $lowStockProducts,
            'recentInvoices' => $recentInvoices,
            'recentPurchases' => $recentPurchases,
            'recentReturns' => $recentReturns,
            'ordersByStatus' => $ordersByStatus,

            'branches' => \App\Models\Branch::all(),
        ]);
    }
}
