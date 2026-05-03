<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Services\InvoiceSaleService;

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
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.serial_ids' => 'nullable|array',
        ]);

        try {
            $employee = !empty($validated['employee_id']) ? \App\Models\Employee::find($validated['employee_id']) : null;
            $paymentMethod = $validated['payment_method'] ?? 'cash';
            $isTransfer = $paymentMethod === 'transfer';
            $bankInfo = $validated['bank_account_info'] ?? null;

            // RR-02: build normalized payload + context, gọi InvoiceSaleService
            $payload = [
                'customer_id'    => $validated['customer_id'] ?? null,
                'branch_id'      => null, // POS legacy: không set branch
                'subtotal'       => $validated['subtotal'],
                'discount'       => $validated['discount'],
                'total'          => $validated['total'],
                'customer_paid'  => $validated['customer_paid'],
                'payment_method' => $paymentMethod,
                'note'           => $isTransfer && !empty($bankInfo) ? 'Chuyển khoản: ' . $bankInfo : null,
                'items'          => array_map(function ($it) {
                    return [
                        'product_id' => $it['product_id'],
                        'quantity'   => $it['quantity'],
                        'price'      => $it['price'],
                        'discount'   => $it['discount'] ?? 0,
                        'serial_ids' => $it['serial_ids'] ?? [],
                    ];
                }, $validated['items']),
            ];

            $context = [
                'source'                         => 'pos',
                'code_prefix'                    => 'HD' . time(),
                'default_status'                 => 'Hoàn thành',
                'sales_channel'                  => 'Bán trực tiếp',
                'seller_id'                      => $employee?->id,
                'seller_name'                    => $employee?->name,
                'created_by_name'                => auth()->user()?->name ?? 'POS',
                'transaction_date'               => $validated['sale_time'] ?? null,
                'validate_before_purchase_date'  => false,
                'validate_stock_setting'         => false,
                'allow_oversell'                 => \App\Models\Setting::get('inventory_allow_oversell', true),
                'cashflow_payment_method'        => $paymentMethod,
                'cashflow_description_extra'     => $isTransfer && !empty($bankInfo)
                    ? ' - CK: ' . $bankInfo
                    : '',
                'stock_movement_branch_id'       => null,
            ];

            $invoice = app(InvoiceSaleService::class)->createSale($payload, $context);

            return response()->json([
                'success'      => true,
                'invoice_code' => $invoice->code,
                'message'      => 'Thanh toán thành công!',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('POS Checkout Error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Có lỗi xảy ra: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Đặt nhanh — Tạo Order (Phiếu tạm) từ POS.
     * KHÔNG trừ kho, KHÔNG tính công nợ.
     */
    public function quickOrder(Request $request)
    {
        $validated = $request->validate([
            'subtotal' => 'required|numeric',
            'discount' => 'numeric',
            'total' => 'required|numeric',
            'customer_id' => 'nullable|exists:customers,id',
            'employee_id' => 'nullable|exists:employees,id',
            'sale_time' => 'nullable',
            'note' => 'nullable|string',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
        ]);

        try {
            $customer = $validated['customer_id'] ? \App\Models\Customer::find($validated['customer_id']) : null;
            $employee = !empty($validated['employee_id']) ? \App\Models\Employee::find($validated['employee_id']) : null;

            $order = \App\Models\Order::create([
                'code' => 'DH' . time() . rand(10, 99),
                'customer_id' => $customer?->id,
                'branch_id' => null,
                'created_by_name' => $employee?->name ?? auth()->user()?->name ?? 'Admin',
                'assigned_to_name' => $employee?->name ?? auth()->user()?->name ?? 'Admin',
                'sales_channel' => 'Bán trực tiếp',
                'price_book_name' => 'Bảng giá chung',
                'status' => 'draft',
                'total_price' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'other_fees' => 0,
                'total_payment' => $validated['total'],
                'amount_paid' => 0,
                'note' => $validated['note'] ?? null,
            ]);

            if (!empty($validated['sale_time'])) {
                $order->update(['created_at' => \Carbon\Carbon::parse($validated['sale_time'])]);
            }

            foreach ($validated['items'] as $item) {
                $subtotal = ($item['quantity'] * $item['price']);
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'qty'        => $item['quantity'],
                    'price'      => $item['price'],
                    'discount'   => 0,
                    'subtotal'   => $subtotal,
                ]);
            }

            return response()->json([
                'success' => true,
                'order_code' => $order->code,
                'message' => 'Đặt hàng thành công! Mã: ' . $order->code,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('POS Quick Order Error', [
                'message' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Có lỗi: ' . $e->getMessage()], 500);
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
            'phone' => 'nullable|string|max:255|unique:customers,phone',
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

