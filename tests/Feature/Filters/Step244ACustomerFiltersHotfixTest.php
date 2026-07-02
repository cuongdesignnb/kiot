<?php

namespace Tests\Feature\Filters;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;

/**
 * HOTFIX 24.4A-1 — Customers page crash on missing capabilities.
 */
class Step244ACustomerFiltersHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin244ahotfix'], [
            'display_name' => 'Admin', 'permissions' => ['*'], 'is_system' => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_customers_index_always_returns_filter_capabilities(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertOk();
        $res->assertInertia(fn ($p) => $p
            ->has('filterOptions.capabilities')
            ->has('filterOptions.capabilities.supportsBirthdayFilter')
            ->has('filterOptions.capabilities.supportsLastTransactionFilter')
            ->has('filterOptions.capabilities.supportsTotalSalesTimeFilter')
            ->has('filterOptions.capabilities.supportsDebtDaysFilter')
            ->has('filterOptions.capabilities.supportsPointsFilter')
            ->has('filterOptions.capabilities.supportsDeliveryAreaFilter')
            ->has('filterOptions.capabilities.supportsCreatedByFilter')
        );
    }

    public function test_capabilities_are_strict_booleans(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.capabilities.supportsBirthdayFilter', fn ($v) => is_bool($v))
            ->where('filterOptions.capabilities.supportsDebtDaysFilter', fn ($v) => is_bool($v))
            ->where('filterOptions.capabilities.supportsPointsFilter', fn ($v) => is_bool($v))
        );
    }

    public function test_unsupported_filters_are_false(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->where('filterOptions.capabilities.supportsDebtDaysFilter', false)
            ->where('filterOptions.capabilities.supportsPointsFilter', false)
        );
    }

    public function test_filter_options_have_expected_keys(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $res = $this->get('/customers');
        $res->assertInertia(fn ($p) => $p
            ->has('filterOptions.customerGroups')
            ->has('filterOptions.types')
            ->has('filterOptions.genders')
            ->has('filterOptions.branches')
            ->has('filterOptions.creators')
            ->has('filterOptions.statuses')
            ->has('filterOptions.partnerTypes')
            ->has('filterOptions.deliveryCities')
            ->has('filterOptions.debtOptions')
        );
    }
}
