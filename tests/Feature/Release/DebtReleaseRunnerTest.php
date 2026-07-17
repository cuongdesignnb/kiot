<?php

declare(strict_types=1);

namespace Tests\Feature\Release;

use DateTimeImmutable;
use DebtRelease\CliArguments;
use DebtRelease\DebtReleaseRunner;
use DebtRelease\ExitCode;
use DebtRelease\FileReleaseLock;
use DebtRelease\ManifestValidator;
use DebtRelease\NativeProcessExecutor;
use DebtRelease\NativeReleasePlatform;
use DebtRelease\ProcessResult;
use DebtRelease\Redactor;
use DebtRelease\ReleaseFailure;
use DebtRelease\ReleaseFiles;
use DebtRelease\ReleasePlatform;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/scripts/debt-release/debt-release.php';

final class DebtReleaseRunnerTest extends TestCase
{
    private string $root;

    private string $auditRoot;

    private string $backupRoot;

    private array $manifest;

    private FakeReleasePlatform $platform;

    private array $output;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
        $temporaryRoot = sys_get_temp_dir().'/debt-release-runner-'.bin2hex(random_bytes(6));
        $this->auditRoot = $temporaryRoot.'/audits';
        $this->backupRoot = $temporaryRoot.'/backups';
        mkdir($this->auditRoot, 0750, true);
        mkdir($this->backupRoot, 0750, true);
        $this->manifest = require $this->root.'/scripts/debt-release/releases/pr-d.php';
        $this->platform = new FakeReleasePlatform($this->manifest);
        $this->output = [];
        $this->now = new DateTimeImmutable('2026-07-17T08:00:00+07:00');
    }

    protected function tearDown(): void
    {
        $base = dirname($this->auditRoot);
        if (is_dir($base)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($base);
        }
        parent::tearDown();
    }

    public function test_manifest_and_exact_migration_allowlist_are_valid(): void
    {
        ManifestValidator::validate($this->manifest, $this->root);
        $this->assertSame(3, count($this->manifest['migrations']));
        $this->assertSame([
            '2026_07_17_000000_add_workflow_evidence_columns_to_debt_offsets',
            '2026_07_17_000100_add_workflow_keys_and_foreign_keys_to_debt_offsets',
            '2026_07_17_000200_add_workflow_checks_to_debt_offsets',
        ], array_column($this->manifest['migrations'], 'name'));

        $invalid = $this->manifest;
        $invalid['migrations'][0]['path'] = 'database/migrations/other.php';
        $failure = $this->captureFailure(fn () => ManifestValidator::validate($invalid, $this->root));
        $this->assertSame('MIGRATION_PATH_ALLOWLIST_INVALID', $failure->blocker);
    }

    public function test_argument_parser_accepts_contract_and_rejects_unknown_command(): void
    {
        $parsed = CliArguments::parse(['runner', 'deploy', '--expected-sha', str_repeat('a', 40), '--maintenance-window-ack']);
        $this->assertSame('deploy', $parsed['command']);
        $this->assertTrue($parsed['options']['maintenance-window-ack']);
        foreach (['doctor', 'preflight', 'deploy'] as $command) {
            $this->assertSame('help', CliArguments::parse(['runner', $command, '--help'])['command']);
        }

        $this->expectException(ReleaseFailure::class);
        CliArguments::parse(['runner', 'destroy']);
    }

    public function test_missing_deploy_flags_fail_before_any_platform_mutation(): void
    {
        $failure = $this->captureFailure(fn () => $this->runner()->execute('deploy', []));
        $this->assertSame(ExitCode::INVALID_ARGUMENTS, $failure->releaseExitCode);
        $this->assertSame([], $this->platform->events);
    }

    /** @dataProvider gitBlockerProvider */
    public function test_git_gate_blocks_wrong_sha_branch_or_dirty_tree(string $property, mixed $value, string $blocker): void
    {
        $expectedSha = $this->platform->head;
        $this->platform->{$property} = $value;
        $failure = $this->captureFailure(fn () => $this->runner()->execute('preflight', ['expected-sha' => $expectedSha]));
        $this->assertSame($blocker, $failure->blocker);
        $this->assertSame(0, $this->platform->backupCalls);
    }

    public static function gitBlockerProvider(): array
    {
        return [
            'wrong branch' => ['branch', 'main', 'WRONG_BRANCH'],
            'dirty tree' => ['clean', false, 'DIRTY_WORKTREE'],
            'wrong expected sha' => ['head', str_repeat('b', 40), 'WRONG_EXPECTED_SHA'],
        ];
    }

    public function test_database_fingerprint_and_version_are_fail_closed(): void
    {
        $this->platform->databaseSha = str_repeat('0', 64);
        $failure = $this->captureFailure(fn () => $this->preflight());
        $this->assertSame('WRONG_DATABASE_FINGERPRINT', $failure->blocker);

        $this->platform->databaseSha = $this->manifest['expected_database_name_sha256'];
        $this->platform->version = '10.10.8';
        $failure = $this->captureFailure(fn () => $this->preflight());
        $this->assertSame('UNSUPPORTED_DATABASE_VERSION', $failure->blocker);
    }

    public function test_missing_dependency_backup_and_restore_fail_fast(): void
    {
        $this->platform->doctorPass = false;
        $this->assertSame('MISSING_DEPENDENCY', $this->captureFailure(fn () => $this->preflight())->blocker);
        $this->platform->doctorPass = true;
        $this->platform->backupPass = false;
        $this->assertSame('BACKUP_COMMAND_FAILED', $this->captureFailure(fn () => $this->preflight())->blocker);
        $this->platform->backupPass = true;
        $this->platform->restorePass = false;
        $this->assertSame('RESTORE_COMMAND_FAILED', $this->captureFailure(fn () => $this->preflight())->blocker);
    }

    public function test_preflight_creates_bound_report_and_prints_token_once(): void
    {
        $this->preflight();
        $text = implode("\n", $this->output);
        $this->assertStringContainsString('PREFLIGHT_STATUS=PASS', $text);
        $this->assertSame(1, substr_count($text, 'APPROVAL_TOKEN='));
        [$report] = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');
        $stored = ReleaseFiles::readJson($report);
        $this->assertArrayNotHasKey('approval_token', $stored);
        $this->assertFileExists(dirname($report).'/approval-token.sha256');
    }

    public function test_repeated_preflight_in_same_second_does_not_overwrite_evidence(): void
    {
        $this->preflight();
        $first = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');
        $this->preflight();
        $second = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');

        $this->assertCount(1, $first);
        $this->assertCount(2, $second);
        $this->assertNotSame(dirname($second[0]), dirname($second[1]));
        $this->assertSame(2, $this->platform->backupCalls);
    }

    public function test_invalid_token_stale_report_and_report_hash_mismatch_are_blocked(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->assertSame('APPROVAL_TOKEN_INVALID', $this->captureFailure(fn () => $this->deploy($report, 'wrong-token'))->blocker);

        $this->now = $this->now->modify('+361 minutes');
        $this->assertSame('PREFLIGHT_EXPIRED', $this->captureFailure(fn () => $this->deploy($report, $token))->blocker);

        $this->now = new DateTimeImmutable('2026-07-17T08:01:00+07:00');
        file_put_contents($report, "\n", FILE_APPEND);
        $this->assertSame('PREFLIGHT_REPORT_HASH_MISMATCH', $this->captureFailure(fn () => $this->deploy($report, $token))->blocker);
    }

    public function test_unsafe_temp_database_and_report_path_are_rejected(): void
    {
        $this->expectException(ReleaseFailure::class);
        NativeReleasePlatform::assertTemporaryDatabaseName('production', $this->manifest);
    }

    public function test_path_outside_allowlist_is_rejected(): void
    {
        $this->expectException(ReleaseFailure::class);
        ReleaseFiles::assertWithin(dirname($this->auditRoot).'/elsewhere/report.json', $this->auditRoot);
    }

    public function test_deploy_progresses_checkpoint_and_emits_final_summary_contract(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->output = [];
        $this->deploy($report, $token);
        $checkpoint = $this->latestCheckpoint();
        $this->assertSame('closeout_written', $checkpoint['stage']);
        $this->assertSame(3, $this->platform->migrationCalls);
        $this->assertSame(1, $this->platform->maintenanceDownCalls);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $summary = implode("\n", $this->output);
        $this->assertStringContainsString('DEPLOYMENT_STATUS=SUCCESS', $summary);
        $this->assertStringContainsString('CURRENT_DEBT_DATA_CHANGED=no', $summary);
        $this->assertLessThanOrEqual(40, count($this->output));
        $deploymentReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($deploymentReport['locks']['filesystem']['released']);
        $this->assertTrue($deploymentReport['locks']['database']['released']);
        $this->assertArrayHasKey('closeout_written', $deploymentReport['checkpoint_stages']);

        $this->output = [];
        $this->runner()->execute('status', []);
        $status = implode("\n", $this->output);
        $this->assertStringContainsString('SAFE_RERUN_COMMAND=', $status);
        $this->assertStringContainsString('<REUSE_OPERATOR_TOKEN>', $status);
        $this->assertStringNotContainsString($token, $status);
    }

    public function test_checkpoint_database_mismatch_is_exit_90(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $reportData = ReleaseFiles::readJson($report);
        $directory = $reportData['deployment_directory'];
        mkdir($directory, 0750, true);
        ReleaseFiles::atomicJson($directory.'/checkpoint.json', [
            'release_id' => 'debt-pr-d',
            'expected_sha' => $this->platform->head,
            'database_sha256' => str_repeat('0', 64),
            'stage' => 'initialized',
            'completed_stages' => ['initialized'],
            'timestamps' => [],
        ]);
        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));
        $this->assertSame(ExitCode::PARTIAL_RELEASE, $failure->releaseExitCode);
        $this->assertSame('CHECKPOINT_DATABASE_STATE_MISMATCH', $failure->blocker);
    }

    public function test_partial_migration_recovers_maintenance_and_resume_skips_verified_stage(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->failMigrationAt = 2;
        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));
        $this->assertSame('MIGRATION_2_FAILED', $failure->blocker);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $this->assertContains('migration_1_verified', $this->latestCheckpoint()['completed_stages']);
        $failureReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertSame('MIGRATION_2_FAILED', $failureReport['blocker']);
        $this->assertTrue($failureReport['maintenance_recovered']);

        $this->platform->failMigrationAt = null;
        $this->deploy($report, $token);
        $this->assertSame(1, $this->platform->runCounts[1]);
        $this->assertSame(2, $this->platform->runCounts[2]);
        $this->assertSame(1, $this->platform->runCounts[3]);
        $this->assertSame('closeout_written', $this->latestCheckpoint()['stage']);
    }

    public function test_database_lock_postflight_and_smoke_failures_recover_maintenance(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->databaseLockPass = false;
        $this->assertSame('DATABASE_RELEASE_LOCK_BUSY', $this->captureFailure(fn () => $this->deploy($report, $token))->blocker);
        $this->assertSame(0, $this->platform->maintenanceDownCalls);

        $this->platform->databaseLockPass = true;
        $this->platform->postflightPass = false;
        $this->assertSame('POSTFLIGHT_MISMATCH', $this->captureFailure(fn () => $this->deploy($report, $token))->blocker);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);

        $this->platform->postflightPass = true;
        $this->platform->smokePass = false;
        $this->assertSame('SMOKE_SIMULATED_FAILURE', $this->captureFailure(fn () => $this->deploy($report, $token))->blocker);
        $this->assertGreaterThanOrEqual(2, $this->platform->maintenanceUpCalls);
    }

    public function test_deploy_rechecks_ddl_risk_inside_maintenance_before_baseline_or_migration(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->ddlRiskPass = false;

        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));

        $this->assertSame('DEPLOY_START_DDL_RISK_BLOCKED', $failure->blocker);
        $this->assertSame(2, $this->platform->ddlRiskCalls);
        $this->assertSame(0, $this->platform->migrationCalls);
        $this->assertSame(1, $this->platform->maintenanceDownCalls);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $reportData = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($reportData['maintenance_recovered']);
        $this->assertStringContainsString('<REUSE_OPERATOR_TOKEN>', $reportData['safe_rerun_command']);
    }

    public function test_preflight_ddl_risk_uses_lock_blocker_exit_code(): void
    {
        $this->platform->ddlRiskPass = false;

        $failure = $this->captureFailure(fn () => $this->preflight());

        $this->assertSame('DDL_RISK_BLOCKED', $failure->blocker);
        $this->assertSame(ExitCode::LOCK_BLOCKER, $failure->releaseExitCode);
        $this->assertSame(0, $this->platform->backupCalls);
    }

    public function test_filesystem_lock_busy_fails_without_platform_mutation(): void
    {
        $path = $this->auditRoot.'/.lock';
        $first = new FileReleaseLock($path);
        $second = new FileReleaseLock($path);
        $first->acquire();
        try {
            $runner = $this->runner($second);
            $failure = $this->captureFailure(fn () => $runner->execute('preflight', ['expected-sha' => $this->platform->head]));
            $this->assertSame('FILESYSTEM_RELEASE_LOCK_BUSY', $failure->blocker);
            $this->assertSame(0, $this->platform->backupCalls);
        } finally {
            $first->release();
        }
    }

    public function test_cleanup_drops_only_allowlisted_temporary_databases(): void
    {
        $credential = sys_get_temp_dir().'/debt-release-client-'.bin2hex(random_bytes(8)).'.cnf';
        file_put_contents($credential, '[client]');
        $this->platform->tempDatabases = ['test_kiot_pr_d_restore_20260717_080000_abcdef'];
        try {
            $this->assertSame(0, $this->runner()->execute('cleanup', []));
            $this->assertSame($this->platform->tempDatabases, $this->platform->droppedDatabases);
            $this->assertFileDoesNotExist($credential);
        } finally {
            if (is_file($credential)) {
                unlink($credential);
            }
        }

        $this->platform->tempDatabases = ['kiot_db'];
        $failure = $this->captureFailure(fn () => $this->runner()->execute('cleanup', []));
        $this->assertSame('UNSAFE_TEMP_DATABASE_NAME', $failure->blocker);
    }

    public function test_reports_redact_credentials_and_canonical_helpers_are_stable(): void
    {
        $redacted = Redactor::redact([
            'password' => 'secret',
            'nested' => ['approval_token' => 'token', 'safe' => 'value'],
        ]);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['nested']['approval_token']);
        $this->assertSame('value', $redacted['nested']['safe']);
        $this->assertSame('{"a":"1","b":"2"}', NativeReleasePlatform::canonicalJson(['b' => '2', 'a' => '1']));
        $this->assertSame(
            'CREATE TABLE `x` (`id` int) ENGINE=InnoDB',
            NativeReleasePlatform::normalizeCreateTable('CREATE  TABLE `x` (`id` int) ENGINE=InnoDB AUTO_INCREMENT=42'),
        );
    }

    /** @group debt-release-integration */
    public function test_disposable_database_runs_preflight_deploy_and_safe_second_deploy(): void
    {
        if (getenv('DEBT_RUNNER_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set DEBT_RUNNER_INTEGRATION=1 inside a disposable database container.');
        }

        $application = require $this->root.'/bootstrap/app.php';
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $databaseName = (string) config('database.connections.'.config('database.default').'.database');
        $this->manifest['expected_database_name_sha256'] = hash('sha256', $databaseName);
        $this->manifest['database_engine'] = [
            'family' => (string) getenv('DEBT_RUNNER_ENGINE_FAMILY'),
            'version_prefix' => (string) getenv('DEBT_RUNNER_ENGINE_VERSION_PREFIX'),
        ];
        $platform = new IntegrationNativeReleasePlatform(
            $this->root,
            $this->backupRoot,
            new NativeProcessExecutor,
            $this->platform->head,
        );
        $this->platform = new FakeReleasePlatform($this->manifest);
        $output = [];
        $runner = new DebtReleaseRunner(
            $this->manifest,
            $this->root,
            $platform,
            new FileReleaseLock($this->auditRoot.'/.integration.lock'),
            $this->auditRoot,
            $this->backupRoot,
            static function (string $line) use (&$output): void {
                $output[] = $line;
            },
        );

        $serverLog = $this->auditRoot.'/server.log';
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:8093', '-t', 'public', 'public/index.php'],
            [0 => ['file', NativeReleasePlatformTestHelper::nullDevice(), 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
            $pipes,
            $this->root,
        );
        $this->assertIsResource($server);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $socket = @fsockopen('127.0.0.1', 8093, $errorCode, $errorMessage, 1);
                if (is_resource($socket)) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(200_000);
            }
            $this->assertTrue($ready, 'Disposable HTTP server did not start: '.(string) @file_get_contents($serverLog));
            $expectedSha = $this->platform->head;
            $this->assertSame(0, $runner->execute('doctor', []));
            $this->assertSame(0, $runner->execute('preflight', ['expected-sha' => $expectedSha]));
            [$report] = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');
            $tokenLine = current(array_filter($output, static fn (string $line) => str_starts_with($line, 'APPROVAL_TOKEN=')));
            $token = substr((string) $tokenLine, strlen('APPROVAL_TOKEN='));
            $deployOptions = [
                'preflight-report' => $report,
                'approval-token' => $token,
                'expected-sha' => $expectedSha,
                'maintenance-window-ack' => true,
            ];
            $this->assertSame(0, $runner->execute('deploy', $deployOptions));
            $this->assertSame(3, $platform->migrationStatus($this->manifest)['ran_count']);
            $this->assertSame(0, $runner->execute('deploy', $deployOptions));
            $this->assertSame(0, $runner->execute('status', []));
            $checkpoint = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/checkpoint.json')[0]);
            $this->assertSame('closeout_written', $checkpoint['stage']);
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($this->root.'/storage/framework/down');
        }
    }

    private function runner(?FileReleaseLock $lock = null): DebtReleaseRunner
    {
        return new DebtReleaseRunner(
            $this->manifest,
            $this->root,
            $this->platform,
            $lock ?? new FileReleaseLock($this->auditRoot.'/.lock'),
            $this->auditRoot,
            $this->backupRoot,
            function (string $line): void {
                $this->output[] = $line;
            },
            fn (): DateTimeImmutable => $this->now,
            static fn (int $bytes): string => str_repeat('a', $bytes * 2),
        );
    }

    private function preflight(): int
    {
        return $this->runner()->execute('preflight', ['expected-sha' => $this->platform->head]);
    }

    private function successfulPreflightEvidence(): array
    {
        $this->preflight();
        [$report] = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');
        $tokenLine = current(array_filter($this->output, static fn (string $line) => str_starts_with($line, 'APPROVAL_TOKEN=')));

        return [$report, substr((string) $tokenLine, strlen('APPROVAL_TOKEN='))];
    }

    private function deploy(string $report, string $token): int
    {
        return $this->runner()->execute('deploy', [
            'preflight-report' => $report,
            'approval-token' => $token,
            'expected-sha' => $this->platform->head,
            'maintenance-window-ack' => true,
        ]);
    }

    private function latestCheckpoint(): array
    {
        [$path] = glob($this->auditRoot.'/debt-pr-d-production-deploy-*/checkpoint.json');

        return ReleaseFiles::readJson($path);
    }

    private function captureFailure(callable $operation): ReleaseFailure
    {
        try {
            $operation();
            $this->fail('Expected ReleaseFailure was not thrown.');
        } catch (ReleaseFailure $failure) {
            return $failure;
        }
    }
}

