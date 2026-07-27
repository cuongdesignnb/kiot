<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductDeletionGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class AuditProductDeletions extends Command
{
    protected $signature = 'products:audit-deletions
        {--from= : Inclusive deleted_at lower bound}
        {--to= : Inclusive deleted_at upper bound}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Read-only audit of soft-deleted products and their business history';

    public function handle(ProductDeletionGuard $guard): int
    {
        try {
            $from = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::create(1970, 1, 1)->startOfDay();
            $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();
        } catch (Throwable $exception) {
            $this->error('Invalid --from or --to date: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('--from must be earlier than or equal to --to.');

            return self::FAILURE;
        }

        $rows = Product::onlyTrashed()
            ->whereBetween('deleted_at', [$from, $to])
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) use ($guard): array {
                $inspection = $guard->inspect($product);
                $unsafeDeletion = $inspection['blocked'];

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'deleted_at' => $product->deleted_at?->toIso8601String(),
                    'stock_quantity' => (float) $product->stock_quantity,
                    'serial_count' => $inspection['serial_count'],
                    'purchase_count' => $inspection['purchase_count'],
                    'invoice_count' => $inspection['invoice_count'],
                    'order_count' => $inspection['order_count'],
                    'movement_count' => $inspection['movement_count'],
                    'has_business_history' => $inspection['has_business_history'],
                    'restore_candidate' => $unsafeDeletion,
                    'restore_reason' => $unsafeDeletion
                        ? 'deleted_product_has_stock_serial_or_business_history_manual_review_required'
                        : 'no_stock_serial_or_business_history_detected',
                ];
            })
            ->values()
            ->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'SKU', 'Name', 'Deleted at', 'Stock', 'Serials', 'Purchases', 'Invoices', 'Orders', 'Movements', 'History', 'Restore candidate', 'Reason'],
            array_map(fn (array $row) => array_values($row), $rows)
        );

        return self::SUCCESS;
    }
}
