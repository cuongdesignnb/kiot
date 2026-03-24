<?php

namespace App\Http\Controllers;

use App\Models\OrderReturn;
use App\Models\Setting;
use Illuminate\Http\Request;
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
                $allowed = ['code', 'created_at', 'total', 'paid_to_customer', 'status'];
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
                'code' => 'TH' . time(),
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
                'created_by_name' => 'Admin',
            ]);

            foreach ($validated['items'] as $item) {
                $return->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'import_price' => $item['price'], // Simple assumption for now
                ]);

                // Update stock
                $product = \App\Models\Product::find($item['product_id']);
                if ($product) {
                    $product->increment('stock_quantity', $item['qty']);
                }
            }

            // Record cash flow (Expense/Payment to customer)
            if ($return->paid_to_customer > 0) {
                \App\Models\CashFlow::create([
                    'code' => 'PC' . time(),
                    'type' => 'payment',
                    'amount' => $return->paid_to_customer,
                    'method' => 'Tiền mặt',
                    'description' => "Chi trả hàng khách cho phiếu {$return->code}",
                    'partner_type' => 'customer',
                    'partner_id' => $return->customer_id,
                ]);
            }

            // Cho phép chọn ngày trả hàng (kế toán nhập sau)
            if (request()->filled('order_date')) {
                $return->update(['created_at' => \Carbon\Carbon::parse(request()->order_date)]);
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
