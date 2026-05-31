<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDualRolePartnerTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Supplier Partner Timeline Test',
            'email' => 'admin-supplier-partner-timeline-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_supplier_dual_role_partner_view_includes_customer_sales_and_supplier_purchases(): void
    {
        $partner = $this->createDualRolePartner([
            'debt_amount' => 3_250_000,
            'supplier_debt_amount' => 22_850_000,
        ]);

        $this->createInvoiceAndPayment($partner, 4_000_000, 750_000);
        $this->createPurchaseAndPayment($partner, 25_000_000, 2_150_000);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner");

        $response->assertOk();
        $data = $response->json();
        $entries = collect($data['entries']);

        $this->assertEquals('partner_net_timeline', $data['summary']['display_mode']);
        $this->assertTrue($data['summary']['is_supplier_tab_partner_timeline']);
        $this->assertEquals(3_250_000, $data['summary']['customer_receivable_balance']);
        $this->assertEquals(22_850_000, $data['summary']['supplier_payable_balance']);
        $this->assertEquals(-19_600_000, $data['summary']['partner_net_position']);
        $this->assertEquals(-19_600_000, $data['summary']['net']);

        $this->assertNotNull($entries->firstWhere('code', 'HD-PARTNER-001'));
        $this->assertNotNull($entries->firstWhere('code', 'TTHD-PARTNER-001'));
        $this->assertNotNull($entries->firstWhere('code', 'PN-PARTNER-001'));
        $this->assertNotNull($entries->firstWhere('code', 'PCPN-PARTNER-001'));

        $this->assertEquals(4_000_000, $entries->firstWhere('code', 'HD-PARTNER-001')['partner_effect']);
        $this->assertEquals(-750_000, $entries->firstWhere('code', 'TTHD-PARTNER-001')['partner_effect']);
        $this->assertEquals(-25_000_000, $entries->firstWhere('code', 'PN-PARTNER-001')['partner_effect']);
        $this->assertEquals(2_150_000, $entries->firstWhere('code', 'PCPN-PARTNER-001')['partner_effect']);
    }

    public function test_default_dual_role_supplier_endpoint_remains_pure_supplier_payable(): void
    {
        $partner = $this->createDualRolePartner([
            'debt_amount' => 3_250_000,
            'supplier_debt_amount' => 22_850_000,
        ]);

        $this->createInvoiceAndPayment($partner, 4_000_000, 750_000);
        $this->createPurchaseAndPayment($partner, 25_000_000, 2_150_000);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions");

        $response->assertOk();
        $data = $response->json();
        $entries = collect($data['entries']);

        $this->assertEquals('supplier_payable', $data['summary']['display_mode']);
        $this->assertFalse($data['summary']['is_supplier_tab_partner_timeline']);
        $this->assertEquals(22_850_000, $data['summary']['net']);
        $this->assertFalse($entries->contains(fn ($entry) => str_starts_with((string) $entry['code'], 'HD')));
        $this->assertFalse($entries->contains(fn ($entry) => str_starts_with((string) $entry['code'], 'TTHD')));
        $this->assertNotNull($entries->firstWhere('code', 'PN-PARTNER-001'));
        $this->assertNotNull($entries->firstWhere('code', 'PCPN-PARTNER-001'));
    }

    public function test_non_dual_supplier_tab_stays_supplier_payable(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-PAYABLE-ONLY',
            'name' => 'Supplier Payable Only',
            'debt_amount' => 0,
            'supplier_debt_amount' => 10_000_000,
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        Purchase::create([
            'code' => 'PN-PAYABLE-ONLY',
            'supplier_id' => $supplier->id,
            'total_amount' => 10_000_000,
            'paid_amount' => 0,
            'debt_amount' => 10_000_000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?view=partner");

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('supplier_payable', $data['summary']['display_mode']);
        $this->assertFalse($data['summary']['is_supplier_tab_partner_timeline']);
        $this->assertEquals(10_000_000, $data['summary']['net']);
        $this->assertEquals(10_000_000, collect($data['entries'])->firstWhere('code', 'PN-PAYABLE-ONLY')['supplier_effect']);
    }

    public function test_partner_view_pagination_keeps_full_summary(): void
    {
        $partner = $this->createDualRolePartner([
            'debt_amount' => 3_250_000,
            'supplier_debt_amount' => 22_850_000,
        ]);

        $this->createInvoiceAndPayment($partner, 4_000_000, 750_000);
        $this->createPurchaseAndPayment($partner, 25_000_000, 2_150_000);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&page=1&per_page=2");

        $response->assertOk();
        $data = $response->json();

        $this->assertCount(2, $data['entries']);
        $this->assertEquals(4, $data['pagination']['total']);
        $this->assertEquals(2, $data['pagination']['last_page']);
        $this->assertEquals(-19_600_000, $data['summary']['partner_net_position']);
        $this->assertEquals(-19_600_000, $data['summary']['net']);
    }

    private function createDualRolePartner(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'NCC-KH-PARTNER-' . uniqid(),
            'name' => 'Dual Role Partner',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => true,
        ], $overrides));
    }

    private function createInvoiceAndPayment(Customer $partner, float $total, float $paid): void
    {
        Invoice::create([
            'code' => 'HD-PARTNER-001',
            'customer_id' => $partner->id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'customer_paid' => $paid,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2026-05-01 09:00:00'),
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);
    }

    private function createPurchaseAndPayment(Customer $partner, float $total, float $paid): void
    {
        Purchase::create([
            'code' => 'PN-PARTNER-001',
            'supplier_id' => $partner->id,
            'total_amount' => $total,
            'paid_amount' => 0,
            'debt_amount' => $total,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-02 09:00:00'),
            'created_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        CashFlow::create([
            'code' => 'PCPN-PARTNER-001',
            'type' => 'payment',
            'amount' => $paid,
            'time' => Carbon::parse('2026-05-03 09:00:00'),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN-PARTNER-001',
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => Carbon::parse('2026-05-03 09:00:00'),
        ]);
    }
}
