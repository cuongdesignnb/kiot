<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Services\InvoiceSaleService;
use App\Services\PosReturnExchangeService;
use App\Services\ProductSearchService;
use App\Services\SerialAvailabilityService;
use App\Support\Reports\SellerResolver;

class PosController extends Controller
{
    public function index()
    {
        // HOTFIX 24.33 — POS seller dropdown now reuses SellerResolver so a
        // super-admin user without a linked Employee can be the seller as
        // virtual admin_user:<id>. The legacy `employees` prop is kept for
        // backward compatibility with anything still reading it.
        $sellerOptions = app(SellerResolver::class)->buildInvoiceSellerOptions();

        return Inertia::render('POS/Index', [
            'employees' => \App\Models\Employee::where('is_active', true)->get(['id', 'name', 'code']),
            'sellerOptions' => $sellerOptions,
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
        ]);
    }

    /**
     * HOTFIX 24.33 — Resolve a POS seller_key payload field into
     * concrete (seller_id, seller_name) values for InvoiceSaleService.
     *
     * Returns: [int|null seller_id, string|null seller_name]
     *
     * Throws \Exception with HTTP-friendly message if the key is malformed,
     * the referenced user is not actually admin, or the employee is missing.
     */
    private function resolveSellerForPos(?string $sellerKey, ?int $legacyEmployeeId): array
    {
        // Prefer explicit seller_key; fall back to legacy employee_id.
        if (!$sellerKey && $legacyEmployeeId) {
            $sellerKey = 'employee:' . $legacyEmployeeId;
        }
        if (!$sellerKey) {
            return [null, null];
        }

        if (preg_match('/^admin_user:(\d+)$/', $sellerKey, $m)) {
            $userId = (int) $m[1];
            $user = \App\Models\User::find($userId);
            if (!$user
                || ($user->status ?? 'active') !== 'active'
                || !$user->isAdmin()) {
                throw new \InvalidArgumentException(
                    'admin_user không hợp lệ. Chỉ chấp nhận tài khoản quản trị hệ thống đang hoạt động.'
                );
            }
            // If admin already has an active linked Employee, force the
            // canonical employee:<id> key so reports stay grouped on it.
            $linked = \App\Models\Employee::where('user_id', $userId)
                ->where('is_active', true)->first();
            if ($linked) {
                throw new \InvalidArgumentException(
                    'Tài khoản admin này đã có nhân viên active. Hãy chọn employee:' . $linked->id . '.'
                );
            }
            return [null, $user->name];
        }

        if (preg_match('/^employee:(\d+)$/', $sellerKey, $m)) {
            $empId = (int) $m[1];
            $emp = \App\Models\Employee::where('id', $empId)
                ->where('is_active', true)->first();
            if (!$emp) {
                throw new \InvalidArgumentException(
                    'Nhân viên không tồn tại hoặc đã ngưng hoạt động.'
                );
            }
            return [$emp->id, $emp->name];
        }

        throw new \InvalidArgumentException(
            'seller_key không hợp lệ. Chỉ chấp nhận employee:<id> hoặc admin_user:<id>.'
        );
    }

