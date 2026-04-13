<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Order;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PriceBook;
use App\Models\Setting;
use Carbon\Carbon;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['customer', 'branch', 'items.product'])
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'created_at', 'total_payment', 'amount_paid', 'status'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->orderBy('created_at', 'desc');
            });

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->search . '%')
                ->orWhereHas('customer', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('phone', 'like', '%' . $request->search . '%');
                });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Apply status filter. Usually passed as an array 'status[]' = 'draft' etc.
        if ($request->has('status') && is_array($request->status)) {
            $query->whereIn('status', $request->status);
        }

        $orders = $query->paginate(15)->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'branches' => Branch::all(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => array_merge($request->only(['search', 'branch_id', 'status', 'date_filter']), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ]),
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

        $validated = $request->validate([
            'assigned_to_name' => 'nullable|string',
            'sales_channel' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        $order->update($validated);

        return back()->with('success', 'Cập nhật đơn hàng thành công');
    }

    public function print(Order $order)
    {
        $order->load(['items.product', 'customer', 'branch']);
        return view('prints.order', compact('order'));
    }

    public function export(Request $request)
    {
        $orders = \App\Models\Order::with(['customer', 'branch'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã đơn hàng', 'Thời gian', 'Khách hàng', 'Chi nhánh', 'Tổng cộng', 'Khách đã trả', 'Còn nợ', 'Trạng thái', 'Ghi chú'],
            $orders->map(fn($o) => [$o->code, $o->created_at?->format('d/m/Y H:i'), $o->customer?->name, $o->branch?->name, $o->total_payment, $o->amount_paid, $o->total_payment - ($o->amount_paid ?? 0), $o->status, $o->note]),
            'don_hang.csv'
        );
    }

    /**
     * Xử lý đơn hàng — Chuyển Order (Phiếu tạm) → Invoice (Hóa đơn).
     * Trừ kho, tính công nợ, tạo CashFlow.
     */
    public function processOrder(Request $request, Order $order)
    {
        if ($order->status === 'completed') {
            return back()->with('error', 'Đơn hàng đã được xử lý trước đó.');
        }

        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0',
            'payment_method' => 'nullable|string',
        ]);

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            $order->load('items.product', 'customer');
            $customer = $order->customer;
            $amountPaid = $validated['amount_paid'];
            $paymentMethod = $validated['payment_method'] ?? 'cash';

            // 1) Create Invoice from Order
            $invoice = \App\Models\Invoice::create([
                'code' => 'HD' . time() . rand(10, 99),
                'subtotal' => $order->total_price,
                'discount' => $order->discount,
                'total' => $order->total_payment,
                'customer_paid' => $amountPaid,
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

            // 3) Customer debt tracking
            $debtAmount = $order->total_payment - $amountPaid;
            if ($customer) {
                if ($debtAmount != 0) {
                    $customer->increment('debt_amount', $debtAmount);
                }
                $customer->increment('total_spent', $order->total_payment);
            }

            // 4) CashFlow
            if ($amountPaid > 0) {
                \App\Models\CashFlow::create([
                    'code' => 'PT' . time() . rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $amountPaid,
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
                'amount_paid' => $amountPaid,
            ]);

            \Illuminate\Support\Facades\DB::commit();

            return back()->with('success', "Xử lý thành công! Hóa đơn {$invoice->code} đã được tạo.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }
}
