<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Damage;
use App\Models\DamageItem;
use App\Models\Product;
use App\Models\Branch;
use App\Models\SerialImei;
use App\Enums\DamageStatus;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use App\Support\Filters\FilterableIndex;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DamageController extends Controller
{
    use FilterableIndex;

    protected function configureDamageFilters(): void
    {
        $this->searchable = ['code', 'note', 'created_by_name', 'destroyed_by_name'];
        $this->searchableRelations = [
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'total_qty', 'total_value', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = null;
        $this->scalarFilters = ['branch_id'];
    }

    public function index(Request $request)
    {
        $this->configureDamageFilters();

        $query = Damage::with(['items.product', 'branch']);
        $this->applyFilters($query, $request);

        $damages = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('Damages/Index', [
            'damages' => $damages,
            'branches' => $branches,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => $branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
                'statuses' => DamageStatus::options(),
            ],
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();
        $defaultBranch = Branch::first();

        return Inertia::render('Damages/Create', [
            'products' => $products,
            'branches' => $branches,
            'defaultBranchId' => $defaultBranch ? $defaultBranch->id : null,
            'damageCode' => 'XH' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
            'status' => 'required|in:draft,completed',
            'branch_id' => 'required|exists:branches,id',
            'note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $damage = Damage::create([
                'code' => $request->code ?? 'XH' . time(),
                'branch_id' => $request->branch_id,
                'status' => $request->status,
                'created_by_name' => 'Trần Văn Tiến', // hardcoded cho demo
                'destroyed_by_name' => collect($request->items)->sum('qty') > 0 ? 'Trần Văn Tiến' : 'Chưa có',
                'destroyed_date' => clone Carbon::now(), // default if empty
                'note' => $request->note,
                'total_qty' => array_sum(array_column($request->items, 'qty')),
                'total_value' => array_sum(array_column($request->items, 'total_value')),
            ]);

            if ($request->filled('action_date')) {
                $damage->created_at = Carbon::parse($request->action_date);
                $damage->destroyed_date = Carbon::parse($request->action_date);
                $damage->save();
            }

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                // RR-09: với hàng có serial, normalize serial_ids cho item này
                $serialIds = [];
                if ($product && $product->has_serial && !empty($item['serial_ids'])) {
                    $serialIds = SerialImei::whereIn('id', $item['serial_ids'])
                        ->where('product_id', $product->id)
                        ->where('status', 'in_stock')
                        ->pluck('id')
                        ->all();
                }

                DamageItem::create([
                    'damage_id'   => $damage->id,
                    'product_id'  => $item['product_id'],
                    'qty'         => $item['qty'],
                    'cost_price'  => $item['cost_price'],
                    'total_value' => $item['total_value'],
                    'note'        => $item['note'] ?? null,
                    'serial_ids'  => !empty($serialIds) ? $serialIds : null,
                ]);

                if ($request->status === 'completed') {
                    if ($product) {
                        if ($product->stock_quantity < $item['qty']) {
                            throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để xuất hủy (Còn: {$product->stock_quantity}, Cần: {$item['qty']}).");
                        }

                        // RR-09: cập nhật BQ + ghi StockMovement (giống pattern RR-04 StockTake).
                        $unitCostBefore = (float) $product->cost_price;
                        MovingAvgCostingService::applyAdjustment($product, -(int) $item['qty']);

                        // RR-09: với hàng serial, đổi đúng các serial đã chọn sang 'defective' (enum hiện có)
                        if ($product->has_serial && !empty($serialIds)) {
                            SerialImei::whereIn('id', $serialIds)
                                ->where('product_id', $product->id)
                                ->update(['status' => 'defective']);
                            $product->refresh();
                            $product->recomputeFromSerials();
                        }

                        StockMovementService::record(
                            $product->fresh(),
                            StockMovementService::TYPE_ADJUST_OUT,
                            (int) $item['qty'],
                            $unitCostBefore,
                            $damage,
                            [
                                'branch_id' => $damage->branch_id,
                                'ref_code'  => $damage->code,
                                'moved_at'  => $damage->destroyed_date ?? now(),
                                'note'      => 'Xuất hủy phiếu ' . $damage->code,
                            ]
                        );
                    }
                }
            }

            DB::commit();

            return redirect()->route('damages.index')->with('success', 'Tạo phiếu xuất hủy thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    /**
     * RR-09: Hủy phiếu xuất hủy — đảo nghiệp vụ (cộng tồn lại, ghi adjust_in,
     * khôi phục serial về in_stock). Idempotent.
     */
    public function cancel(Damage $damage)
    {
        if ($damage->status === DamageStatus::CANCELLED) {
            return back()->with('error', 'Phiếu xuất hủy đã bị hủy trước đó.');
        }

        DB::transaction(function () use ($damage) {
            $damage->load('items');

            // Phiếu draft chưa đụng kho → chỉ đổi status
            if ($damage->status === DamageStatus::DRAFT) {
                $damage->update(['status' => DamageStatus::CANCELLED]);
                return;
            }

            // Phiếu completed: đảo từng item
            foreach ($damage->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) {
                    continue;
                }

                $qty = (int) $item->qty;

                // Cộng tồn lại + cập nhật BQ ngược chiều
                MovingAvgCostingService::applyAdjustment($product, $qty);

                // Khôi phục serial đã hủy về in_stock
                if ($product->has_serial && is_array($item->serial_ids) && !empty($item->serial_ids)) {
                    SerialImei::whereIn('id', $item->serial_ids)
                        ->where('product_id', $product->id)
                        ->update(['status' => 'in_stock']);
                    $product->refresh();
                    $product->recomputeFromSerials();
                }

                StockMovementService::record(
                    $product->fresh(),
                    StockMovementService::TYPE_ADJUST_IN,
                    $qty,
                    (float) ($item->cost_price ?: ($product->cost_price ?? 0)),
                    $damage,
                    [
                        'branch_id' => $damage->branch_id,
                        'ref_code'  => $damage->code,
                        'moved_at'  => now(),
                        'note'      => 'Hủy phiếu xuất hủy ' . $damage->code,
                    ]
                );
            }

            $damage->update(['status' => DamageStatus::CANCELLED]);
        });

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Đã hủy phiếu xuất hủy.']);
        }

        return back()->with('success', 'Đã hủy phiếu xuất hủy ' . $damage->code);
    }

    public function export(Request $request)
    {
        $this->configureDamageFilters();
        $query = Damage::with('branch');
        $this->applyFilters($query, $request);
        $damages = $query->get();

        return \App\Services\CsvService::export(
            ['Mã xuất hủy', 'Chi nhánh', 'Người tạo', 'Người hủy', 'Ngày hủy', 'Tổng SL', 'Tổng giá trị', 'Trạng thái', 'Ghi chú'],
            $damages->map(fn($d) => [$d->code, $d->branch?->name, $d->created_by_name, $d->destroyed_by_name, $d->destroyed_date, $d->total_qty, $d->total_value, $d->status, $d->note]),
            'xuat_huy.csv'
        );
    }

    public function print(\App\Models\Damage $damage)
    {
        $damage->load(['items.product', 'branch']);
        return view('prints.damage', compact('damage'));
    }
}