<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STEP 10 — Read-only audit command guard + classification.
 */
class AuditKiotStyleDebtVoucherCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_requires_dry_run_flag(): void
    {
        $this->artisan('debt:audit-kiot-vouchers --customer-code=NOPE')
            ->expectsOutputToContain('read-only')
            ->assertExitCode(1);
    }

    public function test_dry_run_classifies_real_and_fallback(): void
    {
        $c = Customer::create([
            'code' => 'AUD-' . uniqid(), 'name' => 'Audit KH',
            'debt_amount' => 0, 'is_customer' => true, 'is_supplier' => false,
        ]);
        $base = Carbon::now()->subDays(2);

        // Real receipt invoice
        $inv1 = Invoice::create(['code' => 'HD-AUD-1', 'customer_id' => $c->id, 'total' => 1_000_000, 'customer_paid' => 1_000_000, 'status' => 'Hoàn thành']);
        $inv1->created_at = $base; $inv1->save();
        CashFlow::create([
            'code' => 'PT-AUD-1', 'type' => 'receipt', 'amount' => 1_000_000, 'time' => $base,
            'category' => 'Thu tiền khách hàng', 'target_type' => 'Khách hàng', 'target_id' => $c->id,
            'reference_type' => 'Invoice', 'reference_code' => 'HD-AUD-1', 'status' => 'completed',
        ]);

        // Fallback invoice (paid, no receipt)
        $inv2 = Invoice::create(['code' => 'HD-AUD-2', 'customer_id' => $c->id, 'total' => 2_000_000, 'customer_paid' => 2_000_000, 'status' => 'Hoàn thành']);
        $inv2->created_at = $base->copy()->addHour(); $inv2->save();

        $this->artisan("debt:audit-kiot-vouchers --dry-run --customer-code={$c->code}")
            ->expectsOutputToContain('No data was modified')
            ->assertExitCode(0);
    }

    public function test_dry_run_json_reports_multi_receipt_and_non_clickable_fallback(): void
    {
        $c = Customer::create([
            'code' => 'AUD2-' . uniqid(), 'name' => 'Audit KH2',
            'debt_amount' => 0, 'is_customer' => true, 'is_supplier' => false,
        ]);
        $base = Carbon::now()->subDays(2);

        // Invoice with TWO real receipts
        $inv1 = Invoice::create(['code' => 'HD-AUD2-1', 'customer_id' => $c->id, 'total' => 10_000_000, 'customer_paid' => 10_000_000, 'status' => 'Hoàn thành']);
        $inv1->created_at = $base; $inv1->save();
        foreach ([['PT-AUD2-1', 3_000_000], ['PT-AUD2-2', 7_000_000]] as [$code, $amt]) {
            CashFlow::create([
                'code' => $code, 'type' => 'receipt', 'amount' => $amt, 'time' => $base,
                'category' => 'Thu tiền khách hàng', 'target_type' => 'Khách hàng', 'target_id' => $c->id,
                'reference_type' => 'Invoice', 'reference_code' => 'HD-AUD2-1', 'status' => 'completed',
            ]);
        }

        // Invoice with fallback (paid, no receipt)
        $inv2 = Invoice::create(['code' => 'HD-AUD2-2', 'customer_id' => $c->id, 'total' => 2_000_000, 'customer_paid' => 2_000_000, 'status' => 'Hoàn thành']);
        $inv2->created_at = $base->copy()->addHour(); $inv2->save();

        $jsonPath = storage_path('app/test-audit-' . uniqid() . '.json');
        $this->artisan('debt:audit-kiot-vouchers', [
            '--dry-run' => true,
            '--customer-code' => $c->code,
            '--export-json' => $jsonPath,
        ])->assertExitCode(0);

        $this->assertFileExists($jsonPath);
        $report = json_decode(file_get_contents($jsonPath), true);

        $group = collect($report['invoice_receipt_groups'])->firstWhere('invoice_code', 'HD-AUD2-1');
        $this->assertNotNull($group);
        $this->assertEquals(2, $group['real_receipt_count']);
        $this->assertEquals(10_000_000, $group['real_receipt_total']);
        $this->assertFalse($group['is_mismatch']);

        $this->assertEquals(1, $report['summary']['fallback_rows']);
        $this->assertEquals(1, $report['summary']['non_clickable_fallback_rows']);
        $this->assertEquals(0, $report['summary']['clickable_fallback_rows']);
        $this->assertEquals(0, $report['summary']['receipt_allocation_mismatches']);

        @unlink($jsonPath);
    }
}
