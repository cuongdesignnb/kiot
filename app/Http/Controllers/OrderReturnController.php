<?php

namespace App\Http\Controllers;

use App\Enums\ReturnStatus;
use App\Models\OrderReturn;
use App\Models\Setting;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Services\DebtOffsetService;
use App\Services\StockMovementService;
use App\Support\Filters\FilterableIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class OrderReturnController extends Controller
{
    use FilterableIndex;

    protected function configureReturnFilters(): void
    {
        $this->searchable = ['code', 'note', 'created_by_name', 'seller_name'];
        $this->searchableRelations = [
            'customer'      => ['name', 'phone', 'code'],
            'invoice'       => ['code'],
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

        $query = OrderReturn::with(['items.product', 'customer', 'invoice']);
        $this->applyFilters($query, $request);

        $returns = $query->paginate(15)->withQueryString();

        return Inertia::render('Returns/Index', [
            'returns' => $returns,
            'filters' => $this->currentFilters($request),
            'filterOptions' => [
                'branches' => \App\Models\Branch::select('id', 'name')->get(),
                'statuses' => ReturnStatus::options(),
                'salesChannels' => OrderReturn::query()
                    ->whereNotNull('sales_channel')->where('sales_channel', '!=', '')
                    ->distinct()->orderBy('sales_channel')->pluck('sales_channel')
                    ->map(fn($c) => ['value' => $c, 'label' => $c])->values(),
            ],
        ]);
    }

    public function show(OrderReturn $return)
    {
        $return->load(['customer', 'items.product', 'invoice']);

        return Inertia::render('Returns/Show', [
            'returnOrder' => [
                'id' => $return->id,
                'code' => $return->code,
                'status' => $return->status,
                'created_at' => $return->created_at?->format('d/m/Y H:i'),
                'created_by_name' => $return->created_by_name ?? 'Admin',
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
                'items' => $return->items->map(fn($item) => [
                    'product_code' => $item->product->code ?? '',
                    'product_name' => $item->product->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount ?? 0,
                    'subtotal' => $item->subtotal ?? ($item->quantity * $item->price - ($item->discount ?? 0)),
                ]),
            ],
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
            'fee' => 'nullable|numeric',
            'total' => 'required|numeric',
            'paid_to_customer' => 'nullable|numeric',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            // Check return time limit
            if (Setting::get('return_time_limit_enabled', false) && !empty($validated['invoice_id'])) {
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

            $return = OrderReturn::create([
                'code' => 'TH' . date('YmdHis') . rand(10, 99),
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
            ]);

            $costingMethod = Setting::get('inventory_costing_method', 'average');

            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::lockForUpdate()->find($item['product_id']);
                if (!$product) {
                    continue;
                }

                $qty = (int) $item['qty'];
                $invoiceItem = null;

                // Tìm invoice_item gốc để lấy cost_price_at_sale
                if (!empty($item['invoice_item_id'])) {
                    $invoiceItem = \App\Models\InvoiceItem::find($item['invoice_item_id']);
                } elseif (!empty($validated['invoice_id'])) {
                    $invoiceItem = \App\Models\InvoiceItem::where('invoice_id', $validated['invoice_id'])
                        ->where('product_id', $product->id)
                        ->orderBy('id')
                        ->first();
                }

                // Xác định serial cần khôi phục (nếu hàng serial)
                $restoredSerials = collect();
                if ($product->has_serial) {
                    if (!empty($item['serial_ids'])) {
                        $restoredSerials = SerialImei::whereIn('id', $item['serial_ids'])
                            ->where('product_id', $product->id)
                            ->where('status', 'sold')
                            ->get();
                    } elseif ($invoiceItem) {
                        // Lấy theo invoice_item_serials nếu có
                        $linkSerialIds = \App\Models\InvoiceItemSerial::where('invoice_item_id', $invoiceItem->id)
                            ->pluck('serial_imei_id')->filter()->all();
                        if (!empty($linkSerialIds)) {
                            $restoredSerials = SerialImei::whereIn('id', $linkSerialIds)
                                ->where('status', 'sold')
                                ->limit($qty)->get();
                        }
                    }

                    // Fallback cuối cùng: lấy theo invoice_id + product_id (legacy data)
                    if ($restoredSerials->isEmpty() && !empty($validated['invoice_id'])) {
                        $restoredSerials = SerialImei::where('invoice_id', $validated['invoice_id'])
                            ->where('product_id', $product->id)
                            ->where('status', 'sold')
                            ->limit($qty)->get();
                    }
                }

                // Tính giá vốn hoàn lại (snapshot lúc bán) — ƯU TIÊN invoice_item.cost_price
                $restoredCostPerUnit = 0.0;
                if ($invoiceItem) {
                    $restoredCostPerUnit = (float) $invoiceItem->cost_price;
                } else {
                    // Không có thông tin gốc — fallback dùng cost hiện tại
                    $restoredCostPerUnit = (float) $product->cost_price;
                }

                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'invoice_item_id' => $invoiceItem?->id,
                    'quantity' => $qty,
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'import_price' => $item['price'],
                    'cost_price' => $restoredCostPerUnit,
                ]);

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
                        'moved_at' => $return->return_date ?? now(),
                        'note' => 'Khách trả hàng phiếu ' . $return->code,
                    ]
                );
            }
            if (!empty($validated['customer_id'])) {
                $customer = \App\Models\Customer::find($validated['customer_id']);
                if ($customer) {
                    // Trả hàng luôn giảm nợ KH. debt có thể âm = ta nợ KH (KiotViet style)
                    $customer->decrement('debt_amount', $validated['total']);
                    $customer->decrement('total_spent', $validated['total']);
                }
            }

            // Record cash flow with correct field names matching CashFlow $fillable
            $customer = !empty($validated['customer_id']) ? \App\Models\Customer::find($validated['customer_id']) : null;
            if ($return->paid_to_customer > 0) {
                CashFlow::create([
                    'code' => 'PC' . date('YmdHis') . rand(10, 99),
                    'type' => 'payment',
                    'amount' => $return->paid_to_customer,
                    'time' => now(),
                    'category' => 'Chi tiền trả hàng khách',
                    'target_type' => 'Khách hàng',
                    'target_id' => $return->customer_id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'OrderReturn',
                    'reference_code' => $return->code,
                    'payment_method' => 'cash',
                    'description' => "Chi trả hàng khách cho phiếu {$return->code}" . ($customer ? " - {$customer->name}" : ''),
                ]);
            }

            // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ

            // Cho phép chọn ngày trả hàng (kế toán nhập sau)
            if (request()->filled('order_date')) {
                $returnDate = \Carbon\Carbon::parse(request()->order_date);

                // Validate: ngày trả hàng không được trước ngày hóa đơn gốc
                if (!empty($validated['invoice_id'])) {
                    $invoice = \App\Models\Invoice::find($validated['invoice_id']);
                    if ($invoice && $returnDate->lt($invoice->created_at)) {
                        throw new \Exception("Ngày trả hàng không thể trước ngày hóa đơn gốc (" . $invoice->created_at->format('d/m/Y H:i') . ").");
                    }
                }

                $return->update(['created_at' => $returnDate]);
            }
        });

        return redirect()->route('returns.index')->with('success', 'Phiếu trả hàng đã được tạo thành công.');
    }

    public function export(Request $request)
    {
        $this->configureReturnFilters();

        $query = \App\Models\OrderReturn::with(['customer', 'invoice']);
        $this->applyFilters($query, $request);
        $returns = $query->get();

        return \App\Services\CsvService::export(
            ['Mã trả hàng', 'Thời gian', 'Mã hóa đơn', 'Khách hàng', 'Tổng tiền trả', 'Đã trả khách', 'Trạng thái', 'Ghi chú'],
            $returns->map(fn($r) => [$r->code, $r->created_at?->format('d/m/Y H:i'), $r->invoice?->code, $r->customer?->name, $r->total, $r->paid_to_customer, $r->status, $r->note]),
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
        if ($return->status === 'Đã hủy') {
            return back()->with('error', 'Phiếu trả hàng đã bị hủy trước đó.');
        }

        DB::transaction(function () use ($return) {
            $return->load('items.product');

            // 1. Rollback stock: trừ lại tồn kho đã cộng (đảo ngược applySaleReturn)
            foreach ($return->items as $item) {
                if ($item->product) {
                    $unitCost = (float) ($item->cost_price ?: $item->product->cost_price ?? 0);

                    // BQ DI ĐỘNG: rút khỏi tồn ở chính cost lúc trả hàng (đảo ngược applySaleReturn)
                    \App\Services\MovingAvgCostingService::applyPurchaseReturn(
                        $item->product,
                        (int) $item->quantity,
                        $unitCost
                    );

                    // Rollback serials: set back to sold if linked to invoice
                    if ($item->product->has_serial && $return->invoice_id) {
                        SerialImei::where('product_id', $item->product_id)
                            ->where('status', 'in_stock')
                            ->whereNull('invoice_id')
                            ->limit($item->quantity)
                            ->update([
                                'status' => 'sold',
                                'sold_at' => now(),
                                'invoice_id' => $return->invoice_id,
                            ]);
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
                            'note' => 'Hủy phiếu trả hàng ' . $return->code,
                        ]
                    );
                }
            }

            // 2. Rollback customer debt & total_spent
            if ($return->customer_id) {
                $customer = \App\Models\Customer::find($return->customer_id);
                if ($customer) {
                    $customer->increment('debt_amount', $return->total);
                    $customer->increment('total_spent', $return->total);
                }
            }

            // 3. Cancel related CashFlow
            CashFlow::where('reference_type', 'OrderReturn')
                ->where('reference_code', $return->code)
                ->delete();

            // 4. Mark return as cancelled
            $return->update(['status' => 'Đã hủy']);
        });

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Đã hủy phiếu trả hàng.']);
        }

        return back()->with('success', 'Đã hủy phiếu trả hàng ' . $return->code);
    }
}
