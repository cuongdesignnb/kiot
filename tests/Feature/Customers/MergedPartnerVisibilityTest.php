<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MergedPartnerVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_merged_customer_source_is_hidden_but_surviving_dual_role_target_remains_visible(): void
    {
        $admin = $this->admin();
        $target = Customer::create([
            'code' => 'NCC-VISIBILITY-TARGET-'.uniqid(),
            'name' => 'Merged visibility target',
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 650_000,
            'supplier_debt_amount' => 650_000,
        ]);
        $source = Customer::create([
            'code' => 'KH-VISIBILITY-SOURCE-'.uniqid(),
            'name' => 'Merged visibility source',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'inactive',
            'merged_into_id' => $target->id,
            'merged_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/customers?search=visibility');

        $response->assertOk();
        $rows = collect($this->inertiaProps($response)['customers']['data'] ?? []);

        $this->assertNull($rows->firstWhere('id', $source->id));
        $this->assertSame($target->id, $rows->firstWhere('id', $target->id)['id']);
    }

    public function test_merged_supplier_source_is_hidden_but_surviving_supplier_remains_visible(): void
    {
        $admin = $this->admin();
        $target = Customer::create([
            'code' => 'NCC-SUPPLIER-VISIBILITY-TARGET-'.uniqid(),
            'name' => 'Merged supplier visibility target',
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
            'supplier_debt_amount' => 900_000,
        ]);
        $source = Customer::create([
            'code' => 'NCC-SUPPLIER-VISIBILITY-SOURCE-'.uniqid(),
            'name' => 'Merged supplier visibility source',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'inactive',
            'merged_into_id' => $target->id,
            'merged_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/suppliers?search=visibility');

        $response->assertOk();
        $rows = collect($this->inertiaProps($response)['suppliers']['data'] ?? []);

        $this->assertNull($rows->firstWhere('id', $source->id));
        $this->assertSame($target->id, $rows->firstWhere('id', $target->id)['id']);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Merged partner visibility admin',
            'email' => 'merged-partner-visibility-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function inertiaProps($response): array
    {
        $page = $response->original->getData()['page'] ?? null;

        return $page['props'] ?? [];
    }
}
