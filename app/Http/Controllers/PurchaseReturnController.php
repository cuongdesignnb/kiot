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
use App\Enums\PaymentMethod;
use App\Enums\PurchaseReturnStatus;
use App\Services\StockMovementService;
use App\Support\Filters\FilterableIndex;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\DebtOffsetService;

class PurchaseReturnController extends Controller
{
    use FilterableIndex;

    private function activeReturnEmployees()
    {
        return Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'user_id']);
    }

    private function currentReturnerOption(): ?array
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $employee = Employee::where('user_id', $user->id)
            ->where('is_active', true)
            ->first(['id', 'name', 'code']);

        return [
            'value' => $employee ? (string) $employee->id : 'current_user',
            'employee_id' => $employee?->id,
            'name' => $employee?->name ?: $user->name,
            'code' => $employee?->code,
            'is_current_user' => true,
        ];
    }

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
                'paymentMethods' => PaymentMethod::basicOptions(),
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
            'employees' => $this->activeReturnEmployees(),
            'currentReturner' => $this->currentReturnerOption(),
        ]);
    }

    /**
     * Màn hình tạo phiếu trả nhanh (không cần phiếu nhập gốc).
     */
    public function createQuick()
    {
        return Inertia::render('PurchaseReturns/CreateQuick', [
            'returnCode'   => 'PTN' . date('YmdHis'),
            'suppliers'    => Customer::where('is_supplier', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'phone', 'supplier_debt_amount']),
            'products'     => Product::select('id', 'name', 'sku', 'stock_quantity', 'cost_price', 'has_serial')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
            'employees'    => $this->activeReturnEmployees(),
            'currentReturner' => $this->currentReturnerOption(),
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

        // ── Step 23.3: Validate serial cho hàng has_serial khi trả NCC ──
        // BUG-3: serial phải thuộc đúng purchase_id (không cho cross-purchase).
        // BUG-4: count(serial_ids) === quantity bắt buộc cho hàng has_serial.
        // BUG-5: chống trùng serial_id trong cùng request.
        // BUG-6: serial phải đang status=in_stock và chưa thuộc return khác.
        $seenSerialIds = [];
        foreach ($request->items as $i => $item) {
            $product = Product::find($item['product_id']);
            if (!$product || !$product->has_serial) continue;

            $qty = (int) $item['quantity'];
            $serialIds = array_values(array_filter(array_map('intval', (array) ($item['serial_ids'] ?? []))));

            if (count($serialIds) !== $qty) {
                return back()->withErrors(["items.{$i}.serial_ids" => "Sản phẩm \"{$product->name}\" cần chọn đủ {$qty} serial (đang chọn " . count($serialIds) . ")."]);
            }
            foreach ($serialIds as $sid) {
                if (isset($seenSerialIds[$sid])) {
                    return back()->withErrors(["items.{$i}.serial_ids" => "Serial ID {$sid} bị chọn trùng nhiều dòng."]);
                }
                $seenSerialIds[$sid] = true;
            }
            $validCount = SerialImei::whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->where('purchase_id', $purchase->id)
                ->where('status', 'in_stock')
                ->count();
            if ($validCount !== count($serialIds)) {
                return back()->withErrors(["items.{$i}.serial_ids" => "Sản phẩm \"{$product->name}\" có serial không hợp lệ: phải thuộc phiếu nhập này, đang còn trong kho và chưa bán/chưa trả."]);
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

                // Tham chiếu purchase_item gốc để lấy unit_cost_allocated
                $purchaseItem = $purchase->items->firstWhere('product_id', $item['product_id']);
                $unitCostAtPurchase = $purchaseItem
                    ? (float) ($purchaseItem->unit_cost_allocated ?: $purchaseItem->price)
                    : (float) $item['price'];

                $return->items()->create([
                    'product_id' => $product->id,
                    'purchase_item_id' => $purchaseItem?->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['quantity'] * $item['price'],
                    'cost_price' => $unitCostAtPurchase,
                ]);

                // Check stock available
                $currentStock = $product->stock_quantity;
                if ($currentStock < $item['quantity']) {
                    throw new \Exception("Sản phẩm '{$product->name}' không đủ tồn kho để trả hàng nhập (Còn: {$currentStock}, Cần trả: {$item['quantity']}).");
                }

                // BQ DI ĐỘNG: rút khỏi tồn theo cost lúc nhập
                \App\Services\MovingAvgCostingService::applyPurchaseReturn(
                    $product,
                    (int) $item['quantity'],
                    (float) $unitCostAtPurchase
                );
                $product->refresh();

                // Update serial/IMEI status
                if ($product->has_serial && !empty($item['serial_ids'])) {
                    SerialImei::whereIn('id', $item['serial_ids'])
                        ->where('product_id', $product->id)
                        ->where('purchase_id', $purchase->id)
                        ->where('status', 'in_stock')
                        ->update(['status' => 'returned', 'purchase_return_id' => $return->id]);

                    $product->recomputeFromSerials();
                }

                // Phase 4 — Ghi sổ cái: trả hàng NCC = xuất kho
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_OUT_PURCHASE_RETURN,
                    (int) $item['quantity'],
                    (float) $unitCostAtPurchase,
                    $return,
                    [
                        'branch_id' => $return->branch_id ?? null,
                        'ref_code' => $return->code,
                        'moved_at' => $return->return_date ?? now(),
                        'note' => 'Trả hàng NCC phiếu ' . $return->code,
                    ]
                );
            }

            // Giảm công nợ NCC theo chuẩn KiotViet:
            //   Tính vào công nợ = NCC cần trả (totalAmount) - Tiền NCC trả thực tế (refundAmount)
            // Phần "ghi nợ" này làm giảm khoản mình đang nợ NCC (supplier_debt_amount).
            if ($purchase->supplier) {
                $debtReduction = $totalAmount - $refundAmount;
                $purchase->supplier->supplier_debt_amount -= $debtReduction;
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

            // Step 24.0: audit log purchase return create
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_PURCHASE_RETURN_CREATE,
                "Tạo phiếu trả hàng nhập {$return->code}",
                $return,
                ['total' => (float) ($return->total ?? 0)]
            );

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
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $productIds = collect($request->items)->pluck('product_id')->unique()->values();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($request->items as $i => $item) {
                $product = $products->get($item['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        "items.{$i}.product_id" => 'Sản phẩm trả nhanh không hợp lệ.',
                    ]);
                }

                if ($product->has_serial) {
                    throw ValidationException::withMessages([
                        "items.{$i}.product_id" => "Sản phẩm \"{$product->name}\" có Serial/IMEI. Vui lòng trả theo phiếu nhập để chọn đúng serial.",
                    ]);
                }

                if ((int) $product->stock_quantity < (int) $item['quantity']) {
                    throw ValidationException::withMessages([
                        "items.{$i}.quantity" => "Sản phẩm \"{$product->name}\" không đủ tồn kho để trả hàng nhập (Còn: {$product->stock_quantity}, Cần trả: {$item['quantity']}).",
                    ]);
                }
            }

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
                'bank_account_info' => $request->bank_account_info,
                'return_date' => now(),
            ]);

            foreach ($request->items as $item) {
                $product = $products->get($item['product_id']);

                // Trả nhanh: không có purchase_id → fallback dùng product.cost_price hiện tại làm cost out
                $unitCostAtPurchase = (float) $product->cost_price;

                $return->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $item['quantity'] * $item['price'],
                    'cost_price' => $unitCostAtPurchase,
                ]);

                // Reduce stock
                // BQ DI ĐỘNG: rút khỏi tồn ở giá trả nhanh
                \App\Services\MovingAvgCostingService::applyPurchaseReturn(
                    $product,
                    (int) $item['quantity'],
                    (float) $unitCostAtPurchase
                );
                $product->refresh();

                if ($product->has_serial) {
                    $product->recomputeFromSerials();
                }

                // Phase 4 — Ghi sổ cái: trả hàng NCC nhanh
                StockMovementService::record(
                    $product->fresh(),
                    StockMovementService::TYPE_OUT_PURCHASE_RETURN,
                    (int) $item['quantity'],
                    (float) $unitCostAtPurchase,
                    $return,
                    [
                        'ref_code' => $return->code,
                        'moved_at' => now(),
                        'note' => 'Trả hàng NCC nhanh ' . $return->code,
                    ]
                );
            }

            // Giảm công nợ NCC: ghi nợ phần chưa refund.
            $debtReduction = $totalAmount - $refundAmount;
            $supplier->decrement('supplier_debt_amount', $debtReduction);
            $supplier->decrement('total_bought', $totalAmount);

            // CashFlow if refund > 0 (đồng bộ category có dấu để báo cáo gom nhóm đúng)
            if ($refundAmount > 0) {
                CashFlow::create([
                    'code' => 'PT' . date('YmdHis'),
                    'type' => 'receipt',
                    'amount' => $refundAmount,
                    'time' => now(),
                    'category' => 'Thu tiền NCC trả hàng',
                    'target_type' => 'Nhà cung cấp',
                    'target_name' => $supplier->name,
                    'reference_type' => 'PurchaseReturn',
                    'reference_code' => $return->code,
                    'description' => 'NCC hoàn tiền trả hàng nhập ' . $return->code,
                ]);
            }

            DB::commit();

            // Inertia-friendly redirect; fallback JSON cho API caller.
            if ($request->wantsJson() && !$request->header('X-Inertia')) {
                return response()->json(['success' => true, 'return' => $return]);
            }
            return redirect()->route('purchase-returns.index')
                ->with('success', 'Tạo phiếu trả hàng nhập (trả nhanh) thành công!');
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            if ($request->wantsJson() && !$request->header('X-Inertia')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            return back()->with('error', 'Có lỗi: ' . $e->getMessage());
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

            // Reverse: add stock back
            foreach ($purchaseReturn->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                // Khôi phục cost: dùng cost_price lúc xuất (= unit_cost_allocated lúc nhập)
                $unitCost = (float) ($item->cost_price ?: $item->price);

                // BQ DI ĐỘNG: phục hồi tồn ở cost lúc trả (đảo ngược applyPurchaseReturn)
                \App\Services\MovingAvgCostingService::applySaleReturn(
                    $product,
                    (int) $item->quantity,
                    $unitCost
                );
                $product->refresh();

                // Restore serial status
                if ($product->has_serial) {
                    SerialImei::where('purchase_return_id', $purchaseReturn->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'returned')
                        ->update(['status' => 'in_stock', 'purchase_return_id' => null]);

                    $product->recomputeFromSerials();
                }
            }

            // Đảo công nợ NCC: khôi phục phần đã ghi nợ = total_amount - refund_amount.
            if ($purchaseReturn->supplier) {
                $debtReduction = $purchaseReturn->total_amount - $purchaseReturn->refund_amount;
                $purchaseReturn->supplier->supplier_debt_amount += $debtReduction;
                $purchaseReturn->supplier->total_bought += $purchaseReturn->total_amount;
                $purchaseReturn->supplier->save();
            }

            // Cancel cash flows
            CashFlow::where('reference_type', 'PurchaseReturn')
                ->where('reference_code', $purchaseReturn->code)
                ->update(['status' => 'cancelled']);
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

            // Step 24.0: audit log purchase return cancel
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_PURCHASE_RETURN_CANCEL,
                "Hủy phiếu trả hàng nhập {$purchaseReturn->code}",
                $purchaseReturn,
                ['total' => (float) ($purchaseReturn->total ?? 0)]
            );

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
