<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApplyDebtFixPlanCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_missing_plan_json_fails(): void
    {
        $this->artisan('debt:apply-fix-plan', [
            '--dry-run' => true,
        ])->expectsOutputToContain('Missing --plan-json')
            ->assertExitCode(1);
    }

    public function test_missing_mode_fails(): void
    {
        $plan = $this->planFile('missing-mode', [$this->allowedPlan()]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
        ])->expectsOutputToContain('Pass exactly one mode')
            ->assertExitCode(1);
    }

    public function test_dry_run_and_apply_together_fail(): void
    {
        $plan = $this->planFile('two-modes', [$this->allowedPlan()]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--dry-run' => true,
            '--apply' => true,
        ])->expectsOutputToContain('cannot be used together')
            ->assertExitCode(1);
    }

    public function test_apply_missing_fix_run_id_fails(): void
    {
        $plan = $this->planFile('missing-run-id', [$this->allowedPlan()]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
        ])->expectsOutputToContain('--fix-run-id')
            ->assertExitCode(1);
    }

    public function test_apply_missing_confirm_code_fails(): void
    {
        $plan = $this->planFile('missing-confirm', [$this->allowedPlan()]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--fix-run-id' => 'DEBTFIX-TEST',
        ])->expectsOutputToContain('--confirm-code')
            ->assertExitCode(1);
    }

    public function test_apply_missing_backup_confirmed_fails(): void
    {
        $plan = $this->planFile('missing-backup', [$this->allowedPlan()]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--fix-run-id' => 'DEBTFIX-TEST',
            '--confirm-code' => 'CONFIRM-DEBT-FIX-DEBTFIX-TEST',
        ])->expectsOutputToContain('--backup-confirmed')
            ->assertExitCode(1);
    }

    public function test_plan_input_mismatch_fails(): void
    {
        $plan = $this->planFile('input-mismatch', [[
            'code' => 'DIFF-X',
            'classification' => 'PLAN_INPUT_MISMATCH',
            'fix_group' => 'X_PLAN_INPUT_MISMATCH',
            'proposed_write_operations' => [],
        ]]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--dry-run' => true,
        ])->expectsOutputToContain('PLAN_INPUT_MISMATCH')
            ->assertExitCode(1);
    }

    public function test_blocked_group_fails(): void
    {
        $plan = $this->planFile('blocked', [[
            'code' => 'DIFF-C',
            'classification' => 'DOCUMENT_LEDGER_MISMATCH',
            'fix_group' => 'C_LEDGER_DOCUMENT_MISMATCH',
            'proposed_write_operations' => [],
        ]]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--dry-run' => true,
        ])->expectsOutputToContain('Blocked fix group')
            ->assertExitCode(1);
    }

    public function test_partner_not_in_allowlist_fails(): void
    {
        $plan = $this->planFile('allowlist', [$this->allowedPlan(['code' => 'ALLOW-1'])]);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--fix-run-id' => 'DEBTFIX-TEST',
            '--confirm-code' => 'CONFIRM-DEBT-FIX-DEBTFIX-TEST',
            '--backup-confirmed' => true,
            '--rollback-export' => storage_path('app/testing/rollback-allowlist.json'),
            '--partner-code' => ['OTHER'],
        ])->expectsOutputToContain('No plan rows selected')
            ->assertExitCode(1);
    }

    public function test_dry_run_preview_does_not_write_db(): void
    {
        $plan = $this->planFile('dry-run-preview', [$this->allowedPlan()]);
        $before = $this->counts();

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--dry-run' => true,
            '--group' => 'B_DOCUMENTS_NO_LEDGER',
            '--limit' => 1,
        ])->assertExitCode(0);

        $this->assertSame($before, $this->counts());
    }

    public function test_apply_mode_is_fail_safe_and_does_not_write_db(): void
    {
        $plan = $this->planFile('apply-fail-safe', [$this->allowedPlan(['code' => 'ALLOW-APPLY'])]);
        $rollback = storage_path('app/testing/rollback-apply-fail-safe.json');
        @unlink($rollback);
        $before = $this->counts();

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--fix-run-id' => 'DEBTFIX-TEST',
            '--confirm-code' => 'CONFIRM-DEBT-FIX-DEBTFIX-TEST',
            '--backup-confirmed' => true,
            '--rollback-export' => $rollback,
            '--partner-code' => ['ALLOW-APPLY'],
            '--group' => 'B_DOCUMENTS_NO_LEDGER',
        ])->expectsOutputToContain('Apply mode is fail-safe')
            ->assertExitCode(1);

        $this->assertFileExists($rollback);
        $this->assertSame($before, $this->counts());
    }

    private function planFile(string $name, array $plans): string
    {
        $base = storage_path('app/testing/debt-apply-' . $name . '-' . uniqid());
        @mkdir($base, 0755, true);
        $path = $base . DIRECTORY_SEPARATOR . 'plan.json';

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'input_snapshot_id' => 'testing',
            'plans' => $plans,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function allowedPlan(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ALLOW-1',
            'classification' => 'HAS_DOCUMENTS_NO_LEDGER',
            'fix_group' => 'B_DOCUMENTS_NO_LEDGER',
            'proposed_write_operations' => [[
                'operation' => 'insert_customer_debt',
                'source_document_type' => 'invoice',
                'source_document_id' => 10,
                'source_code' => 'HD-ALLOW-1',
                'amount' => 1200000,
                'direction' => 'increase_debt',
                'recorded_at' => '2026-01-01 00:00:00',
                'fix_run_id' => 'PREVIEW_ONLY',
            ]],
        ], $overrides);
    }

    private function counts(): array
    {
        return [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
        ];
    }
}
