<?php

namespace Tests\Feature\Costing;

use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Services\CostingService;
use App\Services\MovingAvgCostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 26 — giá vốn không được về 0đ khi hết tồn: giữ giá vốn cuối cùng,
 * lần nhập sau tính lại bình quân bình thường.
 */
class CostPriceKeptWhenOutOfStockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_serial_product_keeps_last_cost_after_last_serial_is_sold(): void
    {
        $product = $this->product(['has_serial' => true]);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-KEEP-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 12500000,
            'original_cost' => 12500000,
        ]);
        $product->recomputeFromSerials();
        $this->assertSame(12500000.0, (float) $product->fresh()->cost_price);

        $serial->update(['status' => 'sold', 'sold_cost_price' => 12500000]);
        $product->refresh()->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->inventory_total_cost);
        $this->assertSame(12500000.0, (float) $product->cost_price);
    }

    public function test_standard_product_keeps_last_cost_at_zero_stock_and_next_purchase_recomputes(): void
    {
        $product = $this->product();
        MovingAvgCostingService::applyPurchase($product, 2, 300000);
        MovingAvgCostingService::applyPurchase($product, 2, 500000);
        $this->assertSame(400000.0, (float) $product->fresh()->cost_price);

        MovingAvgCostingService::applySale($product, 4);
        $product->refresh();
        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(400000.0, (float) $product->cost_price);

        MovingAvgCostingService::applyPurchase($product, 1, 600000);
        $this->assertSame(600000.0, (float) $product->fresh()->cost_price);
    }

    public function test_legacy_reverse_on_in_does_not_reset_cost_to_zero(): void
    {
        Setting::set('inventory_costing_method', 'average');
        $this->assertTrue(CostingService::isAverageMethod());
        $product = $this->product(['stock_quantity' => 2, 'cost_price' => 350000, 'inventory_total_cost' => 700000]);

        CostingService::reverseOnIn($product, 2, 350000);

        $this->assertSame(350000.0, (float) $product->cost_price);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SP-KEEP-'.uniqid(),
            'name' => 'Giữ giá vốn khi hết hàng',
            'cost_price' => 0,
            'retail_price' => 1000000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => false,
        ], $attributes));
    }
}
