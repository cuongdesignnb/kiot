<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PriceBook;
use App\Models\Setting;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Services\DebtOffsetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

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

        // Xác định ngày giao dịch (cho phép chỉnh sửa thời gian)
        $transactionDate = $request->filled('order_date')
            ? Carbon::parse($request->order_date)
            : now();

        // Validate: không được bán sản phẩm trước ngày nhập hàng đầu tiên
        foreach ($validated['items'] as $item) {
            $product = \App\Models\Product::find($item['product_id']);
            if ($product) {
                $earliestImport = $product->getEarliestImportDate();
                if ($earliestImport && $transactionDate->lt($earliestImport)) {
                    return back()->withErrors([
                        'items' => "Không thể bán sản phẩm '{$product->name}' trước ngày nhập hàng đầu tiên (" . $earliestImport->format('d/m/Y H:i') . ")."
                    ])->withInput();
                }
            }
        }

        // Check stock if setting disallows out-of-stock transactions
        if (!Setting::get('allow_transaction_when_out_of_stock', false)) {
            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::find($item['product_id']);
                if ($product && $product->stock_quantity < $item['quantity']) {
                    return back()->withErrors(['items' => "Sản phẩm '{$product->name}' không đủ tồn kho (còn {$product->stock_quantity})."])->withInput();
                }
            }
        }

        try {
            DB::beginTransaction();

            $invoice = Invoice::create([
                'code' => 'HD' . date('YmdHis') . rand(10, 99),
                'customer_id' => $validated['customer_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => 'Hoàn thành',
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'total' => $validated['total'],
                'customer_paid' => $validated['customer_paid'] ?? 0,
                'note' => $validated['note'] ?? null,
                'created_by_name' => auth()->user()?->name ?? 'Admin',
                'is_delivery' => $validated['is_delivery'] ?? false,
                'delivery_partner' => $validated['delivery_partner'] ?? null,
                'delivery_fee' => $validated['delivery_fee'] ?? 0,
                'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
                'price_book_name' => $priceBookName,
            ]);

            // Cho phép chọn ngày giao dịch (kế toán nhập sau)
            if ($request->filled('order_date')) {
                $invoice->update(['created_at' => $transactionDate]);
            }

            $allowOversell = Setting::get('inventory_allow_oversell', false);

            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::lockForUpdate()->find($item['product_id']);
                $serialIds = $item['serial_ids'] ?? [];

                // Snapshot cost_price
                $snapshotCostPrice = (float) ($product->cost_price ?? 0);
                $serialStr = null;

                if ($product && $product->has_serial && !empty($serialIds)) {
                    $serialIds = is_array($serialIds) ? $serialIds : [$serialIds];
                    $soldSerials = SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->get();
                    if ($soldSerials->count() > 0) {
                        $totalCost = $soldSerials->sum(fn($s) => (float) ($s->cost_price ?: $product->cost_price ?? 0));
                        $snapshotCostPrice = round($totalCost / $soldSerials->count(), 2);
                    }

                    // Mark serials as sold
                    SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->update([
                            'status' => 'sold',
                            'sold_at' => now(),
                            'invoice_id' => $invoice->id,
                        ]);

                    $serialStr = $soldSerials->pluck('serial_number')->implode(', ');
                }

                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'cost_price' => $snapshotCostPrice,
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
                    'note' => $item['note'] ?? null,
                    'serial' => $serialStr,
                ]);

                // Deduct stock (with lock to prevent race conditions)
                if ($product) {
                    if (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity}). Không cho phép tồn kho âm.");
                    }
                    $product->stock_quantity -= $item['quantity'];
                    $product->save();
                }
            }

            // Customer debt & total_spent tracking
            $customer = $validated['customer_id'] ? \App\Models\Customer::find($validated['customer_id']) : null;
            $customerName = $customer ? $customer->name : 'Khách lẻ';
            $debtAmount = max(0, $validated['total'] - ($validated['customer_paid'] ?? 0));

            if ($customer) {
                if ($debtAmount > 0) {
                    $customer->increment('debt_amount', $debtAmount);
                }
                $customer->increment('total_spent', $validated['total']);
            }

            // CashFlow receipt if customer paid something
            $customerPaid = $validated['customer_paid'] ?? 0;
            if ($customerPaid > 0) {
                CashFlow::create([
                    'code' => 'PT' . date('YmdHis') . rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $customerPaid,
                    'time' => now(),
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customerName,
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'description' => 'Thu tiền hóa đơn ' . $invoice->code . ($customer ? " - {$customer->name}" : ''),
                ]);
            }

            // Auto offset customer↔supplier debt
            if ($customer) {
                DebtOffsetService::offsetDebts($customer);
            }

            DB::commit();
            return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được tạo thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
    }

    public function update(Request $request, Invoice $invoice)
    {
        // Block edit if e-invoice issued
        if (Setting::get('block_edit_cancel_einvoice', false) && !empty($invoice->einvoice_code)) {
            return back()->with('error', 'Không thể sửa hóa đơn đã xuất hóa đơn điện tử.');
        }

        $orderChangeTime = Setting::get('order_change_time', 24);
        if ($invoice->created_at->diffInHours(now()) > $orderChangeTime) {
            return back()->with('error', "Đã quá thời gian cho phép chỉnh sửa ({$orderChangeTime} giờ).");
        }

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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.price' => 'required|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Capture old values for debt diff
            $oldTotal = (float) $invoice->total;
            $oldPaid = (float) ($invoice->customer_paid ?? 0);
            $oldDebt = max(0, $oldTotal - $oldPaid);
            $oldCustomerId = $invoice->customer_id;

            // Restore stock from old items
            foreach ($invoice->items as $oldItem) {
                $product = \App\Models\Product::find($oldItem->product_id);
                if ($product) {
                    $product->increment('stock_quantity', $oldItem->quantity);
                }
            }

            // Restore old serials back to in_stock
            SerialImei::where('invoice_id', $invoice->id)
                ->where('status', 'sold')
                ->update([
                    'status' => 'in_stock',
                    'sold_at' => null,
                    'invoice_id' => null,
                ]);

            // Update invoice header
            $invoice->update([
                'customer_id' => $validated['customer_id'] ?? $invoice->customer_id,
                'branch_id' => $validated['branch_id'] ?? $invoice->branch_id,
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'] ?? 0,
                'total' => $validated['total'],
                'customer_paid' => $validated['customer_paid'] ?? 0,
                'note' => $validated['note'] ?? null,
                'is_delivery' => $validated['is_delivery'] ?? false,
                'delivery_partner' => $validated['delivery_partner'] ?? null,
                'delivery_fee' => $validated['delivery_fee'] ?? 0,
                'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
                'price_book_name' => $validated['price_book_name'] ?? $invoice->price_book_name,
            ]);

            // Delete old items and create new ones
            $invoice->items()->delete();

            $allowOversell = Setting::get('inventory_allow_oversell', true);

            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::lockForUpdate()->find($item['product_id']);
                $serialIds = $item['serial_ids'] ?? [];

                // Snapshot cost_price
                $snapshotCostPrice = (float) ($product->cost_price ?? 0);
                $serialStr = null;

                if ($product && $product->has_serial && !empty($serialIds)) {
                    $serialIds = is_array($serialIds) ? $serialIds : [$serialIds];
                    $soldSerials = SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->get();
                    if ($soldSerials->count() > 0) {
                        $totalCost = $soldSerials->sum(fn($s) => (float) ($s->cost_price ?: $product->cost_price ?? 0));
                        $snapshotCostPrice = round($totalCost / $soldSerials->count(), 2);
                    }

                    // Mark new serials as sold
                    SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->update([
                            'status' => 'sold',
                            'sold_at' => now(),
                            'invoice_id' => $invoice->id,
                        ]);

                    $serialStr = $soldSerials->pluck('serial_number')->implode(', ');
                }

                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'cost_price' => $snapshotCostPrice,
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
                    'note' => $item['note'] ?? null,
                    'serial' => $serialStr,
                ]);

                // Deduct stock for new items
                if ($product) {
                    if (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }
                    $product->decrement('stock_quantity', $item['quantity']);
                }
            }

            // Adjust customer debt
            $newTotal = (float) $validated['total'];
            $newPaid = (float) ($validated['customer_paid'] ?? 0);
            $newDebt = max(0, $newTotal - $newPaid);
            $newCustomerId = $validated['customer_id'] ?? $oldCustomerId;

            // If customer changed, reverse old customer and apply to new
            if ($oldCustomerId && $oldCustomerId != $newCustomerId) {
                $oldCustomer = \App\Models\Customer::find($oldCustomerId);
                if ($oldCustomer) {
                    $oldCustomer->decrement('debt_amount', min($oldDebt, $oldCustomer->debt_amount));
                    $oldCustomer->decrement('total_spent', min($oldTotal, $oldCustomer->total_spent));
                    DebtOffsetService::offsetDebts($oldCustomer);
                }
            }

            if ($newCustomerId) {
                $newCustomer = \App\Models\Customer::find($newCustomerId);
                if ($newCustomer) {
                    if ($oldCustomerId == $newCustomerId) {
                        // Same customer — apply diff
                        $debtDiff = $newDebt - $oldDebt;
                        $totalDiff = $newTotal - $oldTotal;
                        $newCustomer->increment('debt_amount', $debtDiff);
                        $newCustomer->increment('total_spent', $totalDiff);
                    } else {
                        // New customer — apply full new values
                        if ($newDebt > 0) {
                            $newCustomer->increment('debt_amount', $newDebt);
                        }
                        $newCustomer->increment('total_spent', $newTotal);
                    }
                    DebtOffsetService::offsetDebts($newCustomer);
                }
            }

            // Update CashFlow: delete old → create new
            CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->delete();

            if ($newPaid > 0) {
                $customer = $newCustomerId ? \App\Models\Customer::find($newCustomerId) : null;
                CashFlow::create([
                    'code' => 'PT' . date('YmdHis') . rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $newPaid,
                    'time' => now(),
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'description' => 'Thu tiền hóa đơn ' . $invoice->code . ($customer ? " - {$customer->name}" : ''),
                ]);
            }

            DB::commit();
            return redirect()->route('invoices.index')->with('success', 'Hóa đơn đã được cập nhật thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage())->withInput();
        }
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

        try {
            DB::beginTransaction();

            $invoice->load('items');

            // Restore stock & serials for each item
            foreach ($invoice->items as $item) {
                $product = \App\Models\Product::find($item->product_id);
                if ($product) {
                    $product->increment('stock_quantity', $item->quantity);
                }

                // Restore serials back to in_stock
                if ($product && $product->has_serial) {
                    SerialImei::where('invoice_id', $invoice->id)
                        ->where('product_id', $product->id)
                        ->where('status', 'sold')
                        ->update([
                            'status' => 'in_stock',
                            'sold_at' => null,
                            'invoice_id' => null,
                        ]);
                }
            }

            // Reverse customer debt & total_spent
            if ($invoice->customer_id) {
                $customer = \App\Models\Customer::find($invoice->customer_id);
                if ($customer) {
                    $debtAmount = max(0, $invoice->total - ($invoice->customer_paid ?? 0));
                    if ($debtAmount > 0) {
                        $customer->decrement('debt_amount', min($debtAmount, $customer->debt_amount));
                    }
                    $customer->decrement('total_spent', min($invoice->total, $customer->total_spent));

                    // Auto offset
                    DebtOffsetService::offsetDebts($customer);
                }
            }

            // Delete related CashFlow entries
            CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->delete();

            $invoice->delete();

            DB::commit();
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
                    'product_code' => $item->product->code ?? '',
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
                'product_code' => $item->product->code ?? '',
                'product_name' => $item->product->name ?? '',
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount ?? 0,
                'subtotal' => $item->subtotal,
            ]),
        ]);
    }
}
