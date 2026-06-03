<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductBulkDestroyTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Test',
            'email' => 'admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Product ' . $sku,
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_bulk_destroy_products(): void
    {
        $admin = $this->admin();
        $p1 = $this->product('P1-' . uniqid());
        $p2 = $this->product('P2-' . uniqid());

        $response = $this->actingAs($admin)->post('/products/bulk-destroy', [
            'product_ids' => [$p1->id, $p2->id],
        ]);

        $response->assertRedirect();

        $this->assertNotNull($p1->fresh()->deleted_at);
        $this->assertNotNull($p2->fresh()->deleted_at);
    }

    public function test_user_without_permission_cannot_bulk_destroy(): void
    {
        $role = Role::create([
            'name' => 'no_delete_role_' . uniqid(),
            'display_name' => 'No Delete',
            'permissions' => ['products.view'],
            'is_system' => false,
        ]);

        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $p1 = $this->product('P1-' . uniqid());

        $response = $this->actingAs($user)->post('/products/bulk-destroy', [
            'product_ids' => [$p1->id],
        ]);

        $response->assertRedirect('/');

        $this->assertNull($p1->fresh()->deleted_at);
    }
}
