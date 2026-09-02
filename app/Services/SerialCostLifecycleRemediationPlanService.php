<?php

namespace App\Services;

use App\Models\OrderReturn;
use App\Support\Status\BusinessStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds an evidence-backed plan for sale -> return -> resale cost snapshots.
 *
 * The simple remediation workflow deliberately refuses these rows because a
 * historical sale must not overwrite the snapshot of a later sale. This
 * service resolves the complete lifecycle first, then describes every write
 * needed to keep sale COGS, returned COGS and the current serial projection in
 * agreement with the same independent repair evidence.
 */
final class SerialCostLifecycleRemediationPlanService
{
    public const CONTRACT_VERSION = 'serial-cost-lifecycle-remediation-plan-v1';

    public const ACTION_REPAIR = 'APPLY_EVIDENCE_BACKED_SERIAL_LIFECYCLE_COGS';

    public const ACTION_VERIFIED = 'NO_LIFECYCLE_COGS_CHANGE_REQUIRED';

    public const ACTION_BLOCKED = 'LIFECYCLE_REVIEW_REQUIRED';

    public const BLOCK_RETURN_IDENTITY = 'RETURN_IDENTITY_MISMATCH';

    public const BLOCK_RETURN_SERIALS = 'RETURN_SERIAL_EVIDENCE_MISMATCH';

    public const BLOCK_RETURN_MOVEMENT = 'RETURN_STOCK_MOVEMENT_MISSING_OR_AMBIGUOUS';

    public const BLOCK_CURRENT_SERIAL = 'CURRENT_SERIAL_STATE_UNRESOLVED';

    public const BLOCK_CURRENT_SALE_EVIDENCE = 'CURRENT_SALE_COST_EVIDENCE_UNRESOLVED';

    public const BLOCK_LIFECYCLE = 'SERIAL_LIFECYCLE_UNRESOLVED';

    private const ALLOWED_BASE_FLAGS = [
        SerialCostRemediationPlanService::BLOCK_NOT_CURRENT_SERIAL_SNAPSHOT,
        SerialCostRemediationPlanService::BLOCK_RESALE_HISTORY,
        SerialCostRemediationPlanService::BLOCK_RETURN_HISTORY,
        SerialCostRemediationPlanService::BLOCK_NO_FINANCIAL_IMPACT,
        SerialCostRemediationPlanService::BLOCK_MISSING_REPAIR_EVIDENCE,
    ];

    private const ALLOWED_LIFECYCLE_CLASSIFICATIONS = [
        SerialLifecycleInspectionService::ORDERED_RESALE_HISTORY,
        SerialLifecycleInspectionService::BACKDATED_RESALE,
    ];

    public function __construct(
        private readonly SerialCostRemediationPlanService $basePlans,
        private readonly SerialCostSnapshotAuditService $audit,
        private readonly SerialLifecycleInspectionService $lifecycles,
    ) {}

