<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns cancellation of cash-book vouchers that are allowed to be cancelled
 * from the cash-book itself. Source documents remain the owner of their
 * inventory, payroll, offset and document-level reversals.
 */
class CashFlowCancellationService
{
    public const CANCELLED = 'cancelled';

    public const ALREADY_CANCELLED = 'already_cancelled';

    public const SOURCE_DOCUMENT_REQUIRED = 'source_document_required';

    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    private const SOURCE_OWNERS = [
        'Invoice',
        'Order',
        'OrderReturn',
        'Purchase',
        'PurchaseReturn',
        'PaysheetPayment',
        'paysheet_payment',
        'paysheet',
        'payslip',
        'SalaryAdvance',
        'salary_advance',
        'DebtOffset',
        'DebtOffsetCancel',
        'DebtOffsetReversal',
        'transfer',
    ];

    public function __construct(
        private readonly PartnerDebtMutationCoordinator $coordinator,
    ) {}

    public function policy(CashFlow $cashFlow): array
    {
        $referenceType = trim((string) ($cashFlow->reference_type ?? ''));

        if ($cashFlow->status === 'cancelled' || $cashFlow->trashed()) {
            return [
                'allowed' => false,
                'owner' => 'cash_book',
                'message' => 'Phiếu đã được hủy trước đó.',
            ];
        }

        if (in_array($referenceType, self::SOURCE_OWNERS, true)) {
            return [
                'allowed' => false,
                'owner' => 'source_document',
                'message' => 'Phiếu này phát sinh từ chứng từ nguồn. Vui lòng hủy chứng từ gốc để hệ thống tự đảo kho, công nợ và sổ quỹ.',
            ];
        }

        if ($referenceType === 'DebtPayment') {
            return [
                'allowed' => true,
                'owner' => 'customer_payment',
                'message' => 'Hủy phiếu sẽ hoàn lại các phân bổ thu nợ và công nợ khách hàng.',
            ];
        }

        if ($referenceType === 'SupplierPayment') {
            return [
                'allowed' => true,
                'owner' => 'supplier_payment',
                'message' => 'Hủy phiếu sẽ hoàn lại các phân bổ thanh toán và công nợ nhà cung cấp.',
            ];
        }

        if ($referenceType === '' || in_array($referenceType, ['Manual', 'CashFlow'], true)) {
            return [
                'allowed' => true,
                'owner' => 'cash_book',
                'message' => 'Phiếu này sẽ được đánh dấu đã hủy và không còn tính vào tổng quỹ.',
            ];
        }

        return [
            'allowed' => false,
            'owner' => 'unknown_source',
            'message' => 'Không xác định được chủ chứng từ. Phiếu được khóa để tránh đảo sai công nợ.',
        ];
    }

