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
 * HOTFIX 24.18 — Reassembled serial restores to stock.
 *
 * HOTFIX 24.16 wired the restore to flow through Task::changeStatus, so
 * any serial that was stuck at dismantled+ready BEFORE the hotfix
 * shipped — or whose repair task never got re-completed — stayed
 * stuck forever. The new TaskService::restoreReassembledSerial is the
 * explicit "đã lắp lại xong" lever the operator can pull on a single
 * serial without re-cycling the task. This suite pins the safety
 * envelope (won't restore sold / returned / mid-repair serials) and
 * the side-effects (status flip + recomputeFromSerials).
 */
class HOTFIX2418ReassembledSerialRestoresStockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Product $product;
    protected Product $partProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'admin2418'], [
            'display_name' => 'Admin 2418',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->admin);

        $cat = Category::firstOrCreate(['name' => 'Cat 2418']);

        $this->product = Product::create([
            'sku'                  => 'DEV-2418-' . uniqid(),
            'name'                 => 'Máy 2418',
            'cost_price'           => 5_000_000,
            'retail_price'         => 8_000_000,
            'stock_quantity'       => 0,
            'inventory_total_cost' => 0,
            'has_serial'           => true,
            'category_id'          => $cat->id,
        ]);

        $this->partProduct = Product::create([
            'sku'                  => 'PART-2418-' . uniqid(),
            'name'                 => 'Linh kiện 2418',
            'cost_price'           => 100_000,
            'retail_price'         => 200_000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1_000_000,
            'has_serial'           => false,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeStuckSerial(): SerialImei
    {
        // The exact shape tester reported on MTXNBZJ1WK: dismantled
        // body + repair_status=ready + never sold/returned. The product
        // sits at stock_quantity=0 because dismantled rows are not
        // counted by recomputeFromSerials.
        $s = SerialImei::create([
            'product_id'    => $this->product->id,
            'serial_number' => 'SN-STUCK-' . uniqid(),
            'status'        => 'dismantled',
            'repair_status' => 'ready',
            'cost_price'    => 5_000_000,
        ]);
        $this->product->recomputeFromSerials();
        return $s;
    }

    // ── TC-01 — happy path: dismantled+ready → in_stock + product stock++ ──
    public function test_reassembled_serial_restores_to_in_stock(): void
    {
        $serial = $this->makeStuckSerial();
        $this->assertSame(0, (int) $this->product->fresh()->stock_quantity, 'precondition: stock = 0');

        $result = app(TaskService::class)->restoreReassembledSerial($serial->id, $this->admin->id);

        $this->assertTrue($result['restored']);
        $fresh = $serial->fresh();
        $this->assertSame('in_stock', $fresh->status);
        $this->assertSame('ready', $fresh->repair_status);
        $this->assertSame(1, (int) $this->product->fresh()->stock_quantity, 'product stock recomputed to 1');
        $this->assertTrue(
            app(SerialAvailabilityService::class)->isSellable($fresh, $this->product->id),
            'restored serial must be sellable'
        );
    }

    // ── TC-02 — active open repair with import part blocks restore ──
    public function test_dismantled_serial_with_active_import_parts_is_not_restored(): void
    {
        $serial = $this->makeStuckSerial();

        // Spin up a real open repair task on this serial; reset the
        // serial back to the stuck shape (createTask refuses non-in_stock)
        // and add a direction='import' part to simulate ongoing disassembly.
        $serial->update(['status' => 'in_stock', 'repair_status' => null]);
        $task = app(TaskService::class)->createTask([
            'type'              => Task::TYPE_REPAIR,
            'serial_imei_id'    => $serial->id,
            'issue_description' => 'Test 2418 active disassembly',
            'created_by'        => $this->admin->id,
        ]);
        app(TaskService::class)->disassemblePart($task, $this->partProduct->id, 1, 50_000);
        $serial->update(['status' => 'dismantled', 'repair_status' => 'ready']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đóng\/hoàn tất phiếu/i');
        app(TaskService::class)->restoreReassembledSerial($serial->id, $this->admin->id);
    }

    // ── TC-03 — sold serial is never restored ──
    public function test_sold_serial_is_never_restored_to_in_stock(): void
    {
        $serial = $this->makeStuckSerial();
        // Simulate the impossible-but-defensive case: sold + dismantled
        // shouldn't exist, but if it ever drifts to that shape the
        // restore lever must refuse.
        $serial->update(['invoice_id' => 999_999, 'sold_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã bán/i');
        app(TaskService::class)->restoreReassembledSerial($serial->id, $this->admin->id);
    }

    // ── TC-04 — purchase-returned serial is never restored ──
    public function test_returned_serial_is_never_restored_to_in_stock(): void
    {
        $serial = $this->makeStuckSerial();
        $serial->update(['purchase_return_id' => 999_999]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã trả NCC/i');
        app(TaskService::class)->restoreReassembledSerial($serial->id, $this->admin->id);
    }

    // ── TC-05 — repair_status=repairing blocks restore (mid-repair) ──
    public function test_mid_repair_serial_is_not_restored(): void
    {
        $serial = $this->makeStuckSerial();
        $serial->update(['repair_status' => 'repairing']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/repair_status=repairing/');
        app(TaskService::class)->restoreReassembledSerial($serial->id, $this->admin->id);
    }

    // ── TC-06 — SerialAvailabilityService still blocks dismantled+ready ──
    public function test_serial_availability_blocks_dismantled_ready(): void
    {
        $serial = $this->makeStuckSerial();
        $this->assertFalse(
            app(SerialAvailabilityService::class)->isSellable($serial, $this->product->id),
            'dismantled/ready remains unsellable regardless of badge'
        );
    }

    // ── TC-07 — API endpoint exposes the same contract ──
    public function test_api_endpoint_restores_serial(): void
    {
        $serial = $this->makeStuckSerial();
        $res = $this->postJson("/api/tasks/serials/{$serial->id}/restore-reassembled");
        $res->assertOk();
        $body = $res->json();
        $this->assertTrue($body['restored'] ?? false);
        $this->assertSame('in_stock', $body['serial']['status'] ?? null);
        $this->assertSame(1, (int) ($body['serial']['product']['stock_quantity'] ?? 0));
    }

    // ── TC-08 — calling on an already-sellable serial is a no-op (not 500) ──
    public function test_api_endpoint_no_op_on_already_in_stock(): void
    {
        $s = SerialImei::create([
            'product_id'    => $this->product->id,
            'serial_number' => 'SN-FINE-' . uniqid(),
            'status'        => 'in_stock',
            'repair_status' => 'ready',
            'cost_price'    => 5_000_000,
        ]);
        $this->product->recomputeFromSerials();

        $res = $this->postJson("/api/tasks/serials/{$s->id}/restore-reassembled");
        $res->assertOk();
        $body = $res->json();
        $this->assertFalse($body['restored'] ?? true, 'already in_stock — restored should be false');
        $this->assertSame('already_in_stock', $body['reason'] ?? null);
    }
}
