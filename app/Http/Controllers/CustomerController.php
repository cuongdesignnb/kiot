<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\SupplierDebtTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\DebtOffsetService;
use App\Models\DebtOffset;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $type = $request->input('type');
        $gender = $request->input('gender');

        $customers = Customer::with('branch')
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                      ->orWhere('name', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($gender, fn($q) => $q->where('gender', $gender))
            ->when(Setting::get('customer_manage_by_branch', false), function ($q) {
                if (auth()->user()?->branch_id) {
                    $q->where('branch_id', auth()->user()->branch_id);
                }
            })
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['code', 'name', 'phone', 'debt_amount', 'total_spent', 'created_at'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'id';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->orderBy('id', 'desc');
            })
            ->paginate(15)
            ->withQueryString();

        // Collect customer-related settings for the frontend
        $customerSettings = [
            'customer_debt_warning' => Setting::get('customer_debt_warning', true),
            'customer_is_vendor' => Setting::get('customer_is_vendor', false),
            'customer_manage_by_branch' => Setting::get('customer_manage_by_branch', false),
        ];

        // Summary totals
        $summary = [
            'total_debt' => Customer::where('is_customer', true)->where('debt_amount', '>', 0)->sum('debt_amount'),
            'total_spent' => Customer::where('is_customer', true)->sum('total_spent'),
            'total_returns' => Customer::where('is_customer', true)->sum('total_returns'),
        ];

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'type' => $type, 'gender' => $gender, 'sort_by' => $request->sort_by, 'sort_direction' => $request->sort_direction],
            'customerSettings' => $customerSettings,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        // Build dynamic validation rules based on settings
        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => (Setting::get('customer_required_phone', false) ? 'required' : 'nullable') . '|string|max:255|unique:customers,phone',
            'phone2' => 'nullable|string|max:255',
            'birthday' => (Setting::get('customer_required_birthday', false) ? 'required' : 'nullable') . '|date',
            'gender' => (Setting::get('customer_required_gender', false) ? 'required' : 'nullable') . '|in:none,male,female',
            'email' => (Setting::get('customer_required_email', false) ? 'required' : 'nullable') . '|email|max:255',
            'facebook' => (Setting::get('customer_required_facebook', false) ? 'required' : 'nullable') . '|string|max:255',
            'address' => (Setting::get('customer_required_address', false) ? 'required' : 'nullable') . '|string',
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
            'is_supplier' => 'boolean',
        ];

        $validated = $request->validate($rules);
        if (empty($validated['code'])) {
            $validated['code'] = 'KH' . time() . rand(10, 99);
        }

        $validated['is_supplier'] = $request->input('is_supplier', false);
        $validated['is_customer'] = true;

        $linkId = $request->input('linked_supplier_id') ?: $request->input('link_existing_id');
        $linkMode = $request->input('supplier_linking_mode');

        if (($linkMode === 'link_existing' || $linkId) && $linkId) {
            $existing = Customer::findOrFail($linkId);
            $existing->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $existing->phone,
                'is_customer' => true,
                'is_supplier' => true,
                'address' => $validated['address'] ?? $existing->address,
                'note' => $validated['note'] ?? $existing->note,
            ]);
            $customer = $existing;
        } else {
            $customer = Customer::create($validated);
        }

        if ($request->wantsJson()) {
            return response()->json(['customer' => $customer]);
        }

        return redirect()->route('customers.index')->with('success', 'Tạo khách hàng thành công.');
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => (Setting::get('customer_required_phone', false) ? 'required' : 'nullable') . '|string|max:255|unique:customers,phone,' . $customer->id,
            'phone2' => 'nullable|string|max:255',
            'birthday' => (Setting::get('customer_required_birthday', false) ? 'required' : 'nullable') . '|date',
            'gender' => (Setting::get('customer_required_gender', false) ? 'required' : 'nullable') . '|in:none,male,female',
            'email' => (Setting::get('customer_required_email', false) ? 'required' : 'nullable') . '|email|max:255',
            'facebook' => (Setting::get('customer_required_facebook', false) ? 'required' : 'nullable') . '|string|max:255',
            'address' => (Setting::get('customer_required_address', false) ? 'required' : 'nullable') . '|string',
            'city' => 'nullable|string',
            'district' => 'nullable|string',
            'ward' => 'nullable|string',
            'customer_group' => 'nullable|string',
            'note' => 'nullable|string',
            'type' => 'nullable|in:individual,company',
            'tax_code' => 'nullable|string|max:255',
            'invoice_name' => 'nullable|string|max:255',
            'invoice_address' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
            'is_supplier' => 'boolean',
        ]);

        if (array_key_exists('is_supplier', $validated) && $validated['is_supplier']) {
             $validated['is_supplier'] = true;
        } else {
             $validated['is_supplier'] = false;
        }

        $linkId = $request->input('linked_supplier_id') ?: $request->input('link_existing_id');
        $linkMode = $request->input('supplier_linking_mode');

        if (($linkMode === 'link_existing' || $linkId) && $linkId && $linkId != $customer->id) {
            $existing = Customer::findOrFail($linkId);
            
            // Merge relations
            Invoice::where('customer_id', $customer->id)->update(['customer_id' => $existing->id]);
            OrderReturn::where('customer_id', $customer->id)->update(['customer_id' => $existing->id]);
            
            CashFlow::where('target_id', $customer->id)->whereIn('target_type', ['Khách hàng', 'Nhà cung cấp'])->update([
                'target_id' => $existing->id,
                'target_name' => collect([$existing->name])->implode(''),
            ]);

            // Merge financial figures
            $existing->debt_amount += $customer->debt_amount;
            $existing->total_spent += $customer->total_spent;
            $existing->total_returns += $customer->total_returns;
            $existing->supplier_debt_amount += $customer->supplier_debt_amount;
            $existing->total_bought += $customer->total_bought;
            
            $existing->is_customer = true;
            $existing->is_supplier = true;
            $existing->name = $validated['name'];
            if (!empty($validated['phone'])) {
                $existing->phone = $validated['phone'];
            }
            if (!empty($validated['address'])) {
                $existing->address = $validated['address'];
            }
            $existing->save();
            
            $customer->delete();
            return back()->with('success', 'Cập nhật và gộp vào nhà cung cấp thành công.');
        } else {
            $customer->update($validated);
        }

        return back()->with('success', 'Cập nhật khách hàng thành công.');
    }

    public function destroy(Customer $customer)
    {
        // Guard: không cho xóa nếu đã có giao dịch — buộc dùng "Ngừng hoạt động"
        $hasInvoices = Invoice::where('customer_id', $customer->id)->exists();
        $hasPurchases = \App\Models\Purchase::where('supplier_id', $customer->id)->exists();
        $hasReturns = OrderReturn::where('customer_id', $customer->id)->exists();
        $hasDebt = ((float) $customer->debt_amount != 0) || ((float) $customer->supplier_debt_amount != 0);

        if ($hasInvoices || $hasPurchases || $hasReturns || $hasDebt) {
            return back()->with('error', 'Không thể xóa — đối tác này đã có giao dịch hoặc công nợ. Hãy chuyển sang "Ngừng hoạt động" thay vì xóa.');
        }

        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Xóa khách hàng thành công.');
    }

    public function salesHistory(Customer $customer)
    {
        $invoices = Invoice::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total', 'status', 'created_at']);

        $returns = OrderReturn::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total', 'status', 'created_at']);

        return response()->json([
            'invoices' => $invoices,
            'returns' => $returns,
        ]);
    }

    public function debtHistory(Customer $customer)
    {
        $entries = collect();
        $isDualRole = $customer->is_customer && $customer->is_supplier;

        // ═══ 1) Invoices = "Bán hàng" → customer += total (KH nợ DN tăng) ═══
        $invoices = Invoice::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total', 'customer_paid', 'created_at']);

        foreach ($invoices as $inv) {
            // Bán hàng: +total (phát sinh phải thu)
            $entries->push([
                'id' => 'inv-' . $inv->id,
                'code' => $inv->code,
                'type' => 'Bán hàng',
                'amount' => $inv->total, // Giá trị chứng từ (dương)
                'customer_effect' => $inv->total, // KH: +
                'created_at' => $inv->created_at,
            ]);

            // Thanh toán từ KH: -customer_paid (giảm phải thu)
            if ($inv->customer_paid > 0) {
                $entries->push([
                    'id' => 'pay-' . $inv->id,
                    'code' => 'TTHD' . preg_replace('/^HD/', '', $inv->code),
                    'type' => 'Thanh toán',
                    'amount' => $inv->customer_paid, // Giá trị chứng từ (dương)
                    'customer_effect' => -$inv->customer_paid, // KH: -
                    'created_at' => $inv->created_at,
                ]);
            }
        }

        // ═══ 2) Explicit cash_flow receipts (standalone payments, not linked to invoice) ═══
        $invoiceCodes = $invoices->pluck('code')->toArray();
        $cashFlows = CashFlow::where('target_type', 'Khách hàng')
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->whereNotIn('reference_type', ['DebtOffset', 'DebtOffsetCancel'])
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($cashFlows as $cf) {
            if ($cf->reference_type === 'Invoice' && in_array($cf->reference_code, $invoiceCodes)) {
                continue; // Already handled as implicit payment above
            }
            $entries->push([
                'id' => 'cf-' . $cf->id,
                'code' => $cf->code,
                'type' => $cf->reference_type === 'OrderReturn' ? 'Trả hàng' : 'Thanh toán',
                'amount' => $cf->amount,
                'customer_effect' => -$cf->amount, // KH: - (giảm phải thu)
                'created_at' => $cf->created_at,
            ]);
        }

        // ═══ 3) Cross-role: Purchases from this partner (dual-role only) ═══
        if ($isDualRole) {
            $purchases = Purchase::where('supplier_id', $customer->id)
                ->where('status', 'completed')
                ->orderBy('created_at', 'desc')
                ->get(['id', 'code', 'total_amount', 'paid_amount', 'created_at']);

            foreach ($purchases as $p) {
                // Nhập hàng: customer -= total (DN nợ họ tăng = phải thu giảm)
                $entries->push([
                    'id' => 'pur-' . $p->id,
                    'code' => $p->code,
                    'type' => 'Nhập hàng',
                    'amount' => $p->total_amount,
                    'customer_effect' => -$p->total_amount, // KH: -
                    'created_at' => $p->created_at,
                ]);
                // TT cho NCC: customer += paid (DN trả NCC = giảm nợ NCC = phải thu tăng tương đối)
                if ($p->paid_amount > 0) {
                    $entries->push([
                        'id' => 'purpay-' . $p->id,
                        'code' => 'TTNH' . preg_replace('/^PN/', '', $p->code),
                        'type' => 'TT nhập hàng',
                        'amount' => $p->paid_amount,
                        'customer_effect' => $p->paid_amount, // KH: + (giảm nợ NCC)
                        'created_at' => $p->created_at,
                    ]);
                }
            }

            // Trả hàng nhập: customer += amount (giảm phải trả NCC)
            $purchaseReturns = PurchaseReturn::where('supplier_id', $customer->id)
                ->where('status', 'completed')
                ->orderBy('created_at', 'desc')
                ->get(['id', 'code', 'total_amount', 'created_at']);

            foreach ($purchaseReturns as $pr) {
                $entries->push([
                    'id' => 'pret-' . $pr->id,
                    'code' => $pr->code,
                    'type' => 'Trả hàng nhập',
                    'amount' => $pr->total_amount,
                    'customer_effect' => $pr->total_amount, // KH: + (giảm phải trả)
                    'created_at' => $pr->created_at,
                ]);
            }

            // Supplier debt transactions (payment/adjustment/discount only, NOT purchase/return/offset)
            $supplierTxs = SupplierDebtTransaction::where('supplier_id', $customer->id)
                ->whereNotIn('type', ['purchase', 'return', 'offset'])
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($supplierTxs as $stx) {
                // NCC payment (amount<0): DN trả NCC → customer += |amount| (giảm nợ NCC)
                // NCC adjustment: mirror sign for customer perspective
                $entries->push([
                    'id' => 'stx-' . $stx->id,
                    'code' => $stx->code,
                    'type' => $stx->type === 'payment' ? 'TT công nợ NCC' : ($stx->type === 'adjustment' ? 'Điều chỉnh' : ($stx->type === 'discount' ? 'Chiết khấu TT' : $stx->type)),
                    'amount' => abs($stx->amount),
                    'customer_effect' => -$stx->amount, // Mirror: NCC payment(-) → KH(+)
                    'created_at' => $stx->created_at,
                ]);
            }
        }

        // ═══ Sort by date asc → compute running balance (NET customer position) ═══
        $sorted = $entries->sortBy('created_at')->values();
        $balance = 0;
        $ledger = $sorted->map(function ($entry) use (&$balance) {
            $balance += $entry['customer_effect'];
            $entry['balance'] = $balance;
            return $entry;
        });

        return response()->json([
            'entries' => $ledger->reverse()->values(),
            'summary' => [
                'net' => $balance, // Final running balance = NET customer position
                'is_dual_role' => $isDualRole,
            ],
        ]);
    }

    /**
     * Thu nợ khách hàng — hỗ trợ auto-allocate (cũ trước) hoặc manual allocation.
     *
     * Mode AUTO (default):  { amount: 80000, mode: "auto", note: "..." }
     * Mode MANUAL:          { mode: "manual", allocations: [{invoice_id:1, amount:20000}, ...], note: "..." }
     */
    public function debtPayment(Request $request, Customer $customer)
    {
        $mode = $request->input('mode', 'auto');

        if ($mode === 'manual') {
            $validated = $request->validate([
                'allocations' => 'required|array|min:1',
                'allocations.*.invoice_id' => 'required|integer|exists:invoices,id',
                'allocations.*.amount' => 'required|numeric|min:1',
                'note' => 'nullable|string|max:500',
            ]);

            $totalAmount = 0;
            $allocationCodes = [];

            foreach ($validated['allocations'] as $alloc) {
                $invoice = Invoice::where('id', $alloc['invoice_id'])
                    ->where('customer_id', $customer->id)
                    ->first();

                if (!$invoice) continue;

                $remaining = $invoice->total - $invoice->customer_paid;
                $payAmount = min($alloc['amount'], $remaining);

                if ($payAmount <= 0) continue;

                $invoice->increment('customer_paid', $payAmount);
                $totalAmount += $payAmount;
                $allocationCodes[] = $invoice->code . ':' . number_format($payAmount);
            }

            if ($totalAmount <= 0) {
                return back()->with('error', 'Không có khoản nào hợp lệ để thu.');
            }

            $cf = CashFlow::create([
                'code' => 'PT' . date('ymdHis') . rand(10, 99),
                'type' => 'receipt',
                'amount' => $totalAmount,
                'time' => now(),
                'category' => 'Thu nợ khách hàng',
                'target_type' => 'Khách hàng',
                'target_id' => $customer->id,
                'target_name' => $customer->name,
                'reference_type' => 'DebtPayment',
                'reference_code' => implode('; ', $allocationCodes),
                'description' => $validated['note'] ?? 'Thu nợ khách hàng ' . $customer->name,
            ]);

            $customer->decrement('debt_amount', $totalAmount);

        } else {
            // AUTO mode — allocate to oldest invoices first
            $validated = $request->validate([
                'amount' => 'required|numeric|min:1',
                'note' => 'nullable|string|max:500',
            ]);

            $remaining = $validated['amount'];
            $allocationCodes = [];

            // Get invoices with outstanding balance, oldest first
            $invoices = Invoice::where('customer_id', $customer->id)
                ->whereRaw('total > customer_paid')
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                $invoiceDebt = $invoice->total - $invoice->customer_paid;
                $payAmount = min($remaining, $invoiceDebt);

                $invoice->increment('customer_paid', $payAmount);
                $remaining -= $payAmount;
                $allocationCodes[] = $invoice->code . ':' . number_format($payAmount);
            }

            $actualPaid = $validated['amount'] - $remaining;
            if ($actualPaid <= 0) $actualPaid = $validated['amount']; // fallback: reduce debt even without invoices

            $cf = CashFlow::create([
                'code' => 'PT' . date('ymdHis') . rand(10, 99),
                'type' => 'receipt',
                'amount' => $actualPaid,
                'time' => now(),
                'category' => 'Thu nợ khách hàng',
                'target_type' => 'Khách hàng',
                'target_id' => $customer->id,
                'target_name' => $customer->name,
                'reference_type' => 'DebtPayment',
                'reference_code' => !empty($allocationCodes) ? implode('; ', $allocationCodes) : null,
                'description' => $validated['note'] ?? 'Thu nợ khách hàng ' . $customer->name,
            ]);

            $customer->decrement('debt_amount', $actualPaid);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Đã thu nợ ' . number_format($cf->amount) . ' từ khách hàng.']);
        }

        return back()->with('success', 'Đã thu nợ ' . number_format($cf->amount) . ' từ khách hàng.');
    }

    /**
     * Lấy danh sách hóa đơn còn nợ của khách hàng (cho modal thu nợ).
     */
    public function outstandingInvoices(Customer $customer)
    {
        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereRaw('total > customer_paid')
            ->orderBy('created_at', 'asc')
            ->get(['id', 'code', 'total', 'customer_paid', 'created_at'])
            ->map(fn($inv) => [
                'id' => $inv->id,
                'code' => $inv->code,
                'total' => $inv->total,
                'customer_paid' => $inv->customer_paid,
                'remaining' => $inv->total - $inv->customer_paid,
                'created_at' => $inv->created_at,
            ]);

        return response()->json($invoices);
    }

    public function debtAdjust(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric', // Giá trị nợ cuối mong muốn
            'note' => 'nullable|string|max:500',
        ]);

        $targetDebt = $validated['amount']; // Nợ cuối user muốn set
        $currentDebt = (float) $customer->debt_amount;
        $diff = $currentDebt - $targetDebt; // diff > 0 = giảm nợ, diff < 0 = tăng nợ

        if ($diff == 0) {
            return back()->with('info', 'Công nợ không thay đổi.');
        }

        $type = $diff > 0 ? 'receipt' : 'payment';
        $prefix = $diff > 0 ? 'PT' : 'PC';

        CashFlow::create([
            'code' => $prefix . date('ymdHis') . rand(10, 99),
            'type' => $type,
            'amount' => abs($diff),
            'time' => now(),
            'category' => 'Điều chỉnh công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'DebtAdjustment',
            'reference_code' => null,
            'description' => ($validated['note'] ?? 'Điều chỉnh công nợ') . ' | ' . number_format($currentDebt) . ' → ' . number_format($targetDebt),
        ]);

        $customer->update(['debt_amount' => $targetDebt]);

        return back()->with('success', 'Đã điều chỉnh công nợ: ' . number_format($currentDebt) . ' → ' . number_format($targetDebt) . '₫');
    }

    public function searchForMerge(Request $request)
    {
        $q = $request->input('q');
        $type = $request->input('type'); // 'customer' or 'supplier'
        $exclude = $request->input('exclude');

        $results = Customer::query()
            ->when($q, function ($query, $q) {
                $query->where(function ($qb) use ($q) {
                    $qb->where('name', 'LIKE', "%{$q}%")
                       ->orWhere('phone', 'LIKE', "%{$q}%")
                       ->orWhere('code', 'LIKE', "%{$q}%");
                });
            })
            ->when($exclude, fn($qb, $id) => $qb->where('id', '!=', $id))
            ->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount', 'is_customer', 'is_supplier']);

        return response()->json($results);
    }

    public function merge(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'merge_with_id' => 'required|integer|exists:customers,id',
        ]);

        $target = Customer::findOrFail($validated['merge_with_id']);

        if ($target->id === $customer->id) {
            return back()->with('error', 'Không thể gộp với chính mình.');
        }

        // Transfer all relations from $customer (source) into $target
        Invoice::where('customer_id', $customer->id)->update(['customer_id' => $target->id]);
        OrderReturn::where('customer_id', $customer->id)->update(['customer_id' => $target->id]);
        Purchase::where('supplier_id', $customer->id)->update(['supplier_id' => $target->id]);
        PurchaseReturn::where('supplier_id', $customer->id)->update(['supplier_id' => $target->id]);
        SupplierDebtTransaction::where('supplier_id', $customer->id)->update(['supplier_id' => $target->id]);

        CashFlow::where('target_id', $customer->id)->whereIn('target_type', ['Khách hàng', 'Nhà cung cấp'])->update([
            'target_id' => $target->id,
            'target_name' => $target->name,
        ]);

        // Merge financial figures
        $target->debt_amount += $customer->debt_amount;
        $target->total_spent += $customer->total_spent;
        $target->total_returns += $customer->total_returns;
        $target->supplier_debt_amount += $customer->supplier_debt_amount;
        $target->total_bought += $customer->total_bought;

        // Set both flags
        $target->is_customer = $target->is_customer || $customer->is_customer;
        $target->is_supplier = $target->is_supplier || $customer->is_supplier;

        $target->save();



        // Delete source
        $customer->delete();

        return back()->with('success', "Đã gộp thành công vào {$target->name} ({$target->code}).");
    }

    public function export(Request $request)
    {
        $customers = Customer::query()
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%")->orWhere('phone', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã KH', 'Tên khách hàng', 'Điện thoại', 'Email', 'Nhóm KH', 'Địa chỉ', 'Phường/Xã', 'Quận/Huyện', 'Tỉnh/TP', 'Công nợ', 'Tổng mua', 'Ghi chú'],
            $customers->map(fn($c) => [$c->code, $c->name, $c->phone, $c->email, $c->customer_group, $c->address, $c->ward, $c->district, $c->city, $c->debt_amount, $c->total_spent, $c->note]),
            'khach_hang.csv'
        );
    }

    public function exportDebtHistory(Customer $customer)
    {
        $data = $this->debtHistory($customer)->getData(true);
        $entries = $data['entries'] ?? [];

        return \App\Services\CsvService::export(
            ['Mã chứng từ', 'Loại', 'Giá trị', 'Dư nợ sau GD', 'Ngày'],
            collect($entries)->map(fn($e) => [$e['code'], $e['type'], $e['amount'], $e['balance'], $e['created_at']]),
            "cong_no_kh_{$customer->code}.csv"
        );
    }

    public function exportSalesHistory(Customer $customer)
    {
        $invoices = Invoice::where('customer_id', $customer->id)->orderByDesc('created_at')
            ->get(['code', 'total', 'status', 'created_at']);
        $returns = OrderReturn::where('customer_id', $customer->id)->orderByDesc('created_at')
            ->get(['code', 'total', 'status', 'created_at']);

        $rows = $invoices->map(fn($i) => [$i->code, 'Hóa đơn', $i->total, $i->status, $i->created_at])
            ->merge($returns->map(fn($r) => [$r->code, 'Trả hàng', $r->total, $r->status, $r->created_at]));

        return \App\Services\CsvService::export(
            ['Mã chứng từ', 'Loại', 'Giá trị', 'Trạng thái', 'Ngày'],
            $rows,
            "lich_su_ban_{$customer->code}.csv"
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 3 || empty(trim($row[1] ?? ''))) continue;
            Customer::updateOrCreate(
                ['code' => trim($row[0])],
                ['name' => trim($row[1]), 'phone' => trim($row[2] ?? ''), 'email' => trim($row[3] ?? ''), 'customer_group' => trim($row[4] ?? ''), 'address' => trim($row[5] ?? ''), 'ward' => trim($row[6] ?? ''), 'district' => trim($row[7] ?? ''), 'city' => trim($row[8] ?? ''), 'note' => trim($row[11] ?? ''), 'is_customer' => true]
            );
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} khách hàng từ file.");
    }

    // ===== CẤN BẰNG CÔNG NỢ =====

    /**
     * Cấn bằng công nợ thủ công
     */
    public function debtOffset(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:500',
        ]);

        if (!$customer->is_customer || !$customer->is_supplier) {
            return back()->with('error', 'Đối tác phải đồng thời là khách hàng và nhà cung cấp.');
        }

        $receivable = abs((float) $customer->debt_amount);
        $payable = abs((float) $customer->supplier_debt_amount);

        if ($receivable <= 0 || $payable <= 0) {
            return back()->with('error', 'Cả hai bên công nợ phải lớn hơn 0 để cấn bằng.');
        }

        $maxOffset = min($receivable, $payable);
        if ($validated['amount'] > $maxOffset) {
            return back()->with('error', 'Số tiền cấn bằng không được vượt quá ' . number_format($maxOffset) . '₫.');
        }

        $result = DebtOffsetService::manualOffset($customer, $validated['amount'], $validated['note']);

        if (!$result) {
            return back()->with('error', 'Không thể cấn bằng công nợ.');
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'data' => $result]);
        }

        return back()->with('success', 'Cấn bằng công nợ thành công: ' . number_format($validated['amount']) . '₫');
    }

    /**
     * Hủy cấn bằng công nợ
     */
    public function cancelDebtOffset(Request $request, Customer $customer, \App\Models\DebtOffset $debtOffset)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($debtOffset->customer_id !== $customer->id) {
            return back()->with('error', 'Chứng từ cấn bằng không thuộc đối tác này.');
        }

        if ($debtOffset->status !== 'active') {
            return back()->with('error', 'Chứng từ cấn bằng đã bị hủy trước đó.');
        }

        $result = DebtOffsetService::cancelOffset($debtOffset, $validated['reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'data' => $result]);
        }

        return back()->with('success', 'Đã hủy cấn bằng công nợ: ' . number_format($debtOffset->amount) . '₫');
    }

    /**
     * Lịch sử cấn bằng công nợ
     */
    public function debtOffsetHistory(Customer $customer)
    {
        $offsets = \App\Models\DebtOffset::where('customer_id', $customer->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'amount' => $o->amount,
                'receivable_before' => $o->receivable_before,
                'payable_before' => $o->payable_before,
                'receivable_after' => $o->receivable_after,
                'payable_after' => $o->payable_after,
                'is_auto' => $o->is_auto,
                'note' => $o->note,
                'status' => $o->status,
                'cancel_reason' => $o->cancel_reason,
                'cancelled_at' => $o->cancelled_at,
                'created_at' => $o->created_at,
                'user_name' => $o->user?->name ?? 'Admin',
            ]);

        return response()->json($offsets);
    }
}
