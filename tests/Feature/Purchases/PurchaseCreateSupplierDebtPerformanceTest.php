<?php

namespace Tests\Feature\Purchases;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseCreateSupplierDebtPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_initial_supplier_payload_has_no_canonical_debt_queries_and_scales_constant(): void
    {
        $admin = $this->admin();
        $canonicalService = \Mockery::mock(CanonicalPartnerDebtService::class);
        $canonicalService->shouldReceive('calculate')->never();
        $this->app->instance(CanonicalPartnerDebtService::class, $canonicalService);

        $this->seedSuppliers(5, 'small');
        $smallQueries = $this->captureQuerySql(fn () => $this->actingAs($admin)->get('/purchases/create'));

        $this->seedSuppliers(95, 'large');
        $largeQueries = $this->captureQuerySql(fn () => $this->actingAs($admin)->get('/purchases/create'));

        $this->assertSame(0, $this->canonicalQueryCount($smallQueries));
        $this->assertSame(0, $this->canonicalQueryCount($largeQueries));
        $this->assertLessThanOrEqual(
            2,
            abs(count($largeQueries) - count($smallQueries)),
            'initial supplier list query count must remain approximately constant as suppliers grow'
        );
    }

    public function test_initial_payload_and_supplier_search_only_contain_lightweight_fields(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier('NCC-LIGHTWEIGHT-'.uniqid());

        $response = $this->actingAs($admin)->get('/purchases/create');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'] ?? [];
        $row = collect($props['suppliers'] ?? [])->firstWhere('id', $supplier->id);

        $this->assertSame(
            ['id', 'code', 'name', 'phone', 'is_customer', 'is_supplier'],
            array_keys($row)
        );

        $search = $this->actingAs($admin)
            ->getJson('/api/suppliers/search?search='.urlencode($supplier->code))
            ->assertOk()
            ->json();
        $searchRow = collect($search)->firstWhere('id', $supplier->id);

        $this->assertSame(
            ['id', 'code', 'name', 'phone', 'is_customer', 'is_supplier'],
            array_keys($searchRow)
        );
    }

    public function test_lazy_endpoint_matches_canonical_aliases_for_supplier_and_dual_role_fixtures(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier('NCC-CANONICAL-'.uniqid(), [
            'supplier_debt_amount' => 450_000,
        ]);
        $dual = $this->supplier('NCC-DUAL-'.uniqid(), [
            'is_customer' => true,
            'debt_amount' => 125_000,
            'supplier_debt_amount' => 500_000,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-LAZY-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 900_000,
            'paid_amount' => 200_000,
            'debt_amount' => 700_000,
            'purchase_date' => now()->subDay(),
        ]);
        PurchaseReturn::create([
            'code' => 'PTN-LAZY-'.uniqid(),
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 100_000,
            'refund_amount' => 0,
            'return_date' => now()->subHours(12),
        ]);
        CashFlow::create([
            'code' => 'PC-LAZY-'.uniqid(),
            'type' => 'payment',
            'amount' => 200_000,
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'status' => 'active',
            'time' => now()->subHours(6),
        ]);
        DebtOffset::create([
            'code' => 'DO-LAZY-'.uniqid(),
            'customer_id' => $dual->id,
            'amount' => 25_000,
            'customer_amount' => 25_000,
            'supplier_amount' => 25_000,
            'status' => 'active',
            'workflow_status' => 'applied',
            'applied_at' => now()->subHour(),
        ]);

        foreach ([$supplier, $dual] as $partner) {
            $response = $this->actingAs($admin)
                ->getJson("/purchases/suppliers/{$partner->id}/debt-display")
                ->assertOk();
            $data = $response->json();

            $this->assertSame($partner->id, $data['id']);
            foreach (PartnerDebtDisplayBalance::responseAliases($partner->fresh()) as $key => $value) {
                $this->assertEquals($value, $data[$key], "canonical alias {$key} must match for partner {$partner->id}");
            }
        }
    }

    public function test_lazy_endpoint_rejects_inactive_merged_and_non_supplier_without_mutation(): void
    {
        $admin = $this->admin();
        $inactive = $this->supplier('NCC-INACTIVE-'.uniqid(), ['status' => 'inactive']);
        $customer = Customer::create([
            'code' => 'KH-NON-SUPPLIER-'.uniqid(),
            'name' => 'Non supplier',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
        $merged = $this->supplier('NCC-MERGED-'.uniqid());
        $mergedInto = $this->supplier('NCC-MERGED-INTO-'.uniqid());
        $merged->update(['merged_into_id' => $mergedInto->id]);

        $before = Customer::query()
            ->whereKey([$inactive->id, $customer->id, $merged->id])
            ->get(['id', 'is_customer', 'is_supplier', 'status', 'merged_into_id', 'debt_amount', 'supplier_debt_amount', 'updated_at'])
            ->toJson();

        foreach ([$inactive, $customer, $merged] as $partner) {
            $this->actingAs($admin)
                ->getJson("/purchases/suppliers/{$partner->id}/debt-display")
                ->assertNotFound();
        }

        $after = Customer::query()
            ->whereKey([$inactive->id, $customer->id, $merged->id])
            ->get(['id', 'is_customer', 'is_supplier', 'status', 'merged_into_id', 'debt_amount', 'supplier_debt_amount', 'updated_at'])
            ->toJson();

        $this->assertSame($before, $after);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin purchase create performance',
            'email' => 'admin-purchase-create-performance-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(string $code, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $code,
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ], $overrides));
    }

    private function seedSuppliers(int $count, string $prefix): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->supplier('NCC-'.strtoupper($prefix).'-'.$index.'-'.uniqid());
        }
    }

    /** @return list<string> */
    private function captureQuerySql(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $callback();

        return $queries;
    }

    /** @param list<string> $queries */
    private function canonicalQueryCount(array $queries): int
    {
        $canonicalTables = [
            'invoices', 'returns', 'purchases', 'purchase_returns',
            'cash_flows', 'supplier_debt_transactions', 'supplier_payment_allocations',
            'debt_offsets', 'partner_debt_operations', 'customer_payments',
        ];

        return count(array_filter($queries, function (string $sql) use ($canonicalTables): bool {
            foreach ($canonicalTables as $table) {
                if (str_contains($sql, " from `{$table}`") || str_contains($sql, " join `{$table}`")) {
                    return true;
                }
            }

            return false;
        }));
    }
}
