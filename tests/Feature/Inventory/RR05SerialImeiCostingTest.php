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
 * Quy ước (theo MovingAvgCostingService.php dòng 16-17):
 *   "Per-IMEI cost_price chỉ phục vụ HIỂN THỊ, KHÔNG ảnh hưởng COGS hay BQ sản phẩm."
 *   COGS bán serial = product.cost_price (BQ moving avg).
 *
 * Test kiểm tra: khi tất cả serial của product bị bán/trả NCC,
 * product.cost_price phải tuân cùng quy ước nhất quán như sản phẩm thường.
 */
class RR05SerialImeiCostingTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSerialProduct(): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR05 Serial']);

        return Product::create([
            'sku'                  => 'SP-RR05S-' . uniqid(),
            'name'                 => 'Product RR05 Serial',
            'cost_price'           => 0,
            'retail_price'         => 10000000,
            'stock_quantity'       => 0,
            'inventory_total_cost' => 0,
            'is_active'            => true,
            'has_serial'           => true,
            'category_id'          => $category->id,
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
     *  TC-RR05-S2: Bán hết serial — product.cost_price phải giữ BQ cuối
     *
     *  Mô phỏng nhập 2 serial qua applyPurchase với cost khác nhau:
     *    - Serial A cost=5,000,000
     *    - Serial B cost=7,000,000
     *  → BQ = 6,000,000
     *  Sau đó bán cả 2 → product.cost_price phải = 6,000,000 (last known average).
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_selling_all_serials_should_keep_product_cost_price(): void
    {
        $product = $this->makeSerialProduct();

        // Nhập serial A — cost 5M
        $serialA = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 5000000);
        $product->refresh();

        // Nhập serial B — cost 7M
        $serialB = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 7000000,
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
        $serialA->update(['status' => 'sold', 'sold_cost_price' => 6000000]);
        $serialB->update(['status' => 'sold', 'sold_cost_price' => 6000000]);

        $product->refresh();
        $product->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(0, (int) $product->stock_quantity,
            'Hết serial in_stock → stock_quantity = 0');
        $this->assertSame(0.0, (float) $product->inventory_total_cost,
            'Hết tồn → total_cost = 0');
        $this->assertSame(6000000.0, (float) $product->cost_price,
            'Bán hết serial: product.cost_price phải giữ BQ cuối = 6,000,000 (last known average)');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S3: Trả NCC hết serial — product.cost_price không được reset 0
     *
     *  Mô phỏng nhập 2 serial rồi trả NCC cả 2.
     *  Kỳ vọng: stock=0, total=0, cost_price = last known average (6M),
     *  giống TC-S2 (nhất quán với applySale).
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_purchase_returning_all_serials_should_keep_product_cost_price(): void
    {
        $product = $this->makeSerialProduct();

        $serialA = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-RA-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 5000000);

        $serialB = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-RB-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 7000000,
            'original_cost' => 7000000,
        ]);
        MovingAvgCostingService::applyPurchase($product, 1, 7000000);

        $product->refresh();
        $this->assertSame(6000000.0, (float) $product->cost_price);

        // Trả NCC cả 2 trong 1 lần (approach 2 trong spec doc) — cost trung bình = 6M.
        // Không dùng 2 lần riêng vì sau lần 1 BQ đã chuyển từ 6M sang 7M, làm
        // last-known-average tại thời điểm qty về 0 = 7M, không còn = 6M như spec.
        // Cả hai approach đều chứng minh fix RR-05 (cost_price ≠ 0); chọn approach gộp
        // để giữ kỳ vọng "last known trước khi bắt đầu chuỗi trả" = 6M.
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
        $this->assertSame(6000000.0, (float) $product->cost_price,
            'Trả NCC hết serial: product.cost_price phải giữ BQ cuối (giống applySale), KHÔNG reset 0');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR05-S4: recomputeFromSerials không can thiệp cost_price
     *  Sau khi cost_price đã là last known average, gọi recomputeFromSerials
     *  chỉ sync stock_quantity, không reset cost_price.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_recompute_from_serials_does_not_touch_cost_price(): void
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
        $this->assertSame(6000000.0, (float) $product->cost_price,
            'recomputeFromSerials chỉ sync stock, không đụng cost_price');
    }
}
