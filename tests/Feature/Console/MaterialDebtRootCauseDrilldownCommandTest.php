<?php

namespace Tests\Feature\Console;

use App\Console\Commands\MaterialDebtRootCauseDrilldownCommand;
use App\Models\Customer;
use App\Services\Debt\MaterialDebtRootCauseDrilldownService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MaterialDebtRootCauseDrilldownCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(MaterialDebtRootCauseDrilldownCommand::class),
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? File::deleteDirectory($path) : @unlink($path);
        }
        parent::tearDown();
    }

    public function test_dry_run_is_required(): void
    {
        $this->artisan('debt:drilldown-material')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_output_path_rejects_traversal_absolute_and_other_storage_directories(): void
    {
        $partner = $this->partner();
        $audit = $this->auditFile([$this->auditRow($partner)]);

        foreach (['../outside', '/tmp/material-drilldown', 'storage/app/other/material-drilldown'] as $path) {
            try {
                $this->artisan('debt:drilldown-material', [
                    '--dry-run' => true,
                    '--audit-file' => $audit,
                    '--export-dir' => $path,
                ])->run();
                $this->fail("Unsafe export path was accepted: {$path}");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Audit', $exception->getMessage());
            }
        }
    }

    public function test_command_writes_all_required_artifacts_under_audit_root(): void
    {
        $partner = $this->partner();
        $audit = $this->auditFile([$this->auditRow($partner)]);
        $output = $this->outputDir();

        $this->artisan('debt:drilldown-material', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--partner-id' => (string) $partner->id,
            '--export-dir' => $output,
        ])->assertExitCode(0);

        foreach ([
            'material-root-cause-summary.csv',
            'material-root-cause-summary.json',
            'material-root-cause-detail.json',
            'manual-review-queue.csv',
            'command.log',
            'partners/' . $partner->id . '.json',
        ] as $relative) {
            $this->assertFileExists($output . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        $summary = $this->readJson($output . '/material-root-cause-summary.json');
        $this->assertSame('UNRESOLVED', $summary['source_of_truth_default']);
        $this->assertSame($partner->id, $summary['rows'][0]['partner_id']);
    }

    public function test_partner_role_risk_classification_and_limit_filters(): void
    {
        $customer = $this->partner();
        $supplier = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        $dual = $this->partner(['is_supplier' => true]);
        $audit = $this->auditFile([
            $this->auditRow($customer, ['risk_level' => 'HIGH']),
            $this->auditRow($supplier, [
                'role' => 'supplier_only',
                'risk_level' => 'MEDIUM',
                'primary_classification' => 'SUPPLIER_STORED_VS_DOCUMENT',
                'classification_flags' => ['SUPPLIER_STORED_VS_DOCUMENT'],
            ]),
            $this->auditRow($dual, [
                'role' => 'dual_role',
                'risk_level' => 'CRITICAL',
                'primary_classification' => 'DUAL_ROLE_NET_MISMATCH',
                'classification_flags' => ['DUAL_ROLE_NET_MISMATCH', 'TARGET_TYPE_ALIAS_SUSPECT'],
            ]),
        ]);
        $mock = Mockery::mock(MaterialDebtRootCauseDrilldownService::class);
        $mock->shouldReceive('drilldown')->zeroOrMoreTimes()->andReturnUsing(
            fn (Customer $partner, array $row): array => $this->fakeDetail($partner, $row),
        );
        $this->app->instance(MaterialDebtRootCauseDrilldownService::class, $mock);

        $cases = [
            [['--partner-id' => (string) $customer->id], [$customer->id]],
            [['--role' => 'dual'], [$dual->id]],
            [['--risk' => 'HIGH'], [$customer->id]],
            [['--classification' => 'TARGET_TYPE_ALIAS_SUSPECT'], [$dual->id]],
            [['--limit' => '2'], [$customer->id, $supplier->id]],
        ];
        foreach ($cases as [$options, $expectedIds]) {
            $output = $this->outputDir();
            $this->artisan('debt:drilldown-material', array_merge([
                '--dry-run' => true,
                '--audit-file' => $audit,
                '--export-dir' => $output,
            ], $options))->assertExitCode(0);
            $rows = $this->readJson($output . '/material-root-cause-summary.json')['rows'];
            $this->assertSame($expectedIds, array_column($rows, 'partner_id'));
        }
    }

    public function test_one_partner_error_does_not_stop_export_and_final_exit_is_failure(): void
    {
        $first = $this->partner();
        $second = $this->partner();
        $audit = $this->auditFile([$this->auditRow($first), $this->auditRow($second)]);
        $output = $this->outputDir();
        $mock = Mockery::mock(MaterialDebtRootCauseDrilldownService::class);
        $mock->shouldReceive('drilldown')->twice()->andReturnUsing(function (Customer $partner, array $row) use ($first): array {
            if ($partner->id === $first->id) {
                throw new RuntimeException('Sensitive database detail must not be exported');
            }

            return $this->fakeDetail($partner, $row);
        });
        $this->app->instance(MaterialDebtRootCauseDrilldownService::class, $mock);

        $this->artisan('debt:drilldown-material', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--export-dir' => $output,
        ])->expectsOutputToContain('Total errors: 1')->assertExitCode(1);

        $details = $this->readJson($output . '/material-root-cause-detail.json')['details'];
        $this->assertCount(2, $details);
        $this->assertSame(['ERROR', 'OK'], array_column($details, 'drilldown_status'));
        $this->assertSame('UNRESOLVED', $details[0]['source_of_truth_status']);
        $this->assertSame('Drilldown failed: RuntimeException', $details[0]['error_message']);
        $this->assertFileExists($output . '/partners/' . $second->id . '.json');
    }

    public function test_command_is_read_only_across_all_debt_tables(): void
    {
        $partner = $this->partner(['debt_amount' => 4_300_000]);
        $audit = $this->auditFile([$this->auditRow($partner)]);
        $output = $this->outputDir();
        $before = $this->databaseSnapshot();

        $this->artisan('debt:drilldown-material', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--export-dir' => $output,
        ])->assertExitCode(0);

        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertSame(4_300_000.0, (float) $partner->fresh()->debt_amount);
    }

    public function test_repeated_run_has_same_summary_detail_and_queue_order(): void
    {
        $second = $this->partner();
        $first = $this->partner();
        $audit = $this->auditFile([
            $this->auditRow($second, ['risk_level' => 'MEDIUM']),
            $this->auditRow($first, ['risk_level' => 'CRITICAL']),
        ]);
        $firstOutput = $this->outputDir();
        $secondOutput = $this->outputDir();

        foreach ([$firstOutput, $secondOutput] as $output) {
            $this->artisan('debt:drilldown-material', [
                '--dry-run' => true,
                '--audit-file' => $audit,
                '--export-dir' => $output,
            ])->assertExitCode(0);
        }

        foreach (['material-root-cause-summary.json', 'material-root-cause-detail.json', 'manual-review-queue.csv'] as $file) {
            $this->assertSame(
                file_get_contents($firstOutput . '/' . $file),
                file_get_contents($secondOutput . '/' . $file),
            );
        }
    }

    private function partner(array $overrides = []): Customer
    {
        return Customer::query()->forceCreate(array_merge([
            'code' => 'COMMAND-' . uniqid(),
            'name' => 'Generic Command Partner',
            'phone' => '09' . random_int(10_000_000, 99_999_999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function auditRow(Customer $partner, array $overrides = []): array
    {
        return array_merge([
            'partner_id' => $partner->id,
            'partner_code' => $partner->code,
            'role' => $partner->is_supplier ? ($partner->is_customer ? 'dual_role' : 'supplier_only') : 'customer_only',
            'risk_level' => 'HIGH',
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT'],
            'customer_stored_vs_document_raw' => 100_000,
            'customer_stored_vs_ledger' => 100_000,
            'customer_document_vs_ledger' => 0,
            'supplier_stored_vs_document_raw' => 0,
            'supplier_stored_vs_ledger' => 0,
            'supplier_document_vs_ledger' => 0,
            'dual_role_screen_symmetry_difference' => 0,
        ], $overrides);
    }

    private function auditFile(array $rows): string
    {
        $path = storage_path('app/audits/testing-drilldown-input-' . uniqid() . '.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode(['rows' => $rows], JSON_THROW_ON_ERROR));
        $this->cleanup[] = $path;

        return $path;
    }

    private function outputDir(): string
    {
        $path = storage_path('app/audits/testing-drilldown-output-' . uniqid());
        $this->cleanup[] = $path;

        return $path;
    }

    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fakeDetail(Customer $partner, array $row): array
    {
        return [
            'drilldown_status' => 'OK',
            'partner' => [
                'partner_id' => $partner->id,
                'partner_code' => $partner->code,
                'role' => $row['role'],
            ],
            'stored_balance' => ['stored_customer_screen' => 0.0, 'stored_supplier_screen' => 0.0],
            'customer_document' => ['raw_document_final_balance' => 0.0],
            'customer_ledger' => ['ledger_final' => 0.0],
            'supplier_document' => ['raw_document_final_balance' => 0.0],
            'supplier_ledger' => ['ledger_final' => 0.0],
            'observed_patterns' => [[
                'pattern' => 'MULTI_SOURCE_DIVERGENCE',
                'confidence' => 'high',
                'evidence_codes' => [],
                'evidence_ids' => [],
                'reason' => 'Generic test divergence.',
            ]],
            'missing_evidence' => ['Manual debt confirmation'],
            'source_of_truth_status' => 'UNRESOLVED',
            'recommended_next_review' => 'Manual review required.',
        ];
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
