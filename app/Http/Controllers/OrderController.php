<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PriceBook;
use App\Models\Setting;
use App\Enums\OrderStatus;
use App\Support\Filters\FilterableIndex;
use App\Services\LockPeriodService;
use Carbon\Carbon;

class OrderController extends Controller
{
    use FilterableIndex;

    protected function configureOrderFilters(): void
    {
        $this->searchable = ['code', 'note', 'tracking_code', 'receiver_name', 'receiver_phone', 'created_by_name', 'assigned_to_name'];
        $this->searchableRelations = [
            'customer'      => ['name', 'code', 'phone'],
            'items.product' => ['name', 'code', 'barcode'],
        ];
        $this->sortable = ['code', 'created_at', 'total_payment', 'amount_paid', 'status'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = 'created_by';
        $this->scalarFilters = [
            'branch_id', 'customer_id',
            'is_delivery', 'delivery_partner',
            'sales_channel', 'price_table_id', 'promotion_id',
        ];
    }

    public function index(Request $request)
    {
        $this->configureOrderFilters();

        $query = Order::with(['customer', 'branch', 'items.product'])
            ->when($request->filled('has_debt'), function ($q) use ($request) {
                if ((string) $request->input('has_debt') === '1') {
                    $q->whereColumn('total_payment', '>', 'amount_paid');
                } else {
                    $q->whereColumn('total_payment', '<=', 'amount_paid');
                }
            });

        $this->applyFilters($query, $request);

        $orders = $query->paginate(15)->withQueryString();

        $filters = $this->currentFilters($request);
        $filters['has_debt'] = $request->input('has_debt', '');

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'branches' => Branch::all(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'filterOptions' => [
                'branches' => Branch::select('id', 'name')->get(),
                'statuses' => OrderStatus::options(),
                'employees' => Employee::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
                'creators' => \App\Models\User::select('id', 'name')->orderBy('name')->get(),
                'salesChannels' => Order::query()
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
            ],
        ]);
    }

    public function create(Request $request)
    {
        if (!Setting::get('order_enabled', true)) {
            return redirect()->route('orders.index')->with('error', 'Chức năng đặt hàng đã bị tắt trong thiết lập.');
        }

        $invoice = null;
        if ($request->filled('invoice_id')) {
            $invoice = \App\Models\Invoice::with(['items.product', 'customer'])->find($request->invoice_id);
        }

        return Inertia::render('Orders/Create', [
            'branches' => Branch::all(),
            'customers' => Customer::all(),
            'priceBooks' => PriceBook::query()
                ->where(function ($q) {
                    $q->where('is_active', true)
                        ->orWhere('status', 'active');
                })
                ->orderBy('name')
                ->get(['id', 'name']),
            'invoice' => $invoice,
            'action' => $request->input('action', 'edit'),
            'confirmBeforeComplete' => Setting::get('order_confirm_before_complete', false),
            'allowOutOfStock' => Setting::get('order_allow_when_out_of_stock', true),
        ]);
    }

    public function store(Request $request)
    {
        if (!Setting::get('order_enabled', true)) {
            return back()->with('error', 'Chức năng đặt hàng đã bị tắt trong thiết lập.');
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'required|string',
            'total_price' => 'numeric',
            'discount' => 'numeric',
            'other_fees' => 'numeric',
            'total_payment' => 'numeric',
            'amount_paid' => 'numeric',
            'note' => 'nullable|string',
            'created_by_name' => 'nullable|string',
            'assigned_to_name' => 'nullable|string',
            'price_book_id' => 'nullable|exists:price_books,id',
            'price_book_name' => 'nullable|string|max:255',

            // Items
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'numeric',

            // Delivery
            'is_delivery' => 'boolean',
            'delivery_partner' => 'nullable|string',
            'receiver_name' => 'nullable|string',
            'receiver_phone' => 'nullable|string',
            'receiver_address' => 'nullable|string',
            'receiver_ward' => 'nullable|string',
            'receiver_district' => 'nullable|string',
            'receiver_city' => 'nullable|string',
            'weight' => 'numeric|nullable',
            'delivery_fee' => 'numeric|nullable',
            'cod_amount' => 'numeric|nullable',
        ]);

        // Lock period check
        $txDate = $request->order_date ? Carbon::parse($request->order_date) : now();
        app(LockPeriodService::class)->assertNotLocked($txDate, 'order_create');

