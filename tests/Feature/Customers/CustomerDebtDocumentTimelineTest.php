<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\OrderReturn;
use App\Models\CustomerDebt;
use App\Models\Purchase;
use App\Services\CustomerDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtDocumentTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private CustomerDebtDocumentTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CustomerDebtDocumentTimelineService::class);
    }

    private function createTestCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'KH-TEST-' . uniqid(),
            'name' => 'Test Customer',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Test 1 — invoice partial payment
     */
    public function test_invoice_800k_paid_500k_shows_invoice_total_and_receipt_amount(): void
    {
        $customer = $this->createTestCustomer([
            'debt_amount' => 300000,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-DOC-001',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        $receipt = CashFlow::create([
            'code' => 'PT-DOC-001',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-001',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $this->assertSame(300000.0, (float) $res['summary']['document_final_balance']);

        $invEntry = $entries->firstWhere('code', 'HD-DOC-001');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['display_effect']);

        $ptEntry = $entries->firstWhere('code', 'PT-DOC-001');
        $this->assertNotNull($ptEntry);
        $this->assertSame(-500000.0, (float) $ptEntry['display_effect']);
    }

    /**
     * Test 2 — invoice unpaid
     */
    public function test_unpaid_invoice_shows_full_invoice_total(): void
    {
        $customer = $this->createTestCustomer();

        Invoice::create([
            'code' => 'HD-DOC-002',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 0,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $this->assertSame(800000.0, (float) $res['summary']['document_final_balance']);

        $invEntry = $entries->firstWhere('code', 'HD-DOC-002');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['display_effect']);
    }

    /**
     * Test 3 — invoice fully paid
     */
    public function test_fully_paid_invoice_final_balance_zero(): void
    {
        $customer = $this->createTestCustomer();

        $invoice = Invoice::create([
            'code' => 'HD-DOC-003',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 800000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        $receipt = CashFlow::create([
            'code' => 'PT-DOC-003',
            'type' => 'receipt',
            'amount' => 800000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-003',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $res = $this->service->build($customer);
        $this->assertSame(0.0, (float) $res['summary']['document_final_balance']);
    }

    /**
     * Test 4 — fallback payment from customer_paid
     */
    public function test_fallback_payment_from_customer_paid(): void
    {
        $customer = $this->createTestCustomer();

        $invoice = Invoice::create([
            'code' => 'HD-DOC-004',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $this->assertSame(300000.0, (float) $res['summary']['document_final_balance']);

        $fallbackEntry = $entries->firstWhere('code', 'TTHD-DOC-004');
        $this->assertNotNull($fallbackEntry);
        $this->assertTrue((bool) $fallbackEntry['is_virtual_fallback']);
        $this->assertFalse((bool) $fallbackEntry['detail_available']);
        $this->assertSame('none', $fallbackEntry['detail_modal_type']);
        $this->assertSame(-500000.0, (float) $fallbackEntry['display_effect']);
    }

    /**
     * Test 5 — sales return reduces debt
     */
    public function test_sales_return_reduces_customer_debt_in_document_timeline(): void
    {
        $customer = $this->createTestCustomer();

        OrderReturn::create([
            'code' => 'TH-DOC-005',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 7000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $retEntry = $entries->firstWhere('code', 'TH-DOC-005');
        $this->assertNotNull($retEntry);
        $this->assertSame(-7000000.0, (float) $retEntry['display_effect']);
        $this->assertSame(-7000000.0, (float) $res['summary']['document_final_balance']);
    }

    /**
     * Test 6 — sales return with refund
     */
    public function test_sales_return_with_refund(): void
    {
        $customer = $this->createTestCustomer();

        OrderReturn::create([
            'code' => 'TH-DOC-006',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 7000000,
            'paid_to_customer' => 2000000,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $retEntry = $entries->firstWhere('code', 'TH-DOC-006');
        $this->assertNotNull($retEntry);
        $this->assertSame(-7000000.0, (float) $retEntry['display_effect']);

        $refundEntry = $entries->firstWhere('code', 'PCTH-DOC-006');
        $this->assertNotNull($refundEntry);
        $this->assertTrue((bool) $refundEntry['is_virtual_fallback']);
        $this->assertSame(2000000.0, (float) $refundEntry['display_effect']);
        $this->assertSame(-5000000.0, (float) $res['summary']['document_final_balance']);
    }

    /**
     * Test 7 — does not use CustomerDebt sale amount as invoice display amount
     */
    public function test_does_not_use_CustomerDebt_sale_amount_as_invoice_display_amount(): void
    {
        $customer = $this->createTestCustomer();

        $invoice = Invoice::create([
            'code' => 'HD-DOC-007',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'HD-DOC-007',
            'amount' => 300000, // stored part
            'debt_total' => 300000,
            'type' => 'sale',
            'recorded_at' => Carbon::now()->subMinutes(10),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Dedup keeps the invoice entry which has +800k display effect
        $invEntry = $entries->firstWhere('code', 'HD-DOC-007');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['display_effect']);
    }

    /**
     * Test 8 — local real case from screenshot
     */
    public function test_local_real_case_from_screenshot(): void
    {
        $customer = $this->createTestCustomer();
        
        Invoice::create([
            'code' => 'HD178090993527',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $inv = $entries->firstWhere('code', 'HD178090993527');
        $this->assertNotNull($inv);
        $this->assertSame(800000.0, (float) $inv['display_effect']);

        $pay = $entries->firstWhere('code', 'TTHD178090993527');
        $this->assertNotNull($pay);
        $this->assertSame(-500000.0, (float) $pay['display_effect']);
    }

    /**
     * Test 9 — return real case from screenshot
     */
    public function test_return_real_case_from_screenshot(): void
    {
        $customer = $this->createTestCustomer();

        OrderReturn::create([
            'code' => 'TH202605221641861',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 7000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        $ret = $entries->firstWhere('code', 'TH202605221641861');
        $this->assertNotNull($ret);
        $this->assertSame(-7000000.0, (float) $ret['display_effect']);
    }

    /**
     * Test 10 — no DB writes
     */
    public function test_no_db_writes(): void
    {
        $customer = $this->createTestCustomer();

        Invoice::create([
            'code' => 'HD-DOC-010',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        $countsBefore = [
            'customers' => Customer::count(),
            'invoices' => Invoice::count(),
            'cash_flows' => CashFlow::count(),
            'returns' => OrderReturn::count(),
            'customer_debts' => CustomerDebt::count(),
            'purchases' => Purchase::count(),
        ];

        $res = $this->service->build($customer);

        $countsAfter = [
            'customers' => Customer::count(),
            'invoices' => Invoice::count(),
            'cash_flows' => CashFlow::count(),
            'returns' => OrderReturn::count(),
            'customer_debts' => CustomerDebt::count(),
            'purchases' => Purchase::count(),
        ];

        $this->assertSame($countsBefore, $countsAfter);
    }
}
