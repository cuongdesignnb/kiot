<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerFinancialTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Partner Timeline Test',
            'email' => 'admin-partner-timeline-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_thien_phu_timeline_matches_net_debt(): void
    {
        $customer = Customer::create([
            'code' => 'KH-THIEN-PHU-' . uniqid(),
            'name' => 'Thiên Phú',
            'phone' => '0974321888',
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-141',
            'amount' => 47420000,
            'debt_total' => 47420000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'CKTT26052510573737',
            'amount' => -20000,
            'debt_total' => 47400000,
            'type' => 'payment',
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);

        foreach ([
            ['HD177727497421', 7200000, 7200000],
            ['HD177932991721', 42320000, 0],
            ['HD177933240323', 7000000, 0],
            ['HD177933714532', 5100000, 0],
        ] as [$code, $total, $paid]) {
            Invoice::create([
                'code' => $code,
                'customer_id' => $customer->id,
                'subtotal' => $total,
                'discount' => 0,
                'total' => $total,
                'customer_paid' => $paid,
                'status' => 'Hoàn thành',
                'transaction_date' => Carbon::parse('2026-05-22 09:00:00'),
            ]);
        }

        foreach ([
            ['PN20260523105400', 62100000, '2026-05-23 10:54:00'],
            ['PN20260523143050', 2100000, '2026-05-23 14:30:50'],
            ['PN20260527150940', 5400000, '2026-05-27 15:09:40'],
            ['PN20260527163153', 2700000, '2026-05-27 16:31:53'],
            ['PN20260528090703', 2700000, '2026-05-28 09:07:03'],
        ] as [$code, $total, $date]) {
            Purchase::create([
                'code' => $code,
                'supplier_id' => $customer->id,
                'total_amount' => $total,
                'paid_amount' => 0,
                'debt_amount' => $total,
                'status' => 'completed',
                'purchase_date' => Carbon::parse($date),
            ]);
        }

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $this->assertEquals(-27600000, $data['summary']['net']);
        $this->assertEquals(-27600000, $data['reconcile']['computed_balance']);
        $this->assertFalse($data['reconcile']['has_mismatch']);

        $merge = $entries->firstWhere('code', 'MERGE-CUSTOMER-141');
        $this->assertEquals('Số dư đầu kỳ / Gộp công nợ', $merge['display_type']);
        $this->assertTrue($merge['affects_debt_balance']);

        $discount = $entries->firstWhere('code', 'CKTT26052510573737');
        $this->assertEquals('Chiết khấu thanh toán', $discount['display_type']);
        $this->assertEquals(-20000, $discount['customer_effect']);

        $legacyInvoice = $entries->firstWhere('code', 'HD177932991721');
        $this->assertFalse($legacyInvoice['affects_debt_balance']);
        $this->assertEquals('Đã hạch toán', $legacyInvoice['badge_label']);
        $this->assertStringContainsString('không cộng lại', $legacyInvoice['balance_note']);
        $this->assertNotEmpty($legacyInvoice['time']);
        $this->assertEquals(0, $legacyInvoice['customer_effect']);

        $purchase = $entries->firstWhere('code', 'PN20260523105400');
        $this->assertEquals('Nhập hàng', $purchase['display_type']);
        $this->assertTrue($purchase['affects_debt_balance']);
        $this->assertEquals(-62100000, $purchase['customer_effect']);

        $affectingInvoiceCodes = $entries
            ->whereIn('code', ['HD177932991721', 'HD177933240323', 'HD177933714532'])
            ->where('affects_debt_balance', true)
            ->pluck('code');
        $this->assertCount(0, $affectingInvoiceCodes);
    }

    public function test_customer_without_ledger_uses_legacy_invoice(): void
    {
        $customer = $this->createCustomer(['debt_amount' => 10000000]);

        Invoice::create([
            'code' => 'HD-LEGACY-10000000',
            'customer_id' => $customer->id,
            'subtotal' => 10000000,
            'discount' => 0,
            'total' => 10000000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
        ]);

        $data = $this->getDebtHistory($customer);
        $invoice = collect($data['entries'])->firstWhere('code', 'HD-LEGACY-10000000');

        $this->assertTrue($invoice['affects_debt_balance']);
        $this->assertEquals(10000000, $invoice['customer_effect']);
        $this->assertEquals(10000000, $data['reconcile']['computed_balance']);
    }

    public function test_customer_payment_ledger_has_clear_payment_label(): void
    {
        $customer = $this->createCustomer(['debt_amount' => -5000000]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'PT0001',
            'amount' => -5000000,
            'debt_total' => -5000000,
            'type' => 'payment',
            'recorded_at' => Carbon::parse('2026-05-24 10:00:00'),
        ]);

        $entry = collect($this->getDebtHistory($customer)['entries'])->firstWhere('code', 'PT0001');

        $this->assertEquals('Khách thanh toán', $entry['display_type']);
        $this->assertEquals(-5000000, $entry['customer_effect']);
        $this->assertTrue($entry['affects_debt_balance']);
    }

    public function test_sales_return_ledger_has_sales_return_label(): void
    {
        $customer = $this->createCustomer(['debt_amount' => -3000000]);

        $return = OrderReturn::create([
            'code' => 'TH0001',
            'customer_id' => $customer->id,
            'status' => 'Đã trả',
            'subtotal' => 3000000,
            'discount' => 0,
            'fee' => 0,
            'total' => 3000000,
            'paid_to_customer' => 0,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => 'TH0001',
            'amount' => -3000000,
            'debt_total' => -3000000,
            'type' => 'return',
            'recorded_at' => Carbon::parse('2026-05-24 11:00:00'),
        ]);

        $entry = collect($this->getDebtHistory($customer)['entries'])->firstWhere('id', 'ldg-' . CustomerDebt::latest('id')->first()->id);

        $this->assertEquals('Trả hàng bán', $entry['display_type']);
        $this->assertEquals(-3000000, $entry['customer_effect']);
        $this->assertTrue($entry['detail_available']);
    }

    public function test_tthd_with_ledger_is_reference(): void
    {
        $customer = $this->createCustomer(['debt_amount' => 0]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'PT-LEDGER-1',
            'amount' => -7200000,
            'debt_total' => 0,
            'type' => 'payment',
            'recorded_at' => Carbon::parse('2026-05-24 09:00:00'),
        ]);

        Invoice::create([
            'code' => 'HD7200000',
            'customer_id' => $customer->id,
            'subtotal' => 7200000,
            'discount' => 0,
            'total' => 7200000,
            'customer_paid' => 7200000,
            'status' => 'Hoàn thành',
        ]);

        $payment = collect($this->getDebtHistory($customer)['entries'])->firstWhere('code', 'TTHD7200000');

        $this->assertFalse($payment['affects_debt_balance']);
        $this->assertEquals('Đã hạch toán', $payment['badge_label']);
        $this->assertStringContainsString('không cộng lại', $payment['balance_note']);
    }

    public function test_tthd_without_ledger_affects_balance(): void
    {
        $customer = $this->createCustomer(['debt_amount' => 0]);

        Invoice::create([
            'code' => 'HD7200001',
            'customer_id' => $customer->id,
            'subtotal' => 7200000,
            'discount' => 0,
            'total' => 7200000,
            'customer_paid' => 7200000,
            'status' => 'Hoàn thành',
        ]);

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $this->assertEquals(7200000, $entries->firstWhere('code', 'HD7200001')['customer_effect']);
        $this->assertEquals(-7200000, $entries->firstWhere('code', 'TTHD7200001')['customer_effect']);
        $this->assertEquals('Chứng từ cũ', $entries->firstWhere('code', 'HD7200001')['badge_label']);
        $this->assertEquals('Thanh toán HĐ', $entries->firstWhere('code', 'TTHD7200001')['badge_label']);
        $this->assertEquals(0, $data['reconcile']['computed_balance']);
    }

    public function test_supplier_payment_in_customer_screen_offsets_purchase(): void
    {
        $customer = $this->createCustomer([
            'debt_amount' => 0,
            'supplier_debt_amount' => 6000000,
            'is_supplier' => true,
        ]);

        Purchase::create([
            'code' => 'PN-PARTIAL-1',
            'supplier_id' => $customer->id,
            'total_amount' => 10000000,
            'paid_amount' => 4000000,
            'debt_amount' => 6000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-24 12:00:00'),
        ]);

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $this->assertEquals(-10000000, $entries->firstWhere('code', 'PN-PARTIAL-1')['customer_effect']);
        $this->assertEquals(4000000, $entries->firstWhere('display_type', 'Thanh toán NCC')['customer_effect']);
        $this->assertNotEmpty($entries->firstWhere('code', 'PN-PARTIAL-1')['time']);
        $this->assertEquals(-6000000, $data['reconcile']['computed_balance']);
    }

    public function test_purchase_return_in_customer_screen_increases_customer_net_debt(): void
    {
        $customer = $this->createCustomer([
            'debt_amount' => 0,
            'supplier_debt_amount' => 8000000,
            'is_supplier' => true,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-RETURN-1',
            'supplier_id' => $customer->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-24 12:00:00'),
        ]);

        PurchaseReturn::create([
            'code' => 'THN-RETURN-1',
            'purchase_id' => $purchase->id,
            'supplier_id' => $customer->id,
            'total_amount' => 2000000,
            'refund_amount' => 0,
            'status' => 'completed',
            'return_date' => Carbon::parse('2026-05-25 12:00:00'),
        ]);

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $this->assertEquals(-10000000, $entries->firstWhere('code', 'PN-RETURN-1')['customer_effect']);
        $this->assertEquals(2000000, $entries->firstWhere('code', 'THN-RETURN-1')['customer_effect']);
        $this->assertEquals(-8000000, $data['reconcile']['computed_balance']);
    }

    public function test_purchase_before_customer_ledger_does_not_reset_net_balance_with_debt_total(): void
    {
        $customer = $this->createCustomer([
            'debt_amount' => 5000000,
            'supplier_debt_amount' => 10000000,
            'is_supplier' => true,
        ]);

        Purchase::create([
            'code' => 'PN-BEFORE-LEDGER',
            'supplier_id' => $customer->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-AFTER-PURCHASE',
            'amount' => 5000000,
            'debt_total' => 5000000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $purchase = $entries->firstWhere('code', 'PN-BEFORE-LEDGER');
        $merge = $entries->firstWhere('code', 'MERGE-AFTER-PURCHASE');

        $this->assertEquals(-10000000, $purchase['customer_effect']);
        $this->assertEquals(-10000000, $purchase['balance']);
        $this->assertEquals(5000000, $merge['customer_effect']);
        $this->assertEquals(-5000000, $merge['balance']);
        $this->assertEquals(-5000000, $data['reconcile']['computed_balance']);
        $this->assertEquals(-5000000, $data['reconcile']['current_net_debt']);
        $this->assertFalse($data['reconcile']['has_mismatch']);
    }

    public function test_supplier_entry_between_customer_ledgers_keeps_net_running_balance(): void
    {
        $customer = $this->createCustomer([
            'debt_amount' => 8000000,
            'supplier_debt_amount' => 3000000,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'OPENING-1',
            'amount' => 10000000,
            'debt_total' => 10000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        Purchase::create([
            'code' => 'PN-MIDDLE-1',
            'supplier_id' => $customer->id,
            'total_amount' => 3000000,
            'paid_amount' => 0,
            'debt_amount' => 3000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'PAY-1',
            'amount' => -2000000,
            'debt_total' => 8000000,
            'type' => 'payment',
            'recorded_at' => Carbon::parse('2026-05-03 09:00:00'),
        ]);

        $data = $this->getDebtHistory($customer);
        $entries = collect($data['entries']);

        $this->assertEquals(10000000, $entries->firstWhere('code', 'OPENING-1')['balance']);
        $this->assertEquals(7000000, $entries->firstWhere('code', 'PN-MIDDLE-1')['balance']);
        $this->assertEquals(5000000, $entries->firstWhere('code', 'PAY-1')['balance']);
        $this->assertEquals(5000000, $data['reconcile']['computed_balance']);
        $this->assertEquals(5000000, $data['reconcile']['current_net_debt']);
        $this->assertFalse($data['reconcile']['has_mismatch']);
    }

    public function test_ledger_only_customer_keeps_debt_total_metadata(): void
    {
        $customer = $this->createCustomer(['debt_amount' => 1000000]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'LEDGER-META-1',
            'amount' => 1000000,
            'debt_total' => 1000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        $data = $this->getDebtHistory($customer);
        $entry = collect($data['entries'])->firstWhere('code', 'LEDGER-META-1');

        $this->assertEquals(1000000, $data['reconcile']['computed_balance']);
        $this->assertEquals(1000000, $entry['debt_total']);
        $this->assertEquals(1000000, $entry['ledger_debt_total']);
    }

    public function test_timeline_entries_expose_time_used_for_sorting(): void
    {
        $customer = $this->createCustomer([
            'debt_amount' => 1000000,
            'supplier_debt_amount' => 2000000,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'LEDGER-TIME-1',
            'amount' => 1000000,
            'debt_total' => 1000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        Invoice::create([
            'code' => 'HD-TIME-1',
            'customer_id' => $customer->id,
            'subtotal' => 1000000,
            'discount' => 0,
            'total' => 1000000,
            'customer_paid' => 500000,
            'status' => 'Hoàn thành',
            'transaction_date' => Carbon::parse('2026-05-02 10:00:00'),
        ]);

        Purchase::create([
            'code' => 'PN-TIME-1',
            'supplier_id' => $customer->id,
            'total_amount' => 2000000,
            'paid_amount' => 0,
            'debt_amount' => 2000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-03 11:00:00'),
        ]);

        $entries = collect($this->getDebtHistory($customer)['entries']);

        foreach (['LEDGER-TIME-1', 'HD-TIME-1', 'TTHD-TIME-1', 'PN-TIME-1'] as $code) {
            $entry = $entries->firstWhere('code', $code);
            $this->assertNotNull($entry, "Missing timeline entry {$code}");
            $this->assertNotEmpty($entry['time'], "Timeline entry {$code} must expose time");
            $this->assertArrayHasKey('created_at', $entry);
        }
    }

    private function createCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'KH-TIMELINE-' . uniqid(),
            'name' => 'Timeline Customer',
            'phone' => '0900000000',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ], $overrides));
    }

    private function getDebtHistory(Customer $customer): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history");

        $response->assertOk();

        return $response->json();
    }
}
