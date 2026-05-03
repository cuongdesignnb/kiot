<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\MovingAvgCostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-05: Giá vốn bình quân không nhất quán khi tồn về 0.
 *
 * Lỗi: applyPurchaseReturn reset cost_price=0 khi newQty=0;
 *      applySale giữ BQ cũ khi newQty=0. → Hai method không nhất quán.
 *
 * Tham chiếu:
 *   - MovingAvgCostingService::applySale dòng 79  → giữ BQ cũ
 *   - MovingAvgCostingService::applyPurchaseReturn dòng 135 → reset 0
 */
class RR05MovingAvgCostingZeroStockTest extends TestCase
{
    use DatabaseTransactions;

    private function makeProduct(int $stock = 10, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR05']);

        return Product::create([
            'sku'                  => 'SP-RR05-' . uniqid(),
            'name'                 => 'Product RR05',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-01: applySale bán hết tồn → giữ cost_price cuối
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_apply_sale_to_zero_should_keep_cost_price(): void
    {
        $product = $this->makeProduct(10, 100000);

        MovingAvgCostingService::applySale($product, 10);
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->inventory_total_cost);
        $this->assertSame(100000.0, (float) $product->cost_price,
            'applySale phải giữ BQ cuối khi tồn về 0');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-02: applyPurchaseReturn trả hết tồn → giữ cost_price (nhất quán applySale)
     *  Đây là test CHÍNH chứng minh RR-05.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_apply_purchase_return_to_zero_should_keep_cost_price(): void
    {
        $product = $this->makeProduct(10, 100000);

        MovingAvgCostingService::applyPurchaseReturn($product, 10, 100000);
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->inventory_total_cost);
        $this->assertSame(100000.0, (float) $product->cost_price,
            'applyPurchaseReturn phải giữ BQ cuối khi tồn về 0 (giống applySale)');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-03: applyPurchaseReturn trả một phần → BQ vẫn đúng
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_apply_purchase_return_partial_should_keep_avg(): void
    {
        $product = $this->makeProduct(10, 100000);

        MovingAvgCostingService::applyPurchaseReturn($product, 3, 100000);
        $product->refresh();

        $this->assertSame(7, (int) $product->stock_quantity);
        $this->assertSame(700000.0, (float) $product->inventory_total_cost);
        $this->assertSame(100000.0, (float) $product->cost_price);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-04: Cross-method — Sale-to-zero và PurchaseReturn-to-zero
     *  phải cho cùng kết quả cost_price.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_sale_zero_and_purchase_return_zero_should_be_consistent(): void
    {
        // Kịch bản A: bán hết
        $a = $this->makeProduct(10, 100000);
        MovingAvgCostingService::applySale($a, 10);
        $a->refresh();

        // Kịch bản B: trả NCC hết
        $b = $this->makeProduct(10, 100000);
        MovingAvgCostingService::applyPurchaseReturn($b, 10, 100000);
        $b->refresh();

        $this->assertSame((float) $a->cost_price, (float) $b->cost_price,
            'applySale và applyPurchaseReturn phải nhất quán khi tồn về 0');
        $this->assertSame((float) $a->stock_quantity, (float) $b->stock_quantity);
        $this->assertSame((float) $a->inventory_total_cost, (float) $b->inventory_total_cost);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-05: applyAdjustment rút hết tồn → giữ BQ (regression — đã đúng)
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_apply_adjustment_to_zero_should_keep_cost_price(): void
    {
        $product = $this->makeProduct(10, 100000);

        MovingAvgCostingService::applyAdjustment($product, -10);
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->inventory_total_cost);
        $this->assertSame(100000.0, (float) $product->cost_price);
    }
}
