<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Models\Employee;
use App\Models\User;
use App\Enums\PurchaseReturnStatus;
use App\Support\Filters\FilterableIndex;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Services\DebtOffsetService;

class PurchaseReturnController extends Controller
{
    use FilterableIndex;

    protected function configurePurchaseReturnFilters(): void
    {
        $this->searchable = ['code', 'note'];
        $this->searchableRelations = [
            'supplier' => ['name', 'code', 'phone'],
            'purchase' => ['code'],
            'items'    => ['product_name', 'product_code'],
        ];
        $this->sortable = ['code', 'created_at', 'return_date', 'total_amount', 'refund_amount', 'status'];
        $this->dateColumn = 'return_date';
        $this->creatorColumn = 'user_id';
        $this->scalarFilters = ['supplier_id', 'employee_id', 'purchase_id', 'payment_method'];
    }

    public function index(Request $request)
    {
        $this->configurePurchaseReturnFilters();

        $query = PurchaseReturn::with([
            'supplier', 'purchase', 'items.product:id,has_serial', 'user', 'employee',
            'returnedSerials:id,product_id,serial_number,purchase_return_id',
        ]);

        $this->applyFilters($query, $request);

        // Summary (cloned pre-pagination, post-filter)
        $summaryQuery = (clone $query);
        $summary = [
            'total_amount'   => $summaryQuery->sum('total_amount'),
            'total_refund'   => $summaryQuery->sum('refund_amount'),
            'total_refunded' => (clone $summaryQuery)->where('status', 'completed')->sum('refund_amount'),
        ];

        $returns = $query->paginate(20)->withQueryString();

        return Inertia::render('PurchaseReturns/Index', [
            'returns' => $returns,
            'filters' => $this->currentFilters($request),
            'summary' => $summary,
            'filterOptions' => [
                'statuses' => PurchaseReturnStatus::options(),
                'creators' => User::select('id', 'name')->orderBy('name')->get(),
                'employees' => Employee::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
                'suppliers' => Customer::select('id', 'name')->where('is_supplier', true)->orderBy('name')->get(),
                'paymentMethods' => [
                    ['value' => 'cash', 'label' => 'Tiền mặt'],
                    ['value' => 'transfer', 'label' => 'Chuyển khoản'],
                ],
            ],
        ]);
    }

    public function create(Request $request)
    {
        $purchase = Purchase::with(['items.product', 'supplier'])->findOrFail($request->purchase_id);

        // Calculate already returned quantities per product
        $returnedQty = PurchaseReturnItem::whereHas('purchaseReturn', function ($q) use ($purchase) {
            $q->where('purchase_id', $purchase->id)->where('status', 'completed');
        })->selectRaw('product_id, SUM(quantity) as total_returned')
            ->groupBy('product_id')->pluck('total_returned', 'product_id');

        // Load serials for serial products
        foreach ($purchase->items as $item) {
            $item->returned_qty = $returnedQty[$item->product_id] ?? 0;
            $item->max_returnable = $item->quantity - $item->returned_qty;

            if ($item->product && $item->product->has_serial) {
                $item->serials = SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->where('status', 'in_stock')
                    ->get(['id', 'serial_number', 'status']);
            }
        }

        return Inertia::render('PurchaseReturns/Create', [
            'purchase' => $purchase,
            'returnCode' => 'PTN' . date('YmdHis'),
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
            'employees' => \App\Models\Employee::where('is_active', true)->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'purchase_id' => 'required|exists:purchases,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.serial_ids' => 'nullable|array',
            'refund_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
        ]);

        $purchase = Purchase::with('items')->findOrFail($request->purchase_id);

        // Validate returnable quantities
        $returnedQty = PurchaseReturnItem::whereHas('purchaseReturn', function ($q) use ($purchase) {
            $q->where('purchase_id', $purchase->id)->where('status', 'completed');
        })->selectRaw('product_id, SUM(quantity) as total_returned')
            ->groupBy('product_id')->pluck('total_returned', 'product_id');

        foreach ($request->items as $i => $item) {
            $purchaseItem = $purchase->items->firstWhere('product_id', $item['product_id']);
            if (!$purchaseItem) {
                return back()->withErrors(["items.{$i}" => "Sản phẩm không thuộc phiếu nhập này."]);
            }
            $alreadyReturned = $returnedQty[$item['product_id']] ?? 0;
            $maxReturnable = $purchaseItem->quantity - $alreadyReturned;
            if ($item['quantity'] > $maxReturnable) {
                $product = Product::find($item['product_id']);
                return back()->withErrors(["items.{$i}.quantity" => "Sản phẩm \"{$product->name}\" chỉ có thể trả tối đa {$maxReturnable}."]);
            }
        }

        try {
            DB::beginTransaction();

            $totalAmount = collect($request->items)->sum(fn($item) => $item['quantity'] * $item['price']);
            $refundAmount = $request->refund_amount ?? $totalAmount;

            $return = PurchaseReturn::create([
                'code' => $request->code ?? 'PTN' . time(),
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => auth()->id(),
                'employee_id' => $request->employee_id,
                'total_amount' => $totalAmount,
                'refund_amount' => $refundAmount,
                'status' => 'completed',
                'note' => $request->note,
                'payment_method' => $request->payment_method ?? 'cash',
                'bank_account_info' => $request->bank_account_info,
                'return_date' => now(),
            ]);

            $costingMethod = \App\Models\Setting::get('inventory_costing_method', 'average');

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                $return->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['quantity'] * $item['price'],
                ]);

