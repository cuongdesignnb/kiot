<?php

namespace Tests\Feature\Filters;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\CustomerGroup;

/**
 * HOTFIX 24.4A-3 — Customer Group create/select/sidebar flow.
 *
 * Verifies that the master CustomerGroup CRUD endpoints behave correctly
 * for the sidebar create-modal flow and the customer create/edit forms,
 * AND that creating a group doesn't mutate any existing customers.
 */
class Step244ACustomerGroupUiFlowTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin244aGroupUi'], [
            'display_name' => 'Admin',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name'         => 'role-' . uniqid(),
            'display_name' => 'Test',
            'permissions'  => $perms,
            'is_system'    => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══
    public function test_customer_group_options_route_returns_active_groups(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        CustomerGroup::create(['name' => 'VIP-Active',   'is_active' => true]);
        CustomerGroup::create(['name' => 'Old-Inactive', 'is_active' => false]);

        $res = $this->getJson('/customer-groups/options');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();

        $this->assertContains('VIP-Active', $names);
        $this->assertNotContains('Old-Inactive', $names);
    }

    // ═══ TC-02 ═══
    public function test_create_customer_group_returns_json_group(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->postJson('/customer-groups', [
            'name'           => 'Khách thân thiết',
            'discount_type'  => 'amount',
            'discount_value' => 50000,
            'note'           => 'Ghi chú nhóm',
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('group.name', 'Khách thân thiết');

        $this->assertDatabaseHas('customer_groups', [
            'name'          => 'Khách thân thiết',
            'discount_type' => 'amount',
        ]);
    }

    // ═══ TC-03 ═══
    public function test_create_customer_group_percent_over_100_fails(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->postJson('/customer-groups', [
            'name'           => 'Bad Percent',
            'discount_type'  => 'percent',
            'discount_value' => 150,
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('customer_groups', ['name' => 'Bad Percent']);
    }

    // ═══ TC-04 ═══
    public function test_create_customer_group_duplicate_name_fails(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        CustomerGroup::create(['name' => 'Duplicate', 'is_active' => true]);

        $res = $this->postJson('/customer-groups', [
            'name' => 'Duplicate',
        ]);

        $res->assertStatus(422);
        $this->assertEquals(1, CustomerGroup::where('name', 'Duplicate')->count());
    }

    // ═══ TC-05 ═══
    public function test_create_customer_with_existing_group_name_saves_customer_group_string(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        CustomerGroup::create(['name' => 'Khách chợ tốt', 'is_active' => true]);

        $res = $this->post('/customers', [
            'name'           => 'Nguyễn Văn A',
            'phone'          => '0901999001',
            'customer_group' => 'Khách chợ tốt',
            'is_supplier'    => false,
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'phone'          => '0901999001',
            'customer_group' => 'Khách chợ tốt',
        ]);
    }

    // ═══ TC-06 ═══
    public function test_update_customer_group_from_dropdown_saves_string(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        CustomerGroup::create(['name' => 'Bạc',  'is_active' => true]);
        CustomerGroup::create(['name' => 'Vàng', 'is_active' => true]);

        $customer = Customer::create([
            'code'           => 'KH-UPD-' . uniqid(),
            'name'           => 'Customer Upd',
            'phone'          => '0902000002',
            'customer_group' => 'Bạc',
            'is_customer'    => true,
        ]);

        $res = $this->put('/customers/' . $customer->id, [
            'name'           => 'Customer Upd',
            'phone'          => '0902000002',
            'customer_group' => 'Vàng',
            'is_supplier'    => false,
        ]);

        $res->assertRedirect();
        $this->assertEquals('Vàng', $customer->fresh()->customer_group);
    }

    // ═══ TC-07 ═══
    public function test_customers_index_filter_options_include_master_groups_after_create(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $this->postJson('/customer-groups', ['name' => 'Mới Tạo'])->assertOk();

        $res = $this->get('/customers');
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.customerGroups', function ($groups) {
                $values = collect($groups)->pluck('value')->all();
                return in_array('Mới Tạo', $values);
            })
        );
    }

    // ═══ TC-08 ═══
    public function test_customers_filter_by_group_after_customer_created_with_group(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        CustomerGroup::create(['name' => 'GroupA', 'is_active' => true]);
        CustomerGroup::create(['name' => 'GroupB', 'is_active' => true]);

        Customer::create(['code' => 'KH-GA-1', 'name' => 'A1', 'customer_group' => 'GroupA', 'is_customer' => true]);
        Customer::create(['code' => 'KH-GA-2', 'name' => 'A2', 'customer_group' => 'GroupA', 'is_customer' => true]);
        Customer::create(['code' => 'KH-GB-1', 'name' => 'B1', 'customer_group' => 'GroupB', 'is_customer' => true]);

        $res = $this->get('/customers?customer_group=GroupA');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 2));
    }

    // ═══ TC-09 ═══
    public function test_customer_group_create_does_not_mutate_existing_customers(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $c1 = Customer::create(['code' => 'KH-EX-1', 'name' => 'Old1', 'customer_group' => 'Lẻ',     'is_customer' => true]);
        $c2 = Customer::create(['code' => 'KH-EX-2', 'name' => 'Old2', 'customer_group' => null,    'is_customer' => true]);
        $c3 = Customer::create(['code' => 'KH-EX-3', 'name' => 'Old3', 'customer_group' => 'Buôn',  'is_customer' => true]);

        $this->postJson('/customer-groups', [
            'name'        => 'Nhóm Mới Có Điều Kiện',
            'conditions'  => [['field' => 'total_spent', 'op' => '>=', 'value' => 1]],
            'update_mode' => 'add_matching',
            'auto_update' => true,
        ])->assertOk();

        $this->assertEquals('Lẻ',   $c1->fresh()->customer_group);
        $this->assertNull($c2->fresh()->customer_group);
        $this->assertEquals('Buôn', $c3->fresh()->customer_group);
    }

    // ═══ TC-10 ═══
    public function test_unsupported_filter_capabilities_are_false_or_hidden_policy(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.capabilities.supportsDebtDaysFilter', false)
            ->where('filterOptions.capabilities.supportsPointsFilter',   false)
        );
    }

    // ═══ TC-11 (bonus): permission denial ═══
    public function test_create_customer_group_without_permission_returns_403(): void
    {
        $user = $this->userWith(['customers.view']); // intentionally lacks customers.edit
        $this->actingAs($user);

        $res = $this->postJson('/customer-groups', ['name' => 'Forbidden Group']);
        $res->assertStatus(403);
        $this->assertDatabaseMissing('customer_groups', ['name' => 'Forbidden Group']);
    }

    // ═══ TC-12: birthday preset filter resolves correctly ═══
    public function test_birthday_filter_preset_this_month_only_matches_birthdays_this_month(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $now = \Carbon\Carbon::now();
        $thisMonth = $now->copy()->startOfMonth()->addDays(5)->toDateString();
        $lastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();

        Customer::create(['code' => 'KH-BD-A', 'name' => 'A', 'birthday' => $thisMonth, 'is_customer' => true]);
        Customer::create(['code' => 'KH-BD-B', 'name' => 'B', 'birthday' => $lastMonth, 'is_customer' => true]);

        $res = $this->get('/customers?birthday_filter=this_month');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-13: last_transaction preset ═══
    public function test_last_transaction_filter_preset_last_7_days(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $cRecent = Customer::create(['code' => 'KH-LT-R', 'name' => 'Recent', 'is_customer' => true]);
        $cOld    = Customer::create(['code' => 'KH-LT-O', 'name' => 'Old',    'is_customer' => true]);

        \App\Models\Invoice::create([
            'code' => 'HD-LT-R', 'customer_id' => $cRecent->id, 'total' => 100000,
            'transaction_date' => \Carbon\Carbon::now()->subDays(2),
        ]);
        \App\Models\Invoice::create([
            'code' => 'HD-LT-O', 'customer_id' => $cOld->id, 'total' => 100000,
            'transaction_date' => \Carbon\Carbon::now()->subDays(30),
        ]);

        $res = $this->get('/customers?last_transaction_filter=last_7_days');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-14: filters are echoed back so DateRangeFilter v-model can hydrate ═══
    public function test_index_echoes_back_date_filter_keys(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers?birthday_filter=this_month&last_transaction_filter=last_7_days&total_sales_date_filter=this_year');
        $res->assertInertia(fn ($p) => $p
            ->where('filters.birthday_filter', 'this_month')
            ->where('filters.last_transaction_filter', 'last_7_days')
            ->where('filters.total_sales_date_filter', 'this_year')
        );
    }
}
