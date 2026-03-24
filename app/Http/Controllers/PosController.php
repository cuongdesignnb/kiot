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
                'serialImeis as repairing_count' => function ($q) {
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
            ->get(['id', 'serial_number', 'status', 'warranty_expires_at']);

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

            $invoice = \App\Models\Invoice::create([
                'code' => 'HD' . time() . rand(10, 99),
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'],
                'total' => $validated['total'],
                'customer_paid' => $validated['customer_paid'],
                'customer_id' => $customer?->id,
                'employee_id' => $validated['employee_id'] ?? null,
                'sale_time' => $validated['sale_time'] ?? now(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'bank_account_info' => ($validated['payment_method'] ?? 'cash') === 'transfer' ? ($validated['bank_account_info'] ?? null) : null,
            ]);

            // Cho phép chọn ngày bán (kế toán nhập sau)
            if (!empty($validated['sale_time'])) {
                $invoice->update(['created_at' => \Carbon\Carbon::parse($validated['sale_time'])]);
            }

            foreach ($validated['items'] as $item) {
                $serialIds = $item['serial_ids'] ?? [];

                // Create Item
                $invoiceItem = $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ]);

                // Deduct stock
                $product = Product::lockForUpdate()->find($item['product_id']);
                if ($product) {
                    $allowOversell = \App\Models\Setting::get('inventory_allow_oversell', false);
                    if (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }

                    $product->stock_quantity -= $item['quantity'];
                    $product->save();
                }

                // Mark selected serials as sold
                if (!empty($serialIds) && $product && $product->has_serial) {
                    \App\Models\SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->update([
                            'status' => 'sold',
                            'sold_at' => now(),
                            'invoice_id' => $invoice->id,
                        ]);

                    // Store serial numbers in invoice item for reference
                    $serialNumbers = \App\Models\SerialImei::whereIn('id', $serialIds)->pluck('serial_number');
                    $invoiceItem->update(['serial' => $serialNumbers->implode(', ')]);
                }
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

            // Record into Cash Flow as a receipt
            \App\Models\CashFlow::create([
                'code' => 'PT' . time() . rand(10, 99),
                'type' => 'receipt',
                'amount' => $validated['customer_paid'] > 0 ? $validated['customer_paid'] : $validated['total'],
                'time' => now(),
                'category' => 'Thu tiền khách trả',
                'target_type' => 'Khách hàng',
                'target_id' => $customer?->id,
                'target_name' => $customerName,
                'reference_type' => 'Invoice',
                'reference_code' => $invoice->code,
                'description' => 'Thu tiền hóa đơn ' . $invoice->code . ($customer ? " - {$customer->name}" : ''),
            ]);

            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['success' => true, 'invoice_code' => $invoice->code, 'message' => 'Thanh toán thành công!']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
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
            'phone' => 'nullable|string|max:255',
        ]);

        $customer = \App\Models\Customer::create([
            'code' => 'KH' . time() . rand(10, 99),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
        ]);

        return response()->json($customer);
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

