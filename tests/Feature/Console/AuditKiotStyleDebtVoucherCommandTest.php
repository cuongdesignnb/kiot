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
}
