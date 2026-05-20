<?php

namespace Tests\Feature\CashFlows;

use App\Models\CashFlow;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CashFlowAdvertisingCategoryAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function createCashFlow(array $overrides = []): CashFlow
    {
        return CashFlow::create(array_merge([
            'code' => 'PC-AD-' . uniqid(),
            'type' => 'payment',
            'amount' => 1168350,
            'time' => now(),
            'category' => 'Chi khác',
            'description' => 'FB Ads',
            'payment_method' => 'cash',
            'status' => 'active',
        ], $overrides));
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-cashflow-ad-' . uniqid(),
            'display_name' => 'Cashflow Ads',
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_dry_run_lists_fb_ads_payment_with_non_ad_category(): void
    {
        $cashFlow = $this->createCashFlow([
            'code' => 'PC-FB-ADS-246D',
            'category' => 'Chi khác',
            'description' => 'FB Ads',
        ]);

        $exitCode = Artisan::call('cashflows:audit-ad-category', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($cashFlow->code, $output);
        $this->assertStringContainsString('Chi khác', $output);
        $this->assertStringContainsString('Quảng cáo', $output);
    }

    public function test_dry_run_does_not_update_data(): void
    {
        $cashFlow = $this->createCashFlow([
            'category' => 'Chi khác',
            'description' => 'FB Ads',
        ]);

        $this->artisan('cashflows:audit-ad-category', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('Chi khác', $cashFlow->fresh()->category);
    }

    public function test_dry_run_ignores_already_ad_category(): void
    {
        $cashFlow = $this->createCashFlow([
            'code' => 'PC-AD-OK-246D',
            'category' => 'Quảng cáo',
            'description' => 'FB Ads',
        ]);

        $this->artisan('cashflows:audit-ad-category', ['--dry-run' => true])
            ->doesntExpectOutputToContain($cashFlow->code)
            ->assertExitCode(0);
    }

    public function test_dry_run_ignores_receipts(): void
    {
        $cashFlow = $this->createCashFlow([
            'code' => 'PT-AD-246D',
            'type' => 'receipt',
            'category' => 'Chi khác',
            'description' => 'FB Ads',
        ]);

        $this->artisan('cashflows:audit-ad-category', ['--dry-run' => true])
            ->doesntExpectOutputToContain($cashFlow->code)
            ->assertExitCode(0);
    }

    public function test_dry_run_ignores_cancelled(): void
    {
        $cashFlow = $this->createCashFlow([
            'code' => 'PC-AD-CANCELLED-246D',
            'category' => 'Chi khác',
            'description' => 'FB Ads',
            'status' => 'cancelled',
        ]);

        $this->artisan('cashflows:audit-ad-category', ['--dry-run' => true])
            ->doesntExpectOutputToContain($cashFlow->code)
            ->assertExitCode(0);
    }

    public function test_cashflow_category_filter_remains_exact(): void
    {
        $user = $this->userWith(['cash_flows.view']);
        $matching = $this->createCashFlow([
            'code' => 'PC-AD-FILTER-OK-246D',
            'category' => 'Quảng cáo',
            'description' => 'FB Ads',
        ]);
        $misclassified = $this->createCashFlow([
            'code' => 'PC-AD-FILTER-MISS-246D',
            'category' => 'Chi khác',
            'description' => 'FB Ads',
        ]);

        $response = $this->actingAs($user)->get('/cash-flows?category=' . urlencode('Quảng cáo'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('cashFlows.data', function ($rows) use ($matching, $misclassified) {
                $codes = collect($rows)->pluck('code')->all();

                return in_array($matching->code, $codes, true)
                    && !in_array($misclassified->code, $codes, true);
            })
        );
    }
}
