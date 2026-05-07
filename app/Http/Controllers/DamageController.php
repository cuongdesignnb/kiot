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
        $this->dateColumn = \Illuminate\Support\Facades\DB::raw('COALESCE(destroyed_date, created_at)');
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

        // Step 22.1B (read-only): enrich items[].destroyed_serials cho UI hiển thị.
        $allSerialIds = [];
        foreach ($damages->items() as $d) {
            foreach ($d->items as $it) {
                if (is_array($it->serial_ids)) {
                    foreach ($it->serial_ids as $sid) $allSerialIds[] = $sid;
                }
            }
        }
        $serialMap = [];
        if (!empty($allSerialIds)) {
            $serialMap = SerialImei::whereIn('id', array_unique($allSerialIds))
                ->get(['id', 'serial_number'])
                ->keyBy('id');
        }
        foreach ($damages->items() as $d) {
            foreach ($d->items as $it) {
                $list = [];
                if (is_array($it->serial_ids)) {
                    foreach ($it->serial_ids as $sid) {
                        $s = $serialMap[$sid] ?? null;
                        $list[] = [
                            'id'            => (int) $sid,
                            'serial_number' => $s?->serial_number,
                        ];
                    }
                }
                $it->setAttribute('destroyed_serials', $list);
            }
        }

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

        // ===== Step 23.6 pre-flight =====
        // 1) Chặn duplicate product_id trong cùng phiếu.
        // 2) Server-side cost_price/total_value (không tin client).
        // 3) Hàng has_serial ở completed: serial_ids bắt buộc, count = qty,
        //    không duplicate, thuộc product, status in_stock. Validate STRICT.
        // 4) Chuẩn bị serverItems[] cho vòng lặp chính.
        $seenProductIds = [];
        $seenSerialIds  = [];
        $serverItems    = [];
        $serverTotalQty   = 0;
        $serverTotalValue = 0.0;

        foreach ($request->items as $idx => $line) {
            $pid = (int) $line['product_id'];
            if (isset($seenProductIds[$pid])) {
                return back()->withErrors([
                    "items.{$idx}.product_id" => 'Sản phẩm bị trùng trong cùng phiếu xuất hủy.',
                ])->withInput();
            }
            $seenProductIds[$pid] = true;

            $product = Product::find($pid);
            if (!$product) {
                return back()->withErrors([
                    "items.{$idx}.product_id" => 'Sản phẩm không tồn tại.',
                ])->withInput();
            }

            $qty = (int) $line['qty'];
            $serialIds = array_values(array_unique(array_map('intval', (array) ($line['serial_ids'] ?? []))));

            // Duplicate serial across the whole request
            foreach ($serialIds as $sid) {
                if (isset($seenSerialIds[$sid])) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Serial bị trùng trong phiếu xuất hủy.',
                    ])->withInput();
                }
                $seenSerialIds[$sid] = true;
            }

            // Duplicate within the same line (after array_unique they are distinct,
            // nếu user gửi 2 cai giống nhau thì count sẽ lệch qty → fail step tiếp theo).
            if (count($serialIds) !== count((array) ($line['serial_ids'] ?? []))) {
                return back()->withErrors([
                    "items.{$idx}.serial_ids" => 'Serial bị trùng trong cùng dòng sản phẩm.',
                ])->withInput();
            }

            // Strict serial validation cho completed + has_serial
            if ($request->status === 'completed' && $product->has_serial) {
                if (count($serialIds) !== $qty) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Số lượng serial phải bằng số lượng xuất hủy (sản phẩm “' . $product->name . '”).',
                    ])->withInput();
                }
                $valid = SerialImei::whereIn('id', $serialIds)
                    ->where('product_id', $product->id)
                    ->where('status', 'in_stock')
                    ->pluck('id')
                    ->all();
                if (count($valid) !== count($serialIds)) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Serial không hợp lệ: phải thuộc sản phẩm “' . $product->name . '” và đang ở trạng thái in_stock.',
                    ])->withInput();
                }
            }

            $costPrice  = (float) $product->cost_price;
            $totalValue = $qty * $costPrice;

            $serverItems[] = [
                'product_id'  => $pid,
                'qty'         => $qty,
                'cost_price'  => $costPrice,
                'total_value' => $totalValue,
                'note'        => $line['note'] ?? null,
                'serial_ids'  => !empty($serialIds) ? $serialIds : null,
                'product'     => $product,
            ];
            $serverTotalQty   += $qty;
            $serverTotalValue += $totalValue;
        }

        try {
            DB::beginTransaction();

            $damage = Damage::create([
                'code' => $request->code ?? 'XH' . time(),
                'branch_id' => $request->branch_id,
                'status' => $request->status,
                'created_by_name' => 'Trần Văn Tiến', // hardcoded cho demo
                'destroyed_by_name' => $serverTotalQty > 0 ? 'Trần Văn Tiến' : 'Chưa có',
                'destroyed_date' => clone Carbon::now(),
                'note' => $request->note,
                'total_qty' => $serverTotalQty,
                'total_value' => $serverTotalValue,
            ]);

            if ($request->filled('action_date')) {
                $damage->created_at = Carbon::parse($request->action_date);
                $damage->destroyed_date = Carbon::parse($request->action_date);
                $damage->save();
            }

            foreach ($serverItems as $row) {
                /** @var \App\Models\Product $product */
                $product   = $row['product'];
                $qty       = $row['qty'];
                $costPrice = $row['cost_price'];
                $serialIds = $row['serial_ids'] ?? [];

                DamageItem::create([
                    'damage_id'   => $damage->id,
                    'product_id'  => $product->id,
                    'qty'         => $qty,
                    'cost_price'  => $costPrice,
                    'total_value' => $row['total_value'],
                    'note'        => $row['note'],
                    'serial_ids'  => !empty($serialIds) ? $serialIds : null,
                ]);

                if ($request->status === 'completed') {
                    if ($product->stock_quantity < $qty) {
                        throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để xuất hủy (Còn: {$product->stock_quantity}, Cần: {$qty}).");
                    }

                    // RR-09: cập nhật BQ + ghi StockMovement (giống pattern RR-04 StockTake).
                    $unitCostBefore = $costPrice;
                    MovingAvgCostingService::applyAdjustment($product, -$qty);

                    // RR-09: với hàng serial, đổi đúng các serial đã chọn sang 'defective'
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
                        $qty,
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

            DB::commit();

            // Step 24.0: audit log damage create
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_DAMAGE_CREATE,
                "Tạo phiếu xuất hủy {$damage->code}",
                $damage,
                ['total_quantity' => (int) ($damage->total_quantity ?? 0)]
            );

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
            if (request()->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Phiếu xuất hủy đã bị hủy trước đó.'], 422);
            }
            return back()->with('error', 'Phiếu xuất hủy đã bị hủy trước đó.');
        }

        // Step 23.6: chặn cancel cho legacy phiếu completed có hàng has_serial
        // mà serial_ids bị null/empty — KHÔNG đoán serial.
        if ($damage->status === DamageStatus::COMPLETED) {
            $damage->load('items.product');
            foreach ($damage->items as $item) {
                if ($item->product && $item->product->has_serial) {
                    if (!is_array($item->serial_ids) || empty($item->serial_ids)) {
                        $msg = 'Phiếu có sản phẩm có serial nhưng không lưu serial_ids snapshot. Không thể tự động hủy; vui lòng xử lý thủ công.';
                        if (request()->wantsJson()) {
                            return response()->json(['success' => false, 'message' => $msg], 422);
                        }
                        return back()->with('error', $msg);
                    }
                }
            }
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

        // Step 24.0: audit log damage cancel
        \App\Models\ActivityLog::log(
            \App\Models\ActivityLog::ACTION_DAMAGE_CANCEL,
            "Hủy phiếu xuất hủy {$damage->code}",
            $damage
        );

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