<?php

namespace Tests\Feature\Customers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DualRoleDebtAdjustmentKiotStyleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\CheckPermission::class);
    }

    public function test_customer_screen_adjustment_sets_dual_role_display_debt_not_raw_receivable(): void
    {
        $user = $this->user();
        $partner = $this->dualRolePartner();

        $this->assertSame(50_100_000.0, PartnerDebtDisplayBalance::customerScreen($partner));

        $this->actingAs($user)
            ->from('/customers')
            ->post("/customers/{$partner->id}/debt-adjust", [
                'amount' => 0,
                'note' => 'Set customer screen to zero',
                'date' => '2026-07-07 14:01:00',
            ])
            ->assertRedirect('/customers')
            ->assertSessionHasNoErrors();

        $partner->refresh();

        $this->assertSame(-2_700_000.0, (float) $partner->debt_amount);
        $this->assertSame(-2_700_000.0, (float) $partner->supplier_debt_amount);
        $this->assertSame(0.0, PartnerDebtDisplayBalance::customerScreen($partner));
        $this->assertSame(0.0, PartnerDebtDisplayBalance::supplierScreen($partner));

        $cashFlow = CashFlow::query()
            ->where('target_id', $partner->id)
            ->where('reference_type', 'DebtAdjustment')
            ->latest('id')
            ->first();

        $this->assertNotNull($cashFlow);
        $this->assertSame('receipt', $cashFlow->type);
        $this->assertSame(50_100_000.0, (float) $cashFlow->amount);

        $ledger = CustomerDebt::query()
            ->where('customer_id', $partner->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame(-50_100_000.0, (float) $ledger->amount);
        $this->assertSame(-2_700_000.0, (float) $ledger->debt_total);
        $this->assertSame($cashFlow->code, $ledger->ref_code);
    }

    public function test_supplier_screen_adjustment_sets_dual_role_display_debt_not_raw_payable(): void
    {
        $user = $this->user();
        $partner = $this->dualRolePartner();

        $this->assertSame(-50_100_000.0, PartnerDebtDisplayBalance::supplierScreen($partner));

        $this->actingAs($user)
            ->postJson("/api/suppliers/{$partner->id}/adjust-debt", [
                'amount' => 0,
                'note' => 'Set supplier screen to zero',
                'type' => 'adjustment',
                'date' => '2026-07-07 14:05:00',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $partner->refresh();

        $this->assertSame(47_400_000.0, (float) $partner->debt_amount);
        $this->assertSame(47_400_000.0, (float) $partner->supplier_debt_amount);
        $this->assertSame(0.0, PartnerDebtDisplayBalance::customerScreen($partner));
        $this->assertSame(0.0, PartnerDebtDisplayBalance::supplierScreen($partner));

        $tx = SupplierDebtTransaction::query()
            ->where('supplier_id', $partner->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($tx);
        $this->assertSame('adjustment', $tx->type);
        $this->assertSame(50_100_000.0, (float) $tx->amount);
        $this->assertSame(47_400_000.0, (float) $tx->debt_remain);
    }

    private function dualRolePartner(): Customer
    {
        return Customer::create([
            'code' => 'NCC177950763826-' . uniqid(),
            'name' => 'Anh Thanh Thien Phu',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 47_400_000,
            'supplier_debt_amount' => -2_700_000,
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Dual Role Debt Adjustment Tester',
            'email' => 'dual-role-adjustment-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }
}
