<?php

namespace Tests\Feature\Customers;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Customer;
use App\Models\CustomerGroup;

/**
 * HOTFIX 24.10 — Customer Group combobox.
 *
 * Frontend changes only the picker UI; backend contract must stay:
 *   - customers.customer_group is a nullable string column
 *   - POST /customers + PUT /customers/{id} accept any string
 *   - GET /customer-groups/options returns master groups for the dropdown
 *   - duplicate group name on POST /customer-groups returns 422 — but a
 *     customer can still be saved with the existing group name
 */
class Step2410CustomerGroupComboboxTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2410',
            'email'    => 'admin-2410-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    public function test_customer_create_accepts_existing_customer_group_name(): void
    {
        $admin = $this->admin();
        CustomerGroup::create(['name' => 'Khách website', 'is_active' => true]);

        $this->actingAs($admin)->post('/customers', [
            'name'           => 'KH 2410-' . uniqid(),
            'phone'          => '090' . rand(1000000, 9999999),
            'customer_group' => 'Khách website',
        ])->assertRedirect();

        $cust = Customer::where('customer_group', 'Khách website')->latest('id')->first();
        $this->assertNotNull($cust);
        $this->assertSame('Khách website', $cust->customer_group);
    }

    public function test_customer_update_accepts_existing_customer_group_name(): void
    {
        $admin = $this->admin();
        $cust = Customer::create([
            'code'          => 'KH-2410-' . uniqid(),
            'name'          => 'KH old',
            'phone'         => '090' . rand(1000000, 9999999),
            'is_customer'   => true,
            'customer_group'=> 'Khách lẻ',
        ]);
        CustomerGroup::create(['name' => 'Khách chợ tốt', 'is_active' => true]);

        $this->actingAs($admin)->put('/customers/' . $cust->id, [
            'name'           => $cust->name,
            'phone'          => $cust->phone,
            'customer_group' => 'Khách chợ tốt',
        ])->assertRedirect();

        $this->assertSame('Khách chợ tốt', $cust->fresh()->customer_group);
    }

    public function test_customer_create_keeps_customer_group_nullable(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/customers', [
            'name'  => 'KH no-group-' . uniqid(),
            'phone' => '090' . rand(1000000, 9999999),
        ])->assertRedirect();

        $cust = Customer::latest('id')->first();
        $this->assertNotNull($cust);
        $this->assertEmpty($cust->customer_group);
    }

    public function test_customer_group_options_returns_created_group(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/customer-groups', [
            'name' => 'NewGroup-' . uniqid(),
        ])->assertOk();

        $res = $this->actingAs($admin)->getJson('/customer-groups/options');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_starts_with($n, 'NewGroup-')));
    }

    public function test_duplicate_customer_group_name_does_not_break_customer_create(): void
    {
        $admin = $this->admin();
        // First creation succeeds.
        $this->actingAs($admin)->postJson('/customer-groups', ['name' => 'DupG-2410'])->assertOk();
        // Second creation fails 422 — combobox should fall back to "select existing".
        $this->actingAs($admin)->postJson('/customer-groups', ['name' => 'DupG-2410'])->assertStatus(422);

        // But a customer can still be saved using that existing group name.
        $this->actingAs($admin)->post('/customers', [
            'name'           => 'KH dup-' . uniqid(),
            'phone'          => '090' . rand(1000000, 9999999),
            'customer_group' => 'DupG-2410',
        ])->assertRedirect();

        $cust = Customer::where('customer_group', 'DupG-2410')->latest('id')->first();
        $this->assertNotNull($cust);
    }
}
