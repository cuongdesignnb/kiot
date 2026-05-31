<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX FOLLOW-UP — opt-in server-side pagination on
 * GET /customers/{id}/debt-history.
 *
 * Without ?page=, the endpoint returns the full ledger (backward-compat
 * with tests / exports / scripts). With ?page=N (+ optional ?per_page=),
 * the entries array is sliced and a `pagination` meta block is appended.
 */
class HOTFIXFollowUpDebtHistoryPaginationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin Pag ' . uniqid(),
            'email'    => 'admin-pag-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function dualRolePartnerWithPurchases(int $purchaseCount): Customer
    {
        $partner = Customer::create([
            'code'                 => 'PAG-' . uniqid(),
            'name'                 => 'Pag partner',
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => true,
            'is_supplier'          => true,
        ]);

        $base = Carbon::now()->subDays(30);
        for ($i = 1; $i <= $purchaseCount; $i++) {
            Purchase::create([
                'code'          => sprintf('PN-PAG-%03d', $i),
                'supplier_id'   => $partner->id,
                'total_amount'  => 100_000 * $i,
                'paid_amount'   => 0,
                'status'        => 'completed',
                'purchase_date' => $base->copy()->addHours($i),
            ]);
        }

        return $partner;
    }

    // ── Test 1 — No ?page= → returns full ledger (no pagination block) ──
    public function test_default_request_returns_full_ledger_without_pagination(): void
    {
        $partner = $this->dualRolePartnerWithPurchases(25);

        $res = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $res->assertOk();
        $data = $res->json();

        $this->assertCount(25, $data['entries'],
            'Without ?page=, all entries are returned (backward compat)');
        $this->assertArrayNotHasKey('pagination', $data,
            'Pagination block only present when ?page= is sent');
    }

    // ── Test 2 — ?page=2&per_page=10 → returns entries 11–20 + pagination meta ──
    public function test_paginated_request_returns_slice_with_meta(): void
    {
        $partner = $this->dualRolePartnerWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/customers/{$partner->id}/debt-history?page=2&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $this->assertCount(10, $data['entries'], 'Page 2 of per_page=10 must have 10 entries');
        $this->assertArrayHasKey('pagination', $data);
        $this->assertEquals(25, $data['pagination']['total']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(2,  $data['pagination']['current_page']);
        $this->assertEquals(3,  $data['pagination']['last_page']);
        $this->assertEquals(11, $data['pagination']['from']);
        $this->assertEquals(20, $data['pagination']['to']);
    }

    // ── Test 3 — Last page returns the tail correctly ──
    public function test_last_page_returns_remaining_entries(): void
    {
        $partner = $this->dualRolePartnerWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/customers/{$partner->id}/debt-history?page=3&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $this->assertCount(5, $data['entries'],
            'Last page of 25 entries / 10 per page = 5 entries on page 3');
        $this->assertEquals(21, $data['pagination']['from']);
        $this->assertEquals(25, $data['pagination']['to']);
    }

    // ── Test 4 — page beyond last clamps to last page ──
    public function test_page_beyond_last_clamps_to_last(): void
    {
        $partner = $this->dualRolePartnerWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/customers/{$partner->id}/debt-history?page=99&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $this->assertEquals(3, $data['pagination']['current_page'],
            'Out-of-range page clamps to last_page (3)');
        $this->assertCount(5, $data['entries']);
    }

    // ── Test 5 — Summary stays full (not paginated) ──
    public function test_summary_reflects_full_ledger_not_page(): void
    {
        $partner = $this->dualRolePartnerWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/customers/{$partner->id}/debt-history?page=1&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $expectedPayable = 0;
        for ($i = 1; $i <= 25; $i++) $expectedPayable += 100_000 * $i;
        // reconcile.computed_balance is derived from the FULL ledger,
        // not from the current page — this is the contract that
        // pagination must preserve.
        $this->assertEquals(-$expectedPayable, (float) $data['reconcile']['computed_balance'],
            'reconcile.computed_balance must reflect the FULL ledger, not just the page');
    }
}
