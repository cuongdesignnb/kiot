<?php

namespace Tests\Feature\Customers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CustomerDebtTimelineBusinessTimeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Business Time',
            'email' => 'admin-business-time-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'KH-BTIME-'.uniqid(),
            'name' => 'Customer Business Time',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    public function test_customer_invoice_timeline_uses_transaction_date_and_keeps_created_at(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => 8_600_000]);
        $businessTime = Carbon::parse('2026-05-24 16:17:00');
        $createdAt = Carbon::parse('2026-06-02 16:22:00');

        $invoice = Invoice::create([
            'code' => 'HD-BTIME-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 8_600_000,
            'total' => 8_600_000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'transaction_date' => $businessTime,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=100&page=1");

        $response->assertOk();
        $entry = collect($response->json('entries'))->firstWhere('code', $invoice->code);

        $this->assertNotNull($entry);
        $this->assertEntryTimeEquals('2026-05-24 16:17:00', $entry['display_time']);
        $this->assertEntryTimeEquals('2026-05-24 16:17:00', $entry['time']);
        $this->assertEntryTimeEquals('2026-06-02 16:22:00', $entry['created_at']);
    }

    public function test_customer_payment_timeline_uses_cashflow_time_and_keeps_created_at(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => -500_000]);
        $businessTime = Carbon::parse('2026-05-29 09:00:00');
        $createdAt = Carbon::parse('2026-06-02 16:23:00');

        $cashFlow = CashFlow::create([
            'code' => 'PT-BTIME-'.uniqid(),
            'type' => 'receipt',
            'amount' => 500_000,
            'time' => $businessTime,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'ManualPayment',
            'reference_code' => null,
            'status' => 'active',
        ]);
        $cashFlow->created_at = $createdAt;
        $cashFlow->updated_at = $createdAt;
        $cashFlow->save();

        $response = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=100&page=1");

        $response->assertOk();
        $entry = collect($response->json('entries'))->firstWhere('code', $cashFlow->code);

        $this->assertNotNull($entry);
        $this->assertEntryTimeEquals('2026-05-29 09:00:00', $entry['display_time']);
        $this->assertEntryTimeEquals('2026-06-02 16:23:00', $entry['created_at']);
    }

    public function test_supplier_purchase_timeline_uses_purchase_date_and_keeps_created_at(): void
    {
        $admin = $this->admin();
        $supplier = $this->customer([
            'code' => 'NCC-BTIME-'.uniqid(),
            'name' => 'Supplier Business Time',
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => 1_000_000,
        ]);
        $businessTime = Carbon::parse('2026-05-23 14:30:00');
        $createdAt = Carbon::parse('2026-06-02 10:00:00');

        $purchase = Purchase::create([
            'code' => 'PN-BTIME-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 1_000_000,
            'paid_amount' => 0,
            'debt_amount' => 1_000_000,
            'status' => 'completed',
            'purchase_date' => $businessTime,
        ]);
        $purchase->created_at = $createdAt;
        $purchase->updated_at = $createdAt;
        $purchase->save();

        $response = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?per_page=100&page=1");

        $response->assertOk();
        $entry = collect($response->json('entries'))->firstWhere('code', $purchase->code);

        $this->assertNotNull($entry);
        $this->assertEntryTimeEquals('2026-05-23 14:30:00', $entry['display_time']);
        $this->assertEntryTimeEquals('2026-05-23 14:30:00', $entry['time']);
        $this->assertEntryTimeEquals('2026-06-02 10:00:00', $entry['created_at']);
    }

    public function test_order_return_timeline_uses_return_date_when_schema_has_column(): void
    {
        if (! Schema::hasColumn('returns', 'return_date')) {
            $this->markTestSkipped('returns.return_date is not present in this schema.');
        }

        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => -250_000]);
        $businessTime = Carbon::parse('2026-05-25 11:05:00');
        $createdAt = Carbon::parse('2026-06-02 11:05:00');

        $return = OrderReturn::create([
            'code' => 'TH-BTIME-'.uniqid(),
            'customer_id' => $customer->id,
            'status' => 'Đã trả',
            'subtotal' => 250_000,
            'total' => 250_000,
            'paid_to_customer' => 0,
            'return_date' => $businessTime,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?per_page=100&page=1");

        $response->assertOk();
        $entry = collect($response->json('entries'))->firstWhere('code', $return->code);

        $this->assertNotNull($entry);
        $this->assertEntryTimeEquals('2026-05-25 11:05:00', $entry['display_time']);
        $this->assertEntryTimeEquals('2026-06-02 11:05:00', $entry['created_at']);
    }

    public function test_customer_csv_export_uses_business_date(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => 8_600_000]);

        $invoice = Invoice::create([
            'code' => 'HD-CSV-BTIME-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 8_600_000,
            'total' => 8_600_000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'note' => 'CUSTOMER CSV SOURCE NOTE',
            'transaction_date' => Carbon::parse('2026-05-24 16:17:00'),
            'created_at' => Carbon::parse('2026-06-02 16:22:00'),
            'updated_at' => Carbon::parse('2026-06-02 16:22:00'),
        ]);

        $response = $this->actingAs($admin)->get("/customers/{$customer->id}/export-debt");

        $response->assertOk();
        $content = $response->streamedContent() ?: $response->getContent();

        $this->assertStringContainsString('24/05/2026 16:17', $content);
        $this->assertStringNotContainsString('02/06/2026 16:22', $content);
        $this->assertStringContainsString('Ghi chú', $content);
        $this->assertStringContainsString('CUSTOMER CSV SOURCE NOTE', $content);
        $this->assertSame('CUSTOMER CSV SOURCE NOTE', $invoice->fresh()->note);
    }

    public function test_customer_xlsx_export_uses_business_date(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => 8_600_000]);

        $invoice = Invoice::create([
            'code' => 'HD-XLSX-BTIME-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 8_600_000,
            'total' => 8_600_000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'note' => 'CUSTOMER XLSX SOURCE NOTE',
            'transaction_date' => Carbon::parse('2026-05-24 16:17:00'),
            'created_at' => Carbon::parse('2026-06-02 16:22:00'),
            'updated_at' => Carbon::parse('2026-06-02 16:22:00'),
        ]);

        $response = $this->actingAs($admin)
            ->get("/customers/{$customer->id}/export-debt?format=xlsx&date_preset=all");

        $response->assertOk();
        $body = $response->streamedContent() ?: $response->getContent();
        $tmp = tempnam(sys_get_temp_dir(), 'customer-business-time-').'.xlsx';
        file_put_contents($tmp, $body);

        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $highestColumn = $sheet->getHighestColumn();
            $values = [];
            foreach ($sheet->toArray(null, true, true, true) as $row) {
                foreach ($row as $value) {
                    if ($value !== null && $value !== '') {
                        $values[] = (string) $value;
                    }
                }
            }
            $text = implode(' ', $values);
        } finally {
            @unlink($tmp);
        }

        $this->assertStringContainsString('24/05/2026 16:17', $text);
        $this->assertStringNotContainsString('02/06/2026 16:22', $text);
        $this->assertStringContainsString('CUSTOMER XLSX SOURCE NOTE', $text);
        $this->assertSame('M', $highestColumn);
        $this->assertSame('CUSTOMER XLSX SOURCE NOTE', $invoice->fresh()->note);
    }

    public function test_customer_checkpoint_is_hidden_before_pagination_and_csv_export(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(['debt_amount' => 16]);
        $entries = collect(range(1, 11))->map(fn (int $index): array => [
            'code' => $index === 11 ? 'HUY-HD-PAGE-11' : 'HD-PAGE-'.$index,
            'event_identity' => 'invoice|'.$index,
            'event_kind' => $index === 11 ? 'invoice_cancel_reversal' : 'customer_sale',
            'event_order' => $index,
            'business_time' => sprintf('2026-07-30 14:09:%02d', $index),
            'display_type' => $index === 11 ? 'Hủy hóa đơn' : 'Bán hàng',
            'customer_display_effect' => 1,
            'supplier_display_effect' => -1,
            'badge_label' => $index === 11 ? 'Phải thu KH' : 'Hóa đơn',
            'note' => 'CUSTOMER NOTE EXPORTED OUTSIDE TIMELINE',
        ])->push([
            'code' => 'CHECKPOINT-CUSTOMER-PAGE',
            'event_identity' => 'checkpoint|customer-page',
            'event_kind' => 'persisted_ledger_checkpoint',
            'business_time' => '2026-07-30 14:09:06',
            'customer_display_effect' => 5,
            'supplier_display_effect' => -5,
        ])->all();
        $timeline = [
            'entries' => $entries,
            'summary' => ['target_balance' => 16],
            'target_balance' => 16,
        ];
        $mock = Mockery::mock(CustomerDebtDocumentTimelineService::class);
        $mock->shouldReceive('build')->twice()->andReturn($timeline);
        $this->app->instance(CustomerDebtDocumentTimelineService::class, $mock);

        $timelineResponse = $this->actingAs($admin)
            ->getJson("/customers/{$customer->id}/debt-history?page=1&per_page=10");

        $timelineResponse->assertOk()
            ->assertJsonPath('pagination.total', 11)
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonCount(10, 'entries')
            ->assertJsonPath('summary.virtual_opening_balance', 5);
        $this->assertFalse(collect($timelineResponse->json('entries'))->contains(
            fn (array $entry): bool => str_starts_with((string) ($entry['code'] ?? ''), 'CHECKPOINT-'),
        ));

        $exportResponse = $this->actingAs($admin)->get(
            "/customers/{$customer->id}/export-debt?format=csv&date_preset=all&include_detail=0",
        );
        $exportResponse->assertOk();
        $csv = $exportResponse->streamedContent() ?: $exportResponse->getContent();
        $this->assertStringContainsString('HUY-HD-PAGE-11', $csv);
        $this->assertStringNotContainsString('CHECKPOINT-', $csv);
        $this->assertStringContainsString('Ghi chú', $csv);
        $this->assertStringContainsString('CUSTOMER NOTE EXPORTED OUTSIDE TIMELINE', $csv);
    }

    private function assertEntryTimeEquals(string $expected, string $actual): void
    {
        $this->assertSame(
            $expected,
            Carbon::parse($actual)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
        );
    }
}
