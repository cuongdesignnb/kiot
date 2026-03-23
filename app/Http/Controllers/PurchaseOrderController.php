<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Branch;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['items.product', 'branch', 'supplier'])->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $query->where('code', 'like', "%{$request->search}%");
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('created_by_name')) {
            $query->where('created_by_name', 'like', "%{$request->created_by_name}%");
        }

        if ($request->filled('ordered_by_name')) {
            $query->where('ordered_by_name', 'like', "%{$request->ordered_by_name}%");
        }

        if ($request->filled('date_filter')) {
            switch ($request->date_filter) {
                case 'today':
                    $query->whereDate('created_at', Carbon::today());
                    break;
                case 'this_month':
                    $query->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year);
                    break;
                case 'last_year':
                    $query->whereYear('created_at', Carbon::now()->subYear()->year);
                    break;
            }
        }

        $purchaseOrders = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'branches' => $branches,
            'filters' => $request->only([
                'search',
                'status',
                'branch_id',
                'created_by_name',
                'ordered_by_name',
                'date_filter'
            ])
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();
        $defaultBranch = Branch::first();
        $suppliers = Customer::where('is_supplier', true)->get();

        return Inertia::render('PurchaseOrders/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'defaultBranchId' => $defaultBranch ? $defaultBranch->id : null,
            'purchaseOrderCode' => 'DDH' . date('YmdHis') // Đơn đặt hàng nhập
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'status' => 'required|in:draft,confirmed,partial,completed',
            'branch_id' => 'required|exists:branches,id',
            'supplier_id' => 'nullable|exists:customers,id',
            'expected_date' => 'nullable|date',
            'note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $totalAmount = array_sum(array_column($request->items, 'total_value'));
            $discount = $request->input('discount', 0);
            $importFee = $request->input('import_fee', 0);
            $otherImportFee = $request->input('other_import_fee', 0);
            $totalPayment = $totalAmount - $discount + $importFee + $otherImportFee;

            $purchaseOrder = PurchaseOrder::create([
                'code' => $request->code ?? 'DDH' . time(),
                'branch_id' => $request->branch_id,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status,
                'total_amount' => $totalAmount,
                'discount' => $discount,
                'import_fee' => $importFee,
                'other_import_fee' => $otherImportFee,
                'total_payment' => $totalPayment,
                'expected_date' => $request->expected_date,
                'note' => $request->note,
                'created_by_name' => 'Trần Văn Tiến', // mock user
                'ordered_by_name' => collect($request->items)->sum('qty') > 0 ? 'Trần Văn Tiến' : 'Chưa có',
            ]);

            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'total_value' => $item['total_value']
                ]);

                // update stock_quantity and cost_price for 'completed' purchases
                if ($request->status === 'completed') {
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        // MAC value update algorithm simplified
                        $totalCurrentValue = $product->stock_quantity * $product->cost_price;
                        $totalNewValue = $item['qty'] * $item['price'];
                        $newStock = $product->stock_quantity + $item['qty'];
                        $newCostPrice = $newStock > 0 ? ($totalCurrentValue + $totalNewValue) / $newStock : $item['price'];

                        $product->stock_quantity = $newStock;
                        $product->cost_price = $newCostPrice;
                        $product->save();
                    }
                }
            }

            DB::commit();

            // Cho phép chọn ngày đặt hàng (kế toán nhập sau)
            if ($request->filled('order_date')) {
                $purchaseOrder->update(['created_at' => \Carbon\Carbon::parse($request->order_date)]);
            }

            return redirect()->route('purchase-orders.index')->with('success', 'Tạo phiếu đặt hàng nhập thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $orders = \App\Models\PurchaseOrder::with(['supplier', 'branch'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã đặt hàng nhập', 'Thời gian', 'Nhà cung cấp', 'Chi nhánh', 'Tổng tiền', 'Giảm giá', 'Tổng thanh toán', 'Trạng thái', 'Ghi chú'],
            $orders->map(fn($o) => [$o->code, $o->created_at?->format('d/m/Y H:i'), $o->supplier?->name, $o->branch?->name, $o->total_amount, $o->discount, $o->total_payment, $o->status, $o->note]),
            'dat_hang_nhap.csv'
        );
    }

    public function print(\App\Models\PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items.product', 'branch', 'supplier']);
        return view('prints.purchase_order', compact('purchaseOrder'));
    }
}
