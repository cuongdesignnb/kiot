<?php

namespace Tests\Feature\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
