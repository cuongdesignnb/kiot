<?php

namespace Tests\Feature\Customers;

use Tests\TestCase;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Carbon\Carbon;

class AnhThanhThienPhuDebtReconcileTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin-test-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_anh_thanh_thien_phu_reconciliation_calculations_and_api(): void
    {
        // Setup Customer
        $partner = Customer::create([
            'code' => 'KH177727496998',
            'name' => 'Anh Thanh Thiên Phú',
            'phone' => '0974321888',
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // CustomerDebt: MERGE-CUSTOMER-141 amount +47420000
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-CUSTOMER-141',
            'amount' => 47420000,
            'debt_total' => 47420000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        // CustomerDebt: CKTT26052510573737 amount -20000
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'CKTT26052510573737',
            'amount' => -20000,
            'debt_total' => 47400000,
            'type' => 'payment',
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);

        // Purchases total 75.000.000
        $purchases = [
            ['PN20260523105400', 62100000, '2026-05-23 10:54:00'],
            ['PN20260523143050', 2100000, '2026-05-23 14:30:50'],
            ['PN20260527150940', 5400000, '2026-05-27 15:09:40'],
            ['PN20260527163153', 2700000, '2026-05-27 16:31:53'],
            ['PN20260528090703', 2700000, '2026-05-28 09:07:03'],
        ];

        foreach ($purchases as [$code, $total, $date]) {
            Purchase::create([
                'code' => $code,
                'supplier_id' => $partner->id,
                'total_amount' => $total,
                'paid_amount' => 0,
                'debt_amount' => $total,
                'status' => 'completed',
                'purchase_date' => Carbon::parse($date),
            ]);
        }

        // 1) Test customer debt-history API
        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history?include_technical=1");
        $response->assertOk();
        $data = $response->json();

        // Assert summaries
        $this->assertEquals(47400000, $data['summary']['customer_debt_amount']);
        $this->assertEquals(75000000, $data['summary']['supplier_debt_amount']);
        $this->assertEquals(-27600000, $data['summary']['net_debt_amount']);
        $this->assertEquals(-27600000, $data['reconcile']['computed_balance']);
        $this->assertFalse($data['reconcile']['has_mismatch']);
        $this->assertEquals($data['summary']['display_balance_target'], $data['summary']['display_balance_final']);
        $this->assertNotEquals('warning', $data['reconcile']['severity']);
        $this->assertFalse($data['reconcile']['user_warning']);
        $this->assertFalse($data['reconcile']['ledger_mismatch']);
        $this->assertTrue($data['reconcile']['display_resolved']);

        $entries = collect($data['entries']);

        // Assert MERGE
        $merge = $entries->firstWhere('code', 'MERGE-CUSTOMER-141');
        $this->assertNotNull($merge);
        $this->assertEquals('Số dư đầu kỳ / Gộp công nợ', $merge['display_type']);
        $this->assertEquals(47420000, $merge['customer_effect']);
        $this->assertTrue($merge['affects_debt_balance']);

        // Assert CKTT (not double counted, reduces customer receivable)
        $cktt = $entries->firstWhere('code', 'CKTT26052510573737');
        $this->assertNotNull($cktt);
        $this->assertEquals('Chiết khấu thanh toán', $cktt['display_type']);
        $this->assertEquals(-20000, $cktt['customer_effect']);
        $this->assertTrue($cktt['affects_debt_balance']);

        // Assert customer PN mirror entry has negative effect
        $pnMirror = $entries->firstWhere('code', 'PN20260523105400');
        $this->assertNotNull($pnMirror);
        $this->assertEquals(-62100000, $pnMirror['customer_effect']);
        $this->assertTrue($pnMirror['affects_debt_balance']);

        // 2) Test supplier debt-transactions API
        $supResponse = $this->actingAs($this->admin)->getJson("/api/suppliers/{$partner->id}/debt-transactions");
        $supResponse->assertOk();
        $supData = $supResponse->json();

        // Assert summaries
        $this->assertEquals(75000000, $supData['summary']['net']);
        $this->assertEquals(47400000, $supData['summary']['customer_debt_amount']);
        $this->assertEquals(75000000, $supData['summary']['supplier_debt_amount']);
        $this->assertEquals(-27600000, $supData['summary']['net_debt_amount']);
        $this->assertEquals($supData['summary']['display_balance_target'], $supData['summary']['display_balance_final']);
        $this->assertNotEquals('warning', $supData['reconcile']['severity']);
        $this->assertFalse($supData['reconcile']['user_warning']);
        $this->assertFalse($supData['reconcile']['ledger_mismatch']);
        $this->assertTrue($supData['reconcile']['display_resolved']);

        $supEntries = collect($supData['entries']);

        // Assert supplier PN entry has positive effect
        $pnSup = $supEntries->firstWhere('code', 'PN20260523105400');
        $this->assertNotNull($pnSup);
        $this->assertEquals('purchase', $pnSup['type']);
        $this->assertEquals(62100000, $pnSup['supplier_effect']);
        $this->assertTrue($pnSup['affects_debt_balance']);
    }
}
