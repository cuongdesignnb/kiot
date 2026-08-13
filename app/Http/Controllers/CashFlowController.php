<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Services\CashFlowCancellationService;
use App\Services\CustomerPaymentService;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Services\LockPeriodService;
use App\Support\BusinessDateTime;
use App\Support\Filters\FilterableIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CashFlowController extends Controller
{
    use FilterableIndex;

    protected function configureCashFlowFilters(): void
    {
        $this->searchable = ['code', 'description', 'reference_code', 'target_name', 'category'];
        $this->sortable = ['code', 'time', 'type', 'amount', 'category', 'created_at'];
        $this->dateColumn = 'time';
        $this->scalarFilters = ['type', 'payment_method', 'status', 'bank_account_id', 'branch_id', 'category', 'target_type'];
    }

    public function index(Request $request)
    {
        $this->configureCashFlowFilters();

        $cancelledStatuses = (array) $request->input('status', []);
        $query = in_array('cancelled', $cancelledStatuses, true)
            ? CashFlow::withTrashed()
            : CashFlow::active();
        $query->with('cancelledBy');
        $this->applyFilters($query, $request);
        $cashFlows = $query->paginate(15)->withQueryString();
        $cashFlows->getCollection()->transform(function (CashFlow $cashFlow): CashFlow {
            $cashFlow->setAttribute('cancel_policy', app(CashFlowCancellationService::class)->policy($cashFlow));
            $cashFlow->setAttribute('cancelled_by_name', $cashFlow->cancelledBy?->name);

            return $cashFlow;
        });

        // Summary metrics
        $totalReceipts = CashFlow::active()->where('type', 'receipt')->sum('amount');
        $totalPayments = CashFlow::active()->where('type', 'payment')->sum('amount');
        $fundBalance = $totalReceipts - $totalPayments;

        $customers = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_customer', true)
            ->get(['id', 'name', 'phone']);
        $suppliers = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_supplier', true)
            ->get(['id', 'name', 'phone']);

        $savedReceiptCategories = CashFlow::active()->where('type', 'receipt')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category')->toArray();
        $savedPaymentCategories = CashFlow::active()->where('type', 'payment')
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
            'bankAccounts' => BankAccount::where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name as name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name]),
            'categories' => collect(array_merge($savedReceiptCategories, $savedPaymentCategories))->unique()->values()->map(fn ($c) => ['value' => $c, 'label' => $c]),
            'categoryGroups' => [
                'receipt' => collect($savedReceiptCategories)->unique()->values()->map(fn ($c) => [
                    'value' => $c,
                    'label' => $c,
                    'type' => 'receipt',
                    'group' => 'Loại thu',
                ]),
                'payment' => collect($savedPaymentCategories)->unique()->values()->map(fn ($c) => [
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
        $validated = $request->validate([
            'type' => 'required|in:receipt,payment',
            'amount' => 'required|numeric|min:0',
            'time' => 'nullable|date',
            'category' => 'nullable|string',
            'target_type' => 'nullable|string',
            'target_id' => 'nullable|integer|exists:customers,id',
            'target_name' => 'nullable|string',
            'accounting_result' => 'boolean',
            'description' => 'nullable|string',
            'payment_method' => 'nullable|in:cash,bank,ewallet',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);
        $targetPartner = null;
        if (in_array($request->target_type, ['Khách hàng', 'Nhà cung cấp', 'customer', 'supplier'], true)) {
            if (! $request->filled('target_id')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'target_id' => 'Vui lòng chọn đối tác từ danh sách.',
                ]);
            }
            $targetPartner = app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                (int) $request->target_id,
                'target_id'
            );
        }

        $prefix = $request->type === 'receipt' ? 'PT' : 'PC';

        // Lock period check
        $txDate = BusinessDateTime::forCreate($request->input('time'));
        app(LockPeriodService::class)->assertNotLocked($txDate, 'cashflow_create');

        $createCashFlow = function (?Customer $lockedPartner = null) use ($request, $targetPartner, $txDate, $prefix): CashFlow {
            $cashFlow = CashFlow::create([
                'code' => $prefix.date('ymdHis').rand(10, 99),
                'type' => $request->type,
                'amount' => $request->amount,
                'time' => $txDate,
                'category' => $request->category,
                'target_type' => $request->target_type,
                'target_id' => $request->target_id,
                'target_name' => $targetPartner?->name ?? $request->target_name,
                'accounting_result' => $request->has('accounting_result') ? $request->accounting_result : true,
                'payment_method' => $request->payment_method ?? 'cash',
                'bank_account_id' => $request->payment_method !== 'cash' ? $request->bank_account_id : null,
                'description' => $request->description,
            ]);
            app(PartnerDebtMutationCoordinator::class)->checkpoint('document');

            if ($lockedPartner) {
                $isCustomer = in_array((string) $request->target_type, PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES, true);
                $isSupplier = in_array((string) $request->target_type, PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES, true);
                if ($isCustomer && ! (bool) $lockedPartner->is_customer) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'target_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                    ]);
                }
                if ($isSupplier && ! (bool) $lockedPartner->is_supplier) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'target_id' => 'Doi tac khong co vai tro nha cung cap da duoc luu.',
                    ]);
                }

                $amount = (float) $cashFlow->amount;
                if ($isCustomer) {
                    $lockedPartner->debt_amount = (float) $lockedPartner->debt_amount
                        + ($cashFlow->type === 'receipt' ? -$amount : $amount);
                } elseif ($isSupplier) {
                    $lockedPartner->supplier_debt_amount = (float) $lockedPartner->supplier_debt_amount
                        + ($cashFlow->type === 'payment' ? -$amount : $amount);
                }
                $lockedPartner->save();
                app(PartnerDebtMutationCoordinator::class)->checkpoint('projection');
            }

            $typeLabel = $request->type === 'receipt' ? 'thu' : 'chi';
            ActivityLog::log('cashflow_create', "Tạo phiếu {$typeLabel} {$cashFlow->code}, số tiền: ".number_format($cashFlow->amount), $cashFlow);

            return $cashFlow;
        };

        $targetPartnerId = (int) ($targetPartner?->id ?? 0);
        $cashFlow = $targetPartnerId > 0
            ? app(PartnerDebtMutationCoordinator::class)->execute(
                $targetPartnerId,
                'standalone_cash_flow_create',
                hash('sha256', json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
                fn (Customer $lockedPartner): CashFlow => DB::transaction(
                    fn (): CashFlow => $createCashFlow($lockedPartner),
                ),
                $request->header('Idempotency-Key'),
            )
            : DB::transaction(fn (): CashFlow => $createCashFlow());

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
            'is_supplier' => 'boolean',
        ]);

        $isSupplier = $request->input('is_supplier', false);
        $validated['code'] = ($isSupplier ? 'NCC' : 'KH').time().rand(10, 99);
        $validated['is_supplier'] = $isSupplier;
        $validated['is_customer'] = ! $isSupplier;

        \App\Models\Customer::create($validated);

        return redirect()->back()->with('success', 'Tạo đối tượng thành công');
    }

    public function update(Request $request, CashFlow $cashFlow)
    {
        if ($cashFlow->status === 'cancelled' || $cashFlow->trashed()) {
            return back()->with('error', 'Phiếu đã hủy không được phép sửa.');
        }

        if (app(CustomerPaymentService::class)->isFinanciallyLinked($cashFlow)) {
            return back()->with('error', 'Phieu lien ket chung tu tai chinh khong duoc sua truc tiep. Hay huy va tao lai.');
        }

        $request->validate([
            'time' => 'nullable|date',
            'category' => 'nullable|string|max:255',
            'target_type' => 'nullable|string',
            'target_id' => 'nullable|integer|exists:customers,id',
            'target_name' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'accounting_result' => 'boolean',
            'payment_method' => 'nullable|in:cash,bank,ewallet',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);
        $targetPartner = null;
        if (in_array($request->target_type, ['Khách hàng', 'Nhà cung cấp', 'customer', 'supplier'], true)) {
            if (! $request->filled('target_id')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'target_id' => 'Vui lòng chọn đối tác từ danh sách.',
                ]);
            }
            $targetPartner = app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                (int) $request->target_id,
                'target_id'
            );
        }

        $txDate = BusinessDateTime::forUpdate($request->input('time'), $cashFlow->time);
        app(LockPeriodService::class)->assertNotLocked($txDate, 'cashflow_update');

        $oldCategory = $cashFlow->category;

        $cashFlow->update([
            'time' => $txDate,
            'category' => $request->category,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
            'target_name' => $targetPartner?->name ?? $request->target_name,
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

    public function destroy(Request $request, $cash_flow)
    {
        $validated = $request->validate([
            'cancel_reason' => 'required|string|min:5|max:500',
        ], [
            'cancel_reason.required' => 'Vui lòng nhập lý do hủy phiếu thu/chi.',
            'cancel_reason.min' => 'Lý do hủy phải có ít nhất 5 ký tự.',
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) < 16 || mb_strlen($idempotencyKey) > 191) {
            $message = 'Idempotency-Key phải có từ 16 đến 191 ký tự.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'status' => CashFlowCancellationService::MANUAL_REVIEW_REQUIRED, 'message' => $message], 422)
                : back()->with('error', $message);
        }

        $cashFlow = CashFlow::withTrashed()->findOrFail($cash_flow);

        // Lock period check
        app(LockPeriodService::class)->assertNotLocked($cashFlow->time, 'cashflow_cancel');

        $status = app(CashFlowCancellationService::class)->cancel(
            $cashFlow,
            trim($validated['cancel_reason']),
            $idempotencyKey,
        );
        if ($status === CashFlowCancellationService::ALREADY_CANCELLED) {
            $message = 'Phiếu thu/chi này đã bị hủy trước đó.';

            return $request->wantsJson()
                ? response()->json(['success' => true, 'status' => $status, 'message' => $message])
                : back()->with('success', $message);
        }
        if (in_array($status, [
            CashFlowCancellationService::SOURCE_DOCUMENT_REQUIRED,
            CashFlowCancellationService::MANUAL_REVIEW_REQUIRED,
        ], true)) {
            $message = $status === CashFlowCancellationService::MANUAL_REVIEW_REQUIRED
                ? 'Không xác định được chủ chứng từ. Phiếu được khóa để tránh đảo sai công nợ.'
                : 'Phiếu thu/chi này phát sinh từ chứng từ nguồn. Vui lòng hủy chứng từ gốc để hệ thống tự đảo kho, công nợ và sổ quỹ.';

            return $request->wantsJson()
                ? response()->json(['success' => false, 'status' => $status, 'message' => $message], 422)
                : back()->with('error', $message);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'status' => CashFlowCancellationService::CANCELLED,
                'message' => 'Hủy phiếu thành công.',
            ]);
        }

        return redirect()->back()->with('success', 'Huy phieu thanh cong.');
    }

    public function print($cash_flow)
    {
        $cashFlow = CashFlow::withTrashed()->findOrFail($cash_flow);
        $cashFlow->load(['bankAccount', 'cancelledBy']);

        return view('prints.cashflow', compact('cashFlow'));
    }

    public function export(Request $request)
    {
        $this->configureCashFlowFilters();
        $cancelledStatuses = (array) $request->input('status', []);
        $query = in_array('cancelled', $cancelledStatuses, true)
            ? CashFlow::withTrashed()
            : CashFlow::active();
        $query->with('cancelledBy');
        $this->applyFilters($query, $request);
        $flows = $query->get();

        return \App\Services\CsvService::export(
            ['Mã phiếu', 'Thời gian', 'Loại', 'Giá trị', 'Người nộp/nhận', 'Hạng mục', 'Phương thức', 'Ghi chú', 'Trạng thái', 'Lý do hủy', 'Người hủy', 'Thời gian hủy'],
            $flows->map(fn ($f) => [
                $f->code,
                $f->time,
                $f->type === 'receipt' ? 'Thu' : 'Chi',
                $f->amount,
                $f->target_name,
                $f->category,
                $f->payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản',
                $f->description,
                $f->status === 'cancelled' || $f->trashed() ? 'Đã hủy' : 'Đã ghi nhận',
                $f->cancel_reason,
                $f->cancelledBy?->name,
                $f->cancelled_at,
            ]),
            'so_quy.csv'
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 4 || empty(trim($row[0] ?? ''))) {
                continue;
            }
            $type = mb_strtolower(trim($row[2] ?? '')) === 'thu' ? 'receipt' : 'payment';
            CashFlow::create([
                'code' => trim($row[0]),
                'time' => BusinessDateTime::forCreate(trim($row[1] ?? '')),
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

        $refCode = 'CQ'.date('ymdHis').rand(10, 99);
        $transferTime = BusinessDateTime::forCreate();

        // Phieu chi o quy nguon
        $payment = CashFlow::create([
            'code' => 'PC'.date('ymdHis').rand(10, 99),
            'type' => 'payment',
            'amount' => $request->amount,
            'time' => $transferTime,
            'category' => 'Chuyển quỹ nội bộ',
            'payment_method' => $request->from_method,
            'reference_type' => 'transfer',
            'reference_code' => $refCode,
            'description' => $request->description ?? 'Chuyển quỹ nội bộ',
            'status' => 'active',
        ]);

        // Phieu thu doi ung o quy dich
        $receipt = CashFlow::create([
            'code' => 'PT'.date('ymdHis').rand(10, 99),
            'type' => 'receipt',
            'amount' => $request->amount,
            'time' => $transferTime,
            'category' => 'Chuyển quỹ nội bộ',
            'payment_method' => $request->to_method,
            'bank_account_id' => $request->bank_account_id,
            'reference_type' => 'transfer',
            'reference_code' => $refCode,
            'description' => $request->description ?? 'Chuyển quỹ nội bộ',
            'status' => 'active',
        ]);

        ActivityLog::log('cashflow_transfer', "Chuyển quỹ {$refCode}: ".number_format($request->amount)." ({$request->from_method} -> {$request->to_method})");

        return response()->json([
            'success' => true,
            'message' => 'Chuyển quỹ thành công.',
            'payment_id' => $payment->id,
            'receipt_id' => $receipt->id,
            'reference_code' => $refCode,
        ]);
    }
}
