<?php

declare(strict_types=1);

namespace DebtRelease;

use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ExitCode
{
    public const SUCCESS = 0;

    public const INVALID_ARGUMENTS = 10;

    public const GIT_BLOCKER = 20;

    public const DATABASE_BLOCKER = 30;

    public const DEPENDENCY_BLOCKER = 40;

    public const LOCK_BLOCKER = 50;

    public const SCHEMA_BLOCKER = 60;

    public const DATA_BLOCKER = 70;

    public const SMOKE_BLOCKER = 80;

    public const PARTIAL_RELEASE = 90;
}

class ReleaseFailure extends RuntimeException
{
    public function __construct(public readonly string $blocker, public readonly int $releaseExitCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $blocker);
    }
}

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly bool $timedOut = false,
        public readonly float $durationMs = 0.0,
        public readonly ?int $terminationSignal = null,
    ) {}

    public function successful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }
}

interface ProcessExecutor
{
    /** @param list<string> $command */
    public function run(array $command, ?string $cwd = null, ?string $stdinFile = null, ?string $stdoutFile = null, ?int $timeoutSeconds = null): ProcessResult;
}

final class NativeProcessExecutor implements ProcessExecutor
{
    /** @var resource|null */
    private static $activeProcess = null;

    public function run(array $command, ?string $cwd = null, ?string $stdinFile = null, ?string $stdoutFile = null, ?int $timeoutSeconds = null): ProcessResult
    {
        $descriptors = [
            0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
            1 => $stdoutFile === null ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return new ProcessResult(127, '', 'Unable to start process.');
        }
        self::$activeProcess = $process;
        $started = hrtime(true);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $terminationSignal = null;
        $exitCode = -1;
        if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }
        try {
            while (true) {
                if ($stdoutFile === null && isset($pipes[1]) && is_resource($pipes[1])) {
                    $stdout .= (string) stream_get_contents($pipes[1]);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $stderr .= (string) stream_get_contents($pipes[2]);
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if ($timeoutSeconds !== null && (hrtime(true) - $started) >= $timeoutSeconds * 1_000_000_000) {
                    $timedOut = true;
                    $terminationSignal = 15;
                    proc_terminate($process, 15);
                    $graceDeadline = hrtime(true) + 2_000_000_000;
                    do {
                        usleep(50_000);
                        $status = proc_get_status($process);
                    } while ($status['running'] && hrtime(true) < $graceDeadline);
                    if ($status['running']) {
                        $terminationSignal = 9;
                        proc_terminate($process, 9);
                    }
                    break;
                }
                usleep(25_000);
            }
        } catch (Throwable $exception) {
            self::terminateActiveChild();
            throw $exception;
        } finally {
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                    $chunk = (string) stream_get_contents($pipes[$index]);
                    $index === 1 ? $stdout .= $chunk : $stderr .= $chunk;
                    fclose($pipes[$index]);
                }
            }
            $closeCode = proc_close($process);
            if ($exitCode < 0 && $closeCode >= 0) {
                $exitCode = $closeCode;
            }
            self::$activeProcess = null;
        }

        return new ProcessResult(
            $timedOut ? 124 : $exitCode,
            $stdout,
            $stderr,
            $timedOut,
            round((hrtime(true) - $started) / 1_000_000, 2),
            $terminationSignal,
        );
    }

    public static function terminateActiveChild(): void
    {
        if (is_resource(self::$activeProcess)) {
            @proc_terminate(self::$activeProcess, 15);
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                usleep(50_000);
                $status = @proc_get_status(self::$activeProcess);
            } while (is_array($status) && ($status['running'] ?? false) && hrtime(true) < $deadline);
            if (is_array($status) && ($status['running'] ?? false)) {
                @proc_terminate(self::$activeProcess, 9);
            }
        }
    }
}

final class SignalRecovery
{
    private static ?int $receivedSignal = null;

    private static bool $handling = false;

    public static function install(): bool
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return false;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (int $received): never {
                if (self::$handling) {
                    exit(ExitCode::PARTIAL_RELEASE);
                }
                self::$handling = true;
                self::$receivedSignal = $received;
                NativeProcessExecutor::terminateActiveChild();
                throw new ReleaseFailure('SIGNAL_'.self::name($received).'_INTERRUPTED', ExitCode::PARTIAL_RELEASE);
            });
        }

        return true;
    }

    public static function receivedSignal(): ?int
    {
        return self::$receivedSignal;
    }

    private static function name(int $signal): string
    {
        return match ($signal) {
            SIGINT => 'SIGINT',
            SIGTERM => 'SIGTERM',
            SIGHUP => 'SIGHUP',
            default => 'UNKNOWN',
        };
    }
}

final class ApprovalTokenInput
{
    public static function read(): string
    {
        $environment = getenv('DEBT_RELEASE_APPROVAL_TOKEN');
        if (is_string($environment) && $environment !== '') {
            putenv('DEBT_RELEASE_APPROVAL_TOKEN');
            unset($_ENV['DEBT_RELEASE_APPROVAL_TOKEN'], $_SERVER['DEBT_RELEASE_APPROVAL_TOKEN']);

            return $environment;
        }
        if (DIRECTORY_SEPARATOR === '\\' || ! is_readable('/dev/tty')) {
            throw new ReleaseFailure('APPROVAL_TOKEN_TTY_REQUIRED', ExitCode::INVALID_ARGUMENTS);
        }
        $tty = fopen('/dev/tty', 'r+');
        if ($tty === false) {
            throw new ReleaseFailure('APPROVAL_TOKEN_TTY_REQUIRED', ExitCode::INVALID_ARGUMENTS);
        }
        $descriptors = [0 => $tty, 1 => $tty, 2 => $tty];
        $disableEcho = proc_open(['stty', '-echo'], $descriptors, $pipes);
        if (! is_resource($disableEcho) || proc_close($disableEcho) !== 0) {
            fclose($tty);
            throw new ReleaseFailure('APPROVAL_TOKEN_HIDDEN_INPUT_FAILED', ExitCode::INVALID_ARGUMENTS);
        }
        try {
            fwrite($tty, 'Approval token: ');
            $token = trim((string) fgets($tty));
        } finally {
            $enableEcho = proc_open(['stty', 'echo'], $descriptors, $pipes);
            if (is_resource($enableEcho)) {
                proc_close($enableEcho);
            }
            fwrite($tty, PHP_EOL);
            fclose($tty);
        }
        if ($token === '') {
            throw new ReleaseFailure('APPROVAL_TOKEN_REQUIRED', ExitCode::INVALID_ARGUMENTS);
        }

        return $token;
    }
}

interface ReleasePlatform
{
    /** @return array<string, mixed> */
    public function doctor(array $manifest): array;

    /** @return array{branch:string,head:string,clean:bool,previous_sha_is_ancestor:bool} */
    public function gitState(array $manifest): array;

    /** @return array<string, mixed> */
    public function databaseIdentity(): array;

    /** @return array<string, mixed> */
    public function inspectDdlRisk(array $manifest): array;

    /** @return array<string, mixed> */
    public function createBackup(string $path): array;

    /** @return array<string, mixed> */
    public function verifyBackup(string $path, string $expectedSha256): array;

    /** @return array<string, mixed> */
    public function restoreTest(string $backupPath, array $manifest): array;

    /** @return array<string, mixed> */
    public function captureBaseline(array $manifest): array;

    public function acquireDatabaseLock(string $name): bool;

    public function releaseDatabaseLock(string $name): void;

    public function maintenanceDown(): void;

    public function maintenanceUp(): void;

    public function optimizeClear(): void;

    public function migrationRan(string $name): bool;

    public function runMigration(string $path): ProcessResult;

    /** @return array<string, mixed> */
    public function verifyMigrationStage(int $stage, array $manifest, array $baseline): array;

    /** @return array<string, mixed> */
    public function comparePostflight(array $manifest, array $baseline): array;

    /** @return array<string, mixed> */
    public function smoke(array $manifest, int $logOffset): array;

    public function logSize(): int;

    /** @return array<string, mixed> */
    public function migrationStatus(array $manifest): array;

    /** @return list<string> */
    public function temporaryDatabases(array $manifest): array;

    public function dropTemporaryDatabase(string $name, array $manifest): void;
}

