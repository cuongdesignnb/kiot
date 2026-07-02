<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDualRoleListDebtColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_list_uses_customer_oriented_net_for_dual_role_partner(): void
    {
        $admin = User::create([
            'name' => 'Admin Customer List Debt Column Test',
            'email' => 'admin-customer-list-debt-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'KH-LIST-COL-' . uniqid(),
            'name' => 'Anh Thanh Thien Phu Customer Column',
            'debt_amount' => 47_400_000,
            'supplier_debt_amount' => 75_000_000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($admin)->get('/customers?search=' . urlencode($partner->code));

        $response->assertOk();

        $props = $this->inertiaProps($response);
        $row = collect($props['customers']['data'] ?? [])->firstWhere('id', $partner->id);

        $this->assertNotNull($row);
        $this->assertSame(47_400_000.0, (float) $row['debt_amount']);
        $this->assertSame(75_000_000.0, (float) $row['supplier_debt_amount']);
        $this->assertSame(-27_600_000.0, (float) $row['net_debt_amount']);
        $this->assertSame(-27_600_000.0, (float) $row['partner_net_position']);
        $this->assertSame('store_owes_customer_supplier', $row['net_debt_direction']);
    }

    private function inertiaProps($response): array
    {
        return $response->original->getData()['page']['props'] ?? [];
    }
}
