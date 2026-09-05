<?php

namespace Tests\Feature\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\PurchasePayableService;
use App\Services\DebtPartnerInspectionService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseExternalCostDebtTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bigben_canonical_and_legacy_inspection_exclude_separately_paid_shipping(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $before = $expense->getRawOriginal();
        $canonical = app(CanonicalPartnerDebtService::class)->calculate($partner);
        $this->assertSame(780000.0, (float) $canonical['supplier_payable']);
        $this->assertFalse($canonical['has_mismatch']);
        $customer = app(CustomerDebtDocumentTimelineService::class)->build($partner);
        $supplier = app(SupplierDebtDocumentTimelineService::class)->build($partner);
        $this->assertSame(-780000.0, (float) $customer['raw_final_balance']);
        $this->assertSame(780000.0, (float) $supplier['raw_final_balance']);
        $this->assertSame($customer['source_identity_hash'], $supplier['source_identity_hash']);
        $events = app(CanonicalPartnerDebtEventService::class)->build($partner);
        $this->assertCount(3, $events);
        $this->assertFalse($events->contains('source_code', $expense->code));
        $inspection = app(DebtPartnerInspectionService::class)->inspect($partner, true, true);
        $this->assertSame(780000.0, (float) $inspection['computed']['supplier_final_balance']);
        $this->assertFalse($inspection['computed']['supplier_ledger_mismatch']);
        $raw = collect($inspection['raw']['purchases'])->firstWhere('id', $purchase->id);
        $this->assertSame(41000.0, (float) $raw['external_cost_amount']);
        $this->assertSame(1630000.0, (float) $raw['cashflow_total']);
        $this->assertSame($before, $expense->fresh()->getRawOriginal());
        $this->assertSame(0.0, (float) $purchase->fresh()->debt_amount);
    }

    public function test_next_1750000_purchase_commits_without_changing_historical_debt_or_shipping(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $before = $expense->getRawOriginal();
        $product = $this->product();
        $this->actingAs($this->actor());
        $payload = [
            'code' => 'PN-BIGBEN-NEXT', 'supplier_id' => $partner->id,
            'discount' => 0, 'paid_amount' => 0, 'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(), 'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'price' => 350000, 'discount' => 0]],
        ];
        $headers = ['Idempotency-Key' => 'bigben-next-purchase-qa'];
        $response = $this->post('/purchases', $payload, $headers);
        $response->assertRedirect(route('purchases.index'))->assertSessionMissing('error');
        $this->assertSame(2530000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
        $this->assertSame(0.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame($before, $expense->fresh()->getRawOriginal());
        $this->post('/purchases', $payload, $headers)->assertSessionMissing('error');
        $this->assertSame(1, Purchase::where('code', 'PN-BIGBEN-NEXT')->count());
        $this->assertSame(2530000.0, (float) $partner->fresh()->supplier_debt_amount);
    }

    public function test_new_purchase_costs_payable_to_supplier_are_not_removed_by_their_name(): void
    {
        [$partner] = $this->bigben();
        $product = $this->product();
        $response = $this->actingAs($this->actor())->post('/purchases', [
            'code' => 'PN-SUPPLIER-SHIP', 'supplier_id' => $partner->id,
            'discount' => 0, 'paid_amount' => 0, 'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(), 'payment_method' => 'cash',
            'other_costs' => [['name' => 'Ship hàng', 'amount' => 41000]],
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'price' => 350000, 'discount' => 0]],
        ]);
        $response->assertRedirect(route('purchases.index'))->assertSessionMissing('error');
        $this->assertSame(2571000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertSame(1791000.0, (float) Purchase::where('code', 'PN-SUPPLIER-SHIP')->firstOrFail()->debt_amount);
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
    }

    public function test_invalid_cost_edit_rolls_back_the_entire_purchase(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $product = $this->product();
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_code' => $product->sku, 'product_name' => $product->name,
            'quantity' => 1, 'price' => 1630000, 'discount' => 0, 'subtotal' => 1630000]);
        $before = $purchase->getRawOriginal();
        $response = $this->actingAs($this->actor())->put('/purchases/'.$purchase->id, [
            'supplier_id' => $partner->id, 'discount' => 0, 'paid_amount' => 1600000,
            'purchase_date' => $purchase->purchase_date->toDateTimeString(), 'payment_method' => 'cash',
            'other_costs' => [], 'note' => 'Must roll back',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame($before, $purchase->fresh()->getRawOriginal());
        $this->assertSame(780000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertSame('active', $expense->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_payment_edit_never_merges_or_cancels_the_external_expense_voucher(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $before = $expense->getRawOriginal();
        $product = $this->product();
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 1, 'price' => 1630000, 'discount' => 0, 'subtotal' => 1630000]);
        $response = $this->actingAs($this->actor())->put('/purchases/'.$purchase->id, [
            'supplier_id' => $partner->id, 'discount' => 0, 'paid_amount' => 1600000,
            'status' => 'completed', 'purchase_date' => $purchase->purchase_date->toDateTimeString(),
            'payment_method' => 'cash', 'other_costs' => [['name' => 'Ship hàng', 'amount' => 41000]],
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1630000, 'discount' => 0]],
        ]);
        $response->assertSessionMissing('error');
        $this->assertSame(30000.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame(810000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertSame($before, $expense->fresh()->getRawOriginal());
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
    }

    public function test_mixed_supplier_and_external_fees_only_exclude_evidenced_external_amount(): void
    {
        [$partner, $purchase] = $this->bigben();
        $purchase->update(['other_costs_total' => 81000, 'debt_amount' => 40000]);
        $partner->update(['supplier_debt_amount' => 820000]);
        $this->assertSame(1670000.0, app(PurchasePayableService::class)->amount($purchase->fresh()));
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
    }

    public function test_note_or_payment_only_edit_preserves_legacy_encoded_costs(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $product = $this->product();
        PurchaseItem::create(['purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_code' => $product->sku, 'product_name' => $product->name,
            'quantity' => 1, 'price' => 1630000, 'discount' => 0, 'subtotal' => 1630000]);
        $response = $this->actingAs($this->actor())->put('/purchases/'.$purchase->id, [
            'supplier_id' => $partner->id, 'discount' => 0, 'paid_amount' => 1630000,
            'purchase_date' => $purchase->purchase_date->toDateTimeString(),
            'payment_method' => 'cash', 'note' => 'Updated note only',
        ]);
        $response->assertSessionMissing('error');
        $purchase->refresh();
        $this->assertSame('Updated note only', $purchase->note);
        $this->assertSame(41000.0, (float) $purchase->other_costs_total);
        $this->assertSame(0.0, (float) $purchase->debt_amount);
        $this->assertSame(780000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertSame('active', $expense->fresh()->status);
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
    }

    public function test_show_and_edit_expose_external_cost_without_rewriting_legacy_json(): void
    {
        $this->withoutVite();
        [, $purchase] = $this->bigben();
        $before = $purchase->getRawOriginal();
        $this->actingAs($this->actor());
        foreach (['/purchases/'.$purchase->id, '/purchases/'.$purchase->id.'/edit'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
                ->where('purchase.external_cost_amount', 41000)
                ->where('purchase.other_costs.0.name', 'Ship hàng')
                ->where('purchase.other_costs.0.amount', 41000));
        }
        $this->assertSame($before, $purchase->fresh()->getRawOriginal());
    }

    public function test_cancellation_retains_historical_payee_evidence_and_reverses_exact_obligation(): void
    {
        [$partner, $purchase, $expense] = $this->bigben();
        $purchase->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $expense->update(['status' => 'cancelled']);
        $expense->delete();
        $events = app(CanonicalPartnerDebtEventService::class)->build($partner->fresh());
        $original = $events->firstWhere('source_code', $purchase->code);
        $reversal = $events->firstWhere('event_kind', 'purchase_cancel_reversal');
        $this->assertSame(1630000.0, (float) $original['supplier_delta']);
        $this->assertSame(-1630000.0, (float) $reversal['supplier_delta']);
        $this->assertSame($original['event_identity'], $reversal['reversal_of_event_identity']);
        $this->assertSame(780000.0, (float) $events->sum('supplier_delta'));
    }

    public function test_cannot_silently_remove_costs_backed_by_an_external_voucher(): void
    {
        [, $purchase] = $this->bigben();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('external expense evidence exceeds purchase costs');
        app(PurchasePayableService::class)->forAmounts($purchase, 1630000, 0, 0);
    }

    public function test_cancelling_bigben_purchase_through_controller_keeps_remaining_debt_aligned(): void
    {
        [$partner, $purchase] = $this->bigben();
        $response = $this->actingAs($this->actor())->delete('/purchases/'.$purchase->id, [
            'cancel_reason' => 'QA cancel external shipping purchase',
        ]);
        $response->assertSessionMissing('error');
        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(780000.0, (float) $partner->fresh()->supplier_debt_amount);
        $this->assertFalse(app(CanonicalPartnerDebtService::class)->calculate($partner->fresh())['has_mismatch']);
    }

    public function test_customer_and_supplier_excel_use_the_corrected_canonical_balance(): void
    {
        [$partner] = $this->bigben();
        $customer = app(CustomerDebtDocumentTimelineService::class)->build($partner);
        $supplier = app(SupplierDebtDocumentTimelineService::class)->build($partner);
        $sheets = [
            (new \App\Services\Exports\CustomerDebtExcelExportService(
                $partner, collect($customer['entries'])->all(), null, null, false, [],
            ))->build(),
            (new \App\Services\Exports\SupplierDebtExcelExportService(
                collect($supplier['entries'])->all(), $partner, null, null, false, [],
            ))->build(),
        ];
        foreach ($sheets as $index => $sheet) {
            $rows = $sheet->getActiveSheet()->toArray(null, true, false, true);
            $summary = collect($rows)->first(fn ($row) => ($row['H'] ?? '') === 'Nợ cuối kỳ:');
            $this->assertNotNull($summary);
            // Workbook debit/credit columns have the opposite sign to the
            // supplier-oriented screen, as in the existing export contract.
            $balance = (float) ($summary['J'] ?? 0) - (float) ($summary['K'] ?? 0);
            $this->assertSame($index === 0 ? -780000.0 : 780000.0, $balance);
        }
    }

    private function bigben(): array
    {
        $partner = Customer::create(['code' => 'NCC-BIGBEN-QA', 'name' => 'BIGBEN QA',
            'is_customer' => true, 'is_supplier' => true, 'status' => 'active',
            'debt_amount' => 0, 'supplier_debt_amount' => 780000]);
        $purchase = Purchase::create(['code' => 'PN20260330173711', 'supplier_id' => $partner->id,
            'status' => 'completed', 'total_amount' => 1630000, 'discount' => 0,
            'paid_amount' => 1630000, 'debt_amount' => 0, 'other_costs_total' => 41000,
            'other_costs' => json_encode(json_encode([['name' => 'Ship hàng', 'amount' => 41000]])),
            'purchase_date' => '2026-03-30 17:37:00', 'created_at' => '2026-03-30 17:41:07']);
        Purchase::create(['code' => 'PN20260807093058', 'supplier_id' => $partner->id,
            'status' => 'completed', 'total_amount' => 780000, 'discount' => 0,
            'paid_amount' => 0, 'debt_amount' => 780000, 'other_costs_total' => 0,
            'purchase_date' => '2026-08-07 09:30:00']);
        $payment = ['type' => 'payment', 'amount' => 1630000, 'status' => 'active',
            'target_type' => 'Nhà cung cấp', 'target_id' => null, 'reference_type' => 'Purchase',
            'reference_code' => $purchase->code, 'time' => '2026-03-30 17:41:07'];
        CashFlow::create($payment + ['code' => 'PC20260330174107']);
        $expense = CashFlow::create(array_merge($payment, ['code' => 'PC2026033017410713',
            'target_type' => 'Chi phí', 'amount' => 41000, 'description' => 'Ship hàng cho phiếu '.$purchase->code]));

        return [$partner->fresh(), $purchase->fresh(), $expense->fresh()];
    }

    private function actor(): User
    {
        return User::create(['name' => 'Expense QA', 'email' => uniqid().'@test.local',
            'password' => bcrypt('password'), 'role_id' => null]);
    }

    private function product(): Product
    {
        return Product::create(['sku' => 'SP-EXPENSE-'.uniqid(), 'name' => 'Expense QA product',
            'cost_price' => 350000, 'retail_price' => 400000, 'stock_quantity' => 10,
            'inventory_total_cost' => 3500000, 'has_serial' => false, 'is_active' => true]);
    }
}
