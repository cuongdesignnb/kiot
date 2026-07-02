<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtVirtualOpeningTimelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_with_balance_but_no_history_gets_read_only_virtual_opening_row(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Virtual Opening',
            'email' => 'admin-supplier-virtual-opening-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $supplier = Customer::create([
            'code' => 'NCC-VIRTUAL-OPENING-' . uniqid(),
            'name' => 'Supplier Virtual Opening',
            'debt_amount' => 0,
            'supplier_debt_amount' => 500_000,
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?per_page=100&page=1");

        $response->assertOk()
            ->assertJsonPath('summary.has_virtual_opening_balance', true)
            ->assertJsonPath('summary.virtual_opening_balance', 500_000)
            ->assertJsonPath('summary.display_balance_target', 500_000)
            ->assertJsonPath('summary.display_balance_final', 500_000)
            ->assertJsonPath('reconcile.ledger_mismatch', true)
            ->assertJsonPath('reconcile.display_resolved', true)
            ->assertJsonPath('reconcile.has_mismatch', false)
            ->assertJsonPath('reconcile.severity', 'info')
            ->assertJsonPath('reconcile.user_warning', false);

        $entries = collect($response->json('entries'));
        $this->assertCount(1, $entries);

        $opening = $entries->first();
        $this->assertTrue($opening['is_virtual_opening']);
        $this->assertSame('virtual_opening_balance', $opening['event_kind']);
        $this->assertEquals(500_000, $opening['supplier_display_effect']);
        $this->assertEquals(500_000, $opening['supplier_display_running_balance']);
        $this->assertEquals(500_000, $opening['supplier_running_balance']);
        $this->assertEquals('Số dư đầu kỳ', $opening['badge_label']);
        $this->assertNotEquals('Đã hạch toán', $opening['badge_label']);
    }
}
