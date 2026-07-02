<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtVirtualOpeningTimelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_with_balance_but_no_history_gets_read_only_virtual_opening_row(): void
    {
        $admin = User::create([
            'name' => 'Admin Customer Virtual Opening',
            'email' => 'admin-customer-virtual-opening-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $customer = Customer::create([
            'code' => 'KH-VIRTUAL-OPENING-' . uniqid(),
            'name' => 'Chu Ba Lam Virtual Opening',
            'debt_amount' => -800_000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=100&page=1");

        $response->assertOk()
            ->assertJsonPath('summary.has_virtual_opening_balance', true)
            ->assertJsonPath('summary.virtual_opening_balance', -800_000)
            ->assertJsonPath('summary.display_balance_target', -800_000)
            ->assertJsonPath('summary.display_balance_final', -800_000)
            ->assertJsonPath('reconcile.ledger_mismatch', true)
            ->assertJsonPath('reconcile.display_resolved', true)
            ->assertJsonPath('reconcile.has_mismatch', false)
            ->assertJsonPath('reconcile.severity', 'info')
            ->assertJsonPath('reconcile.user_warning', false);

        $this->assertNotEquals(
            'Lịch sử công nợ đang lệch với Nợ hiện tại. Cần đối soát dữ liệu trước khi cập nhật.',
            $response->json('reconcile.message')
        );

        $entries = collect($response->json('entries'));
        $this->assertCount(1, $entries);

        $opening = $entries->first();
        $this->assertTrue($opening['is_virtual_opening']);
        $this->assertSame('virtual_opening_balance', $opening['event_kind']);
        $this->assertEquals(-800_000, $opening['customer_display_effect']);
        $this->assertEquals(-800_000, $opening['customer_display_running_balance']);
        $this->assertEquals(-800_000, $opening['customer_running_balance']);
        $this->assertEquals('Số dư đầu kỳ', $opening['badge_label']);
        $this->assertNotEquals('Đã hạch toán', $opening['badge_label']);
    }
}
