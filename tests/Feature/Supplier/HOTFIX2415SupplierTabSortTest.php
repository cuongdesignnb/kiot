<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.15 — Supplier expanded-tab time sort.
 *
 * The visible sort change is purely frontend (Suppliers/Index.vue copies
 * the returned arrays and reorders for display). This suite pins the
 * BACKEND contract the FE sort depends on:
 *   - `purchase-history` items carry a usable time field.
 *   - `debt-transactions` entries each carry `created_at` AND `debt_remain`,
 *     so reordering display rows on the client does NOT corrupt the
 *     running balance shown next to each row.
 *   - Backend still computes the running balance in chronological (ASC)
 *     order — flipping that on the server would break debt_remain.
 */
class HOTFIX2415SupplierTabSortTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2415',
            'email'    => 'admin-2415-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2415-' . uniqid(),
            'name'                 => 'NCC 2415',
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    private function purchase(Customer $supplier, int $total, Carbon $date, string $codePrefix = 'PN'): Purchase
    {
        return Purchase::create([
            'code'          => $codePrefix . '-' . uniqid(),
            'supplier_id'   => $supplier->id,
            'total_amount'  => $total,
            'paid_amount'   => 0,
            'debt_amount'   => $total,
            'status'        => 'completed',
            'purchase_date' => $date,
            'created_at'    => $date,
        ]);
    }

    public function test_supplier_purchase_history_items_carry_time_field(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        $this->purchase($sup, 100000, Carbon::create(2026, 4, 15, 10, 0));
        $this->purchase($sup, 200000, Carbon::create(2026, 4, 20, 10, 0));
        $this->purchase($sup, 300000, Carbon::create(2026, 4, 27, 10, 0));

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/purchase-history");
        $res->assertOk();

        $rows = $res->json();
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('date', $row, 'history row must expose a `date` field FE can sort on');
            $this->assertNotEmpty($row['date']);
            $this->assertArrayHasKey('code', $row);
            $this->assertArrayHasKey('total', $row);
        }
    }

    public function test_supplier_debt_transactions_carry_created_at_and_debt_remain(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        // Two purchases at different times to drive a running balance.
        $this->purchase($sup, 500000, Carbon::create(2026, 4, 15, 10, 0));
        $this->purchase($sup, 300000, Carbon::create(2026, 4, 27, 10, 0));

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions");
        $res->assertOk();

        $data = $res->json();
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertNotEmpty($data['entries']);

        foreach ($data['entries'] as $entry) {
            $this->assertArrayHasKey('created_at', $entry, 'FE sort key must exist on every entry');
            $this->assertArrayHasKey('debt_remain', $entry, 'debt_remain must travel with each entry so FE display reorder is safe');
        }
    }

    public function test_supplier_debt_running_balance_is_chronological(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        $this->purchase($sup, 500000, Carbon::create(2026, 4, 15, 10, 0));
        $this->purchase($sup, 300000, Carbon::create(2026, 4, 27, 10, 0));

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions");
        $res->assertOk();
        $entries = collect($res->json('entries'));

        // Backend MUST keep the running balance chronological — flipping that
        // would break debt_remain. Sort by created_at asc and verify it grows.
        $chronological = $entries->sortBy('created_at')->values();
        $expected = 0;
        foreach ($chronological as $entry) {
            $expected += (float) ($entry['supplier_effect'] ?? 0);
            $this->assertEquals(
                $expected,
                (float) $entry['debt_remain'],
                'debt_remain must equal the running sum of supplier_effect in chronological order'
            );
        }

        // Summary should equal final running balance.
        $this->assertEquals($expected, (float) $res->json('summary.net'));
    }

    public function test_supplier_debt_summary_unchanged_regardless_of_display_order(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        $this->purchase($sup, 500000, Carbon::create(2026, 4, 15, 10, 0));
        $this->purchase($sup, 200000, Carbon::create(2026, 4, 20, 10, 0));
        $this->purchase($sup, 300000, Carbon::create(2026, 4, 27, 10, 0));

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions");
        $res->assertOk();

        // 3 purchases × supplier_effect=+total → net = 1,000,000
        $this->assertEquals(1000000, (float) $res->json('summary.net'));
    }
}
