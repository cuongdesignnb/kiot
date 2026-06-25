<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Product;
use App\Enums\StockTakeStatus;
use App\Support\BusinessDateTime;
use App\Support\Filters\FilterableIndex;
use App\Services\LockPeriodService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use Illuminate\Support\Facades\DB;
use App\Models\Branch;

class StockTakeController extends Controller
{
    use FilterableIndex;

    protected function configureStockTakeFilters(): void
    {
        $this->searchable = ['code', 'note', 'user_name', 'balancer_name'];
        $this->searchableRelations = [
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'total_actual_qty', 'total_diff_qty', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = null;
        $this->scalarFilters = ['branch_id'];
    }

    public function index(Request $request)
    {
        $this->configureStockTakeFilters();

        $query = StockTake::with('items.product');
        $this->applyFilters($query, $request);

        $stockTakes = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('StockTakes/Index', [
            'stockTakes' => $stockTakes,
            'branches' => $branches,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => $branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
                'statuses' => StockTakeStatus::options(),
            ],
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->where('type', '!=', 'service')->get();
        $branches = Branch::all();

        return Inertia::render('StockTakes/Create', [
            'products' => $products,
            'branches' => $branches,
            'stockTakeCode' => 'KK' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'required|numeric|min:0',
            'status' => 'required|in:draft,balanced',
            'action_date' => 'nullable|date',
            'note' => 'nullable|string'
        ]);

        // ── Step 23.4: Pre-flight server-side validation ──
        // BUG-1: KHÔNG tin client system_stock/diff_qty/diff_value — recompute từ DB.
        // BUG-2: chặn duplicate product_id trong cùng phiếu.
        // BUG-3: chặn cân bằng hàng has_serial khi diff != 0 (chưa có UI chọn serial detail).
        $seenProductIds = [];
        $serverItems = [];
        foreach ($request->items as $i => $item) {
            $pid = (int) $item['product_id'];
            if (isset($seenProductIds[$pid])) {
                return back()->withErrors(["items.{$i}.product_id" => "Sản phẩm bị trùng trong cùng phiếu kiểm kho."]);
            }
            $seenProductIds[$pid] = true;

            $product = Product::find($pid);
            if (!$product) continue;
            if ($product->isService()) {
                return back()->withErrors(["items.{$i}.product_id" => "Dịch vụ không quản lý tồn kho nên không thể kiểm kho."]);
            }
            $systemStock = (int) $product->stock_quantity;
            $actualStock = (int) $item['actual_stock'];
            $diffQty = $actualStock - $systemStock;
            $costPrice = (float) ($product->cost_price ?? 0);
            $diffValue = $diffQty * $costPrice;

            if ($request->status === 'balanced' && $product->has_serial && $diffQty !== 0) {
                return back()->withErrors(["items.{$i}.actual_stock" => "Sản phẩm \"{$product->name}\" có quản lý Serial/IMEI — chưa hỗ trợ cân bằng chênh lệch nếu không khai báo serial cụ thể. Vui lòng dùng phiếu nhập/trả NCC để điều chỉnh."]);
            }

            $serverItems[] = [
                'product_id'   => $pid,
                'system_stock' => $systemStock,
                'actual_stock' => $actualStock,
                'diff_qty'     => $diffQty,
                'diff_value'   => $diffValue,
                'cost_price'   => $costPrice,
            ];
        }

        try {
            DB::beginTransaction();

            // Lock period check
            $stockTakeDate = BusinessDateTime::forCreate($request->input('action_date'));
            app(LockPeriodService::class)->assertNotLocked($stockTakeDate, 'stocktake_create');

            $stockTake = StockTake::create([
                'code' => $request->code ?? 'KK' . time(),
                'status' => $request->status,
                'user_name' => 'Trần Văn Tiến', // hardcoded for demo
                'balancer_name' => $request->status === 'balanced' ? 'Trần Văn Tiến' : null,
                'balanced_date' => $request->status === 'balanced' ? $stockTakeDate : null,
                'note' => $request->note,
                'total_actual_qty' => array_sum(array_column($serverItems, 'actual_stock')),
                'total_diff_qty' => array_sum(array_column($serverItems, 'diff_qty')),
                'total_diff_increase' => collect($serverItems)->filter(fn($i) => $i['diff_qty'] > 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($serverItems)->filter(fn($i) => $i['diff_qty'] < 0)->sum('diff_qty'),
                'total_diff_value' => array_sum(array_column($serverItems, 'diff_value'))
            ]);

            $stockTake->created_at = $stockTakeDate;
            $stockTake->save();

            foreach ($serverItems as $item) {
                StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id'    => $item['product_id'],
                    'system_stock'  => $item['system_stock'],
                    'actual_stock'  => $item['actual_stock'],
                    'diff_qty'      => $item['diff_qty'],
                    'diff_value'    => $item['diff_value'],
                ]);

                // Update product stock if balanced — dùng CostingService + StockMovement
                if ($request->status === 'balanced') {
                    $diff = (int) $item['diff_qty'];
                    if ($diff !== 0) {
                        $product = Product::find($item['product_id']);
                        if ($product) {
                            $costPerUnit = (float) $item['cost_price'];
                            MovingAvgCostingService::applyAdjustment($product, $diff);
                            $product->refresh();
                            StockMovementService::record(
                                $product,
                                $diff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
                                abs($diff),
                                $costPerUnit,
                                $stockTake,
                                ['moved_at' => $stockTakeDate]
                            );
                        }
                    }
                }
            }

            DB::commit();

            $logAction = $request->status === 'balanced' ? 'stocktake_complete' : 'stocktake_create';
            ActivityLog::log($logAction, "Tạo phiếu kiểm kho {$stockTake->code}, trạng thái: {$stockTake->status}", $stockTake);

            return redirect()->route('stock-takes.index')->with('success', 'Tạo phiếu kiểm kho thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $this->configureStockTakeFilters();
        $query = StockTake::query();
        $this->applyFilters($query, $request);
        $stockTakes = $query->get();

        return \App\Services\CsvService::export(
            ['Mã kiểm kho', 'Người kiểm', 'Người cân bằng', 'Ngày cân bằng', 'Tổng SL thực tế', 'Tổng lệch', 'Trạng thái', 'Ghi chú'],
            $stockTakes->map(fn($s) => [$s->code, $s->user_name, $s->balancer_name, $s->balanced_date, $s->total_actual_qty, $s->total_diff_qty, $s->status, $s->note]),
            'kiem_kho.csv'
        );
    }

    public function print(\App\Models\StockTake $stockTake)
    {
        $stockTake->load(['items.product']);
        return view('prints.stock_take', compact('stockTake'));
    }

    /**
     * Chi tiet phieu kiem kho.
     *
     * Step 24.7: dedicated Show.vue not yet wired; redirect to index
     * filtered by code so the stock-card "Mở phiếu" link still works.
     */
    public function show(StockTake $stockTake)
    {
        return redirect()->route('stock-takes.index', ['search' => $stockTake->code]);
    }

    /**
     * Sua draft kiem kho — chi cho phep khi status = draft.
     */
    public function update(Request $request, $id)
    {
        $stockTake = StockTake::findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the sua phieu nhap (draft).'], 422);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Delete old items
            StockTakeItem::where('stock_take_id', $stockTake->id)->forceDelete();

            $totalActual = 0;
            $totalDiff = 0;
            $totalIncrease = 0;
            $totalDecrease = 0;
            $totalDiffValue = 0;

            foreach ($request->items as $item) {
                $systemStock = $item['system_stock'] ?? Product::find($item['product_id'])->stock_quantity ?? 0;
                $diffQty = $item['actual_stock'] - $systemStock;
                $diffValue = $item['diff_value'] ?? 0;

                StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id' => $item['product_id'],
                    'system_stock' => $systemStock,
                    'actual_stock' => $item['actual_stock'],
                    'diff_qty' => $diffQty,
                    'diff_value' => $diffValue,
                ]);

                $totalActual += $item['actual_stock'];
                $totalDiff += $diffQty;
                if ($diffQty > 0) $totalIncrease += $diffQty;
                if ($diffQty < 0) $totalDecrease += $diffQty;
                $totalDiffValue += $diffValue;
            }

            $stockTake->update([
                'note' => $request->note ?? $stockTake->note,
                'total_actual_qty' => $totalActual,
                'total_diff_qty' => $totalDiff,
                'total_diff_increase' => $totalIncrease,
                'total_diff_decrease' => $totalDecrease,
                'total_diff_value' => $totalDiffValue,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Da cap nhat phieu kiem kho.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cân bằng kho — chuyển phiếu draft → balanced.
     */
    public function balance($id)
    {
        $stockTake = StockTake::with('items')->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể cân bằng phiếu tạm (draft).'], 422);
        }

        try {
            DB::beginTransaction();

            // Cập nhật system_stock = tồn kho hiện tại (có thể đã thay đổi kể từ khi tạo draft)
            foreach ($stockTake->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                $currentStock = (int) $product->stock_quantity;
                $actualStock = (int) $item->actual_stock;
                $diff = $actualStock - $currentStock;

                // ── Step 23.4 BUG-3: chặn cân bằng hàng has_serial khi diff != 0 ──
                if ($product->has_serial && $diff !== 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Sản phẩm \"{$product->name}\" có quản lý Serial/IMEI — chưa hỗ trợ cân bằng chênh lệch nếu không khai báo serial cụ thể.",
                    ], 422);
                }

                // Cập nhật system_stock & diff trong item
                $item->update([
                    'system_stock' => $currentStock,
                    'diff_qty' => $diff,
                    'diff_value' => $diff * (float) ($product->cost_price ?? 0),
                ]);

                // Cộng/trừ chênh lệch vào stock — dùng CostingService + StockMovement
                if ($diff !== 0) {
                    $costPerUnit = (float) $product->cost_price;
                    MovingAvgCostingService::applyAdjustment($product, $diff);
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        $diff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
                        abs($diff),
                        $costPerUnit,
                        $stockTake,
                        ['moved_at' => $balancedDate]
                    );
                }
            }

            // Reload items sau update
            $stockTake->load('items');

            $stockTake->update([
                'status' => 'balanced',
                'balancer_name' => auth()->user()?->name ?? 'Hệ thống',
                'balanced_date' => $balancedDate,
                'total_actual_qty' => $stockTake->items->sum('actual_stock'),
                'total_diff_qty' => $stockTake->items->sum('diff_qty'),
                'total_diff_increase' => $stockTake->items->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => $stockTake->items->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => $stockTake->items->sum('diff_value'),
            ]);

            DB::commit();
            ActivityLog::log('stocktake_complete', "Cân bằng kho phiếu {$stockTake->code}", $stockTake);
            return response()->json(['success' => true, 'message' => 'Đã cân bằng kho thành công.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Huy phieu kiem kho — rollback stock ve system_stock.
     */
    public function cancel($id)
    {
        $stockTake = StockTake::with('items')->findOrFail($id);

        if ($stockTake->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Phieu da bi huy truoc do.'], 422);
        }

        if ($stockTake->status === 'draft') {
            $stockTake->update(['status' => 'cancelled']);
            return response()->json(['success' => true, 'message' => 'Da huy phieu nhap.']);
        }

        // status = balanced -> rollback stock
        try {
            DB::beginTransaction();

            foreach ($stockTake->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    // ── Step 23.4: hàng has_serial không thể rollback an toàn nếu không có serial snapshot.
                    //    Hiện tại Step 23.4 chặn balance hàng has_serial diff != 0 nên trường hợp này không xảy ra.
                    //    Nếu legacy data có thì skip rollback và log để admin xử lý thủ công.
                    if ($product->has_serial) {
                        \Log::warning("StockTake cancel skipped serial product {$product->id} (legacy data)", ['stock_take_id' => $stockTake->id]);
                        continue;
                    }
                    // Đảo chênh lệch: nếu kiểm kho đã +3 thì giờ -3 (và ngược lại)
                    $diff = (int) $item->actual_stock - (int) $item->system_stock;
                    if ($diff !== 0) {
                        $reverseDiff = -$diff;
                        $costPerUnit = (float) $product->cost_price;
                        MovingAvgCostingService::applyAdjustment($product, $reverseDiff);
                        $product->refresh();
                        StockMovementService::record(
                            $product,
                            $reverseDiff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
                            abs($reverseDiff),
                            $costPerUnit,
                            $stockTake,
                            ['note' => 'Hủy kiểm kho — đảo chênh lệch']
                        );
                    }
                }
            }

            $stockTake->update(['status' => 'cancelled']);

            DB::commit();
            ActivityLog::log('stocktake_cancel', "Hủy phiếu kiểm kho {$stockTake->code}", $stockTake);
            return response()->json(['success' => true, 'message' => 'Da huy phieu kiem kho va hoan ton kho.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
