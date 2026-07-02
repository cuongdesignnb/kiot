<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCancelledInvoiceLinks extends Command
{
    protected $signature = 'serials:cleanup-cancelled-invoice-links
        {--product= : Product ID or SKU}
        {--apply : Actually delete eligible canceled links}
        {--dry-run : Explicit dry-run; cannot be combined with --apply}';

    protected $description = 'Delete canceled invoice serial links only when a valid completed replacement exists.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');
            return self::FAILURE;
        }

        $product = $this->resolveProduct($this->option('product'));
        if ($this->option('product') && !$product) {
            $this->error('Product not found.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $candidates = $this->candidates($product?->id);

        $this->line(($apply ? 'APPLY' : 'DRY-RUN') . ': canceled invoice serial links eligible for cleanup: ' . count($candidates));

        if ($candidates) {
            $this->table(
                ['delete_link_id', 'serial_id', 'serial_number', 'canceled_invoice', 'kept_completed_invoice'],
                array_slice(array_map(fn ($row) => [
                    $row['delete_link_id'],
                    $row['serial_id'],
                    $row['serial_number'],
                    $row['canceled_invoice_id'] . ' / ' . $row['canceled_invoice_code'],
                    $row['completed_invoice_id'] . ' / ' . $row['completed_invoice_code'],
                ], $candidates), 0, 50)
            );
        }

        if (!$apply) {
            $this->warn('Dry-run only. Re-run with --apply to delete.');
            return self::SUCCESS;
        }

        $deleted = 0;
        DB::transaction(function () use ($candidates, &$deleted) {
            foreach ($candidates as $candidate) {
                $deleted += DB::table('invoice_item_serials')
                    ->where('id', $candidate['delete_link_id'])
                    ->delete();
            }
        });

        $this->info("Deleted links: {$deleted}");

        return self::SUCCESS;
    }

    private function resolveProduct(?string $productOpt): ?Product
    {
        if (!$productOpt) {
            return null;
        }

        return Product::where('id', $productOpt)->orWhere('sku', $productOpt)->first();
    }

    private function candidates(?int $productId): array
    {
        $query = DB::table('invoice_item_serials')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->select(
                'invoice_item_serials.id as link_id',
                'invoice_item_serials.serial_imei_id',
                'serial_imeis.serial_number',
                'serial_imeis.invoice_id as serial_invoice_id',
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.status as invoice_status'
            );

        if ($productId) {
            $query->where('serial_imeis.product_id', $productId);
        }

        $rows = $query->get();
        $bySerial = $rows->groupBy('serial_imei_id');
        $candidates = [];

        foreach ($bySerial as $serialId => $links) {
            $completed = $links->first(fn ($row) => BusinessStatus::isCompleted($row->invoice_status)
                && (int) $row->serial_invoice_id === (int) $row->invoice_id);

            if (!$completed) {
                continue;
            }

            foreach ($links as $link) {
                if (!BusinessStatus::isCancelled($link->invoice_status)) {
                    continue;
                }

                $candidates[] = [
                    'delete_link_id' => (int) $link->link_id,
                    'serial_id' => (int) $serialId,
                    'serial_number' => $link->serial_number,
                    'canceled_invoice_id' => (int) $link->invoice_id,
                    'canceled_invoice_code' => $link->invoice_code,
                    'completed_invoice_id' => (int) $completed->invoice_id,
                    'completed_invoice_code' => $completed->invoice_code,
                ];
            }
        }

        return $candidates;
    }
}
