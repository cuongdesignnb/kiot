<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Damage;
use App\Models\DamageItem;
use App\Models\Product;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DamageController extends Controller
{
    public function index(Request $request)
    {
        $query = Damage::with(['items.product', 'branch'])
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'created_at', 'total_qty', 'total_value', 'status'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'id';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->orderBy('id', 'desc');
            });

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

        if ($request->filled('destroyed_by_name')) {
            $query->where('destroyed_by_name', 'like', "%{$request->destroyed_by_name}%");
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
                    // Just an example for the UI mock
                    $query->whereYear('created_at', Carbon::now()->subYear()->year);
                    break;
            }
        }

        $damages = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('Damages/Index', [
            'damages' => $damages,
            'branches' => $branches,
            'filters' => array_merge($request->only([
                'search',
                'status',
                'branch_id',
                'created_by_name',
                'destroyed_by_name',
                'date_filter'
            ]), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ])
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();
        $defaultBranch = Branch::first();

        return Inertia::render('Damages/Create', [
            'products' => $products,
            'branches' => $branches,
            'defaultBranchId' => $defaultBranch ? $defaultBranch->id : null,
            'damageCode' => 'XH' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:1',
            'status' => 'required|in:draft,completed',
            'branch_id' => 'required|exists:branches,id',
            'note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $damage = Damage::create([
                'code' => $request->code ?? 'XH' . time(),
                'branch_id' => $request->branch_id,
                'status' => $request->status,
                'created_by_name' => 'Trần Văn Tiến', // hardcoded cho demo
                'destroyed_by_name' => collect($request->items)->sum('qty') > 0 ? 'Trần Văn Tiến' : 'Chưa có',
                'destroyed_date' => clone Carbon::now(), // default if empty
                'note' => $request->note,
                'total_qty' => array_sum(array_column($request->items, 'qty')),
                'total_value' => array_sum(array_column($request->items, 'total_value')),
            ]);

            if ($request->filled('action_date')) {
                $damage->created_at = Carbon::parse($request->action_date);
                $damage->destroyed_date = Carbon::parse($request->action_date);
                $damage->save();
            }

            foreach ($request->items as $item) {
                // Lấy sản phẩm để trừ kho nếu không phải là draft (tạm thời)
                // Theo KiotViet, khi lưu phiếu tạm thì kho có thể chưa trừ (hoặc chỉ pending),
                // Nhưng khi "Hoàn thành" thì chắc chắn trừ kho.

                DamageItem::create([
                    'damage_id' => $damage->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'cost_price' => $item['cost_price'],
                    'total_value' => $item['total_value'],
                    'note' => $item['note'] ?? null
                ]);

                if ($request->status === 'completed') {
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        $product->stock_quantity -= $item['qty'];
                        // Prevent negative stock for simple prototype
                        if ($product->stock_quantity < 0) {
                            $product->stock_quantity = 0;
                        }
                        $product->save();
                    }
                }
            }

            DB::commit();

            return redirect()->route('damages.index')->with('success', 'Tạo phiếu xuất hủy thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $damages = \App\Models\Damage::with('branch')
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã xuất hủy', 'Chi nhánh', 'Người tạo', 'Người hủy', 'Ngày hủy', 'Tổng SL', 'Tổng giá trị', 'Trạng thái', 'Ghi chú'],
            $damages->map(fn($d) => [$d->code, $d->branch?->name, $d->created_by_name, $d->destroyed_by_name, $d->destroyed_date, $d->total_qty, $d->total_value, $d->status, $d->note]),
            'xuat_huy.csv'
        );
    }

    public function print(\App\Models\Damage $damage)
    {
        $damage->load(['items.product', 'branch']);
        return view('prints.damage', compact('damage'));
    }
}