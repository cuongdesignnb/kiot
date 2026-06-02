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

class SupplierDualRolePartnerRuntimeApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_partner_api_uses_kiotviet_supplier_orientation(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Partner Runtime Api Test',
            'email' => 'admin-supplier-partner-runtime-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-KH-RUNTIME-' . uniqid(),
            'name' => 'Dual Role Runtime Partner',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        Invoice::create([
            'code' => 'HD008236',
            'customer_id' => $partner->id,
            'subtotal' => 7_000_000,
            'discount' => 0,
            'total' => 7_000_000,
            'customer_paid' => 5_000_000,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2026-05-01 09:00:00'),
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        Purchase::create([
            'code' => 'PN003806',
            'supplier_id' => $partner->id,
            'total_amount' => 5_000_000,
            'paid_amount' => 0,
            'debt_amount' => 5_000_000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-02 09:00:00'),
            'created_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        CashFlow::create([
            'code' => 'PCPN003806',
            'type' => 'payment',
            'amount' => 3_000_000,
            'time' => Carbon::parse('2026-05-03 09:00:00'),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003806',
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => Carbon::parse('2026-05-03 09:00:00'),
        ]);

        $response = $this->actingAs($admin)->getJson(
            "/api/suppliers/{$partner->id}/debt-transactions?view=partner&page=1&per_page=20"
        );

        $response->assertOk()
            ->assertJsonPath('summary.display_mode', 'supplier_partner_timeline')
            ->assertJsonPath('summary.legacy_display_mode', 'partner_net_timeline')
            ->assertJsonPath('summary.orientation', 'supplier')
            ->assertJsonPath('summary.is_supplier_tab_partner_timeline', true)
            ->assertJsonPath('summary.partner_net_position', 0)
            ->assertJsonPath('summary.supplier_oriented_balance', 0)
            ->assertJsonPath('summary.net', 0);

        $entries = collect($response->json('entries'))->keyBy('code');

        $this->assertEquals(-7_000_000, $entries['HD008236']['partner_effect']);
        $this->assertEquals(5_000_000, $entries['TTHD008236']['partner_effect']);
        $this->assertEquals(5_000_000, $entries['PN003806']['partner_effect']);
        $this->assertEquals(-3_000_000, $entries['PCPN003806']['partner_effect']);

        $this->assertEquals(-7_000_000, $entries['HD008236']['supplier_partner_effect']);
        $this->assertEquals(5_000_000, $entries['TTHD008236']['supplier_partner_effect']);
        $this->assertEquals(5_000_000, $entries['PN003806']['supplier_partner_effect']);
        $this->assertEquals(-3_000_000, $entries['PCPN003806']['supplier_partner_effect']);

        $this->assertEquals(-7_000_000, $entries['HD008236']['partner_running_balance']);
        $this->assertEquals(-2_000_000, $entries['TTHD008236']['partner_running_balance']);
        $this->assertEquals(3_000_000, $entries['PN003806']['partner_running_balance']);
        $this->assertEquals(0, $entries['PCPN003806']['partner_running_balance']);

        $this->assertEquals(-7_000_000, $entries['HD008236']['supplier_partner_running_balance']);
        $this->assertEquals(-2_000_000, $entries['TTHD008236']['supplier_partner_running_balance']);
        $this->assertEquals(3_000_000, $entries['PN003806']['supplier_partner_running_balance']);
        $this->assertEquals(0, $entries['PCPN003806']['supplier_partner_running_balance']);
    }
}
