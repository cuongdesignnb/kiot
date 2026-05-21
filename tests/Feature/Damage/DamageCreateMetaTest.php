<?php

namespace Tests\Feature\Damage;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Damage;
use App\Models\Employee;
use App\Models\Product;
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
}
