# PR C Opening Balance and Incident Migration Validation

## Decision

```text
VALIDATION_DATE=2026-07-16
P0_BLOCKERS=0
P1_BLOCKERS=0
READY_FOR_PR_C_SENIOR_REVIEW=yes
READY_TO_MARK_PR_C=no
READY_TO_MERGE_PR_C=no
READY_FOR_PR_C_PRODUCTION_PREFLIGHT=no
READY_FOR_OPENING_APPLICATION_PR=no
READY_FOR_INCIDENT_APPLICATION_PR=no
READY_FOR_BASELINE_PERSISTENCE=no
READY_FOR_SCHEDULE_ENABLEMENT=no
READY_FOR_WRITE_PATH_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

PR C is schema-only. It does not activate an opening balance, persist an
incident baseline, schedule monitoring, enable a debt write path, or change
current debt data.

## Git Scope

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=feat/debt-integrity-pr-c-opening-incidents-schema
BASE_BRANCH=production-customer-group
BASE_SHA=d1c5e7c775275cf290a6499674b861edf1e00106
HEAD_BEFORE=d1c5e7c775275cf290a6499674b861edf1e00106
IMPLEMENTATION_COMMIT=cfe5c63b01298944598976338be931cb80c58309
```

Implementation files:

```text
database/migrations/2026_07_16_100000_create_partner_debt_opening_balances_table.php
database/migrations/2026_07_16_100100_create_partner_debt_integrity_incidents_table.php
database/migrations/2026_07_16_100200_create_partner_debt_integrity_incident_events_table.php
tests/Feature/Migrations/PartnerDebtOpeningIncidentSchemaTest.php
docs/debt/PR-C-OPENING-INCIDENT-MIGRATION-VALIDATION.md
```

No model, service, controller, API, command, job, worker, scheduler, feature
flag, frontend file, legacy migration, PR A migration, or PR B migration was
changed.

## Discovery and Contracts

Repository-wide discovery on the exact base found no existing or
near-equivalent table, migration, model, or class for:

```text
partner_debt_opening_balances
partner_debt_integrity_incidents
partner_debt_integrity_incident_events
PartnerDebtOpeningBalance
PartnerDebtIntegrityIncident
IntegrityIncidentEvent
```

Existing parent key metadata was read before implementation:

| Parent key | Type | Null | Key/extra |
|---|---|---|---|
| `customers.id` | `BIGINT UNSIGNED` | no | primary, auto increment |
| `users.id` | `BIGINT UNSIGNED` | no | primary, auto increment |
| `partner_debt_operations.id` | `BIGINT UNSIGNED` | no | primary, auto increment |

The current checker roles are `customer_only`, `supplier_only`, and
`dual_role`. Only `DRIFT_DETECTED` is a material incident candidate;
`OK`, `INSUFFICIENT_EVIDENCE`, `TECHNICAL_WARNING`, and `CHECK_ERROR` do not
become incident rows in this PR. The checker was not modified.

Business timezone discovery:

```text
BUSINESS_TIMEZONE_SOURCE=config/app.php and APP_TIMEZONE in .env.example
BUSINESS_TIMEZONE_APPLICATION_DEFAULT=Asia/Ho_Chi_Minh
BUSINESS_TIMEZONE_DB_DEFAULT=Asia/Ho_Chi_Minh
BUSINESS_TIMEZONE_CONFLICT=no
```

The database default is deterministic and does not depend on the deployment
environment. A later application service must explicitly persist
`config('app.timezone')`.

## Migration Inventory

### Opening balances

`partner_debt_opening_balances` contains the approved design columns for:

```text
partner and customer/supplier role
version and DATETIME(6) cutoff
business timezone
signed DECIMAL(15,2) amount
source URI and SHA-256 checksum
draft/rejected/approved/active/reversed/void lifecycle
nullable actor and DATETIME(6) lifecycle evidence
nullable approval/activation/reversal operation references
notes and TIMESTAMP(6) audit timestamps
```

Database controls:

```text
UNIQUE pdob_partner_role_cutoff_version_uq
UNIQUE pdob_partner_role_checksum_uq
UNIQUE pdob_partner_role_active_uq
INDEX  pdob_status_cutoff_idx

CHECK pdob_role_chk
CHECK pdob_status_chk
CHECK pdob_version_chk

FK pdob_partner_fk -> customers.id ON DELETE RESTRICT
FK actor columns -> users.id ON DELETE SET NULL
FK operation columns -> partner_debt_operations.id ON DELETE RESTRICT
```

`active_guard` is a nullable `TINYINT UNSIGNED STORED GENERATED` column:

```sql
CASE WHEN status = 'active' THEN 1 ELSE NULL END
```

The unique key is `(partner_id, role, active_guard)`. Functional probes on
both database families proved:

```text
draft and approved derive NULL
active derives 1
multiple non-active rows are accepted
the first active row is accepted
a second active row for the same partner/role is rejected
active customer and supplier rows for the same partner are accepted
active -> reversed releases the guard
a replacement active row is then accepted
```

Positive, negative, and zero amounts were accepted. There is intentionally no
positive-only amount constraint.

### Incident current state

`partner_debt_integrity_incidents` contains the approved current-state
columns, including signed customer/supplier differences, PII-safe nullable
JSON evidence, first/last detection timestamps, occurrence/event counters,
nullable lifecycle actors and notes, suppression fields, and nullable
baseline identifiers.

Database controls:

```text
UNIQUE pdii_partner_role_fingerprint_uq
INDEX  pdii_status_classification_detected_idx
INDEX  pdii_partner_status_idx
INDEX  pdii_status_suppressed_until_idx

CHECK pdii_role_chk
CHECK pdii_status_chk
CHECK pdii_occurrence_chk
CHECK pdii_detected_range_chk
CHECK pdii_classification_nonempty_chk
CHECK pdii_severity_nonempty_chk

FK pdii_partner_fk -> customers.id ON DELETE RESTRICT
FK lifecycle actors -> users.id ON DELETE SET NULL
```

Functional probes verified valid/invalid JSON, role/status/occurrence/date and
non-empty checks, the fingerprint unique boundary, same fingerprint with a
different role, partner delete restriction, and actor nulling.

### Immutable incident event evidence

`partner_debt_integrity_incident_events` contains immutable-event evidence
columns for UUID, dedup key, optional detection run and operation, per-incident
sequence, event/status transition, classification/fingerprint, required JSON
snapshot, optional actor/metadata, occurrence time, and audit timestamps.

Database controls:

```text
UNIQUE pdiie_event_uuid_uq
UNIQUE pdiie_dedup_key_uq
UNIQUE pdiie_incident_sequence_uq
INDEX  pdiie_detection_run_incident_idx
INDEX  pdiie_incident_occurred_idx
INDEX  pdiie_event_type_occurred_idx

CHECK pdiie_event_sequence_chk
CHECK pdiie_event_type_chk
CHECK pdiie_from_status_chk
CHECK pdiie_to_status_chk
CHECK pdiie_detection_run_chk
CHECK pdiie_classification_nonempty_chk

FK pdiie_incident_fk -> partner_debt_integrity_incidents.id ON DELETE RESTRICT
FK pdiie_source_operation_fk -> partner_debt_operations.id ON DELETE RESTRICT
FK pdiie_actor_fk -> users.id ON DELETE SET NULL
```

Functional probes verified UUID/dedup/incident-sequence rejection, valid and
invalid JSON, required snapshot, event/status checks, and the detection run
requirement for `detected`, `redetected`, and `reopened`. An administrative
`acknowledged` event without a detection run is accepted. A sequence gap is
left to the later application lock/transaction guard, and inserting an event
does not mutate incident current state.

Every PR C index and constraint identifier is at most 64 characters.

## MySQL 8.0.44 Fresh Proof

```text
MYSQL_VERSION=8.0.44
DATABASE=local disposable Docker database
CHARACTER_SET=utf8mb4
COLLATION=utf8mb4_unicode_ci
MYSQL_FRESH_MIGRATE=PASS
MYSQL_PR_C_SCHEMA_TESTS=PASS (12 tests, 606 assertions)
MYSQL_GENERATED_GUARD_PROBES=PASS
MYSQL_CHECK_PROBES=PASS
MYSQL_PR_C_ROLLBACK=PASS (three PR C tables removed)
MYSQL_PR_A_B_RETAINED_AFTER_ROLLBACK=yes (six tables retained)
MYSQL_PR_C_REMIGRATE=PASS
MYSQL_SECOND_MIGRATE_NOOP=PASS (Nothing to migrate)
MYSQL_NEW_TABLE_INITIAL_ROWS=0/0/0
```

Combined MySQL schema regression:

```text
PR_A=PASS (12 tests, 441 assertions)
PR_B=PASS (10 tests, 372 assertions)
PR_C=PASS (12 tests, 606 assertions)
TOTAL=34 tests, 1419 assertions
```

## Production-like MySQL Clone Proof

Source clone: `kiot_pr_b_clone_step29`.

Validation clone: `kiot_pr_c_clone_step31`.

