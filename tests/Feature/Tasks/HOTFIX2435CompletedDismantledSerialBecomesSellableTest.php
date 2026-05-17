<?php

namespace Tests\Feature\Tasks;

use App\Console\Commands\RestoreCompletedDismantledSerials;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOTFIX 24.35 — Completing a repair task brings a dismantled device
 * serial back to in_stock + repair_status=ready (unless the serial has
 * already left stock via sale or purchase-return). The `task_parts`
 * audit rows stay; only the physical serial status flips.
 */
class HOTFIX2435CompletedDismantledSerialBecomesSellableTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Product $deviceProduct;
    protected Product $partProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'admin2435'], [
            'display_name' => 'Admin 2435',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->admin);

        $cat = Category::firstOrCreate(['name' => 'Cat 2435']);

        $this->deviceProduct = Product::create([
            'sku'                  => 'DEV-2435-' . uniqid(),
            'name'                 => 'Device 2435',
            'cost_price'           => 1_000_000,
            'retail_price'         => 1_500_000,
            'stock_quantity'       => 1,
            'inventory_total_cost' => 1_000_000,
            'has_serial'           => true,
            'category_id'          => $cat->id,
        ]);

        $this->partProduct = Product::create([
            'sku'                  => 'PART-2435-' . uniqid(),
            'name'                 => 'Linh kiện 2435',
            'cost_price'           => 100_000,
            'retail_price'         => 200_000,
            'stock_quantity'       => 5,
            'inventory_total_cost' => 500_000,
            'has_serial'           => false,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeSerial(string $status = 'in_stock', ?string $repair = null, array $overrides = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id'    => $this->deviceProduct->id,
            'serial_number' => 'SN-' . uniqid(),
            'status'        => $status,
            'repair_status' => $repair,
            'cost_price'    => 1_000_000,
        ], $overrides));
    }

    private function makeRepairTask(SerialImei $serial): Task
    {
        return app(TaskService::class)->createTask([
            'type'              => Task::TYPE_REPAIR,
            'serial_imei_id'    => $serial->id,
            'issue_description' => 'Test 2435',
            'created_by'        => $this->admin->id,
        ]);
    }

    // ── Test 1 — completed task có bóc linh kiện => serial về in_stock + ready ──
    public function test_completed_task_restores_dismantled_serial(): void
    {
        $serial  = $this->makeSerial('in_stock', null);
        $task    = $this->makeRepairTask($serial);
        $service = app(TaskService::class);
        $service->disassemblePart($task, $this->partProduct->id, 1, 50_000);

        $serial->refresh();
        $this->assertSame('dismantled', $serial->status);

        $service->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('in_stock', $fresh->status, 'completed task must restore device to in_stock');
        $this->assertSame('ready', $fresh->repair_status);

        // task_parts are kept for audit
        $this->assertTrue(
            $task->parts()->where('direction', 'import')->exists(),
            'task_parts.direction=import audit rows must persist'
        );

        // product stock recomputed
        $this->deviceProduct->refresh();
        $expectedInStock = SerialImei::where('product_id', $this->deviceProduct->id)
            ->where('status', 'in_stock')->count();
        $this->assertEquals($expectedInStock, (int) $this->deviceProduct->stock_quantity);
    }

    // ── Test 2 — task chưa completed => serial vẫn dismantled ──
    public function test_task_not_yet_completed_keeps_dismantled(): void
    {
        $serial  = $this->makeSerial('in_stock', null);
        $task    = $this->makeRepairTask($serial);
        $service = app(TaskService::class);
        $service->disassemblePart($task, $this->partProduct->id, 1, 50_000);

        $service->changeStatus($task, Task::STATUS_IN_PROGRESS, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('dismantled', $fresh->status,
            'task in_progress must NOT restore — serial still physically dismantled');
    }

    // ── Test 3 — serial đã bán không được hồi về in_stock ──
    public function test_sold_serial_is_not_restored(): void
    {
        $serial = $this->makeSerial('in_stock', null);
        $task   = $this->makeRepairTask($serial);

        $serial->update([
            'status'        => 'sold',
            'repair_status' => 'repairing',
            'invoice_id'    => 999_999,
            'sold_at'       => now(),
        ]);

        app(TaskService::class)->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('sold', $fresh->status, 'sold serial stays sold');
        $this->assertSame('ready', $fresh->repair_status);
    }

    // ── Test 4 — serial trả NCC không được hồi về in_stock ──
    public function test_purchase_returned_serial_is_not_restored(): void
    {
        $serial = $this->makeSerial('in_stock', null);
        $task   = $this->makeRepairTask($serial);

        $serial->update([
            'status'             => 'dismantled',
            'repair_status'      => 'repairing',
            'purchase_return_id' => 777_777,
        ]);

        app(TaskService::class)->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('dismantled', $fresh->status,
            'purchase-returned serial must not be re-introduced as in_stock');
    }

    // ── Test 5 — command skips serial whose latest task is not completed ──
    public function test_command_skips_serial_with_pending_latest_task(): void
    {
        $serial = $this->makeSerial('in_stock', null);

        // Old completed task
        $oldTask = $this->makeRepairTask($serial);
        app(TaskService::class)->markCompleted($oldTask, $this->admin->id);
        // Force the serial back to dismantled to simulate legacy stuck state
        $serial->update(['status' => 'dismantled', 'repair_status' => 'repairing']);

        // Newer task still in_progress
        $newTask = Task::create([
            'code'           => 'T-NEW-' . uniqid(),
            'type'           => Task::TYPE_REPAIR,
            'serial_imei_id' => $serial->id,
            'product_id'     => $this->deviceProduct->id,
            'status'         => Task::STATUS_IN_PROGRESS,
            'created_by'     => $this->admin->id,
            'title'          => 'Newer repair',
        ]);

        $this->artisan('serials:restore-completed-dismantled', ['--apply' => true])
            ->assertExitCode(0);

        $fresh = $serial->fresh();
        $this->assertSame('dismantled', $fresh->status,
            'serial must stay dismantled when its latest repair task is not completed');
    }

    // ── Test 6 — command dry-run does not modify DB ──
    public function test_command_dry_run_does_not_modify_db(): void
    {
        $serial = $this->makeSerial('in_stock', null);
        $task   = $this->makeRepairTask($serial);
        // Mark task completed at the DB layer but force serial stuck at dismantled
        $task->update(['status' => Task::STATUS_COMPLETED, 'completed_at' => now()]);
        $serial->update(['status' => 'dismantled', 'repair_status' => 'repairing']);

        $this->artisan('serials:restore-completed-dismantled')
            ->assertExitCode(0);

        $fresh = $serial->fresh();
        $this->assertSame('dismantled', $fresh->status, 'dry-run must NOT touch the DB');
        $this->assertSame('repairing', $fresh->repair_status);
    }

    // ── Test 7 — command apply restores legacy stuck serials and recomputes stock ──
    public function test_command_apply_restores_and_recomputes(): void
    {
        $serialA = $this->makeSerial('in_stock', null);
        $serialB = $this->makeSerial('in_stock', null);
        $taskA = $this->makeRepairTask($serialA);
        $taskB = $this->makeRepairTask($serialB);
        $taskA->update(['status' => Task::STATUS_COMPLETED, 'completed_at' => now()]);
        $taskB->update(['status' => Task::STATUS_COMPLETED, 'completed_at' => now()]);
        $serialA->update(['status' => 'dismantled', 'repair_status' => 'repairing']);
        $serialB->update(['status' => 'dismantled', 'repair_status' => 'repairing']);

        $this->artisan('serials:restore-completed-dismantled', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('in_stock', $serialA->fresh()->status);
        $this->assertSame('ready',    $serialA->fresh()->repair_status);
        $this->assertSame('in_stock', $serialB->fresh()->status);

        $this->deviceProduct->refresh();
        $expected = SerialImei::where('product_id', $this->deviceProduct->id)
            ->where('status', 'in_stock')->count();
        $this->assertEquals($expected, (int) $this->deviceProduct->stock_quantity,
            'product stock_quantity must be recomputed from in_stock serials');
    }
}
