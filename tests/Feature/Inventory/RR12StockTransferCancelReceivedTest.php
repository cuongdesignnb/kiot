<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\MovingAvgCostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-12: Hủy phiếu chuyển kho `received` trong mô hình product-level (chưa multi-warehouse).
 *
 * Phân tích logic StockTransferController@cancel:
 *   - Đảo destination: applySale($product, $received_qty) — current cost
 *   - Restore source:  applyPurchase($product, $item->quantity) — current cost
 *
 * Quan sát:
 *   1. Tổng tồn (numerical) trong product-level luôn về initial: -Q + R - R + Q = 0. ✅
 *   2. Cost integrity bị lệch nếu BQ thay đổi giữa các pha (cancel dùng CURRENT cost
 *      thay vì cost snapshot lúc transfer_out). Schema thiếu cost snapshot.
 *   3. Partial cancel "fabricate" đơn vị missing (Q != R): cộng đủ Q về source dù chỉ R đã nhận.
 *   4. Idempotent đã có guard.
 */
class RR12StockTransferCancelReceivedTest extends TestCase
{
    use DatabaseTransactions;

    private User    $admin;
    private Branch  $fromBranch;
    private Branch  $toBranch;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR12',
            'email'    => 'admin-rr12-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->fromBranch = Branch::create([
            'name'  => 'Branch Source RR12 ' . uniqid(),
            'phone' => '0900' . rand(100000, 999999),
        ]);
        $this->toBranch = Branch::create([
            'name'  => 'Branch Dest RR12 ' . uniqid(),
            'phone' => '0901' . rand(100000, 999999),
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat RR12']);

        $this->product = Product::create([
            'sku'                  => 'PROD-RR12-' . uniqid(),
            'name'                 => 'Product RR12',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR12-01: Cancel fully received đơn giản giữ stock + cost về initial
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_fully_received_simple_keeps_stock_and_cost(): void
    {
        // store transferring qty=3
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->fromBranch->id,
            'to_branch_id'   => $this->toBranch->id,
            'status'         => 'transferring',
            'items'          => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 100000]],
        ]);

        $transfer = StockTransfer::latest()->first();
        $this->assertNotNull($transfer);
        $this->assertSame('transferring', $transfer->status);

        // receive full
        $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', ['id' => $transfer->id]), [
            'items' => [['product_id' => $this->product->id, 'received_quantity' => 3]],
        ]);

        $this->product->refresh();
        $this->assertSame(10, (int) $this->product->stock_quantity, 'After receive full, stock = initial');

        // cancel
        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));

        $this->product->refresh();
        $this->assertSame(10, (int) $this->product->stock_quantity, 'After cancel, stock back to initial');
        $this->assertSame(1000000.0, (float) $this->product->inventory_total_cost, 'inventory_total_cost back to 1M');
        $this->assertSame(100000.0, (float) $this->product->cost_price, 'cost_price stays 100k');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR12-02: Partial received cancel — stock số học OK trong product-level
     *  Document behavior: 2 đơn vị "missing" cộng lại không có write-off.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_partial_received_keeps_stock_in_product_level(): void
    {
        // store qty=5 transferring
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->fromBranch->id,
            'to_branch_id'   => $this->toBranch->id,
            'status'         => 'transferring',
            'items'          => [['product_id' => $this->product->id, 'quantity' => 5, 'price' => 100000]],
        ]);

        $transfer = StockTransfer::latest()->first();

        // receive partial recv=3 (Q=5, R=3) — cần receive_note vì partial
        $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', ['id' => $transfer->id]), [
            'items'         => [['product_id' => $this->product->id, 'received_quantity' => 3]],
            'receive_note'  => '2 đơn vị bị hỏng dọc đường — kiểm thử RR-12',
        ]);

        $this->product->refresh();
        $this->assertSame(8, (int) $this->product->stock_quantity, 'Partial: stock=10-5+3=8');

        // cancel — đảo destination 3, restore source 5 → net = +2 từ 8 = 10
        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));

        $this->product->refresh();
        // Trong product-level architecture, tổng tồn vẫn về initial. Đây là behavior
        // hiện có — limitation kiến trúc, không phải bug numerical.
        $this->assertSame(10, (int) $this->product->stock_quantity,
            'Product-level: stock về initial 10 dù partial. 2 đơn vị "missing" được cộng lại không có write-off.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR12-03: Cancel idempotent — guard đã có sẵn, lần 2 không đổi state
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_received_should_be_idempotent(): void
    {
        // setup full received
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->fromBranch->id,
            'to_branch_id'   => $this->toBranch->id,
            'status'         => 'received',
            'items'          => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 100000]],
        ]);
        $transfer = StockTransfer::latest()->first();

        // cancel lần 1
        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));

        $this->product->refresh();
        $stockAfter1 = (int) $this->product->stock_quantity;
        $totalAfter1 = (float) $this->product->inventory_total_cost;
        $movementsAfter1 = StockMovement::where('product_id', $this->product->id)->count();

        // cancel lần 2 — phải bị guard reject
        $response2 = $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));
        $response2->assertStatus(422);

        $this->product->refresh();
        $this->assertSame($stockAfter1, (int) $this->product->stock_quantity, 'Stock unchanged after 2nd cancel');
        $this->assertSame($totalAfter1, (float) $this->product->inventory_total_cost, 'total_cost unchanged');
        $this->assertSame($movementsAfter1, StockMovement::where('product_id', $this->product->id)->count(),
            'Không tạo movement đảo thêm khi cancel lần 2');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR12-04: Cost integrity FAIL khi BQ thay đổi giữa các pha
     *  Đây là bug rõ rệt — cancel dùng current cost, không snapshot.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_received_should_preserve_cost_when_avg_changes_between_phases(): void
    {
        // 1. Store transferring qty=3 (cost=100k)
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->fromBranch->id,
            'to_branch_id'   => $this->toBranch->id,
            'status'         => 'transferring',
            'items'          => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 100000]],
        ]);
        $transfer = StockTransfer::latest()->first();

        $this->product->refresh();
        $this->assertSame(7, (int) $this->product->stock_quantity);
        $this->assertSame(700000.0, (float) $this->product->inventory_total_cost);

        // 2. Mua thêm 5 @ 200k giữa các pha — BQ chuyển sang 141.67k
        MovingAvgCostingService::applyPurchase($this->product, 5, 200000);
        $this->product->refresh();
        $this->assertSame(12, (int) $this->product->stock_quantity);
        $this->assertSame(1700000.0, (float) $this->product->inventory_total_cost);
        // cost_price = 1700/12 = 141666.67 (round 2)
        $this->assertEqualsWithDelta(141666.67, (float) $this->product->cost_price, 1.0);

        // 3. Receive full 3 — controller sẽ dùng current cost 141.67k
        $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', ['id' => $transfer->id]), [
            'items' => [['product_id' => $this->product->id, 'received_quantity' => 3]],
        ]);

        // 4. Cancel
        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));

        $this->product->refresh();
        $stockAfter = (int) $this->product->stock_quantity;
        $totalAfter = (float) $this->product->inventory_total_cost;

        // Đáng lẽ (đúng nghiệp vụ): source 10 @ 100k + mua 5 @ 200k = 15 đơn vị, total = 2_000_000.
        $this->assertSame(15, $stockAfter, 'Stock = 10 ban đầu + 5 mua mới');
        $this->assertEqualsWithDelta(2000000.0, $totalAfter, 1.0,
            'inventory_total_cost phải = 1M (source 100k×10) + 1M (mua mới 200k×5) = 2M. '
            . 'Bug RR-12: cancel dùng current cost (141.67k) thay vì cost snapshot lúc transfer_out (100k), '
            . 'nên total bị inflate. Cần lưu cost_at_transfer trên stock_transfer_items.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR12-05: Cancel transferring (chưa received) restore source đúng
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_transferring_restores_source_stock(): void
    {
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->fromBranch->id,
            'to_branch_id'   => $this->toBranch->id,
            'status'         => 'transferring',
            'items'          => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 100000]],
        ]);
        $transfer = StockTransfer::latest()->first();

        $this->product->refresh();
        $this->assertSame(7, (int) $this->product->stock_quantity, 'After store transferring: stock=7');

        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', ['id' => $transfer->id]));

        $this->product->refresh();
        $this->assertSame(10, (int) $this->product->stock_quantity, 'Cancel transferring: stock=10');
        $this->assertSame(1000000.0, (float) $this->product->inventory_total_cost, 'total back to 1M');
        $this->assertSame(100000.0, (float) $this->product->cost_price, 'cost stays 100k');
    }
}
