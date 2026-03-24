<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Branch;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        // Seed branches if empty
        if (Branch::count() === 0) {
            Branch::insert([
                ['name' => 'Chi nhánh trung tâm', 'phone' => '0988123456'],
                ['name' => 'Chi nhánh miền Nam', 'phone' => '0912123456']
            ]);
        }

        $query = StockTransfer::with(['fromBranch', 'toBranch'])
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'created_at', 'sent_date', 'total_quantity', 'total_price', 'status'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'id';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->orderBy('id', 'desc');
            });

        if ($request->filled('search')) {
            $query->where('code', 'like', "%{$request->search}%");
        }

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->from_branch_id);
        }

        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->to_branch_id);
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        }

        $transfers = $query->paginate(20)->withQueryString();
        $branches = Branch::all();

        return Inertia::render('StockTransfers/Index', [
            'transfers' => $transfers,
            'branches' => $branches,
            'filters' => array_merge($request->only(['search', 'from_branch_id', 'to_branch_id', 'status', 'time_filter', 'time_start', 'time_end']), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ])
        ]);
    }

    public function create()
    {
        $products = Product::where('is_active', true)->get();
        $branches = Branch::all();

        return Inertia::render('StockTransfers/Create', [
            'products' => $products,
            'branches' => $branches,
            'transferCode' => 'CH' . date('YmdHis')
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_branch_id' => 'required|exists:branches,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'status' => 'required|in:draft,transferring,received',
            'note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $transfer = StockTransfer::create([
                'code' => $request->code ?? 'CH' . time(),
                'from_branch_id' => $request->from_branch_id,
                'to_branch_id' => $request->to_branch_id,
                'status' => $request->status,
                'note' => $request->note,
                'sent_date' => $request->status !== 'draft' ? Carbon::now() : null,
                'receive_date' => $request->status === 'received' ? Carbon::now() : null,
                'total_quantity' => array_sum(array_column($request->items, 'quantity')),
                'total_price' => array_sum(array_column($request->items, 'price'))
            ]);

            if ($request->filled('action_date')) {
                $transfer->created_at = Carbon::parse($request->action_date);
                if ($request->status !== 'draft') {
                    $transfer->sent_date = Carbon::parse($request->action_date);
                }
                if ($request->status === 'received') {
                    $transfer->receive_date = Carbon::parse($request->action_date);
                }
                $transfer->save();
            }

            foreach ($request->items as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'] ?? 0
                ]);

                // Real system logic for transferring stock happens here, 
                // e.g., deducting from branch A, adding to branch B.
                // But simplified for the scope.
            }

            DB::commit();

            return redirect()->route('stock-transfers.index')->with('success', 'Tạo phiếu chuyển hàng thành công.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Lỗi: ' . $e->getMessage()]);
        }
    }

    public function export(Request $request)
    {
        $transfers = \App\Models\StockTransfer::with(['fromBranch', 'toBranch'])
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã chuyển hàng', 'Chi nhánh chuyển', 'Chi nhánh nhận', 'Ngày chuyển', 'Ngày nhận', 'Tổng SL', 'Tổng giá trị', 'Trạng thái', 'Ghi chú'],
            $transfers->map(fn($t) => [$t->code, $t->fromBranch?->name, $t->toBranch?->name, $t->sent_date, $t->receive_date, $t->total_quantity, $t->total_price, $t->status, $t->note]),
            'chuyen_hang.csv'
        );
    }

    public function print(\App\Models\StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['items.product', 'fromBranch', 'toBranch']);
        return view('prints.stock_transfer', compact('stockTransfer'));
    }
}