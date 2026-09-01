<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Produces a reviewed, immutable-in-practice remediation plan for historical
 * serial COGS snapshots. It intentionally only exposes rows with complete
 * repair evidence and a simple downstream lifecycle as automatic candidates.
 */
final class SerialCostRemediationPlanService
{
    public const CONTRACT_VERSION = 'serial-cost-remediation-plan-v1';

    public const ACTION_REPAIR = 'APPLY_EVIDENCE_BACKED_SERIAL_COGS';

    public const ACTION_MANUAL_REVIEW = 'MANUAL_REVIEW_REQUIRED';

    public const BLOCK_MISSING_REPAIR_EVIDENCE = 'MISSING_INDEPENDENT_REPAIR_EVIDENCE';

    public const BLOCK_INCOMPLETE_SERIAL_EVIDENCE = 'INCOMPLETE_SERIAL_EVIDENCE';

    public const BLOCK_NOT_CURRENT_SERIAL_SNAPSHOT = 'SERIAL_NOT_CURRENT_SALE';

    public const BLOCK_RESALE_HISTORY = 'RESALE_HISTORY';

    public const BLOCK_RETURN_HISTORY = 'COMPLETED_RETURN_HISTORY';

    public const BLOCK_NO_FINANCIAL_IMPACT = 'NO_DOCUMENT_OR_COGS_MISMATCH';

    public const BLOCK_STOCK_MOVEMENT = 'MISSING_OR_AMBIGUOUS_STOCK_MOVEMENT';

    public const BLOCK_INVOICE_STATUS = 'INVOICE_NOT_COMPLETED';

    public const BLOCK_MOVEMENT_IDENTITY = 'STOCK_MOVEMENT_IDENTITY_MISMATCH';