    public function cancel(CashFlow $cashFlow, string $reason, ?string $idempotencyKey = null): string
    {
        $snapshot = CashFlow::withTrashed()->findOrFail($cashFlow->id);
        $policy = $this->policy($snapshot);

        if (! $policy['allowed']) {
            if ($snapshot->status === 'cancelled' || $snapshot->trashed()) {
                return self::ALREADY_CANCELLED;
            }

            return $policy['owner'] === 'unknown_source'
                ? self::MANUAL_REVIEW_REQUIRED
                : self::SOURCE_DOCUMENT_REQUIRED;
        }

        if ($snapshot->reference_type === 'DebtPayment'
            && ! Schema::hasTable('customer_payment_allocation_reversals')) {
            return self::MANUAL_REVIEW_REQUIRED;
        }
        if ($snapshot->reference_type === 'SupplierPayment'
            && (! Schema::hasTable('supplier_payment_allocations')
                || ! Schema::hasTable('supplier_payment_allocation_reversals'))) {
            return self::MANUAL_REVIEW_REQUIRED;
        }

        $partnerId = $this->partnerId($snapshot);
        $payloadHash = hash('sha256', json_encode([
            'cash_flow_id' => (int) $snapshot->id,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE));

        $mutationExecuted = false;
        $mutation = function (?Customer $lockedPartner, $operation = null) use (&$mutationExecuted, $snapshot, $reason): string {
            $mutationExecuted = true;

            return DB::transaction(function () use ($snapshot, $reason, $lockedPartner, $operation): string {
                $flow = CashFlow::withTrashed()->lockForUpdate()->findOrFail($snapshot->id);
                if ($flow->status === 'cancelled' || $flow->trashed()) {
                    return self::ALREADY_CANCELLED;
                }

                $policy = $this->policy($flow);
                if (! $policy['allowed']) {
                    return $policy['owner'] === 'unknown_source'
                        ? self::MANUAL_REVIEW_REQUIRED
                        : self::SOURCE_DOCUMENT_REQUIRED;
                }

                if ($flow->reference_type === 'DebtPayment') {
                    $this->reverseCustomerPayment($flow, $reason, $operation);
                } elseif ($flow->reference_type === 'SupplierPayment') {
                    $this->reverseSupplierPayment($flow, $reason, $operation);
                } elseif ($this->partnerId($flow) > 0) {
                    $this->reverseStandalonePartnerProjection($flow, $lockedPartner);
                }

                $flow->forceFill([
                    'status' => 'cancelled',
                    'cancel_reason' => $reason,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                    'deleted_at' => null,
                ])->save();

                \App\Models\ActivityLog::log(
                    'cashflow_cancel',
                    "Hủy phiếu {$flow->code}, số tiền: ".number_format($flow->amount),
                    $flow,
                    [
                        'amount' => (float) $flow->amount,
                        'reference_type' => $flow->reference_type,
                        'reference_code' => $flow->reference_code,
                        'cancel_reason' => $reason,
                        'owner' => $policy['owner'],
                    ],
                );

                return self::CANCELLED;
            });
        };

        if ($partnerId > 0) {
            $result = $this->coordinator->execute(
                $partnerId,
                'cash_flow_cancel',
                $payloadHash,
                $mutation,
                $idempotencyKey,
            );

            return ! $mutationExecuted && $result === self::CANCELLED
                ? self::ALREADY_CANCELLED
                : $result;
        }

        return DB::transaction(fn (): string => $mutation(null));
    }

    /**
     * Persist allocation evidence when the source invoice owns the
     * cancellation. The invoice workflow owns the receivable reversal; this
     * evidence prevents a later DebtPayment cancellation from reversing the
     * same allocation a second time.
     */
    public function recordInvoiceAllocationReversals(Invoice $invoice, string $reason, $operation = null): void
    {
        if (! Schema::hasTable('customer_payment_allocations')
            || ! Schema::hasTable('customer_payment_allocation_reversals')) {
            throw new \RuntimeException('Customer allocation reversal evidence is unavailable.');
        }

        $allocations = DB::table('customer_payment_allocations')
            ->where('invoice_id', $invoice->id)
            ->lockForUpdate()
            ->get();
        $operationId = (int) ($operation?->id ?? 0);
        if ($allocations->isNotEmpty() && $operationId <= 0) {
            throw new \RuntimeException('Customer invoice cancellation operation evidence is unavailable.');
        }

        foreach ($allocations as $allocation) {
            $alreadyReversed = DB::table('customer_payment_allocation_reversals')
                ->where('allocation_id', $allocation->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyReversed) {
                continue;
            }

            DB::table('customer_payment_allocation_reversals')->insert([
                'allocation_id' => $allocation->id,
                'amount' => (float) $allocation->amount,
                'idempotency_key' => 'source-invoice-cancel:'.$invoice->id.':allocation:'.$allocation->id,
                'operation_id' => $operationId,
                'reason' => $reason,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Persist allocation evidence when the source purchase owns the
     * cancellation. The purchase workflow owns payable/inventory reversal;
     * the evidence makes subsequent SupplierPayment cancellation idempotent.
     */
    public function recordPurchaseAllocationReversals(Purchase $purchase, string $reason, $operation = null): void
    {
        if (! Schema::hasTable('supplier_payment_allocations')
            || ! Schema::hasTable('supplier_payment_allocation_reversals')) {
            throw new \RuntimeException('Supplier allocation reversal evidence is unavailable.');
        }

        $allocations = DB::table('supplier_payment_allocations')
            ->where('purchase_id', $purchase->id)
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $alreadyReversed = DB::table('supplier_payment_allocation_reversals')
                ->where('allocation_id', $allocation->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyReversed) {
                continue;
            }

            DB::table('supplier_payment_allocation_reversals')->insert([
                'allocation_id' => $allocation->id,
                'amount' => (float) $allocation->amount,
                'idempotency_key' => 'source-purchase-cancel:'.$purchase->id.':allocation:'.$allocation->id,
                'operation_id' => (int) ($operation?->id ?? $allocation->operation_id),
                'reason' => $reason,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function reverseCustomerPayment(CashFlow $flow, string $reason, $operation): void
    {
        if (! Schema::hasTable('customer_payment_allocation_reversals')) {
            throw new \RuntimeException('Customer allocation reversal evidence is unavailable.');
        }

        $allocations = $flow->customerPaymentAllocations()
            ->lockForUpdate()
            ->get();
        $restoredDebt = 0.0;

        foreach ($allocations as $allocation) {
            $reversalQuery = DB::table('customer_payment_allocation_reversals')
                ->where('allocation_id', $allocation->id)
                ->lockForUpdate();
            if ($reversalQuery->exists()) {
                continue;
            }

            $invoice = Invoice::query()->lockForUpdate()->find($allocation->invoice_id);
            $amount = (float) $allocation->amount;
            if ($invoice && ! BusinessStatus::isCancelled($invoice->status)) {
                $invoice->customer_paid = max(0.0, (float) $invoice->customer_paid - $amount);
                $invoice->save();
                $restoredDebt += $amount;
            }

            DB::table('customer_payment_allocation_reversals')->insert([
                'allocation_id' => $allocation->id,
                'amount' => $amount,
                'idempotency_key' => $this->reversalKey('customer', $flow, $allocation->id),
                'operation_id' => $this->operationId($operation),
                'reason' => $reason,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // A cancelled invoice already restored its own debt. Its allocation
        // remains audit evidence and must not restore customer debt again.
        $restoredDebt += max(0.0, (float) $flow->amount - (float) $allocations->sum('amount'));
        if ($flow->target_id && $restoredDebt > 0) {
            app(CustomerDebtService::class)->recordAdjustment(
                (int) $flow->target_id,
                $restoredDebt,
                "Hủy phiếu thu {$flow->code}",
                ['ref_code' => $flow->code, 'type' => 'payment_cancel'],
            );
        }
    }

    private function reverseSupplierPayment(CashFlow $flow, string $reason, $operation): void
    {
        if (! Schema::hasTable('supplier_payment_allocations')
            || ! Schema::hasTable('supplier_payment_allocation_reversals')) {
            throw new \RuntimeException('Supplier allocation reversal evidence is unavailable.');
        }

        $allocations = DB::table('supplier_payment_allocations')
            ->where('payment_id', $flow->id)
            ->lockForUpdate()
            ->get();
        $restoredDebt = 0.0;

        foreach ($allocations as $allocation) {
            $alreadyReversed = DB::table('supplier_payment_allocation_reversals')
                ->where('allocation_id', $allocation->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyReversed) {
                continue;
            }

            $purchase = Purchase::query()->lockForUpdate()->find($allocation->purchase_id);
            $amount = (float) $allocation->amount;
            if ($purchase && ! BusinessStatus::isCancelled($purchase->status)) {
                $purchase->paid_amount = max(0.0, (float) $purchase->paid_amount - $amount);
                $purchase->debt_amount = (float) $purchase->debt_amount + $amount;
                $purchase->save();
                $restoredDebt += $amount;
            }

            DB::table('supplier_payment_allocation_reversals')->insert([
                'allocation_id' => $allocation->id,
                'amount' => $amount,
                'idempotency_key' => $this->reversalKey('supplier', $flow, $allocation->id),
                'operation_id' => $this->operationId($operation),
                'reason' => $reason,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $unallocated = max(0.0, (float) $flow->amount - (float) $allocations->sum('amount'));
        $restoredDebt += $unallocated;

        if ($flow->target_id && $restoredDebt > 0) {
            $supplier = Customer::query()->lockForUpdate()->find($flow->target_id);
            if ($supplier) {
                $supplier->supplier_debt_amount = (float) $supplier->supplier_debt_amount + $restoredDebt;
                $supplier->save();
            }
        }

        if ($flow->target_id && $restoredDebt > 0 && Schema::hasTable('supplier_debt_transactions')) {
            DB::table('supplier_debt_transactions')->insert([
                'supplier_id' => $flow->target_id,
                'code' => 'H'.$flow->code,
                'type' => 'payment_cancel',
                'amount' => $restoredDebt,
                'debt_remain' => (float) Customer::find($flow->target_id)?->supplier_debt_amount,
                'note' => $reason,
                'user_id' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function reverseStandalonePartnerProjection(CashFlow $flow, $lockedPartner): void
    {
        if (! $lockedPartner instanceof Customer) {
            return;
        }

        $amount = (float) $flow->amount;
        if (in_array((string) $flow->target_type, PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES, true)) {
            $lockedPartner->debt_amount = (float) $lockedPartner->debt_amount
                - ($flow->type === 'receipt' ? -$amount : $amount);
        } elseif (in_array((string) $flow->target_type, PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES, true)) {
            $lockedPartner->supplier_debt_amount = (float) $lockedPartner->supplier_debt_amount
                - ($flow->type === 'payment' ? -$amount : $amount);
        }
        $lockedPartner->save();
    }

    private function partnerId(CashFlow $flow): int
    {
        if (! $flow->target_id) {
            return 0;
        }

        return in_array((string) $flow->target_type, array_merge(
            PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES,
            PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES,
        ), true) ? (int) $flow->target_id : 0;
    }

    private function operationId($operation): int
    {
        if ($operation && isset($operation->id)) {
            return (int) $operation->id;
        }

        $operationId = DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation_type' => 'debt.mutation.cash_flow_cancel',
            'idempotency_key' => 'internal:cash-flow-cancel:'.\Illuminate\Support\Str::uuid(),
            'request_hash' => hash('sha256', (string) microtime(true)),
            'request_hash_version' => 1,
            'status' => 'committed',
            'initiated_by' => auth()->id(),
            'initiated_at' => now(),
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $operationId;
    }

    private function reversalKey(string $role, CashFlow $flow, int $allocationId): string
    {
        return "cash-flow-cancel:{$role}:{$flow->id}:allocation:{$allocationId}";
    }
}
