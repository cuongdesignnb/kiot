<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Branch;
use App\Models\Product;
use App\Models\SerialImei;
use App\Enums\StockTransferStatus;
use App\Support\Filters\FilterableIndex;
use App\Services\LockPeriodService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    use FilterableIndex;

    protected function configureStockTransferFilters(): void
    {
        $this->searchable = ['code', 'note'];
        $this->searchableRelations = [
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'sent_date', 'total_quantity', 'total_price', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = null;
        $this->scalarFilters = ['from_branch_id', 'to_branch_id'];
    }

    public function index(Request $request)
    {
        // Seed branches if empty
        if (Branch::count() === 0) {
            Branch::insert([
                ['name' => 'Chi nhánh trung tâm', 'phone' => '0988123456'],
                ['name' => 'Chi nhánh miền Nam', 'phone' => '0912123456']
            ]);
        }

        $this->configureStockTransferFilters();

        $query = StockTransfer::with(['fromBranch', 'toBranch']);
        $this->applyFilters($query, $request);

        $transfers = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'branches' => $branches,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => $branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
                'statuses' => StockTransferStatus::options(),
            ],
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();

        return Inertia::render('StockTransfers/Create', [
            'products' => $products,
            'branches' => $branches,
            'transferCode' => 'CH' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id|different:from_branch_id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
            'status' => 'required|in:draft,transferring,received',
            'note' => 'nullable|string'
        ], [
            'to_branch_id.different' => 'Chi nhánh nhận phải khác chi nhánh chuyển.',
        ]);

        // ===== Step 23.5 pre-flight =====
        // 1) Chặn duplicate product_id trong cùng phiếu.
        // 2) Chặn hàng has_serial ở trạng thái transferring/received
        //    vì chưa có serial detail/snapshot — không được chuyển mù.
        // 3) Backend tự tính total_quantity/total_price từ cost_price hiện tại,
        //    KHÔNG tin client price.
        $seenProductIds = [];
        $serverItems = [];
        $serverTotalQty = 0;
        $serverTotalPrice = 0.0;
        foreach ($request->items as $idx => $line) {
            $pid = (int) $line['product_id'];
            if (isset($seenProductIds[$pid])) {
                return back()->withErrors([
                    "items.{$idx}.product_id" => 'Sản phẩm bị trùng trong cùng phiếu chuyển kho.',
                ])->withInput();
            }
            $seenProductIds[$pid] = true;

            $product = Product::find($pid);
            if (!$product) {
                return back()->withErrors([
                    "items.{$idx}.product_id" => 'Sản phẩm không tồn tại.',
                ])->withInput();
            }

            $qty = (int) $line['quantity'];
            $costAtTransfer = (float) $product->cost_price;
            $serialIds = isset($line['serial_ids']) && is_array($line['serial_ids'])
                ? array_values(array_unique(array_map('intval', $line['serial_ids'])))
                : [];

            // Step 23.9: Hàng has_serial — yêu cầu serial_ids khi không phải draft.
            if ($request->status !== 'draft' && $product->has_serial) {
                if (empty($serialIds)) {
                    return back()->withErrors([
                        "items.{$idx}.product_id" => 'Sản phẩm "' . $product->name . '" có Serial/IMEI — cần chọn đủ ' . $qty . ' serial.',
                    ])->withInput();
                }
                if (count($line['serial_ids'] ?? []) !== count($serialIds)) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Serial bị trùng trong danh sách.',
                    ])->withInput();
                }
                if (count($serialIds) !== $qty) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Số serial (' . count($serialIds) . ") không khớp số lượng ({$qty}).",
                    ])->withInput();
                }
                $serials = SerialImei::whereIn('id', $serialIds)->get();
                if ($serials->count() !== count($serialIds)) {
                    return back()->withErrors([
                        "items.{$idx}.serial_ids" => 'Một hoặc nhiều Serial/IMEI không tồn tại.',
                    ])->withInput();
                }
                foreach ($serials as $s) {
                    if ((int) $s->product_id !== (int) $product->id) {
                        return back()->withErrors([
                            "items.{$idx}.serial_ids" => "Serial {$s->serial_number} không thuộc sản phẩm \"{$product->name}\".",
                        ])->withInput();
                    }
                    if ($s->status !== 'in_stock') {
                        return back()->withErrors([
                            "items.{$idx}.serial_ids" => "Serial {$s->serial_number} không trong kho (status: {$s->status}).",
                        ])->withInput();
                    }
                }
            } elseif (!$product->has_serial && !empty($serialIds)) {
                return back()->withErrors([
                    "items.{$idx}.serial_ids" => 'Sản phẩm "' . $product->name . '" không phải hàng Serial/IMEI — không nhận serial_ids.',
                ])->withInput();
            }

            $serverItems[] = [
                'product_id'       => $pid,
                'quantity'         => $qty,
                'cost_at_transfer' => $costAtTransfer,
                'price'            => $qty * $costAtTransfer,
                'product'          => $product,
                'serial_ids'       => $product->has_serial ? $serialIds : null,
            ];
            $serverTotalQty += $qty;
            $serverTotalPrice += $qty * $costAtTransfer;
        }

        try {
            DB::beginTransaction();

            // Lock period check
            $txDate = $request->action_date ? Carbon::parse($request->action_date) : Carbon::now();
            app(LockPeriodService::class)->assertNotLocked($txDate, 'transfer_create');

            $transfer = StockTransfer::create([
                'code' => $request->code ?? 'CH' . time(),
                'from_branch_id' => $request->from_branch_id,
                'to_branch_id' => $request->to_branch_id,
                'status' => $request->status,
                'note' => $request->note,
                'sent_date' => $request->status !== 'draft' ? Carbon::now() : null,
                'receive_date' => $request->status === 'received' ? Carbon::now() : null,
                'total_quantity' => $serverTotalQty,
                'total_price' => $serverTotalPrice,
            ]);

            if ($request->filled('action_date')) {
                $transfer->created_at = Carbon::parse($request->action_date);
                if ($request->status !== 'draft') {
                    $transfer->sent_date = Carbon::parse($request->action_date);
                }
                if ($request->status === 'received') {
                    $transfer->receive_date = Carbon::parse($request->action_date);
                }
                $transfer->save();
            }

            foreach ($serverItems as $row) {
                /** @var \App\Models\Product $product */
                $product = $row['product'];
                $qty = $row['quantity'];
                $costAtTransfer = $row['cost_at_transfer'];

                // Validate stock before transfer
                if ($request->status !== 'draft' && $product->stock_quantity < $qty) {
                    throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để chuyển hàng (Còn: {$product->stock_quantity}, Cần: {$qty}).");
                }

                $transferItem = StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $product->id,
                    'quantity'          => $qty,
                    'price'             => $row['price'],
                    'cost_at_transfer'  => $costAtTransfer,
                    'serial_ids'        => $row['serial_ids'],
                ]);

                $serialIds = $row['serial_ids'] ?? [];

                // Transfer out: deduct stock + update costing + record movement
                if ($request->status !== 'draft') {
                    $cogs = MovingAvgCostingService::applySale($product, $qty);
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_TRANSFER_OUT,
                        $qty,
                        $cogs['cogs_per_unit'],
                        $transfer,
                        ['branch_id' => $request->from_branch_id]
                    );

                    // Step 23.9: Mark serials in_transit (transferring) hoặc giữ in_stock cho 'received'
                    // (vì received nghĩa là transfer + receive ngay → serial vẫn ở kho đích).
                    if ($product->has_serial && !empty($serialIds)) {
                        if ($request->status === 'transferring') {
                            SerialImei::whereIn('id', $serialIds)->update(['status' => 'in_transit']);
                        }
                        // 'received' → serial giữ in_stock (đã ở kho đích). Stock movement vẫn ghi đầy đủ in/out.
                    }
                }

                // Transfer in (received immediately): add stock + update costing + record movement
                if ($request->status === 'received') {
                    // RR-12: dùng cost_at_transfer (snapshot lúc xuất) thay vì current cost
                    // để hàng giữ đúng giá vốn nguồn khi nhập kho đích.
                    $costPerUnit = $costAtTransfer;
                    MovingAvgCostingService::applyPurchase($product, $qty, $costPerUnit);
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_TRANSFER_IN,
                        $qty,
                        $costPerUnit,
                        $transfer,
                        ['branch_id' => $request->to_branch_id]
                    );
                    $transferItem->update(['received_quantity' => $qty]);

                    // Step 23.9: Sync stock_quantity cho hàng has_serial theo serial in_stock thực tế
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }
                } elseif ($request->status === 'transferring' && $product->has_serial) {
                    // Sync stock_quantity sau khi serial → in_transit (giảm count in_stock)
                    $product->recomputeFromSerials();
                }
            }

            DB::commit();

            ActivityLog::log('transfer_create', "Tạo phiếu chuyển kho {$transfer->code}, trạng thái: {$transfer->status}", $transfer);

            return redirect()->route('stock-transfers.index')->with('success', 'Tạo phiếu chuyển hàng thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $this->configureStockTransferFilters();
        $query = StockTransfer::with(['fromBranch', 'toBranch']);
        $this->applyFilters($query, $request);
        $transfers = $query->get();

        return \App\Services\CsvService::export(
            ['Mã chuyển hàng', 'Chi nhánh chuyển', 'Chi nhánh nhận', 'Ngày chuyển', 'Ngày nhận', 'Tổng SL', 'Tổng giá trị', 'Trạng thái', 'Ghi chú'],
            $transfers->map(fn($t) => [$t->code, $t->fromBranch?->name, $t->toBranch?->name, $t->sent_date, $t->receive_date, $t->total_quantity, $t->total_price, $t->status, $t->note]),
            'chuyen_hang.csv'
        );
    }

    public function print(\App\Models\StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['items.product', 'fromBranch', 'toBranch']);
        return view('prints.stock_transfer', compact('stockTransfer'));
    }

    /**
     * Chi tiet phieu chuyen hang.
     */
    public function show(StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['items.product', 'fromBranch', 'toBranch']);

        return Inertia::render('StockTransfers/Show', [
            'transfer' => $stockTransfer,
        ]);
    }

    /**
     * Nhan hang tai kho dich — cap nhat received_quantity, cong stock destination.
     */
    public function receive(Request $request, $id)
    {
        $transfer = StockTransfer::with('items')->findOrFail($id);

        if ($transfer->status !== 'transferring') {
            return response()->json(['success' => false, 'message' => 'Chi co the nhan hang voi phieu dang chuyen.'], 422);
        }

        // Validate receive_date >= sent_date
        $receiveDate = $request->receive_date ? Carbon::parse($request->receive_date) : Carbon::now();
        if ($transfer->sent_date && $receiveDate->lt($transfer->sent_date)) {
            return response()->json(['success' => false, 'message' => 'Ngay nhan khong duoc truoc ngay chuyen.'], 422);
        }

        // Build received quantities
        $receivedItems = collect($request->items ?? []);
        $isPartial = false;
        $resolvedQty = [];

        // Step 23.5: KHÔNG clamp âm thầm. Validate strict.
        foreach ($transfer->items as $item) {
            $recv = $receivedItems->firstWhere('product_id', $item->product_id);
            $recvQty = $recv !== null && array_key_exists('received_quantity', $recv)
                ? (int) $recv['received_quantity']
                : (int) $item->quantity;

            if ($recvQty < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Số lượng nhận không được âm.',
                ], 422);
            }
            if ($recvQty > (int) $item->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Số lượng nhận không được vượt số lượng đã chuyển.',
                ], 422);
            }

            // Step 23.9: hàng has_serial không hỗ trợ partial receive ở step này.
            $product = $item->product ?? Product::find($item->product_id);
            $hasSerial = $product?->has_serial && !empty($item->serial_ids);
            if ($hasSerial && $recvQty !== (int) $item->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hàng Serial/IMEI hiện chưa hỗ trợ nhận một phần — phải nhận đủ ' . $item->quantity . ' cái.',
                ], 422);
            }

            if ($recvQty < (int) $item->quantity) {
                $isPartial = true;
            }
            $resolvedQty[$item->id] = $recvQty;
        }

        // If partial, require note
        if ($isPartial && empty($request->receive_note)) {
            return response()->json(['success' => false, 'message' => 'Nhận hàng thiếu cần ghi chú lý do.'], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $recvQty = $resolvedQty[$item->id];

                $item->update(['received_quantity' => $recvQty]);

                // Add stock to destination via CostingService + record movement
                if ($recvQty > 0) {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        // RR-12: dùng cost_at_transfer (snapshot lúc xuất); fallback current cost cho legacy
                        $costPerUnit = (float) ($item->cost_at_transfer ?: $product->cost_price);
                        MovingAvgCostingService::applyPurchase($product, $recvQty, $costPerUnit);
                        $product->refresh();
                        StockMovementService::record(
                            $product,
                            StockMovementService::TYPE_TRANSFER_IN,
                            $recvQty,
                            $costPerUnit,
                            $transfer,
                            ['branch_id' => $transfer->to_branch_id]
                        );

                        // Step 23.9: Hàng has_serial — chuyển in_transit → in_stock
                        if ($product->has_serial && !empty($item->serial_ids)) {
                            $serials = SerialImei::whereIn('id', $item->serial_ids)
                                ->where('product_id', $product->id)
                                ->get();
                            foreach ($serials as $s) {
                                if ($s->status !== 'in_transit') {
                                    throw new \RuntimeException(
                                        "Serial {$s->serial_number} không ở trạng thái in_transit (đang: {$s->status}). Không thể nhận."
                                    );
                                }
                            }
                            SerialImei::whereIn('id', $item->serial_ids)->update(['status' => 'in_stock']);
                            $product->recomputeFromSerials();
                        }
                    }
                }
            }

            $transfer->update([
                'status' => 'received',
                'receive_date' => $receiveDate,
                'note' => $isPartial
                    ? ($transfer->note ? $transfer->note . ' | ' : '') . 'Nhan hang: ' . ($request->receive_note ?? '')
                    : $transfer->note,
            ]);

            DB::commit();
            ActivityLog::log('transfer_receive', "Nhận hàng chuyển kho {$transfer->code}", $transfer);
            return response()->json(['success' => true, 'message' => 'Da nhan hang thanh cong.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Huy phieu chuyen hang — hoan stock theo trang thai hien tai.
     */
    public function cancel($id)
    {
        $transfer = StockTransfer::with('items')->findOrFail($id);

        if ($transfer->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Phieu da bi huy truoc do.'], 422);
        }

        if ($transfer->status === 'draft') {
            $transfer->update(['status' => 'cancelled']);
            return response()->json(['success' => true, 'message' => 'Da huy phieu nhap.']);
        }

        // Step 23.9: pre-flight check serial cho hàng has_serial trước khi rollback bất cứ thứ gì.
        $originalStatus = $transfer->status;
        if ($originalStatus === 'received') {
            foreach ($transfer->items as $item) {
                if (empty($item->serial_ids)) continue;
                $product = Product::find($item->product_id);
                if (!$product || !$product->has_serial) continue;
                $serials = SerialImei::whereIn('id', $item->serial_ids)->get();
                foreach ($serials as $s) {
                    if ($s->status !== 'in_stock') {
                        return response()->json([
                            'success' => false,
                            'message' => "Không thể hủy: serial {$s->serial_number} đã không còn in_stock (đang: {$s->status}). Có thể đã bán/dùng sau khi nhận.",
                        ], 422);
                    }
                }
            }
        }

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                // RR-12: dùng cost_at_transfer (snapshot lúc transfer_out) thay vì current
                // cost_price để cancel khôi phục cost đúng khi BQ đã thay đổi giữa các pha.
                // Fallback current cost_price cho legacy records không có snapshot.
                $costPerUnit = (float) ($item->cost_at_transfer ?: $product->cost_price);

                // If received, reverse destination stock first (transfer_in reversal).
                // RR-12: applyPurchaseReturn rút tồn ở cost snapshot, không như applySale
                // dùng current BQ — đảm bảo total_cost đảo đúng theo snapshot.
                if ($originalStatus === 'received' && $item->received_quantity > 0) {
                    MovingAvgCostingService::applyPurchaseReturn(
                        $product,
                        (int) $item->received_quantity,
                        $costPerUnit
                    );
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_TRANSFER_OUT,
                        $item->received_quantity,
                        $costPerUnit,
                        $transfer,
                        ['branch_id' => $transfer->to_branch_id, 'note' => 'Hủy chuyển kho — đảo nhận']
                    );
                }

                // Restore source stock (reverse transfer_out) — dùng cùng snapshot
                MovingAvgCostingService::applyPurchase($product, $item->quantity, $costPerUnit);
                $product->refresh();
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_TRANSFER_IN,
                    $item->quantity,
                    $costPerUnit,
                    $transfer,
                    ['branch_id' => $transfer->from_branch_id, 'note' => 'Hủy chuyển kho — hoàn kho nguồn']
                );

                // Step 23.9: Rollback serial cho hàng has_serial.
                // - transferring → serial in_transit → in_stock (chưa từng tới đích).
                // - received    → serial vẫn in_stock (đã pre-check), không đổi status.
                if ($product->has_serial && !empty($item->serial_ids)) {
                    if ($originalStatus === 'transferring') {
                        SerialImei::whereIn('id', $item->serial_ids)
                            ->where('product_id', $product->id)
                            ->where('status', 'in_transit')
                            ->update(['status' => 'in_stock']);
                    }
                    $product->recomputeFromSerials();
                }
            }

            $transfer->update(['status' => 'cancelled']);

            DB::commit();
            ActivityLog::log('transfer_cancel', "Hủy phiếu chuyển kho {$transfer->code}", $transfer);
            return response()->json(['success' => true, 'message' => 'Da huy phieu chuyen hang.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}