<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * HOTFIX 24.35 — Restore serials that were left at status='dismantled'
 * after their latest repair task already completed (legacy data from
 * before the HOTFIX 24.35 code fix shipped).
 *
 * Rules — ALL must hold for a serial to be restored:
 *   1. status = 'dismantled'
 *   2. the latest repair task for the serial has status = 'completed'
 *   3. serial.invoice_id IS NULL
 *   4. serial.sold_at IS NULL
 *   5. serial.purchase_return_id IS NULL
 *
 * Apply: status → 'in_stock', repair_status → 'ready'. Affected products
 * get recomputeFromSerials() so stock_quantity matches the in_stock
 * serial count. We do NOT touch invoice_items, stock_movements, costing
 * snapshots, or task_parts.
 *
 * Default is dry-run. Pass --apply to commit changes.
 */
class RestoreCompletedDismantledSerials extends Command
{
    protected $signature = 'serials:restore-completed-dismantled
        {--apply : Actually write the changes (default is dry-run)}';

    protected $description = 'Restore dismantled serials whose latest repair task is already completed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? '⚙️  Mode: APPLY (writes will be committed)' : '🔎 Mode: DRY-RUN (no writes)');

        $candidates = $this->candidates();
        $skippedSold     = $this->countSkippedSold();
        $skippedReturned = $this->countSkippedReturned();
        $skippedPending  = $this->countSkippedPendingTask();

        $this->line('Serial candidates eligible to restore: ' . $candidates->count());
        $this->line('Skipped (serial sold):                   ' . $skippedSold);
        $this->line('Skipped (purchase-returned):             ' . $skippedReturned);
        $this->line('Skipped (latest task not completed):     ' . $skippedPending);

        if ($candidates->isEmpty()) {
            $this->info('Nothing to restore.');
            return self::SUCCESS;
        }

        $productIds = $candidates->pluck('product_id')->unique()->values();
        $this->line('Products affected: ' . $productIds->count());

        $rows = $candidates->take(20)->map(fn ($r) => [
            $r->serial_id, $r->serial_number, $r->product_id, $r->latest_task_code, $r->completed_at,
        ])->all();
        $this->table(
            ['serial_id', 'serial_number', 'product_id', 'task_code', 'task_completed_at'],
            $rows
        );
        if ($candidates->count() > 20) {
            $this->line('… and ' . ($candidates->count() - 20) . ' more.');
        }

        if (!$apply) {
            $this->warn('Dry-run only — re-run with --apply to write.');
            return self::SUCCESS;
        }

        $updated = 0;
        $recomputed = 0;
        DB::transaction(function () use ($candidates, $productIds, &$updated, &$recomputed) {
            $ids = $candidates->pluck('serial_id')->all();
            // Lock + re-check inside the transaction to defeat any race
            // with a concurrent sale / supplier return.
            $serials = SerialImei::whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($serials as $s) {
                if ($s->status !== 'dismantled') continue;
                if (!empty($s->invoice_id) || !empty($s->sold_at) || !empty($s->purchase_return_id)) continue;

                $s->status        = 'in_stock';
                $s->repair_status = 'ready';
                $s->save();
                $updated++;
            }

            foreach ($productIds as $pid) {
                $product = \App\Models\Product::find($pid);
                if ($product) {
                    $product->recomputeFromSerials();
                    $recomputed++;
                }
            }
        });

        $this->info("Updated serials: {$updated}");
        $this->info("Recomputed products: {$recomputed}");
        return self::SUCCESS;
    }

    /**
     * Pulls serials whose LATEST repair task is completed and the serial
     * has not left stock. Uses a correlated subquery so we always pick
     * the newest repair task per serial.
     */
    private function candidates(): \Illuminate\Support\Collection
    {
        $latestTaskSql = <<<'SQL'
            SELECT t2.id FROM tasks t2
            WHERE t2.serial_imei_id = serial_imeis.id
              AND t2.type = 'repair'
            ORDER BY t2.id DESC LIMIT 1
        SQL;

        return DB::table('serial_imeis')
            ->join('tasks', 'tasks.id', '=', DB::raw("({$latestTaskSql})"))
            ->select(
                'serial_imeis.id as serial_id',
                'serial_imeis.serial_number',
                'serial_imeis.product_id',
                'tasks.code as latest_task_code',
                'tasks.completed_at'
            )
            ->where('serial_imeis.status', 'dismantled')
            ->where('tasks.status', Task::STATUS_COMPLETED)
            ->whereNull('serial_imeis.invoice_id')
            ->whereNull('serial_imeis.sold_at')
            ->whereNull('serial_imeis.purchase_return_id')
            ->orderByDesc('tasks.completed_at')
            ->get();
    }

    private function countSkippedSold(): int
    {
        return SerialImei::where('status', 'dismantled')
            ->where(function ($q) {
                $q->whereNotNull('invoice_id')->orWhereNotNull('sold_at');
            })->count();
    }

    private function countSkippedReturned(): int
    {
        return SerialImei::where('status', 'dismantled')
            ->whereNotNull('purchase_return_id')->count();
    }

    private function countSkippedPendingTask(): int
    {
        $latestTaskSql = <<<'SQL'
            SELECT t2.id FROM tasks t2
            WHERE t2.serial_imei_id = serial_imeis.id
              AND t2.type = 'repair'
            ORDER BY t2.id DESC LIMIT 1
        SQL;

        return DB::table('serial_imeis')
            ->join('tasks', 'tasks.id', '=', DB::raw("({$latestTaskSql})"))
            ->where('serial_imeis.status', 'dismantled')
            ->where('tasks.status', '!=', Task::STATUS_COMPLETED)
            ->whereNull('serial_imeis.invoice_id')
            ->whereNull('serial_imeis.sold_at')
            ->whereNull('serial_imeis.purchase_return_id')
            ->count();
    }
}
