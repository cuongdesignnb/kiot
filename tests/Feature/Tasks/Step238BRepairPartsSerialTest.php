<?php

namespace Tests\Feature\Tasks;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskPart;
use App\Models\StockMovement;
use App\Services\TaskService;

class Step238BRepairPartsSerialTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $normalPart;
    protected Product $serialPart;
    protected Task $repair;
    protected SerialImei $partSerial1;
    protected SerialImei $partSerial2;
    protected SerialImei $partSerial3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $category = Category::firstOrCreate(['name' => 'Cat 238B']);

        // Sản phẩm thiết bị (máy sửa chữa) — internal repair cần serial
        $deviceProduct = Product::create([
            'sku' => 'DEVICE-238B-' . uniqid(), 'name' => 'Device 238B',
            'cost_price' => 5000000, 'retail_price' => 8000000,
            'stock_quantity' => 1, 'inventory_total_cost' => 5000000,
            'has_serial' => true, 'category_id' => $category->id,
        ]);
        $deviceSerial = SerialImei::create([
            'product_id' => $deviceProduct->id, 'serial_number' => 'DEV-238B-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000,
        ]);

        // Linh kiện thường (no serial)
        $this->normalPart = Product::create([
            'sku' => 'PART-NORMAL-' . uniqid(), 'name' => 'Linh kiện thường',
            'cost_price' => 50000, 'retail_price' => 100000,
            'stock_quantity' => 10, 'inventory_total_cost' => 500000,
            'has_serial' => false, 'category_id' => $category->id,
        ]);

        // Linh kiện có serial
        $this->serialPart = Product::create([
            'sku' => 'PART-SERIAL-' . uniqid(), 'name' => 'Linh kiện Serial',
            'cost_price' => 200000, 'retail_price' => 400000,
            'stock_quantity' => 3, 'inventory_total_cost' => 600000,
            'has_serial' => true, 'category_id' => $category->id,
        ]);

        $this->partSerial1 = SerialImei::create([
            'product_id' => $this->serialPart->id, 'serial_number' => 'PS1-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 200000,
        ]);
        $this->partSerial2 = SerialImei::create([
            'product_id' => $this->serialPart->id, 'serial_number' => 'PS2-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 200000,
        ]);
        $this->partSerial3 = SerialImei::create([
            'product_id' => $this->serialPart->id, 'serial_number' => 'PS3-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 200000,
        ]);

        // Tạo phiếu sửa chữa nội bộ
        $service = app(TaskService::class);
        $this->repair = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $deviceSerial->id,
            'issue_description' => 'Test 238B',
            'created_by' => $this->user->id,
        ]);
    }

    // ═══ TC-01: Normal part (no serial) still works ═══

    public function test_add_normal_part_without_serial_should_still_work(): void
    {
        $service = app(TaskService::class);
        $part = $service->addPart($this->repair, $this->normalPart->id, 2);

        $this->normalPart->refresh();
        $this->assertEquals(8, $this->normalPart->stock_quantity);

        // No serial_ids stored
        $this->assertNull($part->serial_ids);

        // Stock movement exists
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->normalPart->id,
            'type' => 'repair_out',
            'qty' => 2,
        ]);
    }

    // ═══ TC-02: Serial part without serial_ids should fail ═══

    public function test_add_serial_part_without_serial_ids_should_fail(): void
    {
        $service = app(TaskService::class);
        $stockBefore = $this->serialPart->stock_quantity;

        $this->expectException(\RuntimeException::class);
        $service->addPart($this->repair, $this->serialPart->id, 1);

        // Stock unchanged
        $this->serialPart->refresh();
        $this->assertEquals($stockBefore, $this->serialPart->stock_quantity);

        // No task_part created
        $this->assertEquals(0, TaskPart::where('product_id', $this->serialPart->id)->count());

        // Serial still in_stock
        $this->partSerial1->refresh();
        $this->assertEquals('in_stock', $this->partSerial1->status);
    }

    // ═══ TC-03: Serial count mismatch should fail ═══

    public function test_add_serial_part_count_mismatch_should_fail(): void
    {
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        // qty=2, but only 1 serial
        $service->addPart($this->repair, $this->serialPart->id, 2, null, null, [$this->partSerial1->id]);
    }

    // ═══ TC-04: Duplicate serial IDs should fail ═══

    public function test_add_serial_part_duplicate_serial_should_fail(): void
    {
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        // qty=2, but same serial twice
        $service->addPart($this->repair, $this->serialPart->id, 2, null, null, [
            $this->partSerial1->id, $this->partSerial1->id,
        ]);
    }

    // ═══ TC-05: Serial from wrong product should fail ═══

    public function test_add_serial_part_wrong_product_should_fail(): void
    {
        // Create serial for a different product
        $otherProduct = Product::create([
            'sku' => 'OTHER-' . uniqid(), 'name' => 'Other Product',
            'cost_price' => 100000, 'retail_price' => 200000,
            'stock_quantity' => 1, 'inventory_total_cost' => 100000,
            'has_serial' => true,
        ]);
        $otherSerial = SerialImei::create([
            'product_id' => $otherProduct->id, 'serial_number' => 'OTHER-SN-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 100000,
        ]);

        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->addPart($this->repair, $this->serialPart->id, 1, null, null, [$otherSerial->id]);
    }

    // ═══ TC-06: Serial not in_stock should fail ═══

    public function test_add_serial_part_not_in_stock_should_fail(): void
    {
        // Mark serial as sold
        $this->partSerial1->update(['status' => 'sold']);

        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->addPart($this->repair, $this->serialPart->id, 1, null, null, [$this->partSerial1->id]);
    }

    // ═══ TC-07: Serial part success — marks serial used and reduces stock ═══

    public function test_add_serial_part_success_should_mark_serial_used_and_reduce_stock(): void
    {
        $service = app(TaskService::class);
        $stockBefore = $this->serialPart->stock_quantity;

        $part = $service->addPart($this->repair, $this->serialPart->id, 1, null, null, [$this->partSerial1->id]);

        // serial_ids stored
        $this->assertEquals([$this->partSerial1->id], $part->serial_ids);

        // Serial marked used_for_repair
        $this->partSerial1->refresh();
        $this->assertEquals('used_for_repair', $this->partSerial1->status);

        // Stock reduced
        $this->serialPart->refresh();
        $this->assertEquals($stockBefore - 1, $this->serialPart->stock_quantity);

        // Stock movement
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->serialPart->id,
            'type' => 'repair_out',
            'qty' => 1,
        ]);
    }

    // ═══ TC-08: Remove serial part restores serial and stock ═══

    public function test_remove_serial_part_should_restore_serial_and_stock(): void
    {
        $service = app(TaskService::class);
        $stockBefore = $this->serialPart->stock_quantity;

        // Add
        $part = $service->addPart($this->repair, $this->serialPart->id, 1, null, null, [$this->partSerial1->id]);

        $this->partSerial1->refresh();
        $this->assertEquals('used_for_repair', $this->partSerial1->status);

        // Remove
        $service->removePart($part);

        // Serial back to in_stock
        $this->partSerial1->refresh();
        $this->assertEquals('in_stock', $this->partSerial1->status);

        // Stock restored
        $this->serialPart->refresh();
        $this->assertEquals($stockBefore, $this->serialPart->stock_quantity);

        // Has repair_in movement
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->serialPart->id,
            'type' => 'repair_in',
        ]);
    }

    // ═══ TC-09: External repair can add serial parts too ═══

    public function test_external_repair_add_serial_part_should_not_require_internal_machine_serial(): void
    {
        $service = app(TaskService::class);

        // Create external repair
        $externalRepair = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_name' => 'Khách ngoài Test',
            'issue_description' => 'Thay linh kiện serial',
            'created_by' => $this->user->id,
        ]);

        // Add serial part — should succeed
        $part = $service->addPart($externalRepair, $this->serialPart->id, 1, null, null, [$this->partSerial2->id]);

        $this->assertEquals([$this->partSerial2->id], $part->serial_ids);

        $this->partSerial2->refresh();
        $this->assertEquals('used_for_repair', $this->partSerial2->status);

        $this->serialPart->refresh();
        $this->assertEquals(2, $this->serialPart->stock_quantity); // 3 - 1
    }

    // ═══ TC-10: RR07 existing flow (normal part) still passes ═══

    public function test_internal_repair_existing_flow_still_passes(): void
    {
        $service = app(TaskService::class);

        // Add normal part
        $part = $service->addPart($this->repair, $this->normalPart->id, 3);
        $this->normalPart->refresh();
        $this->assertEquals(7, $this->normalPart->stock_quantity);

        // Verify movement
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->normalPart->id,
            'type' => 'repair_out',
            'qty' => 3,
        ]);

        // Remove part
        $service->removePart($part);
        $this->normalPart->refresh();
        $this->assertEquals(10, $this->normalPart->stock_quantity);
    }
}
