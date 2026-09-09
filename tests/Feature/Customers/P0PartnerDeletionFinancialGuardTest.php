<?php

namespace Tests\Feature\Customers;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class P0PartnerDeletionFinancialGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_partner_with_active_receipt_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $partner = $this->partner();
        $this->cashFlow($partner, 'Khách hàng');

        $response = $this->actingAs($admin)
            ->withHeader('X-Request-ID', 'req-partner-cash-flow-blocked')
            ->delete(route('customers.destroy', $partner));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Không thể xóa đối tác đã có chứng từ, lịch sử tài chính hoặc số liệu lũy kế. Hãy dùng "Ngừng hoạt động" hoặc "Gộp đối tác" để bảo toàn truy vết.');
        $this->assertDatabaseHas('customers', ['id' => $partner->id]);

        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $log = $this->latestAudit(ActivityLog::ACTION_PARTNER_DELETE_BLOCKED);

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('req-partner-cash-flow-blocked', $log->properties['request_id']);
        $this->assertSame('customers.destroy', $log->properties['route']);
        $this->assertSame('customer_controller.destroy', $log->properties['source']);
        $this->assertSame($partner->id, $log->properties['partner_id']);
        $this->assertContains('cash_flow_count_nonzero', $log->properties['reasons']);
    }

    public function test_cancelled_receipt_remains_a_deletion_blocker_for_audit_history(): void
    {
        $partner = $this->partner();
        $cashFlow = $this->cashFlow($partner, 'customer');
        if (! Schema::hasColumn('cash_flows', 'status') || ! Schema::hasColumn('cash_flows', 'deleted_at')) {
            $this->markTestSkipped('The local legacy test schema does not contain cash-flow cancellation columns.');
        }
        DB::table('cash_flows')->where('id', $cashFlow->id)->update([
            'status' => 'cancelled',
            'deleted_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('customers.destroy', $partner))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['id' => $partner->id]);
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $log = $this->latestAudit(ActivityLog::ACTION_PARTNER_DELETE_BLOCKED);
        $this->assertContains('cash_flow_count_nonzero', $log->properties['reasons']);
    }

    public function test_partner_with_only_a_cumulative_financial_value_cannot_be_deleted(): void
    {
        $partner = $this->partner(['total_spent' => 125000]);

        $this->actingAs($this->admin())
            ->delete(route('customers.destroy', $partner))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['id' => $partner->id]);
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $log = $this->latestAudit(ActivityLog::ACTION_PARTNER_DELETE_BLOCKED);
        $this->assertContains('total_spent_nonzero', $log->properties['reasons']);
    }

    public function test_cash_flow_for_an_unrelated_target_type_does_not_create_a_false_blocker(): void
    {
        $partner = $this->partner();
        $this->cashFlow($partner, 'Nhân viên');

        $this->actingAs($this->admin())
            ->delete(route('customers.destroy', $partner))
            ->assertSessionHas('success', 'Xóa khách hàng thành công.');

        $this->assertDatabaseMissing('customers', ['id' => $partner->id]);
    }

    public function test_unused_zero_balance_partner_can_be_deleted_and_is_audited(): void
    {
        $partner = $this->partner();

        $this->actingAs($this->admin())
            ->delete(route('customers.destroy', $partner))
            ->assertSessionHas('success', 'Xóa khách hàng thành công.');

        $this->assertDatabaseMissing('customers', ['id' => $partner->id]);
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $log = $this->latestAudit(ActivityLog::ACTION_PARTNER_DELETE);
        $this->assertSame('success', $log->properties['result']);
        $this->assertSame([], $log->properties['reasons']);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Partner deletion test administrator',
            'email' => 'partner-delete-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function partner(array $attributes = []): Customer
    {
        return Customer::query()->create(array_merge([
            'code' => 'TEST-PARTNER-'.uniqid(),
            'name' => 'Synthetic partner deletion fixture',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_spent' => 0,
            'total_returns' => 0,
            'total_bought' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function cashFlow(Customer $partner, ?string $targetType): CashFlow
    {
        $attributes = [
            'code' => 'TEST-CF-'.uniqid(),
            'type' => 'receipt',
            'amount' => 125000,
            'time' => now(),
            'category' => 'Synthetic receipt',
            'target_type' => $targetType,
            'target_id' => $partner->id,
            'target_name' => $partner->name,
            'accounting_result' => true,
            'payment_method' => 'cash',
        ];
        if (Schema::hasColumn('cash_flows', 'status')) {
            $attributes['status'] = 'active';
        }

        return CashFlow::query()->create($attributes);
    }

    private function latestAudit(string $action): ActivityLog
    {
        return ActivityLog::query()
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }
}
