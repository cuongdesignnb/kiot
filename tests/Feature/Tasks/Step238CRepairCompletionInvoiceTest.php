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
use App\Models\TaskPart;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\StockMovement;
use App\Services\TaskService;

class Step238CRepairCompletionInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User     $user;
    protected Customer $customer;
    protected Product  $normalPart;
    protected Product  $serialPart;
    protected SerialImei $partSerial1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'code' => 'KH-238C-' . uniqid(),
            'name' => 'Khách sửa chữa Test',
            'phone' => '0999888777',
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat 238C']);

        $this->normalPart = Product::create([
            'sku' => 'PART-238C-' . uniqid(), 'name' => 'Linh kiện thường 238C',
            'cost_price' => 50000, 'retail_price' => 100000,
            'stock_quantity' => 10, 'inventory_total_cost' => 500000,
            'has_serial' => false, 'category_id' => $category->id,
        ]);

        $this->serialPart = Product::create([
            'sku' => 'SPART-238C-' . uniqid(), 'name' => 'Linh kiện serial 238C',
            'cost_price' => 200000, 'retail_price' => 400000,
            'stock_quantity' => 2, 'inventory_total_cost' => 400000,
            'has_serial' => true, 'category_id' => $category->id,
        ]);

        $this->partSerial1 = SerialImei::create([
            'product_id' => $this->serialPart->id, 'serial_number' => 'SPS1-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 200000,
        ]);
        SerialImei::create([
            'product_id' => $this->serialPart->id, 'serial_number' => 'SPS2-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 200000,
        ]);
    }

    private function createExternalRepair(?int $customerId = null, ?string $customerName = null): Task
    {
        $service = app(TaskService::class);
        return $service->createTask([
            'type'              => Task::TYPE_REPAIR,
            'external'          => true,
            'customer_id'       => $customerId,
            'customer_name'     => $customerName ?? 'Khách Test',
            'issue_description' => 'Test 238C',
            'created_by'        => $this->user->id,
        ]);
    }

    // ═══ TC-01: Labor only, paid full → invoice + cashflow, no debt ═══

    public function test_complete_external_repair_labor_only_paid_full_should_create_invoice_and_cashflow(): void
    {
        $task = $this->createExternalRepair($this->customer->id);

        $service = app(TaskService::class);
        $result = $service->completeExternalRepair($task, [
            'labor_fee'   => 500000,
            'paid_amount' => 500000,
        ]);

        // Task completed
        $this->assertEquals(Task::STATUS_COMPLETED, $result->status);
        $this->assertNotNull($result->invoice_id);
        $this->assertEquals(500000, (float) $result->total_amount);
        $this->assertEquals(500000, (float) $result->paid_amount);
        $this->assertEquals(0, (float) $result->debt_amount);

        // Invoice created with source_type=repair
        $invoice = Invoice::find($result->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals('repair', $invoice->source_type);
        $this->assertEquals(500000, (float) $invoice->total);

        // CashFlow receipt
        $cashflow = CashFlow::where('reference_code', $invoice->code)->first();
        $this->assertNotNull($cashflow, 'CashFlow receipt phải tồn tại');
        $this->assertEquals(500000, (float) $cashflow->amount);

        // No customer debt
        $this->assertEquals(0, CustomerDebt::where('customer_id', $this->customer->id)->count());

        // No new stock movements
        $this->assertEquals(0, StockMovement::count());
    }

    // ═══ TC-02: Normal parts — no extra stock deduction ═══

    public function test_complete_external_repair_with_normal_parts_should_not_deduct_stock_again(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $service = app(TaskService::class);

        // Add part — this deducts stock
        $service->addPart($task, $this->normalPart->id, 2);
        $this->normalPart->refresh();
        $stockAfterAdd = $this->normalPart->stock_quantity;
        $this->assertEquals(8, $stockAfterAdd);

        $movementCountBefore = StockMovement::count();

        // Complete — should NOT deduct stock again
        $result = $service->completeExternalRepair($task, [
            'labor_fee'   => 200000,
            'paid_amount' => 400000, // 200000 + 2*100000(retail)
        ]);

        // Stock unchanged after complete
        $this->normalPart->refresh();
        $this->assertEquals($stockAfterAdd, $this->normalPart->stock_quantity);

        // No new stock movements from complete
        $this->assertEquals($movementCountBefore, StockMovement::count());

        // Invoice has parts + labor
        $invoice = Invoice::find($result->invoice_id);
        $this->assertNotNull($invoice);
        $this->assertEquals(2, $invoice->items->count()); // 1 labor + 1 part line
    }

    // ═══ TC-03: Serial parts — keep used_for_repair ═══

    public function test_complete_external_repair_with_serial_parts_should_keep_serial_used_for_repair(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $service = app(TaskService::class);

        // Add serial part
        $service->addPart($task, $this->serialPart->id, 1, null, null, [$this->partSerial1->id]);
        $this->partSerial1->refresh();
        $this->assertEquals('used_for_repair', $this->partSerial1->status);

        // Complete
        $result = $service->completeExternalRepair($task, [
            'labor_fee'   => 100000,
            'paid_amount' => 500000,
        ]);

        // Serial still used_for_repair (NOT sold, NOT in_stock)
        $this->partSerial1->refresh();
        $this->assertEquals('used_for_repair', $this->partSerial1->status);
    }

    // ═══ TC-04: Partial payment → customer debt ═══

    public function test_complete_external_repair_partial_payment_should_create_customer_debt(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $service = app(TaskService::class);

        $debtBefore = (float) $this->customer->debt_amount;

        $result = $service->completeExternalRepair($task, [
            'labor_fee'   => 1000000,
            'paid_amount' => 400000,
        ]);

        $this->assertEquals(1000000, (float) $result->total_amount);
        $this->assertEquals(400000, (float) $result->paid_amount);
        $this->assertEquals(600000, (float) $result->debt_amount);

        // CustomerDebt ledger
        $debtRecord = CustomerDebt::where('customer_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($debtRecord, 'Phải có bản ghi customer_debts');
        $this->assertEquals(600000, (float) $debtRecord->amount);

        // Customer.debt_amount tăng
        $this->customer->refresh();
        $this->assertEquals($debtBefore + 600000, (float) $this->customer->debt_amount);
    }

    // ═══ TC-05: Debt without customer should fail ═══

    public function test_complete_external_repair_debt_without_customer_should_fail(): void
    {
        // Task without customer_id
        $task = $this->createExternalRepair(null, 'Khách vãng lai');

        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->completeExternalRepair($task, [
            'labor_fee'   => 500000,
            'paid_amount' => 100000, // debt = 400000 but no customer_id
        ]);
    }

    // ═══ TC-06: Paid > total should cap ═══

    public function test_complete_external_repair_paid_amount_capped_at_total(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $service = app(TaskService::class);

        $result = $service->completeExternalRepair($task, [
            'labor_fee'   => 300000,
            'paid_amount' => 999999, // more than total
        ]);

        // paid capped at total, no debt
        $this->assertEquals(300000, (float) $result->total_amount);
        $this->assertEquals(300000, (float) $result->paid_amount);
        $this->assertEquals(0, (float) $result->debt_amount);
    }

    // ═══ TC-07: Idempotent — complete twice should fail ═══

    public function test_complete_external_repair_is_idempotent(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $service = app(TaskService::class);

        // First complete — OK
        $service->completeExternalRepair($task, [
            'labor_fee'   => 300000,
            'paid_amount' => 300000,
        ]);

        $invoiceCount = Invoice::count();
        $cashflowCount = CashFlow::count();

        // Second complete — should fail
        $task->refresh();
        $this->expectException(\RuntimeException::class);
        $service->completeExternalRepair($task, [
            'labor_fee'   => 300000,
            'paid_amount' => 300000,
        ]);

        // Counts unchanged
        $this->assertEquals($invoiceCount, Invoice::count());
        $this->assertEquals($cashflowCount, CashFlow::count());
    }

    // ═══ TC-08: Internal repair complete should NOT create invoice ═══

    public function test_internal_repair_mark_completed_should_not_create_invoice(): void
    {
        $category = Category::firstOrCreate(['name' => 'Cat 238C Internal']);
        $deviceProduct = Product::create([
            'sku' => 'DEVICE-238C-' . uniqid(), 'name' => 'Device Internal',
            'cost_price' => 5000000, 'retail_price' => 8000000,
            'stock_quantity' => 1, 'inventory_total_cost' => 5000000,
            'has_serial' => true, 'category_id' => $category->id,
        ]);
        $deviceSerial = SerialImei::create([
            'product_id' => $deviceProduct->id, 'serial_number' => 'SN-INT-' . uniqid(),
            'status' => 'in_stock', 'cost_price' => 5000000,
        ]);

        $service = app(TaskService::class);
        $repair = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $deviceSerial->id,
            'issue_description' => 'Internal repair',
            'created_by' => $this->user->id,
        ]);

        $result = $service->markCompleted($repair, $this->user->id);

        $this->assertEquals(Task::STATUS_COMPLETED, $result->status);
        $this->assertNull($result->invoice_id);
        $this->assertEquals(0, Invoice::count());
    }

    // ═══ TC-09: Cancelled task should not complete ═══

    public function test_complete_cancelled_repair_should_fail(): void
    {
        $task = $this->createExternalRepair($this->customer->id);
        $task->update(['status' => Task::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);
        $service->completeExternalRepair($task, [
            'labor_fee'   => 300000,
            'paid_amount' => 300000,
        ]);
    }

    // ═══ TC-10: Transaction rollback — debt fail should not create invoice ═══

    public function test_complete_external_repair_transaction_rolls_back_on_failure(): void
    {
        // Task without customer_id, debt > 0 → will fail at debt validation
        $task = $this->createExternalRepair(null, 'Khách vãng lai rollback');

        $service = app(TaskService::class);

        try {
            $service->completeExternalRepair($task, [
                'labor_fee'   => 500000,
                'paid_amount' => 100000,
            ]);
            $this->fail('Should have thrown RuntimeException');
        } catch (\RuntimeException $e) {
            // Expected
        }

        // No invoice created
        $this->assertEquals(0, Invoice::count());

        // No cashflow
        $this->assertEquals(0, CashFlow::count());

        // Task not completed
        $task->refresh();
        $this->assertNull($task->invoice_id);
        $this->assertNotEquals(Task::STATUS_COMPLETED, $task->status);
    }
}
