<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DebtReconciliationPlanCommand;
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
        $this->assertFalse($payload['apply_supported']);
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

    private function path(string $name): string
    {
        $path = storage_path('app/audits/testing-' . uniqid() . '-' . $name);
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
}
