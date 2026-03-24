<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PriceBook;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
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
            ->when($request->filled('sort_by'), function ($query) use ($request) {
                $allowed = ['code', 'created_at', 'subtotal', 'discount', 'total', 'customer_paid'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $query->orderBy($sortBy, $dir);
            }, function ($query) {
                $query->orderBy('created_at', 'desc');
            })
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'branches' => \App\Models\Branch::all(),
            'filters' => ['search' => $search, 'sort_by' => $request->sort_by, 'sort_direction' => $request->sort_direction]
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

        // Check stock if setting disallows out-of-stock transactions
        if (!Setting::get('allow_transaction_when_out_of_stock', true)) {
            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::find($item['product_id']);
                if ($product && $product->stock_quantity < $item['quantity']) {
                    return back()->withErrors(['items' => "Sản phẩm '{$product->name}' không đủ tồn kho (còn {$product->stock_quantity})."])->withInput();
                }
            }
        }

        $invoice = Invoice::create([
            'code' => 'HD' . str_pad(Invoice::max('id') + 1, 6, '0', STR_PAD_LEFT),
            'customer_id' => $validated['customer_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => 'Hoàn thành',
            'subtotal' => $validated['subtotal'],
            'discount' => $validated['discount'] ?? 0,
            'total' => $validated['total'],
            'customer_paid' => $validated['customer_paid'] ?? 0,
            'note' => $validated['note'] ?? null,
            'created_by_name' => 'Admin', // Placeholder
            'is_delivery' => $validated['is_delivery'] ?? false,
            'delivery_partner' => $validated['delivery_partner'] ?? null,
            'delivery_fee' => $validated['delivery_fee'] ?? 0,
            'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
            'price_book_name' => $priceBookName,
        ]);

        foreach ($validated['items'] as $item) {
            $invoice->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'discount' => $item['discount'] ?? 0,
                'subtotal' => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
                'note' => $item['note'] ?? null,
            ]);
        }

        return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được tạo thành công.');
    }

    public function destroy(Invoice $invoice)
    {
        // Block cancel if e-invoice issued
        if (Setting::get('block_edit_cancel_einvoice', false) && !empty($invoice->einvoice_code)) {
            return back()->with('error', 'Không thể hủy hóa đơn đã xuất hóa đơn điện tử.');
        }

        $orderChangeTime = Setting::get('order_change_time', 24); // hours
        $createdTime = $invoice->created_at;
        $now = now();

        $diffHours = $createdTime->diffInHours($now);

        if ($diffHours > $orderChangeTime) {
            return back()->with('error', "Đã quá thời gian cho phép chỉnh sửa/hủy hóa đơn ({$orderChangeTime} giờ).");
        }

        // Logic to restore stock would go here if needed...
        $invoice->delete();

        return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được hủy thành công.');
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['items.product', 'customer']);

        // Công nợ cũ: nợ hiện tại của khách trừ đi nợ phát sinh từ hóa đơn này
        $previousDebt = 0;
        if ($invoice->customer) {
            $currentDebt = $invoice->customer->debt_amount ?? 0;
            $invoiceDebt = max(0, $invoice->total - ($invoice->customer_paid ?? 0));
            $previousDebt = $currentDebt - $invoiceDebt;
        }

        return view('prints.invoice', compact('invoice', 'previousDebt'));
    }

    public function paymentHistory(Invoice $invoice)
    {
        $payments = \App\Models\CashFlow::where('target_type', 'Hóa đơn')
            ->where('target_id', $invoice->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'created_at', 'amount', 'note', 'payment_method']);

        // If no CashFlow records, construct from the invoice itself
        if ($payments->isEmpty() && $invoice->customer_paid > 0) {
            $payments = collect([[
                'id' => $invoice->id,
                'code' => $invoice->code,
                'created_at' => $invoice->created_at,
                'amount' => $invoice->customer_paid,
                'method' => 'Tiền mặt',
                'note' => 'Thanh toán khi tạo hóa đơn',
            ]]);
            return response()->json(['payments' => $payments]);
        }

        return response()->json(['payments' => $payments->map(fn($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'created_at' => $p->created_at,
            'amount' => $p->amount,
            'method' => $p->payment_method ?? 'Tiền mặt',
            'note' => $p->note,
        ])]);
    }

    public function export(Request $request)
    {
        $invoices = \App\Models\Invoice::with(['customer'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã hóa đơn', 'Thời gian', 'Khách hàng', 'Tổng tiền hàng', 'Giảm giá', 'Tổng cộng', 'Khách đã trả', 'Ghi chú'],
            $invoices->map(fn($i) => [$i->code, $i->created_at?->format('d/m/Y H:i'), $i->customer?->name, $i->subtotal, $i->discount, $i->total, $i->customer_paid, $i->note]),
            'hoa_don.csv'
        );
    }
}