Both are local Docker databases. No production database was accessed. Hashes
were captured after PR C migrate and before fixture-based schema tests so
test-only auto-increment advancement could not be misclassified as migration
mutation.

Group hash results:

| Group | Schema SHA-256 source/clone | Data SHA-256 source/clone | Verdict |
|---|---|---|---|
| Legacy | `2ba80471f2700098d5c40bb41ba291c302f2757361625fc3cfec2b5ca859a689` | `a8429467b7335a7da1369a2f3725cc6b1318a0e3317213c6eebe0172295f42c4` | unchanged |
| PR A | `94bc80c216658b85bcdd1ee4653941ac65dd7ef993db861b52fa7972b19e13ff` | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` | unchanged |
| PR B | `3fbe77bff67b39aa622e60f975b310fbe242e8aa29a08df5ba3f84e4571386e2` | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` | unchanged |

Per-table counts:

| Table | Source | Clone |
|---|---:|---:|
| `customers` | 322 | 322 |
| `users` | 6 | 6 |
| `cash_flows` | 745 | 745 |
| `invoices` | 381 | 381 |
| `purchases` | 428 | 428 |
| `customer_debts` | 83 | 83 |
| `supplier_debt_transactions` | 233 | 233 |
| `customer_payment_allocations` | 3 | 3 |
| `debt_offsets` | 1 | 1 |
| `partner_debt_operations` | 0 | 0 |
| `partner_debt_operation_participants` | 0 | 0 |
| `partner_debt_outbox_events` | 0 | 0 |
| `supplier_payment_allocations` | 0 | 0 |
| `supplier_payment_allocation_reversals` | 0 | 0 |
| `customer_payment_allocation_reversals` | 0 | 0 |

Results:

```text
MYSQL_CLONE_MIGRATE=PASS
MYSQL_CLONE_PR_C_SCHEMA_TESTS=PASS (12 tests, 606 assertions)
MYSQL_CLONE_LEGACY_HASHES_UNCHANGED=yes
MYSQL_CLONE_PR_A_HASHES_UNCHANGED=yes
MYSQL_CLONE_PR_B_HASHES_UNCHANGED=yes
MYSQL_CLONE_NEW_TABLE_ROWS=0/0/0
MYSQL_CLONE_ROLLBACK=PASS
MYSQL_CLONE_PR_A_B_RETAINED_AFTER_ROLLBACK=yes
MYSQL_CLONE_REMIGRATE=PASS
```

## MariaDB 10.11.10 Proof

```text
MARIADB_VERSION=10.11.10-MariaDB-ubu2204
LARAVEL_CONNECTION_DRIVER=mariadb
CHECK_CONSTRAINT_CHECKS=1
CHARACTER_SET=utf8mb4
COLLATION=utf8mb4_unicode_ci
DATABASE=local disposable Docker database
MARIADB_FRESH_MIGRATE=PASS
MARIADB_PR_C_SCHEMA_TESTS=PASS (12 tests, 597 assertions)
MARIADB_GENERATED_GUARD_PROBES=PASS
MARIADB_CHECK_PROBES=PASS
MARIADB_PR_C_ROLLBACK=PASS (three PR C tables removed)
MARIADB_PR_A_B_RETAINED_AFTER_ROLLBACK=yes (six tables retained)
MARIADB_PR_C_REMIGRATE=PASS
MARIADB_SECOND_MIGRATE_NOOP=PASS (Nothing to migrate)
MARIADB_NEW_TABLE_INITIAL_ROWS=0/0/0
```

Combined MariaDB PR B + PR C schema regression passed 22 tests with 963
assertions. The lower PR C assertion count is expected because MariaDB does
not expose MySQL's `ENFORCED` metadata column; named CHECK discovery and all
functional invalid probes passed. The raw CHECK setup explicitly accepts both
Laravel driver names `mysql` and `mariadb`.

One initial combined regression attempt was interrupted when the local
MariaDB container performed a normal shutdown (`exit 0`, not OOM). The
container was restarted, database state was verified, and the complete PR B +
PR C suite then passed. This was a local infrastructure interruption, not a
test or migration failure.

## Regression and Repository Validation

```text
PHP_LINT=PASS (three migrations and PR C schema test)
PINT=PASS (four PHP files)
PR_A_SCHEMA_REGRESSION=PASS (12 tests, 441 assertions, MySQL)
PR_B_SCHEMA_REGRESSION=PASS (MySQL and MariaDB)
PHASE1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
GIT_DIFF_CHECK=PASS
FORBIDDEN_FILE_SCAN=PASS
FRONTEND_BUILD_REQUIRED=no
```