        $priceBookName = 'Bảng giá chung';
        if (!empty($validated['price_book_id'])) {
            $priceBook = PriceBook::find($validated['price_book_id']);
            if ($priceBook) {
                $priceBookName = $priceBook->name;
            }
        } elseif (!empty($validated['price_book_name'])) {
            $priceBookName = $validated['price_book_name'];
        }

        $order = Order::create([
            'code' => 'DH' . time(), // Simple unique code for now
            'customer_id' => $validated['customer_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'total_price' => $validated['total_price'] ?? 0,
            'discount' => $validated['discount'] ?? 0,
            'other_fees' => $validated['other_fees'] ?? 0,
            'total_payment' => $validated['total_payment'] ?? 0,
            'amount_paid' => $validated['amount_paid'] ?? 0,
            'note' => $validated['note'] ?? null,
            'created_by_name' => $validated['created_by_name'] ?? auth()->user()?->name,
            'assigned_to_name' => $validated['assigned_to_name'] ?? auth()->user()?->name,
            'price_book_name' => $priceBookName,

            'is_delivery' => $validated['is_delivery'] ?? false,
            'delivery_partner' => $validated['delivery_partner'] ?? null,
            'receiver_name' => $validated['receiver_name'] ?? null,
            'receiver_phone' => $validated['receiver_phone'] ?? null,
            'receiver_address' => $validated['receiver_address'] ?? null,
            'receiver_ward' => $validated['receiver_ward'] ?? null,
            'receiver_district' => $validated['receiver_district'] ?? null,
            'receiver_city' => $validated['receiver_city'] ?? null,
            'weight' => $validated['weight'] ?? 0,
            'delivery_fee' => $validated['delivery_fee'] ?? 0,
            'cod_amount' => $validated['cod_amount'] ?? 0,
        ]);

        // Cho phép chọn ngày (kế toán nhập sau)
        if ($request->filled('order_date')) {
            $orderDate = Carbon::parse($request->order_date);

            // Validate: không được đặt hàng trước ngày nhập hàng đầu tiên
            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if ($product) {
                    $earliestImport = $product->getEarliestImportDate();
                    if ($earliestImport && $orderDate->lt($earliestImport)) {
                        return back()->withErrors([
                            'items' => "Không thể đặt hàng sản phẩm '{$product->name}' trước ngày nhập hàng đầu tiên (" . $earliestImport->format('d/m/Y H:i') . ")."
                        ])->withInput();
                    }
                }
            }

            $order->update(['created_at' => $orderDate]);
        }

        foreach ($validated['items'] as $item) {
            $subtotal = ($item['qty'] * $item['price']) - ($item['discount'] ?? 0);
            $order->items()->create([
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'price' => $item['price'],
                'discount' => $item['discount'] ?? 0,
                'subtotal' => $subtotal,
            ]);
        }

        ActivityLog::log('order_create', "Tạo đơn hàng {$order->code}, tổng: " . number_format($order->total_payment), $order);

        if ($request->boolean('_print') || $request->wantsJson()) {
            return response()->json(['id' => $order->id, 'code' => $order->code]);
        }

