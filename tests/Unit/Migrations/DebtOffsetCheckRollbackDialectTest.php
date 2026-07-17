<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class DebtOffsetCheckRollbackDialectTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require dirname(__DIR__, 3)
            .'/database/migrations/2026_07_17_000200_add_workflow_checks_to_debt_offsets.php';
    }

    #[DataProvider('supportedServerMetadataProvider')]
    public function test_server_metadata_selects_the_exact_rollback_dialect(
        string $version,
        string $versionComment,
        string $family,
        string $operation,
    ): void {
        $this->assertSame(
            $family,
            $this->invoke('databaseFamilyFromServerMetadata', $version, $versionComment),
        );
        $this->assertSame($operation, $this->invoke('dropOperationForFamily', $family));
    }

    public static function supportedServerMetadataProvider(): array
    {
        return [
            'mysql 8 exact engine' => [
                '8.0.44',
                'MySQL Community Server - GPL',
                'mysql',
                'DROP CHECK',
            ],
            'mysql package version without vendor comment' => [
                '8.0.44-0ubuntu0.22.04.1',
                '',
                'mysql',
                'DROP CHECK',
            ],
            'percona mysql compatible' => [
                '8.0.44-35',
                'Percona Server (GPL), Release 35',
                'mysql',
                'DROP CHECK',
            ],
            'mariadb behind laravel mysql driver' => [
                '10.11.10-MariaDB-ubu2204',
                'mariadb.org binary distribution',
                'mariadb',
                'DROP CONSTRAINT',
            ],
        ];
    }

    #[DataProvider('unsupportedServerMetadataProvider')]
    public function test_unknown_database_family_fails_closed(string $version, string $versionComment): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNSUPPORTED_DATABASE_FAMILY_FOR_CHECK_ROLLBACK');

        $this->invoke('databaseFamilyFromServerMetadata', $version, $versionComment);
    }

    public static function unsupportedServerMetadataProvider(): array
    {
        return [
            'postgresql' => ['PostgreSQL 16.3', 'PostgreSQL'],
            'unknown database' => ['UnknownDB 1.0', 'Unknown vendor'],
            'missing metadata' => ['', ''],
        ];
    }

    public function test_unknown_family_cannot_select_a_drop_operation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UNSUPPORTED_DATABASE_FAMILY_FOR_CHECK_ROLLBACK');

        $this->invoke('dropOperationForFamily', 'postgresql');
    }

    public function test_constraint_names_are_a_closed_allowlist(): void
    {
        $checks = (new ReflectionClass($this->migration))->getConstant('CHECKS');

        $this->assertSame([
            'do_workflow_status_chk',
            'do_amount_pair_chk',
            'do_amount_positive_chk',
            'do_amount_equal_chk',
            'do_rejection_reason_chk',
            'do_idempotency_nonempty_chk',
        ], $checks);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->migration, $method))->invoke($this->migration, ...$arguments);
    }
}
