<?php

namespace Tests\Feature\Tasks;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Product;
use App\Models\Category;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskPart;
use App\Models\ActivityLog;
use App\Models\StockMovement;
use App\Services\TaskService;

/**
 * HOTFIX 24.11B — Safe rollback for disassembled task parts.
 *
 * Pins the contract:
 *   - removePart() still refuses direction='import'
 *   - rollbackDisassembledPart() (and POST /api/tasks/{task}/parts/{partId}/rollback-disassembly)
 *     reverses stock, serial output, machine serial cost, machine serial status,
 *     stock movement, and writes an audit row
 *   - 422 when output serials no longer in_stock or stock not enough
 *   - 422 when task completed
 *   - permission gated by tasks.disassemble
 */
class Step2411BDisassemblyRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Product $deviceProduct;
    protected SerialImei $deviceSerial;
    protected Product $normalPart;
    protected Product $serialPart;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'admin2411b'], [
            'display_name' => 'Admin',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->admin);

        $cat = Category::firstOrCreate(['name' => 'Cat 2411B']);

        $this->deviceProduct = Product::create([
            'sku' => 'DEV-2411B-' . uniqid(),
            'name' => 'Device 2411B',
            'cost_price' => 1000000,
            'retail_price' => 1500000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 1000000,
            'has_serial' => true,
            'category_id' => $cat->id,
        ]);
        $this->deviceSerial = SerialImei::create([
            'product_id' => $this->deviceProduct->id,
            'serial_number' => 'DEV-SN-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 1000000,
        ]);

        $this->normalPart = Product::create([
            'sku' => 'PART-2411B-' . uniqid(),
            'name' => 'Linh kiện thường 2411B',
            'cost_price' => 100000,
            'retail_price' => 200000,
            'stock_quantity' => 5,
            'inventory_total_cost' => 500000,
            'has_serial' => false,
            'category_id' => $cat->id,
        ]);

        $this->serialPart = Product::create([
            'sku' => 'SPART-2411B-' . uniqid(),
            'name' => 'Linh kiện serial 2411B',
            'cost_price' => 200000,
            'retail_price' => 400000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'has_serial' => true,
            'category_id' => $cat->id,
        ]);
    }

    private function makeRepair(): Task
    {
        return app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $this->deviceSerial->id,
            'issue_description' => 'Test rollback',
            'created_by' => $this->admin->id,
        ]);
    }

    // ─── TC-01 ────────────────────────────────────────────────────
    public function test_remove_part_still_blocks_import_direction(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $this->normalPart->refresh();
        $stockAfterDisassemble = $this->normalPart->stock_quantity;

        // Service-layer guard (matches Step238E TC-13 pattern): direction='import'
        // throws RuntimeException, never silently removes.
        try {
            $service->removePart($part);
            $this->fail('removePart() should reject direction=import');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bóc tách', $e->getMessage());
        }

        // No mutation: stock unchanged, part still present.
        $this->normalPart->refresh();
        $this->assertSame($stockAfterDisassemble, (int) $this->normalPart->stock_quantity);
        $this->assertNotNull(TaskPart::find($part->id));
    }

    // ─── TC-02 ────────────────────────────────────────────────────
    public function test_can_rollback_non_serial_disassembled_part(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);

        $this->normalPart->refresh();
        $stockBefore = (int) $this->normalPart->stock_quantity;
        $deviceSerialCostBefore = (float) $this->deviceSerial->fresh()->cost_price;

        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 80000);
        $this->normalPart->refresh();
        $this->assertSame($stockBefore + 1, (int) $this->normalPart->stock_quantity);

        $res = $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly");
        $res->assertOk();

        $this->normalPart->refresh();
        $this->assertSame($stockBefore, (int) $this->normalPart->stock_quantity, 'Stock must return to original after rollback.');
        $this->assertNull(TaskPart::find($part->id), 'TaskPart should be deleted after rollback.');
        $this->assertEqualsWithDelta(
            $deviceSerialCostBefore,
            (float) $this->deviceSerial->fresh()->cost_price,
            0.01,
            'Machine serial cost must be restored.'
        );

        $log = ActivityLog::where('action', ActivityLog::ACTION_PART_DISASSEMBLE_ROLLBACK)->latest('id')->first();
        $this->assertNotNull($log, 'ActivityLog rollback row must be written.');
    }

    // ─── TC-03 ────────────────────────────────────────────────────
    public function test_rollback_restores_machine_serial_status_when_no_import_parts_left(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $this->assertSame('dismantled', $this->deviceSerial->fresh()->status);

        $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly")->assertOk();

        $this->assertSame('in_stock', $this->deviceSerial->fresh()->status);
    }

    // ─── TC-04 ────────────────────────────────────────────────────
    public function test_rollback_keeps_machine_dismantled_if_other_import_parts_remain(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $partA = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);
        $partB = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $this->postJson("/api/tasks/{$task->id}/parts/{$partA->id}/rollback-disassembly")->assertOk();

        $this->assertSame('dismantled', $this->deviceSerial->fresh()->status,
            'Other import part still exists → machine stays dismantled.');
        $this->assertNotNull(TaskPart::find($partB->id));
    }

    // ─── TC-05 ────────────────────────────────────────────────────
    public function test_cannot_rollback_if_output_stock_not_enough(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        // Drain output stock externally (simulating a sale).
        $this->normalPart->refresh();
        $this->normalPart->stock_quantity = 0;
        $this->normalPart->inventory_total_cost = 0;
        $this->normalPart->save();

        $res = $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly");
        $res->assertStatus(422);

        // Mutation guard: part still present, machine serial still dismantled.
        $this->assertNotNull(TaskPart::find($part->id));
        $this->assertSame('dismantled', $this->deviceSerial->fresh()->status);
    }

    // ─── TC-06 ────────────────────────────────────────────────────
    public function test_can_rollback_serial_output_if_serial_still_in_stock(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart(
            $task,
            $this->serialPart->id,
            1,
            150000,
            null,
            null,
            ['SOUT-' . uniqid()]
        );

        $serialIds = $part->serial_ids;
        $this->assertCount(1, $serialIds);

        $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly")->assertOk();

        $this->assertCount(0, SerialImei::whereIn('id', $serialIds)->get(),
            'Output serial must be deleted after rollback.');
        $this->assertSame('in_stock', $this->deviceSerial->fresh()->status);
    }

    // ─── TC-07 ────────────────────────────────────────────────────
    public function test_cannot_rollback_serial_output_if_serial_was_sold_or_used(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart(
            $task,
            $this->serialPart->id,
            1,
            150000,
            null,
            null,
            ['SOUT-' . uniqid()]
        );

        // Simulate the output serial being consumed by a sale downstream.
        SerialImei::whereIn('id', $part->serial_ids)->update(['status' => 'sold']);

        $res = $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly");
        $res->assertStatus(422);

        // No mutation: part still present, serials still sold, machine still dismantled.
        $this->assertNotNull(TaskPart::find($part->id));
        $this->assertSame('sold', SerialImei::find($part->serial_ids[0])->status);
        $this->assertSame('dismantled', $this->deviceSerial->fresh()->status);
    }

    // ─── TC-08 ────────────────────────────────────────────────────
    public function test_cannot_rollback_completed_task(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        $task->status = Task::STATUS_COMPLETED;
        $task->save();

        $res = $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly");
        $res->assertStatus(422);

        $this->assertNotNull(TaskPart::find($part->id));
    }

    // ─── TC-09 ────────────────────────────────────────────────────
    public function test_rollback_disassembly_requires_tasks_disassemble_permission(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        // Switch to a user without tasks.disassemble.
        $weakRole = Role::create([
            'name' => 'weak-' . uniqid(),
            'display_name' => 'Weak',
            'permissions' => ['tasks.view'],
            'is_system' => false,
        ]);
        $weak = User::factory()->create(['role_id' => $weakRole->id]);
        $this->actingAs($weak);

        $res = $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly");
        $this->assertContains($res->status(), [401, 403]);
    }

    // ─── TC-10 ────────────────────────────────────────────────────
    public function test_admin_can_rollback_disassembly(): void
    {
        $task = $this->makeRepair();
        $service = app(TaskService::class);
        $part = $service->disassemblePart($task, $this->normalPart->id, 1, 50000);

        // Already actingAs admin from setUp.
        $this->postJson("/api/tasks/{$task->id}/parts/{$part->id}/rollback-disassembly")->assertOk();
        $this->assertNull(TaskPart::find($part->id));
    }
}
