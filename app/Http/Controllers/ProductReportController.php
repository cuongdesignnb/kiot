<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\PurchaseItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProductReportController extends Controller
{
    public function index(Request $request)
    {
        // ── Filters ──
        $concern    = $request->input('concern', 'sales');     // sales|profit|stock_value|stock_io|stock_io_detail
        $period     = $request->input('period', 'this_month');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $branchId   = $request->input('branch_id');
        $categoryId = $request->input('category_id');
        $brandId    = $request->input('brand_id');
        $viewMode   = $request->input('view', 'chart');

        // ── Resolve date range ──
        [$startDate, $endDate, $periodLabel, $groupBy] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // ── Base invoice query ──
        $invoiceQuery = Invoice::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $invoiceQuery->where('branch_id', $branchId);

        $invoiceIds = (clone $invoiceQuery)->pluck('id');

        // Returns
        $returnsQuery = OrderReturn::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', 'Đã hủy');
        if ($branchId) $returnsQuery->where('branch_id', $branchId);
        $returnIds = (clone $returnsQuery)->pluck('id');

        // ── Product filter scope ──
        $productFilter = function ($q) use ($categoryId, $brandId) {
            if ($categoryId) $q->where('products.category_id', $categoryId);
            if ($brandId)    $q->where('products.brand_id', $brandId);
        };

        // ── Build data ──
        $chartData = [];

        switch ($concern) {
            case 'sales':
                $chartData = $this->buildSalesCharts($invoiceIds, $returnIds, $productFilter);
                break;
            case 'profit':
                $chartData = $this->buildProfitCharts($invoiceIds, $returnIds, $productFilter);
                break;
            case 'stock_value':
                $chartData = $this->buildStockValueChart($categoryId, $brandId);
                break;
            case 'stock_io':
                $chartData = $this->buildStockIOChart($invoiceIds, $returnIds, $startDate, $endDate, $branchId, $categoryId, $brandId);
                break;
            case 'stock_io_detail':
                $chartData = $this->buildStockIODetailTable($invoiceIds, $returnIds, $startDate, $endDate, $branchId, $categoryId, $brandId);
                break;
        }

        // ── Filter options ──
        $branches   = Branch::orderBy('name')->get(['id', 'name']);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $brands     = Brand::orderBy('name')->get(['id', 'name']);
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/ProductReport', [
            'filters' => [
                'concern'     => $concern,
                'period'      => $period,
                'date_from'   => $startDate->format('Y-m-d'),
                'date_to'     => $endDate->format('Y-m-d'),
                'branch_id'   => $branchId,
                'category_id' => $categoryId,
                'brand_id'    => $brandId,
                'view'        => $viewMode,
            ],
            'periodLabel' => $periodLabel,
            'chartData'   => $chartData,
            'branchName'  => $branchName,
            'branches'    => $branches,
            'categories'  => $categories,
            'brands'      => $brands,
        ]);
    }

    private function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        switch ($period) {
            case 'this_week':  return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'Tuần này', 'day'];
            case 'this_month': return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này', 'day'];
            case 'this_year':  return [Carbon::now()->startOfYear(), Carbon::now()->endOfDay(), 'Năm nay', 'month'];
            case 'last_year':  return [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear(), 'Năm trước', 'month'];
            case 'custom':
                $s = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->startOfMonth();
                $e = $to   ? Carbon::parse($to)->endOfDay()     : Carbon::now()->endOfDay();
                return [$s, $e, 'Tùy chỉnh', $s->diffInDays($e) > 90 ? 'month' : 'day'];
            default: return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này', 'day'];
        }
    }

    // ═══════════════
    // Bán hàng: Top 10 doanh thu + Top 10 số lượng
    // ═══════════════
    private function buildSalesCharts($invoiceIds, $returnIds, $productFilter)
    {
        // Sold items
        $soldQuery = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->tap($productFilter);

        $soldByProduct = (clone $soldQuery)
            ->select(
                'invoice_items.product_id',
                DB::raw('SUM(invoice_items.quantity) as total_qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as total_revenue')
            )
            ->groupBy('invoice_items.product_id')
            ->get()
            ->keyBy('product_id');

        // Returned items
        $returnedByProduct = collect();
        if ($returnIds->count() > 0) {
            $returnedByProduct = DB::table('return_items')
                ->whereIn('return_id', $returnIds)
                ->join('products', 'return_items.product_id', '=', 'products.id')
                ->tap($productFilter)
                ->select(
                    'return_items.product_id',
                    DB::raw('SUM(return_items.quantity) as total_qty'),
                    DB::raw('SUM(return_items.quantity * return_items.price) as total_value')
                )
                ->groupBy('return_items.product_id')
                ->get()
                ->keyBy('product_id');
        }

        // Net revenue per product
        $products = [];
        foreach ($soldByProduct as $pid => $sold) {
            $returned = $returnedByProduct[$pid] ?? null;
            $netQty = (int) $sold->total_qty - (int) ($returned->total_qty ?? 0);
            $netRev = (float) $sold->total_revenue - (float) ($returned->total_value ?? 0);
            $product = Product::find($pid);
            if ($product && $netQty > 0) {
                $products[] = [
                    'name' => $product->name,
                    'qty' => $netQty,
                    'revenue' => $netRev,
                ];
            }
        }

        // Top 10 by revenue
        $byRevenue = collect($products)->sortByDesc('revenue')->take(10)->values();
        // Top 10 by quantity
        $byQty = collect($products)->sortByDesc('qty')->take(10)->values();

        return [
            'charts' => [
                [
                    'title' => 'Top 10 sản phẩm doanh thu cao nhất (đã trừ trả hàng)',
                    'labels' => $byRevenue->pluck('name')->toArray(),
                    'datasets' => [['label' => 'Doanh thu', 'data' => $byRevenue->pluck('revenue')->toArray()]],
                    'type' => 'horizontal_bar',
                ],
                [
                    'title' => 'Top 10 sản phẩm bán chạy theo số lượng (đã trừ trả hàng)',
                    'labels' => $byQty->pluck('name')->toArray(),
                    'datasets' => [['label' => 'Số lượng', 'data' => $byQty->pluck('qty')->toArray()]],
                    'type' => 'horizontal_bar',
                ],
            ],
            'multiChart' => true,
        ];
    }

    // ═══════════════
    // Lợi nhuận: Top 10 lợi nhuận cao nhất
    // ═══════════════
    private function buildProfitCharts($invoiceIds, $returnIds, $productFilter)
    {
        $hasItemCostCol = \Illuminate\Support\Facades\Schema::hasColumn('invoice_items', 'cost_price');
        $costCalc = $hasItemCostCol
            ? 'invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)'
            : 'invoice_items.quantity * COALESCE(products.cost_price, 0)';

        $soldQuery = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->tap($productFilter)
            ->select(
                'invoice_items.product_id',
                DB::raw('SUM(invoice_items.quantity) as total_qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as total_revenue'),
                DB::raw("SUM({$costCalc}) as total_cost")
            )
            ->groupBy('invoice_items.product_id')
            ->get();

        $products = [];
        foreach ($soldQuery as $item) {
            $product = Product::find($item->product_id);
            $profit = (float) $item->total_revenue - (float) $item->total_cost;
            if ($product) {
                $products[] = ['name' => $product->name, 'profit' => $profit, 'revenue' => (float) $item->total_revenue];
            }
        }

        $byProfit = collect($products)->sortByDesc('profit')->take(10)->values();

        return [
            'charts' => [
                [
                    'title' => 'Top 10 sản phẩm lợi nhuận cao nhất',
                    'labels' => $byProfit->pluck('name')->toArray(),
                    'datasets' => [['label' => 'Lợi nhuận', 'data' => $byProfit->pluck('profit')->toArray()]],
                    'type' => 'horizontal_bar',
                ],
            ],
            'multiChart' => true,
        ];
    }

    // ═══════════════
    // Giá trị kho: Top 10 giá trị tồn kho cao nhất
    // ═══════════════
    private function buildStockValueChart($categoryId, $brandId)
    {
        $query = Product::query();
        if ($categoryId) $query->where('category_id', $categoryId);
        if ($brandId)    $query->where('brand_id', $brandId);

        $products = $query->where('stock_quantity', '>', 0)
            ->select('name', 'stock_quantity', 'cost_price')
            ->orderByRaw('stock_quantity * cost_price DESC')
            ->limit(10)
            ->get();

        $labels = [];
        $data = [];
        foreach ($products as $p) {
            $labels[] = $p->name;
            $data[] = (float) $p->stock_quantity * (float) $p->cost_price;
        }

        return [
            'charts' => [
                [
                    'title' => 'Top 10 sản phẩm giá trị tồn kho cao nhất',
                    'labels' => $labels,
                    'datasets' => [['label' => 'Giá trị tồn', 'data' => $data]],
                    'type' => 'horizontal_bar',
                ],
            ],
            'multiChart' => true,
        ];
    }

    // ═══════════════
    // Xuất nhập tồn: aggregated per product
    // ═══════════════
    private function buildStockIOChart($invoiceIds, $returnIds, $startDate, $endDate, $branchId, $categoryId, $brandId)
    {
        $productQuery = Product::query();
        if ($categoryId) $productQuery->where('category_id', $categoryId);
        if ($brandId)    $productQuery->where('brand_id', $brandId);
        $productIds = $productQuery->pluck('id');

        // Purchased qty in period
        $purchasedQty = DB::table('purchase_items')
            ->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->whereIn('purchase_items.product_id', $productIds)
            ->whereBetween('purchases.created_at', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('purchases.branch_id', $branchId))
            ->select('purchase_items.product_id', DB::raw('SUM(purchase_items.quantity) as qty'))
            ->groupBy('purchase_items.product_id')
            ->pluck('qty', 'purchase_items.product_id');

        // Sold qty in period
        $soldQty = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereIn('product_id', $productIds)
            ->select('product_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Returned qty in period
        $returnedQty = collect();
        if ($returnIds->count() > 0) {
            $returnedQty = DB::table('return_items')
                ->whereIn('return_id', $returnIds)
                ->whereIn('product_id', $productIds)
                ->select('product_id', DB::raw('SUM(quantity) as qty'))
                ->groupBy('product_id')
                ->pluck('qty', 'product_id');
        }

        // Build table data
        $rows = [];
        $products = Product::whereIn('id', $productIds)->get();
        foreach ($products as $p) {
            $imported = (int) ($purchasedQty[$p->id] ?? 0);
            $exported = (int) ($soldQty[$p->id] ?? 0);
            $returned = (int) ($returnedQty[$p->id] ?? 0);
            $netExported = $exported - $returned;

            if ($imported > 0 || $netExported > 0) {
                $rows[] = [
                    'name'       => $p->name,
                    'sku'        => $p->sku,
                    'imported'   => $imported,
                    'exported'   => $netExported,
                    'stock'      => (int) $p->stock_quantity,
                    'stockValue' => (float) $p->stock_quantity * (float) $p->cost_price,
                ];
            }
        }

        return [
            'title' => 'Xuất nhập tồn',
            'tableData' => $rows,
            'columns' => ['Hàng hóa', 'Mã SKU', 'Nhập', 'Xuất', 'Tồn', 'Giá trị tồn'],
            'isTable' => true,
        ];
    }

    // ═══════════════
    // Xuất nhập tồn chi tiết: same but with cost detail
    // ═══════════════
    private function buildStockIODetailTable($invoiceIds, $returnIds, $startDate, $endDate, $branchId, $categoryId, $brandId)
    {
        $data = $this->buildStockIOChart($invoiceIds, $returnIds, $startDate, $endDate, $branchId, $categoryId, $brandId);
        $data['title'] = 'Xuất nhập tồn chi tiết';
        return $data;
    }
}
