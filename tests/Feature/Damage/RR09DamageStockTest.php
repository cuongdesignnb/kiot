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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RR-09: Damage / Xuất hủy chưa qua MovingAvgCostingService và StockMovementService.
 *
 * Bug ở DamageController@store dòng 119:
 *   $product->stock_quantity -= $item['qty'];
 *   $product->save();
 *
 *   - KHÔNG cập nhật inventory_total_cost → BQ inflate
 *   - KHÔNG ghi StockMovement → thẻ kho thiếu
 *   - KHÔNG xử lý Serial/IMEI
 *   - KHÔNG có cancel/rollback
 */
class RR09DamageStockTest extends TestCase
{
    use DatabaseTransactions;

    private User    $admin;
    private Branch  $branch;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR09',
            'email'    => 'admin-rr09-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->branch = Branch::firstOrCreate(
            ['name' => 'Branch RR09'],
            ['address' => 'Test addr']
        );

        $category = Category::firstOrCreate(['name' => 'Cat RR09']);

        $this->product = Product::create([
            'sku'                  => 'PROD-RR09-' . uniqid(),
            'name'                 => 'Product RR09',
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
     *  TC-RR09-01: Damage thường phải giảm cả stock_quantity VÀ inventory_total_cost.
     *  Bug hiện tại: chỉ giảm stock_quantity → cost_price inflate.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_damage_should_decrease_stock_and_inventory_total_cost(): void
    {
        $this->actingAs($this->admin)->post(route('damages.store'), [
            'code'      => 'XH-RR09-' . uniqid(),
            'branch_id' => $this->branch->id,
            'status'    => 'completed',
            'note'      => 'RR-09 TC-01',
            'items'     => [[
                'product_id' => $this->product->id,
                'qty'        => 3,
                'cost_price' => 100000,
                'total_value' => 300000,
            ]],
        ]);

        $this->product->refresh();

        $this->assertSame(7, (int) $this->product->stock_quantity,
            'Stock phải giảm còn 7');
        $this->assertSame(700000.0, (float) $this->product->inventory_total_cost,
            'inventory_total_cost phải giảm theo BQ (1M - 3*100k = 700k). '
            . 'Hiện tại không update → cost_price bị inflate.');
        $this->assertSame(100000.0, (float) $this->product->cost_price,
            'cost_price = total/qty phải giữ 100k. Nếu total không update, sẽ inflate ~142,857.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR09-02: Damage phải ghi StockMovement
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_damage_should_create_stock_movement(): void
    {
        $movementsBefore = StockMovement::where('product_id', $this->product->id)->count();

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'code'      => 'XH-RR09-' . uniqid(),
            'branch_id' => $this->branch->id,
            'status'    => 'completed',
            'items'     => [[
                'product_id'  => $this->product->id,
                'qty'         => 3,
                'cost_price'  => 100000,
                'total_value' => 300000,
            ]],
        ]);

        $movementsAfter = StockMovement::where('product_id', $this->product->id)->count();

        $this->assertGreaterThan($movementsBefore, $movementsAfter,
            'Damage completed phải tạo StockMovement. Hiện DamageController không gọi StockMovementService.');

        $movement = StockMovement::where('product_id', $this->product->id)
            ->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame(3, (int) $movement->qty,
            'StockMovement qty phải = 3');
        $this->assertContains($movement->type, ['adjust_out', 'damage_out', 'out_invoice'],
            'StockMovement type phải là adjust_out/damage_out (giảm tồn). Thực tế: ' . $movement->type);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR09-03: Không cho Damage quá tồn (regression — đã có guard sẵn)
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_damage_should_not_allow_quantity_greater_than_stock(): void
    {
        $stockBefore = $this->product->stock_quantity;

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'code'      => 'XH-RR09-OVER-' . uniqid(),
            'branch_id' => $this->branch->id,
            'status'    => 'completed',
            'items'     => [[
                'product_id'  => $this->product->id,
                'qty'         => 15, // > stock_quantity (10)
                'cost_price'  => 100000,
                'total_value' => 1500000,
            ]],
        ]);

        $this->product->refresh();

        $this->assertSame((int) $stockBefore, (int) $this->product->stock_quantity,
            'Stock không được thay đổi khi Damage qty > stock');

        // Không có Damage 'completed' nào active của product này
        $hasActiveDamage = Damage::whereHas('items', function ($q) {
            $q->where('product_id', $this->product->id);
        })->where('status', 'completed')->exists();

        $this->assertFalse($hasActiveDamage,
            'Không được tạo Damage status=completed khi qty > stock');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR09-04: Damage Serial/IMEI phải chỉ ảnh hưởng serial được chọn
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_damage_serial_should_only_affect_selected_serial(): void
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR09 Serial']);

        $serialProduct = Product::create([
            'sku'                  => 'PROD-RR09S-' . uniqid(),
            'name'                 => 'Product RR09 Serial',
            'cost_price'           => 5000000,
            'retail_price'         => 8000000,
            'stock_quantity'       => 0,
            'inventory_total_cost' => 0,
            'is_active'            => true,
            'has_serial'           => true,
            'category_id'          => $category->id,
        ]);

        $serialA = SerialImei::create([
            'product_id'    => $serialProduct->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $serialB = SerialImei::create([
            'product_id'    => $serialProduct->id,
            'serial_number' => 'SN-B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);

        // Đồng bộ stock_quantity = 2 + total_cost = 10M
        $serialProduct->update([
            'stock_quantity'       => 2,
            'inventory_total_cost' => 10000000,
        ]);

        $this->actingAs($this->admin)->post(route('damages.store'), [
            'code'      => 'XH-RR09S-' . uniqid(),
            'branch_id' => $this->branch->id,
            'status'    => 'completed',
            'items'     => [[
                'product_id'  => $serialProduct->id,
                'qty'         => 1,
                'cost_price'  => 5000000,
                'total_value' => 5000000,
                'serial_ids'  => [$serialA->id],
            ]],
        ]);

        $serialA->refresh();
        $serialB->refresh();

        $this->assertContains($serialA->status, ['damaged', 'defective', 'returned'],
            'Serial A (được chọn) phải bị đổi status sang damaged/defective. '
            . 'Thực tế: ' . $serialA->status . '. DamageController không xử lý serial_ids.');
        $this->assertSame('in_stock', $serialB->status,
            'Serial B (không được chọn) phải giữ nguyên in_stock');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR09-05: Phải có cancel/rollback Damage
     *  - DamageStatus enum có CANCELLED.
     *  - Nhưng controller không có method cancel + route chưa đăng ký.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_damage_should_support_cancel_with_rollback(): void
    {
        $hasCancelMethod = method_exists(\App\Http\Controllers\DamageController::class, 'cancel')
            || method_exists(\App\Http\Controllers\DamageController::class, 'destroy');

        // Dùng Route::has() để check tồn tại — route() throw khi thiếu param
        // {damage} dù route tồn tại, sẽ cho false positive.
        $hasCancelRoute = \Illuminate\Support\Facades\Route::has('damages.cancel')
            || \Illuminate\Support\Facades\Route::has('damages.destroy');

        $this->assertTrue($hasCancelMethod && $hasCancelRoute,
            'DamageController phải có method cancel/destroy + route tương ứng để rollback Damage. '
            . 'Hiện tại: method=' . ($hasCancelMethod ? 'yes' : 'no')
            . ', route=' . ($hasCancelRoute ? 'yes' : 'no')
            . '. DamageStatus enum đã có CANCELLED nhưng controller không implement.');
    }
}
