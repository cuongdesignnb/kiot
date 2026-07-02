<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SerialImei;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestoreCompletedDismantledSerials extends Command
{
    protected $signature = 'serials:restore-completed-dismantled
        {--product= : Product ID or SKU}
        {--serial= : Serial/IMEI number}
        {--apply : Actually write the changes}
        {--explain : Show skipped reason detail}';

    protected $description = 'Restore dismantled serials whose latest repair task is already completed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $explain = (bool) $this->option('explain');
        $productOpt = $this->option('product');
        $serialOpt = $this->option('serial');

        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY-RUN');

        $rows = $this->serialRows($productOpt, $serialOpt, $explain);
        if ($rows->isEmpty()) {
            $this->info('No serials matched the filters.');
            return self::SUCCESS;
        }

        $classified = $rows->map(function ($row) {
            $row->skip_reason = $this->skipReason($row);
            return $row;
        });

        $candidates = $classified->filter(fn ($row) => $row->skip_reason === null)->values();
        $skipped = $classified->filter(fn ($row) => $row->skip_reason !== null)->values();

        $this->line('Serial candidates eligible to restore: ' . $candidates->count());
        $this->line('Skipped: ' . $skipped->count());

        if ($candidates->isNotEmpty()) {
            $this->table(
                ['serial_id', 'serial_number', 'product_id', 'old_status', 'old_repair_status', 'task_code', 'task_status'],
                $candidates->take(30)->map(fn ($row) => [
                    $row->serial_id,
                    $row->serial_number,
                    $row->product_id,
                    $row->status,
                    $row->repair_status,
                    $row->latest_task_code,
                    $row->latest_task_status,
                ])->all()
            );
        }

        if ($explain && $skipped->isNotEmpty()) {
            $this->table(
                ['serial_id', 'serial_number', 'product_id', 'status', 'task_code', 'task_status', 'skip_reason'],
                $skipped->take(50)->map(fn ($row) => [
                    $row->serial_id,
                    $row->serial_number,
                    $row->product_id,
                    $row->status,
                    $row->latest_task_code,
                    $row->latest_task_status,
                    $row->skip_reason,
                ])->all()
            );
        }

        if (!$apply) {
            $this->warn('Dry-run only. Re-run with --apply to write.');
            return self::SUCCESS;
        }

        $updated = 0;
        $recomputed = 0;
        $logs = [];

        DB::transaction(function () use ($candidates, &$updated, &$recomputed, &$logs) {
            $productIds = [];

            foreach ($candidates as $candidate) {
                $serial = SerialImei::where('id', $candidate->serial_id)->lockForUpdate()->first();
                if (!$serial) {
                    continue;
                }

                $latestTask = $this->latestRepairTask((int) $serial->id);
                if ($serial->status !== 'dismantled'
                    || $serial->invoice_id
                    || $serial->sold_at
                    || $serial->purchase_return_id
                    || !$latestTask
                    || !BusinessStatus::isCompleted($latestTask->status)
                ) {
                    continue;
                }

                $oldStatus = $serial->status;
                $oldRepairStatus = $serial->repair_status;
                $serial->status = 'in_stock';
                $serial->repair_status = 'ready';
                $serial->save();

                $updated++;
                $productIds[$serial->product_id] = true;
                $logs[] = [
                    $serial->id,
                    $serial->serial_number,
                    $oldStatus,
                    $serial->status,
                    $oldRepairStatus,
                    $serial->repair_status,
                    $latestTask->code,
                ];
            }

            foreach (array_keys($productIds) as $productId) {
                $product = Product::find($productId);
                if ($product) {
                    $product->recomputeFromSerials();
                    $recomputed++;
                }
            }
        });

        $this->info("Updated serials: {$updated}");
        $this->info("Recomputed products: {$recomputed}");

        if ($logs) {
            $this->table(
                ['serial_id', 'serial_number', 'old_status', 'new_status', 'old_repair_status', 'new_repair_status', 'latest_task'],
                $logs
            );
        }

        return self::SUCCESS;
    }

    private function serialRows(?string $productOpt, ?string $serialOpt, bool $explain): Collection
    {
        $latestTaskSql = <<<'SQL'
            SELECT t2.id FROM tasks t2
            WHERE t2.serial_imei_id = serial_imeis.id
              AND t2.type = 'repair'
            ORDER BY t2.id DESC LIMIT 1
        SQL;

        $query = DB::table('serial_imeis')
            ->leftJoin('tasks', 'tasks.id', '=', DB::raw("({$latestTaskSql})"))
            ->select(
                'serial_imeis.id as serial_id',
                'serial_imeis.serial_number',
                'serial_imeis.product_id',
                'serial_imeis.status',
                'serial_imeis.repair_status',
                'serial_imeis.invoice_id',
                'serial_imeis.sold_at',
                'serial_imeis.purchase_return_id',
                'tasks.id as latest_task_id',
                'tasks.code as latest_task_code',
                'tasks.status as latest_task_status',
                'tasks.completed_at'
            );

        if ($productOpt) {
            $query->join('products', 'products.id', '=', 'serial_imeis.product_id')
                ->where(fn ($q) => $q->where('products.id', $productOpt)->orWhere('products.sku', $productOpt));
        }

        if ($serialOpt) {
            $query->where('serial_imeis.serial_number', $serialOpt);
        } elseif (!$explain) {
            $query->where('serial_imeis.status', 'dismantled');
        }

        return $query->orderByDesc('tasks.completed_at')
            ->orderByDesc('serial_imeis.id')
            ->get();
    }

    private function skipReason(object $row): ?string
    {
        if (!$row->latest_task_id) {
            return 'no repair task';
        }

        if ($row->invoice_id || $row->sold_at) {
            return 'serial sold';
        }

        if ($row->purchase_return_id) {
            return 'serial purchase-returned';
        }

        if ($row->status === 'in_stock') {
            return 'already in_stock';
        }

        if ($row->status !== 'dismantled') {
            return 'status not dismantled';
        }

        if (!BusinessStatus::isCompleted($row->latest_task_status)) {
            return 'latest task not completed';
        }

        return null;
    }

    private function latestRepairTask(int $serialId): ?object
    {
        return DB::table('tasks')
            ->where('serial_imei_id', $serialId)
            ->where('type', 'repair')
            ->orderByDesc('id')
            ->first(['id', 'code', 'status']);
    }
}
