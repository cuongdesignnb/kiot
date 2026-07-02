<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDualRoleOrientationKiotVietTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_screen_uses_supplier_orientation_for_dual_role_partner_like_kiotviet(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Dual Role KiotViet Test',
            'email' => 'admin-supplier-dual-role-kv-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-KH-KIOTVIET-' . uniqid(),
            'name' => 'Dual Role KiotViet Partner',
            'debt_amount' => 2_000_000,
            'supplier_debt_amount' => 2_000_000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'CB000345',
            'type' => 'adjustment',
            'amount' => 0,
            'debt_total' => 0,
            'recorded_at' => Carbon::parse('2026-06-01 23:01:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'HD008236',
            'type' => 'sale',
            'amount' => 7_000_000,
            'debt_total' => 7_000_000,
            'recorded_at' => Carbon::parse('2026-06-01 23:31:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'TTHD008236',
            'type' => 'payment',
            'amount' => -5_000_000,
            'debt_total' => 2_000_000,
            'recorded_at' => Carbon::parse('2026-06-01 23:32:00'),
        ]);

        Invoice::create([
            'code' => 'HD008236',
            'customer_id' => $partner->id,
            'subtotal' => 7_000_000,
            'discount' => 0,
            'total' => 7_000_000,
            'customer_paid' => 5_000_000,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2026-06-01 23:31:00'),
            'created_at' => Carbon::parse('2026-06-01 23:31:00'),
        ]);

        Purchase::create([
            'code' => 'PN003806',
            'supplier_id' => $partner->id,
            'total_amount' => 5_000_000,
            'paid_amount' => 0,
            'debt_amount' => 5_000_000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-06-01 23:41:00'),
            'created_at' => Carbon::parse('2026-06-01 23:41:00'),
        ]);

        CashFlow::create([
            'code' => 'PCPN003806',
            'type' => 'payment',
            'amount' => 3_000_000,
            'time' => Carbon::parse('2026-06-01 23:42:00'),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003806',
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => Carbon::parse('2026-06-01 23:42:00'),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&per_page=100&page=1");

        $response->assertOk()
            ->assertJsonPath('summary.display_mode', 'supplier_partner_timeline')
            ->assertJsonPath('summary.legacy_display_mode', 'partner_net_timeline')
            ->assertJsonPath('summary.orientation', 'supplier')
            ->assertJsonPath('summary.balance_label', 'Nợ cần trả nhà cung cấp')
            ->assertJsonPath('summary.partner_net_position', 0)
            ->assertJsonPath('summary.supplier_oriented_balance', 0);

        $byCode = collect($response->json('entries'))->keyBy('code');

        $this->assertEquals(0, $byCode['CB000345']['supplier_partner_effect']);
        $this->assertEquals(-7_000_000, $byCode['HD008236']['supplier_partner_effect']);
        $this->assertEquals(5_000_000, $byCode['TTHD008236']['supplier_partner_effect']);
        $this->assertEquals(5_000_000, $byCode['PN003806']['supplier_partner_effect']);
        $this->assertEquals(-3_000_000, $byCode['PCPN003806']['supplier_partner_effect']);

        $this->assertEquals(0, $byCode['CB000345']['supplier_partner_running_balance']);
        $this->assertEquals(-7_000_000, $byCode['HD008236']['supplier_partner_running_balance']);
        $this->assertEquals(-2_000_000, $byCode['TTHD008236']['supplier_partner_running_balance']);
        $this->assertEquals(3_000_000, $byCode['PN003806']['supplier_partner_running_balance']);
        $this->assertEquals(0, $byCode['PCPN003806']['supplier_partner_running_balance']);
    }
}
