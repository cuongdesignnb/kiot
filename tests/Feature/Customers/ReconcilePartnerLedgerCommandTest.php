<?php

namespace Tests\Feature\Customers;

use Tests\TestCase;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class ReconcilePartnerLedgerCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reconcile_command_reconciles_and_outputs_correct_metrics(): void
    {
        $partner = Customer::create([
            'code' => 'KH177727496998',
            'name' => 'Anh Thanh-Thiên Phú',
            'phone' => '0974321888',
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // MERGE entry
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-CUSTOMER-141',
            'amount' => 47420000,
            'debt_total' => 47420000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        // CKTT entry
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'CKTT26052510573737',
            'amount' => -20000,
            'debt_total' => 47400000,
            'type' => 'payment',
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);

        // Purchase entry
        Purchase::create([
            'code' => 'PN20260523105400',
            'supplier_id' => $partner->id,
            'total_amount' => 75000000,
            'paid_amount' => 0,
            'debt_amount' => 75000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-23 10:54:00'),
        ]);

        // 1) Test search by code
        $exitCode = Artisan::call('customers:reconcile-partner-ledger', ['--code' => 'KH177727496998']);
        $output = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Customer Receivable (Phải thu)', $output);
        $this->assertStringContainsString('47,400,000.00', $output);
        $this->assertStringContainsString('Supplier Payable (Phải trả)', $output);
        $this->assertStringContainsString('75,000,000.00', $output);
        $this->assertStringContainsString('Partner Net Position (Vị thế ròng)', $output);
        $this->assertStringContainsString('-27,600,000.00', $output);
        $this->assertStringContainsString('MERGE-CUSTOMER-141', $output);
        $this->assertStringContainsString('CKTT26052510573737', $output);
        $this->assertStringContainsString('PN20260523105400', $output);

        // 2) Test search by name
        $exitCodeName = Artisan::call('customers:reconcile-partner-ledger', ['--name' => 'Thiên Phú']);
        $outputName = Artisan::output();
        $this->assertEquals(0, $exitCodeName);
        $this->assertStringContainsString('Partner Net Position (Vị thế ròng)', $outputName);

        // 3) Test search by phone
        $exitCodePhone = Artisan::call('customers:reconcile-partner-ledger', ['--phone' => '0974321888']);
        $outputPhone = Artisan::output();
        $this->assertEquals(0, $exitCodePhone);
        $this->assertStringContainsString('Partner Net Position (Vị thế ròng)', $outputPhone);

        $exitCodeJson = Artisan::call('customers:reconcile-partner-ledger', [
            '--customer-id' => $partner->id,
            '--json' => true,
        ]);
        $outputJson = Artisan::output();
        $this->assertEquals(0, $exitCodeJson);

        $startPos = strpos($outputJson, '{');
        $jsonString = ($startPos !== false) ? substr($outputJson, $startPos) : $outputJson;

        $decoded = json_decode($jsonString, true);
        $this->assertNotNull($decoded, 'Artisan output must be valid JSON: ' . $outputJson);
        $this->assertEquals($partner->id, $decoded['partner']['id']);
        $this->assertEquals(47400000, $decoded['reconciliation']['customer_receivable_cached']);
        $this->assertEquals(75000000, $decoded['reconciliation']['supplier_payable_cached']);
        $this->assertEquals(-27600000, $decoded['reconciliation']['net_cached']);

        // Check chronological order and entries count
        $entries = $decoded['entries'];
        $this->assertCount(3, $entries);
        $this->assertEquals('MERGE-CUSTOMER-141', $entries[0]['code']);
        $this->assertEquals('CKTT26052510573737', $entries[1]['code']);
        $this->assertEquals('PN20260523105400', $entries[2]['code']);
    }

    public function test_reconcile_command_handles_no_match(): void
    {
        $exitCode = Artisan::call('customers:reconcile-partner-ledger', ['--code' => 'NONEXISTENT']);
        $output = Artisan::output();
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No partner found matching the criteria.', $output);
    }

    public function test_reconcile_command_handles_ambiguous_matches(): void
    {
        // Delete any existing matches to make it clean
        Customer::where('name', 'like', '%Anh Thanh%')->delete();

        Customer::create([
            'code' => 'KH1',
            'name' => 'Anh Thanh 1',
            'is_customer' => true,
        ]);

        Customer::create([
            'code' => 'KH2',
            'name' => 'Anh Thanh 2',
            'is_customer' => true,
        ]);

        $exitCode = Artisan::call('customers:reconcile-partner-ledger', ['--name' => 'Anh Thanh']);
        $output = Artisan::output();
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Multiple partners found. Please refine your search:', $output);
    }
}
