<?php

namespace Tests\Feature\Tasks;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Customer;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\StockMovement;
use App\Services\TaskService;
use App\Services\SerialAvailabilityService;

/**
 * Step 23.8E — Disassembly hardening.
 *
 * Verify cost cap, output serial validation, input dismantled marking,
 * and sale guard for dismantled serial.
 */
class Step238EDisassemblyHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User       $user;
    protected Product    $deviceProduct;
    protected SerialImei $deviceSerial;
    protected Product    $normalPart;
    protected Product    $serialPart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $cat = Category::firstOrCreate(['name' => 'Cat 238E']);

        // Device gốc (cost = 1.000.000)
        $this->deviceProduct = Product::create([
            'sku' => 'DEV-238E-' . uniqid(), 'name' => 'Device 238E',
            'cost_price' => 1000000, 'retail_price' => 1500000,
            'stock_quantity' => 1, 'inventory_total_cost' => 1000000,
            'has_serial' => true, 'category_id' => $cat->id,
        ]);
        $this->deviceSerial = SerialImei::create([
            'product_id' => $this->deviceProduct->id,
            'serial_number' => 'DEV-SN-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 1000000,
        ]);

        // Linh kiện thường (output)
        $this->normalPart = Product::create([
            'sku' => 'PART-238E-' . uniqid(), 'name' => 'Linh kiện 238E',
            'cost_price' => 100000, 'retail_price' => 200000,
            'stock_quantity' => 5, 'inventory_total_cost' => 500000,
            'has_serial' => false, 'category_id' => $cat->id,
        ]);

        // Linh kiện có serial (output)
        $this->serialPart = Product::create([
            'sku' => 'SPART-238E-' . uniqid(), 'name' => 'Linh kiện serial 238E',
            'cost_price' => 200000, 'retail_price' => 400000,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => true, 'category_id' => $cat->id,
        ]);
    }

    private function createInternalRepair(): Task
    {
        $service = app(TaskService::class);
        return $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $this->deviceSerial->id,
            'issue_description' => 'Test 238E',
            'created_by' => $this->user->id,
        ]);
    }

    // ═══ TC-01 ═══

    public function test_disassemble_normal_part_should_increase_stock_and_record_movement(): void
    {
        $task = $this->createInternalRepair();
        $stockBefore = $this->normalPart->stock_quantity;

        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 2, 100000);

        $this->normalPart->refresh();
        $this->assertEquals($stockBefore + 2, $this->normalPart->stock_quantity);

        $this->assertEquals('import', $part->direction);
        $this->assertEquals(2, $part->quantity);
        $this->assertEquals(200000, (float) $part->total_cost);

        $movement = StockMovement::where('product_id', $this->normalPart->id)
            ->where('type', 'repair_in')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(2, $movement->qty);
    }

    // ═══ TC-02 ═══

    public function test_disassemble_should_mark_input_serial_dismantled(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);
        $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $this->deviceSerial->refresh();
        $this->assertEquals('dismantled', $this->deviceSerial->status);
    }

    // ═══ TC-03 ═══

    public function test_disassemble_output_cost_cannot_exceed_input_cost(): void
    {
        // Tạo task với original_cost = 100k thay vì 1M
        $cheapDevice = Product::create([
            'sku' => 'CHEAP-' . uniqid(), 'name' => 'Cheap',
            'cost_price' => 100000, 'retail_price' => 150000,
            'stock_quantity' => 1, 'inventory_total_cost' => 100000,
            'has_serial' => true, 'category_id' => Category::first()->id,
        ]);
        $cheapSerial = SerialImei::create([
            'product_id' => $cheapDevice->id,
            'serial_number' => 'CHEAP-SN',
            'status' => 'in_stock',
            'cost_price' => 100000,
        ]);
        $service = app(TaskService::class);
        $task = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $cheapSerial->id,
            'created_by' => $this->user->id,
        ]);

        $stockBefore = $this->normalPart->stock_quantity;

        try {
            $service->disassemblePart($task, $this->normalPart->id, 2, 100000); // 200k > 100k
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vượt giá vốn khả dụng', $e->getMessage());
        }

        // Không tăng tồn output
        $this->normalPart->refresh();
        $this->assertEquals($stockBefore, $this->normalPart->stock_quantity);

        // Không đổi serial input
        $cheapSerial->refresh();
        $this->assertEquals('in_stock', $cheapSerial->status);

        // Không task_part được tạo
        $this->assertEquals(0, $task->parts()->count());
    }

    // ═══ TC-04 ═══

    public function test_disassemble_multiple_outputs_total_cost_cannot_exceed_input_cost(): void
    {
        $task = $this->createInternalRepair(); // device cost = 1M
        $service = app(TaskService::class);

        // Lần 1: 600k OK
        $service->disassemblePart($task, $this->normalPart->id, 6, 100000);

        // Lần 2: 500k → tổng 1.1M > 1M → fail
        $this->expectException(\RuntimeException::class);
        $service->disassemblePart($task, $this->normalPart->id, 5, 100000);
    }

    // ═══ TC-05 ═══

    public function test_disassemble_serial_output_requires_serial_numbers(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->disassemblePart($task, $this->serialPart->id, 1, 200000);
    }

    // ═══ TC-06 ═══

    public function test_disassemble_serial_output_count_mismatch_should_fail(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->disassemblePart(
            $task, $this->serialPart->id, 2, 200000,
            null, null, ['SN-OUT-1']
        );
    }

    // ═══ TC-07 ═══

    public function test_disassemble_serial_output_duplicate_should_fail(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->disassemblePart(
            $task, $this->serialPart->id, 2, 200000,
            null, null, ['SN-DUP', 'SN-DUP']
        );
    }

    // ═══ TC-08 ═══

    public function test_disassemble_serial_output_existing_serial_should_fail(): void
    {
        // Tạo serial đã tồn tại
        SerialImei::create([
            'product_id' => $this->serialPart->id,
            'serial_number' => 'SN-EXISTING',
            'status' => 'in_stock',
            'cost_price' => 200000,
        ]);

        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->disassemblePart(
            $task, $this->serialPart->id, 1, 200000,
            null, null, ['SN-EXISTING']
        );
    }

    // ═══ TC-09 ═══

    public function test_disassemble_serial_output_success_should_create_serials_in_stock(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $part = $service->disassemblePart(
            $task, $this->serialPart->id, 2, 200000,
            null, null, ['NEW-OUT-A', 'NEW-OUT-B']
        );

        // 2 SerialImei mới tạo
        $newSerials = SerialImei::whereIn('serial_number', ['NEW-OUT-A', 'NEW-OUT-B'])->get();
        $this->assertCount(2, $newSerials);
        foreach ($newSerials as $s) {
            $this->assertEquals('in_stock', $s->status);
            $this->assertEquals($this->serialPart->id, $s->product_id);
            $this->assertEquals(200000, (float) $s->cost_price);
        }

        // task_part.serial_ids lưu 2 ID
        $this->assertCount(2, $part->serial_ids);

        // recomputeFromSerials → product.stock_quantity = 2
        $this->serialPart->refresh();
        $this->assertEquals(2, $this->serialPart->stock_quantity);
    }

    // ═══ TC-10 ═══

    public function test_disassemble_external_repair_should_fail(): void
    {
        $customer = Customer::create([
            'code' => 'KH-' . uniqid(),
            'name' => 'KH ext',
            'phone' => '0900',
            'is_customer' => true,
        ]);

        $service = app(TaskService::class);
        $task = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH ext',
            'issue_description' => 'External 238E',
            'created_by' => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $service->disassemblePart($task, $this->normalPart->id, 1, 50000);
    }

    // ═══ TC-11 ═══

    public function test_disassemble_completed_or_cancelled_task_should_fail(): void
    {
        $task = $this->createInternalRepair();
        $task->update(['status' => Task::STATUS_COMPLETED, 'completed_at' => now()]);

        $service = app(TaskService::class);
        $this->expectException(\RuntimeException::class);
        $service->disassemblePart($task, $this->normalPart->id, 1, 50000);
    }

    // ═══ TC-12 ═══

    public function test_dismantled_serial_cannot_be_sold(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);
        $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $this->deviceSerial->refresh();
        $this->assertEquals('dismantled', $this->deviceSerial->status);

        // SerialAvailabilityService phải block
        SerialAvailabilityService::clearSchemaCache();
        $svc = app(SerialAvailabilityService::class);
        $this->assertFalse($svc->isSellable($this->deviceSerial, $this->deviceProduct->id));

        $blocked = $svc->findBlockedIds([$this->deviceSerial->id], $this->deviceProduct->id);
        $this->assertContains($this->deviceSerial->id, $blocked);
    }

    // ═══ TC-13 ═══

    public function test_remove_disassembled_serial_output_should_be_blocked(): void
    {
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart(
            $task, $this->serialPart->id, 1, 200000,
            null, null, ['SN-RM-OUT']
        );

        // Policy: block remove cho direction=import
        $this->expectException(\RuntimeException::class);
        $service->removePart($part);
    }

    // ═══ TC-14 ═══

    public function test_rr07_internal_repair_parts_still_pass(): void
    {
        // Smoke check addPart/removePart cho direction=export vẫn OK
        $task = $this->createInternalRepair();
        $service = app(TaskService::class);

        $stockBefore = $this->normalPart->stock_quantity;

        $part = $service->addPart($task, $this->normalPart->id, 2);
        $this->normalPart->refresh();
        $this->assertEquals($stockBefore - 2, $this->normalPart->stock_quantity);
        $this->assertEquals('export', $part->direction);

        // remove cho export OK
        $service->removePart($part);
        $this->normalPart->refresh();
        $this->assertEquals($stockBefore, $this->normalPart->stock_quantity);
    }
}
