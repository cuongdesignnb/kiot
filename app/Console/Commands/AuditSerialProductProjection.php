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
        {--all-sold-out : Audit every serial product with no in-stock serials}
        {--apply : Apply only the exact projection shown by dry-run}
        {--confirm= : Confirmation code emitted by dry-run}
        {--backup-reference= : Optional legacy recovery reference}';

    protected $description = 'Audit and safely repair current serial-product inventory projections without rewriting historical COGS.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $productOption = trim((string) $this->option('product'));
        $allSoldOut = (bool) $this->option('all-sold-out');
        if (($productOption === '') === ! $allSoldOut) {
            $this->error('Provide exactly one scope: --product=ID|SKU or --all-sold-out.');

            return self::FAILURE;
        }

        if ($allSoldOut) {
            return $this->handleAllSoldOut($apply);
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
        $recoveryReference = trim((string) $this->option('backup-reference')) ?: 'ACTIVITY_LOG_BEFORE_STATE';

        $result = DB::transaction(function () use ($product, $before, $target, $planHash, $recoveryReference, $needsChange): array {
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
                    compact('before', 'target', 'planHash', 'recoveryReference'),
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
                'recovery_reference' => $recoveryReference,
                'historical_cogs_mutation' => 'NO',
                'production_business_data_mutation' => $needsChange ? 'YES_APPROVED_SERIAL_PRODUCT_PROJECTION' : 'NO',
            ];
        });

        return $this->emit($result);
    }

    private function handleAllSoldOut(bool $apply): int
    {
        $products = Product::query()
            ->where('has_serial', true)
            ->orderBy('id')
            ->get()
            ->reject(fn (Product $product) => $product->isService())
            ->filter(fn (Product $product) => $this->serialProjection((int) $product->id)['stock_quantity'] === 0)
            ->values();
        $rows = $products
            ->map(fn (Product $product) => $this->projectionRow($product))
            ->filter(fn (array $row) => $row['before'] !== $row['target'])
            ->values()
            ->all();
        $planHash = hash('sha256', json_encode([
            'contract' => 'sold-out-serial-product-projections-v1',
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR));
        $confirmationCode = 'APPLY-SOLD-OUT-SERIAL-PROJECTIONS-'.substr($planHash, 0, 16);

        if (! $apply) {
            return $this->emit([
                'result' => 'DRY_RUN',
                'scope' => 'ALL_SOLD_OUT_SERIAL_PRODUCTS',
                'sold_out_products_scanned' => $products->count(),
                'projection_mismatches' => count($rows),
                'rows' => $rows,
                'plan_hash' => $planHash,
                'confirmation_code' => $confirmationCode,
                'historical_cogs_mutation' => 'NO',
                'production_business_data_mutation' => 'NO',
            ]);
        }

        if ((string) $this->option('confirm') !== $confirmationCode) {
            $this->error('Confirmation code does not match the current sold-out projection plan.');

            return self::FAILURE;
        }

        $recoveryReference = trim((string) $this->option('backup-reference')) ?: 'ACTIVITY_LOG_BEFORE_STATE';
        $result = DB::transaction(function () use ($rows, $planHash, $recoveryReference): array {
            $ids = array_column($rows, 'product_id');
            $lockedProducts = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            SerialImei::query()->whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $lockedRows = $lockedProducts->map(fn (Product $product) => $this->projectionRow($product))->all();
            if ($lockedRows !== $rows) {
                throw new \RuntimeException('Projection source changed after dry-run; run dry-run again.');
            }

            foreach ($lockedProducts as $index => $product) {
                $row = $rows[$index];
                $product->forceFill($row['target'])->save();
                ActivityLog::log(
                    ActivityLog::ACTION_SERIAL_PRODUCT_PROJECTION_REPAIR,
                    'Hiệu chỉnh projection hết tồn của sản phẩm serial',
                    $product,
                    [
                        'before' => $row['before'],
                        'target' => $row['target'],
                        'planHash' => $planHash,
                        'recoveryReference' => $recoveryReference,
                    ],
                );
            }

            return [
                'result' => $rows === [] ? 'REPLAY' : 'APPLIED',
                'scope' => 'ALL_SOLD_OUT_SERIAL_PRODUCTS',
                'rows_changed' => count($rows),
                'product_ids' => $ids,
                'plan_hash' => $planHash,
                'recovery_reference' => $recoveryReference,
                'historical_cogs_mutation' => 'NO',
                'production_business_data_mutation' => $rows === [] ? 'NO' : 'YES_APPROVED_SOLD_OUT_SERIAL_PRODUCT_PROJECTIONS',
            ];
        });

        return $this->emit($result);
    }

    private function projectionRow(Product $product): array
    {
        return [
            'product_id' => (int) $product->id,
            'sku' => $product->sku,
            'before' => $this->projection($product),
            'target' => $this->serialProjection((int) $product->id),
        ];
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
