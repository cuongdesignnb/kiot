<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Services\InvoiceSaleService;
use App\Services\SerialAvailabilityService;

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
     * Lấy danh sách serial/IMEI khả dụng cho 1 sản phẩm.
     * Step 22.2A: dùng SerialAvailabilityService — schema-tolerant + legacy-tolerant.
     */
    public function getProductSerials(Product $product, SerialAvailabilityService $availability)
    {
        $serials = $availability->querySellableForProduct($product->id)
            ->orderBy('serial_number')
            ->get();

        return response()->json(
            $serials->map(fn($s) => $availability->normalizeForResponse($s))->values()
        );
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

    // ════════════════════════════════════════════════════════════════════
    //  STEP 24.6 — POS Quick Return support endpoints (read-only).
    //
    //  These two endpoints exist solely to populate the "Trả hàng nhanh" modal
    //  on the POS screen.  They never mutate data.  The actual return creation
    //  goes through OrderReturnController@store via POST /returns, which keeps
    //  every existing rule (RR-08 serial rollback, RR-11 over-return guard,
    //  Step 23.2 serial-belongs-to-invoice, time-limit settings, debt/cashflow,
    //  MovingAvgCostingService, StockMovementService).
    // ════════════════════════════════════════════════════════════════════

    /**
     * Search invoices that are eligible for a return.
     *
     * Filters: matches by invoice code, customer name/phone/code, or by serial number
     * sold under the invoice. Excludes invoices already cancelled.
     *
     * Permission: returns.create (gated at the route level).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function returnableInvoices(Request $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = Invoice::query()
            ->with('customer:id,name,phone,code')
            ->where('status', '!=', 'Đã hủy');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('phone', 'LIKE', "%{$search}%")
                         ->orWhere('code', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('items.serials', function ($sq) use ($search) {
                      $sq->where('serial_number', 'LIKE', "%{$search}%");
                  });
            });
        }

        $invoices = $query->orderByDesc('id')->limit(20)->get();

        return response()->json(
            $invoices->map(function (Invoice $inv) {
                return [
                    'id'               => $inv->id,
                    'code'             => $inv->code,
                    'status'           => $inv->status,
                    'total'            => (float) $inv->total,
                    'customer_paid'    => (float) ($inv->customer_paid ?? 0),
                    'transaction_date' => optional($inv->transaction_date ?? $inv->created_at)->toIso8601String(),
                    'created_at'       => optional($inv->created_at)->toIso8601String(),
                    'customer_id'      => $inv->customer_id,
                    'customer_name'    => $inv->customer?->name,
                    'customer_phone'   => $inv->customer?->phone,
                    'branch_id'        => $inv->branch_id,
                ];
            })->values()
        );
    }

    /**
     * Return per-line returnable info for a given invoice — sold qty,
     * already-returned qty, remaining qty, plus the serial list still
     * eligible for return (if it's a serial product).
     *
     * Mirrors the same remaining_qty formula used by OrderReturnController@store
     * (RR-11): only count ReturnItem rows whose parent OrderReturn is not
     * already cancelled.
     *
     * Permission: returns.create.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function returnableItems(Invoice $invoice)
    {
        if ($invoice->status === 'Đã hủy') {
            return response()->json([
                'message' => 'Hóa đơn đã bị hủy, không thể trả hàng.',
            ], 422);
        }

        $invoice->loadMissing(['customer:id,name,phone,code', 'items.product:id,sku,name,has_serial']);

        // Aggregate already-returned qty per product (only non-cancelled returns).
        $returnedByProduct = ReturnItem::query()
            ->whereHas('orderReturn', function ($q) use ($invoice) {
                $q->where('invoice_id', $invoice->id)->where('status', '!=', 'Đã hủy');
            })
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id')
            ->toArray();

        // Already-used serial ids (from non-cancelled returns).
        $returnedSerialIds = [];
        foreach (
            ReturnItem::query()
                ->whereHas('orderReturn', function ($q) use ($invoice) {
                    $q->where('invoice_id', $invoice->id)->where('status', '!=', 'Đã hủy');
                })
                ->pluck('serial_ids')
            as $row
        ) {
            $arr = is_array($row) ? $row : (json_decode($row ?? '[]', true) ?: []);
            foreach ($arr as $sid) {
                $returnedSerialIds[(int) $sid] = true;
            }
        }

        $items = $invoice->items->map(function (InvoiceItem $line) use ($returnedByProduct, $returnedSerialIds, $invoice) {
            $product = $line->product;
            $hasSerial = (bool) ($product?->has_serial);
            $sold = (float) $line->quantity;
            $alreadyReturned = (float) ($returnedByProduct[$line->product_id] ?? 0);
            // Note: returnedByProduct is per-product across the whole invoice.
            // For UX clarity we still surface remaining at the line level by
            // showing the per-product remaining (matches what backend enforces).
            $remaining = max(0, $sold - $alreadyReturned);

            $serials = [];
            if ($hasSerial) {
                $serials = SerialImei::where('invoice_id', $invoice->id)
                    ->where('product_id', $line->product_id)
                    ->where('status', 'sold')
                    ->orderBy('serial_number')
                    ->get(['id', 'serial_number', 'status'])
                    ->map(function ($s) use ($returnedSerialIds) {
                        return [
                            'id'                => $s->id,
                            'serial_number'     => $s->serial_number,
                            'status'            => $s->status,
                            'already_returned'  => isset($returnedSerialIds[$s->id]),
                        ];
                    })
                    ->values();
            }

            return [
                'invoice_item_id'      => $line->id,
                'product_id'           => $line->product_id,
                'product_code'         => $product?->sku,
                'product_name'         => $product?->name ?: ('#' . $line->product_id),
                'has_serial'           => $hasSerial,
                'sold_qty'             => $sold,
                'already_returned_qty' => $alreadyReturned,
                'remaining_qty'        => $remaining,
                'price'                => (float) $line->price,
                'discount'             => (float) ($line->discount ?? 0),
                'serials'              => $serials,
            ];
        })->values();

        return response()->json([
            'invoice' => [
                'id'             => $invoice->id,
                'code'           => $invoice->code,
                'status'         => $invoice->status,
                'total'          => (float) $invoice->total,
                'discount'       => (float) ($invoice->discount ?? 0),
                'fee'            => (float) ($invoice->fee ?? 0),
                'customer_paid'  => (float) ($invoice->customer_paid ?? 0),
                'customer_id'    => $invoice->customer_id,
                'customer_name'  => $invoice->customer?->name,
                'customer_phone' => $invoice->customer?->phone,
                'branch_id'      => $invoice->branch_id,
            ],
            'items' => $items,
        ]);
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

