<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Support\Status\BusinessStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPaymentService
{
    public const CANCELLED = 'cancelled';

    public const ALREADY_CANCELLED = 'already_cancelled';

    public const SOURCE_DOCUMENT_REQUIRED = 'source_document_required';

    public function __construct(private readonly PartnerDebtMutationCoordinator $coordinator) {}

    public function collect(
        Customer $customer,
        float $paymentAmount,
        string $mode = 'auto',
        array $requestedAllocations = [],
        ?string $note = null,
        Carbon|string|null $paidAt = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($paymentAmount <= 0) {
            throw ValidationException::withMessages(['amount' => 'So tien thanh toan phai lon hon 0.']);
        }

        $payloadHash = hash('sha256', json_encode([
            'customer_id' => (int) $customer->id,
            'amount' => $paymentAmount,
            'mode' => $mode,
            'allocations' => $requestedAllocations,
            'note' => $note,
            'paid_at' => $paidAt instanceof Carbon ? $paidAt->toIso8601String() : $paidAt,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $this->coordinator->execute(
            (int) $customer->id,
            'customer_payment_collect',
            $payloadHash,
            function (Customer $lockedCustomer) use (
                $customer,
                $paymentAmount,
                $mode,
                $requestedAllocations,
                $note,
                $paidAt,
            ) {
                if (! (bool) $lockedCustomer->is_customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                    ]);
                }
                app(PartnerTransactionGuard::class)->assertCanTransact((int) $customer->id, 'customer_id');
                $debtBefore = (float) $lockedCustomer->debt_amount;
                $allocations = $mode === 'manual'
                    ? $this->resolveManualAllocations($lockedCustomer, $paymentAmount, $requestedAllocations)
                    : $this->resolveAutomaticAllocations($lockedCustomer, $paymentAmount);
                $allocatedAmount = (float) collect($allocations)->sum('amount');
                $unallocatedAmount = max(0.0, $paymentAmount - $allocatedAmount);
                $paymentTime = $paidAt ? Carbon::parse($paidAt) : now();

                $cashFlow = CashFlow::create([
                    'code' => 'PT'.date('ymdHis').random_int(10, 99),
                    'type' => 'receipt',
                    'amount' => $paymentAmount,
                    'time' => $paymentTime,
                    'category' => 'Thu no khach hang',
                    'target_type' => 'Khách hàng',
                    'target_id' => $lockedCustomer->id,
                    'target_name' => $lockedCustomer->name,
                    'reference_type' => 'DebtPayment',
                    'reference_code' => null,
                    'description' => $note ?: 'Thu no khach hang '.$lockedCustomer->name,
                    'status' => 'active',
                ]);
                $this->coordinator->checkpoint('document');

                if ($paidAt) {
                    $cashFlow->created_at = $paymentTime;
                    $cashFlow->save();
                }

                $allocationCodes = [];
                foreach ($allocations as $allocation) {
                    $invoice = Invoice::query()->lockForUpdate()->findOrFail($allocation['invoice_id']);
                    $invoice->increment('customer_paid', $allocation['amount']);
                    CustomerPaymentAllocation::create([
                        'cash_flow_id' => $cashFlow->id,
                        'customer_id' => $lockedCustomer->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $allocation['amount'],
                    ]);
                    $allocationCodes[] = $invoice->code.':'.number_format($allocation['amount'], 2, '.', '');
                }
                $this->coordinator->checkpoint('evidence');

                $cashFlow->reference_code = implode(';', $allocationCodes);
                $cashFlow->save();

                app(CustomerDebtService::class)->recordPayment(
                    $lockedCustomer->id,
                    $paymentAmount,
                    null,
                    $note ?: "Thu no khach hang {$lockedCustomer->name}",
                    ['ref_code' => $cashFlow->code]
                );
                $this->coordinator->checkpoint('projection');

                $debtAfter = (float) $lockedCustomer->fresh()->debt_amount;

                return [
                    'payment_amount' => $paymentAmount,
                    'allocated_amount' => $allocatedAmount,
                    'unallocated_amount' => $unallocatedAmount,
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'is_overpayment' => $unallocatedAmount > 0.0,
                    'overpayment_amount' => $unallocatedAmount,
                    'cash_flow_id' => $cashFlow->id,
                    'cash_flow_code' => $cashFlow->code,
                ];
            },
            $idempotencyKey,
        );
    }

    public function cancel(
        CashFlow $cashFlow,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): string {
        $flowSnapshot = CashFlow::withTrashed()->findOrFail($cashFlow->id);
        if (in_array($flowSnapshot->reference_type, [
            'Invoice',
            'Order',
            'OrderReturn',
            'Purchase',
            'PurchaseReturn',
            'SupplierPayment',
        ], true)) {
            return self::SOURCE_DOCUMENT_REQUIRED;
        }
        if (! $flowSnapshot->target_id) {
            return $this->cancelUnlinkedFlow($flowSnapshot, $reason);
        }

        $payloadHash = hash('sha256', json_encode([
            'cash_flow_id' => (int) $flowSnapshot->id,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE));

        return $this->coordinator->execute(
            (int) $flowSnapshot->target_id,
            'customer_payment_cancel',
            $payloadHash,
            function (Customer $lockedPartner) use ($flowSnapshot, $reason): string {
                return $this->cancelUnlinkedFlow($flowSnapshot, $reason, $lockedPartner);
            },
            $idempotencyKey,
        );
    }

    private function cancelUnlinkedFlow(
        CashFlow $cashFlow,
        ?string $reason,
        ?Customer $lockedPartner = null,
    ): string {
        return DB::transaction(function () use ($cashFlow, $reason, $lockedPartner): string {
            $flow = CashFlow::withTrashed()->lockForUpdate()->findOrFail($cashFlow->id);
            if (! BusinessStatus::isValidCashFlow($flow->status) || $flow->trashed()) {
                return self::ALREADY_CANCELLED;
            }

            if ($flow->reference_type === 'DebtPayment') {
                $this->cancelDebtPayment($flow);
            } elseif (in_array($flow->reference_type, [
                'Invoice',
                'Order',
                'OrderReturn',
                'Purchase',
                'PurchaseReturn',
                'SupplierPayment',
            ], true)) {
                return self::SOURCE_DOCUMENT_REQUIRED;
            }

            if ($lockedPartner && $flow->reference_type !== 'DebtPayment') {
                $amount = (float) $flow->amount;
                $isCustomer = in_array((string) $flow->target_type, PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES, true);
                $isSupplier = in_array((string) $flow->target_type, PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES, true);
                if ($isCustomer) {
                    if (! (bool) $lockedPartner->is_customer) {
                        throw ValidationException::withMessages([
                            'target_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                        ]);
                    }
                    $originalDelta = $flow->type === 'receipt' ? -$amount : $amount;
                    $lockedPartner->debt_amount = (float) $lockedPartner->debt_amount - $originalDelta;
                } elseif ($isSupplier) {
                    if (! (bool) $lockedPartner->is_supplier) {
                        throw ValidationException::withMessages([
                            'target_id' => 'Doi tac khong co vai tro nha cung cap da duoc luu.',
                        ]);
                    }
                    $originalDelta = $flow->type === 'payment' ? -$amount : $amount;
                    $lockedPartner->supplier_debt_amount = (float) $lockedPartner->supplier_debt_amount - $originalDelta;
                }
                $lockedPartner->save();
                $this->coordinator->checkpoint('projection');
            }

            $flow->status = 'cancelled';
            $flow->cancel_reason = $reason;
            $flow->cancelled_by = auth()->id();
            $flow->cancelled_at = now();
            $flow->save();
            $flow->delete();
            $this->coordinator->checkpoint('evidence');

            ActivityLog::log(
                'cashflow_cancel',
                "Huy phieu {$flow->code}, so tien: ".number_format($flow->amount),
                $flow,
                [
                    'amount' => (float) $flow->amount,
                    'reference_type' => $flow->reference_type,
                    'reference_code' => $flow->reference_code,
                    'cancel_reason' => $reason,
                ],
            );

            return self::CANCELLED;
        });
    }

    public function isFinanciallyLinked(CashFlow $cashFlow): bool
    {
        if ($cashFlow->target_id && (in_array((string) $cashFlow->target_type, PartnerDebtRoleResolver::CUSTOMER_TARGET_TYPES, true)
            || in_array((string) $cashFlow->target_type, PartnerDebtRoleResolver::SUPPLIER_TARGET_TYPES, true))) {
            return true;
        }

        return in_array($cashFlow->reference_type, [
            'DebtPayment',
            'Invoice',
            'Order',
            'OrderReturn',
            'Purchase',
            'PurchaseReturn',
            'SupplierPayment',
        ], true);
    }

    private function resolveAutomaticAllocations(Customer $customer, float $paymentAmount): array
    {
        $remaining = $paymentAmount;
        $allocations = [];
        $invoices = app(CustomerReceivableInvoiceService::class)->query($customer)->get();

        foreach ($invoices as $invoice) {
            if ($remaining < 0.01) {
                break;
            }

            $invoiceRemaining = app(CustomerReceivableInvoiceService::class)->remaining($invoice);
            $allocated = min($remaining, $invoiceRemaining);
            if ($allocated < 0.01) {
                continue;
            }
            $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $allocated];
            $remaining -= $allocated;
        }

        return $allocations;
    }

    private function resolveManualAllocations(
        Customer $customer,
        float $paymentAmount,
        array $requestedAllocations
    ): array {
        $allocations = [];
        $allocatedTotal = 0.0;
        $seenInvoiceIds = [];

        foreach ($requestedAllocations as $requested) {
            $invoiceId = (int) ($requested['invoice_id'] ?? 0);
            $amount = (float) ($requested['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'So tien phan bo phai lon hon 0.',
                ]);
            }
            if (isset($seenInvoiceIds[$invoiceId])) {
                throw ValidationException::withMessages([
                    'allocations' => 'Moi hoa don chi duoc xuat hien mot lan trong danh sach phan bo.',
                ]);
            }
            $seenInvoiceIds[$invoiceId] = true;

            $invoice = app(CustomerReceivableInvoiceService::class)->query($customer)
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->first();
            if (! $invoice) {
                throw ValidationException::withMessages([
                    'allocations' => 'Hoa don phan bo khong hop le hoac khong con no.',
                ]);
            }

            $invoiceRemaining = app(CustomerReceivableInvoiceService::class)->remaining($invoice);
            if ($amount > $invoiceRemaining + 0.01) {
                throw ValidationException::withMessages([
                    'allocations' => "So phan bo cho hoa don {$invoice->code} vuot so con phai thu.",
                ]);
            }
            $allocatedTotal += $amount;
            if ($allocatedTotal > $paymentAmount + 0.01) {
                throw ValidationException::withMessages([
                    'allocations' => 'Tong phan bo khong duoc vuot so tien thuc nhan.',
                ]);
            }
            $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $amount];
        }

        return $allocations;
    }

    private function cancelDebtPayment(CashFlow $flow): void
    {
        $allocations = CustomerPaymentAllocation::query()
            ->where('cash_flow_id', $flow->id)
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $invoice = Invoice::query()->lockForUpdate()->find($allocation->invoice_id);
            if ($invoice) {
                $invoice->customer_paid = max(
                    0.0,
                    (float) $invoice->customer_paid - (float) $allocation->amount
                );
                $invoice->save();
            }
        }

        if ($flow->target_id && (float) $flow->amount > 0) {
            app(CustomerDebtService::class)->recordAdjustment(
                (int) $flow->target_id,
                (float) $flow->amount,
                "Huy phieu thu {$flow->code}",
                ['ref_code' => $flow->code, 'type' => 'payment_cancel']
            );
        }
    }

    private function cancelInvoicePayment(CashFlow $flow): void
    {
        $invoice = Invoice::query()
            ->where('code', $flow->reference_code)
            ->lockForUpdate()
            ->first();
        if (! $invoice || BusinessStatus::isCancelled($invoice->status)) {
            return;
        }

        $reversalAmount = min((float) $flow->amount, max(0.0, (float) $invoice->customer_paid));
        $invoice->customer_paid = (float) $invoice->customer_paid - $reversalAmount;
        $invoice->save();

        if ($invoice->customer_id && $reversalAmount >= 0.01) {
            app(CustomerDebtService::class)->recordAdjustment(
                (int) $invoice->customer_id,
                $reversalAmount,
                "Huy phieu thu {$flow->code} cua hoa don {$invoice->code}",
                ['ref_code' => $invoice->code, 'type' => 'payment_cancel']
            );
        }
    }
}
