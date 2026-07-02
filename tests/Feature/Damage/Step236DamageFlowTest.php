<?php

namespace Tests\Feature\Damage;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Damage;
use App\Models\DamageItem;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.6 — Damage / Stock Disposal business rules.
 *
 * Bugs covered:
 *  - BUG-1: duplicate product_id trong cùng phiếu.
 *  - BUG-2: client gửi cost_price/total_value sai → backend phải tự tính.
 *  - BUG-3: hàng has_serial completed thiếu serial_ids → fail.
 *  - BUG-4: serial qty mismatch (count != qty) → fail.
 *  - BUG-5: serial không thuộc product / không in_stock → fail (không filter silent).
 *  - BUG-6: duplicate serial trong request → fail.
 *  - BUG-7: cancel completed legacy serial_ids null → block, không đoán.
 */
class Step236DamageFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.6',
            'email'    => 'admin-236-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->branch = Branch::firstOrCreate(['name' => 'Br236'], ['phone' => '0900236001']);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.6']);
        return Product::create([
            'sku'                  => 'P236-' . uniqid(),
            'name'                 => 'Product 23.6',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeSerial(Product $p, string $status = 'in_stock', float $cost = 5000000): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $p->id,
            'serial_number' => 'SN-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $cost,
            'original_cost' => $cost,
        ]);
    }

    /* ════════════════ A. Draft ════════════════ */

    public function test_damage_draft_normal_should_not_change_stock_or_movements(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'draft',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-01',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value'=> 300000,
            ]],
        ]);

        $damage = Damage::where('note', 'TC-23.6-01')->first();
        $this->assertNotNull($damage);
        $this->assertSame('draft', (string) $damage->status);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $damage->id)
            ->where('ref_type', 'App\\Models\\Damage')->count());
    }

    public function test_damage_draft_serial_should_not_change_serial_or_stock(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'draft',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-02',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);

        $this->assertSame(1, Damage::where('note', 'TC-23.6-02')->count());
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $this->assertSame('in_stock', $sA->fresh()->status);
        $this->assertSame('in_stock', $sB->fresh()->status);
    }

    /* ════════════════ B. Completed normal ════════════════ */

    public function test_damage_completed_normal_should_reduce_stock_and_record_adjust_out(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-03',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value'=> 300000,
            ]],
        ]);

        $damage = Damage::where('note', 'TC-23.6-03')->first();
        $this->assertNotNull($damage);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        $item = DamageItem::where('damage_id', $damage->id)->first();
        $this->assertEqualsWithDelta(100000.0, (float) $item->cost_price, 0.01);
        $this->assertEqualsWithDelta(300000.0, (float) $item->total_value, 0.01);

        $movement = StockMovement::where('ref_id', $damage->id)
            ->where('ref_type', 'App\\Models\\Damage')
            ->where('type', 'adjust_out')->first();
        $this->assertNotNull($movement);
        $this->assertSame(3, (int) $movement->qty);
        $this->assertEqualsWithDelta(100000.0, (float) $movement->unit_cost, 0.01);
    }

    public function test_damage_insufficient_stock_should_fail(): void
    {
        $product = $this->makeProduct(false, 5, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-04',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 10,
                'cost_price' => 100000,
                'total_value'=> 1000000,
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-04')->count());
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    public function test_damage_duplicate_product_lines_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-05',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'cost_price' => 100000, 'total_value' => 200000],
                ['product_id' => $product->id, 'qty' => 1, 'cost_price' => 100000, 'total_value' => 100000],
            ],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-05')->count());
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_damage_should_recompute_cost_server_side_not_trust_client(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-06',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 1,        // ← SAI
                'total_value'=> 1,        // ← SAI
            ]],
        ]);

        $damage = Damage::where('note', 'TC-23.6-06')->first();
        $this->assertNotNull($damage);

        $item = DamageItem::where('damage_id', $damage->id)->first();
        $this->assertEqualsWithDelta(100000.0, (float) $item->cost_price, 0.01,
            'cost_price phải = product.cost_price (100k), không phải 1 từ client.');
        $this->assertEqualsWithDelta(300000.0, (float) $item->total_value, 0.01);
        $this->assertEqualsWithDelta(300000.0, (float) $damage->total_value, 0.01);

        $movement = StockMovement::where('ref_id', $damage->id)->where('type', 'adjust_out')->first();
        $this->assertEqualsWithDelta(100000.0, (float) $movement->unit_cost, 0.01);
    }

    /* ════════════════ C. Completed serial ════════════════ */

    public function test_damage_serial_requires_count_equal_qty(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-07',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 2,
                'cost_price' => 5000000,
                'total_value'=> 10000000,
                'serial_ids' => [$sA->id], // chỉ 1 trong khi qty=2
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-07')->count());
        $this->assertSame('in_stock', $sA->fresh()->status);
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }

    public function test_damage_serial_without_serial_ids_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-08',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                // no serial_ids
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-08')->count());
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }

    public function test_damage_serial_not_product_should_fail(): void
    {
        $productA = $this->makeProduct(true, 0, 5000000);
        $productB = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($productA);
        $sB = $this->makeSerial($productB);
        $productA->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-09',
            'items' => [[
                'product_id' => $productA->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                'serial_ids' => [$sB->id], // serial của product B
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-09')->count());
        $this->assertSame('in_stock', $sA->fresh()->status);
        $this->assertSame('in_stock', $sB->fresh()->status);
    }

    public function test_damage_serial_not_in_stock_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product, 'sold');

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-10',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-10')->count());
        $this->assertSame('sold', $sA->fresh()->status);
    }

    public function test_damage_serial_duplicate_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-11',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 2,
                'cost_price' => 5000000,
                'total_value'=> 10000000,
                'serial_ids' => [$sA->id, $sA->id], // duplicate
            ]],
        ]);

        $this->assertSame(0, Damage::where('note', 'TC-23.6-11')->count());
        $this->assertSame('in_stock', $sA->fresh()->status);
    }

    public function test_damage_serial_success_should_mark_defective_and_reduce_stock(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-12',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);

        $damage = Damage::where('note', 'TC-23.6-12')->first();
        $this->assertNotNull($damage);
        $this->assertSame('defective', $sA->fresh()->status);
        $this->assertSame('in_stock', $sB->fresh()->status);

        $item = DamageItem::where('damage_id', $damage->id)->first();
        $this->assertSame([$sA->id], $item->serial_ids);

        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
    }

    /* ════════════════ D. Cancel ════════════════ */

    public function test_cancel_draft_damage_should_not_change_stock(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'draft',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-13',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value'=> 300000,
            ]],
        ]);
        $damage = Damage::where('note', 'TC-23.6-13')->first();

        $this->actingAs($this->admin)->post(route('damages.cancel', $damage->id));

        $damage->refresh();
        $this->assertSame('cancelled', (string) $damage->status);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $damage->id)->count());
    }

    public function test_cancel_completed_normal_should_restore_stock_and_record_adjust_in(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-14',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value'=> 300000,
            ]],
        ]);
        $damage = Damage::where('note', 'TC-23.6-14')->first();
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->post(route('damages.cancel', $damage->id));

        $product->refresh();
        $this->assertSame(10, (int) $product->stock_quantity, 'Stock phải về 10 sau cancel.');
        $this->assertSame(1, StockMovement::where('ref_id', $damage->id)->where('type', 'adjust_in')->count());
        $this->assertSame(1, StockMovement::where('ref_id', $damage->id)->where('type', 'adjust_out')->count());
    }

    public function test_cancel_completed_serial_should_restore_serial_in_stock(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-15',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'cost_price' => 5000000,
                'total_value'=> 5000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);
        $damage = Damage::where('note', 'TC-23.6-15')->first();
        $this->assertSame('defective', $sA->fresh()->status);

        $this->actingAs($this->admin)->post(route('damages.cancel', $damage->id));

        $this->assertSame('in_stock', $sA->fresh()->status, 'Serial phải về in_stock.');
        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
    }

    public function test_cancel_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'note' => 'TC-23.6-16',
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value'=> 300000,
            ]],
        ]);
        $damage = Damage::where('note', 'TC-23.6-16')->first();

        $this->actingAs($this->admin)->post(route('damages.cancel', $damage->id));
        $stockAfter1 = (int) $product->fresh()->stock_quantity;
        $movementCount1 = StockMovement::where('ref_id', $damage->id)->count();

        $resp = $this->actingAs($this->admin)->postJson(route('damages.cancel', $damage->id));
        $resp->assertStatus(422);
        $this->assertSame($stockAfter1, (int) $product->fresh()->stock_quantity, 'Stock không đổi sau cancel lần 2.');
        $this->assertSame($movementCount1, StockMovement::where('ref_id', $damage->id)->count(),
            'Không được tạo movement double.');
    }

    public function test_cancel_legacy_serial_damage_without_serial_ids_should_not_guess(): void
    {
        // Tạo legacy damage trực tiếp qua model (mô phỏng dữ liệu cũ trước Step 23.6)
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product, 'defective');
        $product->update(['stock_quantity' => 0, 'inventory_total_cost' => 0]);

        $damage = Damage::create([
            'code' => 'XH-LEG-' . uniqid(),
            'branch_id' => $this->branch->id,
            'status' => 'completed',
            'created_by_name' => 'Legacy',
            'destroyed_by_name' => 'Legacy',
            'destroyed_date' => now(),
            'total_qty' => 1,
            'total_value' => 5000000,
            'note' => 'TC-23.6-17',
        ]);
        DamageItem::create([
            'damage_id'   => $damage->id,
            'product_id'  => $product->id,
            'qty'         => 1,
            'cost_price'  => 5000000,
            'total_value' => 5000000,
            'serial_ids'  => null, // ← LEGACY thiếu
        ]);

        // Cancel — phải bị chặn
        $resp = $this->actingAs($this->admin)->postJson(route('damages.cancel', $damage->id));
        $resp->assertStatus(422);

        $damage->refresh();
        $this->assertNotSame('cancelled', (string) $damage->status,
            'Phiếu legacy không được tự cancel khi thiếu serial_ids.');
        $this->assertSame('defective', $sA->fresh()->status, 'Serial KHÔNG được tự đổi về in_stock.');
        $this->assertSame(0, (int) $product->fresh()->stock_quantity, 'Stock không được tự cộng.');
    }
}