    /** @return array<string, mixed> */
    public function build(?int $productId = null, bool $includeVerifiedLines = false): array
    {
        $basePlan = $this->basePlans->build($productId);
        $baseLines = collect((array) $basePlan['manual_review_lines'])
            ->filter(fn (array $line): bool => $this->hasCompleteAllowedEvidence($line))
            ->map(fn (array $line): array => $this->scopeToKnownEvidence($line))
            ->sortBy('line_key')
            ->values();

        if ($baseLines->isEmpty()) {
            return $this->emptyPlan($basePlan);
        }

        $auditRows = $this->audit->inspect($productId);
        $auditBySerial = $auditRows->groupBy(fn (array $row): int => (int) $row['serial_id']);
        $serialIds = $baseLines
            ->flatMap(fn (array $line): array => (array) data_get($line, 'expected.serials', []))
            ->pluck('serial_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $serialRows = DB::table('serial_imeis')
            ->whereIn('id', $serialIds->all())
            ->get(['id', 'serial_number', 'product_id', 'status', 'invoice_id', 'sold_cost_price'])
            ->keyBy(fn (object $serial): int => (int) $serial->id);
        $lifecycleBySerial = ($productId === null
            ? $this->lifecycles->inspectAll()
            : $this->lifecycles->inspectProduct($productId))
            ->keyBy(fn (array $row): int => (int) $row['serial_id']);
        $returnsByItem = $this->returnDependencies($baseLines);

        $enriched = $baseLines
            ->map(fn (array $line): array => $this->enrichLine(
                $line,
                $returnsByItem->get((int) $line['invoice_item_id'], collect()),
                $auditBySerial,
                $serialRows,
                $lifecycleBySerial,
            ))
            ->values();

        $repairLines = $enriched
            ->where('proposed_action', self::ACTION_REPAIR)
            ->sortBy('line_key')
            ->values();
        $verifiedLines = $enriched
            ->where('proposed_action', self::ACTION_VERIFIED)
            ->sortBy('line_key')
            ->values();
        $blockedLines = $enriched
            ->where('proposed_action', self::ACTION_BLOCKED)
            ->sortBy('line_key')
            ->values();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'database_fingerprint' => (string) $basePlan['database_fingerprint'],
            'source_plan_hash' => (string) $basePlan['plan_hash'],
            'summary' => $this->summary($repairLines, $verifiedLines, $blockedLines),
            'repair_lines' => $repairLines->all(),
            'verified_lines' => $includeVerifiedLines ? $verifiedLines->all() : [],
            'blocked_lines' => $blockedLines->all(),
            'plan_hash' => $this->planHash($repairLines->all()),
        ];
    }

