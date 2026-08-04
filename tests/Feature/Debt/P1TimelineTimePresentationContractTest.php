<?php

namespace Tests\Feature\Debt;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\SupplierDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class P1TimelineTimePresentationContractTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'P1 Timeline Time QA',
            'email' => 'p1-timeline-time-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_detail_time_metadata_is_read_only_and_keeps_all_financial_timeline_snapshots_byte_identical(): void
    {
        $customer = $this->partner('CUSTOMER', true, false);
        $supplier = $this->partner('SUPPLIER', false, true);
        $dualRole = $this->partner('DUAL', true, true);

        $businessTime = Carbon::parse('2026-07-11 09:49:00');
        $recordedAt = Carbon::parse('2026-07-11 10:02:22');

        $invoice = Invoice::query()->create([
            'code' => 'HD-P1-'.uniqid(),
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 4_700_000,
            'customer_paid' => 0,
            'sale_time' => $businessTime,
            'transaction_date' => $businessTime,
            'lock_started_at' => $recordedAt,
            'created_at' => $recordedAt,
        ]);

        $purchase = Purchase::query()->create([
            'code' => 'PN-P1-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 2_000_000,
            'paid_amount' => 0,
            'debt_amount' => 2_000_000,
            'purchase_date' => $businessTime,
            'created_at' => $recordedAt,
        ]);
        $purchase->forceFill(['created_at' => $recordedAt])->saveQuietly();

        $return = OrderReturn::query()->create([
            'code' => 'TH-P1-'.uniqid(),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'status' => 'completed',
            'total' => 1_000_000,
            'paid_to_customer' => 0,
            'return_date' => Carbon::parse('2026-07-11 09:58:09'),
            'created_at' => $recordedAt,
        ]);

        $purchaseReturn = PurchaseReturn::query()->create([
            'code' => 'THN-P1-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 500_000,
            'refund_amount' => 0,
            'return_date' => $businessTime,
            'created_at' => $recordedAt,
        ]);

        Invoice::query()->create([
            'code' => 'HD-P1-DUAL-'.uniqid(),
            'customer_id' => $dualRole->id,
            'status' => 'Hoàn thành',
            'total' => 700_000,
            'customer_paid' => 0,
            'transaction_date' => $businessTime,
        ]);
        Purchase::query()->create([
            'code' => 'PN-P1-DUAL-'.uniqid(),
            'supplier_id' => $dualRole->id,
            'status' => 'completed',
            'total_amount' => 900_000,
            'paid_amount' => 0,
            'debt_amount' => 900_000,
            'purchase_date' => $businessTime->copy()->addMinute(),
        ]);

        $before = $this->financialSnapshots($customer, $supplier, $dualRole);
        $customerTimelineBefore = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=10&page=1")
            ->assertOk()
            ->json();
        $supplierTimelineBefore = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?per_page=10&page=1")
            ->assertOk()
            ->json();

        $this->actingAs($this->admin)
            ->getJson("/invoices/{$invoice->id}/detail")
            ->assertOk()
            ->assertJsonPath('business_time', '11/07/2026 09:49')
            ->assertJsonPath('recorded_at', '11/07/2026 10:02')
            ->assertJsonPath('business_time_source', 'transaction_date')
            ->assertJsonPath('recorded_time_source', 'lock_started_at');

        $this->actingAs($this->admin)
            ->getJson("/purchases/{$purchase->id}/detail")
            ->assertOk()
            ->assertJsonPath('business_time', '11/07/2026 09:49')
            ->assertJsonPath('recorded_at', '11/07/2026 10:02')
            ->assertJsonPath('business_time_source', 'purchase_date')
            ->assertJsonPath('recorded_time_source', 'created_at');

        $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-voucher-detail?code={$invoice->code}")
            ->assertOk()
            ->assertJsonPath('data.business_time', '11/07/2026 09:49')
            ->assertJsonPath('data.recorded_at', '11/07/2026 10:02');

        $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-voucher-detail?code={$purchase->code}")
            ->assertOk()
            ->assertJsonPath('data.business_time', '11/07/2026 09:49')
            ->assertJsonPath('data.recorded_at', '11/07/2026 10:02');

        $this->actingAs($this->admin)
            ->get("/returns/{$return->id}/show")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('returnOrder.business_time', '11/07/2026 10:02')
                ->where('returnOrder.recorded_at', '11/07/2026 10:02')
                ->where('returnOrder.business_time_source', 'created_at')
                ->where('returnOrder.recorded_time_source', 'created_at')
            )
            ->assertSee('TH-P1-');

        $this->actingAs($this->admin)
            ->get("/purchase-returns/{$purchaseReturn->id}")
            ->assertOk()
            ->assertSee('THN-P1-');

        $after = $this->financialSnapshots($customer, $supplier, $dualRole);
        $customerTimelineAfter = $this->actingAs($this->admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=10&page=1")
            ->assertOk()
            ->json();
        $supplierTimelineAfter = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?per_page=10&page=1")
            ->assertOk()
            ->json();

        $this->assertSame($before, $after);
        $this->assertSame($before['customer_only']['hash'], $after['customer_only']['hash']);
        $this->assertSame($before['supplier_only']['hash'], $after['supplier_only']['hash']);
        $this->assertSame($before['dual_customer']['hash'], $after['dual_customer']['hash']);
        $this->assertSame($before['dual_supplier']['hash'], $after['dual_supplier']['hash']);
        $this->assertSame($this->paginationSignature($customerTimelineBefore), $this->paginationSignature($customerTimelineAfter));
        $this->assertSame($this->paginationSignature($supplierTimelineBefore), $this->paginationSignature($supplierTimelineAfter));
        $this->assertDualRoleOrientationParity($before);
    }

    private function partner(string $suffix, bool $isCustomer, bool $isSupplier): Customer
    {
        return Customer::query()->create([
            'code' => 'P1-'.$suffix.'-'.uniqid(),
            'name' => 'P1 '.$suffix,
            'phone' => '090'.random_int(1000000, 9999999),
            'status' => 'active',
            'is_customer' => $isCustomer,
            'is_supplier' => $isSupplier,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ]);
    }

    private function financialSnapshots(Customer $customer, Customer $supplier, Customer $dualRole): array
    {
        return [
            'customer_only' => $this->snapshot(app(CustomerDebtDocumentTimelineService::class)->build($customer, [])),
            'supplier_only' => $this->snapshot(app(SupplierDebtDocumentTimelineService::class)->build($supplier, [])),
            'dual_customer' => $this->snapshot(app(CustomerDebtDocumentTimelineService::class)->build($dualRole, [])),
            'dual_supplier' => $this->snapshot(app(SupplierDebtDocumentTimelineService::class)->build($dualRole, ['view' => 'partner'])),
        ];
    }

    private function snapshot(array $timeline): array
    {
        $entries = collect($timeline['entries'] ?? [])->map(fn (array $entry) => [
            'event_identity' => $entry['event_identity'] ?? $entry['source_identity_hash'] ?? $entry['_entryKey'] ?? null,
            'event_kind' => $entry['event_kind'] ?? $entry['type'] ?? null,
            'source_type' => $entry['source_type'] ?? $entry['source'] ?? null,
            'source_id' => $entry['source_id'] ?? $entry['id'] ?? null,
            'source_code' => $entry['source_code'] ?? $entry['code'] ?? null,
            'business_time' => $entry['business_time'] ?? $entry['display_time'] ?? $entry['time'] ?? null,
            'event_order' => $entry['event_order'] ?? null,
            'customer_delta' => $entry['customer_effect'] ?? $entry['customer_display_effect'] ?? null,
            'supplier_delta' => $entry['supplier_effect'] ?? $entry['supplier_display_effect'] ?? null,
            'affects_balance' => $entry['affects_debt_balance'] ?? $entry['affects_balance'] ?? null,
            'reference_only' => $entry['reference_only'] ?? null,
            'customer_display_effect' => $entry['customer_display_effect'] ?? $entry['display_effect'] ?? null,
            'supplier_display_effect' => $entry['supplier_display_effect'] ?? null,
            'customer_display_running_balance' => $entry['customer_display_running_balance'] ?? $entry['display_running_balance'] ?? null,
            'supplier_display_running_balance' => $entry['supplier_display_running_balance'] ?? null,
            'source_identity_hash' => $entry['source_identity_hash'] ?? null,
        ])->values()->all();

        $summary = $timeline['summary'] ?? [];
        $normalized = [
            'entries' => $entries,
            'target_balance' => $summary['target_balance'] ?? $summary['target_debt'] ?? null,
            'raw_final_balance' => $summary['raw_document_final_balance'] ?? $summary['final_balance'] ?? null,
            'difference' => $summary['difference'] ?? $summary['reconcile_difference'] ?? null,
            'has_mismatch' => $summary['has_mismatch'] ?? null,
            'entry_count' => count($entries),
        ];

        return ['hash' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), 'data' => $normalized];
    }

    private function paginationSignature(array $timeline): array
    {
        return [
            'entries' => collect($timeline['entries'] ?? [])->map(fn (array $entry) => [
                'event_identity' => $entry['event_identity'] ?? $entry['source_identity_hash'] ?? $entry['_entryKey'] ?? null,
                'time' => $entry['display_time'] ?? $entry['time'] ?? null,
            ])->values()->all(),
            'pagination' => $timeline['pagination'] ?? null,
        ];
    }

    private function assertDualRoleOrientationParity(array $snapshots): void
    {
        $customer = $snapshots['dual_customer']['data'];
        $supplier = $snapshots['dual_supplier']['data'];

        $this->assertSame(
            array_column($customer['entries'], 'event_identity'),
            array_column($supplier['entries'], 'event_identity')
        );

        foreach ($customer['entries'] as $index => $customerEntry) {
            $supplierEntry = $supplier['entries'][$index];

            $this->assertEqualsWithDelta(
                -((float) ($customerEntry['customer_display_effect'] ?? 0)),
                (float) ($supplierEntry['supplier_display_effect'] ?? 0),
                0.0001
            );
            $this->assertEqualsWithDelta(
                -((float) ($customerEntry['customer_display_running_balance'] ?? 0)),
                (float) ($supplierEntry['supplier_display_running_balance'] ?? 0),
                0.0001
            );
        }

        $this->assertEqualsWithDelta(
            -((float) ($customer['target_balance'] ?? 0)),
            (float) ($supplier['target_balance'] ?? 0),
            0.0001
        );
    }
}
