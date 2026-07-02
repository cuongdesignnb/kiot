<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtHistoryReturnSettlementDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Debt History Display Admin',
            'email' => 'debt-history-display-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function customer(float $debt = 0): Customer
    {
        return Customer::create([
            'code' => 'KH-DH-' . uniqid(),
            'name' => 'Debt History Customer',
            'phone' => '091' . rand(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => $debt,
            'total_spent' => 0,
            'total_returns' => 0,
        ]);
    }

    private function addDebt(Customer $customer, array $attrs): CustomerDebt
    {
        return CustomerDebt::create(array_merge([
            'customer_id' => $customer->id,
            'ref_code' => 'TH_TEST',
            'amount' => 0,
            'debt_total' => 0,
            'type' => 'adjustment',
            'note' => null,
            'recorded_at' => now(),
        ], $attrs));
    }

    private function history(Customer $customer): array
    {
        return $this->actingAs($this->admin())
            ->getJson("/customers/{$customer->id}/debt-history")
            ->assertOk()
            ->json();
    }

    private function entriesFor(array $payload, string $code): array
    {
        return collect($payload['entries'])
            ->filter(fn($entry) => ($entry['code'] ?? null) === $code)
            ->values()
            ->all();
    }

    public function test_paid_return_settlement_is_displayed_as_single_return_row(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'TH_TEST',
            'type' => 'return',
            'amount' => -3000000,
            'debt_total' => -3000000,
            'note' => 'Return test',
            'recorded_at' => now()->subMinute(),
        ]);
        $settlement = $this->addDebt($customer, [
            'ref_code' => 'TH_TEST',
            'type' => 'adjustment',
            'amount' => 3000000,
            'debt_total' => 0,
            'note' => 'Tat toan tien da tra khach cho phieu tra TH_TEST',
            'recorded_at' => now(),
        ]);

        $entries = $this->entriesFor($this->history($customer), 'TH_TEST');

        $this->assertCount(1, $entries);
        $this->assertSame('return', $entries[0]['type_raw']);
        $this->assertSame(-3000000.0, (float) $entries[0]['amount']);
        $this->assertSame(0.0, (float) $entries[0]['balance']);
        $this->assertTrue($entries[0]['display_merged_settlement']);
        $this->assertSame([$settlement->id], $entries[0]['settlement_adjustment_ids']);
        $this->assertFalse(collect($entries)->contains(fn($entry) => ($entry['type_raw'] ?? null) === 'adjustment'));
    }

    public function test_legacy_apply_settlement_is_also_merged(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'TH_APPLY',
            'type' => 'return',
            'amount' => -3000000,
            'debt_total' => -3000000,
            'recorded_at' => now()->subMinute(),
        ]);
        $this->addDebt($customer, [
            'ref_code' => 'TH_APPLY',
            'type' => 'adjustment',
            'amount' => 3000000,
            'debt_total' => 0,
            'note' => 'Bo sung tat toan tien da tra khach cho phieu tra TH_APPLY',
            'recorded_at' => now(),
        ]);

        $entries = $this->entriesFor($this->history($customer), 'TH_APPLY');

        $this->assertCount(1, $entries);
        $this->assertSame('return', $entries[0]['type_raw']);
        $this->assertSame(0.0, (float) $entries[0]['debt_total']);
    }

    public function test_unpaid_return_still_shows_negative_credit(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'TH_UNPAID',
            'type' => 'return',
            'amount' => -3000000,
            'debt_total' => -3000000,
        ]);

        $entries = $this->entriesFor($this->history($customer), 'TH_UNPAID');

        $this->assertCount(1, $entries);
        $this->assertSame('return', $entries[0]['type_raw']);
        $this->assertSame(-3000000.0, (float) $entries[0]['balance']);
        $this->assertArrayNotHasKey('display_merged_settlement', $entries[0]);
    }

    public function test_partial_paid_return_shows_return_row_with_final_balance_after_settlement(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'TH_PARTIAL',
            'type' => 'return',
            'amount' => -10000000,
            'debt_total' => -10000000,
            'recorded_at' => now()->subMinute(),
        ]);
        $this->addDebt($customer, [
            'ref_code' => 'TH_PARTIAL',
            'type' => 'adjustment',
            'amount' => 4000000,
            'debt_total' => -6000000,
            'note' => 'Tat toan tien da tra khach cho phieu tra TH_PARTIAL',
            'recorded_at' => now(),
        ]);

        $entries = $this->entriesFor($this->history($customer), 'TH_PARTIAL');

        $this->assertCount(1, $entries);
        $this->assertSame('return', $entries[0]['type_raw']);
        $this->assertSame(-10000000.0, (float) $entries[0]['amount']);
        $this->assertSame(-6000000.0, (float) $entries[0]['balance']);
        $this->assertSame(4000000.0, (float) $entries[0]['settlement_adjusted_amount']);
    }

    public function test_manual_adjustment_still_visible(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'DC_MANUAL',
            'type' => 'adjustment',
            'amount' => 500000,
            'debt_total' => 500000,
            'note' => 'Dieu chinh cong no thu cong',
        ]);

        $entries = $this->entriesFor($this->history($customer), 'DC_MANUAL');

        $this->assertCount(1, $entries);
        $this->assertSame('adjustment', $entries[0]['type_raw']);
        $this->assertSame(500000.0, (float) $entries[0]['amount']);
    }

    public function test_settlement_adjustment_without_return_match_is_not_hidden(): void
    {
        $customer = $this->customer();
        $this->addDebt($customer, [
            'ref_code' => 'TH_ORPHAN',
            'type' => 'adjustment',
            'amount' => 3000000,
            'debt_total' => 0,
            'note' => 'Tat toan tien da tra khach cho phieu tra TH_ORPHAN',
        ]);

        $entries = $this->entriesFor($this->history($customer), 'TH_ORPHAN');

        $this->assertCount(1, $entries);
        $this->assertSame('adjustment', $entries[0]['type_raw']);
        $this->assertSame(3000000.0, (float) $entries[0]['amount']);
    }
}
