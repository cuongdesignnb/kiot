<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\CashFlow;
use App\Models\SupplierDebtTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Services\DebtOffsetService;

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

        $query->when($request->filled('sort_by'), function ($q) use ($request) {
            $allowed = ['code', 'name', 'phone', 'email', 'supplier_debt_amount', 'total_bought', 'created_at'];
            $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
            $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
            $q->orderBy($sortBy, $dir);
        }, function ($q) {
            $q->latest();
        });

        $suppliers = $query->paginate(50)->withQueryString();

        // Summary totals - use supplier_debt_amount which is maintained by purchase/return flows
        $summary = [
            'total_debt' => Customer::where('is_supplier', true)
                ->where('supplier_debt_amount', '>', 0)
                ->sum('supplier_debt_amount'),
            'total_bought' => Customer::where('is_supplier', true)
                ->sum('total_bought'),
        ];

        $groups = Customer::where('is_supplier', true)->whereNotNull('customer_group')->distinct()->pluck('customer_group');

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'groups' => $groups,
            'filters' => $request->only(['search', 'customer_group', 'date_filter', 'partner_type', 'sort_by', 'sort_direction']),
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
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

        Customer::create($validated);

        return redirect()->route('suppliers.index')->with('success', 'Tạo nhà cung cấp thành công.');
    }

    public function quickStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        $validated['code'] = 'NCC' . time() . rand(10, 99);
        $validated['is_supplier'] = true;
        $validated['is_customer'] = false;

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

    public function exportDebtHistory($id)
    {
        $data = $this->debtTransactions($id)->getData(true);

        return \App\Services\CsvService::export(
            ['Mã chứng từ', 'Loại', 'Giá trị', 'Còn nợ', 'Ngày', 'Ghi chú'],
            collect($data)->map(fn($t) => [$t['code'], $t['type_label'], $t['amount'], $t['debt_remain'], $t['date'], $t['note'] ?? '']),
            "cong_no_ncc_{$id}.csv"
        );
    }

    public function exportPurchaseHistory($id)
    {
        $data = $this->purchaseHistory($id)->getData(true);

        return \App\Services\CsvService::export(
            ['Mã phiếu nhập', 'Ngày', 'Người tạo', 'Chi nhánh', 'Tổng tiền', 'Trạng thái'],
            collect($data)->map(fn($p) => [$p['code'], $p['date'], $p['user_name'], $p['branch'], $p['total'], $p['status_label']]),
            "lich_su_nhap_{$id}.csv"
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
                ['name' => trim($row[1]), 'phone' => trim($row[2] ?? ''), 'email' => trim($row[3] ?? ''), 'address' => trim($row[4] ?? ''), 'ward' => trim($row[5] ?? ''), 'district' => trim($row[6] ?? ''), 'city' => trim($row[7] ?? ''), 'note' => trim($row[9] ?? ''), 'is_supplier' => true, 'is_customer' => false]
            );
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} nhà cung cấp từ file.");
    }

    // ===== API METHODS =====

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
     * Nợ cần trả NCC - lịch sử công nợ (unified for dual-role)
     */
    public function debtTransactions($id)
    {
        // Seed debt transactions from purchases if empty
        $this->seedDebtTransactions($id);

        $supplier = Customer::find($id);

        $entries = collect();

        // 1) Supplier debt transactions (purchase, return, payment, adjustment, discount, offset)
        $transactions = SupplierDebtTransaction::where('supplier_id', $id)
            ->orderBy('created_at')
            ->get();

        $typeLabels = [
            'purchase' => 'Nhập hàng',
            'return' => 'Trả hàng',
            'payment' => 'Thanh toán',
            'adjustment' => 'Điều chỉnh',
            'discount' => 'Chiết khấu TT',
            'offset' => 'Đối trừ CN',
        ];

        foreach ($transactions as $t) {
            $entries->push([
                'id' => 'stx-' . $t->id,
                'code' => $t->code,
                'type' => $t->type,
                'type_label' => $typeLabels[$t->type] ?? $t->type,
                'amount' => $t->amount,
                'note' => $t->note,
                'created_at' => $t->created_at,
            ]);
        }

        // 2) If dual-role (also customer): include invoice entries (mirrored)
        // In KiotViet supplier view: sales show as NEGATIVE (they owe us → offsets what we owe them)
        if ($supplier && $supplier->is_customer) {
            $invoices = Invoice::where('customer_id', $id)
                ->orderBy('created_at')
                ->get(['id', 'code', 'total', 'customer_paid', 'created_at']);

            foreach ($invoices as $inv) {
                $entries->push([
                    'id' => 'inv-' . $inv->id,
                    'code' => $inv->code,
                    'type' => 'sale',
                    'type_label' => 'Bán hàng',
                    'amount' => -$inv->total, // Negative: they owe us → offsets our debt to them
                    'note' => null,
                    'created_at' => $inv->created_at,
                ]);
                if ($inv->customer_paid > 0) {
                    $entries->push([
                        'id' => 'invpay-' . $inv->id,
                        'code' => 'TTHD' . preg_replace('/^HD/', '', $inv->code),
                        'type' => 'sale_payment',
                        'type_label' => 'TT bán hàng',
                        'amount' => $inv->customer_paid, // Positive: customer paid us → increases what we owe net
                        'note' => null,
                        'created_at' => $inv->created_at,
                    ]);
                }
            }

            // Order returns = positive (we refund customer → increases what we owe net)
            $returns = OrderReturn::where('customer_id', $id)
                ->orderBy('created_at')
                ->get(['id', 'code', 'total', 'paid_to_customer', 'created_at']);

            foreach ($returns as $ret) {
                $entries->push([
                    'id' => 'ret-' . $ret->id,
                    'code' => $ret->code,
                    'type' => 'sale_return',
                    'type_label' => 'Trả hàng bán',
                    'amount' => $ret->total, // Positive: we refund → more we owe
                    'note' => null,
                    'created_at' => $ret->created_at,
                ]);
            }

            // Customer-side cash_flows (non-invoice receipts like debt payment, adjustment)
            $cashFlows = CashFlow::where('target_type', 'Khách hàng')
                ->where('target_id', $id)
                ->where('type', 'receipt')
                ->whereNotIn('reference_type', ['Invoice', 'DebtOffset']) // Skip duplicates
                ->orderBy('created_at')
                ->get();

            foreach ($cashFlows as $cf) {
                $entries->push([
                    'id' => 'cf-' . $cf->id,
                    'code' => $cf->code,
                    'type' => 'customer_payment',
                    'type_label' => 'Thu nợ KH',
                    'amount' => $cf->amount, // Positive: customer paid debt → increases our net obligation
                    'note' => $cf->description,
                    'created_at' => $cf->created_at,
                ]);
            }
        }

        // Sort by date asc, compute running debt balance
        $sorted = $entries->sortBy('created_at')->values();
        $balance = 0;
        $ledger = $sorted->map(function ($entry) use (&$balance) {
            $balance += $entry['amount'];
            $entry['debt_remain'] = $balance;
            return $entry;
        });

        return response()->json($ledger->values());
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

        // Tự động đối trừ công nợ NCC↔KH
        DebtOffsetService::offsetDebts($supplier);

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

        // Tự động đối trừ công nợ NCC↔KH
        DebtOffsetService::offsetDebts($supplier);

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

