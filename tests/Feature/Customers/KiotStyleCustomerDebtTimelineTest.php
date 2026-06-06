<?php

namespace Tests\Feature\Customers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PartnerDebtLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STEP 10 — KiotViet-style debt timeline: prefer the REAL phiếu thu
 * voucher for an invoice payment line, fall back to a synthesised TTHD
 * line only when no real receipt exists.
 *
 * Balance semantics are intentionally unchanged — these tests pin the
 * DISPLAY IDENTITY (code / is_real_voucher / is_virtual_fallback /
 * click target), not the running balance.
 */
class KiotStyleCustomerDebtTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin KS ' . uniqid(),
            'email'    => 'admin-ks-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function customer(string $code = 'KS'): Customer
    {
        return Customer::create([
            'code'        => $code . '-' . uniqid(),
            'name'        => 'KH KiotStyle ' . $code,
            'debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
    }

    private function invoice(Customer $c, string $code, int $total, int $paid, ?Carbon $at = null): Invoice
    {
        $at = $at ?? Carbon::now()->subDays(2);
        $inv = Invoice::create([
            'code'          => $code,
            'customer_id'   => $c->id,
            'total'         => $total,
            'customer_paid' => $paid,
            'status'        => 'Hoàn thành',
        ]);
        $inv->created_at = $at;
        $inv->save();
        return $inv;
    }

    private function receipt(Customer $c, string $code, string $invoiceCode, int $amount, ?Carbon $at = null): CashFlow
    {
        $at = $at ?? Carbon::now()->subDays(2);
        return CashFlow::create([
            'code'           => $code,
            'type'           => 'receipt',
            'amount'         => $amount,
            'time'           => $at,
            'category'       => 'Thu tiền khách hàng',
            'target_type'    => 'Khách hàng',
            'target_id'      => $c->id,
            'reference_type' => 'Invoice',
            'reference_code' => $invoiceCode,
            'status'         => 'completed',
        ]);
    }

    private function paymentLine(array $ledger): ?array
    {
        return collect($ledger['entries'])->firstWhere('event_kind', 'invoice_payment');
    }

    // ── Test 1 — Paid invoice WITH a real receipt → payment line shows the real PT code ──
    public function test_paid_invoice_with_real_receipt_uses_real_voucher(): void
    {
        $c = $this->customer('REAL');
        $this->invoice($c, 'HD-KS-001', 12_000_000, 12_000_000);
        $cf = $this->receipt($c, 'PT-KS-001', 'HD-KS-001', 12_000_000);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($c);
        $entries = collect($ledger['entries']);

        $pay = $this->paymentLine($ledger);
        $this->assertNotNull($pay, 'invoice payment line must exist');
        $this->assertSame('PT-KS-001', $pay['code'], 'payment line shows the REAL phiếu thu code, not synthesised TTHD');
        $this->assertTrue($pay['is_real_voucher']);
        $this->assertFalse($pay['is_virtual_fallback']);
        $this->assertSame('cash_flow', $pay['detail_modal_type']);
        $this->assertEquals($cf->id, $pay['detail_reference_id']);

        // No synthesised TTHD duplicate, and the real receipt is not also
        // shown as a separate standalone "Khách thanh toán" line.
        $this->assertNull($entries->firstWhere('code', 'TTHD-KS-001'),
            'no synthesised TTHD line when a real receipt exists');
        $this->assertCount(1, $entries->where('event_kind', 'invoice_payment'),
            'exactly one payment line');
    }

    // ── Test 2 — Paid invoice WITHOUT a real receipt → TTHD fallback, badged ──
    public function test_paid_invoice_without_receipt_uses_fallback(): void
    {
        $c = $this->customer('FB');
        $this->invoice($c, 'HD-KS-002', 5_000_000, 5_000_000);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($c);
        $pay = $this->paymentLine($ledger);

        $this->assertNotNull($pay);
        $this->assertSame('TTHD-KS-002', $pay['code'], 'synthesised TTHD fallback when no real receipt');
        $this->assertFalse($pay['is_real_voucher']);
        $this->assertTrue($pay['is_virtual_fallback']);
        $this->assertStringContainsString('chưa có phiếu thu thật', (string) $pay['badge_title']);
    }

    // ── Test 3 — Partial paid invoice keeps the correct amount ──
    public function test_partial_paid_amount_is_correct(): void
    {
        $c = $this->customer('PART');
        $this->invoice($c, 'HD-KS-003', 10_000_000, 3_000_000);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($c);
        $pay = $this->paymentLine($ledger);

        $this->assertNotNull($pay);
        $this->assertEquals(3_000_000, (float) $pay['amount']);
        $this->assertEquals(-3_000_000, (float) $pay['display_effect']);
    }

    // ── Test 4 — Cancelled receipt is ignored → falls back to TTHD ──
    public function test_cancelled_receipt_is_ignored(): void
    {
        $c = $this->customer('CANC');
        $this->invoice($c, 'HD-KS-004', 4_000_000, 4_000_000);
        $cf = $this->receipt($c, 'PT-KS-004', 'HD-KS-004', 4_000_000);
        $cf->update(['status' => 'cancelled']);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($c);
        $pay = $this->paymentLine($ledger);

        $this->assertNotNull($pay);
        $this->assertSame('TTHD-KS-004', $pay['code'],
            'cancelled receipt must be ignored; fall back to TTHD');
        $this->assertTrue($pay['is_virtual_fallback']);
    }

    // ── Test 5 — display_sequence orders payment after invoice in ledger sense ──
    public function test_sequence_metadata_present(): void
    {
        $c = $this->customer('SEQ');
        $this->invoice($c, 'HD-KS-005', 1_000_000, 1_000_000);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($c);
        $entries = collect($ledger['entries']);

        $inv = $entries->firstWhere('event_kind', 'customer_sale');
        $pay = $entries->firstWhere('event_kind', 'invoice_payment');

        $this->assertEquals(10, $inv['display_sequence']);
        $this->assertEquals(20, $pay['display_sequence'],
            'payment has higher display_sequence so frontend can place it above the invoice at the same timestamp');
    }

    // ── Test 6 — API endpoint exposes the new metadata fields ──
    public function test_api_exposes_voucher_metadata(): void
    {
        $c = $this->customer('API');
        $this->invoice($c, 'HD-KS-006', 2_000_000, 2_000_000);
        $this->receipt($c, 'PT-KS-006', 'HD-KS-006', 2_000_000);

        $res = $this->actingAs($this->admin)->getJson("/customers/{$c->id}/debt-history");
        $res->assertOk();
        $pay = collect($res->json('entries'))->firstWhere('event_kind', 'invoice_payment');

        $this->assertNotNull($pay);
        $this->assertSame('PT-KS-006', $pay['code']);
        $this->assertTrue($pay['is_real_voucher']);
        $this->assertArrayHasKey('detail_modal_type', $pay);
    }
}
