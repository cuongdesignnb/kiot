<?php

namespace Tests\Feature\Migrations;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerDebtOpeningIncidentSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const OPENINGS = 'partner_debt_opening_balances';

    private const INCIDENTS = 'partner_debt_integrity_incidents';

    private const EVENTS = 'partner_debt_integrity_incident_events';

    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('PR C schema tests require MySQL or MariaDB.');
        }
    }

    public function test_opening_column_generated_index_foreign_key_and_check_metadata(): void
    {
        $this->assertTableColumns(self::OPENINGS, [
            'id' => ['bigint unsigned', false, null],
            'partner_id' => ['bigint unsigned', false, null],
            'role' => ['varchar(16)', false, null],
            'version' => ['int unsigned', false, null],
            'cutoff_at' => ['datetime(6)', false, null],
            'business_timezone' => ['varchar(64)', false, 'Asia/Ho_Chi_Minh'],
            'amount' => ['decimal(15,2)', false, null],
            'source_document_uri' => ['varchar(500)', false, null],
            'source_checksum' => ['char(64)', false, null],
            'status' => ['varchar(24)', false, 'draft'],
            'active_guard' => ['tinyint unsigned', true, null],
            'created_by' => ['bigint unsigned', true, null],
            'approved_by' => ['bigint unsigned', true, null],
            'activated_by' => ['bigint unsigned', true, null],
            'reversed_by' => ['bigint unsigned', true, null],
            'approved_at' => ['datetime(6)', true, null],
            'activated_at' => ['datetime(6)', true, null],
            'reversed_at' => ['datetime(6)', true, null],
            'rejected_at' => ['datetime(6)', true, null],
            'approval_operation_id' => ['bigint unsigned', true, null],
            'activation_operation_id' => ['bigint unsigned', true, null],
            'reversal_operation_id' => ['bigint unsigned', true, null],
            'rejection_reason' => ['text', true, null],
            'note' => ['text', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);

        $activeGuard = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->where('TABLE_NAME', self::OPENINGS)
            ->where('COLUMN_NAME', 'active_guard')
            ->first();
        $this->assertNotNull($activeGuard);
        $this->assertStringContainsString('stored', strtolower((string) $activeGuard->EXTRA));
        $this->assertStringContainsString('generated', strtolower((string) $activeGuard->EXTRA));
        $expression = $this->normalizeExpression((string) $activeGuard->GENERATION_EXPRESSION);
        $this->assertStringContainsString('case', $expression);
        $this->assertStringContainsString('status', $expression);
        $this->assertStringContainsString('active', $expression);
        $this->assertStringContainsString('then1', $expression);

        $this->assertIndex(self::OPENINGS, 'pdob_partner_role_cutoff_version_uq', ['partner_id', 'role', 'cutoff_at', 'version'], true);
        $this->assertIndex(self::OPENINGS, 'pdob_partner_role_checksum_uq', ['partner_id', 'role', 'source_checksum'], true);
        $this->assertIndex(self::OPENINGS, 'pdob_partner_role_active_uq', ['partner_id', 'role', 'active_guard'], true);
        $this->assertIndex(self::OPENINGS, 'pdob_status_cutoff_idx', ['status', 'cutoff_at'], false);

        foreach ([
            ['pdob_partner_fk', 'partner_id', 'customers', 'RESTRICT'],
            ['pdob_created_by_fk', 'created_by', 'users', 'SET NULL'],
            ['pdob_approved_by_fk', 'approved_by', 'users', 'SET NULL'],
            ['pdob_activated_by_fk', 'activated_by', 'users', 'SET NULL'],
            ['pdob_reversed_by_fk', 'reversed_by', 'users', 'SET NULL'],
            ['pdob_approval_operation_fk', 'approval_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['pdob_activation_operation_fk', 'activation_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['pdob_reversal_operation_fk', 'reversal_operation_id', 'partner_debt_operations', 'RESTRICT'],
        ] as [$name, $column, $target, $rule]) {
            $this->assertForeignKey(self::OPENINGS, $name, $column, $target, $rule);
        }

        $this->assertChecks(self::OPENINGS, ['pdob_role_chk', 'pdob_status_chk', 'pdob_version_chk']);
    }

    public function test_incident_column_index_foreign_key_and_check_metadata(): void
    {
        $this->assertTableColumns(self::INCIDENTS, [
            'id' => ['bigint unsigned', false, null],
            'partner_id' => ['bigint unsigned', false, null],
            'role' => ['varchar(24)', false, null],
            'status' => ['varchar(24)', false, 'open'],
            'classification' => ['varchar(64)', false, null],
            'severity' => ['varchar(16)', false, null],
            'customer_difference' => ['decimal(15,2)', false, '0.00'],
            'supplier_difference' => ['decimal(15,2)', false, '0.00'],
            'fingerprint' => ['char(64)', false, null],
            'evidence' => ['json', true, null],
            'first_detected_at' => ['datetime(6)', false, null],
            'last_detected_at' => ['datetime(6)', false, null],
            'occurrence_count' => ['int unsigned', false, '1'],
            'last_event_sequence' => ['int unsigned', false, '0'],
            'acknowledged_by' => ['bigint unsigned', true, null],
            'acknowledged_at' => ['datetime(6)', true, null],
            'acknowledgment_note' => ['text', true, null],
            'resolved_by' => ['bigint unsigned', true, null],
            'resolved_at' => ['datetime(6)', true, null],
            'resolution_note' => ['text', true, null],
            'suppressed_by' => ['bigint unsigned', true, null],
            'suppression_reason' => ['text', true, null],
            'suppressed_until' => ['datetime(6)', true, null],
            'baseline_run_id' => ['char(36)', true, null],
            'baseline_cutoff_at' => ['datetime(6)', true, null],
            'baseline_checksum' => ['char(64)', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);

        $this->assertIndex(self::INCIDENTS, 'pdii_partner_role_fingerprint_uq', ['partner_id', 'role', 'fingerprint'], true);
        $this->assertIndex(self::INCIDENTS, 'pdii_status_classification_detected_idx', ['status', 'classification', 'last_detected_at'], false);
        $this->assertIndex(self::INCIDENTS, 'pdii_partner_status_idx', ['partner_id', 'status'], false);
        $this->assertIndex(self::INCIDENTS, 'pdii_status_suppressed_until_idx', ['status', 'suppressed_until'], false);

        foreach ([
            ['pdii_partner_fk', 'partner_id', 'customers', 'RESTRICT'],
            ['pdii_acknowledged_by_fk', 'acknowledged_by', 'users', 'SET NULL'],
            ['pdii_resolved_by_fk', 'resolved_by', 'users', 'SET NULL'],
            ['pdii_suppressed_by_fk', 'suppressed_by', 'users', 'SET NULL'],
        ] as [$name, $column, $target, $rule]) {
            $this->assertForeignKey(self::INCIDENTS, $name, $column, $target, $rule);
        }

        $this->assertChecks(self::INCIDENTS, [
            'pdii_role_chk',
            'pdii_status_chk',
            'pdii_occurrence_chk',
            'pdii_detected_range_chk',
            'pdii_classification_nonempty_chk',
            'pdii_severity_nonempty_chk',
        ]);
    }

    public function test_incident_event_column_index_foreign_key_and_check_metadata(): void
    {
        $this->assertTableColumns(self::EVENTS, [
            'id' => ['bigint unsigned', false, null],
            'incident_id' => ['bigint unsigned', false, null],
            'event_uuid' => ['char(36)', false, null],
            'dedup_key' => ['char(64)', false, null],
            'detection_run_id' => ['char(36)', true, null],
            'source_operation_id' => ['bigint unsigned', true, null],
            'event_sequence' => ['int unsigned', false, null],
            'event_type' => ['varchar(24)', false, null],
            'from_status' => ['varchar(24)', true, null],
            'to_status' => ['varchar(24)', true, null],
            'classification' => ['varchar(64)', false, null],
            'fingerprint' => ['char(64)', false, null],
            'snapshot' => ['json', false, null],
            'actor_id' => ['bigint unsigned', true, null],
            'occurred_at' => ['datetime(6)', false, null],
            'metadata' => ['json', true, null],
            'created_at' => ['timestamp(6)', true, null],
            'updated_at' => ['timestamp(6)', true, null],
        ]);

        $this->assertIndex(self::EVENTS, 'pdiie_event_uuid_uq', ['event_uuid'], true);
        $this->assertIndex(self::EVENTS, 'pdiie_dedup_key_uq', ['dedup_key'], true);
        $this->assertIndex(self::EVENTS, 'pdiie_incident_sequence_uq', ['incident_id', 'event_sequence'], true);
        $this->assertIndex(self::EVENTS, 'pdiie_detection_run_incident_idx', ['detection_run_id', 'incident_id'], false);
        $this->assertIndex(self::EVENTS, 'pdiie_incident_occurred_idx', ['incident_id', 'occurred_at', 'id'], false);
        $this->assertIndex(self::EVENTS, 'pdiie_event_type_occurred_idx', ['event_type', 'occurred_at'], false);

        foreach ([
            ['pdiie_incident_fk', 'incident_id', self::INCIDENTS, 'RESTRICT'],
            ['pdiie_source_operation_fk', 'source_operation_id', 'partner_debt_operations', 'RESTRICT'],
            ['pdiie_actor_fk', 'actor_id', 'users', 'SET NULL'],
        ] as [$name, $column, $target, $rule]) {
            $this->assertForeignKey(self::EVENTS, $name, $column, $target, $rule);
        }

        $this->assertChecks(self::EVENTS, [
            'pdiie_event_sequence_chk',
            'pdiie_event_type_chk',
            'pdiie_from_status_chk',
            'pdiie_to_status_chk',
            'pdiie_detection_run_chk',
            'pdiie_classification_nonempty_chk',
        ]);
    }

    public function test_all_pr_c_constraint_and_index_identifiers_fit_mysql_limit(): void
    {
        $tables = [self::OPENINGS, self::INCIDENTS, self::EVENTS];
        $constraintNames = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', $tables)
            ->pluck('CONSTRAINT_NAME');
        $indexNames = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->databaseName())
            ->whereIn('TABLE_NAME', $tables)
            ->pluck('INDEX_NAME');

        foreach ($constraintNames->merge($indexNames)->unique() as $identifier) {
            $this->assertLessThanOrEqual(64, strlen((string) $identifier), "Identifier {$identifier} exceeds 64 characters.");
        }
    }

    public function test_openings_accept_signed_amounts_and_enforce_generated_active_guard(): void
    {
        $partnerId = $this->insertCustomer();

        foreach (['125.50', '-125.50', '0.00'] as $amount) {
            $id = $this->insertOpening($partnerId, ['amount' => $amount]);
            $this->assertSame($amount, number_format((float) DB::table(self::OPENINGS)->find($id)->amount, 2, '.', ''));
        }

        $draftId = $this->insertOpening($partnerId, ['status' => 'draft']);
        $approvedId = $this->insertOpening($partnerId, ['status' => 'approved']);
        $activeId = $this->insertOpening($partnerId, ['status' => 'active']);
        $supplierActiveId = $this->insertOpening($partnerId, ['role' => 'supplier', 'status' => 'active']);

        $this->assertNull(DB::table(self::OPENINGS)->find($draftId)->active_guard);
        $this->assertNull(DB::table(self::OPENINGS)->find($approvedId)->active_guard);
        $this->assertSame(1, (int) DB::table(self::OPENINGS)->find($activeId)->active_guard);
        $this->assertSame(1, (int) DB::table(self::OPENINGS)->find($supplierActiveId)->active_guard);

        $this->assertQueryRejected(fn () => $this->insertOpening($partnerId, ['status' => 'active']));

        DB::table(self::OPENINGS)->where('id', $activeId)->update(['status' => 'reversed']);
        $this->assertNull(DB::table(self::OPENINGS)->find($activeId)->active_guard);
        $replacementId = $this->insertOpening($partnerId, ['status' => 'active']);
        $this->assertSame(1, (int) DB::table(self::OPENINGS)->find($replacementId)->active_guard);
    }

    public function test_opening_checks_and_business_uniques_are_enforced(): void
    {
        $partnerId = $this->insertCustomer();

        $this->assertCheckRejected(fn () => $this->insertOpening($partnerId, ['role' => 'both']), 'pdob_role_chk');
        $this->assertCheckRejected(fn () => $this->insertOpening($partnerId, ['status' => 'pending']), 'pdob_status_chk');
        $this->assertCheckRejected(fn () => $this->insertOpening($partnerId, ['version' => 0]), 'pdob_version_chk');

        $cutoff = $this->nextTimestamp();
        $checksum = hash('sha256', (string) Str::uuid());
        $this->insertOpening($partnerId, [
            'cutoff_at' => $cutoff,
            'version' => 7,
            'source_checksum' => $checksum,
        ]);
        $this->assertQueryRejected(fn () => $this->insertOpening($partnerId, [
            'cutoff_at' => $cutoff,
            'version' => 7,
        ]));
        $this->assertQueryRejected(fn () => $this->insertOpening($partnerId, [
            'version' => 8,
            'source_checksum' => $checksum,
        ]));
    }

    public function test_opening_foreign_key_delete_rules_are_enforced(): void
    {
        $partnerId = $this->insertCustomer();
        $actorId = $this->insertUser();
        $approvalOperationId = $this->insertOperation();
        $activationOperationId = $this->insertOperation();
        $reversalOperationId = $this->insertOperation();
        $openingId = $this->insertOpening($partnerId, [
            'created_by' => $actorId,
            'approved_by' => $actorId,
            'activated_by' => $actorId,
            'reversed_by' => $actorId,
            'approval_operation_id' => $approvalOperationId,
            'activation_operation_id' => $activationOperationId,
            'reversal_operation_id' => $reversalOperationId,
        ]);

        $this->assertQueryRejected(fn () => DB::table('customers')->where('id', $partnerId)->delete());
        foreach ([$approvalOperationId, $activationOperationId, $reversalOperationId] as $operationId) {
            $this->assertQueryRejected(fn () => DB::table('partner_debt_operations')->where('id', $operationId)->delete());
        }

        DB::table('users')->where('id', $actorId)->delete();
        $opening = DB::table(self::OPENINGS)->find($openingId);
        $this->assertNull($opening->created_by);
        $this->assertNull($opening->approved_by);
        $this->assertNull($opening->activated_by);
        $this->assertNull($opening->reversed_by);
    }

    public function test_incident_checks_uniqueness_and_json_are_enforced(): void
    {
        $partnerId = $this->insertCustomer();

        $validId = $this->insertIncident($partnerId, ['evidence' => json_encode(['evidence_ids' => ['safe-id']])]);
        $this->assertNotNull(DB::table(self::INCIDENTS)->find($validId));
        $this->assertQueryRejected(fn () => $this->insertIncident($partnerId, ['evidence' => '{invalid-json']));

        $this->assertCheckRejected(fn () => $this->insertIncident($partnerId, ['role' => 'customer']), 'pdii_role_chk');
        $this->assertCheckRejected(fn () => $this->insertIncident($partnerId, ['status' => 'closed']), 'pdii_status_chk');
        $this->assertCheckRejected(fn () => $this->insertIncident($partnerId, ['occurrence_count' => 0]), 'pdii_occurrence_chk');
        $this->assertCheckRejected(fn () => $this->insertIncident($partnerId, [
            'first_detected_at' => '2031-01-02 00:00:00.000000',
            'last_detected_at' => '2031-01-01 00:00:00.000000',
        ]), 'pdii_detected_range_chk');
        foreach (['', '   '] as $classification) {
            $this->assertCheckRejected(
                fn () => $this->insertIncident($partnerId, ['classification' => $classification]),
                'pdii_classification_nonempty_chk'
            );
        }
        foreach (['', '   '] as $severity) {
            $this->assertCheckRejected(
                fn () => $this->insertIncident($partnerId, ['severity' => $severity]),
                'pdii_severity_nonempty_chk'
            );
        }

        $fingerprint = hash('sha256', 'stable-fingerprint-'.Str::uuid());
        $this->insertIncident($partnerId, ['fingerprint' => $fingerprint]);
        $this->assertQueryRejected(fn () => $this->insertIncident($partnerId, ['fingerprint' => $fingerprint]));
        $this->assertNotNull($this->insertIncident($partnerId));
        $this->assertNotNull($this->insertIncident($partnerId, [
            'role' => 'supplier_only',
            'fingerprint' => $fingerprint,
        ]));
    }

    public function test_incident_foreign_key_delete_rules_are_enforced(): void
    {
        $partnerId = $this->insertCustomer();
        $actorId = $this->insertUser();
        $incidentId = $this->insertIncident($partnerId, [
            'acknowledged_by' => $actorId,
            'resolved_by' => $actorId,
            'suppressed_by' => $actorId,
        ]);

        $this->assertQueryRejected(fn () => DB::table('customers')->where('id', $partnerId)->delete());
        DB::table('users')->where('id', $actorId)->delete();

        $incident = DB::table(self::INCIDENTS)->find($incidentId);
        $this->assertNull($incident->acknowledged_by);
        $this->assertNull($incident->resolved_by);
        $this->assertNull($incident->suppressed_by);
    }

    public function test_event_checks_detection_run_and_json_are_enforced(): void
    {
        $incidentId = $this->insertIncident($this->insertCustomer());

        $validId = $this->insertEvent($incidentId, [
            'snapshot' => json_encode(['difference' => '10.00']),
            'metadata' => json_encode(['source' => 'schema-test']),
        ]);
        $this->assertNotNull(DB::table(self::EVENTS)->find($validId));

        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'snapshot' => '{invalid-json',
        ]));
        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'metadata' => '{invalid-json',
        ]));
        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'snapshot' => null,
        ]));

        $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 0]), 'pdiie_event_sequence_chk');
        $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 2, 'event_type' => 'ignored']), 'pdiie_event_type_chk');
        $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 2, 'from_status' => 'closed']), 'pdiie_from_status_chk');
        $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 2, 'to_status' => 'closed']), 'pdiie_to_status_chk');
        foreach (['detected', 'redetected', 'reopened'] as $eventType) {
            $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, [
                'event_sequence' => 2,
                'event_type' => $eventType,
                'detection_run_id' => null,
            ]), 'pdiie_detection_run_chk');
        }
        $this->assertCheckRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 2, 'classification' => '   ']), 'pdiie_classification_nonempty_chk');

        $adminEventId = $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'event_type' => 'acknowledged',
            'detection_run_id' => null,
            'from_status' => 'open',
            'to_status' => 'acknowledged',
        ]);
        $this->assertNotNull(DB::table(self::EVENTS)->find($adminEventId));
    }

    public function test_event_dedup_sequence_and_state_boundaries_are_enforced(): void
    {
        $incidentId = $this->insertIncident($this->insertCustomer());
        $eventUuid = (string) Str::uuid();
        $dedupKey = hash('sha256', 'dedup-'.Str::uuid());
        $eventId = $this->insertEvent($incidentId, [
            'event_uuid' => $eventUuid,
            'dedup_key' => $dedupKey,
        ]);
        $this->assertNotNull(DB::table(self::EVENTS)->find($eventId));

        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'event_uuid' => $eventUuid,
        ]));
        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, [
            'event_sequence' => 2,
            'dedup_key' => $dedupKey,
        ]));
        $this->assertQueryRejected(fn () => $this->insertEvent($incidentId, ['event_sequence' => 1]));

        $gapEventId = $this->insertEvent($incidentId, ['event_sequence' => 3]);
        $this->assertNotNull(DB::table(self::EVENTS)->find($gapEventId));

        $incident = DB::table(self::INCIDENTS)->find($incidentId);
        $this->assertSame('open', $incident->status);
        $this->assertSame(0, (int) $incident->last_event_sequence);
        $this->assertSame(2, DB::table(self::EVENTS)->where('incident_id', $incidentId)->count());
    }

    public function test_event_foreign_key_delete_rules_are_enforced(): void
    {
        $incidentId = $this->insertIncident($this->insertCustomer());
        $operationId = $this->insertOperation();
        $actorId = $this->insertUser();
        $eventId = $this->insertEvent($incidentId, [
            'source_operation_id' => $operationId,
            'actor_id' => $actorId,
        ]);

        $this->assertQueryRejected(fn () => DB::table(self::INCIDENTS)->where('id', $incidentId)->delete());
        $this->assertQueryRejected(fn () => DB::table('partner_debt_operations')->where('id', $operationId)->delete());
        DB::table('users')->where('id', $actorId)->delete();
        $this->assertNull(DB::table(self::EVENTS)->find($eventId)->actor_id);
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
            $actualType = $this->normalizeColumnType((string) $row->COLUMN_TYPE);
            if ($type === 'json' && DB::connection()->getDriverName() === 'mariadb') {
                $this->assertContains($actualType, ['json', 'longtext']);
            } else {
                $this->assertSame($type, $actualType);
            }
            $this->assertSame($nullable ? 'YES' : 'NO', $row->IS_NULLABLE);
            $this->assertSame($default, $this->normalizeDefault($row->COLUMN_DEFAULT));
        }

        $id = $rows->get('id');
        $this->assertStringContainsString('auto_increment', strtolower((string) $id->EXTRA));
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
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    private function normalizeExpression(string $expression): string
    {
        return strtolower((string) preg_replace('/[`_\\\'"\s()]/', '', $expression));
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

    /** @param list<string> $checks */
    private function assertChecks(string $table, array $checks): void
    {
        foreach ($checks as $constraint) {
            $row = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $this->databaseName())
                ->where('TABLE_NAME', $table)
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

    /** @param array<string, mixed> $overrides */
    private function insertOpening(int $partnerId, array $overrides = []): int
    {
        $token = (string) Str::uuid();
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::OPENINGS)->insertGetId(array_merge([
            'partner_id' => $partnerId,
            'role' => 'customer',
            'version' => 1,
            'cutoff_at' => $this->nextTimestamp(),
            'business_timezone' => 'Asia/Ho_Chi_Minh',
            'amount' => '100.00',
            'source_document_uri' => "urn:debt-opening:{$token}",
            'source_checksum' => hash('sha256', $token),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertIncident(int $partnerId, array $overrides = []): int
    {
        $token = (string) Str::uuid();
        $detectedAt = $this->nextTimestamp();
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::INCIDENTS)->insertGetId(array_merge([
            'partner_id' => $partnerId,
            'role' => 'customer_only',
            'status' => 'open',
            'classification' => 'material_difference',
            'severity' => 'high',
            'customer_difference' => '10.00',
            'supplier_difference' => '0.00',
            'fingerprint' => hash('sha256', $token),
            'evidence' => null,
            'first_detected_at' => $detectedAt,
            'last_detected_at' => $detectedAt,
            'occurrence_count' => 1,
            'last_event_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertEvent(int $incidentId, array $overrides = []): int
    {
        $token = (string) Str::uuid();
        $now = now()->format('Y-m-d H:i:s.u');

        return (int) DB::table(self::EVENTS)->insertGetId(array_merge([
            'incident_id' => $incidentId,
            'event_uuid' => $token,
            'dedup_key' => hash('sha256', 'dedup-'.$token),
            'detection_run_id' => (string) Str::uuid(),
            'source_operation_id' => null,
            'event_sequence' => 1,
            'event_type' => 'detected',
            'from_status' => null,
            'to_status' => 'open',
            'classification' => 'material_difference',
            'fingerprint' => hash('sha256', 'fingerprint-'.$token),
            'snapshot' => json_encode(['version' => 1]),
            'actor_id' => null,
            'occurred_at' => $this->nextTimestamp(),
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
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
            'operation_type' => 'opening_incident_schema_test',
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

    private function nextTimestamp(): string
    {
        $this->fixtureSequence++;

        return sprintf('2031-01-01 00:00:%02d.%06d', intdiv($this->fixtureSequence, 1000000), $this->fixtureSequence % 1000000);
    }

    private function databaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