The first Phase 1 invocation used `.env.example`, whose `APP_KEY` is empty,
and three HTTP report tests raised `MissingAppKeyException`. The complete
suite was rerun with a test-only `APP_KEY` supplied through the process
environment; all 73 tests passed. No `.env` value or secret was committed.

The local PHP binary emits pre-existing startup warnings for unavailable
Oracle and Firebird extensions. Required lint, migration, and test commands
exited successfully; those warnings are unrelated to PR C.

## Data and DDL Safety

```text
LEGACY_TABLES_ALTERED=no
PR_A_TABLES_ALTERED=no
PR_B_TABLES_ALTERED=no
LEGACY_DATA_MUTATED=no
PR_A_DATA_MUTATED=no
PR_B_DATA_MUTATED=no
BACKFILL=no
SEED=no
OPENING_IMPORT=no
VIRTUAL_OPENING_PROMOTION=no
INCIDENT_BASELINE_PERSISTED=no
SCHEDULE_ENABLED=no
CURRENT_DEBT_DATA_CHANGED=no
PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
```

Each migration wraps its own table creation and raw CHECK setup. If generated
column or CHECK setup fails, only that migration's newly created table is
dropped and the error is rethrown. Each `down()` drops only its own table.
Dependency order is opening, incident, event; rollback order is event,
incident, opening.

Future production DDL risk is limited to three additive empty-table creates,
their indexes/CHECK constraints, and FK validation/metadata locks against
`customers`, `users`, `partner_debt_operations`, and the incident parent.
Production preflight, backup, maintenance timing, and deployment approval are
still required; this report does not authorize them.

## Deferred Application Work

The following are deliberately not implemented or enabled:

```text
opening create/approve/activate/reverse workflow
self-approval prevention
checksum normalization and source-document verification
opening immutability and calculation participation
partner row locking and operation/outbox integration
incident fingerprint registry/service
incident acknowledge/resolve/suppress/reopen state service
append-only event enforcement
contiguous event sequence locking
baseline persistence
checker optimization or integration
monitoring schedule
retention/archive policy
write-path rollout
current data correction
```

These controls require later application PRs with authorization, row locks,
idempotency, and one outer transaction. Schema existence alone does not make
the application ready for any of them.

## Final Gate Matrix

```text
DUPLICATE_DISCOVERY=PASS
NEAR_EQUIVALENT_SCHEMA_FOUND=no
BUSINESS_TIMEZONE_CONFLICT=no

OPENING_COLUMNS_VERDICT=PASS
OPENING_INDEXES_VERDICT=PASS
OPENING_FKS_VERDICT=PASS
OPENING_CHECKS_VERDICT=PASS
OPENING_GENERATED_GUARD_VERDICT=PASS
OPENING_SECOND_ACTIVE_REJECTED=yes
OPENING_SIGNED_AMOUNT_VERDICT=PASS

INCIDENT_COLUMNS_VERDICT=PASS
INCIDENT_INDEXES_VERDICT=PASS
INCIDENT_FKS_VERDICT=PASS
INCIDENT_CHECKS_VERDICT=PASS
INCIDENT_JSON_VERDICT=PASS

INCIDENT_EVENT_COLUMNS_VERDICT=PASS
INCIDENT_EVENT_INDEXES_VERDICT=PASS
INCIDENT_EVENT_FKS_VERDICT=PASS
INCIDENT_EVENT_CHECKS_VERDICT=PASS
INCIDENT_EVENT_JSON_VERDICT=PASS
INCIDENT_EVENT_DEDUP_VERDICT=PASS
INCIDENT_EVENT_SEQUENCE_VERDICT=PASS
DETECTION_RUN_REQUIREMENT_VERDICT=PASS

P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=incident index selectivity; event retention/archive volume; application fingerprint registry; checksum normalization; incident batch performance; schedule cadence; suppression expiry; lifecycle services
FIXES_APPLIED=expanded detection-run functional probes; supplied test-only APP_KEY for HTTP regression; no schema correction required

PR_C_DRAFT=required
PR_C_MERGED=no
READY_FOR_PR_C_SENIOR_REVIEW=yes
READY_TO_MARK_PR_C=no
READY_TO_MERGE_PR_C=no
READY_FOR_PR_C_PRODUCTION_PREFLIGHT=no
READY_FOR_OPENING_APPLICATION_PR=no
READY_FOR_INCIDENT_APPLICATION_PR=no
READY_FOR_BASELINE_PERSISTENCE=no
READY_FOR_SCHEDULE_ENABLEMENT=no
READY_FOR_WRITE_PATH_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```
