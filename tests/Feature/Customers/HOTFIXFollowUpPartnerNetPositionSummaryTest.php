<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\User;
use App\Services\PartnerDebtLedgerService;
use App\Services\PartnerFinancialTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX FOLLOW-UP — pin the new canonical summary contract so the UI
 * and report stop conflating "vị thế ròng (display delta)" with "đã
 * cấn trừ (CB/HCB voucher recorded)".
 *
 * Required keys in `summary`:
 *   - customer_receivable_balance
 *   - supplier_payable_balance
 *   - partner_net_position
 *   - has_debt_offset_voucher
 *   - is_actual_offset
 *
 * Backward-compatible keys (net_debt_amount etc.) must stay present.
 */
class HOTFIXFollowUpPartnerNetPositionSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin FU Summary ' . uniqid(),
            'email'    => 'admin-fu-summary-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function partner(string $code, float $receivable, float $payable, bool $supplier = true): Customer
    {
        return Customer::create([
            'code'                 => $code . '-' . uniqid(),
            'name'                 => 'Partner FU ' . $code,
            'debt_amount'          => $receivable,
            'supplier_debt_amount' => $payable,
            'is_customer'          => true,
            'is_supplier'          => $supplier,
        ]);
    }

    // ── partner_net_position is a display delta when no CB exists ──
    public function test_summary_exposes_partner_net_position_without_offset_flag(): void
    {
        $partner = $this->partner('NETPOS', 47_400_000, 75_000_000);

        $result = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($partner);
        $summary = $result['summary'];

        $this->assertSame(47_400_000.0, (float) $summary['customer_receivable_balance']);
        $this->assertSame(75_000_000.0, (float) $summary['supplier_payable_balance']);
        $this->assertSame(-27_600_000.0, (float) $summary['partner_net_position']);
        $this->assertFalse($summary['has_debt_offset_voucher'],
            'No CB/HCB voucher exists → has_debt_offset_voucher must be false');
        $this->assertFalse($summary['is_actual_offset'],
            'Partner net position is a display delta, not an actual offset');

        // Backward-compatible keys still present
        $this->assertArrayHasKey('net_debt_amount', $summary);
        $this->assertSame((float) $summary['partner_net_position'], (float) $summary['net_debt_amount']);
    }

    // ── has_debt_offset_voucher flips to true when a real CB exists ──
    public function test_summary_flags_actual_debt_offset_voucher_when_present(): void
    {
        $partner = $this->partner('CBREAL', 10_000_000, 8_000_000);

        DebtOffset::create([
            'code'        => 'CB-FU-' . uniqid(),
            'customer_id' => $partner->id, // dual-role partner = same customer_id
            'amount'      => 5_000_000,
            'status'      => 'active',     // default + non-cancelled
        ]);

        $result = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($partner);
        $summary = $result['summary'];

        $this->assertTrue($summary['has_debt_offset_voucher'],
            'A real CB/HCB voucher must surface has_debt_offset_voucher = true');
        // Even with a CB voucher, partner_net_position is still a display
        // delta. The flag exists so the UI can refer to "đối trừ thật"
        // without conflating it with the net display number.
        $this->assertFalse($summary['is_actual_offset']);
    }

    // ── PartnerFinancialTimelineService also publishes the new keys ──
    public function test_partner_financial_timeline_publishes_same_canonical_keys(): void
    {
        $partner = $this->partner('TIMELINE', 1_000_000, 600_000);

        $result = app(PartnerFinancialTimelineService::class)->buildForCustomer($partner);
        $summary = $result['summary'];

        $this->assertSame(1_000_000.0, (float) $summary['customer_receivable_balance']);
        $this->assertSame(600_000.0,   (float) $summary['supplier_payable_balance']);
        $this->assertSame(400_000.0,   (float) $summary['partner_net_position']);
        $this->assertFalse($summary['has_debt_offset_voucher']);
        $this->assertFalse($summary['is_actual_offset']);
    }
}
