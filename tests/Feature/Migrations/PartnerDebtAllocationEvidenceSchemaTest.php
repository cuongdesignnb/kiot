<?php

namespace Tests\Feature\Migrations;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerDebtAllocationEvidenceSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const SUPPLIER_ALLOCATIONS = 'supplier_payment_allocations';

    private const SUPPLIER_REVERSALS = 'supplier_payment_allocation_reversals';

    private const CUSTOMER_REVERSALS = 'customer_payment_allocation_reversals';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('PR B schema constraints require MySQL or MariaDB.');
        }

        foreach ([self::SUPPLIER_ALLOCATIONS, self::SUPPLIER_REVERSALS, self::CUSTOMER_REVERSALS] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing migrated table {$table}.");
        }
    }

    public function test_columns_match_the_allocation_evidence_contract(): void
    {
        $this->assertTableColumns(self::SUPPLIER_ALLOCATIONS, [
            'id' => ['bigint unsigned', false, null],
            'payment_id' => ['bigint unsigned', false, null],
            'purchase_id' => ['bigint unsigned', false, null],
            'supplier_id' => ['bigint unsigned', false, null],
            'amount' => ['decimal(15,2)', false, null],
            'allocation_source' => ['varchar(16)', false, null],
            'idempotency_key' => ['varchar(191)', false, null],
            'operation_id' => ['bigint unsigned', false, null],
            'allocated_at' => ['datetime(6)', false, null],
            'created_by' => ['bigint unsigned', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);

        $reversalColumns = [
            'id' => ['bigint unsigned', false, null],
            'allocation_id' => ['bigint unsigned', false, null],
            'amount' => ['decimal(15,2)', false, null],
            'idempotency_key' => ['varchar(191)', false, null],
            'operation_id' => ['bigint unsigned', false, null],
            'reason' => ['text', false, null],
            'reversed_by' => ['bigint unsigned', true, null],
            'reversed_at' => ['datetime(6)', false, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ];
        $this->assertTableColumns(self::SUPPLIER_REVERSALS, $reversalColumns);
        $this->assertTableColumns(self::CUSTOMER_REVERSALS, $reversalColumns);
    }

    public function test_index_names_column_order_and_identifier_lengths(): void
    {
        $this->assertIndex(self::SUPPLIER_ALLOCATIONS, 'spa_payment_purchase_uq', ['payment_id', 'purchase_id'], true);
        $this->assertIndex(self::SUPPLIER_ALLOCATIONS, 'spa_idempotency_uq', ['idempotency_key'], true);
        $this->assertIndex(self::SUPPLIER_ALLOCATIONS, 'spa_supplier_purchase_idx', ['supplier_id', 'purchase_id'], false);
        $this->assertIndex(self::SUPPLIER_ALLOCATIONS, 'spa_purchase_allocated_idx', ['purchase_id', 'allocated_at', 'id'], false);
        $this->assertIndex(self::SUPPLIER_ALLOCATIONS, 'spa_operation_idx', ['operation_id'], false);

        $this->assertReversalIndexes(self::SUPPLIER_REVERSALS, 'spar');
        $this->assertReversalIndexes(self::CUSTOMER_REVERSALS, 'cpar');

        $constraintNames = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', [self::SUPPLIER_ALLOCATIONS, self::SUPPLIER_REVERSALS, self::CUSTOMER_REVERSALS])
            ->pluck('CONSTRAINT_NAME');
        $indexNames = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', [self::SUPPLIER_ALLOCATIONS, self::SUPPLIER_REVERSALS, self::CUSTOMER_REVERSALS])
            ->pluck('INDEX_NAME');

        foreach ($constraintNames->merge($indexNames)->unique() as $identifier) {
            $this->assertLessThanOrEqual(64, strlen((string) $identifier), (string) $identifier);
        }
    }

    public function test_foreign_keys_have_the_required_targets_and_delete_rules(): void
    {
        $this->assertForeignKey(self::SUPPLIER_ALLOCATIONS, 'spa_payment_fk', 'payment_id', 'cash_flows', 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_ALLOCATIONS, 'spa_purchase_fk', 'purchase_id', 'purchases', 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_ALLOCATIONS, 'spa_supplier_fk', 'supplier_id', 'customers', 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_ALLOCATIONS, 'spa_operation_fk', 'operation_id', 'partner_debt_operations', 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_ALLOCATIONS, 'spa_created_by_fk', 'created_by', 'users', 'SET NULL');

        $this->assertForeignKey(self::SUPPLIER_REVERSALS, 'spar_allocation_fk', 'allocation_id', self::SUPPLIER_ALLOCATIONS, 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_REVERSALS, 'spar_operation_fk', 'operation_id', 'partner_debt_operations', 'RESTRICT');
        $this->assertForeignKey(self::SUPPLIER_REVERSALS, 'spar_reversed_by_fk', 'reversed_by', 'users', 'SET NULL');

        $this->assertForeignKey(self::CUSTOMER_REVERSALS, 'cpar_allocation_fk', 'allocation_id', 'customer_payment_allocations', 'RESTRICT');
        $this->assertForeignKey(self::CUSTOMER_REVERSALS, 'cpar_operation_fk', 'operation_id', 'partner_debt_operations', 'RESTRICT');
        $this->assertForeignKey(self::CUSTOMER_REVERSALS, 'cpar_reversed_by_fk', 'reversed_by', 'users', 'SET NULL');
    }

    public function test_named_checks_exist_and_are_enforced_when_metadata_is_available(): void
    {
        foreach ([
            'spa_amount_positive_chk',
            'spa_source_chk',
            'spar_amount_positive_chk',
            'spar_reason_nonempty_chk',
            'cpar_amount_positive_chk',
            'cpar_reason_nonempty_chk',
        ] as $constraint) {
            $row = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $this->databaseName())
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->first();

            $this->assertNotNull($row, "Missing CHECK constraint {$constraint}.");
            if (property_exists($row, 'ENFORCED')) {
                $this->assertSame('YES', strtoupper((string) $row->ENFORCED));
            }
        }
    }

    public function test_supplier_allocation_checks_uniques_and_foreign_keys_are_enforced(): void
    {
        $fixture = $this->supplierAllocationFixture();
        $validId = $this->insertSupplierAllocation($fixture);
        $this->assertNotNull(DB::table(self::SUPPLIER_ALLOCATIONS)->find($validId));

        foreach (['0.00', '-1.00'] as $amount) {
            $this->assertCheckRejected(
                fn () => $this->insertSupplierAllocation($fixture, [
                    'payment_id' => $this->insertCashFlow(),
                    'purchase_id' => $this->insertPurchase($fixture['supplier_id']),
                    'amount' => $amount,
                ]),
                'spa_amount_positive_chk'
            );
        }
        $this->assertCheckRejected(
            fn () => $this->insertSupplierAllocation($fixture, [
                'payment_id' => $this->insertCashFlow(),
                'purchase_id' => $this->insertPurchase($fixture['supplier_id']),
                'allocation_source' => 'unknown',
            ]),
            'spa_source_chk'
        );
        $this->assertQueryRejected(fn () => $this->insertSupplierAllocation($fixture, [
            'payment_id' => $this->insertCashFlow(),
            'purchase_id' => $this->insertPurchase($fixture['supplier_id']),
            'allocation_source' => null,
        ]));

        $this->assertQueryRejected(fn () => $this->insertSupplierAllocation($fixture, [
            'idempotency_key' => (string) Str::uuid(),
        ]));
        $this->assertQueryRejected(fn () => $this->insertSupplierAllocation($fixture, [
            'payment_id' => $this->insertCashFlow(),
            'purchase_id' => $this->insertPurchase($fixture['supplier_id']),
            'idempotency_key' => $fixture['idempotency_key'],
        ]));

        foreach (['payment_id', 'purchase_id', 'supplier_id', 'operation_id', 'created_by'] as $foreignKey) {
            $this->assertQueryRejected(fn () => $this->insertSupplierAllocation($fixture, [
                'payment_id' => $this->insertCashFlow(),
                'purchase_id' => $this->insertPurchase($fixture['supplier_id']),
                $foreignKey => 900000001,
            ]));
        }
    }

    public function test_supplier_allocation_restricts_evidence_deletes_and_nulls_deleted_actor(): void
    {
        $fixture = $this->supplierAllocationFixture();
        $allocationId = $this->insertSupplierAllocation($fixture);

        $this->assertQueryRejected(fn () => DB::table('cash_flows')->where('id', $fixture['payment_id'])->delete());
        $this->assertQueryRejected(fn () => DB::table('purchases')->where('id', $fixture['purchase_id'])->delete());
        $this->assertQueryRejected(fn () => DB::table('customers')->where('id', $fixture['supplier_id'])->delete());
        $this->assertQueryRejected(fn () => DB::table('partner_debt_operations')->where('id', $fixture['operation_id'])->delete());

        DB::table('users')->where('id', $fixture['actor_id'])->delete();
        $this->assertNull(DB::table(self::SUPPLIER_ALLOCATIONS)->find($allocationId)->created_by);
    }

    public function test_supplier_reversal_checks_uniques_and_foreign_keys_are_enforced(): void
    {
        $fixture = $this->supplierAllocationFixture();
        $fixture['allocation_id'] = $this->insertSupplierAllocation($fixture);
        $fixture['reversal_operation_id'] = $this->insertOperation();
        $fixture['reversal_key'] = (string) Str::uuid();
        $reversalId = $this->insertReversal(self::SUPPLIER_REVERSALS, $fixture);
        $this->assertNotNull(DB::table(self::SUPPLIER_REVERSALS)->find($reversalId));

        foreach (['0.00', '-1.00'] as $amount) {
            $newFixture = $this->supplierAllocationFixture();
            $newFixture['allocation_id'] = $this->insertSupplierAllocation($newFixture);
            $newFixture['reversal_operation_id'] = $this->insertOperation();
            $this->assertCheckRejected(
                fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, ['amount' => $amount]),
                'spar_amount_positive_chk'
            );
        }
        foreach (['', '   '] as $reason) {
            $newFixture = $this->supplierAllocationFixture();
            $newFixture['allocation_id'] = $this->insertSupplierAllocation($newFixture);
            $newFixture['reversal_operation_id'] = $this->insertOperation();
            $this->assertCheckRejected(
                fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, ['reason' => $reason]),
                'spar_reason_nonempty_chk'
            );
        }

        $this->assertQueryRejected(fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $fixture, [
            'idempotency_key' => (string) Str::uuid(),
        ]));
        $newFixture = $this->supplierAllocationFixture();
        $newFixture['allocation_id'] = $this->insertSupplierAllocation($newFixture);
        $newFixture['reversal_operation_id'] = $this->insertOperation();
        $this->assertQueryRejected(fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, [
            'idempotency_key' => $fixture['reversal_key'],
        ]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, ['allocation_id' => 900000001]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, ['operation_id' => 900000001]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::SUPPLIER_REVERSALS, $newFixture, ['reversed_by' => 900000001]));
    }

    public function test_supplier_reversal_restricts_evidence_deletes_and_nulls_deleted_actor(): void
    {
        $fixture = $this->supplierAllocationFixture();
        $fixture['allocation_id'] = $this->insertSupplierAllocation($fixture);
        $fixture['reversal_operation_id'] = $this->insertOperation();
        $reversalId = $this->insertReversal(self::SUPPLIER_REVERSALS, $fixture);

        $this->assertQueryRejected(fn () => DB::table(self::SUPPLIER_ALLOCATIONS)->where('id', $fixture['allocation_id'])->delete());
        $this->assertQueryRejected(fn () => DB::table('partner_debt_operations')->where('id', $fixture['reversal_operation_id'])->delete());
        DB::table('users')->where('id', $fixture['actor_id'])->delete();
        $this->assertNull(DB::table(self::SUPPLIER_REVERSALS)->find($reversalId)->reversed_by);
    }

    public function test_customer_reversal_checks_uniques_and_foreign_keys_are_enforced(): void
    {
        $fixture = $this->customerAllocationFixture();
        $fixture['reversal_operation_id'] = $this->insertOperation();
        $fixture['reversal_key'] = (string) Str::uuid();
        $reversalId = $this->insertReversal(self::CUSTOMER_REVERSALS, $fixture);
        $this->assertNotNull(DB::table(self::CUSTOMER_REVERSALS)->find($reversalId));

        foreach (['0.00', '-1.00'] as $amount) {
            $newFixture = $this->customerAllocationFixture();
            $newFixture['reversal_operation_id'] = $this->insertOperation();
            $this->assertCheckRejected(
                fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, ['amount' => $amount]),
                'cpar_amount_positive_chk'
            );
        }
        foreach (['', '   '] as $reason) {
            $newFixture = $this->customerAllocationFixture();
            $newFixture['reversal_operation_id'] = $this->insertOperation();
            $this->assertCheckRejected(
                fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, ['reason' => $reason]),
                'cpar_reason_nonempty_chk'
            );
        }

        $this->assertQueryRejected(fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $fixture, [
            'idempotency_key' => (string) Str::uuid(),
        ]));
        $newFixture = $this->customerAllocationFixture();
        $newFixture['reversal_operation_id'] = $this->insertOperation();
        $this->assertQueryRejected(fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, [
            'idempotency_key' => $fixture['reversal_key'],
        ]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, ['allocation_id' => 900000001]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, ['operation_id' => 900000001]));
        $this->assertQueryRejected(fn () => $this->insertReversal(self::CUSTOMER_REVERSALS, $newFixture, ['reversed_by' => 900000001]));
    }

    public function test_customer_reversal_restricts_evidence_deletes_and_nulls_deleted_actor(): void
    {
        $fixture = $this->customerAllocationFixture();
        $fixture['reversal_operation_id'] = $this->insertOperation();
        $reversalId = $this->insertReversal(self::CUSTOMER_REVERSALS, $fixture);

        $this->assertQueryRejected(fn () => DB::table('customer_payment_allocations')->where('id', $fixture['allocation_id'])->delete());
        $this->assertQueryRejected(fn () => DB::table('partner_debt_operations')->where('id', $fixture['reversal_operation_id'])->delete());
        DB::table('users')->where('id', $fixture['actor_id'])->delete();
        $this->assertNull(DB::table(self::CUSTOMER_REVERSALS)->find($reversalId)->reversed_by);
    }

    /** @param array<string, array{0: string, 1: bool, 2: string|null}> $expected */
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
            $actualDefault = $row->COLUMN_DEFAULT;
            if (is_string($actualDefault) && strtoupper($actualDefault) === 'NULL') {
                $actualDefault = null;
            }
            $this->assertSame($type, $this->normalizeColumnType((string) $row->COLUMN_TYPE));
            $this->assertSame($nullable ? 'YES' : 'NO', $row->IS_NULLABLE);
            $this->assertSame($default, $actualDefault);
        }
    }

    private function normalizeColumnType(string $type): string
    {
        return preg_replace('/\b(bigint|int|smallint|tinyint)\(\d+\)/', '$1', strtolower($type)) ?? strtolower($type);
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

        $this->assertNotEmpty($rows, "Missing index {$name}.");
        $this->assertSame($columns, $rows->pluck('COLUMN_NAME')->all());
        $this->assertSame($unique ? 0 : 1, (int) $rows->first()->NON_UNIQUE);
    }

    private function assertReversalIndexes(string $table, string $prefix): void
    {
        $this->assertIndex($table, "{$prefix}_allocation_uq", ['allocation_id'], true);
        $this->assertIndex($table, "{$prefix}_idempotency_uq", ['idempotency_key'], true);
        $this->assertIndex($table, "{$prefix}_operation_idx", ['operation_id'], false);
        $this->assertIndex($table, "{$prefix}_reversed_at_idx", ['reversed_at', 'id'], false);
    }

    private function assertForeignKey(string $table, string $name, string $column, string $target, string $deleteRule): void
    {
        $row = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                    ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.CONSTRAINT_SCHEMA', $this->databaseName())
            ->where('k.TABLE_NAME', $table)
            ->where('k.CONSTRAINT_NAME', $name)
            ->select(['k.COLUMN_NAME', 'k.REFERENCED_TABLE_NAME', 'r.DELETE_RULE'])
            ->first();

        $this->assertNotNull($row, "Missing foreign key {$name}.");
        $this->assertSame($column, $row->COLUMN_NAME);
        $this->assertSame($target, $row->REFERENCED_TABLE_NAME);
        $this->assertSame($deleteRule, $row->DELETE_RULE);
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

    /** @return array<string, int|string> */
    private function supplierAllocationFixture(): array
    {
        $supplierId = $this->insertCustomer();

        return [
            'supplier_id' => $supplierId,
            'payment_id' => $this->insertCashFlow(),
            'purchase_id' => $this->insertPurchase($supplierId),
            'operation_id' => $this->insertOperation(),
            'actor_id' => $this->insertUser(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, int|string> */
    private function customerAllocationFixture(): array
    {
        $customerId = $this->insertCustomer();
        $cashFlowId = $this->insertCashFlow('receipt');
        $invoiceId = $this->insertInvoice($customerId);
        $allocationId = (int) DB::table('customer_payment_allocations')->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'amount' => '50.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'allocation_id' => $allocationId,
            'actor_id' => $this->insertUser(),
        ];
    }

    /** @param array<string, int|string> $fixture @param array<string, mixed> $overrides */
    private function insertSupplierAllocation(array $fixture, array $overrides = []): int
    {
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::SUPPLIER_ALLOCATIONS)->insertGetId(array_merge([
            'payment_id' => $fixture['payment_id'],
            'purchase_id' => $fixture['purchase_id'],
            'supplier_id' => $fixture['supplier_id'],
            'amount' => '50.00',
            'allocation_source' => 'manual',
            'idempotency_key' => $fixture['idempotency_key'] ?? (string) Str::uuid(),
            'operation_id' => $fixture['operation_id'],
            'allocated_at' => $now,
            'created_by' => $fixture['actor_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /** @param array<string, int|string> $fixture @param array<string, mixed> $overrides */
    private function insertReversal(string $table, array $fixture, array $overrides = []): int
    {
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table($table)->insertGetId(array_merge([
            'allocation_id' => $fixture['allocation_id'],
            'amount' => '50.00',
            'idempotency_key' => $fixture['reversal_key'] ?? (string) Str::uuid(),
            'operation_id' => $fixture['reversal_operation_id'],
            'reason' => 'Schema test reversal',
            'reversed_by' => $fixture['actor_id'] ?? null,
            'reversed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertCashFlow(string $type = 'payment'): int
    {
        $token = (string) Str::uuid();

        return (int) DB::table('cash_flows')->insertGetId([
            'code' => 'SCHEMA-CF-'.$token,
            'type' => $type,
            'amount' => '100.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPurchase(int $supplierId): int
    {
        return (int) DB::table('purchases')->insertGetId([
            'code' => 'SCHEMA-P-'.Str::uuid(),
            'supplier_id' => $supplierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertInvoice(int $customerId): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'code' => 'SCHEMA-I-'.Str::uuid(),
            'customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCustomer(): int
    {
        $token = (string) Str::uuid();

        return (int) DB::table('customers')->insertGetId([
            'code' => 'SCHEMA-C-'.$token,
            'name' => 'Schema Test Partner',
            'created_at' => now(),
            'updated_at' => now(),
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
            'operation_type' => 'allocation_schema_test',
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
