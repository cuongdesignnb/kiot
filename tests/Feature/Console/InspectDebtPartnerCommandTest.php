<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InspectDebtPartnerCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        $customer = $this->customer();

        $this->artisan('debt:inspect-partner', [
            '--customer-id' => $customer->id,
        ])->assertExitCode(1);
    }

    public function test_inspect_by_customer_id(): void
    {
        $customer = $this->customer();

        $this->artisan('debt:inspect-partner', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
        ])->assertExitCode(0);
    }

    public function test_inspect_by_code(): void
    {
        $customer = $this->customer();

        $this->artisan('debt:inspect-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
        ])->assertExitCode(0);
    }

    public function test_export_json_report(): void
    {
        $customer = $this->customer([
            'debt_amount' => 1200000,
        ]);
        $path = storage_path('app/testing/debt-inspect.json');
        @unlink($path);

        $this->artisan('debt:inspect-partner', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
            '--export' => $path,
            '--pretty' => true,
            '--include-raw' => true,
            '--include-timeline' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($path);
        $json = json_decode(file_get_contents($path), true);

        $this->assertArrayHasKey('partner', $json);
        $this->assertArrayHasKey('stored_balances', $json);
        $this->assertArrayHasKey('raw', $json);
        $this->assertArrayHasKey('timelines', $json);
        $this->assertArrayHasKey('diagnosis', $json);
        $this->assertSame($customer->id, $json['partner']['id']);
    }

    public function test_dry_run_does_not_write_debt_or_cashflow_rows(): void
    {
        $customer = $this->customer([
            'debt_amount' => 1200000,
        ]);
        $before = [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
        ];

        $this->artisan('debt:inspect-partner', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
            '--include-raw' => true,
            '--include-timeline' => true,
        ])->assertExitCode(0);

        $this->assertSame($before, [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'INSPECT-DEBT-' . uniqid(),
            'name' => 'Inspect Debt Partner',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }
}
