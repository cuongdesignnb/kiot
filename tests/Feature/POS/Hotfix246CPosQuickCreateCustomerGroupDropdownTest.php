<?php

namespace Tests\Feature\POS;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Hotfix246CPosQuickCreateCustomerGroupDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-hotfix-246c-' . uniqid(),
            'display_name' => 'HOTFIX 24.6C',
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_customer_group_options_are_available_for_pos_quick_create_combobox(): void
    {
        $user = $this->userWith(['pos.use']);
        CustomerGroup::create(['name' => 'VIP POS 24.6C', 'is_active' => true]);
        CustomerGroup::create(['name' => 'Inactive POS 24.6C', 'is_active' => false]);
        Customer::create([
            'code' => 'KH-LEGACY-246C',
            'name' => 'Legacy POS 24.6C',
            'customer_group' => 'Legacy POS Group 24.6C',
            'is_customer' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/customer-groups/options');

        $response->assertOk();
        $names = collect($response->json())->pluck('name')->all();

        $this->assertContains('VIP POS 24.6C', $names);
        $this->assertContains('Legacy POS Group 24.6C', $names);
        $this->assertNotContains('Inactive POS 24.6C', $names);
    }

    public function test_pos_quick_create_customer_keeps_selected_customer_group_string(): void
    {
        $user = $this->userWith(['pos.use']);
        CustomerGroup::create(['name' => 'Khach hang POS 24.6C', 'is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'Nguyen POS 24.6C',
            'phone' => '0902466001',
            'customer_group' => 'Khach hang POS 24.6C',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('customer.customer_group', 'Khach hang POS 24.6C');

        $this->assertDatabaseHas('customers', [
            'phone' => '0902466001',
            'customer_group' => 'Khach hang POS 24.6C',
        ]);

        $customer = Customer::where('phone', '0902466001')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Nguyen POS 24.6C', $customer->name);
    }
}
