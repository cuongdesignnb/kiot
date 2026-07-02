<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-04: Kiểm kho không ghi StockMovement, không cập nhật giá vốn.
 *
 * Vấn đề: StockTakeController chỉ dùng increment/decrement trực tiếp
 * trên products.stock_quantity mà:
 *   - KHÔNG tạo StockMovement (thẻ kho thiếu dòng kiểm kho)
 *   - KHÔNG cập nhật inventory_total_cost (giá vốn BQ sai)
 *
 * Luồng kiểm kho:
 *   - store() status=balanced: cộng/trừ chênh lệch raw
 *   - balance(): chuyển draft → balanced, cộng/trừ chênh lệch raw
 *   - cancel(): đảo chênh lệch raw
 */
class RR04StockTakeTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;
    private User    $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR04',
            'email'    => 'admin-rr04-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat RR04']);

        $this->product = Product::create([
            'sku'                  => 'SP-RR04-' . uniqid(),
            'name'                 => 'Product RR04 StockTake',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 10 * 100000, // 1.000.000
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR04-01: Kiểm kho tăng tồn phải tạo StockMovement adjust_in
     *
     *  stock = 10, actual = 13 → diff = +3
     *  Phải có StockMovement type = 'adjust_in'
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stocktake_increase_should_create_adjust_in_movement(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'Test RR04-01',
            'items'  => [
                [
                    'product_id'   => $this->product->id,
                    'system_stock' => 10,
                    'actual_stock' => 13,
                    'diff_qty'     => 3,
                    'diff_value'   => 3 * 100000,
                ],
            ],
        ]);

        $stockTake = StockTake::where('note', 'Test RR04-01')->first();
        $this->assertNotNull($stockTake, 'Phiếu kiểm kho phải được tạo');

        // stock_quantity phải tăng +3
        $this->product->refresh();
        $this->assertEquals(13, $this->product->stock_quantity);

        // Phải có StockMovement adjust_in
        $movement = StockMovement::where('product_id', $this->product->id)
            ->where('type', 'adjust_in')
            ->where('ref_type', 'App\\Models\\StockTake')
            ->where('ref_id', $stockTake->id)
            ->first();

        $this->assertNotNull(
            $movement,
            "Phải có StockMovement type='adjust_in' khi kiểm kho tăng tồn. "
            . "Hiện tại: KHÔNG có dòng nào trong stock_movements."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR04-02: Kiểm kho giảm tồn phải tạo StockMovement adjust_out
     *
     *  stock = 10, actual = 7 → diff = -3
     *  Phải có StockMovement type = 'adjust_out'
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stocktake_decrease_should_create_adjust_out_movement(): void
    {
        $response = $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'Test RR04-02',
            'items'  => [
                [
                    'product_id'   => $this->product->id,
                    'system_stock' => 10,
                    'actual_stock' => 7,
                    'diff_qty'     => -3,
                    'diff_value'   => -3 * 100000,
                ],
            ],
        ]);

        $stockTake = StockTake::where('note', 'Test RR04-02')->first();
        $this->assertNotNull($stockTake);

        $this->product->refresh();
        $this->assertEquals(7, $this->product->stock_quantity);

        $movement = StockMovement::where('product_id', $this->product->id)
            ->where('type', 'adjust_out')
            ->where('ref_type', 'App\\Models\\StockTake')
            ->where('ref_id', $stockTake->id)
            ->first();

        $this->assertNotNull(
            $movement,
            "Phải có StockMovement type='adjust_out' khi kiểm kho giảm tồn. "
            . "Hiện tại: KHÔNG có dòng nào trong stock_movements."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR04-03: Kiểm kho tăng tồn phải cập nhật inventory_total_cost
     *
     *  stock 10 → 13, cost_price = 100.000
     *  inventory_total_cost phải tăng từ 1.000.000 → 1.300.000
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stocktake_increase_should_update_inventory_total_cost(): void
    {
        $costBefore = (float) $this->product->inventory_total_cost; // 1.000.000

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'Test RR04-03',
            'items'  => [
                [
                    'product_id'   => $this->product->id,
                    'system_stock' => 10,
                    'actual_stock' => 13,
                    'diff_qty'     => 3,
                    'diff_value'   => 3 * 100000,
                ],
            ],
        ]);

        $this->product->refresh();

        // inventory_total_cost phải = 1.300.000
        $expected = $costBefore + (3 * 100000);
        $this->assertEquals(
            $expected,
            (float) $this->product->inventory_total_cost,
            "inventory_total_cost phải tăng khi kiểm kho tăng tồn. "
            . "Trước: " . number_format($costBefore)
            . ", kỳ vọng: " . number_format($expected)
            . ", thực tế: " . number_format($this->product->inventory_total_cost)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR04-04: Kiểm kho giảm tồn phải cập nhật inventory_total_cost
     *
     *  stock 10 → 7, cost_price = 100.000
     *  inventory_total_cost phải giảm từ 1.000.000 → 700.000
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stocktake_decrease_should_update_inventory_total_cost(): void
    {
        $costBefore = (float) $this->product->inventory_total_cost; // 1.000.000

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'Test RR04-04',
            'items'  => [
                [
                    'product_id'   => $this->product->id,
                    'system_stock' => 10,
                    'actual_stock' => 7,
                    'diff_qty'     => -3,
                    'diff_value'   => -3 * 100000,
                ],
            ],
        ]);

        $this->product->refresh();

        $expected = $costBefore - (3 * 100000); // 700.000
        $this->assertEquals(
            $expected,
            (float) $this->product->inventory_total_cost,
            "inventory_total_cost phải giảm khi kiểm kho giảm tồn. "
            . "Trước: " . number_format($costBefore)
            . ", kỳ vọng: " . number_format($expected)
            . ", thực tế: " . number_format($this->product->inventory_total_cost)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR04-05: Hủy kiểm kho phải đảo đúng và idempotent
     *
     *  Kiểm kho tăng 10 → 13, hủy phải đưa về 10.
     *  Hủy lần 2 không thay đổi thêm.
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cancel_stocktake_should_be_idempotent(): void
    {
        // Tạo phiếu kiểm kho balanced (tăng 3)
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'Test RR04-05',
            'items'  => [
                [
                    'product_id'   => $this->product->id,
                    'system_stock' => 10,
                    'actual_stock' => 13,
                    'diff_qty'     => 3,
                    'diff_value'   => 3 * 100000,
                ],
            ],
        ]);

        $stockTake = StockTake::where('note', 'Test RR04-05')->first();
        $this->assertNotNull($stockTake);

        $this->product->refresh();
        $this->assertEquals(13, $this->product->stock_quantity);

        // Cancel lần 1
        $controller = app(\App\Http\Controllers\StockTakeController::class);
        $controller->cancel($stockTake->id);

        $this->product->refresh();
        $this->assertEquals(
            10,
            $this->product->stock_quantity,
            "Hủy lần 1: stock phải phục hồi về 10"
        );

        // Cancel lần 2
        $controller->cancel($stockTake->id);

        $this->product->refresh();
        $this->assertEquals(
            10,
            $this->product->stock_quantity,
            "Hủy lần 2: stock phải giữ nguyên 10, không trừ thêm"
        );
    }
}