    public function searchProducts(Request $request, ProductSearchService $productSearch)
    {
        $query = Product::where('is_active', true);
        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $productSearch->apply($query, $search, [
                'include_serials' => true,
                'serial_relation' => 'serials',
            ]);
            $productSearch->applyScore($query, $search);
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
        $availability = app(SerialAvailabilityService::class);
        $serialLike = $productSearch->serialLikePattern($search);
        $products->each(function ($p) use ($search, $serialLike, $availability) {
            $p->sellable_quantity = $p->has_serial
                ? max(0, $p->stock_quantity - $p->repairing_count)
                : $p->stock_quantity;

            $p->matched_serials = [];
            if ($p->has_serial && $search !== '' && $serialLike !== null) {
                $p->matched_serials = $availability->querySellableForProduct($p->id)
                    ->where('serial_number', 'like', $serialLike)
                    ->orderBy('serial_number')
                    ->limit(5)
                    ->get()
                    ->map(fn ($s) => $availability->normalizeForResponse($s))
                    ->values();
            }
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
            'seller_key' => 'nullable|string',
            'sale_time' => 'nullable|date',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
            'note' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.serial_ids' => 'nullable|array',
        ]);

        try {
            // HOTFIX 24.33 — resolve seller via seller_key (admin_user:<id> or employee:<id>),
            // falling back to legacy employee_id payload.
            try {
                [$sellerId, $sellerName] = $this->resolveSellerForPos(
                    $validated['seller_key'] ?? null,
                    !empty($validated['employee_id']) ? (int) $validated['employee_id'] : null
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
            $paymentMethod = $validated['payment_method'] ?? 'cash';
            $isTransfer = $paymentMethod === 'transfer';
            $bankInfo = $validated['bank_account_info'] ?? null;

            // 24.6C: combine user note + bank transfer info — never overwrite user note.
            $userNote = trim((string) ($validated['note'] ?? ''));
            $bankNote = $isTransfer && !empty($bankInfo) ? 'Chuyển khoản: ' . $bankInfo : '';
            $noteParts = array_values(array_filter([$userNote, $bankNote], fn($v) => $v !== ''));
            $finalNote = count($noteParts) ? implode("\n", $noteParts) : null;

            // RR-02: build normalized payload + context, gọi InvoiceSaleService
            $payload = [
                'customer_id'    => $validated['customer_id'] ?? null,
                'branch_id'      => null, // POS legacy: không set branch
                'subtotal'       => $validated['subtotal'],
                'discount'       => $validated['discount'],
                'total'          => $validated['total'],
                'customer_paid'  => $validated['customer_paid'],
                'payment_method' => $paymentMethod,
                'note'           => $finalNote,
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
                'seller_id'                      => $sellerId,
                'seller_name'                    => $sellerName,
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

    public function returnExchange(Request $request, PosReturnExchangeService $service)
    {
        try {
            $validated = $request->validate([
                'invoice_id' => 'required|exists:invoices,id',
                'customer_id' => 'nullable|exists:customers,id',
                'branch_id' => 'nullable|exists:branches,id',
                'seller_key' => 'nullable|string',
                'employee_id' => 'nullable|exists:employees,id',
                'sale_time' => 'nullable|date',
                'payment_method' => 'nullable|string|in:cash,transfer',
                'bank_account_info' => 'nullable|string',
                'note' => 'nullable|string|max:1000',
                'return' => 'required|array',
                'return.discount' => 'nullable|numeric|min:0',
                'return.fee_type' => 'nullable|in:amount,percent',
                'return.fee_value' => 'nullable|numeric|min:0',
                'return.paid_to_customer' => 'nullable|numeric|min:0',
                'return.items' => 'required|array|min:1',
                'return.items.*.product_id' => 'required|exists:products,id',
                'return.items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
                'return.items.*.qty' => 'required|integer|min:1',
                'return.items.*.price' => 'required|numeric|min:0',
                'return.items.*.discount' => 'nullable|numeric|min:0',
                'return.items.*.serial_ids' => 'nullable|array',
                'return.items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
                'exchange' => 'required|array',
                'exchange.discount' => 'nullable|numeric|min:0',
                'exchange.customer_paid' => 'nullable|numeric|min:0',
                'exchange.items' => 'required|array|min:1',
                'exchange.items.*.product_id' => 'required|exists:products,id',
                'exchange.items.*.quantity' => 'required|integer|min:1',
                'exchange.items.*.price' => 'required|numeric|min:0',
                'exchange.items.*.discount' => 'nullable|numeric|min:0',
                'exchange.items.*.serial_ids' => 'nullable|array',
                'exchange.items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
            ]);

            try {
                [$sellerId, $sellerName] = $this->resolveSellerForPos(
                    $validated['seller_key'] ?? null,
                    !empty($validated['employee_id']) ? (int) $validated['employee_id'] : null
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $result = $service->create($validated, [
                'created_by_name' => auth()->user()?->name ?? 'POS',
                'sale_context' => [
                    'seller_id' => $sellerId,
                    'seller_name' => $sellerName,
                    'created_by_name' => auth()->user()?->name ?? 'POS',
                ],
            ]);

            return response()->json([
                'success' => true,
                'return' => [
                    'id' => $result['return']->id,
                    'code' => $result['return']->code,
                ],
                'exchange_invoice' => [
                    'id' => $result['exchange_invoice']->id,
                    'code' => $result['exchange_invoice']->code,
                ],
                'settlement' => $result['settlement'],
                'message' => 'Đổi hàng thành công',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'POS_RETURN_EXCHANGE_VALIDATION_FAILED',
                'message' => 'Dữ liệu đổi hàng không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            $debugId = 'pos-exchange-' . now()->format('YmdHis') . '-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 8);

            \Illuminate\Support\Facades\Log::error('POS Return Exchange Error', [
                'debug_id' => $debugId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'invoice_id' => $validated['invoice_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'return_items_count' => count(data_get($validated ?? [], 'return.items', [])),
                'exchange_items_count' => count(data_get($validated ?? [], 'exchange.items', [])),
            ]);

            return response()->json([
                'success' => false,
                'error_code' => 'POS_RETURN_EXCHANGE_FAILED',
                'debug_id' => $debugId,
                'message' => app()->environment('production')
                    ? "Không tạo được phiếu đổi hàng. Mã lỗi: {$debugId}"
                    : 'Không tạo được phiếu đổi hàng: ' . $e->getMessage(),
            ], 500);
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
            'seller_key'  => 'nullable|string',
            'sale_time' => 'nullable',
            'note' => 'nullable|string|max:1000',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
        ]);

        try {
            $customer = $validated['customer_id'] ? \App\Models\Customer::find($validated['customer_id']) : null;

            // HOTFIX 24.33 — resolve seller_key (admin_user:<id> or employee:<id>),
            // falling back to legacy employee_id. assigned_to_name holds the seller
            // name snapshot; created_by_name stays the auth user (creator), never
            // the seller.
            try {
                [, $sellerName] = $this->resolveSellerForPos(
                    $validated['seller_key'] ?? null,
                    !empty($validated['employee_id']) ? (int) $validated['employee_id'] : null
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $order = \App\Models\Order::create([
                'code' => 'DH' . time() . rand(10, 99),
                'customer_id' => $customer?->id,
                'branch_id' => null,
                'created_by_name' => auth()->user()?->name ?? 'Admin',
                'assigned_to_name' => $sellerName ?? auth()->user()?->name ?? 'Admin',
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
    public function returnableInvoices(Request $request, ProductSearchService $productSearch)
    {
        $search = trim((string) $request->input('search', ''));

        $query = Invoice::query()
            ->with('customer:id,name,phone,code')
            ->where('status', '!=', 'Đã hủy');

        if ($search !== '') {
            $query->where(function ($q) use ($search, $productSearch) {
                $q->where('code', 'LIKE', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('phone', 'LIKE', "%{$search}%")
                         ->orWhere('code', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('items.product', function ($pq) use ($search, $productSearch) {
                      $productSearch->apply($pq, $search, ['include_serials' => false]);
                  })
                  ->orWhereHas('items.serials', function ($sq) use ($search) {
                      $sq->where('serial_number', 'LIKE', "%{$search}%")
                         ->orWhereHas('serial', function ($ssq) use ($search) {
                             $ssq->where('serial_number', 'LIKE', "%{$search}%");
                         });
                  })
                  ->orWhereExists(function ($sq) use ($search) {
                      $sq->selectRaw('1')
                         ->from('serial_imeis')
                         ->whereColumn('serial_imeis.invoice_id', 'invoices.id')
                         ->where('serial_imeis.serial_number', 'LIKE', "%{$search}%");
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
                $linkedSerials = InvoiceItemSerial::with('serial')
                    ->where('invoice_item_id', $line->id)
                    ->get()
                    ->map(function (InvoiceItemSerial $link) use ($line, $returnedSerialIds) {
                        $serial = $link->serial;
                        if (!$serial || (int) $serial->product_id !== (int) $line->product_id) {
                            return null;
                        }
                        if ($serial->status !== 'sold' && !isset($returnedSerialIds[$serial->id])) {
                            return null;
                        }

                        return [
                            'id'                => $serial->id,
                            'serial_number'     => $serial->serial_number ?: $link->serial_number,
                            'status'            => $serial->status,
                            'already_returned'  => isset($returnedSerialIds[$serial->id]),
                        ];
                    })
                    ->filter()
                    ->values();

                $fallbackSerials = SerialImei::where('invoice_id', $invoice->id)
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

                $serials = $linkedSerials
                    ->concat($fallbackSerials)
                    ->unique('id')
                    ->sortBy('serial_number')
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
