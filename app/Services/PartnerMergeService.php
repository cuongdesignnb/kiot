<?php

namespace App\Services;

use App\Domain\DebtOffset\DebtOffsetStateMachine;
use App\Domain\DebtOffset\DecimalMoney;
use App\Exceptions\PartnerMergeException;
use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerMerge;
use App\Models\SupplierDebtTransaction;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\DebtOffsetWriteMode;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerMergeService
{
    /** @var list<array{0: string, 1: string}> */
    private const TRANSFER_RELATIONS = [
        ['invoices', 'customer_id'],
        ['orders', 'customer_id'],
        ['returns', 'customer_id'],
        ['purchases', 'supplier_id'],
        ['purchase_orders', 'supplier_id'],
        ['purchase_returns', 'supplier_id'],
        ['customer_debts', 'customer_id'],
        ['supplier_debt_transactions', 'supplier_id'],
        ['debt_offsets', 'customer_id'],
        ['customer_payment_allocations', 'customer_id'],
        ['supplier_payment_allocations', 'supplier_id'],
        ['customer_payment_discounts', 'customer_id'],
        ['customer_payment_discount_allocations', 'customer_id'],
        ['customer_delivery_addresses', 'customer_id'],
        ['promotion_usages', 'customer_id'],
        ['tasks', 'customer_id'],
        ['waybills', 'customer_id'],
    ];

    private const CASH_FLOW_TARGET_TYPES = [
        'customer',
        'supplier',
        'Khách hàng',
        'Nhà cung cấp',
        'Khach hang',
        'Nha cung cap',
    ];

    public function __construct(
        private readonly PartnerDebtMutationCoordinator $coordinator,
        private readonly CanonicalPartnerDebtService $canonical,
        private readonly DebtOffsetWriteMode $writeMode,
    ) {}

    public function preview(Customer $source, Customer $target): array
    {
        $this->assertMergeable($source, $target);
        $this->assertCanonicalAligned($source);
        $this->assertCanonicalAligned($target);

        return $this->buildPreview($source, $target);
    }

    public function merge(Customer $source, Customer $target, ?string $idempotencyKey = null): array
    {
        $executed = false;

        try {
            $result = $this->coordinator->executeForPartners(
                [(int) $source->id, (int) $target->id],
                'partner_merge',
                hash('sha256', 'merge|'.(int) $source->id.'|'.(int) $target->id),
                function (Collection $partners, ?PartnerDebtOperation $operation) use ($source, $target, &$executed): array {
                    $executed = true;
                    $lockedSource = $partners->get($source->id);
                    $lockedTarget = $partners->get($target->id);

                    if (! $lockedSource || ! $lockedTarget) {
                        throw PartnerMergeException::invalid(
                            'PARTNER_MERGE_TARGET_INVALID',
                            'Không tìm thấy đối tác để gộp.'
                        );
                    }

                    $this->assertMergeable($lockedSource, $lockedTarget);
                    $this->assertCanonicalAligned($lockedSource);
                    $this->assertCanonicalAligned($lockedTarget);

                    $markerCode = $this->markerCode($lockedSource->id, $lockedTarget->id);
                    if (PartnerMerge::query()->where('ref_code', $markerCode)->exists()) {
                        throw PartnerMergeException::conflict(
                            'PARTNER_ALREADY_MERGED',
                            'Đối tác nguồn đã được gộp trước đó.'
                        );
                    }

                    $preview = $this->buildPreview($lockedSource, $lockedTarget);
                    $this->lockTransferRelations([
                        (int) $lockedSource->id,
                        (int) $lockedTarget->id,
                    ]);
                    $this->transferRelations(
                        (int) $lockedSource->id,
                        (int) $lockedTarget->id,
                        (string) $lockedTarget->name,
                    );

                    $lockedTarget->forceFill([
                        'debt_amount' => $preview['combined']['debt_amount'],
                        'supplier_debt_amount' => $preview['combined']['supplier_debt_amount'],
                        'total_spent' => $preview['after']['total_spent'],
                        'total_returns' => $preview['after']['total_returns'],
                        'total_bought' => $preview['after']['total_bought'],
                        'is_customer' => (bool) ($lockedTarget->is_customer || $lockedSource->is_customer),
                        'is_supplier' => (bool) ($lockedTarget->is_supplier || $lockedSource->is_supplier),
                    ])->save();

                    $partnerMerge = PartnerMerge::query()->create([
                        'ref_code' => $markerCode,
                        'source_partner_id' => $lockedSource->id,
                        'target_partner_id' => $lockedTarget->id,
                        'source_debt_amount' => $lockedSource->debt_amount,
                        'source_supplier_debt_amount' => $lockedSource->supplier_debt_amount,
                        'target_debt_amount_before' => $preview['before']['target']['debt_amount'],
                        'target_supplier_debt_amount_before' => $preview['before']['target']['supplier_debt_amount'],
                        'source_total_spent_before' => $lockedSource->total_spent,
                        'source_total_returns_before' => $lockedSource->total_returns,
                        'source_total_bought_before' => $lockedSource->total_bought,
                        'target_total_spent_before' => $preview['before']['target']['total_spent'],
                        'target_total_returns_before' => $preview['before']['target']['total_returns'],
                        'target_total_bought_before' => $preview['before']['target']['total_bought'],
                        'target_debt_amount_after' => $preview['after']['debt_amount'],
                        'target_supplier_debt_amount_after' => $preview['after']['supplier_debt_amount'],
                        'target_total_spent_after' => $preview['after']['total_spent'],
                        'target_total_returns_after' => $preview['after']['total_returns'],
                        'target_total_bought_after' => $preview['after']['total_bought'],
                        'merged_by' => auth()->id(),
                        'merged_at' => now(),
                    ]);
                    $operation?->forceFill([
                        'source_type' => 'PartnerMerge',
                        'source_id' => $partnerMerge->id,
                    ])->save();
                    $this->coordinator->checkpoint('document');

                    $offset = $this->createAutomaticOffset(
                        $lockedSource,
                        $lockedTarget,
                        $preview,
                        $markerCode,
                        $partnerMerge,
                    );

                    CustomerDebt::query()->firstOrCreate(
                        [
                            'customer_id' => $lockedTarget->id,
                            'ref_code' => $markerCode,
                            'type' => 'merge_marker',
                        ],
                        [
                            'amount' => 0,
                            'debt_total' => $preview['after']['debt_amount'],
                            'note' => "Gộp hồ sơ {$lockedSource->code} vào {$lockedTarget->code}",
                            'created_by' => auth()->id(),
                            'recorded_at' => now(),
                        ]
                    );
                    $this->coordinator->checkpoint('evidence');

                    $lockedTarget->forceFill([
                        'debt_amount' => $preview['after']['debt_amount'],
                        'supplier_debt_amount' => $preview['after']['supplier_debt_amount'],
                    ])->save();
                    $lockedSource->forceFill([
                        'debt_amount' => 0,
                        'supplier_debt_amount' => 0,
                        'total_spent' => 0,
                        'total_returns' => 0,
                        'total_bought' => 0,
                        'status' => 'inactive',
                        'merged_into_id' => $lockedTarget->id,
                        'merged_at' => now(),
                    ])->save();
                    $this->coordinator->checkpoint('projection');

                    $result = array_merge($preview, [
                        'status' => 'merged',
                        'source_id' => (int) $lockedSource->id,
                        'target_id' => (int) $lockedTarget->id,
                        'offset' => $offset ? [
                            'id' => (int) $offset->id,
                            'code' => (string) $offset->code,
                            'amount' => (float) $offset->amount,
                            'workflow_status' => $offset->workflow_status,
                        ] : null,
                    ]);

                    ActivityLog::log(
                        'partner_merge',
                        "Gộp đối tác {$lockedSource->code} vào {$lockedTarget->code}",
                        $lockedTarget,
                        [
                            'source_partner_id' => $lockedSource->id,
                            'target_partner_id' => $lockedTarget->id,
                            'marker_code' => $markerCode,
                            'before' => $preview['before'],
                            'combined' => $preview['combined'],
                            'automatic_offset' => $preview['automatic_offset'],
                            'after' => $preview['after'],
                            'offset_id' => $offset?->id,
                        ]
                    );

                    return $result;
                },
                $idempotencyKey,
            );
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['idempotency_key'])) {
                throw PartnerMergeException::conflict(
                    'IDEMPOTENCY_KEY_REUSED',
                    (string) $exception->errors()['idempotency_key'][0],
                    $exception->errors(),
                );
            }

            throw $exception;
        }

        if (! $executed) {
            $result['status'] = 'already_merged';
        }

        return $result;
    }

    private function buildPreview(Customer $source, Customer $target): array
    {
        $sourceReceivable = $this->money($source->debt_amount);
        $targetReceivable = $this->money($target->debt_amount);
        $sourcePayable = $this->money($source->supplier_debt_amount);
        $targetPayable = $this->money($target->supplier_debt_amount);
        $combinedReceivable = $sourceReceivable->add($targetReceivable);
        $combinedPayable = $sourcePayable->add($targetPayable);
        $offset = DecimalMoney::fromCents(min(
            max($combinedReceivable->cents(), 0),
            max($combinedPayable->cents(), 0),
        ));

        if ($offset->isPositive() && $this->writeMode->current() === DebtOffsetWriteMode::DISABLED) {
            throw PartnerMergeException::forbidden(
                'DEBT_OFFSET_DISABLED',
                'Không thể gộp vì thao tác này cần tự động đối trừ công nợ nhưng chức năng cấn trừ đang bị tắt.'
            );
        }

        $receivableAfter = $combinedReceivable->subtract($offset);
        $payableAfter = $combinedPayable->subtract($offset);
        $totalSpentAfter = $this->money($source->total_spent)->add($this->money($target->total_spent));
        $totalReturnsAfter = $this->money($source->total_returns)->add($this->money($target->total_returns));
        $totalBoughtAfter = $this->money($source->total_bought)->add($this->money($target->total_bought));

        return [
            'status' => 'ready',
            'allowed' => true,
            'surviving_partner_id' => (int) $target->id,
            'before' => [
                'source' => $this->partnerSnapshot($source),
                'target' => $this->partnerSnapshot($target),
            ],
            'combined' => [
                'debt_amount' => $this->number($combinedReceivable),
                'supplier_debt_amount' => $this->number($combinedPayable),
            ],
            'automatic_offset' => [
                'required' => $offset->isPositive(),
                'amount' => $this->number($offset),
                'is_auto' => true,
            ],
            'after' => [
                'debt_amount' => $this->number($receivableAfter),
                'supplier_debt_amount' => $this->number($payableAfter),
                'total_spent' => $this->number($totalSpentAfter),
                'total_returns' => $this->number($totalReturnsAfter),
                'total_bought' => $this->number($totalBoughtAfter),
                'customer_net_position' => $this->number($receivableAfter->subtract($payableAfter)),
                'supplier_net_position' => $this->number($payableAfter->subtract($receivableAfter)),
            ],
            'marker' => [
                'ref_code' => $this->markerCode($source->id, $target->id),
                'amount' => 0.0,
                'type' => 'merge_marker',
                'source_layer' => 'reference',
                'is_reference_only' => true,
                'affects_debt_balance' => false,
            ],
        ];
    }

    private function createAutomaticOffset(
        Customer $source,
        Customer $target,
        array $preview,
        string $markerCode,
        PartnerMerge $partnerMerge,
    ): ?DebtOffset {
        $amount = $this->money($preview['automatic_offset']['amount']);
        if (! $amount->isPositive()) {
            return null;
        }

        $mode = $this->writeMode->current();
        if ($mode === DebtOffsetWriteMode::DISABLED) {
            throw PartnerMergeException::forbidden(
                'DEBT_OFFSET_DISABLED',
                'Chức năng cấn trừ công nợ đang bị tắt.'
            );
        }

        $systemKey = "partner-merge-offset:{$source->id}:{$target->id}";
        $offset = DebtOffset::query()->create([
            'code' => 'TMP-CB-'.Str::uuid(),
            'customer_id' => $target->id,
            'amount' => $amount->toDecimal(),
            'customer_amount' => $amount->toDecimal(),
            'supplier_amount' => $amount->toDecimal(),
            'receivable_before' => $preview['combined']['debt_amount'],
            'payable_before' => $preview['combined']['supplier_debt_amount'],
            'receivable_after' => $preview['after']['debt_amount'],
            'payable_after' => $preview['after']['supplier_debt_amount'],
            'is_auto' => true,
            'note' => "Tự động đối trừ khi gộp {$source->code} vào {$target->code}",
            'user_id' => auth()->id(),
            'status' => 'active',
            'workflow_status' => $mode === DebtOffsetWriteMode::WORKFLOW
                ? DebtOffsetStateMachine::APPLIED
                : null,
            'applied_at' => now(),
            'idempotency_key' => $systemKey,
            'source_references' => [
                'created_via' => 'partner_merge',
                'partner_merge_ref_code' => $markerCode,
                'partner_merge_id' => (int) $partnerMerge->id,
                'source_partner_id' => (int) $source->id,
                'target_partner_id' => (int) $target->id,
            ],
        ]);
        $offset->forceFill([
            'code' => 'CB'.str_pad((string) $offset->id, 6, '0', STR_PAD_LEFT),
        ])->save();

        CashFlow::query()->create([
            'code' => $offset->code,
            'type' => 'receipt',
            'amount' => $amount->toDecimal(),
            'time' => now(),
            'category' => 'Đối trừ công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $target->id,
            'target_name' => $target->name,
            'branch_id' => $target->branch_id,
            'reference_type' => 'DebtOffset',
            'reference_code' => $offset->code,
            'description' => "Tự động đối trừ KH↔NCC khi gộp {$source->code} vào {$target->code}",
            'idempotency_key' => $systemKey,
        ]);

        SupplierDebtTransaction::query()->create([
            'supplier_id' => $target->id,
            'code' => $offset->code,
            'type' => 'offset',
            'amount' => $amount->negate()->toDecimal(),
            'debt_remain' => $preview['after']['supplier_debt_amount'],
            'note' => "Tự động đối trừ KH↔NCC khi gộp {$source->code} vào {$target->code}",
            'user_id' => auth()->id(),
        ]);

        return $offset;
    }

    private function assertMergeable(Customer $source, Customer $target): void
    {
        if ((int) $source->id === (int) $target->id) {
            throw PartnerMergeException::invalid(
                'PARTNER_MERGE_TARGET_INVALID',
                'Không thể gộp đối tác với chính mình.'
            );
        }
        if ($source->merged_into_id) {
            throw PartnerMergeException::conflict(
                'PARTNER_ALREADY_MERGED',
                'Đối tác nguồn đã được gộp trước đó.'
            );
        }
        if ($target->merged_into_id || strtolower((string) $target->status) === 'inactive') {
            throw PartnerMergeException::invalid(
                'PARTNER_MERGE_TARGET_INVALID',
                'Đối tác đích không còn hoạt động.'
            );
        }
        if (strtolower((string) $source->status) === 'inactive') {
            throw PartnerMergeException::invalid(
                'PARTNER_MERGE_NOT_ALLOWED',
                'Đối tác nguồn không còn hoạt động.'
            );
        }

        $hasCustomerRole = (bool) $source->is_customer || (bool) $target->is_customer;
        $hasSupplierRole = (bool) $source->is_supplier || (bool) $target->is_supplier;
        if (! $hasCustomerRole || ! $hasSupplierRole) {
            throw PartnerMergeException::invalid(
                'PARTNER_MERGE_NOT_ALLOWED',
                'Chỉ được gộp hồ sơ có đủ vai trò khách hàng và nhà cung cấp.'
            );
        }
    }

    private function assertCanonicalAligned(Customer $partner): void
    {
        $canonical = $this->canonical->calculate($partner);
        if (! ($canonical['has_mismatch'] ?? false)) {
            return;
        }

        throw PartnerMergeException::invalid(
            'PARTNER_MERGE_DEBT_RECONCILIATION_REQUIRED',
            "Công nợ của {$partner->name} ({$partner->code}) chưa khớp sổ chi tiết. Hãy đối soát trước khi gộp.",
            [
                'partner_id' => [(int) $partner->id],
                'customer_receivable_difference' => [(float) $canonical['differences']['customer_receivable']],
                'supplier_payable_difference' => [(float) $canonical['differences']['supplier_payable']],
            ],
        );
    }

    /** @param list<int> $partnerIds */
    private function lockTransferRelations(array $partnerIds): void
    {
        foreach (self::TRANSFER_RELATIONS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $selectColumn = Schema::hasColumn($table, 'id') ? 'id' : $column;
            DB::table($table)
                ->whereIn($column, $partnerIds)
                ->orderBy($selectColumn)
                ->lockForUpdate()
                ->get([$selectColumn]);
        }

        CashFlow::withTrashed()
            ->whereIn('target_id', $partnerIds)
            ->whereIn('target_type', self::CASH_FLOW_TARGET_TYPES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function transferRelations(int $sourceId, int $targetId, string $targetName): void
    {
        foreach (self::TRANSFER_RELATIONS as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $sourceId)->update([$column => $targetId]);
            }
        }

        CashFlow::withTrashed()
            ->where('target_id', $sourceId)
            ->whereIn('target_type', self::CASH_FLOW_TARGET_TYPES)
            ->update(['target_id' => $targetId, 'target_name' => $targetName]);

        foreach (self::TRANSFER_RELATIONS as [$table, $column]) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $sourceId)->exists()) {
                throw PartnerMergeException::invalid(
                    'PARTNER_MERGE_TRANSFER_INCOMPLETE',
                    "Không thể chuyển hết dữ liệu liên quan trong bảng {$table}."
                );
            }
        }

        if (CashFlow::withTrashed()
            ->where('target_id', $sourceId)
            ->whereIn('target_type', self::CASH_FLOW_TARGET_TYPES)
            ->exists()) {
            throw PartnerMergeException::invalid(
                'PARTNER_MERGE_TRANSFER_INCOMPLETE',
                'Không thể chuyển hết phiếu thu/chi liên quan.'
            );
        }
    }

    private function partnerSnapshot(Customer $partner): array
    {
        return [
            'id' => (int) $partner->id,
            'code' => $partner->code,
            'name' => $partner->name,
            'debt_amount' => (float) $partner->debt_amount,
            'supplier_debt_amount' => (float) $partner->supplier_debt_amount,
            'total_spent' => (float) $partner->total_spent,
            'total_returns' => (float) $partner->total_returns,
            'total_bought' => (float) $partner->total_bought,
            'document_counts' => [
                'invoices' => $partner->invoices()->count(),
                'orders' => $partner->orders()->count(),
                'returns' => $partner->returns()->count(),
                'purchases' => $partner->purchases()->count(),
            ],
        ];
    }

    private function money(mixed $value): DecimalMoney
    {
        return DecimalMoney::from((string) ($value ?? 0));
    }

    private function number(DecimalMoney $money): float
    {
        return (float) $money->toDecimal();
    }

    private function markerCode(int $sourceId, int $targetId): string
    {
        return "MERGE-PARTNER-{$sourceId}-TO-{$targetId}";
    }
}
