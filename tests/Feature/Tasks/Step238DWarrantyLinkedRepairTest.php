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
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\StockMovement;
use App\Models\Warranty;
use App\Services\TaskService;
use Carbon\Carbon;

class Step238DWarrantyLinkedRepairTest extends TestCase
{
    use RefreshDatabase;

    protected User     $user;
    protected Customer $customer;
    protected Product  $deviceProduct;
    protected Product  $normalPart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'code' => 'KH-238D-' . uniqid(),
            'name' => 'Khách bảo hành Test',
            'phone' => '0999111222',
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat 238D']);

        $this->deviceProduct = Product::create([
            'sku' => 'DEV-238D-' . uniqid(), 'name' => 'Thiết bị 238D',
            'cost_price' => 5000000, 'retail_price' => 8000000,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => true, 'category_id' => $category->id,
        ]);

        $this->normalPart = Product::create([
            'sku' => 'PART-238D-' . uniqid(), 'name' => 'Linh kiện 238D',
            'cost_price' => 50000, 'retail_price' => 100000,
            'stock_quantity' => 10, 'inventory_total_cost' => 500000,
            'has_serial' => false, 'category_id' => $category->id,
        ]);
    }

    private function createExternalRepair(?int $customerId = null): Task
    {
        $service = app(TaskService::class);
        return $service->createTask([
            'type'              => Task::TYPE_REPAIR,
            'external'          => true,
            'customer_id'       => $customerId ?? $this->customer->id,
            'customer_name'     => 'Khách bảo hành',
            'issue_description' => 'Test 238D',
            'created_by'        => $this->user->id,
        ]);
    }

    private function createValidWarranty(string $serial = 'SN-VALID-238D'): Warranty
    {
        return Warranty::create([
            'invoice_code'      => 'HD-238D-' . uniqid(),
            'product_id'        => $this->deviceProduct->id,
            'customer_name'     => 'Khách bảo hành',
            'serial_imei'       => $serial,
            'warranty_period'   => 12,
            'purchase_date'     => Carbon::now()->subMonths(2),
            'warranty_end_date' => Carbon::now()->addMonths(10),
        ]);
    }

    private function createExpiredWarranty(string $serial = 'SN-EXPIRED-238D'): Warranty
    {
        return Warranty::create([
            'invoice_code'      => 'HD-EXP-238D-' . uniqid(),
            'product_id'        => $this->deviceProduct->id,
            'customer_name'     => 'Khách bảo hành cũ',
            'serial_imei'       => $serial,
            'warranty_period'   => 12,
            'purchase_date'     => Carbon::now()->subMonths(15),
            'warranty_end_date' => Carbon::now()->subMonths(3),
        ]);
    }

    // ═══ TC-01: attach valid warranty to external repair OK ═══

    public function test_attach_valid_warranty_to_external_repair_should_succeed(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $result = $service->attachWarranty($task, $warranty);

        $this->assertEquals($warranty->id, $result->warranty_id);
        $this->assertEquals(Task::STATUS_PENDING, $result->status);
    }

    // ═══ TC-02: internal repair cannot attach warranty ═══

    public function test_attach_warranty_to_internal_repair_should_fail(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->deviceProduct->id,
            'serial_number' => 'SN-INT-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 5000000,
        ]);
        $this->deviceProduct->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $service = app(TaskService::class);
        $task = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $serial->id,
            'issue_description' => 'Internal',
            'created_by' => $this->user->id,
        ]);

        $warranty = $this->createValidWarranty();

        $this->expectException(\RuntimeException::class);
        $service->attachWarranty($task, $warranty);
    }

    // ═══ TC-03: completed task cannot attach warranty ═══

    public function test_attach_warranty_to_completed_task_should_fail(): void
    {
        $task = $this->createExternalRepair();
        $task->update(['status' => Task::STATUS_COMPLETED, 'completed_at' => now()]);

        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $this->expectException(\RuntimeException::class);
        $service->attachWarranty($task, $warranty);
    }

    // ═══ TC-04: serial mismatch should fail ═══

    public function test_attach_warranty_serial_mismatch_should_fail(): void
    {
        // Task có serial_imei_id A, warranty có serial_imei B
        $serialA = SerialImei::create([
            'product_id' => $this->deviceProduct->id,
            'serial_number' => 'SN-A-238D',
            'status' => 'in_stock',
            'cost_price' => 5000000,
        ]);

        $task = $this->createExternalRepair();
        // Manually link task to serialA (simulate customer's device tracking)
        $task->update(['serial_imei_id' => $serialA->id]);

        $warranty = $this->createValidWarranty('SN-B-238D');

        $service = app(TaskService::class);
        $this->expectException(\RuntimeException::class);
        $service->attachWarranty($task, $warranty);
    }

    // ═══ TC-05: free_labor only zeroes labor fee ═══

    public function test_complete_valid_warranty_free_labor_should_zero_labor_fee_only(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $service->attachWarranty($task, $warranty);
        $task->refresh();

        // Add part: parts_total = 2 * 100000 = 200000
        $service->addPart($task, $this->normalPart->id, 2);
        $task->refresh();

        $result = $service->completeExternalRepair($task, [
            'labor_fee'       => 300000,
            'paid_amount'     => 200000, // chỉ trả phần parts
            'warranty_policy' => Task::WARRANTY_POLICY_FREE_LABOR,
        ]);

        $this->assertEquals(300000, (float) $result->labor_fee, 'gross labor giữ nguyên');
        $this->assertEquals(200000, (float) $result->parts_total, 'gross parts giữ nguyên');
        $this->assertEquals(200000, (float) $result->total_amount, 'payable = parts only');
        $this->assertEquals(200000, (float) $result->paid_amount);
        $this->assertEquals(0, (float) $result->debt_amount);
        $this->assertEquals(300000, (float) $result->warranty_covered_amount);
        $this->assertEquals(Task::WARRANTY_POLICY_FREE_LABOR, $result->warranty_policy);

        $invoice = Invoice::find($result->invoice_id);
        $this->assertEquals(200000, (float) $invoice->total);

        // Cashflow theo payable
        $cashflow = CashFlow::where('reference_code', $invoice->code)->first();
        $this->assertNotNull($cashflow);
        $this->assertEquals(200000, (float) $cashflow->amount);
    }

    // ═══ TC-06: free_parts only zeroes parts ═══

    public function test_complete_valid_warranty_free_parts_should_zero_parts_only(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $service->attachWarranty($task, $warranty);
        $task->refresh();

        // Snapshot stock trước addPart
        $stockBefore = $this->normalPart->stock_quantity;
        $service->addPart($task, $this->normalPart->id, 2);
        $this->normalPart->refresh();
        $stockAfterAdd = $this->normalPart->stock_quantity;
        $this->assertEquals($stockBefore - 2, $stockAfterAdd, 'addPart trừ tồn');

        $task->refresh();
        $result = $service->completeExternalRepair($task, [
            'labor_fee'       => 500000,
            'paid_amount'     => 500000, // chỉ trả phần labor
            'warranty_policy' => Task::WARRANTY_POLICY_FREE_PARTS,
        ]);

        $this->assertEquals(500000, (float) $result->labor_fee);
        $this->assertEquals(200000, (float) $result->parts_total, 'gross parts giữ nguyên');
        $this->assertEquals(500000, (float) $result->total_amount, 'payable = labor only');
        $this->assertEquals(0, (float) $result->debt_amount);
        $this->assertEquals(200000, (float) $result->warranty_covered_amount);

        // Linh kiện vẫn đã trừ tồn trước đó, KHÔNG hoàn kho khi miễn phí
        $this->normalPart->refresh();
        $this->assertEquals($stockAfterAdd, $this->normalPart->stock_quantity, 'free_parts không hoàn tồn');
    }

    // ═══ TC-07: full_free creates zero payable, no cashflow, no debt ═══

    public function test_complete_valid_warranty_full_free_should_create_zero_payable(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $service->attachWarranty($task, $warranty);
        $task->refresh();

        $service->addPart($task, $this->normalPart->id, 1);
        $task->refresh();

        $cashflowBefore = CashFlow::count();
        $debtBefore = CustomerDebt::count();

        $result = $service->completeExternalRepair($task, [
            'labor_fee'       => 400000,
            'paid_amount'     => 0,
            'warranty_policy' => Task::WARRANTY_POLICY_FULL_FREE,
        ]);

        $this->assertEquals(0, (float) $result->total_amount, 'payable = 0');
        $this->assertEquals(0, (float) $result->paid_amount);
        $this->assertEquals(0, (float) $result->debt_amount);
        $this->assertEquals(500000, (float) $result->warranty_covered_amount, '400k labor + 100k part');

        // Invoice exists with total = 0 (lưu lịch sử)
        $invoice = Invoice::find($result->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals(0, (float) $invoice->total);

        // Không cashflow / không debt
        $this->assertEquals($cashflowBefore, CashFlow::count(), 'không cashflow khi paid=0');
        $this->assertEquals($debtBefore, CustomerDebt::count(), 'không debt khi total=0');
    }

    // ═══ TC-08: expired warranty cannot use free policy ═══

    public function test_expired_warranty_cannot_use_free_policy(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createExpiredWarranty();

        $service = app(TaskService::class);
        // attach expired warranty vẫn cho phép (lưu lịch sử)
        $service->attachWarranty($task, $warranty);
        $task->refresh();

        $this->expectException(\RuntimeException::class);
        $service->completeExternalRepair($task, [
            'labor_fee'       => 300000,
            'paid_amount'     => 0,
            'warranty_policy' => Task::WARRANTY_POLICY_FREE_LABOR,
        ]);
    }

    // ═══ TC-09: no warranty cannot use free policy ═══

    public function test_no_warranty_cannot_use_free_policy(): void
    {
        $task = $this->createExternalRepair();

        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->completeExternalRepair($task, [
            'labor_fee'       => 300000,
            'paid_amount'     => 0,
            'warranty_policy' => Task::WARRANTY_POLICY_FREE_LABOR,
        ]);
    }

    // ═══ TC-10: warranty policy does NOT deduct stock again ═══

    public function test_warranty_policy_does_not_deduct_stock_again(): void
    {
        $task = $this->createExternalRepair();
        $warranty = $this->createValidWarranty();

        $service = app(TaskService::class);
        $service->attachWarranty($task, $warranty);
        $task->refresh();

        $stockBefore = $this->normalPart->stock_quantity;
        $service->addPart($task, $this->normalPart->id, 3);
        $this->normalPart->refresh();
        $stockAfterAdd = $this->normalPart->stock_quantity;
        $this->assertEquals($stockBefore - 3, $stockAfterAdd);

        $movementBefore = StockMovement::count();

        $task->refresh();
        $service->completeExternalRepair($task, [
            'labor_fee'       => 200000,
            'paid_amount'     => 0,
            'warranty_policy' => Task::WARRANTY_POLICY_FREE_PARTS,
        ]);

        // Stock không đổi
        $this->normalPart->refresh();
        $this->assertEquals($stockAfterAdd, $this->normalPart->stock_quantity);

        // Không stock movement mới khi complete
        $this->assertEquals($movementBefore, StockMovement::count());
    }
}
