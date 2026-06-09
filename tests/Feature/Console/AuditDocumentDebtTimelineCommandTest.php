<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\OrderReturn;
use App\Models\CustomerDebt;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditDocumentDebtTimelineCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function createTestCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'KH-AUDIT-' . uniqid(),
            'name' => 'Audit Test Customer',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0.0,
            'supplier_debt_amount' => 0.0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Test 1 — requires dry-run
     */
    public function test_requires_dry_run_option(): void
    {
        $this->artisan('debt:audit-document-timeline --all-customers')
            ->assertFailed()
            ->expectsOutputToContain('This command is read-only. Please pass --dry-run. No data was modified.');
    }

    /**
     * Test 2 — no mode fails
     */
    public function test_no_mode_fails(): void
    {
        $this->artisan('debt:audit-document-timeline --dry-run')
            ->assertFailed()
            ->expectsOutputToContain('Provide a single (--customer-code/--supplier-code) or bulk (--all/--all-customers/--all-suppliers) mode.');
    }

    /**
     * Test 3 — single customer detects MERGE mismatch
     */
    public function test_single_customer_detects_merge_mismatch(): void
    {
        // Document final will be 800k - 500k + 2M = 2.3M
        // DB net is 300k, so mismatch is 2M
        $customer = $this->createTestCustomer(['debt_amount' => 2300000]);

        Invoice::create([
            'code' => 'HD-AUDIT-003',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        CashFlow::create([
            'code' => 'PT-AUDIT-003',
            'type' => 'receipt',
            'amount' => 500000,
            'target_type' => 'Khách hàng',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'Invoice',
            'reference_code' => 'HD-AUDIT-003',
            'status' => 'active',
            'time' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        // Merge row
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'MERGE-CUSTOMER-239',
            'amount' => 2000000,
            'debt_total' => 2000000,
            'type' => 'adjustment',
            'recorded_at' => Carbon::now(),
        ]);

        $this->artisan("debt:audit-document-timeline --dry-run --customer-code={$customer->code}")
            ->assertSuccessful()
            ->expectsOutputToContain("document_balance_mismatch_critical")
            ->expectsOutputToContain("merge_affects_balance");
    }

    /**
     * Test 4 — invoice invariant
     */
    public function test_invoice_invariant(): void
    {
        $customer = $this->createTestCustomer(['debt_amount' => 300000]);

        Invoice::create([
            'code' => 'HD-AUDIT-004',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $this->artisan("debt:audit-document-timeline --dry-run --customer-code={$customer->code}")
            ->assertSuccessful();
        // Since invoice total matches display effect (800k), invoice_display_not_total should not be triggered
    }

    /**
     * Test 5 — return invariant
     */
    public function test_return_invariant(): void
    {
        $customer = $this->createTestCustomer(['debt_amount' => -1000000]);

        OrderReturn::create([
            'code' => 'TH-AUDIT-005',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 1000000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::now(),
        ]);

        $this->artisan("debt:audit-document-timeline --dry-run --customer-code={$customer->code}")
            ->assertSuccessful();
        // Return has correct negative effect (-1M), so return_not_negative should not be triggered
    }

    /**
     * Test 7 — only-mismatch filters OK partners
     */
    public function test_only_mismatch_filters_ok_partners(): void
    {
        // 1 mismatch partner
        $c1 = $this->createTestCustomer(['debt_amount' => 1000000]);
        Invoice::create([
            'code' => 'HD-AUDIT-007A',
            'customer_id' => $c1->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 0,
            'created_at' => Carbon::now(),
        ]);

        // 1 OK partner (stored = document final)
        $c2 = $this->createTestCustomer(['debt_amount' => 800000]);
        Invoice::create([
            'code' => 'HD-AUDIT-007B',
            'customer_id' => $c2->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 0,
            'created_at' => Carbon::now(),
        ]);

        $jsonPath = storage_path('app/test-mismatch.json');
        File::delete($jsonPath);

        $this->artisan('debt:audit-document-timeline', [
            '--dry-run' => true,
            '--all-customers' => true,
            '--only-mismatch' => true,
            '--export-json' => $jsonPath,
        ])->assertSuccessful();

        $this->assertTrue(File::exists($jsonPath));
        $data = json_decode(File::get($jsonPath), true);
        $partnerCodes = collect($data['partners'])->pluck('partner.code');

        $this->assertTrue($partnerCodes->contains($c1->code));
        $this->assertFalse($partnerCodes->contains($c2->code));

        File::delete($jsonPath);
    }

    /**
     * Test 8 — export JSON/CSV/MD
     */
    public function test_export_files_generation(): void
    {
        $customer = $this->createTestCustomer(['debt_amount' => 300000]);
        Invoice::create([
            'code' => 'HD-AUDIT-008',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $jsonPath = storage_path('app/test-bulk.json');
        $csvPath = storage_path('app/test-bulk.csv');
        $mdPath = storage_path('app/test-bulk.md');

        File::delete([$jsonPath, $csvPath, $mdPath]);

        $this->artisan('debt:audit-document-timeline', [
            '--dry-run' => true,
            '--all-customers' => true,
            '--export-json' => $jsonPath,
            '--export-csv' => $csvPath,
            '--export-md' => $mdPath,
        ])->assertSuccessful();

        $this->assertTrue(File::exists($jsonPath));
        $this->assertTrue(File::exists($csvPath));
        $this->assertTrue(File::exists($mdPath));

        $this->assertStringContainsString('severity,risk,view', File::get($csvPath));
        $this->assertStringContainsString('# STEP 10E — Bulk audit', File::get($mdPath));

        File::delete([$jsonPath, $csvPath, $mdPath]);
    }

    /**
     * Test 9 — limit works
     */
    public function test_limit_option_works(): void
    {
        $this->createTestCustomer();
        $this->createTestCustomer();
        $this->createTestCustomer();

        $this->artisan('debt:audit-document-timeline --dry-run --all-customers --limit=2 --summary-only')
            ->assertSuccessful()
            ->expectsOutputToContain('partners_scanned');
    }

    /**
     * Test 10 — no DB writes
     */
    public function test_no_db_writes_during_audit(): void
    {
        $customer = $this->createTestCustomer(['debt_amount' => 300000]);
        Invoice::create([
            'code' => 'HD-AUDIT-010',
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'total' => 800000,
            'customer_paid' => 500000,
            'created_at' => Carbon::now(),
        ]);

        $countsBefore = [
            'customers' => Customer::count(),
            'invoices' => Invoice::count(),
            'cash_flows' => CashFlow::count(),
            'returns' => OrderReturn::count(),
            'customer_debts' => CustomerDebt::count(),
            'purchases' => Purchase::count(),
        ];

        $this->artisan("debt:audit-document-timeline --dry-run --all-customers")
            ->assertSuccessful();

        $countsAfter = [
            'customers' => Customer::count(),
            'invoices' => Invoice::count(),
            'cash_flows' => CashFlow::count(),
            'returns' => OrderReturn::count(),
            'customer_debts' => CustomerDebt::count(),
            'purchases' => Purchase::count(),
        ];

        $this->assertSame($countsBefore, $countsAfter);
    }
}
