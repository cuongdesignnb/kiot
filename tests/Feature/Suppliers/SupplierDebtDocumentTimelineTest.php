<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\User;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\CashFlow;
use App\Models\SupplierDebtTransaction;
use App\Models\Invoice;
use App\Services\SupplierDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtDocumentTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private SupplierDebtDocumentTimelineService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SupplierDebtDocumentTimelineService::class);
        $this->admin = User::create([
            'name' => 'Admin Test Supplier Timeline',
            'email' => 'admin-timeline-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function createTestSupplier(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'NCC-TEST-' . uniqid(),
            'name' => 'Test Supplier',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Test purchase and payments
     */
    public function test_purchase_and_payments_reconciliation(): void
    {
        $supplier = $this->createTestSupplier([
            'supplier_debt_amount' => 3000000,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-TEST-001',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 5000000,
            'paid_amount' => 2000000,
            'purchase_date' => Carbon::now()->subMinutes(10),
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        $payment = CashFlow::create([
            'code' => 'PC-TEST-001',
            'type' => 'payment',
            'amount' => 2000000,
            'target_type' => 'Nhà cung cấp',
            'target_id' => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN-TEST-001',
            'status' => 'completed',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $res = $this->service->build($supplier);
        $entries = collect($res['entries']);

        $this->assertSame(3000000.0, (float) $res['summary']['document_final_balance']);

        $purEntry = $entries->firstWhere('code', 'PN-TEST-001');
        $this->assertNotNull($purEntry);
        $this->assertSame(5000000.0, (float) $purEntry['supplier_display_effect']);

        $payEntry = $entries->firstWhere('code', 'PC-TEST-001');
        $this->assertNotNull($payEntry);
        $this->assertSame(-2000000.0, (float) $payEntry['supplier_display_effect']);
    }

    /**
     * Test technical ledger entries exclusion
     */
    public function test_technical_ledger_entries_excluded_by_default(): void
    {
        $supplier = $this->createTestSupplier([
            'supplier_debt_amount' => 1000000,
        ]);

        $merge = SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => 'MERGE-SUPPLIER-123',
            'type' => 'adjustment',
            'amount' => 1000000,
            'note' => 'Gộp công nợ',
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($supplier);
        $entries = collect($res['entries']);

        $this->assertNull($entries->firstWhere('code', 'MERGE-SUPPLIER-123'));

        $resWithTech = $this->service->build($supplier, ['include_technical' => true]);
        $entriesWithTech = collect($resWithTech['entries']);
        $this->assertNotNull($entriesWithTech->firstWhere('code', 'MERGE-SUPPLIER-123'));
    }

    /**
     * Test dual-role mirror
     */
    public function test_dual_role_mirrors_customer_invoice_correctly(): void
    {
        $supplier = $this->createTestSupplier([
            'is_customer' => true,
            'debt_amount' => 1000000,
            'supplier_debt_amount' => 3000000,
        ]);

        // Supplier side: Purchase 3M
        $purchase = Purchase::create([
            'code' => 'PN-DUAL-001',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 3000000,
            'paid_amount' => 0,
            'purchase_date' => Carbon::now()->subMinutes(10),
        ]);

        // Customer side: Invoice 1M
        $invoice = Invoice::create([
            'code' => 'HD-DUAL-001',
            'customer_id' => $supplier->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'customer_paid' => 0,
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $res = $this->service->build($supplier, ['view' => 'partner']);
        $entries = collect($res['entries']);

        // Oriented net payable: supplier_debt_amount - debt_amount = 3M - 1M = 2M
        $this->assertSame(2000000.0, (float) $res['summary']['document_final_balance']);

        $purEntry = $entries->firstWhere('code', 'PN-DUAL-001');
        $this->assertNotNull($purEntry);
        $this->assertSame(3000000.0, (float) $purEntry['supplier_display_effect']);

        $invEntry = $entries->firstWhere('code', 'HD-DUAL-001');
        $this->assertNotNull($invEntry);
        $this->assertSame(-1000000.0, (float) $invEntry['supplier_display_effect']);
    }

    /**
     * Test API debtTransactions endpoint
     */
    public function test_api_debt_transactions_mode_document(): void
    {
        $supplier = $this->createTestSupplier([
            'supplier_debt_amount' => 1000000,
        ]);

        Purchase::create([
            'code' => 'PN-API-001',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'purchase_date' => Carbon::now(),
        ]);

        $res = $this->actingAs($this->admin)->getJson("/api/suppliers/{$supplier->id}/debt-transactions?mode=document");
        $res->assertOk()
            ->assertJsonPath('summary.display_balance_final', 1000000);
    }
}
