<?php

namespace Tests\Feature\Damage;

use App\Models\Branch;
use App\Models\Damage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DamageActionDateTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Damage Date Admin',
            'email' => 'damage-date-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $this->branch = Branch::firstOrCreate(['name' => 'Damage Date Branch'], ['address' => 'Test']);
        $this->product = Product::create([
            'sku' => 'SKU-' . uniqid(),
            'name' => 'Sản phẩm Test',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 100,
            'inventory_total_cost' => 100000 * 100,
            'has_serial' => false,
        ]);
    }

    public function test_damage_store_saves_action_date_correctly(): void
    {
        $payload = [
            'code' => 'XH-TEST-DATE',
            'status' => 'completed',
            'branch_id' => $this->branch->id,
            'action_date' => '2026-05-23T08:50',
            'note' => 'Test action date',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 5,
                ]
            ],
        ];

        $res = $this->actingAs($this->admin)->post('/damages', $payload);
        $res->assertRedirect();

        $damage = Damage::where('code', 'XH-TEST-DATE')->first();
        $this->assertNotNull($damage);
        $this->assertEquals('2026-05-23 08:50:00', $damage->created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-05-23 08:50:00', $damage->destroyed_date->format('Y-m-d H:i:s'));
    }
}
