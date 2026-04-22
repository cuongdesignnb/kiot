<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\PurchaseReturn;
use App\Models\CashFlow;
use App\Models\SupplierDebtTransaction;
use App\Support\Filters\FilterableIndex;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Services\DebtOffsetService;

class SupplierController extends Controller
{
    use FilterableIndex;

    protected function configureSupplierFilters(): void
    {
        $this->searchable = ['code', 'name', 'phone', 'phone2', 'email', 'tax_code'];
        $this->sortable = ['code', 'name', 'phone', 'email', 'supplier_debt_amount', 'total_bought', 'created_at'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = null; // customers table không có created_by
        $this->scalarFilters = ['customer_group', 'status', 'branch_id', 'city'];
    }

    public function index(Request $request)
    {
        $this->configureSupplierFilters();

        $query = Customer::where('is_supplier', true);

        $this->applyFilters($query, $request);

        // partner_type is a pseudo-filter derived from is_customer flag
        if ($request->filled('partner_type')) {
            if ($request->partner_type === 'supplier_only') {
                $query->where('is_customer', false);
            } elseif ($request->partner_type === 'both') {
                $query->where('is_customer', true);
            }
        }

        // has_payable: bật nếu cần lọc NCC còn/không còn nợ phải trả
        if ($request->filled('has_payable')) {
            if ((string) $request->input('has_payable') === '1') {
                $query->where('supplier_debt_amount', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('supplier_debt_amount')->orWhere('supplier_debt_amount', '<=', 0);
                });
            }
        }

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

        $filters = $this->currentFilters($request);
        $filters['partner_type'] = $request->input('partner_type');
        $filters['has_payable'] = $request->input('has_payable', '');

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'groups' => $groups,
            'filters' => $filters,
            'summary' => $summary,
            'filterOptions' => [
                'groups' => $groups->map(fn($g) => ['value' => $g, 'label' => $g])->values(),
                'partnerTypes' => [
                    ['value' => 'supplier_only', 'label' => 'Chỉ nhà cung cấp'],
                    ['value' => 'both', 'label' => 'Vừa là khách, vừa là NCC'],
                ],
                'payableOptions' => [
                    ['value' => '1', 'label' => 'Còn nợ NCC'],
                    ['value' => '0', 'label' => 'Đã trả đủ'],
                ],
                'statuses' => [
                    ['value' => 'active', 'label' => 'Đang hoạt động', 'color' => 'green'],
                    ['value' => 'inactive', 'label' => 'Ngừng hoạt động', 'color' => 'gray'],
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => 'nullable|string|max:255|unique:customers,phone',
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
            'phone' => 'nullable|string|max:255|unique:customers,phone',
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
        $this->configureSupplierFilters();

        $query = Customer::where('is_supplier', true);
        $this->applyFilters($query, $request);

        if ($request->filled('partner_type')) {
            if ($request->partner_type === 'supplier_only') {
                $query->where('is_customer', false);
            } elseif ($request->partner_type === 'both') {
                $query->where('is_customer', true);
            }
        }
        if ($request->filled('has_payable')) {
            if ((string) $request->input('has_payable') === '1') {
                $query->where('supplier_debt_amount', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('supplier_debt_amount')->orWhere('supplier_debt_amount', '<=', 0);
                });
            }
        }

        $suppliers = $query->get();

        return \App\Services\CsvService::export(
            ['Mã NCC', 'Tên NCC', 'Điện thoại', 'Email', 'Địa chỉ', 'Phường/Xã', 'Quận/Huyện', 'Tỉnh/TP', 'Công nợ NCC', 'Ghi chú'],
            $suppliers->map(fn($s) => [$s->code, $s->name, $s->phone, $s->email, $s->address, $s->ward, $s->district, $s->city, $s->supplier_debt_amount, $s->note]),
            'nha_cung_cap.csv'
        );
    }

    public function exportDebtHistory($id)
    {
        $data = $this->debtTransactions($id)->getData(true);
        $entries = $data['entries'] ?? $data;

        return \App\Services\CsvService::export(
            ['Mã chứng từ', 'Loại', 'Giá trị', 'Còn nợ', 'Ngày', 'Ghi chú'],
            collect($entries)->map(fn($t) => [$t['code'], $t['type_label'], $t['amount'], $t['debt_remain'], $t['date'] ?? ($t['created_at'] ?? ''), $t['note'] ?? '']),
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
     * Nợ cần trả NCC - lịch sử công nợ (unified ledger, dual-role aware)
     * supplier_effect = -customer_effect (theo motakh.md spec)
     */
    public function debtTransactions($id)
    {
        $this->seedDebtTransactions($id);
        $supplier = Customer::find($id);
        $isDualRole = $supplier && $supplier->is_customer && $supplier->is_supplier;
        $entries = collect();

        // ═══ 1) Purchases = "Nhập hàng" → supplier += total (DN nợ NCC tăng) ═══
        $purchases = Purchase::where('supplier_id', $id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total_amount', 'paid_amount', 'created_at']);

        foreach ($purchases as $p) {
            // Nhập hàng: +total (tăng phải trả NCC)
            $entries->push([
                'id' => 'pur-' . $p->id,
                'code' => $p->code,
                'type' => 'purchase',
                'type_label' => 'Nhập hàng',
                'amount' => $p->total_amount,
                'supplier_effect' => $p->total_amount, // NCC: +
                'created_at' => $p->created_at,
            ]);
            // TT khi nhập hàng: -paid (giảm phải trả NCC)
            if ($p->paid_amount > 0) {
                $entries->push([
                    'id' => 'purpay-' . $p->id,
                    'code' => 'TTNH' . preg_replace('/^PN/', '', $p->code),
                    'type' => 'payment',
                    'type_label' => 'Thanh toán',
                    'amount' => $p->paid_amount,
                    'supplier_effect' => -$p->paid_amount, // NCC: -
                    'created_at' => $p->created_at,
                ]);
            }
        }

        // ═══ 2) Purchase returns → giảm phải trả NCC ═══
        $purchaseReturns = PurchaseReturn::where('supplier_id', $id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total_amount', 'created_at']);

        foreach ($purchaseReturns as $pr) {
            $entries->push([
                'id' => 'pret-' . $pr->id,
                'code' => $pr->code,
                'type' => 'return',
                'type_label' => 'Trả hàng',
                'amount' => $pr->total_amount,
                'supplier_effect' => -$pr->total_amount, // NCC: - (giảm phải trả)
                'created_at' => $pr->created_at,
            ]);
        }

        // ═══ 3) Supplier debt transactions (standalone payment/adjustment/discount) ═══
        $supplierTxs = SupplierDebtTransaction::where('supplier_id', $id)
            ->whereNotIn('type', ['purchase', 'return', 'offset'])
            ->orderBy('created_at', 'desc')
            ->get();

        $typeLabels = [
            'payment' => 'Thanh toán',
            'adjustment' => 'Điều chỉnh',
            'discount' => 'Chiết khấu TT',
        ];

        foreach ($supplierTxs as $stx) {
            $entries->push([
                'id' => 'stx-' . $stx->id,
                'code' => $stx->code,
                'type' => $stx->type,
                'type_label' => $typeLabels[$stx->type] ?? $stx->type,
                'amount' => abs($stx->amount),
                'supplier_effect' => $stx->amount, // Already correct sign from source
                'created_at' => $stx->created_at,
            ]);
        }

        // ═══ 4) Cross-role: Invoices from customer side (dual-role only) ═══
        if ($isDualRole) {
            $invoices = Invoice::where('customer_id', $id)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'code', 'total', 'customer_paid', 'created_at']);

            foreach ($invoices as $inv) {
                // Bán hàng cho họ: supplier -= total (phát sinh phải thu → giảm nợ NCC)
                $entries->push([
                    'id' => 'inv-' . $inv->id,
                    'code' => $inv->code,
                    'type' => 'sale',
                    'type_label' => 'Bán hàng',
                    'amount' => $inv->total,
                    'supplier_effect' => -$inv->total, // NCC: -
                    'created_at' => $inv->created_at,
                ]);
                // Thu tiền KH: supplier += paid (thu từ họ → tăng lại nợ NCC tương đối)
                if ($inv->customer_paid > 0) {
                    $entries->push([
                        'id' => 'pay-' . $inv->id,
                        'code' => 'TTHD' . preg_replace('/^HD/', '', $inv->code),
                        'type' => 'customer_payment',
                        'type_label' => 'Thanh toán',
                        'amount' => $inv->customer_paid,
                        'supplier_effect' => $inv->customer_paid, // NCC: +
                        'created_at' => $inv->created_at,
                    ]);
                }
            }

            // Standalone CashFlow receipts (customer payments not linked to invoice)
            $invoiceCodes = $invoices->pluck('code')->toArray();
            $cashFlows = CashFlow::where('target_type', 'Khách hàng')
                ->where('target_id', $id)
                ->where('type', 'receipt')
                ->whereNotIn('reference_type', ['DebtOffset', 'DebtOffsetCancel'])
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($cashFlows as $cf) {
                if ($cf->reference_type === 'Invoice' && in_array($cf->reference_code, $invoiceCodes)) {
                    continue;
                }
                $entries->push([
                    'id' => 'cf-' . $cf->id,
                    'code' => $cf->code,
                    'type' => 'customer_payment',
                    'type_label' => $cf->reference_type === 'OrderReturn' ? 'Trả hàng bán' : 'Thanh toán',
                    'amount' => $cf->amount,
                    'supplier_effect' => $cf->amount, // NCC: + (thu từ KH → tương đối tăng nợ NCC)
                    'created_at' => $cf->created_at,
                ]);
            }
        }

        // ═══ Sort by date asc → compute running balance (NET supplier position) ═══
        $sorted = $entries->sortBy('created_at')->values();
        $balance = 0;
        $ledger = $sorted->map(function ($entry) use (&$balance) {
            $balance += $entry['supplier_effect'];
            $entry['debt_remain'] = $balance;
            return $entry;
        });

        return response()->json([
            'entries' => $ledger->values(),
            'summary' => [
                'net' => $balance, // Final running balance = NET supplier position
                'is_dual_role' => $isDualRole,
            ],
        ]);
    }

    /**
     * Thanh toan cong no NCC — auto-allocate hoac manual allocation.
     * CHI thay doi: them phan bo vao phieu nhap. KHONG dung debtTransactions/offset.
     */
    public function recordPayment(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
            'mode' => 'nullable|string|in:auto,manual',
            'allocations' => 'nullable|array',
            'allocations.*.purchase_id' => 'required_with:allocations|exists:purchases,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0',
            'date' => 'nullable|date',
        ]);

        $supplier = Customer::findOrFail($id);
        $currentDebt = $this->calculateDebt($id);
        $totalPay = abs($data['amount']);
        $mode = $data['mode'] ?? 'auto';
        $paidAt = !empty($data['date']) ? \Carbon\Carbon::parse($data['date']) : now();

        DB::transaction(function () use ($id, $supplier, $currentDebt, $totalPay, $mode, $data, $paidAt) {
            $code = 'PCPN' . date('ymd') . rand(100, 999);

            // Create SupplierDebtTransaction
            $tx = SupplierDebtTransaction::create([
                'supplier_id' => $id,
                'code' => $code,
                'type' => 'payment',
                'amount' => -$totalPay,
                'debt_remain' => $currentDebt - $totalPay,
                'note' => $data['note'] ?? 'Thanh toan cong no',
                'user_id' => auth()->id(),
            ]);
            if (!empty($data['date'])) {
                $tx->created_at = $paidAt;
                $tx->save();
            }

            // Create CashFlow phieu chi
            $cf = CashFlow::create([
                'code' => $code,
                'type' => 'payment',
                'amount' => $totalPay,
                'time' => $paidAt,
                'category' => 'Chi thanh toan NCC',
                'target_type' => 'Nha cung cap',
                'target_id' => $id,
                'target_name' => $supplier->name,
                'reference_type' => 'SupplierPayment',
                'reference_code' => $code,
                'payment_method' => 'cash',
                'description' => "Chi thanh toan cong no NCC {$supplier->name}: " . number_format($totalPay) . "d",
            ]);
            if (!empty($data['date'])) {
                $cf->created_at = $paidAt;
                $cf->save();
            }

            // Allocate into purchases
            if ($mode === 'manual' && !empty($data['allocations'])) {
                foreach ($data['allocations'] as $alloc) {
                    if ($alloc['amount'] <= 0) continue;
                    $purchase = Purchase::find($alloc['purchase_id']);
                    if ($purchase && $purchase->supplier_id == $id) {
                        $purchase->increment('paid_amount', $alloc['amount']);
                        $purchase->decrement('debt_amount', $alloc['amount']);
                    }
                }
            } else {
                // Auto-allocate: oldest first
                $remaining = $totalPay;
                $purchases = Purchase::where('supplier_id', $id)
                    ->where('status', 'completed')
                    ->where('debt_amount', '>', 0)
                    ->orderBy('purchase_date')
                    ->orderBy('created_at')
                    ->get();

                foreach ($purchases as $purchase) {
                    if ($remaining <= 0) break;
                    $payThis = min($remaining, $purchase->debt_amount);
                    $purchase->increment('paid_amount', $payThis);
                    $purchase->decrement('debt_amount', $payThis);
                    $remaining -= $payThis;
                }
            }

            // Update cached debt
            $supplier->update(['supplier_debt_amount' => $currentDebt - $totalPay]);
        });

        return response()->json(['success' => true, 'message' => 'Da ghi thanh toan.']);
    }

    /**
     * Danh sach phieu nhap con no cua NCC (cho manual allocation UI).
     */
    public function outstandingPurchases($id)
    {
        $purchases = Purchase::where('supplier_id', $id)
            ->where('status', 'completed')
            ->where('debt_amount', '>', 0)
            ->orderBy('purchase_date')
            ->orderBy('created_at')
            ->get(['id', 'code', 'total_amount', 'paid_amount', 'debt_amount', 'purchase_date', 'created_at']);

        return response()->json($purchases->map(fn($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'total' => $p->total_amount,
            'paid' => $p->paid_amount,
            'remaining' => $p->debt_amount,
            'date' => $p->purchase_date ? $p->purchase_date->format('d/m/Y') : ($p->created_at ? $p->created_at->format('d/m/Y') : ''),
        ]));
    }

    /**
     * Điều chỉnh công nợ NCC
     */
    public function adjustDebt(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => 'required|numeric', // Giá trị nợ cuối mong muốn
            'note' => 'nullable|string',
            'type' => 'nullable|string', // 'adjustment' or 'discount'
            'date' => 'nullable|date',
        ]);

