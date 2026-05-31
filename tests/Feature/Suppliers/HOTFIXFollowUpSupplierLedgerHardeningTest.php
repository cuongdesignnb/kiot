<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\PartnerDebtLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX FOLLOW-UP — Two payment-double-count failure modes in
 * PartnerDebtLedgerService::buildSupplierPayableLedger() that the
 * earlier `78ff482` round did not cover:
 *
 *   A) CashFlow with status = NULL was excluded by
 *      `where('status', '!=', 'cancelled')`, so the service then
 *      synthesised a virtual TTNH payment from Purchase.paid_amount
 *      and counted the payment twice.
 *
 *   B) Standalone SupplierDebtTransaction payments were globally
 *      gated by `$purchasePaidTotal <= 0` — meaning ANY non-zero
 *      paid_amount on ANY unrelated purchase silently marked every
 *      standalone payment as "Đã hạch toán" and stripped its effect
 *      from the supplier ledger.
 */
class HOTFIXFollowUpSupplierLedgerHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin FollowUp ' . uniqid(),
            'email'    => 'admin-followup-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function supplier(string $code = 'NCC-FU', int $payable = 0): Customer
    {
        return Customer::create([
            'code'                 => $code . '-' . uniqid(),
            'name'                 => 'NCC FollowUp ' . $code,
            'debt_amount'          => 0,
            'supplier_debt_amount' => $payable,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    // ── A) Non-default / NULL-ish CashFlow.status must still count as a real payment ──
    //
    // The production scenario is a legacy CashFlow row whose `status` is
    // NULL (left over from a migration that added the column without a
    // backfill). The local schema marks `status` NOT NULL with default
    // 'active', so we exercise the same scope by inserting an unusual but
    // valid non-cancelled status. The scope (`whereNull → orWhereNotIn`)
    // protects both paths.
    public function test_non_cancelled_cashflow_prevents_virtual_ttnh_double_count(): void
    {
        $supplier = $this->supplier('PNNC', 500_000);
        $baseTime = Carbon::now()->subDays(2);

        Purchase::create([
            'code'          => 'PNNC001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        CashFlow::create([
            'code'           => 'PCPNNC001',
            'type'           => 'payment',
            'amount'         => 500_000,
            'time'           => $baseTime->copy()->addMinutes(5),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PNNC001',
            'status'         => 'pending', // non-default, non-cancelled
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $payments = collect($ledger['entries'])->where('type', 'payment')->values();

        $this->assertCount(1, $payments, 'Exactly one payment must be surfaced — the real cashflow, not also a virtual TTNH duplicate');
        $this->assertSame('PCPNNC001', $payments[0]['code']);
        $this->assertNotEquals('TTNHNC001', $payments[0]['code'],
            'No virtual TTNH may be synthesised when a real CashFlow exists, regardless of its non-cancelled status');

        // Closing balance must reflect only one debit (purchase 1M) and one
        // credit (payment 500k); not two credits.
        $this->assertEquals(500_000.0, (float) $ledger['closing_balance']);
    }

    // Confirms the cancelled-status guard still blocks a cancelled
    // CashFlow from suppressing the virtual TTNH (legacy fallback).
    public function test_cancelled_cashflow_does_not_block_legacy_purchase_paid_amount(): void
    {
        $supplier = $this->supplier('PNCAN', 500_000);
        $baseTime = Carbon::now()->subDays(2);

        Purchase::create([
            'code'          => 'PNCAN001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        CashFlow::create([
            'code'           => 'PCPNCAN001',
            'type'           => 'payment',
            'amount'         => 500_000,
            'time'           => $baseTime->copy()->addMinutes(5),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PNCAN001',
            'status'         => 'cancelled',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $payments = collect($ledger['entries'])->where('type', 'payment')->values();

        // The cancelled cashflow is correctly excluded, so the legacy
        // Purchase.paid_amount synthesises a TTNH payment. Net should
        // still be one payment, not two.
        $this->assertCount(1, $payments);
        $this->assertStringStartsWith('TTNH', (string) $payments[0]['code']);
    }

    // ── B) Standalone payment must still affect ledger when an unrelated purchase has paid_amount > 0 ──
    public function test_standalone_supplier_payment_still_affects_even_when_other_purchase_has_paid_amount(): void
    {
        $supplier = $this->supplier('PNPAID', 1_000_000);
        $baseTime = Carbon::now()->subDays(3);

        // An unrelated purchase that has paid_amount > 0 — the old gate
        // used $purchasePaidTotal > 0 to suppress all standalone payments.
        Purchase::create([
            'code'          => 'PNPAID001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 200_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        // Standalone payment recorded directly in SupplierDebtTransaction,
        // unrelated to PNPAID001 (no purchase reference in note, no matching
        // cashflow). Should still count as a real ledger-affecting payment.
        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code'        => 'PCPNSTANDALONE001',
            'type'        => 'payment',
            'amount'      => -300_000,
            'note'        => 'Thanh toán độc lập không liên quan PN khác',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $standalone = collect($ledger['entries'])
            ->first(fn ($e) => ($e['code'] ?? '') === 'PCPNSTANDALONE001');

        $this->assertNotNull($standalone, 'Standalone payment must appear in the ledger');
        $this->assertTrue($standalone['affects_debt_balance'],
            'Standalone payment must still affect debt balance even if another purchase has paid_amount > 0');
        $this->assertSame('Thanh toán', $standalone['badge_label'],
            'Badge "Thanh toán" (KiotViet display; was "Thanh toán NCC" pre-FOLLOW-UP)');
        $this->assertEquals(-300_000.0, (float) $standalone['supplier_effect']);
    }

    // ── A.2) Vietnamese cancelled status "Đã hủy" must be treated as cancelled ──
    public function test_vietnamese_cancelled_cashflow_does_not_block_legacy_purchase_paid_amount(): void
    {
        $supplier = $this->supplier('PNVICAN', 500_000);
        $baseTime = Carbon::now()->subDays(2);

        Purchase::create([
            'code'          => 'PNVICAN001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        // status='Đã hủy' is the canonical Vietnamese form some legacy
        // rows carry. scopeNotCancelledCashFlow + isCancelledStatus
        // must both treat it as cancelled.
        CashFlow::create([
            'code'           => 'PCPNVICAN001',
            'type'           => 'payment',
            'amount'         => 500_000,
            'time'           => $baseTime->copy()->addMinutes(5),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PNVICAN001',
            'status'         => 'Đã hủy',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $payments = collect($ledger['entries'])->where('type', 'payment')->values();

        $codes = $payments->pluck('code')->all();
        $this->assertNotContains('PCPNVICAN001', $codes,
            'Vietnamese-cancelled CashFlow must be excluded from payments');
        $this->assertContains('TTNHVICAN001', $codes,
            'Legacy Purchase.paid_amount must synthesise a TTNH fallback');
        $this->assertCount(1, $payments, 'Exactly one (legacy) payment surfaces');
    }

    // ── A.3) ASCII Vietnamese cancelled status "da huy" must also be treated as cancelled ──
    public function test_ascii_vietnamese_cancelled_cashflow_does_not_block_legacy_purchase_paid_amount(): void
    {
        $supplier = $this->supplier('PNASCIICAN', 500_000);
        $baseTime = Carbon::now()->subDays(2);

        Purchase::create([
            'code'          => 'PNASCIICAN001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        CashFlow::create([
            'code'           => 'PCPNASCIICAN001',
            'type'           => 'payment',
            'amount'         => 500_000,
            'time'           => $baseTime->copy()->addMinutes(5),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PNASCIICAN001',
            'status'         => 'da huy',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $codes = collect($ledger['entries'])->where('type', 'payment')->pluck('code')->all();

        $this->assertNotContains('PCPNASCIICAN001', $codes);
        $this->assertContains('TTNHASCIICAN001', $codes);
    }

    // ── A.4) Mixed-case + whitespace cancelled status normalised ──
    public function test_mixed_case_cancelled_cashflow_is_normalised(): void
    {
        $supplier = $this->supplier('PNMIXED', 500_000);
        $baseTime = Carbon::now()->subDays(2);

        Purchase::create([
            'code'          => 'PNMIXED001',
            'supplier_id'   => $supplier->id,
            'total_amount'  => 1_000_000,
            'paid_amount'   => 500_000,
            'status'        => 'completed',
            'purchase_date' => $baseTime->copy(),
        ]);

        CashFlow::create([
            'code'           => 'PCPNMIXED001',
            'type'           => 'payment',
            'amount'         => 500_000,
            'time'           => $baseTime->copy()->addMinutes(5),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PNMIXED001',
            'status'         => ' CANCELLED ', // padded + upper-case
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $codes = collect($ledger['entries'])->where('type', 'payment')->pluck('code')->all();

        $this->assertNotContains('PCPNMIXED001', $codes,
            'LOWER+TRIM must normalise mixed-case/whitespace cancelled status');
        $this->assertContains('TTNHMIXED001', $codes);
    }

    // ── C) Same standalone payment, but a real CashFlow with the same code already exists ──
    //    must NOT be duplicated (regression guard for the per-transaction guard).
    public function test_standalone_payment_with_matching_cashflow_does_not_duplicate(): void
    {
        $supplier = $this->supplier('PNDUP', 0);
        $baseTime = Carbon::now()->subDays(1);

        CashFlow::create([
            'code'           => 'PCPNDUP777',
            'type'           => 'payment',
            'amount'         => 250_000,
            'time'           => $baseTime->copy(),
            'target_type'    => 'Nhà cung cấp',
            'target_id'      => $supplier->id,
            'status'         => 'completed',
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code'        => 'PCPNDUP777',
            'type'        => 'payment',
            'amount'      => -250_000,
            'note'        => 'Cùng code với CashFlow đã có',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($supplier);
        $matches = collect($ledger['entries'])->where('code', 'PCPNDUP777');

        $this->assertCount(1, $matches,
            'A SupplierDebtTransaction sharing the cashflow code must NOT create a second ledger row');
    }
}
