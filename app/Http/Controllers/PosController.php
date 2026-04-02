<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;

class PosController extends Controller
{
    public function index()
    {
        return Inertia::render('POS/Index', [
            'employees' => \App\Models\Employee::where('is_active', true)->get(['id', 'name', 'code']),
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
        ]);
    }

    public function searchProducts(Request $request)
    {
        $query = Product::where('is_active', true);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('serials', function ($sq) use ($search) {
                        $sq->where('serial_number', 'like', "%{$search}%");
                    });
            });
        }

        // Return top 20 matches for POS search
        $products = $query
            ->withCount([
                'serials as repairing_count' => function ($q) {
                    $q->where('status', 'in_stock')
                      ->whereIn('repair_status', ['not_started', 'repairing']);
                },
            ])
            ->limit(20)->get();

        // Add sellable_quantity: total stock minus repairing units
        $products->each(function ($p) {
            $p->sellable_quantity = $p->has_serial
                ? max(0, $p->stock_quantity - $p->repairing_count)
                : $p->stock_quantity;
        });

        return response()->json($products);
    }

    /**
     * Lấy danh sách serial/IMEI khả dụng cho 1 sản phẩm
     */
    public function getProductSerials(Product $product)
    {
        $serials = \App\Models\SerialImei::where('product_id', $product->id)
            ->where('status', 'in_stock')
            ->where(function ($q) {
                $q->whereNull('repair_status')
                  ->orWhereNotIn('repair_status', ['not_started', 'repairing']);
            })
            ->orderBy('serial_number')
            ->get(['id', 'serial_number', 'status', 'cost_price']);

        return response()->json($serials);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'subtotal' => 'required|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'customer_paid' => 'required|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,id',
            'employee_id' => 'nullable|exists:employees,id',
            'sale_time' => 'nullable|date',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.serial_ids' => 'nullable|array',
        ]);

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            $customer = $validated['customer_id'] ? \App\Models\Customer::find($validated['customer_id']) : null;
            $employee = !empty($validated['employee_id']) ? \App\Models\Employee::find($validated['employee_id']) : null;

            $invoice = \App\Models\Invoice::create([
                'code' => 'HD' . time() . rand(10, 99),
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'],
                'total' => $validated['total'],
                'customer_paid' => $validated['customer_paid'],
                'customer_id' => $customer?->id,
                'created_by' => $employee?->id,
                'seller_name' => $employee?->name,
                'sale_time' => $validated['sale_time'] ?? now(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'note' => ($validated['payment_method'] ?? 'cash') === 'transfer' && !empty($validated['bank_account_info']) ? 'Chuyển khoản: ' . $validated['bank_account_info'] : null,
            ]);

            // Cho phép chọn ngày bán (kế toán nhập sau)
            if (!empty($validated['sale_time'])) {
                $invoice->update(['created_at' => \Carbon\Carbon::parse($validated['sale_time'])]);
            }

            foreach ($validated['items'] as $item) {
                $serialIds = $item['serial_ids'] ?? [];

                // Deduct stock & resolve product
                $product = Product::lockForUpdate()->find($item['product_id']);
                if ($product) {
                    $allowOversell = \App\Models\Setting::get('inventory_allow_oversell', true);
                    if (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }
                    $product->stock_quantity -= $item['quantity'];
                    $product->save();
                }

                // --- Snapshot cost_price at sale time ---
                // For serial products: use average cost_price of the specific sold serials
                // (These serials may have been repaired/upgraded, so cost differs from product average)
                $snapshotCostPrice = 0;
                if (!empty($serialIds) && $product && $product->has_serial) {
                    $soldSerials = \App\Models\SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->get(['id', 'serial_number', 'cost_price']);

                    // Average cost_price of selected serials (fallback to product.cost_price if 0)
                    $totalCost = $soldSerials->sum(fn($s) => (float) ($s->cost_price ?: $product->cost_price ?? 0));
                    $snapshotCostPrice = $soldSerials->count() > 0
                        ? round($totalCost / $soldSerials->count(), 2)
                        : ($product->cost_price ?? 0);

                    // Mark serials as sold
                    \App\Models\SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->update([
                            'status' => 'sold',
                            'sold_at' => now(),
                            'invoice_id' => $invoice->id,
                        ]);

                    // Store serial numbers in invoice item for reference
                    $serialNumbers = $soldSerials->pluck('serial_number');
                    $serialStr = $serialNumbers->implode(', ');
                } else {
                    // Regular product: use product.cost_price
                    $snapshotCostPrice = (float) ($product->cost_price ?? 0);
                    $serialStr = null;
                }

                // Create Item with cost_price snapshot
                $invoiceItem = $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                    'cost_price' => $snapshotCostPrice,
                    'serial'     => $serialStr,
                ]);
            }

            // Customer debt tracking
            $customerName = $customer ? $customer->name : 'Khách lẻ';
            $debtAmount = max(0, $validated['total'] - $validated['customer_paid']);

            if ($customer && $debtAmount > 0) {
                $customer->increment('debt_amount', $debtAmount);
                $customer->increment('total_spent', $validated['total']);
            } elseif ($customer) {
                $customer->increment('total_spent', $validated['total']);
            }

            // Record into Cash Flow as a receipt ONLY if customer pays something
            if ($validated['customer_paid'] > 0) {
                \App\Models\CashFlow::create([
                    'code' => 'PT' . time() . rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $validated['customer_paid'],
                    'time' => now(),
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customerName,
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'description' => 'Thu tiền hóa đơn ' . $invoice->code . ($customer ? " - {$customer->name}" : '') . (($validated['payment_method'] ?? 'cash') === 'transfer' && !empty($validated['bank_account_info']) ? ' - CK: ' . $validated['bank_account_info'] : ''),
                ]);
            }

            // Tự động đối trừ công nợ NCC↔KH
            if ($customer) {
                \App\Services\DebtOffsetService::offsetDebts($customer);
            }

            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['success' => true, 'invoice_code' => $invoice->code, 'message' => 'Thanh toán thành công!']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('POS Checkout Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Tìm kiếm khách hàng (typeahead).
     */
    public function searchCustomers(Request $request)
    {
        $search = $request->input('search', '');
        if (strlen($search) < 1) {
            return response()->json([]);
        }

        $customers = \App\Models\Customer::where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name', 'phone', 'debt_amount']);

        return response()->json($customers);
    }

    /**
     * Tạo nhanh khách hàng từ POS.
     */
    public function quickCreateCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'birthday' => 'nullable|date',
            'gender' => 'nullable|in:none,male,female',
            'email' => 'nullable|email|max:255',
            'facebook' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'district' => 'nullable|string',
            'ward' => 'nullable|string',
            'customer_group' => 'nullable|string',
            'note' => 'nullable|string',
            'type' => 'nullable|in:individual,company',
            'invoice_name' => 'nullable|string|max:255',
            'id_card' => 'nullable|string|max:255',
            'passport' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:255',
            'invoice_address' => 'nullable|string',
            'invoice_city' => 'nullable|string',
            'invoice_district' => 'nullable|string',
            'invoice_ward' => 'nullable|string',
            'invoice_email' => 'nullable|email|max:255',
            'invoice_phone' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account' => 'nullable|string|max:255',
            'is_supplier' => 'boolean',
            'is_customer' => 'boolean',
        ]);

        if (empty($validated['code'])) {
            $validated['code'] = 'KH' . time() . rand(10, 99);
        }

        $validated['is_supplier'] = $request->input('is_supplier', false);
        $validated['is_customer'] = $request->input('is_customer', true);

        $customer = \App\Models\Customer::create($validated);

        return response()->json(['customer' => $customer]);
    }

    /**
     * Tìm kiếm nhà cung cấp (typeahead).
     */
    public function searchSuppliers(Request $request)
    {
        $search = $request->input('search', '');
        if (strlen($search) < 1) {
            return response()->json([]);
        }

        $suppliers = \App\Models\Supplier::where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'code', 'name', 'phone', 'debt_amount']);

        return response()->json($suppliers);
    }
}

