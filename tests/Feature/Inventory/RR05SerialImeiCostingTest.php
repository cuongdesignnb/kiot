<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Services\MovingAvgCostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RR-05 — Phần Serial/IMEI:
 *
 * Quy ước: giá vốn của hàng serial là giá vốn đích danh của serial. Projection
 * products chỉ là tổng hợp các serial còn in_stock; không được giữ BQ cũ sau
 * khi hết serial vì giá đó có thể bị dùng nhầm cho một serial ở lô khác.
 */
class RR05SerialImeiCostingTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSerialProduct(): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR05 Serial']);

        return Product::create([
            'sku' => 'SP-RR05S-'.uniqid(),
            'name' => 'Product RR05 Serial',
            'cost_price' => 0,
            'retail_price' => 10000000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S1: Discovery — schema serial cost
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_serial_imei_schema_has_cost_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('serial_imeis', 'cost_price'),
            'serial_imeis cần có cost_price (giá vốn current)');
        $this->assertTrue(Schema::hasColumn('serial_imeis', 'original_cost'),
            'serial_imeis cần có original_cost (giá nhập gốc snapshot)');
        $this->assertTrue(Schema::hasColumn('serial_imeis', 'sold_cost_price'),
            'serial_imeis cần có sold_cost_price (BQ tại lúc bán)');
        $this->assertTrue(Schema::hasColumn('invoice_item_serials', 'cost_price'),
            'invoice_item_serials cần có cost_price (snapshot per-serial bán)');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S2: Bán hết serial — projection phải về 0
     *
     *  Mô phỏng nhập 2 serial qua applyPurchase với cost khác nhau:
     *    - Serial A cost=5,000,000
     *    - Serial B cost=7,000,000
     *  → Projection = 6,000,000
     *  Sau đó bán cả 2 → không còn serial in_stock nên projection phải về 0.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_selling_all_serials_should_clear_product_projection(): void
    {
        $product = $this->makeSerialProduct();

        // Nhập serial A — cost 5M
        $serialA = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-A-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 5000000);
        $product->refresh();

        // Nhập serial B — cost 7M
        $serialB = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-B-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 7000000,
            'original_cost' => 7000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 7000000);
        $product->refresh();

        // Sanity check: BQ = 6M
        $this->assertSame(2, (int) $product->stock_quantity);
        $this->assertSame(12000000.0, (float) $product->inventory_total_cost);
        $this->assertSame(6000000.0, (float) $product->cost_price);

        // Bán cả 2 (qua applySale — đại diện luồng bán hàng tại Invoice/Pos)
        MovingAvgCostingService::applySale($product, 2);

        // Đánh dấu serial sold (mô phỏng InvoiceController)
        $serialA->update(['status' => 'sold', 'sold_cost_price' => 5000000]);
        $serialB->update(['status' => 'sold', 'sold_cost_price' => 7000000]);

        $product->refresh();
        $product->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity,
            'Hết serial in_stock → stock_quantity = 0');
        $this->assertSame(0.0, (float) $product->inventory_total_cost,
            'Hết tồn → total_cost = 0');
        $this->assertSame(0.0, (float) $product->cost_price,
            'Bán hết serial: không còn serial in_stock nên BQ projection phải bằng 0');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S3: Trả NCC hết serial — projection phải về 0
     *
     *  Mô phỏng nhập 2 serial rồi trả NCC cả 2.
     *  Kỳ vọng: stock=0, total=0, cost_price=0 vì không còn serial in_stock.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_purchase_returning_all_serials_should_clear_product_projection(): void
    {
        $product = $this->makeSerialProduct();

        $serialA = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RA-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 5000000);

        $serialB = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RB-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => 7000000,
            'original_cost' => 7000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 7000000);

        $product->refresh();
        $this->assertSame(6000000.0, (float) $product->cost_price);

        // Trả NCC cả 2 trong 1 lần.
        MovingAvgCostingService::applyPurchaseReturn($product, 2, 6000000);

        // Đánh dấu serial returned (mô phỏng PurchaseReturnController)
        $serialA->update(['status' => 'returned']);
        $serialB->update(['status' => 'returned']);

        $product->refresh();
        $product->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity,
            'Hết serial in_stock → stock_quantity = 0');
        $this->assertSame(0.0, (float) $product->inventory_total_cost,
            'Hết tồn → total_cost = 0');
        $this->assertSame(0.0, (float) $product->cost_price,
            'Trả NCC hết serial: không còn serial in_stock nên BQ projection phải bằng 0');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S4: recomputeFromSerials luôn đồng bộ đầy đủ projection
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_recompute_from_serials_clears_stale_cost_without_stock(): void
    {
        $product = $this->makeSerialProduct();

        // Set state: cost_price = 6M, stock_quantity = 0 (đã hết tồn), không có serial in_stock
        $product->cost_price = 6000000;
        $product->stock_quantity = 0;
        $product->inventory_total_cost = 0;
        $product->save();

        $product->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->cost_price,
            'recomputeFromSerials phải xóa BQ cũ khi không còn serial in_stock');
    }
}
