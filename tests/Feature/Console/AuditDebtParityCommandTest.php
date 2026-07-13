<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AuditDebtParityCommand;
use App\Models\CashFlow;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditDebtParityCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(AuditDebtParityCommand::class)
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_command_requires_dry_run(): void
    {
        $this->artisan('debt:audit-parity')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_customer_fixture_exports_csv_and_json(): void
    {
        $customer = $this->customer();
        $csv = $this->path('parity-command.csv');
        $json = $this->path('parity-command.json');

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--export' => $csv,
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertFileExists($csv);
        $this->assertFileExists($json);
        $payload = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['dry_run']);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame($customer->id, $payload['rows'][0]['partner_id']);
        $this->assertSame('customer_only', $payload['rows'][0]['role']);
    }

    public function test_command_is_read_only_across_debt_tables(): void
    {
        $customer = $this->customer(['debt_amount' => 4_300_000]);
        $before = $this->databaseSnapshot();

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
        ])->assertExitCode(0);

        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertSame(4_300_000.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_target_type_alias_is_reported_without_mutation(): void
    {
        $customer = $this->customer();
        CashFlow::query()->insert([
            'code' => 'PT-ALIAS-' . uniqid(),
            'type' => 'receipt',
            'amount' => 100_000,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $customer->id,
            'target_name' => 'Generic Partner',
            'reference_type' => 'DebtPayment',
            'status' => 'active',
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $json = $this->path('parity-alias.json');

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--json' => $json,
        ])->assertExitCode(0);

        $row = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR)['rows'][0];
        $this->assertTrue($row['has_target_type_alias']);
        $this->assertContains('TARGET_TYPE_ALIAS_SUSPECT', $row['classification_flags']);
        $this->assertDatabaseHas('cash_flows', ['code' => $row['suspect_receipt_codes'][0] ?? 'PT-ALIAS-NOT-REQUIRED']);
    }

    public function test_output_outside_audit_directory_is_rejected(): void
    {
        $customer = $this->customer();

        $this->expectException(\RuntimeException::class);
        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--json' => storage_path('app/forbidden.json'),
        ])->run();
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::query()->forceCreate(array_merge([
            'code' => 'PARITY-' . uniqid(),
            'name' => 'Generic Parity Partner',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function path(string $name): string
    {
        $path = storage_path('app/audits/testing-' . uniqid() . '-' . $name);
        $this->files[] = $path;

        return $path;
    }

    private function databaseSnapshot(): array
    {
        $spec = [
            'customers' => ['debt_amount', 'supplier_debt_amount'],
            'customer_debts' => ['amount', 'debt_total'],
            'supplier_debt_transactions' => ['amount', 'debt_remain'],
            'cash_flows' => ['amount'],
            'invoices' => ['total', 'customer_paid'],
            'returns' => ['total', 'paid_to_customer'],
            'purchases' => ['total_amount', 'paid_amount', 'debt_amount'],
            'purchase_returns' => ['total_amount', 'refund_amount'],
            'debt_offsets' => ['amount'],
        ];
        $snapshot = [];
        foreach ($spec as $table => $columns) {
            $snapshot[$table]['count'] = DB::table($table)->count();
            foreach ($columns as $column) {
                $snapshot[$table][$column] = (float) DB::table($table)->sum($column);
            }
        }

        return $snapshot;
    }
}
