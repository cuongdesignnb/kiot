<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Branch;
use App\Models\Product;
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
            'to_branch_id' => 'required|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'status' => 'required|in:draft,transferring,received',
            'note' => 'nullable|string'
        ]);

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
                'total_quantity' => array_sum(array_column($request->items, 'quantity')),
                'total_price' => array_sum(array_column($request->items, 'price'))
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

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                // Validate stock before transfer
                if ($request->status !== 'draft') {
                    if ($product && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để chuyển hàng (Còn: {$product->stock_quantity}, Cần: {$item['quantity']}).");
                    }
                }

                // RR-12: snapshot BQ TRƯỚC khi applySale để cancel/receive
                // dùng đúng cost lúc xuất chuyển (không phụ thuộc current BQ).
                $costAtTransfer = $product ? (float) $product->cost_price : 0.0;

                $transferItem = StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $item['product_id'],
                    'quantity'          => $item['quantity'],
                    'price'             => $item['price'] ?? 0,
                    'cost_at_transfer'  => $costAtTransfer,
                ]);

                // Transfer out: deduct stock + update costing + record movement
                if ($request->status !== 'draft' && $product) {
                    $cogs = MovingAvgCostingService::applySale($product, $item['quantity']);
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_TRANSFER_OUT,
                        $item['quantity'],
                        $cogs['cogs_per_unit'],
                        $transfer,
                        ['branch_id' => $request->from_branch_id]
                    );
                }

                // Transfer in (received immediately): add stock + update costing + record movement
                if ($request->status === 'received' && $product) {
                    // RR-12: dùng cost_at_transfer (snapshot lúc xuất) thay vì current cost
                    // để hàng giữ đúng giá vốn nguồn khi nhập kho đích.
                    $costPerUnit = $costAtTransfer;
                    MovingAvgCostingService::applyPurchase($product, $item['quantity'], $costPerUnit);
                    $product->refresh();
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_TRANSFER_IN,
                        $item['quantity'],
                        $costPerUnit,
                        $transfer,
                        ['branch_id' => $request->to_branch_id]
                    );
                    $transferItem->update(['received_quantity' => $item['quantity']]);
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

        foreach ($transfer->items as $item) {
            $recv = $receivedItems->firstWhere('product_id', $item->product_id);
            $recvQty = $recv ? (int)$recv['received_quantity'] : $item->quantity;

            if ($recvQty < 0) $recvQty = 0;
            if ($recvQty > $item->quantity) $recvQty = $item->quantity;

            if ($recvQty < $item->quantity) $isPartial = true;
        }

        // If partial, require note
        if ($isPartial && empty($request->receive_note)) {
            return response()->json(['success' => false, 'message' => 'Nhan hang thieu can ghi chu ly do.'], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($transfer->items as $item) {
                $recv = $receivedItems->firstWhere('product_id', $item->product_id);
                $recvQty = $recv ? (int)$recv['received_quantity'] : $item->quantity;

                if ($recvQty < 0) $recvQty = 0;
                if ($recvQty > $item->quantity) $recvQty = $item->quantity;

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
                if ($transfer->status === 'received' && $item->received_quantity > 0) {
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