        $supplier = Customer::findOrFail($id);
        $currentDebt = (float) $supplier->supplier_debt_amount;
        $type = $data['type'] ?? 'adjustment';
        $adjustedAt = !empty($data['date']) ? \Carbon\Carbon::parse($data['date']) : now();

        if ($type === 'discount') {
            // Chiết khấu: giữ logic cũ — amount là số tiền chiết khấu
            $amount = -abs($data['amount']);
            $code = 'CKNCC' . date('ymd') . rand(100, 999);

            $tx = SupplierDebtTransaction::create([
                'supplier_id' => $id,
                'code' => $code,
                'type' => $type,
                'amount' => $amount,
                'debt_remain' => $currentDebt + $amount,
                'note' => $data['note'] ?? 'Chiết khấu thanh toán',
                'user_id' => auth()->id(),
            ]);
            if (!empty($data['date'])) {
                $tx->created_at = $adjustedAt;
                $tx->save();
            }

            $supplier->update(['supplier_debt_amount' => $currentDebt + $amount]);
        } else {
            // Điều chỉnh: amount = nợ cuối mong muốn
            $targetDebt = $data['amount'];
            $diff = $targetDebt - $currentDebt;

            if ($diff == 0) {
                return response()->json(['success' => true, 'message' => 'Công nợ không thay đổi.']);
            }

            $code = 'DCNCC' . date('ymd') . rand(100, 999);

            $tx = SupplierDebtTransaction::create([
                'supplier_id' => $id,
                'code' => $code,
                'type' => 'adjustment',
                'amount' => $diff,
                'debt_remain' => $targetDebt,
                'note' => ($data['note'] ?? 'Điều chỉnh công nợ') . ' | ' . number_format($currentDebt) . ' → ' . number_format($targetDebt),
                'user_id' => auth()->id(),
            ]);
            if (!empty($data['date'])) {
                $tx->created_at = $adjustedAt;
                $tx->save();
            }

            $supplier->update(['supplier_debt_amount' => $targetDebt]);
        }

        return response()->json(['success' => true, 'message' => 'Đã cập nhật công nợ.']);
    }

    // Private helpers

    private function calculateDebt($supplierId)
    {
        // Primary: use cached supplier_debt_amount (always kept in sync)
        $supplier = Customer::find($supplierId);
        if ($supplier && $supplier->supplier_debt_amount != 0) {
            return $supplier->supplier_debt_amount;
        }

        // Fallback: last transaction
        $lastTx = SupplierDebtTransaction::where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->first();
        if ($lastTx) return $lastTx->debt_remain;

        // Final fallback: sum from purchases
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

