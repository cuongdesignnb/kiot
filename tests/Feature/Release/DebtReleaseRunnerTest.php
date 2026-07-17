<?php

declare(strict_types=1);

namespace Tests\Feature\Release;

use DateTimeImmutable;
use DebtRelease\ApprovalTokenInput;
use DebtRelease\CliArguments;
use DebtRelease\DebtReleaseRunner;
use DebtRelease\ExitCode;
use DebtRelease\FileReleaseLock;
use DebtRelease\ManifestValidator;
use DebtRelease\NativeProcessExecutor;
use DebtRelease\NativeReleasePlatform;
use DebtRelease\ProcessExecutor;
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

    private ?string $approvalToken = null;

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
        $this->approvalToken = null;
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
        $this->assertTrue(CliArguments::parse(['runner', 'deploy', '--resume-partial-ack'])['options']['resume-partial-ack']);
        $this->assertSame(
            'INVALID_OR_DUPLICATE_OPTION',
            $this->captureFailure(fn () => CliArguments::parse(['runner', 'deploy', '--approval-token', 'secret']))->blocker,
        );
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

    public function test_ensure_directory_never_chmods_existing_paths_and_blocks_files_and_symlinks(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX directory modes and symlinks are validated in Linux/Docker.');
        }

        $root = dirname($this->auditRoot).'/directory-contract';
        mkdir($root, 0750);
        $existing = $root.'/existing';
        mkdir($existing, 0711);
        chmod($existing, 0711);

        ReleaseFiles::ensureDirectory($existing, 0750);
        clearstatcache(true, $existing);
        $this->assertSame(0711, fileperms($existing) & 07777);

        $created = $root.'/created';
        ReleaseFiles::ensureDirectory($created, 0750);
        clearstatcache(true, $created);
        $this->assertSame(0750, fileperms($created) & 07777);

        $file = $root.'/file';
        file_put_contents($file, 'not a directory');
        $this->assertSame(
            'DIRECTORY_PATH_IS_NOT_DIRECTORY',
            $this->captureFailure(fn () => ReleaseFiles::ensureDirectory($file, 0750))->blocker,
        );

        $link = $root.'/link';
        if (! symlink($existing, $link)) {
            $this->fail('Linux symlink fixture could not be created.');
        }
        $this->assertSame(
            'DIRECTORY_SYMLINK_BLOCKED',
            $this->captureFailure(fn () => ReleaseFiles::ensureDirectory($link.DIRECTORY_SEPARATOR, 0750))->blocker,
        );
        unlink($link);
        clearstatcache(true, $existing);
        $this->assertSame(0711, fileperms($existing) & 07777);
    }

    public function test_atomic_report_writes_preserve_existing_parent_metadata(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX directory metadata is validated in Linux/Docker.');
        }

        $directory = dirname($this->auditRoot).'/existing-report-parent';
        mkdir($directory, 0711);
        chmod($directory, 0711);
        $before = $this->directoryMetadata($directory);

        ReleaseFiles::atomicText($directory.'/report.txt', 'report');
        ReleaseFiles::atomicJson($directory.'/report.json', ['pass' => true]);

        $this->assertSame($before, $this->directoryMetadata($directory));
    }

    public function test_client_credential_is_exclusive_private_and_preserves_system_temp_metadata(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('System temp ownership and POSIX mode are validated in Linux/Docker.');
        }

        $directory = rtrim(sys_get_temp_dir(), '/\\');
        $filename = 'debt-release-client-'.bin2hex(random_bytes(8)).'.cnf';
        $content = "[client]\nuser=\"runner-test\"\npassword=\"credential-unit-secret\"\n";
        $before = $this->directoryMetadata($directory);
        $path = ReleaseFiles::writeExclusiveClientCredential($directory, $filename, $content);
        try {
            $this->assertMatchesRegularExpression('/^debt-release-client-[a-f0-9]{16}\.cnf$/', basename($path));
            $this->assertSame($content, file_get_contents($path));
            clearstatcache(true, $path);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame($before, $this->directoryMetadata($directory));
            $this->assertStringNotContainsString('credential-unit-secret', implode("\n", $this->output));
            $collision = $this->captureFailure(fn () => ReleaseFiles::writeExclusiveClientCredential(
                $directory,
                $filename,
                '[client] collision',
            ));
            $this->assertSame('CLIENT_CREDENTIAL_CREATE_FAILED', $collision->blocker);
            $this->assertSame($content, file_get_contents($path));
        } finally {
            @unlink($path);
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->directoryMetadata($directory));
    }

    /** @dataProvider credentialFailureProvider */
    public function test_client_credential_failure_removes_partial_file_and_preserves_parent(
        string $stage,
        string $blocker,
    ): void {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('System temp ownership and POSIX mode are validated in Linux/Docker.');
        }

        $directory = rtrim(sys_get_temp_dir(), '/\\');
        $filename = 'debt-release-client-'.bin2hex(random_bytes(8)).'.cnf';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $before = $this->directoryMetadata($directory);
        $secret = 'credential-failure-secret';
        $failure = $this->captureFailure(fn () => ReleaseFiles::writeExclusiveClientCredential(
            $directory,
            $filename,
            "[client]\npassword=\"{$secret}\"\n",
            static function (string $actualStage) use ($stage, $blocker): void {
                if ($actualStage === $stage) {
                    throw new ReleaseFailure($blocker, ExitCode::DEPENDENCY_BLOCKER);
                }
            },
        ));

        $this->assertSame($blocker, $failure->blocker);
        $this->assertStringNotContainsString($secret, $failure->getMessage());
        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->directoryMetadata($directory));
    }

    public static function credentialFailureProvider(): array
    {
        return [
            'create' => ['create', 'CLIENT_CREDENTIAL_CREATE_FAILED'],
            'chmod' => ['chmod', 'CLIENT_CREDENTIAL_MODE_FAILED'],
            'write' => ['write', 'CLIENT_CREDENTIAL_WRITE_FAILED'],
            'flush' => ['flush', 'CLIENT_CREDENTIAL_FLUSH_FAILED'],
        ];
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
        $this->assertStringNotContainsString('SAFE_RERUN_COMMAND=', $status);
        $this->assertStringNotContainsString('--approval-token', $status);
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
        $this->now = $this->now->modify('+361 minutes');
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
        $temporaryMetadata = $this->directoryMetadata(sys_get_temp_dir());

        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));

        $this->assertSame('DEPLOY_START_DDL_RISK_BLOCKED', $failure->blocker);
        $this->assertSame(2, $this->platform->ddlRiskCalls);
        $this->assertSame(0, $this->platform->migrationCalls);
        $this->assertSame(1, $this->platform->maintenanceDownCalls);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $reportData = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($reportData['maintenance_recovered']);
        $this->assertStringNotContainsString('--approval-token', $reportData['safe_rerun_command']);
        $this->assertSame($temporaryMetadata, $this->directoryMetadata(sys_get_temp_dir()));
    }

    /** @dataProvider migrationStageFailureProvider */
    public function test_each_migration_stage_failure_preserves_temp_metadata_and_recovers_maintenance(int $stage): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $temporaryMetadata = $this->directoryMetadata(sys_get_temp_dir());
        $credentialFiles = $this->credentialFiles();
        $this->platform->failMigrationAt = $stage;

        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));

        $this->assertSame('MIGRATION_'.$stage.'_FAILED', $failure->blocker);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $failureReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($failureReport['maintenance_recovered']);
        $this->assertTrue($failureReport['database_lock_released']);
        $this->assertSame($temporaryMetadata, $this->directoryMetadata(sys_get_temp_dir()));
        $this->assertSame($credentialFiles, $this->credentialFiles());
        $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);
    }

    public static function migrationStageFailureProvider(): array
    {
        return [
            'stage 1' => [1],
            'stage 2' => [2],
            'stage 3' => [3],
        ];
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
        $temporaryMetadata = $this->directoryMetadata(sys_get_temp_dir());
        $credential = sys_get_temp_dir().'/debt-release-client-'.bin2hex(random_bytes(8)).'.cnf';
        $rawSql = $this->backupRoot.'/kiot-pr-d-raw-20260717-080000-0123456789abcdef.sql.tmp';
        $finalBackup = $this->backupRoot.'/kiot-pr-d-db-backup-final.sql.gz';
        $auditReport = $this->auditRoot.'/retained-report.json';
        file_put_contents($credential, '[client]');
        file_put_contents($rawSql, 'sensitive raw SQL');
        file_put_contents($finalBackup, 'final backup');
        file_put_contents($auditReport, '{}');
        $this->platform->tempDatabases = ['test_kiot_pr_d_restore_20260717_080000_abcdef'];
        try {
            $this->assertSame(0, $this->runner()->execute('cleanup', []));
            $this->assertSame($this->platform->tempDatabases, $this->platform->droppedDatabases);
            $this->assertFileDoesNotExist($credential);
            $this->assertFileDoesNotExist($rawSql);
            $this->assertFileExists($finalBackup);
            $this->assertFileExists($auditReport);
            $this->assertSame($temporaryMetadata, $this->directoryMetadata(sys_get_temp_dir()));
        } finally {
            if (is_file($credential)) {
                unlink($credential);
            }
        }

        $this->platform->tempDatabases = ['kiot_db'];
        $failure = $this->captureFailure(fn () => $this->runner()->execute('cleanup', []));
        $this->assertSame('UNSAFE_TEMP_DATABASE_NAME', $failure->blocker);
    }

    public function test_doctor_and_status_preserve_system_temp_metadata(): void
    {
        $temporaryMetadata = $this->directoryMetadata(sys_get_temp_dir());

        $this->assertSame(0, $this->runner()->execute('doctor', []));
        $this->assertSame(0, $this->runner()->execute('status', []));

        $this->assertSame($temporaryMetadata, $this->directoryMetadata(sys_get_temp_dir()));
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

    public function test_native_process_timeout_terminates_child_and_reports_duration(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Native signal timeout is validated in Linux/Docker.');
        }
        $result = (new NativeProcessExecutor)->run([PHP_BINARY, '-r', 'sleep(30);'], $this->root, null, null, 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertContains($result->terminationSignal, [9, 15]);
        $this->assertGreaterThanOrEqual(900, $result->durationMs);
        $this->assertLessThan(5000, $result->durationMs);
    }

    public function test_native_process_writes_to_precreated_output_file_and_reaps_child(): void
    {
        $path = $this->backupRoot.'/process-output.tmp';
        touch($path);
        $result = (new NativeProcessExecutor)->run([PHP_BINARY, '-r', 'echo "fixture";'], $this->root, null, $path, 5);

        $this->assertTrue($result->successful(), $result->stderr.' exit='.$result->exitCode);
        $this->assertStringEndsWith('fixture', (string) file_get_contents($path));
    }

    public function test_non_interactive_token_environment_is_unset_after_read(): void
    {
        putenv('DEBT_RELEASE_APPROVAL_TOKEN=test-only-token');
        $_ENV['DEBT_RELEASE_APPROVAL_TOKEN'] = 'test-only-token';
        $_SERVER['DEBT_RELEASE_APPROVAL_TOKEN'] = 'test-only-token';

        $this->assertSame('test-only-token', ApprovalTokenInput::read());
        $this->assertFalse(getenv('DEBT_RELEASE_APPROVAL_TOKEN'));
        $this->assertArrayNotHasKey('DEBT_RELEASE_APPROVAL_TOKEN', $_ENV);
        $this->assertArrayNotHasKey('DEBT_RELEASE_APPROVAL_TOKEN', $_SERVER);
    }

    /** @dataProvider controlledSignalProvider */
    public function test_controlled_signal_handler_maps_linux_signals_to_partial_release(int $signal, string $name): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || ! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Controlled signal behavior requires Linux pcntl/posix.');
        }
        $runner = $this->root.'/scripts/debt-release/debt-release.php';
        $code = <<<'PHP'
