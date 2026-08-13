<?php

namespace App\Http\Controllers;

use App\Enums\StockTakeStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTakeItemSerial;
use App\Services\LockPeriodService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use App\Support\BusinessDateTime;
use App\Support\Filters\FilterableIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

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

        $query = StockTake::with(['items.product', 'items.serialChecks', 'branch']);
        $this->applyFilters($query, $request);

        $stockTakes = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('StockTakes/Index', [
            'stockTakes' => $stockTakes,
            'branches' => $branches,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => $branches->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
                'statuses' => StockTakeStatus::options(),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('StockTakes/Create', [
            'products' => Product::where('is_active', true)->where('type', '!=', 'service')->with('units')->get(),
            'branches' => Branch::all(),
            'categories' => Category::with('childrenRecursive')
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => $this->categoryTreePayload($category)),
            'stockTakeCode' => 'KK'.date('YmdHis'),
        ]);
    }

    public function edit($id)
    {
        $stockTake = StockTake::with(['items.product', 'items.serialChecks', 'branch'])->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return redirect()->route('stock-takes.index', ['search' => $stockTake->code])
                ->with('error', 'Chỉ có thể cập nhật phiếu tạm.');
        }

        return Inertia::render('StockTakes/Create', [
            'products' => Product::where('is_active', true)->where('type', '!=', 'service')->with('units')->get(),
            'branches' => Branch::all(),
            'categories' => Category::with('childrenRecursive')
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => $this->categoryTreePayload($category)),
            'stockTakeCode' => $stockTake->code,
            'stockTake' => $stockTake,
        ]);
    }

    public function products(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'include_children' => 'nullable|boolean',
            'branch_id' => 'nullable|exists:branches,id',
            'active_only' => 'nullable|boolean',
            'inventory_only' => 'nullable|boolean',
            'only_in_stock' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $query = Product::query()->with('units');

        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }
        if ($request->boolean('inventory_only', true)) {
            $query->where('type', '!=', 'service');
        }
        if ($request->boolean('only_in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }
        $search = null;
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('serials', function ($serialQuery) use ($search) {
                        $serialQuery->where('serial_number', 'like', "%{$search}%")
                            ->where('status', 'in_stock');
                    });
            });
        }

        $categoryIds = collect($validated['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($categoryIds->isNotEmpty()) {
            $ids = $request->boolean('include_children', true)
                ? $this->categoryIdsWithChildren($categoryIds->all())
                : $categoryIds->all();
            $query->whereIn('category_id', $ids);
        }

        // TODO: Current system uses global product.stock_quantity; branch_id is stored for document ownership but stock calculation is still global until branch stock ledger is implemented.
        $limit = (int) $request->input('limit', $categoryIds->isNotEmpty() ? 500 : 20);

        $products = $query->orderBy('name')->limit($limit)->get();
        $matchedSerials = $search
            ? SerialImei::query()
                ->whereIn('product_id', $products->pluck('id'))
                ->where('serial_number', $search)
                ->where('status', 'in_stock')
                ->get(['product_id', 'serial_number'])
                ->groupBy('product_id')
            : collect();

        return response()->json(
            $products->map(fn (Product $product) => $this->productPayload(
                $product,
                $matchedSerials->get($product->id, collect())->pluck('serial_number')->values()->all(),
            ))
        );
    }

    public function productSerials(Product $product)
    {
        abort_unless($product->has_serial, 404);

        $serials = $this->physicalInventorySerials($product->id)->get([
            'id',
            'serial_number',
            'status',
            'repair_status',
            'cost_price',
        ]);
        $aggregateStock = (int) ($product->fresh(['serials'])->stock_quantity ?? 0);

        return response()->json([
            'product_id' => (int) $product->id,
            'has_serial' => true,
            'aggregate_stock' => $aggregateStock,
            'serial_stock_count' => $serials->count(),
            'integrity_match' => $aggregateStock === $serials->count(),
            'serials' => $serials->map(fn (SerialImei $serial) => [
                'id' => (int) $serial->id,
                'serial_imei_id' => (int) $serial->id,
                'serial_number' => $serial->serial_number,
                'status' => $serial->status,
                'repair_status' => $serial->repair_status,
                'cost_price' => $serial->cost_price !== null ? (float) $serial->cost_price : null,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'nullable|numeric|min:0',
            'items.*.checked' => 'boolean',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*.serial_imei_id' => 'nullable|integer',
            'items.*.serials.*.serial_number' => 'nullable|string|max:255',
            'items.*.serials.*.actual_present' => 'nullable|boolean',
            'items.*.unknown_serials' => 'nullable|array',
            'items.*.unknown_serials.*' => 'string|max:255',
            'status' => 'required|in:draft,balanced',
            'action_date' => 'nullable|date',
            'note' => 'nullable|string',
        ]);

        $branchId = $this->resolveBranchId($request);
        $serverItems = $this->prepareItems($request->items, $request->status);

        try {
            DB::beginTransaction();

            // Lock period check
            $stockTakeDate = BusinessDateTime::forCreate($request->input('action_date'));
            app(LockPeriodService::class)->assertNotLocked($stockTakeDate, 'stocktake_create');

            $stockTake = StockTake::create([
                'code' => $request->code ?? 'KK'.time(),
                'branch_id' => $branchId,
                'status' => $request->status,
                'user_name' => auth()->user()?->name ?? 'Hệ thống',
                'created_by' => auth()->id(),
                'balanced_by' => $request->status === 'balanced' ? auth()->id() : null,
                'balancer_name' => $request->status === 'balanced' ? (auth()->user()?->name ?? 'Hệ thống') : null,
                'balanced_date' => $request->status === 'balanced' ? $stockTakeDate : null,
                'note' => $request->note,
                'total_actual_qty' => collect($serverItems)->sum(fn ($i) => $i['actual_stock'] ?? 0),
                'total_diff_qty' => collect($serverItems)->sum('diff_qty'),
                'total_diff_increase' => collect($serverItems)->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($serverItems)->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => collect($serverItems)->sum('diff_value'),
            ]);

            $stockTake->created_at = $stockTakeDate;
            $stockTake->save();

            foreach ($serverItems as $item) {
                $stockTakeItem = StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id' => $item['product_id'],
                    'system_stock' => $item['system_stock'],
                    'system_stock_snapshot' => $item['system_stock'],
                    'actual_stock' => $item['actual_stock'],
                    'checked' => $item['checked'],
                    'diff_qty' => $item['diff_qty'],
                    'diff_value' => $item['diff_value'],
                    'cost_price_snapshot' => $item['cost_price_snapshot'],
                    'unit_name' => $item['unit_name'],
                    'category_id' => $item['category_id'],
                    'unknown_serials' => $item['unknown_serials'] ?? [],
                ]);
                $this->persistSerialChecks($stockTakeItem, $item['serial_checks'] ?? []);

                if ($request->status === 'balanced') {
                    $this->applyBalanceMovement($stockTake, $item);
                }
            }

            DB::commit();

            $logAction = $request->status === 'balanced' ? 'stocktake_complete' : 'stocktake_create';
            ActivityLog::log($logAction, "Tao phieu kiem kho {$stockTake->code}, trang thai: {$stockTake->status}", $stockTake);

            return redirect()->route('stock-takes.index')->with('success', 'Tao phieu kiem kho thanh cong.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Loi: '.$e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $this->configureStockTakeFilters();
        $query = StockTake::query();
        $this->applyFilters($query, $request);
        $stockTakes = $query->get();

        return \App\Services\CsvService::export(
            ['Ma kiem kho', 'Nguoi kiem', 'Nguoi can bang', 'Ngay can bang', 'Tong SL thuc te', 'Tong lech', 'Trang thai', 'Ghi chu'],
            $stockTakes->map(fn ($s) => [$s->code, $s->user_name, $s->balancer_name, $s->balanced_date, $s->total_actual_qty, $s->total_diff_qty, $s->status, $s->note]),
            'kiem_kho.csv'
        );
    }

    public function print(StockTake $stockTake)
    {
        $stockTake->load(['items.product', 'items.serialChecks', 'branch']);

        return view('prints.stock_take', compact('stockTake'));
    }

    public function show(StockTake $stockTake)
    {
        return redirect()->route('stock-takes.index', ['search' => $stockTake->code]);
    }

    public function update(Request $request, $id)
    {
        $stockTake = StockTake::with(['items.serialChecks', 'items.product'])->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the sua phieu tam (draft).'], 422);
        }

        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'nullable|numeric|min:0',
            'items.*.checked' => 'boolean',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*.serial_imei_id' => 'nullable|integer',
            'items.*.serials.*.serial_number' => 'nullable|string|max:255',
            'items.*.serials.*.actual_present' => 'nullable|boolean',
            'items.*.unknown_serials' => 'nullable|array',
            'items.*.unknown_serials.*' => 'string|max:255',
            'note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $branchId = $this->resolveBranchId($request);
            $existingSnapshots = $stockTake->items->keyBy('product_id')->map(fn ($item) => [
                'system_stock' => (int) ($item->system_stock_snapshot ?? $item->system_stock),
                'cost_price_snapshot' => (float) ($item->cost_price_snapshot ?? 0),
                'unit_name' => $item->unit_name,
                'category_id' => $item->category_id,
                'serial_checks' => $item->serialChecks->map(fn (StockTakeItemSerial $check) => [
                    'serial_imei_id' => $check->serial_imei_id,
                    'serial_number_snapshot' => $check->serial_number_snapshot,
                    'system_present' => (bool) $check->system_present,
                    'status_snapshot' => $check->status_snapshot,
                    'repair_status_snapshot' => $check->repair_status_snapshot,
                    'cost_price_snapshot' => $check->cost_price_snapshot !== null ? (float) $check->cost_price_snapshot : null,
                ])->values()->all(),
            ]);
            $serverItems = $this->prepareItems($request->items, 'draft', $existingSnapshots);

            StockTakeItem::where('stock_take_id', $stockTake->id)->forceDelete();

            foreach ($serverItems as $item) {
                $stockTakeItem = StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id' => $item['product_id'],
                    'system_stock' => $item['system_stock'],
                    'system_stock_snapshot' => $item['system_stock'],
                    'actual_stock' => $item['actual_stock'],
                    'checked' => $item['checked'],
                    'diff_qty' => $item['diff_qty'],
                    'diff_value' => $item['diff_value'],
                    'cost_price_snapshot' => $item['cost_price_snapshot'],
                    'unit_name' => $item['unit_name'],
                    'category_id' => $item['category_id'],
                    'unknown_serials' => $item['unknown_serials'] ?? [],
                ]);
                $this->persistSerialChecks($stockTakeItem, $item['serial_checks'] ?? []);
            }

            $stockTake->update([
                'branch_id' => $branchId,
                'note' => $request->note ?? $stockTake->note,
                'total_actual_qty' => collect($serverItems)->sum(fn ($i) => $i['actual_stock'] ?? 0),
                'total_diff_qty' => collect($serverItems)->sum('diff_qty'),
                'total_diff_increase' => collect($serverItems)->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($serverItems)->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => collect($serverItems)->sum('diff_value'),
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Da cap nhat phieu kiem kho.']);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function balance($id)
    {
        $stockTake = StockTake::with(['items.product', 'items.serialChecks'])->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the can bang phieu tam (draft).'], 422);
        }

        try {
            DB::beginTransaction();
            $balancedDate = BusinessDateTime::forCreate();

            foreach ($stockTake->items as $item) {
                $product = Product::find($item->product_id);
                if (! $product) {
                    continue;
                }

                if (! $item->checked || $item->actual_stock === null) {
                    DB::rollBack();

                    return response()->json(['success' => false, 'message' => 'Khong the hoan thanh khi con hang chua kiem hoac chua nhap thuc te.'], 422);
                }

                $snapshotStock = (int) ($item->system_stock_snapshot ?? $item->system_stock);
                $serialChecks = $item->serialChecks;
                if ($product->has_serial && $serialChecks->isNotEmpty()) {
                    $unknownSerials = $item->unknown_serials ?? [];
                    if ($unknownSerials !== []) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => $this->serialDifferenceMessage($product, [], $unknownSerials),
                        ], 422);
                    }

                    $currentSerials = $this->physicalSerialSnapshot($product->id);
                    $storedSerials = $serialChecks->map(fn (StockTakeItemSerial $check) => [
                        'serial_imei_id' => $check->serial_imei_id,
                        'serial_number_snapshot' => $check->serial_number_snapshot,
                        'status_snapshot' => $check->status_snapshot,
                        'repair_status_snapshot' => $check->repair_status_snapshot,
                        'cost_price_snapshot' => $check->cost_price_snapshot !== null ? (float) $check->cost_price_snapshot : null,
                    ])->all();
                    if (! $this->serialSnapshotsEqual($storedSerials, $currentSerials)) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Danh sách Serial tồn kho đã thay đổi kể từ lúc tạo phiếu. Vui lòng kiểm tra lại trước khi hoàn tất.',
                        ], 422);
                    }

                    if ($product->stock_quantity !== $serialChecks->count()) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => "Sản phẩm \"{$product->name}\" có Tồn kho {$product->stock_quantity} nhưng tập Serial vật lý hiện tại có {$serialChecks->count()} dòng. Dữ liệu tồn kho và Serial đang lệch; chưa thể cân bằng.",
                        ], 422);
                    }

                    $unverified = $serialChecks->filter(fn (StockTakeItemSerial $check) => $check->actual_present === null);
                    if ($unverified->isNotEmpty()) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => "Sản phẩm \"{$product->name}\" vẫn còn Serial/IMEI chưa kiểm tra: {$unverified->pluck('serial_number_snapshot')->implode(', ')}.",
                        ], 422);
                    }

                    $missing = $serialChecks->filter(fn (StockTakeItemSerial $check) => ! $check->actual_present)
                        ->pluck('serial_number_snapshot')->values()->all();
                    if ($missing !== []) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => $this->serialDifferenceMessage($product, $missing),
                        ], 422);
                    }

                    $actualStock = (int) $serialChecks->where('actual_present', true)->count();
                } else {
                    $actualStock = (int) $item->actual_stock;
                }
                $diff = $actualStock - $snapshotStock;

                if ($product->has_serial && $serialChecks->isEmpty() && $diff !== 0) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => $this->serialDifferenceMessage($product),
                    ], 422);
                }

                if ((int) $product->stock_quantity !== $snapshotStock) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Ton he thong cua mot so hang da thay doi tu luc tao phieu. Vui long tai lai ton he thong hoac xac nhan dung snapshot cu truoc khi can bang.',
                    ], 422);
                }

                $item->update([
                    'system_stock' => $snapshotStock,
                    'system_stock_snapshot' => $snapshotStock,
                    'actual_stock' => $actualStock,
                    'checked' => true,
                    'diff_qty' => $diff,
                    'diff_value' => $diff * (float) ($item->cost_price_snapshot ?? $product->cost_price ?? 0),
                ]);

                $this->applyBalanceMovement($stockTake, [
                    'product_id' => $item->product_id,
                    'diff_qty' => $diff,
                    'cost_price_snapshot' => (float) ($item->cost_price_snapshot ?? $product->cost_price ?? 0),
                ]);
            }

            $stockTake->load('items');

            $stockTake->update([
                'status' => 'balanced',
                'balancer_name' => auth()->user()?->name ?? 'Hệ thống',
                'balanced_by' => auth()->id(),
                'balanced_date' => $balancedDate,
                'total_actual_qty' => $stockTake->items->sum('actual_stock'),
                'total_diff_qty' => $stockTake->items->sum('diff_qty'),
                'total_diff_increase' => $stockTake->items->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => $stockTake->items->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => $stockTake->items->sum('diff_value'),
            ]);

            DB::commit();
            ActivityLog::log('stocktake_complete', "Can bang kho phieu {$stockTake->code}", $stockTake);

            return response()->json(['success' => true, 'message' => 'Da can bang kho thanh cong.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel($id)
    {
        $stockTake = StockTake::with('items')->findOrFail($id);

        if ($stockTake->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Phieu da bi huy truoc do.'], 422);
        }

        if ($stockTake->status === 'draft') {
            $stockTake->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => request('cancel_reason'),
            ]);

            return response()->json(['success' => true, 'message' => 'Da huy phieu tam.']);
        }

        try {
            DB::beginTransaction();

            foreach ($stockTake->items as $item) {
                $product = Product::find($item->product_id);
                if (! $product) {
                    continue;
                }
                if ($product->has_serial) {
                    \Log::warning("StockTake cancel skipped serial product {$product->id} (legacy data)", ['stock_take_id' => $stockTake->id]);

                    continue;
                }

                $diff = (int) $item->diff_qty;
                if ($diff === 0) {
                    continue;
                }

                $reverseDiff = -$diff;
                $costPerUnit = (float) ($item->cost_price_snapshot ?? $product->cost_price);
                MovingAvgCostingService::applyAdjustment($product, $reverseDiff);
                $product->refresh();
                StockMovementService::record(
                    $product,
                    $reverseDiff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
                    abs($reverseDiff),
                    $costPerUnit,
                    $stockTake,
                    [
                        'branch_id' => $stockTake->branch_id,
                        'note' => 'Huy kiem kho - dao chenh lech',
                        'moved_at' => now(),
                    ]
                );
            }

            $stockTake->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => request('cancel_reason'),
            ]);

            DB::commit();
            ActivityLog::log('stocktake_cancel', "Huy phieu kiem kho {$stockTake->code}", $stockTake);

            return response()->json(['success' => true, 'message' => 'Da huy phieu kiem kho va hoan ton kho.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function productPayload(Product $product, array $matchedSerialNumbers = []): array
    {
        $unitName = $product->units->firstWhere('is_base_unit', true)?->unit_name
            ?? $product->units->first()?->unit_name
            ?? 'Cai';

        return [
            'id' => $product->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'unit_name' => $unitName,
            'category_id' => $product->category_id,
            'system_stock' => (int) ($product->stock_quantity ?? 0),
            'stock_quantity' => (int) ($product->stock_quantity ?? 0),
            'cost_price' => (float) ($product->cost_price ?? 0),
            'cost_price_snapshot' => (float) ($product->cost_price ?? 0),
            'has_serial' => (bool) $product->has_serial,
            'matched_serial_numbers' => array_values($matchedSerialNumbers),
        ];
    }

    /**
     * StockTake's physical inventory contract deliberately differs from the
     * sellable-for-sale contract: Product::recomputeFromSerials() counts every
     * serial with status=in_stock, regardless of repair metadata. The branch
     * column is document ownership only until a branch stock ledger exists.
     */
    private function physicalInventorySerials(int $productId)
    {
        return SerialImei::query()
            ->where('product_id', $productId)
            ->where('status', 'in_stock')
            ->orderBy('id');
    }

    private function physicalSerialSnapshot(int $productId): array
    {
        return $this->physicalInventorySerials($productId)
            ->get(['id', 'serial_number', 'status', 'repair_status', 'cost_price'])
            ->map(fn (SerialImei $serial) => [
                'serial_imei_id' => (int) $serial->id,
                'serial_number_snapshot' => (string) $serial->serial_number,
                'system_present' => true,
                'status_snapshot' => $serial->status,
                'repair_status_snapshot' => $serial->repair_status,
                'cost_price_snapshot' => $serial->cost_price !== null ? (float) $serial->cost_price : null,
            ])->values()->all();
    }

    private function serialSnapshotIdentity(array $serial): string
    {
        return implode('|', [
            (int) ($serial['serial_imei_id'] ?? 0),
            (string) ($serial['serial_number_snapshot'] ?? ''),
            (string) ($serial['status_snapshot'] ?? ''),
            (string) ($serial['repair_status_snapshot'] ?? ''),
            $serial['cost_price_snapshot'] === null ? '' : number_format((float) $serial['cost_price_snapshot'], 2, '.', ''),
        ]);
    }

    private function serialSnapshotsEqual(array $left, array $right): bool
    {
        $normalize = fn (array $rows) => collect($rows)
            ->map(fn (array $row) => $this->serialSnapshotIdentity($row))
            ->sort()
            ->values()
            ->all();

        return $normalize($left) === $normalize($right);
    }

    private function serialDifferenceMessage(Product $product, array $missing = [], array $unknown = []): string
    {
        $lines = ["Sản phẩm \"{$product->name}\" đang lệch Serial/IMEI."];
        $lines[] = 'Thiếu:';
        $lines[] = $missing !== [] ? '- '.implode(', ', $missing) : '- Không có thông tin Serial hợp lệ để xác nhận.';
        if ($unknown !== []) {
            $lines[] = 'Serial lạ/chưa có trong tồn hệ thống:';
            $lines[] = '- '.implode(', ', $unknown);
        }
        $lines[] = 'Phiếu có thể lưu tạm nhưng chưa thể hoàn thành/cân bằng.';

        return implode("\n", $lines);
    }

    private function persistSerialChecks(StockTakeItem $stockTakeItem, array $checks): void
    {
        foreach ($checks as $check) {
            $stockTakeItem->serialChecks()->create($check);
        }
    }

    private function categoryIdsWithChildren(array $categoryIds): array
    {
        $all = collect($categoryIds)->map(fn ($id) => (int) $id)->filter()->values();
        $frontier = $all;

        while ($frontier->isNotEmpty()) {
            $children = Category::whereIn('parent_id', $frontier)->pluck('id');
            $children = $children->diff($all)->values();
            if ($children->isEmpty()) {
                break;
            }
            $all = $all->merge($children)->unique()->values();
            $frontier = $children;
        }

        return $all->all();
    }

    private function categoryTreePayload(Category $category): array
    {
        $children = $category->childrenRecursive ?? collect();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'children' => $children
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (Category $child) => $this->categoryTreePayload($child))
                ->all(),
        ];
    }

    private function prepareItems(array $items, string $status, $existingSnapshots = null): array
    {
        $seenProductIds = [];
        $serverItems = [];

        foreach ($items as $i => $item) {
            $pid = (int) $item['product_id'];
            if (isset($seenProductIds[$pid])) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'San pham bi trung trong cung phieu kiem kho.']);
            }
            $seenProductIds[$pid] = true;

            $product = Product::with('units')->find($pid);
            if (! $product) {
                continue;
            }
            if ($product->isService()) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'Dich vu khong quan ly ton kho nen khong the kiem kho.']);
            }

            $snapshot = $existingSnapshots?->get($pid);
            $systemStock = (int) ($snapshot['system_stock'] ?? $product->stock_quantity ?? 0);
            $costPriceSnapshot = (float) ($snapshot['cost_price_snapshot'] ?? $product->cost_price ?? 0);

            $actualStock = array_key_exists('actual_stock', $item) && $item['actual_stock'] !== null && $item['actual_stock'] !== ''
                ? (int) $item['actual_stock']
                : null;
            $checked = array_key_exists('checked', $item)
                ? filter_var($item['checked'], FILTER_VALIDATE_BOOL)
                : $actualStock !== null;
            $serialChecks = [];

            if ($product->has_serial && array_key_exists('serials', $item)) {
                $currentSerials = $this->physicalSerialSnapshot($pid);
                $hasStoredSnapshot = is_array($snapshot) && array_key_exists('serial_checks', $snapshot);
                $expectedSerials = $hasStoredSnapshot ? ($snapshot['serial_checks'] ?? []) : $currentSerials;

                if ($hasStoredSnapshot && ! $this->serialSnapshotsEqual($expectedSerials, $currentSerials)) {
                    throw ValidationException::withMessages(["items.{$i}.serials" => 'Danh sách Serial tồn kho đã thay đổi kể từ lúc tạo phiếu. Vui lòng kiểm tra lại trước khi hoàn tất.']);
                }

                $submitted = array_values($item['serials'] ?? []);
                $submittedById = [];
                $submittedNumbers = [];
                foreach ($submitted as $serialIndex => $serial) {
                    $serialId = (int) ($serial['serial_imei_id'] ?? 0);
                    $serialNumber = trim((string) ($serial['serial_number'] ?? ''));
                    if ($serialId <= 0 || $serialNumber === '') {
                        throw ValidationException::withMessages(["items.{$i}.serials.{$serialIndex}" => 'Mỗi Serial phải có mã định danh và số Serial.']);
                    }
                    if (isset($submittedById[$serialId]) || isset($submittedNumbers[$serialNumber])) {
                        throw ValidationException::withMessages(["items.{$i}.serials.{$serialIndex}" => 'Không được gửi trùng Serial trong phiếu kiểm kho.']);
                    }
                    $submittedById[$serialId] = $serial;
                    $submittedNumbers[$serialNumber] = true;
                }

                $expectedById = collect($expectedSerials)->keyBy(fn (array $serial) => (int) $serial['serial_imei_id']);
                $unexpectedIds = array_values(array_diff(array_keys($submittedById), $expectedById->keys()->map(fn ($id) => (int) $id)->all()));
                $missingIds = array_values(array_diff($expectedById->keys()->map(fn ($id) => (int) $id)->all(), array_keys($submittedById)));
                if ($unexpectedIds !== [] || $missingIds !== []) {
                    throw ValidationException::withMessages(["items.{$i}.serials" => 'Danh sách Serial gửi lên không trùng với snapshot tồn kho của sản phẩm.']);
                }

                foreach ($expectedById as $serialId => $expected) {
                    $submittedSerial = $submittedById[(int) $serialId];
                    if (trim((string) ($submittedSerial['serial_number'] ?? '')) !== (string) $expected['serial_number_snapshot']) {
                        throw ValidationException::withMessages(["items.{$i}.serials" => 'Số Serial gửi lên không khớp với Serial tồn kho.']);
                    }
                    $actualPresent = array_key_exists('actual_present', $submittedSerial) && $submittedSerial['actual_present'] !== null
                        ? filter_var($submittedSerial['actual_present'], FILTER_VALIDATE_BOOL)
                        : null;
                    $serialChecks[] = [
                        'serial_imei_id' => (int) $expected['serial_imei_id'],
                        'serial_number_snapshot' => (string) $expected['serial_number_snapshot'],
                        'system_present' => true,
                        'actual_present' => $actualPresent,
                        'status_snapshot' => $expected['status_snapshot'] ?? null,
                        'repair_status_snapshot' => $expected['repair_status_snapshot'] ?? null,
                        'cost_price_snapshot' => $expected['cost_price_snapshot'] ?? null,
                        'checked_at' => $actualPresent === null ? null : now(),
                    ];
                }

                $verified = collect($serialChecks)->filter(fn (array $serial) => $serial['actual_present'] !== null);
                $actualStock = $verified->isEmpty() ? null : $verified->where('actual_present', true)->count();
                $checked = count($serialChecks) === 0 || $verified->count() === count($serialChecks);
                $integrityMatch = $systemStock === count($expectedSerials);
                $unknownSerials = array_values(array_filter(array_map('strval', $item['unknown_serials'] ?? [])));

                if ($status === 'balanced') {
                    if (! $integrityMatch) {
                        throw ValidationException::withMessages(["items.{$i}.serials" => "Tồn số lượng: {$systemStock}; Serial đang tồn: ".count($expectedSerials).'. Dữ liệu tồn kho và Serial đang lệch.']);
                    }
                    if (! $checked || $actualStock === null) {
                        throw ValidationException::withMessages(["items.{$i}.serials" => 'Không thể hoàn thành khi còn Serial/IMEI chưa kiểm tra.']);
                    }
                    if ($unknownSerials !== [] || $actualStock !== $systemStock) {
                        $missing = collect($serialChecks)->filter(fn (array $serial) => ! $serial['actual_present'])->pluck('serial_number_snapshot')->all();
                        throw ValidationException::withMessages(["items.{$i}.serials" => $this->serialDifferenceMessage($product, $missing, $unknownSerials)]);
                    }
                }
            }

            $diffQty = ($checked && $actualStock !== null) ? $actualStock - $systemStock : 0;

            if ($status === 'balanced' && $product->has_serial && $serialChecks === [] && $diffQty !== 0) {
                throw ValidationException::withMessages(["items.{$i}.actual_stock" => $this->serialDifferenceMessage($product)]);
            }

            $unitName = $snapshot['unit_name'] ?? $product->units->firstWhere('is_base_unit', true)?->unit_name ?? $product->units->first()?->unit_name ?? 'Cai';

            $serverItems[] = [
                'product_id' => $pid,
                'system_stock' => $systemStock,
                'actual_stock' => $actualStock,
                'checked' => $checked,
                'diff_qty' => $diffQty,
                'diff_value' => $diffQty * $costPriceSnapshot,
                'cost_price_snapshot' => $costPriceSnapshot,
                'unit_name' => $unitName,
                'category_id' => $snapshot['category_id'] ?? $product->category_id,
                'serial_checks' => $serialChecks,
                'unknown_serials' => $unknownSerials ?? [],
            ];
        }

        return $serverItems;
    }

    private function applyBalanceMovement(StockTake $stockTake, array $item): void
    {
        $diff = (int) $item['diff_qty'];
        if ($diff === 0) {
            return;
        }

        $product = Product::find($item['product_id']);
        if (! $product) {
            return;
        }

        $costPerUnit = (float) ($item['cost_price_snapshot'] ?? $product->cost_price ?? 0);
        MovingAvgCostingService::applyAdjustment($product, $diff);
        $product->refresh();
        StockMovementService::record(
            $product,
            $diff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
            abs($diff),
            $costPerUnit,
            $stockTake,
            [
                'branch_id' => $stockTake->branch_id,
                'note' => 'Can bang kiem kho',
                'moved_at' => $stockTake->balanced_date ?? now(),
            ]
        );
    }

    private function resolveBranchId(Request $request): int
    {
        if ($request->filled('branch_id')) {
            return (int) $request->input('branch_id');
        }

        $branch = Branch::query()->first();
        if (! $branch) {
            $data = ['name' => 'Chi nhanh mac dinh'];
            if (\Schema::hasColumn('branches', 'code')) {
                $data['code'] = 'DEFAULT';
            }
            $branch = Branch::create($data);
        }

        return (int) $branch->id;
    }
}
