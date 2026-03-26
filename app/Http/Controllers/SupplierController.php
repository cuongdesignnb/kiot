<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $query = Customer::where('is_supplier', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('customer_group')) {
            $query->where('customer_group', $request->customer_group);
        }

        if ($request->filled('date_filter')) {
            switch ($request->date_filter) {
                case 'today':
                    $query->whereDate('created_at', now()->today());
                    break;
                case 'this_month':
                    $query->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year);
                    break;
            }
        }

        if ($request->filled('partner_type')) {
            if ($request->partner_type === 'supplier_only') {
                $query->where('is_customer', false);
            } elseif ($request->partner_type === 'both') {
                $query->where('is_customer', true);
            }
        }

        $suppliers = $query
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'name', 'phone', 'email', 'created_at'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'id';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->latest();
            })
            ->paginate(50)->withQueryString();

        // Recalculate supplier_debt_amount from actual purchase data
        $supplierIds = $suppliers->pluck('id');
        $debts = Purchase::whereIn('supplier_id', $supplierIds)
            ->where('status', 'completed')
            ->groupBy('supplier_id')
            ->select('supplier_id', DB::raw('SUM(debt_amount) as real_debt'), DB::raw('SUM(total_amount) as real_total'))
            ->pluck('real_debt', 'supplier_id');
        $totals = Purchase::whereIn('supplier_id', $supplierIds)
            ->where('status', 'completed')
            ->groupBy('supplier_id')
            ->select('supplier_id', DB::raw('SUM(total_amount) as real_total'))
            ->pluck('real_total', 'supplier_id');

        $suppliers->getCollection()->transform(function ($s) use ($debts, $totals) {
            $s->supplier_debt_amount = $debts[$s->id] ?? 0;
            $s->total_bought = $totals[$s->id] ?? 0;
            return $s;
        });

        // Summary totals
        $summary = [
            'total_debt' => Purchase::where('status', 'completed')
                ->whereHas('supplier', fn($q) => $q->where('is_supplier', true))
                ->sum('debt_amount'),
            'total_bought' => Purchase::where('status', 'completed')
                ->whereHas('supplier', fn($q) => $q->where('is_supplier', true))
                ->sum('total_amount'),
        ];

        $groups = Customer::where('is_supplier', true)->whereNotNull('customer_group')->distinct()->pluck('customer_group');

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'groups' => $groups,
            'filters' => array_merge($request->only(['search', 'customer_group', 'date_filter', 'partner_type']), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ]),
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        if ($request->filled('link_existing_id')) {
            $customer = Customer::find($request->input('link_existing_id'));
            if ($customer) {
                $customer->update(['is_supplier' => true]);
                if ($request->wantsJson()) {
                    return response()->json(['supplier' => $customer]);
                }
                return redirect()->route('suppliers.index')->with('success', 'Đã liên kết nhà cung cấp thành công.');
            }
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'customer_group' => 'nullable|string',
            'note' => 'nullable|string',
            'is_customer' => 'boolean',
        ]);

        if (empty($validated['code'])) {
            $validated['code'] = 'NCC' . time() . rand(10, 99);
        }

        $validated['is_supplier'] = true;
        // If the toggle 'is_customer' is false, it means they are only a supplier.
        $validated['is_customer'] = $request->input('is_customer', false);

        $customer = Customer::create($validated);

        if ($request->wantsJson()) {
            return response()->json(['supplier' => $customer]);
        }

        return redirect()->route('suppliers.index')->with('success', 'Tạo nhà cung cấp thành công.');
    }

    public function quickStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => 'nullable|string|max:255',
            'phone2' => 'nullable|string|max:255',
            'birthday' => 'nullable|date',
            'gender' => 'nullable|in:none,male,female',
            'email' => 'nullable|email|max:255',
            'facebook' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'district' => 'nullable|string',
            'ward' => 'nullable|string',
            'customer_group' => 'nullable|string',
            'note' => 'nullable|string',
            'type' => 'nullable|in:individual,company',
            'invoice_name' => 'nullable|string|max:255',
            'id_card' => 'nullable|string|max:255',
            'passport' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:255',
            'invoice_address' => 'nullable|string',
            'invoice_city' => 'nullable|string',
            'invoice_district' => 'nullable|string',
            'invoice_ward' => 'nullable|string',
            'invoice_email' => 'nullable|email|max:255',
            'invoice_phone' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account' => 'nullable|string|max:255',
            'is_customer' => 'boolean',
        ]);

        if (empty($validated['code'])) {
            $validated['code'] = 'NCC' . time() . rand(10, 99);
        }

        $validated['is_supplier'] = true;
        $validated['is_customer'] = $request->input('is_customer', false);

        $supplier = Customer::create($validated);

        return response()->json(['success' => true, 'supplier' => $supplier]);
    }

    public function export(Request $request)
    {
        $suppliers = Customer::where('is_supplier', true)
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%")->orWhere('phone', 'LIKE', "%{$s}%"))
            ->latest()->get();

        return \App\Services\CsvService::export(
            ['Mã NCC', 'Tên NCC', 'Điện thoại', 'Email', 'Địa chỉ', 'Phường/Xã', 'Quận/Huyện', 'Tỉnh/TP', 'Công nợ NCC', 'Ghi chú'],
            $suppliers->map(fn($s) => [$s->code, $s->name, $s->phone, $s->email, $s->address, $s->ward, $s->district, $s->city, $s->supplier_debt_amount, $s->note]),
            'nha_cung_cap.csv'
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 2 || empty(trim($row[1] ?? ''))) continue;
            Customer::updateOrCreate(
                ['code' => trim($row[0])],
                ['name' => trim($row[1]), 'phone' => trim($row[2] ?? ''), 'email' => trim($row[3] ?? ''), 'address' => trim($row[4] ?? ''), 'ward' => trim($row[5] ?? ''), 'district' => trim($row[6] ?? ''), 'city' => trim($row[7] ?? ''), 'note' => trim($row[9] ?? ''), 'is_supplier' => true]
            );
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} nhà cung cấp từ file.");
    }

    // ===== API METHODS =====

    public function apiSearch(Request $request)
    {
        $search = $request->input('search');
        $suppliers = Customer::where('is_supplier', true)
            ->when($search, function($q) use ($search) {
                $q->where(function($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->take(20)
            ->get(['id', 'name', 'phone']);
            
        return response()->json($suppliers);
    }

    /**
     * Lịch sử nhập/trả hàng
     */
    public function purchaseHistory($id)
    {
        $purchases = Purchase::where('supplier_id', $id)
            ->with(['user:id,name'])
            ->orderByDesc('purchase_date')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'date' => $p->purchase_date ? $p->purchase_date->format('d/m/Y H:i') : ($p->created_at ? $p->created_at->format('d/m/Y H:i') : ''),
                    'user_name' => $p->user->name ?? 'Admin',
                    'branch' => 'Laptopplus.vn',
                    'total' => $p->total_amount,
                    'status' => $p->status,
                    'status_label' => $p->status === 'completed' ? 'Đã nhập hàng' : ($p->status === 'returned' ? 'Đã trả hàng' : ucfirst($p->status)),
                ];
            });

        return response()->json($purchases);
    }

    /**
     * Nợ cần trả NCC - lịch sử công nợ
     */
    public function debtTransactions($id)
    {
        // Seed debt transactions from purchases if empty
        $this->seedDebtTransactions($id);

        $transactions = SupplierDebtTransaction::where('supplier_id', $id)
            ->orderBy('created_at')
            ->get()
            ->map(function ($t) {
                $typeLabels = [
                    'purchase' => 'Nhập hàng',
                    'return' => 'Trả hàng',
                    'payment' => 'Thanh toán',
                    'adjustment' => 'Điều chỉnh',
                    'discount' => 'Chiết khấu TT',
                ];
                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'date' => $t->created_at->format('d/m/Y H:i'),
                    'type' => $t->type,
                    'type_label' => $typeLabels[$t->type] ?? $t->type,
                    'amount' => $t->amount,
                    'debt_remain' => $t->debt_remain,
                    'note' => $t->note,
                ];
            });

        return response()->json($transactions);
    }

    /**
     * Thanh toán công nợ NCC
     */
    public function recordPayment(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
        ]);

        $supplier = Customer::findOrFail($id);
        $currentDebt = $this->calculateDebt($id);

        $code = 'PCPN' . date('ymd') . rand(100, 999);
        SupplierDebtTransaction::create([
            'supplier_id' => $id,
            'code' => $code,
            'type' => 'payment',
            'amount' => -abs($data['amount']),
            'debt_remain' => $currentDebt - abs($data['amount']),
            'note' => $data['note'] ?? 'Thanh toán công nợ',
            'user_id' => auth()->id(),
        ]);

        // Update cached debt
        $supplier->update(['supplier_debt_amount' => $currentDebt - abs($data['amount'])]);

        return response()->json(['success' => true, 'message' => 'Đã ghi thanh toán.']);
    }

    /**
     * Điều chỉnh công nợ NCC
     */
    public function adjustDebt(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'note' => 'nullable|string',
            'type' => 'nullable|string', // 'adjustment' or 'discount'
        ]);

        $supplier = Customer::findOrFail($id);
        $currentDebt = $this->calculateDebt($id);
        $type = $data['type'] ?? 'adjustment';

        $code = ($type === 'discount' ? 'CKNCC' : 'DCNCC') . date('ymd') . rand(100, 999);
        $amount = $type === 'discount' ? -abs($data['amount']) : $data['amount'];

        SupplierDebtTransaction::create([
            'supplier_id' => $id,
            'code' => $code,
            'type' => $type,
            'amount' => $amount,
            'debt_remain' => $currentDebt + $amount,
            'note' => $data['note'] ?? ($type === 'discount' ? 'Chiết khấu thanh toán' : 'Điều chỉnh công nợ'),
            'user_id' => auth()->id(),
        ]);

        $supplier->update(['supplier_debt_amount' => $currentDebt + $amount]);

        return response()->json(['success' => true, 'message' => 'Đã cập nhật công nợ.']);
    }

    // Private helpers

    private function calculateDebt($supplierId)
    {
        $lastTx = SupplierDebtTransaction::where('supplier_id', $supplierId)
            ->orderByDesc('created_at')
            ->first();
        if ($lastTx) return $lastTx->debt_remain;

        return Purchase::where('supplier_id', $supplierId)
            ->where('status', 'completed')
            ->sum('debt_amount');
    }

    private function seedDebtTransactions($supplierId)
    {
        if (SupplierDebtTransaction::where('supplier_id', $supplierId)->exists()) return;

        $purchases = Purchase::where('supplier_id', $supplierId)
            ->where('status', 'completed')
            ->orderBy('purchase_date')
            ->orderBy('created_at')
            ->get();

        $runningDebt = 0;
        foreach ($purchases as $p) {
            // Purchase entry
            $runningDebt += $p->total_amount;
            SupplierDebtTransaction::create([
                'supplier_id' => $supplierId,
                'code' => $p->code,
                'type' => 'purchase',
                'amount' => $p->total_amount,
                'debt_remain' => $runningDebt,
                'purchase_id' => $p->id,
                'user_id' => $p->user_id,
                'created_at' => $p->purchase_date ?? $p->created_at,
                'updated_at' => $p->purchase_date ?? $p->created_at,
            ]);

            // Payment entry (if paid)
            $paid = $p->paid_amount ?? ($p->total_amount - ($p->debt_amount ?? 0));
            if ($paid > 0) {
                $runningDebt -= $paid;
                SupplierDebtTransaction::create([
                    'supplier_id' => $supplierId,
                    'code' => 'PCPN' . substr($p->code, 2),
                    'type' => 'payment',
                    'amount' => -$paid,
                    'debt_remain' => $runningDebt,
                    'purchase_id' => $p->id,
                    'user_id' => $p->user_id,
                    'created_at' => $p->purchase_date ?? $p->created_at,
                    'updated_at' => $p->purchase_date ?? $p->created_at,
                ]);
            }
        }
    }
}