    /** @param array<string, mixed> $plan
     * @return array<int, array<string, mixed>>
     */
    public function validatedRepairLines(array $plan): array
    {
        if (($plan['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Unsupported serial lifecycle COGS remediation plan contract.');
        }

        $lines = array_values((array) ($plan['repair_lines'] ?? []));
        if (! hash_equals((string) ($plan['plan_hash'] ?? ''), $this->planHash($lines))) {
            throw new RuntimeException('Lifecycle remediation plan hash mismatch. Regenerate the plan.');
        }
        if (! hash_equals(
            (string) ($plan['database_fingerprint'] ?? ''),
            $this->basePlans->databaseFingerprint(),
        )) {
            throw new RuntimeException('Lifecycle remediation plan belongs to another database.');
        }

        foreach ($lines as $line) {
            if (($line['proposed_action'] ?? null) !== self::ACTION_REPAIR
                || (array) ($line['lifecycle_blocking_flags'] ?? []) !== []) {
                throw new RuntimeException('Lifecycle plan contains a blocked or non-repair line.');
            }
        }

        return $lines;
    }

    /** @param array<int, array<string, mixed>> $lines */
    public function planHash(array $lines): string
    {
        return $this->canonicalHash(collect($lines)
            ->sortBy('line_key')
            ->values()
            ->all());
    }

    public function canonicalHash(mixed $payload): string
    {
        return $this->basePlans->canonicalHash($payload);
    }

    /** @param array<string, mixed> $basePlan
     * @return array<string, mixed>
     */
    private function emptyPlan(array $basePlan): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'database_fingerprint' => (string) $basePlan['database_fingerprint'],
            'source_plan_hash' => (string) $basePlan['plan_hash'],
            'summary' => [
                'repair_lines' => 0,
                'verified_lines' => 0,
                'blocked_lines' => 0,
                'invoice_item_serials_to_update' => 0,
                'return_items_to_update' => 0,
                'current_serial_snapshots_to_update' => 0,
                'sale_cogs_delta' => 0,
                'return_cogs_delta' => 0,
                'net_report_cogs_delta' => 0,
            ],
            'repair_lines' => [],
            'verified_lines' => [],
            'blocked_lines' => [],
            'plan_hash' => $this->planHash([]),
        ];
    }

    /** @param array<string, mixed> $line */
    private function hasCompleteAllowedEvidence(array $line): bool
    {
        if (array_diff((array) ($line['blocking_flags'] ?? []), self::ALLOWED_BASE_FLAGS) !== []) {
            return false;
        }

        $serials = collect((array) data_get($line, 'expected.serials', []));
        $known = $serials->whereNotNull('expected_cost');
        $complete = $serials->count() === (int) ($line['invoice_item_quantity'] ?? 0)
            && $serials->isNotEmpty()
            && $known->count() === $serials->count();

        return data_get($line, 'before.stock_movement.id') !== null
            && ($complete || (! (bool) ($line['financial_impact'] ?? false) && $known->isNotEmpty()));
    }

    /** @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function scopeToKnownEvidence(array $line): array
    {
        $knownExpected = collect((array) data_get($line, 'expected.serials', []))
            ->whereNotNull('expected_cost')
            ->values();
        $knownLinkIds = $knownExpected->pluck('invoice_item_serial_id')->map(fn ($id): int => (int) $id);
        $allSerialCount = count((array) data_get($line, 'expected.serials', []));

        data_set($line, 'expected.serials', $knownExpected->all());
        data_set($line, 'before.serials', collect((array) data_get($line, 'before.serials', []))
            ->filter(fn (array $serial): bool => $knownLinkIds->contains((int) $serial['invoice_item_serial_id']))
            ->values()
            ->all());
        $line['_partial_evidence'] = $knownExpected->count() !== $allSerialCount
            || $knownExpected->count() !== (int) $line['invoice_item_quantity'];

        return $line;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $returnDependencies
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $auditBySerial
     * @param  Collection<int, object>  $serialRows
     * @param  Collection<int, array<string, mixed>>  $lifecycleBySerial
     * @return array<string, mixed>
     */
    private function enrichLine(
        array $line,
        Collection $returnDependencies,
        Collection $auditBySerial,
        Collection $serialRows,
        Collection $lifecycleBySerial,
    ): array {
        $blockingFlags = $returnDependencies
            ->flatMap(fn (array $return): array => (array) ($return['blocking_flags'] ?? []))
            ->values();
        if (! (bool) ($line['_partial_evidence'] ?? false)
            && in_array(SerialCostRemediationPlanService::BLOCK_RETURN_HISTORY, (array) $line['blocking_flags'], true)
            && $returnDependencies->isEmpty()) {
            $blockingFlags->push(self::BLOCK_RETURN_IDENTITY);
        }

        $expectedSerials = collect((array) data_get($line, 'expected.serials', []))
            ->sortBy('serial_id')
            ->values();
        $currentSnapshots = $expectedSerials->map(function (array $expected) use (
            $auditBySerial,
            $serialRows,
            $lifecycleBySerial,
            $blockingFlags,
        ): array {
            $serialId = (int) $expected['serial_id'];
            $serial = $serialRows->get($serialId);
            $serialAuditRows = $auditBySerial->get($serialId, collect());
            $saleCount = $serialAuditRows->pluck('invoice_id')->unique()->count();
            $lifecycle = null;

            if ($saleCount > 1) {
                $lifecycle = $lifecycleBySerial->get($serialId);
                if (! is_array($lifecycle)
                    || ! in_array((string) ($lifecycle['classification'] ?? ''), self::ALLOWED_LIFECYCLE_CLASSIFICATIONS, true)) {
                    $blockingFlags->push(self::BLOCK_LIFECYCLE);
                }
            }

            if (! $serial) {
                $blockingFlags->push(self::BLOCK_CURRENT_SERIAL);

                return ['serial_id' => $serialId, 'blocking_flags' => [self::BLOCK_CURRENT_SERIAL]];
            }

            $status = (string) $serial->status;
            $currentInvoiceId = $serial->invoice_id === null ? null : (int) $serial->invoice_id;
            if ($status === 'in_stock') {
                $flags = [];
                if ($currentInvoiceId !== null) {
                    $flags[] = self::BLOCK_CURRENT_SERIAL;
                    $blockingFlags->push(self::BLOCK_CURRENT_SERIAL);
                }

                return [
                    'serial_id' => $serialId,
                    'serial_number' => (string) $serial->serial_number,
                    'status' => $status,
                    'invoice_id' => null,
                    'current_invoice_item_id' => null,
                    'current_invoice_item_serial_id' => null,
                    'repair_task_id' => null,
                    'before_sold_cost' => $this->money($serial->sold_cost_price),
                    'expected_sold_cost' => null,
                    'before_current_link_cost' => null,
                    'expected_current_link_cost' => null,
                    'lifecycle' => $lifecycle,
                    'blocking_flags' => $flags,
                ];
            }

            if ($status !== 'sold' || $currentInvoiceId === null) {
                $blockingFlags->push(self::BLOCK_CURRENT_SERIAL);

                return [
                    'serial_id' => $serialId,
                    'serial_number' => (string) $serial->serial_number,
                    'status' => $status,
                    'invoice_id' => $currentInvoiceId,
                    'lifecycle' => $lifecycle,
                    'blocking_flags' => [self::BLOCK_CURRENT_SERIAL],
                ];
            }

            $currentRows = $serialAuditRows
                ->where('invoice_id', $currentInvoiceId)
                ->values();
            if ($currentRows->count() !== 1 || $currentRows->first()['expected_cost'] === null) {
                $blockingFlags->push(self::BLOCK_CURRENT_SALE_EVIDENCE);

                return [
                    'serial_id' => $serialId,
                    'serial_number' => (string) $serial->serial_number,
                    'status' => $status,
                    'invoice_id' => $currentInvoiceId,
                    'lifecycle' => $lifecycle,
                    'blocking_flags' => [self::BLOCK_CURRENT_SALE_EVIDENCE],
                ];
            }

            $current = $currentRows->first();

            return [
                'serial_id' => $serialId,
                'serial_number' => (string) $serial->serial_number,
                'status' => $status,
                'invoice_id' => $currentInvoiceId,
                'current_invoice_item_id' => (int) $current['invoice_item_id'],
                'current_invoice_item_serial_id' => (int) $current['invoice_item_serial_id'],
                'repair_task_id' => (int) $current['repair_task_id'],
                'repair_task_code' => (string) $current['repair_task_code'],
                'repair_completed_at' => $current['repair_completed_at'],
                'before_sold_cost' => $this->money($serial->sold_cost_price),
                'expected_sold_cost' => $this->money($current['expected_cost']),
                'before_current_link_cost' => $this->money($current['invoice_item_serial_cost_price']),
                'expected_current_link_cost' => $this->money($current['expected_cost']),
                'lifecycle' => $lifecycle,
                'blocking_flags' => [],
            ];
        })->values();

        $blockingFlags = $blockingFlags
            ->merge($currentSnapshots->flatMap(fn (array $snapshot): array => (array) ($snapshot['blocking_flags'] ?? [])))
            ->unique()
            ->sort()
            ->values();

        $financialImpact = (bool) ($line['financial_impact'] ?? false);
        $saleBefore = [
            'invoice_item_cost' => $this->money(data_get($line, 'before.invoice_item_cost')),
            'stock_movement' => (array) data_get($line, 'before.stock_movement'),
            'serials' => collect((array) data_get($line, 'before.serials', []))
                ->sortBy('invoice_item_serial_id')
                ->values()
                ->all(),
        ];
        $saleExpected = [
            'invoice_item_cost' => $financialImpact
                ? $this->money(data_get($line, 'expected.invoice_item_cost'))
                : $saleBefore['invoice_item_cost'],
            'stock_movement' => $financialImpact
                ? (array) data_get($line, 'expected.stock_movement')
                : $saleBefore['stock_movement'],
            'serials' => collect((array) data_get($line, 'expected.serials', []))
                ->sortBy('invoice_item_serial_id')
                ->values()
                ->all(),
        ];

        $currentSaleState = [
            'invoice_item_cost' => $saleBefore['invoice_item_cost'],
            'stock_movement' => $saleBefore['stock_movement'],
            'serials' => collect($saleBefore['serials'])->map(fn (array $serial): array => [
                'serial_id' => (int) $serial['serial_id'],
                'invoice_item_serial_id' => (int) $serial['invoice_item_serial_id'],
                'cost_price' => $this->money($serial['before_link_cost']),
            ])->sortBy('invoice_item_serial_id')->values()->all(),
        ];
        $expectedSaleState = [
            'invoice_item_cost' => $saleExpected['invoice_item_cost'],
            'stock_movement' => $saleExpected['stock_movement'],
            'serials' => collect($saleExpected['serials'])->map(fn (array $serial): array => [
                'serial_id' => (int) $serial['serial_id'],
                'invoice_item_serial_id' => (int) $serial['invoice_item_serial_id'],
                'cost_price' => $this->money($serial['expected_cost']),
            ])->sortBy('invoice_item_serial_id')->values()->all(),
        ];
        $currentState = [
            'sale' => $currentSaleState,
            'returns' => $returnDependencies->map(fn (array $return): array => [
                'return_item_id' => $return['return_item_id'],
                'cost_price' => data_get($return, 'before.cost_price'),
                'movement' => data_get($return, 'before.stock_movement'),
            ])->sortBy('return_item_id')->values()->all(),
            'current_serials' => $currentSnapshots->map(fn (array $snapshot): array => [
                'serial_id' => $snapshot['serial_id'],
                'sold_cost_price' => $snapshot['before_sold_cost'] ?? null,
                'current_invoice_item_serial_id' => $snapshot['current_invoice_item_serial_id'] ?? null,
                'current_link_cost' => $snapshot['before_current_link_cost'] ?? null,
            ])->sortBy('serial_id')->values()->all(),
        ];
        $expectedState = [
            'sale' => $expectedSaleState,
            'returns' => $returnDependencies->map(fn (array $return): array => [
                'return_item_id' => $return['return_item_id'],
                'cost_price' => data_get($return, 'expected.cost_price'),
                'movement' => data_get($return, 'expected.stock_movement'),
            ])->sortBy('return_item_id')->values()->all(),
            'current_serials' => $currentSnapshots->map(fn (array $snapshot): array => [
                'serial_id' => $snapshot['serial_id'],
                'sold_cost_price' => $snapshot['expected_sold_cost'] ?? null,
                'current_invoice_item_serial_id' => $snapshot['current_invoice_item_serial_id'] ?? null,
                'current_link_cost' => $snapshot['expected_current_link_cost'] ?? null,
            ])->sortBy('serial_id')->values()->all(),
        ];

        $identity = [
            'line_key' => (string) $line['line_key'],
            'invoice_id' => (int) $line['invoice_id'],
            'invoice_code' => (string) $line['invoice_code'],
            'invoice_item_id' => (int) $line['invoice_item_id'],
            'product_id' => (int) $line['product_id'],
            'invoice_status' => (string) $line['invoice_status'],
            'quantity' => (int) $line['invoice_item_quantity'],
            'sale_movement_id' => (int) data_get($line, 'before.stock_movement.id'),
            'serial_links' => $expectedSerials->map(fn (array $serial): array => [
                'serial_id' => (int) $serial['serial_id'],
                'invoice_item_serial_id' => (int) $serial['invoice_item_serial_id'],
            ])->all(),
            'return_dependencies' => $returnDependencies->map(fn (array $return): array => [
                'return_id' => $return['return_id'],
                'return_code' => $return['return_code'],
                'return_item_id' => $return['return_item_id'],
                'serial_ids' => $return['serial_ids'],
                'movement_id' => data_get($return, 'before.stock_movement.id'),
            ])->sortBy('return_item_id')->values()->all(),
            'current_serials' => $currentSnapshots->map(fn (array $snapshot): array => [
                'serial_id' => $snapshot['serial_id'],
                'status' => $snapshot['status'] ?? null,
                'invoice_id' => $snapshot['invoice_id'] ?? null,
                'current_invoice_item_id' => $snapshot['current_invoice_item_id'] ?? null,
                'current_invoice_item_serial_id' => $snapshot['current_invoice_item_serial_id'] ?? null,
                'repair_task_id' => $snapshot['repair_task_id'] ?? null,
                'lifecycle' => $snapshot['lifecycle'] ?? null,
            ])->sortBy('serial_id')->values()->all(),
        ];

        $changes = $this->changeSummary($currentState, $expectedState);
        $proposedAction = $blockingFlags->isNotEmpty()
            ? self::ACTION_BLOCKED
            : ($changes['total_updates'] > 0 ? self::ACTION_REPAIR : self::ACTION_VERIFIED);
        $saleDelta = (int) $line['invoice_item_quantity']
            * ((int) $saleExpected['invoice_item_cost'] - (int) $saleBefore['invoice_item_cost']);
        $returnDelta = $returnDependencies->sum(fn (array $return): int => (int) $return['quantity']
            * ((int) data_get($return, 'expected.cost_price') - (int) data_get($return, 'before.cost_price'))
        );

        $result = [
            'line_key' => (string) $line['line_key'],
            'invoice_id' => (int) $line['invoice_id'],
            'invoice_code' => (string) $line['invoice_code'],
            'invoice_item_id' => (int) $line['invoice_item_id'],
            'product_id' => (int) $line['product_id'],
            'product_sku' => (string) $line['product_sku'],
            'invoice_item_quantity' => (int) $line['invoice_item_quantity'],
            'base_blocking_flags' => array_values((array) $line['blocking_flags']),
            'lifecycle_blocking_flags' => $blockingFlags->all(),
            'proposed_action' => $proposedAction,
            'before' => [
                'sale' => $saleBefore,
                'returns' => $returnDependencies->pluck('before', 'return_item_id')->all(),
                'current_serials' => $currentState['current_serials'],
            ],
            'expected' => [
                'sale' => $saleExpected,
                'returns' => $returnDependencies->pluck('expected', 'return_item_id')->all(),
                'current_serials' => $expectedState['current_serials'],
            ],
            'return_dependencies' => $returnDependencies->all(),
            'current_serial_snapshots' => $currentSnapshots->all(),
            'changes' => $changes,
            'sale_cogs_delta' => $saleDelta,
            'return_cogs_delta' => $returnDelta,
            'net_report_cogs_delta' => $saleDelta - $returnDelta,
            'identity_hash' => $this->canonicalHash($identity),
            'current_state_hash' => $this->canonicalHash($currentState),
            'expected_state_hash' => $this->canonicalHash($expectedState),
        ];
        $result['precondition_hash'] = $this->canonicalHash([
            'identity_hash' => $result['identity_hash'],
            'current_state_hash' => $result['current_state_hash'],
            'expected_state_hash' => $result['expected_state_hash'],
            'lifecycle_blocking_flags' => $result['lifecycle_blocking_flags'],
        ]);

        return $result;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function returnDependencies(Collection $lines): Collection
    {
        $itemIds = $lines->pluck('invoice_item_id')->map(fn ($id): int => (int) $id)->all();
        $lineByItem = $lines->keyBy(fn (array $line): int => (int) $line['invoice_item_id']);
        $returns = DB::table('return_items as ri')
            ->join('returns as r', 'r.id', '=', 'ri.return_id')
            ->whereIn('ri.invoice_item_id', $itemIds)
            ->select([
                'ri.id as return_item_id', 'ri.return_id', 'ri.invoice_item_id', 'ri.product_id',
                'ri.quantity', 'ri.cost_price', 'ri.serial_ids',
                'r.code as return_code', 'r.status as return_status',
            ])
            ->get()
            ->filter(fn (object $row): bool => BusinessStatus::isReturnCompleted($row->return_status))
            ->values();
        if ($returns->isEmpty()) {
            return collect();
        }

        $returnIds = $returns->pluck('return_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $returnIdByCode = $returns->mapWithKeys(fn (object $row): array => [(string) $row->return_code => (int) $row->return_id]);
        $movements = DB::table('stock_movements')
            ->where('type', StockMovementService::TYPE_IN_INVOICE_RETURN)
            ->where(function ($query) use ($returnIds, $returnIdByCode): void {
                $query->where(function ($byId) use ($returnIds): void {
                    $byId->where('ref_type', OrderReturn::class)->whereIn('ref_id', $returnIds->all());
                })->orWhereIn('ref_code', $returnIdByCode->keys()->all());
            })
            ->get(['id', 'product_id', 'type', 'direction', 'qty', 'unit_cost', 'total_cost', 'ref_type', 'ref_id', 'ref_code'])
            ->map(function (object $movement) use ($returnIdByCode): array {
                $returnId = (string) $movement->ref_type === OrderReturn::class
                    ? (int) $movement->ref_id
                    : (int) $returnIdByCode->get((string) $movement->ref_code, 0);

                return [
                    'id' => (int) $movement->id,
                    'return_id' => $returnId,
                    'product_id' => (int) $movement->product_id,
                    'type' => (string) $movement->type,
                    'direction' => (string) $movement->direction,
                    'qty' => (int) $movement->qty,
                    'unit_cost' => $this->money($movement->unit_cost),
                    'total_cost' => $this->money($movement->total_cost),
                    'ref_type' => $movement->ref_type,
                    'ref_id' => $movement->ref_id === null ? null : (int) $movement->ref_id,
                    'ref_code' => $movement->ref_code,
                ];
            })
            ->groupBy(fn (array $movement): string => $movement['return_id'].'|'.$movement['product_id']);

        return $returns->map(function (object $return) use ($lineByItem, $movements): ?array {
            $line = $lineByItem->get((int) $return->invoice_item_id);
            $expectedBySerial = collect((array) data_get($line, 'expected.serials', []))->keyBy('serial_id');
            $serialIds = $this->serialIds($return->serial_ids);
            $quantity = (int) $return->quantity;
            $hasUnknownSerial = collect($serialIds)
                ->contains(fn (int $id): bool => ! $expectedBySerial->has($id));
            if ((bool) ($line['_partial_evidence'] ?? false) && $hasUnknownSerial) {
                // Never infer the return cost for a serial without independent
                // evidence. Proven sibling serials remain independently safe.
                return null;
            }
            $flags = [];
            if ((int) $return->product_id !== (int) $line['product_id']) {
                $flags[] = self::BLOCK_RETURN_IDENTITY;
            }
            if ($quantity < 1 || count($serialIds) !== $quantity
                || collect($serialIds)->contains(fn (int $id): bool => ! $expectedBySerial->has($id))) {
                $flags[] = self::BLOCK_RETURN_SERIALS;
            }

            $movementRows = $movements->get(
                ((int) $return->return_id).'|'.((int) $return->product_id),
                collect(),
            );
            $movement = $movementRows->count() === 1 ? $movementRows->first() : null;
            if (! is_array($movement)
                || $movement['type'] !== StockMovementService::TYPE_IN_INVOICE_RETURN
                || $movement['direction'] !== 'in'
                || $movement['qty'] !== $quantity
                || ! (($movement['ref_type'] === OrderReturn::class && $movement['ref_id'] === (int) $return->return_id)
                    || $movement['ref_code'] === (string) $return->return_code)) {
                $flags[] = self::BLOCK_RETURN_MOVEMENT;
            }

            $expectedCosts = collect($serialIds)
                ->map(fn (int $id): ?int => $expectedBySerial->has($id)
                    ? (int) $expectedBySerial->get($id)['expected_cost']
                    : null)
                ->filter(fn ($cost): bool => $cost !== null)
                ->values();
            $expectedTotal = $quantity > 0 && $expectedCosts->count() === $quantity
                ? (int) $expectedCosts->sum()
                : null;
            $expectedUnit = $expectedTotal === null ? null : (int) round($expectedTotal / $quantity);

            return [
                'return_item_id' => (int) $return->return_item_id,
                'return_id' => (int) $return->return_id,
                'return_code' => (string) $return->return_code,
                'invoice_item_id' => (int) $return->invoice_item_id,
                'product_id' => (int) $return->product_id,
                'quantity' => $quantity,
                'serial_ids' => $serialIds,
                'blocking_flags' => array_values(array_unique($flags)),
                'before' => [
                    'cost_price' => $this->money($return->cost_price),
                    'stock_movement' => $movement === null ? null : [
                        'id' => $movement['id'],
                        'unit_cost' => $movement['unit_cost'],
                        'total_cost' => $movement['total_cost'],
                    ],
                ],
                'expected' => [
                    'cost_price' => $expectedUnit,
                    'stock_movement' => $movement === null || $expectedUnit === null ? null : [
                        'id' => $movement['id'],
                        'unit_cost' => $expectedUnit,
                        // Preserve the exact sum of the individual serial costs.
                        // It may differ by one dong from rounded unit cost × qty.
                        'total_cost' => $expectedTotal,
                    ],
                ],
            ];
        })->filter()->groupBy('invoice_item_id');
    }

    /** @return array<string, int> */
    private function changeSummary(array $before, array $expected): array
    {
        $saleDocument = $this->canonicalHash($before['sale']['invoice_item_cost']) !== $this->canonicalHash($expected['sale']['invoice_item_cost'])
            || $this->canonicalHash($before['sale']['stock_movement']) !== $this->canonicalHash($expected['sale']['stock_movement']);
        $beforeLinks = collect((array) $before['sale']['serials'])->keyBy('invoice_item_serial_id');
        $expectedLinks = collect((array) $expected['sale']['serials'])->keyBy('invoice_item_serial_id');
        $linkIds = $expectedLinks->filter(function (array $serial, int|string $linkId) use ($beforeLinks): bool {
            $beforeSerial = $beforeLinks->get($linkId);

            return ! is_array($beforeSerial)
                || $this->money($beforeSerial['cost_price'] ?? null) !== $this->money($serial['cost_price'] ?? null);
        })->pluck('invoice_item_serial_id');

        $beforeReturns = collect((array) $before['returns'])->keyBy('return_item_id');
        $returnIds = collect((array) $expected['returns'])->filter(function (array $return) use ($beforeReturns): bool {
            $beforeReturn = $beforeReturns->get($return['return_item_id']);

            return ! is_array($beforeReturn)
                || $this->canonicalHash($beforeReturn) !== $this->canonicalHash($return);
        })->pluck('return_item_id');

        $beforeCurrent = collect((array) $before['current_serials'])->keyBy('serial_id');
        $currentSerialIds = collect((array) $expected['current_serials'])->filter(function (array $serial) use ($beforeCurrent): bool {
            $beforeSerial = $beforeCurrent->get($serial['serial_id']);

            return ! is_array($beforeSerial)
                || $this->canonicalHash($beforeSerial) !== $this->canonicalHash($serial);
        })->pluck('serial_id');

        return [
            'sale_document_update' => $saleDocument,
            'invoice_item_serial_ids' => $linkIds->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
            'return_item_ids' => $returnIds->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
            'current_serial_ids' => $currentSerialIds->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
            'total_updates' => ($saleDocument ? 1 : 0)
                + $linkIds->unique()->count()
                + $returnIds->unique()->count()
                + $currentSerialIds->unique()->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $repair
     * @param  Collection<int, array<string, mixed>>  $verified
     * @param  Collection<int, array<string, mixed>>  $blocked
     * @return array<string, int>
     */
    private function summary(Collection $repair, Collection $verified, Collection $blocked): array
    {
        return [
            'repair_lines' => $repair->count(),
            'verified_lines' => $verified->count(),
            'blocked_lines' => $blocked->count(),
            'invoice_item_serials_to_update' => $repair
                ->flatMap(fn (array $line): array => (array) data_get($line, 'changes.invoice_item_serial_ids', []))
                ->unique()->count(),
            'return_items_to_update' => $repair
                ->flatMap(fn (array $line): array => (array) data_get($line, 'changes.return_item_ids', []))
                ->unique()->count(),
            'current_serial_snapshots_to_update' => $repair
                ->flatMap(fn (array $line): array => (array) data_get($line, 'changes.current_serial_ids', []))
                ->unique()->count(),
            'sale_cogs_delta' => (int) $repair->sum('sale_cogs_delta'),
            'return_cogs_delta' => (int) $repair->sum('return_cogs_delta'),
            'net_report_cogs_delta' => (int) $repair->sum('net_report_cogs_delta'),
        ];
    }

    /** @return array<int, int> */
    private function serialIds(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } else {
            $decoded = json_decode((string) $value, true);
        }

        return is_array($decoded)
            ? array_values(array_unique(array_filter(array_map('intval', $decoded))))
            : [];
    }

    private function money(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