final class FakeReleasePlatform implements ReleasePlatform
{
    public string $branch = 'production-customer-group';

    public string $head = '1111111111111111111111111111111111111111';

    public bool $clean = true;

    public string $databaseSha;

    public string $version = '10.11.10';

    public bool $doctorPass = true;

    public bool $backupPass = true;

    public bool $restorePass = true;

    public bool $databaseLockPass = true;

    public bool $postflightPass = true;

    public bool $smokePass = true;

    public bool $ddlRiskPass = true;

    public ?int $failMigrationAt = null;

    public int $backupCalls = 0;

    public int $migrationCalls = 0;

    public int $maintenanceDownCalls = 0;

    public int $maintenanceUpCalls = 0;

    public int $ddlRiskCalls = 0;

    public array $runCounts = [1 => 0, 2 => 0, 3 => 0];

    public array $events = [];

    public array $tempDatabases = [];

    public array $droppedDatabases = [];

    private array $ran = [];

    public function __construct(private readonly array $manifest)
    {
        $this->databaseSha = $manifest['expected_database_name_sha256'];
    }

    public function doctor(array $manifest): array
    {
        $this->events[] = 'doctor';

        return ['pass' => $this->doctorPass, 'blocker' => $this->doctorPass ? null : 'MISSING_DEPENDENCY'];
    }

