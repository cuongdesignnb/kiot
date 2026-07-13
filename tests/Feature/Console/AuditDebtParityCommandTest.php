<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AuditDebtParityCommand;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Services\Debt\PartnerDebtParityAuditService;
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

    public function test_csv_contains_suspect_and_technical_columns_with_pipe_serialization(): void
    {
        $customer = $this->customer();
        $csv = $this->path('parity-evidence.csv');
        $row = $this->auditRow($customer, [
            'primary_classification' => 'TECHNICAL_LEDGER_EXCLUDED',
            'classification_flags' => ['TECHNICAL_LEDGER_EXCLUDED', 'TARGET_TYPE_ALIAS_SUSPECT'],
            'risk_level' => 'MEDIUM',
            'suspect_invoice_codes' => ['HD-A', 'HD-B'],
            'suspect_receipt_codes' => ['PT-A', 'PT-B'],
            'suspect_return_codes' => ['TH-A', 'TH-B'],
            'suspect_refund_codes' => ['PC-A', 'PC-B'],
            'suspect_purchase_codes' => ['PN-A', 'PN-B'],
            'suspect_supplier_payment_codes' => ['PCPN-A', 'PCPN-B'],
            'suspect_purchase_return_codes' => ['THN-A', 'THN-B'],
            'suspect_adjustment_codes' => ['DCCN-A', 'DCCN-B'],
            'suspect_fallback_codes' => ['FB-A', 'FB-B'],
            'customer_technical_codes' => ['MERGE-CUSTOMER-A', 'MERGE-CUSTOMER-B'],
            'supplier_technical_codes' => ['MERGE-SUPPLIER-A', 'MERGE-SUPPLIER-B'],
            'excluded_technical_codes' => ['MERGE-CUSTOMER-A', 'MERGE-SUPPLIER-A'],
        ]);
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->once()->andReturn($row);
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--export' => $csv,
        ])->assertExitCode(0);

        $handle = fopen($csv, 'rb');
        $headers = fgetcsv($handle);
        $values = fgetcsv($handle);
        fclose($handle);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $csvRow = array_combine($headers, $values);

        foreach ([
            'partner_name', 'suspect_invoice_codes', 'suspect_receipt_codes', 'suspect_return_codes',
            'suspect_refund_codes', 'suspect_purchase_codes', 'suspect_supplier_payment_codes',
            'suspect_purchase_return_codes', 'suspect_adjustment_codes', 'suspect_fallback_codes',
            'customer_technical_codes', 'supplier_technical_codes', 'excluded_technical_codes',
        ] as $column) {
            $this->assertArrayHasKey($column, $csvRow);
        }
        $this->assertSame('HD-A|HD-B', $csvRow['suspect_invoice_codes']);
        $this->assertSame('MERGE-CUSTOMER-A|MERGE-CUSTOMER-B', $csvRow['customer_technical_codes']);
        $this->assertSame('MERGE-CUSTOMER-A|MERGE-SUPPLIER-A', $csvRow['excluded_technical_codes']);
    }

    public function test_classification_filter_matches_a_non_primary_flag(): void
    {
        $customer = $this->customer();
        $json = $this->path('classification-filter.json');
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->once()->andReturn($this->auditRow($customer, [
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT', 'TARGET_TYPE_ALIAS_SUSPECT'],
            'risk_level' => 'HIGH',
        ]));
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--classification' => 'TARGET_TYPE_ALIAS_SUSPECT',
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertCount(1, json_decode((string) file_get_contents($json), true)['rows']);
    }

    public function test_risk_filter_and_validation(): void
    {
        $customer = $this->customer();
        $json = $this->path('risk-filter.json');
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->once()->andReturn($this->auditRow($customer, ['risk_level' => 'CRITICAL']));
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--risk' => 'CRITICAL',
            '--json' => $json,
        ])->assertExitCode(0);
        $this->assertCount(1, json_decode((string) file_get_contents($json), true)['rows']);

        $this->artisan('debt:audit-parity', ['--dry-run' => true, '--risk' => 'INVALID'])
            ->expectsOutputToContain('Invalid --risk')
            ->assertExitCode(1);
    }

    public function test_high_risk_filter_is_supported(): void
    {
        $customer = $this->customer();
        $json = $this->path('high-risk-filter.json');
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->once()->andReturn($this->auditRow($customer, [
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT'],
            'risk_level' => 'HIGH',
        ]));
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--partner-id' => $customer->id,
            '--risk' => 'HIGH',
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertCount(1, json_decode((string) file_get_contents($json), true)['rows']);
    }

    public function test_audit_error_does_not_stop_scanning_but_returns_failure(): void
    {
        $first = $this->customer();
        $second = $this->customer();
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->twice()->andReturn(
            $this->auditRow($first, [
                'primary_classification' => 'AUDIT_ERROR',
                'classification_flags' => ['AUDIT_ERROR'],
                'risk_level' => 'CRITICAL',
                'audit_error' => 'Synthetic audit failure',
            ]),
            $this->auditRow($second),
        );
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', [
            '--dry-run' => true,
            '--role' => 'customer',
            '--limit' => '2',
        ])->expectsOutputToContain('Total scanned: 2')->assertExitCode(1);
    }

    public function test_limit_accepts_only_positive_integer(): void
    {
        $customer = $this->customer();
        $mock = \Mockery::mock(PartnerDebtParityAuditService::class);
        $mock->shouldReceive('audit')->once()->andReturn($this->auditRow($customer));
        $this->app->instance(PartnerDebtParityAuditService::class, $mock);

        $this->artisan('debt:audit-parity', ['--dry-run' => true, '--partner-id' => $customer->id, '--limit' => '1'])
            ->expectsOutputToContain('Total scanned: 1')
            ->assertExitCode(0);

        foreach (['0', '-1', 'abc'] as $invalid) {
            $this->artisan('debt:audit-parity', ['--dry-run' => true, '--limit' => $invalid])
                ->expectsOutputToContain('Invalid --limit')
                ->assertExitCode(1);
        }
    }

    public function test_invalid_classification_is_rejected(): void
    {
        $this->artisan('debt:audit-parity', ['--dry-run' => true, '--classification' => 'INVALID'])
            ->expectsOutputToContain('Invalid --classification')
            ->assertExitCode(1);
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

    private function auditRow(Customer $customer, array $overrides = []): array
    {
        return array_merge(array_fill_keys(PartnerDebtParityAuditService::CSV_COLUMNS, null), [
            'partner_id' => $customer->id,
            'partner_code' => $customer->code,
            'partner_name' => $customer->name,
            'role' => 'customer_only',
            'status' => 'active',
            'primary_classification' => 'OK',
            'classification_flags' => ['OK'],
            'risk_level' => 'OK',
            'audit_error' => null,
        ], $overrides);
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
