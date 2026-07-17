<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

final class DebtOffsetRollbackPortabilityIntegrationTest extends TestCase
{
    private const MIGRATIONS = [
        '2026_07_17_000000_add_workflow_evidence_columns_to_debt_offsets',
        '2026_07_17_000100_add_workflow_keys_and_foreign_keys_to_debt_offsets',
        '2026_07_17_000200_add_workflow_checks_to_debt_offsets',
    ];

    private const NEW_COLUMNS = [
        'workflow_status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'applied_at',
        'idempotency_key',
        'approval_operation_id',
        'apply_operation_id',
        'reversal_operation_id',
        'customer_amount',
        'supplier_amount',
        'source_references',
        'reverses_debt_offset_id',
    ];

    private const INDEXES = [
        'do_requested_by_idx',
        'do_approved_by_idx',
        'do_rejected_by_idx',
        'do_approval_operation_idx',
        'do_apply_operation_idx',
        'do_reversal_operation_idx',
        'do_idempotency_uq',
        'do_reverses_uq',
    ];

    private const FOREIGN_KEYS = [
        'do_requested_by_fk',
        'do_approved_by_fk',
        'do_rejected_by_fk',
        'do_approval_operation_fk',
        'do_apply_operation_fk',
        'do_reversal_operation_fk',
        'do_reverses_fk',
    ];

    private const CHECKS = [
        'do_workflow_status_chk',
        'do_amount_pair_chk',
        'do_amount_positive_chk',
        'do_amount_equal_chk',
        'do_rejection_reason_chk',
        'do_idempotency_nonempty_chk',
    ];

