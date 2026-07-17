<?php

namespace App\Services\Debt;

use App\Domain\DebtOffset\DebtOffsetStateMachine;
use App\Domain\DebtOffset\DecimalMoney;
use App\Exceptions\DebtOffsetWorkflowException;
use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Models\PartnerDebtOutboxEvent;
use App\Models\Setting;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DebtOffsetWorkflowService
{
    private const OPERATION_CREATE = 'debt_offset.create_draft';

    private const OPERATION_UPDATE = 'debt_offset.update_draft';

    private const OPERATION_SUBMIT = 'debt_offset.submit';

    private const OPERATION_APPROVE = 'debt_offset.approve';

    private const OPERATION_REJECT = 'debt_offset.reject';

    private const OPERATION_APPLY = 'debt_offset.apply';

    private const OPERATION_REVERSE = 'debt_offset.reverse';

    private const OPERATION_VOID = 'debt_offset.void';

    public function __construct(
        private readonly DebtOffsetStateMachine $stateMachine,
        private readonly DebtOffsetWriteMode $writeMode,
        private readonly DebtOffsetFailureInjector $failureInjector,
    ) {}

    public function createDraft(Customer $partner, User $actor, int|string $amount, ?string $note, string $idempotencyKey): array
    {
        $this->writeMode->assertWorkflowAllowed();
        $money = $this->positiveMoney($amount);
        $note = $this->trimNullable($note);
        $key = $this->idempotencyKey($idempotencyKey);
        $payload = $this->commandPayload(self::OPERATION_CREATE, null, $partner->id, $money, $note, null);

        return DB::transaction(function () use ($partner, $actor, $money, $note, $key, $payload): array {
            $lockedPartner = Customer::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            $this->assertPartnerEligible($lockedPartner, $actor);

            [$operation, $replay] = $this->operation(
                self::OPERATION_CREATE,
                $key,
                $payload,
                $lockedPartner->id,
                null,
                $actor,
            );
            if ($replay) {
                return $this->replayResult($operation);
            }

            $receivable = DecimalMoney::from((string) $lockedPartner->debt_amount);
            $payable = DecimalMoney::from((string) $lockedPartner->supplier_debt_amount);
            $temporaryCode = 'TMP-CB-'.Str::uuid();
            $offset = DebtOffset::query()->create([
                'code' => $temporaryCode,
                'customer_id' => $lockedPartner->id,
                'amount' => $money->toDecimal(),
                'customer_amount' => $money->toDecimal(),
                'supplier_amount' => $money->toDecimal(),
                'receivable_before' => $receivable->toDecimal(),
                'payable_before' => $payable->toDecimal(),
                'receivable_after' => $receivable->toDecimal(),
                'payable_after' => $payable->toDecimal(),
                'is_auto' => false,
                'note' => $note,
                'user_id' => $actor->id,
                'status' => 'pending',
                'workflow_status' => DebtOffsetStateMachine::DRAFT,
                'idempotency_key' => $key,
                'source_references' => ['created_via' => 'workflow'],
            ]);
            $offset->forceFill(['code' => $this->voucherCode('CB', $offset->id)])->save();
            $operation->forceFill(['source_type' => 'DebtOffset', 'source_id' => $offset->id])->save();

            return $this->commitEvidence(
                $operation,
                $offset,
                $lockedPartner,
                $actor,
                'debt_offset.draft.created',
                ActivityLog::ACTION_DEBT_OFFSET_DRAFT_CREATE,
                'Tạo bản nháp cấn trừ công nợ',
                DecimalMoney::fromCents(0),
                DecimalMoney::fromCents(0),
                null,
            );
        }, 5);
    }

    public function updateDraft(DebtOffset $debtOffset, User $actor, int|string $amount, ?string $note, string $versionToken, string $idempotencyKey): array
    {
        $this->writeMode->assertWorkflowAllowed();
        $money = $this->positiveMoney($amount);
        $note = $this->trimNullable($note);

        return $this->transitionCommand(
            $debtOffset,
            $actor,
            self::OPERATION_UPDATE,
            $idempotencyKey,
            $versionToken,
            $money,
            $note,
            function (DebtOffset $lockedOffset): void {
                $this->assertCurrentState($lockedOffset, DebtOffsetStateMachine::DRAFT);
            },
            function (DebtOffset $lockedOffset) use ($money, $note): void {
                $lockedOffset->forceFill([
                    'amount' => $money->toDecimal(),
                    'customer_amount' => $money->toDecimal(),
                    'supplier_amount' => $money->toDecimal(),
                    'note' => $note,
                ])->save();
            },
            'debt_offset.draft.updated',
            ActivityLog::ACTION_DEBT_OFFSET_DRAFT_UPDATE,
            'Cập nhật bản nháp cấn trừ công nợ',
        );
    }

    public function submit(DebtOffset $debtOffset, User $actor, string $versionToken, string $idempotencyKey): array
    {
        return $this->transitionCommand(
            $debtOffset,
            $actor,
            self::OPERATION_SUBMIT,
            $idempotencyKey,
            $versionToken,
            DecimalMoney::from((string) $debtOffset->amount),
            null,
            function (DebtOffset $lockedOffset, Customer $partner): void {
                $this->stateMachine->assertCan((string) $lockedOffset->workflow_status, DebtOffsetStateMachine::PENDING_APPROVAL);
                $this->assertAmountWithinCurrentBalances($lockedOffset, $partner);
            },
            function (DebtOffset $lockedOffset) use ($actor): void {
                $lockedOffset->forceFill([
                    'workflow_status' => DebtOffsetStateMachine::PENDING_APPROVAL,
                    'status' => 'pending',
                    'requested_by' => $actor->id,
                    'requested_at' => now(),
                ])->save();
            },
            'debt_offset.submitted',
            ActivityLog::ACTION_DEBT_OFFSET_SUBMIT,
            'Gửi yêu cầu cấn trừ để duyệt',
        );
    }

    public function approve(DebtOffset $debtOffset, User $actor, string $versionToken, string $idempotencyKey): array
    {
        return $this->transitionCommand(
            $debtOffset,
            $actor,
            self::OPERATION_APPROVE,
            $idempotencyKey,
            $versionToken,
            DecimalMoney::from((string) $debtOffset->amount),
            null,
            function (DebtOffset $lockedOffset) use ($actor): void {
                $this->stateMachine->assertCan((string) $lockedOffset->workflow_status, DebtOffsetStateMachine::APPROVED);
                if ((bool) config('debt.offsets.require_distinct_approver', true)
                    && (int) $lockedOffset->requested_by === (int) $actor->id) {
                    throw DebtOffsetWorkflowException::forbidden(
                        'SELF_APPROVAL_FORBIDDEN',
                        'Người gửi yêu cầu không được tự duyệt phiếu cấn trừ.'
                    );
                }
            },
            function (DebtOffset $lockedOffset, Customer $partner, PartnerDebtOperation $operation) use ($actor): void {
                $lockedOffset->forceFill([
                    'workflow_status' => DebtOffsetStateMachine::APPROVED,
                    'status' => 'pending',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'approval_operation_id' => $operation->id,
                ])->save();
            },
            'debt_offset.approved',
            ActivityLog::ACTION_DEBT_OFFSET_APPROVE,
            'Duyệt yêu cầu cấn trừ công nợ',
        );
    }

    public function reject(DebtOffset $debtOffset, User $actor, string $reason, string $versionToken, string $idempotencyKey): array
    {
        $reason = $this->requiredReason($reason);

        return $this->transitionCommand(
            $debtOffset,
            $actor,
            self::OPERATION_REJECT,
            $idempotencyKey,
            $versionToken,
            DecimalMoney::from((string) $debtOffset->amount),
            $reason,
            function (DebtOffset $lockedOffset): void {
                $this->stateMachine->assertCan((string) $lockedOffset->workflow_status, DebtOffsetStateMachine::REJECTED);
            },
            function (DebtOffset $lockedOffset) use ($actor, $reason): void {
                $lockedOffset->forceFill([
                    'workflow_status' => DebtOffsetStateMachine::REJECTED,
                    'status' => 'rejected',
                    'rejected_by' => $actor->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $reason,
                ])->save();
            },
            'debt_offset.rejected',
            ActivityLog::ACTION_DEBT_OFFSET_REJECT,
            'Từ chối yêu cầu cấn trừ công nợ',
        );
    }

    public function apply(DebtOffset $debtOffset, User $actor, string $versionToken, string $idempotencyKey): array
    {
        $this->writeMode->assertWorkflowAllowed();
        $identity = $this->offsetIdentity($debtOffset);
        $key = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($identity, $actor, $versionToken, $key): array {
            $partner = Customer::query()->whereKey($identity['partner_id'])->lockForUpdate()->firstOrFail();
            $offset = DebtOffset::query()->whereKey($identity['offset_id'])->lockForUpdate()->firstOrFail();
            $this->assertSamePartner($offset, $partner);
            $this->assertPartnerEligible($partner, $actor);
            $amount = DecimalMoney::from((string) $offset->amount);
            $payload = $this->commandPayload(self::OPERATION_APPLY, $offset->id, $partner->id, $amount, null, $versionToken);
            [$operation, $replay] = $this->operation(self::OPERATION_APPLY, $key, $payload, $partner->id, $offset->id, $actor);
            if ($replay) {
                return $this->replayResult($operation);
            }

            $this->assertVersion($offset, $versionToken);
            $this->stateMachine->assertCan((string) $offset->workflow_status, DebtOffsetStateMachine::APPLIED);
            if ((bool) config('debt.offsets.require_distinct_applier', false)
                && in_array((int) $actor->id, [(int) $offset->requested_by, (int) $offset->approved_by], true)) {
                throw DebtOffsetWorkflowException::forbidden(
                    'SELF_APPLY_FORBIDDEN',
                    'Người yêu cầu hoặc người duyệt không được tự áp dụng phiếu cấn trừ.'
                );
            }

            $approvalOperation = PartnerDebtOperation::query()
                ->whereKey($offset->approval_operation_id)
                ->lockForUpdate()
                ->first();
            if (! $approvalOperation || $approvalOperation->status !== 'committed') {
                throw DebtOffsetWorkflowException::conflict(
                    'INVALID_DEBT_OFFSET_TRANSITION',
                    'Phiếu chưa có bằng chứng phê duyệt hợp lệ.'
                );
            }

            [$receivableBefore, $payableBefore] = $this->assertAmountWithinCurrentBalances($offset, $partner);
            $receivableAfter = $receivableBefore->subtract($amount);
            $payableAfter = $payableBefore->subtract($amount);
            $partner->forceFill([
                'debt_amount' => $receivableAfter->toDecimal(),
                'supplier_debt_amount' => $payableAfter->toDecimal(),
            ])->save();
            $this->failureInjector->hit('AFTER_PARTNER_BALANCE_UPDATE');

            CashFlow::query()->create([
                'code' => $offset->code,
                'type' => 'receipt',
                'amount' => $amount->toDecimal(),
                'time' => now(),
                'category' => 'Đối trừ công nợ',
                'target_type' => 'Khách hàng',
                'target_id' => $partner->id,
                'target_name' => $partner->name,
                'branch_id' => $partner->branch_id,
                'reference_type' => 'DebtOffset',
                'reference_code' => $offset->code,
                'description' => 'Áp dụng cấn trừ công nợ '.$offset->code,
                'idempotency_key' => 'debt-offset:'.$operation->operation_uuid,
            ]);
            $this->failureInjector->hit('AFTER_CASH_FLOW_CREATE');

            SupplierDebtTransaction::query()->create([
                'supplier_id' => $partner->id,
                'code' => $offset->code,
                'type' => 'offset',
                'amount' => $amount->negate()->toDecimal(),
                'debt_remain' => $payableAfter->toDecimal(),
                'note' => 'Áp dụng cấn trừ công nợ '.$offset->code,
                'user_id' => $actor->id,
            ]);
            $this->failureInjector->hit('AFTER_SUPPLIER_TRANSACTION_CREATE');

            $offset->forceFill([
                'workflow_status' => DebtOffsetStateMachine::APPLIED,
                'status' => 'active',
                'applied_at' => now(),
                'apply_operation_id' => $operation->id,
                'receivable_before' => $receivableBefore->toDecimal(),
                'payable_before' => $payableBefore->toDecimal(),
                'receivable_after' => $receivableAfter->toDecimal(),
                'payable_after' => $payableAfter->toDecimal(),
                'customer_amount' => $amount->toDecimal(),
                'supplier_amount' => $amount->toDecimal(),
            ])->save();

            return $this->commitEvidence(
                $operation,
                $offset,
                $partner,
                $actor,
                'debt_offset.applied',
                ActivityLog::ACTION_DEBT_OFFSET_APPLY,
                'Áp dụng cấn trừ công nợ',
                $amount->negate(),
                $amount->negate(),
                DebtOffsetStateMachine::APPROVED,
                true,
            );
        }, 5);
    }

    public function reverse(DebtOffset $debtOffset, User $actor, string $reason, string $versionToken, string $idempotencyKey): array
    {
        $this->writeMode->assertWorkflowAllowed();
        $reason = $this->requiredReason($reason);
        $identity = $this->offsetIdentity($debtOffset);
        $key = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($identity, $actor, $reason, $versionToken, $key): array {
            $partner = Customer::query()->whereKey($identity['partner_id'])->lockForUpdate()->firstOrFail();
            $original = DebtOffset::query()->whereKey($identity['offset_id'])->lockForUpdate()->firstOrFail();
            $this->assertSamePartner($original, $partner);
            $this->assertPartnerEligible($partner, $actor);
            $amount = DecimalMoney::from((string) $original->amount);
            $payload = $this->commandPayload(self::OPERATION_REVERSE, $original->id, $partner->id, $amount, $reason, $versionToken);

            $applyOperation = null;
            if ($original->apply_operation_id !== null) {
                $applyOperation = PartnerDebtOperation::query()
                    ->whereKey($original->apply_operation_id)
                    ->lockForUpdate()
                    ->first();
            }

            DebtOffset::query()->where('reverses_debt_offset_id', $original->id)->lockForUpdate()->get();
            $existingOperation = PartnerDebtOperation::query()
                ->where('operation_type', self::OPERATION_REVERSE)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existingOperation) {
                [$operation, $replay] = $this->operation(
                    self::OPERATION_REVERSE,
                    $key,
                    $payload,
                    $partner->id,
                    $original->id,
                    $actor,
                    $applyOperation?->id,
                    ['legacy_source' => $original->isLegacy()],
                );

                if ($replay) {
                    return $this->replayResult($operation);
                }
            }

            $this->assertVersion($original, $versionToken);
            $this->assertReversible($original, $applyOperation);
            [$operation, $replay] = $this->operation(
                self::OPERATION_REVERSE,
                $key,
                $payload,
                $partner->id,
                $original->id,
                $actor,
                $applyOperation?->id,
                ['legacy_source' => $original->isLegacy()],
            );
            if ($replay) {
                return $this->replayResult($operation);
            }

            $fromStatus = $original->workflow_status;

            $receivableBefore = DecimalMoney::from((string) $partner->debt_amount);
            $payableBefore = DecimalMoney::from((string) $partner->supplier_debt_amount);
            $receivableAfter = $receivableBefore->add($amount);
            $payableAfter = $payableBefore->add($amount);
            $partner->forceFill([
                'debt_amount' => $receivableAfter->toDecimal(),
                'supplier_debt_amount' => $payableAfter->toDecimal(),
            ])->save();
            $this->failureInjector->hit('AFTER_PARTNER_BALANCE_UPDATE');

            $reversal = DebtOffset::query()->create([
                'code' => 'TMP-HCB-'.Str::uuid(),
                'customer_id' => $partner->id,
                'amount' => $amount->toDecimal(),
                'customer_amount' => $amount->toDecimal(),
                'supplier_amount' => $amount->toDecimal(),
                'receivable_before' => $receivableBefore->toDecimal(),
                'payable_before' => $payableBefore->toDecimal(),
                'receivable_after' => $receivableAfter->toDecimal(),
                'payable_after' => $payableAfter->toDecimal(),
                'is_auto' => false,
                'note' => 'Đảo phiếu '.$original->code.': '.$reason,
                'user_id' => $actor->id,
                'status' => 'active',
                'workflow_status' => DebtOffsetStateMachine::APPLIED,
                'applied_at' => now(),
                'apply_operation_id' => $operation->id,
                'reverses_debt_offset_id' => $original->id,
                'source_references' => [
                    'original_offset_id' => $original->id,
                    'original_offset_code' => $original->code,
                    'legacy_source' => $original->isLegacy(),
                ],
            ]);
            $reversal->forceFill(['code' => $this->voucherCode('HCB', $reversal->id)])->save();

            CashFlow::query()->create([
                'code' => $reversal->code,
                'type' => 'payment',
                'amount' => $amount->toDecimal(),
                'time' => now(),
                'category' => 'Hủy đối trừ công nợ',
                'target_type' => 'Khách hàng',
                'target_id' => $partner->id,
                'target_name' => $partner->name,
                'branch_id' => $partner->branch_id,
                'reference_type' => 'DebtOffsetReversal',
                'reference_code' => $reversal->code,
                'description' => 'Đảo phiếu cấn trừ '.$original->code,
                'idempotency_key' => 'debt-offset:'.$operation->operation_uuid,
            ]);
            $this->failureInjector->hit('AFTER_CASH_FLOW_CREATE');

            SupplierDebtTransaction::query()->create([
                'supplier_id' => $partner->id,
                'code' => $reversal->code,
                'type' => 'offset',
                'amount' => $amount->toDecimal(),
                'debt_remain' => $payableAfter->toDecimal(),
                'note' => 'Đảo phiếu cấn trừ '.$original->code,
                'user_id' => $actor->id,
            ]);
            $this->failureInjector->hit('AFTER_SUPPLIER_TRANSACTION_CREATE');

            $original->forceFill([
                'workflow_status' => DebtOffsetStateMachine::REVERSED,
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_operation_id' => $operation->id,
            ])->save();
            if ($applyOperation) {
                $applyOperation->forceFill(['status' => 'reversed'])->save();
            }

            $result = $this->commitEvidence(
                $operation,
                $original,
                $partner,
                $actor,
                'debt_offset.reversed',
                ActivityLog::ACTION_DEBT_OFFSET_REVERSE,
                'Đảo phiếu cấn trừ công nợ',
                $amount,
                $amount,
                $fromStatus,
                true,
                $reversal,
            );

            return $result;
        }, 5);
    }

    public function void(DebtOffset $debtOffset, User $actor, ?string $reason, string $versionToken, string $idempotencyKey): array
    {
        $reason = $this->trimNullable($reason) ?? 'Hủy bản nháp';

        return $this->transitionCommand(
            $debtOffset,
            $actor,
            self::OPERATION_VOID,
            $idempotencyKey,
            $versionToken,
            DecimalMoney::from((string) $debtOffset->amount),
            $reason,
            function (DebtOffset $lockedOffset): void {
                $this->stateMachine->assertCan((string) $lockedOffset->workflow_status, DebtOffsetStateMachine::VOID);
            },
            function (DebtOffset $lockedOffset) use ($actor, $reason): void {
                $lockedOffset->forceFill([
                    'workflow_status' => DebtOffsetStateMachine::VOID,
                    'status' => 'void',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancel_reason' => $reason,
                ])->save();
            },
            'debt_offset.voided',
            ActivityLog::ACTION_DEBT_OFFSET_VOID,
            'Hủy bản nháp cấn trừ công nợ',
        );
    }

    private function transitionCommand(
        DebtOffset $debtOffset,
        User $actor,
        string $operationType,
        string $idempotencyKey,
        string $versionToken,
        DecimalMoney $amount,
        ?string $reasonOrNote,
        callable $guard,
        callable $mutation,
        string $eventType,
        string $activityAction,
        string $activityDescription,
    ): array {
        $this->writeMode->assertWorkflowAllowed();
        $identity = $this->offsetIdentity($debtOffset);
        $key = $this->idempotencyKey($idempotencyKey);

        return DB::transaction(function () use (
            $identity,
            $actor,
            $operationType,
            $key,
            $versionToken,
            $amount,
            $reasonOrNote,
            $guard,
            $mutation,
            $eventType,
            $activityAction,
            $activityDescription,
        ): array {
            $partner = Customer::query()->whereKey($identity['partner_id'])->lockForUpdate()->firstOrFail();
            $offset = DebtOffset::query()->whereKey($identity['offset_id'])->lockForUpdate()->firstOrFail();
            $this->assertSamePartner($offset, $partner);
            $this->assertPartnerEligible($partner, $actor);
            $payload = $this->commandPayload(
                $operationType,
                $offset->id,
                $partner->id,
                $amount,
                $reasonOrNote,
                $versionToken,
            );
            [$operation, $replay] = $this->operation($operationType, $key, $payload, $partner->id, $offset->id, $actor);
            if ($replay) {
                return $this->replayResult($operation);
            }

            $this->assertVersion($offset, $versionToken);
            $fromStatus = $offset->workflow_status;
            $guard($offset, $partner, $operation);
            $mutation($offset, $partner, $operation);

            return $this->commitEvidence(
                $operation,
                $offset,
                $partner,
                $actor,
                $eventType,
                $activityAction,
                $activityDescription,
                DecimalMoney::fromCents(0),
                DecimalMoney::fromCents(0),
                $fromStatus,
            );
        }, 5);
    }

    private function commitEvidence(
        PartnerDebtOperation $operation,
        DebtOffset $offset,
        Customer $partner,
        User $actor,
        string $eventType,
        string $activityAction,
        string $activityDescription,
        DecimalMoney $customerDelta,
        DecimalMoney $supplierDelta,
        ?string $fromStatus,
        bool $injectFailures = false,
        ?DebtOffset $reversal = null,
    ): array {
        PartnerDebtOperationParticipant::query()->create([
            'operation_id' => $operation->id,
            'partner_id' => $partner->id,
            'participant_role' => 'primary',
            'effect_role' => $customerDelta->isZero() && $supplierDelta->isZero() ? 'none' : 'both',
            'customer_delta' => $customerDelta->toDecimal(),
            'supplier_delta' => $supplierDelta->toDecimal(),
        ]);
        if ($injectFailures) {
            $this->failureInjector->hit('AFTER_OPERATION_PARTICIPANT_CREATE');
            $this->failureInjector->hit('BEFORE_OUTBOX_CREATE');
        }

        $occurredAt = now();
        $eventUuid = (string) Str::uuid();
        PartnerDebtOutboxEvent::query()->create([
            'event_uuid' => $eventUuid,
            'operation_id' => $operation->id,
            'aggregate_type' => 'DebtOffset',
            'aggregate_id' => $offset->id,
            'event_type' => $eventType,
            'schema_version' => 1,
            'payload' => $this->outboxPayload($eventUuid, $operation, $offset, $partner, $actor, $occurredAt, $reversal),
            'status' => 'pending',
            'occurred_at' => $occurredAt,
            'next_attempt_at' => $occurredAt,
            'attempts' => 0,
        ]);

        ActivityLog::query()->create([
            'user_id' => $actor->id,
            'action' => $activityAction,
            'description' => $activityDescription.' '.$offset->code,
            'subject_type' => DebtOffset::class,
            'subject_id' => $offset->id,
            'properties' => [
                'debt_offset_id' => $offset->id,
                'code' => $offset->code,
                'partner_id' => $partner->id,
                'amount' => DecimalMoney::from((string) $offset->amount)->toDecimal(),
                'from_status' => $fromStatus,
                'to_status' => $offset->workflow_status,
                'operation_uuid' => $operation->operation_uuid,
                'idempotent_replay' => false,
            ],
        ]);

        $result = [
            'debt_offset' => $this->resource($offset->fresh()),
            'idempotent_replay' => false,
        ];
        if ($reversal) {
            $result['reversal_voucher'] = $this->resource($reversal->fresh());
        }

        if ($injectFailures) {
            $this->failureInjector->hit('BEFORE_OPERATION_COMMIT');
        }
        $operation->forceFill([
            'status' => 'committed',
            'committed_at' => now(),
            'result' => $result,
        ])->save();

        return $result;
    }

    private function operation(
        string $type,
        string $key,
        array $payload,
        int $partnerId,
        ?int $offsetId,
        User $actor,
        ?int $reversesOperationId = null,
        array $metadata = [],
    ): array {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $existing = PartnerDebtOperation::query()
            ->where('operation_type', $type)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            if (! hash_equals((string) $existing->request_hash, $hash)) {
                throw DebtOffsetWorkflowException::conflict(
                    'IDEMPOTENCY_KEY_REUSED',
                    'Idempotency-Key đã được dùng cho nội dung khác.'
                );
            }
            if (! in_array($existing->status, ['committed', 'reversed'], true) || ! is_array($existing->result)) {
                throw DebtOffsetWorkflowException::conflict(
                    'IDEMPOTENCY_KEY_REUSED',
                    'Lệnh cùng Idempotency-Key chưa có kết quả ổn định.'
                );
            }

            return [$existing, true];
        }

        return [PartnerDebtOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'partner_id' => $partnerId,
            'operation_type' => $type,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'request_hash_version' => 1,
            'status' => 'pending',
            'source_type' => $offsetId === null ? null : 'DebtOffset',
            'source_id' => $offsetId,
            'reverses_operation_id' => $reversesOperationId,
            'attempt_count' => 1,
            'initiated_by' => $actor->id,
            'initiated_at' => now(),
            'metadata' => $metadata ?: null,
        ]), false];
    }

    private function replayResult(PartnerDebtOperation $operation): array
    {
        $result = $operation->result;
        if ($operation->operation_type === self::OPERATION_APPLY && $operation->status === 'reversed') {
            $currentOffset = DebtOffset::query()->find($operation->source_id);
            if ($currentOffset) {
                $result['debt_offset'] = $this->resource($currentOffset);
            }
        }
        $result['idempotent_replay'] = true;

        return $result;
    }

    private function assertPartnerEligible(Customer $partner, User $actor): void
    {
        if (! $partner->is_customer || ! $partner->is_supplier) {
            throw DebtOffsetWorkflowException::invalid(
                'PARTNER_NOT_DUAL_ROLE',
                'Đối tác phải đồng thời là khách hàng và nhà cung cấp.'
            );
        }
        if ($partner->merged_into_id !== null || strtolower((string) $partner->status) === 'inactive') {
            throw DebtOffsetWorkflowException::invalid(
                'PARTNER_INACTIVE_OR_MERGED',
                'Đối tác đã ngừng hoạt động hoặc đã được gộp.'
            );
        }
        if (Setting::get('customer_manage_by_branch', false)
            && ! $actor->isAdmin()
            && $partner->branch_id !== null
            && ! in_array((int) $partner->branch_id, array_map('intval', $actor->getAccessibleBranchIds()), true)) {
            throw DebtOffsetWorkflowException::forbidden(
                'BRANCH_SCOPE_FORBIDDEN',
                'Bạn không được thao tác đối tác ngoài phạm vi chi nhánh.'
            );
        }
    }

    private function assertAmountWithinCurrentBalances(DebtOffset $offset, Customer $partner): array
    {
        $receivable = DecimalMoney::from((string) $partner->debt_amount);
        $payable = DecimalMoney::from((string) $partner->supplier_debt_amount);
        $amount = DecimalMoney::from((string) $offset->amount);
        if (! $receivable->isPositive() || ! $payable->isPositive()
            || $amount->greaterThan(DecimalMoney::min($receivable, $payable))) {
            throw DebtOffsetWorkflowException::conflict(
                'OFFSET_AMOUNT_EXCEEDS_CURRENT_BALANCE',
                'Số tiền cấn trừ vượt quá công nợ hiện tại.'
            );
        }

        return [$receivable, $payable];
    }

    private function assertReversible(
        DebtOffset $original,
        ?PartnerDebtOperation $applyOperation,
    ): void {
        if ($original->reverses_debt_offset_id !== null) {
            throw DebtOffsetWorkflowException::conflict(
                'REVERSAL_OF_REVERSAL_FORBIDDEN',
                'Không được đảo một phiếu đảo.'
            );
        }
        if (DebtOffset::query()->where('reverses_debt_offset_id', $original->id)->exists()
            || $original->reversal_operation_id !== null
            || $original->workflow_status === DebtOffsetStateMachine::REVERSED
            || $original->status === 'cancelled') {
            throw DebtOffsetWorkflowException::conflict(
                'OFFSET_ALREADY_REVERSED',
                'Phiếu cấn trừ đã được đảo trước đó.'
            );
        }
        if (! $original->isLegacy()) {
            $this->stateMachine->assertCan((string) $original->workflow_status, DebtOffsetStateMachine::REVERSED);
        } elseif ($original->status !== 'active') {
            throw DebtOffsetWorkflowException::conflict(
                'INVALID_DEBT_OFFSET_TRANSITION',
                'Phiếu cấn trừ cũ không còn hiệu lực để đảo.'
            );
        }

        if ($applyOperation) {
            if ($applyOperation->status !== 'committed') {
                throw DebtOffsetWorkflowException::conflict(
                    'INVALID_DEBT_OFFSET_TRANSITION',
                    'Operation áp dụng không ở trạng thái committed.'
                );
            }
            if ($applyOperation->reverses_operation_id !== null) {
                throw DebtOffsetWorkflowException::conflict(
                    'REVERSAL_OF_REVERSAL_FORBIDDEN',
                    'Operation nguồn là một operation đảo.'
                );
            }
            $this->assertOperationChainAcyclic($applyOperation);
            if (PartnerDebtOperation::query()
                ->where('reverses_operation_id', $applyOperation->id)
                ->exists()) {
                throw DebtOffsetWorkflowException::conflict(
                    'OFFSET_ALREADY_REVERSED',
                    'Operation áp dụng đã có operation đảo.'
                );
            }
        }
    }

    private function assertOperationChainAcyclic(PartnerDebtOperation $operation): void
    {
        $seen = [];
        $current = $operation;
        while ($current) {
            if (isset($seen[$current->id])) {
                throw DebtOffsetWorkflowException::conflict(
                    'REVERSAL_CYCLE_FORBIDDEN',
                    'Chuỗi operation đảo tạo thành vòng lặp.'
                );
            }
            $seen[$current->id] = true;
            if ($current->reverses_operation_id === null) {
                return;
            }
            if ((int) $current->reverses_operation_id === (int) $current->id) {
                throw DebtOffsetWorkflowException::conflict(
                    'SELF_REVERSAL_FORBIDDEN',
                    'Operation không được tự đảo chính nó.'
                );
            }
            $current = PartnerDebtOperation::query()->whereKey($current->reverses_operation_id)->lockForUpdate()->first();
        }
    }

    private function assertVersion(DebtOffset $offset, string $versionToken): void
    {
        if (! hash_equals($offset->versionToken(), trim($versionToken))) {
            throw DebtOffsetWorkflowException::conflict(
                'STALE_DEBT_OFFSET_VERSION',
                'Phiếu cấn trừ đã thay đổi. Vui lòng tải lại dữ liệu.'
            );
        }
    }

    private function assertCurrentState(DebtOffset $offset, string $state): void
    {
        if ($offset->workflow_status !== $state) {
            throw DebtOffsetWorkflowException::conflict(
                'INVALID_DEBT_OFFSET_TRANSITION',
                'Trạng thái phiếu cấn trừ không cho phép thao tác này.'
            );
        }
    }

    private function assertSamePartner(DebtOffset $offset, Customer $partner): void
    {
        if ((int) $offset->customer_id !== (int) $partner->id) {
            throw DebtOffsetWorkflowException::conflict(
                'INVALID_DEBT_OFFSET_TRANSITION',
                'Đối tác của phiếu cấn trừ đã thay đổi.'
            );
        }
    }

    private function offsetIdentity(DebtOffset $offset): array
    {
        return ['offset_id' => (int) $offset->id, 'partner_id' => (int) $offset->customer_id];
    }

    private function idempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw DebtOffsetWorkflowException::invalid(
                'IDEMPOTENCY_KEY_REQUIRED',
                'Idempotency-Key là bắt buộc.'
            );
        }
        if (strlen($key) < 16 || strlen($key) > 191) {
            throw DebtOffsetWorkflowException::invalid(
                'IDEMPOTENCY_KEY_INVALID',
                'Idempotency-Key phải có từ 16 đến 191 ký tự.'
            );
        }

        return $key;
    }

    private function positiveMoney(int|string $amount): DecimalMoney
    {
        try {
            $money = DecimalMoney::from($amount);
        } catch (\InvalidArgumentException) {
            throw DebtOffsetWorkflowException::invalid('INVALID_DEBT_OFFSET_AMOUNT', 'Số tiền cấn trừ không hợp lệ.');
        }
        if (! $money->isPositive()) {
            throw DebtOffsetWorkflowException::invalid('INVALID_DEBT_OFFSET_AMOUNT', 'Số tiền cấn trừ phải lớn hơn 0.');
        }

        return $money;
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw DebtOffsetWorkflowException::invalid('DEBT_OFFSET_REASON_REQUIRED', 'Lý do là bắt buộc.');
        }

        return $reason;
    }

    private function trimNullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function commandPayload(
        string $operationType,
        ?int $offsetId,
        int $partnerId,
        DecimalMoney $amount,
        ?string $reasonOrNote,
        ?string $versionToken,
    ): array {
        return [
            'operation_type' => $operationType,
            'debt_offset_id' => $offsetId,
            'partner_id' => $partnerId,
            'amount' => $amount->toDecimal(),
            'reason_or_note' => $reasonOrNote,
            'version_token' => $versionToken,
        ];
    }

    private function voucherCode(string $prefix, int $id): string
    {
        return $prefix.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private function resource(DebtOffset $offset): array
    {
        return [
            'id' => $offset->id,
            'code' => $offset->code,
            'partner_id' => $offset->customer_id,
            'amount' => DecimalMoney::from((string) $offset->amount)->toDecimal(),
            'customer_amount' => $offset->customer_amount,
            'supplier_amount' => $offset->supplier_amount,
            'receivable_before' => (string) $offset->receivable_before,
            'payable_before' => (string) $offset->payable_before,
            'receivable_after' => (string) $offset->receivable_after,
            'payable_after' => (string) $offset->payable_after,
            'workflow_status' => $offset->workflow_status,
            'status' => $offset->status,
            'note' => $offset->note,
            'requested_by' => $offset->requested_by,
            'requested_at' => $offset->requested_at?->toISOString(),
            'approved_by' => $offset->approved_by,
            'approved_at' => $offset->approved_at?->toISOString(),
            'rejected_by' => $offset->rejected_by,
            'rejected_at' => $offset->rejected_at?->toISOString(),
            'rejection_reason' => $offset->rejection_reason,
            'applied_at' => $offset->applied_at?->toISOString(),
            'cancel_reason' => $offset->cancel_reason,
            'reverses_debt_offset_id' => $offset->reverses_debt_offset_id,
            'is_legacy' => $offset->isLegacy(),
            'version_token' => $offset->versionToken(),
        ];
    }

    private function outboxPayload(
        string $eventId,
        PartnerDebtOperation $operation,
        DebtOffset $offset,
        Customer $partner,
        User $actor,
        $occurredAt,
        ?DebtOffset $reversal,
    ): array {
        $balanceSource = $reversal ?? $offset;

        return [
            'schema_version' => 1,
            'event_id' => $eventId,
            'operation_uuid' => $operation->operation_uuid,
            'debt_offset_id' => $offset->id,
            'debt_offset_code' => $offset->code,
            'partner_id' => $partner->id,
            'workflow_status' => $offset->workflow_status,
            'amount' => DecimalMoney::from((string) $offset->amount)->toDecimal(),
            'customer_balance_before' => (string) $balanceSource->receivable_before,
            'customer_balance_after' => (string) $balanceSource->receivable_after,
            'supplier_balance_before' => (string) $balanceSource->payable_before,
            'supplier_balance_after' => (string) $balanceSource->payable_after,
            'actor_id' => $actor->id,
            'occurred_at' => $occurredAt->toISOString(),
            'original_offset_id' => $reversal?->reverses_debt_offset_id,
        ];
    }
}
