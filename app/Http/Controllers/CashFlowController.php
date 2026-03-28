<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashFlowController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        // Lấy danh sách phiếu thu/chi có phân trang
        $cashFlows = CashFlow::when($search, function ($query, $search) {
            return $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhere('reference_code', 'LIKE', "%{$search}%")
                    ->orWhere('target_name', 'LIKE', "%{$search}%")
                    ->orWhere('category', 'LIKE', "%{$search}%");
            });
        })
            ->when($request->filled('sort_by'), function ($query) use ($request) {
                $allowed = ['code', 'time', 'type', 'amount', 'target_name', 'category', 'created_at'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'time';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $query->orderBy($sortBy, $dir);
            }, function ($query) {
                $query->orderBy('time', 'desc')->orderBy('created_at', 'desc');
            })
            ->paginate(15)
            ->withQueryString();

        // Tính tồn quỹ tổng quan
        $totalReceipts = CashFlow::where('type', 'receipt')->sum('amount');
        $totalPayments = CashFlow::where('type', 'payment')->sum('amount');
        $fundBalance = $totalReceipts - $totalPayments;

        // Fetch base subjects for UI auto-complete simulation
        $customers = \App\Models\Customer::where('is_supplier', false)->get(['id', 'name', 'phone']);
        $suppliers = \App\Models\Customer::where('is_supplier', true)->get(['id', 'name', 'phone']);

        // Load user-created categories from existing records
        $savedReceiptCategories = CashFlow::where('type', 'receipt')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->toArray();
        $savedPaymentCategories = CashFlow::where('type', 'payment')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->toArray();

        return Inertia::render('CashFlows/Index', [
            'cashFlows' => $cashFlows,
            'filters' => ['search' => $search, 'sort_by' => $request->sort_by, 'sort_direction' => $request->sort_direction],
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

        $validated['code'] = ($request->input('is_supplier', false) ? 'NCC' : 'KH') . time() . rand(10, 99);
        $validated['is_supplier'] = $request->input('is_supplier', false);

        \App\Models\Customer::create($validated);

        return redirect()->back()->with('success', 'Tạo đối tượng thành công');
    }

    public function update(Request $request, CashFlow $cashFlow)
    {
        $request->validate([
            'time' => 'nullable|date',
            'category' => 'nullable|string',
            'target_type' => 'nullable|string',
            'target_name' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'accounting_result' => 'boolean',
            'payment_method' => 'nullable|in:cash,bank,ewallet',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

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

        return redirect()->back()->with('success', 'Cập nhật phiếu thành công');
    }

    public function destroy(CashFlow $cashFlow)
    {
        $cashFlow->delete();
        return redirect()->back()->with('success', 'Huỷ phiếu thành công');
    }

    public function print(CashFlow $cashFlow)
    {
        $cashFlow->load('bankAccount');
        return view('prints.cashflow', compact('cashFlow'));
    }

    public function export(Request $request)
    {
        $flows = CashFlow::query()
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%")->orWhere('description', 'LIKE', "%{$s}%"))
            ->orderBy('time', 'desc')->orderBy('id', 'desc')->get();

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
}
