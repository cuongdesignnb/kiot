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
                    ->orWhere('barcode', 'like', "%{$search}%");
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

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'subtotal' => 'required|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'customer_paid' => 'required|numeric|min:0',
            'employee_id' => 'nullable|exists:employees,id',
            'sale_time' => 'nullable|date',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            $invoice = \App\Models\Invoice::create([
                'code' => 'HD' . time() . rand(10, 99),
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'],
                'total' => $validated['total'],
                'customer_paid' => $validated['customer_paid'],
                'employee_id' => $validated['employee_id'] ?? null,
                'sale_time' => $validated['sale_time'] ?? now(),
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'bank_account_info' => ($validated['payment_method'] ?? 'cash') === 'transfer' ? ($validated['bank_account_info'] ?? null) : null,
            ]);

            foreach ($validated['items'] as $item) {
                // Create Item
                $invoice->items()->create([
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
                    // If allowOversell is false, we already checked it won't be < 0
                    // If allowOversell is true, it can go negative.
                    $product->save();
                }
            }

            // Record into Cash Flow as a receipt
            \App\Models\CashFlow::create([
                'code' => 'PT' . time() . rand(10, 99),
                'type' => 'receipt',
                'amount' => $validated['total'], // Actually received for the invoice
                'time' => now(),
                'category' => 'Thu tiền khách trả',
                'target_type' => 'Khách hàng',
                'target_name' => 'Khách lẻ',
                'reference_type' => 'Invoice',
                'reference_code' => $invoice->code,
                'description' => 'Thu tiền hóa đơn ' . $invoice->code,
            ]);

            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['success' => true, 'invoice_code' => $invoice->code, 'message' => 'Thanh toán thành công!']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()], 500);
        }
    }
}
