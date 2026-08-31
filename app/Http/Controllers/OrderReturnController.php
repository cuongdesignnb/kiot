<?php

namespace App\Http\Controllers;

use App\Enums\ReturnStatus;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Services\CustomerDebtService;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Services\DebtOffsetService;
use App\Services\OrderReturnCreationService;
use App\Services\ReturnSalesAttributionService;
use App\Services\ReturnTotalCalculator;
use App\Services\StockMovementService;
use App\Support\BusinessDateTime;
use App\Support\Filters\FilterableIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrderReturnController extends Controller
{
    use FilterableIndex;

    protected function configureReturnFilters(): void
    {
        $this->searchable = ['code', 'note', 'created_by_name', 'seller_name'];
        $this->searchableRelations = [
            'customer' => ['name', 'phone', 'code'],
            'invoice' => ['code'],
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'subtotal', 'total', 'paid_to_customer', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = 'created_by';
        $this->scalarFilters = ['branch_id', 'customer_id', 'invoice_id', 'sales_channel'];
    }

    public function index(Request $request)
    {
        $this->configureReturnFilters();

        $query = OrderReturn::with([
            'items.product',
            'customer',
            'invoice.creator',
            'receivedByEmployee',
            'salesAttributionEmployee',
        ]);
        $this->applyFilters($query, $request);

        $sellerKey = $request->input('seller_key');
        if ($sellerKey) {
            $resolver = app(\App\Support\Reports\SellerResolver::class);
            $query = $resolver->filterReturnsBySeller($query, $sellerKey);
        }

        $returns = $query->paginate(15)->withQueryString();
        $resolver = app(\App\Support\Reports\SellerResolver::class);

        // Step 22.1B (read-only): enrich items[].returned_serials cho UI hiển thị.
        // Không sửa serial_ids, không thay đổi nghiệp vụ.
        $allSerialIds = [];
        foreach ($returns->items() as $ret) {
            foreach ($ret->items as $it) {
                if (is_array($it->serial_ids)) {
                    foreach ($it->serial_ids as $sid) {
                        $allSerialIds[] = $sid;
                    }
                }
            }
        }
        $serialMap = [];
        if (! empty($allSerialIds)) {
            $serialMap = SerialImei::whereIn('id', array_unique($allSerialIds))
                ->get(['id', 'serial_number'])
                ->keyBy('id');
        }
        foreach ($returns->items() as $ret) {
            foreach ($ret->items as $it) {
                $list = [];
                if (is_array($it->serial_ids)) {
                    foreach ($it->serial_ids as $sid) {
                        $s = $serialMap[$sid] ?? null;
                        $list[] = [
                            'id' => (int) $sid,
                            'serial_number' => $s?->serial_number,
                        ];
                    }
                }
                $it->setAttribute('returned_serials', $list);
            }

            $ret->setAttribute('original_seller_name', $resolver->originalSellerNameForReturn($ret));
            $ret->setAttribute('effective_sales_attribution_name', $resolver->displayNameForReturn($ret));
            $ret->setAttribute(
                'is_sales_attribution_overridden',
                $ret->sales_attribution_employee_id !== null || filled($ret->sales_attribution_name),
            );
            $ret->setAttribute('received_by_name', $ret->received_by_name ?: $ret->receivedByEmployee?->name);
        }

        $filters = $this->currentFilters($request);
        $filters['seller_key'] = $sellerKey ?? '';

        return Inertia::render('Returns/Index', [
            'returns' => $returns,
            'filters' => $filters,
            'filterOptions' => [
                'branches' => \App\Models\Branch::select('id', 'name')->get(),
                'statuses' => ReturnStatus::options(),
                'salesChannels' => OrderReturn::query()
                    ->whereNotNull('sales_channel')->where('sales_channel', '!=', '')
                    ->distinct()->orderBy('sales_channel')->pluck('sales_channel')
                    ->map(fn ($c) => ['value' => $c, 'label' => $c])->values(),
            ],
            'activeEmployees' => Employee::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function show(OrderReturn $return)
    {
        $return->load([
            'customer',
            'items.product',
            'invoice.creator',
            'receivedByEmployee',
            'salesAttributionEmployee',
            'salesAttributionUpdatedBy',
        ]);
        $businessTime = BusinessDateTime::nullable($return->return_date) ?? $return->created_at;
        $recordedAt = $return->recorded_at ?? $return->created_at;
        $resolver = app(\App\Support\Reports\SellerResolver::class);
        $canEditSalesAttribution = (bool) auth()->user()?->hasPermission('returns.sales_attribution.edit');

        // Step 22.1B (read-only): map serial_ids → display names.
        $allSerialIds = [];
        foreach ($return->items as $it) {
            if (is_array($it->serial_ids)) {
                foreach ($it->serial_ids as $sid) {
                    $allSerialIds[] = $sid;
                }
            }
        }
        $serialMap = [];
        if (! empty($allSerialIds)) {
            $serialMap = SerialImei::whereIn('id', array_unique($allSerialIds))
                ->get(['id', 'serial_number'])
                ->keyBy('id');
        }

        return Inertia::render('Returns/Show', [
            'returnOrder' => [
                'id' => $return->id,
                'code' => $return->code,
                'status' => $return->status,
                'created_at' => $return->created_at?->format('d/m/Y H:i'),
                'business_time' => $businessTime?->format('d/m/Y H:i') ?? '',
                'recorded_at' => $recordedAt?->format('d/m/Y H:i') ?? '',
                'business_time_source' => $return->return_date ? 'return_date' : 'created_at',
                'recorded_time_source' => $return->recorded_at ? 'recorded_at' : 'created_at',
                'created_by_name' => $return->created_by_name,
                'original_seller_name' => $resolver->originalSellerNameForReturn($return),
                'effective_sales_attribution_name' => $resolver->displayNameForReturn($return),
                'sales_attribution_employee_id' => $return->sales_attribution_employee_id,
                'sales_attribution_reason' => $return->sales_attribution_reason,
                'sales_attribution_updated_at' => $return->sales_attribution_updated_at?->format('d/m/Y H:i'),
                'sales_attribution_updated_by_name' => $return->salesAttributionUpdatedBy?->name,
                'is_sales_attribution_overridden' => $return->sales_attribution_employee_id !== null
                    || filled($return->sales_attribution_name),
                'can_edit_sales_attribution' => $canEditSalesAttribution,
                'received_by_employee_id' => $return->received_by_employee_id,
                'received_by_name' => $return->received_by_name ?: $return->receivedByEmployee?->name,
                'invoice_code' => $return->invoice?->code,
                'invoice_id' => $return->invoice_id,
                'customer' => $return->customer ? [
                    'id' => $return->customer->id,
                    'name' => $return->customer->name,
                    'code' => $return->customer->code,
                    'phone' => $return->customer->phone,
                ] : null,
                'note' => $return->note,
                'subtotal' => $return->subtotal,
                'discount' => $return->discount,
                'fee' => $return->fee ?? 0,
                'total' => $return->total,
                'paid_to_customer' => $return->paid_to_customer,
                'items' => $return->items->map(function ($item) use ($serialMap) {
                    $serials = [];
                    if (is_array($item->serial_ids)) {
                        foreach ($item->serial_ids as $sid) {
                            $s = $serialMap[$sid] ?? null;
                            $serials[] = [
                                'id' => (int) $sid,
                                'serial_number' => $s?->serial_number,
                            ];
                        }
                    }

                    return [
                        'product_code' => $item->product->code ?? '',
                        'product_name' => $item->product->name ?? '',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'discount' => $item->discount ?? 0,
                        'subtotal' => $item->subtotal ?? ($item->quantity * $item->price - ($item->discount ?? 0)),
                        'returned_serials' => $serials,
                    ];
                }),
            ],
            'salesAttributionEmployees' => $canEditSalesAttribution
                ? Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
                : [],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'nullable|exists:invoices,id',
            'customer_id' => 'nullable|exists:customers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|string',
            'subtotal' => 'required|numeric',
            'discount' => 'nullable|numeric',
            // Step 24.6E: fee can be VND amount (legacy) or percent.
            'fee' => 'nullable|numeric|min:0',
            'fee_type' => 'nullable|in:amount,percent',
            'fee_value' => 'nullable|numeric|min:0',
            'total' => 'required|numeric',
            'paid_to_customer' => 'nullable|numeric',
            'note' => 'nullable|string',
            'received_by_employee_id' => 'required|integer|exists:employees,id',
            'order_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
        ], [
            'received_by_employee_id.required' => 'Vui lòng chọn nhân viên nhận trả.',
            'received_by_employee_id.integer' => 'Nhân viên nhận trả không hợp lệ.',
            'received_by_employee_id.exists' => 'Nhân viên nhận trả không tồn tại.',
        ]);

        // Step 24.6E: backend recomputes subtotal/fee/total from raw inputs.
        // Frontend `total` is intentionally ignored — fee_type/fee_value drive
        // the canonical net refund. Legacy payloads (no fee_type) default to
        // 'amount' and the existing `fee` column is treated as VND.
        $calculated = app(ReturnTotalCalculator::class)->calculate([
            'items' => $validated['items'],
            'subtotal' => $validated['subtotal'] ?? null,
            'discount' => $validated['discount'] ?? 0,
            'fee_type' => $validated['fee_type'] ?? null,
            'fee_value' => $validated['fee_value'] ?? null,
            'fee' => $validated['fee'] ?? null,
            'paid_to_customer' => $validated['paid_to_customer'] ?? null,
        ]);
        // Override the validated bag with backend-canonical values so every
        // downstream OrderReturn::create / debt / cashflow uses the same numbers.
        $validated['subtotal'] = $calculated['subtotal'];
        $validated['discount'] = $calculated['discount'];
        $validated['fee'] = $calculated['fee_amount'];
        $validated['fee_type'] = $calculated['fee_type'];
        $validated['fee_value'] = $calculated['fee_value'];
        $validated['total'] = $calculated['total_refund'];
        $validated['paid_to_customer'] = $calculated['paid_to_customer'];

        $createdReturn = app(OrderReturnCreationService::class)->create($validated, [
            'created_by_name' => auth()->user()?->name ?? 'Admin',
            'order_date' => $validated['order_date'] ?? null,
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'return' => [
                    'id' => $createdReturn->id,
                    'code' => $createdReturn->code,
                ],
                'message' => 'Phiếu trả hàng đã được tạo thành công.',
            ]);
        }

        return redirect()->route('returns.index')->with('success', 'Phiếu trả hàng đã được tạo thành công.');

        // ── RR-11: Validate qty trả vs qty đã bán ──────────────────────
        if (! empty($validated['invoice_id'])) {
            $invoice = \App\Models\Invoice::find($validated['invoice_id']);

            // Không cho trả hàng trên invoice đã hủy
            if ($invoice && $invoice->status === 'Đã hủy') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'invoice_id' => 'Không thể trả hàng cho hóa đơn đã bị hủy.',
                ]);
            }

            if ($invoice) {
                // Gom qty request theo product_id (phòng nhiều dòng cùng product)
                $requestedByProduct = [];
                foreach ($validated['items'] as $item) {
                    $pid = $item['product_id'];
                    $requestedByProduct[$pid] = ($requestedByProduct[$pid] ?? 0) + (int) $item['qty'];
                }

                foreach ($requestedByProduct as $productId => $requestedQty) {
                    // Qty đã bán trên invoice
                    $soldQty = \App\Models\InvoiceItem::where('invoice_id', $invoice->id)
                        ->where('product_id', $productId)
                        ->sum('quantity');

                    // Qty đã trả trước đó (chỉ tính phiếu chưa hủy)
                    $alreadyReturned = ReturnItem::where('product_id', $productId)
                        ->whereHas('orderReturn', function ($q) use ($invoice) {
                            $q->where('invoice_id', $invoice->id)
                                ->where('status', '!=', 'Đã hủy');
                        })
                        ->sum('quantity');

                    $remainingQty = $soldQty - $alreadyReturned;

                    if ($requestedQty > $remainingQty) {
                        $product = \App\Models\Product::find($productId);
                        $productName = $product ? $product->name : "ID {$productId}";
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => "Sản phẩm '{$productName}' chỉ còn được trả {$remainingQty} (đã bán {$soldQty}, đã trả {$alreadyReturned}), yêu cầu trả {$requestedQty}.",
                        ]);
                    }
                }
            }
        }
        // ── End RR-11 validation ────────────────────────────────────────

        // ── Step 23.2: Validate serial cho hàng has_serial khi return ──
        // (a) count(serial_ids) === qty bắt buộc (không tự đoán/auto-pick).
        // (b) mọi serial phải thuộc invoice_id (nếu có) và đang status='sold'.
        // (c) một serial không xuất hiện 2 dòng cùng phiếu trả này.
        // Áp dụng TRƯỚC DB::transaction để fail sớm, không tạo phiếu lỗi.
        $seenSerialIds = [];
        foreach ($validated['items'] as $item) {
            $product = \App\Models\Product::find($item['product_id']);
            if (! $product || ! $product->has_serial) {
                continue;
            }
            $qty = (int) $item['qty'];
            $serialIds = array_values(array_filter(array_map('intval', (array) ($item['serial_ids'] ?? []))));

            if (count($serialIds) !== $qty) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'items' => "Sản phẩm '{$product->name}' (Serial/IMEI) yêu cầu chọn đúng "
                        ."{$qty} mã, hiện đã chọn ".count($serialIds).'.',
                ]);
            }

            // Trùng serial trong cùng request
            foreach ($serialIds as $sid) {
                if (isset($seenSerialIds[$sid])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => "Serial ID {$sid} bị chọn trùng nhiều dòng.",
                    ]);
                }
                $seenSerialIds[$sid] = true;
            }

            // Mọi serial phải sold + thuộc product + (nếu có invoice) thuộc invoice
            $serialQuery = SerialImei::whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->where('status', 'sold');
            if (! empty($validated['invoice_id'])) {
                $serialQuery->where('invoice_id', $validated['invoice_id']);
            }
            $validCount = $serialQuery->count();
            if ($validCount !== count($serialIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'items' => "Sản phẩm '{$product->name}': có serial không hợp lệ "
                        .'(không thuộc hóa đơn này hoặc chưa từng bán).',
                ]);
            }
        }
        // ── End Step 23.2 serial validation ─────────────────────────────

        $createdReturn = null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, &$createdReturn) {
            $returnDate = BusinessDateTime::forCreate($validated['order_date'] ?? null);

            // Check return time limit
            if (Setting::get('return_time_limit_enabled', false) && ! empty($validated['invoice_id'])) {
                $invoice = \App\Models\Invoice::find($validated['invoice_id']);
                if ($invoice) {
                    $limitDays = Setting::get('return_time_limit_days', 7);
                    if ($invoice->created_at->diffInDays(now()) > $limitDays) {
                        $action = Setting::get('return_overdue_action', 'warn');
                        if ($action === 'block') {
                            throw new \Exception("Hóa đơn đã quá {$limitDays} ngày, không thể trả hàng.");
                        }
                    }
                }
            }

            $returnPayload = [
                'code' => 'TH'.date('YmdHis').rand(10, 99),
                'invoice_id' => $validated['invoice_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => 'Đã trả',
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'fee' => $validated['fee'] ?? 0,
                'total' => $validated['total'],
                'paid_to_customer' => $validated['paid_to_customer'] ?? $validated['total'],
                'note' => $validated['note'] ?? null,
                'created_by_name' => auth()->user()?->name ?? 'Admin',
                'created_at' => $returnDate,
            ];
            // Step 24.6E: persist fee_type + fee_value when the schema has them.
            if (\Illuminate\Support\Facades\Schema::hasColumn('returns', 'fee_type')) {
                $returnPayload['fee_type'] = $validated['fee_type'] ?? 'amount';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('returns', 'fee_value')) {
                $returnPayload['fee_value'] = $validated['fee_value'] ?? 0;
            }
            $return = $createdReturn = OrderReturn::create($returnPayload);

            $costingMethod = Setting::get('inventory_costing_method', 'average');

            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::lockForUpdate()->find($item['product_id']);
                if (! $product) {
                    continue;
                }

                $qty = (int) $item['qty'];
                $invoiceItem = null;

                // Tìm invoice_item gốc để lấy cost_price_at_sale
                if (! empty($item['invoice_item_id'])) {
                    $invoiceItem = \App\Models\InvoiceItem::find($item['invoice_item_id']);
                } elseif (! empty($validated['invoice_id'])) {
                    $invoiceItem = \App\Models\InvoiceItem::where('invoice_id', $validated['invoice_id'])
                        ->where('product_id', $product->id)
                        ->orderBy('id')
                        ->first();
                }

                // Xác định serial cần khôi phục (nếu hàng serial)
                $restoredSerials = collect();
                if ($product->tracksInventory() && $product->has_serial) {
                    if (! empty($item['serial_ids'])) {
                        $restoredSerials = SerialImei::whereIn('id', $item['serial_ids'])
                            ->where('product_id', $product->id)
                            ->where('status', 'sold')
                            ->get();
                    } elseif ($invoiceItem) {
                        // Lấy theo invoice_item_serials nếu có
                        $linkSerialIds = \App\Models\InvoiceItemSerial::where('invoice_item_id', $invoiceItem->id)
                            ->pluck('serial_imei_id')->filter()->all();
                        if (! empty($linkSerialIds)) {
                            $restoredSerials = SerialImei::whereIn('id', $linkSerialIds)
                                ->where('status', 'sold')
                                ->limit($qty)->get();
                        }
                    }

                    // Fallback cuối cùng: lấy theo invoice_id + product_id (legacy data)
                    if ($restoredSerials->isEmpty() && ! empty($validated['invoice_id'])) {
                        $restoredSerials = SerialImei::where('invoice_id', $validated['invoice_id'])
                            ->where('product_id', $product->id)
                            ->where('status', 'sold')
                            ->limit($qty)->get();
                    }
                }

                // Tính giá vốn hoàn lại (snapshot lúc bán) — ƯU TIÊN invoice_item.cost_price
                $restoredCostPerUnit = 0.0;
                if ($invoiceItem) {
                    $restoredCostPerUnit = $product->tracksInventory() ? (float) $invoiceItem->cost_price : 0.0;
                } else {
                    // Không có thông tin gốc — fallback dùng cost hiện tại
                    $restoredCostPerUnit = $product->tracksInventory() ? (float) $product->cost_price : 0.0;
                }
                if ($product->tracksInventory() && $product->has_serial && $restoredSerials->isNotEmpty()) {
                    $restoredCostPerUnit = \App\Services\SerialCostingService::snapshotForReturn($restoredSerials)['unit_cost'];
                }

                // RR-08: lưu serial_ids đã trả để cancel rollback đúng
                $serialIdsForItem = $product->tracksInventory() && $product->has_serial
                    ? $restoredSerials->pluck('id')->map(fn ($id) => (int) $id)->all()
                    : null;

                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'invoice_item_id' => $invoiceItem?->id,
                    'quantity' => $qty,
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'import_price' => $item['price'],
                    'cost_price' => $restoredCostPerUnit,
                    'serial_ids' => ! empty($serialIdsForItem) ? $serialIdsForItem : null,
                ]);

                if (! $product->tracksInventory()) {
                    continue;
                }

                // BQ DI ĐỘNG: phục hồi tồn ở cost lúc bán
                \App\Services\MovingAvgCostingService::applySaleReturn(
                    $product,
                    (int) $qty,
                    (float) $restoredCostPerUnit
                );
                $product->refresh();

                // Khôi phục serial về in_stock. Per-IMEI cost_price KHÔNG đổi
                // (giữ giá nhập gốc). BQ đã cập nhật qua applySaleReturn.
                foreach ($restoredSerials as $serial) {
                    $serial->status = 'in_stock';
                    $serial->sold_at = null;
                    $serial->invoice_id = null;
                    $serial->sold_cost_price = null;
                    $serial->save();
                }

                // Sync stock_quantity audit cho hàng serial
                if ($product->has_serial) {
                    $product->recomputeFromSerials();
                }

                // Phase 4 — Ghi sổ cái: hàng KH trả về (nhập vào kho)
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_IN_INVOICE_RETURN,
                    (int) $qty,
                    (float) $restoredCostPerUnit,
                    $return,
                    [
                        'branch_id' => $return->branch_id ?? null,
                        'ref_code' => $return->code,
                        'moved_at' => $returnDate,
                        'note' => 'Khách trả hàng phiếu '.$return->code,
                    ]
                );
            }
            if (! empty($validated['customer_id'])) {
                $customer = \App\Models\Customer::find($validated['customer_id']);
                if ($customer) {
                    // RR-06: ghi ledger qua service. Trả hàng luôn giảm nợ KH; debt có thể âm = ta nợ KH.
                    app(CustomerDebtService::class)->recordReturn(
                        $customer->id,
                        (float) $validated['total'],
                        $return,
                        "Giảm công nợ do trả hàng phiếu {$return->code}"
                    );
                    $customer->decrement('total_spent', $validated['total']);
                }
            }

            // Record cash flow with correct field names matching CashFlow $fillable
            $customer = ! empty($validated['customer_id']) ? \App\Models\Customer::find($validated['customer_id']) : null;
            if ($return->paid_to_customer > 0) {
                CashFlow::create([
                    'code' => 'PC'.date('YmdHis').rand(10, 99),
                    'type' => 'payment',
                    'amount' => $return->paid_to_customer,
                    'time' => $returnDate,
                    'category' => 'Chi tiền trả hàng khách',
                    'target_type' => 'Khách hàng',
                    'target_id' => $return->customer_id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'OrderReturn',
                    'reference_code' => $return->code,
                    'payment_method' => 'cash',
                    'description' => "Chi trả hàng khách cho phiếu {$return->code}".($customer ? " - {$customer->name}" : ''),
                ]);
            }

            // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ

            // Cho phép chọn ngày trả hàng (kế toán nhập sau)
            if (request()->filled('order_date')) {
                $returnDate = \Carbon\Carbon::parse(request()->order_date);

                // Validate: ngày trả hàng không được trước ngày hóa đơn gốc
                if (! empty($validated['invoice_id'])) {
                    $invoice = \App\Models\Invoice::find($validated['invoice_id']);
                    if ($invoice && $returnDate->lt($invoice->created_at)) {
                        throw new \Exception('Ngày trả hàng không thể trước ngày hóa đơn gốc ('.$invoice->created_at->format('d/m/Y H:i').').');
                    }
                }

                $return->update(['created_at' => $returnDate]);
            }
        });

        // Step 24.0: audit log return create
        if ($createdReturn) {
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_RETURN_CREATE,
                "Tạo phiếu trả hàng {$createdReturn->code}",
                $createdReturn,
                ['total' => (float) $createdReturn->total]
            );
        }

        return redirect()->route('returns.index')->with('success', 'Phiếu trả hàng đã được tạo thành công.');
    }

    public function updateReceiver(Request $request, OrderReturn $return)
    {
        $validated = $request->validate([
            'received_by_employee_id' => 'required|integer|exists:employees,id',
        ], [
            'received_by_employee_id.required' => 'Vui lòng chọn nhân viên nhận trả.',
            'received_by_employee_id.exists' => 'Nhân viên nhận trả không tồn tại.',
        ]);

        $updated = DB::transaction(function () use ($return, $validated) {
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (in_array(trim((string) $locked->status), [ReturnStatus::CANCELLED, 'cancelled', 'canceled', 'void', 'deleted', 'Đã hủy'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'received_by_employee_id' => 'Không thể đổi người nhận của phiếu đã hủy.',
                ]);
            }

            $employee = Employee::query()->lockForUpdate()->find($validated['received_by_employee_id']);
            if (! $employee) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'received_by_employee_id' => 'Nhân viên nhận trả không tồn tại.',
                ]);
            }
            if (! $employee->is_active) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'received_by_employee_id' => 'Chỉ được chọn nhân viên đang hoạt động.',
                ]);
            }

            $old = [
                'received_by_employee_id' => $locked->received_by_employee_id,
                'received_by_name' => $locked->received_by_name,
            ];
            $locked->update([
                'received_by_employee_id' => $employee->id,
                'received_by_name' => $employee->name,
            ]);

            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_RETURN_RECEIVER_UPDATE,
                "Cập nhật người nhận trả phiếu {$locked->code}",
                $locked,
                [
                    'old' => $old,
                    'new' => [
                        'received_by_employee_id' => $employee->id,
                        'received_by_name' => $employee->name,
                    ],
                ],
            );

            return $locked->fresh(['receivedByEmployee']);
        });

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'return' => [
                    'id' => $updated->id,
                    'received_by_employee_id' => $updated->received_by_employee_id,
                    'received_by_name' => $updated->received_by_name,
                ],
                'message' => 'Đã lưu người nhận trả.',
            ]);
        }

        return back()->with('success', 'Đã lưu người nhận trả.');
    }

    public function updateSalesAttribution(Request $request, OrderReturn $return, ReturnSalesAttributionService $service)
    {
        $validated = $request->validate([
            'sales_attribution_employee_id' => 'nullable|integer|exists:employees,id',
            'reason' => 'required|string|min:5|max:500',
        ], [
            'sales_attribution_employee_id.integer' => 'Người chịu doanh số phải là nhân viên hợp lệ.',
            'sales_attribution_employee_id.exists' => 'Nhân viên chịu doanh số không tồn tại.',
            'reason.required' => 'Vui lòng nhập lý do điều chỉnh.',
            'reason.min' => 'Lý do điều chỉnh phải có ít nhất 5 ký tự.',
            'reason.max' => 'Lý do điều chỉnh không được vượt quá 500 ký tự.',
        ]);

        $updated = $service->update(
            $return,
            isset($validated['sales_attribution_employee_id'])
                ? (int) $validated['sales_attribution_employee_id']
                : null,
            $validated['reason'],
            $request->user(),
        );
        $resolver = app(\App\Support\Reports\SellerResolver::class);
        $isOverridden = $updated->sales_attribution_employee_id !== null
            || filled($updated->sales_attribution_name);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'return' => [
                    'id' => $updated->id,
                    'original_seller_name' => $resolver->originalSellerNameForReturn($updated),
                    'effective_sales_attribution_name' => $resolver->displayNameForReturn($updated),
                    'sales_attribution_employee_id' => $updated->sales_attribution_employee_id,
                    'sales_attribution_reason' => $updated->sales_attribution_reason,
                    'sales_attribution_updated_at' => $updated->sales_attribution_updated_at?->toISOString(),
                    'is_sales_attribution_overridden' => $isOverridden,
                ],
                'message' => 'Đã lưu người chịu doanh số trả hàng.',
            ]);
        }

        return back()->with('success', 'Đã lưu người chịu doanh số trả hàng.');
    }

    public function export(Request $request)
    {
        $this->configureReturnFilters();

        $query = \App\Models\OrderReturn::with(['customer', 'invoice']);
        $this->applyFilters($query, $request);
        if ($sellerKey = $request->input('seller_key')) {
            $query = app(\App\Support\Reports\SellerResolver::class)
                ->filterReturnsBySeller($query, $sellerKey);
        }
        $returns = $query->get();

        return \App\Services\CsvService::export(
            ['Mã trả hàng', 'Thời gian', 'Mã hóa đơn', 'Khách hàng', 'Tổng tiền trả', 'Đã trả khách', 'Trạng thái', 'Ghi chú'],
            $returns->map(fn ($r) => [$r->code, $r->created_at?->format('d/m/Y H:i'), $r->invoice?->code, $r->customer?->name, $r->total, $r->paid_to_customer, $r->status, $r->note]),
            'tra_hang.csv'
        );
    }

    public function print(\App\Models\OrderReturn $return)
    {
        $return->load(['items.product', 'invoice', 'customer']);

        return view('prints.return', compact('return'));
    }

    /**
     * Hủy phiếu trả hàng — rollback tồn kho, công nợ, CashFlow.
     */
    public function cancel(OrderReturn $return)
    {
        if (in_array(trim((string) $return->status), [ReturnStatus::CANCELLED, 'cancelled', 'canceled', 'void', 'deleted'], true)) {
            return back()->with('error', 'Phieu tra hang da huy truoc do.');
        }

        if ($return->status === 'Đã hủy') {
            return back()->with('error', 'Phiếu trả hàng đã bị hủy trước đó.');
        }

        $cancelReturn = function () use ($return) {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($this->isCancelledReturn($return)) {
                return null;
            }

            $return->setRelation('items', ReturnItem::query()
                ->where('return_id', $return->id)
                ->with('product')
                ->lockForUpdate()
                ->get());
            $this->assertReturnSerialsSafeToCancel($return);
            $return->load('items.product');

            // 1. Rollback stock: trừ lại tồn kho đã cộng (đảo ngược applySaleReturn)
            foreach ($return->items as $item) {
                if ($item->product && $item->product->tracksInventory()) {
                    $unitCost = (float) ($item->cost_price ?: $item->product->cost_price ?? 0);

                    // BQ DI ĐỘNG: rút khỏi tồn ở chính cost lúc trả hàng (đảo ngược applySaleReturn)
                    \App\Services\MovingAvgCostingService::applyPurchaseReturn(
                        $item->product,
                        (int) $item->quantity,
                        $unitCost
                    );

                    // RR-08: Rollback serials theo đúng serial_ids đã lưu trên ReturnItem.
                    // Không dùng query mơ hồ whereNull(invoice_id)->limit($qty) vì có thể
                    // chọn nhầm serial khác đang in_stock (chưa từng thuộc invoice).
                    if ($item->product->has_serial && $return->invoice_id) {
                        $serialIds = is_array($item->serial_ids) ? $item->serial_ids : [];
                        if (! empty($serialIds)) {
                            SerialImei::whereIn('id', $serialIds)
                                ->where('product_id', $item->product_id)
                                ->update([
                                    'status' => 'sold',
                                    'sold_at' => now(),
                                    'invoice_id' => $return->invoice_id,
                                    'sold_cost_price' => (float) ($item->cost_price ?: 0) ?: null,
                                ]);
                        }
                        // Nếu serial_ids rỗng (legacy data trước RR-08), không fallback
                        // chọn đại — để tránh gán nhầm serial. Cần backfill nếu có data cũ.
                    }

                    // Phase 4 — Ghi sổ cái: hủy phiếu trả hàng = xuất kho ngược lại
                    StockMovementService::record(
                        $item->product->fresh(),
                        StockMovementService::TYPE_OUT_INVOICE,
                        (int) $item->quantity,
                        $unitCost,
                        $return,
                        [
                            'branch_id' => $return->branch_id ?? null,
                            'ref_code' => $return->code,
                            'moved_at' => now(),
                            'note' => 'Hủy phiếu trả hàng '.$return->code,
                        ]
                    );
                }
            }

            // 2. Rollback customer debt & total_spent
            // RR-06: ghi ledger adjustment khôi phục công nợ khi hủy phiếu trả hàng.
            if ($return->customer_id) {
                $customer = \App\Models\Customer::find($return->customer_id);
                if ($customer) {
                    $preCancelSettledAmount = (float) \App\Models\CustomerDebt::query()
                        ->where(function ($q) use ($return) {
                            $q->where('order_return_id', $return->id)
                                ->orWhere('ref_code', $return->code);
                        })
                        ->where('type', 'adjustment')
                        ->where('amount', '>', 0)
                        ->sum('amount');

                    app(CustomerDebtService::class)->recordAdjustment(
                        $customer->id,
                        (float) $return->total, // dương = khôi phục nợ
                        "Khôi phục công nợ do hủy phiếu trả hàng {$return->code}",
                        ['order_return_id' => $return->id, 'ref_code' => $return->code]
                    );
                    if ($preCancelSettledAmount > 0) {
                        app(CustomerDebtService::class)->recordAdjustment(
                            $customer->id,
                            -$preCancelSettledAmount,
                            "Dao tat toan tien da tra khach do huy phieu tra {$return->code}",
                            ['order_return_id' => $return->id, 'ref_code' => $return->code]
                        );
                    }

                    $customer->increment('total_spent', $return->total);
                }
            }

            // 3. Cancel related CashFlow
            CashFlow::where('reference_type', 'OrderReturn')
                ->where('reference_code', $return->code)
                ->update(['status' => 'cancelled']);
            CashFlow::where('reference_type', 'OrderReturn')
                ->where('reference_code', $return->code)
                ->delete();

            // 4. Mark return as cancelled
            $return->update(['status' => 'Đã hủy']);

            return $return;
        };

        if ($return->customer_id) {
            $cancelledReturn = app(PartnerDebtMutationCoordinator::class)->execute(
                (int) $return->customer_id,
                'customer_return_cancel',
                hash('sha256', 'return_cancel|'.(int) $return->id),
                fn () => DB::transaction($cancelReturn),
                request()->header('Idempotency-Key'),
            );
        } else {
            $cancelledReturn = DB::transaction($cancelReturn);
        }

        if (! $cancelledReturn) {
            return back()->with('error', 'Phiếu trả hàng đã bị hủy trước đó.');
        }

        // Step 24.0: audit log return cancel
        \App\Models\ActivityLog::log(
            \App\Models\ActivityLog::ACTION_RETURN_CANCEL,
            "Hủy phiếu trả hàng {$cancelledReturn->code}",
            $cancelledReturn,
            ['total' => (float) $cancelledReturn->total]
        );

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Đã hủy phiếu trả hàng.']);
        }

        return back()->with('success', 'Đã hủy phiếu trả hàng '.$cancelledReturn->code);
    }

    private function isCancelledReturn(OrderReturn $return): bool
    {
        return in_array(trim((string) $return->status), [
            ReturnStatus::CANCELLED,
            'cancelled',
            'canceled',
            'void',
            'deleted',
            'Đã hủy',
        ], true);
    }

    /**
     * A return can be cancelled only while every specifically returned serial
     * remains in the safe state produced by return creation. This guard runs
     * under the return and serial row locks, before stock/debt/cash mutations.
     */
    private function assertReturnSerialsSafeToCancel(OrderReturn $return): void
    {
        $serialIds = [];
        foreach ($return->items as $item) {
            $ids = array_values(array_filter(array_map('intval', (array) $item->serial_ids)));
            if ($item->product?->has_serial && $ids === []) {
                throw ValidationException::withMessages([
                    'serial_ids' => 'Không thể hủy phiếu trả vì thiếu Serial/IMEI đã lưu. Hệ thống chưa thay đổi tồn kho, công nợ hoặc Serial.',
                ]);
            }
            foreach ($ids as $serialId) {
                $serialIds[$serialId] = true;
            }
        }

        if ($serialIds === []) {
            return;
        }

        $serials = SerialImei::query()
            ->with('invoice:id,code')
            ->whereIn('id', array_keys($serialIds))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($serials->count() !== count($serialIds)) {
            throw ValidationException::withMessages([
                'serial_ids' => 'Không thể hủy phiếu trả vì Serial/IMEI đã lưu không còn tồn tại. Hệ thống chưa thay đổi tồn kho, công nợ hoặc Serial.',
            ]);
        }

        foreach ($serials as $serial) {
            if ($serial->status === 'in_stock' && $serial->invoice_id === null) {
                continue;
            }

            if ($serial->status === 'sold'
                && $serial->invoice_id !== null
                && (int) $serial->invoice_id !== (int) $return->invoice_id) {
                $invoiceCode = $serial->invoice?->code ?? ('#'.$serial->invoice_id);
                throw ValidationException::withMessages([
                    'serial_ids' => "Không thể hủy phiếu trả vì Serial {$serial->serial_number} đã được bán lại trên hóa đơn {$invoiceCode}.\nHãy dùng chức năng “Điều chỉnh người chịu doanh số trả hàng”.\nHệ thống chưa thay đổi tồn kho, công nợ hoặc Serial.",
                ]);
            }

            throw ValidationException::withMessages([
                'serial_ids' => "Không thể hủy phiếu trả vì Serial {$serial->serial_number} không còn ở trạng thái tồn kho an toàn. Hệ thống chưa thay đổi tồn kho, công nợ hoặc Serial.",
            ]);
        }
    }
}
