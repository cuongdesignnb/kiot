<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\PriceBook;
use App\Models\Setting;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Models\User;
use App\Services\CustomerDebtService;
use App\Services\DebtOffsetService;
use App\Services\InvoiceSaleService;
use App\Services\InvoiceUpdateService;
use App\Services\StockMovementService;
use App\Support\Filters\FilterableIndex;
use App\Support\Reports\SellerResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    use FilterableIndex;

    /**
     * Cấu hình chuẩn cho mọi endpoint list/export của hoá đơn.
     * Giữ một chỗ duy nhất để index() và export() dùng chung.
     */
    protected function configureInvoiceFilters(): void
    {
        $this->searchable = ['code', 'note', 'tracking_code', 'seller_name', 'created_by_name'];
        $this->searchableRelations = [
            'customer'      => ['name', 'code', 'phone'],
            'items.product' => ['name', 'code', 'barcode'],
            'order'         => ['code'],
        ];
        $this->sortable = ['code', 'created_at', 'subtotal', 'discount', 'total', 'customer_paid', 'status'];
        // Step 24.3: filtering/sorting by transaction_date, fallback created_at for legacy
        $this->dateColumn = \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'transaction_date')
            ? \Illuminate\Support\Facades\DB::raw('COALESCE(invoices.transaction_date, invoices.created_at)')
            : 'created_at';
        $this->creatorColumn = \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'created_by')
            ? 'created_by' : null;
        $this->scalarFilters = [
            'branch_id', 'customer_id',
            'is_delivery', 'delivery_partner',
            'payment_method', 'sales_channel',
            'order_id', 'promotion_id', 'price_table_id',
        ];
    }

    public function index(Request $request)
    {
        $this->configureInvoiceFilters();

        $query = Invoice::with(['items.product', 'customer'])
            ->when($request->filled('has_debt'), function ($q) use ($request) {
                // has_debt=1 → còn nợ (total > customer_paid)
                // has_debt=0 → đã trả đủ
                if ((string)$request->input('has_debt') === '1') {
                    $q->whereColumn('total', '>', 'customer_paid');
                } else {
                    $q->whereColumn('total', '<=', 'customer_paid');
                }
            });

        $this->applyFilters($query, $request);

        // HOTFIX 24.28B — seller_key filter (Người bán)
        $sellerKey = $request->input('seller_key') ?? $request->input('employee_id');
        $sellerResolver = new SellerResolver();
        if ($sellerKey) {
            $query = $sellerResolver->filterBySeller($query, $sellerKey);
        }

        // HOTFIX 24.28B — creator_key filter (Người tạo snapshot)
        $creatorKey = $request->input('creator_key');
        if ($creatorKey) {
            $query = $sellerResolver->filterByCreator($query, $creatorKey);
        }

        $invoices = $query->paginate(15)->withQueryString();

        // Step 24.3C: enrich each invoice with cancel-policy hints so the UI
        // can render the right cancel modal state without guessing or duplicating
        // backend rules. The same checks still run server-side in destroy().
        $user = auth()->user();
        $canOverride = $user ? (bool) $user->hasPermission('invoices.override_time_lock') : false;
        $blockEinvoice = (bool) Setting::get('block_edit_cancel_einvoice', false);
        $orderChangeTime = (int) Setting::get('order_change_time', 24);
        $now = now();

        $invoices->getCollection()->transform(function ($inv) use ($canOverride, $blockEinvoice, $orderChangeTime, $now) {
            $lockRef = $inv->lock_started_at ?? $inv->created_at;
            $diffHours = $lockRef ? (float) Carbon::parse($lockRef)->floatDiffInHours($now) : 0.0;
            $isTimeLocked = $diffHours > $orderChangeTime;

            $blockReason = null;
            if ($inv->status === 'Đã hủy') {
                $blockReason = 'Hóa đơn này đã được hủy.';
            } elseif ($blockEinvoice && !empty($inv->einvoice_code ?? null)) {
                $blockReason = 'Không thể hủy hóa đơn đã xuất hóa đơn điện tử.';
            } elseif ($isTimeLocked && !$canOverride) {
                $blockReason = "Đã quá thời gian cho phép hủy hóa đơn ({$orderChangeTime} giờ). Cần quyền override.";
            }

            $inv->setAttribute('is_time_locked',          $isTimeLocked);
            $inv->setAttribute('lock_age_hours',          round($diffHours, 2));
            $inv->setAttribute('order_change_time_hours', $orderChangeTime);
            $inv->setAttribute('can_override_time_lock',  $canOverride);
            $inv->setAttribute('requires_override_reason', $isTimeLocked && $canOverride);
            $inv->setAttribute('cancel_block_reason',     $blockReason);
            $inv->setAttribute('can_cancel',              $blockReason === null);
            return $inv;
        });

        $filters = $this->currentFilters($request);
        $filters['has_debt'] = $request->input('has_debt', '');
        // HOTFIX 24.28B — pass seller_key and creator_key back to frontend
        $filters['seller_key'] = $sellerKey ?? '';
        $filters['creator_key'] = $creatorKey ?? '';

        // HOTFIX 24.28B — seller and creator options
        $sellerOptions = $sellerResolver->buildSellerFilterOptions();
        $creatorOptions = $sellerResolver->buildCreatorFilterOptions();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'filterOptions' => [
                'branches' => \App\Models\Branch::select('id', 'name')->get(),
                'statuses' => InvoiceStatus::options(),
                'sellers' => $sellerOptions,
                'creators' => $creatorOptions,
                'paymentMethods' => PaymentMethod::options(),
                'salesChannels' => Invoice::query()
                    ->whereNotNull('sales_channel')->where('sales_channel', '!=', '')
                    ->distinct()->orderBy('sales_channel')->pluck('sales_channel')
                    ->map(fn($c) => ['value' => $c, 'label' => $c])->values(),
                'deliveryOptions' => [
                    ['value' => '0', 'label' => 'Không giao hàng'],
                    ['value' => '1', 'label' => 'Giao hàng'],
                ],
                'debtOptions' => [
                    ['value' => '1', 'label' => 'Còn nợ'],
                    ['value' => '0', 'label' => 'Đã trả đủ'],
                ],
                // HOTFIX 24.30 — full active employee list for invoice detail dropdown
                'invoiceSellerOptions' => $sellerResolver->buildInvoiceSellerOptions(),
            ],
        ]);
    }

    public function apiSearch(Request $request)
    {
        $search = $request->input('search');
        $invoices = Invoice::with(['items.product', 'customer'])
            ->when($search, function ($query, $search) {
                return $query->where('code', 'LIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('code', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            })
            ->latest()
            ->limit(20)
            ->get();

        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'branch_id' => 'nullable',
            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'total' => 'required|numeric',
            'customer_paid' => 'nullable|numeric',
            'note' => 'nullable|string',
            'is_delivery' => 'boolean',
            'delivery_partner' => 'nullable|string',
            'delivery_fee' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
            'price_book_id' => 'nullable|exists:price_books,id',
            'price_book_name' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.note' => 'nullable|string',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
        ]);

        $priceBookName = 'Bảng giá chung';
        if (!empty($validated['price_book_id'])) {
            $priceBook = PriceBook::find($validated['price_book_id']);
            if ($priceBook) {
                $priceBookName = $priceBook->name;
            }
        } elseif (!empty($validated['price_book_name'])) {
            $priceBookName = $validated['price_book_name'];
        }

        try {
            // RR-02: build normalized payload + context, gọi InvoiceSaleService
            $payload = [
                'customer_id'    => $validated['customer_id'] ?? null,
                'branch_id'      => $validated['branch_id'] ?? null,
                'subtotal'       => $validated['subtotal'],
                'discount'       => $validated['discount'] ?? 0,
                'total'          => $validated['total'],
                'customer_paid'  => $validated['customer_paid'] ?? 0,
                'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
                'note'           => $validated['note'] ?? null,
                'items'          => array_map(function ($it) {
                    return [
                        'product_id' => $it['product_id'],
                        'quantity'   => $it['quantity'],
                        'price'      => $it['price'],
                        'discount'   => $it['discount'] ?? 0,
                        'note'       => $it['note'] ?? null,
                        'serial_ids' => $it['serial_ids'] ?? [],
                    ];
                }, $validated['items']),
            ];

            $context = [
                'source'                        => 'invoice',
                'code_prefix'                   => 'HD' . date('YmdHis'),
                'default_status'                => 'Hoàn thành',
                'price_book_name'               => $priceBookName,
                'created_by_name'               => auth()->user()?->name ?? 'Admin',
                'is_delivery'                   => $validated['is_delivery'] ?? false,
                'delivery_partner'              => $validated['delivery_partner'] ?? null,
                'delivery_fee'                  => $validated['delivery_fee'] ?? 0,
                'transaction_date'              => $request->filled('order_date') ? $request->input('order_date') : null,
                'validate_before_purchase_date' => true,
                'validate_stock_setting'        => true,
                'allow_oversell'                => Setting::get('inventory_allow_oversell', false),
                'cashflow_payment_method'       => $validated['payment_method'] ?? 'cash',
                'cashflow_description_extra'    => '',
                // stock_movement_branch_id để service mặc định lấy invoice.branch_id
            ];

            $invoice = app(InvoiceSaleService::class)->createSale($payload, $context);

            // Step 24.0: audit log invoice create
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_INVOICE_CREATE,
                "Tạo hóa đơn {$invoice->code}",
                $invoice,
                ['total' => (float) $invoice->total]
            );

            return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được tạo thành công.');
        } catch (\Exception $e) {
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }

    public function update(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'branch_id' => 'nullable',
            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            'total' => 'required|numeric',
            'customer_paid' => 'nullable|numeric',
            'note' => 'nullable|string',
            'is_delivery' => 'boolean',
            'delivery_partner' => 'nullable|string',
            'delivery_fee' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
            'price_book_name' => 'nullable|string|max:255',
            'transaction_date' => 'nullable|date',
            'time_lock_override_reason' => 'nullable|string|min:5|max:500',
            'transaction_date_change_reason' => 'nullable|string|min:5|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.note' => 'nullable|string',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
        ]);

        try {
            $payload = $validated;
            $payload['items'] = array_map(function ($it) {
                return [
                    'product_id' => $it['product_id'],
                    'quantity'   => $it['quantity'],
                    'price'      => $it['price'],
                    'discount'   => $it['discount'] ?? 0,
                    'note'       => $it['note'] ?? null,
                    'serial_ids' => $it['serial_ids'] ?? [],
                ];
            }, $validated['items']);

            $context = [
                'user' => auth()->user(),
                'time_lock_override_reason' => $validated['time_lock_override_reason'] ?? null,
                'transaction_date_change_reason' => $validated['transaction_date_change_reason'] ?? null,
            ];

            $invoice = app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, $context);

            return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được cập nhật thành công.');
        } catch (\Exception $e) {
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy(Invoice $invoice, Request $request)
    {
        // RR-01 Guard: Không cho hủy lặp — idempotent check
        if ($invoice->status === 'Đã hủy') {
            return back()->with('error', 'Hóa đơn này đã được hủy trước đó.');
        }

        // Block cancel if e-invoice issued
        if (Setting::get('block_edit_cancel_einvoice', false) && !empty($invoice->einvoice_code)) {
            return back()->with('error', 'Không thể hủy hóa đơn đã xuất hóa đơn điện tử.');
        }

        // Step 24.3: time lock uses lock_started_at fallback created_at
        $orderChangeTime = Setting::get('order_change_time', 24); // hours
        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $now = now();
        $diffHours = Carbon::parse($lockRef)->diffInHours($now);
        $isOverdue = $diffHours > $orderChangeTime;

        if ($isOverdue) {
            $user = auth()->user();
            $hasOverride = $user && $user->hasPermission('invoices.override_time_lock');
            if (!$hasOverride) {
                return back()->with('error', "Đã quá thời gian cho phép hủy hóa đơn ({$orderChangeTime} giờ). Cần quyền override.");
            }
            $reason = $request->input('time_lock_override_reason');
            if (!$reason || strlen(trim($reason)) < 5) {
                return back()->with('error', 'Cần nhập lý do override (ít nhất 5 ký tự).');
            }
        }

        try {
            DB::beginTransaction();

            $invoice->load('items');

            // Restore stock & serials for each item
            foreach ($invoice->items as $item) {
                $product = \App\Models\Product::find($item->product_id);
                if (!$product) continue;

                $qtyBack = (int) $item->quantity;
                $costAtSale = (float) ($item->cost_price ?? $product->cost_price ?? 0);

                // BQ DI ĐỘNG: phục hồi tồn ở cost lúc bán
                \App\Services\MovingAvgCostingService::applySaleReturn(
                    $product,
                    $qtyBack,
                    $costAtSale
                );
                $product->refresh();

                // Restore serials back to in_stock. Per-IMEI cost_price KHÔNG đổi
                // (giá nhập gốc của IMEI). BQ đã được service applySaleReturn xử lý.
                if ($product->has_serial) {
                    $serials = SerialImei::where('invoice_id', $invoice->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'sold')
                        ->get();
                    foreach ($serials as $serial) {
                        $serial->status = 'in_stock';
                        $serial->sold_at = null;
                        $serial->invoice_id = null;
                        $serial->sold_cost_price = null;
                        $serial->save();
                    }
                    $product->refresh();
                    $product->recomputeFromSerials();
                }

                // Phase 4 — Ghi sổ cái: hoàn nhập do hủy hóa đơn
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_IN_INVOICE_RETURN,
                    $qtyBack,
                    $costAtSale,
                    $invoice,
                    [
                        'branch_id' => $invoice->branch_id ?? null,
                        'ref_code' => $invoice->code,
                        'moved_at' => now(),
                        'note' => 'Hủy hóa đơn ' . $invoice->code,
                    ]
                );
            }
            if ($invoice->customer_id) {
                $customer = \App\Models\Customer::find($invoice->customer_id);
                if ($customer) {
                    // Hủy hóa đơn: hoàn lại debt (bao gồm cả overpayment negative)
                    // RR-06: ghi ledger qua service thay vì decrement trực tiếp.
                    $debtAmount = $invoice->total - ($invoice->customer_paid ?? 0);
                    if ($debtAmount != 0) {
                        app(CustomerDebtService::class)->recordSaleReversal(
                            $customer->id,
                            (float) $debtAmount,
                            $invoice,
                            "Đảo công nợ do hủy hóa đơn {$invoice->code}"
                        );
                    }
                    $customer->decrement('total_spent', $invoice->total);
                }
            }

            // RR-01: Đổi status CashFlow sang cancelled (không xóa) — đồng bộ với CashFlowController@cancel
            $cancelledCashFlowCount = CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'cancelled']);

            // RR-01: Đổi trạng thái hóa đơn — KHÔNG xóa vật lý (giữ items cho audit trail)
            $invoice->status = 'Đã hủy';
            $invoice->save();

            DB::commit();

            // Step 24.0: audit log invoice cancel
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_INVOICE_CANCEL,
                "Hủy hóa đơn {$invoice->code}",
                $invoice,
                ['total' => (float) $invoice->total]
            );

            // Step 24.3: log override if applicable
            if ($isOverdue && !empty($request->input('time_lock_override_reason'))) {
                \App\Models\ActivityLog::log(
                    \App\Models\ActivityLog::ACTION_INVOICE_CANCEL_TIME_LOCK_OVERRIDE,
                    "Hủy hóa đơn {$invoice->code} quá hạn (override)",
                    $invoice,
                    [
                        'total' => (float) $invoice->total,
                        'reason' => $request->input('time_lock_override_reason'),
                    ]
                );
            }

            return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được hủy thành công. Tồn kho và công nợ đã hoàn lại.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['items.product', 'customer']);

        // Công nợ cũ: nợ hiện tại của khách trừ đi nợ phát sinh từ hóa đơn này
        $previousDebt = 0;
        if ($invoice->customer) {
            $currentDebt = $invoice->customer->debt_amount ?? 0;
            $invoiceDebt = $invoice->total - ($invoice->customer_paid ?? 0);
            $previousDebt = $currentDebt - $invoiceDebt;
        }

        return view('prints.invoice', compact('invoice', 'previousDebt'));
    }

    public function paymentHistory(Invoice $invoice)
    {
        $payments = \App\Models\CashFlow::withTrashed()
            ->where('reference_type', 'Invoice')
            ->where('reference_code', $invoice->code)
            ->where('type', 'receipt')
            ->orderBy('time', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // If no CashFlow records, construct from the invoice itself (legacy compatibility, but only if not cancelled)
        if ($payments->isEmpty() && $invoice->customer_paid > 0 && $invoice->status !== 'Đã hủy') {
            $payments = collect([[
                'id' => $invoice->id,
                'code' => $invoice->code,
                'created_at' => $invoice->created_at,
                'time' => $invoice->created_at,
                'amount' => (float) $invoice->customer_paid,
                'method' => 'Tiền mặt',
                'payment_method' => 'Tiền mặt',
                'status' => 'completed',
                'is_cancelled' => false,
                'note' => 'Thanh toán khi tạo hóa đơn',
                'description' => 'Thanh toán khi tạo hóa đơn',
            ]]);
        } else {
            $payments = $payments->map(fn($cf) => [
                'id' => $cf->id,
                'code' => $cf->code,
                'created_at' => $cf->created_at,
                'time' => $cf->time,
                'amount' => (float) $cf->amount,
                'method' => $cf->payment_method ?: 'Tiền mặt',
                'payment_method' => $cf->payment_method ?: 'Tiền mặt',
                'status' => $cf->status,
                'is_cancelled' => $cf->status === 'cancelled',
                'note' => $cf->description,
                'description' => $cf->description,
            ])->values();
        }

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'code' => $invoice->code,
                'status' => $invoice->status,
                'customer_paid_snapshot' => (float) $invoice->customer_paid,
                'effective_paid' => $invoice->status === 'Đã hủy' ? 0.0 : (float) $invoice->customer_paid,
            ],
            'payments' => $payments,
        ]);
    }

    public function export(Request $request)
    {
        $this->configureInvoiceFilters();
        $query = \App\Models\Invoice::with(['customer']);
        $this->applyFilters($query, $request);
        $invoices = $query->get();

        return \App\Services\CsvService::export(
            ['Mã hóa đơn', 'Thời gian', 'Khách hàng', 'Tổng tiền hàng', 'Giảm giá', 'Tổng cộng', 'Khách đã trả', 'Ghi chú'],
            $invoices->map(fn($i) => [$i->code, $i->created_at?->format('d/m/Y H:i'), $i->customer?->name, $i->subtotal, $i->discount, $i->total, $i->customer_paid, $i->note]),
            'hoa_don.csv'
        );
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['customer', 'items.product', 'branch']);

        return Inertia::render('Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'code' => $invoice->code,
                'status' => $invoice->status,
                'created_at' => $invoice->created_at?->format('d/m/Y H:i'),
                'created_by_name' => $invoice->created_by_name ?? 'Admin',
                'seller_name' => $invoice->seller_name,
                'customer' => $invoice->customer ? [
                    'id' => $invoice->customer->id,
                    'name' => $invoice->customer->name,
                    'code' => $invoice->customer->code,
                    'phone' => $invoice->customer->phone,
                ] : null,
                'branch_name' => $invoice->branch->name ?? 'Chi nhánh chính',
                'note' => $invoice->note,
                'subtotal' => $invoice->subtotal,
                'discount' => $invoice->discount,
                'total' => $invoice->total,
                'customer_paid' => $invoice->customer_paid,
                'debt_amount' => $invoice->total - ($invoice->customer_paid ?? 0),
                'delivery_fee' => $invoice->delivery_fee ?? 0,
                'is_delivery' => $invoice->is_delivery,
                'delivery_partner' => $invoice->delivery_partner,
                'payment_method' => $invoice->payment_method,
                'items' => $invoice->items->map(fn($item) => [
                    'product_code' => $item->product?->sku ?: $item->product?->code ?: $item->product?->barcode ?: '',
                    'product_name' => $item->product->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount ?? 0,
                    'subtotal' => $item->subtotal,
                ]),
            ],
        ]);
    }

    public function detail(Invoice $invoice)
    {
        $invoice->load(['customer', 'items.product']);

        return response()->json([
            'id' => $invoice->id,
            'code' => $invoice->code,
            'status' => $invoice->status,
            'created_at' => $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : '',
            'created_by_name' => $invoice->created_by_name ?? 'Admin',
            'customer_name' => $invoice->customer->name ?? 'Khách lẻ',
            'customer_code' => $invoice->customer->code ?? '',
            'note' => $invoice->note,
            'subtotal' => $invoice->subtotal,
            'discount' => $invoice->discount,
            'total' => $invoice->total,
            'customer_paid' => $invoice->customer_paid,
            'delivery_fee' => $invoice->delivery_fee ?? 0,
            'is_delivery' => $invoice->is_delivery,
            'delivery_partner' => $invoice->delivery_partner,
            'payment_method' => $invoice->payment_method,
            'items' => $invoice->items->map(fn($item) => [
                'product_code' => $item->product?->sku ?: $item->product?->code ?: $item->product?->barcode ?: '',
                'product_name' => $item->product->name ?? '',
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount ?? 0,
                'subtotal' => $item->subtotal,
            ]),
        ]);
    }

    /**
     * HOTFIX 24.30 — Update the seller of an invoice.
     *
     * Only accepts employee:<id> as seller_key.
     * Updates created_by and seller_name. NEVER touches created_by_name.
     */
    public function updateSeller(Request $request, Invoice $invoice)
    {
        $user = auth()->user();

        // Check permission: admin (role_id = null) or user with invoices.cancel (closest existing permission)
        if ($user->role_id !== null && !$user->hasPermission('invoices.cancel')) {
            abort(403, 'Bạn không có quyền sửa người bán hóa đơn.');
        }

        // Cannot update cancelled invoices
        if ($invoice->status === 'Đã hủy') {
            return response()->json([
                'message' => 'Không thể sửa người bán của hóa đơn đã hủy.',
            ], 422);
        }

        $request->validate([
            'seller_key' => 'required|string',
        ]);

        $sellerKey = $request->input('seller_key');

        // Time-lock check (shared between both seller key types)
        $orderChangeTime = (int) Setting::get('order_change_time', 24);
        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $diffHours = $lockRef ? (float) Carbon::parse($lockRef)->floatDiffInHours(now()) : 0.0;
        $isTimeLocked = $diffHours > $orderChangeTime;
        $canOverride = $user->role_id === null || ($user->hasPermission('invoices.override_time_lock') ?? false);

        if ($isTimeLocked && !$canOverride) {
            return response()->json([
                'message' => "Đã quá thời gian cho phép sửa hóa đơn ({$orderChangeTime} giờ). Cần quyền override.",
            ], 422);
        }

        $oldCreatedBy  = $invoice->created_by;
        $oldSellerName = $invoice->seller_name;

        // HOTFIX 24.32 — admin_user:<id> virtual seller branch.
        // Used when the super-admin user has no Employee record. We store
        // the seller as a snapshot (created_by=NULL, seller_name=user.name)
        // and never touch created_by_name.
        if (preg_match('/^admin_user:(\d+)$/', (string) $sellerKey, $m)) {
            $adminUserId = (int) $m[1];
            $adminUser = User::find($adminUserId);
            if (!$adminUser
                || ($adminUser->status ?? 'active') !== 'active'
                || !$adminUser->isAdmin()) {
                return response()->json([
                    'message' => 'admin_user không hợp lệ. Chỉ chấp nhận tài khoản quản trị hệ thống đang hoạt động.',
                ], 422);
            }

            // If this admin already has an active linked Employee, force
            // the caller to use employee:<id> instead — keeps reports
            // grouped on the canonical key.
            $linkedEmployee = Employee::where('user_id', $adminUserId)
                ->where('is_active', true)->first();
            if ($linkedEmployee) {
                return response()->json([
                    'message' => 'Tài khoản admin này đã có nhân viên active. Hãy dùng employee:' . $linkedEmployee->id . '.',
                ], 422);
            }

            $invoice->created_by  = null;
            $invoice->seller_name = $adminUser->name;
            $invoice->save();

            ActivityLog::log(
                ActivityLog::ACTION_INVOICE_UPDATE,
                "Đổi người bán hóa đơn {$invoice->code} sang admin",
                $invoice,
                [
                    'action_detail'   => 'seller_change_admin_user',
                    'old_created_by'  => $oldCreatedBy,
                    'old_seller_name' => $oldSellerName,
                    'new_created_by'  => null,
                    'new_seller_name' => $adminUser->name,
                    'admin_user_id'   => $adminUser->id,
                    'actor_user_id'   => $user->id,
                ]
            );

            return response()->json([
                'message'     => 'Đã đổi người bán sang admin.',
                'created_by'  => null,
                'seller_name' => $adminUser->name,
                'seller_key'  => "admin_user:{$adminUser->id}",
            ]);
        }

        // employee:<id> branch (original behaviour)
        if (!preg_match('/^employee:(\d+)$/', (string) $sellerKey, $m)) {
            return response()->json([
                'message' => 'seller_key không hợp lệ. Chỉ chấp nhận employee:<id> hoặc admin_user:<id>.',
            ], 422);
        }

        $employeeId = (int) $m[1];
        $employee = Employee::where('id', $employeeId)
            ->where('is_active', true)
            ->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Nhân viên không tồn tại hoặc đã ngưng hoạt động.',
            ], 422);
        }

        // Resolve display name for seller (Hướng A)
        $sellerDisplayName = $employee->name;
        if ($employee->user_id) {
            $linkedUser = User::where('id', $employee->user_id)->where('status', 'active')->first();
            if ($linkedUser) {
                $sellerDisplayName = $linkedUser->name;
            }
        }

        $invoice->created_by = $employee->id;
        $invoice->seller_name = $employee->name;
        $invoice->save();

        ActivityLog::log(
            ActivityLog::ACTION_INVOICE_UPDATE,
            "Đổi người bán hóa đơn {$invoice->code}",
            $invoice,
            [
                'action_detail'      => 'seller_change',
                'old_created_by'     => $oldCreatedBy,
                'old_seller_name'    => $oldSellerName,
                'new_created_by'     => $employee->id,
                'new_seller_name'    => $employee->name,
                'new_seller_display' => $sellerDisplayName,
                'employee_code'      => $employee->code,
            ]
        );

        return response()->json([
            'message'     => 'Đã đổi người bán thành công.',
            'created_by'  => $employee->id,
            'seller_name' => $sellerDisplayName,
            'seller_key'  => "employee:{$employee->id}",
        ]);
    }
}
