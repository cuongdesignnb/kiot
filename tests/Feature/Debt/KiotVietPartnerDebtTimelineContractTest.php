<?php

namespace Tests\Feature\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class KiotVietPartnerDebtTimelineContractTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'name' => 'Partner debt contract test',
            'email' => 'partner-debt-contract-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_dual_role_views_use_the_same_stream_without_view_parameter(): void
    {
        $partner = $this->partner([
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 100_000,
            'supplier_debt_amount' => 300_000,
        ]);
        Invoice::create([
            'code' => 'HD-CONTRACT-1',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'total' => 100_000,
            'customer_paid' => 0,
            'transaction_date' => now()->subMinute(),
        ]);
        Purchase::create([
            'code' => 'PN-CONTRACT-1',
            'supplier_id' => $partner->id,
            'status' => 'completed',
            'total_amount' => 300_000,
            'paid_amount' => 0,
            'debt_amount' => 300_000,
            'purchase_date' => now(),
        ]);

        $customer = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplier = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh());
        $customerEntries = collect($customer['entries']);
        $supplierEntries = collect($supplier['entries']);

        $this->assertSame(-200_000.0, (float) $customer['raw_final_balance']);
        $this->assertSame(200_000.0, (float) $supplier['raw_final_balance']);
        $this->assertSame($customer['entry_count'], $supplier['entry_count']);
        $this->assertSame($customer['source_identity_hash'], $supplier['source_identity_hash']);
        $this->assertSame($customerEntries->pluck('event_identity')->all(), $supplierEntries->pluck('event_identity')->all());
        foreach ($customerEntries as $index => $entry) {
            $this->assertSame(0.0, (float) $entry['display_delta'] + (float) $supplierEntries[$index]['display_delta']);
            $this->assertSame(0.0, (float) $entry['running_balance'] + (float) $supplierEntries[$index]['running_balance']);
        }
    }

    public function test_legacy_supplier_partner_view_cannot_duplicate_customer_events_for_a_dual_role_partner(): void
    {
        $partner = $this->partner([
            'code' => 'KH178333171285',
            'name' => 'Anh Hữu Trần Cung',
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 15_300_000,
        ]);
        $invoiceTime = now()->subDays(48)->setTime(16, 52);
        $paymentTime = now()->subDays(21)->setTime(11, 3);
        $cancelledPurchaseTime = now()->subDays(6)->setTime(9, 52);
        $cancelledAt = now()->subDays(4)->setTime(11, 12);
        $openPurchaseTime = now()->subDays(4)->setTime(14, 58);

        Invoice::create([
            'code' => 'HD178333171519',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'total' => 5_600_000,
            'customer_paid' => 5_600_000,
            'transaction_date' => $invoiceTime,
        ]);
        CashFlow::create([
            'code' => 'PT26072811032316',
            'type' => 'receipt',
            'amount' => 5_600_000,
            'status' => 'active',
            'target_type' => 'Customer',
            'target_id' => $partner->id,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD178333171519',
            'time' => $paymentTime,
        ]);
        $cancelledPurchasePayload = [
            'code' => 'PN20260813100850',
            'supplier_id' => $partner->id,
            'status' => 'cancelled',
            'total_amount' => 13_000_000,
            'paid_amount' => 0,
            'debt_amount' => 0,
            'purchase_date' => $cancelledPurchaseTime,
        ];

        // Fresh historical schemas do not have purchases.cancelled_at. The
        // production source must retain its existing updated_at fallback.
        if (Schema::hasColumn('purchases', 'cancelled_at')) {
            $cancelledPurchasePayload['cancelled_at'] = $cancelledAt;
        }

        Purchase::create($cancelledPurchasePayload);
        Purchase::create([
            'code' => 'PN20260815145835',
            'supplier_id' => $partner->id,
            'status' => 'completed',
            'total_amount' => 15_300_000,
            'paid_amount' => 0,
            'debt_amount' => 15_300_000,
            'purchase_date' => $openPurchaseTime,
        ]);

        $customer = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplier = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh(), ['view' => 'partner']);
        $customerEntries = collect($customer['entries']);
        $supplierEntries = collect($supplier['entries']);

        $this->assertSame(-15_300_000.0, (float) $customer['raw_final_balance']);
        $this->assertSame(15_300_000.0, (float) $supplier['raw_final_balance']);
        $this->assertSame($customer['source_identity_hash'], $supplier['source_identity_hash']);
        $this->assertSame($customerEntries->pluck('event_identity')->all(), $supplierEntries->pluck('event_identity')->all());
        $this->assertSame(1, $supplierEntries->where('source_code', 'PT26072811032316')->count());
        $this->assertFalse($supplierEntries->contains(
            fn (array $entry): bool => str_starts_with((string) ($entry['source_code'] ?? ''), 'TTHD'),
        ));

        // Older Supplier tabs sent view=partner. The public endpoint must
        // ignore it now: callers get the same canonical stream and balance.
        $withoutLegacyView = $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?page=1&per_page=100")
            ->assertOk();
        $withLegacyView = $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&page=1&per_page=100")
            ->assertOk();

        $this->assertSame(
            $withoutLegacyView->json('source_identity_hash'),
            $withLegacyView->json('source_identity_hash'),
        );
        $this->assertSame(
            $withoutLegacyView->json('entries'),
            $withLegacyView->json('entries'),
        );
    }

    public function test_real_customer_receipt_suppresses_a_partial_legacy_invoice_payment_fallback(): void
    {
        $customer = $this->partner([
            'is_customer' => true,
            'debt_amount' => 0,
        ]);
        Invoice::create([
            'code' => 'HD-REAL-RECEIPT-WINS',
            'customer_id' => $customer->id,
            'status' => 'completed',
            'total' => 100_000,
            'customer_paid' => 200_000,
            'transaction_date' => now()->subMinute(),
        ]);
        CashFlow::create([
            'code' => 'PT-REAL-RECEIPT-WINS',
            'type' => 'receipt',
            'amount' => 100_000,
            'status' => 'active',
            'target_type' => 'Customer',
            'target_id' => $customer->id,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-REAL-RECEIPT-WINS',
            'time' => now(),
        ]);

        $timeline = app(CustomerDebtDocumentTimelineService::class)->build($customer->fresh());
        $entries = collect($timeline['entries']);

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(1, $entries->where('source_code', 'PT-REAL-RECEIPT-WINS')->count());
        $this->assertFalse($entries->contains(
            fn (array $entry): bool => str_starts_with((string) ($entry['source_code'] ?? ''), 'TTHD'),
        ));
    }

    public function test_order_deposit_is_distinct_from_a_real_invoice_receipt(): void
    {
        $customer = $this->partner([
            'is_customer' => true,
            'debt_amount' => 0,
        ]);
        $order = Order::create([
            'code' => 'DH-LEGACY-DEPOSIT',
            'customer_id' => $customer->id,
            'status' => 'draft',
            'total_price' => 400_000,
            'total_payment' => 400_000,
            'amount_paid' => 150_000,
        ]);
        Invoice::create([
            'code' => 'HD-LEGACY-DEPOSIT',
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'completed',
            'total' => 400_000,
            'customer_paid' => 400_000,
            'order_deposit_applied_amount' => 150_000,
            'transaction_date' => now(),
        ]);
        CashFlow::create([
            'code' => 'PT-LEGACY-DEPOSIT',
            'type' => 'receipt',
            'amount' => 250_000,
            'status' => 'active',
            'target_type' => 'Customer',
            'target_id' => $customer->id,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-LEGACY-DEPOSIT',
            'time' => now(),
        ]);

        $timeline = app(CustomerDebtDocumentTimelineService::class)->build($customer->fresh());
        $entries = collect($timeline['entries']);

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(1, $entries->where('event_kind', 'order_deposit')->count());
        $this->assertSame(-150_000.0, (float) $entries->firstWhere('event_kind', 'order_deposit')['display_delta']);
        $this->assertSame(1, $entries->where('source_code', 'PT-LEGACY-DEPOSIT')->count());
        $this->assertFalse($entries->contains(
            fn (array $entry): bool => str_starts_with((string) ($entry['source_code'] ?? ''), 'TTHD'),
        ));
    }

    public function test_purchase_return_and_real_supplier_refund_reduce_payable_by_the_unrefunded_part(): void
    {
        $supplier = $this->partner([
            'is_supplier' => true,
            'supplier_debt_amount' => -650_000,
        ]);
        $return = PurchaseReturn::create([
            'code' => 'PTN-CONTRACT-1',
            'supplier_id' => $supplier->id,
            'total_amount' => 900_000,
            'refund_amount' => 250_000,
            'status' => 'completed',
            'return_date' => now(),
        ]);
        CashFlow::create([
            'code' => 'PT-CONTRACT-1',
            'type' => 'receipt',
            'amount' => 250_000,
            'status' => 'active',
            'reference_type' => 'PurchaseReturn',
            'reference_code' => $return->code,
            'time' => now(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($timeline['entries']);

        $this->assertSame(-650_000.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-900_000.0, (float) $entries->firstWhere('event_kind', 'purchase_return')['display_delta']);
        $this->assertSame(250_000.0, (float) $entries->firstWhere('event_kind', 'supplier_refund')['display_delta']);
        $this->assertNull($entries->firstWhere('event_kind', 'supplier_refund_fallback'));
    }

    public function test_purchase_payment_excludes_cash_amount_that_did_not_reduce_supplier_payable(): void
    {
        $supplier = $this->partner([
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-CONTRACT-NON-DEBT-COST',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 1_000_000,
            'paid_amount' => 1_100_000,
            'debt_amount' => 0,
            'purchase_date' => now(),
        ]);
        CashFlow::create([
            'code' => 'PC-CONTRACT-NON-DEBT-COST',
            'type' => 'payment',
            'amount' => 1_100_000,
            'status' => 'active',
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'time' => now(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $payment = collect($timeline['entries'])->firstWhere('event_kind', 'supplier_payment');

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-1_000_000.0, (float) $payment['display_delta']);
        $this->assertSame(1_100_000.0, (float) $payment['original_cash_flow_amount']);
        $this->assertSame(100_000.0, (float) $payment['non_debt_cash_amount']);
    }

    public function test_purchase_return_refund_restores_missing_historical_payment_evidence(): void
    {
        $supplier = $this->partner([
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-CONTRACT-RETURNED-LEGACY',
            'supplier_id' => $supplier->id,
            'status' => 'returned',
            'total_amount' => 900_000,
            'paid_amount' => 0,
            'debt_amount' => 900_000,
            'purchase_date' => now()->subMinute(),
        ]);
        $return = PurchaseReturn::create([
            'code' => 'PTN-CONTRACT-RETURNED-LEGACY',
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 900_000,
            'refund_amount' => 900_000,
            'status' => 'completed',
            'return_date' => now(),
        ]);
        CashFlow::create([
            'code' => 'PT-CONTRACT-RETURNED-LEGACY',
            'type' => 'receipt',
            'amount' => 900_000,
            'status' => 'active',
            'reference_type' => 'PurchaseReturn',
            'reference_code' => $return->code,
            'time' => now(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($timeline['entries']);

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-900_000.0, (float) $entries
            ->firstWhere('persisted_evidence', 'purchase_returns.refund_amount')['display_delta']);
        $this->assertSame(900_000.0, (float) $entries->firstWhere('event_kind', 'supplier_refund')['display_delta']);
    }

    public function test_persisted_payment_allocation_is_capped_by_purchase_obligation(): void
    {
        $supplier = $this->partner([
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-CONTRACT-ALLOCATED-COST',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 1_000_000,
            'paid_amount' => 1_100_000,
            'debt_amount' => 0,
            'purchase_date' => now(),
        ]);
        $payment = CashFlow::create([
            'code' => 'PC-CONTRACT-ALLOCATED-COST',
            'type' => 'payment',
            'amount' => 1_100_000,
            'status' => 'active',
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'SupplierPayment',
            'time' => now(),
        ]);
        $token = (string) Str::uuid();
        $operationId = DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => $token,
            'operation_type' => 'timeline_contract_test',
            'idempotency_key' => 'timeline-contract-'.$token,
            'request_hash' => hash('sha256', $token),
            'status' => 'pending',
            'initiated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_payment_allocations')->insert([
            'payment_id' => $payment->id,
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'amount' => 1_100_000,
            'allocation_source' => 'manual',
            'idempotency_key' => 'timeline-allocation-'.$token,
            'operation_id' => $operationId,
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $allocation = collect($timeline['entries'])->firstWhere('event_kind', 'supplier_payment');

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-1_000_000.0, (float) $allocation['display_delta']);
        $this->assertSame(1_100_000.0, (float) $allocation['original_allocated_amount']);
        $this->assertSame(100_000.0, (float) $allocation['non_debt_cash_amount']);
    }

    public function test_persisted_supplier_ledger_debt_remain_restores_auditable_history_gap(): void
    {
        $supplier = $this->partner([
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
        ]);
        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => 'DCNCC-CONTRACT-CHECKPOINT',
            'type' => 'adjustment',
            'amount' => -800_000,
            'debt_remain' => 0,
            'purchase_id' => null,
            'created_at' => now(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($timeline['entries']);

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(800_000.0, (float) $entries->firstWhere('event_kind', 'persisted_ledger_checkpoint')['display_delta']);
        $this->assertSame(-800_000.0, (float) $entries->firstWhere('event_kind', 'supplier_adjustment')['display_delta']);
        $this->assertFalse($entries->contains(
            fn (array $entry): bool => str_contains((string) $entry['event_kind'], 'virtual_opening'),
        ));
    }

    public function test_cancelled_purchase_return_keeps_originals_and_exact_reversals(): void
    {
        $supplier = $this->partner(['is_supplier' => true]);
        PurchaseReturn::create([
            'code' => 'PTN-CANCELLED-CONTRACT',
            'supplier_id' => $supplier->id,
            'total_amount' => 900_000,
            'refund_amount' => 250_000,
            'status' => 'cancelled',
            'return_date' => now()->subMinute(),
        ]);

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $entries = collect($timeline['entries']);
        $byIdentity = $entries->keyBy('event_identity');

        $this->assertSame(0.0, (float) $timeline['raw_final_balance']);
        foreach ($entries->filter(fn (array $entry): bool => str_contains($entry['event_kind'], 'cancel')) as $reversal) {
            $original = $byIdentity->get($reversal['reversal_of_event_identity']);
            $this->assertNotNull($original);
            $this->assertSame(0.0, (float) $original['supplier_delta'] + (float) $reversal['supplier_delta']);
        }
    }

    public function test_endpoints_default_to_canonical_contract_and_enforce_persisted_role(): void
    {
        $customer = $this->partner(['is_customer' => true]);
        $supplier = $this->partner(['is_supplier' => true]);

        $this->actingAs($this->user)
            ->getJson("/customers/{$customer->id}/debt-history")
            ->assertOk()
            ->assertJsonPath('orientation', 'customer')
            ->assertJsonStructure([
                'persisted_role', 'effective_role', 'role_integrity_status',
                'customer_receivable', 'supplier_payable', 'target_balance',
                'raw_final_balance', 'difference', 'has_mismatch',
                'entry_count', 'source_identity_hash',
            ]);
        $this->actingAs($this->user)
            ->getJson("/customers/{$supplier->id}/debt-history")
            ->assertNotFound();
        $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions")
            ->assertOk()
            ->assertJsonPath('orientation', 'supplier')
            ->assertJsonStructure([
                'persisted_role', 'effective_role', 'role_integrity_status',
                'customer_receivable', 'supplier_payable', 'target_balance',
                'raw_final_balance', 'difference', 'has_mismatch',
                'entry_count', 'source_identity_hash',
            ]);
        $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$customer->id}/debt-transactions")
            ->assertNotFound();
    }

    public function test_customer_and_supplier_lists_follow_only_persisted_role_flags(): void
    {
        $customerOnly = $this->partner(['is_customer' => true]);
        $supplierOnly = $this->partner(['is_supplier' => true]);
        $dualRole = $this->partner(['is_customer' => true, 'is_supplier' => true]);

        $customerProps = $this->pageProps(
            $this->actingAs($this->user)->get('/customers')->assertOk(),
        );
        $customerIds = collect($customerProps['customers']['data'] ?? [])->pluck('id');
        $this->assertTrue($customerIds->contains($customerOnly->id));
        $this->assertTrue($customerIds->contains($dualRole->id));
        $this->assertFalse($customerIds->contains($supplierOnly->id));

        $supplierProps = $this->pageProps(
            $this->actingAs($this->user)->get('/suppliers')->assertOk(),
        );
        $supplierIds = collect($supplierProps['suppliers']['data'] ?? [])->pluck('id');
        $this->assertTrue($supplierIds->contains($supplierOnly->id));
        $this->assertTrue($supplierIds->contains($dualRole->id));
        $this->assertFalse($supplierIds->contains($customerOnly->id));
    }

    public function test_evidence_role_warning_does_not_silently_promote_persisted_role(): void
    {
        $partner = $this->partner();
        Invoice::create([
            'code' => 'HD-ROLE-EVIDENCE',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'total' => 10_000,
            'customer_paid' => 0,
            'transaction_date' => now(),
        ]);
        Purchase::create([
            'code' => 'PN-ROLE-EVIDENCE',
            'supplier_id' => $partner->id,
            'status' => 'completed',
            'total_amount' => 20_000,
            'paid_amount' => 0,
            'debt_amount' => 20_000,
            'purchase_date' => now(),
        ]);

        $integrity = PartnerDebtRoleResolver::integrity($partner->fresh());
        $this->assertSame('missing_role', $integrity['persisted_role']);
        $this->assertSame('dual_role', $integrity['evidence_role']);
        $this->assertSame('ROLE_FLAG_EVIDENCE_MISMATCH', $integrity['role_integrity_status']);
        $this->actingAs($this->user)
            ->getJson("/customers/{$partner->id}/debt-history")
            ->assertNotFound();
        $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions")
            ->assertNotFound();
    }

    public function test_ncc177621742868_remains_supplier_only_across_every_customer_surface(): void
    {
        $supplier = $this->partner([
            'code' => 'NCC177621742868',
            'name' => 'P0 supplier-only scope regression',
            'is_customer' => false,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 6_800_000,
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-P0-SUPPLIER-ONLY-6800',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 6_800_000,
            'paid_amount' => 0,
            'debt_amount' => 6_800_000,
            'purchase_date' => now(),
        ]);

        $exactCustomerProps = $this->pageProps(
            $this->actingAs($this->user)
                ->get('/customers?keyword=NCC177621742868')
                ->assertOk(),
        );
        $this->assertCount(0, $exactCustomerProps['customers']['data'] ?? []);
        $this->assertSame(0, (int) ($exactCustomerProps['customers']['total'] ?? -1));
        $this->assertSame(0.0, (float) ($exactCustomerProps['summary']['total_debt'] ?? -1));
        $this->assertSame(0.0, (float) ($exactCustomerProps['summary']['total_store_owes'] ?? -1));

        $keywordCustomerProps = $this->pageProps(
            $this->actingAs($this->user)
                ->get('/customers?search=177621742868')
                ->assertOk(),
        );
        $this->assertCount(0, $keywordCustomerProps['customers']['data'] ?? []);

        $paginatedCustomerProps = $this->pageProps(
            $this->actingAs($this->user)
                ->get('/customers?keyword=NCC177621742868&page=2')
                ->assertOk(),
        );
        $this->assertCount(0, $paginatedCustomerProps['customers']['data'] ?? []);
        $this->assertSame(0, (int) ($paginatedCustomerProps['customers']['total'] ?? -1));

        $export = $this->actingAs($this->user)
            ->get('/customers/export?keyword=NCC177621742868')
            ->assertOk();
        $exportBody = $export->streamedContent() ?: $export->getContent();
        $this->assertStringNotContainsString('NCC177621742868', $exportBody);

        $this->actingAs($this->user)
            ->getJson('/api/customers/search?search=NCC177621742868')
            ->assertOk()
            ->assertExactJson([]);

        foreach ([
            "/customers/{$supplier->id}/sales-history",
            "/customers/{$supplier->id}/debt-history",
            "/customers/{$supplier->id}/export-debt",
            "/customers/{$supplier->id}/export-sales",
            "/customers/{$supplier->id}/debt-voucher-detail?code={$purchase->code}",
            "/customers/{$supplier->id}/outstanding-invoices",
            "/customers/{$supplier->id}/debt-offset-history",
            "/customers/{$supplier->id}/payment-discount-invoices",
        ] as $customerEndpoint) {
            $this->actingAs($this->user)->get($customerEndpoint)->assertNotFound();
        }

        $supplierProps = $this->pageProps(
            $this->actingAs($this->user)
                ->get('/suppliers?keyword=NCC177621742868')
                ->assertOk(),
        );
        $supplierRows = collect($supplierProps['suppliers']['data'] ?? []);
        $this->assertCount(1, $supplierRows);
        $supplierRow = (array) $supplierRows->first();
        $this->assertSame($supplier->id, (int) $supplierRow['id']);
        $this->assertFalse((bool) $supplierRow['is_customer']);
        $this->assertTrue((bool) $supplierRow['is_supplier']);
        $this->assertFalse((bool) $supplierRow['is_dual_role']);
        $this->assertSame(0.0, (float) $supplierRow['customer_screen_debt']);

        $this->actingAs($this->user)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions")
            ->assertOk()
            ->assertJsonPath('orientation', 'supplier')
            ->assertJsonPath('applicable', true);

        $customerTimeline = app(CustomerDebtDocumentTimelineService::class)->build($supplier->fresh());
        $supplierTimeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $role = PartnerDebtRoleResolver::integrity($supplier->fresh());

        $this->assertFalse($customerTimeline['applicable']);
        $this->assertSame(0, $customerTimeline['entry_count']);
        $this->assertSame(0.0, (float) $customerTimeline['target_balance']);
        $this->assertSame(0.0, (float) $customerTimeline['raw_final_balance']);
        $this->assertTrue($supplierTimeline['applicable']);
        $this->assertSame(6_800_000.0, (float) $supplierTimeline['raw_final_balance']);
        $this->assertSame('supplier_only', $role['persisted_role']);
        $this->assertNull($role['owner_confirmed_role']);
        $this->assertNotContains('NCC177621742868', PartnerDebtRoleResolver::OWNER_CONFIRMED_DUAL_ROLE_CODES);
    }

    public function test_customer_only_partner_is_denied_across_every_supplier_surface(): void
    {
        $customer = $this->partner([
            'is_customer' => true,
            'is_supplier' => false,
            'debt_amount' => 25_000,
            'supplier_debt_amount' => 0,
        ]);
        Invoice::create([
            'code' => 'HD-CUSTOMER-ONLY-SUPPLIER-SCOPE',
            'customer_id' => $customer->id,
            'status' => 'completed',
            'total' => 25_000,
            'customer_paid' => 0,
            'transaction_date' => now(),
        ]);

        foreach ([
            "/api/suppliers/{$customer->id}/purchase-history",
            "/api/suppliers/{$customer->id}/debt-transactions",
            "/api/suppliers/{$customer->id}/debt-voucher-detail?code=HD-CUSTOMER-ONLY-SUPPLIER-SCOPE",
            "/api/suppliers/{$customer->id}/export-debt",
            "/api/suppliers/{$customer->id}/export-purchases",
        ] as $supplierEndpoint) {
            $this->actingAs($this->user)->get($supplierEndpoint)->assertNotFound();
        }

        $this->actingAs($this->user)
            ->post("/api/suppliers/{$customer->id}/payment", ['amount' => 1])
            ->assertNotFound();
        $this->actingAs($this->user)
            ->post("/api/suppliers/{$customer->id}/adjust-debt", ['amount' => 1])
            ->assertNotFound();
        $this->actingAs($this->user)
            ->put("/suppliers/{$customer->id}", ['name' => 'Blocked customer-only partner'])
            ->assertNotFound();
    }

    private function partner(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'PARTNER-CONTRACT-'.uniqid(),
            'name' => 'Partner contract',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => false,
            'is_supplier' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function pageProps($response): array
    {
        $page = $response->original?->getData()['page'] ?? null;

        return is_array($page) ? (array) ($page['props'] ?? []) : (array) $response->json();
    }
}