                // Reduce stock
                $currentStock = $product->stock_quantity;
                if ($currentStock < $item['quantity']) {
                    throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để trả hàng nhập (Còn: {$currentStock}, Cần trả: {$item['quantity']}).");
                }
                $newStock = $currentStock - $item['quantity'];

                // Reverse cost price
                if ($costingMethod === 'average' && $currentStock > 0) {
                    $totalCurrentValue = $currentStock * $product->cost_price;
                    $removedValue = $item['quantity'] * $item['price'];
                    $product->cost_price = $newStock > 0
                        ? ($totalCurrentValue - $removedValue) / $newStock
                        : 0;
                }

                $product->stock_quantity = $newStock;
                $product->save();

                // Update serial/IMEI status
                if ($product->has_serial && !empty($item['serial_ids'])) {
                    SerialImei::whereIn('id', $item['serial_ids'])
                        ->where('product_id', $product->id)
                        ->where('status', 'in_stock')
                        ->update(['status' => 'returned', 'purchase_return_id' => $return->id]);
                }
            }

            // Reduce supplier debt (NCC owes us now)
            if ($purchase->supplier) {
                $purchase->supplier->supplier_debt_amount -= $refundAmount;
                $purchase->supplier->total_bought -= $totalAmount;
                $purchase->supplier->save();
            }

            // Create cash flow (Thu tiền từ NCC)
            if ($refundAmount > 0) {
                CashFlow::create([
                    'code' => 'PT' . date('YmdHis'),
                    'type' => 'receipt',
                    'amount' => $refundAmount,
                    'time' => now(),
                    'category' => 'Thu tiền NCC trả hàng',
                    'target_type' => 'Nhà cung cấp',
                    'target_name' => $purchase->supplier->name ?? 'Nhà cung cấp',
                    'reference_type' => 'PurchaseReturn',
                    'reference_code' => $return->code,
                    'description' => 'NCC hoàn tiền trả hàng nhập ' . $return->code . ' (phiếu nhập ' . $purchase->code . ')',
                ]);
            }

            // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ

            // Update purchase status based on total returned qty
            $totalPurchasedQty = $purchase->items->sum('quantity');
            $totalReturnedQty = \App\Models\PurchaseReturnItem::whereHas('purchaseReturn', function ($q) use ($purchase) {
                $q->where('purchase_id', $purchase->id)->where('status', 'completed');
            })->sum('quantity');

            if ($totalReturnedQty >= $totalPurchasedQty) {
                $purchase->status = 'returned';
            } else {
                $purchase->status = 'partial_return';
            }
            $purchase->save();

            DB::commit();

            return redirect()->route('purchase-returns.index')
                ->with('success', 'Tạo phiếu trả hàng nhập thành công! Tồn kho và công nợ đã được cập nhật.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    /**
     * Quick supplier return — khong can purchase_id (tra nhanh).
     */
    public function quickStore(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'refund_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $totalAmount = collect($request->items)->sum(fn($item) => $item['quantity'] * $item['price']);
            $refundAmount = $request->refund_amount ?? $totalAmount;
            $supplier = Customer::findOrFail($request->supplier_id);

            $return = PurchaseReturn::create([
                'code' => $request->code ?? 'PTN' . time(),
                'purchase_id' => null,
                'supplier_id' => $supplier->id,
                'user_id' => auth()->id(),
                'employee_id' => $request->employee_id,
                'total_amount' => $totalAmount,
                'refund_amount' => $refundAmount,
                'status' => 'completed',
                'note' => $request->note,
                'payment_method' => $request->payment_method ?? 'cash',
                'return_date' => now(),
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                $return->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['quantity'] * $item['price'],
                ]);

                // Reduce stock
                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception("San pham '{$product->name}' khong du ton kho (Con: {$product->stock_quantity}, Can tra: {$item['quantity']}).");
                }
                $product->decrement('stock_quantity', $item['quantity']);
            }

            // Reduce supplier debt
            $supplier->decrement('supplier_debt_amount', $refundAmount);
            $supplier->decrement('total_bought', $totalAmount);

            // CashFlow if refund > 0
            if ($refundAmount > 0) {
                CashFlow::create([
                    'code' => 'PT' . date('YmdHis'),
                    'type' => 'receipt',
                    'amount' => $refundAmount,
                    'time' => now(),
                    'category' => 'Thu tien NCC tra hang',
                    'target_type' => 'Nha cung cap',
                    'target_name' => $supplier->name,
                    'reference_type' => 'PurchaseReturn',
                    'reference_code' => $return->code,
                    'description' => 'NCC hoan tien tra hang nhap ' . $return->code,
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'return' => $return]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchase', 'items.product:id,has_serial', 'user', 'employee', 'returnedSerials:id,product_id,serial_number,purchase_return_id']);

        return Inertia::render('PurchaseReturns/Show', [
            'purchaseReturn' => $purchaseReturn,
        ]);
    }

    public function destroy(PurchaseReturn $purchaseReturn)
    {
        if ($purchaseReturn->status === 'cancelled') {
            return back()->with('error', 'Phiếu đã bị hủy trước đó.');
        }

        try {
            DB::beginTransaction();

            $costingMethod = \App\Models\Setting::get('inventory_costing_method', 'average');

            // Reverse: add stock back
            foreach ($purchaseReturn->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                $currentStock = $product->stock_quantity;
                $newStock = $currentStock + $item->quantity;

                if ($costingMethod === 'average') {
                    $totalCurrentValue = $currentStock * $product->cost_price;
                    $addedValue = $item->quantity * $item->price;
                    $product->cost_price = $newStock > 0
                        ? ($totalCurrentValue + $addedValue) / $newStock
                        : $item->price;
                }

                $product->stock_quantity = $newStock;
                $product->save();

                // Restore serial status
                if ($product->has_serial) {
                    SerialImei::where('purchase_return_id', $purchaseReturn->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'returned')
                        ->update(['status' => 'in_stock', 'purchase_return_id' => null]);
                }
            }

            // Restore supplier debt
            if ($purchaseReturn->supplier) {
                $purchaseReturn->supplier->supplier_debt_amount += $purchaseReturn->refund_amount;
                $purchaseReturn->supplier->total_bought += $purchaseReturn->total_amount;
                $purchaseReturn->supplier->save();
            }

            // Delete cash flows
            CashFlow::where('reference_type', 'PurchaseReturn')
                ->where('reference_code', $purchaseReturn->code)
                ->delete();

            $purchaseReturn->status = 'cancelled';
            $purchaseReturn->save();

            // Restore purchase status back to completed
            $purchase = Purchase::find($purchaseReturn->purchase_id);
            if ($purchase) {
                // Check if there are other active returns for this purchase
                $otherActiveReturns = PurchaseReturn::where('purchase_id', $purchase->id)
                    ->where('id', '!=', $purchaseReturn->id)
                    ->where('status', 'completed')
                    ->exists();
                if (!$otherActiveReturns) {
                    $purchase->status = 'completed';
                    $purchase->save();
                }
            }

            DB::commit();
            return redirect()->route('purchase-returns.index')
                ->with('success', 'Đã hủy phiếu trả hàng. Tồn kho và công nợ đã được hoàn lại.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $this->configurePurchaseReturnFilters();

        $query = PurchaseReturn::with(['supplier', 'purchase', 'user']);
        $this->applyFilters($query, $request);
        $returns = $query->get();

        return \App\Services\CsvService::export(
            ['Mã phiếu trả', 'Mã phiếu nhập', 'Nhà cung cấp', 'Ngày trả', 'Tổng tiền hàng trả', 'Tiền hoàn lại', 'Trạng thái', 'Người tạo', 'Ghi chú'],
            $returns->map(fn($r) => [
                $r->code,
                $r->purchase?->code,
                $r->supplier?->name,
                $r->return_date,
                $r->total_amount,
                $r->refund_amount,
                $r->status === 'completed' ? 'Đã trả hàng' : ($r->status === 'cancelled' ? 'Đã hủy' : $r->status),
                $r->user?->name,
                $r->note,
            ]),
            'tra_hang_nhap.csv'
        );
    }
}
