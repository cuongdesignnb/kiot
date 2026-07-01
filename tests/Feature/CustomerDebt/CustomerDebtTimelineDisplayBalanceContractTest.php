<?php

namespace Tests\Feature\CustomerDebt;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\CustomerDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtTimelineDisplayBalanceContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_timeline_latest_running_balance_matches_customer_screen_balance(): void
    {
        $partner = Customer::create([
            'code' => 'KH-DISPLAY-CONTRACT-' . uniqid(),
            'name' => 'Customer Display Contract',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 12_700_000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);

        Invoice::create([
            'code' => 'HD-DISPLAY-CONTRACT',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'total' => 1_900_000,
            'customer_paid' => 0,
            'transaction_date' => Carbon::now()->subMinute(),
            'created_at' => Carbon::now()->subMinute(),
        ]);

        $result = app(CustomerDebtDocumentTimelineService::class)->build($partner);
        $entries = collect($result['entries']);
        $latest = $entries->firstWhere('code', 'HD-DISPLAY-CONTRACT');

        $this->assertNotNull($latest);
        $this->assertSame(12_700_000.0, (float) $result['summary']['current_debt']);
        $this->assertSame(12_700_000.0, (float) $result['summary']['display_balance_target']);
        $this->assertSame(12_700_000.0, (float) $result['summary']['display_balance_final']);
        $this->assertSame(1_900_000.0, (float) $result['summary']['raw_document_final_balance']);
        $this->assertTrue((bool) $result['summary']['has_virtual_display_alignment']);
        $this->assertFalse((bool) $result['summary']['has_virtual_opening_balance']);
        $this->assertFalse((bool) $result['reconcile']['user_warning']);
        $this->assertSame(12_700_000.0, (float) $latest['customer_display_running_balance']);
        $this->assertSame(12_700_000.0, (float) $latest['running_balance']);
    }
}
