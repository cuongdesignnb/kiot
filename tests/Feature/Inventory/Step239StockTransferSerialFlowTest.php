<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Branch;
use App\Models\SerialImei;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockMovement;
use App\Services\SerialAvailabilityService;

/**
 * STEP 23.9 — Stock transfer with Serial/IMEI detail.
 */
class Step239StockTransferSerialFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User    $user;
    protected Branch  $branchA;
    protected Branch  $branchB;
    protected Product $serialProduct;
    protected Product $normalProduct;
    protected Product $otherProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->branchA = Branch::firstOrCreate(['name' => 'Branch A 239'], ['phone' => '0900']);
        $this->branchB = Branch::firstOrCreate(['name' => 'Branch B 239'], ['phone' => '0901']);

        $cat = Category::firstOrCreate(['name' => 'Cat 239']);

        $this->serialProduct = Product::create([
            'sku' => 'SP-239-' . uniqid(), 'name' => 'Serial Product 239',
            'cost_price' => 1000000, 'retail_price' => 1500000,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => true, 'category_id' => $cat->id,
        ]);

        $this->normalProduct = Product::create([
            'sku' => 'NP-239-' . uniqid(), 'name' => 'Normal Product 239',
            'cost_price' => 50000, 'retail_price' => 100000,
            'stock_quantity' => 10, 'inventory_total_cost' => 500000,
            'has_serial' => false, 'category_id' => $cat->id,
        ]);

        $this->otherProduct = Product::create([
            'sku' => 'OP-239-' . uniqid(), 'name' => 'Other Serial Product',
            'cost_price' => 500000, 'retail_price' => 700000,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => true, 'category_id' => $cat->id,
        ]);
    }

    private function createSerial(Product $product, string $sn = null, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $sn ?: 'SN-239-' . uniqid(),
            'status' => $status,
            'cost_price' => $product->cost_price,
        ]);
    }

    // ═══ TC-01 ═══

    public function test_transfer_serial_draft_can_be_created_without_serial_ids(): void
    {
        $s1 = $this->createSerial($this->serialProduct, 'SN-D-1');
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'draft',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1],
            ],
        ]);

        $res->assertRedirect();
        $this->assertEquals(1, StockTransfer::count());
        $s1->refresh();
        $this->assertEquals('in_stock', $s1->status);
        $this->serialProduct->refresh();
        $this->assertEquals(1, $this->serialProduct->stock_quantity);
        $this->assertEquals(0, StockMovement::count());
    }

    // ═══ TC-02 ═══

    public function test_transfer_serial_transferring_requires_serial_ids(): void
    {
        $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1],
            ],
        ]);

        $res->assertSessionHasErrors();
        $this->assertEquals(0, StockTransfer::count());
    }

    // ═══ TC-03 ═══

    public function test_transfer_serial_count_mismatch_should_fail(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 2, 'inventory_total_cost' => 2000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 2, 'serial_ids' => [$s1->id]],
            ],
        ]);

        $res->assertSessionHasErrors();
        $this->assertEquals(0, StockTransfer::count());
    }

    // ═══ TC-04 ═══

    public function test_transfer_serial_duplicate_should_fail(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 2, 'serial_ids' => [$s1->id, $s1->id]],
            ],
        ]);

        $res->assertSessionHasErrors();
        $this->assertEquals(0, StockTransfer::count());
    }

    // ═══ TC-05 ═══

    public function test_transfer_serial_wrong_product_should_fail(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $other = $this->createSerial($this->otherProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 2, 'serial_ids' => [$s1->id, $other->id]],
            ],
        ]);

        $res->assertSessionHasErrors();
        $this->assertEquals(0, StockTransfer::count());
    }

    // ═══ TC-06 ═══

    public function test_transfer_serial_not_in_stock_should_fail(): void
    {
        $sold = $this->createSerial($this->serialProduct, 'SN-SOLD', 'sold');
        $this->serialProduct->update(['stock_quantity' => 0, 'inventory_total_cost' => 0]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1, 'serial_ids' => [$sold->id]],
            ],
        ]);

        $res->assertSessionHasErrors();
        $this->assertEquals(0, StockTransfer::count());
    }

    // ═══ TC-07 ═══

    public function test_transfer_serial_transferring_success_should_mark_in_transit(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $s2 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 2, 'inventory_total_cost' => 2000000]);

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 2, 'serial_ids' => [$s1->id, $s2->id]],
            ],
        ]);

        $res->assertRedirect();
        $transfer = StockTransfer::first();
        $this->assertNotNull($transfer);
        $this->assertEquals('transferring', $transfer->status);

        $s1->refresh(); $s2->refresh();
        $this->assertEquals('in_transit', $s1->status);
        $this->assertEquals('in_transit', $s2->status);

        $this->serialProduct->refresh();
        $this->assertEquals(0, $this->serialProduct->stock_quantity);

        $movement = StockMovement::where('type', 'transfer_out')->first();
        $this->assertNotNull($movement);
        $this->assertEquals($this->branchA->id, $movement->branch_id);

        $item = $transfer->items()->first();
        $this->assertEquals([$s1->id, $s2->id], $item->serial_ids);
    }

    // ═══ TC-08 ═══

    public function test_receive_serial_transfer_should_mark_in_stock(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1, 'serial_ids' => [$s1->id]],
            ],
        ]);
        $transfer = StockTransfer::first();
        $s1->refresh();
        $this->assertEquals('in_transit', $s1->status);

        $res = $this->postJson("/stock-transfers/{$transfer->id}/receive", [
            'items' => [['product_id' => $this->serialProduct->id, 'received_quantity' => 1]],
        ]);
        $res->assertOk();

        $transfer->refresh();
        $this->assertEquals('received', $transfer->status);

        $s1->refresh();
        $this->assertEquals('in_stock', $s1->status);

        $movementIn = StockMovement::where('type', 'transfer_in')->first();
        $this->assertNotNull($movementIn);
        $this->assertEquals($this->branchB->id, $movementIn->branch_id);

        $this->serialProduct->refresh();
        $this->assertEquals(1, $this->serialProduct->stock_quantity);
    }

    // ═══ TC-09 ═══

    public function test_receive_serial_transfer_partial_should_fail_for_now(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $s2 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 2, 'inventory_total_cost' => 2000000]);

        $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 2, 'serial_ids' => [$s1->id, $s2->id]],
            ],
        ]);
        $transfer = StockTransfer::first();

        $res = $this->postJson("/stock-transfers/{$transfer->id}/receive", [
            'items' => [['product_id' => $this->serialProduct->id, 'received_quantity' => 1]],
            'receive_note' => 'partial test',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('chưa hỗ trợ nhận một phần', $res->json('message'));
    }

    // ═══ TC-10 ═══

    public function test_cancel_transferring_serial_should_restore_in_stock(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1, 'serial_ids' => [$s1->id]],
            ],
        ]);
        $transfer = StockTransfer::first();
        $s1->refresh();
        $this->assertEquals('in_transit', $s1->status);

        $res = $this->postJson("/stock-transfers/{$transfer->id}/cancel");
        $res->assertOk();

        $transfer->refresh();
        $this->assertEquals('cancelled', $transfer->status);

        $s1->refresh();
        $this->assertEquals('in_stock', $s1->status);

        $this->serialProduct->refresh();
        $this->assertEquals(1, $this->serialProduct->stock_quantity);
    }

    // ═══ TC-11 ═══

    public function test_cancel_received_serial_should_fail_if_serial_already_sold_after_receive(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'received',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1, 'serial_ids' => [$s1->id]],
            ],
        ]);
        $transfer = StockTransfer::first();

        // Simulate sold after receive
        $s1->refresh();
        $s1->update(['status' => 'sold']);

        $res = $this->postJson("/stock-transfers/{$transfer->id}/cancel");
        $res->assertStatus(422);
        $transfer->refresh();
        $this->assertNotEquals('cancelled', $transfer->status);
    }

    // ═══ TC-12 ═══

    public function test_in_transit_serial_cannot_be_sold(): void
    {
        $s1 = $this->createSerial($this->serialProduct);
        $this->serialProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 1000000]);

        $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->serialProduct->id, 'quantity' => 1, 'serial_ids' => [$s1->id]],
            ],
        ]);

        $s1->refresh();
        $this->assertEquals('in_transit', $s1->status);

        SerialAvailabilityService::clearSchemaCache();
        $svc = app(SerialAvailabilityService::class);
        $this->assertFalse($svc->isSellable($s1, $this->serialProduct->id));
        $blocked = $svc->findBlockedIds([$s1->id], $this->serialProduct->id);
        $this->assertContains($s1->id, $blocked);
    }

    // ═══ TC-13 ═══

    public function test_normal_stock_transfer_existing_flow_still_passes(): void
    {
        $stockBefore = $this->normalProduct->stock_quantity;

        $res = $this->post('/stock-transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'items' => [
                ['product_id' => $this->normalProduct->id, 'quantity' => 3],
            ],
        ]);
        $res->assertRedirect();

        $this->normalProduct->refresh();
        $this->assertEquals($stockBefore - 3, $this->normalProduct->stock_quantity);

        $movement = StockMovement::where('type', 'transfer_out')->first();
        $this->assertNotNull($movement);
        $this->assertEquals(3, $movement->qty);
    }
}
