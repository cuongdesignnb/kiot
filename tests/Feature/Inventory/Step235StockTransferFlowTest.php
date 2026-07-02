<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.5 — Stock Transfer business rules.
 *
 * Bugs covered:
 *  - BUG-1: from_branch_id == to_branch_id phải fail.
 *  - BUG-2: duplicate product_id trong cùng phiếu phải fail.
 *  - BUG-3: chuyển hàng has_serial transferring/received phải fail (chưa có serial detail).
 *  - BUG-4: receive() KHÔNG được clamp âm thầm (qty<0 hay qty>quantity → 422).
 *  - BUG-5: backend tự tính total_price từ cost_price * quantity, không tin client.
 */
class Step235StockTransferFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.5',
            'email'    => 'admin-235-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->branchA = Branch::firstOrCreate(['name' => 'Br235-A'], ['phone' => '0900235001']);
        $this->branchB = Branch::firstOrCreate(['name' => 'Br235-B'], ['phone' => '0900235002']);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.5']);
        return Product::create([
            'sku'                  => 'P235-' . uniqid(),
            'name'                 => 'Product 23.5',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    /* ════════════════ A. Validation ════════════════ */

    public function test_transfer_from_branch_must_differ_to_branch(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $resp = $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchA->id, // SAME
            'status'         => 'transferring',
            'note'           => 'TC-23.5-01',
            'items'          => [['product_id' => $product->id, 'quantity' => 2, 'price' => 200000]],
        ]);

        $resp->assertSessionHasErrors('to_branch_id');
        $this->assertSame(0, StockTransfer::where('note', 'TC-23.5-01')->count());
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_transfer_draft_should_not_change_stock_or_movements(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'draft',
            'note'           => 'TC-23.5-02',
            'items'          => [['product_id' => $product->id, 'quantity' => 3, 'price' => 300000]],
        ]);

        $transfer = StockTransfer::where('note', 'TC-23.5-02')->first();
        $this->assertNotNull($transfer);
        $this->assertSame('draft', $transfer->status);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $transfer->id)
            ->where('ref_type', 'App\\Models\\StockTransfer')->count());
    }

    public function test_transfer_duplicate_product_lines_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'TC-23.5-03',
            'items'          => [
                ['product_id' => $product->id, 'quantity' => 2, 'price' => 200000],
                ['product_id' => $product->id, 'quantity' => 1, 'price' => 100000],
            ],
        ]);

        $this->assertSame(0, StockTransfer::where('note', 'TC-23.5-03')->count());
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ B. Transferring + received ════════════════ */

    public function test_transfer_transferring_should_deduct_stock_and_record_out_movement(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'TC-23.5-04',
            'items'          => [['product_id' => $product->id, 'quantity' => 3, 'price' => 999999]], // price client SAI
        ]);

        $transfer = StockTransfer::where('note', 'TC-23.5-04')->first();
        $this->assertNotNull($transfer);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, StockMovement::where('ref_id', $transfer->id)
            ->where('type', 'transfer_out')->count());
        // Backend tự tính total_price = qty * cost_price = 3 * 100000
        $this->assertEqualsWithDelta(300000.0, (float) $transfer->total_price, 0.01,
            'Backend phải tự tính total_price từ cost_price, không tin client.');
    }

    public function test_transfer_received_immediately_should_record_out_and_in_movements(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'received',
            'note'           => 'TC-23.5-05',
            'items'          => [['product_id' => $product->id, 'quantity' => 3, 'price' => 300000]],
        ]);

        $transfer = StockTransfer::where('note', 'TC-23.5-05')->first();
        $this->assertSame(10, (int) $product->fresh()->stock_quantity, 'Tồn global = 10 (trừ 3 + cộng 3).');
        $this->assertSame(1, StockMovement::where('ref_id', $transfer->id)->where('type', 'transfer_out')->count());
        $this->assertSame(1, StockMovement::where('ref_id', $transfer->id)->where('type', 'transfer_in')->count());
    }

    public function test_transfer_insufficient_stock_should_fail(): void
    {
        $product = $this->makeProduct(false, 5, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'TC-23.5-06',
            'items'          => [['product_id' => $product->id, 'quantity' => 10, 'price' => 1000000]],
        ]);

        $this->assertSame(0, StockTransfer::where('note', 'TC-23.5-06')->count());
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ C. Receive ════════════════ */

    private function createTransferring(Product $product, int $qty, string $note): StockTransfer
    {
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => $note,
            'items'          => [['product_id' => $product->id, 'quantity' => $qty, 'price' => $qty * 100000]],
        ]);
        return StockTransfer::where('note', $note)->firstOrFail();
    }

    public function test_receive_full_should_add_stock_and_record_in_movement(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-07');
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->post(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
        ]);

        $product->refresh();
        $this->assertSame(10, (int) $product->stock_quantity);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(1, StockMovement::where('ref_id', $transfer->id)->where('type', 'transfer_in')->count());
    }

    public function test_receive_partial_requires_note(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 5, 'TC-23.5-08');

        // Không có receive_note → 422
        $resp = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
        ]);
        $resp->assertStatus(422);
        $this->assertSame('transferring', $transfer->fresh()->status, 'Status không đổi khi nhận partial thiếu note.');

        // Có note → OK
        $resp2 = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
            'receive_note' => 'Mất 2 cái',
        ]);
        $resp2->assertStatus(200);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(8, (int) $product->fresh()->stock_quantity, '5 - 5 + 3 = ... wait: 10 - 5 + 3 = 8.');
    }

    public function test_receive_negative_quantity_should_fail_not_clamp(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-09');

        $resp = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => -1]],
        ]);
        $resp->assertStatus(422);
        $this->assertSame('transferring', $transfer->fresh()->status);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity, 'Stock không đổi khi receive fail.');
    }

    public function test_receive_over_quantity_should_fail_not_clamp(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-10');

        $resp = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 99]],
        ]);
        $resp->assertStatus(422);
        $this->assertSame('transferring', $transfer->fresh()->status);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);
    }

    public function test_receive_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-11');

        $r1 = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
        ]);
        $r1->assertStatus(200);

        $r2 = $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
        ]);
        $r2->assertStatus(422);
        $this->assertSame(1, StockMovement::where('ref_id', $transfer->id)->where('type', 'transfer_in')->count());
    }

    /* ════════════════ D. Cancel ════════════════ */

    public function test_cancel_draft_should_not_change_stock(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'draft',
            'note'           => 'TC-23.5-12',
            'items'          => [['product_id' => $product->id, 'quantity' => 3, 'price' => 300000]],
        ]);
        $transfer = StockTransfer::where('note', 'TC-23.5-12')->first();

        $resp = $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', $transfer->id));
        $resp->assertStatus(200);
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $transfer->id)->count());
    }

    public function test_cancel_transferring_should_restore_stock(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-13');
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', $transfer->id))->assertStatus(200);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity, 'Tồn về lại 10.');
        $this->assertSame('cancelled', $transfer->fresh()->status);
    }

    public function test_cancel_received_should_reverse_both_sides(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'received',
            'note'           => 'TC-23.5-14',
            'items'          => [['product_id' => $product->id, 'quantity' => 3, 'price' => 300000]],
        ]);
        $transfer = StockTransfer::where('note', 'TC-23.5-14')->first();
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', $transfer->id))->assertStatus(200);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity,
            'Tồn vẫn 10 (đảo cả transfer_in và transfer_out).');
        $this->assertSame('cancelled', $transfer->fresh()->status);
    }

    public function test_cancel_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $transfer = $this->createTransferring($product, 3, 'TC-23.5-15');

        $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', $transfer->id))->assertStatus(200);
        $stockAfter1 = (int) $product->fresh()->stock_quantity;

        $r2 = $this->actingAs($this->admin)->postJson(route('stock-transfers.cancel', $transfer->id));
        $r2->assertStatus(422);
        $this->assertSame($stockAfter1, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ E. Serial ════════════════ */

    public function test_transfer_serial_without_serial_detail_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'TC-23.5-16',
            'items'          => [['product_id' => $product->id, 'quantity' => 1, 'price' => 5000000]],
        ]);

        $this->assertSame(0, StockTransfer::where('note', 'TC-23.5-16')->count(),
            'Hàng has_serial chưa hỗ trợ chuyển kho — phải bị chặn.');
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }

    public function test_transfer_serial_draft_should_not_change_stock(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000, 'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'draft',
            'note'           => 'TC-23.5-17',
            'items'          => [['product_id' => $product->id, 'quantity' => 1, 'price' => 5000000]],
        ]);

        $this->assertSame(1, StockTransfer::where('note', 'TC-23.5-17')->count(), 'Draft serial vẫn cho phép.');
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ F. Cost snapshot ════════════════ */

    public function test_receive_uses_cost_at_transfer_not_current_cost(): void
    {
        $product = $this->makeProduct(false, 10, 100000);

        $transfer = $this->createTransferring($product, 3, 'TC-23.5-18');

        // Đổi cost_price product sau khi xuất nhưng trước khi nhận
        $product->update(['cost_price' => 999999]);

        $this->actingAs($this->admin)->postJson(route('stock-transfers.receive', $transfer->id), [
            'items' => [['product_id' => $product->id, 'received_quantity' => 3]],
        ])->assertStatus(200);

        $movementIn = StockMovement::where('ref_id', $transfer->id)
            ->where('type', 'transfer_in')->first();
        $this->assertNotNull($movementIn);
        $this->assertEqualsWithDelta(100000.0, (float) $movementIn->unit_cost, 0.01,
            'transfer_in phải dùng cost_at_transfer (100k snapshot), không phải 999k current.');

        $item = StockTransferItem::where('stock_transfer_id', $transfer->id)->first();
        $this->assertEqualsWithDelta(100000.0, (float) $item->cost_at_transfer, 0.01);
    }
}
