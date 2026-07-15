<?php

namespace Tests\Feature\Migrations;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerDebtOperationOutboxSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const OPERATIONS = 'partner_debt_operations';

    private const PARTICIPANTS = 'partner_debt_operation_participants';

    private const OUTBOX = 'partner_debt_outbox_events';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('PR A schema constraints must be verified on MySQL.');
        }

        foreach ([self::OPERATIONS, self::PARTICIPANTS, self::OUTBOX] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing migrated table {$table}.");
        }
    }

    public function test_operations_columns_match_the_mysql_contract(): void
    {
        $this->assertTableColumns(self::OPERATIONS, [
            'id' => ['bigint unsigned', false, null],
            'operation_uuid' => ['char(36)', false, null],
            'partner_id' => ['bigint unsigned', true, null],
            'operation_type' => ['varchar(64)', false, null],
            'idempotency_key' => ['varchar(191)', false, null],
            'request_hash' => ['char(64)', false, null],
            'request_hash_version' => ['smallint unsigned', false, '1'],
            'status' => ['varchar(24)', false, 'pending'],
            'source_type' => ['varchar(64)', true, null],
            'source_id' => ['bigint unsigned', true, null],
            'reverses_operation_id' => ['bigint unsigned', true, null],
            'result' => ['json', true, null],
            'attempt_count' => ['int unsigned', false, '1'],
            'initiated_by' => ['bigint unsigned', true, null],
            'initiated_at' => ['datetime(6)', false, null],
            'committed_at' => ['datetime(6)', true, null],
            'failed_at' => ['datetime(6)', true, null],
            'failure_code' => ['varchar(64)', true, null],
            'metadata' => ['json', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);
    }

    public function test_participant_columns_match_the_mysql_contract(): void
    {
        $this->assertTableColumns(self::PARTICIPANTS, [
            'id' => ['bigint unsigned', false, null],
            'operation_id' => ['bigint unsigned', false, null],
            'partner_id' => ['bigint unsigned', false, null],
            'participant_role' => ['varchar(32)', false, null],
            'effect_role' => ['varchar(16)', true, null],
            'customer_delta' => ['decimal(15,2)', true, null],
            'supplier_delta' => ['decimal(15,2)', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);
    }

    public function test_outbox_columns_match_the_mysql_contract(): void
    {
        $this->assertTableColumns(self::OUTBOX, [
            'id' => ['bigint unsigned', false, null],
            'event_uuid' => ['char(36)', false, null],
            'operation_id' => ['bigint unsigned', false, null],
            'aggregate_type' => ['varchar(64)', false, null],
            'aggregate_id' => ['bigint unsigned', false, null],
            'event_type' => ['varchar(128)', false, null],
            'schema_version' => ['smallint unsigned', false, '1'],
            'payload' => ['json', false, null],
            'status' => ['varchar(24)', false, 'pending'],
            'occurred_at' => ['datetime(6)', false, null],
            'next_attempt_at' => ['datetime(6)', false, null],
            'attempts' => ['int unsigned', false, '0'],
            'locked_at' => ['datetime(6)', true, null],
            'lease_expires_at' => ['datetime(6)', true, null],
            'locked_by' => ['varchar(191)', true, null],
            'claim_token' => ['char(36)', true, null],
            'published_at' => ['datetime(6)', true, null],
            'last_error_code' => ['varchar(64)', true, null],
            'last_error' => ['varchar(1000)', true, null],
            'dead_lettered_at' => ['datetime(6)', true, null],
            'resolved_by' => ['bigint unsigned', true, null],
            'resolved_at' => ['datetime(6)', true, null],
            'resolution_note' => ['text', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);
    }

    public function test_index_names_column_order_and_identifier_lengths(): void
    {
        $this->assertIndex(self::OPERATIONS, 'pdo_operation_uuid_uq', ['operation_uuid'], true);
        $this->assertIndex(
            self::OPERATIONS,
            'pdo_type_idempotency_uq',
            ['operation_type', 'idempotency_key'],
            true
        );
        $this->assertIndex(
            self::OPERATIONS,
            'pdo_partner_initiated_idx',
            ['partner_id', 'initiated_at'],
            false
        );
        $this->assertIndex(self::OPERATIONS, 'pdo_source_idx', ['source_type', 'source_id'], false);
        $this->assertIndex(self::OPERATIONS, 'pdo_reverses_uq', ['reverses_operation_id'], true);

        $this->assertIndex(
            self::PARTICIPANTS,
            'pdop_op_partner_role_uq',
            ['operation_id', 'partner_id', 'participant_role'],
            true
        );
        $this->assertIndex(
            self::PARTICIPANTS,
            'pdop_partner_operation_idx',
            ['partner_id', 'operation_id'],
            false
        );
        $this->assertIndex(
            self::PARTICIPANTS,
            'pdop_operation_effect_idx',
            ['operation_id', 'effect_role'],
            false
        );

        $this->assertIndex(self::OUTBOX, 'pdoe_event_uuid_uq', ['event_uuid'], true);
        $this->assertIndex(
            self::OUTBOX,
            'pdoe_due_claim_idx',
            ['status', 'next_attempt_at', 'lease_expires_at', 'id'],
            false
        );
        $this->assertIndex(
            self::OUTBOX,
            'pdoe_operation_event_idx',
            ['operation_id', 'event_type'],
            false
        );
        $this->assertIndex(self::OUTBOX, 'pdoe_published_idx', ['published_at', 'id'], false);

        $constraintNames = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', [self::OPERATIONS, self::PARTICIPANTS, self::OUTBOX])
            ->pluck('CONSTRAINT_NAME');
        $indexNames = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', [self::OPERATIONS, self::PARTICIPANTS, self::OUTBOX])
            ->pluck('INDEX_NAME');

        foreach ($constraintNames->merge($indexNames)->unique() as $identifier) {
            $this->assertLessThanOrEqual(64, strlen((string) $identifier), (string) $identifier);
        }
    }

    public function test_foreign_keys_have_the_required_delete_rules(): void
    {
        $this->assertForeignKey(self::OPERATIONS, 'pdo_partner_fk', 'partner_id', 'customers', 'RESTRICT');
        $this->assertForeignKey(
            self::OPERATIONS,
            'pdo_reverses_fk',
            'reverses_operation_id',
            self::OPERATIONS,
            'RESTRICT'
        );
        $this->assertForeignKey(self::OPERATIONS, 'pdo_initiated_by_fk', 'initiated_by', 'users', 'SET NULL');
        $this->assertForeignKey(
            self::PARTICIPANTS,
            'pdop_operation_fk',
            'operation_id',
            self::OPERATIONS,
            'RESTRICT'
        );
        $this->assertForeignKey(self::PARTICIPANTS, 'pdop_partner_fk', 'partner_id', 'customers', 'RESTRICT');
        $this->assertForeignKey(
            self::OUTBOX,
            'pdoe_operation_fk',
            'operation_id',
            self::OPERATIONS,
            'RESTRICT'
        );
        $this->assertForeignKey(self::OUTBOX, 'pdoe_resolved_by_fk', 'resolved_by', 'users', 'SET NULL');
    }

    public function test_named_mysql_checks_exist_and_are_enforced(): void
    {
        foreach ([
            'pdo_status_chk',
            'pdo_source_pair_chk',
            'pdo_attempt_chk',
            'pdop_effect_shape_chk',
            'pdoe_status_chk',
            'pdoe_schema_version_chk',
        ] as $constraint) {
            $row = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $this->databaseName())
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->first();

            $this->assertNotNull($row, "Missing CHECK constraint {$constraint}.");
            $this->assertSame('YES', $row->ENFORCED, "CHECK {$constraint} is not enforced.");
        }
    }

    public function test_operation_primary_key_retains_auto_increment_without_the_incompatible_check(): void
    {
        $extra = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::OPERATIONS)
            ->where('COLUMN_NAME', 'id')
            ->value('EXTRA');

        $this->assertStringContainsString('auto_increment', (string) $extra);
        $this->assertFalse(
            DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $this->databaseName())
                ->where('CONSTRAINT_NAME', 'pdo_no_self_reverse_chk')
                ->exists()
        );
    }

    public function test_operation_unique_reversal_and_check_constraints(): void
    {
        $first = $this->insertOperation();
        $second = $this->insertOperation();
        $this->assertNotSame($first, $second);

        $firstRow = DB::table(self::OPERATIONS)->find($first);
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'operation_uuid' => $firstRow->operation_uuid,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'operation_type' => $firstRow->operation_type,
            'idempotency_key' => $firstRow->idempotency_key,
        ]));

        $reversal = $this->insertOperation(['reverses_operation_id' => $first]);
        $this->assertNotNull(DB::table(self::OPERATIONS)->find($reversal));
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'reverses_operation_id' => $first,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'reverses_operation_id' => 900000001,
        ]));

        $this->assertQueryRejected(fn () => $this->insertOperation(['status' => 'invalid']));
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'source_type' => 'invoice',
            'source_id' => null,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOperation([
            'source_type' => null,
            'source_id' => 1,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOperation(['attempt_count' => 0]));
        $jsonOperation = $this->insertOperation([
            'source_type' => 'invoice',
            'source_id' => 123,
            'result' => json_encode(['invoice_id' => 123], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
        ]);
        $stored = DB::table(self::OPERATIONS)->find($jsonOperation);
        $this->assertSame(123, json_decode($stored->result, true, 512, JSON_THROW_ON_ERROR)['invoice_id']);
    }

    public function test_operation_foreign_key_restrict_and_set_null_behaviour(): void
    {
        $customerId = $this->insertCustomer();
        $operationId = $this->insertOperation(['partner_id' => $customerId]);
        $this->assertQueryRejected(fn () => DB::table('customers')->where('id', $customerId)->delete());

        $userId = $this->insertUser();
        $userOperationId = $this->insertOperation(['initiated_by' => $userId]);
        DB::table('users')->where('id', $userId)->delete();
        $this->assertNull(DB::table(self::OPERATIONS)->find($userOperationId)->initiated_by);

        $reversalId = $this->insertOperation(['reverses_operation_id' => $operationId]);
        $this->assertNotNull(DB::table(self::OPERATIONS)->find($reversalId));
        $this->assertQueryRejected(fn () => DB::table(self::OPERATIONS)->where('id', $operationId)->delete());
    }

    public function test_participant_unique_foreign_keys_and_effect_shapes(): void
    {
        $operationId = $this->insertOperation();
        $customerId = $this->insertCustomer();

        $validShapes = [
            ['effect_role' => null, 'customer_delta' => null, 'supplier_delta' => null],
            ['effect_role' => 'customer', 'customer_delta' => '125.50', 'supplier_delta' => null],
            ['effect_role' => 'customer', 'customer_delta' => '-25.25', 'supplier_delta' => null],
            ['effect_role' => 'supplier', 'customer_delta' => null, 'supplier_delta' => '-40.00'],
            ['effect_role' => 'both', 'customer_delta' => '10.00', 'supplier_delta' => '-10.00'],
            ['effect_role' => 'none', 'customer_delta' => '0.00', 'supplier_delta' => '0.00'],
        ];
        foreach ($validShapes as $index => $shape) {
            $this->insertParticipant($operationId, $customerId, "valid_{$index}", $shape);
        }
        $this->assertCount(
            count($validShapes),
            DB::table(self::PARTICIPANTS)->where('operation_id', $operationId)->get()
        );

        $invalidShapes = [
            ['effect_role' => null, 'customer_delta' => '1.00', 'supplier_delta' => null],
            ['effect_role' => 'customer', 'customer_delta' => null, 'supplier_delta' => null],
            ['effect_role' => 'customer', 'customer_delta' => '1.00', 'supplier_delta' => '1.00'],
            ['effect_role' => 'supplier', 'customer_delta' => '1.00', 'supplier_delta' => null],
            ['effect_role' => 'both', 'customer_delta' => '1.00', 'supplier_delta' => null],
            ['effect_role' => 'none', 'customer_delta' => '1.00', 'supplier_delta' => '0.00'],
            ['effect_role' => 'unknown', 'customer_delta' => null, 'supplier_delta' => null],
        ];
        foreach ($invalidShapes as $index => $shape) {
            $this->assertQueryRejected(fn () => $this->insertParticipant(
                $operationId,
                $customerId,
                "invalid_{$index}",
                $shape
            ));
        }

        $this->assertQueryRejected(fn () => $this->insertParticipant(
            $operationId,
            $customerId,
            'valid_0',
            $validShapes[0]
        ));
        $this->assertQueryRejected(fn () => DB::table(self::OPERATIONS)->where('id', $operationId)->delete());
        $this->assertQueryRejected(fn () => DB::table('customers')->where('id', $customerId)->delete());
    }

    public function test_outbox_unique_checks_json_foreign_keys_and_nullable_lease_contract(): void
    {
        $operationId = $this->insertOperation();
        $eventUuid = (string) Str::uuid();
        $eventId = $this->insertOutboxEvent($operationId, [
            'event_uuid' => $eventUuid,
            'last_error' => str_repeat('x', 1000),
        ]);
        $event = DB::table(self::OUTBOX)->find($eventId);
        $this->assertNull($event->locked_at);
        $this->assertNull($event->lease_expires_at);
        $this->assertNull($event->locked_by);
        $this->assertNull($event->claim_token);
        $this->assertSame([], json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR));

        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, [
            'event_uuid' => $eventUuid,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, ['status' => 'invalid']));
        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, ['schema_version' => 0]));
        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, ['payload' => '{invalid']));
        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, ['payload' => null]));
        $this->assertQueryRejected(fn () => $this->insertOutboxEvent($operationId, [
            'last_error' => str_repeat('x', 1001),
        ]));
        $this->assertQueryRejected(fn () => DB::table(self::OPERATIONS)->where('id', $operationId)->delete());

        $userId = $this->insertUser();
        $resolvedEventId = $this->insertOutboxEvent($operationId, ['resolved_by' => $userId]);
        DB::table('users')->where('id', $userId)->delete();
        $this->assertNull(DB::table(self::OUTBOX)->find($resolvedEventId)->resolved_by);
    }

    /**
     * @param  array<string, array{0: string, 1: bool, 2: string|null}>  $expected
     */
    private function assertTableColumns(string $table, array $expected): void
    {
        $rows = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->get()
            ->keyBy('COLUMN_NAME');

        $this->assertSame(array_keys($expected), $rows->keys()->all());
        foreach ($expected as $column => [$type, $nullable, $default]) {
            $row = $rows->get($column);
            $this->assertNotNull($row, "Missing {$table}.{$column}.");
            $this->assertSame($type, strtolower($row->COLUMN_TYPE), "Type mismatch for {$table}.{$column}.");
            $this->assertSame($nullable ? 'YES' : 'NO', $row->IS_NULLABLE, "Nullability mismatch for {$table}.{$column}.");
            $this->assertSame($default, $row->COLUMN_DEFAULT, "Default mismatch for {$table}.{$column}.");
        }
    }

    /** @param list<string> $columns */
    private function assertIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->orderBy('SEQ_IN_INDEX')
            ->get();

        $this->assertSame($columns, $rows->pluck('COLUMN_NAME')->all(), "Index order mismatch for {$name}.");
        $this->assertNotEmpty($rows);
        $this->assertSame($unique ? 0 : 1, (int) $rows->first()->NON_UNIQUE, "Uniqueness mismatch for {$name}.");
    }

    private function assertForeignKey(
        string $table,
        string $name,
        string $column,
        string $referencedTable,
        string $deleteRule
    ): void {
        $row = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                    ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.CONSTRAINT_SCHEMA', $this->databaseName())
            ->where('k.TABLE_NAME', $table)
            ->where('k.CONSTRAINT_NAME', $name)
            ->select([
                'k.COLUMN_NAME as column_name',
                'k.REFERENCED_TABLE_NAME as referenced_table_name',
                'r.DELETE_RULE as delete_rule',
            ])
            ->first();

        $this->assertNotNull($row, "Missing foreign key {$name}.");
        $this->assertSame($column, $row->column_name);
        $this->assertSame($referencedTable, $row->referenced_table_name);
        $this->assertSame($deleteRule, $row->delete_rule);
    }

    private function assertQueryRejected(Closure $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected MySQL to reject the write.');
    }

    /** @param array<string, mixed> $overrides */
    private function insertOperation(array $overrides = []): int
    {
        $now = now()->format('Y-m-d H:i:s.u');
        $token = (string) Str::uuid();

        return (int) DB::table(self::OPERATIONS)->insertGetId(array_merge([
            'operation_uuid' => $token,
            'partner_id' => null,
            'operation_type' => 'schema_test',
            'idempotency_key' => "schema-test-{$token}",
            'request_hash' => hash('sha256', $token),
            'request_hash_version' => 1,
            'status' => 'pending',
            'source_type' => null,
            'source_id' => null,
            'reverses_operation_id' => null,
            'result' => null,
            'attempt_count' => 1,
            'initiated_by' => null,
            'initiated_at' => $now,
            'committed_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /** @param array<string, mixed> $shape */
    private function insertParticipant(
        int $operationId,
        int $partnerId,
        string $role,
        array $shape
    ): int {
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::PARTICIPANTS)->insertGetId(array_merge([
            'operation_id' => $operationId,
            'partner_id' => $partnerId,
            'participant_role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ], $shape));
    }

    /** @param array<string, mixed> $overrides */
    private function insertOutboxEvent(int $operationId, array $overrides = []): int
    {
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::OUTBOX)->insertGetId(array_merge([
            'event_uuid' => (string) Str::uuid(),
            'operation_id' => $operationId,
            'aggregate_type' => 'partner_debt_operation',
            'aggregate_id' => $operationId,
            'event_type' => 'debt.operation.schema_test.v1',
            'schema_version' => 1,
            'payload' => '[]',
            'status' => 'pending',
            'occurred_at' => $now,
            'next_attempt_at' => $now,
            'attempts' => 0,
            'locked_at' => null,
            'lease_expires_at' => null,
            'locked_by' => null,
            'claim_token' => null,
            'published_at' => null,
            'last_error_code' => null,
            'last_error' => null,
            'dead_lettered_at' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_note' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertCustomer(): int
    {
        $token = str_replace('-', '', (string) Str::uuid());

        return (int) DB::table('customers')->insertGetId([
            'code' => 'SCHEMA-'.$token,
            'name' => 'Schema Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(): int
    {
        $token = str_replace('-', '', (string) Str::uuid());

        return (int) DB::table('users')->insertGetId([
            'name' => 'Schema Test User',
            'email' => "schema-{$token}@example.test",
            'password' => 'not-used-by-schema-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function databaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
