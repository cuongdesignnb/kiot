<?php

namespace Tests\Feature\Suppliers;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\Purchase;

/**
 * HOTFIX 24.8 — Supplier update + deactivate/activate actions.
 *
 * Suppliers share the customers table (is_supplier=true). These tests pin:
 *   - update touches only basic info, never debt/total_bought
 *   - deactivate flips status to 'inactive' without deleting record or
 *     touching purchase / supplier_debt_amount / total_bought
 *   - activate restores status to 'active'
 *   - dual-role partners (is_customer=true & is_supplier=true) keep both
 *     roles after deactivate
 *   - non-supplier customers cannot be edited via /suppliers/{id} routes
 */
class Step248SupplierActionsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 248',
            'email'    => 'admin-248-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null, // null role_id => isAdmin() true
        ]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name'         => 'r248-' . uniqid(),
            'display_name' => 'Test 248',
            'permissions'  => $perms,
            'is_system'    => false,
        ]);
        return User::create([
            'name'     => 'User 248 ' . uniqid(),
            'email'    => 'u248-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
        ]);
    }

    private function makeSupplier(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'code'                 => 'NCC-248-' . uniqid(),
            'name'                 => 'NCC 248',
            'phone'                => '090' . rand(1000000, 9999999),
            'is_supplier'          => true,
            'is_customer'          => false,
            'status'               => 'active',
            'supplier_debt_amount' => 0,
            'total_bought'         => 0,
        ], $attrs));
    }

    public function test_can_update_supplier_basic_info(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier(['name' => 'Old name', 'note' => 'old']);

        $this->actingAs($admin)->put('/suppliers/' . $sup->id, [
            'name'           => 'New name',
            'phone'          => $sup->phone,
            'address'        => 'New address',
            'customer_group' => 'NCC nhóm A',
            'note'           => 'new note',
        ])->assertRedirect();

        $fresh = $sup->fresh();
        $this->assertSame('New name', $fresh->name);
        $this->assertSame('New address', $fresh->address);
        $this->assertSame('NCC nhóm A', $fresh->customer_group);
        $this->assertSame('new note', $fresh->note);
        $this->assertTrue((bool) $fresh->is_supplier);
    }

    public function test_update_supplier_does_not_mutate_debt_or_total_bought(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier([
            'supplier_debt_amount' => 1500000,
            'total_bought'         => 9000000,
        ]);

        $this->actingAs($admin)->put('/suppliers/' . $sup->id, [
            'name'                 => 'Updated',
            'phone'                => $sup->phone,
            // Even if frontend sends these (it shouldn't), backend must ignore.
            'supplier_debt_amount' => 0,
            'total_bought'         => 0,
        ])->assertRedirect();

        $fresh = $sup->fresh();
        $this->assertEquals(1500000.0, (float) $fresh->supplier_debt_amount);
        $this->assertEquals(9000000.0, (float) $fresh->total_bought);
    }

    public function test_update_supplier_code_unique_ignores_current_supplier(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier(['code' => 'NCC-DUP-' . uniqid()]);

        // Same code must not collide with self.
        $this->actingAs($admin)->put('/suppliers/' . $sup->id, [
            'name' => $sup->name,
            'code' => $sup->code,
            'phone' => $sup->phone,
        ])->assertRedirect();

        // Another supplier with the same code → 422.
        $other = $this->makeSupplier(['code' => 'NCC-OTHER-' . uniqid()]);
        $this->actingAs($admin)
            ->from('/suppliers')
            ->put('/suppliers/' . $sup->id, [
                'name' => $sup->name,
                'code' => $other->code,
                'phone' => $sup->phone,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_deactivate_supplier_does_not_delete_record(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier();

        $this->actingAs($admin)
            ->post('/suppliers/' . $sup->id . '/deactivate')
            ->assertRedirect();

        $fresh = $sup->fresh();
        $this->assertNotNull($fresh, 'Supplier record must still exist');
        $this->assertSame('inactive', $fresh->status);
        $this->assertTrue((bool) $fresh->is_supplier);
    }

    public function test_deactivate_supplier_keeps_purchase_history_and_debt(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier(['supplier_debt_amount' => 2000000, 'total_bought' => 5000000]);
        $purchase = Purchase::create([
            'code'         => 'PN-248-' . uniqid(),
            'supplier_id'  => $sup->id,
            'total_amount' => 5000000,
            'status'       => 'completed',
        ]);

        $this->actingAs($admin)->post('/suppliers/' . $sup->id . '/deactivate')->assertRedirect();

        $fresh = $sup->fresh();
        $this->assertEquals(2000000.0, (float) $fresh->supplier_debt_amount);
        $this->assertEquals(5000000.0, (float) $fresh->total_bought);
        $this->assertNotNull(Purchase::find($purchase->id));
    }

    public function test_activate_supplier_restores_active_status(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier(['status' => 'inactive']);

        $this->actingAs($admin)->post('/suppliers/' . $sup->id . '/activate')->assertRedirect();

        $this->assertSame('active', $sup->fresh()->status);
    }

    public function test_deactivate_supplier_does_not_remove_customer_role_when_dual_role(): void
    {
        $admin = $this->admin();
        $sup = $this->makeSupplier(['is_customer' => true]);

        $this->actingAs($admin)->post('/suppliers/' . $sup->id . '/deactivate')->assertRedirect();

        $fresh = $sup->fresh();
        $this->assertTrue((bool) $fresh->is_customer);
        $this->assertTrue((bool) $fresh->is_supplier);
        $this->assertSame('inactive', $fresh->status);
    }

    public function test_non_supplier_cannot_be_updated_via_supplier_route(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'code'        => 'KH-248-' . uniqid(),
            'name'        => 'Pure customer',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->actingAs($admin)
            ->put('/suppliers/' . $customer->id, ['name' => 'hacked'])
            ->assertNotFound();
    }

    public function test_non_supplier_cannot_be_deactivated_via_supplier_route(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'code'        => 'KH-248-' . uniqid(),
            'name'        => 'Pure customer',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->actingAs($admin)
            ->post('/suppliers/' . $customer->id . '/deactivate')
            ->assertNotFound();
    }

    public function test_supplier_filter_status_active_inactive(): void
    {
        $admin = $this->admin();
        $active = $this->makeSupplier(['status' => 'active', 'name' => 'A active']);
        $inactive = $this->makeSupplier(['status' => 'inactive', 'name' => 'B inactive']);

        $this->actingAs($admin)
            ->get('/suppliers?status=inactive')
            ->assertInertia(fn ($p) => $p->where('suppliers.data', function ($rows) use ($inactive, $active) {
                $ids = collect($rows)->pluck('id')->all();
                return in_array($inactive->id, $ids) && !in_array($active->id, $ids);
            }));
    }

    public function test_user_without_suppliers_edit_permission_is_blocked(): void
    {
        $user = $this->userWith(['suppliers.view']); // no suppliers.edit
        $sup = $this->makeSupplier();

        $this->actingAs($user)
            ->put('/suppliers/' . $sup->id, ['name' => 'should not save'])
            ->assertRedirect('/'); // CheckPermission middleware redirects to '/'

        $this->assertNotSame('should not save', $sup->fresh()->name);
    }
}
