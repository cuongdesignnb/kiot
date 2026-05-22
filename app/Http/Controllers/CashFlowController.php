<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\BankAccount;
use App\Services\LockPeriodService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Enums\PaymentMethod;
use App\Support\Filters\FilterableIndex;

class CashFlowController extends Controller
{
    use FilterableIndex;

    protected function configureCashFlowFilters(): void
    {
        $this->searchable = ['code', 'description', 'reference_code', 'target_name', 'category'];
        $this->sortable = ['code', 'time', 'type', 'amount', 'category', 'created_at'];
        $this->dateColumn = 'time';
        $this->scalarFilters = ['type', 'payment_method', 'status', 'bank_account_id', 'category', 'target_type'];
    }

    public function index(Request $request)
    {
        $this->configureCashFlowFilters();

        $query = CashFlow::query();
        $this->applyFilters($query, $request);
        $cashFlows = $query->paginate(15)->withQueryString();

        // Summary metrics
        $totalReceipts = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount');
        $totalPayments = CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
        $fundBalance = $totalReceipts - $totalPayments;

        $customers = \App\Models\Customer::where('is_supplier', false)->get(['id', 'name', 'phone']);
        $suppliers = \App\Models\Customer::where('is_supplier', true)->get(['id', 'name', 'phone']);

        $savedReceiptCategories = CashFlow::where('type', 'receipt')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->toArray();
        $savedPaymentCategories = CashFlow::where('type', 'payment')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->toArray();

        $filterOptions = [
            'types' => [
                ['value' => 'receipt', 'label' => 'Phiếu thu'],
                ['value' => 'payment', 'label' => 'Phiếu chi'],
            ],
            'paymentMethods' => PaymentMethod::cashFlowOptions(),
            'statuses' => [
                ['value' => 'active', 'label' => 'Đã ghi nhận'],
                ['value' => 'cancelled', 'label' => 'Đã hủy'],
            ],
            'bankAccounts' => BankAccount::where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name as name'])->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
            'categories' => collect(array_merge($savedReceiptCategories, $savedPaymentCategories))->unique()->values()->map(fn($c) => ['value' => $c, 'label' => $c]),
            'categoryGroups' => [
                'receipt' => collect($savedReceiptCategories)->unique()->values()->map(fn($c) => [
                    'value' => $c,
                    'label' => $c,
                    'type' => 'receipt',
                    'group' => 'Loại thu',
                ]),
                'payment' => collect($savedPaymentCategories)->unique()->values()->map(fn($c) => [
                    'value' => $c,
                    'label' => $c,
                    'type' => 'payment',
                    'group' => 'Loại chi',
                ]),
            ],
            'targetTypes' => [
                ['value' => 'customer', 'label' => 'Khách hàng'],
                ['value' => 'supplier', 'label' => 'Nhà cung cấp'],
                ['value' => 'employee', 'label' => 'Nhân viên'],
                ['value' => 'other', 'label' => 'Khác'],
            ],
        ];

        return Inertia::render('CashFlows/Index', [
            'cashFlows' => $cashFlows,
            'filters' => $this->currentFilters($request),
            'filterOptions' => $filterOptions,
            'metrics' => [
                'totalReceipts' => $totalReceipts,
                'totalPayments' => $totalPayments,
                'fundBalance' => $fundBalance,
            ],
            'subjects' => [
                'customers' => $customers,
                'suppliers' => $suppliers,
            ],
            'bankAccounts' => BankAccount::where('status', 'active')->orderBy('bank_name')->get(),
            'savedReceiptCategories' => $savedReceiptCategories,
            'savedPaymentCategories' => $savedPaymentCategories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:receipt,payment',
            'amount' => 'required|numeric|min:0',
            'time' => 'nullable|date',
            'category' => 'nullable|string',
            'target_type' => 'nullable|string',
            'target_name' => 'nullable|string',
            'accounting_result' => 'boolean',
            'description' => 'nullable|string',
            'payment_method' => 'nullable|in:cash,bank,ewallet',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        $prefix = $request->type === 'receipt' ? 'PT' : 'PC';

        // Lock period check
        $txDate = $request->time ? \Carbon\Carbon::parse($request->time) : now();
        app(LockPeriodService::class)->assertNotLocked($txDate, 'cashflow_create');

        $cashFlow = CashFlow::create([
            'code' => $prefix . date('ymdHis') . rand(10, 99),
            'type' => $request->type,
            'amount' => $request->amount,
            'time' => $request->time ? \Carbon\Carbon::parse($request->time) : now(),
            'category' => $request->category,
            'target_type' => $request->target_type,
            'target_name' => $request->target_name,
            'accounting_result' => $request->has('accounting_result') ? $request->accounting_result : true,
            'payment_method' => $request->payment_method ?? 'cash',
            'bank_account_id' => $request->payment_method !== 'cash' ? $request->bank_account_id : null,
            'description' => $request->description,
        ]);

        $typeLabel = $request->type === 'receipt' ? 'thu' : 'chi';
        ActivityLog::log('cashflow_create', "Tạo phiếu {$typeLabel} {$cashFlow->code}, số tiền: " . number_format($cashFlow->amount), $cashFlow);

        if ($request->boolean('_print')) {
            return redirect()->back()->with(['success' => 'Tạo phiếu thành công', 'print_id' => $cashFlow->id]);
        }

        return redirect()->back()->with('success', 'Tạo phiếu thành công');
    }

    public function storeSubject(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'is_supplier' => 'boolean'
        ]);

        $isSupplier = $request->input('is_supplier', false);
        $validated['code'] = ($isSupplier ? 'NCC' : 'KH') . time() . rand(10, 99);
        $validated['is_supplier'] = $isSupplier;
        $validated['is_customer'] = !$isSupplier;

        \App\Models\Customer::create($validated);

        return redirect()->back()->with('success', 'Tạo đối tượng thành công');
    }

