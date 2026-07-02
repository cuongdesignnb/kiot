<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
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
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CustomerDebtDocumentTimelineService::class);
        $this->admin = User::create([
            'name' => 'Admin Test Timeline',
            'email' => 'admin-timeline-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
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

    public function test_invoice_partial_payment_displays_invoice_total_not_remaining_debt(): void
    {
        $customer = $this->createTestCustomer(['debt_amount' => 300000]);
        $invoice = Invoice::create([
            'code' => 'HD-PARTIAL-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'HD-PARTIAL-123',
            'amount' => 300000,
            'debt_total' => 300000,
            'type' => 'sale',
            'recorded_at' => Carbon::now()->subMinutes(10),
        ]);
        
        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        
        $invEntry = $entries->firstWhere('code', 'HD-PARTIAL-123');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['display_effect']);
        $this->assertSame(800000.0, (float) $invEntry['customer_display_effect']);
        $this->assertSame('document_first', $invEntry['source']);
        $this->assertNotEquals('Ledger', $invEntry['badge_label']);
        
        $fallbackEntry = $entries->firstWhere('code', 'TTHD-PARTIAL-123');
        $this->assertNotNull($fallbackEntry);
        $this->assertSame(-500000.0, (float) $fallbackEntry['display_effect']);
    }

    public function test_default_debt_history_uses_document_mode(): void
    {
        $customer = $this->createTestCustomer();
        $invoice = Invoice::create([
            'code' => 'HD-DEFAULT-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);
        
        $response = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $response->assertStatus(200);
        $entries = collect($response->json('entries'));
        
        $invEntry = $entries->firstWhere('code', 'HD-DEFAULT-123');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['display_effect']);
        $this->assertSame('document_first', $invEntry['source']);
        $this->assertNotEquals('Ledger', $invEntry['badge_label']);
    }

    public function test_legacy_mode_does_not_affect_default_document_mode(): void
    {
        $customer = $this->createTestCustomer();
        $invoice = Invoice::create([
            'code' => 'HD-COMPARE-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'HD-COMPARE-123',
            'amount' => 300000,
            'debt_total' => 300000,
            'type' => 'sale',
            'recorded_at' => Carbon::now()->subMinutes(10),
        ]);

        // Default / document mode
        $resDoc = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $entriesDoc = collect($resDoc->json('entries'));
        $invDoc = $entriesDoc->firstWhere('code', 'HD-COMPARE-123');
        $this->assertSame(800000.0, (float) $invDoc['display_effect']);
        
        // Legacy mode
        $resLegacy = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history?mode=legacy");
        $entriesLegacy = collect($resLegacy->json('entries'));
        $invLegacy = $entriesLegacy->firstWhere('code', 'HD-COMPARE-123');
        $this->assertSame(300000.0, (float) $invLegacy['display_effect']);
    }

    public function test_sales_return_document_reduces_debt_even_when_ledger_exists(): void
    {
        $customer = $this->createTestCustomer();
        OrderReturn::create([
            'code' => 'TH-RED-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'TH-RED-123',
            'amount' => -1000000,
            'debt_total' => -1000000,
            'type' => 'return',
            'recorded_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        
        $retEntry = $entries->firstWhere('code', 'TH-RED-123');
        $this->assertNotNull($retEntry);
        $this->assertSame(-1000000.0, (float) $retEntry['display_effect']);
        $this->assertSame('document_first', $retEntry['source']);
        $this->assertNotEquals('Ledger', $retEntry['badge_label']);
    }

    public function test_frontend_payload_has_no_ledger_badge_for_invoice_document_entry(): void
    {
        $customer = $this->createTestCustomer();
        $invoice = Invoice::create([
            'code' => 'HD-FRONT-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $entries = collect($response->json('entries'));
        
        $invEntry = $entries->firstWhere('code', 'HD-FRONT-123');
        $this->assertNotNull($invEntry);
        $this->assertSame('document_first', $invEntry['source']);
        $this->assertNull($invEntry['badge_label']);
    }

    public function test_document_timeline_entries_include_running_balance_even_when_reconcile_warns(): void
    {
        // Stored debt is 300000 but document final will be 800000 - 500000 - 1000000 = -700000
        // This mismatch triggers reconcile warning
        $customer = $this->createTestCustomer(['debt_amount' => 300000]);
        
        $invoice = Invoice::create([
            'code' => 'HD-WARN-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        
        CashFlow::create([
            'code' => 'PT-WARN-123',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-WARN-123',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        OrderReturn::create([
            'code' => 'TH-WARN-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $response->assertStatus(200);
        
        $this->assertSame('warning', $response->json('reconcile.severity'));
        
        $entries = $response->json('entries');
        $this->assertNotEmpty($entries);
        
        foreach ($entries as $entry) {
            $this->assertArrayHasKey('customer_display_running_balance', $entry);
            $this->assertNotNull($entry['customer_display_running_balance']);
            $this->assertTrue(is_numeric($entry['customer_display_running_balance']));
            
            $this->assertArrayHasKey('running_balance', $entry);
            $this->assertNotNull($entry['running_balance']);
            $this->assertTrue(is_numeric($entry['running_balance']));
        }
    }

    public function test_zero_balance_displays_as_numeric_zero(): void
    {
        $customer = $this->createTestCustomer();
        
        $invoice = Invoice::create([
            'code' => 'HD-ZERO-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 800000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        
        CashFlow::create([
            'code' => 'PT-ZERO-123',
            'type' => 'receipt',
            'amount' => 800000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-ZERO-123',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $entries = collect($response->json('entries'));
        
        $lastEntry = $entries->sortBy(function($e) {
            return $e['display_time'] ?? $e['time'] ?? $e['created_at'];
        })->last();

        $this->assertNotNull($lastEntry);
        $this->assertEquals(0.0, (float) $lastEntry['customer_display_running_balance']);
        $this->assertEquals(0.0, (float) $lastEntry['running_balance']);
    }

    public function test_collection_map_persistence(): void
    {
        $customer = $this->createTestCustomer();
        
        Invoice::create([
            'code' => 'HD-PERSIST-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->get("/customers/{$customer->id}/debt-history");
        $entries = collect($response->json('entries'));
        
        $invEntry = $entries->firstWhere('code', 'HD-PERSIST-123');
        $this->assertNotNull($invEntry);
        $this->assertNotNull($invEntry['customer_display_running_balance']);
    }

    public function test_does_not_regress_document_values(): void
    {
        $customer = $this->createTestCustomer();
        
        Invoice::create([
            'code' => 'HD-REGRESS-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        
        CashFlow::create([
            'code' => 'PT-REGRESS-123',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-REGRESS-123',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        OrderReturn::create([
            'code' => 'TH-REGRESS-123',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        
        $inv = $entries->firstWhere('code', 'HD-REGRESS-123');
        $this->assertSame(800000.0, (float) $inv['display_effect']);
        
        $pay = $entries->firstWhere('code', 'PT-REGRESS-123');
        $this->assertSame(-500000.0, (float) $pay['display_effect']);
        
        $ret = $entries->firstWhere('code', 'TH-REGRESS-123');
        $this->assertSame(-1000000.0, (float) $ret['display_effect']);
    }

    public function test_invoice_payment_is_grouped_immediately_after_parent_invoice(): void
    {
        $customer = $this->createTestCustomer();

        // Create parent invoice
        $invoice = Invoice::create([
            'code' => 'HD-DOC-GRP1',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subHours(5),
            'transaction_date' => Carbon::now()->subHours(5),
        ]);

        // Create return (newer than invoice but older than payment)
        $orderReturn = OrderReturn::create([
            'code' => 'TH-DOC-GRP1',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 100000,
            'created_at' => Carbon::now()->subHours(3),
            'return_date' => Carbon::now()->subHours(3),
        ]);

        // Create cash flow payment (newer than both return and invoice)
        $receipt = CashFlow::create([
            'code' => 'PT-DOC-GRP1',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-GRP1',
            'status' => 'active',
            'time' => Carbon::now()->subHours(1),
            'created_at' => Carbon::now()->subHours(1),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Assert they are in event time DESC display order:
        // 1. PT-DOC-GRP1 (subHours(1))
        // 2. TH-DOC-GRP1 (subHours(3))
        // 3. HD-DOC-GRP1 (subHours(5))
        
        $codes = $entries->pluck('code')->toArray();
        $this->assertEquals(['PT-DOC-GRP1', 'TH-DOC-GRP1', 'HD-DOC-GRP1'], $codes);
    }

    public function test_multiple_payments_stay_under_invoice(): void
    {
        $customer = $this->createTestCustomer();

        $invoice = Invoice::create([
            'code' => 'HD-DOC-GRP2',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'customer_paid' => 1000000,
            'created_at' => Carbon::now()->subHours(10),
            'transaction_date' => Carbon::now()->subHours(10),
        ]);

        // Create 3 payments at different times
        $pt1 = CashFlow::create([
            'code' => 'PT-GRP2-1',
            'type' => 'receipt',
            'amount' => 200000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-GRP2',
            'status' => 'active',
            'time' => Carbon::now()->subHours(8),
            'created_at' => Carbon::now()->subHours(8),
        ]);

        $pt2 = CashFlow::create([
            'code' => 'PT-GRP2-2',
            'type' => 'receipt',
            'amount' => 300000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-GRP2',
            'status' => 'active',
            'time' => Carbon::now()->subHours(6),
            'created_at' => Carbon::now()->subHours(6),
        ]);

        $pt3 = CashFlow::create([
            'code' => 'PT-GRP2-3',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-GRP2',
            'status' => 'active',
            'time' => Carbon::now()->subHours(4),
            'created_at' => Carbon::now()->subHours(4),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Assert they are in event time DESC display order:
        // PT-GRP2-3 (subHours(4)), PT-GRP2-2 (subHours(6)), PT-GRP2-1 (subHours(8)), HD-DOC-GRP2 (subHours(10))
        $codes = $entries->pluck('code')->toArray();
        $this->assertEquals(['PT-GRP2-3', 'PT-GRP2-2', 'PT-GRP2-1', 'HD-DOC-GRP2'], $codes);
    }

    public function test_merge_customer_excluded_from_document_balance(): void
    {
        $customer = $this->createTestCustomer([
            'debt_amount' => 1300000,
        ]);

        // Seed documents summing to 1.3M
        Invoice::create([
            'code' => 'HD-MERGE-TEST',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 2800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subHours(5),
            'transaction_date' => Carbon::now()->subHours(5),
        ]);

        CashFlow::create([
            'code' => 'PT-MERGE-TEST',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-MERGE-TEST',
            'status' => 'active',
            'time' => Carbon::now()->subHours(4),
            'created_at' => Carbon::now()->subHours(4),
        ]);

        OrderReturn::create([
            'code' => 'TH-MERGE-TEST',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'created_at' => Carbon::now()->subHours(3),
            'return_date' => Carbon::now()->subHours(3),
        ]);

        // Create technical ledger entry MERGE-CUSTOMER-239 for 2M
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-239',
            'amount' => 2000000,
            'debt_total' => 2000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::now()->subHours(2),
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Expected: MERGE-CUSTOMER-239 is NOT in entries
        $this->assertNull($entries->firstWhere('code', 'MERGE-CUSTOMER-239'));

        // Expected: document_final_balance = 1.3M (2.8M - 500k - 1M)
        $this->assertSame(1300000.0, (float) $res['summary']['document_final_balance']);

        // Expected: reconcile severity is ok
        $this->assertSame('ok', $res['reconcile']['severity']);

        // Expected: excluded_ledger_entries contains MERGE-CUSTOMER-239
        $excluded = collect($res['reconcile']['excluded_ledger_entries'] ?? []);
        $mergeExcluded = $excluded->firstWhere('code', 'MERGE-CUSTOMER-239');
        $this->assertNotNull($mergeExcluded);
        $this->assertSame(2000000.0, (float) $mergeExcluded['amount']);
    }

    public function test_technical_opening_merge_does_not_affect_running_balance(): void
    {
        $customer = $this->createTestCustomer([
            'debt_amount' => 800000,
        ]);

        // Technical Opening Balance
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'OPENING-BALANCE-123',
            'amount' => 5000000,
            'debt_total' => 5000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::now()->subHours(10),
            'created_at' => Carbon::now()->subHours(10),
        ]);

        // Real Invoice
        Invoice::create([
            'code' => 'HD-TECH-TEST',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 0,
            'created_at' => Carbon::now()->subHours(5),
            'transaction_date' => Carbon::now()->subHours(5),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Since it is default build, opening balance technical entry should be hidden/excluded
        $this->assertNull($entries->firstWhere('code', 'OPENING-BALANCE-123'));

        // Running balance of invoice HD-TECH-TEST should be 800k (not affected by 5M)
        $invEntry = $entries->firstWhere('code', 'HD-TECH-TEST');
        $this->assertNotNull($invEntry);
        $this->assertSame(800000.0, (float) $invEntry['customer_display_running_balance']);
        $this->assertSame(800000.0, (float) $res['summary']['document_final_balance']);
    }

    public function test_document_timeline_displays_by_event_time_desc_like_kiotviet(): void
    {
        $customer = $this->createTestCustomer([
            'is_supplier' => true,
        ]);

        $baseDate = Carbon::today();
        
        Invoice::create([
            'code' => 'HD-DOC-001',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'transaction_date' => $baseDate->copy()->setTime(8, 53, 0),
            'created_at' => $baseDate->copy()->setTime(8, 53, 0),
        ]);

        CashFlow::create([
            'code' => 'PT-DOC-001',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-001',
            'status' => 'active',
            'time' => $baseDate->copy()->setTime(16, 12, 0),
            'created_at' => $baseDate->copy()->setTime(16, 12, 0),
        ]);

        Purchase::create([
            'code' => 'PN-DOC-001',
            'supplier_id' => $customer->id,
            'total_amount' => 40000,
            'paid_amount' => 0,
            'debt_amount' => 40000,
            'status' => 'completed',
            'purchase_date' => $baseDate->copy()->setTime(15, 54, 0),
            'created_at' => $baseDate->copy()->setTime(15, 54, 0),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        $codes = $entries->pluck('code')->toArray();

        // Expected display order (DESC by event time):
        // 1. PT-DOC-001 (16:12)
        // 2. PN-DOC-001 (15:54)
        // 3. HD-DOC-001 (08:53)
        $this->assertEquals(['PT-DOC-001', 'PN-DOC-001', 'HD-DOC-001'], $codes);
    }

    public function test_payment_keeps_parent_invoice_reference(): void
    {
        $customer = $this->createTestCustomer();
        $baseDate = Carbon::today();
        
        Invoice::create([
            'code' => 'HD-DOC-001',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'transaction_date' => $baseDate->copy()->setTime(8, 53, 0),
            'created_at' => $baseDate->copy()->setTime(8, 53, 0),
        ]);

        CashFlow::create([
            'code' => 'PT-DOC-001',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-001',
            'status' => 'active',
            'time' => $baseDate->copy()->setTime(16, 12, 0),
            'created_at' => $baseDate->copy()->setTime(16, 12, 0),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        $ptEntry = $entries->firstWhere('code', 'PT-DOC-001');

        $this->assertNotNull($ptEntry);
        $this->assertEquals('HD-DOC-001', $ptEntry['reference_code'] ?? null);
        $this->assertEquals('HD-DOC-001', $ptEntry['payment_for_code'] ?? null);
        $this->assertEquals('HD-DOC-001', $ptEntry['linked_document_code'] ?? null);
    }

    public function test_running_balance_uses_chronological_asc(): void
    {
        $customer = $this->createTestCustomer();
        $baseDate = Carbon::today();
        
        Invoice::create([
            'code' => 'HD-DOC-001',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'transaction_date' => $baseDate->copy()->setTime(8, 53, 0),
            'created_at' => $baseDate->copy()->setTime(8, 53, 0),
        ]);

        CashFlow::create([
            'code' => 'PT-DOC-001',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-001',
            'status' => 'active',
            'time' => $baseDate->copy()->setTime(16, 12, 0),
            'created_at' => $baseDate->copy()->setTime(16, 12, 0),
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);

        // Sorted DESC in display, so PT-DOC-001 is index 0, HD-DOC-001 is index 1
        $ptEntry = $entries->firstWhere('code', 'PT-DOC-001');
        $hdEntry = $entries->firstWhere('code', 'HD-DOC-001');

        $this->assertNotNull($ptEntry);
        $this->assertNotNull($hdEntry);

        // HD happens first (ASC): balance = 800000
        $this->assertEquals(800000.0, (float) $hdEntry['customer_display_running_balance']);
        // PT happens second (ASC): balance = 800000 - 500000 = 300000
        $this->assertEquals(300000.0, (float) $ptEntry['customer_display_running_balance']);
    }

    public function test_same_timestamp_tie_breaker_resembles_kiotviet(): void
    {
        $customer = $this->createTestCustomer();
        $baseDate = Carbon::today();
        $sameTime = $baseDate->copy()->setTime(14, 21, 0);

        Invoice::create([
            'code' => 'HD-DOC-001',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'transaction_date' => $sameTime,
            'created_at' => $sameTime,
        ]);

        CashFlow::create([
            'code' => 'PT-DOC-001',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-DOC-001',
            'status' => 'active',
            'time' => $sameTime,
            'created_at' => $sameTime,
        ]);

        $res = $this->service->build($customer);
        $entries = collect($res['entries']);
        $codes = $entries->pluck('code')->toArray();

        // Expected display (DESC) with same timestamp: PT-DOC-001 has display_order = 90, HD-DOC-001 has 50.
        // So PT-DOC-001 is displayed above HD-DOC-001.
        $this->assertEquals(['PT-DOC-001', 'HD-DOC-001'], $codes);

        $ptEntry = $entries->firstWhere('code', 'PT-DOC-001');
        $hdEntry = $entries->firstWhere('code', 'HD-DOC-001');

        // Expected chronological balance (ASC): HD-DOC-001 has balance_order = 10, PT-DOC-001 has 30.
        // HD is calculated first (+800k), PT second (-500k).
        $this->assertEquals(800000.0, (float) $hdEntry['customer_display_running_balance']);
        $this->assertEquals(300000.0, (float) $ptEntry['customer_display_running_balance']);
    }

    public function test_include_technical_only_for_audit_debug(): void
    {
        $customer = $this->createTestCustomer();
        
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-239',
            'amount' => 2000000,
            'debt_total' => 2000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::now(),
        ]);

        // Default: excluded
        $resDefault = $this->service->build($customer);
        $this->assertNull(collect($resDefault['entries'])->firstWhere('code', 'MERGE-CUSTOMER-239'));

        // Explicit: included
        $resExplicit = $this->service->build($customer, ['include_technical' => true]);
        $this->assertNotNull(collect($resExplicit['entries'])->firstWhere('code', 'MERGE-CUSTOMER-239'));
    }
}
