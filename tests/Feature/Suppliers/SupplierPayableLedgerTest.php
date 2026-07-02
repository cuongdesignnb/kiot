<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierPayableLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Supplier Ledger Test',
            'email' => 'admin-sup-ledger-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    /**
     * Case 1 — Mirror giống ảnh KiotViet
     */
    public function test_supplier_kiotviet_dual_role_behavior(): void
    {
        $partner = Customer::create([
            'code' => 'LongPinKHNCCSup',
            'name' => 'Long pin',
            'debt_amount' => 0,
            'supplier_debt_amount' => 22850000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $baseTime = Carbon::now()->subDays(10);

        Purchase::create([
            'code' => 'PN000000',
            'supplier_id' => $partner->id,
            'total_amount' => 28820000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(1),
        ]);

        Purchase::create([
            'code' => 'PN003209',
            'supplier_id' => $partner->id,
            'total_amount' => 860000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(2),
        ]);

        CashFlow::create([
            'code' => 'PCPN003209',
            'type' => 'payment',
            'amount' => 980000,
            'time' => $baseTime->copy()->addMinutes(3),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003209',
            'status' => 'completed',
        ]);

        Purchase::create([
            'code' => 'PN003210',
            'supplier_id' => $partner->id,
            'total_amount' => 3380000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(4),
        ]);

        Purchase::create([
            'code' => 'PN003211',
            'supplier_id' => $partner->id,
            'total_amount' => 850000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(5),
        ]);

        CashFlow::create([
            'code' => 'PCPN003211',
            'type' => 'payment',
            'amount' => 980000,
            'time' => $baseTime->copy()->addMinutes(6),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003211',
            'status' => 'completed',
        ]);

        Purchase::create([
            'code' => 'PN003212',
            'supplier_id' => $partner->id,
            'total_amount' => 900000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(7),
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $partner->id,
            'code' => 'CB00306',
            'type' => 'adjustment',
            'amount' => -10000000,
            'created_at' => $baseTime->copy()->addMinutes(8),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/suppliers/{$partner->id}/debt-transactions");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        // Assert supplier ledger effects
        $pn3212 = $entries->firstWhere('code', 'PN003212');
        $this->assertNotNull($pn3212);
        $this->assertEquals(900000, $pn3212['supplier_effect']);

        $pcpn3211 = $entries->firstWhere('code', 'PCPN003211');
        $this->assertNotNull($pcpn3211);
        $this->assertEquals(-980000, $pcpn3211['supplier_effect']);

        $cb306 = $entries->firstWhere('code', 'CB00306');
        $this->assertNotNull($cb306);
        $this->assertEquals(-10000000, $cb306['supplier_effect']);

        // Final balance = 22,850,000
        $this->assertEquals(22850000, $data['summary']['net']);
    }

    /**
     * Case 3 — Không double count thanh toán NCC
     */
    public function test_no_double_count_payment(): void
    {
        $supplier = Customer::create([
            'code' => 'NCCDoublePay',
            'name' => 'Supplier double count test',
            'supplier_debt_amount' => 0,
            'is_supplier' => true,
        ]);

        // Purchase with paid_amount > 0
        Purchase::create([
            'code' => 'PN112233',
            'supplier_id' => $supplier->id,
            'total_amount' => 1000000,
            'paid_amount' => 500000,
            'status' => 'completed',
            'purchase_date' => Carbon::now()->subMinutes(5),
        ]);

        // Real cashflow payment corresponding
        CashFlow::create([
            'code' => 'PCPN112233',
            'type' => 'payment',
            'amount' => 500000,
            'time' => Carbon::now(),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN112233',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/suppliers/{$supplier->id}/debt-transactions");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        // Must only have one payment entry (the PCPN one)
        $payments = $entries->where('type', 'payment');
        $this->assertCount(1, $payments);
        $this->assertEquals('PCPN112233', $payments->first()['code']);
        $this->assertFalse($payments->contains(fn ($entry) => str_starts_with((string) $entry['code'], 'TTNH')));
    }

    /**
     * Case 4 — Legacy fallback
     */
    public function test_legacy_fallback_virtual_payment(): void
    {
        $supplier = Customer::create([
            'code' => 'NCCLegacy',
            'name' => 'Supplier legacy payment test',
            'supplier_debt_amount' => 0,
            'is_supplier' => true,
        ]);

        // Purchase with paid_amount > 0 and NO cashflow or supplier debt transaction
        Purchase::create([
            'code' => 'PN998877',
            'supplier_id' => $supplier->id,
            'total_amount' => 1000000,
            'paid_amount' => 400000,
            'status' => 'completed',
            'purchase_date' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/suppliers/{$supplier->id}/debt-transactions");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        $virtualPay = $entries->firstWhere('code', 'TTNH998877');
        $this->assertNotNull($virtualPay);
        $this->assertEquals(-400000, $virtualPay['supplier_effect']);
        $this->assertEquals('legacy_purchase_paid_amount', $virtualPay['source']);
    }

    /**
     * Case 5 — GET không ghi DB
     */
    public function test_get_supplier_endpoints_do_not_write_to_db(): void
    {
        $supplier = Customer::create([
            'code' => 'NCCStrictReadOnly',
            'name' => 'NCC GET strictly read only',
            'supplier_debt_amount' => 0,
            'is_supplier' => true,
        ]);

        $customerDebtsCount = CustomerDebt::count();
        $supplierTxsCount = SupplierDebtTransaction::count();
        $cashflowsCount = CashFlow::count();
        $debtOffsetsCount = DebtOffset::count();
        $customersCount = Customer::count();

        // Perform GET request
        $response = $this->actingAs($this->admin)->getJson("/api/suppliers/{$supplier->id}/debt-transactions");
        $response->assertOk();

        $this->assertEquals($customerDebtsCount, CustomerDebt::count());
        $this->assertEquals($supplierTxsCount, SupplierDebtTransaction::count());
        $this->assertEquals($cashflowsCount, CashFlow::count());
        $this->assertEquals($debtOffsetsCount, DebtOffset::count());
        $this->assertEquals($customersCount, Customer::count());
    }

    /**
     * Case 6 — Cấn bằng thật
     */
    public function test_supplier_offset_displays_correctly(): void
    {
        $partner = Customer::create([
            'code' => 'NCCRealOffset',
            'name' => 'NCC Real Offset Partner',
            'supplier_debt_amount' => 5000000,
            'is_supplier' => true,
        ]);

        // Cấn bằng công nợ CB123456
        SupplierDebtTransaction::create([
            'supplier_id' => $partner->id,
            'code' => 'CB123456',
            'type' => 'offset',
            'amount' => -5000000, // giảm payable nợ NCC
            'note' => 'Cấn trừ nợ Long Pin',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/suppliers/{$partner->id}/debt-transactions");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        $offsetEntry = $entries->firstWhere('code', 'CB123456');
        $this->assertNotNull($offsetEntry);
        $this->assertEquals(-5000000, $offsetEntry['supplier_effect']);
        $this->assertEquals('offset', $offsetEntry['type']);
        $this->assertEquals('Điều chỉnh', $offsetEntry['type_label']);
    }
}
