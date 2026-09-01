<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Task;
use App\Support\BusinessDateTime;
use App\Support\Status\BusinessStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only reconciliation of historical serial COGS snapshots.
 *
 * A serial sale can be proven only when there is a completed internal repair
 * ticket for the same serial before the sale.  In that case task.total_cost
 * is the independent source, and must agree with every stored sale snapshot.
 * The service deliberately does not infer a cost from the current product
 * moving average or from a later resale.
 */
final class SerialCostSnapshotAuditService
{
    public const VERIFIED_REPAIR_SNAPSHOT = 'verified_repair_snapshot';

    public const REPAIR_COST_MISMATCH = 'repair_cost_mismatch';

    public const REPAIR_COST_MISMATCH_PROTECTED_RESALE = 'repair_cost_mismatch_protected_resale';

    public const SNAPSHOT_STORAGE_MISMATCH = 'snapshot_storage_mismatch';

    public const NO_INDEPENDENT_COST_EVIDENCE = 'no_independent_cost_evidence';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function inspect(?int $productId = null, ?string $invoiceCode = null): Collection
    {
        $sales = $this->completedSaleLinks($productId, $invoiceCode);
        if ($sales->isEmpty()) {
            return collect();
        }

        $completedSalesPerSerial = $sales
            ->groupBy(fn (object $sale) => (int) $sale->serial_id)
            ->map(fn (Collection $rows) => $rows->pluck('invoice_id')->unique()->count());
        $tasksBySerial = $this->completedRepairTasks($sales->pluck('serial_id')->unique()->all())
            ->groupBy('serial_id');
        $movementsByInvoiceProduct = $this->outgoingInvoiceMovements($sales);

        $rows = $sales->map(function (object $sale) use ($completedSalesPerSerial, $tasksBySerial): array {
            $recordedSaleAt = BusinessDateTime::nullable($sale->recorded_sale_at)
                ?? Carbon::parse($sale->invoice_created_at);
            $task = $this->latestRepairTaskBeforeSale(
                $tasksBySerial->get((int) $sale->serial_id, collect()),
                $recordedSaleAt,
            );
            $expectedCost = $task ? round((float) $task['total_cost'], 0) : null;
            $serialSnapshotComparable = (int) ($sale->serial_current_invoice_id ?? 0) === (int) $sale->invoice_id;
            $mismatchTypes = [];

            if ($expectedCost !== null && $this->differs((float) $sale->link_cost_price, $expectedCost)) {
                $mismatchTypes[] = 'invoice_item_serial';
            }

            if ($serialSnapshotComparable
                && $expectedCost !== null
                && $this->differs((float) ($sale->serial_sold_cost_price ?? 0), $expectedCost)) {
                $mismatchTypes[] = 'serial_sold_cost';
            }

            if ($expectedCost === null
                && $serialSnapshotComparable
                && $this->differs((float) $sale->link_cost_price, (float) ($sale->serial_sold_cost_price ?? 0))) {
                $mismatchTypes[] = 'stored_snapshot_conflict';
            }

            return [
                'invoice_id' => (int) $sale->invoice_id,
                'invoice_code' => (string) $sale->invoice_code,
                'invoice_item_id' => (int) $sale->invoice_item_id,
                'product_id' => (int) $sale->product_id,
                'product_sku' => (string) $sale->product_sku,
                'serial_id' => (int) $sale->serial_id,
                'serial_number' => (string) $sale->serial_number,
                'sale_recorded_at' => $recordedSaleAt->toDateTimeString(),
                'invoice_item_quantity' => (int) $sale->invoice_item_quantity,
                'invoice_item_cost_price' => round((float) $sale->invoice_item_cost_price, 0),
                'invoice_item_serial_id' => (int) $sale->invoice_item_serial_id,
                'invoice_item_serial_cost_price' => round((float) $sale->link_cost_price, 0),
                'serial_sold_cost_price' => $serialSnapshotComparable
                    ? round((float) ($sale->serial_sold_cost_price ?? 0), 0)
                    : null,
                'serial_snapshot_comparable' => $serialSnapshotComparable,
                'repair_task_id' => $task['task_id'] ?? null,
                'repair_task_code' => $task['task_code'] ?? null,
                'repair_completed_at' => ($task['effective_at'] ?? null)?->toDateTimeString(),
                'expected_cost' => $expectedCost,
                'resale_protected' => (int) ($completedSalesPerSerial->get((int) $sale->serial_id) ?? 0) > 1,
                'mismatch_types' => $mismatchTypes,
                'financial_mismatch_fields' => [],
                'financial_impact' => false,
                'impact_scope' => 'unproven',
                'line_resale_protected' => false,
                'stock_movement_cost' => null,
                'classification' => self::NO_INDEPENDENT_COST_EVIDENCE,
            ];
        });

        $invoiceProductItemCounts = $rows
            ->groupBy(fn (array $row) => $this->invoiceProductKey($row['invoice_id'], $row['product_id']))
            ->map(fn (Collection $sameProductRows) => $sameProductRows->pluck('invoice_item_id')->unique()->count());

        return $this->finalizeInvoiceLineEvidence($rows, $movementsByInvoiceProduct, $invoiceProductItemCounts)
            ->sortBy(fn (array $row) => sprintf('%s|%010d|%s', $row['invoice_code'], $row['invoice_item_id'], $row['serial_number']))
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function completedSaleLinks(?int $productId, ?string $invoiceCode): Collection
    {
        $recordedSaleAt = Schema::hasColumn('invoices', 'lock_started_at')
            ? 'COALESCE(invoices.lock_started_at, invoices.created_at)'
            : 'invoices.created_at';

        $query = DB::table('invoice_item_serials')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->join('products', 'products.id', '=', 'serial_imeis.product_id')
            ->select([
                'invoice_item_serials.id as invoice_item_serial_id',
                'invoice_item_serials.cost_price as link_cost_price',
                'invoice_items.id as invoice_item_id',
                'invoice_items.quantity as invoice_item_quantity',
                'invoice_items.cost_price as invoice_item_cost_price',
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.status as invoice_status',
                'invoices.created_at as invoice_created_at',
                'serial_imeis.id as serial_id',
                'serial_imeis.serial_number',
                'serial_imeis.product_id',
                'serial_imeis.invoice_id as serial_current_invoice_id',
                'serial_imeis.sold_cost_price as serial_sold_cost_price',
                'products.sku as product_sku',
            ])
            ->selectRaw($recordedSaleAt.' as recorded_sale_at');

        if ($productId !== null) {
            $query->where('serial_imeis.product_id', $productId);
        }

        if ($invoiceCode !== null && $invoiceCode !== '') {
            $query->where('invoices.code', $invoiceCode);
        }

        return $query->orderBy('invoices.id')
            ->orderBy('invoice_items.id')
            ->orderBy('invoice_item_serials.id')
            ->get()
            ->filter(fn (object $sale) => BusinessStatus::isCompleted($sale->invoice_status))
            ->values();
    }

    /**
     * @param  array<int, int|string>  $serialIds
     * @return Collection<int, array<string, mixed>>
     */
    private function completedRepairTasks(array $serialIds): Collection
    {
        $serialIds = array_values(array_unique(array_filter(array_map('intval', $serialIds))));
        if ($serialIds === []) {
            return collect();
        }

        return DB::table('tasks')
            ->whereIn('serial_imei_id', $serialIds)
            ->where('type', Task::TYPE_REPAIR)
            ->select([
                'id',
                'code',
                'serial_imei_id',
                'status',
                'total_cost',
                'completed_at',
                'created_at',
            ])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (object $task) => BusinessStatus::isCompleted($task->status))
            ->map(function (object $task): array {
                return [
                    'task_id' => (int) $task->id,
                    'task_code' => (string) $task->code,
                    'serial_id' => (int) $task->serial_imei_id,
                    'total_cost' => round((float) $task->total_cost, 0),
                    'effective_at' => BusinessDateTime::nullable($task->completed_at)
                        ?? Carbon::parse($task->created_at),
                ];
            })
            ->filter(fn (array $task) => $task['total_cost'] > 0)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function latestRepairTaskBeforeSale(Collection $tasks, Carbon $recordedSaleAt): ?array
    {
        return $tasks
            ->filter(fn (array $task) => $task['effective_at']->lte($recordedSaleAt))
            ->sortBy(fn (array $task) => sprintf('%s|%010d', $task['effective_at']->format('Y-m-d H:i:s.u'), $task['task_id']))
            ->last();
    }

    /**
     * @param  Collection<int, object>  $sales
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function outgoingInvoiceMovements(Collection $sales): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $invoiceIds = $sales->pluck('invoice_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $invoiceIdByCode = $sales->mapWithKeys(fn (object $sale) => [(string) $sale->invoice_code => (int) $sale->invoice_id]);
        $invoiceCodes = $invoiceIdByCode->keys()->all();
        if ($invoiceIds === [] || $invoiceCodes === []) {
            return collect();
        }

        return DB::table('stock_movements')
            ->where('type', StockMovementService::TYPE_OUT_INVOICE)
            ->where(function ($query) use ($invoiceIds, $invoiceCodes): void {
                $query->where(function ($invoiceQuery) use ($invoiceIds): void {
                    $invoiceQuery->where('ref_type', Invoice::class)
                        ->whereIn('ref_id', $invoiceIds);
                })->orWhereIn('ref_code', $invoiceCodes);
            })
            ->select([
                'id',
                'product_id',
                'unit_cost',
                'ref_type',
                'ref_id',
                'ref_code',
            ])
            ->get()
            ->map(function (object $movement) use ($invoiceIdByCode, $invoiceIds): ?array {
                $invoiceId = null;
                if ((string) $movement->ref_type === Invoice::class
                    && in_array((int) $movement->ref_id, $invoiceIds, true)) {
                    $invoiceId = (int) $movement->ref_id;
                } elseif ($invoiceIdByCode->has((string) $movement->ref_code)) {
                    $invoiceId = (int) $invoiceIdByCode->get((string) $movement->ref_code);
                }

                if ($invoiceId === null) {
                    return null;
                }

                return [
                    'movement_id' => (int) $movement->id,
                    'invoice_id' => $invoiceId,
                    'product_id' => (int) $movement->product_id,
                    'unit_cost' => round((float) $movement->unit_cost, 0),
                ];
            })
            ->filter()
            ->groupBy(fn (array $movement) => $this->invoiceProductKey($movement['invoice_id'], $movement['product_id']));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $movementsByInvoiceProduct
     * @param  Collection<string, int>  $invoiceProductItemCounts
     * @return Collection<int, array<string, mixed>>
     */
    private function finalizeInvoiceLineEvidence(
        Collection $rows,
        Collection $movementsByInvoiceProduct,
        Collection $invoiceProductItemCounts,
    ): Collection {
        return $rows->groupBy('invoice_item_id')
            ->flatMap(function (Collection $lineRows) use ($movementsByInvoiceProduct, $invoiceProductItemCounts): Collection {
                $first = $lineRows->first();
                $hasCompleteTaskEvidence = $lineRows->count() === (int) $first['invoice_item_quantity']
                    && $lineRows->every(fn (array $row) => $row['expected_cost'] !== null);
                $expectedLineCost = $hasCompleteTaskEvidence
                    ? round($lineRows->sum('expected_cost') / max(1, (int) $first['invoice_item_quantity']), 0)
                    : null;
                $lineMismatch = $expectedLineCost !== null
                    && $this->differs((float) $first['invoice_item_cost_price'], $expectedLineCost);

                $invoiceProductKey = $this->invoiceProductKey((int) $first['invoice_id'], (int) $first['product_id']);
                $movementRows = $movementsByInvoiceProduct->get(
                    $invoiceProductKey,
                    collect(),
                );
                $movement = (int) ($invoiceProductItemCounts->get($invoiceProductKey) ?? 0) === 1
                    && $movementRows->count() === 1
                    ? $movementRows->first()
                    : null;
                $movementMismatch = $movement !== null
                    && $expectedLineCost !== null
                    && $this->differs((float) $movement['unit_cost'], $expectedLineCost);
                $lineResaleProtected = $lineRows->contains(
                    fn (array $row) => (bool) $row['resale_protected'],
                );

                return $lineRows->map(function (array $row) use ($expectedLineCost, $lineMismatch, $movement, $movementMismatch, $lineResaleProtected): array {
                    $mismatchTypes = $row['mismatch_types'];
                    $financialMismatchFields = [];
                    if ($lineMismatch) {
                        $mismatchTypes[] = 'invoice_item';
                        $financialMismatchFields[] = 'invoice_item';
                    }
                    if ($movementMismatch) {
                        $mismatchTypes[] = 'stock_movement';
                        $financialMismatchFields[] = 'stock_movement';
                    }
                    $mismatchTypes = array_values(array_unique($mismatchTypes));
                    $row['expected_invoice_item_cost'] = $expectedLineCost;
                    $row['stock_movement_cost'] = $movement['unit_cost'] ?? null;
                    $row['stock_movement_id'] = $movement['movement_id'] ?? null;
                    $row['mismatch_types'] = $mismatchTypes;
                    $row['financial_mismatch_fields'] = $financialMismatchFields;
                    $row['financial_impact'] = $financialMismatchFields !== [];
                    $row['line_resale_protected'] = $lineResaleProtected;
                    $row['impact_scope'] = $financialMismatchFields === []
                        ? ($row['expected_cost'] !== null ? 'serial_snapshot_only' : 'unproven')
                        : ($lineResaleProtected ? 'protected_historical_financial' : 'document_and_cogs');
                    $row['classification'] = $this->classificationFor($row);

                    return $row;
                });
            })
            ->values();
    }

    /** @param array<string, mixed> $row */
    private function classificationFor(array $row): string
    {
        if ($row['expected_cost'] !== null) {
            if ($row['mismatch_types'] === []) {
                return self::VERIFIED_REPAIR_SNAPSHOT;
            }

            return $row['resale_protected']
                ? self::REPAIR_COST_MISMATCH_PROTECTED_RESALE
                : self::REPAIR_COST_MISMATCH;
        }

        return $row['mismatch_types'] === []
            ? self::NO_INDEPENDENT_COST_EVIDENCE
            : self::SNAPSHOT_STORAGE_MISMATCH;
    }

    private function invoiceProductKey(int $invoiceId, int $productId): string
    {
        return $invoiceId.'|'.$productId;
    }

    private function differs(float $left, float $right): bool
    {
        return round($left, 0) !== round($right, 0);
    }
}
