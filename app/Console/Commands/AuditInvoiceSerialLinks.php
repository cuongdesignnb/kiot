<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\InvoiceItemSerialEvidenceService;
use App\Services\SerialLifecycleInspectionService;
use App\Support\Status\BusinessStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditInvoiceSerialLinks extends Command
{
    protected $signature = 'serials:audit-invoice-links
        {--product= : Product ID or SKU}';

    protected $description = 'Read-only audit of serial sale/return/resale lifecycles and canceled invoice links.';

    public function handle(): int
    {
        $product = $this->resolveProduct($this->option('product'));
        if ($this->option('product') && ! $product) {
            $this->error('Product not found.');

            return self::FAILURE;
        }

        $rows = $this->linkRows($product?->id);
        $bySerial = $rows->groupBy('serial_imei_id');

        $multipleLinks = [];
        $canceledCandidates = [];
        $multiCompleted = [];

        foreach ($bySerial as $serialId => $links) {
            if ($links->count() > 1) {
                $multipleLinks[] = $this->summaryRow($serialId, $links);
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

        $inspector = app(SerialLifecycleInspectionService::class);
        $inspections = $product
            ? $inspector->inspectProduct($product->id)
            : $inspector->inspectAll();

        $byClassification = $inspections->groupBy('classification');
        $missingNormalizedEvidence = $this->missingNormalizedEvidenceRows($product?->id);
        $byEvidenceClassification = $missingNormalizedEvidence->groupBy('classification');
        $unresolvedEvidenceCount = $missingNormalizedEvidence
            ->whereIn('classification', [
                InvoiceItemSerialEvidenceService::LEGACY_SERIAL_TEXT_INCOMPLETE,
                InvoiceItemSerialEvidenceService::NO_SERIAL_EVIDENCE,
            ])
            ->count();

        $this->line('Total serial links: '.$rows->count());
        $this->line('Serials with more than one historical link: '.count($multipleLinks));
        $this->line('Serials with more than one completed sale: '.count($multiCompleted));
        $this->line('  - Backdated resale: '.$byClassification->get(SerialLifecycleInspectionService::BACKDATED_RESALE, collect())->count());
        $this->line('  - Ordered resale history: '.$byClassification->get(SerialLifecycleInspectionService::ORDERED_RESALE_HISTORY, collect())->count());
        $this->line('  - Recorded time unknown: '.$byClassification->get(SerialLifecycleInspectionService::RECORDED_TIME_UNKNOWN, collect())->count());
        $this->line('  - Unresolved multiple completed sales: '.$byClassification->get(SerialLifecycleInspectionService::UNRESOLVED_MULTIPLE_COMPLETED_SALES, collect())->count());
        $this->line('Canceled invoice link candidates: '.count($canceledCandidates));
        $this->line('Completed serial sale items without normalized links: '.$missingNormalizedEvidence->count());
        $this->line('  - Direct serial assignment fallback: '.$byEvidenceClassification->get(InvoiceItemSerialEvidenceService::DIRECT_SERIAL_ASSIGNMENT, collect())->count());
        $this->line('  - Legacy serial text evidence: '.$byEvidenceClassification->get(InvoiceItemSerialEvidenceService::LEGACY_SERIAL_TEXT, collect())->count());
        $this->line('  - Incomplete legacy serial text: '.$byEvidenceClassification->get(InvoiceItemSerialEvidenceService::LEGACY_SERIAL_TEXT_INCOMPLETE, collect())->count());
        $this->line('  - No serial evidence: '.$byEvidenceClassification->get(InvoiceItemSerialEvidenceService::NO_SERIAL_EVIDENCE, collect())->count());

        if ($multipleLinks) {
            $this->table(['serial_id', 'serial_number', 'product_id', 'link_ids', 'invoice_ids', 'invoice_statuses'], array_slice($multipleLinks, 0, 50));
        }

        if ($canceledCandidates) {
            $this->table(['link_id', 'serial_id', 'serial_number', 'invoice_id', 'invoice_code', 'invoice_status'], array_slice($canceledCandidates, 0, 50));
        }

        if ($inspections->isNotEmpty()) {
            $this->table(
                ['serial_id', 'serial_number', 'product_id', 'classification', 'invoice_codes', 'assessment'],
                $inspections->take(100)->map(fn (array $row) => [
                    $row['serial_id'],
                    $row['serial_number'],
                    $row['product_id'],
                    $row['classification'],
                    $row['invoice_codes'],
                    $row['message'],
                ])->all(),
            );
        }

        if ($missingNormalizedEvidence->isNotEmpty()) {
            $this->table(
                ['invoice_code', 'item_id', 'product_sku', 'qty', 'classification', 'standard_links', 'direct_serials', 'legacy_serials'],
                $missingNormalizedEvidence->take(100)->map(fn (array $row) => [
                    $row['invoice_code'],
                    $row['invoice_item_id'],
                    $row['product_sku'],
                    $row['expected_quantity'],
                    $row['classification'],
                    $row['standard_link_count'],
                    $row['direct_serial_count'],
                    implode(', ', $row['legacy_serial_numbers']),
                ])->all(),
            );
        }

        return count($multiCompleted) > 0 || $unresolvedEvidenceCount > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function resolveProduct(?string $productOpt): ?Product
    {
        if (! $productOpt) {
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

    /** @return Collection<int, array<string, mixed>> */
    private function missingNormalizedEvidenceRows(?int $productId): Collection
    {
        $query = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('products.has_serial', true)
            ->select([
                'invoice_items.id as invoice_item_id',
                'invoice_items.invoice_id',
                'invoice_items.product_id',
                'invoice_items.quantity',
                'invoice_items.serial as legacy_serial_text',
                'invoices.code as invoice_code',
                'invoices.status as invoice_status',
                'products.sku as product_sku',
            ]);

        if ($productId) {
            $query->where('products.id', $productId);
        }

        $evidenceService = app(InvoiceItemSerialEvidenceService::class);

        return $query->orderBy('invoices.created_at')
            ->orderBy('invoice_items.id')
            ->get()
            ->filter(fn (object $row) => BusinessStatus::isCompleted($row->invoice_status))
            ->map(function (object $row) use ($evidenceService): array {
                $evidence = $evidenceService->inspect(
                    (int) $row->product_id,
                    (int) $row->invoice_item_id,
                    (int) $row->invoice_id,
                    (int) $row->quantity,
                    $row->legacy_serial_text,
                );

                return [
                    'invoice_code' => (string) $row->invoice_code,
                    'invoice_item_id' => (int) $row->invoice_item_id,
                    'product_sku' => (string) $row->product_sku,
                    ...$evidence,
                ];
            })
            ->filter(fn (array $row) => $row['classification'] !== InvoiceItemSerialEvidenceService::STANDARD_LINKS)
            ->values();
    }
}
