<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDualRoleTimelineFinancialDisplayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dual_role_reference_documents_keep_financial_values_on_both_screens(): void
    {
        $admin = User::create([
            'name' => 'Admin Dual Role Timeline Financial Display',
            'email' => 'admin-dual-role-timeline-financial-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'KH-NCC-FIN-' . uniqid(),
            'name' => 'Dual Role Financial Partner',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-FIN-001',
            'type' => 'adjustment',
            'amount' => 0,
            'debt_total' => 0,
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2025-11-18 09:00:00'),
        ]);

        Invoice::create([
            'code' => 'HD008170',
            'customer_id' => $partner->id,
            'subtotal' => 75_000_000,
            'discount' => 0,
            'total' => 75_000_000,
            'customer_paid' => 20_000_000,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2025-12-16 18:55:00'),
            'created_at' => Carbon::parse('2025-12-16 18:55:00'),
        ]);

        $customerResponse = $this->actingAs($admin)
            ->getJson("/customers/{$partner->id}/debt-history?per_page=100&page=1");

        $customerResponse->assertOk();
        $customerEntries = collect($customerResponse->json('entries'))->keyBy('code');

        $this->assertEquals(75_000_000, $customerEntries['HD008170']['customer_display_effect']);
        $this->assertEquals(75_000_000, $customerEntries['HD008170']['display_effect']);
        $this->assertEquals(0, $customerEntries['HD008170']['customer_balance_effect']);
        $this->assertEquals(0, $customerEntries['HD008170']['customer_effect']);
        $this->assertFalse($customerEntries['HD008170']['affects_debt_balance']);
        $this->assertEquals('Phải thu KH', $customerEntries['HD008170']['badge_label']);

        $this->assertEquals(-20_000_000, $customerEntries['TTHD008170']['customer_display_effect']);
        $this->assertEquals(-20_000_000, $customerEntries['TTHD008170']['display_effect']);
        $this->assertEquals(0, $customerEntries['TTHD008170']['customer_balance_effect']);
        $this->assertEquals(0, $customerEntries['TTHD008170']['customer_effect']);
        $this->assertFalse($customerEntries['TTHD008170']['affects_debt_balance']);
        $this->assertEquals('Thanh toán', $customerEntries['TTHD008170']['badge_label']);

        $supplierResponse = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&per_page=100&page=1");

        $supplierResponse->assertOk()
            ->assertJsonPath('summary.display_mode', 'supplier_partner_timeline')
            ->assertJsonPath('summary.orientation', 'supplier');

        $supplierEntries = collect($supplierResponse->json('entries'))->keyBy('code');

        $this->assertEquals(-75_000_000, $supplierEntries['HD008170']['supplier_display_effect']);
        $this->assertEquals(-75_000_000, $supplierEntries['HD008170']['supplier_partner_effect']);
        $this->assertEquals(0, $supplierEntries['HD008170']['supplier_balance_effect']);
        $this->assertNotNull($supplierEntries['HD008170']['supplier_display_running_balance']);
        $this->assertNotNull($supplierEntries['HD008170']['supplier_partner_running_balance']);
        $this->assertEquals('Phải thu KH', $supplierEntries['HD008170']['badge_label']);

        $this->assertEquals(20_000_000, $supplierEntries['TTHD008170']['supplier_display_effect']);
        $this->assertEquals(20_000_000, $supplierEntries['TTHD008170']['supplier_partner_effect']);
        $this->assertEquals(0, $supplierEntries['TTHD008170']['supplier_balance_effect']);
        $this->assertNotNull($supplierEntries['TTHD008170']['supplier_display_running_balance']);
        $this->assertNotNull($supplierEntries['TTHD008170']['supplier_partner_running_balance']);
        $this->assertEquals('Phải thu KH', $supplierEntries['TTHD008170']['badge_label']);

        $this->assertTrue($supplierResponse->json('summary.has_virtual_opening_balance'));
        $this->assertEquals(0, $supplierResponse->json('summary.display_balance_final'));
    }
}
