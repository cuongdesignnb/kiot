<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDualRoleListDebtColumnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dual_role_supplier_list_debt_column_uses_supplier_oriented_balance(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier List Debt Column Test',
            'email' => 'admin-supplier-list-debt-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-LIST-COL-' . uniqid(),
            'name' => 'Anh Thanh Thien Phu Supplier Column',
            'debt_amount' => 47_400_000,
            'supplier_debt_amount' => 75_000_000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($admin)->get('/suppliers?search=' . urlencode($partner->code));

        $response->assertOk();

        $props = $this->inertiaProps($response);
        $row = collect($props['suppliers']['data'] ?? [])->firstWhere('id', $partner->id);

        $this->assertNotNull($row);
        $this->assertSame(75_000_000.0, (float) $row['supplier_debt_amount']);
        $this->assertSame(47_400_000.0, (float) $row['customer_receivable_balance']);
        $this->assertSame(75_000_000.0, (float) $row['supplier_payable_balance']);
        $this->assertSame(-27_600_000.0, (float) $row['partner_net_position']);
        $this->assertSame(27_600_000.0, (float) $row['supplier_screen_debt']);
        $this->assertSame(27_600_000.0, (float) $row['supplier_oriented_balance']);
        $this->assertSame(27_600_000.0, (float) $row['supplier_list_debt_amount']);
    }

    public function test_supplier_only_list_debt_column_keeps_gross_payable(): void
    {
        $admin = User::create([
            'name' => 'Admin Supplier Only List Debt Column Test',
            'email' => 'admin-supplier-only-list-debt-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $supplier = Customer::create([
            'code' => 'NCC-ONLY-LIST-' . uniqid(),
            'name' => 'Supplier Only List Column',
            'debt_amount' => 0,
            'supplier_debt_amount' => 75_000_000,
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($admin)->get('/suppliers?search=' . urlencode($supplier->code));

        $response->assertOk();

        $props = $this->inertiaProps($response);
        $row = collect($props['suppliers']['data'] ?? [])->firstWhere('id', $supplier->id);

        $this->assertNotNull($row);
        $this->assertSame(75_000_000.0, (float) $row['supplier_debt_amount']);
        $this->assertSame(75_000_000.0, (float) $row['supplier_screen_debt']);
        $this->assertSame(75_000_000.0, (float) $row['supplier_list_debt_amount']);
    }

    private function inertiaProps($response): array
    {
        return $response->original->getData()['page']['props'] ?? [];
    }
}