    public function update(Request $request, CashFlow $cashFlow)
    {
        $request->validate([
            'time' => 'nullable|date',
            'category' => 'nullable|string|max:255',
            'target_type' => 'nullable|string',
            'target_name' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'accounting_result' => 'boolean',
            'payment_method' => 'nullable|in:cash,bank,ewallet',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        $txDate = $request->time ? \Carbon\Carbon::parse($request->time) : $cashFlow->time;
        app(LockPeriodService::class)->assertNotLocked($txDate, 'cashflow_update');

        $oldCategory = $cashFlow->category;

        $cashFlow->update([
            'time' => $request->time ? \Carbon\Carbon::parse($request->time) : $cashFlow->time,
            'category' => $request->category,
            'target_type' => $request->target_type,
            'target_name' => $request->target_name,
            'amount' => $request->amount,
            'description' => $request->description,
            'accounting_result' => $request->has('accounting_result') ? $request->accounting_result : $cashFlow->accounting_result,
            'payment_method' => $request->payment_method ?? $cashFlow->payment_method,
            'bank_account_id' => ($request->payment_method ?? $cashFlow->payment_method) !== 'cash' ? $request->bank_account_id : null,
        ]);

        if ($oldCategory !== $cashFlow->category) {
            ActivityLog::log(
                'cashflow_update_category',
                "Cập nhật loại thu/chi phiếu {$cashFlow->code}: {$oldCategory} -> {$cashFlow->category}",
                $cashFlow
            );
        }

        return redirect()->back()->with('success', 'Cập nhật phiếu thành công');
    }

    public function destroy(CashFlow $cashFlow)
    {
        // Lock period check
        app(LockPeriodService::class)->assertNotLocked($cashFlow->time, 'cashflow_cancel');

        ActivityLog::log('cashflow_cancel', "Hủy phiếu {$cashFlow->code}, số tiền: " . number_format($cashFlow->amount), $cashFlow);
        $cashFlow->update(['status' => 'cancelled']);
        $cashFlow->delete(); // soft-delete
        return redirect()->back()->with('success', 'Huỷ phiếu thành công');
    }

    public function print(CashFlow $cashFlow)
    {
        $cashFlow->load('bankAccount');
        return view('prints.cashflow', compact('cashFlow'));
    }

    public function export(Request $request)
    {
        $this->configureCashFlowFilters();
        $query = CashFlow::query();
        $this->applyFilters($query, $request);
        $flows = $query->get();

        return \App\Services\CsvService::export(
            ['Mã phiếu', 'Thời gian', 'Loại', 'Giá trị', 'Người nộp/nhận', 'Hạng mục', 'Phương thức', 'Ghi chú'],
            $flows->map(fn($f) => [$f->code, $f->time, $f->type === 'receipt' ? 'Thu' : 'Chi', $f->amount, $f->target_name, $f->category, $f->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản', $f->description]),
            'so_quy.csv'
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 4 || empty(trim($row[0] ?? ''))) continue;
            $type = mb_strtolower(trim($row[2] ?? '')) === 'thu' ? 'receipt' : 'payment';
            CashFlow::create([
                'code' => trim($row[0]),
                'time' => trim($row[1] ?? '') ?: now(),
                'type' => $type,
                'amount' => (float) preg_replace('/[^0-9.]/', '', $row[3] ?? '0'),
                'target_name' => trim($row[4] ?? ''),
                'category' => trim($row[5] ?? ''),
                'payment_method' => mb_stripos(trim($row[6] ?? ''), 'chuyển') !== false ? 'bank' : 'cash',
                'description' => trim($row[7] ?? ''),
            ]);
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} bút toán từ file.");
    }

    /**
     * Chuyen quy noi bo — tao phieu chi nguon + phieu thu doi ung dich.
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'from_method' => 'required|in:cash,bank,ewallet',
            'to_method' => 'required|in:cash,bank,ewallet',
            'description' => 'nullable|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        if ($request->from_method === $request->to_method) {
            return response()->json(['success' => false, 'message' => 'Quy nguon va quy dich phai khac nhau.'], 422);
        }

        $refCode = 'CQ' . date('ymdHis') . rand(10, 99);

        // Phieu chi o quy nguon
        $payment = CashFlow::create([
            'code' => 'PC' . date('ymdHis') . rand(10, 99),
            'type' => 'payment',
            'amount' => $request->amount,
            'time' => now(),
            'category' => 'Chuyển quỹ nội bộ',
            'payment_method' => $request->from_method,
            'reference_type' => 'transfer',
            'reference_code' => $refCode,
            'description' => $request->description ?? 'Chuyển quỹ nội bộ',
            'status' => 'active',
        ]);

        // Phieu thu doi ung o quy dich
        $receipt = CashFlow::create([
            'code' => 'PT' . date('ymdHis') . rand(10, 99),
            'type' => 'receipt',
            'amount' => $request->amount,
            'time' => now(),
            'category' => 'Chuyển quỹ nội bộ',
            'payment_method' => $request->to_method,
            'bank_account_id' => $request->bank_account_id,
            'reference_type' => 'transfer',
            'reference_code' => $refCode,
            'description' => $request->description ?? 'Chuyển quỹ nội bộ',
            'status' => 'active',
        ]);

        ActivityLog::log('cashflow_transfer', "Chuyển quỹ {$refCode}: " . number_format($request->amount) . " ({$request->from_method} -> {$request->to_method})");

        return response()->json([
            'success' => true,
            'message' => 'Chuyển quỹ thành công.',
            'payment_id' => $payment->id,
            'receipt_id' => $receipt->id,
            'reference_code' => $refCode,
        ]);
    }
}
