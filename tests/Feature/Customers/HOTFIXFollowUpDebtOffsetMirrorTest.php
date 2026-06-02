<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\Purchase;
use App\Models\User;
use App\Services\PartnerDebtLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX FOLLOW-UP — KiotViet display contract for DebtOffset (CB/HCB).
 *
 * Reference: production "Long pin" partner shows CB000306 on BOTH the
 * supplier screen and the customer screen with mirrored signs:
 *   Supplier: CB amount -10,000,000 → running 6,845,000
 *   Customer: CB amount +10,000,000 → running -6,845,000
 *
 * Pre-fix behaviour: CB was emitted only on the customer-receivable
 * ledger as customer_effect = -amount and invisible on the supplier
 * screen. After this fix, CB is emitted on the supplier-payable ledger
 * as supplier_effect = -amount; the existing mirror in
 * buildCustomerNetLedger inverts it to customer_effect = +amount.
 */
class HOTFIXFollowUpDebtOffsetMirrorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin Mirror ' . uniqid(),
            'email'    => 'admin-mirror-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function dualRolePartner(string $code = 'DUAL'): Customer
    {
        return Customer::create([
            'code'                 => $code . '-' . uniqid(),
            'name'                 => 'Long pin Mirror ' . $code,
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => true,
            'is_supplier'          => true,
        ]);
    }

    // ── Test 1 — Active CB appears on supplier ledger with negative supplier_effect ──
    public function test_active_cb_appears_on_supplier_ledger_with_negative_effect(): void
    {
        $partner = $this->dualRolePartner('CBSUP');
        $base = Carbon::now()->subDays(5);

        Purchase::create([
            'code'          => 'PN-MIRROR-001',
            'supplier_id'   => $partner->id,
            'total_amount'  => 16_845_000,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => $base->copy(),
        ]);

        DebtOffset::create([
            'code'        => 'CB-MIRROR-001',
            'customer_id' => $partner->id,
            'amount'      => 10_000_000,
            'status'      => 'active',
        ]);

        // Anchor CB time after the purchase so the running balance matches
        // the KiotViet screenshot (purchase first, then CB reduces payable).
        DebtOffset::where('code', 'CB-MIRROR-001')->update([
            'created_at' => $base->copy()->addHours(1),
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($partner);
        $cb = collect($ledger['entries'])->firstWhere('code', 'CB-MIRROR-001');

        $this->assertNotNull($cb, 'CB row must appear on supplier ledger');
        $this->assertEquals(-10_000_000.0, (float) $cb['supplier_effect']);
        $this->assertSame('Điều chỉnh', $cb['type_label']);
        $this->assertSame('Điều chỉnh', $cb['badge_label']);
        $this->assertSame('debt_offset', $cb['source']);
        $this->assertTrue($cb['affects_debt_balance']);
        $this->assertEquals(6_845_000.0, (float) $cb['balance'],
            'Supplier running balance after CB = 16,845,000 - 10,000,000');
        $this->assertEquals(6_845_000.0, (float) $ledger['closing_balance']);
    }

    // ── Test 2 — Customer-net view mirrors CB to positive customer_effect ──
    public function test_customer_net_view_mirrors_cb_to_positive_effect(): void
    {
        $partner = $this->dualRolePartner('CBCUST');
        $base = Carbon::now()->subDays(5);

        Purchase::create([
            'code'          => 'PN-MIRROR-002',
            'supplier_id'   => $partner->id,
            'total_amount'  => 16_845_000,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => $base->copy(),
        ]);

        DebtOffset::create([
            'code'        => 'CB-MIRROR-002',
            'customer_id' => $partner->id,
            'amount'      => 10_000_000,
            'status'      => 'active',
        ]);
        DebtOffset::where('code', 'CB-MIRROR-002')->update([
            'created_at' => $base->copy()->addHours(1),
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($partner);
        $cbRows = collect($ledger['entries'])->where('code', 'CB-MIRROR-002')->values();

        $this->assertCount(1, $cbRows,
            'CB must appear exactly once on customer-net view (no double count from customer-side emission)');
        $cb = $cbRows->first();
        $this->assertEquals(+10_000_000.0, (float) $cb['customer_effect'],
            'Mirror: customer_effect = -1 * supplier_effect = +10M');
        $this->assertEquals(-6_845_000.0, (float) $cb['ledger_running_balance'],
            'Ledger running balance after CB = -16,845,000 + 10,000,000');
        $this->assertEquals(0.0, (float) $cb['balance'],
            'Display running balance includes read-only opening so final timeline matches current net debt');
        $this->assertSame('supplier_ledger_mirror', $cb['source']);
    }

    // ── Test 3 — Cancelled CB still appears, plus HCB reversal ──
    public function test_cancelled_cb_emits_hcb_reversal(): void
    {
        $partner = $this->dualRolePartner('CBCANC');
        $base = Carbon::now()->subDays(5);

        Purchase::create([
            'code'          => 'PN-MIRROR-003',
            'supplier_id'   => $partner->id,
            'total_amount'  => 16_845_000,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => $base->copy(),
        ]);

        DebtOffset::create([
            'code'           => 'CB-MIRROR-003',
            'customer_id'    => $partner->id,
            'amount'         => 10_000_000,
            'status'         => 'cancelled',
            'cancelled_at'   => $base->copy()->addHours(3),
        ]);
        DebtOffset::where('code', 'CB-MIRROR-003')->update([
            'created_at' => $base->copy()->addHours(1),
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($partner);
        $entries = collect($ledger['entries']);

        $cb = $entries->firstWhere('code', 'CB-MIRROR-003');
        $this->assertNotNull($cb);
        $this->assertEquals(-10_000_000.0, (float) $cb['supplier_effect']);

        $hcb = $entries->first(fn ($e) => str_starts_with((string) ($e['code'] ?? ''), 'HCB'));
        $this->assertNotNull($hcb, 'Cancelled CB must emit an HCB reversal');
        $this->assertEquals(+10_000_000.0, (float) $hcb['supplier_effect']);
        $this->assertSame('Hủy điều chỉnh', $hcb['type_label']);

        // Net effect after both CB and HCB: balance returns to pre-CB state.
        $this->assertEquals(16_845_000.0, (float) $ledger['closing_balance'],
            'After CB (-10M) and HCB (+10M), payable returns to original 16,845,000');
    }

    // ── Test 4 — Pure customer (not dual-role) keeps customer-side CB emission ──
    //   This protects backward compat: a pure-customer partner without supplier role
    //   should still see CB on their customer ledger (where it has always been).
    public function test_pure_customer_still_emits_cb_on_customer_side(): void
    {
        $customer = Customer::create([
            'code'        => 'PURE-' . uniqid(),
            'name'        => 'Pure customer',
            'debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        DebtOffset::create([
            'code'        => 'CB-PURE-001',
            'customer_id' => $customer->id,
            'amount'      => 5_000_000,
            'status'      => 'active',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerReceivableLedger($customer);
        $cb = collect($ledger['entries'])->firstWhere('code', 'CB-PURE-001');

        $this->assertNotNull($cb, 'Pure customer still gets CB on their own ledger');
        $this->assertEquals(-5_000_000.0, (float) $cb['customer_effect']);
        $this->assertSame('Điều chỉnh', $cb['display_type'],
            'display_type aligned with KiotViet (Điều chỉnh, not Cấn bằng công nợ)');
    }

    // ── Test 5 — Payment label "Thanh toán" (not "Thanh toán NCC") ──
    public function test_payment_label_matches_kiotviet(): void
    {
        $partner = $this->dualRolePartner('LBL');
        $base = Carbon::now()->subDays(5);

        Purchase::create([
            'code'          => 'PN-LBL-001',
            'supplier_id'   => $partner->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $base,
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($partner);
        $payment = collect($ledger['entries'])->firstWhere('type', 'payment');

        $this->assertNotNull($payment);
        $this->assertSame('Thanh toán', $payment['badge_label'],
            'KiotViet column "Loại" shows "Thanh toán" — not "Thanh toán NCC"');
    }
}
