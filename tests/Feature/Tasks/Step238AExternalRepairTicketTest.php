<?php

namespace Tests\Feature\Tasks;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\StockMovement;

class Step238AExternalRepairTicketTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * TC-01: External repair with customer snapshot — no stock change.
     */
    public function test_create_external_repair_with_customer_snapshot_should_succeed(): void
    {
        $product = Product::create([
            'name' => 'iPhone 14', 'sku' => 'IP14-TEST',
            'cost_price' => 20000000, 'retail_price' => 25000000,
            'stock_quantity' => 5, 'inventory_total_cost' => 100000000,
        ]);

        $stockBefore = $product->stock_quantity;
        $costBefore = $product->inventory_total_cost;

        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_name'     => 'Nguyễn Văn A',
            'customer_phone'    => '0901234567',
            'product_id'        => $product->id,
            'issue_description' => 'Màn hình bị vỡ, cần thay mới',
        ]);

        $response->assertStatus(201);
        $task = Task::find($response->json('id'));

        $this->assertTrue($task->external);
        $this->assertEquals('received', $task->sub_status);
        $this->assertEquals('Nguyễn Văn A', $task->customer_name);
        $this->assertEquals('0901234567', $task->customer_phone);
        $this->assertEquals('pending', $task->status);
        $this->assertEquals('repair', $task->type);

        // Stock MUST NOT change
        $product->refresh();
        $this->assertEquals($stockBefore, $product->stock_quantity);
        $this->assertEquals($costBefore, $product->inventory_total_cost);

        // No stock movements
        $this->assertEquals(0, StockMovement::count());
    }

    /**
     * TC-02: External repair with customer_id — should snapshot customer info.
     */
    public function test_create_external_repair_with_customer_id_should_snapshot_customer(): void
    {
        $customer = Customer::create([
            'name' => 'Trần Thị B', 'phone' => '0987654321', 'code' => 'KH-EXT-01',
        ]);

        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_id'       => $customer->id,
            'issue_description' => 'Điện thoại không lên nguồn',
        ]);

        $response->assertStatus(201);
        $task = Task::find($response->json('id'));

        $this->assertEquals($customer->id, $task->customer_id);
        $this->assertEquals('Trần Thị B', $task->customer_name);
        $this->assertEquals('0987654321', $task->customer_phone);
        $this->assertTrue($task->external);
    }

    /**
     * TC-03: External repair does NOT require internal serial in_stock.
     */
    public function test_external_repair_does_not_require_internal_serial_in_stock(): void
    {
        // Serial text "IMEI-FAKE-999" does NOT exist in serial_imeis table
        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_name'     => 'Lê Văn C',
            'issue_description' => 'Máy bị treo logo, IMEI: IMEI-FAKE-999',
        ]);

        // Should succeed — external repair doesn't need serial_imeis record
        $response->assertStatus(201);
        $task = Task::find($response->json('id'));
        $this->assertTrue($task->external);
        $this->assertNull($task->serial_imei_id);
    }

    /**
     * TC-04: External repair REQUIRES issue_description.
     */
    public function test_external_repair_requires_issue_description(): void
    {
        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_name'     => 'Phạm Văn D',
            // Missing issue_description
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('issue_description');
    }

    /**
     * TC-05: Internal repair flow still requires in_stock serial.
     */
    public function test_internal_repair_existing_flow_still_requires_in_stock_serial(): void
    {
        $product = Product::create([
            'name' => 'Galaxy S24', 'sku' => 'GS24',
            'cost_price' => 15000000, 'retail_price' => 20000000,
            'stock_quantity' => 3, 'has_serial' => true,
            'inventory_total_cost' => 45000000,
        ]);

        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-SOLD-001',
            'status' => 'sold',
            'cost_price' => 15000000,
        ]);

        // Internal repair (external=false) with sold serial should fail
        $response = $this->postJson('/api/tasks', [
            'type'            => 'repair',
            'external'        => false,
            'serial_imei_id'  => $serial->id,
            'issue_description' => 'Test internal flow',
        ]);

        $response->assertStatus(422);
    }

    /**
     * TC-06: External repair does NOT create invoice or debt.
     */
    public function test_external_repair_does_not_create_invoice_or_debt(): void
    {
        $customer = Customer::create([
            'name' => 'Hoàng Văn E', 'phone' => '0911222333', 'code' => 'KH-EXT-02',
            'debt_amount' => 0,
        ]);

        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_id'       => $customer->id,
            'issue_description' => 'Thay pin iPhone 13',
        ]);

        $response->assertStatus(201);
        $task = Task::find($response->json('id'));

        $this->assertNull($task->invoice_id);
        $this->assertEquals(0, (float) $task->debt_amount);

        // Customer debt unchanged
        $customer->refresh();
        $this->assertEquals(0, (float) $customer->debt_amount);
    }

    /**
     * TC-07: External repair cancel — no stock effect.
     */
    public function test_external_repair_can_be_cancelled_without_stock_effect(): void
    {
        $product = Product::create([
            'name' => 'iPad Air', 'sku' => 'IPA-5',
            'cost_price' => 12000000, 'retail_price' => 15000000,
            'stock_quantity' => 10, 'inventory_total_cost' => 120000000,
        ]);

        $stockBefore = $product->stock_quantity;
        $costBefore = $product->inventory_total_cost;

        // Create external repair
        $response = $this->postJson('/api/tasks', [
            'type'              => 'repair',
            'external'          => true,
            'customer_name'     => 'Vũ Thị F',
            'product_id'        => $product->id,
            'issue_description' => 'Loa bị rè',
        ]);
        $response->assertStatus(201);
        $task = Task::find($response->json('id'));

        // Cancel the task
        $this->deleteJson("/api/tasks/{$task->id}")->assertOk();

        // Stock MUST NOT change
        $product->refresh();
        $this->assertEquals($stockBefore, $product->stock_quantity);
        $this->assertEquals($costBefore, $product->inventory_total_cost);

        // No stock movements
        $this->assertEquals(0, StockMovement::count());

        // Task cancelled
        $task->refresh();
        $this->assertEquals('cancelled', $task->status);
    }
}
