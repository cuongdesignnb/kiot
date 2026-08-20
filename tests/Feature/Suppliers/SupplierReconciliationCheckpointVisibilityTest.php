<?php

namespace Tests\Feature\Suppliers;

use App\Models\Customer;
use App\Models\User;
use App\Services\Debt\PartnerDebtPublicTimelineService;
use App\Services\Exports\SupplierDebtExcelExportService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class SupplierReconciliationCheckpointVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_projection_hides_checkpoint_metadata_and_preserves_real_cancellation_rows(): void
    {
        $timeline = $this->timelineFixture();

        $supplier = app(PartnerDebtPublicTimelineService::class)->project($timeline, 'supplier');
        $customer = app(PartnerDebtPublicTimelineService::class)->project($timeline, 'customer');

        $this->assertSame(
            ['HUY-PN-SECOND-ORDER', 'PCPN-SECOND-ORDER', 'PN-SECOND-ORDER'],
            collect($supplier['entries'])->pluck('code')->all(),
        );
        $this->assertSame(50.0, $supplier['summary']['virtual_opening_balance']);
        $this->assertSame(1, $supplier['summary']['hidden_reconciliation_checkpoint_count']);
        $this->assertSame(3, $supplier['summary']['entry_count']);
        $this->assertSame(120.0, (float) $supplier['entries'][0]['supplier_display_running_balance']);
        $this->assertSame(-120.0, (float) $customer['entries'][0]['customer_display_running_balance']);

        foreach (array_merge($supplier['entries'], $customer['entries']) as $entry) {
            $this->assertFalse(str_starts_with((string) ($entry['code'] ?? ''), 'CHECKPOINT-'));
            $this->assertArrayNotHasKey('badge_label', $entry);
            $this->assertArrayNotHasKey('badge_title', $entry);
            $this->assertArrayNotHasKey('note', $entry);
            $this->assertArrayNotHasKey('description', $entry);
        }
    }

    public function test_checkpoint_is_absorbed_into_excel_opening_with_note_column(): void
    {
        $projected = app(PartnerDebtPublicTimelineService::class)
            ->project($this->timelineFixture(), 'supplier');
        $supplier = new Customer([
            'code' => 'NCC-PUBLIC-TIMELINE',
            'name' => 'Nhà cung cấp kiểm thử',
        ]);
        $sheet = (new SupplierDebtExcelExportService(
            $projected['entries'],
            $supplier,
            null,
            null,
            false,
            [],
            (float) $projected['summary']['virtual_opening_balance'],
        ))->build()->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true);
        $text = implode(' ', array_map(
            static fn (array $row): string => implode(' ', array_filter($row, static fn ($value): bool => $value !== null && $value !== '')),
            $rows,
        ));

        $this->assertSame('N', $sheet->getHighestColumn());
        $this->assertStringNotContainsString('CHECKPOINT-', $text);
        $this->assertStringContainsString('Ghi chú', $text);
        $this->assertStringContainsString('HUY-PN-SECOND-ORDER', $text);

        $cancelRow = collect($rows)->first(fn (array $row): bool => ($row['B'] ?? null) === 'HUY-PN-SECOND-ORDER');
        $this->assertNotNull($cancelRow);
        $this->assertSame(120.0, (float) ($cancelRow['M'] ?? 0));
    }

    public function test_existing_virtual_opening_is_preserved_without_double_counting_checkpoint(): void
    {
        $timeline = [
            'entries' => [
                [
                    'code' => 'OPENING-BALANCE-SUPPLIER-1',
                    'event_identity' => 'virtual-opening|1',
                    'event_kind' => 'virtual_opening_balance',
                    'event_order' => 1,
                    'business_time' => '2026-07-30 14:08:58',
                    'supplier_display_effect' => 25,
                    'customer_display_effect' => -25,
                    'affects_debt_balance' => false,
                    'is_virtual_opening' => true,
                    'badge_label' => 'Số dư đầu kỳ',
                ],
                [
                    'code' => 'CHECKPOINT-VIRTUAL-OPENING',
                    'event_identity' => 'checkpoint|virtual-opening',
                    'event_kind' => 'persisted_ledger_checkpoint',
                    'event_order' => 2,
                    'business_time' => '2026-07-30 14:08:59',
                    'supplier_display_effect' => 50,
                    'customer_display_effect' => -50,
                ],
                [
                    'code' => 'PN-VIRTUAL-OPENING',
                    'event_identity' => 'purchase|virtual-opening',
                    'event_kind' => 'purchase',
                    'event_order' => 3,
                    'business_time' => '2026-07-30 14:09:00',
                    'supplier_display_effect' => 100,
                    'customer_display_effect' => -100,
                ],
            ],
            'summary' => [
                'has_virtual_opening_balance' => true,
                'virtual_opening_balance' => 25,
            ],
        ];

        $projected = app(PartnerDebtPublicTimelineService::class)->project($timeline, 'supplier');

        $this->assertSame(75.0, (float) $projected['summary']['virtual_opening_balance']);
        $this->assertSame(50.0, (float) $projected['summary']['hidden_reconciliation_adjustment']);
        $this->assertSame(175.0, (float) $projected['entries'][0]['supplier_display_running_balance']);
        $opening = collect($projected['entries'])->firstWhere('event_kind', 'virtual_opening_balance');
        $this->assertNotNull($opening);
        $this->assertArrayNotHasKey('badge_label', $opening);
    }

    public function test_supplier_and_customer_debt_ui_do_not_render_public_row_badges_notes_or_local_debt_sort(): void
    {
        $supplierVue = file_get_contents(resource_path('js/Pages/Suppliers/Index.vue'));
        $customerVue = file_get_contents(resource_path('js/Pages/Customers/Index.vue'));

        $this->assertStringNotContainsString('supplierDebtEntryBadge', $supplierVue);
        $this->assertStringNotContainsString('sortedSupplierDebt', $supplierVue);
        $this->assertStringNotContainsString('isReconciliationCheckpoint', $supplierVue);
        $this->assertStringNotContainsString("{ key: 'note', label: 'Ghi chú dòng' }", $supplierVue);
        $this->assertStringNotContainsString('customerDebtEntryBadge', $customerVue);
        $this->assertStringNotContainsString("{ key: 'note', label: 'Ghi chú dòng' }", $customerVue);
    }

    public function test_checkpoint_is_removed_before_supplier_pagination(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-PAGINATION-'.uniqid(),
            'name' => 'Nhà cung cấp phân trang',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 16,
        ]);
        $admin = User::create([
            'name' => 'Admin public debt timeline',
            'email' => 'public-debt-timeline-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $entries = collect(range(1, 11))->map(fn (int $index): array => [
            'code' => 'PN-PAGE-'.$index,
            'event_identity' => 'purchase|'.$index,
            'event_kind' => 'purchase',
            'event_order' => $index,
            'business_time' => sprintf('2026-07-30 14:09:%02d', $index),
            'type_label' => 'Nhập hàng',
            'supplier_display_effect' => 1,
            'customer_display_effect' => -1,
        ])->push([
            'code' => 'CHECKPOINT-PAGE',
            'event_identity' => 'checkpoint|page',
            'event_kind' => 'persisted_ledger_checkpoint',
            'business_time' => '2026-07-30 14:09:06',
            'supplier_display_effect' => 5,
            'customer_display_effect' => -5,
        ])->all();
        $timeline = [
            'entries' => $entries,
            'summary' => ['target_balance' => 16],
            'target_balance' => 16,
        ];
        $mock = Mockery::mock(SupplierDebtDocumentTimelineService::class);
        $mock->shouldReceive('build')->once()->andReturn($timeline);
        $this->app->instance(SupplierDebtDocumentTimelineService::class, $mock);

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?page=1&per_page=10");

        $response->assertOk()
            ->assertJsonPath('pagination.total', 11)
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonCount(10, 'entries')
            ->assertJsonPath('summary.virtual_opening_balance', 5);
        $this->assertFalse(collect($response->json('entries'))->contains(
            fn (array $entry): bool => str_starts_with((string) ($entry['code'] ?? ''), 'CHECKPOINT-'),
        ));
    }

    public function test_supplier_csv_excludes_checkpoint_but_exports_source_notes_and_cancelled_row(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-CSV-PUBLIC-'.uniqid(),
            'name' => 'Nhà cung cấp xuất CSV',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 120,
        ]);
        $admin = User::create([
            'name' => 'Admin supplier CSV public timeline',
            'email' => 'supplier-csv-public-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $mock = Mockery::mock(SupplierDebtDocumentTimelineService::class);
        $mock->shouldReceive('build')->once()->andReturn($this->timelineFixture());
        $this->app->instance(SupplierDebtDocumentTimelineService::class, $mock);

        $response = $this->actingAs($admin)->get(
            "/api/suppliers/{$supplier->id}/export-debt?format=csv&date_preset=all&include_detail=0",
        );

        $response->assertOk();
        $csv = $response->streamedContent() ?: $response->getContent();
        $this->assertStringContainsString('HUY-PN-SECOND-ORDER', $csv);
        $this->assertStringNotContainsString('CHECKPOINT-', $csv);
        $this->assertStringContainsString('Ghi chú', $csv);
        $this->assertStringContainsString('Không xuất ghi chú', $csv);
        $this->assertStringContainsString('Ghi chú nội bộ', $csv);
        $this->assertStringNotContainsString('Không được đưa ra giao diện', $csv);
    }

    private function timelineFixture(): array
    {
        return [
            'entries' => [
                [
                    'code' => 'CHECKPOINT-CKNCC-SECOND-ORDER',
                    'event_identity' => 'checkpoint|1',
                    'event_kind' => 'persisted_ledger_checkpoint',
                    'business_time' => '2026-07-30 14:09:00',
                    'customer_display_effect' => -50,
                    'supplier_display_effect' => 50,
                    'badge_label' => 'Không phải phiếu',
                    'badge_title' => 'Đối chiếu lịch sử',
                    'note' => 'Không được đưa ra giao diện',
                ],
                [
                    'code' => 'PN-SECOND-ORDER',
                    'event_identity' => 'purchase|1',
                    'event_kind' => 'purchase',
                    'event_order' => 10,
                    'business_time' => '2026-07-30 14:08:59',
                    'type_label' => 'Nhập hàng',
                    'customer_display_effect' => -100,
                    'supplier_display_effect' => 100,
                    'badge_label' => 'Phiếu nhập',
                    'description' => 'Ghi chú nội bộ',
                ],
                [
                    'code' => 'PCPN-SECOND-ORDER',
                    'event_identity' => 'payment|1',
                    'event_kind' => 'supplier_payment',
                    'event_order' => 20,
                    'business_time' => '2026-07-30 14:09:00',
                    'type_label' => 'Thanh toán NCC',
                    'customer_display_effect' => 20,
                    'supplier_display_effect' => -20,
                    'badge_label' => 'Thanh toán',
                    'note' => 'Không xuất ghi chú',
                ],
                [
                    'code' => 'HUY-PN-SECOND-ORDER',
                    'event_identity' => 'purchase|1|cancel',
                    'event_kind' => 'purchase_cancel_reversal',
                    'event_order' => 30,
                    'business_time' => '2026-07-30 14:09:01',
                    'type_label' => 'Hủy phiếu nhập',
                    'customer_display_effect' => 10,
                    'supplier_display_effect' => -10,
                    'badge_label' => 'Phải trả NCC',
                ],
            ],
            'summary' => [
                'target_balance' => 120,
            ],
        ];
    }
}
