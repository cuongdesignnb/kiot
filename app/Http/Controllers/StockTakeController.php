<?php

namespace App\Http\Controllers;

use App\Enums\StockTakeStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Services\LockPeriodService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use App\Support\Filters\FilterableIndex;
use Carbon\Carbon;
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

        $query = StockTake::with(['items.product', 'branch']);
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
        return Inertia::render('StockTakes/Create', [
            'products' => Product::where('is_active', true)->where('type', '!=', 'service')->with('units')->get(),
            'branches' => Branch::all(),
            'categories' => Category::with('children.children')->whereNull('parent_id')->orderBy('name')->get(),
            'stockTakeCode' => 'KK' . date('YmdHis'),
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
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $categoryIds = collect($validated['category_ids'] ?? [])->map(fn($id) => (int) $id)->filter()->values();
        if ($categoryIds->isNotEmpty()) {
            $ids = $request->boolean('include_children', true)
                ? $this->categoryIdsWithChildren($categoryIds->all())
                : $categoryIds->all();
            $query->whereIn('category_id', $ids);
        }

        // TODO: Current system uses global product.stock_quantity; branch_id is stored for document ownership but stock calculation is still global until branch stock ledger is implemented.
        $limit = (int) $request->input('limit', $categoryIds->isNotEmpty() ? 500 : 20);

        return response()->json(
            $query->orderBy('name')->limit($limit)->get()->map(fn(Product $product) => $this->productPayload($product))
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'nullable|numeric|min:0',
            'items.*.checked' => 'boolean',
            'status' => 'required|in:draft,balanced',
            'note' => 'nullable|string',
        ]);

        $branchId = $this->resolveBranchId($request);
        $serverItems = $this->prepareItems($request->items, $request->status);

        try {
            DB::beginTransaction();

            $txDate = $request->action_date ? Carbon::parse($request->action_date) : Carbon::now();
            app(LockPeriodService::class)->assertNotLocked($txDate, 'stocktake_create');

            $stockTake = StockTake::create([
                'code' => $request->code ?? 'KK' . time(),
                'branch_id' => $branchId,
                'status' => $request->status,
                'user_name' => auth()->user()?->name ?? 'He thong',
                'created_by' => auth()->id(),
                'balanced_by' => $request->status === 'balanced' ? auth()->id() : null,
                'balancer_name' => $request->status === 'balanced' ? (auth()->user()?->name ?? 'He thong') : null,
                'balanced_date' => $request->status === 'balanced' ? $txDate : null,
                'note' => $request->note,
                'total_actual_qty' => collect($serverItems)->sum(fn($i) => $i['actual_stock'] ?? 0),
                'total_diff_qty' => collect($serverItems)->sum('diff_qty'),
                'total_diff_increase' => collect($serverItems)->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($serverItems)->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => collect($serverItems)->sum('diff_value'),
            ]);

            if ($request->filled('action_date')) {
                $stockTake->created_at = $txDate;
                $stockTake->save();
            }

            foreach ($serverItems as $item) {
                StockTakeItem::create([
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
                ]);

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
            return back()->withErrors(['error' => 'Loi: ' . $e->getMessage()]);
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
            $stockTakes->map(fn($s) => [$s->code, $s->user_name, $s->balancer_name, $s->balanced_date, $s->total_actual_qty, $s->total_diff_qty, $s->status, $s->note]),
            'kiem_kho.csv'
        );
    }

    public function print(StockTake $stockTake)
    {
        $stockTake->load(['items.product', 'branch']);
        return view('prints.stock_take', compact('stockTake'));
    }

    public function show(StockTake $stockTake)
    {
        return redirect()->route('stock-takes.index', ['search' => $stockTake->code]);
    }

    public function update(Request $request, $id)
    {
        $stockTake = StockTake::with('items')->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the sua phieu tam (draft).'], 422);
        }

        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'nullable|numeric|min:0',
            'items.*.checked' => 'boolean',
            'note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $branchId = $this->resolveBranchId($request);
            $existingSnapshots = $stockTake->items->keyBy('product_id')->map(fn($item) => [
                'system_stock' => (int) ($item->system_stock_snapshot ?? $item->system_stock),
                'cost_price_snapshot' => (float) ($item->cost_price_snapshot ?? 0),
                'unit_name' => $item->unit_name,
                'category_id' => $item->category_id,
            ]);
            $serverItems = $this->prepareItems($request->items, 'draft', $existingSnapshots);

            StockTakeItem::where('stock_take_id', $stockTake->id)->forceDelete();

            foreach ($serverItems as $item) {
                StockTakeItem::create([
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
                ]);
            }

            $stockTake->update([
                'branch_id' => $branchId,
                'note' => $request->note ?? $stockTake->note,
                'total_actual_qty' => collect($serverItems)->sum(fn($i) => $i['actual_stock'] ?? 0),
                'total_diff_qty' => collect($serverItems)->sum('diff_qty'),
                'total_diff_increase' => collect($serverItems)->where('diff_qty', '>', 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($serverItems)->where('diff_qty', '<', 0)->sum('diff_qty'),
                'total_diff_value' => collect($serverItems)->sum('diff_value'),
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Da cap nhat phieu kiem kho.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function balance($id)
    {
        $stockTake = StockTake::with('items.product')->findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the can bang phieu tam (draft).'], 422);
        }

        try {
            DB::beginTransaction();

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
                $actualStock = (int) $item->actual_stock;
                $diff = $actualStock - $snapshotStock;

                if ($product->has_serial && $diff !== 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "San pham \"{$product->name}\" co quan ly Serial/IMEI - chua ho tro can bang chenh lech neu khong khai bao serial cu the.",
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
                'balancer_name' => auth()->user()?->name ?? 'He thong',
                'balanced_by' => auth()->id(),
                'balanced_date' => Carbon::now(),
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

    private function productPayload(Product $product): array
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
        ];
    }

    private function categoryIdsWithChildren(array $categoryIds): array
    {
        $all = collect($categoryIds)->map(fn($id) => (int) $id)->filter()->values();
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

            $actualStock = array_key_exists('actual_stock', $item) && $item['actual_stock'] !== null && $item['actual_stock'] !== ''
                ? (int) $item['actual_stock']
                : null;
            $checked = array_key_exists('checked', $item)
                ? filter_var($item['checked'], FILTER_VALIDATE_BOOL)
                : $actualStock !== null;

            if ($status === 'balanced' && (! $checked || $actualStock === null)) {
                throw ValidationException::withMessages(["items.{$i}.actual_stock" => 'Khong the hoan thanh khi con hang chua kiem hoac chua nhap thuc te.']);
            }

            $snapshot = $existingSnapshots?->get($pid);
            $systemStock = (int) ($snapshot['system_stock'] ?? $product->stock_quantity ?? 0);
            $costPriceSnapshot = (float) ($snapshot['cost_price_snapshot'] ?? $product->cost_price ?? 0);
            $diffQty = ($checked && $actualStock !== null) ? $actualStock - $systemStock : 0;

            if ($status === 'balanced' && $product->has_serial && $diffQty !== 0) {
                throw ValidationException::withMessages(["items.{$i}.actual_stock" => "San pham \"{$product->name}\" co quan ly Serial/IMEI - chua ho tro can bang chenh lech neu khong khai bao serial cu the."]);
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
