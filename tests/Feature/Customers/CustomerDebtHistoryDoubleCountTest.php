<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtHistoryDoubleCountTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Double Count Test',
            'email' => 'admin-double-count-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    /**
     * Test 1 — Thiên Phú case: has customer_debts ledger + invoices legacy + purchases
     */
    public function test_thien_phu_double_count_prevented_correctly(): void
    {
        $customer = Customer::create([
            'code' => 'NCC177950763826',
            'name' => 'Anh Thanh Thiên Phú',
            'phone' => '0974321888',
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // 1. CustomerDebts ledger entries
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-141',
            'amount' => 47420000,
            'debt_total' => 47420000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::now()->subDays(5),
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'CKTT26052510573737',
            'amount' => -20000,
            'debt_total' => 47400000,
            'type' => 'payment',
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::now()->subDays(4),
        ]);

        // 2. Legacy Invoices (should become reference, non-affecting)
        Invoice::create([
            'code' => 'HD177727497421',
            'customer_id' => $customer->id,
            'total' => 7200000,
            'customer_paid' => 7200000,
            'status' => 'Hoàn thành',
            'subtotal' => 7200000,
            'discount' => 0,
        ]);

        Invoice::create([
            'code' => 'HD177932991721',
            'customer_id' => $customer->id,
            'total' => 42320000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'subtotal' => 42320000,
            'discount' => 0,
        ]);

        Invoice::create([
            'code' => 'HD177933240323',
            'customer_id' => $customer->id,
            'total' => 7000000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'subtotal' => 7000000,
            'discount' => 0,
        ]);

        Invoice::create([
            'code' => 'HD177933714532',
            'customer_id' => $customer->id,
            'total' => 5100000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'subtotal' => 5100000,
            'discount' => 0,
        ]);

        // 3. Completed Purchases
        $purchasesData = [62100000, 2100000, 5400000, 2700000, 2700000];
        foreach ($purchasesData as $idx => $total) {
            Purchase::create([
                'code' => 'PN' . (20260523105400 + $idx),
                'supplier_id' => $customer->id,
                'total_amount' => $total,
                'paid_amount' => 0,
                'debt_amount' => $total,
                'status' => 'completed',
                'purchase_date' => Carbon::now()->subDays(3)->addHours($idx),
            ]);
        }

        // Call the API endpoint
        $response = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history");

        $response->assertOk();
        $data = $response->json();

        // Assert Net Debt details
        $this->assertEquals(-27600000, $data['summary']['net']);
        $this->assertEquals(-27600000, $data['reconcile']['current_net_debt']);
        $this->assertEquals(-27600000, $data['reconcile']['computed_balance']);
        $this->assertFalse($data['reconcile']['has_mismatch']);

        $entries = collect($data['entries']);

        // Check ledger entries affect balance
        $mergeEntry = $entries->firstWhere('code', 'MERGE-CUSTOMER-141');
        $this->assertNotNull($mergeEntry);
        $this->assertTrue($mergeEntry['affects_debt_balance']);
        $this->assertEquals(47420000, $mergeEntry['customer_effect']);

        $ckttEntry = $entries->firstWhere('code', 'CKTT26052510573737');
        $this->assertNotNull($ckttEntry);
        $this->assertTrue($ckttEntry['affects_debt_balance']);
        $this->assertEquals(-20000, $ckttEntry['customer_effect']);

        // Check legacy invoices do NOT affect balance
        $legacyInvoice = $entries->firstWhere('code', 'HD177932991721');
        $this->assertNotNull($legacyInvoice);
        $this->assertFalse($legacyInvoice['affects_debt_balance']);
        $this->assertEquals(0, $legacyInvoice['customer_effect']);
        $this->assertEquals('Tham khảo', $legacyInvoice['badge_label']);

        // Check purchases affect balance
        $purchaseEntry = $entries->firstWhere('code', 'PN20260523105400');
        $this->assertNotNull($purchaseEntry);
        $this->assertTrue($purchaseEntry['affects_debt_balance']);
        $this->assertEquals(-62100000, $purchaseEntry['customer_effect']);
        $this->assertEquals('Phiếu nhập', $purchaseEntry['badge_label']);
    }

    /**
     * Test 2 — Customer without ledger uses legacy fallback
     */
    public function test_customer_without_ledger_uses_legacy_fallback(): void
    {
        $customer = Customer::create([
            'code' => 'KH-LEGACY-' . uniqid(),
            'name' => 'Legacy Customer',
            'phone' => '0901234567',
            'debt_amount' => 10000000,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        Invoice::create([
            'code' => 'HD100000',
            'customer_id' => $customer->id,
            'total' => 10000000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'subtotal' => 10000000,
            'discount' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history");

        $response->assertOk();
        $data = $response->json();

        $entries = collect($data['entries']);
        $legacyInvoice = $entries->firstWhere('code', 'HD100000');

        $this->assertNotNull($legacyInvoice);
        $this->assertTrue($legacyInvoice['affects_debt_balance']);
        $this->assertEquals(10000000, $legacyInvoice['customer_effect']);
        $this->assertEquals('Chứng từ cũ', $legacyInvoice['badge_label']);
        $this->assertEquals(10000000, $data['reconcile']['computed_balance']);
    }

    /**
     * Test 3 — TTHD virtual payment is reference only when ledger exists
     */
    public function test_tthd_is_reference_only_when_ledger_exists(): void
    {
        $customer = Customer::create([
            'code' => 'KH-LEDGER-VIRTUAL-' . uniqid(),
            'name' => 'Ledger Virtual Customer',
            'phone' => '0901234568',
            'debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-999',
            'amount' => 7200000,
            'debt_total' => 7200000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::now()->subDays(2),
        ]);

        Invoice::create([
            'code' => 'HD720000',
            'customer_id' => $customer->id,
            'total' => 7200000,
            'customer_paid' => 7200000,
            'status' => 'Hoàn thành',
            'subtotal' => 7200000,
            'discount' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history");

        $response->assertOk();
        $data = $response->json();

        $entries = collect($data['entries']);
        $tthdPayment = $entries->firstWhere('code', 'TTHD720000');

        $this->assertNotNull($tthdPayment);
        $this->assertFalse($tthdPayment['affects_debt_balance']);
        $this->assertEquals(0, $tthdPayment['customer_effect']);
        $this->assertEquals('Tham khảo', $tthdPayment['badge_label']);
    }

    /**
     * Test 4 — Purchase side does not double count supplier transactions
     */
    public function test_purchase_side_does_not_double_count_supplier_transactions(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NCC-DUAL-' . uniqid(),
            'name' => 'Dual NCC Partner',
            'phone' => '0901234569',
            'debt_amount' => 0,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        Purchase::create([
            'code' => 'PN-75000000',
            'supplier_id' => $customer->id,
            'total_amount' => 75000000,
            'paid_amount' => 0,
            'debt_amount' => 75000000,
            'status' => 'completed',
            'purchase_date' => Carbon::now()->subDays(2),
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $customer->id,
            'code' => 'STX-999',
            'type' => 'payment',
            'amount' => -64200000,
            'debt_remain' => 10800000,
            'user_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history");

        $response->assertOk();
        $data = $response->json();

        $entries = collect($data['entries']);

        // Check purchase affects balance
        $purchaseEntry = $entries->firstWhere('code', 'PN-75000000');
        $this->assertNotNull($purchaseEntry);
        $this->assertTrue($purchaseEntry['affects_debt_balance']);
        $this->assertEquals(-75000000, $purchaseEntry['customer_effect']);

        // Check supplier transaction does NOT affect balance
        $stxEntry = $entries->firstWhere('code', 'STX-999');
        $this->assertNotNull($stxEntry);
        $this->assertFalse($stxEntry['affects_debt_balance']);
        $this->assertEquals(0, $stxEntry['customer_effect']);
        $this->assertEquals('Tham khảo', $stxEntry['badge_label']);

        $this->assertEquals(-75000000, $data['reconcile']['computed_balance']);
        $this->assertFalse($data['reconcile']['has_mismatch']);
    }
}