    public function gitState(array $manifest): array
    {
        $this->events[] = 'git';

        return ['branch' => $this->branch, 'head' => $this->head, 'clean' => $this->clean, 'previous_sha_is_ancestor' => true];
    }

    public function databaseIdentity(): array
    {
        $this->events[] = 'database';

        return [
            'database_sha256' => $this->databaseSha,
            'family' => 'MariaDB',
            'version' => $this->version,
            'driver' => 'mariadb',
            'foreign_key_checks' => 1,
            'check_constraint_checks' => 1,
        ];
    }

    public function inspectDdlRisk(array $manifest): array
    {
        $this->ddlRiskCalls++;

        return ['pass' => $this->ddlRiskPass];
    }

    public function createBackup(string $path): array
    {
        $this->backupCalls++;
        if (! $this->backupPass) {
            return ['pass' => false, 'blocker' => 'BACKUP_COMMAND_FAILED'];
        }
        file_put_contents($path, 'fake-gzip-backup');

        return ['pass' => true, 'path' => $path, 'sha256' => hash_file('sha256', $path), 'size_bytes' => filesize($path), 'gzip_test' => true];
    }

    public function verifyBackup(string $path, string $expectedSha256): array
    {
        return ['pass' => is_file($path) && hash_equals($expectedSha256, (string) hash_file('sha256', $path))];
    }

