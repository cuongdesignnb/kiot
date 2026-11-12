<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;

/**
 * Creates the KIOT goods for Website PC order lines whose SKU does not exist
 * in KIOT yet (web-only products such as WEB-NEW-*). The goods start with
 * stock 0 and cost 0 and are flagged cost_pending ("Chưa có giá vốn") until
 * the shop records a purchase receipt or a cost price for them.
 */
class PcWebProductCreator
{
    /**
     * @param  list<string>  $skus  SKUs that do not exist in KIOT (not even soft-deleted)
     * @param  array<int, array<string, mixed>>  $items  Website order lines
     * @return array<string, Product>
     */
    public function createMissing(array $skus, array $items, string $externalOrderCode, string $externalOrderId): array
    {
        if ($skus === []) {
            return [];
        }

        $category = Category::query()->firstOrCreate(
            ['name' => Product::WEB_PENDING_CATEGORY_NAME, 'parent_id' => null],
            ['is_active' => true, 'show_on_pc_website' => false],
        );

        $created = [];
        foreach ($skus as $sku) {
            $item = collect($items)->first(fn (array $line) => trim((string) ($line['sku'] ?? '')) === $sku) ?? [];
            $name = $this->productName($item, $sku);
            $price = $this->retailPrice($item);

            $product = new Product([
                'sku' => $sku,
                'name' => $name,
                'type' => 'standard',
                'category_id' => $category->id,
                'cost_price' => 0,
                'retail_price' => $price,
                'stock_quantity' => 0,
                'inventory_total_cost' => 0,
                'has_serial' => false,
                'has_variants' => false,
                'is_active' => true,
                'sell_directly' => true,
            ]);
            $product->forceFill([
                'created_from_web_at' => now(),
                'cost_pending' => true,
            ]);
            $product->save();

            ActivityLog::log(
                ActivityLog::ACTION_EXTERNAL_PRODUCT_CREATED,
                "Web tạo hàng {$sku} từ đơn {$externalOrderCode}",
                $product,
                [
                    'sku' => $sku,
                    'name' => $name,
                    'retail_price' => $price,
                    'category' => $category->name,
                    'external_order_id' => $externalOrderId,
                    'external_order_code' => $externalOrderCode,
                ],
            );

            $created[$sku] = $product;
        }

        return $created;
    }

    private function productName(array $item, string $sku): string
    {
        $catalogName = trim((string) ($item['catalog_name'] ?? ''));
        if ($catalogName !== '') {
            return mb_substr($catalogName, 0, 255);
        }

        $name = trim((string) ($item['product_name'] ?? ''));
        // "Laptop X (bảo hành 12 tháng)" -> "Laptop X"
        $name = trim((string) preg_replace('/\s*\(\s*bảo hành\s+\d+\s+tháng\s*\)\s*$/iu', '', $name));

        return $name !== '' ? mb_substr($name, 0, 255) : $sku;
    }

    private function retailPrice(array $item): float
    {
        if (isset($item['catalog_price']) && $item['catalog_price'] !== '' && is_numeric($item['catalog_price'])) {
            return max(0.0, round((float) $item['catalog_price'], 2));
        }

        return max(0.0, round((float) ($item['unit_price'] ?? 0), 2));
    }
}
