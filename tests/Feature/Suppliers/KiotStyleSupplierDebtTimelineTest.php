<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use App\Services\PartnerDebtLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STEP 10 — KiotViet-style supplier debt timeline + click-to-detail.
 */
class KiotStyleSupplierDebtTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin KSS ' . uniqid(),
            'email'    => 'admin-kss-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function supplier(string $code = 'KSS'): Customer
    {
        return Customer::create([
            'code'                 => $code . '-' . uniqid(),
            'name'                 => 'NCC KiotStyle ' . $code,
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    // ── Real phiếu chi preferred; click endpoint opens the cashflow ──
    public function test_purchase_with_real_payment_voucher_is_clickable(): void
    {
        $sup = $this->supplier('PAY');
        $base = Carbon::now()->subDays(2);
        $pn = Purchase::create([
            'code' => 'PN-KSS-001', 'supplier_id' => $sup->id, 'total_amount' => 5_000_000,
            'paid_amount' => 5_000_000, 'status' => 'completed', 'purchase_date' => $base,
        ]);
        $cf = CashFlow::create([
            'code' => 'PCPN-KSS-001', 'type' => 'payment', 'amount' => 5_000_000, 'time' => $base->copy()->addMinutes(5),
            'target_type' => 'Nhà cung cấp', 'target_id' => $sup->id,
            'reference_type' => 'Purchase', 'reference_code' => 'PN-KSS-001', 'status' => 'completed',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($sup);
        $payment = collect($ledger['entries'])->firstWhere('type', 'payment');
        $this->assertNotNull($payment);
        $this->assertSame('PCPN-KSS-001', $payment['code'], 'real phiếu chi preferred over virtual TTNH');

        // Click endpoint opens the purchase
        $res = $this->actingAs($this->admin)->getJson("/api/suppliers/{$sup->id}/debt-voucher-detail?code=PN-KSS-001");
        $res->assertOk()->assertJson(['success' => true, 'type' => 'purchase']);

        // Click endpoint opens the cashflow
        $res2 = $this->actingAs($this->admin)->getJson("/api/suppliers/{$sup->id}/debt-voucher-detail?code=PCPN-KSS-001");
        $res2->assertOk()->assertJson(['success' => true, 'type' => 'cashflow']);
    }

    // ── Legacy paid purchase without cashflow → TTNH fallback, flagged + not clickable ──
    public function test_legacy_paid_purchase_uses_fallback(): void
    {
        $sup = $this->supplier('FB');
        $base = Carbon::now()->subDays(2);
        Purchase::create([
            'code' => 'PN-KSS-002', 'supplier_id' => $sup->id, 'total_amount' => 3_000_000,
            'paid_amount' => 1_000_000, 'status' => 'completed', 'purchase_date' => $base,
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildSupplierPayableLedger($sup);
        $fallback = collect($ledger['entries'])->firstWhere('source', 'legacy_purchase_paid_amount');
        $this->assertNotNull($fallback);
        $this->assertTrue($fallback['is_virtual_fallback']);
        $this->assertStringStartsWith('TTNH', $fallback['code']);

        // Virtual code → 404 with clear message
        $res = $this->actingAs($this->admin)->getJson("/api/suppliers/{$sup->id}/debt-voucher-detail?code={$fallback['code']}");
        $res->assertStatus(404);
        $this->assertStringContainsString('tạm tính', mb_strtolower($res->json('message')));
    }

    // ── Voucher detail rejects a code from another supplier ──
    public function test_voucher_detail_rejects_foreign_document(): void
    {
        $a = $this->supplier('A');
        $b = $this->supplier('B');
        Purchase::create([
            'code' => 'PN-KSS-OWN', 'supplier_id' => $a->id, 'total_amount' => 1_000_000,
            'paid_amount' => 0, 'status' => 'completed', 'purchase_date' => Carbon::now(),
        ]);

        $res = $this->actingAs($this->admin)->getJson("/api/suppliers/{$b->id}/debt-voucher-detail?code=PN-KSS-OWN");
        $res->assertStatus(404);
    }
}
