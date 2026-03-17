<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashFlow;
use App\Models\SerialImei;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $statuses = $request->input('status');
        $dateFilter = $request->input('date_filter');

        $query = Purchase::with(['supplier', 'items'])->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($statuses && is_array($statuses) && count($statuses) > 0) {
            $query->whereIn('status', $statuses);
        }

        if ($dateFilter) {
            if ($dateFilter === 'this_month') {
                $query->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year);
            }
        }

        $purchases = $query->paginate(20)->withQueryString();

        // Tổng công nợ NCC (toàn bộ, không phụ thuộc filter)
        $summary = [
            'total_amount' => Purchase::sum('total_amount'),
            'total_discount' => Purchase::sum('discount'),
            'total_paid' => Purchase::sum('paid_amount'),
            'total_debt' => Purchase::sum('debt_amount'),
        ];

        return Inertia::render('Purchases/Index', [
            'purchases' => $purchases,
            'filters' => $request->only(['search', 'status', 'date_filter']),
            'summary' => $summary,
        ]);
    }

    public function create(Request $request)
    {
        $suppliers = Customer::where('is_supplier', true)->get();
        $products = Product::where('is_active', true)->get();

        $purchaseOrderInfo = null;
        if ($request->has('purchase_order_id')) {
            $po = \App\Models\PurchaseOrder::with('items.product')->find($request->purchase_order_id);
            if ($po) {
                $purchaseOrderInfo = [
                    'supplier_id' => $po->supplier_id,
                    'discount' => collect($po->items)->sum('discount') + $po->discount,
                    'items' => $po->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'name' => $item->product ? $item->product->name : '',
                            'sku' => $item->product ? $item->product->sku : '',
                            'quantity' => $item->qty,
                            'price' => $item->price,
                            'discount' => 0,
                            'stock_quantity' => $item->product ? $item->product->stock_quantity : 0,
                        ];
                    })
                ];
            }
        }

        // Check if any active price book enables retail/technician price columns
        $priceBooks = \App\Models\PriceBook::where('is_active', true)->get();
        $showRetailPrice = $priceBooks->contains('enable_retail_price', true);
        $showTechnicianPrice = $priceBooks->contains('enable_technician_price', true);

        return Inertia::render('Purchases/Create', [
            'suppliers' => $suppliers,
            'products' => $products,
            'employees' => \App\Models\Employee::where('is_active', true)->get(['id', 'name', 'code']),
            'categories' => \App\Models\Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => \App\Models\Brand::all(),
            'purchaseCode' => 'PN' . date('YmdHis'),
            'purchaseOrderInfo' => $purchaseOrderInfo,
            'showRetailPrice' => $showRetailPrice,
            'showTechnicianPrice' => $showTechnicianPrice,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:customers,id',
            'employee_id' => 'nullable|exists:employees,id',
            'purchase_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.retail_price' => 'nullable|numeric|min:0',
            'items.*.technician_price' => 'nullable|numeric|min:0',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'string|max:100',
            'items.*.warranty_months' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        // Validate serial products have quantity matching serials count
        foreach ($request->items as $i => $item) {
            $product = Product::find($item['product_id']);
            if ($product && $product->has_serial) {
                $serials = $item['serials'] ?? [];
                if (count($serials) === 0) {
                    return back()->withErrors(["items.{$i}.serials" => "S\u1ea3n ph\u1ea9m \"{$product->name}\" y\u00eau c\u1ea7u nh\u1eadp s\u1ed1 Serial/IMEI."]);
                }
                // Check for duplicates in DB
                $existing = SerialImei::whereIn('serial_number', $serials)->first();
                if ($existing) {
                    return back()->withErrors(["items.{$i}.serials" => "Serial/IMEI \"{$existing->serial_number}\" \u0111\u00e3 t\u1ed3n t\u1ea1i trong h\u1ec7 th\u1ed1ng."]);
                }
            }
        }

        try {
            DB::beginTransaction();

            $total_amount = collect($request->items)->sum(function ($item) {
                return $item['quantity'] * $item['price'] - ($item['discount'] ?? 0);
            });

            $discount = $request->discount ?? 0;
            $pay_amount = $total_amount - $discount; // Total to pay
            $paid_amount = $request->paid_amount ?? 0;
            $debt_amount = $pay_amount - $paid_amount; // Current debt for this order

            $purchase = Purchase::create([
                'code' => $request->code ?? 'PN' . time(),
                'supplier_id' => $request->supplier_id,
                'user_id' => auth()->id(),
                'employee_id' => $request->employee_id,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'paid_amount' => $paid_amount,
                'debt_amount' => $debt_amount,
                'note' => $request->note,
                'status' => $request->status ?? 'completed',
                'purchase_date' => $request->purchase_date ?? now(),
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                $warrantyMonths = $item['warranty_months'] ?? 0;
                $warrantyExpiresAt = $warrantyMonths > 0
                    ? ($purchase->purchase_date ?? now())->copy()->addMonths($warrantyMonths)->toDateString()
                    : null;

                // Add item
                $purchase->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => $item['quantity'] * $item['price'] - ($item['discount'] ?? 0),
                    'warranty_months' => $warrantyMonths,
                    'warranty_expires_at' => $warrantyExpiresAt,
                ]);

                if ($purchase->status === 'completed') {
                    $newStock = $product->stock_quantity + $item['quantity'];

                    // Update last purchase price
                    $product->last_purchase_price = $item['price'];

                    // Only update cost price if method is 'average'
                    $costingMethod = \App\Models\Setting::get('inventory_costing_method', 'average');
                    if ($costingMethod === 'average') {
                        $totalCurrentValue = $product->stock_quantity * $product->cost_price;
                        $totalNewValue = $item['quantity'] * $item['price'];
                        $newCostPrice = $newStock > 0 ? ($totalCurrentValue + $totalNewValue) / $newStock : $item['price'];
                        $product->cost_price = $newCostPrice;
                    }

                    $product->stock_quantity = $newStock;

                    // Update retail_price if provided
                    if (isset($item['retail_price']) && $item['retail_price'] > 0) {
                        $product->retail_price = $item['retail_price'];
                    }

                    $product->save();

                    // Update technician_price in active price books if provided
                    if (isset($item['technician_price']) && $item['technician_price'] > 0) {
                        $activeBooks = \App\Models\PriceBook::where('is_active', true)
                            ->where('enable_technician_price', true)->get();
                        foreach ($activeBooks as $book) {
                            \App\Models\PriceBookProduct::updateOrCreate(
                                ['price_book_id' => $book->id, 'product_id' => $product->id],
                                ['technician_price' => $item['technician_price'], 'price' => $item['retail_price'] ?? $product->retail_price ?? 0]
                            );
                        }
                    }

                    // Create Serial/IMEI records for products with serial tracking
                    if ($product->has_serial && !empty($item['serials'])) {
                        foreach ($item['serials'] as $serialNumber) {
                            SerialImei::create([
                                'product_id' => $product->id,
                                'serial_number' => trim($serialNumber),
                                'status' => 'in_stock',
                                'purchase_id' => $purchase->id,
                            ]);
                        }
                    }
                }
            }

            if ($purchase->status === 'completed') {
                // Update Supplier Debt & Total Bought
                $supplier = Customer::find($request->supplier_id);
                if ($supplier) {
                    $supplier->supplier_debt_amount += $debt_amount;
                    $supplier->total_bought += $total_amount;
                    $supplier->save();
                }

                // Create Cash Flow if paid > 0 (Chi tiền trả NCC)
                if ($paid_amount > 0) {
                    CashFlow::create([
                        'code' => 'PC' . date('YmdHis'),
                        'type' => 'payment', // chi
                        'amount' => $paid_amount,
                        'time' => now(),
                        'category' => 'Chi tiền trả NCC',
                        'target_type' => 'Nhà cung cấp',
                        'target_name' => $supplier->name ?? 'Nhà cung cấp',
                        'reference_type' => 'Purchase',
                        'reference_code' => $purchase->code,
                        'description' => 'Chi tiền trả NCC cho phiếu ' . $purchase->code
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('purchases.index')->with('success', 'Tạo đơn nhập hàng thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['supplier', 'items.product', 'user', 'employee']);

        // Fix quantity for serial products (old bug: saved as 0)
        $recalcTotal = false;
        foreach ($purchase->items as $item) {
            if ($item->product && $item->product->has_serial) {
                $serialCount = SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)->count();
                if ($item->quantity == 0 && $serialCount > 0) {
                    $item->quantity = $serialCount;
                    $item->subtotal = ($item->quantity * $item->price) - $item->discount;
                    $item->save();
                    $recalcTotal = true;
                }
            }
        }
        if ($recalcTotal) {
            $purchase->total_amount = $purchase->items->sum('subtotal');
            $purchase->debt_amount = ($purchase->total_amount - $purchase->discount) - $purchase->paid_amount;
            $purchase->save();
            $purchase->refresh();
            $purchase->load(['supplier', 'items.product', 'user', 'employee']);
        }

        // Load serials for each item (after save, to avoid dirty attributes)
        foreach ($purchase->items as $item) {
            if ($item->product && $item->product->has_serial) {
                $item->setRelation('serials', SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->get(['id', 'serial_number', 'status']));
            } else {
                $item->setRelation('serials', collect([]));
            }
        }

        // Load payment history (cash flows)
        $purchase->cash_flows = CashFlow::where('reference_code', $purchase->code)
            ->where('reference_type', 'Purchase')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase,
        ]);
    }

    public function destroy(Purchase $purchase)
    {
        if ($purchase->status !== 'completed') {
            $purchase->delete();
            return redirect()->route('purchases.index')->with('success', 'Đã xóa phiếu tạm.');
        }

        try {
            DB::beginTransaction();

            // Reverse stock changes
            foreach ($purchase->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->stock_quantity = max(0, $product->stock_quantity - $item->quantity);
                    $product->save();
                }
                // Delete serials
                SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->delete();
            }

            // Reverse supplier debt
            if ($purchase->supplier) {
                $purchase->supplier->supplier_debt_amount -= $purchase->debt_amount;
                $purchase->supplier->total_bought -= $purchase->total_amount;
                $purchase->supplier->save();
            }

            $purchase->items()->delete();
            $purchase->status = 'cancelled';
            $purchase->save();

            DB::commit();
            return redirect()->route('purchases.index')->with('success', 'Đã hủy phiếu nhập hàng.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $purchases = \App\Models\Purchase::with('supplier')
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã nhập hàng', 'Thời gian', 'Nhà cung cấp', 'Tổng cộng', 'Giảm giá', 'Đã trả NCC', 'Còn nợ NCC', 'Trạng thái', 'Ghi chú'],
            $purchases->map(fn($p) => [$p->code, $p->created_at?->format('d/m/Y H:i'), $p->supplier?->name, $p->total_amount, $p->discount, $p->paid_amount, $p->debt_amount, $p->status, $p->note]),
            'nhap_hang.csv'
        );
    }

    public function print(\App\Models\Purchase $purchase)
    {
        $purchase->load(['items.product', 'supplier']);
        return view('prints.purchase', compact('purchase'));
    }
}