    private const LEGACY_COLUMNS = [
        'id',
        'code',
        'customer_id',
        'amount',
        'receivable_before',
        'payable_before',
        'receivable_after',
        'payable_after',
        'is_auto',
        'note',
        'user_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'created_at',
        'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DEBT_ROLLBACK_PORTABILITY_INTEGRATION') !== '1') {
            $this->markTestSkipped('Use a disposable exact-engine database for rollback portability integration.');
        }
    }

    #[Group('debt-offset-rollback-integration')]
    public function test_pr_d_round_trip_restores_exact_baseline_and_preserves_all_data(): void
    {
        $expectedDriver = (string) (getenv('DEBT_ROLLBACK_LARAVEL_DRIVER') ?: 'mysql');
        $this->assertSame($expectedDriver, DB::connection()->getDriverName());
        $serverVersion = (string) (DB::selectOne('SELECT VERSION() AS version')->version ?? '');
        $expectedVersion = (string) getenv('DEBT_ROLLBACK_ENGINE_VERSION_PREFIX');
        $expectedFamily = strtolower((string) getenv('DEBT_ROLLBACK_ENGINE_FAMILY'));
        $this->assertStringStartsWith($expectedVersion, $serverVersion);

        $migration = require database_path('migrations/2026_07_17_000200_add_workflow_checks_to_debt_offsets.php');
        $detectedFamily = (new ReflectionMethod($migration, 'databaseFamily'))->invoke($migration);
        $dropOperation = (new ReflectionMethod($migration, 'dropOperationForFamily'))->invoke(
            $migration,
            $detectedFamily,
        );
        $this->assertSame($expectedFamily, $detectedFamily);
        $this->assertSame($expectedFamily === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK', $dropOperation);

        $this->assertPrDMigrationsPresent();
        $legacyBeforeSetupRollback = $this->legacyDebtOffsetState();
        $abcBeforeSetupRollback = $this->prAbcState();
        $financialBeforeSetupRollback = $this->financialAggregateState();

        try {
            $this->rollbackPrD();
            $this->assertPrDSchemaAbsent();
            $this->assertSame($legacyBeforeSetupRollback, $this->legacyDebtOffsetState());
            $this->assertSame($abcBeforeSetupRollback, $this->prAbcState());
            $this->assertSame($financialBeforeSetupRollback, $this->financialAggregateState());

            $baselineSchema = $this->normalizedCreateTable('debt_offsets');
            $baselineLegacy = $this->legacyDebtOffsetState();
            $baselineAbc = $this->prAbcState();
            $baselineFinancial = $this->financialAggregateState();

            $this->migratePrD();
            $this->assertPrDSchemaPresent();
            $this->assertSame($baselineLegacy, $this->legacyDebtOffsetState());
            $this->assertSame($baselineAbc, $this->prAbcState());
            $this->assertSame($baselineFinancial, $this->financialAggregateState());

            $this->rollbackPrD();
            $this->assertPrDSchemaAbsent();
            $this->assertSame($baselineSchema, $this->normalizedCreateTable('debt_offsets'));
            $this->assertSame($baselineLegacy, $this->legacyDebtOffsetState());
            $this->assertSame($baselineAbc, $this->prAbcState());
            $this->assertSame($baselineFinancial, $this->financialAggregateState());

            $this->migratePrD();
            $this->assertPrDSchemaPresent();
            $this->assertSame($baselineLegacy, $this->legacyDebtOffsetState());
            $this->assertSame($baselineAbc, $this->prAbcState());
            $this->assertSame($baselineFinancial, $this->financialAggregateState());

            $migrationRows = $this->prDMigrationRows();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
            $this->assertSame($migrationRows, $this->prDMigrationRows());
            $this->assertSame($baselineLegacy, $this->legacyDebtOffsetState());
            $this->assertSame($baselineAbc, $this->prAbcState());
            $this->assertSame($baselineFinancial, $this->financialAggregateState());
        } finally {
            if (count($this->prDMigrationRows()) !== count(self::MIGRATIONS)) {
                Artisan::call('migrate', ['--force' => true]);
            }
        }
    }

    private function rollbackPrD(): void
    {
        $latest = DB::table('migrations')
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('migration')
            ->all();
        $this->assertEqualsCanonicalizing(self::MIGRATIONS, $latest);
        $this->assertSame(
            0,
            Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]),
            Artisan::output(),
        );
        $this->assertSame([], $this->prDMigrationRows());
    }

    private function migratePrD(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->assertPrDMigrationsPresent();
    }

    private function assertPrDMigrationsPresent(): void
    {
        $this->assertSame(self::MIGRATIONS, array_column($this->prDMigrationRows(), 'migration'));
    }

    /** @return list<array{migration:string,batch:int}> */
    private function prDMigrationRows(): array
    {
        return DB::table('migrations')
            ->whereIn('migration', self::MIGRATIONS)
            ->orderBy('migration')
            ->get(['migration', 'batch'])
            ->map(static fn (object $row): array => [
                'migration' => (string) $row->migration,
                'batch' => (int) $row->batch,
            ])
            ->all();
    }

    private function assertPrDSchemaPresent(): void
    {
        $this->assertEqualsCanonicalizing(self::NEW_COLUMNS, $this->columnNames(self::NEW_COLUMNS));
        $this->assertEqualsCanonicalizing(self::INDEXES, $this->indexNames(self::INDEXES));
        $this->assertEqualsCanonicalizing(self::FOREIGN_KEYS, $this->constraintNames(self::FOREIGN_KEYS, 'FOREIGN KEY'));
        $this->assertEqualsCanonicalizing(self::CHECKS, $this->constraintNames(self::CHECKS, 'CHECK'));
    }

    private function assertPrDSchemaAbsent(): void
    {
        $this->assertSame([], $this->columnNames(self::NEW_COLUMNS));
        $this->assertSame([], $this->indexNames(self::INDEXES));
        $this->assertSame([], $this->constraintNames(self::FOREIGN_KEYS, 'FOREIGN KEY'));
        $this->assertSame([], $this->constraintNames(self::CHECKS, 'CHECK'));
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function columnNames(array $names): array
    {
        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'debt_offsets')
            ->whereIn('COLUMN_NAME', $names)
            ->orderBy('COLUMN_NAME')
            ->pluck('COLUMN_NAME')
            ->all();
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function indexNames(array $names): array
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'debt_offsets')
            ->whereIn('INDEX_NAME', $names)
            ->distinct()
            ->orderBy('INDEX_NAME')
            ->pluck('INDEX_NAME')
            ->all();
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function constraintNames(array $names, string $type): array
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'debt_offsets')
            ->where('CONSTRAINT_TYPE', $type)
            ->whereIn('CONSTRAINT_NAME', $names)
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    /** @return array{row_count:int,amount:string,row_hash:string} */
    private function legacyDebtOffsetState(): array
    {
        $rows = DB::table('debt_offsets')
            ->select(self::LEGACY_COLUMNS)
            ->orderBy('id')
            ->get()
            ->map(static function (object $row): array {
                $values = (array) $row;
                ksort($values);

                return $values;
            })
            ->all();

        return [
            'row_count' => count($rows),
            'amount' => (string) DB::table('debt_offsets')->selectRaw('COALESCE(SUM(amount), 0) AS amount')->value('amount'),
            'row_hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    /** @return array<string, array{schema:string,row_count:int,row_hash:string}> */
    private function prAbcState(): array
    {
        $manifest = require base_path('scripts/debt-release/releases/pr-d.php');
        $tables = array_merge(
            $manifest['invariant_table_groups']['pr_a'],
            $manifest['invariant_table_groups']['pr_b'],
            $manifest['invariant_table_groups']['pr_c'],
        );
        $state = [];
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing retained table {$table}.");
            $columns = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->orderBy('ORDINAL_POSITION')
                ->pluck('COLUMN_NAME')
                ->all();
            $rows = DB::table($table)
                ->select($columns)
                ->orderBy($columns[0])
                ->get()
                ->map(static function (object $row): array {
                    $values = (array) $row;
                    ksort($values);

                    return $values;
                })
                ->all();
            $state[$table] = [
                'schema' => $this->normalizedCreateTable($table),
                'row_count' => count($rows),
                'row_hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            ];
        }

        return $state;
    }

    /** @return array<string, array<string, mixed>> */
    private function financialAggregateState(): array
    {
        $manifest = require base_path('scripts/debt-release/releases/pr-d.php');
        $state = [];
        foreach ($manifest['financial_aggregates'] as $table => $sql) {
            $row = DB::selectOne($sql);
            $values = (array) $row;
            ksort($values);
            $state[$table] = $values;
        }

        return $state;
    }

    private function normalizedCreateTable(string $table): string
    {
        $row = (array) DB::selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`');
        $create = (string) array_values($row)[1];
        $create = preg_replace('/\s+AUTO_INCREMENT=\d+\b/i', '', $create) ?? $create;

        return preg_replace('/\s+/', ' ', trim($create)) ?? trim($create);
    }
}
