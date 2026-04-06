<?php

namespace App\Http\Controllers;

use App\Models\OrderReturn;
use App\Models\Setting;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Services\DebtOffsetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderReturnController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $returns = OrderReturn::with(['items.product', 'customer', 'invoice'])
            ->when($search, function ($query, $search) {
                return $query->where('code', 'LIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%")
                            ->orWhere('code', 'LIKE', "%{$search}%");
                    });
            })
            ->when($request->filled('sort_by'), function ($query) use ($request) {
                $allowed = ['code', 'created_at', 'subtotal', 'total', 'paid_to_customer', 'status'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $query->orderBy($sortBy, $dir);
            }, function ($query) {
                $query->orderBy('created_at', 'desc');
            })
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Returns/Index', [
            'returns' => $returns,
            'branches' => \App\Models\Branch::all(),
            'filters' => ['search' => $search, 'sort_by' => $request->sort_by, 'sort_direction' => $request->sort_direction]
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

            foreach ($validated['items'] as $item) {
                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'import_price' => $item['price'],
                ]);

                // Restore stock
                $product = \App\Models\Product::find($item['product_id']);
                if ($product) {
                    $product->increment('stock_quantity', $item['qty']);

                    // Restore serials back to in_stock if product has serial
                    if ($product->has_serial && !empty($validated['invoice_id'])) {
                        // Find serials that were sold with this invoice for this product, limit to qty returned
                        SerialImei::where('invoice_id', $validated['invoice_id'])
                            ->where('product_id', $product->id)
                            ->where('status', 'sold')
                            ->limit($item['qty'])
                            ->update([
                                'status' => 'in_stock',
                                'sold_at' => null,
                                'invoice_id' => null,
                            ]);
                    }
                }
            }

            // Reverse customer debt & total_spent
            if (!empty($validated['customer_id'])) {
                $customer = \App\Models\Customer::find($validated['customer_id']);
                if ($customer) {
                    $customer->decrement('debt_amount', min($validated['total'], $customer->debt_amount));
                    $customer->decrement('total_spent', min($validated['total'], $customer->total_spent));
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

            // Auto offset customer↔supplier debt
            if ($customer) {
                DebtOffsetService::offsetDebts($customer);
            }

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
        $returns = \App\Models\OrderReturn::with(['customer', 'invoice'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

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
}
