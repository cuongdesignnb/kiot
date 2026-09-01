<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Applies only a separately approved, evidence-backed batch. Every row is
 * re-audited under locks before one all-or-nothing transaction is committed.
 */
final class SerialCostRemediationApplyService
{
    public function __construct(
        private readonly SerialCostRemediationPlanService $plans,
        private readonly SerialCostRemediationApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public function preview(array $plan, array $approval): array
    {
        $selection = $this->approvals->validatedSelection($plan, $approval);

        return [
            'mode' => 'dry-run',
            'plan_hash' => $selection['plan_hash'],
            'approval_hash' => $selection['approval_hash'],
            'approved_by' => $approval['approved_by'],
            'approval_reference' => $approval['approval_reference'],
            'lines_selected' => $selection['lines']->count(),
            'invoice_codes' => $selection['lines']->pluck('invoice_code')->unique()->values()->all(),
            'invoice_items_to_update' => $selection['lines']->count(),
            'invoice_item_serials_to_update' => $selection['lines']->sum(
                fn (array $line): int => count((array) data_get($line, 'expected.serials', [])),
            ),
            'serial_sold_snapshots_to_update' => $selection['lines']->sum(
                fn (array $line): int => count((array) data_get($line, 'expected.serials', [])),
            ),
            'stock_movements_to_update' => $selection['lines']->filter(
                fn (array $line): bool => data_get($line, 'expected.stock_movement.id') !== null,
            )->count(),
            'database_mutation' => 'NO',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public function apply(array $plan, array $approval, string $operator, string $backupReference): array
    {
        $operator = trim($operator);
        if ($operator === '') {
            throw new RuntimeException('Apply requires an operator identity.');
        }
        $backupReference = trim($backupReference);
        if ($backupReference === '') {
            throw new RuntimeException('Apply requires a backup reference.');
        }
        if (! Schema::hasTable('activity_logs')) {
            throw new RuntimeException('The activity log schema is required before applying historical COGS remediation.');
        }

        $selection = $this->approvals->validatedSelection($plan, $approval);

        return DB::transaction(function () use ($selection, $approval, $operator, $backupReference): array {
            $this->lockDependencies($selection['lines']);

            $needsApply = collect();
            $replayed = collect();
            foreach ($selection['lines'] as $line) {
                $currentLine = $this->plans->findCurrentLine(
                    (string) $line['invoice_code'],
                    (int) $line['invoice_item_id'],
                );
                if (! is_array($currentLine)) {
                    throw new RuntimeException('Current audit evidence no longer contains '.$line['line_key'].'.');
                }

                if (($currentLine['proposed_action'] ?? null) === SerialCostRemediationPlanService::ACTION_REPAIR
                    && hash_equals((string) $line['precondition_hash'], (string) $currentLine['precondition_hash'])) {
                    $needsApply->push($line);

                    continue;
                }

                if ($this->alreadyAtExpectedSnapshot($line, $currentLine)) {
                    $replayed->push($line);

                    continue;
                }

                throw new RuntimeException('Precondition changed for '.$line['line_key'].'. Regenerate plan and approval; no rows were applied.');
            }

            if ($needsApply->isEmpty()) {
                return [
                    'result' => 'REPLAY',
                    'plan_hash' => $selection['plan_hash'],
                    'approval_hash' => $selection['approval_hash'],
                    'operator' => $operator,
                    'backup_reference' => $backupReference,
                    'lines_changed' => 0,
                    'replayed_lines' => $replayed->pluck('line_key')->values()->all(),
                ];
            }
            if ($replayed->isNotEmpty()) {
                throw new RuntimeException('The approval batch is partly already applied. Split or regenerate it; no rows were applied.');
            }

            $changes = $needsApply
                ->sortBy('line_key')
                ->map(fn (array $line): array => $this->applyLine($line))
                ->values();

            foreach ($changes as $change) {
                ActivityLog::log(
                    ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY,
                    'Applied approved evidence-backed serial COGS remediation for '.$change['invoice_code'].'.',
                    Invoice::query()->find($change['invoice_id']),
                    [
                        'plan_hash' => $selection['plan_hash'],
                        'approval_hash' => $selection['approval_hash'],
                        'approved_by' => $approval['approved_by'],
                        'approval_reference' => $approval['approval_reference'],
                        'operator' => $operator,
                        'backup_confirmed' => true,
                        'backup_reference' => $backupReference,
                        'line_key' => $change['line_key'],
                        'before' => $change['before'],
                        'after' => $change['after'],
                        'evidence' => $change['evidence'],
                    ],
                );
            }

            return [
                'result' => 'APPLIED',
                'plan_hash' => $selection['plan_hash'],
                'approval_hash' => $selection['approval_hash'],
                'operator' => $operator,
                'backup_reference' => $backupReference,
                'lines_changed' => $changes->count(),
                'invoice_items_updated' => $changes->count(),
                'invoice_item_serials_updated' => $changes->sum('serial_count'),
                'serial_sold_snapshots_updated' => $changes->sum('serial_count'),
                'stock_movements_updated' => $changes->count(),
                'changes' => $changes->all(),
            ];
        }, 3);
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function lockDependencies(Collection $lines): void
    {
        $invoiceIds = $lines->pluck('invoice_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $invoiceItemIds = $lines->pluck('invoice_item_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $serials = $lines->flatMap(fn (array $line): array => (array) data_get($line, 'expected.serials', []));
        $serialIds = $serials->pluck('serial_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $linkIds = $serials->pluck('invoice_item_serial_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $taskIds = $lines->flatMap(fn (array $line): array => (array) data_get($line, 'evidence.repair_tasks', []))
            ->pluck('repair_task_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $movementIds = $lines->pluck('expected.stock_movement.id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
        InvoiceItem::query()->whereIn('id', $invoiceItemIds)->orderBy('id')->lockForUpdate()->get();
        InvoiceItemSerial::query()->whereIn('id', $linkIds)->orderBy('id')->lockForUpdate()->get();
        SerialImei::query()->whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();
        StockMovement::query()->whereIn('id', $movementIds)->orderBy('id')->lockForUpdate()->get();
        Task::query()->whereIn('id', $taskIds)->orderBy('id')->lockForUpdate()->get();

        if (Schema::hasTable('returns')) {
            DB::table('returns')
                ->whereIn('invoice_id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
        if (Schema::hasTable('return_items')) {
            DB::table('return_items')
                ->whereIn('invoice_item_id', $invoiceItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    /**
     * @param  array<string, mixed>  $planned
     * @param  array<string, mixed>  $current
     */
    private function alreadyAtExpectedSnapshot(array $planned, array $current): bool
    {
        $blockingFlags = array_values(array_diff(
            (array) ($current['blocking_flags'] ?? []),
            [SerialCostRemediationPlanService::BLOCK_NO_FINANCIAL_IMPACT],
        ));
        if ($blockingFlags !== []) {
            return false;
        }
        if (! $this->sameMoney(data_get($planned, 'expected.invoice_item_cost'), data_get($current, 'before.invoice_item_cost'))
            || ! $this->sameMoney(data_get($planned, 'expected.stock_movement.unit_cost'), data_get($current, 'before.stock_movement.unit_cost'))
            || ! $this->sameMoney(data_get($planned, 'expected.stock_movement.total_cost'), data_get($current, 'before.stock_movement.total_cost'))
            || ! $this->sameMoney(data_get($planned, 'expected.invoice_item_cost'), data_get($current, 'expected.invoice_item_cost'))
            || ! $this->sameMoney(data_get($planned, 'expected.stock_movement.unit_cost'), data_get($current, 'expected.stock_movement.unit_cost'))
            || ! $this->sameMoney(data_get($planned, 'expected.stock_movement.total_cost'), data_get($current, 'expected.stock_movement.total_cost'))) {
            return false;
        }

        $expectedByLink = collect((array) data_get($planned, 'expected.serials', []))
            ->keyBy('invoice_item_serial_id');
        $currentExpectedByLink = collect((array) data_get($current, 'expected.serials', []))
            ->keyBy('invoice_item_serial_id');
        $currentBeforeByLink = collect((array) data_get($current, 'before.serials', []))
            ->keyBy('invoice_item_serial_id');
        if ($expectedByLink->keys()->sort()->values()->all() !== $currentExpectedByLink->keys()->sort()->values()->all()
            || $expectedByLink->keys()->sort()->values()->all() !== $currentBeforeByLink->keys()->sort()->values()->all()) {
            return false;
        }

        foreach ($expectedByLink as $linkId => $expected) {
            $currentExpected = $currentExpectedByLink->get($linkId);
            $currentBefore = $currentBeforeByLink->get($linkId);
            if (! is_array($currentExpected)
                || ! is_array($currentBefore)
                || (int) $expected['serial_id'] !== (int) $currentExpected['serial_id']
                || (int) $expected['serial_id'] !== (int) $currentBefore['serial_id']
                || ! $this->sameMoney($expected['expected_cost'], $currentExpected['expected_cost'])
                || ! $this->sameMoney($expected['expected_cost'], $currentBefore['before_link_cost'])
                || ! $this->sameMoney($expected['expected_cost'], $currentBefore['before_sold_cost'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $line */
    private function applyLine(array $line): array
    {
        $expectedItemCost = (int) data_get($line, 'expected.invoice_item_cost');
        $expectedMovement = (array) data_get($line, 'expected.stock_movement');
        $expectedSerials = collect((array) data_get($line, 'expected.serials', []));

        DB::table('invoice_items')
            ->where('id', (int) $line['invoice_item_id'])
            ->update(['cost_price' => $expectedItemCost]);
        foreach ($expectedSerials as $serial) {
            DB::table('invoice_item_serials')
                ->where('id', (int) $serial['invoice_item_serial_id'])
                ->update(['cost_price' => (int) $serial['expected_cost']]);
            DB::table('serial_imeis')
                ->where('id', (int) $serial['serial_id'])
                ->update(['sold_cost_price' => (int) $serial['expected_cost']]);
        }
        DB::table('stock_movements')
            ->where('id', (int) $expectedMovement['id'])
            ->update([
                'unit_cost' => (int) $expectedMovement['unit_cost'],
                'total_cost' => (int) $expectedMovement['total_cost'],
                'updated_at' => now(),
            ]);

        return [
            'line_key' => (string) $line['line_key'],
            'invoice_id' => (int) $line['invoice_id'],
            'invoice_code' => (string) $line['invoice_code'],
            'serial_count' => $expectedSerials->count(),
            'before' => $line['before'],
            'after' => $line['expected'],
            'evidence' => $line['evidence'],
        ];
    }

    private function sameMoney(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) round((float) $left, 0) === (int) round((float) $right, 0);
    }
}