    public function restoreTest(string $backupPath, array $manifest): array
    {
        return ['pass' => $this->restorePass, 'blocker' => $this->restorePass ? null : 'RESTORE_COMMAND_FAILED'];
    }

    public function captureBaseline(array $manifest): array
    {
        return ['pass' => true, 'baseline' => 'fixed'];
    }

    public function acquireDatabaseLock(string $name): bool
    {
        return $this->databaseLockPass;
    }

    public function releaseDatabaseLock(string $name): void
    {
        $this->events[] = 'db_unlock';
    }

    public function maintenanceDown(): void
    {
        $this->maintenanceDownCalls++;
        $this->events[] = 'down';
    }

    public function maintenanceUp(): void
    {
        $this->maintenanceUpCalls++;
        $this->events[] = 'up';
    }

    public function optimizeClear(): void
    {
        $this->events[] = 'optimize';
    }

    public function migrationRan(string $name): bool
    {
        return $this->ran[$name] ?? false;
    }

    public function runMigration(string $path): ProcessResult
    {
        $stage = 1 + (int) str_contains($path, '000100') + (2 * (int) str_contains($path, '000200'));
        $this->migrationCalls++;
        $this->runCounts[$stage]++;
        if ($this->failMigrationAt === $stage) {
            return new ProcessResult(1, '', 'simulated');
        }
        $this->ran[$this->manifest['migrations'][$stage - 1]['name']] = true;

        return new ProcessResult(0);
    }