    public function __construct(private readonly SerialCostSnapshotAuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?int $productId = null, ?string $invoiceCode = null): array
    {
        $rows = $this->audit->inspect($productId, $invoiceCode);
        $invoiceItemIds = $rows->pluck('invoice_item_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $invoiceIds = $rows->pluck('invoice_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $movementIds = $rows->pluck('stock_movement_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $returnsByItem = $this->completedReturnsByInvoiceItem($invoiceItemIds);
        $invoices = $this->invoicesById($invoiceIds);
        $movements = $this->movementsById($movementIds);

        $lines = $rows
            ->groupBy(fn (array $row): int => (int) $row['invoice_item_id'])
            ->sortBy(function (Collection $lineRows): string {
                $first = $lineRows->first();

                return sprintf('%010d|%010d', (int) $first['invoice_id'], (int) $first['invoice_item_id']);
            })
            ->map(fn (Collection $lineRows): array => $this->buildLine(
                $lineRows->values(),
                $returnsByItem->get((int) $lineRows->first()['invoice_item_id'], collect()),
                $invoices->get((int) $lineRows->first()['invoice_id']),
                $movements,
            ))
            ->values();

        $repairLines = $lines
            ->where('proposed_action', self::ACTION_REPAIR)
            ->values()
            ->all();
        $manualReviewLines = $lines
            ->where('proposed_action', self::ACTION_MANUAL_REVIEW)
            ->values()
            ->all();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'database_fingerprint' => $this->databaseFingerprint(),
            'source_audit' => [
                'serial_rows_checked' => $rows->count(),
                'invoice_lines_checked' => $lines->count(),
                'with_repair_evidence' => $rows->whereNotNull('expected_cost')->count(),
                'financial_mismatch_lines' => $lines
                    ->filter(fn (array $line): bool => (bool) $line['financial_impact'])
                    ->count(),
            ],
            'summary' => $this->summary($lines),
            'repair_lines' => $repairLines,
            'manual_review_lines' => $manualReviewLines,
            'plan_hash' => $this->planHash($repairLines),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, mixed>>
     */
    public function validatedRepairLines(array $plan): array
    {
        if (($plan['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Unsupported serial COGS remediation plan contract.');
        }

        $repairLines = array_values((array) ($plan['repair_lines'] ?? []));
        $expectedPlanHash = $this->planHash($repairLines);
        if (! hash_equals((string) ($plan['plan_hash'] ?? ''), $expectedPlanHash)) {
            throw new RuntimeException('Plan hash mismatch. Regenerate the plan from the current read-only audit.');
        }

        if (! hash_equals((string) ($plan['database_fingerprint'] ?? ''), $this->databaseFingerprint())) {
            throw new RuntimeException('Database fingerprint mismatch. This plan belongs to another database.');
        }

        foreach ($repairLines as $line) {
            if (($line['proposed_action'] ?? null) !== self::ACTION_REPAIR
                || (array) ($line['blocking_flags'] ?? []) !== []) {
                throw new RuntimeException('The plan contains a non-repair or blocked line in the auto-apply scope.');
            }
        }

        return $repairLines;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCurrentRepairLine(string $invoiceCode, int $invoiceItemId): ?array
    {
        $line = $this->findCurrentLine($invoiceCode, $invoiceItemId);

        return is_array($line) && ($line['proposed_action'] ?? null) === self::ACTION_REPAIR
            ? $line
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCurrentLine(string $invoiceCode, int $invoiceItemId): ?array
    {
        $plan = $this->build(null, $invoiceCode);

        return collect(array_merge(
            (array) $plan['repair_lines'],
            (array) $plan['manual_review_lines'],
        ))->first(fn (array $line): bool => (int) $line['invoice_item_id'] === $invoiceItemId);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function currentAuditRowsForLine(string $invoiceCode, int $invoiceItemId): Collection
    {
        return $this->audit->inspect(null, $invoiceCode)
            ->where('invoice_item_id', $invoiceItemId)
            ->values();
    }

    /** @param array<int, array<string, mixed>> $repairLines */
    public function planHash(array $repairLines): string
    {
        $repairLines = collect($repairLines)
            ->sortBy('line_key')
            ->values()
            ->all();

        return $this->canonicalHash($repairLines);
    }

    public function databaseFingerprint(): string
    {
        $connection = DB::connection();

        return hash('sha256', implode('|', [
            $connection->getDriverName(),
            $connection->getDatabaseName(),
            (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        ]));
    }

    public function canonicalHash(mixed $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, object>  $returns
     * @param  Collection<int, object>  $movements
     * @return array<string, mixed>
     */
    private function buildLine(Collection $rows, Collection $returns, ?object $invoice, Collection $movements): array
    {
        $first = $rows->first();
        $invoiceItemId = (int) $first['invoice_item_id'];
        $quantity = (int) $first['invoice_item_quantity'];
        $movementIds = $rows->pluck('stock_movement_id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $movement = $movementIds->count() === 1
            ? $movements->get((int) $movementIds->first())
            : null;
        $expectedItemCost = $first['expected_invoice_item_cost'] === null
            ? null
            : $this->money($first['expected_invoice_item_cost']);

        $flags = [];
        if ($rows->contains(fn (array $row): bool => $row['expected_cost'] === null)) {
            $flags[] = self::BLOCK_MISSING_REPAIR_EVIDENCE;
        }
        if ($rows->count() !== $quantity) {
            $flags[] = self::BLOCK_INCOMPLETE_SERIAL_EVIDENCE;
        }
        if ($rows->contains(fn (array $row): bool => ! (bool) $row['serial_snapshot_comparable'])) {
            $flags[] = self::BLOCK_NOT_CURRENT_SERIAL_SNAPSHOT;
        }
        if ($rows->contains(fn (array $row): bool => (bool) $row['resale_protected'] || (bool) $row['line_resale_protected'])) {
            $flags[] = self::BLOCK_RESALE_HISTORY;
        }
        if ($returns->isNotEmpty()) {
            $flags[] = self::BLOCK_RETURN_HISTORY;
        }
        if (! $rows->contains(fn (array $row): bool => (bool) $row['financial_impact'])) {
            $flags[] = self::BLOCK_NO_FINANCIAL_IMPACT;
        }
        if ($movement === null) {
            $flags[] = self::BLOCK_STOCK_MOVEMENT;
        }
        if (! $invoice || ! BusinessStatus::isCompleted($invoice->status)) {
            $flags[] = self::BLOCK_INVOICE_STATUS;
        }
        if ($movement !== null && ! $this->movementMatchesLine($movement, $first, $quantity)) {
            $flags[] = self::BLOCK_MOVEMENT_IDENTITY;
        }

        $flags = array_values(array_unique($flags));
        $serials = $rows
            ->sortBy('serial_id')
            ->map(fn (array $row): array => [
                'serial_id' => (int) $row['serial_id'],
                'serial_number' => (string) $row['serial_number'],
                'invoice_item_serial_id' => (int) $row['invoice_item_serial_id'],
                'repair_task_id' => $row['repair_task_id'] === null ? null : (int) $row['repair_task_id'],
                'repair_task_code' => $row['repair_task_code'],
                'repair_completed_at' => $row['repair_completed_at'],
                'expected_cost' => $row['expected_cost'] === null ? null : $this->money($row['expected_cost']),
                'before_link_cost' => $this->money($row['invoice_item_serial_cost_price']),
                'before_sold_cost' => $row['serial_sold_cost_price'] === null
                    ? null
                    : $this->money($row['serial_sold_cost_price']),
            ])
            ->values()
            ->all();
        $line = [
            'line_key' => 'invoice_item:'.$invoiceItemId,
            'invoice_id' => (int) $first['invoice_id'],
            'invoice_code' => (string) $first['invoice_code'],
            'invoice_item_id' => $invoiceItemId,
            'product_id' => (int) $first['product_id'],
            'product_sku' => (string) $first['product_sku'],
            'invoice_status' => $invoice->status ?? null,
            'invoice_item_quantity' => $quantity,
            'financial_impact' => $rows->contains(fn (array $row): bool => (bool) $row['financial_impact']),
            'impact_scope' => (string) $first['impact_scope'],
            'proposed_action' => $flags === [] ? self::ACTION_REPAIR : self::ACTION_MANUAL_REVIEW,
            'blocking_flags' => $flags,
            'return_documents' => $returns
                ->map(fn (object $return): array => [
                    'return_id' => (int) $return->return_id,
                    'return_code' => (string) $return->return_code,
                ])
                ->values()
                ->all(),
            'before' => [
                'invoice_item_cost' => $this->money($first['invoice_item_cost_price']),
                'stock_movement' => $movement === null ? null : [
                    'id' => (int) $movement->id,
                    'unit_cost' => $this->money($movement->unit_cost),
                    'total_cost' => $this->money($movement->total_cost),
                ],
                'serials' => $serials,
            ],
            'expected' => [
                'invoice_item_cost' => $expectedItemCost,
                'stock_movement' => $movement === null || $expectedItemCost === null ? null : [
                    'id' => (int) $movement->id,
                    'unit_cost' => $expectedItemCost,
                    'total_cost' => $this->money($quantity * $expectedItemCost),
                ],
                'serials' => collect($serials)
                    ->map(fn (array $serial): array => [
                        'serial_id' => $serial['serial_id'],
                        'invoice_item_serial_id' => $serial['invoice_item_serial_id'],
                        'expected_cost' => $serial['expected_cost'],
                    ])
                    ->all(),
            ],
            'evidence' => [
                'sale_recorded_at' => $first['sale_recorded_at'],
                'repair_tasks' => collect($serials)
                    ->map(fn (array $serial): array => [
                        'serial_id' => $serial['serial_id'],
                        'repair_task_id' => $serial['repair_task_id'],
                        'repair_task_code' => $serial['repair_task_code'],
                        'repair_completed_at' => $serial['repair_completed_at'],
                        'expected_cost' => $serial['expected_cost'],
                    ])
                    ->all(),
            ],
        ];
        $line['precondition_hash'] = $this->canonicalHash($this->preconditionPayload($line));

        return $line;
    }

    /** @param array<string, mixed> $line */
    private function preconditionPayload(array $line): array
    {
        return [
            'line_key' => $line['line_key'],
            'invoice_id' => $line['invoice_id'],
            'invoice_code' => $line['invoice_code'],
            'invoice_item_id' => $line['invoice_item_id'],
            'product_id' => $line['product_id'],
            'invoice_status' => $line['invoice_status'],
            'invoice_item_quantity' => $line['invoice_item_quantity'],
            'financial_impact' => $line['financial_impact'],
            'blocking_flags' => $line['blocking_flags'],
            'return_documents' => $line['return_documents'],
            'before' => $line['before'],
            'expected' => $line['expected'],
            'evidence' => $line['evidence'],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function summary(Collection $lines): array
    {
        return [
            'auto_apply_candidates' => $lines->where('proposed_action', self::ACTION_REPAIR)->count(),
            'manual_review_lines' => $lines->where('proposed_action', self::ACTION_MANUAL_REVIEW)->count(),
            'blocked_resale_history' => $this->countFlag($lines, self::BLOCK_RESALE_HISTORY),
            'blocked_return_history' => $this->countFlag($lines, self::BLOCK_RETURN_HISTORY),
            'blocked_missing_repair_evidence' => $this->countFlag($lines, self::BLOCK_MISSING_REPAIR_EVIDENCE),
            'serial_snapshot_only' => $this->countFlag($lines, self::BLOCK_NO_FINANCIAL_IMPACT),
            'blocked_stock_movement' => $this->countFlag($lines, self::BLOCK_STOCK_MOVEMENT),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function countFlag(Collection $lines, string $flag): int
    {
        return $lines->filter(fn (array $line): bool => in_array($flag, (array) $line['blocking_flags'], true))->count();
    }

    /** @param array<int, int> $invoiceItemIds */
    private function completedReturnsByInvoiceItem(array $invoiceItemIds): Collection
    {
        if ($invoiceItemIds === [] || ! Schema::hasTable('returns') || ! Schema::hasTable('return_items')) {
            return collect();
        }

        return DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->whereIn('return_items.invoice_item_id', $invoiceItemIds)
            ->select([
                'return_items.invoice_item_id',
                'returns.id as return_id',
                'returns.code as return_code',
                'returns.status as return_status',
            ])
            ->get()
            ->filter(fn (object $return): bool => BusinessStatus::isReturnCompleted($return->return_status))
            ->groupBy(fn (object $return): int => (int) $return->invoice_item_id);
    }

    /** @param array<int, int> $invoiceIds */
    private function invoicesById(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return DB::table('invoices')
            ->whereIn('id', $invoiceIds)
            ->get(['id', 'code', 'status'])
            ->keyBy(fn (object $invoice): int => (int) $invoice->id);
    }

    /** @param array<int, int> $movementIds */
    private function movementsById(array $movementIds): Collection
    {
        if ($movementIds === [] || ! Schema::hasTable('stock_movements')) {
            return collect();
        }

        return DB::table('stock_movements')
            ->whereIn('id', $movementIds)
            ->get([
                'id',
                'product_id',
                'type',
                'direction',
                'qty',
                'unit_cost',
                'total_cost',
                'ref_type',
                'ref_id',
                'ref_code',
            ])
            ->keyBy(fn (object $movement): int => (int) $movement->id);
    }

    /** @param array<string, mixed> $row */
    private function movementMatchesLine(object $movement, array $row, int $quantity): bool
    {
        return (int) $movement->product_id === (int) $row['product_id']
            && (string) $movement->type === StockMovementService::TYPE_OUT_INVOICE
            && (string) $movement->direction === 'out'
            && (int) $movement->qty === $quantity
            && (
                ((int) ($movement->ref_id ?? 0) === (int) $row['invoice_id']
                    && (string) ($movement->ref_type ?? '') === Invoice::class)
                || (string) ($movement->ref_code ?? '') === (string) $row['invoice_code']
            );
    }

    private function money(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value, 0);
    }
}
