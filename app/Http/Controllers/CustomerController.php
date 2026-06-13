<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\SupplierDebtTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\CustomerDebtService;
use App\Services\CustomerPaymentDiscountService;
use App\Services\CustomerPaymentService;
use App\Services\PartnerMergeService;
use App\Services\PartnerFinancialTimelineService;
use App\Models\CustomerPaymentDiscount;
use App\Services\DebtOffsetService;
use App\Models\DebtOffset;
use App\Support\Filters\FilterableIndex;
use App\Support\Filters\DateRangePresets;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use FilterableIndex;

    protected function configureCustomerFilters(): void
    {
        $this->searchable = ['code', 'name', 'phone', 'phone2', 'email', 'tax_code'];
        $this->sortable = ['code', 'name', 'phone', 'debt_amount', 'total_spent', 'total_returns', 'created_at'];
        $this->dateColumn = 'created_at';
        $this->creatorColumn = 'created_by';
        $this->scalarFilters = ['type', 'gender', 'customer_group', 'branch_id', 'city', 'district'];
    }

    /**
     * Build capabilities — tells the frontend which advanced filters are available.
     */
    private function buildCapabilities(): array
    {
        $hasInvoiceTxDate = Schema::hasColumn('invoices', 'transaction_date');
        return [
            'supportsBirthdayFilter'        => true,
            'supportsLastTransactionFilter'  => true,
            'supportsTotalSalesTimeFilter'   => true,
            'supportsDebtDaysFilter'         => false,
            'supportsPointsFilter'           => false,
            'supportsDeliveryAreaFilter'     => true,
            'supportsCreatedByFilter'        => Schema::hasColumn('customers', 'created_by'),
        ];
    }

    /**
     * Safe invoice date expression: COALESCE(transaction_date, created_at) if column exists.
     */
    private function invoiceDateExpr(): string
    {
        return Schema::hasColumn('invoices', 'transaction_date')
            ? 'COALESCE(transaction_date, created_at)'
            : 'created_at';
    }

    /**
     * Apply all advanced KiotViet-style customer filters.
     * Called by both index() and export() for consistency.
     */
    private function applyAdvancedCustomerFilters($query, Request $request): void
    {
        // Branch auto-lock when setting enabled
        if (Setting::get('customer_manage_by_branch', false) && auth()->user()?->branch_id) {
            $query->where('branch_id', auth()->user()->branch_id);
        }

        // Partner type: customer | customer_supplier
        if ($request->filled('partner_type')) {
            $hasIsSupplierColumn = Schema::hasColumn('customers', 'is_supplier');
            if ($request->partner_type === 'customer') {
                $query->where('is_customer', true);
                if ($hasIsSupplierColumn) {
                    $query->where(function ($q) {
                        $q->where('is_supplier', false)->orWhereNull('is_supplier');
                    });
                }
            } elseif ($request->partner_type === 'customer_supplier') {
                $query->where('is_customer', true);
                if ($hasIsSupplierColumn) {
                    $query->where('is_supplier', true);
                } else {
                    $query->whereRaw('0 = 1');
                }
            }
        }

        // Net debt range: debt_amount - supplier_debt_amount
        $hasSupplierDebtColumn = Schema::hasColumn('customers', 'supplier_debt_amount');
        $supplierDebtExprForFilter = $hasSupplierDebtColumn
            ? 'COALESCE(customers.supplier_debt_amount, 0)'
            : '0';
        $netDebtExpr = DB::raw("(COALESCE(customers.debt_amount, 0) - $supplierDebtExprForFilter)");

        if ($request->filled('net_debt_from') || $request->filled('net_debt_to')) {
            if ($request->filled('net_debt_from')) {
                $query->where($netDebtExpr, '>=', (float) $request->net_debt_from);
            }
            if ($request->filled('net_debt_to')) {
                $query->where($netDebtExpr, '<=', (float) $request->net_debt_to);
            }
        }

        // Legacy has_debt shortcut (uses net debt)
        if ($request->filled('has_debt')) {
            if ($request->has_debt === 'yes') {
                $query->where($netDebtExpr, '>', 0);
            } elseif ($request->has_debt === 'no') {
                $query->where($netDebtExpr, '<=', 0);
            }
        }

        // Birthday range — supports preset (birthday_filter) OR direct from/to.
        // If no preset given but bare from/to provided, treat as 'custom' for back-compat.
        $birthdayPreset = $request->input('birthday_filter')
            ?: (($request->filled('birthday_from') || $request->filled('birthday_to')) ? 'custom' : null);
        [$birthdayFrom, $birthdayTo] = DateRangePresets::resolve(
            $birthdayPreset,
            $request->input('birthday_from'),
            $request->input('birthday_to'),
        );
        if ($birthdayFrom) {
            $query->whereDate('birthday', '>=', $birthdayFrom->toDateString());
        }
        if ($birthdayTo) {
            $query->whereDate('birthday', '<=', $birthdayTo->toDateString());
        }

        // Last transaction date (max invoice transaction_date for this customer)
        // Supports preset (last_transaction_filter) OR direct from/to.
        $lastTxPreset = $request->input('last_transaction_filter')
            ?: (($request->filled('last_transaction_from') || $request->filled('last_transaction_to')) ? 'custom' : null);
        [$lastTxFrom, $lastTxTo] = DateRangePresets::resolve(
            $lastTxPreset,
            $request->input('last_transaction_from'),
            $request->input('last_transaction_to'),
        );
        if ($lastTxFrom || $lastTxTo) {
            $subquery = Invoice::selectRaw('MAX(' . $this->invoiceDateExpr() . ')')
                ->whereColumn('invoices.customer_id', 'customers.id');

            if ($lastTxFrom) {
                $query->where(function ($q) use ($subquery, $lastTxFrom) {
                    $q->whereRaw('(' . $subquery->toSql() . ') >= ?', array_merge($subquery->getBindings(), [$lastTxFrom->toDateTimeString()]));
                });
            }
            if ($lastTxTo) {
                $query->where(function ($q) use ($subquery, $lastTxTo) {
                    $q->whereRaw('(' . $subquery->toSql() . ') <= ?', array_merge($subquery->getBindings(), [$lastTxTo->toDateTimeString()]));
                });
            }
        }

        // Total sales range (lifetime or time-scoped via preset/custom)
        $hasTotalSalesFilter = $request->filled('total_sales_from') || $request->filled('total_sales_to');
        $totalSalesPreset = $request->input('total_sales_date_filter')
            ?: (($request->filled('total_sales_date_from') || $request->filled('total_sales_date_to')) ? 'custom' : null);
        [$totalSalesFrom, $totalSalesTo] = DateRangePresets::resolve(
            $totalSalesPreset,
            $request->input('total_sales_date_from'),
            $request->input('total_sales_date_to'),
        );
        $hasTotalSalesTime = $totalSalesFrom || $totalSalesTo;

        if ($hasTotalSalesFilter) {
            if ($hasTotalSalesTime) {
                // Time-scoped: must compute from invoices
                $sumSubquery = Invoice::selectRaw('COALESCE(SUM(total), 0)')
                    ->whereColumn('invoices.customer_id', 'customers.id');

                if ($totalSalesFrom) {
                    $sumSubquery->where(DB::raw($this->invoiceDateExpr()), '>=', $totalSalesFrom->toDateTimeString());
                }
                if ($totalSalesTo) {
                    $sumSubquery->where(DB::raw($this->invoiceDateExpr()), '<=', $totalSalesTo->toDateTimeString());
                }

                if ($request->filled('total_sales_from')) {
                    $query->whereRaw('(' . $sumSubquery->toSql() . ') >= ?', array_merge($sumSubquery->getBindings(), [(float) $request->total_sales_from]));
                }
                if ($request->filled('total_sales_to')) {
                    $query->whereRaw('(' . $sumSubquery->toSql() . ') <= ?', array_merge($sumSubquery->getBindings(), [(float) $request->total_sales_to]));
                }
            } else {
                // Lifetime: use materialized customers.total_spent
                if ($request->filled('total_sales_from')) {
                    $query->where('total_spent', '>=', (float) $request->total_sales_from);
                }
                if ($request->filled('total_sales_to')) {
                    $query->where('total_spent', '<=', (float) $request->total_sales_to);
                }
            }
        }

        // Delivery area (customers.city/district/ward)
        if ($request->filled('delivery_city')) {
            $query->where('city', $request->delivery_city);
        }
        if ($request->filled('delivery_district')) {
            $query->where('district', $request->delivery_district);
        }

        // Standard filters via FilterableIndex
        $this->applyFilters($query, $request);
    }

    public function index(Request $request)
    {
        $this->configureCustomerFilters();

        $query = Customer::with('branch');
        
        $hasSupplierDebtColumn = Schema::hasColumn('customers', 'supplier_debt_amount');

        // If request sorts by debt_amount, intercept and sort by net debt expression if supplier_debt_amount column exists
        if ($request->input('sort_by') === 'debt_amount') {
            $direction = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
            $supplierDebtExprForSort = $hasSupplierDebtColumn
                ? 'COALESCE(customers.supplier_debt_amount, 0)'
                : '0';
            $query->orderByRaw("(COALESCE(customers.debt_amount, 0) - {$supplierDebtExprForSort}) {$direction}");
            $request->merge(['sort_by' => null]);
        }

        $this->applyAdvancedCustomerFilters($query, $request);

        // Clone query BEFORE paginate for filtered summary
        $summaryQuery = clone $query;

        $customers = $query->paginate(15)->withQueryString();

        $customers->getCollection()->transform(function ($customer) use ($hasSupplierDebtColumn) {
            $customerDebt = (float) ($customer->debt_amount ?? 0);
            $supplierDebt = $hasSupplierDebtColumn
                ? (float) ($customer->supplier_debt_amount ?? 0)
                : 0.0;
            $netDebt = $customerDebt - $supplierDebt;

            $customer->net_debt_amount = $netDebt;
            $customer->net_debt_direction = $netDebt > 0
                ? 'customer_owes_store'
                : ($netDebt < 0 ? 'store_owes_customer_supplier' : 'settled');

            $customer->net_debt_label = $netDebt > 0
                ? 'Khách còn nợ'
                : ($netDebt < 0 ? 'Mình còn nợ lại' : 'Hết nợ');

            // HOTFIX FOLLOW-UP — canonical receivable/payable/net keys.
            // Old `net_debt_amount` retained for backward compatibility;
            // new keys make it explicit this is a display delta, NOT a
            // recorded offset voucher.
            $customer->customer_receivable_balance = $customerDebt;
            $customer->supplier_payable_balance    = $supplierDebt;
            $customer->partner_net_position        = $netDebt;

            return $customer;
        });

        $customerSettings = [
            'customer_debt_warning' => Setting::get('customer_debt_warning', true),
            'customer_is_vendor' => Setting::get('customer_is_vendor', false),
            'customer_manage_by_branch' => Setting::get('customer_manage_by_branch', false),
        ];

        $customerDebtExpr = 'COALESCE(customers.debt_amount, 0)';
        $supplierDebtExpr = $hasSupplierDebtColumn
            ? 'COALESCE(customers.supplier_debt_amount, 0)'
            : '0';
        $netDebtExpr = "($customerDebtExpr - $supplierDebtExpr)";

        // Summary from filtered query (not global)
        $summaryRows = (clone $summaryQuery)
            ->reorder()
            ->selectRaw("COALESCE(SUM(CASE WHEN $netDebtExpr > 0 THEN $netDebtExpr ELSE 0 END), 0) as total_positive_net_debt")
            ->selectRaw("COALESCE(SUM(CASE WHEN $netDebtExpr < 0 THEN -($netDebtExpr) ELSE 0 END), 0) as total_negative_net_debt")
            ->first();

        $summary = [
            'total_debt' => (float) ($summaryRows->total_positive_net_debt ?? 0),
            'total_store_owes' => (float) ($summaryRows->total_negative_net_debt ?? 0),
            'total_spent' => (float) (clone $summaryQuery)->sum('total_spent'),
            'total_returns' => (float) (clone $summaryQuery)->sum('total_returns'),
        ];

        $capabilities = $this->buildCapabilities();

        // Build branches (respect branch lock)
        $branchLocked = Setting::get('customer_manage_by_branch', false) && auth()->user()?->branch_id;
        $branches = $branchLocked
            ? \App\Models\Branch::select('id', 'name')->where('id', auth()->user()->branch_id)->get()
            : \App\Models\Branch::select('id', 'name')->orderBy('name')->get();

        // CustomerGroup options: master groups + legacy distinct values not yet in master
        $masterGroups = CustomerGroup::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($g) => ['value' => $g->name, 'label' => $g->name]);

        $legacyGroups = Customer::whereNotNull('customer_group')
            ->where('customer_group', '!=', '')
            ->distinct()->pluck('customer_group')
            ->diff($masterGroups->pluck('value'))
            ->map(fn($g) => ['value' => $g, 'label' => $g])
            ->values();

        $customerGroups = $masterGroups->concat($legacyGroups)->unique('value')->values();

        // Creators: users who have created customers
        $creators = $capabilities['supportsCreatedByFilter']
            ? \App\Models\User::select('id', 'name')
                ->whereIn('id', Customer::whereNotNull('created_by')->distinct()->pluck('created_by'))
                ->orderBy('name')->get()
            : collect();

        // Delivery areas (distinct cities from customers)
        $deliveryCities = Customer::whereNotNull('city')->where('city', '!=', '')
            ->distinct()->orderBy('city')->pluck('city')
            ->map(fn($c) => ['value' => $c, 'label' => $c])->values();

        $filterOptions = [
            'branches'       => $branches,
            'customerGroups' => $customerGroups,
            'types' => [
                ['value' => 'individual', 'label' => 'Cá nhân'],
                ['value' => 'company',    'label' => 'Công ty'],
            ],
            'genders' => [
                ['value' => 'male',   'label' => 'Nam'],
                ['value' => 'female', 'label' => 'Nữ'],
                ['value' => 'none',   'label' => 'Không xác định'],
            ],
            'statuses' => [
                ['value' => 'active',   'label' => 'Đang hoạt động'],
                ['value' => 'inactive', 'label' => 'Ngừng hoạt động'],
            ],
            'partnerTypes' => [
                ['value' => 'customer',          'label' => 'Khách hàng'],
                ['value' => 'customer_supplier', 'label' => 'Khách hàng - Nhà cung cấp'],
            ],
            'creators'       => $creators,
            'deliveryCities' => $deliveryCities,
            'debtOptions' => [
                ['value' => 'yes', 'label' => 'Còn nợ'],
                ['value' => 'no',  'label' => 'Không nợ'],
            ],
            'capabilities' => $capabilities,
        ];

        // Echo back all filter values (standard + advanced)
        $filters = $this->currentFilters($request);
        $filters['has_debt']              = $request->input('has_debt', '');
        $filters['partner_type']          = $request->input('partner_type', '');
        $filters['net_debt_from']         = $request->input('net_debt_from', '');
        $filters['net_debt_to']           = $request->input('net_debt_to', '');
        $filters['birthday_filter']           = $request->input('birthday_filter', 'all');
        $filters['birthday_from']             = $request->input('birthday_from', '');
        $filters['birthday_to']               = $request->input('birthday_to', '');
        $filters['last_transaction_filter']   = $request->input('last_transaction_filter', 'all');
        $filters['last_transaction_from']     = $request->input('last_transaction_from', '');
        $filters['last_transaction_to']       = $request->input('last_transaction_to', '');
        $filters['total_sales_from']          = $request->input('total_sales_from', '');
        $filters['total_sales_to']            = $request->input('total_sales_to', '');
        $filters['total_sales_date_filter']   = $request->input('total_sales_date_filter', 'all');
        $filters['total_sales_date_from']     = $request->input('total_sales_date_from', '');
        $filters['total_sales_date_to']       = $request->input('total_sales_date_to', '');
        $filters['delivery_city']         = $request->input('delivery_city', '');
        $filters['delivery_district']     = $request->input('delivery_district', '');

        return Inertia::render('Customers/Index', [
            'customers'        => $customers,
            'filters'          => $filters,
            'filterOptions'    => $filterOptions,
            'customerSettings' => $customerSettings,
            'summary'          => $summary,
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
        $validated['created_by'] = auth()->id();

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
            app(PartnerMergeService::class)->merge($customer, $existing);
            $existing->refresh();
            $existing->fill([
                'name' => $validated['name'] ?? $existing->name,
                'phone' => $validated['phone'] ?? $existing->phone,
                'address' => $validated['address'] ?? $existing->address,
                'is_customer' => true,
                'is_supplier' => true,
            ])->save();

            return back()->with('success', 'Cập nhật và gộp vào nhà cung cấp thành công.');
        } else {
            $customer->update($validated);
        }

        return back()->with('success', 'Cập nhật khách hàng thành công.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->merged_into_id !== null || $customer->mergedSources()->exists()) {
            return back()->with(
                'error',
                'Không thể xóa hồ sơ đối tác đã tham gia gộp. Hãy giữ hồ sơ để bảo toàn dấu vết kiểm toán.'
            );
        }

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

    /**
     * Step 22.1E (hybrid): trả cả ledger mới (customer_debts) lẫn legacy
     * (invoices/cashflows/purchases/...). Production có dữ liệu cũ trước RR-06
     * chưa được backfill vào customer_debts ⇒ chỉ đọc ledger sẽ làm mất lịch sử.
     *
     * Hợp đồng dữ liệu:
     *  - entries: combined view (ledger + legacy không trùng), mỗi item có 'source'
     *  - ledger_entries / legacy_entries: tách rời để UI có thể filter
     *  - summary.net = customers.debt_amount hiện tại (KHÔNG tính lại từ entries)
     *  - summary.source = 'hybrid'
     *
     * Dedup: nếu legacy.code trùng ledger.ref_code thì ưu tiên ledger.
     * Không backfill, không cập nhật customers.debt_amount, không sửa CustomerDebtService.
     */
    public function debtHistory(Request $request, Customer $customer)
    {
        $mode = $request->query('mode', 'document');

        if ($mode === 'legacy') {
            $ledger = app(\App\Services\PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);
        } else {
            $ledger = app(\App\Services\CustomerDebtDocumentTimelineService::class)->build($customer, $request->all());
        }

        // HOTFIX FOLLOW-UP — opt-in server-side pagination to match KiotViet
        // (10/page). Caller activates by sending ?page=N; without that
        // param, the full ledger is returned for backward compat with
        // existing tests/exports/scripts that iterate all entries.
        if ($request->has('page')) {
            $entries = collect($ledger['entries'] ?? []);
            $perPage = max(1, min(100, (int) $request->input('per_page', 10)));
            $total = $entries->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $currentPage = max(1, min($lastPage, (int) $request->input('page', 1)));
            $offset = ($currentPage - 1) * $perPage;

            return response()->json(array_merge($ledger, [
                'entries' => $entries->slice($offset, $perPage)->values(),
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $currentPage,
                    'last_page'    => $lastPage,
                    'from'         => $total === 0 ? 0 : $offset + 1,
                    'to'           => min($offset + $perPage, $total),
                ],
            ]));
        }

        return response()->json($ledger);
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
        $rules = [
            'amount' => ($mode === 'manual' ? 'nullable' : 'required') . '|numeric|min:1',
            'allocations' => $mode === 'manual' ? 'required|array|min:1' : 'nullable|array',
            'allocations.*.invoice_id' => 'required_with:allocations|integer|exists:invoices,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:1',
            'note' => 'nullable|string|max:500',
            'date' => 'nullable|date',
        ];
        $validated = $request->validate($rules);
        $allocations = $validated['allocations'] ?? [];
        $paymentAmount = (float) (
            $validated['amount']
            ?? collect($allocations)->sum(fn (array $allocation) => (float) $allocation['amount'])
        );

        $result = app(CustomerPaymentService::class)->collect(
            $customer,
            $paymentAmount,
            $mode,
            $allocations,
            $validated['note'] ?? null,
            $validated['date'] ?? null
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Da thu ' . number_format($result['payment_amount']) . ' tu khach hang.',
                'payment' => $result,
            ]);
        }

        return back()->with(
            'success',
            'Da thu ' . number_format($result['payment_amount']) . ' tu khach hang.'
        );
    }

    /**
     * Lấy danh sách hóa đơn còn nợ của khách hàng (cho modal thu nợ).
     */
    public function outstandingInvoices(Customer $customer)
    {
        $invoices = app(CustomerPaymentDiscountService::class)
            ->getCustomerReceivableInvoices($customer);

        return response()->json($invoices);
    }

    public function debtAdjust(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric', // Giá trị nợ cuối mong muốn
            'note' => 'nullable|string|max:500',
            'date' => 'nullable|date',
        ]);

        $targetDebt = $validated['amount']; // Nợ cuối user muốn set
        $currentDebt = (float) $customer->debt_amount;
        $diff = $currentDebt - $targetDebt; // diff > 0 = giảm nợ, diff < 0 = tăng nợ

        if ($diff == 0) {
            return back()->with('info', 'Công nợ không thay đổi.');
        }

        $type = $diff > 0 ? 'receipt' : 'payment';
        $prefix = $diff > 0 ? 'PT' : 'PC';
        $adjustedAt = !empty($validated['date']) ? \Carbon\Carbon::parse($validated['date']) : now();

        $cashFlow = CashFlow::create([
            'code' => $prefix . date('ymdHis') . rand(10, 99),
            'type' => $type,
            'amount' => abs($diff),
            'time' => $adjustedAt,
            'category' => 'Điều chỉnh công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'DebtAdjustment',
            'reference_code' => null,
            'description' => ($validated['note'] ?? 'Điều chỉnh công nợ') . ' | ' . number_format($currentDebt) . ' → ' . number_format($targetDebt),
        ]);
        // Override created_at để hiển thị trong lịch sử theo ngày người dùng chọn
        if (!empty($validated['date'])) {
            $cashFlow->created_at = $adjustedAt;
            $cashFlow->save();
        }

        // RR-06: ghi ledger adjustment qua service.
        // delta theo signed amount cho debt_amount: targetDebt - currentDebt.
        // Lưu ý: $diff trong code này = currentDebt - targetDebt (đảo dấu).
        $debtDelta = $targetDebt - $currentDebt;
        if (abs($debtDelta) >= 0.01) {
            app(CustomerDebtService::class)->recordAdjustment(
                $customer->id,
                $debtDelta,
                ($validated['note'] ?? 'Điều chỉnh công nợ')
                    . ' | ' . number_format($currentDebt) . ' → ' . number_format($targetDebt),
                ['ref_code' => $cashFlow->code]
            );
        }

        return back()->with('success', 'Đã điều chỉnh công nợ: ' . number_format($currentDebt) . ' → ' . number_format($targetDebt) . '₫');
    }

    public function searchForMerge(Request $request)
    {
        $q = $request->input('q');
        $type = $request->input('type'); // 'customer' or 'supplier'
        $exclude = $request->input('exclude');

        $results = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->when($q, function ($query, $q) {
                $query->where(function ($qb) use ($q) {
                    $qb->where('name', 'LIKE', "%{$q}%")
                       ->orWhere('phone', 'LIKE', "%{$q}%")
                       ->orWhere('code', 'LIKE', "%{$q}%");
                });
            })
            ->when($exclude, fn($qb, $id) => $qb->where('id', '!=', $id))
            ->when($type === 'supplier', fn ($qb) => $qb->where('is_supplier', true))
            ->when($type === 'customer', fn ($qb) => $qb->where('is_customer', true))
            ->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount', 'is_customer', 'is_supplier']);

        return response()->json($results);
    }

    /**
     * Step 22.2E: typeahead search cho Orders/Create (và các màn hình khác cần KH).
     * Schema-tolerant: chỉ áp is_customer / status nếu cột tồn tại.
     * Limit 20, không paginate.
     */
    public function apiSearch(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return response()->json([]);
        }

        $query = app(\App\Services\PartnerTransactionGuard::class)->availablePartners();

        if (Schema::hasColumn('customers', 'is_customer')) {
            $query->where('is_customer', true);
        }

        if (Schema::hasColumn('customers', 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'inactive');
            });
        }

        $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('code', 'LIKE', "%{$search}%")
              ->orWhere('phone', 'LIKE', "%{$search}%")
              ->orWhere('phone2', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%")
              ->orWhere('tax_code', 'LIKE', "%{$search}%");
        });

        $columns = ['id', 'code', 'name', 'phone', 'phone2', 'email', 'address',
                    'debt_amount', 'total_spent'];
        $columns = array_values(array_filter($columns, fn($c) => Schema::hasColumn('customers', $c)));

        $rows = $query->orderBy('name')->limit(20)->get($columns);

        return response()->json(
            $rows->map(function (Customer $c) {
                return [
                    'id'            => (int) $c->id,
                    'code'          => $c->code,
                    'name'          => $c->name,
                    'phone'         => $c->phone,
                    'phone2'        => $c->phone2 ?? null,
                    'email'         => $c->email ?? null,
                    'address'       => $c->address ?? null,
                    'debt_amount'   => isset($c->debt_amount) ? (float) $c->debt_amount : 0,
                    'total_spent'   => isset($c->total_spent) ? (float) $c->total_spent : 0,
                    'display_label' => trim(($c->name ?? '') . ($c->phone ? ' — ' . $c->phone : '')) ?: ('#' . $c->id),
                ];
            })->values()
        );
    }

    public function merge(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'merge_with_id' => 'required|integer|exists:customers,id',
        ]);

        $target = Customer::findOrFail($validated['merge_with_id']);
        $preview = app(PartnerMergeService::class)->merge($customer, $target);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'merge' => $preview]);
        }

        return back()->with('success', "Đã gộp thành công vào {$target->name} ({$target->code}).");
    }

    public function mergePreview(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'target_id' => 'required|integer|exists:customers,id',
        ]);

        return response()->json(
            app(PartnerMergeService::class)->preview(
                $customer,
                Customer::findOrFail($validated['target_id'])
            )
        );
    }

    public function export(Request $request)
    {
        $this->configureCustomerFilters();

        $query = Customer::with('branch');
        $this->applyAdvancedCustomerFilters($query, $request);
        $customers = $query->get();

        return \App\Services\CsvService::export(
            ['Mã KH', 'Tên khách hàng', 'Điện thoại', 'Email', 'Nhóm KH', 'Địa chỉ', 'Phường/Xã', 'Quận/Huyện', 'Tỉnh/TP', 'Công nợ', 'Tổng mua', 'Ghi chú'],
            $customers->map(fn($c) => [$c->code, $c->name, $c->phone, $c->email, $c->customer_group, $c->address, $c->ward, $c->district, $c->city, $c->debt_amount, $c->total_spent, $c->note]),
            'khach_hang.csv'
        );
    }

    public function exportDebtHistory(Customer $customer, Request $request)
    {
        // HOTFIX FOLLOW-UP — export must include ALL entries; bypass the
        // pagination layer added to debtHistory() for the UI tab.
        $mode = $request->query('mode', 'document');

        if ($mode === 'legacy') {
            $data = app(\App\Services\PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);
        } else {
            $data = app(\App\Services\CustomerDebtDocumentTimelineService::class)->build($customer, $request->all());
        }
        // Normalise to a plain array of associative arrays — historically
        // the export pulled this via getData(true) which produced this shape.
        $entries = collect($data['entries'] ?? [])
            ->map(fn ($e) => is_array($e) ? $e : (array) $e)
            ->all();

        $hasQuery = $request->hasAny(['date_preset', 'date_from', 'date_to', 'include_detail', 'columns', 'format']);

        if ($hasQuery) {
            $validated = $request->validate([
                'date_preset'    => 'nullable|string|in:today,this_week,last_7_days,last_30_days,this_month,last_month,this_quarter,this_year,all,custom',
                'date_from'      => ['nullable', 'string', 'regex:#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{4})$#'],
                'date_to'        => ['nullable', 'string', 'regex:#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{4})$#'],
                'include_detail' => 'nullable|in:0,1,true,false',
                'columns'        => 'nullable|array',
                'columns.*'      => 'string|in:unit,quantity,unit_price,discount,vat,cost,line_total,note',
                'format'         => 'nullable|string|in:csv,xlsx',
            ], [
                'date_from.regex' => 'Ngay bat dau phai co dinh dang dd/mm/yyyy hoac YYYY-MM-DD.',
                'date_to.regex'   => 'Ngay ket thuc phai co dinh dang dd/mm/yyyy hoac YYYY-MM-DD.',
            ]);

            foreach (['date_from', 'date_to'] as $key) {
                if (!empty($validated[$key]) && $this->parseDebtExportDate($validated[$key]) === null) {
                    return response()->json([
                        'message' => "Ngay {$key} khong hop le.",
                        'errors' => [$key => ["Ngay {$key} khong hop le."]],
                    ], 422);
                }
            }

            [$from, $to] = $this->resolveCustomerDebtExportRange(
                $validated['date_preset'] ?? 'all',
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );

            if ($from && $to && $from->greaterThan($to)) {
                return response()->json(['message' => 'date_from phai <= date_to'], 422);
            }

            $includeDetail = in_array((string) ($validated['include_detail'] ?? '0'), ['1', 'true'], true);
            $selectedColumns = array_values($validated['columns'] ?? []);

            if (($validated['format'] ?? '') === 'xlsx') {
                return (new \App\Services\Exports\CustomerDebtExcelExportService(
                    $customer,
                    is_array($entries) ? $entries : collect($entries)->toArray(),
                    $from,
                    $to,
                    $includeDetail,
                    $selectedColumns
                ))->download('cong_no_kh_' . ($customer->code ?: $customer->id) . '.xlsx');
            }

            $entries = collect($entries)->filter(function ($entry) use ($from, $to) {
                if (!$from && !$to) return true;
                $ts = $this->customerDebtEntryExportCarbon($entry);
                if (!$ts) return false;
                if ($from && $ts->lessThan($from)) return false;
                return !($to && $ts->greaterThan($to));
            })->values()->all();
        }

        return \App\Services\CsvService::export(
            ['Mã chứng từ', 'Loại', 'Giá trị', 'Dư nợ sau GD', 'Ngày'],
            collect($entries)->map(fn($e) => [$e['code'], $e['type'], $e['amount'], $e['balance'], $this->customerDebtEntryExportTime($e)]),
            "cong_no_kh_{$customer->code}.csv"
        );
    }

    private function customerDebtEntryExportRawTime(array $entry)
    {
        return $entry['display_time']
            ?? $entry['time']
            ?? $entry['recorded_at']
            ?? $entry['transaction_date']
            ?? $entry['purchase_date']
            ?? $entry['return_date']
            ?? $entry['created_at']
            ?? $entry['date']
            ?? null;
    }

    private function customerDebtEntryExportCarbon(array $entry): ?\Carbon\Carbon
    {
        $raw = $this->customerDebtEntryExportRawTime($entry);
        if (!$raw) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function customerDebtEntryExportTime(array $entry): string
    {
        $raw = $this->customerDebtEntryExportRawTime($entry);
        if (!$raw) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($raw)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    private function resolveCustomerDebtExportRange(string $preset, ?string $from, ?string $to): array
    {
        $now = \Carbon\Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                ($this->parseDebtExportDate($from))?->startOfDay(),
                ($this->parseDebtExportDate($to))?->endOfDay(),
            ],
            default => [null, null],
        };
    }

    private function parseDebtExportDate(?string $value): ?\Carbon\Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $value, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];
        } else {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return \Carbon\Carbon::create($year, $month, $day, 0, 0, 0);
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

    /**
     * Helper guard: check if customer has a debt reference matching the code
     */
    private function customerHasDebtRef(Customer $customer, string $code): bool
    {
        return \App\Models\CustomerDebt::where('customer_id', $customer->id)
            ->where('ref_code', $code)
            ->exists();
    }

    /**
     * Hotfix — Khách hàng/Công nợ: Bấm mã phiếu mở chi tiết chứng từ read-only giống KiotViet
     */
    public function debtVoucherDetail(Request $request, Customer $customer)
    {
        $code = $request->query('code');
        if (empty($code)) {
            return response()->json([
                'success' => false,
                'message' => 'Mã chứng từ không được để trống.'
            ], 422);
        }

        // 1. HD - Hóa đơn bán hàng
        if (str_starts_with($code, 'HD')) {
            $invoice = Invoice::where('code', $code)->first();
            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $belongsToCustomer = (int) $invoice->customer_id === (int) $customer->id
                || $this->customerHasDebtRef($customer, $invoice->code);

            if (!$belongsToCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $invoice->load(['customer', 'items.product', 'branch', 'employee']);

            $data = [
                'id' => $invoice->id,
                'code' => $invoice->code,
                'status' => $invoice->status,
                'created_at' => $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : '',
                'created_by_name' => $invoice->created_by_name ?? 'Admin',
                'seller_name' => $invoice->seller_name ?? ($invoice->employee->name ?? 'Admin'),
                'customer_name' => $invoice->customer->name ?? 'Khách lẻ',
                'branch_name' => $invoice->branch->name ?? '',
                'note' => $invoice->note,
                'subtotal' => $invoice->subtotal,
                'discount' => $invoice->discount,
                'total' => $invoice->total,
                'customer_paid' => $invoice->customer_paid,
                'effective_paid' => $invoice->customer_paid,
                'debt_amount' => max(0, $invoice->total - $invoice->customer_paid),
                'payment_method' => $invoice->payment_method,
                'items' => $invoice->items->map(fn($item) => [
                    'product_code' => $item->product->code ?? '',
                    'product_name' => $item->product->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount ?? 0,
                    'subtotal' => $item->subtotal,
                ]),
            ];

            return response()->json([
                'success' => true,
                'type' => 'invoice',
                'title' => 'Hóa đơn',
                'code' => $invoice->code,
                'data' => $data,
            ]);
        }

        // 2. PN - Phiếu nhập hàng
        if (str_starts_with($code, 'PN')) {
            $purchase = Purchase::where('code', $code)->first();
            if (!$purchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $belongsToCustomer = (int) $purchase->supplier_id === (int) $customer->id
                || $this->customerHasDebtRef($customer, $purchase->code);

            if (!$belongsToCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $purchase->load(['supplier', 'items.product', 'user', 'employee']);

            $data = [
                'id' => $purchase->id,
                'code' => $purchase->code,
                'status' => $purchase->status,
                'status_label' => $purchase->status === 'completed' ? 'Đã nhập hàng' : ($purchase->status === 'returned' ? 'Đã trả hàng' : ($purchase->status === 'cancelled' ? 'Đã hủy' : ucfirst($purchase->status))),
                'purchase_date' => $purchase->purchase_date ? $purchase->purchase_date->format('d/m/Y H:i') : ($purchase->created_at ? $purchase->created_at->format('d/m/Y H:i') : ''),
                'user_name' => $purchase->user->name ?? 'Admin',
                'employee_name' => $purchase->employee->name ?? null,
                'supplier_name' => $purchase->supplier->name ?? '',
                'supplier_code' => $purchase->supplier->code ?? '',
                'note' => $purchase->note,
                'total_amount' => $purchase->total_amount,
                'discount' => $purchase->discount,
                'paid_amount' => $purchase->paid_amount,
                'debt_amount' => $purchase->debt_amount,
                'payment_method' => $purchase->payment_method,
                'items' => $purchase->items->map(fn($item) => [
                    'product_code' => $item->product->code ?? '',
                    'product_name' => $item->product->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'discount' => $item->discount ?? 0,
                    'subtotal' => $item->subtotal,
                ]),
            ];

            return response()->json([
                'success' => true,
                'type' => 'purchase',
                'title' => 'Phiếu nhập hàng',
                'code' => $purchase->code,
                'data' => $data,
            ]);
        }

        // 3. PT / TTHD - Phiếu thu / thanh toán
        if (str_starts_with($code, 'PT') || str_starts_with($code, 'TTHD')) {
            $cashFlow = CashFlow::where('code', $code)->first();

            if (!$cashFlow && str_starts_with($code, 'TTHD')) {
                $invoiceCode = 'HD' . substr($code, 4);
                $cashFlow = CashFlow::where('reference_type', 'Invoice')
                    ->where('reference_code', $invoiceCode)
                    ->where('type', 'receipt')
                    ->first();
            }

            if (!$cashFlow && str_starts_with($code, 'TTHD')) {
                $invoiceCode = 'HD' . substr($code, 4);
                $invoice = Invoice::with('customer')->where('code', $invoiceCode)->first();
                if ($invoice) {
                    $belongsToCustomer = (int) $invoice->customer_id === (int) $customer->id
                        || $this->customerHasDebtRef($customer, $invoice->code);

                    if (!$belongsToCustomer) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                        ], 404);
                    }

                    return response()->json([
                        'success' => true,
                        'type' => 'cashflow',
                        'title' => 'Thanh toán hóa đơn',
                        'code' => $code,
                        'data' => [
                            'id' => null,
                            'code' => $code,
                            'type' => 'receipt', // Phiếu thu
                            'status' => 'completed',
                            'amount' => (float) $invoice->customer_paid,
                            'time' => $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : '',
                            'category' => 'Thu tiền khách hàng',
                            'target_type' => 'Khách hàng',
                            'target_name' => $invoice->customer->name ?? 'Khách lẻ',
                            'payment_method' => $invoice->payment_method ?? 'Tiền mặt',
                            'bank_account_name' => null,
                            'reference_type' => 'Invoice',
                            'reference_code' => $invoice->code,
                            'description' => 'Thanh toán tự động khi tạo hóa đơn ' . $invoice->code,
                            'created_at' => $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : '',
                        ]
                    ]);
                }
            }

            if (!$cashFlow) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $belongsToCustomer = ((int) $cashFlow->target_id === (int) $customer->id && $cashFlow->target_type === 'Khách hàng')
                || $this->customerHasDebtRef($customer, $cashFlow->code);

            if (!$belongsToCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $cashFlow->load('bankAccount');

            $data = [
                'id' => $cashFlow->id,
                'code' => $cashFlow->code,
                'type' => $cashFlow->type,
                'amount' => $cashFlow->amount,
                'time' => $cashFlow->time ? (\Carbon\Carbon::parse($cashFlow->time)->format('d/m/Y H:i')) : '',
                'category' => $cashFlow->category,
                'target_type' => $cashFlow->target_type,
                'target_name' => $cashFlow->target_name,
                'payment_method' => $cashFlow->payment_method,
                'bank_account_name' => $cashFlow->bankAccount ? ($cashFlow->bankAccount->bank_name . ' - ' . $cashFlow->bankAccount->account_number) : null,
                'reference_type' => $cashFlow->reference_type,
                'reference_code' => $cashFlow->reference_code,
                'description' => $cashFlow->description,
                'status' => $cashFlow->status,
                'created_at' => $cashFlow->created_at ? $cashFlow->created_at->format('d/m/Y H:i') : '',
            ];

            $title = 'Phiếu thu';
            if (str_starts_with($code, 'TTHD')) {
                $title = 'Thanh toán hóa đơn';
            } elseif ($cashFlow->type === 'payment') {
                $title = 'Phiếu chi';
            }

            return response()->json([
                'success' => true,
                'type' => 'cashflow',
                'title' => $title,
                'code' => $cashFlow->code,
                'data' => $data,
            ]);
        }

        // 4. CKTT - Chiết khấu thanh toán
        if (str_starts_with($code, 'CKTT')) {
            $discount = CustomerPaymentDiscount::where('code', $code)
                ->where('customer_id', $customer->id)
                ->first();
            if (!$discount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
                ], 404);
            }

            $discount->load(['allocations.invoice', 'performer', 'creator']);

            $data = [
                'id' => $discount->id,
                'code' => $discount->code,
                'status' => $discount->status,
                'amount' => $discount->amount,
                'discount_at' => $discount->discount_at ? $discount->discount_at->format('d/m/Y H:i') : '',
                'performed_by_name' => $discount->performer->name ?? 'Admin',
                'created_by_name' => $discount->creator->name ?? 'Admin',
                'note' => $discount->note,
                'allocate_to_invoices' => $discount->allocate_to_invoices,
                'cancelled_at' => $discount->cancelled_at ? $discount->cancelled_at->format('d/m/Y H:i') : null,
                'cancel_reason' => $discount->cancel_reason,
                'allocations' => $discount->allocations->map(fn($alloc) => [
                    'invoice_code' => $alloc->invoice->code ?? '',
                    'invoice_id' => $alloc->invoice_id,
                    'invoice_total' => $alloc->invoice->total ?? 0,
                    'invoice_customer_paid' => $alloc->invoice->customer_paid ?? 0,
                    'amount' => $alloc->amount,
                ]),
            ];

            return response()->json([
                'success' => true,
                'type' => 'payment_discount',
                'title' => 'Chiết khấu thanh toán',
                'code' => $discount->code,
                'data' => $data,
            ]);
        }

        // 5. MERGE / ledger adjustment (fallbacks to customer_debts)
        $debts = \App\Models\CustomerDebt::where('customer_id', $customer->id)
            ->where('ref_code', $code)
            ->get();

        if ($debts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.'
            ], 404);
        }

        $entries = $debts->map(fn($d) => [
            'code' => $d->ref_code,
            'type' => $d->type,
            'amount' => $d->amount,
            'debt_total' => $d->debt_total,
            'note' => $d->note,
            'recorded_at' => $d->recorded_at ? $d->recorded_at->format('d/m/Y H:i') : '',
            'created_at' => $d->created_at ? $d->created_at->format('d/m/Y H:i') : '',
            'source' => 'ledger',
        ]);

        $first = $debts->first();
        $data = [
            'code' => $first->ref_code,
            'type' => $first->type,
            'amount' => $first->amount,
            'debt_total' => $first->debt_total,
            'note' => $first->note,
            'recorded_at' => $first->recorded_at ? $first->recorded_at->format('d/m/Y H:i') : '',
            'created_at' => $first->created_at ? $first->created_at->format('d/m/Y H:i') : '',
            'source' => 'ledger',
            'entries' => $entries,
        ];

        return response()->json([
            'success' => true,
            'type' => 'ledger',
            'title' => 'Điều chỉnh công nợ',
            'code' => $first->ref_code,
            'data' => $data,
        ]);
    }
}
