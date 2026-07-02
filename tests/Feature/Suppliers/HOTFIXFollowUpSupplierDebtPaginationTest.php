<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX FOLLOW-UP — opt-in server-side pagination on
 * GET /api/suppliers/{id}/debt-transactions.
 *
 * Same contract as the customer debt-history pagination test.
 */
class HOTFIXFollowUpSupplierDebtPaginationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin SupPag ' . uniqid(),
            'email'    => 'admin-suppag-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function supplierWithPurchases(int $purchaseCount): Customer
    {
        $supplier = Customer::create([
            'code'                 => 'SUP-PAG-' . uniqid(),
            'name'                 => 'Sup pag',
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);

        $base = Carbon::now()->subDays(30);
        for ($i = 1; $i <= $purchaseCount; $i++) {
            Purchase::create([
                'code'          => sprintf('PN-SUPPAG-%03d', $i),
                'supplier_id'   => $supplier->id,
                'total_amount'  => 100_000 * $i,
                'paid_amount'   => 0,
                'status'        => 'completed',
                'purchase_date' => $base->copy()->addHours($i),
            ]);
        }
        return $supplier;
    }

    public function test_default_request_returns_full_ledger_without_pagination(): void
    {
        $supplier = $this->supplierWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions");
        $res->assertOk();
        $data = $res->json();

        $this->assertCount(25, $data['entries']);
        $this->assertArrayNotHasKey('pagination', $data);
    }

    public function test_paginated_request_returns_slice_with_meta(): void
    {
        $supplier = $this->supplierWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?page=2&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $this->assertCount(10, $data['entries']);
        $this->assertEquals(25, $data['pagination']['total']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(2,  $data['pagination']['current_page']);
        $this->assertEquals(3,  $data['pagination']['last_page']);
        $this->assertEquals(11, $data['pagination']['from']);
        $this->assertEquals(20, $data['pagination']['to']);
    }

    public function test_summary_reflects_full_ledger_not_page(): void
    {
        $supplier = $this->supplierWithPurchases(25);

        $res = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?page=1&per_page=10");
        $res->assertOk();
        $data = $res->json();

        $expectedPayable = 0;
        for ($i = 1; $i <= 25; $i++) $expectedPayable += 100_000 * $i;
        $this->assertEquals($expectedPayable, (float) $data['summary']['net'],
            'closing_balance summary reflects FULL ledger, not just the current page');
    }
}
