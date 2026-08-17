<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $startOfLastMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfLastMonth = Carbon::now()->subMonth()->endOfMonth();

        $rankingPeriod = $request->query('ranking_period', 'month');
        if (! in_array($rankingPeriod, ['month', 'quarter', 'year'], true)) {
            $rankingPeriod = 'month';
        }

        $rankingMetric = $request->query('ranking_metric', 'revenue');
        if (! in_array($rankingMetric, ['revenue', 'orders', 'profit'], true)) {
            $rankingMetric = 'revenue';
        }

        $rankingStart = match ($rankingPeriod) {
            'quarter' => Carbon::now()->startOfQuarter(),
            'year' => Carbon::now()->startOfYear(),
            default => Carbon::now()->startOfMonth(),
        };
        $rankingEnd = Carbon::now()->endOfDay();
        $rankingPeriodLabel = match ($rankingPeriod) {
            'quarter' => 'quý này',
            'year' => 'năm nay',
            default => 'tháng này',
        };

        // ═══════════════════════════════════════
        // 1. KEY METRICS
        // ═══════════════════════════════════════

        // Doanh thu hôm nay (từ hóa đơn) — loại hóa đơn đã hủy
        $todayRevenue = Invoice::whereDate('created_at', $today)->where('status', '!=', 'Đã hủy')->sum('total');
        $yesterdayRevenue = Invoice::whereDate('created_at', $today->copy()->subDay())->where('status', '!=', 'Đã hủy')->sum('total');

        // Đơn hàng hôm nay (không tính đơn đã hủy)
        $todayOrders = Invoice::whereDate('created_at', $today)->where('status', '!=', 'Đã hủy')->count();
        $yesterdayOrders = Invoice::whereDate('created_at', $today->copy()->subDay())->where('status', '!=', 'Đã hủy')->count();

        // Tổng tồn kho
        $totalProductsInStock = Product::sum('stock_quantity');
        $totalProductCount = Product::count();

        // Metrics tháng này & tháng trước (MetricService — single source of truth)
        $metricsMonth = \App\Support\Reports\MetricService::compute(
            $startOfMonth,
            Carbon::now()->endOfDay()
        );
        $metricsLastMonth = \App\Support\Reports\MetricService::compute(
            $startOfLastMonth,
            $endOfLastMonth
        );
        $thisMonthRevenue = $metricsMonth['gross_revenue'];
        $lastMonthRevenue = $metricsLastMonth['gross_revenue'];
        $thisMonthCost = $metricsMonth['cogs_net'];

        // Tổng chi phí (phiếu chi) tháng này - trừ các khoản trả NCC (đã tính vào giá vốn)
        $thisMonthExpenses = CashFlow::active()->cashImpacting()->where('type', 'payment')
            ->where('created_at', '>=', $startOfMonth)
            ->where(function ($q) {
                $q->where('category', '!=', 'Chi tiền trả NCC')
                    ->orWhereNull('category');
            })
            ->sum('amount') ?? 0;

        // Lợi nhuận gộp = Doanh thu thuần - Giá vốn thuần (không trừ chi phí — chi phí thuộc LN thuần)
        $thisMonthProfit = $metricsMonth['gross_profit'];

        // Nhập hàng tháng này
        $thisMonthPurchase = Purchase::where('created_at', '>=', $startOfMonth)
            ->where('status', 'completed')
            ->sum('total_amount');

        // Trả hàng tháng này
        $thisMonthReturn = OrderReturn::where('created_at', '>=', $startOfMonth)->sum('total');

        // Khách hàng mới tháng này
        $newCustomersThisMonth = Customer::where('created_at', '>=', $startOfMonth)->count();
        $totalCustomers = Customer::count();

        // Nợ phải thu (khách nợ)
        $totalCustomerDebt = Customer::where('debt_amount', '>', 0)->sum('debt_amount');

        // Nợ phải trả (nợ NCC) - dùng supplier_debt_amount đã được cập nhật khi nhập/trả hàng
        $totalSupplierDebt = Customer::where('is_supplier', true)
            ->where('supplier_debt_amount', '>', 0)
            ->sum('supplier_debt_amount');

        // ═══════════════════════════════════════
        // 2. BIỂU ĐỒ DOANH THU 30 NGÀY
        // ═══════════════════════════════════════
        $revenueChart = ['labels' => [], 'revenue' => [], 'orders' => []];
        $subtotalCol = Schema::hasColumn('invoices', 'subtotal') ? 'subtotal' : 'total';
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $revenueChart['labels'][] = $date->format('d/m');
            $revenueChart['revenue'][] = (float) Invoice::whereDate('created_at', $date)
                ->where('status', '!=', 'Đã hủy')->sum($subtotalCol);
            $revenueChart['orders'][] = (int) Invoice::whereDate('created_at', $date)
                ->where('status', '!=', 'Đã hủy')->count();
        }

        // ═══════════════════════════════════════
        // 3. BIỂU ĐỒ THU CHI THÁNG NÀY (theo tuần)
        // ═══════════════════════════════════════
        $cashFlowChart = ['labels' => [], 'receipts' => [], 'payments' => []];
        $weeksInMonth = ceil($today->day / 7);
        for ($w = 1; $w <= min($weeksInMonth + 1, 5); $w++) {
            $weekStart = $startOfMonth->copy()->addDays(($w - 1) * 7);
            $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
            if ($weekStart->gt(Carbon::now())) {
                break;
            }

            $cashFlowChart['labels'][] = 'Tuần '.$w;
            $cashFlowChart['receipts'][] = (float) CashFlow::active()->cashImpacting()->where('type', 'receipt')
                ->whereNotIn('category', ['Thu nợ khách hàng', 'Điều chỉnh công nợ'])
                ->whereBetween('created_at', [$weekStart, $weekEnd])->sum('amount');
            $cashFlowChart['payments'][] = (float) CashFlow::active()->cashImpacting()->where('type', 'payment')
                ->whereBetween('created_at', [$weekStart, $weekEnd])->sum('amount');
        }

        // ═══════════════════════════════════════
        // 4. TOP 10 SẢN PHẨM BÁN CHẠY THÁNG NÀY
        // ═══════════════════════════════════════
        $topProducts = InvoiceItem::select('product_id', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(quantity * price) as total_revenue'))
            ->whereHas('invoice', function ($q) use ($startOfMonth) {
                $q->where('created_at', '>=', $startOfMonth)
                    ->where('status', '!=', 'Đã hủy');
            })
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->with('product:id,name,sku')
            ->get()
            ->map(fn ($item) => [
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

        // ═══════════════════════════════════════
        // 8. TOP SẢN PHẨM THEO DOANH THU
        // ═══════════════════════════════════════
        $topProductsByRevenue = InvoiceItem::select(
            'product_id',
            DB::raw('SUM(quantity) as total_qty'),
            DB::raw('SUM(quantity * price) as total_revenue'),
            DB::raw('SUM(quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), 0)) as total_cost')
        )
            ->whereHas('invoice', fn ($q) => $q->where('created_at', '>=', $startOfMonth)->where('status', '!=', 'Đã hủy'))
            ->groupBy('product_id')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->with('product:id,name,sku,cost_price')
            ->get()
            ->map(function ($item) {
                $totalCost = (float) ($item->total_cost ?? 0);
                // Fallback: nếu invoice_items.cost_price = 0, dùng product.cost_price
                if ($totalCost == 0 && $item->product) {
                    $totalCost = (float) ($item->product->cost_price ?? 0) * (int) $item->total_qty;
                }

                return [
                    'name' => $item->product->name ?? 'N/A',
                    'sku' => $item->product->sku ?? '',
                    'qty' => (int) $item->total_qty,
                    'revenue' => (float) $item->total_revenue,
                    'cost' => $totalCost,
                    'profit' => (float) ($item->total_revenue - $totalCost),
                ];
            });

        // ═══════════════════════════════════════
        // 9. TOP SẢN PHẨM THEO LỢI NHUẬN
        // ═══════════════════════════════════════
        $allProductSales = InvoiceItem::select(
            'product_id',
            DB::raw('SUM(quantity) as total_qty'),
            DB::raw('SUM(quantity * price) as total_revenue'),
            DB::raw('SUM(quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), 0)) as total_cost')
        )
            ->whereHas('invoice', fn ($q) => $q->where('created_at', '>=', $startOfMonth)->where('status', '!=', 'Đã hủy'))
            ->groupBy('product_id')
            ->with('product:id,name,sku,cost_price')
            ->get()
            ->map(function ($item) {
                $totalCost = (float) ($item->total_cost ?? 0);
                if ($totalCost == 0 && $item->product) {
                    $totalCost = (float) ($item->product->cost_price ?? 0) * (int) $item->total_qty;
                }

                return [
                    'name' => $item->product->name ?? 'N/A',
                    'sku' => $item->product->sku ?? '',
                    'qty' => (int) $item->total_qty,
                    'revenue' => (float) $item->total_revenue,
                    'profit' => (float) ($item->total_revenue - $totalCost),
                ];
            })
            ->sortByDesc('profit')
            ->take(10)
            ->values();

        // ═══════════════════════════════════════
        // 10. TOP KHÁCH HÀNG / NHÂN VIÊN BÁN HÀNG
        // ═══════════════════════════════════════
        // Doanh thu và số đơn lấy từ invoices; lợi nhuận dùng cùng công thức
        // lợi nhuận gộp hiện có của dashboard: doanh thu dòng hàng - giá vốn.
        $buildRankings = function (string $groupColumn, string $relation) use ($rankingStart, $rankingEnd, $rankingMetric) {
            $relationshipFields = $relation === 'customer'
                ? 'customer:id,name,phone,code'
                : 'creator:id,name';

            $sales = Invoice::query()
                ->select(
                    "$groupColumn as ranking_id",
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(total) as total_revenue')
                )
                ->whereNotNull($groupColumn)
                ->whereBetween('created_at', [$rankingStart, $rankingEnd])
                ->where('status', '!=', 'Đã hủy')
                ->whereHas($relation)
                ->groupBy($groupColumn)
                ->with($relationshipFields)
                ->get();

            $profits = InvoiceItem::query()
                ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                ->leftJoin('products', 'invoice_items.product_id', '=', 'products.id')
                ->select(
                    "invoices.$groupColumn as ranking_id",
                    DB::raw('COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as item_revenue'),
                    DB::raw('COALESCE(SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)), 0) as item_cost')
                )
                ->whereNotNull("invoices.$groupColumn")
                ->whereBetween('invoices.created_at', [$rankingStart, $rankingEnd])
                ->where('invoices.status', '!=', 'Đã hủy')
                ->groupBy("invoices.$groupColumn")
                ->get()
                ->keyBy('ranking_id');

            return $sales->map(function ($row) use ($relation, $profits, $rankingMetric) {
                $entity = $row->{$relation};
                $profitRow = $profits->get($row->ranking_id);
                $revenue = (float) $row->total_revenue;
                $profit = (float) ($profitRow?->item_revenue ?? 0) - (float) ($profitRow?->item_cost ?? 0);
                $orders = (int) $row->order_count;
                $value = match ($rankingMetric) {
                    'orders' => $orders,
                    'profit' => $profit,
                    default => $revenue,
                };

                return [
                    'name' => $entity->name ?? 'N/A',
                    'phone' => $entity->phone ?? '',
                    'code' => $entity->code ?? '',
                    'orders' => $orders,
                    'invoices' => $orders,
                    'revenue' => $revenue,
                    'profit' => $profit,
                    'value' => $value,
                ];
            })->sortByDesc('value')->take(10)->values();
        };

        $topCustomerRankings = $buildRankings('customer_id', 'customer');
        $topEmployeeRankings = $buildRankings('created_by', 'creator');

        // ═══════════════════════════════════════
        // 12. BẢNG TỒN KHO ĐẦY ĐỦ
        // ═══════════════════════════════════════
        $inventoryProducts = Product::where('is_active', true)
            ->orderBy('stock_quantity', 'asc')
            ->limit(50)
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price', 'retail_price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => (int) $p->stock_quantity,
                'cost_price' => (float) ($p->cost_price ?? 0),
                'selling_price' => (float) ($p->retail_price ?? 0),
                'stock_value' => (float) (($p->cost_price ?? 0) * $p->stock_quantity),
                'alert' => $p->stock_quantity <= 0 ? 'out' : ($p->stock_quantity <= 5 ? 'low' : 'ok'),
            ]);

        $totalStockValue = Product::where('is_active', true)
            ->selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) as val')
            ->value('val');

        // ═══════════════════════════════════════
        // STEP 24.1 — Operational dashboard metrics
        // ═══════════════════════════════════════
        $opDash = app(\App\Support\Reports\OperationalDashboardService::class);

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
            'totalStockValue' => (float) $totalStockValue,

            // Charts
            'revenueChart' => $revenueChart,
            'cashFlowChart' => $cashFlowChart,

            // Lists
            'topProducts' => $topProducts,
            'topProductsByRevenue' => $topProductsByRevenue,
            'topProductsByProfit' => $allProductSales,
            'topCustomersByRevenue' => $topCustomerRankings,
            'topCustomersByQty' => $topCustomerRankings,
            'topEmployees' => $topEmployeeRankings,
            'topCustomerRankings' => $topCustomerRankings,
            'topEmployeeRankings' => $topEmployeeRankings,
            'rankingPeriod' => $rankingPeriod,
            'rankingPeriodLabel' => $rankingPeriodLabel,
            'rankingMetric' => $rankingMetric,
            'inventoryProducts' => $inventoryProducts,
            'lowStockProducts' => $lowStockProducts,
            'recentInvoices' => $recentInvoices,
            'recentPurchases' => $recentPurchases,
            'recentReturns' => $recentReturns,
            'ordersByStatus' => $ordersByStatus,

            'branches' => \App\Models\Branch::all(),

            // Step 24.1 — Operational control metrics
            'serialControl' => $opDash->getSerialControl(),
            'stockTransferControl' => $opDash->getStockTransferControl(),
            'repairControl' => $opDash->getRepairControl(),
            'warrantyControl' => $opDash->getWarrantyControl(),
            'inventoryRisk' => $opDash->getInventoryRisk(),
            'financeControl' => $opDash->getFinanceControl(),
            'highRiskActivities' => $opDash->getHighRiskActivities(auth()->user()),
            'canViewAuditLog' => auth()->user() ? auth()->user()->hasPermission('system.audit.view') : false,
        ]);
    }
}
