<?php

namespace Tests\Feature\Products;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockCardCustomerLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Stock card customer link admin',
            'email' => 'stock-card-admin-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'stock-card-link-'.uniqid(),
            'display_name' => 'Stock card customer link test',
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        return User::create([
            'name' => 'Stock card customer link user',
            'email' => 'stock-card-user-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);
    }

    public function test_invoice_detail_exposes_an_openable_customer_profile_link(): void
    {
        $this->actingAs($this->admin());

        $customer = Customer::create([
            'code' => 'KH-stock-card-link-'.uniqid(),
            'name' => 'Khách hàng mở từ thẻ kho',
            'is_customer' => true,
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-stock-card-link-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 0,
            'total' => 0,
            'status' => 'Hoàn thành',
        ]);

        $response = $this->getJson('/products/document-detail?type=invoice&id='.$invoice->id);

        $response->assertOk();
        $response->assertJsonPath('customer.id', $customer->id);
        $response->assertJsonPath('customer.code', $customer->code);
        $response->assertJsonPath('customer.name', $customer->name);
        $this->assertStringContainsString('/customers?', (string) $response->json('customer.open_url'));
        $this->assertStringContainsString('search='.urlencode($customer->code), (string) $response->json('customer.open_url'));
        $this->assertStringContainsString('open_customer='.$customer->id, (string) $response->json('customer.open_url'));
    }

    public function test_invoice_detail_hides_customer_profile_link_without_customer_access(): void
    {
        $this->actingAs($this->userWith(['products.view']));

        $customer = Customer::create([
            'code' => 'KH-stock-card-private-'.uniqid(),
            'name' => 'Khách hàng hạn chế quyền',
            'is_customer' => true,
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-stock-card-private-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 0,
            'total' => 0,
            'status' => 'Hoàn thành',
        ]);

        $this->getJson('/products/document-detail?type=invoice&id='.$invoice->id)
            ->assertOk()
            ->assertJsonPath('partner_name', $customer->name)
            ->assertJsonPath('customer', null);
    }
}
