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
            $order->update(['created_at' => \Carbon\Carbon::parse($request->order_date)]);
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
}
