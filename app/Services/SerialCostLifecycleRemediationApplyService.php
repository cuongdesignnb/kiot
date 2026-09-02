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

/** Applies a complete approved lifecycle correction in one transaction. */
final class SerialCostLifecycleRemediationApplyService
{
    public function __construct(
        private readonly SerialCostLifecycleRemediationPlanService $plans,
        private readonly SerialCostLifecycleRemediationApprovalService $approvals,
    ) {}

    /** @param array<string, mixed> $plan
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public function preview(array $plan, array $approval): array
    {
        $selection = $this->approvals->validatedSelection($plan, $approval);
        $counts = $this->counts($selection['lines']);

        return [
            'mode' => 'dry-run',
            'plan_hash' => $selection['plan_hash'],
            'approval_hash' => $selection['approval_hash'],
            'approved_by' => $approval['approved_by'],
            'approval_reference' => $approval['approval_reference'],
            ...$counts,
            'sale_cogs_delta' => (int) $selection['lines']->sum('sale_cogs_delta'),
            'return_cogs_delta' => (int) $selection['lines']->sum('return_cogs_delta'),
            'net_report_cogs_delta' => (int) $selection['lines']->sum('net_report_cogs_delta'),
            'database_mutation' => 'NO',
        ];
    }

    /** @param array<string, mixed> $plan
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public function apply(array $plan, array $approval, string $operator, string $backupReference): array
    {
        $operator = trim($operator);
        $backupReference = trim($backupReference);
        if ($operator === '') {
            throw new RuntimeException('Lifecycle apply requires an operator identity.');
        }
        if ($backupReference === '') {
            throw new RuntimeException('Lifecycle apply requires a restorable backup reference.');
        }
        if (! Schema::hasTable('activity_logs')) {
            throw new RuntimeException('Activity logs are required for lifecycle remediation.');
        }

        $selection = $this->approvals->validatedSelection($plan, $approval);

        return DB::transaction(function () use ($selection, $approval, $operator, $backupReference): array {
            $this->lockDependencies($selection['lines']);
            $currentPlan = $this->plans->build(null, true);
            $currentByKey = collect(array_merge(
                (array) $currentPlan['repair_lines'],
                (array) $currentPlan['verified_lines'],
                (array) $currentPlan['blocked_lines'],
            ))->keyBy('line_key');

            $allBefore = $selection['lines']->every(function (array $planned) use ($currentByKey): bool {
                $current = $currentByKey->get($planned['line_key']);

                return is_array($current)
                    && ($current['proposed_action'] ?? null) === SerialCostLifecycleRemediationPlanService::ACTION_REPAIR
                    && hash_equals((string) $planned['identity_hash'], (string) $current['identity_hash'])
                    && hash_equals((string) $planned['precondition_hash'], (string) $current['precondition_hash']);
            });
            $allExpected = $selection['lines']->every(function (array $planned) use ($currentByKey): bool {
                $current = $currentByKey->get($planned['line_key']);

                return is_array($current)
                    && (array) ($current['lifecycle_blocking_flags'] ?? []) === []
                    && hash_equals((string) $planned['identity_hash'], (string) $current['identity_hash'])
                    && hash_equals((string) $planned['expected_state_hash'], (string) $current['expected_state_hash'])
                    && hash_equals((string) $planned['expected_state_hash'], (string) $current['current_state_hash']);
            });

            if ($allExpected) {
                return [
                    'result' => 'REPLAY',
                    'plan_hash' => $selection['plan_hash'],
                    'approval_hash' => $selection['approval_hash'],
                    'operator' => $operator,
                    'backup_reference' => $backupReference,
                    'lines_changed' => 0,
                    'replayed_lines' => $selection['lines']->count(),
                ];
            }
            if (! $allBefore) {
                throw new RuntimeException('Lifecycle precondition changed or a partial apply was detected. No rows were written.');
            }

            $counts = $this->counts($selection['lines']);
            $this->applySaleDocuments($selection['lines']);
            $this->applySerialLinks($selection['lines']);
            $this->applyReturns($selection['lines']);
            $this->applyCurrentSerialSnapshots($selection['lines']);

            $postPlan = $this->plans->build(null, true);
            $postByKey = collect(array_merge(
                (array) $postPlan['repair_lines'],
                (array) $postPlan['verified_lines'],
                (array) $postPlan['blocked_lines'],
            ))->keyBy('line_key');
            $postMatches = $selection['lines']->every(function (array $planned) use ($postByKey): bool {
                $current = $postByKey->get($planned['line_key']);

                return is_array($current)
                    && (array) ($current['lifecycle_blocking_flags'] ?? []) === []
                    && hash_equals((string) $planned['identity_hash'], (string) $current['identity_hash'])
                    && hash_equals((string) $planned['expected_state_hash'], (string) $current['current_state_hash']);
            });
            if (! $postMatches) {
                throw new RuntimeException('Lifecycle post-apply invariant failed. The transaction was rolled back.');
            }

            foreach ($selection['lines'] as $line) {
                ActivityLog::log(
                    ActivityLog::ACTION_SERIAL_COST_LIFECYCLE_REMEDIATION_APPLY,
                    'Applied approved serial sale-return-resale COGS remediation for '.$line['invoice_code'].'.',
                    Invoice::query()->find((int) $line['invoice_id']),
                    [
                        'plan_hash' => $selection['plan_hash'],
                        'approval_hash' => $selection['approval_hash'],
                        'approved_by' => $approval['approved_by'],
                        'approval_reference' => $approval['approval_reference'],
                        'operator' => $operator,
                        'backup_confirmed' => true,
                        'backup_reference' => $backupReference,
                        'line_key' => $line['line_key'],
                        'changes' => $line['changes'],
                        'sale_cogs_delta' => $line['sale_cogs_delta'],
                        'return_cogs_delta' => $line['return_cogs_delta'],
                        'net_report_cogs_delta' => $line['net_report_cogs_delta'],
                    ],
                );
            }

            return [
                'result' => 'APPLIED',
                'plan_hash' => $selection['plan_hash'],
                'approval_hash' => $selection['approval_hash'],
                'operator' => $operator,
                'backup_reference' => $backupReference,
                ...$counts,
                'lines_changed' => $selection['lines']->count(),
                'sale_cogs_delta' => (int) $selection['lines']->sum('sale_cogs_delta'),
                'return_cogs_delta' => (int) $selection['lines']->sum('return_cogs_delta'),
                'net_report_cogs_delta' => (int) $selection['lines']->sum('net_report_cogs_delta'),
            ];
        }, 3);
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function lockDependencies(Collection $lines): void
    {
        $invoiceIds = $lines->pluck('invoice_id')
            ->merge($lines->flatMap(fn (array $line): array => collect((array) $line['current_serial_snapshots'])
                ->pluck('invoice_id')->filter()->all()))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $invoiceItemIds = $lines->pluck('invoice_item_id')
            ->merge($lines->flatMap(fn (array $line): array => collect((array) $line['current_serial_snapshots'])
                ->pluck('current_invoice_item_id')->filter()->all()))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $serialIds = $lines->flatMap(fn (array $line): array => collect((array) $line['current_serial_snapshots'])
            ->pluck('serial_id')->all())
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $linkIds = $lines->flatMap(function (array $line): array {
            return collect((array) data_get($line, 'expected.sale.serials', []))
                ->pluck('invoice_item_serial_id')
                ->merge(collect((array) $line['current_serial_snapshots'])->pluck('current_invoice_item_serial_id')->filter())
                ->all();
        })->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $movementIds = $lines->flatMap(function (array $line): array {
            return collect([(int) data_get($line, 'expected.sale.stock_movement.id')])
                ->merge(collect((array) $line['return_dependencies'])->pluck('expected.stock_movement.id')->filter())
                ->all();
        })->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values()->all();
        $returnIds = $lines->flatMap(fn (array $line): array => collect((array) $line['return_dependencies'])
            ->pluck('return_id')->all())
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $returnItemIds = $lines->flatMap(fn (array $line): array => collect((array) $line['return_dependencies'])
            ->pluck('return_item_id')->all())
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $taskIds = $lines->flatMap(function (array $line): array {
            return collect((array) data_get($line, 'before.sale.serials', []))
                ->pluck('repair_task_id')->filter()
                ->merge(collect((array) $line['current_serial_snapshots'])->pluck('repair_task_id')->filter())
                ->all();
        })->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
        InvoiceItem::query()->whereIn('id', $invoiceItemIds)->orderBy('id')->lockForUpdate()->get();
        InvoiceItemSerial::query()->whereIn('id', $linkIds)->orderBy('id')->lockForUpdate()->get();
        SerialImei::query()->whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();
        StockMovement::query()->whereIn('id', $movementIds)->orderBy('id')->lockForUpdate()->get();
        Task::query()->whereIn('id', $taskIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('returns')->whereIn('id', $returnIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('return_items')->whereIn('id', $returnItemIds)->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function applySaleDocuments(Collection $lines): void
    {
        foreach ($lines->where('changes.sale_document_update', true) as $line) {
            DB::table('invoice_items')->where('id', (int) $line['invoice_item_id'])->update([
                'cost_price' => (int) data_get($line, 'expected.sale.invoice_item_cost'),
            ]);
            DB::table('stock_movements')
                ->where('id', (int) data_get($line, 'expected.sale.stock_movement.id'))
                ->update([
                    'unit_cost' => (int) data_get($line, 'expected.sale.stock_movement.unit_cost'),
                    'total_cost' => (int) data_get($line, 'expected.sale.stock_movement.total_cost'),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function applySerialLinks(Collection $lines): void
    {
        $updates = collect();
        foreach ($lines as $line) {
            foreach ((array) data_get($line, 'expected.sale.serials', []) as $serial) {
                $this->putConsistent($updates, (int) $serial['invoice_item_serial_id'], (int) $serial['expected_cost']);
            }
            foreach ((array) $line['current_serial_snapshots'] as $snapshot) {
                if (($snapshot['current_invoice_item_serial_id'] ?? null) !== null) {
                    $this->putConsistent(
                        $updates,
                        (int) $snapshot['current_invoice_item_serial_id'],
                        (int) $snapshot['expected_current_link_cost'],
                    );
                }
            }
        }

        foreach ($updates->sortKeys() as $linkId => $cost) {
            DB::table('invoice_item_serials')->where('id', (int) $linkId)->update(['cost_price' => (int) $cost]);
        }
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function applyReturns(Collection $lines): void
    {
        $returns = $lines->flatMap(fn (array $line): array => (array) $line['return_dependencies'])
            ->keyBy('return_item_id')
            ->filter(fn (array $return): bool => $this->money(data_get($return, 'before.cost_price')) !== $this->money(data_get($return, 'expected.cost_price'))
                || $this->money(data_get($return, 'before.stock_movement.unit_cost')) !== $this->money(data_get($return, 'expected.stock_movement.unit_cost'))
                || $this->money(data_get($return, 'before.stock_movement.total_cost')) !== $this->money(data_get($return, 'expected.stock_movement.total_cost')))
            ->sortKeys();
        foreach ($returns as $return) {
            DB::table('return_items')->where('id', (int) $return['return_item_id'])->update([
                'cost_price' => (int) data_get($return, 'expected.cost_price'),
            ]);
            DB::table('stock_movements')
                ->where('id', (int) data_get($return, 'expected.stock_movement.id'))
                ->update([
                    'unit_cost' => (int) data_get($return, 'expected.stock_movement.unit_cost'),
                    'total_cost' => (int) data_get($return, 'expected.stock_movement.total_cost'),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function applyCurrentSerialSnapshots(Collection $lines): void
    {
        $updates = collect();
        foreach ($lines as $line) {
            foreach ((array) $line['current_serial_snapshots'] as $snapshot) {
                $serialId = (int) $snapshot['serial_id'];
                $expected = $snapshot['expected_sold_cost'] ?? null;
                if ($this->money($snapshot['before_sold_cost'] ?? null) !== $this->money($expected)) {
                    $this->putConsistent($updates, $serialId, $expected === null ? null : (int) $expected);
                }
            }
        }

        foreach ($updates->sortKeys() as $serialId => $cost) {
            DB::table('serial_imeis')->where('id', (int) $serialId)->update(['sold_cost_price' => $cost]);
        }
    }

    /** @param Collection<int, array<string, mixed>> $lines
     * @return array<string, int>
     */
    private function counts(Collection $lines): array
    {
        $linkIds = $lines->flatMap(function (array $line): array {
            return collect((array) data_get($line, 'changes.invoice_item_serial_ids', []))
                ->merge(collect((array) $line['current_serial_snapshots'])
                    ->filter(fn (array $snapshot): bool => $this->money($snapshot['before_current_link_cost'] ?? null)
                        !== $this->money($snapshot['expected_current_link_cost'] ?? null))
                    ->pluck('current_invoice_item_serial_id')->filter())
                ->all();
        })->unique();

        return [
            'lines_selected' => $lines->count(),
            'invoice_items_to_update' => $lines->where('changes.sale_document_update', true)->count(),
            'invoice_item_serials_to_update' => $linkIds->count(),
            'return_items_to_update' => $lines
                ->flatMap(fn (array $line): array => (array) data_get($line, 'changes.return_item_ids', []))
                ->unique()->count(),
            'serial_sold_snapshots_to_update' => $lines
                ->flatMap(fn (array $line): array => (array) data_get($line, 'changes.current_serial_ids', []))
                ->unique()->count(),
            'stock_movements_to_update' => $lines->where('changes.sale_document_update', true)->count()
                + $lines->flatMap(fn (array $line): array => (array) data_get($line, 'changes.return_item_ids', []))
                    ->unique()->count(),
        ];
    }

    private function putConsistent(Collection $updates, int $id, ?int $value): void
    {
        if ($updates->has($id) && $updates->get($id) !== $value) {
            throw new RuntimeException('Conflicting lifecycle cost expectations for entity #'.$id.'.');
        }
        $updates->put($id, $value);
    }

    private function money(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
