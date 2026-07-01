<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtTimelineDisplayBalanceContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_partner_timeline_latest_running_balance_matches_supplier_screen_balance(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Display Contract',
            'email' => 'admin-supplier-display-contract-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-DISPLAY-CONTRACT-' . uniqid(),
            'name' => 'Supplier Display Contract',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 205_000,
            'supplier_debt_amount' => 205_000,
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);

        Purchase::create([
            'code' => 'PN-DISPLAY-CONTRACT',
            'supplier_id' => $partner->id,
            'total_amount' => 205_000,
            'paid_amount' => 0,
            'debt_amount' => 205_000,
            'status' => 'completed',
            'purchase_date' => Carbon::now()->subMinute(),
            'created_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&per_page=100&page=1");

        $response->assertOk();
        $summary = $response->json('summary');
        $entries = collect($response->json('entries'));
        $purchase = $entries->firstWhere('code', 'PN-DISPLAY-CONTRACT');

        $this->assertNotNull($purchase);
        $this->assertSame(0.0, (float) $summary['current_debt']);
        $this->assertSame(0.0, (float) $summary['supplier_oriented_balance']);
        $this->assertSame(0.0, (float) $summary['display_balance_target']);
        $this->assertSame(0.0, (float) $summary['display_balance_final']);
        $this->assertSame(205_000.0, (float) $summary['raw_document_final_balance']);
        $this->assertTrue((bool) $summary['has_virtual_display_alignment']);
        $this->assertFalse((bool) $summary['has_virtual_opening_balance']);
        $this->assertFalse((bool) $response->json('reconcile.user_warning'));
        $this->assertSame(0.0, (float) $purchase['supplier_display_running_balance']);
        $this->assertSame(0.0, (float) $purchase['running_balance']);
        $this->assertNull($entries->firstWhere('event_kind', 'virtual_opening_balance'));
    }
}
