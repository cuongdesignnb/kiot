<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierStoreParityTest extends TestCase
{
    use DatabaseTransactions;

    private function actor(): User
    {
        $role = Role::create([
            'name' => 'supplier-store-parity-'.uniqid(),
            'display_name' => 'Supplier store parity',
            'permissions' => ['suppliers.create'],
            'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_full_store_creates_normal_and_dual_role_supplier_with_ncc_prefix(): void
    {
        $user = $this->actor();

        $normal = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Full store supplier',
            'phone' => '0900000301',
            'is_customer' => false,
            'supplier_linking_mode' => 'new',
        ]);
        $normal->assertOk()->assertJsonPath('supplier.is_supplier', true);
        $this->assertStringStartsWith('NCC', (string) $normal->json('supplier.code'));

        $dual = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Full store dual role',
            'phone' => '0900000302',
            'is_customer' => true,
            'supplier_linking_mode' => 'new',
        ]);
        $dual->assertOk()
            ->assertJsonPath('supplier.is_customer', true)
            ->assertJsonPath('supplier.is_supplier', true);
        $this->assertStringStartsWith('NCC', (string) $dual->json('supplier.code'));
    }

    public function test_full_store_link_existing_customer_preserves_row_and_financial_history(): void
    {
        $user = $this->actor();
        $target = Customer::create([
            'code' => 'FULL-STORE-CUSTOMER',
            'name' => 'Existing full-store customer',
            'phone' => '0900000310',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
            'debt_amount' => 101000,
            'supplier_debt_amount' => 202000,
            'total_spent' => 303000,
            'total_returns' => 404000,
            'total_bought' => 505000,
        ]);
        Invoice::create([
            'code' => 'FULL-STORE-INVOICE-'.uniqid(),
            'customer_id' => $target->id,
            'subtotal' => 100,
            'total' => 100,
            'customer_paid' => 0,
        ]);
        $beforeCount = Customer::count();
        $before = $target->only([
            'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount',
            'total_spent', 'total_returns', 'total_bought', 'status',
        ]);
        $beforeInvoices = Invoice::where('customer_id', $target->id)->count();

        $response = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Must not overwrite target',
            'code' => 'MUST-NOT-OVERWRITE',
            'phone' => '0900000399',
            'is_customer' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_customer_id' => $target->id,
        ]);

        $response->assertOk()->assertJsonPath('supplier.id', $target->id);
        $after = Customer::findOrFail($target->id);
        $this->assertSame($beforeCount, Customer::count());
        foreach (array_keys($before) as $field) {
            if (in_array($field, ['debt_amount', 'supplier_debt_amount', 'total_spent', 'total_returns', 'total_bought'], true)) {
                $this->assertSame((float) $before[$field], (float) $after->{$field});
            } else {
                $this->assertSame($before[$field], $after->{$field});
            }
        }
        $this->assertSame($beforeInvoices, Invoice::where('customer_id', $target->id)->count());
        $this->assertTrue((bool) $after->is_customer);
        $this->assertTrue((bool) $after->is_supplier);
    }

    public function test_full_store_duplicate_returns_cta_and_no_validation_key(): void
    {
        $user = $this->actor();
        $existing = Customer::create([
            'code' => 'FULL-STORE-DUPLICATE',
            'name' => 'Existing duplicate customer',
            'phone' => '0900000320',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Duplicate supplier',
            'code' => $existing->code,
            'phone' => $existing->phone,
            'is_customer' => true,
            'supplier_linking_mode' => 'new',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_ALREADY_EXISTS')
            ->assertJsonPath('existing_partner.id', $existing->id)
            ->assertJsonPath('suggested_action', 'link_existing');
        $this->assertStringNotContainsString('validation.', $response->getContent());
        $this->assertSame($beforeCount, Customer::count());
    }

    public function test_full_store_rejects_missing_target_stale_toggle_and_invalid_targets(): void
    {
        $user = $this->actor();
        $customerOnly = Customer::create([
            'code' => 'FULL-STORE-NOT-SUPPLIER',
            'name' => 'Customer only target',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $wrongRole = Customer::create([
            'code' => 'FULL-STORE-WRONG-ROLE',
            'name' => 'Supplier only target',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $inactive = Customer::create([
            'code' => 'FULL-STORE-INACTIVE',
            'name' => 'Inactive target',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'inactive',
        ]);
        $mergedInto = Customer::create([
            'code' => 'FULL-STORE-MERGED-INTO',
            'name' => 'Merged into target',
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $merged = Customer::create([
            'code' => 'FULL-STORE-MERGED',
            'name' => 'Merged target',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
            'merged_into_id' => $mergedInto->id,
        ]);

        $stale = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Stale supplier link',
            'is_customer' => false,
            'supplier_linking_mode' => 'link_existing',
            'linked_customer_id' => $customerOnly->id,
        ]);
        $stale->assertStatus(422)->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');

        foreach ([$wrongRole, $inactive, $merged] as $target) {
            $response = $this->actingAs($user)->postJson('/suppliers', [
                'name' => 'Invalid supplier target',
                'is_customer' => true,
                'supplier_linking_mode' => 'link_existing',
                'linked_customer_id' => $target->id,
            ]);
            $response->assertStatus(422)->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');
            $this->assertStringNotContainsString('validation.', $response->getContent());
        }

        $missing = $this->actingAs($user)->postJson('/suppliers', [
            'name' => 'Missing supplier target',
            'is_customer' => true,
            'supplier_linking_mode' => 'link_existing',
        ]);
        $missing->assertStatus(422)->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');
    }
}