final class FileReleaseLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): void
    {
        ReleaseFiles::ensureDirectory(dirname($this->path), 0750);
        $handle = fopen($this->path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new ReleaseFailure('FILESYSTEM_RELEASE_LOCK_BUSY', ExitCode::LOCK_BLOCKER);
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function cleanupIfStale(): bool
    {
        if (! is_file($this->path)) {
            return false;
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        return unlink($this->path);
    }
}

final class ReleaseFiles
{
    public static function ensureDirectory(string $path, int $mode): void
    {
        self::assertNoDirectorySymlink($path);
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path)) {
            throw new ReleaseFailure('DIRECTORY_PATH_IS_NOT_DIRECTORY', ExitCode::DEPENDENCY_BLOCKER);
        }

        $created = @mkdir($path, $mode, true);
        if (! $created) {
            self::assertNoDirectorySymlink($path);
            if (is_dir($path)) {
                return;
            }
            throw new ReleaseFailure('AUDIT_DIRECTORY_CREATE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        }

        self::assertNoDirectorySymlink($path);
        if (DIRECTORY_SEPARATOR !== '\\' && ! @chmod($path, $mode)) {
            @rmdir($path);
            throw new ReleaseFailure('CREATED_DIRECTORY_MODE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            clearstatcache(true, $path);
            $permissions = fileperms($path);
            if ($permissions === false || ($permissions & 07777) !== $mode) {
                @rmdir($path);
                throw new ReleaseFailure('CREATED_DIRECTORY_MODE_INVALID', ExitCode::DEPENDENCY_BLOCKER);
            }
        }
    }

    public static function atomicJson(string $path, array $payload, int $mode = 0640): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        self::atomicWrite($path, $json, $mode);
    }

    public static function atomicText(string $path, string $text, int $mode = 0640): void
    {
        self::atomicWrite($path, $text, $mode);
    }

    public static function writeExclusiveClientCredential(
        string $directory,
        string $filename,
        string $content,
        ?callable $failureInjector = null,
    ): string {
        if (is_link($directory)) {
            throw new ReleaseFailure('SYSTEM_TEMP_DIRECTORY_SYMLINK_BLOCKED', ExitCode::DEPENDENCY_BLOCKER);
        }
        if (! is_dir($directory)) {
            throw new ReleaseFailure('SYSTEM_TEMP_DIRECTORY_MISSING', ExitCode::DEPENDENCY_BLOCKER);
        }
        if (! is_writable($directory)) {
            throw new ReleaseFailure('SYSTEM_TEMP_DIRECTORY_NOT_WRITABLE', ExitCode::DEPENDENCY_BLOCKER);
        }
        if (basename($filename) !== $filename || ! preg_match('/^debt-release-client-[a-f0-9]{16}\.cnf$/', $filename)) {
            throw new ReleaseFailure('CLIENT_CREDENTIAL_FILENAME_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }

        $parentMetadata = self::directorySecurityMetadata($directory);
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$filename;
        $handle = null;
        $created = false;
        $success = false;
        try {
            self::injectFailure($failureInjector, 'create');
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new ReleaseFailure('CLIENT_CREDENTIAL_CREATE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $created = true;

            self::injectFailure($failureInjector, 'chmod');
            if (DIRECTORY_SEPARATOR !== '\\' && ! @chmod($path, 0600)) {
                throw new ReleaseFailure('CLIENT_CREDENTIAL_MODE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }

            self::injectFailure($failureInjector, 'write');
            self::writeAll($handle, $content, 'CLIENT_CREDENTIAL_WRITE_FAILED');

            self::injectFailure($failureInjector, 'flush');
            if (! fflush($handle)) {
                throw new ReleaseFailure('CLIENT_CREDENTIAL_FLUSH_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                clearstatcache(true, $path);
                $permissions = fileperms($path);
                if ($permissions === false || ($permissions & 0777) !== 0600) {
                    throw new ReleaseFailure('CLIENT_CREDENTIAL_MODE_INVALID', ExitCode::DEPENDENCY_BLOCKER);
                }
            }
            if (self::directorySecurityMetadata($directory) !== $parentMetadata) {
                throw new ReleaseFailure('CLIENT_CREDENTIAL_PARENT_METADATA_CHANGED', ExitCode::DEPENDENCY_BLOCKER);
            }

            $success = true;

            return $path;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! $success && $created) {
                @unlink($path);
            }
        }
    }

    public static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new ReleaseFailure('REPORT_NOT_FOUND', ExitCode::INVALID_ARGUMENTS);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReleaseFailure('REPORT_JSON_INVALID', ExitCode::INVALID_ARGUMENTS);
        }
        if (! is_array($value)) {
            throw new ReleaseFailure('REPORT_JSON_INVALID', ExitCode::INVALID_ARGUMENTS);
        }

        return $value;
    }

    public static function assertWithin(string $path, string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $candidate = str_replace('\\', '/', $path);
        if (! str_starts_with($candidate, '/') && ! preg_match('/^[A-Za-z]:\//', $candidate)) {
            $candidate = $root.'/'.ltrim($candidate, '/');
        }
        $candidate = self::normalizePath($candidate);
        if ($candidate !== $root && ! str_starts_with($candidate, $root.'/')) {
            throw new ReleaseFailure('PATH_OUTSIDE_ALLOWLIST', ExitCode::INVALID_ARGUMENTS);
        }

        return $candidate;
    }

    public static function normalizePath(string $path): string
    {
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return $prefix.implode('/', $parts);
    }

    private static function atomicWrite(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory, 0750);
        $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($tmp, 'x+b');
        if ($handle === false) {
            throw new ReleaseFailure('REPORT_WRITE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        }

        $renamed = false;
        try {
            if (DIRECTORY_SEPARATOR !== '\\' && ! @chmod($tmp, $mode)) {
                throw new ReleaseFailure('REPORT_MODE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            self::writeAll($handle, $content, 'REPORT_WRITE_FAILED');
            if (! fflush($handle)) {
                throw new ReleaseFailure('REPORT_FLUSH_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            fclose($handle);
            $handle = null;
            if (! rename($tmp, $path)) {
                throw new ReleaseFailure('REPORT_ATOMIC_RENAME_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $renamed = true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! $renamed) {
                @unlink($tmp);
            }
        }
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $content, string $blocker): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new ReleaseFailure($blocker, ExitCode::DEPENDENCY_BLOCKER);
            }
            $offset += $written;
        }
    }

    private static function assertNoDirectorySymlink(string $path): void
    {
        $candidate = rtrim($path, '/\\');
        if ($candidate === '') {
            $candidate = DIRECTORY_SEPARATOR;
        } elseif (preg_match('/^[A-Za-z]:$/', $candidate)) {
            $candidate .= DIRECTORY_SEPARATOR;
        }
        while (true) {
            if (is_link($candidate)) {
                throw new ReleaseFailure('DIRECTORY_SYMLINK_BLOCKED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return;
            }
            $candidate = $parent;
        }
    }

    /** @return array{mode:int, owner:int|false, group:int|false} */
    private static function directorySecurityMetadata(string $directory): array
    {
        clearstatcache(true, $directory);
        $permissions = fileperms($directory);
        if ($permissions === false) {
            throw new ReleaseFailure('SYSTEM_TEMP_DIRECTORY_METADATA_UNAVAILABLE', ExitCode::DEPENDENCY_BLOCKER);
        }

        return [
            'mode' => $permissions & 07777,
            'owner' => fileowner($directory),
            'group' => filegroup($directory),
        ];
    }

    private static function injectFailure(?callable $failureInjector, string $stage): void
    {
        if ($failureInjector !== null) {
            $failureInjector($stage);
        }
    }
}

final class Redactor
{
    private const SENSITIVE = '/password|passwd|secret|app_key|username|dsn|database_name|approval_token/i';

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE, $key)) {
            return '[REDACTED]';
        }
        if (! is_array($value)) {
            return $value;
        }
        $output = [];
        foreach ($value as $childKey => $childValue) {
            $output[$childKey] = self::redact($childValue, (string) $childKey);
        }

        return $output;
    }
}

final class ManifestValidator
{
    public static function validate(array $manifest, string $repositoryRoot): void
    {
        foreach (['release_id', 'release_name', 'expected_previous_production_sha', 'expected_database_name_sha256', 'allowed_branch', 'database_engine', 'database_advisory_lock', 'preflight_ttl_minutes', 'raw_sql_temp_pattern', 'signal_recovery_required', 'process_timeouts_seconds', 'migrations', 'new_columns', 'unique_indexes', 'foreign_keys', 'checks', 'legacy_offset_columns', 'invariant_table_groups'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new ReleaseFailure('MANIFEST_MISSING_'.strtoupper($key), ExitCode::DEPENDENCY_BLOCKER);
            }
        }
        if ($manifest['release_id'] !== 'debt-pr-d' || count($manifest['migrations']) !== 3) {
            throw new ReleaseFailure('MANIFEST_RELEASE_CONTRACT_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }
        if ($manifest['process_timeouts_seconds'] !== [
            'git_php' => 30,
            'curl' => 30,
            'gzip' => 60,
            'backup' => 1800,
            'restore' => 1800,
            'migration' => 180,
            'optimize_clear' => 120,
            'maintenance' => 60,
        ]) {
            throw new ReleaseFailure('PROCESS_TIMEOUT_CONTRACT_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }
        $expected = [
            '2026_07_17_000000_add_workflow_evidence_columns_to_debt_offsets',
            '2026_07_17_000100_add_workflow_keys_and_foreign_keys_to_debt_offsets',
            '2026_07_17_000200_add_workflow_checks_to_debt_offsets',
        ];
        if (array_column($manifest['migrations'], 'name') !== $expected) {
            throw new ReleaseFailure('MIGRATION_ALLOWLIST_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }
        $expectedPaths = array_map(
            static fn (string $name): string => 'database/migrations/'.$name.'.php',
            $expected,
        );
        if (array_column($manifest['migrations'], 'path') !== $expectedPaths) {
            throw new ReleaseFailure('MIGRATION_PATH_ALLOWLIST_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }
        foreach ($manifest['migrations'] as $migration) {
            $path = $repositoryRoot.'/'.ltrim((string) $migration['path'], '/');
            if (! is_file($path)) {
                throw new ReleaseFailure('MIGRATION_FILE_MISSING', ExitCode::DEPENDENCY_BLOCKER, (string) $migration['path']);
            }
        }
    }
}

final class CliArguments
{
    private const COMMANDS = ['doctor', 'preflight', 'deploy', 'status', 'cleanup'];

    private const VALUES = ['expected-sha', 'preflight-report'];

    private const FLAGS = ['maintenance-window-ack', 'resume-partial-ack', 'help'];

    /** @param list<string> $argv */
    public static function parse(array $argv): array
    {
        array_shift($argv);
        if ($argv === [] || $argv[0] === '--help' || $argv[0] === '-h') {
            return ['command' => 'help', 'options' => []];
        }
        $command = array_shift($argv);
        if (! in_array($command, self::COMMANDS, true)) {
            throw new ReleaseFailure('UNKNOWN_SUBCOMMAND', ExitCode::INVALID_ARGUMENTS);
        }
        if ($argv === ['--help']) {
            return ['command' => 'help', 'options' => []];
        }
        $options = [];
        while ($argv !== []) {
            $argument = array_shift($argv);
            if (! str_starts_with($argument, '--')) {
                throw new ReleaseFailure('INVALID_ARGUMENT', ExitCode::INVALID_ARGUMENTS, $argument);
            }
            $name = substr($argument, 2);
            if (! in_array($name, array_merge(self::VALUES, self::FLAGS), true) || array_key_exists($name, $options)) {
                throw new ReleaseFailure('INVALID_OR_DUPLICATE_OPTION', ExitCode::INVALID_ARGUMENTS, $name);
            }
            if (in_array($name, self::FLAGS, true)) {
                $options[$name] = true;

                continue;
            }
            if ($argv === [] || str_starts_with((string) $argv[0], '--')) {
                throw new ReleaseFailure('OPTION_VALUE_MISSING', ExitCode::INVALID_ARGUMENTS, $name);
            }
            $options[$name] = array_shift($argv);
        }

        return ['command' => $command, 'options' => $options];
    }
}

final class DebtReleaseRunner
{
    /** @var callable(string):void */
    private $output;

    /** @var callable():DateTimeImmutable */
    private $clock;

    /** @var callable(int):string */
    private $random;

    /** @var callable():string */
    private $tokenReader;

    public function __construct(
        private readonly array $manifest,
        private readonly string $repositoryRoot,
        private readonly ReleasePlatform $platform,
        private readonly FileReleaseLock $fileLock,
        private readonly string $auditRoot,
        private readonly string $backupRoot,
        ?callable $output = null,
        ?callable $clock = null,
        ?callable $random = null,
        ?callable $tokenReader = null,
    ) {
        $this->output = $output ?? static fn (string $line) => print $line.PHP_EOL;
        $this->clock = $clock ?? static fn () => new DateTimeImmutable('now');
        $this->random = $random ?? static fn (int $bytes) => bin2hex(random_bytes($bytes));
        $this->tokenReader = $tokenReader ?? ApprovalTokenInput::read(...);
    }

    public function execute(string $command, array $options): int
    {
        ManifestValidator::validate($this->manifest, $this->repositoryRoot);

        return match ($command) {
            'doctor' => $this->doctor($options),
            'preflight' => $this->preflight($options),
            'deploy' => $this->deploy($options),
            'status' => $this->status($options),
            'cleanup' => $this->cleanup($options),
            default => throw new ReleaseFailure('UNKNOWN_SUBCOMMAND', ExitCode::INVALID_ARGUMENTS),
        };
    }

    private function doctor(array $options): int
    {
        $this->assertAllowedOptions($options, []);
        $doctor = $this->platform->doctor($this->manifest);
        $this->assertPassing($doctor, 'DEPENDENCY_DOCTOR_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        $git = $this->platform->gitState($this->manifest);
        $identity = $this->platform->databaseIdentity();
        ($this->output)('RELEASE='.$this->manifest['release_id']);
        ($this->output)('DOCTOR_STATUS=PASS');
        ($this->output)('BRANCH='.$git['branch']);
        ($this->output)('HEAD='.$git['head']);
        ($this->output)('WORKTREE_CLEAN='.($git['clean'] ? 'yes' : 'no'));
        ($this->output)('CONNECTED_DATABASE_SHA256='.$identity['database_sha256']);
        ($this->output)('PCNTL_AVAILABLE='.(($doctor['pcntl_available'] ?? false) ? 'yes' : 'no'));
        ($this->output)('SIGNAL_RECOVERY_AVAILABLE='.(($doctor['signal_recovery_available'] ?? false) ? 'yes' : 'no'));

        return ExitCode::SUCCESS;
    }

    private function preflight(array $options): int
    {
        $this->assertAllowedOptions($options, ['expected-sha']);
        $expectedSha = $this->requiredString($options, 'expected-sha');
        $this->fileLock->acquire();
        try {
            $git = $this->assertGitGate($expectedSha);
            $identity = $this->assertDatabaseGate();
            $doctor = $this->platform->doctor($this->manifest);
            $this->assertPassing($doctor, 'DEPENDENCY_DOCTOR_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            if (($this->manifest['signal_recovery_required'] ?? false)
                && ($doctor['signal_recovery_available'] ?? false) !== true) {
                throw new ReleaseFailure('SIGNAL_RECOVERY_UNAVAILABLE', ExitCode::DEPENDENCY_BLOCKER);
            }
            $doctor = $this->platform->doctor($this->manifest);
            $this->assertPassing($doctor, 'DEPENDENCY_DOCTOR_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            $risk = $this->platform->inspectDdlRisk($this->manifest);
            $this->assertPassing($risk, 'DDL_RISK_BLOCKED', ExitCode::LOCK_BLOCKER);
            $migrationStatus = $this->platform->migrationStatus($this->manifest);
            if (($migrationStatus['ran_count'] ?? -1) !== 0) {
                throw new ReleaseFailure('PR_D_MIGRATIONS_ALREADY_PRESENT_AT_PREFLIGHT', ExitCode::SCHEMA_BLOCKER);
            }

            $now = ($this->clock)();
            [$stamp, $directory, $backupPath, $deploymentDirectory] = $this->allocatePreflightArtifactPaths($now);
            ReleaseFiles::ensureDirectory($directory, 0750);
            $backup = $this->platform->createBackup($backupPath);
            $this->assertPassing($backup, 'BACKUP_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            $restore = $this->platform->restoreTest($backupPath, $this->manifest);
            $this->assertPassing($restore, 'RESTORE_TEST_FAILED', ExitCode::DATABASE_BLOCKER);

            $token = ($this->random)(32);
            $createdAt = $now->format(DATE_ATOM);
            $expiresAt = $now->modify('+'.(int) $this->manifest['preflight_ttl_minutes'].' minutes')->format(DATE_ATOM);
            $reportPath = $directory.'/preflight-report.json';
            $report = [
                'release_id' => $this->manifest['release_id'],
                'status' => 'PASS',
                'ready_for_controlled_deploy' => true,
                'expected_sha' => $expectedSha,
                'git' => $git,
                'database' => $identity,
                'doctor' => Redactor::redact($doctor),
                'ddl_risk' => Redactor::redact($risk),
                'backup' => Redactor::redact($backup),
                'restore_test' => Redactor::redact($restore),
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'deployment_directory' => $deploymentDirectory,
            ];
            ReleaseFiles::atomicJson($reportPath, $report);
            $reportHash = hash_file('sha256', $reportPath);
            if ($reportHash === false) {
                throw new ReleaseFailure('REPORT_HASH_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $binding = $this->approvalBinding($report, $reportHash);
            ReleaseFiles::atomicJson($directory.'/approval-token.sha256', [
                'release_id' => $this->manifest['release_id'],
                'token_sha256' => hash('sha256', $token),
                'report_sha256' => $reportHash,
                'binding_sha256' => hash('sha256', $binding),
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
            ], 0600);
            ReleaseFiles::atomicText($directory.'/preflight-summary.txt', implode(PHP_EOL, [
                'RELEASE='.$this->manifest['release_id'],
                'PREFLIGHT_STATUS=PASS',
                'EXPECTED_SHA='.$expectedSha,
                'CONNECTED_DATABASE_SHA256='.$identity['database_sha256'],
                'BACKUP=PASS',
                'RESTORE_TEST=PASS',
                'DDL_RISK=PASS_WITH_MAINTENANCE_REQUIRED',
                'READY_FOR_CONTROLLED_DEPLOY=yes',
                'PREFLIGHT_REPORT='.$reportPath,
                'PREFLIGHT_REPORT_SHA256='.$reportHash,
            ]).PHP_EOL);

            foreach ([
                'RELEASE='.$this->manifest['release_id'],
                'PREFLIGHT_STATUS=PASS',
                'EXPECTED_SHA='.$expectedSha,
                'CONNECTED_DATABASE_SHA256='.$identity['database_sha256'],
                'BACKUP=PASS',
                'RESTORE_TEST=PASS',
                'DDL_RISK=PASS_WITH_MAINTENANCE_REQUIRED',
                'READY_FOR_CONTROLLED_DEPLOY=yes',
                'PREFLIGHT_REPORT='.$reportPath,
                'PREFLIGHT_REPORT_SHA256='.$reportHash,
                'APPROVAL_TOKEN='.$token,
            ] as $line) {
                ($this->output)($line);
            }

            return ExitCode::SUCCESS;
        } catch (ReleaseFailure $failure) {
            if (isset($directory)) {
                $blockedReport = [
                    'release_id' => $this->manifest['release_id'],
                    'status' => 'BLOCKED',
                    'ready_for_controlled_deploy' => false,
                    'blocker' => $failure->blocker,
                    'expected_sha' => $expectedSha,
                    'created_at' => ($this->clock)()->format(DATE_ATOM),
                ];
                ReleaseFiles::atomicJson($directory.'/preflight-report.json', $blockedReport);
                ReleaseFiles::atomicText($directory.'/preflight-summary.txt', implode(PHP_EOL, [
                    'RELEASE='.$this->manifest['release_id'],
                    'PREFLIGHT_STATUS=BLOCKED',
                    'BLOCKER='.$failure->blocker,
                    'READY_FOR_CONTROLLED_DEPLOY=no',
                ]).PHP_EOL);
            }
            throw $failure;
        } finally {
            $this->fileLock->release();
        }
    }

    private function deploy(array $options): int
    {
        $this->assertAllowedOptions($options, ['expected-sha', 'preflight-report', 'maintenance-window-ack', 'resume-partial-ack']);
        $expectedSha = $this->requiredString($options, 'expected-sha');
        $reportOption = $this->requiredString($options, 'preflight-report');
        if (($options['maintenance-window-ack'] ?? false) !== true) {
            throw new ReleaseFailure('MAINTENANCE_WINDOW_ACK_REQUIRED', ExitCode::INVALID_ARGUMENTS);
        }

        $reportPath = ReleaseFiles::assertWithin($reportOption, $this->auditRoot);
        $report = ReleaseFiles::readJson($reportPath);
        $preflightEvidence = $this->validatePreflightReport($report, $reportPath, $expectedSha);

        $this->fileLock->acquire();
        $dbLocked = false;
        $maintenanceEntered = false;
        $deploymentDirectory = ReleaseFiles::assertWithin((string) ($report['deployment_directory'] ?? ''), $this->auditRoot);
        ReleaseFiles::ensureDirectory($deploymentDirectory, 0750);
        $checkpointPath = $deploymentDirectory.'/checkpoint.json';
        $failureReportPath = null;
        $successfulReportPath = null;
        try {
            $git = $this->assertGitGate($expectedSha);
            $identity = $this->assertDatabaseGate();
            $checkpointExists = is_file($checkpointPath);
            $checkpoint = $checkpointExists
                ? ReleaseFiles::readJson($checkpointPath)
                : $this->newCheckpoint($reportPath, $report, $expectedSha, $identity);
            $this->assertCheckpointIdentity($checkpoint, $expectedSha, $identity);
            if (($checkpoint['preflight_report_path'] ?? '') !== $reportPath
                || ($checkpoint['preflight_report_sha256'] ?? $preflightEvidence['report_sha256']) !== $preflightEvidence['report_sha256']) {
                throw new ReleaseFailure('CHECKPOINT_PREFLIGHT_BINDING_MISMATCH', ExitCode::PARTIAL_RELEASE);
            }
            $migrationStatus = $this->platform->migrationStatus($this->manifest);
            if (! $checkpointExists && (int) ($migrationStatus['ran_count'] ?? 0) > 0) {
                throw new ReleaseFailure('PARTIAL_RESUME_CHECKPOINT_REQUIRED', ExitCode::PARTIAL_RELEASE);
            }
            $verifiedMigrationStages = array_intersect(
                array_column($this->manifest['migrations'], 'stage'),
                $checkpoint['completed_stages'] ?? [],
            );
            $isPartialResume = $checkpointExists
                && ($verifiedMigrationStages !== [] || (int) ($migrationStatus['ran_count'] ?? 0) > 0);
            if (($checkpoint['stage'] ?? '') === 'closeout_written') {
                throw new ReleaseFailure('SUCCESSFUL_CLOSEOUT_CANNOT_BE_RESUMED', ExitCode::INVALID_ARGUMENTS);
            }
            if ($isPartialResume && ($options['resume-partial-ack'] ?? false) !== true) {
                throw new ReleaseFailure('PARTIAL_RESUME_ACK_REQUIRED', ExitCode::INVALID_ARGUMENTS);
            }
            if (! $isPartialResume && ($options['resume-partial-ack'] ?? false) === true) {
                throw new ReleaseFailure('PARTIAL_RESUME_NOT_AVAILABLE', ExitCode::INVALID_ARGUMENTS);
            }
            $token = null;
            if (! $isPartialResume) {
                $this->assertFreshPreflightAuthorization($report, $preflightEvidence['sidecar']);
                $token = ($this->tokenReader)();
                if (! hash_equals((string) ($preflightEvidence['sidecar']['token_sha256'] ?? ''), hash('sha256', $token))) {
                    throw new ReleaseFailure('APPROVAL_TOKEN_INVALID', ExitCode::INVALID_ARGUMENTS);
                }
                unset($token);
            }
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'initialized');

            $dbLocked = $this->platform->acquireDatabaseLock((string) $this->manifest['database_advisory_lock']);
            if (! $dbLocked) {
                throw new ReleaseFailure('DATABASE_RELEASE_LOCK_BUSY', ExitCode::LOCK_BLOCKER);
            }
            $this->platform->maintenanceDown();
            $maintenanceEntered = true;
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'maintenance_entered');
            $logOffset = $this->platform->logSize();
            $deployDdlRisk = $this->platform->inspectDdlRisk($this->manifest);
            $this->assertPassing($deployDdlRisk, 'DEPLOY_START_DDL_RISK_BLOCKED', ExitCode::PARTIAL_RELEASE);

            $baselinePath = $deploymentDirectory.'/deploy-start-baseline.json';
            if (is_file($baselinePath)) {
                $baseline = ReleaseFiles::readJson($baselinePath);
            } else {
                $baseline = $this->platform->captureBaseline($this->manifest);
                $this->assertPassing($baseline, 'DEPLOY_BASELINE_FAILED', ExitCode::DATA_BLOCKER);
                ReleaseFiles::atomicJson($baselinePath, Redactor::redact($baseline));
            }
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'baseline_captured');

            if (! $isPartialResume) {
                $this->consumeApprovalToken($preflightEvidence['sidecar_path'], $preflightEvidence['sidecar'], $checkpointPath, $checkpoint);
            }

            $migrationResults = [];
            foreach ($this->manifest['migrations'] as $index => $migration) {
                $stageNumber = $index + 1;
                $stage = (string) $migration['stage'];
                $alreadyVerified = in_array($stage, $checkpoint['completed_stages'] ?? [], true);
                $ran = $this->platform->migrationRan((string) $migration['name']);
                if ($alreadyVerified && ! $ran) {
                    throw new ReleaseFailure('CHECKPOINT_DATABASE_STATE_MISMATCH', ExitCode::PARTIAL_RELEASE);
                }
                $migrationStartedAt = microtime(true);
                if (! $ran) {
                    $result = $this->platform->runMigration((string) $migration['path']);
                    if (! $result->successful()) {
                        $suffix = $result->timedOut ? '_TIMEOUT' : '_FAILED';
                        throw new ReleaseFailure('MIGRATION_'.$stageNumber.$suffix, ExitCode::PARTIAL_RELEASE);
                    }
                }
                $verification = $this->platform->verifyMigrationStage($stageNumber, $this->manifest, $baseline);
                $this->assertPassing($verification, 'MIGRATION_'.$stageNumber.'_VERIFICATION_FAILED', ExitCode::PARTIAL_RELEASE);
                $migrationResults[$stage] = [
                    'status' => 'PASS',
                    'resumed' => $alreadyVerified || $ran,
                    'duration_ms' => round((microtime(true) - $migrationStartedAt) * 1000, 2),
                    'batch' => $verification['migration_batch'] ?? null,
                    'verification' => Redactor::redact($verification),
                ];
                $this->writeCheckpoint($checkpointPath, $checkpoint, $stage);
            }

            $postflight = $this->platform->comparePostflight($this->manifest, $baseline);
            $this->assertPassing($postflight, 'POSTFLIGHT_INVARIANCE_FAILED', ExitCode::PARTIAL_RELEASE);
            ReleaseFiles::atomicJson($deploymentDirectory.'/postflight.json', Redactor::redact($postflight));
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'postflight_verified');
            $this->platform->optimizeClear();
            $this->platform->maintenanceUp();
            $maintenanceEntered = false;
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'application_recovered');
            $smoke = $this->platform->smoke($this->manifest, $logOffset);
            $this->assertPassing($smoke, 'HTTP_OR_LOG_SMOKE_FAILED', ExitCode::SMOKE_BLOCKER);
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'smoke_verified');

            $deploymentReport = [
                'release_id' => $this->manifest['release_id'],
                'deployment_status' => 'SUCCESS',
                'expected_sha' => $expectedSha,
                'deployed_sha' => $git['head'],
                'git' => $git,
                'database' => $identity,
                'preflight_report' => $reportPath,
                'preflight_report_sha256' => hash_file('sha256', $reportPath),
                'backup' => $report['backup'],
                'restore_test' => $report['restore_test'],
                'preflight_ddl_risk' => $report['ddl_risk'],
                'deploy_start_ddl_risk' => Redactor::redact($deployDdlRisk),
                'locks' => [
                    'filesystem' => ['acquired' => true, 'released' => false, 'release_guarantee' => 'finally'],
                    'database' => ['acquired' => true, 'released' => false, 'release_guarantee' => 'finally'],
                ],
                'checkpoint_stages' => $checkpoint['timestamps'] ?? [],
                'migrations' => $migrationResults,
                'postflight' => Redactor::redact($postflight),
                'data_snapshot_consistent' => ($baseline['data_snapshot_consistent'] ?? false)
                    && ($postflight['data_snapshot_consistent'] ?? false),
                'snapshot_read_only' => ($baseline['snapshot_read_only'] ?? false)
                    && ($postflight['snapshot_read_only'] ?? false),
                'snapshot_rolled_back' => ($baseline['snapshot_rolled_back'] ?? false)
                    && ($postflight['snapshot_rolled_back'] ?? false),
                'smoke' => Redactor::redact($smoke),
                'maintenance_recovered' => true,
                'backfill' => false,
                'workflow_enabled' => false,
                'workflow_status_backfilled' => false,
                'operations_created' => false,
                'outbox_created' => false,
                'current_debt_data_changed' => false,
                'ready_for_pr_d_production_closeout' => true,
                'completed_at' => ($this->clock)()->format(DATE_ATOM),
            ];
            $deploymentReportPath = $deploymentDirectory.'/deployment-report.json';
            ReleaseFiles::atomicJson($deploymentReportPath, $deploymentReport);
            $this->writeCheckpoint($checkpointPath, $checkpoint, 'closeout_written');
            $successfulReportPath = $deploymentReportPath;
        } catch (ReleaseFailure $failure) {
            if ($failure->blocker === 'SUCCESSFUL_CLOSEOUT_CANNOT_BE_RESUMED') {
                throw $failure;
            }
            if (isset($checkpoint)) {
                $checkpoint['blocker'] = $failure->blocker;
                $checkpoint['failed_at'] = ($this->clock)()->format(DATE_ATOM);
                if (SignalRecovery::receivedSignal() !== null) {
                    $checkpoint['stage'] = 'interrupted';
                    $checkpoint['interrupted_signal'] = SignalRecovery::receivedSignal();
                    $checkpoint['timestamps']['interrupted'] = $checkpoint['failed_at'];
                }
                ReleaseFiles::atomicJson($checkpointPath, Redactor::redact($checkpoint));
            }
            $failureReportPath = $deploymentDirectory.'/deployment-report.json';
            ReleaseFiles::atomicJson($failureReportPath, [
                'release_id' => $this->manifest['release_id'],
                'deployment_status' => 'PARTIAL',
                'blocker' => $failure->blocker,
                'exit_code' => $failure->releaseExitCode,
                'last_successful_stage' => $checkpoint['stage'] ?? 'none',
                'maintenance_recovered' => ! $maintenanceEntered,
                'safe_rerun_required' => true,
                'safe_rerun_command' => $this->safeRerunCommand(
                    $reportPath,
                    $expectedSha,
                    isset($checkpoint) && array_intersect(
                        array_column($this->manifest['migrations'], 'stage'),
                        $checkpoint['completed_stages'] ?? [],
                    ) !== [],
                ),
                'interrupted_signal' => SignalRecovery::receivedSignal(),
                'backfill' => false,
                'current_debt_data_changed' => false,
                'failed_at' => ($this->clock)()->format(DATE_ATOM),
            ]);
            throw $failure;
        } finally {
            $recovered = ! $maintenanceEntered;
            if ($maintenanceEntered) {
                try {
                    $this->platform->maintenanceUp();
                    $recovered = true;
                } catch (Throwable) {
                    // The original blocker remains authoritative; recovery failure is visible in status/checkpoint.
                }
            }
            if ($failureReportPath !== null && is_file($failureReportPath)) {
                $failureReport = ReleaseFiles::readJson($failureReportPath);
                $failureReport['maintenance_recovered'] = $recovered;
                ReleaseFiles::atomicJson($failureReportPath, $failureReport);
            }
            $databaseLockReleased = ! $dbLocked;
            if ($dbLocked) {
                try {
                    $this->platform->releaseDatabaseLock((string) $this->manifest['database_advisory_lock']);
                    $databaseLockReleased = true;
                } catch (Throwable) {
                    $databaseLockReleased = false;
                }
            }
            $this->fileLock->release();
            if ($successfulReportPath !== null && is_file($successfulReportPath)) {
                $successfulReport = ReleaseFiles::readJson($successfulReportPath);
                $releasedAt = ($this->clock)()->format(DATE_ATOM);
                $successfulReport['locks']['filesystem']['released'] = true;
                $successfulReport['locks']['filesystem']['released_at'] = $releasedAt;
                $successfulReport['locks']['database']['released'] = $databaseLockReleased;
                $successfulReport['locks']['database']['released_at'] = $databaseLockReleased ? $releasedAt : null;
                $successfulReport['checkpoint_stages'] = $checkpoint['timestamps'] ?? [];
                if (! $databaseLockReleased) {
                    $successfulReport['deployment_status'] = 'PARTIAL';
                    $successfulReport['blocker'] = 'DATABASE_RELEASE_LOCK_RELEASE_FAILED';
                }
                ReleaseFiles::atomicJson($successfulReportPath, $successfulReport);
            }
            if ($failureReportPath !== null && is_file($failureReportPath)) {
                $failureReport = ReleaseFiles::readJson($failureReportPath);
                $failureReport['database_lock_released'] = $databaseLockReleased;
                ReleaseFiles::atomicJson($failureReportPath, $failureReport);
            }
            if ($successfulReportPath !== null && ! $databaseLockReleased) {
                throw new ReleaseFailure('DATABASE_RELEASE_LOCK_RELEASE_FAILED', ExitCode::PARTIAL_RELEASE);
            }
        }

        $deploymentHash = hash_file('sha256', (string) $successfulReportPath);
        if ($successfulReportPath === null || $deploymentHash === false) {
            throw new ReleaseFailure('DEPLOYMENT_REPORT_HASH_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        }
        $summary = $this->deploymentSummary($expectedSha, $successfulReportPath, $deploymentHash);
        ReleaseFiles::atomicText($deploymentDirectory.'/deployment-summary.txt', $summary);
        foreach (explode(PHP_EOL, trim($summary)) as $line) {
            ($this->output)($line);
        }

        return ExitCode::SUCCESS;
    }

    private function status(array $options): int
    {
        $this->assertAllowedOptions($options, []);
        $git = $this->platform->gitState($this->manifest);
        $identity = $this->platform->databaseIdentity();
        $migrationStatus = $this->platform->migrationStatus($this->manifest);
        $checkpoints = glob(rtrim($this->auditRoot, '/\\').'/debt-pr-d-production-deploy-*/checkpoint.json') ?: [];
        rsort($checkpoints, SORT_STRING);
        $checkpoint = $checkpoints === [] ? null : ReleaseFiles::readJson($checkpoints[0]);
        ($this->output)('RELEASE='.$this->manifest['release_id']);
        ($this->output)('STATUS=READ_ONLY');
        ($this->output)('HEAD='.$git['head']);
        ($this->output)('CONNECTED_DATABASE_SHA256='.$identity['database_sha256']);
        ($this->output)('MIGRATIONS_RAN='.(string) ($migrationStatus['ran_count'] ?? 0).'/3');
        ($this->output)('LAST_STAGE='.($checkpoint['stage'] ?? 'none'));
        if (is_array($checkpoint)
            && ($checkpoint['stage'] ?? '') !== 'closeout_written'
            && isset($checkpoint['preflight_report_path'], $checkpoint['expected_sha'])) {
            $resume = array_intersect(
                array_column($this->manifest['migrations'], 'stage'),
                $checkpoint['completed_stages'] ?? [],
            ) !== [];
            ($this->output)('SAFE_RERUN_COMMAND='.$this->safeRerunCommand(
                (string) $checkpoint['preflight_report_path'],
                (string) $checkpoint['expected_sha'],
                $resume,
            ));
        }

        return ExitCode::SUCCESS;
    }

    private function cleanup(array $options): int
    {
        $this->assertAllowedOptions($options, []);
        $this->fileLock->acquire();
        try {
            $this->assertDatabaseGate();
            $dropped = [];
            foreach ($this->platform->temporaryDatabases($this->manifest) as $database) {
                if (! preg_match((string) $this->manifest['temp_database_pattern'], $database)) {
                    throw new ReleaseFailure('UNSAFE_TEMP_DATABASE_NAME', ExitCode::DATABASE_BLOCKER);
                }
                $this->platform->dropTemporaryDatabase($database, $this->manifest);
                $dropped[] = hash('sha256', $database);
            }
            $credentialsRemoved = $this->cleanupTemporaryCredentials();
            $rawSqlRemoved = $this->cleanupRawSqlTemps();
        } finally {
            $this->fileLock->release();
        }
        $staleLock = $this->fileLock->cleanupIfStale();
        ($this->output)('RELEASE='.$this->manifest['release_id']);
        ($this->output)('CLEANUP_STATUS=PASS');
        ($this->output)('TEMP_DATABASES_DROPPED='.count($dropped));
        ($this->output)('TEMP_CREDENTIAL_FILES_REMOVED='.$credentialsRemoved);
        ($this->output)('STALE_RAW_SQL_TEMP_FILES_REMOVED='.$rawSqlRemoved);
        ($this->output)('STALE_LOCK_REMOVED='.($staleLock ? 'yes' : 'no'));

        return ExitCode::SUCCESS;
    }

    private function assertGitGate(string $expectedSha): array
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $expectedSha)) {
            throw new ReleaseFailure('EXPECTED_SHA_INVALID', ExitCode::INVALID_ARGUMENTS);
        }
        $state = $this->platform->gitState($this->manifest);
        if ($state['branch'] !== $this->manifest['allowed_branch']) {
            throw new ReleaseFailure('WRONG_BRANCH', ExitCode::GIT_BLOCKER);
        }
        if ($state['head'] !== $expectedSha) {
            throw new ReleaseFailure('WRONG_EXPECTED_SHA', ExitCode::GIT_BLOCKER);
        }
        if (! $state['clean']) {
            throw new ReleaseFailure('DIRTY_WORKTREE', ExitCode::GIT_BLOCKER);
        }
        if (! $state['previous_sha_is_ancestor']) {
            throw new ReleaseFailure('PREVIOUS_PRODUCTION_SHA_NOT_ANCESTOR', ExitCode::GIT_BLOCKER);
        }

        return $state;
    }

    private function assertDatabaseGate(): array
    {
        $identity = $this->platform->databaseIdentity();
        if (($identity['database_sha256'] ?? '') !== $this->manifest['expected_database_name_sha256']) {
            throw new ReleaseFailure('WRONG_DATABASE_FINGERPRINT', ExitCode::DATABASE_BLOCKER);
        }
        $engine = $this->manifest['database_engine'] ?? ['family' => 'MariaDB', 'version_prefix' => '10.11.'];
        if (($identity['family'] ?? '') !== $engine['family']
            || ! str_starts_with((string) ($identity['version'] ?? ''), (string) $engine['version_prefix'])) {
            throw new ReleaseFailure('UNSUPPORTED_DATABASE_VERSION', ExitCode::DATABASE_BLOCKER);
        }
        if ((int) ($identity['foreign_key_checks'] ?? 0) !== 1 || (int) ($identity['check_constraint_checks'] ?? 0) !== 1) {
            throw new ReleaseFailure('DATABASE_CONSTRAINT_CHECKS_DISABLED', ExitCode::DATABASE_BLOCKER);
        }

        return $identity;
    }

    /** @return array{report_sha256:string,sidecar_path:string,sidecar:array<string,mixed>} */
    private function validatePreflightReport(array $report, string $reportPath, string $expectedSha): array
    {
        if (($report['release_id'] ?? null) !== $this->manifest['release_id'] || ($report['status'] ?? null) !== 'PASS') {
            throw new ReleaseFailure('PREFLIGHT_REPORT_NOT_PASS', ExitCode::INVALID_ARGUMENTS);
        }
        if (($report['expected_sha'] ?? null) !== $expectedSha) {
            throw new ReleaseFailure('PREFLIGHT_SHA_MISMATCH', ExitCode::GIT_BLOCKER);
        }
        $sidecarPath = dirname($reportPath).'/approval-token.sha256';
        $sidecar = ReleaseFiles::readJson($sidecarPath);
        $reportHash = hash_file('sha256', $reportPath);
        if ($reportHash === false || ! hash_equals((string) ($sidecar['report_sha256'] ?? ''), $reportHash)) {
            throw new ReleaseFailure('PREFLIGHT_REPORT_HASH_MISMATCH', ExitCode::INVALID_ARGUMENTS);
        }
        if (! hash_equals((string) ($sidecar['binding_sha256'] ?? ''), hash('sha256', $this->approvalBinding($report, $reportHash)))) {
            throw new ReleaseFailure('APPROVAL_BINDING_INVALID', ExitCode::INVALID_ARGUMENTS);
        }
        $currentIdentity = $this->assertDatabaseGate();
        if (($report['database']['database_sha256'] ?? '') !== $currentIdentity['database_sha256']) {
            throw new ReleaseFailure('PREFLIGHT_DATABASE_MISMATCH', ExitCode::DATABASE_BLOCKER);
        }
        $backup = $report['backup'] ?? [];
        $backupVerification = $this->platform->verifyBackup((string) ($backup['path'] ?? ''), (string) ($backup['sha256'] ?? ''));
        $this->assertPassing($backupVerification, 'BACKUP_REVALIDATION_FAILED', ExitCode::DEPENDENCY_BLOCKER);

        return ['report_sha256' => $reportHash, 'sidecar_path' => $sidecarPath, 'sidecar' => $sidecar];
    }

    private function assertFreshPreflightAuthorization(array $report, array $sidecar): void
    {
        $expiresAt = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) ($report['expires_at'] ?? ''));
        if (! $expiresAt || ($this->clock)() > $expiresAt) {
            throw new ReleaseFailure('PREFLIGHT_EXPIRED', ExitCode::INVALID_ARGUMENTS);
        }
        if (isset($sidecar['consumed_at'])) {
            throw new ReleaseFailure('APPROVAL_TOKEN_ALREADY_CONSUMED', ExitCode::INVALID_ARGUMENTS);
        }
    }

    private function consumeApprovalToken(string $sidecarPath, array $sidecar, string $checkpointPath, array &$checkpoint): void
    {
        if (isset($sidecar['consumed_at'])) {
            throw new ReleaseFailure('APPROVAL_TOKEN_ALREADY_CONSUMED', ExitCode::INVALID_ARGUMENTS);
        }
        $consumedAt = ($this->clock)()->format(DATE_ATOM);
        $sidecar['consumed_at'] = $consumedAt;
        $sidecar['consumption_stage'] = 'ddl_boundary_before_migration_1';
        ReleaseFiles::atomicJson($sidecarPath, $sidecar, 0600);
        $checkpoint['approval_consumed_at'] = $consumedAt;
        $checkpoint['approval_consumption_stage'] = 'ddl_boundary_before_migration_1';
        ReleaseFiles::atomicJson($checkpointPath, Redactor::redact($checkpoint));
    }

    private function approvalBinding(array $report, string $reportHash): string
    {
        return implode('|', [
            (string) ($report['release_id'] ?? ''),
            (string) ($report['expected_sha'] ?? ''),
            (string) ($report['database']['database_sha256'] ?? ''),
            $reportHash,
            (string) ($report['created_at'] ?? ''),
            (string) ($report['expires_at'] ?? ''),
        ]);
    }

    private function newCheckpoint(string $reportPath, array $report, string $expectedSha, array $identity): array
    {
        return [
            'release_id' => $this->manifest['release_id'],
            'expected_sha' => $expectedSha,
            'database_sha256' => $identity['database_sha256'],
            'preflight_report_path' => $reportPath,
            'preflight_report_sha256' => hash_file('sha256', $reportPath),
            'deployment_directory' => $report['deployment_directory'] ?? null,
            'stage' => 'initialized',
            'completed_stages' => [],
            'timestamps' => [],
        ];
    }

    /** @return array{string,string,string,string} */
    private function allocatePreflightArtifactPaths(DateTimeImmutable $now): array
    {
        for ($offset = 0; $offset < 60; $offset++) {
            $stamp = $now->modify('+'.$offset.' seconds')->format('Ymd-His');
            $directory = ReleaseFiles::assertWithin($this->auditRoot.'/debt-pr-d-preflight-'.$stamp, $this->auditRoot);
            $backupPath = ReleaseFiles::assertWithin($this->backupRoot.'/kiot-pr-d-db-backup-'.$stamp.'.sql.gz', $this->backupRoot);
            $deploymentDirectory = ReleaseFiles::assertWithin($this->auditRoot.'/debt-pr-d-production-deploy-'.$stamp, $this->auditRoot);
            if (! file_exists($directory) && ! file_exists($backupPath) && ! file_exists($deploymentDirectory)) {
                return [$stamp, $directory, $backupPath, $deploymentDirectory];
            }
        }

        throw new ReleaseFailure('PREFLIGHT_ARTIFACT_COLLISION', ExitCode::DEPENDENCY_BLOCKER);
    }

    private function writeCheckpoint(string $path, array &$checkpoint, string $stage): void
    {
        $checkpoint['stage'] = $stage;
        if (! in_array($stage, $checkpoint['completed_stages'], true)) {
            $checkpoint['completed_stages'][] = $stage;
        }
        $checkpoint['timestamps'][$stage] = ($this->clock)()->format(DATE_ATOM);
        ReleaseFiles::atomicJson($path, Redactor::redact($checkpoint));
    }

    private function assertCheckpointIdentity(array $checkpoint, string $expectedSha, array $identity): void
    {
        if (($checkpoint['release_id'] ?? '') !== $this->manifest['release_id']
            || ($checkpoint['expected_sha'] ?? '') !== $expectedSha
            || ($checkpoint['database_sha256'] ?? '') !== $identity['database_sha256']) {
            throw new ReleaseFailure('CHECKPOINT_DATABASE_STATE_MISMATCH', ExitCode::PARTIAL_RELEASE);
        }
    }

    private function deploymentSummary(string $sha, string $reportPath, string $reportHash): string
    {
        return implode(PHP_EOL, [
            'RELEASE='.$this->manifest['release_id'],
            'DEPLOYMENT_STATUS=SUCCESS',
            'EXPECTED_SHA='.$sha,
            'DEPLOYED_SHA='.$sha,
            'BACKUP=PASS',
            'RESTORE_TEST=PASS',
            'MIGRATION_1=PASS',
            'MIGRATION_2=PASS',
            'MIGRATION_3=PASS',
            'SCHEMA_POSTFLIGHT=PASS',
            'LEGACY_ROW_COUNT=UNCHANGED',
            'LEGACY_ROW_HASH=UNCHANGED',
            'NON_TARGET_DATA=UNCHANGED',
            'FINANCIAL_AGGREGATES=UNCHANGED',
            'HTTP_SMOKE=PASS',
            'NEW_APPLICATION_ERRORS=NONE',
            'BACKFILL=no',
            'WORKFLOW_STATUS_BACKFILLED=no',
            'WORKFLOW_ENABLED=no',
            'OPERATIONS_CREATED=no',
            'OUTBOX_CREATED=no',
            'CURRENT_DEBT_DATA_CHANGED=no',
            'REPORT_PATH='.$reportPath,
            'REPORT_SHA256='.$reportHash,
            'READY_FOR_PR_D_PRODUCTION_CLOSEOUT=yes',
        ]).PHP_EOL;
    }

    private function safeRerunCommand(string $reportPath, string $expectedSha, bool $resume = false): string
    {
        return sprintf(
            'bash scripts/debt-release/pr-d-release.sh deploy --preflight-report %s --expected-sha %s --maintenance-window-ack%s',
            escapeshellarg($reportPath),
            escapeshellarg($expectedSha),
            $resume ? ' --resume-partial-ack' : '',
        );
    }

    private function cleanupTemporaryCredentials(): int
    {
        $removed = 0;
        $pattern = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'debt-release-client-*.cnf';
        foreach (glob($pattern) ?: [] as $path) {
            if (! preg_match('/^debt-release-client-[a-f0-9]{16}\.cnf$/', basename($path)) || ! is_file($path)) {
                continue;
            }
            if (! unlink($path)) {
                throw new ReleaseFailure('TEMP_CREDENTIAL_CLEANUP_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $removed++;
        }

        return $removed;
    }

    private function cleanupRawSqlTemps(): int
    {
        $removed = 0;
        $pattern = rtrim($this->backupRoot, '/\\').DIRECTORY_SEPARATOR.'kiot-pr-d-raw-*.sql.tmp';
        foreach (glob($pattern) ?: [] as $path) {
            if (! preg_match((string) $this->manifest['raw_sql_temp_pattern'], basename($path)) || ! is_file($path)) {
                continue;
            }
            if (! unlink($path)) {
                throw new ReleaseFailure('RAW_SQL_TEMP_CLEANUP_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            $removed++;
        }

        return $removed;
    }

    private function assertPassing(array $result, string $blocker, int $exitCode): void
    {
        if (($result['pass'] ?? false) !== true) {
            throw new ReleaseFailure((string) ($result['blocker'] ?? $blocker), $exitCode);
        }
    }

    private function requiredString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ReleaseFailure('MISSING_REQUIRED_OPTION_'.strtoupper(str_replace('-', '_', $key)), ExitCode::INVALID_ARGUMENTS);
        }

        return trim($value);
    }

    private function assertAllowedOptions(array $options, array $allowed): void
    {
        foreach (array_keys($options) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new ReleaseFailure('OPTION_NOT_ALLOWED_FOR_SUBCOMMAND', ExitCode::INVALID_ARGUMENTS, (string) $name);
            }
        }
    }
}

class NativeReleasePlatform implements ReleasePlatform
{
    private const TIMEOUT_GIT_PHP = 30;

    private const TIMEOUT_CURL = 30;

    private const TIMEOUT_GZIP = 60;

    private const TIMEOUT_BACKUP = 1800;

    private const TIMEOUT_RESTORE = 1800;

    private const TIMEOUT_MIGRATION = 180;

    private const TIMEOUT_OPTIMIZE = 120;

    private const TIMEOUT_MAINTENANCE = 60;

    private readonly ConnectionInterface $connection;

    private readonly PDO $pdo;

    private bool $databaseLockHeld = false;

    /** @var null|callable(string):void */
    private $credentialFailureInjector;

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $backupRoot,
        private readonly ProcessExecutor $process,
        ?callable $credentialFailureInjector = null,
    ) {
        $this->connection = DB::connection();
        $this->pdo = $this->connection->getPdo();
        $this->credentialFailureInjector = $credentialFailureInjector;
    }

    public function doctor(array $manifest): array
    {
        $missing = [];
        foreach (['git', 'php', 'mysqldump', 'mysql', 'gzip', 'curl', 'flock'] as $binary) {
            if (self::findExecutable($binary) === null) {
                $missing[] = $binary;
            }
        }
        $databaseSize = (int) $this->scalar(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $minimumBytes = max(2 * 1024 * 1024 * 1024, (int) ceil($databaseSize * 2.5));
        $freeBytes = @disk_free_space($this->backupRoot);
        $diskPass = $freeBytes !== false && $freeBytes >= $minimumBytes;
        $signalRecoveryAvailable = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
        $signalPass = ! ($manifest['signal_recovery_required'] ?? false) || $signalRecoveryAvailable;

        return [
            'pass' => $missing === [] && $diskPass && $signalPass,
            'missing_dependencies' => $missing,
            'sha256_provider' => self::findExecutable('sha256sum') !== null ? 'sha256sum' : 'php',
            'database_connectivity' => true,
            'database_size_bytes' => $databaseSize,
            'backup_free_bytes' => $freeBytes,
            'minimum_free_bytes' => $minimumBytes,
            'migration_files_present' => count($manifest['migrations']) === 3,
            'pcntl_available' => $signalRecoveryAvailable,
            'signal_recovery_available' => $signalRecoveryAvailable,
            'blocker' => $missing !== []
                ? 'MISSING_DEPENDENCY'
                : (! $diskPass ? 'INSUFFICIENT_BACKUP_DISK' : ($signalPass ? null : 'SIGNAL_RECOVERY_UNAVAILABLE')),
        ];
    }

    public function gitState(array $manifest): array
    {
        $branch = $this->mustProcess(['git', 'branch', '--show-current'])->stdout;
        $head = $this->mustProcess(['git', 'rev-parse', 'HEAD'])->stdout;
        $status = $this->mustProcess(['git', 'status', '--porcelain', '--untracked-files=all'])->stdout;
        $ancestor = $this->process->run(
            ['git', 'merge-base', '--is-ancestor', (string) $manifest['expected_previous_production_sha'], trim($head)],
            $this->repositoryRoot,
            null,
            null,
            self::TIMEOUT_GIT_PHP,
        );

        return [
            'branch' => trim($branch),
            'head' => trim($head),
            'clean' => trim($status) === '',
            'previous_sha_is_ancestor' => $ancestor->exitCode === 0,
        ];
    }

    public function databaseIdentity(): array
    {
        $name = (string) $this->scalar('SELECT DATABASE()');
        $version = (string) $this->scalar('SELECT VERSION()');
        $family = stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        $fk = (int) $this->scalar('SELECT @@SESSION.FOREIGN_KEY_CHECKS');
        $check = $family === 'MariaDB'
            ? (int) $this->scalar('SELECT @@SESSION.CHECK_CONSTRAINT_CHECKS')
            : 1;

        return [
            'database_sha256' => hash('sha256', $name),
            'family' => $family,
            'version' => preg_replace('/-.*/', '', $version),
            'driver' => (string) $this->connection->getDriverName(),
            'foreign_key_checks' => $fk,
            'check_constraint_checks' => $check,
        ];
    }

    public function inspectDdlRisk(array $manifest): array
    {
        $table = $this->row(
            "SELECT ENGINE AS engine, TABLE_COLLATION AS collation, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length
             FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'debt_offsets'"
        );
        $openTableCount = 0;
        $openInUse = 0;
        try {
            foreach ($this->pdo->query('SHOW OPEN TABLES')->fetchAll(PDO::FETCH_ASSOC) as $open) {
                if (($open['Table'] ?? '') === 'debt_offsets') {
                    $openTableCount++;
                    $openInUse += (int) ($open['In_use'] ?? 0);
                }
            }
        } catch (Throwable) {
            $openInUse = 1;
        }
        $hasProcessPrivilege = $this->hasProcessPrivilege();
        $innodbTrxVisible = false;
        $activeTransactionCount = 0;
        $maxTransactionAge = 0;
        try {
            $transactionState = $this->row(
                'SELECT COUNT(*) AS active_count, COALESCE(MAX(TIMESTAMPDIFF(SECOND, trx_started, NOW())), 0) AS max_age FROM information_schema.innodb_trx'
            );
            $innodbTrxVisible = $hasProcessPrivilege;
            $activeTransactionCount = (int) ($transactionState['active_count'] ?? 0);
            $maxTransactionAge = (int) ($transactionState['max_age'] ?? 0);
        } catch (Throwable) {
            $innodbTrxVisible = false;
        }
        $processlistVisible = false;
        $targetQueryCount = 0;
        $maxTargetQueryAge = 0;
        try {
            $queryState = $this->row(
                "SELECT COUNT(*) AS target_count, COALESCE(MAX(TIME), 0) AS max_age
                 FROM information_schema.processlist WHERE ID <> CONNECTION_ID() AND INFO LIKE '%debt_offsets%'"
            );
            $processlistVisible = $hasProcessPrivilege;
            $targetQueryCount = (int) ($queryState['target_count'] ?? 0);
            $maxTargetQueryAge = (int) ($queryState['max_age'] ?? 0);
        } catch (Throwable) {
            $processlistVisible = false;
        }
        $metadataLocksVisible = false;
        $metadataLocksTargetCount = 0;
        $metadataLockWaiters = 0;
        $metadataLockBlockers = 0;
        try {
            $performanceSchemaEnabled = (int) $this->scalar('SELECT @@performance_schema') === 1;
            $metadataInstrumentEnabled = (int) $this->scalar(
                "SELECT COUNT(*) FROM performance_schema.setup_instruments
                 WHERE NAME = 'wait/lock/metadata/sql/mdl' AND ENABLED = 'YES'"
            ) === 1;
            $currentThread = $this->scalar(
                'SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()'
            );
            if (! $performanceSchemaEnabled || ! $metadataInstrumentEnabled || $currentThread === false) {
                throw new RuntimeException('Performance Schema metadata lock visibility is unavailable.');
            }
            $lockState = $this->row(
                "SELECT COUNT(*) AS target_count,
                        COALESCE(SUM(CASE WHEN LOCK_STATUS = 'PENDING' THEN 1 ELSE 0 END), 0) AS waiter_count,
                        COALESCE(SUM(CASE WHEN LOCK_STATUS = 'GRANTED' THEN 1 ELSE 0 END), 0) AS blocker_count
                 FROM performance_schema.metadata_locks
                 WHERE OBJECT_SCHEMA = DATABASE()
                   AND OBJECT_NAME = 'debt_offsets'
                   AND (OWNER_THREAD_ID IS NULL OR OWNER_THREAD_ID <> ".(int) $currentThread.')'
            );
            $metadataLocksTargetCount = (int) ($lockState['target_count'] ?? 0);
            $metadataLockWaiters = (int) ($lockState['waiter_count'] ?? 0);
            $metadataLockBlockers = (int) ($lockState['blocker_count'] ?? 0);
            $metadataLocksVisible = $hasProcessPrivilege;
        } catch (Throwable) {
            $metadataLocksVisible = false;
        }
        $visibilityComplete = $innodbTrxVisible && $processlistVisible && $metadataLocksVisible;
        $blocked = ! $visibilityComplete
            || $metadataLockWaiters > 0
            || $metadataLockBlockers > 0
            || $openInUse > 0
            || $maxTransactionAge > (int) $manifest['ddl_thresholds']['active_transaction_seconds']
            || $maxTargetQueryAge > (int) $manifest['ddl_thresholds']['target_query_seconds'];
        $blocker = ! $visibilityComplete
            ? 'DDL_RISK_VISIBILITY_INSUFFICIENT'
            : ($blocked ? 'DDL_ACTIVITY_DETECTED' : null);

        return [
            'pass' => ! $blocked,
            'maintenance_required' => true,
            'estimated_row_count' => (int) ($table['estimated_rows'] ?? 0),
            'engine' => $table['engine'] ?? null,
            'collation' => $table['collation'] ?? null,
            'data_length' => (int) ($table['data_length'] ?? 0),
            'index_length' => (int) ($table['index_length'] ?? 0),
            'open_table_count' => $openTableCount,
            'open_tables_in_use' => $openInUse,
            'active_transaction_count' => $activeTransactionCount,
            'max_active_transaction_seconds' => $maxTransactionAge,
            'target_query_count' => $targetQueryCount,
            'max_target_query_seconds' => $maxTargetQueryAge,
            'innodb_trx_visible' => $innodbTrxVisible,
            'processlist_visible' => $processlistVisible,
            'metadata_locks_visible' => $metadataLocksVisible,
            'metadata_locks_target_count' => $metadataLocksTargetCount,
            'metadata_lock_waiters' => $metadataLockWaiters,
            'metadata_lock_blockers' => $metadataLockBlockers,
            'DDL_VISIBILITY_COMPLETE' => $visibilityComplete,
            'blocker' => $blocker,
        ];
    }

    public function createBackup(string $path): array
    {
        ReleaseFiles::ensureDirectory(dirname($path), 0700);
        $engines = $this->pdo->query(
            'SELECT DISTINCT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_TYPE = \'BASE TABLE\''
        )->fetchAll(PDO::FETCH_COLUMN);
        $allTransactional = count(array_diff(array_map('strtoupper', $engines), ['INNODB'])) === 0;
        $credentials = $this->writeClientDefaultsFile();
        $sqlPath = $this->createSecureRawSqlTemp();
        $gzipPath = $path.'.tmp';
        try {
            $database = (string) $this->scalar('SELECT DATABASE()');
            $command = [
                'mysqldump',
                '--defaults-extra-file='.$credentials,
                '--routines', '--events', '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
                ...$this->localDatabaseClientArguments(),
                '--skip-lock-tables',
            ];
            if (($this->databaseIdentity()['family'] ?? '') === 'MySQL' && $this->processSupports('mysqldump', 'set-gtid-purged')) {
                $command[] = '--set-gtid-purged=OFF';
            }
            if ($allTransactional) {
                $command[] = '--single-transaction';
            } else {
                $command = array_values(array_filter($command, static fn (string $arg) => $arg !== '--skip-lock-tables'));
                $command[] = '--lock-all-tables';
            }
            $command[] = $database;
            $dump = $this->process->run($command, $this->repositoryRoot, null, $sqlPath, self::TIMEOUT_BACKUP);
            clearstatcache(true, $sqlPath);
            if (! $dump->successful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
                throw new ReleaseFailure(
                    'MYSQLDUMP_FAILED',
                    ExitCode::DEPENDENCY_BLOCKER,
                    'MYSQLDUMP_FAILED exit='.$dump->exitCode
                        .' timed_out='.($dump->timedOut ? 'yes' : 'no')
                        .' size='.(is_file($sqlPath) ? (string) filesize($sqlPath) : 'missing')
                        .': '.substr(trim($dump->stderr), 0, 500),
                );
            }
            $gzip = $this->process->run(['gzip', '-c', $sqlPath], $this->repositoryRoot, null, $gzipPath, self::TIMEOUT_GZIP);
            if (! $gzip->successful() || ! rename($gzipPath, $path)) {
                throw new ReleaseFailure('BACKUP_GZIP_FAILED', ExitCode::DEPENDENCY_BLOCKER);
            }
            @chmod($path, 0600);
            $verified = $this->verifyBackup($path, (string) hash_file('sha256', $path));

            return $verified + [
                'path' => $path,
                'consistency_mode' => $allTransactional ? 'single_transaction' : 'lock_all_tables',
                'engines' => array_values($engines),
            ];
        } finally {
            @unlink($credentials);
            @unlink($sqlPath);
            @unlink($gzipPath);
        }
    }

    public function verifyBackup(string $path, string $expectedSha256): array
    {
        $allowed = ReleaseFiles::assertWithin($path, $this->backupRoot);
        $sha = is_file($allowed) ? hash_file('sha256', $allowed) : false;
        $gzip = is_file($allowed) ? $this->process->run(['gzip', '-t', $allowed], $this->repositoryRoot, null, null, self::TIMEOUT_GZIP) : new ProcessResult(1);
        $permissions = fileperms($allowed);
        $mode = $permissions === false ? null : $permissions & 0777;
        $modePass = DIRECTORY_SEPARATOR === '\\' || $mode === 0600;
        $pass = $sha !== false && filesize($allowed) > 0 && hash_equals($expectedSha256, $sha) && $gzip->successful() && $modePass;

        return [
            'pass' => $pass,
            'path' => $allowed,
            'sha256' => $sha,
            'size_bytes' => is_file($allowed) ? filesize($allowed) : 0,
            'gzip_test' => $gzip->successful(),
            'file_mode' => $mode === null ? null : sprintf('%04o', $mode),
            'file_mode_0600' => $modePass,
            'blocker' => $pass ? null : 'BACKUP_VALIDATION_FAILED',
        ];
    }

    public function restoreTest(string $backupPath, array $manifest): array
    {
        $database = 'test_kiot_pr_d_restore_'.date('Ymd_His').'_'.substr(bin2hex(random_bytes(5)), 0, 10);
        self::assertTemporaryDatabaseName($database, $manifest);
        $credentials = $this->writeClientDefaultsFile();
        $sqlPath = $this->createSecureRawSqlTemp();
        $created = false;
        try {
            $this->pdo->exec('CREATE DATABASE '.$this->quoteIdentifier($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            $gunzip = $this->process->run(['gzip', '-dc', $backupPath], $this->repositoryRoot, null, $sqlPath, self::TIMEOUT_GZIP);
            if (! $gunzip->successful()) {
                throw new ReleaseFailure('RESTORE_DECOMPRESSION_FAILED', ExitCode::DATABASE_BLOCKER);
            }
            $restore = $this->process->run(
                ['mysql', '--defaults-extra-file='.$credentials, ...$this->localDatabaseClientArguments(), '--default-character-set=utf8mb4', $database],
                $this->repositoryRoot,
                $sqlPath,
                null,
                self::TIMEOUT_RESTORE,
            );
            if (! $restore->successful()) {
                throw new ReleaseFailure('RESTORE_IMPORT_FAILED', ExitCode::DATABASE_BLOCKER);
            }
            $tableCount = (int) $this->scalarForDatabase($database, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?', [$database]);
            $migrationCount = (int) $this->scalarForDatabase($database, 'SELECT COUNT(*) FROM migrations');
            $customerCount = (int) $this->scalarForDatabase($database, 'SELECT COUNT(*) FROM customers');
            $offsetCount = (int) $this->scalarForDatabase($database, 'SELECT COUNT(*) FROM debt_offsets');
            $required = array_merge($manifest['invariant_table_groups']['pr_a'], $manifest['invariant_table_groups']['pr_b'], $manifest['invariant_table_groups']['pr_c']);
            $requiredCount = (int) $this->scalarForDatabase(
                $database,
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name IN ('.implode(',', array_fill(0, count($required), '?')).')',
                array_merge([$database], $required),
            );
            $migrationNames = array_column($manifest['migrations'], 'name');
            $prDRows = (int) $this->scalarForDatabase(
                $database,
                'SELECT COUNT(*) FROM migrations WHERE migration IN ('.implode(',', array_fill(0, count($migrationNames), '?')).')',
                $migrationNames,
            );
            if ($tableCount === 0 || $migrationCount === 0 || $requiredCount !== count($required) || $prDRows !== 0) {
                throw new ReleaseFailure('RESTORE_CONTENT_VERIFICATION_FAILED', ExitCode::DATABASE_BLOCKER);
            }

            $result = [
                'pass' => true,
                'table_count' => $tableCount,
                'migration_row_count' => $migrationCount,
                'customers_row_count' => $customerCount,
                'debt_offsets_row_count' => $offsetCount,
                'pr_a_b_c_tables_present' => true,
                'pr_d_migrations_absent' => true,
                'temporary_database_sha256' => hash('sha256', $database),
                'temporary_database_dropped' => true,
            ];
        } finally {
            if ($created) {
                $this->pdo->exec('DROP DATABASE '.$this->quoteIdentifier($database));
                if ((int) $this->preparedScalar(
                    'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
                    [$database],
                ) !== 0) {
                    throw new ReleaseFailure('TEMP_DATABASE_DROP_VERIFICATION_FAILED', ExitCode::DATABASE_BLOCKER);
                }
            }
            @unlink($credentials);
            @unlink($sqlPath);
        }

        return $result;
    }

    public function captureBaseline(array $manifest): array
    {
        $this->assertInvariantTablesTransactional($manifest);

        return $this->withConsistentReadOnlySnapshot(fn (): array => $this->captureBaselineState($manifest));
    }

    private function captureBaselineState(array $manifest): array
    {
        $tables = array_values(array_unique(array_merge(...array_values($manifest['invariant_table_groups']))));
        $tableState = [];
        foreach ($tables as $table) {
            $tableState[$table] = [
                'row_count' => (int) $this->scalar('SELECT COUNT(*) FROM '.$this->quoteIdentifier($table)),
                'schema_sha256' => $this->schemaHash($table),
                'data_sha256' => $table === $manifest['target_table'] ? null : $this->tableHash($table),
            ];
        }

        return [
            'pass' => true,
            'tables' => $tableState,
            'debt_offsets_legacy_sha256' => $this->tableHash((string) $manifest['target_table'], $manifest['legacy_offset_columns']),
            'financial_aggregates' => $this->financialAggregates($manifest),
            'migrations' => $this->migrationStatus($manifest),
            'migration_table' => [
                'row_count' => (int) $this->scalar('SELECT COUNT(*) FROM migrations'),
                'data_sha256' => $this->tableHash('migrations'),
            ],
            'database_settings' => $this->databaseIdentity(),
        ];
    }

    public function acquireDatabaseLock(string $name): bool
    {
        $this->databaseLockHeld = (int) $this->preparedScalar('SELECT GET_LOCK(?, 0)', [$name]) === 1;

        return $this->databaseLockHeld;
    }

    public function releaseDatabaseLock(string $name): void
    {
        if ($this->databaseLockHeld) {
            $released = (int) $this->preparedScalar('SELECT RELEASE_LOCK(?)', [$name]) === 1;
            $this->databaseLockHeld = false;
            if (! $released) {
                throw new ReleaseFailure('DATABASE_RELEASE_LOCK_RELEASE_FAILED', ExitCode::PARTIAL_RELEASE);
            }
        }
    }

    public function maintenanceDown(): void
    {
        $result = $this->process->run([PHP_BINARY, 'artisan', 'down', '--retry=60'], $this->repositoryRoot, null, null, self::TIMEOUT_MAINTENANCE);
        if (! $result->successful()) {
            throw new ReleaseFailure('MAINTENANCE_DOWN_FAILED', ExitCode::PARTIAL_RELEASE);
        }
    }

    public function maintenanceUp(): void
    {
        $result = $this->process->run([PHP_BINARY, 'artisan', 'up'], $this->repositoryRoot, null, null, self::TIMEOUT_MAINTENANCE);
        if (! $result->successful()) {
            throw new ReleaseFailure('MAINTENANCE_UP_FAILED', ExitCode::PARTIAL_RELEASE);
        }
    }

    public function optimizeClear(): void
    {
        $result = $this->process->run([PHP_BINARY, 'artisan', 'optimize:clear'], $this->repositoryRoot, null, null, self::TIMEOUT_OPTIMIZE);
        if (! $result->successful()) {
            throw new ReleaseFailure('OPTIMIZE_CLEAR_FAILED', ExitCode::PARTIAL_RELEASE);
        }
    }

    public function migrationRan(string $name): bool
    {
        return (int) $this->preparedScalar('SELECT COUNT(*) FROM migrations WHERE migration = ?', [$name]) === 1;
    }

    public function runMigration(string $path): ProcessResult
    {
        return $this->process->run([PHP_BINARY, 'artisan', 'migrate', '--path='.$path, '--force'], $this->repositoryRoot, null, null, self::TIMEOUT_MIGRATION);
    }

    public function verifyMigrationStage(int $stage, array $manifest, array $baseline): array
    {
        if (! $this->migrationRan((string) $manifest['migrations'][$stage - 1]['name'])) {
            return ['pass' => false, 'blocker' => 'MIGRATION_ROW_MISSING'];
        }
        $columns = $this->columnMetadata((string) $manifest['target_table']);
        if ($stage >= 1) {
            foreach ($manifest['new_columns'] as $column) {
                $default = $columns[$column]['column_default'] ?? null;
                $defaultIsNull = $default === null || strtoupper(trim((string) $default, "'")) === 'NULL';
                if (! isset($columns[$column]) || $columns[$column]['is_nullable'] !== 'YES' || ! $defaultIsNull) {
                    return ['pass' => false, 'blocker' => 'STAGE_1_COLUMN_CONTRACT_MISMATCH'];
                }
            }
            $nullPredicate = implode(' OR ', array_map(fn (string $column) => $this->quoteIdentifier($column).' IS NOT NULL', $manifest['new_columns']));
            if ((int) $this->scalar('SELECT COUNT(*) FROM debt_offsets WHERE '.$nullPredicate) !== 0) {
                return ['pass' => false, 'blocker' => 'PR_D_COLUMNS_WERE_BACKFILLED'];
            }
        }
        if ($stage >= 2) {
            $indexes = $this->indexMetadata((string) $manifest['target_table']);
            foreach ($manifest['unique_indexes'] as $name => $expectedColumns) {
                if (($indexes[$name]['unique'] ?? false) !== true || ($indexes[$name]['columns'] ?? []) !== $expectedColumns) {
                    return ['pass' => false, 'blocker' => 'STAGE_2_INDEX_CONTRACT_MISMATCH'];
                }
            }
            $foreignKeys = $this->foreignKeyMetadata((string) $manifest['target_table']);
            foreach ($manifest['foreign_keys'] as $name => $expected) {
                if (($foreignKeys[$name] ?? null) !== $expected) {
                    return ['pass' => false, 'blocker' => 'STAGE_2_FOREIGN_KEY_CONTRACT_MISMATCH'];
                }
            }
        }
        if ($stage >= 3) {
            $checks = $this->checkMetadata((string) $manifest['target_table']);
            foreach ($manifest['checks'] as $name => $tokens) {
                $clause = strtolower((string) ($checks[$name] ?? ''));
                foreach ($tokens as $token) {
                    if (! str_contains($clause, strtolower((string) $token))) {
                        return ['pass' => false, 'blocker' => 'STAGE_3_CHECK_CONTRACT_MISMATCH'];
                    }
                }
            }
        }

        $verification = $this->verifyBusinessInvariance($manifest, $baseline);
        $verification['migration_batch'] = (int) $this->preparedScalar(
            'SELECT batch FROM migrations WHERE migration = ?',
            [$manifest['migrations'][$stage - 1]['name']],
        );

        return $verification;
    }

    public function comparePostflight(array $manifest, array $baseline): array
    {
        $this->assertInvariantTablesTransactional($manifest);

        return $this->withConsistentReadOnlySnapshot(function () use ($manifest, $baseline): array {
            $current = $this->captureBaselineState($manifest);
            foreach ($baseline['tables'] as $table => $before) {
                $after = $current['tables'][$table] ?? null;
                if ($after === null || $before['row_count'] !== $after['row_count']) {
                    return ['pass' => false, 'blocker' => 'TABLE_ROW_COUNT_CHANGED', 'table' => $table];
                }
                if ($table !== $manifest['target_table']
                    && ($before['data_sha256'] !== $after['data_sha256'] || $before['schema_sha256'] !== $after['schema_sha256'])) {
                    return ['pass' => false, 'blocker' => 'NON_TARGET_TABLE_CHANGED', 'table' => $table];
                }
            }
            $invariance = $this->verifyBusinessInvarianceState($manifest, $baseline);
            if (! $invariance['pass']) {
                return $invariance;
            }
            if (($current['migrations']['ran_count'] ?? 0) !== 3) {
                return ['pass' => false, 'blocker' => 'PR_D_MIGRATION_SET_INCOMPLETE'];
            }
            if (($current['migration_table']['row_count'] ?? 0) !== ($baseline['migration_table']['row_count'] ?? -3) + 3) {
                return ['pass' => false, 'blocker' => 'UNEXPECTED_MIGRATION_ROWS_ADDED'];
            }

            return [
                'pass' => true,
                'non_target_data_unchanged' => true,
                'non_target_schema_unchanged' => true,
                'legacy_row_count_unchanged' => true,
                'legacy_offset_hash_unchanged' => true,
                'financial_aggregates_unchanged' => true,
                'migration_rows_exact' => true,
            ];
        });
    }

    public function smoke(array $manifest, int $logOffset): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $url = parse_url($baseUrl);
        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            return ['pass' => false, 'blocker' => 'APP_URL_INVALID'];
        }
        $port = (int) ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80));
        $resolve = $url['host'].':'.$port.':127.0.0.1';
        $results = [];
        foreach ($manifest['smoke_paths'] as $smoke) {
            $result = $this->process->run([
                'curl', '--silent', '--show-error', '--output', self::nullDevice(), '--write-out', '%{http_code}',
                '--connect-timeout', '5', '--max-time', '15', '--resolve', $resolve, $baseUrl.$smoke['path'],
            ], $this->repositoryRoot, null, null, self::TIMEOUT_CURL);
            $status = (int) trim($result->stdout);
            $results[] = ['path' => $smoke['path'], 'status' => $status];
            if (! $result->successful() || ! in_array($status, $smoke['statuses'], true)) {
                return ['pass' => false, 'blocker' => 'HTTP_SMOKE_FAILED', 'requests' => $results];
            }
        }
        $log = $this->laravelLogPath();
        $delta = '';
        if (is_file($log)) {
            $handle = fopen($log, 'rb');
            if ($handle !== false) {
                fseek($handle, $logOffset);
                $delta = (string) stream_get_contents($handle);
                fclose($handle);
            }
        }
        $newErrors = preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/i', $delta) === 1;

        return [
            'pass' => ! $newErrors,
            'requests' => $results,
            'new_application_errors' => $newErrors,
            'log_delta_bytes' => strlen($delta),
            'blocker' => $newErrors ? 'NEW_APPLICATION_ERRORS' : null,
        ];
    }

    public function logSize(): int
    {
        $path = $this->laravelLogPath();

        return is_file($path) ? (int) filesize($path) : 0;
    }

    public function migrationStatus(array $manifest): array
    {
        $states = [];
        foreach ($manifest['migrations'] as $migration) {
            $states[$migration['name']] = $this->migrationRan((string) $migration['name']);
        }

        return ['pass' => true, 'ran_count' => count(array_filter($states)), 'migrations' => $states];
    }

    public function temporaryDatabases(array $manifest): array
    {
        $statement = $this->pdo->query("SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME LIKE 'test\\_kiot\\_pr\\_d\\_restore\\_%'");

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function dropTemporaryDatabase(string $name, array $manifest): void
    {
        self::assertTemporaryDatabaseName($name, $manifest);
        $this->pdo->exec('DROP DATABASE '.$this->quoteIdentifier($name));
    }

    public static function assertTemporaryDatabaseName(string $name, array $manifest): void
    {
        if (! preg_match((string) $manifest['temp_database_pattern'], $name)) {
            throw new ReleaseFailure('UNSAFE_TEMP_DATABASE_NAME', ExitCode::DATABASE_BLOCKER);
        }
    }

    public static function canonicalJson(array $row): string
    {
        ksort($row, SORT_STRING);

        return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function normalizeCreateTable(string $ddl): string
    {
        $ddl = preg_replace('/\sAUTO_INCREMENT=\d+\b/i', '', $ddl) ?? $ddl;

        return trim(preg_replace('/\s+/', ' ', $ddl) ?? $ddl);
    }

    public static function findExecutable(string $binary): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $extensions = DIRECTORY_SEPARATOR === '\\' ? ['', '.exe', '.bat', '.cmd'] : [''];
        foreach ($paths as $path) {
            foreach ($extensions as $extension) {
                $candidate = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$binary.$extension;
                if (is_file($candidate) && (DIRECTORY_SEPARATOR === '\\' || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function verifyBusinessInvariance(array $manifest, array $baseline): array
    {
        $this->assertInvariantTablesTransactional($manifest);

        return $this->withConsistentReadOnlySnapshot(fn (): array => $this->verifyBusinessInvarianceState($manifest, $baseline));
    }

    private function verifyBusinessInvarianceState(array $manifest, array $baseline): array
    {
        $rowCount = (int) $this->scalar('SELECT COUNT(*) FROM debt_offsets');
        $legacyHash = $this->tableHash('debt_offsets', $manifest['legacy_offset_columns']);
        $aggregates = $this->financialAggregates($manifest);
        $pass = $rowCount === $baseline['tables']['debt_offsets']['row_count']
            && hash_equals((string) $baseline['debt_offsets_legacy_sha256'], $legacyHash)
            && $baseline['financial_aggregates'] === $aggregates;

        return [
            'pass' => $pass,
            'legacy_row_count_unchanged' => $rowCount === $baseline['tables']['debt_offsets']['row_count'],
            'legacy_offset_hash_unchanged' => hash_equals((string) $baseline['debt_offsets_legacy_sha256'], $legacyHash),
            'financial_aggregates_unchanged' => $baseline['financial_aggregates'] === $aggregates,
            'blocker' => $pass ? null : 'BUSINESS_DATA_INVARIANCE_FAILED',
        ];
    }

    private function withConsistentReadOnlySnapshot(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new ReleaseFailure('SNAPSHOT_TRANSACTION_ALREADY_OPEN', ExitCode::DATA_BLOCKER);
        }
        $started = false;
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $started = true;
            $result = $callback();
        } finally {
            if ($started) {
                $this->pdo->exec('ROLLBACK');
            }
        }
        if (is_array($result)) {
            $result['data_snapshot_consistent'] = true;
            $result['snapshot_read_only'] = true;
            $result['snapshot_rolled_back'] = true;
        }

        return $result;
    }

    private function assertInvariantTablesTransactional(array $manifest): void
    {
        $tables = array_values(array_unique([
            ...array_merge(...array_values($manifest['invariant_table_groups'])),
            (string) $manifest['target_table'],
            'migrations',
        ]));
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('.$placeholders.')'
        );
        $statement->execute($tables);
        $engines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $engines[(string) $row['TABLE_NAME']] = strtoupper((string) $row['ENGINE']);
        }
        foreach ($tables as $table) {
            if (($engines[$table] ?? null) !== 'INNODB') {
                throw new ReleaseFailure('NON_TRANSACTIONAL_INVARIANT_TABLE', ExitCode::DATA_BLOCKER, $table);
            }
        }
    }

    private function financialAggregates(array $manifest): array
    {
        $aggregates = [];
        foreach ($manifest['financial_aggregates'] as $name => $sql) {
            $row = $this->row($sql);
            ksort($row, SORT_STRING);
            $aggregates[$name] = $row;
        }

        return $aggregates;
    }

    private function hasProcessPrivilege(): bool
    {
        try {
            $grants = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($grants as $grant) {
                $normalized = strtoupper((string) $grant);
                if (str_contains($normalized, 'ALL PRIVILEGES') || preg_match('/\bPROCESS\b/', $normalized) === 1) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function tableHash(string $table, ?array $columns = null): string
    {
        $allColumns = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION'
        );
        $allColumns->execute([$table]);
        $available = array_map('strval', $allColumns->fetchAll(PDO::FETCH_COLUMN));
        $columns ??= $available;
        if (array_diff($columns, $available) !== []) {
            throw new ReleaseFailure('HASH_COLUMN_MISSING', ExitCode::SCHEMA_BLOCKER);
        }
        $primary = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ORDINAL_POSITION"
        );
        $primary->execute([$table]);
        $primaryColumns = array_map('strval', $primary->fetchAll(PDO::FETCH_COLUMN));
        if ($primaryColumns === []) {
            throw new ReleaseFailure('TABLE_WITHOUT_PRIMARY_KEY_BLOCKED', ExitCode::DATA_BLOCKER, $table);
        }
        $select = implode(', ', array_map(fn (string $column) => $this->quoteIdentifier($column), $columns));
        $order = implode(', ', array_map(fn (string $column) => $this->quoteIdentifier($column), $primaryColumns));
        $bufferAttribute = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') ? PDO::MYSQL_ATTR_USE_BUFFERED_QUERY : null;
        $previousBuffered = null;
        if ($bufferAttribute !== null) {
            try {
                $previousBuffered = $this->pdo->getAttribute($bufferAttribute);
                $this->pdo->setAttribute($bufferAttribute, false);
            } catch (Throwable) {
                $bufferAttribute = null;
            }
        }
        try {
            $statement = $this->pdo->query('SELECT '.$select.' FROM '.$this->quoteIdentifier($table).' ORDER BY '.$order);
            $hash = hash_init('sha256');
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                hash_update($hash, self::canonicalJson($row)."\n");
            }
            $statement->closeCursor();
        } finally {
            if ($bufferAttribute !== null && $previousBuffered !== null) {
                $this->pdo->setAttribute($bufferAttribute, $previousBuffered);
            }
        }

        return hash_final($hash);
    }

    private function schemaHash(string $table): string
    {
        $statement = $this->pdo->query('SHOW CREATE TABLE '.$this->quoteIdentifier($table));
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (! is_array($row) || ! isset($row[1])) {
            throw new ReleaseFailure('SHOW_CREATE_TABLE_FAILED', ExitCode::SCHEMA_BLOCKER, $table);
        }

        return hash('sha256', self::normalizeCreateTable((string) $row[1]));
    }

    private function columnMetadata(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = ['is_nullable' => $row['IS_NULLABLE'], 'column_default' => $row['COLUMN_DEFAULT']];
        }

        return $columns;
    }

    private function indexMetadata(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['INDEX_NAME'];
            $indexes[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $indexes[$name]['columns'][] = $row['COLUMN_NAME'];
        }

        return $indexes;
    }

    private function foreignKeyMetadata(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $statement->execute([$table]);
        $keys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keys[$row['CONSTRAINT_NAME']] = [
                'column' => $row['COLUMN_NAME'],
                'table' => $row['REFERENCED_TABLE_NAME'],
                'delete' => strtoupper($row['DELETE_RULE']),
            ];
        }

        return $keys;
    }

    private function checkMetadata(string $table): array
    {
        $statement = $this->pdo->prepare(
            "SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM information_schema.table_constraints tc
             JOIN information_schema.check_constraints cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );
        $statement->execute([$table]);
        $checks = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $checks[$row['CONSTRAINT_NAME']] = $row['CHECK_CLAUSE'];
        }

        return $checks;
    }

    private function writeClientDefaultsFile(): string
    {
        $config = $this->connection->getConfig();
        $directory = rtrim(sys_get_temp_dir(), '/\\');
        $filename = 'debt-release-client-'.bin2hex(random_bytes(8)).'.cnf';
        $lines = [
            '[client]',
            'host='.self::optionValue((string) ($config['host'] ?? '127.0.0.1')),
            'port='.(int) ($config['port'] ?? 3306),
            'user='.self::optionValue((string) ($config['username'] ?? '')),
            'password='.self::optionValue((string) ($config['password'] ?? '')),
        ];

        return ReleaseFiles::writeExclusiveClientCredential(
            $directory,
            $filename,
            implode(PHP_EOL, $lines).PHP_EOL,
            $this->credentialFailureInjector,
        );
    }

    private function createSecureRawSqlTemp(): string
    {
        ReleaseFiles::ensureDirectory($this->backupRoot, 0700);
        $path = rtrim($this->backupRoot, '/\\').DIRECTORY_SEPARATOR
            .'kiot-pr-d-raw-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.sql.tmp';
        if (! preg_match('/^kiot-pr-d-raw-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}\.sql\.tmp$/', basename($path))) {
            throw new ReleaseFailure('RAW_SQL_TEMP_NAME_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new ReleaseFailure('RAW_SQL_TEMP_CREATE_FAILED', ExitCode::DEPENDENCY_BLOCKER);
        }
        fclose($handle);
        @chmod($path, 0600);
        $mode = fileperms($path);
        if (DIRECTORY_SEPARATOR !== '\\' && ($mode === false || ($mode & 0777) !== 0600)) {
            @unlink($path);
            throw new ReleaseFailure('RAW_SQL_TEMP_MODE_INVALID', ExitCode::DEPENDENCY_BLOCKER);
        }

        return $path;
    }

    /** @return list<string> */
    private function localDatabaseClientArguments(): array
    {
        $host = strtolower((string) ($this->connection->getConfig()['host'] ?? ''));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'], true)
            ? ['--skip-ssl']
            : [];
    }

    private static function optionValue(string $value): string
    {
        return '"'.addcslashes($value, "\\\"\n\r").'"';
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);

        return $statement->fetchColumn();
    }

    private function preparedScalar(string $sql, array $bindings): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    private function scalarForDatabase(string $database, string $sql, array $bindings = []): mixed
    {
        self::assertTemporaryDatabaseName($database, ['temp_database_pattern' => '/^test_kiot_pr_d_restore_[0-9]{8}_[0-9]{6}_[a-z0-9]{6,16}$/']);
        if (! str_contains(strtolower($sql), 'information_schema')) {
            $sql = preg_replace('/\bFROM\s+([a-z_]+)/i', 'FROM '.$this->quoteIdentifier($database).'.$1', $sql, 1) ?? $sql;
        }

        return $this->preparedScalar($sql, $bindings);
    }

    private function row(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? array_map(static fn ($value) => $value === null ? null : (string) $value, $row) : [];
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new ReleaseFailure('UNSAFE_SQL_IDENTIFIER', ExitCode::DATABASE_BLOCKER);
        }

        return '`'.$identifier.'`';
    }

    private function mustProcess(array $command): ProcessResult
    {
        $result = $this->process->run($command, $this->repositoryRoot, null, null, self::TIMEOUT_GIT_PHP);
        if (! $result->successful()) {
            throw new ReleaseFailure('PROCESS_FAILED', ExitCode::DEPENDENCY_BLOCKER, $command[0]);
        }

        return $result;
    }

    private function processSupports(string $binary, string $option): bool
    {
        $result = $this->process->run([$binary, '--help'], $this->repositoryRoot, null, null, self::TIMEOUT_GIT_PHP);

        return $result->successful() && str_contains(strtolower($result->stdout), strtolower($option));
    }

    private function laravelLogPath(): string
    {
        $configured = (string) config('logging.channels.single.path', $this->repositoryRoot.'/storage/logs/laravel.log');

        return ReleaseFiles::normalizePath($configured);
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}

final class DebtReleaseCli
{
    public static function run(array $argv): int
    {
        try {
            if (DIRECTORY_SEPARATOR !== '\\') {
                umask(0077);
            }
            SignalRecovery::install();
            $parsed = CliArguments::parse($argv);
            if ($parsed['command'] === 'help') {
                self::help();

                return ExitCode::SUCCESS;
            }
            $scriptRoot = __DIR__;
            $repositoryRoot = dirname($scriptRoot, 2);
            $manifest = require $scriptRoot.'/releases/pr-d.php';
            $autoload = $repositoryRoot.'/vendor/autoload.php';
            if (! is_file($autoload)) {
                throw new ReleaseFailure('COMPOSER_AUTOLOAD_MISSING', ExitCode::DEPENDENCY_BLOCKER);
            }
            require_once $autoload;
            $application = require $repositoryRoot.'/bootstrap/app.php';
            $application->make(Kernel::class)->bootstrap();
            $auditRoot = $repositoryRoot.'/storage/app/audits';
            $backupRoot = DIRECTORY_SEPARATOR === '\\' ? $repositoryRoot.'/storage/app/backups' : '/root';
            $platform = new NativeReleasePlatform($repositoryRoot, $backupRoot, new NativeProcessExecutor);
            $runner = new DebtReleaseRunner(
                $manifest,
                $repositoryRoot,
                $platform,
                new FileReleaseLock($repositoryRoot.'/storage/app/audits/debt-release/.runner.lock'),
                $auditRoot,
                $backupRoot,
            );

            return $runner->execute($parsed['command'], $parsed['options']);
        } catch (ReleaseFailure $failure) {
            fwrite(STDERR, 'RELEASE_STATUS=BLOCKED'.PHP_EOL);
            fwrite(STDERR, 'BLOCKER='.$failure->blocker.PHP_EOL);
            fwrite(STDERR, 'EXIT_CODE='.$failure->releaseExitCode.PHP_EOL);

            return $failure->releaseExitCode;
        } catch (Throwable) {
            fwrite(STDERR, 'RELEASE_STATUS=BLOCKED'.PHP_EOL);
            fwrite(STDERR, 'BLOCKER=UNEXPECTED_RUNNER_FAILURE'.PHP_EOL);
            fwrite(STDERR, 'EXIT_CODE='.ExitCode::PARTIAL_RELEASE.PHP_EOL);

            return ExitCode::PARTIAL_RELEASE;
        }
    }

    private static function help(): void
    {
        echo <<<'HELP'
Debt Release Runner (debt-pr-d)

Usage:
  bash scripts/debt-release/pr-d-release.sh doctor
  bash scripts/debt-release/pr-d-release.sh preflight --expected-sha <40-char-sha>
  bash scripts/debt-release/pr-d-release.sh deploy --preflight-report <path> --expected-sha <40-char-sha> --maintenance-window-ack [--resume-partial-ack]
  bash scripts/debt-release/pr-d-release.sh status
  bash scripts/debt-release/pr-d-release.sh cleanup

Exit codes:
  0 success; 10 arguments; 20 git; 30 database; 40 dependency;
  50 lock; 60 schema; 70 data; 80 smoke/log; 90 partial release.
HELP;
        echo PHP_EOL;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(DebtReleaseCli::run($argv));
}
