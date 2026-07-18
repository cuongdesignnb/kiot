<?php

namespace App\Services\Debt;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PartnerDebtMutationCoordinator
{
    private ?Collection $activePartners = null;

    private ?PartnerDebtOperation $activeOperation = null;

    public function __construct(private readonly CanonicalPartnerDebtService $canonical) {}

    public function execute(
        int $partnerId,
        string $operationType,
        string $payloadHash,
        callable $mutation,
        ?string $idempotencyKey = null,
    ): mixed {
        return $this->executeForPartners(
            [$partnerId],
            $operationType,
            $payloadHash,
            fn (Collection $partners, ?PartnerDebtOperation $operation) => $mutation(
                $partners->get($partnerId),
                $operation,
            ),
            $idempotencyKey,
        );
    }

    /**
     * Lock every affected partner by ascending ID, mutate evidence and
     * projections atomically, then prove the canonical invariant before the
     * transaction may commit.
     */
    public function executeForPartners(
        array $partnerIds,
        string $operationType,
        string $payloadHash,
        callable $mutation,
        ?string $idempotencyKey = null,
    ): mixed {
        $partnerIds = collect($partnerIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($partnerIds === []) {
            throw new RuntimeException('At least one valid partner ID is required.');
        }

        // Debt services can compose other debt services inside one business
        // mutation (for example a payment writes its ledger projection). Keep
        // those writes in the outer coordinator instead of opening a second
        // operation and checking a half-finished event stream.
        if ($this->activePartners !== null) {
            $missingPartnerIds = array_diff($partnerIds, $this->activePartners->keys()->map(fn ($id): int => (int) $id)->all());
            if ($missingPartnerIds !== []) {
                throw new RuntimeException('A nested debt mutation cannot expand the outer partner lock set.');
            }
            if ($idempotencyKey !== null) {
                throw new RuntimeException('Nested debt mutations must inherit the outer idempotency operation.');
            }

            return $mutation($this->activePartners, $this->activeOperation);
        }

        if ($idempotencyKey !== null && (mb_strlen(trim($idempotencyKey)) < 16 || mb_strlen(trim($idempotencyKey)) > 191)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Idempotency-Key must contain 16 to 191 characters.',
            ]);
        }
        $idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);
        $hasOperationSchema = Schema::hasTable('partner_debt_operations');
        if ($idempotencyKey !== null && ! $hasOperationSchema) {
            throw new RuntimeException('Debt operation schema is required for an idempotent mutation.');
        }

        try {
            return DB::transaction(function () use (
                $partnerIds,
                $operationType,
                $payloadHash,
                $mutation,
                $idempotencyKey,
                $hasOperationSchema,
            ) {
                $partners = Customer::query()
                    ->whereIn('id', $partnerIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($partners->count() !== count($partnerIds)) {
                    throw new RuntimeException('One or more debt mutation partners were not found.');
                }

                $existing = $this->existingOperation($operationType, $idempotencyKey);
                if ($existing) {
                    if (! hash_equals((string) $existing->request_hash, $payloadHash)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'Idempotency-Key was already used with a different payload.',
                        ]);
                    }

                    return $this->replayResult($existing);
                }

                $operation = $hasOperationSchema
                    ? PartnerDebtOperation::query()->create([
                        'operation_uuid' => (string) Str::uuid(),
                        'partner_id' => $partnerIds[0],
                        'operation_type' => 'debt.mutation.'.mb_substr($operationType, 0, 48),
                        'idempotency_key' => $idempotencyKey ?? 'internal:'.Str::uuid(),
                        'request_hash' => $payloadHash,
                        'request_hash_version' => 1,
                        'status' => 'pending',
                        'attempt_count' => 1,
                        'initiated_by' => auth()->id(),
                        'initiated_at' => now(),
                    ])
                    : null;
                $before = $partners->map(fn (Customer $partner): array => $this->snapshot($partner));
                $this->activePartners = $partners;
                $this->activeOperation = $operation;
                try {
                    $result = $mutation($partners, $operation);
                } finally {
                    $this->activePartners = null;
                    $this->activeOperation = null;
                }
                $this->checkpoint('mutation');

                $after = collect();
                $canonicalVersions = [];
                foreach ($partnerIds as $partnerId) {
                    $fresh = Customer::query()->findOrFail($partnerId);
                    $canonical = $this->canonical->calculate($fresh);
                    if (abs((float) $canonical['customer_receivable'] - (float) $fresh->debt_amount) > 1.0
                        || abs((float) $canonical['supplier_payable'] - (float) $fresh->supplier_debt_amount) > 1.0) {
                        throw new RuntimeException(sprintf(
                            'Canonical debt invariant failed before commit for partner %d: customer canonical=%.2f stored=%.2f; supplier canonical=%.2f stored=%.2f',
                            $partnerId,
                            (float) $canonical['customer_receivable'],
                            (float) $fresh->debt_amount,
                            (float) $canonical['supplier_payable'],
                            (float) $fresh->supplier_debt_amount,
                        ));
                    }
                    $after->put($partnerId, $this->snapshot($fresh));
                    $canonicalVersions[$partnerId] = $canonical['source_version'];
                }
                $this->checkpoint('before_commit');

                if ($operation) {
                    $operation->update([
                        'status' => 'committed',
                        'result' => [
                            'return_value' => $this->serializableResult($result),
                            'before' => $before->all(),
                            'after' => $after->all(),
                        ],
                        'committed_at' => now(),
                        'metadata' => ['canonical_source_versions' => $canonicalVersions],
                    ]);
                    $this->recordParticipants($operation, $before, $after);
                    ActivityLog::log(
                        'partner_debt_mutation',
                        'Committed partner debt mutation '.$operation->operation_uuid,
                        $partners->get($partnerIds[0]),
                        [
                            'operation_uuid' => $operation->operation_uuid,
                            'operation_type' => $operation->operation_type,
                            'partner_ids' => $partnerIds,
                            'before' => $before->all(),
                            'after' => $after->all(),
                        ],
                    );
                }

                return $result;
            }, 3);
        } catch (Throwable $exception) {
            if ($idempotencyKey !== null
                && $exception instanceof QueryException
                && (string) $exception->getCode() === '23000') {
                $racedOperation = DB::transaction(
                    fn () => $this->existingOperation($operationType, $idempotencyKey),
                );
                if ($racedOperation) {
                    if (! hash_equals((string) $racedOperation->request_hash, $payloadHash)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'Idempotency-Key was already used with a different payload.',
                        ]);
                    }

                    return $this->replayResult($racedOperation);
                }
            }
            Log::critical('Partner debt mutation rolled back', [
                'partner_ids' => $partnerIds,
                'operation_type' => $operationType,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    public function checkpoint(string $stage): void
    {
        $configured = (string) config('debt.mutation.failure_after', env('DEBT_MUTATION_FAIL_AFTER', ''));
        if ($configured !== '' && hash_equals($configured, $stage)) {
            throw new RuntimeException('Injected debt mutation failure after '.$stage.'.');
        }
    }

    private function existingOperation(string $operationType, ?string $idempotencyKey): ?PartnerDebtOperation
    {
        if (! $idempotencyKey || ! Schema::hasTable('partner_debt_operations')) {
            return null;
        }

        return PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.'.mb_substr($operationType, 0, 48))
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    private function replayResult(PartnerDebtOperation $operation): mixed
    {
        return $this->restoreSerializableResult($operation->result['return_value'] ?? null);
    }

    private function serializableResult(mixed $result): mixed
    {
        if ($result instanceof \Illuminate\Database\Eloquent\Model) {
            return ['model' => $result::class, 'id' => $result->getKey()];
        }

        if (is_array($result)) {
            return array_map(fn (mixed $value): mixed => $this->serializableResult($value), $result);
        }

        return is_scalar($result) || $result === null ? $result : null;
    }

    private function restoreSerializableResult(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result;
        }
        if (isset($result['model'], $result['id']) && is_a((string) $result['model'], \Illuminate\Database\Eloquent\Model::class, true)) {
            $model = (string) $result['model'];

            return $model::query()->findOrFail($result['id']);
        }

        return array_map(fn (mixed $value): mixed => $this->restoreSerializableResult($value), $result);
    }

    private function snapshot(Customer $partner): array
    {
        return [
            'customer_receivable' => (float) ($partner->debt_amount ?? 0),
            'supplier_payable' => (float) ($partner->supplier_debt_amount ?? 0),
        ];
    }

    private function recordParticipants(
        PartnerDebtOperation $operation,
        Collection $before,
        Collection $after,
    ): void {
        if (! Schema::hasTable('partner_debt_operation_participants')) {
            return;
        }

        foreach ($after as $partnerId => $snapshot) {
            $previous = (array) $before->get($partnerId, []);
            PartnerDebtOperationParticipant::query()->create([
                'operation_id' => $operation->id,
                'partner_id' => $partnerId,
                'participant_role' => 'affected_partner',
                'effect_role' => 'both',
                'customer_delta' => (float) ($snapshot['customer_receivable'] ?? 0)
                    - (float) ($previous['customer_receivable'] ?? 0),
                'supplier_delta' => (float) ($snapshot['supplier_payable'] ?? 0)
                    - (float) ($previous['supplier_payable'] ?? 0),
            ]);
        }
    }
}
