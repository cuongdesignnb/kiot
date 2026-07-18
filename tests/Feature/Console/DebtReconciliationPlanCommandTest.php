<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DebtReconciliationPlanCommand;
use App\Models\CashFlow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebtReconciliationPlanCommandTest extends TestCase
{
    use DatabaseTransactions;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(DebtReconciliationPlanCommand::class)
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
        $this->artisan('debt:reconcile-plan')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_generate_plan_does_not_apply_or_change_database(): void
    {
        $audit = $this->path('audit.json');
        $csv = $this->path('plan.csv');
        $json = $this->path('plan.json');
        file_put_contents($audit, json_encode([
            'dry_run' => true,
            'rows' => [[
                'partner_id' => 999001,
                'partner_code' => 'GENERIC-999001',
                'role' => 'customer_only',
                'risk_level' => 'HIGH',
                'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
                'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT'],
            ]],
        ], JSON_THROW_ON_ERROR));
        $before = $this->databaseSnapshot();

        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--export' => $csv,
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertFileExists($csv);
        $payload = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['apply_supported']);
        $this->assertSame('PROPOSED', $payload['rows'][0]['status']);
        $this->assertSame('BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH', $payload['rows'][0]['proposed_action_type']);
        $this->assertSame(0, DB::table('customers')->where('id', 999001)->count());
    }

    public function test_missing_audit_file_is_rejected(): void
    {
        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $this->path('missing.json'),
        ])->assertExitCode(1);
    }

    public function test_population_orphan_is_added_as_guarded_audit_trail_action(): void
    {
        $audit = $this->path('orphan-audit.json');
        $population = $this->path('orphan-population.json');
        $json = $this->path('orphan-plan.json');
        $orphanId = ((int) DB::table('customers')->max('id')) + 200_000;
        CashFlow::query()->create([
            'code' => 'PT-ORPHAN-PLAN-'.uniqid(),
            'type' => 'receipt',
            'amount' => 100_000,
            'time' => now(),
            'target_type' => 'Customer',
            'target_id' => $orphanId,
            'target_name' => 'Missing Partner Row',
            'reference_type' => 'DebtPayment',
            'status' => 'active',
            'payment_method' => 'cash',
        ]);
        file_put_contents($audit, json_encode(['rows' => []], JSON_THROW_ON_ERROR));
        file_put_contents($population, json_encode([
            'orphan_financial_references' => [[
                'partner_id' => $orphanId,
                'reason' => 'LEGACY_ORPHAN_FINANCIAL_REFERENCE',
                'sources' => ['cash_flows'],
                'affects_canonical_balance' => false,
            ]],
        ], JSON_THROW_ON_ERROR));
        $before = $this->databaseSnapshot();

        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--population-file' => $population,
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertSame($before, $this->databaseSnapshot());
        $payload = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $payload['rows']);
        $this->assertSame('MARK_LEGACY_ORPHAN_EXCLUDED', $payload['rows'][0]['proposed_action_type']);
        $this->assertSame(1, $payload['rows'][0]['before_snapshot']['source_count']);
        $this->assertFalse($payload['rows'][0]['canonical_target']['affects_canonical_balance']);
        $this->assertNotEmpty($payload['population_report_sha256']);
    }

    public function test_partner_classification_flag_and_risk_filters_run_before_plan_generation(): void
    {
        $audit = $this->path('filtered-audit.json');
        $json = $this->path('filtered-plan.json');
        file_put_contents($audit, json_encode(['rows' => [
            $this->auditRow(101, 'CUSTOMER_STORED_VS_DOCUMENT', ['CUSTOMER_STORED_VS_DOCUMENT', 'TARGET_TYPE_ALIAS_SUSPECT'], 'HIGH'),
            $this->auditRow(102, 'TARGET_TYPE_ALIAS_SUSPECT', ['TARGET_TYPE_ALIAS_SUSPECT'], 'MEDIUM'),
            $this->auditRow(103, 'CUSTOMER_STORED_VS_DOCUMENT', ['CUSTOMER_STORED_VS_DOCUMENT'], 'HIGH'),
        ]], JSON_THROW_ON_ERROR));

        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--partner-id' => 101,
            '--classification' => 'TARGET_TYPE_ALIAS_SUSPECT',
            '--risk' => 'HIGH',
            '--json' => $json,
        ])->assertExitCode(0);

        $rows = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(101, $rows[0]['partner_id']);
        $this->assertSame('PROPOSED', $rows[0]['status']);
        $this->assertEquals(0.0, $rows[0]['customer_delta']);
        $this->assertEquals(0.0, $rows[0]['supplier_delta']);
        $this->assertNull($rows[0]['proposed_voucher']);
    }

    public function test_invalid_plan_filters_are_rejected(): void
    {
        $audit = $this->path('invalid-filter-audit.json');
        file_put_contents($audit, json_encode(['rows' => []], JSON_THROW_ON_ERROR));

        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--classification' => 'INVALID',
        ])->expectsOutputToContain('Invalid --classification')->assertExitCode(1);

        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--risk' => 'INVALID',
        ])->expectsOutputToContain('Invalid --risk')->assertExitCode(1);
    }

    public function test_plan_output_outside_audit_directory_is_rejected(): void
    {
        $audit = $this->path('path-audit.json');
        file_put_contents($audit, json_encode(['rows' => []], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->artisan('debt:reconcile-plan', [
            '--dry-run' => true,
            '--audit-file' => $audit,
            '--json' => storage_path('app/forbidden-plan.json'),
        ])->run();
    }

    private function path(string $name): string
    {
        $path = storage_path('app/audits/testing-'.uniqid().'-'.$name);
        $this->files[] = $path;

        return $path;
    }

    private function databaseSnapshot(): array
    {
        $tables = [
            'customers', 'customer_debts', 'supplier_debt_transactions', 'cash_flows',
            'invoices', 'returns', 'purchases', 'purchase_returns', 'debt_offsets',
        ];

        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();
    }

    private function auditRow(int $partnerId, string $classification, array $flags, string $risk): array
    {
        return [
            'partner_id' => $partnerId,
            'partner_code' => 'GENERIC-'.$partnerId,
            'role' => 'customer_only',
            'risk_level' => $risk,
            'primary_classification' => $classification,
            'classification_flags' => $flags,
        ];
    }
}
