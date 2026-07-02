<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.19 (labelled "24.17" in the brief; renamed to avoid
 * colliding with the existing 24.17 supplier-debt-Excel chain).
 *
 * Inactive suppliers (customers.status = 'inactive') must stay on the
 * /suppliers admin page but disappear from the supplier picker in the
 * Nhập hàng flow:
 *   - GET /purchases/create — Inertia prop `suppliers` must NOT contain
 *     deactivated rows.
 *   - GET /api/suppliers/search — live search must NOT return them.
 *
 * Active suppliers stay visible. Existing purchases that point to a
 * since-deactivated supplier remain viewable on /purchases/{id} (the
 * relation is loaded, not filtered).
 */
class HOTFIX2419HideInactiveSuppliersFromPurchaseTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2419',
            'email'    => 'admin-2419-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(string $name, string $status = 'active'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2419-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'is_customer'          => false,
            'is_supplier'          => true,
            'status'               => $status,
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
        ]);
    }

    // ── TC-01 — /purchases/create prop only carries active suppliers ──
    public function test_purchase_create_page_does_not_include_inactive_suppliers(): void
    {
        $admin   = $this->admin();
        $active  = $this->supplier('NCC Active 2419', 'active');
        $stopped = $this->supplier('NCC Stopped 2419', 'inactive');

        $res = $this->actingAs($admin)->get('/purchases/create');
        $res->assertOk();

        // Inertia HTML carries the props as JSON inside `data-page`.
        $body = $res->getContent();
        $this->assertStringContainsString($active->code, $body, 'active supplier must be present');
        $this->assertStringNotContainsString($stopped->code, $body, 'inactive supplier must NOT appear in Nhập hàng selector');
    }

    // ── TC-02 — /api/suppliers/search excludes inactive rows ──
    public function test_supplier_search_endpoint_excludes_inactive_suppliers(): void
    {
        $admin   = $this->admin();
        $active  = $this->supplier('Search Active 2419', 'active');
        $stopped = $this->supplier('Search Stopped 2419', 'inactive');

        $res = $this->actingAs($admin)->getJson('/api/suppliers/search?search=2419');
        $res->assertOk();
        $codes = collect($res->json())->pluck('code')->all();
        $this->assertContains($active->code, $codes);
        $this->assertNotContains($stopped->code, $codes,
            'deactivated supplier must NEVER appear in the live search payload');
    }

    // ── TC-03 — search?q= variant works too (FE may send either key) ──
    public function test_supplier_search_supports_q_param(): void
    {
        $admin = $this->admin();
        $a     = $this->supplier('AlphaCo 2419', 'active');

        $res = $this->actingAs($admin)->getJson('/api/suppliers/search?q=AlphaCo');
        $res->assertOk();
        $this->assertContains($a->code, collect($res->json())->pluck('code')->all());
    }

    // ── TC-04 — non-standard status (e.g. unset legacy 'active' default)
    //   still resolves correctly via the active branch ──
    public function test_default_status_is_active(): void
    {
        $admin = $this->admin();
        // Create without passing status; the migration default kicks in.
        $c = Customer::create([
            'code'                 => 'NCC-2419-' . uniqid(),
            'name'                 => 'DefaultStatus 2419',
            'phone'                => '09' . random_int(10000000, 99999999),
            'is_customer'          => false,
            'is_supplier'          => true,
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
        ]);
        $this->assertSame('active', $c->fresh()->status, 'default status must be active per migration');

        $res = $this->actingAs($admin)->getJson('/api/suppliers/search?search=DefaultStatus');
        $res->assertOk();
        $this->assertContains($c->code, collect($res->json())->pluck('code')->all());
    }

    // ── TC-05 — existing purchase row still resolves an inactive supplier
    //          (history / detail page must stay viewable) ──
    public function test_existing_purchase_with_inactive_supplier_is_still_loadable(): void
    {
        $admin   = $this->admin();
        $stopped = $this->supplier('History Stopped 2419', 'inactive');
        $p       = Purchase::create([
            'code'          => 'PN-2419-' . uniqid(),
            'supplier_id'   => $stopped->id,
            'user_id'       => null,
            'total_amount'  => 500_000,
            'paid_amount'   => 0,
            'debt_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => Carbon::now(),
        ]);

        // Loading the purchase relation must still see the supplier;
        // we never deleted the row, just hid it from the selector.
        $purchase = Purchase::with('supplier')->find($p->id);
        $this->assertNotNull($purchase->supplier);
        $this->assertSame($stopped->id, $purchase->supplier->id);
        $this->assertSame('inactive', $purchase->supplier->status);
    }

    // ── TC-06 — /suppliers admin page still shows inactive rows ──
    public function test_suppliers_admin_page_still_includes_inactive_suppliers(): void
    {
        $admin   = $this->admin();
        $stopped = $this->supplier('Admin Listed 2419', 'inactive');

        $res = $this->actingAs($admin)->get('/suppliers');
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringContainsString($stopped->code, $body,
            'inactive supplier must still show on /suppliers (admin view) so "Hoạt động lại" can be hit');
    }

    // ── TC-07 — empty search returns active suppliers only (no leakage) ──
    public function test_supplier_search_empty_query_returns_only_active(): void
    {
        $admin   = $this->admin();
        $active  = $this->supplier('Empty Active 2419', 'active');
        $stopped = $this->supplier('Empty Stopped 2419', 'inactive');

        $res = $this->actingAs($admin)->getJson('/api/suppliers/search');
        $res->assertOk();
        $codes = collect($res->json())->pluck('code')->all();
        $this->assertNotContains($stopped->code, $codes,
            'empty-query search must still respect the active filter');
    }
}
