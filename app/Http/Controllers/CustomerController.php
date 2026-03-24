<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

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

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'type' => $type, 'gender' => $gender, 'sort_by' => $request->sort_by, 'sort_direction' => $request->sort_direction],
            'customerSettings' => $customerSettings,
        ]);
    }

    public function store(Request $request)
    {
        // Build dynamic validation rules based on settings
        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:customers,code',
            'phone' => (Setting::get('customer_required_phone', false) ? 'required' : 'nullable') . '|string|max:255',
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

        Customer::create($validated);

        return redirect()->route('customers.index')->with('success', 'Tạo khách hàng thành công.');
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => (Setting::get('customer_required_phone', false) ? 'required' : 'nullable') . '|string|max:255',
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

        $customer->update($validated);

        return back()->with('success', 'Cập nhật khách hàng thành công.');
    }

    public function destroy(Customer $customer)
    {
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

        // 1) Invoices = "Bán hàng" entries (create debt)
        $invoices = Invoice::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'code', 'total', 'customer_paid', 'created_at']);

        foreach ($invoices as $inv) {
            $entries->push([
                'id' => 'inv-' . $inv->id,
                'code' => $inv->code,
                'type' => 'Bán hàng',
                'amount' => $inv->total,
                'created_at' => $inv->created_at,
            ]);

            // Implicit payment entry from customer_paid
            if ($inv->customer_paid > 0) {
                $entries->push([
                    'id' => 'pay-' . $inv->id,
                    'code' => 'TTHD' . preg_replace('/^HD/', '', $inv->code),
                    'type' => 'Thanh toán',
                    'amount' => -$inv->customer_paid,
                    'created_at' => $inv->created_at,
                ]);
            }
        }

        // 2) Explicit cash_flow receipts linked to this customer
        $cashFlows = CashFlow::where('target_type', 'Khách hàng')
            ->where('target_id', $customer->id)
            ->where('type', 'receipt')
            ->orderBy('created_at', 'desc')
            ->get();

        // Avoid duplicates: skip cash_flows whose reference_code matches an invoice we already handled
        $invoiceCodes = $invoices->pluck('code')->toArray();
        foreach ($cashFlows as $cf) {
            if ($cf->reference_type === 'Invoice' && in_array($cf->reference_code, $invoiceCodes)) {
                continue;
            }
            $entries->push([
                'id' => 'cf-' . $cf->id,
                'code' => $cf->code,
                'type' => 'Thanh toán',
                'amount' => -$cf->amount,
                'created_at' => $cf->created_at,
            ]);
        }

        // Sort by date asc to compute running balance
        $sorted = $entries->sortBy('created_at')->values();
        $balance = 0;
        $ledger = $sorted->map(function ($entry) use (&$balance) {
            $balance += $entry['amount'];
            $entry['balance'] = $balance;
            return $entry;
        });

        // Return newest first for display
        return response()->json([
            'entries' => $ledger->reverse()->values(),
        ]);
    }

    public function debtPayment(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:500',
        ]);

        $cf = CashFlow::create([
            'code' => 'PT' . date('ymdHis') . rand(10, 99),
            'type' => 'receipt',
            'amount' => $validated['amount'],
            'time' => now(),
            'category' => 'Thu nợ khách hàng',
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'DebtPayment',
            'reference_code' => null,
            'description' => $validated['note'] ?? 'Thu nợ khách hàng ' . $customer->name,
        ]);

        $customer->decrement('debt_amount', $validated['amount']);

        return back()->with('success', 'Đã thu nợ ' . number_format($validated['amount']) . ' từ khách hàng.');
    }

    public function debtAdjust(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'note' => 'nullable|string|max:500',
        ]);

        $adjustAmount = $validated['amount'];
        $type = $adjustAmount >= 0 ? 'receipt' : 'payment';
        $prefix = $adjustAmount >= 0 ? 'PT' : 'PC';

        CashFlow::create([
            'code' => $prefix . date('ymdHis') . rand(10, 99),
            'type' => $type,
            'amount' => abs($adjustAmount),
            'time' => now(),
            'category' => 'Điều chỉnh công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'DebtAdjustment',
            'reference_code' => null,
            'description' => $validated['note'] ?? 'Điều chỉnh công nợ khách hàng ' . $customer->name,
        ]);

        $customer->update(['debt_amount' => max(0, $customer->debt_amount - $adjustAmount)]);

        return back()->with('success', 'Đã điều chỉnh công nợ thành công.');
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
}
