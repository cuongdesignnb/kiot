<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Branch;
use App\Services\ProductSearchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Parse common date range and branch filters
     */
    private function parseFilters(Request $request): array
    {
        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $branchId = $request->input('branch_id');

        // Previous period (same duration)
        $duration = $dateFrom->diffInDays($dateTo);
        $prevFrom = $dateFrom->copy()->subDays($duration + 1)->startOfDay();
        $prevTo = $dateFrom->copy()->subDay()->endOfDay();

        return compact('dateFrom', 'dateTo', 'branchId', 'prevFrom', 'prevTo', 'duration');
    }

    /**
     * Scope invoices by branch
     */
    private function scopeBranch($query, $branchId)
    {
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        return $query;
    }

    // ═══════════════════════════════════════
    // 1. TỔNG QUAN KINH DOANH
    // ═══════════════════════════════════════
    public function businessOverview(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];
        $prevFrom = $f['prevFrom'];
        $prevTo = $f['prevTo'];
        $duration = $f['duration'];
        $days = max($duration, 1);

        // Current period (via MetricService — single source of truth)
        $m = \App\Support\Reports\MetricService::compute($dateFrom, $dateTo, $branchId);
        $invoiceCount = $m['invoice_count'];
        $revenue      = $m['gross_revenue'];
        $returns      = $m['return_value'];
        $netRevenue   = $m['net_revenue'];
        $totalCost    = $m['cogs_net'];
        $grossProfit  = $m['gross_profit'];

        // Previous period
        $pm = \App\Support\Reports\MetricService::compute($prevFrom, $prevTo, $branchId);
        $prevInvoiceCount = $pm['invoice_count'];
        $prevRevenue      = $pm['gross_revenue'];
        $prevReturns      = $pm['return_value'];
        $prevNetRevenue   = $pm['net_revenue'];
        $prevTotalCost    = $pm['cogs_net'];
        $prevGrossProfit  = $pm['gross_profit'];

        // Chart data — daily breakdown (also via MetricService for consistency)
        $chartLabels = [];
        $chartRevenue = [];
        $chartReturns = [];
        $chartCost = [];
        $chartProfit = [];

        $current = $dateFrom->copy();
        while ($current->lte($dateTo)) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();
            $dm = \App\Support\Reports\MetricService::compute($dayStart, $dayEnd, $branchId);

            $chartLabels[]  = $current->format('d/m/Y');
            $chartRevenue[] = $dm['gross_revenue'];
            $chartReturns[] = $dm['return_value'];
            $chartCost[]    = $dm['cogs_net'];
            $chartProfit[]  = $dm['gross_profit'];

            $current->addDay();
        }

        return Inertia::render('Reports/BusinessOverview', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'invoiceCount' => $invoiceCount,
            'revenue' => $revenue,
            'returns' => $returns,
            'netRevenue' => $netRevenue,
            'totalCost' => $totalCost,
            'grossProfit' => $grossProfit,
            'avgPerDay' => [
                'invoiceCount' => round($invoiceCount / $days, 1),
                'revenue' => round($revenue / $days),
                'returns' => round($returns / $days),
                'netRevenue' => round($netRevenue / $days),
                'totalCost' => round($totalCost / $days),
                'grossProfit' => round($grossProfit / $days),
            ],
            'prevPeriod' => [
                'invoiceCount' => $prevInvoiceCount,
                'revenue' => $prevRevenue,
                'returns' => $prevReturns,
                'netRevenue' => $prevNetRevenue,
                'totalCost' => $prevTotalCost,
                'grossProfit' => $prevGrossProfit,
            ],
            'chart' => [
                'labels' => $chartLabels,
                'revenue' => $chartRevenue,
                'returns' => $chartReturns,
                'cost' => $chartCost,
                'profit' => $chartProfit,
            ],
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 2. CHI PHÍ - LỢI NHUẬN
    // ═══════════════════════════════════════
    public function costProfit(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];
        $prevFrom = $f['prevFrom'];
        $prevTo = $f['prevTo'];
        $days = max($f['duration'], 1);

        // Net revenue, COGS, gross profit via MetricService
        $m = \App\Support\Reports\MetricService::compute($dateFrom, $dateTo, $branchId);
        $revenue     = $m['gross_revenue'];
        $returns     = $m['return_value'];
        $netRevenue  = $m['net_revenue'];
        $cogs        = $m['cogs_net'];
        $grossProfit = $m['gross_profit'];

        // Operating expenses from CashFlow (type = 'payment'), excluding NCC payments (already in COGS)
        $expenseQuery = CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('category', '!=', 'Chi tiền trả NCC');
        $totalExpenses = (float) $expenseQuery->sum('amount');

        // Expense breakdown by category
        $expenseCategories = CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('category', '!=', 'Chi tiền trả NCC')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Khác',
                'total' => (float) $row->total,
                'percent' => $netRevenue > 0 ? round(($row->total / $netRevenue) * 100, 2) : 0,
            ]);

        // Other income (type = 'receipt', not from sales)
        $otherIncome = (float) CashFlow::active()->where('type', 'receipt')
            ->where('category', '!=', 'Bán hàng')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('amount');

        $netProfit = $netRevenue - $cogs - $totalExpenses + $otherIncome;

        // Previous period expenses (also excluding NCC payments)
        $prevExpenses = (float) CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->where('category', '!=', 'Chi tiền trả NCC')
            ->sum('amount');

        $expensePerDay = round($totalExpenses / $days);
        $costRevenueRatio = $netRevenue > 0 ? round(($totalExpenses / $netRevenue) * 100, 2) : 0;

        // Previous period net revenue (consistent via MetricService)
        $pm = \App\Support\Reports\MetricService::compute($prevFrom, $prevTo, $branchId);
        $prevNetRevenue = $pm['net_revenue'];
        $prevCostRatio = $prevNetRevenue > 0 ? round(($prevExpenses / $prevNetRevenue) * 100, 2) : 0;

        return Inertia::render('Reports/CostProfit', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'netRevenue' => $netRevenue,
            'grossProfit' => $grossProfit,
            'totalExpenses' => $totalExpenses,
            'otherIncome' => $otherIncome,
            'netProfit' => $netProfit,
            'expensePerDay' => $expensePerDay,
            'costRevenueRatio' => $costRevenueRatio,
            'prevExpenses' => $prevExpenses,
            'prevCostRatio' => $prevCostRatio,
            'expenseCategories' => $expenseCategories,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 3. TỔNG QUAN HÀNG HÓA
    // ═══════════════════════════════════════
    public function productOverview(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];

        // Products sold
        $soldItems = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo, $branchId) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
            if ($branchId) $q->where('branch_id', $branchId);
        })->with('product:id,category_id');

        $soldData = $soldItems->get();
        $uniqueProductsSold = $soldData->pluck('product_id')->unique()->count();
        $totalItemsSold = $soldData->sum('quantity');
        $totalSoldRevenue = $soldData->sum(fn($i) => $i->quantity * $i->price);
        $totalSoldCost = $soldData->sum(fn($i) => $i->quantity * ($i->cost_price ?? 0));
        $avgRevenuePerProduct = $uniqueProductsSold > 0 ? round($totalSoldRevenue / $uniqueProductsSold) : 0;
        $avgProfitPerProduct = $uniqueProductsSold > 0 ? round(($totalSoldRevenue - $totalSoldCost) / $uniqueProductsSold) : 0;

        // Top product groups (by category) - best sellers
        $topGroupsBestSeller = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo, $branchId) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
            if ($branchId) $q->where('branch_id', $branchId);
        })
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category_name',
                DB::raw('SUM(invoice_items.quantity) as total_qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as total_revenue')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'name' => $row->category_name,
                    'qty' => (int) $row->total_qty,
                    'returns' => 0,
                    'revenue' => (float) $row->total_revenue,
                    'profit' => 0,
                ];
            });

        // Top product groups - slow sellers
        $allCategoryIds = Category::pluck('id', 'name');
        $soldCategoryIds = InvoiceItem::whereHas('invoice', function ($q) use ($dateFrom, $dateTo, $branchId) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'Đã hủy');
            if ($branchId) $q->where('branch_id', $branchId);
        })
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->select(
                'products.category_id',
                DB::raw('SUM(invoice_items.quantity) as total_qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as total_revenue')
            )
            ->groupBy('products.category_id')
            ->orderBy('total_qty')
            ->limit(10)
            ->get();

        $topGroupsSlowSeller = Category::select('categories.id', 'categories.name')
            ->leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->leftJoin('invoice_items', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('invoices', function ($j) use ($dateFrom, $dateTo, $branchId) {
                $j->on('invoice_items.invoice_id', '=', 'invoices.id')
                    ->whereBetween('invoices.created_at', [$dateFrom, $dateTo])
                    ->where('invoices.status', '!=', 'Đã hủy');
                if ($branchId) $j->where('invoices.branch_id', $branchId);
            })
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as total_qty')
            ->selectRaw('COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue')
            ->orderBy('total_qty')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'name' => $row->name,
                'qty' => (int) $row->total_qty,
                'returns' => 0,
                'revenue' => (float) $row->total_revenue,
                'profit' => 0,
            ]);

        return Inertia::render('Reports/ProductOverview', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'uniqueProductsSold' => $uniqueProductsSold,
            'totalItemsSold' => $totalItemsSold,
            'avgRevenuePerProduct' => $avgRevenuePerProduct,
            'avgProfitPerProduct' => $avgProfitPerProduct,
            'topGroupsBestSeller' => $topGroupsBestSeller,
            'topGroupsSlowSeller' => $topGroupsSlowSeller,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 4. TỒN KHO
    // ═══════════════════════════════════════
    public function inventory(Request $request)
    {
        $branchId = $request->input('branch_id');

        $productQuery = Product::where('is_active', true);
        $productsInStock = (clone $productQuery)->where('stock_quantity', '>', 0)->count();
        $totalStock = (int) (clone $productQuery)->sum('stock_quantity');
        $totalStockValue = (float) (clone $productQuery)
            ->selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) as val')
            ->value('val');

        // Out of stock alerts
        $outOfStockToday = Product::where('is_active', true)->where('stock_quantity', '<=', 0)->count();

        // Products with low stock (< min_stock)
        $lowStock7Days = Product::where('is_active', true)
            ->whereColumn('stock_quantity', '<=', DB::raw('COALESCE(min_stock, 0)'))
            ->where('stock_quantity', '>', 0)
            ->count();

        $lowStock30Days = Product::where('is_active', true)
            ->where('stock_quantity', '<=', 5)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Dead stock (not sold)
        $unsoldProducts = Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->whereDoesntHave('warranties') // placeholder
            ->count();
        // More accurate: products not in any invoice in last 90 days
        $soldProductIds = InvoiceItem::whereHas('invoice', function ($q) {
            $q->where('created_at', '>=', Carbon::now()->subDays(90))
                ->where('status', '!=', 'Đã hủy');
        })->pluck('product_id')->unique();

        $deadStockCount = Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->whereNotIn('id', $soldProductIds)
            ->count();

        $deadStockValue = (float) Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->whereNotIn('id', $soldProductIds)
            ->selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) as val')
            ->value('val');

        // Overstock
        $overstockCount = Product::where('is_active', true)
            ->whereNotNull('max_stock')
            ->whereColumn('stock_quantity', '>', 'max_stock')
            ->count();

        $overstockValue = (float) Product::where('is_active', true)
            ->whereNotNull('max_stock')
            ->whereColumn('stock_quantity', '>', 'max_stock')
            ->selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) as val')
            ->value('val');

        // Top groups by stock
        $topGroupsByStock = Product::where('products.is_active', true)
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.name',
                DB::raw('SUM(products.stock_quantity) as total_stock'),
                DB::raw('SUM(products.stock_quantity * products.cost_price) as stock_value')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_stock')
            ->limit(10)
            ->get();

        // Top products by stock
        $topProductsByStock = Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->orderByDesc('stock_quantity')
            ->limit(10)
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price'])
            ->map(fn($p) => [
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => (int) $p->stock_quantity,
                'value' => (float) ($p->stock_quantity * ($p->cost_price ?? 0)),
            ]);

        // Top groups by value
        $topGroupsByValue = Product::where('products.is_active', true)
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.name',
                DB::raw('SUM(products.stock_quantity) as total_stock'),
                DB::raw('SUM(products.stock_quantity * products.cost_price) as stock_value')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('stock_value')
            ->limit(10)
            ->get();

        // Top products by value
        $topProductsByValue = Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->orderByDesc(DB::raw('stock_quantity * cost_price'))
            ->limit(10)
            ->get(['id', 'name', 'sku', 'stock_quantity', 'cost_price'])
            ->map(fn($p) => [
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => (int) $p->stock_quantity,
                'value' => (float) ($p->stock_quantity * ($p->cost_price ?? 0)),
            ]);

        return Inertia::render('Reports/Inventory', [
            'filters' => ['branch_id' => $branchId],
            'productsInStock' => $productsInStock,
            'totalStock' => $totalStock,
            'totalStockValue' => $totalStockValue,
            'outOfStockToday' => $outOfStockToday,
            'lowStock7Days' => $lowStock7Days,
            'lowStock30Days' => $lowStock30Days,
            'deadStockCount' => $deadStockCount,
            'deadStockValue' => $deadStockValue,
            'overstockCount' => $overstockCount,
            'overstockValue' => $overstockValue,
            'topGroupsByStock' => $topGroupsByStock,
            'topGroupsByValue' => $topGroupsByValue,
            'topProductsByStock' => $topProductsByStock,
            'topProductsByValue' => $topProductsByValue,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 5. PHÂN LOẠI HÀNG HÓA
    // ═══════════════════════════════════════
    public function productCategory(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];

        $categories = Category::leftJoin('products', 'categories.id', '=', 'products.category_id')
            ->leftJoin('invoice_items', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('invoices', function ($j) use ($dateFrom, $dateTo, $branchId) {
                $j->on('invoice_items.invoice_id', '=', 'invoices.id')
                    ->whereBetween('invoices.created_at', [$dateFrom, $dateTo])
                    ->where('invoices.status', '!=', 'Đã hủy');
                if ($branchId) $j->where('invoices.branch_id', $branchId);
            })
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('COALESCE(SUM(invoice_items.quantity), 0) as total_sold'),
                DB::raw('COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn($row) => [
                'name' => $row->name,
                'sold' => (int) $row->total_sold,
                'returns' => 0,
                'revenue' => (float) $row->total_revenue,
                'profit' => 0,
            ]);

        return Inertia::render('Reports/ProductCategory', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'categories' => $categories,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 6. TỔNG QUAN KHÁCH HÀNG
    // ═══════════════════════════════════════
    public function customerOverview(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];

        // Total unique customers in period
        $invoiceQ = Invoice::active()->whereBetween('created_at', [$dateFrom, $dateTo]);
        $this->scopeBranch($invoiceQ, $branchId);
        $totalCustomersInPeriod = (clone $invoiceQ)->whereNotNull('customer_id')
            ->distinct('customer_id')->count('customer_id');

        $totalRevenueInPeriod = (float) (clone $invoiceQ)->sum('total');

        // New customers (created in this period)
        $newCustomerIds = Customer::whereBetween('created_at', [$dateFrom, $dateTo])
            ->pluck('id');
        $newCustomerCount = $newCustomerIds->count();

        // Revenue from new customers
        $newCustomerRevenue = (float) Invoice::active()->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('customer_id', $newCustomerIds)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        // Old customers (existed before this period)
        $oldCustomerRevQ = Invoice::active()->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('customer_id')
            ->whereNotIn('customer_id', $newCustomerIds)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));
        $oldCustomerCount = (clone $oldCustomerRevQ)->distinct('customer_id')->count('customer_id');
        $oldCustomerRevenue = (float) (clone $oldCustomerRevQ)->sum('total');

        // Walk-in (no customer_id)
        $walkinRevenue = (float) Invoice::active()->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNull('customer_id')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');
        $walkinCount = Invoice::active()->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNull('customer_id')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->count();

        // Chart data — weekly breakdown
        $chartLabels = [];
        $chartOld = [];
        $chartNew = [];
        $chartWalkin = [];
        $chartRevOld = [];
        $chartRevNew = [];
        $chartRevWalkin = [];

        $current = $dateFrom->copy();
        $weekNum = 1;
        while ($current->lte($dateTo)) {
            $weekEnd = $current->copy()->addDays(6)->min($dateTo);
            $chartLabels[] = $current->format('d/m');

            $weekInvQ = Invoice::active()->whereBetween('created_at', [$current, $weekEnd->copy()->endOfDay()])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

            $weekNewCustIds = Customer::whereBetween('created_at', [$dateFrom, $weekEnd])->pluck('id');

            $chartNew[] = (clone $weekInvQ)->whereIn('customer_id', $weekNewCustIds)->distinct('customer_id')->count('customer_id');
            $chartOld[] = (clone $weekInvQ)->whereNotNull('customer_id')->whereNotIn('customer_id', $weekNewCustIds)->distinct('customer_id')->count('customer_id');
            $chartWalkin[] = (clone $weekInvQ)->whereNull('customer_id')->count();

            $chartRevNew[] = (float) (clone $weekInvQ)->whereIn('customer_id', $weekNewCustIds)->sum('total');
            $chartRevOld[] = (float) (clone $weekInvQ)->whereNotNull('customer_id')->whereNotIn('customer_id', $weekNewCustIds)->sum('total');
            $chartRevWalkin[] = (float) (clone $weekInvQ)->whereNull('customer_id')->sum('total');

            $current = $weekEnd->copy()->addDay();
        }

        return Inertia::render('Reports/CustomerOverview', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'totalCustomers' => $totalCustomersInPeriod + $walkinCount,
            'totalRevenue' => $totalRevenueInPeriod,
            'customerBreakdown' => [
                'old' => ['count' => $oldCustomerCount, 'revenue' => $oldCustomerRevenue],
                'new' => ['count' => $newCustomerCount, 'revenue' => $newCustomerRevenue],
                'walkin' => ['count' => $walkinCount, 'revenue' => $walkinRevenue],
            ],
            'chart' => [
                'labels' => $chartLabels,
                'countOld' => $chartOld,
                'countNew' => $chartNew,
                'countWalkin' => $chartWalkin,
                'revOld' => $chartRevOld,
                'revNew' => $chartRevNew,
                'revWalkin' => $chartRevWalkin,
            ],
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 7. PHÂN LOẠI KHÁCH HÀNG (RFM Segmentation)
    // ═══════════════════════════════════════
    public function customerCategory(Request $request)
    {
        $f = $this->parseFilters($request);
        $dateFrom = $f['dateFrom'];
        $dateTo = $f['dateTo'];
        $branchId = $f['branchId'];

        // Define 5 segments based on purchase behavior
        $segmentDefs = [
            'Trung thành' => ['color' => '#f59e0b', 'desc' => 'Các khách hàng thường xuyên ghé thăm cửa hàng, đã mua hàng nhiều lần với mức chi tiêu lớn. Họ đóng góp nhiều vào doanh thu của cửa hàng.'],
            'Thân thiết'  => ['color' => '#3b82f6', 'desc' => 'Các khách hàng có tần suất trung bình hoặc mới mua gần đây với mức chi tiêu đáng kể. Có tiềm năng phát triển thành nhóm Trung thành.'],
            'Tiềm năng'   => ['color' => '#22c55e', 'desc' => 'Các khách hàng mới mua gần đây với mức chi tiêu trung bình. Có triển vọng trong việc tiếp cận và tạo sự gắn kết.'],
            'Cần quan tâm' => ['color' => '#ef4444', 'desc' => 'Các khách hàng đã từng mua đều đặn, nhưng không quay lại mua hàng trong thời gian gần đây.'],
            'Sắp rời bỏ'  => ['color' => '#9ca3af', 'desc' => 'Các khách hàng có tần suất thấp và đã rất lâu không quay lại mua hàng.'],
        ];

        $customers = Customer::where('is_customer', true)
            ->orWhereNull('is_customer')
            ->get();

        $totalCustomerCount = $customers->count();
        $segments = [];

        foreach ($segmentDefs as $segName => $segInfo) {
            $segments[$segName] = [
                'name' => $segName,
                'color' => $segInfo['color'],
                'desc' => $segInfo['desc'],
                'count' => 0,
                'percent' => 0,
                'revenue' => 0,
                'returns' => 0,
                'profit' => 0,
            ];
        }

        // Classify each customer based on invoice count and recency
        foreach ($customers as $customer) {
            $invoiceCount = Invoice::active()->where('customer_id', $customer->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->count();

            $lastInvoice = Invoice::active()->where('customer_id', $customer->id)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('created_at')
                ->first(['created_at']);

            $daysSinceLast = $lastInvoice
                ? Carbon::now()->diffInDays($lastInvoice->created_at)
                : 999;

            // RFM-style classification
            if ($invoiceCount >= 5 && $daysSinceLast <= 30) {
                $segment = 'Trung thành';
            } elseif ($invoiceCount >= 2 && $daysSinceLast <= 60) {
                $segment = 'Thân thiết';
            } elseif ($invoiceCount >= 1 && $daysSinceLast <= 90) {
                $segment = 'Tiềm năng';
            } elseif ($invoiceCount >= 1 && $daysSinceLast <= 180) {
                $segment = 'Cần quan tâm';
            } else {
                $segment = 'Sắp rời bỏ';
            }

            $segments[$segment]['count']++;

            // Revenue
            $custRevenue = (float) Invoice::active()->where('customer_id', $customer->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->sum('total');
            $segments[$segment]['revenue'] += $custRevenue;

            // Returns
            $custReturns = (float) OrderReturn::where('customer_id', $customer->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->sum('total');
            $segments[$segment]['returns'] += $custReturns;

            // Gross profit (simplified: revenue - cost)
            $custCost = 0;
            $costItems = InvoiceItem::whereHas('invoice', function ($q) use ($customer, $dateFrom, $dateTo, $branchId) {
                $q->where('customer_id', $customer->id)
                    ->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->where('status', '!=', 'Đã hủy');
                if ($branchId) $q->where('branch_id', $branchId);
            })->get();

            foreach ($costItems as $item) {
                $custCost += $item->quantity * ($item->cost_price ?? 0);
            }
            $segments[$segment]['profit'] += ($custRevenue - $custReturns - $custCost);
        }

        // Calculate percentages
        foreach ($segments as &$seg) {
            $seg['percent'] = $totalCustomerCount > 0
                ? round(($seg['count'] / $totalCustomerCount) * 100, 2)
                : 0;
        }

        return Inertia::render('Reports/CustomerCategory', [
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'branch_id' => $branchId,
            ],
            'segments' => array_values($segments),
            'totalCustomers' => $totalCustomerCount,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 8. CÔNG NỢ KHÁCH HÀNG (Enhanced)
    // ═══════════════════════════════════════
    public function customerDebt(Request $request)
    {
        $branchId = $request->input('branch_id');

        // === Summary Cards ===
        $debtorsQuery = Customer::where('debt_amount', '>', 0)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $debtorCount = (clone $debtorsQuery)->count();
        $totalDebt = (float) (clone $debtorsQuery)->sum('debt_amount');

        // Giá trị nợ / Doanh thu thuần năm nay
        $yearStart = Carbon::now()->startOfYear();
        $yearEnd = Carbon::now()->endOfDay();
        $yearRevenue = (float) Invoice::active()->whereBetween('created_at', [$yearStart, $yearEnd])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');
        $yearReturns = (float) OrderReturn::whereBetween('created_at', [$yearStart, $yearEnd])
            ->sum('total');
        $yearNetRevenue = $yearRevenue - $yearReturns;
        $debtRevenueRatio = $yearNetRevenue > 0 ? round(($totalDebt / $yearNetRevenue) * 100, 2) : 0;

        // === 12-month trend chart ===
        $chartLabels = [];
        $chartDebt = [];
        $chartNetRevenue = [];
        $totalDebtMonthly = 0;
        $monthCount = 0;

        for ($i = 11; $i >= 0; $i--) {
            $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();
            $chartLabels[] = $monthStart->format('m/Y');

            // Monthly debt snapshot (approximate: sum of debt_amount at end of period)
            // For simplicity, use invoices unpaid in that month
            $monthRev = (float) Invoice::active()->whereBetween('created_at', [$monthStart, $monthEnd])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->sum('total');
            $monthRet = (float) OrderReturn::whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('total');
            $monthNetRev = $monthRev - $monthRet;

            $chartNetRevenue[] = $monthNetRev;

            // Approximate monthly debt: proportion of current total debt
            // Since we don't have historical debt snapshots, use a rough approximation
            $monthDebt = $monthNetRev > 0 ? round($totalDebt * ($monthNetRev / max($yearNetRevenue, 1))) : 0;
            $chartDebt[] = max($monthDebt, 0);

            $totalDebtMonthly += max($monthDebt, 0);
            $monthCount++;
        }

        $avgDebtPerMonth = $monthCount > 0 ? round($totalDebtMonthly / $monthCount) : 0;
        $avgDebtRevenueRatio = collect($chartDebt)->zip($chartNetRevenue)->map(function ($pair) {
            return $pair[1] > 0 ? round(($pair[0] / $pair[1]) * 100, 2) : 0;
        })->avg();
        $avgDebtRevenueRatio = round($avgDebtRevenueRatio ?? 0, 2);

        // === Bar chart: Lượng khách theo số ngày nợ ===
        $allDebtors = Customer::where('debt_amount', '>', 0)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'code', 'name', 'phone', 'debt_amount', 'customer_group']);

        $debtByDays = [
            '0-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '91-120' => 0,
            '>120' => 0,
        ];

        $debtorDetails = [];

        foreach ($allDebtors as $debtor) {
            // Find the last invoice to estimate debt age
            $lastInv = Invoice::active()->where('customer_id', $debtor->id)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('created_at')
                ->first(['created_at']);
            $debtDays = $lastInv ? Carbon::now()->diffInDays($lastInv->created_at) : 0;

            // Customer revenue in period
            $custYearRevenue = (float) Invoice::active()->where('customer_id', $debtor->id)
                ->whereBetween('created_at', [$yearStart, $yearEnd])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->sum('total');
            $custDebtRatio = $custYearRevenue > 0
                ? round(($debtor->debt_amount / $custYearRevenue) * 100, 2)
                : 0;

            $debtorDetails[] = [
                'id' => $debtor->id,
                'code' => $debtor->code,
                'name' => $debtor->name,
                'phone' => $debtor->phone,
                'debt' => (float) $debtor->debt_amount,
                'group' => $debtor->customer_group ?: 'Chưa phân loại',
                'debtDays' => (int) $debtDays,
                'debtRatio' => $custDebtRatio,
            ];

            // Classify by days
            if ($debtDays <= 30) $debtByDays['0-30']++;
            elseif ($debtDays <= 60) $debtByDays['31-60']++;
            elseif ($debtDays <= 90) $debtByDays['61-90']++;
            elseif ($debtDays <= 120) $debtByDays['91-120']++;
            else $debtByDays['>120']++;
        }

        // Top 20% by amount
        $topByAmount = collect($debtorDetails)->sortByDesc('debt')->take(max(1, ceil(count($debtorDetails) * 0.2)))->values();
        // Top 20% by days
        $topByDays = collect($debtorDetails)->sortByDesc('debtDays')->take(max(1, ceil(count($debtorDetails) * 0.2)))->values();

        return Inertia::render('Reports/CustomerDebt', [
            'filters' => ['branch_id' => $branchId],
            'debtorCount' => $debtorCount,
            'totalDebt' => $totalDebt,
            'debtRevenueRatio' => $debtRevenueRatio,
            'avgDebtPerMonth' => $avgDebtPerMonth,
            'avgDebtRevenueRatio' => $avgDebtRevenueRatio,
            'chart' => [
                'labels' => $chartLabels,
                'debt' => $chartDebt,
                'netRevenue' => $chartNetRevenue,
            ],
            'debtByDays' => $debtByDays,
            'topByAmount' => $topByAmount,
            'topByDays' => $topByDays,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ═══════════════════════════════════════
    // 9. ĐỐI SOÁT CÔNG NỢ (Debt Reconciliation)
    // ═══════════════════════════════════════
    public function debtReconciliation(Request $request)
    {
        $branchId = $request->input('branch_id');
        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : null;
        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : null;
        $partnerType = $request->input('partner_type', 'all'); // all, dual, customer_only, supplier_only
        $search = $request->input('search');

        // Dual-role partners
        $query = Customer::query()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('is_customer', true)->where('is_supplier', true);
                })->orWhere(function ($sub) {
                    $sub->where('debt_amount', '!=', 0)->where('supplier_debt_amount', '!=', 0);
                });
            })
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($search, fn($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }));

        if ($partnerType === 'dual') {
            $query->where('is_customer', true)->where('is_supplier', true);
        } elseif ($partnerType === 'customer_only') {
            $query->where('is_customer', true)->where('is_supplier', false);
        } elseif ($partnerType === 'supplier_only') {
            $query->where('is_customer', false)->where('is_supplier', true);
        }

        $partners = $query->orderByRaw('ABS(debt_amount) + ABS(supplier_debt_amount) DESC')
            ->get(['id', 'code', 'name', 'phone', 'is_customer', 'is_supplier', 'debt_amount', 'supplier_debt_amount']);

        // Offset history per partner
        $offsetQuery = DebtOffset::query()
            ->whereIn('customer_id', $partners->pluck('id'))
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo));

        $offsetsByPartner = $offsetQuery->get()->groupBy('customer_id');

        $rows = $partners->map(function ($p) use ($offsetsByPartner) {
            $offsets = $offsetsByPartner->get($p->id, collect());
            $totalOffset = $offsets->where('status', 'active')->sum('amount');
            $autoOffset = $offsets->where('status', 'active')->where('is_auto', true)->sum('amount');
            $manualOffset = $offsets->where('status', 'active')->where('is_auto', false)->sum('amount');
            $cancelledOffset = $offsets->where('status', 'cancelled')->sum('amount');
            $receivable = (float) $p->debt_amount;
            $payable = (float) $p->supplier_debt_amount;
            $net = $receivable - $payable;

            return [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'phone' => $p->phone,
                'is_customer' => $p->is_customer,
                'is_supplier' => $p->is_supplier,
                'receivable' => $receivable,
                'payable' => $payable,
                'net' => $net,
                'total_offset' => (float) $totalOffset,
                'auto_offset' => (float) $autoOffset,
                'manual_offset' => (float) $manualOffset,
                'cancelled_offset' => (float) $cancelledOffset,
                'offset_count' => $offsets->where('status', 'active')->count(),
                'status' => $receivable == 0 && $payable == 0
                    ? 'clear'
                    : ($net == 0 ? 'balanced' : ($net > 0 ? 'receivable' : 'payable')),
            ];
        });

        // Summary totals
        $summary = [
            'total_partners' => $rows->count(),
            'total_receivable' => $rows->sum('receivable'),
            'total_payable' => $rows->sum('payable'),
            'total_net' => $rows->sum('net'),
            'total_offset_amount' => $rows->sum('total_offset'),
            'total_auto_offset' => $rows->sum('auto_offset'),
            'total_manual_offset' => $rows->sum('manual_offset'),
            'total_cancelled' => $rows->sum('cancelled_offset'),
            'clear_count' => $rows->where('status', 'clear')->count(),
            'balanced_count' => $rows->where('status', 'balanced')->count(),
            'receivable_count' => $rows->where('status', 'receivable')->count(),
            'payable_count' => $rows->where('status', 'payable')->count(),
        ];

        return Inertia::render('Reports/DebtReconciliation', [
            'filters' => [
                'branch_id' => $branchId,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'partner_type' => $partnerType,
                'search' => $search,
            ],
            'rows' => $rows->values(),
            'summary' => $summary,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function exportDebtReconciliation(Request $request)
    {
        $branchId = $request->input('branch_id');
        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : null;
        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : null;

        $partners = Customer::query()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('is_customer', true)->where('is_supplier', true);
                })->orWhere(function ($sub) {
                    $sub->where('debt_amount', '!=', 0)->where('supplier_debt_amount', '!=', 0);
                });
            })
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderByRaw('ABS(debt_amount) + ABS(supplier_debt_amount) DESC')
            ->get(['id', 'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount']);

        $offsetsByPartner = DebtOffset::whereIn('customer_id', $partners->pluck('id'))
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('created_at', '<=', $dateTo))
            ->where('status', 'active')
            ->get()
            ->groupBy('customer_id');

        $csvHeader = "Mã,Tên,SĐT,Nợ phải thu,Nợ phải trả,Đã cấn bằng,Còn lại\n";
        $csvRows = $partners->map(function ($p) use ($offsetsByPartner) {
            $totalOffset = $offsetsByPartner->get($p->id, collect())->sum('amount');
            $net = (float) $p->debt_amount - (float) $p->supplier_debt_amount;
            return implode(',', [
                $p->code,
                '"' . str_replace('"', '""', $p->name) . '"',
                $p->phone ?? '',
                $p->debt_amount,
                $p->supplier_debt_amount,
                $totalOffset,
                $net,
            ]);
        })->implode("\n");

        return response($csvHeader . $csvRows, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="doi-soat-cong-no-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Phase 5 — Phân tích giá vốn:
     * So sánh product.cost_price (snapshot) vs avg(serial.cost_price WHERE in_stock).
     * Cảnh báo khi lệch quá ngưỡng.
     */
    public function costAnalysis(Request $request, ProductSearchService $productSearch)
    {
        $threshold = (float) $request->input('threshold_pct', 1); // 1% mặc định
        $onlyMismatch = $request->boolean('only_mismatch', false);
        $search = trim((string) $request->input('search', ''));

        // Lấy products có serial → so sánh; products không serial → chỉ liệt kê snapshot
        $q = Product::query()
            ->select('id', 'sku', 'name', 'cost_price', 'stock_quantity', 'has_serial', 'is_active')
            ->where('is_active', true);

        if ($search !== '') {
            $productSearch->apply($q, $search, [
                'include_serials' => true,
                'serial_relation' => 'serialImeis',
            ]);
            $productSearch->applyScore($q, $search);
        }

        $products = $q->orderBy('name')->limit(500)->get();

        // Một query gộp avg/count cho tất cả product có serial
        $serialAggs = SerialImei::query()
            ->whereIn('product_id', $products->where('has_serial', true)->pluck('id'))
            ->where('status', 'in_stock')
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as in_stock_count, AVG(cost_price) as avg_cost, MIN(cost_price) as min_cost, MAX(cost_price) as max_cost')
            ->get()
            ->keyBy('product_id');

        $rows = $products->map(function ($p) use ($serialAggs, $threshold) {
            $snapshot = (float) $p->cost_price;
            $avgSerial = null;
            $inStockCount = null;
            $minCost = null;
            $maxCost = null;
            $diff = 0;
            $diffPct = 0;
            $status = 'ok';

            if ($p->has_serial) {
                $agg = $serialAggs->get($p->id);
                if ($agg) {
                    $avgSerial = round((float) $agg->avg_cost);
                    $inStockCount = (int) $agg->in_stock_count;
                    $minCost = (float) $agg->min_cost;
                    $maxCost = (float) $agg->max_cost;
                    $diff = $avgSerial - $snapshot;
                    $diffPct = $snapshot > 0 ? abs($diff) / $snapshot * 100 : ($avgSerial > 0 ? 100 : 0);
                    if ($diffPct > $threshold) {
                        $status = 'mismatch';
                    }
                } else {
                    // has_serial nhưng không còn serial in_stock
                    $status = $p->stock_quantity > 0 ? 'no_in_stock_serial' : 'empty';
                }
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'has_serial' => (bool) $p->has_serial,
                'stock_quantity' => (int) $p->stock_quantity,
                'snapshot_cost' => $snapshot,
                'avg_serial_cost' => $avgSerial,
                'in_stock_serial_count' => $inStockCount,
                'min_serial_cost' => $minCost,
                'max_serial_cost' => $maxCost,
                'diff' => $diff,
                'diff_pct' => round($diffPct, 2),
                'status' => $status, // ok | mismatch | no_in_stock_serial | empty
            ];
        });

        if ($onlyMismatch) {
            $rows = $rows->filter(fn ($r) => $r['status'] === 'mismatch')->values();
        }

        $summary = [
            'total' => $rows->count(),
            'mismatch_count' => $rows->where('status', 'mismatch')->count(),
            'no_in_stock_serial' => $rows->where('status', 'no_in_stock_serial')->count(),
            'total_inventory_value_snapshot' => $rows->sum(fn ($r) => $r['snapshot_cost'] * $r['stock_quantity']),
            'total_inventory_value_serial' => $rows->sum(fn ($r) => ($r['avg_serial_cost'] ?? $r['snapshot_cost']) * ($r['in_stock_serial_count'] ?? $r['stock_quantity'])),
        ];

        return Inertia::render('Reports/CostAnalysis', [
            'rows' => $rows->values(),
            'summary' => $summary,
            'filters' => [
                'threshold_pct' => $threshold,
                'only_mismatch' => $onlyMismatch,
                'search' => $search,
            ],
        ]);
    }

    /**
     * Phase 5 — Lịch sử thay đổi giá vốn serial.
     * Đọc ActivityLog action='serial_cost_update' (ghi từ ProductController::updateSerial — Phase 3).
     */
    public function serialCostHistory(Request $request)
    {
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $search = trim((string) $request->input('search', ''));
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $productId = $request->input('product_id');

        $q = ActivityLog::query()
            ->where('action', 'serial_cost_update')
            ->with(['user:id,name', 'employee:id,name,code'])
            ->orderByDesc('created_at');

        if ($dateFrom) {
            $q->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo) {
            $q->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }
        if ($productId) {
            $q->whereJsonContains('properties->product_id', (int) $productId);
        }
        if ($search !== '') {
            $q->where('description', 'like', "%$search%");
        }

        $logs = $q->paginate($perPage)->withQueryString();

        // Stats nhanh
        $stats = [
            'total' => ActivityLog::where('action', 'serial_cost_update')->count(),
            'this_month' => ActivityLog::where('action', 'serial_cost_update')
                ->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            'today' => ActivityLog::where('action', 'serial_cost_update')
                ->where('created_at', '>=', Carbon::now()->startOfDay())->count(),
        ];

        return Inertia::render('Reports/SerialCostHistory', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'product_id' => $productId,
            ],
        ]);
    }

    /**
     * Phase 4 — Thẻ kho (sổ cái tồn kho theo SKU).
     * Hiển thị từng dịch chuyển + balance sau mỗi lần.
     */
    public function stockCard(Request $request)
    {
        $productId = $request->input('product_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $type = $request->input('type'); // optional filter
        $perPage = min(200, max(20, (int) $request->input('per_page', 50)));

        $product = $productId ? Product::find($productId) : null;

        $query = StockMovement::query()
            ->with(['serialImei:id,serial_number', 'employee:id,name,code', 'user:id,name'])
            ->orderBy('moved_at', 'desc')
            ->orderBy('id', 'desc');

        if ($product) {
            $query->where('product_id', $product->id);
        }
        if ($dateFrom) {
            $query->where('moved_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo) {
            $query->where('moved_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }
        if ($type) {
            $query->where('type', $type);
        }

        // Aggregate trong khoảng (ignoring product filter chỉ khi product có)
        $aggQuery = clone $query;
        $aggQuery->getQuery()->orders = null;
        $stats = [
            'total_in_qty' => (int) (clone $aggQuery)->where('direction', 'in')->sum('qty'),
            'total_out_qty' => (int) (clone $aggQuery)->where('direction', 'out')->sum('qty'),
            'total_in_value' => (float) (clone $aggQuery)->where('direction', 'in')->sum('total_cost'),
            'total_out_value' => (float) (clone $aggQuery)->where('direction', 'out')->sum('total_cost'),
        ];

        $movements = $query->paginate($perPage)->withQueryString();

        // Danh sách product để filter
        $products = Product::select('id', 'sku', 'name')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return Inertia::render('Reports/StockCard', [
            'movements' => $movements,
            'stats' => $stats,
            'product' => $product ? [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock_quantity' => $product->stock_quantity,
                'cost_price' => $product->cost_price,
            ] : null,
            'products' => $products,
            'filters' => [
                'product_id' => $productId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'type' => $type,
            ],
        ]);
    }
}
