<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\SupplierDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtTimelineParityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Supplier Debt Parity Admin',
            'email' => 'supplier-debt-parity-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    public function test_generic_supplier_payments_do_not_double_count_purchase_paid_fallbacks(): void
    {
        $supplier = $this->supplier('NCC177466273054', 2_900_000);
        $base = Carbon::parse('2026-04-01 09:00:00');

        foreach ([1_000_000, 2_000_000, 1_500_000, 1_140_000, 1_200_000, 1_300_000, 1_500_000] as $index => $amount) {
            $this->purchase($supplier, 'PN-GEN-A-' . ($index + 1), $amount, $amount, $base->copy()->addDays($index));
        }

        $this->purchase($supplier, 'PN-GEN-B-1', 2_000_000, 2_000_000, $base->copy()->addDays(8));
        $this->genericPayment($supplier, 'PCPN260422188', 11_640_000, $base->copy()->addDays(21));

        $this->purchase($supplier, 'PN-GEN-C-1', 3_240_000, 3_240_000, $base->copy()->addDays(40));
        $this->genericPayment($supplier, 'PCPN260626688', 3_240_000, $base->copy()->addDays(86)->setTime(14, 6));

        $directA = $this->purchase($supplier, 'PN-DIRECT-13250', 13_250_000, 13_250_000, $base->copy()->addDays(57));
        $this->directPurchasePayment($supplier, $directA, 'PC20260603141538', 13_250_000, $base->copy()->addDays(63)->setTime(14, 15));

        $directB = $this->purchase($supplier, 'PN-DIRECT-11600', 11_600_000, 11_600_000, $base->copy()->addDays(75));
        $this->directPurchasePayment($supplier, $directB, 'PC20260626140446', 11_600_000, $base->copy()->addDays(86)->setTime(14, 2));

        $latest = $this->purchase($supplier, 'PN20260626155849', 5_800_000, 2_900_000, $base->copy()->addDays(86)->setTime(15, 58));
        $this->directPurchasePayment($supplier, $latest, 'PC20260626155948', 2_900_000, $base->copy()->addDays(86)->setTime(15, 59));

        $this->assertSame(45_530_000.0, (float) Purchase::where('supplier_id', $supplier->id)->sum('total_amount'));
        $this->assertSame(42_630_000.0, (float) Purchase::where('supplier_id', $supplier->id)->sum('paid_amount'));
        $this->assertSame(2_900_000.0, 45_530_000.0 - 42_630_000.0);

        $snapshot = $this->readOnlySnapshot($supplier);

        $result = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($result['entries']);

        $this->assertSame(2_900_000.0, (float) $result['summary']['document_final_balance']);
        $this->assertSame(2_900_000.0, (float) $result['summary']['display_balance_final']);
        $this->assertSame(2_900_000.0, (float) $result['summary']['current_debt']);
        $this->assertFalse((bool) $result['reconcile']['has_mismatch']);
        $this->assertSame('warning', $result['reconcile']['severity']);
        $this->assertTrue((bool) $result['reconcile']['has_inferred_generic_allocations']);
        $this->assertSame('inferred', $result['reconcile']['allocation_confidence']);
        $this->assertFalse((bool) $result['reconcile']['display_resolved']);

        $this->assertNotNull($entries->firstWhere('code', 'PCPN260422188'));
        $this->assertNotNull($entries->firstWhere('code', 'PCPN260626688'));
        $this->assertSame(1, $entries->where('code', 'PCPN260422188')->count());
        $this->assertSame(1, $entries->where('code', 'PCPN260626688')->count());
        $this->assertFalse((bool) $entries->firstWhere('code', 'PCPN260422188')['allocation_is_actual']);
        $this->assertSame('global_payment_only', $entries->firstWhere('code', 'PCPN260422188')['payment_allocation_confidence']);

        $this->assertSame([], $entries
            ->filter(fn (array $entry) => str_starts_with((string) ($entry['code'] ?? ''), 'TTNH'))
            ->pluck('code')
            ->values()
            ->all());

        $this->assertNotNull($entries->firstWhere('code', 'PC20260603141538'));
        $this->assertNotNull($entries->firstWhere('code', 'PC20260626140446'));
        $this->assertNotNull($entries->firstWhere('code', 'PC20260626155948'));

        $this->assertSame($snapshot, $this->readOnlySnapshot($supplier), 'Building supplier timeline must not mutate debt data.');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?mode=document&per_page=100&page=1");

        $response->assertOk()
            ->assertJsonPath('summary.display_balance_final', 2_900_000)
            ->assertJsonPath('summary.current_debt', 2_900_000)
            ->assertJsonPath('reconcile.has_mismatch', false)
            ->assertJsonPath('reconcile.severity', 'warning')
            ->assertJsonPath('reconcile.has_inferred_generic_allocations', true)
            ->assertJsonPath('reconcile.display_resolved', false);
    }

    public function test_generic_supplier_payment_manual_allocation_without_persisted_table_is_marked_inferred(): void
    {
        $supplier = $this->supplier('NCC-MANUAL-ALLOCATION', 1_000_000);
        $base = Carbon::parse('2026-06-01 09:00:00');

        $this->purchase($supplier, 'PN-MANUAL-A', 1_000_000, 0, $base);
        $this->purchase($supplier, 'PN-MANUAL-B', 1_000_000, 1_000_000, $base->copy()->addHour());
        $this->genericPayment($supplier, 'PCPN-MANUAL-B', 1_000_000, $base->copy()->addHours(2));

        $snapshot = $this->readOnlySnapshot($supplier);
        $result = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($result['entries']);
        $payment = $entries->firstWhere('code', 'PCPN-MANUAL-B');
        $inferredAllocations = collect($result['reconcile']['generic_payment_allocation']['inferred_allocations']);

        $this->assertSame(1_000_000.0, (float) $result['summary']['document_final_balance']);
        $this->assertFalse((bool) $result['reconcile']['has_mismatch']);
        $this->assertSame('warning', $result['reconcile']['severity']);
        $this->assertTrue((bool) $result['reconcile']['user_warning']);
        $this->assertTrue((bool) $result['reconcile']['has_inferred_generic_allocations']);
        $this->assertFalse((bool) $result['reconcile']['display_resolved']);

        $this->assertNotNull($payment);
        $this->assertSame('global_payment_only', $payment['payment_allocation_confidence']);
        $this->assertFalse((bool) $payment['allocation_is_actual']);
        $this->assertArrayNotHasKey('payment_for_code', $payment);

        $this->assertTrue($inferredAllocations->contains(fn (array $allocation) =>
            $allocation['payment_code'] === 'PCPN-MANUAL-B'
            && $allocation['purchase_code'] === 'PN-MANUAL-B'
            && (float) $allocation['amount'] === 1_000_000.0
            && $allocation['allocation_confidence'] === 'inferred'
            && $allocation['allocation_is_actual'] === false
        ));
        $this->assertFalse($inferredAllocations->contains('purchase_code', 'PN-MANUAL-A'));

        $this->assertSame([], $entries
            ->filter(fn (array $entry) => str_starts_with((string) ($entry['code'] ?? ''), 'TTNH'))
            ->pluck('code')
            ->values()
            ->all());
        $this->assertSame($snapshot, $this->readOnlySnapshot($supplier), 'Manual-allocation ambiguity handling must be read-only.');
    }

    public function test_generic_supplier_payment_auto_fifo_is_inferred_not_actual_allocation_evidence(): void
    {
        $supplier = $this->supplier('NCC-AUTO-FIFO', 1_000_000);
        $base = Carbon::parse('2026-06-01 09:00:00');

        $this->purchase($supplier, 'PN-AUTO-A', 1_000_000, 1_000_000, $base);
        $this->purchase($supplier, 'PN-AUTO-B', 1_000_000, 0, $base->copy()->addHour());
        $this->genericPayment($supplier, 'PCPN-AUTO-A', 1_000_000, $base->copy()->addHours(2));

        $result = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($result['entries']);
        $inferredAllocations = collect($result['reconcile']['generic_payment_allocation']['inferred_allocations']);

        $this->assertSame(1_000_000.0, (float) $result['summary']['document_final_balance']);
        $this->assertFalse((bool) $result['reconcile']['has_mismatch']);
        $this->assertSame('warning', $result['reconcile']['severity']);
        $this->assertTrue((bool) $result['reconcile']['has_inferred_generic_allocations']);
        $this->assertSame('generic_supplier_payment_allocation_is_inferred_not_actual', $result['reconcile']['generic_payment_allocation']['warnings'][0]);

        $this->assertTrue($inferredAllocations->contains(fn (array $allocation) =>
            $allocation['payment_code'] === 'PCPN-AUTO-A'
            && $allocation['purchase_code'] === 'PN-AUTO-A'
            && $allocation['allocation_confidence'] === 'inferred'
            && $allocation['allocation_is_actual'] === false
        ));
        $this->assertSame('global_payment_only', $entries->firstWhere('code', 'PCPN-AUTO-A')['payment_allocation_confidence']);
    }

    public function test_legacy_ttnh_fallback_uses_only_uncovered_paid_amount(): void
    {
        $supplier = $this->supplier('NCC-PARTIAL-FALLBACK', 500_000);
        $base = Carbon::parse('2026-06-01 09:00:00');

        $purchase = $this->purchase($supplier, 'PN-PARTIAL-001', 1_000_000, 500_000, $base);
        $this->directPurchasePayment($supplier, $purchase, 'PC-PARTIAL-001', 200_000, $base->copy()->addHour());

        $result = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($result['entries']);
        $fallback = $entries->firstWhere('code', 'TTNH-PARTIAL-001');

        $this->assertSame(500_000.0, (float) $result['summary']['document_final_balance']);
        $this->assertNotNull($fallback);
        $this->assertSame(300_000.0, (float) $fallback['amount']);
        $this->assertSame(-300_000.0, (float) $fallback['supplier_display_effect']);
        $this->assertFalse((bool) $result['reconcile']['has_inferred_generic_allocations']);
        $this->assertSame('ok', $result['reconcile']['severity']);
        $this->assertTrue((bool) $result['reconcile']['display_resolved']);
    }

    public function test_ambiguous_generic_payment_does_not_cover_future_purchase_or_mutate_data(): void
    {
        $supplier = $this->supplier('NCC-AMBIGUOUS-FUTURE', 500_000);
        $base = Carbon::parse('2026-06-01 09:00:00');

        $this->genericPayment($supplier, 'PCPN-AMBIGUOUS-001', 500_000, $base);
        $this->purchase($supplier, 'PN-AMBIGUOUS-001', 1_000_000, 500_000, $base->copy()->addDay());

        $snapshot = $this->readOnlySnapshot($supplier);
        $result = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($result['entries']);

        $fallback = $entries->firstWhere('code', 'TTNH-AMBIGUOUS-001');

        $this->assertNotNull($fallback, 'Future generic payment must not suppress legacy paid_amount fallback by guesswork.');
        $this->assertSame(500_000.0, (float) $fallback['amount']);
        $this->assertSame(-500_000.0, (float) $entries->firstWhere('code', 'PCPN-AMBIGUOUS-001')['supplier_display_effect']);
        $this->assertTrue((bool) $result['reconcile']['has_mismatch']);
        $this->assertSame('warning', $result['reconcile']['severity']);
        $this->assertTrue((bool) $result['reconcile']['has_unallocated_generic_payments']);
        $this->assertFalse((bool) $result['reconcile']['has_inferred_generic_allocations']);
        $this->assertSame($snapshot, $this->readOnlySnapshot($supplier), 'Ambiguous timeline build must be read-only.');
    }

    private function supplier(string $code, int $payable): Customer
    {
        return Customer::create([
            'code' => $code . '-' . uniqid(),
            'name' => 'Supplier Debt Parity',
            'debt_amount' => 0,
            'supplier_debt_amount' => $payable,
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
    }

    private function purchase(Customer $supplier, string $code, int $total, int $paid, Carbon $time): Purchase
    {
        return Purchase::create([
            'code' => $code,
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => $total,
            'paid_amount' => $paid,
            'debt_amount' => max(0, $total - $paid),
            'purchase_date' => $time,
            'created_at' => $time,
            'updated_at' => $time,
        ]);
    }

    private function genericPayment(Customer $supplier, string $code, int $amount, Carbon $time): void
    {
        CashFlow::create([
            'code' => $code,
            'type' => 'payment',
            'amount' => $amount,
            'time' => $time,
            'target_type' => 'Nha cung cap',
            'target_id' => $supplier->id,
            'target_name' => $supplier->name,
            'reference_type' => 'SupplierPayment',
            'reference_code' => $code,
            'status' => 'completed',
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => $code,
            'type' => 'payment',
            'amount' => -$amount,
            'debt_remain' => max(0, (float) $supplier->supplier_debt_amount - $amount),
            'note' => 'Generic supplier payment parity fixture',
            'created_at' => $time,
            'updated_at' => $time,
        ]);
    }

    private function directPurchasePayment(Customer $supplier, Purchase $purchase, string $code, int $amount, Carbon $time): void
    {
        CashFlow::create([
            'code' => $code,
            'type' => 'payment',
            'amount' => $amount,
            'time' => $time,
            'target_type' => 'Nha cung cap',
            'target_id' => $supplier->id,
            'target_name' => $supplier->name,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'status' => 'completed',
            'created_at' => $time,
            'updated_at' => $time,
        ]);
    }

    private function readOnlySnapshot(Customer $supplier): array
    {
        return [
            'supplier_debt_amount' => (float) $supplier->fresh()->supplier_debt_amount,
            'purchase_paid_sum' => (float) Purchase::where('supplier_id', $supplier->id)->sum('paid_amount'),
            'purchase_debt_sum' => (float) Purchase::where('supplier_id', $supplier->id)->sum('debt_amount'),
            'cash_flow_count' => CashFlow::where('target_id', $supplier->id)->count(),
            'supplier_debt_transaction_count' => SupplierDebtTransaction::where('supplier_id', $supplier->id)->count(),
        ];
    }
}