    public function verifyMigrationStage(int $stage, array $manifest, array $baseline): array
    {
        return ['pass' => $this->migrationRan($manifest['migrations'][$stage - 1]['name'])];
    }

    public function comparePostflight(array $manifest, array $baseline): array
    {
        return ['pass' => $this->postflightPass, 'blocker' => $this->postflightPass ? null : 'POSTFLIGHT_MISMATCH'];
    }

    public function smoke(array $manifest, int $logOffset): array
    {
        return ['pass' => $this->smokePass, 'blocker' => $this->smokePass ? null : 'SMOKE_SIMULATED_FAILURE'];
    }

    public function logSize(): int
    {
        return 0;
    }

    public function migrationStatus(array $manifest): array
    {
        $states = [];
        foreach ($manifest['migrations'] as $migration) {
            $states[$migration['name']] = $this->migrationRan($migration['name']);
        }

        return ['pass' => true, 'ran_count' => count(array_filter($states)), 'migrations' => $states];
    }

    public function temporaryDatabases(array $manifest): array
    {
        return $this->tempDatabases;
    }

    public function dropTemporaryDatabase(string $name, array $manifest): void
    {
        $this->droppedDatabases[] = $name;
    }
}

final class IntegrationNativeReleasePlatform extends NativeReleasePlatform
{
    public function __construct(string $root, string $backupRoot, NativeProcessExecutor $process, private readonly string $head)
    {
        parent::__construct($root, $backupRoot, $process);
    }

    public function gitState(array $manifest): array
    {
        return [
            'branch' => $manifest['allowed_branch'],
            'head' => $this->head,
            'clean' => true,
            'previous_sha_is_ancestor' => true,
        ];
    }
}

final class NativeReleasePlatformTestHelper
{
    public static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
