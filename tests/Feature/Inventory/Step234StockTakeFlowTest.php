<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.4 — StockTake / Inventory Adjustment business rules.
 *
 * Bugs covered:
 *  - BUG-1 server-side recompute (KHÔNG tin client system_stock/diff_qty/diff_value).
 *  - BUG-2 chống duplicate product_id trong cùng phiếu.
 *  - BUG-3 chặn cân bằng has_serial diff != 0.
 *  - cost snapshot dùng product.cost_price tại thời điểm thêm hàng vào phiếu.
 */
class Step234StockTakeFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.4',
            'email'    => 'admin-234-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.4']);
        return Product::create([
            'sku'                  => 'P234-' . uniqid(),
            'name'                 => 'Product 23.4',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeBranch(): Branch
    {
        return Branch::create([
            'code' => 'BR-ST-' . uniqid(),
            'name' => 'Branch StockTake ' . uniqid(),
        ]);
    }

    /* ════════════════ A. Draft ════════════════ */

    public function test_stocktake_draft_should_not_change_stock(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-01',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 5,
                'diff_qty'     => -5,
                'diff_value'   => -500000,
            ]],
        ]);

        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $stockTake = StockTake::where('note', 'TC-23.4-01')->first();
        $this->assertNotNull($stockTake);
        $this->assertSame('draft', $stockTake->status);
        $this->assertSame(5, (int) $stockTake->total_actual_qty);
        $this->assertSame(-5, (int) $stockTake->total_diff_qty);
        $this->assertEqualsWithDelta(-500000.0, (float) $stockTake->total_diff_value, 0.01);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)
            ->where('ref_type', 'App\\Models\\StockTake')
            ->where('ref_id', $stockTake->id)->count());
    }

    /* ════════════════ B. Balanced normal ════════════════ */

    public function test_stocktake_balanced_normal_increase_should_adjust(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-03',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 12,
                'diff_qty'     => 2,
                'diff_value'   => 200000,
            ]],
        ]);

        $product->refresh();
        $this->assertSame(12, (int) $product->stock_quantity);
        $stockTake = StockTake::where('note', 'TC-23.4-03')->first();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'adjust_in')
            ->where('ref_id', $stockTake->id)->count());
    }

    public function test_stocktake_balanced_normal_decrease_should_adjust(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-04',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 7,
                'diff_qty'     => -3,
                'diff_value'   => -300000,
            ]],
        ]);

        $product->refresh();
        $this->assertSame(7, (int) $product->stock_quantity);
        $stockTake = StockTake::where('note', 'TC-23.4-04')->first();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'adjust_out')
            ->where('ref_id', $stockTake->id)->count());
    }

    /* ════════════════ C. Server-side recompute (BUG-1) ════════════════ */

    public function test_stocktake_should_recompute_server_side_not_trust_client(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        // Client gửi system_stock SAI (=999) và diff_qty SAI (=-994)
        // Backend phải dùng DB stock=10 để tính diff = 8 - 10 = -2
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-05',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 999,        // ← SAI
                'actual_stock' => 8,
                'diff_qty'     => -994,       // ← SAI
                'diff_value'   => -99400000,  // ← SAI
            ]],
        ]);

        $product->refresh();
        $this->assertSame(8, (int) $product->stock_quantity, 'Stock phải = 8 (actual), không trừ 994.');

        $stockTake = StockTake::where('note', 'TC-23.4-05')->first();
        $this->assertNotNull($stockTake);
        $item = $stockTake->items()->first();
        $this->assertSame(10, (int) $item->system_stock, 'system_stock phải lưu DB stock=10, không phải 999.');
        $this->assertSame(-2, (int) $item->diff_qty, 'diff_qty phải -2 (8-10), không phải -994.');
        $this->assertEqualsWithDelta(-200000.0, (float) $item->diff_value, 0.01);

        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'adjust_out')
            ->where('qty', 2)->count(),
            'Movement phải ghi qty=2 (không phải 994).');
    }

    public function test_stocktake_duplicate_product_lines_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-06',
            'items'  => [
                ['product_id' => $product->id, 'system_stock' => 10, 'actual_stock' => 12, 'diff_qty' => 2, 'diff_value' => 200000],
                ['product_id' => $product->id, 'system_stock' => 10, 'actual_stock' => 8,  'diff_qty' => -2, 'diff_value' => -200000],
            ],
        ]);

        $this->assertSame(0, StockTake::where('note', 'TC-23.4-06')->count(),
            'Phiếu không được tạo khi duplicate product_id.');
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ D. Balance draft protects snapshot ════════════════ */

    public function test_balance_draft_should_fail_when_current_stock_changed_after_snapshot(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        // Tạo draft với actual=8 lúc stock=10
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-07',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 8,
                'diff_qty'     => -2,
                'diff_value'   => -200000,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-07')->first();

        // Sau đó stock đổi thành 12 do giao dịch khác
        $product->update(['stock_quantity' => 12, 'inventory_total_cost' => 12 * 100000]);

        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJson(fn($json) => $json->where('message', fn($message) => str_contains($message, 'Ton he thong') && str_contains($message, 'thay doi'))
                ->etc());

        $product->refresh();
        $this->assertSame(12, (int) $product->stock_quantity, 'Stock không được ép về actual khi snapshot đã lệch.');
        $stockTake->refresh();
        $this->assertSame('draft', $stockTake->status);
        $item = $stockTake->items()->first();
        $this->assertSame(10, (int) $item->system_stock);
        $this->assertSame(10, (int) $item->system_stock_snapshot, 'Snapshot không được tự đổi sang tồn hiện tại.');
        $this->assertSame(-2, (int) $item->diff_qty);

        $this->assertSame(0, StockMovement::where('product_id', $product->id)
            ->where('ref_id', $stockTake->id)->count());
    }

    public function test_balance_draft_should_succeed_when_current_stock_matches_snapshot(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(false, 15, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'draft',
            'note'   => 'TC-23.4-07B',
            'items'  => [[
                'product_id' => $product->id,
                'actual_stock' => 10,
                'checked' => true,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-07B')->first();

        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertOk()->assertJsonPath('success', true);

        $product->refresh();
        $stockTake->refresh();
        $item = $stockTake->items()->first();

        $this->assertSame('balanced', $stockTake->status);
        $this->assertSame(10, (int) $product->stock_quantity);
        $this->assertSame(-5, (int) $item->diff_qty);
        $this->assertEqualsWithDelta(-500000.0, (float) $item->diff_value, 0.01);
        $this->assertSame(10, (int) $stockTake->total_actual_qty);
        $this->assertSame(-5, (int) $stockTake->total_diff_qty);
        $this->assertSame(-5, (int) $stockTake->total_diff_decrease);
        $this->assertEqualsWithDelta(-500000.0, (float) $stockTake->total_diff_value, 0.01);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjust_out',
            'qty' => 5,
            'branch_id' => $branch->id,
            'ref_type' => StockTake::class,
            'ref_id' => $stockTake->id,
            'ref_code' => $stockTake->code,
        ]);
    }

    public function test_balance_should_fail_when_any_item_is_unchecked(): void
    {
        $productA = $this->makeProduct(false, 15, 100000);
        $productB = $this->makeProduct(false, 8, 50000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-07C',
            'items'  => [
                ['product_id' => $productA->id, 'actual_stock' => 10, 'checked' => true],
                ['product_id' => $productB->id, 'actual_stock' => null, 'checked' => false],
            ],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-07C')->first();

        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJson(fn($json) => $json->where('message', fn($message) => str_contains($message, 'chua kiem'))
                ->etc());

        $this->assertSame('draft', $stockTake->fresh()->status);
        $this->assertSame(15, (int) $productA->fresh()->stock_quantity);
        $this->assertSame(8, (int) $productB->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
    }

    public function test_balance_should_fail_when_checked_item_has_null_actual_stock(): void
    {
        $product = $this->makeProduct(false, 15, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-07D',
            'items'  => [[
                'product_id' => $product->id,
                'actual_stock' => null,
                'checked' => true,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-07D')->first();

        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame('draft', $stockTake->fresh()->status);
        $this->assertSame(15, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
    }

    public function test_balance_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-08',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 7,
                'diff_qty'     => -3,
                'diff_value'   => -300000,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-08')->first();

        $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        // Lần 2 phải fail
        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertStatus(422);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity, 'Stock không được double-adjust.');
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('ref_id', $stockTake->id)->count(),
            'Chỉ được có đúng 1 movement, không double.');
    }

    /* ════════════════ E. Serial guards (BUG-3) ════════════════ */

    public function test_stocktake_serial_diff_via_store_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $sB = SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        // Cố cân bằng actual=1 (giảm 1) qua store balanced — phải fail
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-09',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 2,
                'actual_stock' => 1,
                'diff_qty'     => -1,
                'diff_value'   => -5000000,
            ]],
        ]);

        $this->assertSame(0, StockTake::where('note', 'TC-23.4-09')->count(),
            'Phiếu balanced cho hàng has_serial diff != 0 phải bị chặn.');
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $this->assertSame('in_stock', $sA->fresh()->status);
        $this->assertSame('in_stock', $sB->fresh()->status);
    }

    public function test_stocktake_serial_no_diff_should_pass(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        // actual = 2 = current stock → diff = 0 → pass
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-10',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 2,
                'actual_stock' => 2,
                'diff_qty'     => 0,
                'diff_value'   => 0,
            ]],
        ]);

        $this->assertSame(1, StockTake::where('note', 'TC-23.4-10')->count(),
            'Phiếu balanced diff=0 hàng serial vẫn được tạo.');
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $stockTake = StockTake::where('note', 'TC-23.4-10')->first();
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count(),
            'Diff=0 không tạo movement.');
    }

    public function test_balance_serial_diff_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        // Tạo draft (không bị check ở store vì status=draft)
        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'note'   => 'TC-23.4-11',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 1,
                'actual_stock' => 3,
                'diff_qty'     => 2,
                'diff_value'   => 10000000,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-11')->first();
        $this->assertNotNull($stockTake);

        // Balance phải fail vì hàng has_serial diff != 0
        $resp = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake->id));
        $resp->assertStatus(422);

        $stockTake->refresh();
        $this->assertSame('draft', $stockTake->status, 'Phiếu phải vẫn ở draft sau khi balance fail.');
        $this->assertSame(1, (int) $product->fresh()->stock_quantity, 'Stock không đổi.');
    }

    /* ════════════════ F. Cancel ════════════════ */

    public function test_cancel_balanced_normal_should_reverse_adjustment(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'balanced',
            'note'   => 'TC-23.4-12',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 7,
                'diff_qty'     => -3,
                'diff_value'   => -300000,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-12')->first();
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->post(route('stock-takes.cancel', $stockTake->id), [
            'cancel_reason' => 'Regression cancel reversal',
        ]);

        $product->refresh();
        $stockTake->refresh();
        $this->assertSame('cancelled', $stockTake->status);
        $this->assertSame($this->admin->id, (int) $stockTake->cancelled_by);
        $this->assertNotNull($stockTake->cancelled_at);
        $this->assertSame('Regression cancel reversal', $stockTake->cancel_reason);
        $this->assertSame(10, (int) $product->stock_quantity, 'Stock phải về 10 sau cancel.');
        $this->assertSame(2, StockMovement::where('product_id', $product->id)
            ->where('ref_id', $stockTake->id)->count(),
            'Phải có 2 movement: 1 adjust_out lúc balance + 1 adjust_in lúc cancel.');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjust_in',
            'qty' => 3,
            'branch_id' => $branch->id,
            'ref_type' => StockTake::class,
            'ref_id' => $stockTake->id,
            'note' => 'Huy kiem kho - dao chenh lech',
        ]);
    }

    public function test_cancel_stocktake_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-13',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 7,
                'diff_qty'     => -3,
                'diff_value'   => -300000,
            ]],
        ]);
        $stockTake = StockTake::where('note', 'TC-23.4-13')->first();

        $this->actingAs($this->admin)->post(route('stock-takes.cancel', $stockTake->id));
        $stockAfter1 = (int) $product->fresh()->stock_quantity;

        $resp = $this->actingAs($this->admin)->post(route('stock-takes.cancel', $stockTake->id));
        $resp->assertStatus(422);
        $this->assertSame($stockAfter1, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ G. Cost snapshot ════════════════ */

    public function test_stocktake_adjustment_uses_cost_snapshot(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'balanced',
            'note'   => 'TC-23.4-14',
            'items'  => [[
                'product_id'   => $product->id,
                'system_stock' => 10,
                'actual_stock' => 7,
                'diff_qty'     => -3,
                'diff_value'   => -300000,
            ]],
        ]);

        $stockTake = StockTake::where('note', 'TC-23.4-14')->first();
        $item = $stockTake->items()->first();
        $this->assertEqualsWithDelta(-300000.0, (float) $item->diff_value, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $item->cost_price_snapshot, 0.01);
        $movement = StockMovement::where('product_id', $product->id)
            ->where('ref_id', $stockTake->id)->first();
        $this->assertNotNull($movement);
        $this->assertEqualsWithDelta(100000.0, (float) $movement->unit_cost, 0.01,
            'Movement unit_cost phải = cost_price snapshot lúc balance (100k).');

        // Đổi cost_price sau khi balance — không ảnh hưởng phiếu cũ
        $product->update(['cost_price' => 999999]);
        $item->refresh();
        $this->assertEqualsWithDelta(-300000.0, (float) $item->diff_value, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $item->cost_price_snapshot, 0.01);
        $movement->refresh();
        $this->assertEqualsWithDelta(100000.0, (float) $movement->unit_cost, 0.01);
    }
}
