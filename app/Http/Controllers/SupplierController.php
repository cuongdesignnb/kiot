<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\OrderReturn;
use App\Models\PurchaseReturn;
use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use App\Support\Debt\PartnerDebtDisplayBalance;
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

        $suppliers->getCollection()->transform(function ($supplier) {
            foreach (PartnerDebtDisplayBalance::aliases($supplier) as $key => $value) {
                $supplier->{$key} = $value;
            }

            return $supplier;
        });

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

        $supplier = Customer::create($validated);

        // STEP 24.13 — return JSON when the caller expects it so a quick-create
        // form can stay in-context (Purchases/Create, PurchaseOrders/Create) and
        // auto-select the new supplier without a full-page redirect.
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'supplier' => $supplier]);
        }

        return redirect()->route('suppliers.index')->with('success', 'Tạo nhà cung cấp thành công.');
    }

    /**
     * Step 24.8 — Update an existing supplier (basic info only).
     *
     * is_supplier is force-locked to true. Debt fields (supplier_debt_amount,
     * total_bought, debt_amount) are not touched — they stay maintained by the
     * purchase / payment flows.
     */
    public function update(Request $request, Customer $supplier)
    {
        if (!$supplier->is_supplier) {
            abort(404);
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'nullable|string|max:255|unique:customers,code,' . $supplier->id,
            'phone'           => 'nullable|string|max:255|unique:customers,phone,' . $supplier->id,
            'phone2'          => 'nullable|string|max:255',
            'email'           => 'nullable|email|max:255',
            'address'         => 'nullable|string',
            'city'            => 'nullable|string|max:255',
            'district'        => 'nullable|string|max:255',
            'ward'            => 'nullable|string|max:255',
            'customer_group'  => 'nullable|string|max:255',
            'tax_code'        => 'nullable|string|max:255',
            'note'            => 'nullable|string',
            'invoice_name'    => 'nullable|string|max:255',
            'invoice_address' => 'nullable|string',
            'invoice_email'   => 'nullable|email|max:255',
            'invoice_phone'   => 'nullable|string|max:255',
            'bank_name'       => 'nullable|string|max:255',
            'bank_account'    => 'nullable|string|max:255',
            'is_customer'     => 'sometimes|boolean',
        ]);

        // Force is_supplier=true. Never let edit form clear it.
        $validated['is_supplier'] = true;

        $supplier->update($validated);

        return back()->with('success', 'Cập nhật nhà cung cấp thành công.');
    }

    /**
     * Step 24.8 — Mark a supplier as inactive without deleting any record.
     * Purchase / payment / debt history is preserved.
     */
    public function deactivate(Customer $supplier)
    {
        if (!$supplier->is_supplier) {
            abort(404);
        }

        $supplier->update(['status' => 'inactive']);

        return back()->with('success', 'Đã ngừng hoạt động nhà cung cấp.');
    }

    /**
     * Step 24.8 — Re-activate a previously deactivated supplier.
     */
    public function activate(Customer $supplier)
    {
        if (!$supplier->is_supplier) {
            abort(404);
        }

        $supplier->update(['status' => 'active']);

        return back()->with('success', 'Đã kích hoạt lại nhà cung cấp.');
    }

    /**
     * HOTFIX 24.19 — live supplier search for the Nhập hàng selectors.
     *
     * Returns active suppliers only (status='active' or legacy NULL).
     * Deactivated suppliers stay on the admin /suppliers page where
     * "Hoạt động lại" lives — they must never appear in the create /
     * edit forms here, otherwise operators could keep opening fresh
     * debt against a stopped vendor.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->input('search', $request->input('q', '')));

        $query = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_supplier', true);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = '%' . $q . '%';
                $w->where('name', 'like', $like)
                  ->orWhere('code', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('phone2', 'like', $like);
            });
        }

        $suppliers = $query->orderBy('name')->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount', 'is_customer', 'is_supplier'])
            ->map(function (Customer $supplier) {
                foreach (PartnerDebtDisplayBalance::aliases($supplier) as $key => $value) {
                    $supplier->{$key} = $value;
                }

                return $supplier;
            });

        return response()->json($suppliers);
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

    /**
     * HOTFIX 24.17 — export công nợ NCC với date filter + chọn cột.
     *
     * Backwards-compat: nếu không truyền query nào (date_preset, date_from,
     * date_to, include_detail, columns) thì giữ format CSV cũ pin trong
     * HOTFIX 24.14 test (`Mã chứng từ`, `Còn nợ`, ...). Có query → headers
     * mới (`Thời gian`, `Nợ cần trả nhà cung cấp`) + filter ngày + chọn
     * cột detail.
     *
     * `debt_remain` được tính trên **full ledger** trước khi filter — đảo
     * thứ tự rows không làm sai số dư.
     */
    public function exportDebtHistory($id, Request $request)
    {
        // Nếu không có bất kỳ query nào → fast path legacy format.
        $hasQuery = $request->hasAny(['date_preset', 'date_from', 'date_to', 'include_detail', 'columns', 'format']);

        // HOTFIX FOLLOW-UP — export must pull ALL entries; bypass the
        // pagination added to debtTransactions() for the UI.
        $supplier = Customer::findOrFail($id);
        $ledger = app(\App\Services\PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $entries = collect($ledger['entries'] ?? [])
            ->map(fn ($e) => is_array($e) ? $e : (array) $e)
            ->all();

        if (!$hasQuery) {
            return \App\Services\CsvService::export(
                ['Mã chứng từ', 'Loại', 'Giá trị', 'Còn nợ', 'Ngày', 'Ghi chú'],
                collect($entries)->map(fn($t) => [
                    $t['code'],
                    $t['type_label'],
                    $t['amount'],
                    $t['debt_remain'],
                    $this->supplierDebtEntryExportTime($t),
                    $t['note'] ?? '',
                ]),
                "cong_no_ncc_{$id}.csv"
            );
        }

        // HOTFIX 24.17C — accept Vietnamese `dd/mm/yyyy` alongside ISO
        // `YYYY-MM-DD` so the modal's localized inputs can be passed
        // through to the backend without ambiguity. We bypass Laravel's
        // built-in `date` rule because that one parses `01/04/2026` as
        // US-format (Jan 4) on PHP, which silently flips day↔month.
        $validated = $request->validate([
            'date_preset'    => 'nullable|string|in:today,this_week,last_7_days,last_30_days,this_month,last_month,this_quarter,this_year,all,custom',
            'date_from'      => ['nullable', 'string', 'regex:#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{4})$#'],
            'date_to'        => ['nullable', 'string', 'regex:#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{4})$#'],
            'include_detail' => 'nullable|in:0,1,true,false',
            'columns'        => 'nullable|array',
            'columns.*'      => 'string|in:unit,quantity,unit_price,discount,vat,cost,line_total,note',
            'format'         => 'nullable|string|in:csv,xlsx',
        ], [
            'date_from.regex' => 'Ngày bắt đầu phải có định dạng dd/mm/yyyy hoặc YYYY-MM-DD.',
            'date_to.regex'   => 'Ngày kết thúc phải có định dạng dd/mm/yyyy hoặc YYYY-MM-DD.',
        ]);

        // Reject impossible calendar dates (e.g. 31/02/2026) — the regex
        // above is intentionally permissive about ranges.
        foreach (['date_from', 'date_to'] as $k) {
            if (!empty($validated[$k]) && $this->parseExportDate($validated[$k]) === null) {
                return response()->json([
                    'message' => "Ngày {$k} không hợp lệ.",
                    'errors'  => [$k => ["Ngày {$k} không hợp lệ."]],
                ], 422);
            }
        }

        $preset = $validated['date_preset'] ?? 'all';
        [$from, $to] = $this->resolveDebtExportRange($preset, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

        if ($from && $to && $from->greaterThan($to)) {
            return response()->json(['message' => 'date_from phải <= date_to'], 422);
        }

        $includeDetail = in_array((string) ($validated['include_detail'] ?? '0'), ['1', 'true'], true);
        $selectedCols  = array_values($validated['columns'] ?? []);

        // HOTFIX 24.17B — Excel branch: render KiotViet-style workbook
        // from the same full ledger. The Excel service computes
        // opening / debit / credit / closing from supplier_effect on
        // entries OUTSIDE / INSIDE the window — it never recomputes
        // debt_remain, so the ledger contract is preserved.
        if (($validated['format'] ?? '') === 'xlsx') {
            $supplier = \App\Models\Customer::find($id) ?? new \App\Models\Customer(['name' => 'NCC #' . $id, 'code' => '', 'phone' => '']);
            $service  = new \App\Services\Exports\SupplierDebtExcelExportService(
                is_array($entries) ? $entries : collect($entries)->toArray(),
                $supplier,
                $from,
                $to,
                $includeDetail,
                $selectedCols
            );
            return $service->download("cong_no_ncc_{$id}.xlsx");
        }

        // Filter theo business/display time (debt_remain đã được tính ở full ledger).
        $filtered = collect($entries)->filter(function ($t) use ($from, $to) {
            if (!$from && !$to) return true;
            $ts = $this->supplierDebtEntryExportCarbon($t);
            if (!$ts) return false;
            if ($from && $ts->lessThan($from)) return false;
            if ($to && $ts->greaterThan($to)) return false;
            return true;
        })->values();

        $headers = ['Thời gian', 'Mã chứng từ', 'Loại', 'Giá trị', 'Nợ cần trả nhà cung cấp', 'Ghi chú'];

        $detailColumnMap = [
            'unit'       => 'ĐVT',
            'quantity'   => 'Số lượng',
            'unit_price' => 'Đơn giá',
            'discount'   => 'Giảm giá',
            'vat'        => 'VAT',
            'cost'       => 'Giá nhập/trả',
            'line_total' => 'Thành tiền',
            'note'       => 'Ghi chú dòng',
        ];
        $appendDetailCols = $includeDetail
            ? array_values(array_intersect_key($detailColumnMap, array_flip($selectedCols)))
            : [];
        $headers = array_merge($headers, $appendDetailCols);

        $rows = collect();
        foreach ($filtered as $t) {
            $when = $this->supplierDebtEntryExportTime($t);
            $base = [
                $when,
                $t['code'] ?? '',
                $t['type_label'] ?? '',
                $t['amount'] ?? 0,
                $t['debt_remain'] ?? 0,
                $t['note'] ?? '',
            ];
            $rows->push(array_merge($base, array_fill(0, count($appendDetailCols), '')));

            if ($includeDetail && count($appendDetailCols) > 0) {
                foreach ($this->loadDebtExportDetailLines($t) as $line) {
                    $detail = [];
                    foreach ($selectedCols as $col) {
                        if (!array_key_exists($col, $detailColumnMap)) continue;
                        $detail[] = $line[$col] ?? '';
                    }
                    $rows->push(array_merge(
                        ['', '', '', '', '', ''], // chừa cột tổng quan
                        $detail
                    ));
                }
            }
        }

        return \App\Services\CsvService::export($headers, $rows, "cong_no_ncc_{$id}.csv");
    }

    private function supplierDebtEntryExportRawTime(array $entry)
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

    private function supplierDebtEntryExportCarbon(array $entry): ?\Carbon\Carbon
    {
        $raw = $this->supplierDebtEntryExportRawTime($entry);
        if (!$raw) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function supplierDebtEntryExportTime(array $entry): string
    {
        $raw = $this->supplierDebtEntryExportRawTime($entry);
        if (!$raw) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($raw)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    private function resolveDebtExportRange(string $preset, ?string $from, ?string $to): array
    {
        $now = \Carbon\Carbon::now();
        switch ($preset) {
            case 'today':
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'this_week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            case 'last_7_days':
                return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
            case 'last_30_days':
                return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
            case 'this_month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case 'last_month':
                $lm = $now->copy()->subMonthNoOverflow();
                return [$lm->copy()->startOfMonth(), $lm->copy()->endOfMonth()];
            case 'this_quarter':
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()];
            case 'this_year':
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            case 'custom':
                $f = $this->parseExportDate($from);
                $t = $this->parseExportDate($to);
                return [$f ? $f->startOfDay() : null, $t ? $t->endOfDay() : null];
            case 'all':
            default:
                return [null, null];
        }
    }

    /**
     * HOTFIX 24.17C — strict parser: ISO `YYYY-MM-DD` and Vietnamese
     * `dd/mm/yyyy` only. Never falls back to Carbon::parse() (which
     * would silently flip `01/04/2026` to Jan 4 on PHP). Returns null
     * for any unparseable / impossible calendar date (e.g. 31/02).
     */
    private function parseExportDate(?string $value): ?\Carbon\Carbon
    {
        if (!$value) return null;
        $value = trim($value);
        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $value, $m)) {
            $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            $d = (int) $m[1]; $mo = (int) $m[2]; $y = (int) $m[3];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y)) return null;
        return \Carbon\Carbon::create($y, $mo, $d, 0, 0, 0);
    }

    private function loadDebtExportDetailLines(array $entry): array
    {
        $id = $entry['id'] ?? '';
        if (!is_string($id) || !str_contains($id, '-')) return [];

        [$prefix, $rawId] = explode('-', $id, 2);
        $rawId = (int) $rawId;
        if ($rawId <= 0) return [];

        if ($prefix === 'pur') {
            $items = \App\Models\PurchaseItem::where('purchase_id', $rawId)->get();
            return $items->map(fn($i) => [
                'unit'       => '',
                'quantity'   => $i->quantity ?? 0,
                'unit_price' => $i->price ?? 0,
                'discount'   => $i->discount ?? 0,
                'vat'        => '',
                'cost'       => $i->price ?? 0,
                'line_total' => $i->subtotal ?? 0,
                'note'       => $i->product_name ?? $i->product_code ?? '',
            ])->all();
        }

        if ($prefix === 'pret') {
            $items = \App\Models\PurchaseReturnItem::where('purchase_return_id', $rawId)->get();
            return $items->map(fn($i) => [
                'unit'       => '',
                'quantity'   => $i->quantity ?? 0,
                'unit_price' => $i->price ?? 0,
                'discount'   => '',
                'vat'        => '',
                'cost'       => $i->price ?? 0,
                'line_total' => $i->subtotal ?? 0,
                'note'       => $i->product_name ?? $i->product_code ?? '',
            ])->all();
        }

        if ($prefix === 'inv') {
            $items = \App\Models\InvoiceItem::where('invoice_id', $rawId)->get();
            return $items->map(fn($i) => [
                'unit'       => '',
                'quantity'   => $i->quantity ?? 0,
                'unit_price' => $i->price ?? 0,
                'discount'   => $i->discount ?? 0,
                'vat'        => '',
                'cost'       => $i->price ?? 0,
                'line_total' => ($i->price ?? 0) * ($i->quantity ?? 0) - ($i->discount ?? 0),
                'note'       => $i->product_name ?? '',
            ])->all();
        }

        // payment, adjustment, discount, customer_payment, return, ... → no line detail.
        return [];
    }

    public function exportPurchaseHistory($id)
    {
        $data = $this->purchaseHistory($id)->getData(true);

        return \App\Services\CsvService::export(
            ['Mã phiếu nhập', 'Thời gian', 'Loại phiếu', 'Người tạo', 'Chi nhánh', 'Tổng tiền', 'Trạng thái'],
            collect($data)->map(fn($p) => [
                $p['code'],
                $p['transaction_at_display'] ?? $p['date'] ?? '',
                $p['type_label'] ?? '',
                $p['user_name'] ?? '',
                $p['branch'] ?? '',
                $p['total'] ?? 0,
                $p['status_label'] ?? '',
            ]),
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
            ->with(['user:id,name', 'employee:id,name'])
            ->get()
            ->map(function ($p) {
                $transactionAt = $p->purchase_date
                    ? \Carbon\Carbon::parse($p->purchase_date)
                    : ($p->created_at ? \Carbon\Carbon::parse($p->created_at) : null);

                return [
                    'id' => $p->id,
                    'type' => 'purchase',
                    'type_label' => 'Nhập hàng',
                    'code' => $p->code,
                    'transaction_at' => $transactionAt?->toIso8601String(),
                    'transaction_at_display' => $transactionAt?->format('H:i d/m/Y') ?? '',
                    'date' => $transactionAt?->format('H:i d/m/Y') ?? '',
                    'purchase_date' => $transactionAt?->toIso8601String(),
                    'user_name' => $p->employee->name ?? $p->user->name ?? 'Admin',
                    'creator_name' => $p->employee->name ?? $p->user->name ?? 'Admin',
                    'branch' => 'Laptopplus.vn',
                    'branch_name' => 'Laptopplus.vn',
                    'total' => $p->total_amount,
                    'total_amount' => (float) $p->total_amount,
                    'status' => $p->status,
                    'status_label' => $p->status === 'completed' ? 'Đã nhập hàng' : ($p->status === 'returned' ? 'Đã trả hàng' : ucfirst($p->status)),
                ];
            });

        $purchaseReturns = PurchaseReturn::where('supplier_id', $id)
            ->with(['user:id,name', 'employee:id,name'])
            ->get()
            ->map(function ($return) {
                $transactionAt = $return->return_date
                    ? \Carbon\Carbon::parse($return->return_date)
                    : ($return->created_at ? \Carbon\Carbon::parse($return->created_at) : null);

                return [
                    'id' => $return->id,
                    'type' => 'purchase_return',
                    'type_label' => 'Trả hàng nhập',
                    'code' => $return->code,
                    'transaction_at' => $transactionAt?->toIso8601String(),
                    'transaction_at_display' => $transactionAt?->format('H:i d/m/Y') ?? '',
                    'date' => $transactionAt?->format('H:i d/m/Y') ?? '',
                    'return_date' => $transactionAt?->toIso8601String(),
                    'user_name' => $return->employee->name ?? $return->user->name ?? 'Admin',
                    'creator_name' => $return->employee->name ?? $return->user->name ?? 'Admin',
                    'branch' => 'Laptopplus.vn',
                    'branch_name' => 'Laptopplus.vn',
                    'total' => $return->total_amount,
                    'total_amount' => (float) $return->total_amount,
                    'status' => $return->status,
                    'status_label' => $return->status === 'completed' ? 'Hoàn thành' : ucfirst((string) $return->status),
                ];
            });

        $histories = $purchases
            ->merge($purchaseReturns)
            ->sortByDesc(fn($item) => $item['transaction_at'] ? \Carbon\Carbon::parse($item['transaction_at'])->timestamp : 0)
            ->values();

        return response()->json($histories);
    }

    /**
     * Nợ cần trả NCC - lịch sử công nợ (unified ledger, dual-role aware)
     * supplier_effect = -customer_effect (theo motakh.md spec)
     */
    public function debtTransactions($id, Request $request)
    {
        $supplier = Customer::findOrFail($id);
        if (!$supplier->is_supplier) {
            abort(404);
        }

        $hasSupplierColumn = \Illuminate\Support\Facades\Schema::hasColumn('customers', 'supplier_debt_amount');
        $isDualRole = (bool) $supplier->is_customer;
        $usePartnerTimeline = $isDualRole && (string) $request->input('view', '') === 'partner';

        $mode = $request->query('mode', 'document');

        if ($mode === 'legacy') {
            $ledgerService = app(\App\Services\PartnerDebtLedgerService::class);
            $ledger = $usePartnerTimeline
                ? $ledgerService->buildSupplierDualRolePartnerTimeline($supplier)
                : $ledgerService->buildSupplierPayableLedger($supplier);
        } else {
            $ledger = app(\App\Services\SupplierDebtDocumentTimelineService::class)->build($supplier, $request->all());
        }

        // HOTFIX FOLLOW-UP — opt-in server-side pagination matching
        // KiotViet (10 rows per page). Caller activates by sending
        // ?page=N. Without that param, the full ledger is returned
        // so existing tests / exports / scripts that iterate all
        // entries continue to work.
        $usePagination = $request->has('page');
        $allEntries = collect($ledger['entries'] ?? []);
        $pagination = null;
        $pagedEntries = $allEntries;
        if ($usePagination) {
            $perPage = max(1, min(100, (int) $request->input('per_page', 10)));
            $total = $allEntries->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $currentPage = max(1, min($lastPage, (int) $request->input('page', 1)));
            $offset = ($currentPage - 1) * $perPage;
            $pagedEntries = $allEntries->slice($offset, $perPage)->values();
            $pagination = [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $currentPage,
                'last_page'    => $lastPage,
                'from'         => $total === 0 ? 0 : $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ];
        }

        $pagedEntries = $pagedEntries
            ->map(function ($entry) {
                $entry = is_array($entry) ? $entry : (array) $entry;
                if (!array_key_exists('affects_debt_balance', $entry)) {
                    $entry['affects_debt_balance'] = ! (bool) (
                        $entry['reference_only']
                        ?? $entry['is_reference_only']
                        ?? false
                    );
                }

                return $entry;
            })
            ->values();

        $customerDebt = PartnerDebtDisplayBalance::customerReceivable($supplier);
        $supplierDebt = $hasSupplierColumn ? PartnerDebtDisplayBalance::supplierPayable($supplier) : 0.0;
        $netDebt = $customerDebt - $supplierDebt;
        $supplierOrientedBalance = PartnerDebtDisplayBalance::supplierScreen($supplier);
        $ledgerSummary = $ledger['summary'] ?? [];
        $compatNet = (float) (
            $ledgerSummary['display_balance_final']
            ?? $ledgerSummary['document_final_balance']
            ?? $ledger['closing_balance']
            ?? $ledgerSummary['net']
            ?? ($usePartnerTimeline ? $supplierOrientedBalance : $supplierDebt)
        );

        $hasDebtOffsetVoucher = \App\Models\DebtOffset::query()
            ->where('customer_id', $supplier->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        $response = [
            'entries' => $pagedEntries,
            'summary' => [
                // Canonical receivable/payable/net keys (HOTFIX FOLLOW-UP)
                'customer_receivable_balance' => $customerDebt,
                'supplier_payable_balance'    => $supplierDebt,
                'partner_net_position'        => $netDebt,
                'supplier_oriented_balance'   => $supplierOrientedBalance,
                'current_debt'                => (float) ($ledgerSummary['current_debt'] ?? $supplierOrientedBalance),
                'has_debt_offset_voucher'     => $hasDebtOffsetVoucher,
                'is_actual_offset'            => false,
                'is_net_view'                 => $usePartnerTimeline,
                'is_supplier_tab_partner_timeline' => $usePartnerTimeline,
                'display_mode'                => $usePartnerTimeline
                    ? (string) ($ledgerSummary['display_mode'] ?? 'supplier_partner_timeline')
                    : 'supplier_payable',
                'legacy_display_mode'         => $usePartnerTimeline
                    ? (string) ($ledgerSummary['legacy_display_mode'] ?? 'partner_net_timeline')
                    : null,
                'orientation'                 => $usePartnerTimeline ? 'supplier' : 'supplier',
                'supplier_partner_balance'    => $usePartnerTimeline ? $supplierOrientedBalance : null,
                'supplier_screen_balance'     => $usePartnerTimeline ? $supplierOrientedBalance : null,
                'balance_label'               => 'Nợ cần trả nhà cung cấp',
                'display_timeline_mode'       => (bool) ($ledgerSummary['display_timeline_mode'] ?? true),
                'has_virtual_opening_balance' => (bool) ($ledgerSummary['has_virtual_opening_balance'] ?? false),
                'virtual_opening_balance'     => (float) ($ledgerSummary['virtual_opening_balance'] ?? 0.0),
                'display_balance_target'      => (float) ($ledgerSummary['display_balance_target'] ?? $supplierOrientedBalance),
                'display_balance_final'       => (float) ($ledgerSummary['display_balance_final'] ?? $ledger['closing_balance'] ?? 0.0),
                'raw_document_final_balance'  => (float) ($ledgerSummary['raw_document_final_balance'] ?? $ledgerSummary['document_final_balance_before_alignment'] ?? $ledgerSummary['document_final_balance'] ?? 0.0),
                'document_final_balance_before_alignment' => (float) ($ledgerSummary['document_final_balance_before_alignment'] ?? $ledgerSummary['document_final_balance'] ?? 0.0),
                'display_alignment_amount'    => (float) ($ledgerSummary['display_alignment_amount'] ?? 0.0),
                'display_aligned'             => (bool) ($ledgerSummary['display_aligned'] ?? false),
                'has_virtual_display_alignment' => (bool) ($ledgerSummary['has_virtual_display_alignment'] ?? false),

                // Backward-compatible keys (existing FE/tests still read these)
                'net' => $compatNet,
                'is_dual_role' => $isDualRole,
                'customer_debt_amount' => $customerDebt,
                'supplier_debt_amount' => $supplierDebt,
                'net_debt_amount' => $netDebt,
            ],
        ];
        if (!empty($ledger['reconcile'])) {
            $response['reconcile'] = $ledger['reconcile'];
        }
        if ($pagination !== null) {
            $response['pagination'] = $pagination;
        }
        return response()->json($response);
    }

    /**
     * STEP 10 — Supplier debt voucher detail (click-to-open from the
     * NCC Công nợ timeline). Mirrors CustomerController::debtVoucherDetail
     * but for the supplier orientation. Read-only.
     *
     * Resolves the document by code across the relevant tables instead of
     * relying on brittle prefix parsing:
     *   Purchase (PN…) → phiếu nhập
     *   PurchaseReturn → trả hàng nhập
     *   CashFlow (PCPN…/PC…/PT…) → phiếu chi/thu
     *   DebtOffset (CB…/HCB…) → cấn trừ công nợ
     *   Invoice (HD…) → hóa đơn (dual-role)
     * Virtual fallback rows (TTNH…) have no real voucher → 404 with a clear
     * "tạm tính" message.
     */
    public function debtVoucherDetail($id, Request $request)
    {
        $supplier = Customer::findOrFail($id);
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['success' => false, 'message' => 'Mã chứng từ không được để trống.'], 422);
        }

        $notFound = fn () => response()->json([
            'success' => false,
            'message' => 'Không tìm thấy chứng từ hoặc chứng từ không thuộc nhà cung cấp này.',
        ], 404);

        // 1) Phiếu nhập
        if ($purchase = Purchase::where('code', $code)->first()) {
            if ((int) $purchase->supplier_id !== (int) $supplier->id) return $notFound();
            $purchase->load(['supplier', 'items.product', 'user', 'employee']);
            return response()->json([
                'success' => true, 'type' => 'purchase', 'title' => 'Phiếu nhập hàng', 'code' => $purchase->code,
                'data' => [
                    'id' => $purchase->id, 'code' => $purchase->code, 'status' => $purchase->status,
                    'purchase_date' => optional($purchase->purchase_date ?? $purchase->created_at)->format('d/m/Y H:i'),
                    'supplier_name' => $purchase->supplier->name ?? '', 'supplier_code' => $purchase->supplier->code ?? '',
                    'user_name' => $purchase->user->name ?? 'Admin', 'note' => $purchase->note,
                    'total_amount' => $purchase->total_amount, 'discount' => $purchase->discount,
                    'paid_amount' => $purchase->paid_amount, 'debt_amount' => $purchase->debt_amount,
                    'payment_method' => $purchase->payment_method,
                    'items' => $purchase->items->map(fn ($it) => [
                        'product_code' => $it->product->code ?? '', 'product_name' => $it->product->name ?? '',
                        'quantity' => $it->quantity, 'price' => $it->price, 'discount' => $it->discount ?? 0, 'subtotal' => $it->subtotal,
                    ]),
                ],
            ]);
        }

        // 2) Trả hàng nhập
        if ($return = PurchaseReturn::where('code', $code)->first()) {
            if ((int) $return->supplier_id !== (int) $supplier->id) return $notFound();
            $return->loadMissing(['items.product']);
            return response()->json([
                'success' => true, 'type' => 'purchase_return', 'title' => 'Trả hàng nhập', 'code' => $return->code,
                'data' => [
                    'id' => $return->id, 'code' => $return->code, 'status' => $return->status,
                    'return_date' => optional($return->return_date ?? $return->created_at)->format('d/m/Y H:i'),
                    'total_amount' => $return->total_amount, 'note' => $return->note,
                    'items' => $return->items->map(fn ($it) => [
                        'product_code' => $it->product->code ?? '', 'product_name' => $it->product->name ?? '',
                        'quantity' => $it->quantity, 'price' => $it->price, 'subtotal' => $it->subtotal ?? null,
                    ]),
                ],
            ]);
        }

        // 3) DebtOffset (CB / HCB)
        if (str_starts_with($code, 'CB') || str_starts_with($code, 'HCB')) {
            $offsetCode = str_starts_with($code, 'HCB') ? substr($code, 1) : $code;
            $offset = \App\Models\DebtOffset::where('code', $offsetCode)->first();
            if ($offset && (int) $offset->customer_id === (int) $supplier->id) {
                $isCancel = str_starts_with($code, 'HCB');
                return response()->json([
                    'success' => true, 'type' => 'offset', 'title' => $isCancel ? 'Hủy điều chỉnh công nợ' : 'Điều chỉnh công nợ', 'code' => $code,
                    'data' => [
                        'id' => $offset->id, 'code' => $code, 'amount' => (float) $offset->amount,
                        'status' => $offset->status, 'note' => $offset->note,
                        'created_at' => optional($offset->created_at)->format('d/m/Y H:i'),
                    ],
                ]);
            }
            return $notFound();
        }

        // 4) CashFlow (phiếu chi/thu thật: PCPN, PC, PT...). Virtual TTNH/TTHD has no row.
        if ($cashFlow = CashFlow::where('code', $code)->first()) {
            $belongs = ((int) $cashFlow->target_id === (int) $supplier->id)
                || in_array($cashFlow->reference_code, Purchase::where('supplier_id', $supplier->id)->pluck('code')->all(), true);
            if (!$belongs) return $notFound();
            $cashFlow->load('bankAccount');
            return response()->json([
                'success' => true, 'type' => 'cashflow', 'title' => $cashFlow->type === 'payment' ? 'Phiếu chi' : 'Phiếu thu', 'code' => $cashFlow->code,
                'data' => [
                    'id' => $cashFlow->id, 'code' => $cashFlow->code, 'type' => $cashFlow->type, 'amount' => (float) $cashFlow->amount,
                    'time' => $cashFlow->time ? \Carbon\Carbon::parse($cashFlow->time)->format('d/m/Y H:i') : '',
                    'category' => $cashFlow->category, 'target_name' => $cashFlow->target_name, 'payment_method' => $cashFlow->payment_method,
                    'bank_account_name' => $cashFlow->bankAccount ? ($cashFlow->bankAccount->bank_name . ' - ' . $cashFlow->bankAccount->account_number) : null,
                    'reference_type' => $cashFlow->reference_type, 'reference_code' => $cashFlow->reference_code,
                    'description' => $cashFlow->description, 'status' => $cashFlow->status,
                ],
            ]);
        }

        // 5) Invoice (dual-role HD)
        if ($invoice = Invoice::where('code', $code)->first()) {
            if ((int) $invoice->customer_id !== (int) $supplier->id) return $notFound();
            $invoice->load(['items.product']);
            return response()->json([
                'success' => true, 'type' => 'invoice', 'title' => 'Hóa đơn', 'code' => $invoice->code,
                'data' => [
                    'id' => $invoice->id, 'code' => $invoice->code, 'status' => $invoice->status,
                    'created_at' => optional($invoice->created_at)->format('d/m/Y H:i'),
                    'total' => $invoice->total, 'discount' => $invoice->discount, 'customer_paid' => $invoice->customer_paid,
                    'items' => $invoice->items->map(fn ($it) => [
                        'product_code' => $it->product->code ?? '', 'product_name' => $it->product->name ?? '',
                        'quantity' => $it->quantity, 'price' => $it->price, 'subtotal' => $it->subtotal,
                    ]),
                ],
            ]);
        }

        // 6) Virtual fallback (TTNH/TTHD) — no real voucher exists.
        if (str_starts_with($code, 'TTNH') || str_starts_with($code, 'TTHD')) {
            return response()->json([
                'success' => false,
                'message' => 'Đây là dòng tạm tính từ phiếu nhập — chưa có phiếu chi/thu thật để mở.',
            ], 404);
        }

        return $notFound();
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
        app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
            (int) $supplier->id,
            'supplier_id'
        );
        $currentDebt = $this->calculateDebt($id);
        $totalPay = abs($data['amount']);
        $mode = $data['mode'] ?? 'auto';
        $paidAt = !empty($data['date']) ? \Carbon\Carbon::parse($data['date']) : now();

        DB::transaction(function () use ($id, $supplier, $currentDebt, $totalPay, $mode, $data, $paidAt) {
            app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                (int) $id,
                'supplier_id'
            );
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

            // Payment entries: lấy từ CashFlow thật thay vì Purchase.paid_amount
            $purchaseCashFlows = CashFlow::where('reference_type', 'Purchase')
                ->where('reference_code', $p->code)
                ->where('type', 'payment')
                ->orderBy('created_at')
                ->get();

            foreach ($purchaseCashFlows as $cf) {
                $runningDebt -= $cf->amount;
                SupplierDebtTransaction::create([
                    'supplier_id' => $supplierId,
                    'code' => $cf->code,
                    'type' => 'payment',
                    'amount' => -$cf->amount,
                    'debt_remain' => $runningDebt,
                    'purchase_id' => $p->id,
                    'user_id' => $p->user_id,
                    'created_at' => $cf->created_at ?? $p->purchase_date ?? $p->created_at,
                    'updated_at' => $cf->created_at ?? $p->purchase_date ?? $p->created_at,
                ]);
            }
        }
    }
}