require $argv[1];
\DebtRelease\SignalRecovery::install();
try {
    posix_kill(getmypid(), (int) $argv[2]);
    usleep(100000);
} catch (\DebtRelease\ReleaseFailure $failure) {
    echo $failure->blocker;
    exit($failure->releaseExitCode);
}
exit(1);
PHP;
        $result = (new NativeProcessExecutor)->run([PHP_BINARY, '-r', $code, $runner, (string) $signal], $this->root, null, null, 5);

        $this->assertSame(ExitCode::PARTIAL_RELEASE, $result->exitCode);
        $this->assertStringContainsString('SIGNAL_'.$name.'_INTERRUPTED', $result->stdout);
    }

    public static function controlledSignalProvider(): array
    {
        return [
            'SIGINT' => [2, 'SIGINT'],
            'SIGTERM' => [15, 'SIGTERM'],
            'SIGHUP' => [1, 'SIGHUP'],
        ];
    }

    /** @dataProvider controlledSignalProvider */
    public function test_controlled_signal_failure_recovers_maintenance_locks_and_checkpoint(int $signal, string $name): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->throwSignalAtMigration = 2;
        $this->platform->throwSignalName = $name;

        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));

        $this->assertSame('SIGNAL_'.$name.'_INTERRUPTED', $failure->blocker);
        $this->assertSame(ExitCode::PARTIAL_RELEASE, $failure->releaseExitCode);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $this->assertContains('db_unlock', $this->platform->events);
        $this->assertContains('migration_1_verified', $this->latestCheckpoint()['completed_stages']);
        $reportData = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($reportData['maintenance_recovered']);
        $this->assertTrue($reportData['database_lock_released']);
    }

    public function test_token_is_consumed_only_at_ddl_boundary_and_tokenless_fresh_is_blocked(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->approvalToken = null;
        $failure = $this->captureFailure(fn () => $this->deploy($report, ''));
        $this->assertSame('APPROVAL_TOKEN_REQUIRED_FOR_TEST', $failure->blocker);
        $this->assertArrayNotHasKey('consumed_at', ReleaseFiles::readJson(dirname($report).'/approval-token.sha256'));

        $this->approvalToken = $token;
        $this->platform->ddlRiskPass = false;
        $this->captureFailure(fn () => $this->deploy($report, $token));
        $this->assertArrayNotHasKey('consumed_at', ReleaseFiles::readJson(dirname($report).'/approval-token.sha256'));

        $this->platform->ddlRiskPass = true;
        $this->deploy($report, $token);
        $sidecar = ReleaseFiles::readJson(dirname($report).'/approval-token.sha256');
        $this->assertSame('ddl_boundary_before_migration_1', $sidecar['consumption_stage']);
        $this->assertArrayNotHasKey('approval_token', $sidecar);
    }

    public function test_partial_resume_requires_ack_and_successful_closeout_cannot_resume(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->failMigrationAt = 2;
        $this->captureFailure(fn () => $this->deploy($report, $token));
        $this->platform->failMigrationAt = null;
        $this->approvalToken = null;

        $failure = $this->captureFailure(fn () => $this->runner()->execute('deploy', [
            'preflight-report' => $report,
            'expected-sha' => $this->platform->head,
            'maintenance-window-ack' => true,
        ]));
        $this->assertSame('PARTIAL_RESUME_ACK_REQUIRED', $failure->blocker);

        $this->now = $this->now->modify('+361 minutes');
        $this->deploy($report, '');
        $failure = $this->captureFailure(fn () => $this->runner()->execute('deploy', [
            'preflight-report' => $report,
            'expected-sha' => $this->platform->head,
            'maintenance-window-ack' => true,
            'resume-partial-ack' => true,
        ]));
        $this->assertSame('SUCCESSFUL_CLOSEOUT_CANNOT_BE_RESUMED', $failure->blocker);
        $closeoutReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertSame('SUCCESS', $closeoutReport['deployment_status']);
        $this->assertArrayNotHasKey('blocker', $closeoutReport);
    }

    public function test_migration_timeout_recovers_maintenance_and_writes_partial_evidence(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->timeoutMigrationAt = 1;

        $failure = $this->captureFailure(fn () => $this->deploy($report, $token));

        $this->assertSame('MIGRATION_1_TIMEOUT', $failure->blocker);
        $this->assertSame(ExitCode::PARTIAL_RELEASE, $failure->releaseExitCode);
        $this->assertSame(1, $this->platform->maintenanceUpCalls);
        $failureReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
        $this->assertTrue($failureReport['maintenance_recovered']);
        $this->assertTrue($failureReport['database_lock_released']);
    }

    public function test_partial_resume_blocks_checkpoint_schema_mismatch(): void
    {
        [$report, $token] = $this->successfulPreflightEvidence();
        $this->platform->failMigrationAt = 2;
        $this->captureFailure(fn () => $this->deploy($report, $token));
        $this->platform->failMigrationAt = null;
        $this->platform->forgetMigration(1);

        $failure = $this->captureFailure(fn () => $this->deploy($report, ''));

        $this->assertSame('CHECKPOINT_DATABASE_STATE_MISMATCH', $failure->blocker);
        $this->assertSame(ExitCode::PARTIAL_RELEASE, $failure->releaseExitCode);
    }

    /** @group debt-release-integration */
    public function test_disposable_database_runs_preflight_deploy_and_safe_second_deploy(): void
    {
        if (getenv('DEBT_RUNNER_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set DEBT_RUNNER_INTEGRATION=1 inside a disposable database container.');
        }

        $systemTempMetadata = $this->directoryMetadata(sys_get_temp_dir());
        $credentialFiles = $this->credentialFiles();
        $auditRootMetadata = $this->directoryMetadata($this->auditRoot);
        $backupRootMetadata = $this->directoryMetadata($this->backupRoot);
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
        $integrationToken = '';

        foreach (self::credentialFailureProvider() as [$stage, $blocker]) {
            $credentialFailurePlatform = new NativeReleasePlatform(
                $this->root,
                $this->backupRoot,
                new NativeProcessExecutor,
                static function (string $actualStage) use ($stage, $blocker): void {
                    if ($actualStage === $stage) {
                        throw new ReleaseFailure($blocker, ExitCode::DEPENDENCY_BLOCKER);
                    }
                },
            );
            $failedCredentialBackup = $this->backupRoot.'/credential-'.$stage.'-failure.sql.gz';
            $this->assertSame(
                $blocker,
                $this->captureFailure(fn () => $credentialFailurePlatform->createBackup($failedCredentialBackup))->blocker,
            );
            $this->assertFileDoesNotExist($failedCredentialBackup);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());
            $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);
        }

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
            null,
            null,
            static function () use (&$integrationToken): string {
                return $integrationToken;
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
            $ddlRisk = $platform->inspectDdlRisk($this->manifest);
            if (! $ddlRisk['DDL_VISIBILITY_COMPLETE']) {
                $this->assertSame('DDL_RISK_VISIBILITY_INSUFFICIENT', $ddlRisk['blocker']);
                \Illuminate\Support\Facades\DB::connection()->getPdo()->exec(
                    "UPDATE performance_schema.setup_instruments
                     SET ENABLED = 'YES', TIMED = 'YES'
                     WHERE NAME = 'wait/lock/metadata/sql/mdl'"
                );
                $ddlRisk = $platform->inspectDdlRisk($this->manifest);
            }
            $this->assertTrue($ddlRisk['DDL_VISIBILITY_COMPLETE']);
            $this->assertTrue($ddlRisk['pass']);

            $failedDump = $this->backupRoot.'/failed-dump.sql.gz';
            $failingDumpPlatform = new NativeReleasePlatform(
                $this->root,
                $this->backupRoot,
                new FailingMysqldumpProcessExecutor,
            );
            $this->assertSame(
                'MYSQLDUMP_FAILED',
                $this->captureFailure(fn () => $failingDumpPlatform->createBackup($failedDump))->blocker,
            );
            $this->assertFileDoesNotExist($failedDump);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());
            $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);

            $failedBackup = $this->backupRoot.'/failed-backup.sql.gz';
            $failingBackupPlatform = new NativeReleasePlatform(
                $this->root,
                $this->backupRoot,
                new FailingGzipProcessExecutor,
            );
            $this->assertSame(
                'BACKUP_GZIP_FAILED',
                $this->captureFailure(fn () => $failingBackupPlatform->createBackup($failedBackup))->blocker,
            );
            $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);
            $this->assertFileDoesNotExist($failedBackup);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());

            $restoreFailureBackup = $this->backupRoot.'/restore-failure-fixture.sql.gz';
            $restoreFailureBackupResult = $platform->createBackup($restoreFailureBackup);
            $failingRestorePlatform = new NativeReleasePlatform(
                $this->root,
                $this->backupRoot,
                new FailingMysqlRestoreProcessExecutor,
            );
            $this->assertSame(
                'RESTORE_IMPORT_FAILED',
                $this->captureFailure(fn () => $failingRestorePlatform->restoreTest($restoreFailureBackup, $this->manifest))->blocker,
            );
            $this->assertTrue($restoreFailureBackupResult['pass']);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());
            $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);

            $connection = config('database.connections.'.config('database.default'));
            $writer = new \PDO(
                'mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset=utf8mb4',
                $connection['username'],
                $connection['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            $snapshotMethod = new \ReflectionMethod(NativeReleasePlatform::class, 'withConsistentReadOnlySnapshot');
            $fixture = 'debt_release_snapshot_fixture_'.bin2hex(random_bytes(4));
            $snapshot = $snapshotMethod->invoke($platform, function () use ($writer, $fixture): array {
                $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
                $before = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
                $statement = $writer->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
                $statement->execute([$fixture, 999999]);
                $after = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();

                return ['before' => $before, 'after' => $after];
            });
            $this->assertSame($snapshot['before'], $snapshot['after']);
            $this->assertTrue($snapshot['snapshot_read_only']);
            $this->assertTrue($snapshot['snapshot_rolled_back']);
            $writer->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$fixture]);

            $writer->beginTransaction();
            $writer->query('SELECT * FROM debt_offsets LIMIT 1')->fetchAll();
            $lockedRisk = $platform->inspectDdlRisk($this->manifest);
            $this->assertFalse($lockedRisk['pass']);
            $this->assertGreaterThan(0, $lockedRisk['metadata_lock_blockers']);
            $ddlFailure = $this->captureFailure(fn () => $runner->execute('preflight', ['expected-sha' => $expectedSha]));
            $this->assertSame('DDL_ACTIVITY_DETECTED', $ddlFailure->blocker);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());
            $writer->rollBack();
            $this->assertSame(0, $runner->execute('preflight', ['expected-sha' => $expectedSha]));
            [$report] = glob($this->auditRoot.'/debt-pr-d-preflight-*/preflight-report.json');
            $tokenLine = current(array_filter($output, static fn (string $line) => str_starts_with($line, 'APPROVAL_TOKEN=')));
            $token = substr((string) $tokenLine, strlen('APPROVAL_TOKEN='));
            $integrationToken = $token;
            $deployOptions = [
                'preflight-report' => $report,
                'expected-sha' => $expectedSha,
                'maintenance-window-ack' => true,
            ];
            $this->assertSame(0, $runner->execute('deploy', $deployOptions));
            $this->assertSame(3, $platform->migrationStatus($this->manifest)['ran_count']);
            $failure = $this->captureFailure(fn () => $runner->execute('deploy', $deployOptions + ['resume-partial-ack' => true]));
            $this->assertSame('SUCCESSFUL_CLOSEOUT_CANNOT_BE_RESUMED', $failure->blocker);
            $this->assertSame(0, $runner->execute('status', []));
            $checkpoint = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/checkpoint.json')[0]);
            $this->assertSame('closeout_written', $checkpoint['stage']);
            $deploymentReport = ReleaseFiles::readJson(glob($this->auditRoot.'/debt-pr-d-production-deploy-*/deployment-report.json')[0]);
            $this->assertTrue($deploymentReport['data_snapshot_consistent']);
            $this->assertTrue($deploymentReport['snapshot_read_only']);
            $this->assertTrue($deploymentReport['snapshot_rolled_back']);
            $this->assertSame([], glob($this->backupRoot.'/kiot-pr-d-raw-*.sql.tmp') ?: []);
            $this->assertNotSame([], glob($this->backupRoot.'/kiot-pr-d-db-backup-*.sql.gz') ?: []);
            $this->assertSame($systemTempMetadata, $this->directoryMetadata(sys_get_temp_dir()));
            $this->assertSame($credentialFiles, $this->credentialFiles());
            $this->assertSame($auditRootMetadata, $this->directoryMetadata($this->auditRoot));
            $this->assertSame($backupRootMetadata, $this->directoryMetadata($this->backupRoot));
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
            function (): string {
                if ($this->approvalToken === null || $this->approvalToken === '') {
                    throw new ReleaseFailure('APPROVAL_TOKEN_REQUIRED_FOR_TEST', ExitCode::INVALID_ARGUMENTS);
                }

                return $this->approvalToken;
            },
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

        $this->approvalToken = substr((string) $tokenLine, strlen('APPROVAL_TOKEN='));

        return [$report, $this->approvalToken];
    }

    private function deploy(string $report, string $token): int
    {
        $this->approvalToken = $token === '' ? null : $token;
        $options = [
            'preflight-report' => $report,
            'expected-sha' => $this->platform->head,
            'maintenance-window-ack' => true,
        ];
        $checkpoints = glob($this->auditRoot.'/debt-pr-d-production-deploy-*/checkpoint.json') ?: [];
        if ($checkpoints !== []) {
            $checkpoint = ReleaseFiles::readJson($checkpoints[0]);
            if (array_intersect(array_column($this->manifest['migrations'], 'stage'), $checkpoint['completed_stages'] ?? []) !== []) {
                $options['resume-partial-ack'] = true;
            }
        }

        return $this->runner()->execute('deploy', $options);
    }

    private function latestCheckpoint(): array
    {
        [$path] = glob($this->auditRoot.'/debt-pr-d-production-deploy-*/checkpoint.json');

        return ReleaseFiles::readJson($path);
    }

    /** @return array{mode:int, owner:int|false, group:int|false} */
    private function directoryMetadata(string $directory): array
    {
        clearstatcache(true, $directory);
        $permissions = fileperms($directory);
        $this->assertNotFalse($permissions, 'Directory metadata must be readable: '.$directory);

        return [
            'mode' => $permissions & 07777,
            'owner' => fileowner($directory),
            'group' => filegroup($directory),
        ];
    }

    /** @return list<string> */
    private function credentialFiles(): array
    {
        $files = glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'debt-release-client-*.cnf') ?: [];
        sort($files);

        return array_values($files);
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

    public ?int $timeoutMigrationAt = null;

    public ?int $throwSignalAtMigration = null;

    public string $throwSignalName = 'SIGTERM';

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

        return [
            'pass' => $this->doctorPass,
            'pcntl_available' => true,
            'signal_recovery_available' => true,
            'blocker' => $this->doctorPass ? null : 'MISSING_DEPENDENCY',
        ];
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

        return [
            'pass' => $this->ddlRiskPass,
            'innodb_trx_visible' => true,
            'processlist_visible' => true,
            'metadata_locks_visible' => true,
            'DDL_VISIBILITY_COMPLETE' => true,
        ];
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
        if ($this->timeoutMigrationAt === $stage) {
            return new ProcessResult(124, '', 'timed out', true, 180000, 15);
        }
        if ($this->throwSignalAtMigration === $stage) {
            throw new ReleaseFailure('SIGNAL_'.$this->throwSignalName.'_INTERRUPTED', ExitCode::PARTIAL_RELEASE);
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

    public function forgetMigration(int $stage): void
    {
        unset($this->ran[$this->manifest['migrations'][$stage - 1]['name']]);
    }
}

