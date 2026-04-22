<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Setting;
use App\Enums\PurchaseOrderStatus;
use App\Support\Filters\FilterableIndex;
use App\Services\LockPeriodService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    use FilterableIndex;

    protected function configurePurchaseOrderFilters(): void
    {
        $this->searchable = ['code', 'note', 'created_by_name', 'ordered_by_name'];
        $this->searchableRelations = [
            'supplier' => ['name', 'code', 'phone'],
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'total_amount', 'total_payment', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = null;
        $this->scalarFilters = ['branch_id', 'supplier_id'];
    }

    public function index(Request $request)
    {
        $this->configurePurchaseOrderFilters();

        $query = PurchaseOrder::with(['items.product', 'branch', 'supplier']);
        $this->applyFilters($query, $request);

        $purchaseOrders = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'branches' => $branches,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => $branches->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
                'statuses' => PurchaseOrderStatus::options(),
                'suppliers' => Customer::where('is_supplier', true)->orderBy('name')->get(['id', 'name'])->map(fn($s) => ['value' => $s->id, 'label' => $s->name]),
            ],
        ]);
    }

    public function create()
    {
        if (!Setting::get('purchase_order_enabled', true)) {
            return redirect()->route('purchase-orders.index')->with('error', 'Chức năng đặt hàng nhập đã bị tắt.');
        }

        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();
        $defaultBranch = Branch::first();
        $suppliers = Customer::where('is_supplier', true)->get();

        return Inertia::render('PurchaseOrders/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'defaultBranchId' => $defaultBranch ? $defaultBranch->id : null,
            'purchaseOrderCode' => 'DDH' . date('YmdHis') // Đơn đặt hàng nhập
        ]);
    }

    public function store(Request $request)
    {
        if (!Setting::get('purchase_order_enabled', true)) {
            return back()->with('error', 'Chức năng đặt hàng nhập đã bị tắt.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'status' => 'required|in:draft,confirmed,partial,completed',
            'branch_id' => 'required|exists:branches,id',
            'supplier_id' => 'nullable|exists:customers,id',
            'expected_date' => 'nullable|date',
            'note' => 'nullable|string'
        ]);

        // Lock period check
        $txDate = $request->order_date ? Carbon::parse($request->order_date) : now();
        app(LockPeriodService::class)->assertNotLocked($txDate, 'po_create');

        try {
            DB::beginTransaction();

            $totalAmount = array_sum(array_column($request->items, 'total_value'));
            $discount = $request->input('discount', 0);
            $importFee = $request->input('import_fee', 0);
            $otherImportFee = $request->input('other_import_fee', 0);
            $supplierDeposit = $request->input('supplier_deposit', 0);
            $totalPayment = $totalAmount - $discount + $importFee + $otherImportFee;

            $purchaseOrder = PurchaseOrder::create([
                'code' => $request->code ?? 'DDH' . time(),
                'branch_id' => $request->branch_id,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status,
                'total_amount' => $totalAmount,
                'discount' => $discount,
                'import_fee' => $importFee,
                'other_import_fee' => $otherImportFee,
                'total_payment' => $totalPayment,
                'supplier_deposit' => $supplierDeposit,
                'expected_date' => $request->expected_date,
                'note' => $request->note,
                'created_by_name' => auth()->user()?->name ?? 'Admin',
                'ordered_by_name' => $request->ordered_by_name ?? auth()->user()?->name ?? 'Admin',
            ]);

            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'received_qty' => 0,
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'total_value' => $item['total_value']
                ]);
            }

            // NOTE: PO does NOT adjust stock. Stock only changes via Purchase (receipt).

            DB::commit();

            // Cho phép chọn ngày đặt hàng (kế toán nhập sau)
            if ($request->filled('order_date')) {
                $purchaseOrder->update(['created_at' => Carbon::parse($request->order_date)]);
            }

            ActivityLog::log('po_create', "Tạo đặt hàng nhập {$purchaseOrder->code}, tổng: " . number_format($purchaseOrder->total_payment), $purchaseOrder);

            return redirect()->route('purchase-orders.index')->with('success', 'Tạo phiếu đặt hàng nhập thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    /**
     * Update editable fields on existing PO.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (in_array($purchaseOrder->status, ['completed', 'cancelled'])) {
            return back()->with('error', 'Không thể sửa đơn đã hoàn thành hoặc đã hủy.');
        }

        $validated = $request->validate([
            'ordered_by_name' => 'nullable|string',
            'expected_date' => 'nullable|date',
            'note' => 'nullable|string',
            'supplier_deposit' => 'nullable|numeric',
        ]);

        $purchaseOrder->update(array_filter($validated, fn($v) => $v !== null));

        ActivityLog::log('po_update', "Cập nhật đặt hàng nhập {$purchaseOrder->code}", $purchaseOrder);

        return back()->with('success', 'Cập nhật thành công.');
    }

    /**
     * Cancel PO. Block if linked receipts exist.
     */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'cancelled') {
            return back()->with('error', 'Đơn đã bị hủy trước đó.');
        }

        // Block if has any linked receipts
        $linkedReceipts = Purchase::where('purchase_order_id', $purchaseOrder->id)->count();
        if ($linkedReceipts > 0) {
            return back()->with('error', 'Không thể hủy: đã có phiếu nhập hàng liên kết.');
        }

        $purchaseOrder->update([
            'status' => 'cancelled',
            'note' => ($purchaseOrder->note ? $purchaseOrder->note . ' | ' : '') . 'Hủy: ' . ($request->reason ?? ''),
        ]);

        ActivityLog::log('po_cancel', "Hủy đặt hàng nhập {$purchaseOrder->code}", $purchaseOrder);

        return back()->with('success', 'Đã hủy đơn đặt hàng nhập.');
    }

    /**
     * Finish/close PO with remaining outstanding qty.
     */
    public function finish(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (in_array($purchaseOrder->status, ['completed', 'cancelled', 'finished'])) {
            return back()->with('error', 'Đơn không ở trạng thái có thể kết thúc.');
        }

        $purchaseOrder->update([
            'status' => 'finished',
            'note' => ($purchaseOrder->note ? $purchaseOrder->note . ' | ' : '') . 'Kết thúc: ' . ($request->reason ?? ''),
        ]);

        ActivityLog::log('po_finish', "Kết thúc đặt hàng nhập {$purchaseOrder->code}", $purchaseOrder);

        return back()->with('success', 'Đã kết thúc đơn đặt hàng nhập.');
    }

    /**
     * Copy/duplicate PO as a fresh draft.
     */
    public function copy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items');

        $newPo = PurchaseOrder::create([
            'code' => 'DDH' . time() . 'C',
            'branch_id' => $purchaseOrder->branch_id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'status' => 'draft',
            'total_amount' => $purchaseOrder->total_amount,
            'discount' => $purchaseOrder->discount,
            'import_fee' => $purchaseOrder->import_fee,
            'other_import_fee' => $purchaseOrder->other_import_fee,
            'total_payment' => $purchaseOrder->total_payment,
            'supplier_deposit' => 0, // fresh — no deposit carried
            'expected_date' => null,
            'note' => 'Sao chép từ ' . $purchaseOrder->code,
            'created_by_name' => auth()->user()?->name ?? 'Admin',
            'ordered_by_name' => $purchaseOrder->ordered_by_name,
        ]);

        foreach ($purchaseOrder->items as $item) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $newPo->id,
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'received_qty' => 0,
                'price' => $item->price,
                'discount' => $item->discount,
                'total_value' => $item->total_value,
            ]);
        }

        ActivityLog::log('po_copy', "Sao chép đặt hàng nhập {$purchaseOrder->code} → {$newPo->code}", $newPo);

        return back()->with('success', "Đã sao chép thành {$newPo->code}");
    }

    /**
     * Convert PO to Purchase Receipt. Updates received_qty and PO status.
     */
    public function convertToReceipt(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (in_array($purchaseOrder->status, ['completed', 'cancelled', 'finished'])) {
            return back()->with('error', 'Đơn không ở trạng thái có thể tạo phiếu nhập.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.price' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $purchaseOrder->load('items');
            $totalAmount = 0;
            $receiptItems = [];

            foreach ($request->items as $reqItem) {
                // Find PO item
                $poItem = $purchaseOrder->items->firstWhere('product_id', $reqItem['product_id']);
                $receiveQty = $reqItem['quantity'];

                if ($receiveQty <= 0) continue;

                // Check over-receipt
                if ($poItem) {
                    $outstanding = $poItem->qty - $poItem->received_qty;
                    if ($receiveQty > $outstanding && !Setting::get('po_allow_over_receipt', false)) {
                        throw new \Exception("Sản phẩm {$reqItem['product_id']}: nhận {$receiveQty} vượt quá đặt hàng còn lại ({$outstanding}).");
                    }
                }

                $lineTotal = $receiveQty * $reqItem['price'];
                $totalAmount += $lineTotal;

                $receiptItems[] = [
                    'product_id' => $reqItem['product_id'],
                    'quantity' => $receiveQty,
                    'price' => $reqItem['price'],
                    'po_item' => $poItem,
                ];
            }

            if (empty($receiptItems)) {
                throw new \Exception('Không có sản phẩm nào được nhận.');
            }

            $paidAmount = $request->input('paid_amount', 0);
            $debtAmount = $totalAmount - $paidAmount;

            // Create Purchase (receipt)
            $purchase = Purchase::create([
                'code' => 'PN' . time() . rand(10, 99),
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'total_amount' => $totalAmount,
                'discount' => 0,
                'paid_amount' => $paidAmount,
                'debt_amount' => max(0, $debtAmount),
                'status' => 'completed',
                'purchase_date' => now(),
                'note' => 'Từ đặt hàng nhập ' . $purchaseOrder->code,
            ]);

            // Create receipt items + update stock + update PO received_qty
            foreach ($receiptItems as $ri) {
                $product = Product::lockForUpdate()->find($ri['product_id']);

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $ri['product_id'],
                    'product_name' => $product->name ?? '',
                    'product_code' => $product->sku ?? $product->code ?? '',
                    'quantity' => $ri['quantity'],
                    'price' => $ri['price'],
                    'discount' => 0,
                    'subtotal' => $ri['quantity'] * $ri['price'],
                ]);

                // Stock increase via receipt
                if ($product) {
                    $totalCurrentValue = $product->stock_quantity * $product->cost_price;
                    $totalNewValue = $ri['quantity'] * $ri['price'];
                    $newStock = $product->stock_quantity + $ri['quantity'];
                    $newCostPrice = $newStock > 0 ? ($totalCurrentValue + $totalNewValue) / $newStock : $ri['price'];

                    $product->stock_quantity = $newStock;
                    $product->cost_price = $newCostPrice;
                    $product->save();
                }

                // Update PO item received_qty
                if ($ri['po_item']) {
                    $ri['po_item']->increment('received_qty', $ri['quantity']);
                }
            }

            // Update PO status based on fulfillment
            $purchaseOrder->refresh();
            $purchaseOrder->load('items');
            $allFulfilled = $purchaseOrder->items->every(fn($item) => $item->received_qty >= $item->qty);
            $anyReceived = $purchaseOrder->items->contains(fn($item) => $item->received_qty > 0);

            if ($allFulfilled) {
                $purchaseOrder->update(['status' => 'completed']);
            } elseif ($anyReceived) {
                $purchaseOrder->update(['status' => 'partial']);
            }

            ActivityLog::log('po_convert', "Tạo phiếu nhập {$purchase->code} từ đặt hàng nhập {$purchaseOrder->code}", $purchaseOrder);

            DB::commit();

            return back()->with('success', "Đã tạo phiếu nhập {$purchase->code} từ đơn đặt hàng.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $this->configurePurchaseOrderFilters();
        $query = PurchaseOrder::with(['supplier', 'branch']);
        $this->applyFilters($query, $request);
        $orders = $query->get();

        return \App\Services\CsvService::export(
            ['Mã đặt hàng nhập', 'Thời gian', 'Nhà cung cấp', 'Chi nhánh', 'Tổng tiền', 'Giảm giá', 'Tổng thanh toán', 'Trạng thái', 'Ghi chú'],
            $orders->map(fn($o) => [$o->code, $o->created_at?->format('d/m/Y H:i'), $o->supplier?->name, $o->branch?->name, $o->total_amount, $o->discount, $o->total_payment, $o->status, $o->note]),
            'dat_hang_nhap.csv'
        );
    }

    public function print(\App\Models\PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items.product', 'branch', 'supplier']);
        return view('prints.purchase_order', compact('purchaseOrder'));
    }
}
