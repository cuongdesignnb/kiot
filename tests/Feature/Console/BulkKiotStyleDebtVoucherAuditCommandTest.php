<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STEP 10C — bulk read-only audit across all customers / suppliers.
 */
class BulkKiotStyleDebtVoucherAuditCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(string $code, bool $supplier = false): Customer
    {
        return Customer::create([
            'code' => $code . '-' . uniqid(), 'name' => 'P ' . $code,
            'debt_amount' => 0, 'supplier_debt_amount' => 0,
            'is_customer' => !$supplier, 'is_supplier' => $supplier,
        ]);
    }

    private function paidInvoice(Customer $c, string $code, int $total, int $paid): Invoice
    {
        $inv = Invoice::create([
            'code' => $code, 'customer_id' => $c->id, 'total' => $total,
            'customer_paid' => $paid, 'status' => 'Hoàn thành',
        ]);
        $inv->created_at = Carbon::now()->subDays(2);
        $inv->save();
        return $inv;
    }

    private function receipt(Customer $c, string $code, string $invoiceCode, int $amount): CashFlow
    {
        return CashFlow::create([
            'code' => $code, 'type' => 'receipt', 'amount' => $amount,
            'time' => Carbon::now()->subDays(2), 'category' => 'Thu tiền khách hàng',
            'target_type' => 'Khách hàng', 'target_id' => $c->id,
            'reference_type' => 'Invoice', 'reference_code' => $invoiceCode, 'status' => 'completed',
        ]);
    }

    public function test_requires_dry_run(): void
    {
        $this->artisan('debt:audit-kiot-vouchers', ['--all' => true])
            ->expectsOutputToContain('read-only')
            ->assertExitCode(1);
    }

    public function test_no_mode_fails(): void
    {
        $this->artisan('debt:audit-kiot-vouchers', ['--dry-run' => true])
            ->assertExitCode(1);
    }

    public function test_single_and_bulk_together_fails(): void
    {
        $this->artisan('debt:audit-kiot-vouchers', ['--dry-run' => true, '--all' => true, '--customer-code' => 'X'])
            ->assertExitCode(1);
    }

    public function test_all_customers_scans_and_classifies(): void
    {
        // OK customer (clean invoice w/ matching receipt)
        $ok = $this->customer('BULK-OK');
        $this->paidInvoice($ok, 'HD-BULK-OK', 1_000_000, 1_000_000);
        $this->receipt($ok, 'PT-BULK-OK', 'HD-BULK-OK', 1_000_000);

        // Fallback customer (paid, no receipt)
        $fb = $this->customer('BULK-FB');
        $this->paidInvoice($fb, 'HD-BULK-FB', 2_000_000, 2_000_000);

        // Mismatch customer (receipt total != customer_paid)
        $mm = $this->customer('BULK-MM');
        $this->paidInvoice($mm, 'HD-BULK-MM', 10_000_000, 10_000_000);
        $this->receipt($mm, 'PT-BULK-MM', 'HD-BULK-MM', 8_000_000);

        $json = storage_path('app/test-bulk-' . uniqid() . '.json');
        $this->artisan('debt:audit-kiot-vouchers', [
            '--dry-run' => true, '--all-customers' => true, '--export-json' => $json,
        ])->assertExitCode(0);

        $report = json_decode(file_get_contents($json), true);
        $this->assertSame('all-customers', $report['mode']);
        $this->assertTrue($report['dry_run']);
        $this->assertGreaterThanOrEqual(3, $report['summary']['customers_scanned']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['virtual_fallbacks']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['receipt_allocation_mismatches']);
        $this->assertEquals(0, $report['summary']['clickable_fallback_rows']);
        @unlink($json);
    }

    public function test_only_risk_filters_clean_partners(): void
    {
        $ok = $this->customer('OR-OK');
        $this->paidInvoice($ok, 'HD-OR-OK', 1_000_000, 1_000_000);
        $this->receipt($ok, 'PT-OR-OK', 'HD-OR-OK', 1_000_000);

        $fb = $this->customer('OR-FB');
        $this->paidInvoice($fb, 'HD-OR-FB', 2_000_000, 2_000_000); // fallback → risk

        $json = storage_path('app/test-onlyrisk-' . uniqid() . '.json');
        $this->artisan('debt:audit-kiot-vouchers', [
            '--dry-run' => true, '--all-customers' => true, '--only-risk' => true, '--export-json' => $json,
        ])->assertExitCode(0);

        $report = json_decode(file_get_contents($json), true);
        $codes = collect($report['partners'])->pluck('partner.code')->all();
        $this->assertContains($fb->code, $codes, 'risk partner included');
        $this->assertNotContains($ok->code, $codes, 'clean partner excluded by --only-risk');
        @unlink($json);
    }

    public function test_limit_caps_scan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->customer('LIM-' . $i);
        }
        $json = storage_path('app/test-limit-' . uniqid() . '.json');
        $this->artisan('debt:audit-kiot-vouchers', [
            '--dry-run' => true, '--all-customers' => true, '--limit' => 2, '--export-json' => $json,
        ])->assertExitCode(0);

        $report = json_decode(file_get_contents($json), true);
        $this->assertEquals(2, $report['summary']['customers_scanned']);
        @unlink($json);
    }

    public function test_csv_and_md_exports(): void
    {
        $fb = $this->customer('EXP-FB');
        $this->paidInvoice($fb, 'HD-EXP-FB', 2_000_000, 2_000_000);

        $base = 'app/test-exp-' . uniqid();
        $csv = storage_path($base . '.csv');
        $md = storage_path($base . '.md');
        $this->artisan('debt:audit-kiot-vouchers', [
            '--dry-run' => true, '--all-customers' => true, '--only-risk' => true,
            '--export-csv' => $csv, '--export-md' => $md,
        ])->assertExitCode(0);

        $this->assertFileExists($csv);
        $this->assertFileExists($md);
        $this->assertStringContainsString('severity,risk,view,partner_id', file_get_contents($csv));
        $this->assertStringContainsString('Bulk Kiot-style debt voucher audit', file_get_contents($md));
        @unlink($csv);
        @unlink($md);
    }

    public function test_no_db_writes(): void
    {
        $this->customer('NW-1');
        $c = $this->customer('NW-2');
        $this->paidInvoice($c, 'HD-NW', 1_000_000, 1_000_000);

        $before = [
            'customers' => Customer::count(),
            'invoices' => Invoice::count(),
            'cash_flows' => CashFlow::count(),
            'purchases' => Purchase::count(),
        ];

        $this->artisan('debt:audit-kiot-vouchers', ['--dry-run' => true, '--all' => true])
            ->assertExitCode(0);

        $this->assertEquals($before['customers'], Customer::count());
        $this->assertEquals($before['invoices'], Invoice::count());
        $this->assertEquals($before['cash_flows'], CashFlow::count());
        $this->assertEquals($before['purchases'], Purchase::count());
    }
}
