<?php

namespace Tests\Feature\Filters;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.4A — KiotViet-style Full Customer Sidebar Filters.
 */
class Step244AFullCustomerSidebarFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin244a'], [
            'display_name' => 'Admin', 'permissions' => ['*'], 'is_system' => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name' => 'role-' . uniqid(), 'display_name' => 'Test',
            'permissions' => $perms, 'is_system' => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══

    public function test_customer_group_options_include_master_and_legacy_groups(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        // Master
        CustomerGroup::create(['name' => 'VIP', 'is_active' => true]);
        // Legacy (string-only)
        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'customer_group' => 'Khách lẻ', 'is_customer' => true]);

        $res = $this->get('/customers');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->where('filterOptions.customerGroups', function ($groups) {
                $values = collect($groups)->pluck('value')->all();
                return in_array('VIP', $values) && in_array('Khách lẻ', $values);
            })
        );
    }

    // ═══ TC-02 ═══

    public function test_create_customer_group_modal_api_saves_info_and_advanced_config(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $countBefore = Customer::count();

        $res = $this->postJson('/customer-groups', [
            'name' => 'Đại lý cấp 1',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'note' => 'Note',
            'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => 5000000]],
            'update_mode' => 'add_matching',
            'auto_update' => true,
        ]);
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $g = CustomerGroup::where('name', 'Đại lý cấp 1')->first();
        $this->assertNotNull($g);
        $this->assertEquals('percent', $g->discount_type);
        $this->assertEquals(10, $g->discount_value);
        $this->assertEquals('add_matching', $g->update_mode);
        $this->assertTrue((bool) $g->auto_update);
        $this->assertEquals($admin->id, $g->created_by);

        // No customer mutation
        $this->assertEquals($countBefore, Customer::count());
    }

    // ═══ TC-03 ═══

    public function test_customer_filter_by_group_type_gender_status_partner_type(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'customer_group' => 'VIP', 'type' => 'individual', 'gender' => 'male', 'status' => 'active', 'is_customer' => true, 'is_supplier' => false]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'customer_group' => 'VIP', 'type' => 'company', 'gender' => 'female', 'status' => 'inactive', 'is_customer' => true, 'is_supplier' => true]);
        Customer::create(['code' => 'KH-C-' . uniqid(), 'name' => 'C', 'customer_group' => 'Khách lẻ', 'type' => 'individual', 'gender' => 'male', 'status' => 'active', 'is_customer' => true]);

        // Group
        $res = $this->get('/customers?customer_group=VIP');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 2));

        // Type
        $res = $this->get('/customers?type=company');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));

        // Gender
        $res = $this->get('/customers?gender=female');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));

        // Status (whereIn array)
        $res = $this->get('/customers?status[]=inactive');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));

        // Partner type — customer_supplier
        $res = $this->get('/customers?partner_type=customer_supplier');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));

        // Partner type — customer (excludes B which is also supplier)
        $res = $this->get('/customers?partner_type=customer');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 2));
    }

    // ═══ TC-04 ═══

    public function test_customer_created_by_filter_and_options(): void
    {
        $admin = $this->adminUser();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'created_by' => $userA->id, 'is_customer' => true]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'created_by' => $userB->id, 'is_customer' => true]);

        $this->actingAs($admin);
        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.capabilities.supportsCreatedByFilter', true)
            ->where('filterOptions.creators', function ($creators) use ($userA, $userB) {
                $ids = collect($creators)->pluck('id')->all();
                return in_array($userA->id, $ids) && in_array($userB->id, $ids);
            })
        );

        $res = $this->get('/customers?created_by=' . $userA->id);
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-05 ═══

    public function test_customer_created_date_filter(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $old = Customer::create(['code' => 'KH-OLD-' . uniqid(), 'name' => 'Old', 'is_customer' => true]);
        $old->created_at = Carbon::now()->subDays(20);
        $old->save();

        Customer::create(['code' => 'KH-NEW-' . uniqid(), 'name' => 'New', 'is_customer' => true]); // now

        $from = Carbon::now()->subDays(10)->toDateString();
        $res = $this->get("/customers?date_filter=custom&date_from={$from}");
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-06 ═══

    public function test_customer_birthday_filter(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'birthday' => '1990-05-15', 'is_customer' => true]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'birthday' => '1995-12-20', 'is_customer' => true]);

        $res = $this->get('/customers?birthday_from=1990-01-01&birthday_to=1992-12-31');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-07 ═══

    public function test_customer_last_transaction_filter_uses_invoice_transaction_date(): void
    {
        if (!Schema::hasColumn('invoices', 'transaction_date')) {
            $this->markTestSkipped('invoices.transaction_date column not present');
        }

        $admin = $this->adminUser();
        $this->actingAs($admin);

        $custOld = Customer::create(['code' => 'KH-OLD-' . uniqid(), 'name' => 'Old', 'is_customer' => true]);
        $custNew = Customer::create(['code' => 'KH-NEW-' . uniqid(), 'name' => 'New', 'is_customer' => true]);

        Invoice::create([
            'code' => 'HD-' . uniqid(), 'customer_id' => $custOld->id,
            'subtotal' => 100, 'total' => 100, 'discount' => 0, 'status' => 'Hoàn thành',
            'transaction_date' => Carbon::now()->subDays(40),
        ]);
        Invoice::create([
            'code' => 'HD-' . uniqid(), 'customer_id' => $custNew->id,
            'subtotal' => 100, 'total' => 100, 'discount' => 0, 'status' => 'Hoàn thành',
            'transaction_date' => Carbon::now()->subDays(2),
        ]);

        $from = Carbon::now()->subDays(7)->toDateString();
        $res = $this->get("/customers?last_transaction_from={$from}");
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-08 ═══

    public function test_customer_total_sales_range_lifetime_uses_total_spent(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-BIG-' . uniqid(), 'name' => 'Big', 'total_spent' => 10000000, 'is_customer' => true]);
        Customer::create(['code' => 'KH-SMALL-' . uniqid(), 'name' => 'Small', 'total_spent' => 500000, 'is_customer' => true]);

        $res = $this->get('/customers?total_sales_from=1000000');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-09 ═══

    public function test_customer_total_sales_range_with_time_uses_invoice_sum_not_total_spent(): void
    {
        if (!Schema::hasColumn('invoices', 'transaction_date')) {
            $this->markTestSkipped('invoices.transaction_date column not present');
        }

        $admin = $this->adminUser();
        $this->actingAs($admin);

        $custA = Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'total_spent' => 100, 'is_customer' => true]);
        $custB = Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'total_spent' => 999999999, 'is_customer' => true]); // big lifetime

        // A có invoice tháng này 5M
        Invoice::create([
            'code' => 'HD-' . uniqid(), 'customer_id' => $custA->id,
            'subtotal' => 5000000, 'total' => 5000000, 'discount' => 0, 'status' => 'Hoàn thành',
            'transaction_date' => Carbon::now()->subDays(2),
        ]);
        // B chỉ có invoice cũ
        Invoice::create([
            'code' => 'HD-' . uniqid(), 'customer_id' => $custB->id,
            'subtotal' => 100, 'total' => 100, 'discount' => 0, 'status' => 'Hoàn thành',
            'transaction_date' => Carbon::now()->subDays(40),
        ]);

        $from = Carbon::now()->subDays(7)->toDateString();
        // total_sales_from 1M trong 7 ngày — chỉ A khớp (5M); B có 100 trong range
        $res = $this->get("/customers?total_sales_from=1000000&total_sales_date_from={$from}");
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-10 ═══

    public function test_customer_net_debt_filter_uses_debt_minus_supplier_debt(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'debt_amount' => 10, 'supplier_debt_amount' => 3, 'is_customer' => true]); // net 7
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'debt_amount' => 5, 'supplier_debt_amount' => 10, 'is_customer' => true]); // net -5
        Customer::create(['code' => 'KH-C-' . uniqid(), 'name' => 'C', 'debt_amount' => 0, 'supplier_debt_amount' => 0, 'is_customer' => true]);  // net 0

        $res = $this->get('/customers?net_debt_from=1');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1)); // only A

        $res = $this->get('/customers?net_debt_to=-1');
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1)); // only B
    }

    // ═══ TC-11 ═══

    public function test_customer_debt_days_capability_is_false(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p->where('filterOptions.capabilities.supportsDebtDaysFilter', false));
    }

    // ═══ TC-12 ═══

    public function test_customer_points_capability_is_false(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p->where('filterOptions.capabilities.supportsPointsFilter', false));
    }

    // ═══ TC-13 ═══

    public function test_customer_delivery_area_filter_uses_city(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'city' => 'Hà Nội', 'is_customer' => true]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'city' => 'TP.HCM', 'is_customer' => true]);

        $res = $this->get('/customers?delivery_city=' . urlencode('Hà Nội'));
        $res->assertInertia(fn ($p) => $p->where('customers.total', 1));
    }

    // ═══ TC-14 ═══

    public function test_customer_summary_uses_filtered_query(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'customer_group' => 'VIP', 'debt_amount' => 100, 'total_spent' => 1000, 'is_customer' => true]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'customer_group' => 'VIP', 'debt_amount' => 200, 'total_spent' => 2000, 'is_customer' => true]);
        Customer::create(['code' => 'KH-C-' . uniqid(), 'name' => 'C', 'customer_group' => 'Lẻ', 'debt_amount' => 999, 'total_spent' => 99999, 'is_customer' => true]);

        $res = $this->get('/customers?customer_group=VIP');
        $res->assertInertia(fn ($p) => $p
            ->where('summary.total_debt', fn ($v) => (float) $v === 300.0)    // A+B only
            ->where('summary.total_spent', fn ($v) => (float) $v === 3000.0)
        );
    }

    // ═══ TC-15 ═══

    public function test_branch_lock_limits_query_and_filter_options(): void
    {
        Setting::create(['key' => 'customer_manage_by_branch', 'value' => '1']);

        $branchX = Branch::create(['name' => 'X', 'phone' => '0']);
        $branchY = Branch::create(['name' => 'Y', 'phone' => '0']);

        Customer::create(['code' => 'KH-A-' . uniqid(), 'name' => 'A', 'branch_id' => $branchX->id, 'is_customer' => true]);
        Customer::create(['code' => 'KH-B-' . uniqid(), 'name' => 'B', 'branch_id' => $branchY->id, 'is_customer' => true]);

        $userX = User::factory()->create(['branch_id' => $branchX->id]);
        $role = Role::create(['name' => 'r-' . uniqid(), 'display_name' => 'r', 'permissions' => ['*']]);
        $userX->update(['role_id' => $role->id]);

        $this->actingAs($userX);
        $res = $this->get('/customers');
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p
            ->where('customers.total', 1) // only A (branch X)
            ->where('filterOptions.branches', function ($branches) use ($branchX, $branchY) {
                $ids = collect($branches)->pluck('id')->all();
                return in_array($branchX->id, $ids) && !in_array($branchY->id, $ids);
            })
        );
    }

    // ═══ TC-16 ═══

    public function test_unknown_filter_values_do_not_500(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers?customer_group=NONEXIST&type=alien&status[]=ghost&partner_type=unknown');
        $res->assertOk(); // 200, just empty/filtered
    }

    // ═══ TC-17 ═══

    public function test_pagination_preserves_all_customer_filter_query(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        for ($i = 0; $i < 20; $i++) {
            Customer::create(['code' => 'KH-' . $i . '-' . uniqid(), 'name' => "C{$i}", 'customer_group' => 'VIP', 'is_customer' => true]);
        }

        $res = $this->get('/customers?customer_group=VIP&type=individual&page=1');
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p
            ->where('customers.current_page', 1)
            ->where('customers.total', 20)
        );

        $res = $this->get('/customers?customer_group=VIP&page=2');
        $res->assertInertia(fn ($p) => $p->where('customers.current_page', 2));
    }

    // ═══ TC-18 ═══

    public function test_no_fake_filters_are_rendered_without_backend_capability(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.capabilities.supportsBirthdayFilter', true)
            ->where('filterOptions.capabilities.supportsLastTransactionFilter', true)
            ->where('filterOptions.capabilities.supportsTotalSalesTimeFilter', true)
            ->where('filterOptions.capabilities.supportsDebtDaysFilter', false)
            ->where('filterOptions.capabilities.supportsPointsFilter', false)
            ->where('filterOptions.capabilities.supportsDeliveryAreaFilter', true)
            ->where('filterOptions.capabilities.supportsCreatedByFilter', true)
        );
    }
}
