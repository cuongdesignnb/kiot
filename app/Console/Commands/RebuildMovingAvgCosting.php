<?php

namespace App\Console\Commands;

use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskPart;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RebuildMovingAvgCosting extends Command
{
    protected $signature = 'costing:rebuild-moving-avg
        {--product= : Product ID or SKU}
        {--all : Rebuild all products}
        {--mismatched-serials : Rebuild only serial products whose aggregate differs from in-stock serials}
        {--dry-run : Explicit dry-run; cannot be combined with --apply}
        {--apply : Actually write changes}';

    protected $description = 'Safely rebuild weighted moving-average costing from source tables.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRunOption = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $mismatchedSerials = (bool) $this->option('mismatched-serials');
        $productOpt = $this->option('product');

        if ($apply && $dryRunOption) {
            $this->error('Use either --apply or --dry-run, not both.');
            return self::FAILURE;
        }

        if (!$all && !$mismatchedSerials && !$productOpt) {
            $this->error('Provide --product=ID|SKU, --mismatched-serials, or --all.');
            return self::FAILURE;
        }

        $products = $this->productsForRun($productOpt, $all, $mismatchedSerials);
        if ($products->isEmpty()) {
            $this->error('No matching products found.');
            return self::FAILURE;
        }

        $dry = !$apply;
        $this->info(($dry ? '[DRY-RUN] ' : '[APPLY] ') . 'Rebuild ' . $products->count() . ' product(s)');

        $totals = [
            'products' => 0,
            'products_applied' => 0,
            'hard_errors' => 0,
            'warnings' => 0,
            'invoice_items_updated' => 0,
            'invoice_item_serials_updated' => 0,
            'serials_updated' => 0,
        ];

        foreach ($products as $product) {
            $stats = $this->rebuildOne($product, $apply);
            $totals['products']++;
            $totals['products_applied'] += $stats['product_applied'];
            $totals['hard_errors'] += $stats['hard_errors'];
            $totals['warnings'] += $stats['warnings'];
            $totals['invoice_items_updated'] += $stats['invoice_items_updated'];
            $totals['invoice_item_serials_updated'] += $stats['invoice_item_serials_updated'];
            $totals['serials_updated'] += $stats['serials_updated'];
        }

        $this->newLine();
        $this->info('Summary');
        $this->line('Products scanned: ' . $totals['products']);
        $this->line('Products applied: ' . $totals['products_applied']);
        $this->line('Hard errors: ' . $totals['hard_errors']);
        $this->line('Warnings: ' . $totals['warnings']);
        $this->line('Invoice items changed: ' . $totals['invoice_items_updated']);
        $this->line('Invoice item serials changed: ' . $totals['invoice_item_serials_updated']);
        $this->line('Serial sold costs changed: ' . $totals['serials_updated']);

        if ($dry) {
            $this->warn('DRY-RUN: no database writes were made. Re-run with --apply to write.');
        }

        return $totals['hard_errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function productsForRun(?string $productOpt, bool $all, bool $mismatchedSerials): Collection
    {
        if ($productOpt) {
            return Product::query()
                ->where(fn ($q) => $q->where('id', $productOpt)->orWhere('sku', $productOpt))
                ->orderBy('id')
                ->get();
        }

        $query = Product::query()->orderBy('id');
        if ($mismatchedSerials) {
            $query->where('has_serial', true);
        }

        $products = $query->get();
        if (!$mismatchedSerials) {
            return $products;
        }

        return $products->filter(fn (Product $product) => $this->serialAggregateDiffers($product))->values();
    }

    private function serialAggregateDiffers(Product $product): bool
    {
        $aggregate = $this->serialStockAggregate($product->id);

        return (int) $product->stock_quantity !== $aggregate['qty']
            || round((float) $product->inventory_total_cost, 0) !== round($aggregate['total'], 0)
            || round((float) $product->cost_price, 0) !== round($aggregate['avg'], 0);
    }

    private function rebuildOne(Product $product, bool $apply): array
    {
        $stats = [
            'product_applied' => 0,
            'hard_errors' => 0,
            'warnings' => 0,
            'invoice_items_updated' => 0,
            'invoice_item_serials_updated' => 0,
            'serials_updated' => 0,
        ];

        $warnings = [];
        $hardErrors = [];
        $events = $this->buildTimeline($product, $warnings, $hardErrors);

        if (empty($events) && !$product->has_serial) {
            $this->line(sprintf('  - #%d %s: no source history, skipped.', $product->id, $product->sku));
            return $stats;
        }

        $result = $this->simulate($product, $events, $warnings);
        $hardErrors = array_values(array_unique($hardErrors));
        $warnings = array_values(array_unique($warnings));
        $stats['hard_errors'] = count($hardErrors);
        $stats['warnings'] = count($warnings);

        $this->newLine();
        $this->line(sprintf(
            '  - #%d %s: timeline qty=%d total=%s avg=%s; final qty=%d total=%s avg=%s; old qty=%d total=%s avg=%s',
            $product->id,
            $product->sku,
            $result['timeline_qty'],
            number_format($result['timeline_total'], 2),
            number_format($result['timeline_avg'], 2),
            $result['final_qty'],
            number_format($result['final_total'], 2),
            number_format($result['final_avg'], 2),
            (int) $product->stock_quantity,
            number_format((float) $product->inventory_total_cost, 2),
            number_format((float) $product->cost_price, 2)
        ));

        foreach ($warnings as $warning) {
            $this->warn('    WARNING: ' . $warning);
        }

        foreach ($hardErrors as $error) {
            $this->error('    HARD ERROR: ' . $error);
        }

        $this->printDiffPreview($result);

        if ($hardErrors) {
            if ($apply) {
                $this->error('    Apply skipped for this product because hard errors were found.');
            }
            return $stats;
        }

        $stats['invoice_items_updated'] = count($result['invoice_item_diffs']);
        $stats['invoice_item_serials_updated'] = count($result['invoice_item_serial_diffs']);
        $stats['serials_updated'] = count($result['serial_diffs']);

        if (!$apply) {
            return $stats;
        }

        DB::transaction(function () use ($product, $result, &$stats) {
            foreach ($result['invoice_item_diffs'] as $diff) {
                InvoiceItem::where('id', $diff['id'])->update(['cost_price' => $diff['new']]);
            }

            foreach ($result['invoice_item_serial_diffs'] as $diff) {
                InvoiceItemSerial::where('id', $diff['id'])->update(['cost_price' => $diff['new']]);
            }

            foreach ($result['serial_diffs'] as $diff) {
                SerialImei::where('id', $diff['id'])->update(['sold_cost_price' => $diff['new']]);
            }

            Product::where('id', $product->id)->update([
                'stock_quantity' => $result['final_qty'],
                'inventory_total_cost' => round($result['final_total'], 2),
                'cost_price' => round($result['final_avg'], 2),
            ]);

            $stats['product_applied'] = 1;
        });

        $this->info('    Applied product rebuild.');

        return $stats;
    }

    private function simulate(Product $product, array $events, array &$warnings): array
    {
        $qty = 0;
        $total = 0.0;
        $invoiceCogsMap = [];
        $soldSerials = [];

        $invoiceItemDiffs = [];
        $invoiceItemSerialDiffs = [];
        $serialDiffs = [];

        foreach ($events as $event) {
            switch ($event['kind']) {
                case 'purchase':
                    $qty += $event['qty'];
                    $total += $event['qty'] * $event['unit_cost'];
                    break;

                case 'sale':
                    if ($product->has_serial) {
                        $cogsTotal = array_sum($event['serial_costs']);
                        $unitCost = $event['qty'] > 0 ? round($cogsTotal / $event['qty'], 0) : 0;

                        $this->addInvoiceItemDiff($invoiceItemDiffs, $event['invoice_item_id'], $event['old_item_cost'], $unitCost);

                        foreach ($event['serials'] as $serial) {
                            $soldSerials[$serial['id']] = true;
                            $this->addSerialDiff($serialDiffs, $serial['id'], $serial['old_sold_cost_price'], $serial['cost_price']);
                            if ($serial['invoice_item_serial_id']) {
                                $this->addInvoiceItemSerialDiff(
                                    $invoiceItemSerialDiffs,
                                    $serial['invoice_item_serial_id'],
                                    $serial['old_link_cost_price'],
                                    $serial['cost_price']
                                );
                            }
                        }
                    } else {
                        $unitCost = $qty > 0 ? round($total / $qty, 0) : 0;
                        $cogsTotal = $unitCost * $event['qty'];
                        $this->addInvoiceItemDiff($invoiceItemDiffs, $event['invoice_item_id'], $event['old_item_cost'], $unitCost);
                    }

                    $total = max(0, $total - $cogsTotal);
                    $qty = max(0, $qty - $event['qty']);
                    $invoiceCogsMap[$event['invoice_id']] = $unitCost;
                    break;

                case 'sale_return':
                    $unitCost = $invoiceCogsMap[$event['invoice_id']] ?? $event['unit_cost'];
                    $total += $event['qty'] * $unitCost;
                    $qty += $event['qty'];
                    break;

                case 'purchase_return':
                    $total = max(0, $total - $event['qty'] * $event['unit_cost']);
                    $qty = max(0, $qty - $event['qty']);
                    break;

                case 'stock_take':
                    $unitCost = $qty > 0 ? ($total / $qty) : 0;
                    $qty += $event['diff'];
                    $total += $event['diff'] * $unitCost;
                    $qty = max(0, $qty);
                    $total = max(0, $total);
                    break;

                case 'damage':
                    $unitCost = $qty > 0 ? ($total / $qty) : 0;
                    $total = max(0, $total - $unitCost * $event['qty']);
                    $qty = max(0, $qty - $event['qty']);
                    break;

                case 'part_export':
                    $qty = max(0, $qty - $event['qty']);
                    $total = max(0, $total - $event['cost']);
                    break;

                case 'part_import':
                    $qty += $event['qty'];
                    $total += $event['cost'];
                    break;

                case 'repair_on_machine_in':
                    if (!empty($event['serial_imei_id']) && isset($soldSerials[$event['serial_imei_id']])) {
                        $warnings[] = 'Skipped repair input cost on sold serial #' . $event['serial_imei_id'];
                        break;
                    }
                    $total += $event['cost'];
                    break;

                case 'repair_on_machine_out':
                    if (!empty($event['serial_imei_id']) && isset($soldSerials[$event['serial_imei_id']])) {
                        $warnings[] = 'Skipped repair output cost on sold serial #' . $event['serial_imei_id'];
                        break;
                    }
                    $total = max(0, $total - $event['cost']);
                    break;
            }
        }

        $timelineAvg = $qty > 0 ? round($total / $qty, 2) : 0.0;
        $finalQty = $qty;
        $finalTotal = $total;
        $finalAvg = $timelineAvg;

        if ($product->has_serial) {
            $serialAggregate = $this->serialStockAggregate($product->id);
            $finalQty = $serialAggregate['qty'];
            $finalTotal = $serialAggregate['total'];
            $finalAvg = $serialAggregate['avg'];

            if ($qty !== $finalQty || round($total, 0) !== round($finalTotal, 0)) {
                $warnings[] = sprintf(
                    'Timeline aggregate differs from serial state; final product aggregate will use in-stock serials (timeline qty=%d total=%s, serial qty=%d total=%s).',
                    $qty,
                    number_format($total, 0),
                    $finalQty,
                    number_format($finalTotal, 0)
                );
            }
        }

        return [
            'timeline_qty' => $qty,
            'timeline_total' => round($total, 2),
            'timeline_avg' => $timelineAvg,
            'final_qty' => $finalQty,
            'final_total' => round($finalTotal, 2),
            'final_avg' => $finalAvg,
            'invoice_item_diffs' => array_values($invoiceItemDiffs),
            'invoice_item_serial_diffs' => array_values($invoiceItemSerialDiffs),
            'serial_diffs' => array_values($serialDiffs),
        ];
    }

    private function buildTimeline(Product $product, array &$warnings, array &$hardErrors): array
    {
        $events = [];

        if ($product->has_serial) {
            $this->validateSerialProductData($product, $warnings, $hardErrors);
        }

        $purchaseItems = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $product->id)
            ->orderBy('purchases.created_at')
            ->orderBy('purchases.id')
            ->get(['purchase_items.*', 'purchases.created_at as ts', 'purchases.status as source_status']);

        foreach ($purchaseItems as $row) {
            if (!BusinessStatus::isCompleted($row->source_status) || $row->quantity <= 0) {
                continue;
            }

            $events[] = [
                'kind' => 'purchase',
                'ts' => $row->ts,
                'sort_key' => $this->sortKey($row->ts, 0),
                'qty' => (int) $row->quantity,
                'unit_cost' => (float) ($row->unit_cost_allocated ?? $row->price ?? 0),
            ];
        }

        $invoiceItems = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->orderBy('invoices.created_at')
            ->orderBy('invoices.id')
            ->get([
                'invoice_items.*',
                'invoices.created_at as ts',
                'invoices.status as invoice_status',
                'invoices.code as invoice_code',
            ]);

        foreach ($invoiceItems as $row) {
            if ($row->quantity <= 0) {
                continue;
            }

            if (BusinessStatus::isCancelled($row->invoice_status)) {
                if ((float) $row->cost_price !== 0.0) {
                    $warnings[] = "Canceled invoice item #{$row->id} has non-zero cost_price and is ignored.";
                }
                continue;
            }

            if (!BusinessStatus::isCompleted($row->invoice_status)) {
                continue;
            }

            $event = [
                'kind' => 'sale',
                'ts' => $row->ts,
                'sort_key' => $this->sortKey($row->ts, 4),
                'qty' => (int) $row->quantity,
                'invoice_id' => (int) $row->invoice_id,
                'invoice_item_id' => (int) $row->id,
                'old_item_cost' => (float) $row->cost_price,
            ];

            if ($product->has_serial) {
                $serials = $this->resolveSaleSerials($product, $row, $warnings, $hardErrors);
                $event['qty'] = count($serials);
                $event['serials'] = $serials;
                $event['serial_costs'] = array_map(fn ($serial) => (float) $serial['cost_price'], $serials);
            }

            $events[] = $event;
        }

        $returnItems = DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('return_items.product_id', $product->id)
            ->orderBy('returns.created_at')
            ->orderBy('returns.id')
            ->get(['return_items.*', 'returns.created_at as ts', 'returns.invoice_id', 'returns.status as source_status']);

        foreach ($returnItems as $row) {
            if ($row->quantity <= 0 || !BusinessStatus::isReturnCompleted($row->source_status)) {
                continue;
            }

            $events[] = [
                'kind' => 'sale_return',
                'ts' => $row->ts,
                'sort_key' => $this->sortKey($row->ts, 6),
                'qty' => (int) $row->quantity,
                'unit_cost' => (float) ($row->cost_price ?? 0),
                'invoice_id' => $row->invoice_id,
            ];
        }

        $purchaseReturnItems = DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.product_id', $product->id)
            ->orderBy('purchase_returns.created_at')
            ->orderBy('purchase_returns.id')
            ->get(['purchase_return_items.*', 'purchase_returns.created_at as ts', 'purchase_returns.status as source_status']);

        foreach ($purchaseReturnItems as $row) {
            if ($row->quantity <= 0 || !BusinessStatus::isCompleted($row->source_status)) {
                continue;
            }

            $events[] = [
                'kind' => 'purchase_return',
                'ts' => $row->ts,
                'sort_key' => $this->sortKey($row->ts, 7),
                'qty' => (int) $row->quantity,
                'unit_cost' => (float) ($row->cost_price ?? $row->price ?? 0),
            ];
        }

        if (DB::getSchemaBuilder()->hasTable('stock_take_items')) {
            $stockTakeItems = DB::table('stock_take_items')
                ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
                ->where('stock_take_items.product_id', $product->id)
                ->orderBy('stock_takes.balanced_date')
                ->orderBy('stock_takes.id')
                ->get(['stock_take_items.*', 'stock_takes.balanced_date as ts', 'stock_takes.status as source_status']);

            foreach ($stockTakeItems as $row) {
                if (!BusinessStatus::isBalanced($row->source_status)) {
                    continue;
                }

                $diff = (int) ($row->diff_qty ?? ((int) $row->actual_stock - (int) $row->system_stock));
                if ($diff === 0) {
                    continue;
                }

                $events[] = [
                    'kind' => 'stock_take',
                    'ts' => $row->ts,
                    'sort_key' => $this->sortKey($row->ts, 5),
                    'diff' => $diff,
                ];
            }
        }

        if (DB::getSchemaBuilder()->hasTable('damage_items')) {
            $damageItems = DB::table('damage_items')
                ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
                ->where('damage_items.product_id', $product->id)
                ->orderBy('damages.created_at')
                ->orderBy('damages.id')
                ->get(['damage_items.*', 'damages.created_at as ts', 'damages.status as source_status']);

            foreach ($damageItems as $row) {
                if ($row->qty <= 0 || !BusinessStatus::isCompleted($row->source_status)) {
                    continue;
                }

                $events[] = [
                    'kind' => 'damage',
                    'ts' => $row->ts,
                    'sort_key' => $this->sortKey($row->ts, 5),
                    'qty' => (int) $row->qty,
                ];
            }
        }

        if (DB::getSchemaBuilder()->hasTable('task_parts')) {
            foreach (TaskPart::where('product_id', $product->id)->orderBy('created_at')->orderBy('id')->get() as $part) {
                $isImport = ($part->direction ?? 'export') === 'import';
                $events[] = [
                    'kind' => $isImport ? 'part_import' : 'part_export',
                    'ts' => $part->created_at,
                    'sort_key' => $this->sortKey($part->created_at, 2),
                    'qty' => (int) ($part->quantity ?? 1),
                    'cost' => (float) $part->total_cost,
                ];
            }

            $taskIds = Task::where(function ($query) use ($product) {
                $query->where('product_id', $product->id)
                    ->orWhereHas('serialImei', fn ($serialQuery) => $serialQuery->where('product_id', $product->id));
            })->pluck('id')->all();

            if ($taskIds) {
                $tasksById = Task::whereIn('id', $taskIds)->get(['id', 'serial_imei_id'])->keyBy('id');
                $machineParts = TaskPart::whereIn('task_id', $taskIds)
                    ->where('product_id', '!=', $product->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get();

                foreach ($machineParts as $part) {
                    $isImport = ($part->direction ?? 'export') === 'import';
                    $task = $tasksById->get($part->task_id);
                    $events[] = [
                        'kind' => $isImport ? 'repair_on_machine_out' : 'repair_on_machine_in',
                        'ts' => $part->created_at,
                        'sort_key' => $this->sortKey($part->created_at, 2),
                        'cost' => (float) $part->total_cost,
                        'serial_imei_id' => $task?->serial_imei_id,
                    ];
                }
            }
        }

        usort($events, fn ($a, $b) => $a['sort_key'] <=> $b['sort_key']);

        return $events;
    }

    private function resolveSaleSerials(Product $product, object $invoiceItem, array &$warnings, array &$hardErrors): array
    {
        $linkRows = DB::table('invoice_item_serials')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->where('invoice_item_serials.invoice_item_id', $invoiceItem->id)
            ->get([
                'invoice_item_serials.id as link_id',
                'invoice_item_serials.serial_imei_id',
                'invoice_item_serials.cost_price as link_cost_price',
                'invoice_item_serials.serial_number as link_serial_number',
                'serial_imeis.product_id as serial_product_id',
                'serial_imeis.cost_price as serial_cost_price',
                'serial_imeis.sold_cost_price',
                'invoices.status as invoice_status',
            ]);

        $serials = [];
        foreach ($linkRows as $row) {
            if (BusinessStatus::isCancelled($row->invoice_status)) {
                $warnings[] = "Serial link #{$row->link_id} belongs to a canceled invoice and is ignored.";
                continue;
            }

            if (!$row->serial_imei_id) {
                $hardErrors[] = "Invoice item #{$invoiceItem->id} has serial link #{$row->link_id} without serial_imei_id.";
                continue;
            }

            if ((int) $row->serial_product_id !== (int) $product->id) {
                $hardErrors[] = "Invoice item #{$invoiceItem->id} links serial #{$row->serial_imei_id} from another product.";
                continue;
            }

            $serials[(int) $row->serial_imei_id] = [
                'id' => (int) $row->serial_imei_id,
                'invoice_item_serial_id' => (int) $row->link_id,
                'cost_price' => (float) $row->serial_cost_price,
                'old_sold_cost_price' => (float) ($row->sold_cost_price ?? 0),
                'old_link_cost_price' => (float) $row->link_cost_price,
            ];
        }

        if (!$serials) {
            $fallbackSerials = SerialImei::where('product_id', $product->id)
                ->where('invoice_id', $invoiceItem->invoice_id)
                ->orderBy('id')
                ->get(['id', 'cost_price', 'sold_cost_price']);

            foreach ($fallbackSerials as $serial) {
                $serials[(int) $serial->id] = [
                    'id' => (int) $serial->id,
                    'invoice_item_serial_id' => null,
                    'cost_price' => (float) $serial->cost_price,
                    'old_sold_cost_price' => (float) ($serial->sold_cost_price ?? 0),
                    'old_link_cost_price' => 0.0,
                ];
            }
        }

        if (count($serials) !== (int) $invoiceItem->quantity) {
            $hardErrors[] = sprintf(
                'Invoice item #%d quantity mismatch: item qty=%d, resolved serial qty=%d.',
                $invoiceItem->id,
                (int) $invoiceItem->quantity,
                count($serials)
            );
        }

        return array_values($serials);
    }

    private function validateSerialProductData(Product $product, array &$warnings, array &$hardErrors): void
    {
        $links = DB::table('invoice_item_serials')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->where('serial_imeis.product_id', $product->id)
            ->get([
                'invoice_item_serials.id as link_id',
                'invoice_item_serials.serial_imei_id',
                'serial_imeis.serial_number',
                'invoices.id as invoice_id',
                'invoices.status as invoice_status',
                'invoices.code as invoice_code',
            ]);

        $completedBySerial = [];
        foreach ($links as $link) {
            if (BusinessStatus::isCancelled($link->invoice_status)) {
                $warnings[] = "Cleanup candidate: serial #{$link->serial_imei_id} ({$link->serial_number}) has canceled invoice link #{$link->link_id}.";
                continue;
            }

            if (BusinessStatus::isCompleted($link->invoice_status)) {
                $completedBySerial[(int) $link->serial_imei_id][(int) $link->invoice_id] = $link->invoice_code;
            }
        }

        foreach ($completedBySerial as $serialId => $invoiceCodes) {
            if (count($invoiceCodes) > 1) {
                $hardErrors[] = 'Serial #' . $serialId . ' is linked to multiple completed invoices: ' . implode(', ', $invoiceCodes);
            }
        }

        $soldSerials = SerialImei::where('product_id', $product->id)
            ->where('status', 'sold')
            ->get(['id', 'serial_number', 'invoice_id']);

        foreach ($soldSerials as $serial) {
            if (!$serial->invoice_id) {
                $hardErrors[] = "Sold serial #{$serial->id} ({$serial->serial_number}) has no invoice_id.";
                continue;
            }

            $invoice = DB::table('invoices')->where('id', $serial->invoice_id)->first(['id', 'status', 'code']);
            if (!$invoice || !BusinessStatus::isCompleted($invoice->status)) {
                $hardErrors[] = "Sold serial #{$serial->id} ({$serial->serial_number}) points to a non-completed invoice.";
                continue;
            }

            $hasItem = DB::table('invoice_items')
                ->where('invoice_id', $serial->invoice_id)
                ->where('product_id', $product->id)
                ->exists();
            if (!$hasItem) {
                $hardErrors[] = "Sold serial #{$serial->id} ({$serial->serial_number}) has no completed invoice item for this product.";
            }
        }
    }

    private function serialStockAggregate(int $productId): array
    {
        $row = SerialImei::where('product_id', $productId)
            ->where('status', 'in_stock')
            ->selectRaw('COUNT(*) as qty, COALESCE(SUM(cost_price), 0) as total')
            ->first();

        $qty = (int) ($row->qty ?? 0);
        $total = (float) ($row->total ?? 0);

        return [
            'qty' => $qty,
            'total' => $total,
            'avg' => $qty > 0 ? round($total / $qty, 2) : 0.0,
        ];
    }

    private function addInvoiceItemDiff(array &$diffs, int $id, float $old, float $new): void
    {
        $new = round($new, 0);
        if (round($old, 0) === $new) {
            return;
        }

        $diffs[$id] = ['id' => $id, 'old' => round($old, 0), 'new' => $new, 'diff' => $new - round($old, 0)];
    }

    private function addInvoiceItemSerialDiff(array &$diffs, int $id, float $old, float $new): void
    {
        $new = round($new, 0);
        if (round($old, 0) === $new) {
            return;
        }

        $diffs[$id] = ['id' => $id, 'old' => round($old, 0), 'new' => $new, 'diff' => $new - round($old, 0)];
    }

    private function addSerialDiff(array &$diffs, int $id, float $old, float $new): void
    {
        $new = round($new, 0);
        if (round($old, 0) === $new) {
            return;
        }

        $diffs[$id] = ['id' => $id, 'old' => round($old, 0), 'new' => $new, 'diff' => $new - round($old, 0)];
    }

    private function printDiffPreview(array $result): void
    {
        $this->line('    COGS diff preview');
        $this->line('    invoice_items: ' . count($result['invoice_item_diffs']) . ' row(s), total diff=' . number_format(array_sum(array_column($result['invoice_item_diffs'], 'diff')), 0));
        if ($result['invoice_item_diffs']) {
            $this->table(['invoice_item_id', 'old_cost', 'new_cost', 'diff'], array_slice(array_map(
                fn ($row) => [$row['id'], $row['old'], $row['new'], $row['diff']],
                $result['invoice_item_diffs']
            ), 0, 20));
        }

        $this->line('    serial_imeis.sold_cost_price: ' . count($result['serial_diffs']) . ' row(s), total diff=' . number_format(array_sum(array_column($result['serial_diffs'], 'diff')), 0));
        if ($result['serial_diffs']) {
            $this->table(['serial_id', 'old_sold_cost_price', 'new_sold_cost_price', 'diff'], array_slice(array_map(
                fn ($row) => [$row['id'], $row['old'], $row['new'], $row['diff']],
                $result['serial_diffs']
            ), 0, 20));
        }

        $this->line('    invoice_item_serials.cost_price: ' . count($result['invoice_item_serial_diffs']) . ' row(s), total diff=' . number_format(array_sum(array_column($result['invoice_item_serial_diffs'], 'diff')), 0));
    }

    private function sortKey(mixed $timestamp, int $phase): int
    {
        $time = $timestamp ? strtotime((string) $timestamp) : 0;

        return ($time * 10) + $phase;
    }
}
