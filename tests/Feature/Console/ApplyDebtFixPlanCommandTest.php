<?php

namespace Tests\Feature\Console;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\SupplierDebtTransaction;
use App\Services\Debt\LegacyOrphanFinancialReferenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    public function test_guarded_orphan_classification_is_audited_and_replays_without_duplicates(): void
    {
        $orphanId = ((int) DB::table('customers')->max('id')) + 300_000;
        CashFlow::query()->create([
            'code' => 'PT-ORPHAN-APPLY-'.uniqid(),
            'type' => 'receipt',
            'amount' => 125_000,
            'time' => now(),
            'target_type' => 'Customer',
            'target_id' => $orphanId,
            'target_name' => 'Missing Partner Row',
            'reference_type' => 'DebtPayment',
            'status' => 'active',
            'payment_method' => 'cash',
        ]);
        $evidence = app(LegacyOrphanFinancialReferenceService::class)->snapshot($orphanId);
        $row = [
            'partner_id' => $orphanId,
            'partner_code' => 'LEGACY-ORPHAN-'.$orphanId,
            'role' => 'orphan',
            'proposed_action_type' => 'MARK_LEGACY_ORPHAN_EXCLUDED',
            'blocking_flags' => [],
            'canonical_target' => [
                'affects_canonical_balance' => false,
                'affects_any_partner_balance' => false,
            ],
            'orphan_evidence_hash' => $evidence['evidence_hash'],
        ];
        $plan = $this->guardedPlanFile('orphan-apply', [$row]);
        $payload = json_decode((string) file_get_contents($plan), true, flags: JSON_THROW_ON_ERROR);

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--approval-hash' => $payload['approval_hash'],
        ])->assertExitCode(0);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'partner_debt_orphan_excluded',
            'subject_type' => 'LegacyOrphanFinancialReference',
            'subject_id' => $orphanId,
        ]);
        $this->assertSame(1, PartnerDebtOperation::query()
            ->where('operation_type', 'debt.repair_plan')
            ->where('request_hash', $payload['plan_hash'])->count());
        $this->assertSame(1, ActivityLog::query()
            ->where('action', 'partner_debt_orphan_excluded')
            ->where('subject_id', $orphanId)->count());

        $this->artisan('debt:apply-fix-plan', [
            '--plan-json' => $plan,
            '--apply' => true,
            '--approval-hash' => $payload['approval_hash'],
        ])->expectsOutputToContain('REPLAY')->assertExitCode(0);

        $this->assertSame(1, ActivityLog::query()
            ->where('action', 'partner_debt_orphan_excluded')
            ->where('subject_id', $orphanId)->count());
    }

    private function planFile(string $name, array $plans): string
    {
        $base = storage_path('app/testing/debt-apply-'.$name.'-'.uniqid());
        @mkdir($base, 0755, true);
        $path = $base.DIRECTORY_SEPARATOR.'plan.json';

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

    private function guardedPlanFile(string $name, array $rows): string
    {
        $base = storage_path('app/testing/debt-apply-'.$name.'-'.uniqid());
        @mkdir($base, 0755, true);
        $path = $base.DIRECTORY_SEPARATOR.'plan.json';
        $planHash = hash('sha256', json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $databaseFingerprint = hash('sha256', implode('|', [
            DB::connection()->getDriverName(),
            DB::connection()->getDatabaseName(),
            (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        ]));
        $approvalHash = hash('sha256', 'approval-'.$planHash);
        file_put_contents($path, json_encode([
            'source_report_sha256' => hash('sha256', 'audit'),
            'population_report_sha256' => hash('sha256', 'population'),
            'database_fingerprint' => $databaseFingerprint,
            'plan_hash' => $planHash,
            'approval_hash' => $approvalHash,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $path;
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
