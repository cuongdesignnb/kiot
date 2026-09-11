<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSerialProductProjection extends Command
{
    protected $signature = 'costing:audit-serial-product-projection
        {--product= : Product ID or SKU}
        {--apply : Apply only the exact projection shown by dry-run}
        {--confirm= : Confirmation code emitted by dry-run}
        {--backup-reference= : Required backup reference for apply}';

    protected $description = 'Audit and safely repair current serial-product inventory projections without rewriting historical COGS.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $productOption = trim((string) $this->option('product'));
        if ($productOption === '') {
            $this->error('Provide --product=ID|SKU.');

            return self::FAILURE;
        }

        $product = Product::query()
            ->where(fn ($query) => $query->where('id', $productOption)->orWhere('sku', $productOption))
            ->first();

        if (! $product || ! $product->has_serial || $product->isService()) {
            $this->error('Product not found or is not a stock-managed serial product.');

            return self::FAILURE;
        }

        $before = $this->projection($product);
        $target = $this->serialProjection((int) $product->id);
        $planHash = hash('sha256', json_encode([
            'contract' => 'serial-product-projection-v1',
            'product_id' => (int) $product->id,
            'before' => $before,
            'target' => $target,
        ], JSON_THROW_ON_ERROR));
        $confirmationCode = 'APPLY-SERIAL-PROJECTION-'.substr($planHash, 0, 16);
        $needsChange = $before !== $target;

        if (! $apply) {
            return $this->emit([
                'result' => 'DRY_RUN',
                'product_id' => (int) $product->id,
                'sku' => $product->sku,
                'before' => $before,
                'target' => $target,
                'projection_mismatch' => $needsChange,
                'plan_hash' => $planHash,
                'confirmation_code' => $confirmationCode,
                'historical_cogs_mutation' => 'NO',
                'production_business_data_mutation' => 'NO',
            ]);
        }

        if ((string) $this->option('confirm') !== $confirmationCode) {
            $this->error('Confirmation code does not match the current projection plan.');

            return self::FAILURE;
        }
        $backupReference = trim((string) $this->option('backup-reference'));
        if ($backupReference === '') {
            $this->error('--backup-reference is required for apply.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($product, $before, $target, $planHash, $backupReference, $needsChange): array {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            SerialImei::query()->where('product_id', $locked->id)->lockForUpdate()->get(['id']);
            if ($this->projection($locked) !== $before || $this->serialProjection((int) $locked->id) !== $target) {
                throw new \RuntimeException('Projection source changed after dry-run; run dry-run again.');
            }

            if ($needsChange) {
                $locked->forceFill($target)->save();
                ActivityLog::log(
                    ActivityLog::ACTION_SERIAL_PRODUCT_PROJECTION_REPAIR,
                    'Hiệu chỉnh projection tồn kho hiện tại của sản phẩm serial',
                    $locked,
                    compact('before', 'target', 'planHash', 'backupReference'),
                );
            }

            return [
                'result' => $needsChange ? 'APPLIED' : 'REPLAY',
                'product_id' => (int) $locked->id,
                'sku' => $locked->sku,
                'before' => $before,
                'after' => $this->projection($locked->fresh()),
                'rows_changed' => $needsChange ? 1 : 0,
                'plan_hash' => $planHash,
                'backup_reference_recorded' => true,
                'historical_cogs_mutation' => 'NO',
                'production_business_data_mutation' => $needsChange ? 'YES_APPROVED_SERIAL_PRODUCT_PROJECTION' : 'NO',
            ];
        });

        return $this->emit($result);
    }

    private function projection(Product $product): array
    {
        return [
            'stock_quantity' => (int) $product->stock_quantity,
            'inventory_total_cost' => round((float) $product->inventory_total_cost, 2),
            'cost_price' => round((float) $product->cost_price, 2),
        ];
    }

    private function serialProjection(int $productId): array
    {
        $aggregate = SerialImei::query()->where('product_id', $productId)->where('status', 'in_stock')
            ->selectRaw('COUNT(*) quantity, COALESCE(SUM(cost_price), 0) total_cost')->first();
        $quantity = (int) ($aggregate->quantity ?? 0);
        $totalCost = round((float) ($aggregate->total_cost ?? 0), 2);

        return [
            'stock_quantity' => $quantity,
            'inventory_total_cost' => $totalCost,
            'cost_price' => $quantity > 0 ? round($totalCost / $quantity, 2) : 0.0,
        ];
    }

    private function emit(array $payload): int
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
