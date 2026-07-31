<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\User;
use App\Services\PartnerTransactionGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class P0PosCustomerSupplierDualRoleUniqueValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function actor(): User
    {
        $role = Role::create([
            'name' => 'p0-partner-role-'.uniqid(),
            'display_name' => 'P0 partner hotfix',
            'permissions' => ['pos.use', 'customers.create', 'suppliers.create'],
            'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_pos_can_create_new_customer_and_new_dual_role_partner(): void
    {
        $user = $this->actor();

        $new = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 customer new',
            'phone' => '0900000001',
            'is_customer' => true,
            'is_supplier' => false,
            'supplier_linking_mode' => 'new',
        ]);
        $new->assertOk()->assertJsonPath('customer.name', 'P0 customer new');
        $this->assertStringStartsWith('KH', (string) $new->json('customer.code'));

        $dual = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 dual role new',
            'phone' => '0900000002',
            'is_customer' => true,
            'is_supplier' => true,
            'supplier_linking_mode' => 'new',
        ]);
        $dual->assertOk()
            ->assertJsonPath('customer.is_customer', true)
            ->assertJsonPath('customer.is_supplier', true);
        $this->assertStringStartsWith('KH', (string) $dual->json('customer.code'));
    }

    public function test_supplier_quick_store_new_keeps_ncc_code_prefix(): void
    {
        $user = $this->actor();

        $response = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'P0 supplier prefix',
            'phone' => '0900000003',
            'is_customer' => false,
            'supplier_linking_mode' => 'new',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('NCC', (string) $response->json('supplier.code'));
    }

    public function test_pos_link_existing_supplier_with_identical_code_and_phone_without_creating_or_changing_financials(): void
    {
        $user = $this->actor();
        $supplier = Customer::create([
            'code' => 'P0-LINK-SUPPLIER',
            'name' => 'P0 existing supplier',
            'phone' => '0900000010',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 125000,
            'supplier_debt_amount' => 275000,
            'total_spent' => 800000,
            'total_returns' => 12000,
            'total_bought' => 990000,
        ]);
        $beforeCount = Customer::count();
        $before = $supplier->only([
            'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount',
            'total_spent', 'total_returns', 'total_bought', 'status',
        ]);

        $response = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 form label must not overwrite target',
            'code' => $supplier->code,
            'phone' => $supplier->phone,
            'is_customer' => true,
            'is_supplier' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $supplier->id,
        ]);

        $response->assertOk()->assertJsonPath('customer.id', $supplier->id);
        $this->assertSame($beforeCount, Customer::count());
        $after = Customer::findOrFail($supplier->id);
        $this->assertSame($before['code'], $after->code);
        $this->assertSame($before['name'], $after->name);
        $this->assertSame($before['phone'], $after->phone);
        foreach (['debt_amount', 'supplier_debt_amount', 'total_spent', 'total_returns', 'total_bought'] as $field) {
            $this->assertSame((float) $before[$field], (float) $after->{$field}, $field.' must not change');
        }
        $this->assertSame($before['status'], $after->status);
        $this->assertTrue((bool) Customer::findOrFail($supplier->id)->is_customer);
        $this->assertTrue((bool) Customer::findOrFail($supplier->id)->is_supplier);
    }

    public function test_customers_endpoint_uses_the_same_link_existing_contract(): void
    {
        $user = $this->actor();
        $supplier = Customer::create([
            'code' => 'P0-CUSTOMERS-LINK',
            'name' => 'P0 customers endpoint supplier',
            'phone' => '0900000011',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'supplier_debt_amount' => 345000,
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/customers', [
            'name' => 'Ignored form name',
            'code' => $supplier->code,
            'phone' => $supplier->phone,
            'is_supplier' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $supplier->id,
        ]);

        $response->assertOk()->assertJsonPath('customer.id', $supplier->id);
        $this->assertSame($beforeCount, Customer::count());
        $this->assertSame(345000.0, (float) Customer::findOrFail($supplier->id)->supplier_debt_amount);
        $this->assertTrue((bool) Customer::findOrFail($supplier->id)->is_customer);
    }

    public function test_customers_endpoint_returns_structured_vietnamese_validation_errors(): void
    {
        $user = $this->actor();
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/customers', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED')
            ->assertJsonPath('errors.name.0', 'Vui lòng nhập tên khách hàng.');
        $this->assertStringNotContainsString('validation.', $response->getContent());
        $this->assertSame($beforeCount, Customer::count());
    }

    public function test_supplier_quick_store_uses_the_shared_link_contract(): void
    {
        $user = $this->actor();
        $customer = Customer::create([
            'code' => 'P0-SUPPLIER-QUICK-LINK',
            'name' => 'P0 customer quick-store target',
            'phone' => '0900000012',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'Ignored supplier quick-store label',
            'code' => $customer->code,
            'phone' => $customer->phone,
            'is_customer' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_customer_id' => $customer->id,
        ]);

        $response->assertOk()->assertJsonPath('supplier.id', $customer->id);
        $this->assertSame($beforeCount, Customer::count());
        $this->assertTrue((bool) Customer::findOrFail($customer->id)->is_supplier);
    }

    public function test_supplier_flow_links_existing_customer_and_preserves_documents_and_financials(): void
    {
        $user = $this->actor();
        $customer = Customer::create([
            'code' => 'P0-CUSTOMER-LINK-TARGET',
            'name' => 'P0 customer link target',
            'phone' => '0900000013',
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
            'code' => 'P0-INVOICE-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 100,
            'total' => 100,
            'customer_paid' => 0,
        ]);
        $beforePurchaseCount = Purchase::where('supplier_id', $customer->id)->count();
        $before = $customer->only([
            'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount',
            'total_spent', 'total_returns', 'total_bought', 'status',
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'P0 supplier form label',
            'code' => $customer->code,
            'phone' => $customer->phone,
            'is_customer' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $customer->id,
        ]);

        $response->assertOk()->assertJsonPath('supplier.id', $customer->id);
        $after = Customer::findOrFail($customer->id);
        $this->assertSame($beforeCount, Customer::count());
        $this->assertSame($before['code'], $after->code);
        $this->assertSame($before['name'], $after->name);
        $this->assertSame($before['phone'], $after->phone);
        foreach (['debt_amount', 'supplier_debt_amount', 'total_spent', 'total_returns', 'total_bought'] as $field) {
            $this->assertSame((float) $before[$field], (float) $after->{$field}, $field.' must not change');
        }
        $this->assertSame($before['status'], $after->status);
        $this->assertSame($beforePurchaseCount, Purchase::where('supplier_id', $customer->id)->count());
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        $this->assertTrue((bool) $after->is_customer);
        $this->assertTrue((bool) $after->is_supplier);
    }

    public function test_legacy_null_status_target_can_link_in_customer_and_supplier_contexts(): void
    {
        $guard = app(PartnerTransactionGuard::class);
        $this->assertTrue($guard->isAvailable(new Customer(['status' => null])));

        $user = $this->actor();
        $supplier = Customer::create([
            'code' => 'P0-NULL-SUPPLIER',
            'name' => 'P0 null status supplier',
            'phone' => '0900000014',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'code' => 'P0-NULL-CUSTOMER',
            'name' => 'P0 null status customer',
            'phone' => '0900000015',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);

        $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 null customer link',
            'is_customer' => true,
            'is_supplier' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $supplier->id,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'P0 null supplier link',
            'is_customer' => true,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $customer->id,
        ])->assertOk();

        $this->assertTrue((bool) Customer::findOrFail($supplier->id)->is_customer);
        $this->assertTrue((bool) Customer::findOrFail($customer->id)->is_supplier);
    }

    public function test_stale_link_payloads_with_missing_counterpart_role_are_rejected_without_mutation(): void
    {
        $user = $this->actor();
        $supplier = Customer::create([
            'code' => 'P0-STALE-SUPPLIER',
            'name' => 'P0 stale supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'code' => 'P0-STALE-CUSTOMER',
            'name' => 'P0 stale customer',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $beforeCount = Customer::count();

        $customerContext = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 stale customer request',
            'is_customer' => true,
            'is_supplier' => false,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $supplier->id,
        ]);
        $customerContext->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');

        $supplierContext = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'P0 stale supplier request',
            'is_customer' => false,
            'supplier_linking_mode' => 'link_existing',
            'linked_supplier_id' => $customer->id,
        ]);
        $supplierContext->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');

        $this->assertSame($beforeCount, Customer::count());
        $this->assertFalse((bool) Customer::findOrFail($supplier->id)->is_customer);
        $this->assertFalse((bool) Customer::findOrFail($customer->id)->is_supplier);
        $this->assertStringNotContainsString('validation.', $customerContext->getContent());
        $this->assertStringNotContainsString('validation.', $supplierContext->getContent());
    }

    public function test_duplicate_new_returns_partner_already_exists_without_mutation_and_vietnamese_errors(): void
    {
        $user = $this->actor();
        $existing = Customer::create([
            'code' => 'P0-DUPLICATE',
            'name' => 'P0 duplicate supplier',
            'phone' => '0900000020',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/api/pos/customers', [
            'name' => 'P0 duplicate new',
            'code' => $existing->code,
            'phone' => $existing->phone,
            'is_customer' => true,
            'is_supplier' => true,
            'supplier_linking_mode' => 'new',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_ALREADY_EXISTS')
            ->assertJsonPath('existing_partner.id', $existing->id)
            ->assertJsonPath('suggested_action', 'link_existing')
            ->assertJsonPath('errors.code.0', 'Mã đối tác đã tồn tại.')
            ->assertJsonPath('errors.phone.0', 'Số điện thoại đã tồn tại.');
        $this->assertStringNotContainsString('validation.', $response->getContent());
        $this->assertSame($beforeCount, Customer::count());
    }

    public function test_duplicate_supplier_new_against_existing_customer_suggests_link_existing(): void
    {
        $user = $this->actor();
        $existing = Customer::create([
            'code' => 'P0-CUSTOMER-DUPLICATE',
            'name' => 'P0 duplicate customer',
            'phone' => '0900000021',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $beforeCount = Customer::count();

        $response = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'P0 duplicate supplier new',
            'code' => $existing->code,
            'phone' => $existing->phone,
            'is_customer' => true,
            'supplier_linking_mode' => 'new',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PARTNER_ALREADY_EXISTS')
            ->assertJsonPath('existing_partner.id', $existing->id)
            ->assertJsonPath('suggested_action', 'link_existing');
        $this->assertSame($beforeCount, Customer::count());
        $this->assertFalse((bool) Customer::findOrFail($existing->id)->is_supplier);
    }

    public function test_invalid_inactive_and_merged_targets_are_rejected_without_mutation(): void
    {
        $user = $this->actor();
        $invalid = Customer::create([
            'code' => 'P0-NOT-SUPPLIER',
            'name' => 'P0 customer only',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $inactive = Customer::create([
            'code' => 'P0-INACTIVE',
            'name' => 'P0 inactive supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'inactive',
        ]);
        $mergeTarget = Customer::create([
            'code' => 'P0-MERGE-TARGET',
            'name' => 'P0 merge target',
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $merged = Customer::create([
            'code' => 'P0-MERGED',
            'name' => 'P0 merged supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'merged_into_id' => $mergeTarget->id,
        ]);

        foreach ([$invalid, $inactive, $merged] as $target) {
            $beforeCount = Customer::count();
            $before = Customer::findOrFail($target->id)->only(['is_customer', 'is_supplier', 'status', 'merged_into_id']);

            $response = $this->actingAs($user)->postJson('/api/pos/customers', [
                'name' => 'P0 invalid link',
                'is_customer' => true,
                'is_supplier' => true,
                'supplier_linking_mode' => 'link_existing',
                'linked_supplier_id' => $target->id,
            ]);

            $response->assertStatus(422)->assertJsonPath('code', 'PARTNER_VALIDATION_FAILED');
            $this->assertStringNotContainsString('validation.', $response->getContent());
            $this->assertSame($beforeCount, Customer::count());
            $this->assertSame($before, Customer::findOrFail($target->id)->only(array_keys($before)));
        }
    }
}
