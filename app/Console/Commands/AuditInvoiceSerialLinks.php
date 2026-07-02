<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditInvoiceSerialLinks extends Command
{
    protected $signature = 'serials:audit-invoice-links
        {--product= : Product ID or SKU}';

    protected $description = 'Audit invoice_item_serials for duplicate and canceled invoice links.';

    public function handle(): int
    {
        $product = $this->resolveProduct($this->option('product'));
        if ($this->option('product') && !$product) {
            $this->error('Product not found.');
            return self::FAILURE;
        }

        $rows = $this->linkRows($product?->id);
        $bySerial = $rows->groupBy('serial_imei_id');

        $duplicates = [];
        $canceledCandidates = [];
        $multiCompleted = [];

        foreach ($bySerial as $serialId => $links) {
            if ($links->count() > 1) {
                $duplicates[] = $this->summaryRow($serialId, $links);
            }

            $completedInvoiceIds = $links
                ->filter(fn ($row) => BusinessStatus::isCompleted($row->invoice_status))
                ->pluck('invoice_id')
                ->unique()
                ->values();

            if ($completedInvoiceIds->count() > 1) {
                $multiCompleted[] = $this->summaryRow($serialId, $links);
            }

            foreach ($links as $link) {
                if (BusinessStatus::isCancelled($link->invoice_status)) {
                    $canceledCandidates[] = [
                        $link->link_id,
                        $link->serial_imei_id,
                        $link->serial_number,
                        $link->invoice_id,
                        $link->invoice_code,
                        $link->invoice_status,
                    ];
                }
            }
        }

        $this->line('Total serial links: ' . $rows->count());
        $this->line('Duplicate serials: ' . count($duplicates));
        $this->line('Serials linked to multiple completed invoices: ' . count($multiCompleted));
        $this->line('Canceled invoice link candidates: ' . count($canceledCandidates));

        if ($duplicates) {
            $this->table(['serial_id', 'serial_number', 'product_id', 'link_ids', 'invoice_ids', 'invoice_statuses'], array_slice($duplicates, 0, 50));
        }

        if ($canceledCandidates) {
            $this->table(['link_id', 'serial_id', 'serial_number', 'invoice_id', 'invoice_code', 'invoice_status'], array_slice($canceledCandidates, 0, 50));
        }

        return count($multiCompleted) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveProduct(?string $productOpt): ?Product
    {
        if (!$productOpt) {
            return null;
        }

        return Product::where('id', $productOpt)->orWhere('sku', $productOpt)->first();
    }

    private function linkRows(?int $productId)
    {
        $query = DB::table('invoice_item_serials')
            ->join('serial_imeis', 'serial_imeis.id', '=', 'invoice_item_serials.serial_imei_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_serials.invoice_item_id')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->select(
                'invoice_item_serials.id as link_id',
                'invoice_item_serials.serial_imei_id',
                'serial_imeis.serial_number',
                'serial_imeis.product_id',
                'serial_imeis.invoice_id as serial_invoice_id',
                'invoice_items.id as invoice_item_id',
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.status as invoice_status'
            );

        if ($productId) {
            $query->where('serial_imeis.product_id', $productId);
        }

        return $query->orderBy('serial_imeis.product_id')
            ->orderBy('serial_imeis.serial_number')
            ->orderBy('invoice_item_serials.id')
            ->get();
    }

    private function summaryRow(int|string $serialId, $links): array
    {
        $first = $links->first();

        return [
            $serialId,
            $first->serial_number,
            $first->product_id,
            $links->pluck('link_id')->implode(','),
            $links->pluck('invoice_id')->implode(','),
            $links->pluck('invoice_status')->implode(' | '),
        ];
    }
}
