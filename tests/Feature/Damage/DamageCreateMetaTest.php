<?php

namespace Tests\Feature\Damage;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Damage;
use App\Models\Employee;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DamageCreateMetaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_damage_create_uses_selected_employee_and_action_date(): void
    {
        $admin = User::create([
            'name' => 'Damage Meta Admin',
            'email' => 'damage-meta-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $branch = Branch::firstOrCreate(['name' => 'Damage Meta Branch'], ['address' => 'Test']);
        $category = Category::firstOrCreate(['name' => 'Damage Meta Category']);
        $employee = Employee::create([
            'name' => 'Người Xuất Hủy Test',
            'code' => 'NV-XH',
            'is_active' => true,
        ]);

        $product = Product::create([
            'sku' => 'DAMAGE-META-' . uniqid(),
            'name' => 'Damage Meta Product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 5,
            'inventory_total_cost' => 500000,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => $category->id,
        ]);

        $this->actingAs($admin)->post(route('damages.store'), [
            'code' => 'XH-META-' . uniqid(),
            'branch_id' => $branch->id,
            'employee_id' => $employee->id,
            'status' => 'draft',
            'action_date' => '2026-05-22T09:35',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'serial_ids' => [],
            ]],
        ])->assertRedirect();

        $damage = Damage::latest('id')->firstOrFail();

        $this->assertSame($employee->name, $damage->created_by_name);
        $this->assertSame($employee->name, $damage->destroyed_by_name);
        $this->assertSame('2026-05-22 09:35:00', $damage->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-22 09:35:00', $damage->destroyed_date->format('Y-m-d H:i:s'));
    }

    public function test_damage_create_exposes_employee_and_admin_actor_options(): void
    {
        $admin = User::create([
            'name' => 'Damage Actor Admin',
            'email' => 'damage-actor-admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $employee = Employee::create([
            'name' => 'Damage Actor Employee',
            'code' => 'NV-ACTOR',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('damages.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('currentDamageActorKey', 'admin_user:' . $admin->id)
                ->where('damageActorOptions', fn ($options) => collect($options)->contains('value', 'employee:' . $employee->id)
                    && collect($options)->contains('value', 'admin_user:' . $admin->id)
                    && collect($options)->contains('label', 'Damage Actor Admin (Admin)'))
            );
    }

    public function test_damage_store_with_admin_actor_key_saves_admin_name(): void
    {
        $admin = User::create([
            'name' => 'Damage Store Admin',
            'email' => 'damage-store-admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $branch = Branch::firstOrCreate(['name' => 'Damage Admin Actor Branch'], ['address' => 'Test']);
        $category = Category::firstOrCreate(['name' => 'Damage Admin Actor Category']);
        $product = Product::create([
            'sku' => 'DAMAGE-ACTOR-' . uniqid(),
            'name' => 'Damage Actor Product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 5,
            'inventory_total_cost' => 500000,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => $category->id,
        ]);

        $this->actingAs($admin)->post(route('damages.store'), [
            'code' => 'XH-ACTOR-' . uniqid(),
            'branch_id' => $branch->id,
            'damage_actor_key' => 'admin_user:' . $admin->id,
            'status' => 'draft',
            'action_date' => '2026-05-22T10:10',
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'serial_ids' => [],
            ]],
        ])->assertRedirect();

        $damage = Damage::latest('id')->firstOrFail();

        $this->assertSame($admin->name, $damage->created_by_name);
        $this->assertSame($admin->name, $damage->destroyed_by_name);
    }

    public function test_damage_product_serials_endpoint_returns_only_sellable_serials(): void
    {
        $admin = User::create([
            'name' => 'Damage Serial Endpoint Admin',
            'email' => 'damage-serial-endpoint-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $category = Category::firstOrCreate(['name' => 'Damage Serial Endpoint Category']);
        $product = Product::create([
            'sku' => 'DAMAGE-SERIAL-ENDPOINT-' . uniqid(),
            'name' => 'Damage Serial Endpoint Product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 100000,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);

        $sellable = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'DAMAGE-SERIAL-OK-' . uniqid(),
            'status' => 'in_stock',
            'repair_status' => 'ready',
            'cost_price' => 100000,
        ]);

        foreach (['sold', 'defective', 'dismantled', 'in_transit', 'warranty', 'returned'] as $status) {
            SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => 'DAMAGE-SERIAL-' . strtoupper($status) . '-' . uniqid(),
                'status' => $status,
                'cost_price' => 100000,
            ]);
        }

        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'DAMAGE-SERIAL-REPAIRING-' . uniqid(),
            'status' => 'in_stock',
            'repair_status' => 'repairing',
            'cost_price' => 100000,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('damages.products.serials', $product));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame([$sellable->id], $ids);
    }

    public function test_pos_product_serials_endpoint_used_by_damage_returns_only_sellable_serials(): void
    {
        $admin = User::create([
            'name' => 'Damage POS Serial Admin',
            'email' => 'damage-pos-serial-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $category = Category::firstOrCreate(['name' => 'Damage POS Serial Category']);
        $product = Product::create([
            'sku' => 'DAMAGE-POS-SERIAL-' . uniqid(),
            'name' => 'Damage POS Serial Product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 100000,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);

        $sellable = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'DAMAGE-POS-SERIAL-OK-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 100000,
        ]);

        foreach (['sold', 'defective', 'dismantled', 'in_transit', 'warranty', 'returned'] as $status) {
            SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => 'DAMAGE-POS-SERIAL-' . strtoupper($status) . '-' . uniqid(),
                'status' => $status,
                'cost_price' => 100000,
            ]);
        }

        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'DAMAGE-POS-SERIAL-REPAIRING-' . uniqid(),
            'status' => 'in_stock',
            'repair_status' => 'repairing',
            'cost_price' => 100000,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('api.products.serials', $product));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame([$sellable->id], $ids);
    }
}
