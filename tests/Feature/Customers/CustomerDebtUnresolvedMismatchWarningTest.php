<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtUnresolvedMismatchWarningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unresolved_display_mismatch_still_returns_warning(): void
    {
        $admin = User::create([
            'name' => 'Admin Customer Unresolved Mismatch',
            'email' => 'admin-customer-unresolved-mismatch-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $customer = Customer::create([
            'code' => 'KH-UNRESOLVED-MISMATCH-' . uniqid(),
            'name' => 'Customer Unresolved Mismatch',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'LEDGER-MISMATCH-' . uniqid(),
            'amount' => 1_000_000,
            'debt_total' => 1_000_000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=100&page=1");

        $response->assertOk();

        $this->assertFalse($response->json('reconcile.display_resolved'));
        $this->assertTrue($response->json('reconcile.has_mismatch'));
        $this->assertEquals('warning', $response->json('reconcile.severity'));
        $this->assertTrue($response->json('reconcile.user_warning'));
        $this->assertEquals(
            'Lịch sử công nợ đang lệch với Nợ hiện tại. Cần đối soát dữ liệu trước khi cập nhật.',
            $response->json('reconcile.message')
        );
    }
}
