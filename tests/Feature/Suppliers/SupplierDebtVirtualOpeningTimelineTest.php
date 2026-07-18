<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtVirtualOpeningTimelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_with_balance_but_no_history_exposes_drift_without_virtual_opening(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Virtual Opening',
            'email' => 'admin-supplier-virtual-opening-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $supplier = Customer::create([
            'code' => 'NCC-VIRTUAL-OPENING-'.uniqid(),
            'name' => 'Supplier Virtual Opening',
            'debt_amount' => 0,
            'supplier_debt_amount' => 500_000,
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?per_page=100&page=1");

        $response->assertOk()
            ->assertJsonPath('summary.has_virtual_opening_balance', false)
            ->assertJsonPath('summary.virtual_opening_balance', 0)
            ->assertJsonPath('summary.display_balance_target', 0)
            ->assertJsonPath('summary.display_balance_final', 0)
            ->assertJsonPath('reconcile.ledger_mismatch', true)
            ->assertJsonPath('reconcile.display_resolved', false)
            ->assertJsonPath('reconcile.has_mismatch', true)
            ->assertJsonPath('reconcile.severity', 'warning')
            ->assertJsonPath('reconcile.user_warning', true);

        $entries = collect($response->json('entries'));
        $this->assertCount(0, $entries);

    }
}