        return redirect()->route('orders.index')->with('success', 'Tạo đơn đặt hàng thành công');
    }

    public function update(Request $request, Order $order)
    {
        if (Setting::get('block_change_transaction_time', false) && $request->has('created_at')) {
            return back()->with('error', 'Không được phép thay đổi thời gian giao dịch.');
        }

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return back()->with('error', 'Không thể sửa đơn hàng đã hoàn thành hoặc đã hủy.');
        }

        $validated = $request->validate([
            'assigned_to_name' => 'nullable|string',
            'sales_channel' => 'nullable|string',
            'status' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.qty' => 'required_with:items|numeric|min:1',
            'items.*.price' => 'required_with:items|numeric',
            'items.*.discount' => 'numeric',
            'total_price' => 'nullable|numeric',
            'discount' => 'nullable|numeric',
            'other_fees' => 'nullable|numeric',
            'total_payment' => 'nullable|numeric',
            'amount_paid' => 'nullable|numeric',
            'note' => 'nullable|string',
        ]);

        // Update items if provided
        if ($request->has('items')) {
            $order->items()->delete();
            foreach ($validated['items'] as $item) {
                $subtotal = ($item['qty'] * $item['price']) - ($item['discount'] ?? 0);
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => $subtotal,
                ]);
            }
        }

        $order->update(array_filter($validated, fn($v) => $v !== null));

        ActivityLog::log('order_update', "Cập nhật đơn hàng {$order->code}", $order);

        return back()->with('success', 'Cập nhật đơn hàng thành công');
    }

    public function print(Order $order)
    {
        $order->load(['items.product', 'customer', 'branch']);
        return view('prints.order', compact('order'));
    }

    public function export(Request $request)
    {
        $this->configureOrderFilters();

        $query = Order::with(['customer', 'branch']);
        $this->applyFilters($query, $request);
        $orders = $query->get();

        return \App\Services\CsvService::export(
            ['Mã đơn hàng', 'Thời gian', 'Khách hàng', 'Chi nhánh', 'Tổng cộng', 'Khách đã trả', 'Còn nợ', 'Trạng thái', 'Ghi chú'],
            $orders->map(fn($o) => [$o->code, $o->created_at?->format('d/m/Y H:i'), $o->customer?->name, $o->branch?->name, $o->total_payment, $o->amount_paid, $o->total_payment - ($o->amount_paid ?? 0), $o->status, $o->note]),
            'don_hang.csv'
        );
    }

    /**
     * Xử lý đơn hàng — Chuyển Order (Phiếu tạm) → Invoice (Hóa đơn).
     * Trừ kho, tính công nợ, tạo CashFlow.
     * Prior deposit (order.amount_paid) is factored in.
     */
    public function processOrder(Request $request, Order $order)
    {
        if ($order->status === 'completed') {
            return back()->with('error', 'Đơn hàng đã được xử lý trước đó.');
        }

        if ($order->status === 'cancelled' || $order->status === 'ended') {
            return back()->with('error', 'Đơn hàng đã bị hủy hoặc kết thúc.');
        }

        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
        ]);

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            $order->load('items.product', 'customer');
            $customer = $order->customer;
            $newPayment = $validated['amount_paid']; // Additional payment at conversion
            $priorDeposit = $order->amount_paid ?? 0;
            $totalPaid = $priorDeposit + $newPayment;
            $paymentMethod = $validated['payment_method'] ?? 'cash';

            // 1) Create Invoice from Order — link via order_id
            $invoice = \App\Models\Invoice::create([
                'code' => 'HD' . time() . rand(10, 99),
                'order_id' => $order->id,
                'subtotal' => $order->total_price,
                'discount' => $order->discount,
                'total' => $order->total_payment,
                'customer_paid' => $totalPaid,
                'customer_id' => $customer?->id,
                'created_by_name' => $order->created_by_name,
                'seller_name' => $order->assigned_to_name,
                'sales_channel' => $order->sales_channel ?? 'Bán trực tiếp',
                'price_book_name' => $order->price_book_name,
                'payment_method' => $paymentMethod,
                'note' => 'Từ đơn hàng ' . $order->code,
                'status' => 'Hoàn thành',
            ]);

            // 2) Create Invoice Items + Deduct stock
            foreach ($order->items as $orderItem) {
                $product = $orderItem->product;

                if ($product) {
                    $product = Product::lockForUpdate()->find($product->id);
                    $allowOversell = Setting::get('inventory_allow_oversell', true);

                    // Serial products: check serial availability
                    if ($product->has_serial) {
                        $inStockSerials = \App\Models\SerialImei::where('product_id', $product->id)
                            ->where('status', 'in_stock')
                            ->count();
                        if (!$allowOversell && $inStockSerials < $orderItem->qty) {
                            throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ Serial tồn kho (Còn: {$inStockSerials})");
                        }
                    } elseif (!$allowOversell && $product->stock_quantity < $orderItem->qty) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }
                    $product->stock_quantity -= $orderItem->qty;
                    $product->save();
                }

                $invoice->items()->create([
                    'product_id' => $orderItem->product_id,
                    'quantity' => $orderItem->qty,
                    'price' => $orderItem->price,
                    'cost_price' => $product->cost_price ?? 0,
                ]);
            }

            // 3) Customer debt tracking — debt = total - totalPaid
            $debtAmount = $order->total_payment - $totalPaid;
            if ($customer) {
                if ($debtAmount != 0) {
                    $customer->increment('debt_amount', $debtAmount);
                }
                $customer->increment('total_spent', $order->total_payment);
            }

            // 4) CashFlow for the NEW payment at conversion time (deposit was already recorded)
            if ($newPayment > 0) {
                \App\Models\CashFlow::create([
                    'code' => 'PT' . time() . rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $newPayment,
                    'time' => now(),
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $paymentMethod,
                    'description' => 'Xử lý đơn ' . $order->code . ' → HD ' . $invoice->code,
                ]);
            }

            // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ

            // 6) Update Order status
            $order->update([
                'status' => 'completed',
                'amount_paid' => $totalPaid,
            ]);

            ActivityLog::log('order_convert', "Chuyển đơn {$order->code} → hóa đơn {$invoice->code}", $order);

            \Illuminate\Support\Facades\DB::commit();

            return back()->with('success', "Xử lý thành công! Hóa đơn {$invoice->code} đã được tạo.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }

    /**
     * Hủy đơn hàng.
     */
    public function cancel(Request $request, Order $order)
    {
        if ($order->status === 'completed') {
            return back()->with('error', 'Không thể hủy đơn hàng đã hoàn thành.');
        }
        if ($order->status === 'cancelled') {
            return back()->with('error', 'Đơn hàng đã bị hủy trước đó.');
        }

        $order->update([
            'status' => 'cancelled',
            'note' => ($order->note ? $order->note . ' | ' : '') . 'Hủy: ' . ($request->reason ?? ''),
        ]);

        ActivityLog::log('order_cancel', "Hủy đơn hàng {$order->code}", $order);

        return back()->with('success', 'Đã hủy đơn hàng.');
    }

    /**
     * Kết thúc đơn hàng (đóng mà không chuyển hóa đơn).
     */
    public function endOrder(Request $request, Order $order)
    {
        if (in_array($order->status, ['completed', 'cancelled', 'ended'])) {
            return back()->with('error', 'Đơn hàng không ở trạng thái có thể kết thúc.');
        }

        $order->update([
            'status' => 'ended',
            'note' => ($order->note ? $order->note . ' | ' : '') . 'Kết thúc: ' . ($request->reason ?? ''),
        ]);

        ActivityLog::log('order_end', "Kết thúc đơn hàng {$order->code}", $order);

        return back()->with('success', 'Đã kết thúc đơn hàng.');
    }

    /**
     * Merge compatible orders — same customer, same branch, draft/confirmed states.
     */
    public function merge(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:2',
            'order_ids.*' => 'required|exists:orders,id',
        ]);

        $orders = Order::with('items')->whereIn('id', $request->order_ids)->get();

        // Validate compatibility
        $customers = $orders->pluck('customer_id')->unique();
        if ($customers->count() > 1) {
            return back()->with('error', 'Không thể gộp: các đơn hàng phải cùng khách hàng.');
        }

        $branches = $orders->pluck('branch_id')->unique();
        if ($branches->count() > 1) {
            return back()->with('error', 'Không thể gộp: các đơn hàng phải cùng chi nhánh.');
        }

        $invalidStates = $orders->filter(fn($o) => !in_array($o->status, ['draft', 'confirmed']));
        if ($invalidStates->isNotEmpty()) {
            return back()->with('error', 'Không thể gộp: tất cả đơn hàng phải ở trạng thái nháp hoặc đã xác nhận.');
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // Create merged order
            $totalPrice = $orders->sum('total_price');
            $totalDiscount = $orders->sum('discount');
            $totalOther = $orders->sum('other_fees');
            $totalPayment = $totalPrice - $totalDiscount + $totalOther;
            $totalDeposit = $orders->sum('amount_paid');

            $merged = Order::create([
                'code' => 'DH' . time() . 'M',
                'customer_id' => $customers->first(),
                'branch_id' => $branches->first(),
                'status' => 'draft',
                'total_price' => $totalPrice,
                'discount' => $totalDiscount,
                'other_fees' => $totalOther,
                'total_payment' => $totalPayment,
                'amount_paid' => $totalDeposit,
                'note' => 'Gộp từ: ' . $orders->pluck('code')->join(', '),
                'created_by_name' => auth()->user()?->name,
                'assigned_to_name' => $orders->first()->assigned_to_name,
                'price_book_name' => $orders->first()->price_book_name,
            ]);

            // Copy all items
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $merged->items()->create([
                        'product_id' => $item->product_id,
                        'qty' => $item->qty,
                        'price' => $item->price,
                        'discount' => $item->discount,
                        'subtotal' => $item->subtotal,
                    ]);
                }
                // Cancel source orders
                $order->update([
                    'status' => 'cancelled',
                    'note' => ($order->note ? $order->note . ' | ' : '') . 'Đã gộp vào ' . $merged->code,
                ]);
            }

            ActivityLog::log('order_merge', "Gộp đơn hàng: " . $orders->pluck('code')->join(', ') . " → {$merged->code}", $merged);

            \Illuminate\Support\Facades\DB::commit();

            return back()->with('success', "Đã gộp thành đơn hàng {$merged->code}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }
}
