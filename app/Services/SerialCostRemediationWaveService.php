<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Coordinates several independently guarded 25-line remediation batches under
 * one reviewed wave artifact and one backup reference. Each batch keeps its
 * own database transaction, approval hash and replay guard.
 */
final class SerialCostRemediationWaveService
{
    public const CONTRACT_VERSION = 'serial-cost-remediation-wave-v1';

    public const MAX_WAVE_LINES = 50;

    public const SELECTION_STRATEGY = 'lowest-absolute-cogs-delta-whole-invoice-v1';

    public function __construct(
        private readonly SerialCostRemediationPlanService $plans,
        private readonly SerialCostRemediationApprovalService $approvals,
        private readonly SerialCostRemediationApplyService $apply,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function prepare(
        array $plan,
        int $limit,
        string $approvedBy,
        string $approvalReference,
    ): array {
        if ($limit < 1 || $limit > self::MAX_WAVE_LINES) {
            throw new RuntimeException('Wave limit must be between 1 and '.self::MAX_WAVE_LINES.' invoice lines.');
        }

        $approvedBy = trim($approvedBy);
        $approvalReference = trim($approvalReference);
        if ($approvedBy === '' || $approvalReference === '') {
            throw new RuntimeException('Wave preparation requires approved-by and approval-reference.');
        }

        $eligible = collect($this->plans->validatedRepairLines($plan));
        $packed = $this->packWholeInvoices($eligible, $limit);
        if ($packed['batches']->isEmpty()) {
            throw new RuntimeException('No eligible remediation lines could be packed into this wave.');
        }

        $batches = $packed['batches']
            ->values()
            ->map(function (Collection $lines, int $index) use ($plan, $approvedBy, $approvalReference): array {
                $batchNumber = $index + 1;
                $lineKeys = $lines->pluck('line_key')->sort()->values()->all();
                $approval = $this->approvals->createForLineKeys(
                    $plan,
                    $lineKeys,
                    $approvedBy,
                    sprintf('%s-%02d', $approvalReference, $batchNumber),
                );
                $preview = $this->apply->preview($plan, $approval);

                return [
                    'batch_number' => $batchNumber,
                    'line_keys' => $lineKeys,
                    'invoice_codes' => $lines->pluck('invoice_code')->unique()->sort()->values()->all(),
                    'approval' => $approval,
                    'preview' => $preview,
                    ...$this->movementSummary($lines),
                ];
            });

        $selected = $packed['batches']->flatten(1)->sortBy('line_key')->values();
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'plan_hash' => (string) $plan['plan_hash'],
            'database_fingerprint' => (string) $plan['database_fingerprint'],
            'selection_strategy' => self::SELECTION_STRATEGY,
            'requested_limit' => $limit,
            'selected_lines' => $selected->count(),
            'selected_invoices' => $selected->pluck('invoice_code')->unique()->count(),
            'selected_serials' => $selected->sum(
                fn (array $line): int => count((array) data_get($line, 'expected.serials', [])),
            ),
            'approved_by' => $approvedBy,
            'approval_reference' => $approvalReference,
            'batches' => $batches->all(),
            'oversized_invoice_groups_skipped' => $packed['oversized']->all(),
            ...$this->movementSummary($selected),
        ];
        $payload['wave_hash'] = $this->waveHash($payload);
        $payload['confirmation_code'] = $this->confirmationCode($payload['wave_hash']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $wave
     * @return array<string, mixed>
     */
    public function preview(array $plan, array $wave): array
    {
        $validated = $this->validatedWave($plan, $wave);

        return [
            'mode' => 'dry-run',
            'wave_hash' => $validated['wave_hash'],
            'plan_hash' => (string) $plan['plan_hash'],
            'confirmation_code' => $this->confirmationCode($validated['wave_hash']),
            'batches' => $validated['batches']->map(fn (array $batch): array => [
                'batch_number' => $batch['batch_number'],
                'approval_hash' => $batch['approval']['approval_hash'],
                'lines_selected' => $batch['lines']->count(),
                'invoice_items_to_update' => $batch['preview']['invoice_items_to_update'],
                'invoice_item_serials_to_update' => $batch['preview']['invoice_item_serials_to_update'],
                'serial_sold_snapshots_to_update' => $batch['preview']['serial_sold_snapshots_to_update'],
                'stock_movements_to_update' => $batch['preview']['stock_movements_to_update'],
                'expected_movement_cogs_delta' => $batch['expected_movement_cogs_delta'],
            ])->all(),
            'selected_lines' => $validated['lines']->count(),
            'selected_invoices' => $validated['lines']->pluck('invoice_code')->unique()->count(),
            'selected_serials' => $validated['lines']->sum(
                fn (array $line): int => count((array) data_get($line, 'expected.serials', [])),
            ),
            ...$this->movementSummary($validated['lines']),
            'database_mutation' => 'NO',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $wave
     * @return array<string, mixed>
     */
    public function applyWave(
        array $plan,
        array $wave,
        string $operator,
        string $backupReference,
    ): array {
        $operator = trim($operator);
        $backupReference = trim($backupReference);
        if ($operator === '') {
            throw new RuntimeException('Wave apply requires an operator identity.');
        }
        if ($backupReference === '') {
            throw new RuntimeException('Wave apply requires a backup reference.');
        }

        $validated = $this->validatedWave($plan, $wave);
        $results = collect();

        foreach ($validated['batches'] as $batch) {
            try {
                $result = $this->apply->apply($plan, $batch['approval'], $operator, $backupReference);
                $results->push($this->batchResult($batch, $result));
            } catch (Throwable $exception) {
                return $this->failedResult(
                    $validated,
                    $results,
                    $batch,
                    $operator,
                    $backupReference,
                    $exception,
                );
            }
        }

        $changed = $results->sum('lines_changed');
        $replayed = $results->sum('replayed_lines');
        $result = $changed === 0
            ? 'REPLAY'
            : ($replayed > 0 ? 'RESUMED' : 'APPLIED');

        return [
            'result' => $result,
            'wave_hash' => $validated['wave_hash'],
            'plan_hash' => (string) $plan['plan_hash'],
            'operator' => $operator,
            'backup_reference' => $backupReference,
            'batches_total' => $validated['batches']->count(),
            'batches_completed' => $results->count(),
            'lines_selected' => $validated['lines']->count(),
            'lines_changed' => $changed,
            'replayed_lines' => $replayed,
            'invoice_item_serials_updated' => $results->sum('invoice_item_serials_updated'),
            'serial_sold_snapshots_updated' => $results->sum('serial_sold_snapshots_updated'),
            'stock_movements_updated' => $results->sum('stock_movements_updated'),
            'movement_cogs_delta_applied_this_run' => $results->sum('movement_cogs_delta'),
            'expected_movement_cogs_delta' => $validated['expected_movement_cogs_delta'],
            'batches' => $results->all(),
            'database_mutation' => $changed > 0 ? 'YES' : 'NO',
        ];
    }

    public function confirmationCode(string $waveHash): string
    {
        return 'APPLY-SERIAL-COGS-WAVE-'.substr($waveHash, 0, 16);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $wave
     * @return array{wave_hash:string,batches:Collection<int, array<string, mixed>>,lines:Collection<int, array<string, mixed>>,expected_movement_cogs_delta:int}
     */
    private function validatedWave(array $plan, array $wave): array
    {
        if (($wave['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Unsupported serial COGS remediation wave contract.');
        }
        $waveHash = (string) ($wave['wave_hash'] ?? '');
        if ($waveHash === '' || ! hash_equals($waveHash, $this->waveHash($wave))) {
            throw new RuntimeException('Wave hash mismatch. Generate a new wave artifact.');
        }
        if (! hash_equals((string) ($wave['confirmation_code'] ?? ''), $this->confirmationCode($waveHash))) {
            throw new RuntimeException('Wave confirmation code does not match its hash.');
        }
        if (! hash_equals((string) ($wave['plan_hash'] ?? ''), (string) ($plan['plan_hash'] ?? ''))) {
            throw new RuntimeException('Wave does not belong to this remediation plan.');
        }
        if (! hash_equals(
            (string) ($wave['database_fingerprint'] ?? ''),
            (string) ($plan['database_fingerprint'] ?? ''),
        )) {
            throw new RuntimeException('Wave database fingerprint does not match the remediation plan.');
        }
        if (($wave['selection_strategy'] ?? null) !== self::SELECTION_STRATEGY) {
            throw new RuntimeException('Unsupported remediation wave selection strategy.');
        }

        $requestedLimit = (int) ($wave['requested_limit'] ?? 0);
        if ($requestedLimit < 1 || $requestedLimit > self::MAX_WAVE_LINES) {
            throw new RuntimeException('Wave requested limit is outside the guarded range.');
        }

        $approvedBy = trim((string) ($wave['approved_by'] ?? ''));
        $approvalReference = trim((string) ($wave['approval_reference'] ?? ''));
        if ($approvedBy === '' || $approvalReference === '') {
            throw new RuntimeException('Wave is missing approver identity or approval reference.');
        }

        $batchPayloads = collect((array) ($wave['batches'] ?? []))->values();
        $maxBatches = (int) ceil($requestedLimit / SerialCostRemediationApprovalService::MAX_BATCH_LINES);
        if ($batchPayloads->isEmpty() || $batchPayloads->count() > $maxBatches) {
            throw new RuntimeException('Wave has an invalid number of guarded batches.');
        }

        $seenLineKeys = collect();
        $seenInvoiceCodes = collect();
        $validatedBatches = $batchPayloads->map(function (array $batch, int $index) use (
            $plan,
            $seenLineKeys,
            $seenInvoiceCodes,
            $approvedBy,
            $approvalReference,
        ): array {
            $batchNumber = $index + 1;
            if ((int) ($batch['batch_number'] ?? 0) !== $batchNumber) {
                throw new RuntimeException('Wave batch numbering is not contiguous.');
            }

            $approval = (array) ($batch['approval'] ?? []);
            if (! hash_equals($approvedBy, (string) ($approval['approved_by'] ?? ''))
                || ! hash_equals(
                    sprintf('%s-%02d', $approvalReference, $batchNumber),
                    (string) ($approval['approval_reference'] ?? ''),
                )) {
                throw new RuntimeException('Wave batch approval identity or reference is inconsistent.');
            }
            $selection = $this->approvals->validatedSelection($plan, $approval);
            $lines = $selection['lines'];
            $lineKeys = $lines->pluck('line_key')->sort()->values();
            $declaredLineKeys = collect((array) ($batch['line_keys'] ?? []))->map('strval')->sort()->values();
            if ($lineKeys->all() !== $declaredLineKeys->all()) {
                throw new RuntimeException('Wave batch line keys do not match its approval.');
            }
            if ($lineKeys->intersect($seenLineKeys)->isNotEmpty()) {
                throw new RuntimeException('A remediation line appears in more than one wave batch.');
            }
            $seenLineKeys->push(...$lineKeys->all());

            $invoiceCodes = $lines->pluck('invoice_code')->unique()->sort()->values()->all();
            $declaredInvoiceCodes = collect((array) ($batch['invoice_codes'] ?? []))->map('strval')->sort()->values()->all();
            if ($invoiceCodes !== $declaredInvoiceCodes) {
                throw new RuntimeException('Wave batch invoice codes do not match its approved lines.');
            }
            if (collect($invoiceCodes)->intersect($seenInvoiceCodes)->isNotEmpty()) {
                throw new RuntimeException('An invoice appears in more than one wave batch.');
            }
            $seenInvoiceCodes->push(...$invoiceCodes);

            $preview = $this->apply->preview($plan, $approval);
            if ($this->plans->canonicalHash($preview) !== $this->plans->canonicalHash((array) ($batch['preview'] ?? []))) {
                throw new RuntimeException('Wave batch preview no longer matches its approval.');
            }

            $movement = $this->movementSummary($lines);
            foreach ($movement as $key => $value) {
                if ((int) ($batch[$key] ?? PHP_INT_MIN) !== $value) {
                    throw new RuntimeException('Wave batch movement summary mismatch.');
                }
            }

            return [
                'batch_number' => $batchNumber,
                'approval' => $approval,
                'preview' => $preview,
                'lines' => $lines,
                ...$movement,
            ];
        });

        $lines = $validatedBatches->flatMap(fn (array $batch): Collection => $batch['lines'])
            ->sortBy('line_key')
            ->values();
        if ($lines->count() > $requestedLimit || $lines->count() > self::MAX_WAVE_LINES) {
            throw new RuntimeException('Wave selects more lines than its guarded limit.');
        }

        $summary = [
            'selected_lines' => $lines->count(),
            'selected_invoices' => $lines->pluck('invoice_code')->unique()->count(),
            'selected_serials' => $lines->sum(
                fn (array $line): int => count((array) data_get($line, 'expected.serials', [])),
            ),
            ...$this->movementSummary($lines),
        ];
        foreach ($summary as $key => $value) {
            if ((int) ($wave[$key] ?? PHP_INT_MIN) !== $value) {
                throw new RuntimeException('Wave summary mismatch for '.$key.'.');
            }
        }

        return [
            'wave_hash' => $waveHash,
            'batches' => $validatedBatches,
            'lines' => $lines,
            'expected_movement_cogs_delta' => $summary['expected_movement_cogs_delta'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eligible
     * @return array{batches:Collection<int, Collection<int, array<string, mixed>>>,oversized:Collection<int, array<string, mixed>>}
     */
    private function packWholeInvoices(Collection $eligible, int $limit): array
    {
        $groups = $eligible
            ->groupBy('invoice_code')
            ->map(function (Collection $lines, string $invoiceCode): array {
                $lines = $lines->sortBy('line_key')->values();
                $summary = $this->movementSummary($lines);

                return [
                    'invoice_code' => $invoiceCode,
                    'lines' => $lines,
                    'line_count' => $lines->count(),
                    'absolute_delta' => $summary['expected_absolute_movement_cogs_delta'],
                    'max_absolute_delta' => $summary['max_absolute_line_delta'],
                ];
            })
            ->sortBy(fn (array $group): string => sprintf(
                '%020d|%020d|%s',
                $group['max_absolute_delta'],
                $group['absolute_delta'],
                $group['invoice_code'],
            ))
            ->values();

        $oversized = $groups
            ->filter(fn (array $group): bool => $group['line_count'] > SerialCostRemediationApprovalService::MAX_BATCH_LINES)
            ->map(fn (array $group): array => [
                'invoice_code' => $group['invoice_code'],
                'line_count' => $group['line_count'],
            ])
            ->values();

        $slots = collect(range(1, (int) ceil($limit / SerialCostRemediationApprovalService::MAX_BATCH_LINES)))
            ->map(fn (): Collection => collect());
        $selectedCount = 0;

        foreach ($groups as $group) {
            if ($selectedCount >= $limit) {
                break;
            }
            if ($group['line_count'] > SerialCostRemediationApprovalService::MAX_BATCH_LINES
                || $selectedCount + $group['line_count'] > $limit) {
                continue;
            }

            $slot = $slots->first(
                fn (Collection $lines): bool => $lines->count() + $group['line_count']
                    <= SerialCostRemediationApprovalService::MAX_BATCH_LINES,
            );
            if (! $slot instanceof Collection) {
                continue;
            }

            $slot->push(...$group['lines']->all());
            $selectedCount += $group['line_count'];
        }

        return [
            'batches' => $slots->filter()->values(),
            'oversized' => $oversized,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{expected_movement_cogs_delta:int,expected_absolute_movement_cogs_delta:int,max_absolute_line_delta:int}
     */
    private function movementSummary(Collection $lines): array
    {
        $deltas = $lines->map(fn (array $line): int => (int) data_get($line, 'expected.stock_movement.total_cost', 0)
            - (int) data_get($line, 'before.stock_movement.total_cost', 0));

        return [
            'expected_movement_cogs_delta' => $deltas->sum(),
            'expected_absolute_movement_cogs_delta' => $deltas->sum(fn (int $delta): int => abs($delta)),
            'max_absolute_line_delta' => $deltas->map(fn (int $delta): int => abs($delta))->max() ?? 0,
        ];
    }

    /** @param array<string, mixed> $wave */
    private function waveHash(array $wave): string
    {
        unset($wave['wave_hash'], $wave['confirmation_code']);

        return $this->plans->canonicalHash($wave);
    }

    /**
     * @param  array<string, mixed>  $batch
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function batchResult(array $batch, array $result): array
    {
        $changes = collect((array) ($result['changes'] ?? []));

        return [
            'batch_number' => $batch['batch_number'],
            'approval_hash' => $batch['approval']['approval_hash'],
            'result' => $result['result'],
            'lines_selected' => $batch['lines']->count(),
            'lines_changed' => (int) ($result['lines_changed'] ?? 0),
            'replayed_lines' => count((array) ($result['replayed_lines'] ?? [])),
            'invoice_item_serials_updated' => (int) ($result['invoice_item_serials_updated'] ?? 0),
            'serial_sold_snapshots_updated' => (int) ($result['serial_sold_snapshots_updated'] ?? 0),
            'stock_movements_updated' => (int) ($result['stock_movements_updated'] ?? 0),
            'movement_cogs_delta' => $changes->sum(fn (array $change): int => (int) data_get($change, 'after.stock_movement.total_cost', 0)
                - (int) data_get($change, 'before.stock_movement.total_cost', 0)),
            'expected_movement_cogs_delta' => $batch['expected_movement_cogs_delta'],
        ];
    }

    /**
     * @param  array{wave_hash:string,batches:Collection<int, array<string, mixed>>,lines:Collection<int, array<string, mixed>>,expected_movement_cogs_delta:int}  $validated
     * @param  Collection<int, array<string, mixed>>  $results
     * @param  array<string, mixed>  $failedBatch
     * @return array<string, mixed>
     */
    private function failedResult(
        array $validated,
        Collection $results,
        array $failedBatch,
        string $operator,
        string $backupReference,
        Throwable $exception,
    ): array {
        $changed = $results->sum('lines_changed');

        return [
            'result' => 'PARTIAL_FAILURE',
            'wave_hash' => $validated['wave_hash'],
            'operator' => $operator,
            'backup_reference' => $backupReference,
            'batches_total' => $validated['batches']->count(),
            'batches_completed' => $results->count(),
            'failed_batch' => $failedBatch['batch_number'],
            'failed_approval_hash' => $failedBatch['approval']['approval_hash'],
            'error' => $exception->getMessage(),
            'lines_selected' => $validated['lines']->count(),
            'lines_changed' => $changed,
            'replayed_lines' => $results->sum('replayed_lines'),
            'movement_cogs_delta_applied_this_run' => $results->sum('movement_cogs_delta'),
            'expected_movement_cogs_delta' => $validated['expected_movement_cogs_delta'],
            'batches' => $results->all(),
            'database_mutation' => $changed > 0 ? 'YES_PARTIAL' : 'NO',
        ];
    }
}
