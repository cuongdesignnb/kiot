<?php

namespace Tests\Feature\Tasks;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskPart;
use App\Models\User;
use App\Services\SerialAvailabilityService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOTFIX 24.16 — Repair serial dismantled/ready mismatch.
 *
 * The previous TaskService::changeStatus only updated `repair_status` when
 * an internal repair task transitioned, never `status`. A serial that had
 * been set to `status='dismantled'` during disassembly was therefore left
 * at `dismantled` + `repair_status='ready'` after the repair finished — and
 * the UI badged it "✓ Sẵn bán". This suite pins the corrected behaviour
 * (incl. all the "do NOT silently restore" guard rails) and verifies the
 * SerialAvailabilityService still refuses the mismatched state.
 */
class HOTFIX2416RepairSerialReadyStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Product $deviceProduct;
    protected Product $partProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'admin2416'], [
            'display_name' => 'Admin 2416',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->admin);

        $cat = Category::firstOrCreate(['name' => 'Cat 2416']);

        $this->deviceProduct = Product::create([
            'sku'                  => 'DEV-2416-' . uniqid(),
            'name'                 => 'Device 2416',
            'cost_price'           => 1000000,
            'retail_price'         => 1500000,
            'stock_quantity'       => 1,
            'inventory_total_cost' => 1000000,
            'has_serial'           => true,
            'category_id'          => $cat->id,
        ]);

        $this->partProduct = Product::create([
            'sku'                  => 'PART-2416-' . uniqid(),
            'name'                 => 'Linh kiện 2416',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 5,
            'inventory_total_cost' => 500000,
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
            'cost_price'    => 1000000,
        ], $overrides));
    }

    private function makeRepairTask(SerialImei $serial): Task
    {
        return app(TaskService::class)->createTask([
            'type'              => Task::TYPE_REPAIR,
            'serial_imei_id'    => $serial->id,
            'issue_description' => 'Test repair 2416',
            'created_by'        => $this->admin->id,
        ]);
    }

    // ── TC-01 ─────────────────────────────────────────────────────────
    public function test_internal_repair_completion_restores_in_stock_when_no_disassembly_outputs(): void
    {
        $serial = $this->makeSerial('in_stock', 'repairing');
        $task   = $this->makeRepairTask($serial);

        app(TaskService::class)->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('in_stock', $fresh->status);
        $this->assertSame('ready', $fresh->repair_status);
        $this->assertTrue(
            app(SerialAvailabilityService::class)->isSellable($fresh, $this->deviceProduct->id),
            'Repaired serial should be sellable again.'
        );
    }

    // ── TC-02 ─────────────────────────────────────────────────────────
    /**
     * Pins the CD0TVG3 scenario: serial was left at dismantled+ready after
     * a previous flow but has NO active import task_parts. Completing the
     * repair task must reconcile status back to in_stock.
     */
    public function test_internal_repair_completion_restores_dismantled_to_in_stock_when_no_active_import_parts(): void
    {
        // createTask() correctly refuses to start a repair on a dismantled
        // serial, so we set the stuck state AFTER task creation — mirrors
        // the production CD0TVG3 case where status drifted to 'dismantled'
        // via some other path (e.g. earlier disassembly that lost its
        // import task_part).
        $serial = $this->makeSerial('in_stock', null);
        $task   = $this->makeRepairTask($serial);
        $serial->update(['status' => 'dismantled', 'repair_status' => 'repairing']);

        app(TaskService::class)->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('in_stock', $fresh->status);
        $this->assertSame('ready', $fresh->repair_status);
    }

    // ── TC-03 ─────────────────────────────────────────────────────────
    // HOTFIX 24.35 updated contract: completing a repair task always
    // restores the device serial to in_stock (unless sold/returned), even
    // if direction='import' task_parts are still on file. The task_parts
    // stay for audit trail; only the physical serial status is flipped.
    public function test_internal_repair_completion_restores_dismantled_even_with_import_parts(): void
    {
        $serial  = $this->makeSerial('in_stock', null);
        $task    = $this->makeRepairTask($serial);
        $service = app(TaskService::class);

        $service->disassemblePart($task, $this->partProduct->id, 1, 50000);

        $serial->refresh();
        $this->assertSame('dismantled', $serial->status, 'sanity check: disassembly set the device to dismantled');
        $this->assertTrue(
            $task->parts()->where('direction', 'import')->exists(),
            'sanity check: import task_part exists'
        );

        $service->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('in_stock', $fresh->status,
            'HOTFIX 24.35: completed repair restores device serial regardless of import parts');
        $this->assertSame('ready', $fresh->repair_status);
        $this->assertTrue(
            app(SerialAvailabilityService::class)->isSellable($fresh, $this->deviceProduct->id),
            'Restored serial must be sellable.'
        );
        $this->assertTrue(
            $task->parts()->where('direction', 'import')->exists(),
            'task_parts stay for audit trail — only the serial is flipped'
        );
    }

    // ── TC-04 ─────────────────────────────────────────────────────────
    public function test_completed_repair_does_not_restore_sold_serial_to_in_stock(): void
    {
        // Same trick as TC-02: createTask refuses non-in_stock serials,
        // so mutate to 'sold' after the task exists to mimic an out-of-order
        // sale that happened during the repair window.
        $serial = $this->makeSerial('in_stock', null);
        $task   = $this->makeRepairTask($serial);
        $serial->update([
            'status'        => 'sold',
            'repair_status' => 'repairing',
            'invoice_id'    => 999999,
            'sold_at'       => now(),
        ]);

        app(TaskService::class)->markCompleted($task, $this->admin->id);

        $fresh = $serial->fresh();
        $this->assertSame('sold', $fresh->status, 'Sold serial must not be brought back into stock.');
        $this->assertSame('ready', $fresh->repair_status);
    }

    // ── TC-05 ─────────────────────────────────────────────────────────
    public function test_serial_availability_blocks_dismantled_ready(): void
    {
        $serial = $this->makeSerial('dismantled', 'ready');

        $this->assertFalse(
            app(SerialAvailabilityService::class)->isSellable($serial, $this->deviceProduct->id),
            'SerialAvailabilityService must classify dismantled/ready as NOT sellable.'
        );
    }

    // ── TC-06 ─────────────────────────────────────────────────────────
    /**
     * HOTFIX 24.35 evolved contract: a dismantled serial in a task that is
     * NOT yet completed still carries status='dismantled' so the corrected
     * Welcome.vue badge can render "⚠ Đã bóc tách". Once the task completes,
     * the serial flips to in_stock (see TC-03). This TC pins the not-yet-
     * completed path that the UI relies on.
     */
    public function test_disassembled_serial_keeps_dismantled_until_task_completes(): void
    {
        $serial  = $this->makeSerial('in_stock', null);
        $task    = $this->makeRepairTask($serial);
        $service = app(TaskService::class);
        $service->disassemblePart($task, $this->partProduct->id, 1, 50000);
        // task remains in_progress / created — NOT completed

        $row = SerialImei::find($serial->id);
        $this->assertSame('dismantled', $row->status);
        // Sellable-only scope must NOT return this serial while still dismantled.
        $sellableIds = app(SerialAvailabilityService::class)
            ->querySellableForProduct($this->deviceProduct->id)
            ->pluck('id')
            ->all();
        $this->assertNotContains($row->id, $sellableIds);
    }
}
