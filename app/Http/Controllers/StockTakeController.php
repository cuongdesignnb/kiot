<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ActivityLog;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Branch;

class StockTakeController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTake::with('items.product')
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'created_at', 'total_actual_qty', 'total_diff_qty', 'status'];
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

        if ($request->filled('user_name')) {
            $query->where('user_name', 'like', "%{$request->user_name}%");
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
            }
        }

        $stockTakes = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('StockTakes/Index', [
            'stockTakes' => $stockTakes,
            'branches' => $branches,
            'filters' => array_merge($request->only(['search', 'status', 'user_name', 'date_filter']), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ])
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();

        return Inertia::render('StockTakes/Create', [
            'products' => $products,
            'branches' => $branches,
            'stockTakeCode' => 'KK' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'required|numeric|min:0',
            'status' => 'required|in:draft,balanced',
            'note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $stockTake = StockTake::create([
                'code' => $request->code ?? 'KK' . time(),
                'status' => $request->status,
                'user_name' => 'Trần Văn Tiến', // hardcoded for demo
                'balancer_name' => $request->status === 'balanced' ? 'Trần Văn Tiến' : null,
                'balanced_date' => $request->status === 'balanced' ? Carbon::now() : null,
                'note' => $request->note,
                'total_actual_qty' => array_sum(array_column($request->items, 'actual_stock')),
                'total_diff_qty' => array_sum(array_column($request->items, 'diff_qty')),
                'total_diff_increase' => collect($request->items)->filter(fn($i) => $i['diff_qty'] > 0)->sum('diff_qty'),
                'total_diff_decrease' => collect($request->items)->filter(fn($i) => $i['diff_qty'] < 0)->sum('diff_qty'),
                'total_diff_value' => array_sum(array_column($request->items, 'diff_value'))
            ]);

            if ($request->filled('action_date')) {
                $stockTake->created_at = Carbon::parse($request->action_date);
                if ($request->status === 'balanced') {
                    $stockTake->balanced_date = Carbon::parse($request->action_date);
                }
                $stockTake->save();
            }

            foreach ($request->items as $item) {
                StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id' => $item['product_id'],
                    'system_stock' => $item['system_stock'],
                    'actual_stock' => $item['actual_stock'],
                    'diff_qty' => $item['diff_qty'],
                    'diff_value' => $item['diff_value']
                ]);

                // Update product stock if balanced
                if ($request->status === 'balanced') {
                    Product::where('id', $item['product_id'])->update([
                        'stock_quantity' => $item['actual_stock']
                    ]);
                }
            }

            DB::commit();

            $logAction = $request->status === 'balanced' ? 'stocktake_complete' : 'stocktake_create';
            ActivityLog::log($logAction, "Tạo phiếu kiểm kho {$stockTake->code}, trạng thái: {$stockTake->status}", $stockTake);

            return redirect()->route('stock-takes.index')->with('success', 'Tạo phiếu kiểm kho thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $stockTakes = \App\Models\StockTake::query()
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã kiểm kho', 'Người kiểm', 'Người cân bằng', 'Ngày cân bằng', 'Tổng SL thực tế', 'Tổng lệch', 'Trạng thái', 'Ghi chú'],
            $stockTakes->map(fn($s) => [$s->code, $s->user_name, $s->balancer_name, $s->balanced_date, $s->total_actual_qty, $s->total_diff_qty, $s->status, $s->note]),
            'kiem_kho.csv'
        );
    }

    public function print(\App\Models\StockTake $stockTake)
    {
        $stockTake->load(['items.product']);
        return view('prints.stock_take', compact('stockTake'));
    }

    /**
     * Chi tiet phieu kiem kho.
     */
    public function show(StockTake $stockTake)
    {
        $stockTake->load(['items.product']);
        return Inertia::render('StockTakes/Show', [
            'stockTake' => $stockTake,
        ]);
    }

    /**
     * Sua draft kiem kho — chi cho phep khi status = draft.
     */
    public function update(Request $request, $id)
    {
        $stockTake = StockTake::findOrFail($id);

        if ($stockTake->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Chi co the sua phieu nhap (draft).'], 422);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.actual_stock' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Delete old items
            StockTakeItem::where('stock_take_id', $stockTake->id)->forceDelete();

            $totalActual = 0;
            $totalDiff = 0;
            $totalIncrease = 0;
            $totalDecrease = 0;
            $totalDiffValue = 0;

            foreach ($request->items as $item) {
                $systemStock = $item['system_stock'] ?? Product::find($item['product_id'])->stock_quantity ?? 0;
                $diffQty = $item['actual_stock'] - $systemStock;
                $diffValue = $item['diff_value'] ?? 0;

                StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'product_id' => $item['product_id'],
                    'system_stock' => $systemStock,
                    'actual_stock' => $item['actual_stock'],
                    'diff_qty' => $diffQty,
                    'diff_value' => $diffValue,
                ]);

                $totalActual += $item['actual_stock'];
                $totalDiff += $diffQty;
                if ($diffQty > 0) $totalIncrease += $diffQty;
                if ($diffQty < 0) $totalDecrease += $diffQty;
                $totalDiffValue += $diffValue;
            }

            $stockTake->update([
                'note' => $request->note ?? $stockTake->note,
                'total_actual_qty' => $totalActual,
                'total_diff_qty' => $totalDiff,
                'total_diff_increase' => $totalIncrease,
                'total_diff_decrease' => $totalDecrease,
                'total_diff_value' => $totalDiffValue,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Da cap nhat phieu kiem kho.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Huy phieu kiem kho — rollback stock ve system_stock.
     */
    public function cancel($id)
    {
        $stockTake = StockTake::with('items')->findOrFail($id);

        if ($stockTake->status === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Phieu da bi huy truoc do.'], 422);
        }

        if ($stockTake->status === 'draft') {
            $stockTake->update(['status' => 'cancelled']);
            return response()->json(['success' => true, 'message' => 'Da huy phieu nhap.']);
        }

        // status = balanced -> rollback stock
        try {
            DB::beginTransaction();

            foreach ($stockTake->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    // Restore to system_stock (what it was before stocktake)
                    $product->update(['stock_quantity' => $item->system_stock]);
                }
            }

            $stockTake->update(['status' => 'cancelled']);

            DB::commit();
            ActivityLog::log('stocktake_cancel', "Hủy phiếu kiểm kho {$stockTake->code}", $stockTake);
            return response()->json(['success' => true, 'message' => 'Da huy phieu kiem kho va hoan ton kho.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}