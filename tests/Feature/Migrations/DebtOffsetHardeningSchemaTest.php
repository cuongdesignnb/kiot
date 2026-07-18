<?php

namespace Tests\Feature\Migrations;

use App\Models\DebtOffset;
use App\Services\DebtOffsetService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebtOffsetHardeningSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const TABLE = 'debt_offsets';

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

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('PR D schema tests require MySQL or MariaDB.');
        }
    }

    public function test_new_columns_match_the_nullable_contract_and_legacy_contract_is_unchanged(): void
    {
        $this->assertTableColumns(self::TABLE, [
            'workflow_status' => ['varchar(32)', true, null],
            'requested_by' => ['bigint unsigned', true, null],
            'requested_at' => ['datetime(6)', true, null],
            'approved_by' => ['bigint unsigned', true, null],
            'approved_at' => ['datetime(6)', true, null],
            'rejected_by' => ['bigint unsigned', true, null],
            'rejected_at' => ['datetime(6)', true, null],
            'rejection_reason' => ['text', true, null],
            'applied_at' => ['datetime(6)', true, null],
            'idempotency_key' => ['varchar(191)', true, null],
            'approval_operation_id' => ['bigint unsigned', true, null],
            'apply_operation_id' => ['bigint unsigned', true, null],
            'reversal_operation_id' => ['bigint unsigned', true, null],
            'customer_amount' => ['decimal(15,2)', true, null],
            'supplier_amount' => ['decimal(15,2)', true, null],
            'source_references' => ['json', true, null],
            'reverses_debt_offset_id' => ['bigint unsigned', true, null],
        ]);

        $status = $this->column('status');
        $this->assertSame('varchar(255)', $this->normalizeColumnType((string) $status->COLUMN_TYPE));
        $this->assertSame('NO', $status->IS_NULLABLE);
        $this->assertSame('active', $this->normalizeDefault($status->COLUMN_DEFAULT));

        $this->assertForeignKey(
            'debt_offsets_customer_id_foreign',
            'customer_id',
            'customers',
            'CASCADE'
        );
    }

    public function test_indexes_foreign_keys_checks_and_identifier_lengths_match_contract(): void
    {
        foreach ([
            ['do_requested_by_idx', ['requested_by'], false],
            ['do_approved_by_idx', ['approved_by'], false],
            ['do_rejected_by_idx', ['rejected_by'], false],
            ['do_approval_operation_idx', ['approval_operation_id'], false],
            ['do_apply_operation_idx', ['apply_operation_id'], false],
            ['do_reversal_operation_idx', ['reversal_operation_id'], false],
            ['do_idempotency_uq', ['idempotency_key'], true],
            ['do_reverses_uq', ['reverses_debt_offset_id'], true],
        ] as [$name, $columns, $unique]) {
            $this->assertIndex($name, $columns, $unique);
        }

        foreach ([
            ['do_requested_by_fk', 'requested_by', 'users', 'SET NULL'],
            ['do_approved_by_fk', 'approved_by', 'users', 'SET NULL'],
            ['do_rejected_by_fk', 'rejected_by', 'users', 'SET NULL'],
            ['do_approval_operation_fk', 'approval_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['do_apply_operation_fk', 'apply_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['do_reversal_operation_fk', 'reversal_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['do_reverses_fk', 'reverses_debt_offset_id', self::TABLE, 'RESTRICT'],
        ] as [$name, $column, $target, $rule]) {
            $this->assertForeignKey($name, $column, $target, $rule);
        }

        $checks = [
            'do_workflow_status_chk',
            'do_amount_pair_chk',
            'do_amount_positive_chk',
            'do_amount_equal_chk',
            'do_rejection_reason_chk',
            'do_idempotency_nonempty_chk',
        ];
        $this->assertChecks($checks);

        $constraintNames = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', 'like', 'do\_%')
            ->pluck('CONSTRAINT_NAME');
        $indexNames = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('INDEX_NAME', 'like', 'do\_%')
            ->pluck('INDEX_NAME');

        foreach ($constraintNames->merge($indexNames)->unique() as $identifier) {
            $this->assertLessThanOrEqual(64, strlen((string) $identifier));
        }
    }

    public function test_legacy_active_and_cancelled_rows_remain_readable_with_all_new_columns_null(): void
    {
        $customerId = $this->insertCustomer();
        $activeId = $this->insertOffset($customerId, ['status' => 'active']);
        $cancelledId = $this->insertOffset($customerId, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => 'Schema compatibility cancellation',
        ]);

        foreach ([$activeId, $cancelledId] as $id) {
            $this->assertNewColumnsNull(DB::table(self::TABLE)->find($id));
            $this->assertNotNull(DebtOffset::query()->find($id));
        }

        $this->assertSame('active', DebtOffset::query()->findOrFail($activeId)->status);
        $this->assertSame('cancelled', DebtOffset::query()->findOrFail($cancelledId)->status);
    }

    public function test_current_manual_auto_and_cancel_service_paths_remain_legacy_compatible(): void
    {
        $actorId = $this->insertUser();
        $actor = \App\Models\User::query()->findOrFail($actorId);
        $this->actingAs($actor);

        $manualPartnerId = $this->insertCustomer([
            'debt_amount' => '120.00',
            'supplier_debt_amount' => '100.00',
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $this->insertCanonicalDebtDocuments($manualPartnerId, 120.00, 100.00);
        $manualPartner = \App\Models\Customer::query()->findOrFail($manualPartnerId);
        $manual = DebtOffsetService::manualOffset($manualPartner, 40.00, 'Legacy manual compatibility');
        $this->assertSame(40.0, (float) $manual['offset_amount']);

        $manualOffset = DebtOffset::query()->where('customer_id', $manualPartnerId)->latest('id')->firstOrFail();
        $this->assertSame('active', $manualOffset->status);
        $this->assertFalse((bool) $manualOffset->is_auto);
        $this->assertNewColumnsNull(DB::table(self::TABLE)->find($manualOffset->id));

        $cancelled = DebtOffsetService::cancelOffset($manualOffset, 'Legacy cancel compatibility');
        $this->assertSame(40.0, (float) $cancelled['cancelled_amount']);
        $manualOffset->refresh();
        $this->assertSame('cancelled', $manualOffset->status);
        $this->assertNewColumnsNull(DB::table(self::TABLE)->find($manualOffset->id));

        $autoPartnerId = $this->insertCustomer([
            'debt_amount' => '90.00',
            'supplier_debt_amount' => '70.00',
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $this->insertCanonicalDebtDocuments($autoPartnerId, 90.00, 70.00);
        $autoPartner = \App\Models\Customer::query()->findOrFail($autoPartnerId);
        $auto = DebtOffsetService::offsetDebts($autoPartner);
        $this->assertSame(70.0, (float) $auto['offset_amount']);

        $autoOffset = DebtOffset::query()->where('customer_id', $autoPartnerId)->latest('id')->firstOrFail();
        $this->assertSame('active', $autoOffset->status);
        $this->assertTrue((bool) $autoOffset->is_auto);
        $this->assertNewColumnsNull(DB::table(self::TABLE)->find($autoOffset->id));
    }

    public function test_nullable_unique_idempotency_key_allows_many_nulls_and_rejects_duplicate_value(): void
    {
        $customerId = $this->insertCustomer();
        $this->assertNotNull($this->insertOffset($customerId));
        $this->assertNotNull($this->insertOffset($customerId));

        $key = 'offset-request-'.Str::uuid();
        $this->insertOffset($customerId, ['idempotency_key' => $key]);
        $this->assertQueryRejected(
            fn () => $this->insertOffset($customerId, ['idempotency_key' => $key])
        );
    }

    public function test_nullable_unique_reversal_reference_allows_many_nulls_and_one_reversal(): void
    {
        $customerId = $this->insertCustomer();
        $originalId = $this->insertOffset($customerId);
        $this->assertNotNull($this->insertOffset($customerId));
        $this->assertNotNull($this->insertOffset($customerId));
        $this->insertOffset($customerId, ['reverses_debt_offset_id' => $originalId]);

        $this->assertQueryRejected(
            fn () => $this->insertOffset($customerId, ['reverses_debt_offset_id' => $originalId])
        );
    }

    public function test_actor_foreign_keys_reject_invalid_ids_and_set_null_on_delete(): void
    {
        $customerId = $this->insertCustomer();
        foreach (['requested_by', 'approved_by', 'rejected_by'] as $column) {
            $this->assertQueryRejected(fn () => $this->insertOffset($customerId, [$column => 900000001]));
        }

        $actorId = $this->insertUser();
        $offsetId = $this->insertOffset($customerId, [
            'requested_by' => $actorId,
            'approved_by' => $actorId,
            'rejected_by' => $actorId,
        ]);
        DB::table('users')->where('id', $actorId)->delete();

        $row = DB::table(self::TABLE)->find($offsetId);
        $this->assertNull($row->requested_by);
        $this->assertNull($row->approved_by);
        $this->assertNull($row->rejected_by);
    }

    public function test_operation_foreign_keys_reject_invalid_ids_and_restrict_delete(): void
    {
        $customerId = $this->insertCustomer();
        foreach (['approval_operation_id', 'apply_operation_id', 'reversal_operation_id'] as $column) {
            $this->assertQueryRejected(fn () => $this->insertOffset($customerId, [$column => 900000001]));
        }

        $approvalId = $this->insertOperation();
        $applyId = $this->insertOperation();
        $reversalId = $this->insertOperation();
        $this->insertOffset($customerId, [
            'approval_operation_id' => $approvalId,
            'apply_operation_id' => $applyId,
            'reversal_operation_id' => $reversalId,
        ]);

        foreach ([$approvalId, $applyId, $reversalId] as $operationId) {
            $this->assertQueryRejected(
                fn () => DB::table('partner_debt_operations')->where('id', $operationId)->delete()
            );
        }
    }

    public function test_self_foreign_key_rejects_invalid_reference_and_restricts_original_delete(): void
    {
        $customerId = $this->insertCustomer();
        $this->assertQueryRejected(
            fn () => $this->insertOffset($customerId, ['reverses_debt_offset_id' => 900000001])
        );

        $originalId = $this->insertOffset($customerId);
        $this->insertOffset($customerId, ['reverses_debt_offset_id' => $originalId]);
        $this->assertQueryRejected(fn () => DB::table(self::TABLE)->where('id', $originalId)->delete());
    }

    public function test_workflow_status_check_accepts_null_and_allowed_states_and_rejects_unknown_state(): void
    {
        $customerId = $this->insertCustomer();
        $this->assertNotNull($this->insertOffset($customerId, ['workflow_status' => null]));

        foreach (['draft', 'pending_approval', 'approved', 'applied', 'void', 'reversed'] as $status) {
            $this->assertNotNull($this->insertOffset($customerId, ['workflow_status' => $status]));
        }
        $this->assertNotNull($this->insertOffset($customerId, [
            'workflow_status' => 'rejected',
            'rejection_reason' => 'Rejected by schema test',
        ]));

        $this->assertCheckRejected(
            fn () => $this->insertOffset($customerId, ['workflow_status' => 'cancelled']),
            'do_workflow_status_chk'
        );
    }

    public function test_amount_checks_accept_null_or_positive_equal_sides_and_reject_invalid_shapes(): void
    {
        $customerId = $this->insertCustomer();
        $this->assertNotNull($this->insertOffset($customerId, [
            'customer_amount' => null,
            'supplier_amount' => null,
        ]));
        $this->assertNotNull($this->insertOffset($customerId, [
            'amount' => '100.00',
            'customer_amount' => '100.00',
            'supplier_amount' => '100.00',
        ]));

        $this->assertCheckRejected(fn () => $this->insertOffset($customerId, [
            'customer_amount' => '100.00',
            'supplier_amount' => null,
        ]), 'do_amount_pair_chk');

        foreach (['0.00', '-1.00'] as $amount) {
            $this->assertCheckRejected(fn () => $this->insertOffset($customerId, [
                'amount' => $amount,
                'customer_amount' => $amount,
                'supplier_amount' => $amount,
            ]), 'do_amount_positive_chk');
        }

        $this->assertCheckRejected(fn () => $this->insertOffset($customerId, [
            'amount' => '100.00',
            'customer_amount' => '100.00',
            'supplier_amount' => '90.00',
        ]), 'do_amount_equal_chk');
        $this->assertCheckRejected(fn () => $this->insertOffset($customerId, [
            'amount' => '100.00',
            'customer_amount' => '90.00',
            'supplier_amount' => '90.00',
        ]), 'do_amount_equal_chk');
    }

    public function test_rejection_reason_and_idempotency_nonempty_checks_are_enforced(): void
    {
        $customerId = $this->insertCustomer();
        foreach ([null, '', '   '] as $reason) {
            $this->assertCheckRejected(fn () => $this->insertOffset($customerId, [
                'workflow_status' => 'rejected',
                'rejection_reason' => $reason,
            ]), 'do_rejection_reason_chk');
        }

        $this->assertNotNull($this->insertOffset($customerId, [
            'workflow_status' => 'rejected',
            'rejection_reason' => 'Valid reason',
        ]));
        $this->assertNotNull($this->insertOffset($customerId, ['idempotency_key' => null]));
        $this->assertNotNull($this->insertOffset($customerId, [
            'idempotency_key' => 'valid-'.Str::uuid(),
        ]));

        foreach (['', '   '] as $key) {
            $this->assertCheckRejected(
                fn () => $this->insertOffset($customerId, ['idempotency_key' => $key]),
                'do_idempotency_nonempty_chk'
            );
        }
    }

    public function test_source_references_accepts_null_and_valid_json_and_rejects_invalid_json(): void
    {
        $customerId = $this->insertCustomer();
        $this->assertNotNull($this->insertOffset($customerId, ['source_references' => null]));
        $id = $this->insertOffset($customerId, [
            'source_references' => json_encode([
                ['type' => 'invoice', 'id' => 'fixture-1'],
            ]),
        ]);
        $this->assertJson((string) DB::table(self::TABLE)->find($id)->source_references);
        $this->assertQueryRejected(
            fn () => $this->insertOffset($customerId, ['source_references' => '{invalid-json'])
        );
    }

    /** @param array<string, array{0: string, 1: bool, 2: string|null}> $expected */
    private function assertTableColumns(string $table, array $expected): void
    {
        $rows = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', array_keys($expected))
            ->get()
            ->keyBy('COLUMN_NAME');

        $this->assertEqualsCanonicalizing(array_keys($expected), $rows->keys()->all());
        foreach ($expected as $column => [$type, $nullable, $default]) {
            $row = $rows->get($column);
            $this->assertNotNull($row, "Missing {$table}.{$column}.");
            $actualType = $this->normalizeColumnType((string) $row->COLUMN_TYPE);
            if ($type === 'json' && $this->isMariaDb()) {
                $this->assertContains($actualType, ['json', 'longtext']);
            } else {
                $this->assertSame($type, $actualType);
            }
            $this->assertSame($nullable ? 'YES' : 'NO', $row->IS_NULLABLE);
            $this->assertSame($default, $this->normalizeDefault($row->COLUMN_DEFAULT));
        }
    }

    private function isMariaDb(): bool
    {
        if (DB::connection()->getDriverName() === 'mariadb') {
            return true;
        }

        $serverVersion = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return str_contains(strtolower($serverVersion), 'mariadb');
    }

    private function column(string $name): object
    {
        $row = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('COLUMN_NAME', $name)
            ->first();

        $this->assertNotNull($row, "Missing debt_offsets.{$name}.");

        return $row;
    }

    private function normalizeColumnType(string $type): string
    {
        return preg_replace('/\b(bigint|int|smallint|tinyint)\(\d+\)/', '$1', strtolower($type)) ?? strtolower($type);
    }

    private function normalizeDefault(mixed $default): ?string
    {
        if ($default === null || (is_string($default) && strtoupper($default) === 'NULL')) {
            return null;
        }

        $value = (string) $default;
        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /** @param list<string> $columns */
    private function assertIndex(string $name, array $columns, bool $unique): void
    {
        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('INDEX_NAME', $name)
            ->orderBy('SEQ_IN_INDEX')
            ->get();

        $this->assertNotEmpty($rows, "Missing index {$name}.");
        $this->assertSame($columns, $rows->pluck('COLUMN_NAME')->all());
        $this->assertSame($unique ? 0 : 1, (int) $rows->first()->NON_UNIQUE);
    }

    private function assertForeignKey(string $name, string $column, string $target, string $deleteRule): void
    {
        $row = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                    ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.CONSTRAINT_SCHEMA', $this->databaseName())
            ->where('k.TABLE_NAME', self::TABLE)
            ->where('k.CONSTRAINT_NAME', $name)
            ->select(['k.COLUMN_NAME', 'k.REFERENCED_TABLE_NAME', 'r.DELETE_RULE'])
            ->first();

        $this->assertNotNull($row, "Missing foreign key {$name}.");
        $this->assertSame($column, $row->COLUMN_NAME);
        $this->assertSame($target, $row->REFERENCED_TABLE_NAME);
        $this->assertSame($deleteRule, $row->DELETE_RULE);
    }

    /** @param list<string> $checks */
    private function assertChecks(array $checks): void
    {
        foreach ($checks as $constraint) {
            $row = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $this->databaseName())
                ->where('TABLE_NAME', self::TABLE)
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->first();

            $this->assertNotNull($row, "Missing CHECK constraint {$constraint}.");
            if (property_exists($row, 'ENFORCED')) {
                $this->assertSame('YES', strtoupper((string) $row->ENFORCED));
            }
        }
    }

    private function assertQueryRejected(Closure $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the write.');
    }

    private function assertCheckRejected(Closure $callback, string $constraint): void
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [3819, 4025]);
            $this->assertStringContainsString($constraint, $exception->getMessage());

            return;
        }

        $this->fail("Expected CHECK {$constraint} to reject the write.");
    }

    private function assertNewColumnsNull(object $row): void
    {
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertNull($row->{$column}, "Expected {$column} to remain NULL for a legacy write.");
        }
    }

    /** @param array<string, mixed> $overrides */
    private function insertOffset(int $customerId, array $overrides = []): int
    {
        return (int) DB::table(self::TABLE)->insertGetId(array_merge([
            'code' => 'SCHEMA-DO-'.Str::uuid(),
            'customer_id' => $customerId,
            'amount' => '100.00',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertCustomer(array $overrides = []): int
    {
        $token = (string) Str::uuid();

        return (int) DB::table('customers')->insertGetId(array_merge([
            'code' => 'SCHEMA-C-'.$token,
            'name' => 'Schema Test Partner',
            'debt_amount' => '0.00',
            'supplier_debt_amount' => '0.00',
            'is_customer' => true,
            'is_supplier' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertCanonicalDebtDocuments(int $partnerId, float $receivable, float $payable): void
    {
        $token = (string) Str::uuid();
        $now = now();

        DB::table('invoices')->insert([
            'code' => 'SCHEMA-HD-'.$token,
            'customer_id' => $partnerId,
            'subtotal' => $receivable,
            'discount' => 0,
            'total' => $receivable,
            'customer_paid' => 0,
            'status' => 'completed',
            'transaction_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('purchases')->insert([
            'code' => 'SCHEMA-PN-'.$token,
            'supplier_id' => $partnerId,
            'total_amount' => $payable,
            'discount' => 0,
            'paid_amount' => 0,
            'debt_amount' => $payable,
            'status' => 'completed',
            'purchase_date' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertUser(): int
    {
        $token = (string) Str::uuid();

        return (int) DB::table('users')->insertGetId([
            'name' => 'Schema Test User',
            'email' => "schema-{$token}@example.test",
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOperation(): int
    {
        $now = now()->format('Y-m-d H:i:s.u');
        $token = (string) Str::uuid();

        return (int) DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => $token,
            'partner_id' => null,
            'operation_type' => 'debt_offset_schema_test',
            'idempotency_key' => "schema-{$token}",
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
        ]);
    }

    private function databaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