final class FailingGzipProcessExecutor implements ProcessExecutor
{
    public function run(
        array $command,
        ?string $cwd = null,
        ?string $stdinFile = null,
        ?string $stdoutFile = null,
        ?int $timeoutSeconds = null,
    ): ProcessResult {
        if (($command[0] ?? null) === 'gzip' && ($command[1] ?? null) === '-c') {
            return new ProcessResult(1, '', 'simulated gzip failure');
        }

        return (new NativeProcessExecutor)->run($command, $cwd, $stdinFile, $stdoutFile, $timeoutSeconds);
    }
}

final class FailingMysqldumpProcessExecutor implements ProcessExecutor
{
    public function run(
        array $command,
        ?string $cwd = null,
        ?string $stdinFile = null,
        ?string $stdoutFile = null,
        ?int $timeoutSeconds = null,
    ): ProcessResult {
        if (($command[0] ?? null) === 'mysqldump') {
            return new ProcessResult(1, '', 'simulated mysqldump failure');
        }

        return (new NativeProcessExecutor)->run($command, $cwd, $stdinFile, $stdoutFile, $timeoutSeconds);
    }
}

final class FailingMysqlRestoreProcessExecutor implements ProcessExecutor
{
    public function run(
        array $command,
        ?string $cwd = null,
        ?string $stdinFile = null,
        ?string $stdoutFile = null,
        ?int $timeoutSeconds = null,
    ): ProcessResult {
        if (($command[0] ?? null) === 'mysql') {
            return new ProcessResult(1, '', 'simulated mysql restore failure');
        }

        return (new NativeProcessExecutor)->run($command, $cwd, $stdinFile, $stdoutFile, $timeoutSeconds);
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
