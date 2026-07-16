# PR D Debt Offset Hardening Migration Validation

## Decision

`Migration PR D` is ready for senior review as a Draft PR. This validation
authorizes schema review only. It does not authorize production preflight,
deployment, workflow activation, legacy-row migration, application write paths,
or current-debt correction.

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
READY_FOR_PR_D_SENIOR_REVIEW=yes
READY_TO_MARK_PR_D=no
READY_TO_MERGE_PR_D=no
```

## Git And Scope Gate

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=feat/debt-integrity-pr-d-debt-offset-hardening
BASE_SHA=6ed1c198a38a2c8d31e2d67d6cc39e6662485700
HEAD_BEFORE=6ed1c198a38a2c8d31e2d67d6cc39e6662485700
IMPLEMENTATION_COMMIT=4c4e494
EXPECTED_BASE_BRANCH=production-customer-group
INITIAL_WORKTREE=clean
INITIAL_DIFF=empty
```

The exact local head, remote feature head, remote production branch, and merge
base all resolved to the expected base before implementation. No force push,
production access, or unrelated branch integration was performed.

Changed implementation scope:

```text
database/migrations/2026_07_17_000000_add_workflow_evidence_columns_to_debt_offsets.php
database/migrations/2026_07_17_000100_add_workflow_keys_and_foreign_keys_to_debt_offsets.php
database/migrations/2026_07_17_000200_add_workflow_checks_to_debt_offsets.php
tests/Feature/Migrations/DebtOffsetHardeningSchemaTest.php
docs/debt/PR-D-DEBT-OFFSET-HARDENING-MIGRATION-VALIDATION.md
```

No model, service, controller, route, frontend, command, job, worker, scheduler,
feature flag, seed, or legacy migration was changed.

## Duplicate Discovery

Repository and database metadata searches covered every proposed column,
index, FK, CHECK, migration, model, and the complete `debt_offsets` contract.
Matches outside the authoritative Phase 2 design were unrelated fields/tests.

```text
DUPLICATE_DISCOVERY=PASS
NEAR_EQUIVALENT_HARDENING_FOUND=no
DUPLICATE_MIGRATION_CREATED=no
DUPLICATE_MODEL_CREATED=no
```

## Legacy Contract

The unchanged legacy migration is
`database/migrations/2026_04_05_100000_create_debt_offsets_table.php`.
`DebtOffset`, `DebtOffsetService`, and all discovered callers/tests were read but
not edited.

```text
LEGACY_DEBT_OFFSET_ROWS=1 on the production-like local clone
LEGACY_STATUS_VALUES=active,cancelled
CLONE_STATUS_VALUES=active (1 row)
LEGACY_CUSTOMER_FK=debt_offsets_customer_id_foreign
LEGACY_CUSTOMER_FK_DELETE_RULE=CASCADE
LEGACY_CUSTOMER_FK_UNCHANGED=yes
```

The current service still directly changes customer receivable/payable caches,
creates legacy offset/cash-flow/supplier-ledger documents, and marks the original
offset `cancelled` on cancellation. PR D does not claim to make that service
transactionally safe.

## Migration Inventory

### Nullable columns

All 17 fields are nullable and have no non-NULL default:

```text
workflow_status VARCHAR(32)
requested_by BIGINT UNSIGNED
requested_at DATETIME(6)
approved_by BIGINT UNSIGNED
approved_at DATETIME(6)
rejected_by BIGINT UNSIGNED
rejected_at DATETIME(6)
rejection_reason TEXT
applied_at DATETIME(6)
idempotency_key VARCHAR(191)
approval_operation_id BIGINT UNSIGNED
apply_operation_id BIGINT UNSIGNED
reversal_operation_id BIGINT UNSIGNED
customer_amount DECIMAL(15,2)
supplier_amount DECIMAL(15,2)
source_references JSON
reverses_debt_offset_id BIGINT UNSIGNED
```

No workflow status, amount pair, idempotency key, source reference, or operation
reference is generated for a legacy row.

### Keys and foreign keys

```text
UNIQUE do_idempotency_uq(idempotency_key)
UNIQUE do_reverses_uq(reverses_debt_offset_id)

do_requested_by_fk: requested_by -> users.id ON DELETE SET NULL
do_approved_by_fk: approved_by -> users.id ON DELETE SET NULL
do_rejected_by_fk: rejected_by -> users.id ON DELETE SET NULL

do_approval_operation_fk: approval_operation_id -> partner_debt_operations.id ON DELETE RESTRICT
do_apply_operation_fk: apply_operation_id -> partner_debt_operations.id ON DELETE RESTRICT
do_reversal_operation_fk: reversal_operation_id -> partner_debt_operations.id ON DELETE RESTRICT
do_reverses_fk: reverses_debt_offset_id -> debt_offsets.id ON DELETE RESTRICT
```

Explicit supporting indexes are added for actor and operation FKs so rollback can
remove exactly the PR D indexes. No speculative workflow queue index was added.
All PR D identifiers are at most 64 characters.

### Null-compatible checks

```text
do_workflow_status_chk=PASS
do_amount_pair_chk=PASS
do_amount_positive_chk=PASS
do_amount_equal_chk=PASS
do_rejection_reason_chk=PASS
do_idempotency_nonempty_chk=PASS
```

Functional probes reject unknown workflow states, partial amount pairs,
non-positive side amounts, unequal amounts, side amounts unequal to legacy
`amount`, rejected rows without a non-empty reason, and blank idempotency keys.
NULL legacy fields remain valid. JSON accepts NULL and valid JSON and rejects
invalid JSON.

## MySQL 8.0.44 Fresh Proof

Database: isolated local `kiot_pr_d_fresh_step33`, never production.

```text
MYSQL_VERSION=8.0.44
MYSQL_FRESH_MIGRATE=PASS
MYSQL_PR_D_SCHEMA_TESTS=PASS (13 tests, 338 assertions)
MYSQL_LEGACY_OFFSET_COMPATIBILITY=PASS
MYSQL_CHECK_JSON_FK_PROBES=PASS
MYSQL_PR_D_ROLLBACK=PASS
MYSQL_DEBT_OFFSETS_TABLE_RETAINED=yes
MYSQL_PR_A_B_C_RETAINED=yes (9 tables)
MYSQL_PR_D_REMIGRATE=PASS
MYSQL_SECOND_MIGRATE_NOOP=PASS (Nothing to migrate)
```

The fresh lifecycle was migrate from zero, schema tests, rollback exactly three
PR D migrations, verification that `debt_offsets` and PR A/B/C remained,
remigrate, schema tests, then a no-op second migrate.

## MariaDB 10.11.10 Fresh Proof

Database: isolated local `kiot_pr_d_mariadb_fresh_step33`, never production.

```text
MARIADB_VERSION=10.11.10-MariaDB-ubu2204
MARIADB_CONNECTION_DRIVER=mariadb
MARIADB_CHECK_CONSTRAINT_CHECKS=1
MARIADB_FRESH_MIGRATE=PASS
MARIADB_PR_D_SCHEMA_TESTS=PASS (13 tests, 332 assertions)
MARIADB_LEGACY_OFFSET_COMPATIBILITY=PASS
MARIADB_CHECK_JSON_FK_PROBES=PASS
MARIADB_PR_D_ROLLBACK=PASS
MARIADB_DEBT_OFFSETS_TABLE_RETAINED=yes
MARIADB_PR_A_B_C_RETAINED=yes
MARIADB_PR_D_REMIGRATE=PASS
MARIADB_SECOND_MIGRATE_NOOP=PASS
```

MariaDB uses `DROP CONSTRAINT` for named CHECK rollback; MySQL uses `DROP CHECK`.
Metadata assertions do not depend on MySQL-only `ENFORCED` metadata.

## Production-Like Clone Proof

Source clone: `kiot_pr_c_clone_step31`.
Validation clone: `kiot_pr_d_clone_step33`.
Both are local MySQL databases.

Baseline `debt_offsets`:

```text
EXACT_COUNT=1
INFORMATION_SCHEMA_DATA_LENGTH=16384 bytes
INFORMATION_SCHEMA_INDEX_LENGTH=32768 bytes
STATUS_COUNTS=active:1
```

Before fixture tests, source and migrated clone matched as follows:

| Group | Schema SHA-256 match | Data SHA-256 match |
|---|---:|---:|
| Legacy non-offset tables | yes | yes |
| PR A tables | yes | yes |
| PR B tables | yes | yes |
| PR C tables | yes | yes |

Representative hashes:

```text
LEGACY_SCHEMA_SHA256=797347b0b00969d51974729eb2475b817024d9795fa5cb14d3b55b7de62f4506
LEGACY_DATA_SHA256=6414b2bd5d68c8b3245cbbc4566a9731f0c11079a0ded416950504e726cd6ba2
PR_A_SCHEMA_SHA256=1d0dc0d76527e3f6d0965d230c154ce425bb75594ab00527f11e7ee22aba86b5
PR_B_SCHEMA_SHA256=3fbe77bff67b39aa622e60f975b310fbe242e8aa29a08df5ba3f84e4571386e2
PR_C_SCHEMA_SHA256=674bbe263e4e16df6550ccad9b4084b41a48caee7b048206dd1cb79a9ec1865f
```

The deterministic hash over every legacy `debt_offsets` column was unchanged:

```text
SOURCE_LEGACY_ROW_SHA256=d7d3b61705d2af6be3a7784c5d05f9f30092c84187885caf346775748bf6539d
MIGRATED_LEGACY_ROW_SHA256=d7d3b61705d2af6be3a7784c5d05f9f30092c84187885caf346775748bf6539d
LEGACY_ROWS_NEW_COLUMN_NON_NULL_VALUES=0
```

Financial count/sum aggregates were identical before and after migration:

| Table | Rows | Aggregate 1 | Aggregate 2 | Aggregate 3 |
|---|---:|---:|---:|---:|
| customers | 322 | 242865000.00 | 1171998000.00 | 0.00 |
| cash_flows | 745 | 4304295813.00 | 0.00 | 0.00 |
| invoices | 381 | 2654528000.00 | 0.00 | 0.00 |
| purchases | 428 | 2675805272.00 | 1632357861.00 | 1044221400.00 |
| customer_debts | 83 | 233015000.00 | 0.00 | 0.00 |
| supplier_debt_transactions | 233 | 97897850.00 | 0.00 | 0.00 |
| debt_offsets | 1 | 14000000.00 | 0.00 | 0.00 |

The table contains no PII in this report. Aggregate labels correspond to the
table's relevant amount fields, and are evidence of equality rather than a
business reconciliation result.

The clone schema suite passed with 13 tests and 338 assertions. Its transactional
fixtures incremented MySQL's in-memory AUTO_INCREMENT counter even though rows
were rolled back; this is normal MySQL sequence behavior and not a migration
mutation. To avoid conflating that fixture side effect with migration rollback,
the target clone was rebuilt from the source and the migrate/rollback proof was
repeated without fixtures between those two operations.

Exact rollback result:

```text
BASELINE_DEBT_OFFSETS_SCHEMA_SHA256=651294f53ff6f334c2fcb5883563fbeef2cbe7fef8f8db6b824328442ce5e0f6
ROLLED_BACK_DEBT_OFFSETS_SCHEMA_SHA256=651294f53ff6f334c2fcb5883563fbeef2cbe7fef8f8db6b824328442ce5e0f6
BASELINE_DEBT_OFFSETS_DATA_SHA256=8e18447cb2b404846aad3838b08566e5562d902919c314355a45cdeff94b74ed
ROLLED_BACK_DEBT_OFFSETS_DATA_SHA256=8e18447cb2b404846aad3838b08566e5562d902919c314355a45cdeff94b74ed
PR_D_COLUMNS_AFTER_ROLLBACK=0
PR_A_B_C_TABLES_AFTER_ROLLBACK=9
PR_D_MIGRATION_ROWS_AFTER_ROLLBACK=0
```

Remigration then restored all three PR D migration rows. The existing row still
had zero populated PR D fields. A second migrate returned `Nothing to migrate`.

## ALTER And Metadata-Lock Risk

Observed isolated-clone migration timing:

```text
MYSQL_COLUMN_ALTER_MS=486.93 artisan DDL; 2122.97 process wall
MYSQL_KEY_FK_ALTER_MS=approximately 1000 artisan DDL; 1995.80 process wall
MYSQL_CHECK_ALTER_MS=792.08 artisan DDL; 1770.21 process wall

MARIADB_COLUMN_ALTER_MS=238.80
MARIADB_KEY_FK_ALTER_MS=473.03
MARIADB_CHECK_ALTER_MS=301.28
```

No metadata-lock wait was observed on the isolated one-row clone. MySQL 8.0.44
did not accept `EXPLAIN ALTER TABLE` in this environment, and the generated SQL
contains repeated `ALTER TABLE` statements: 17 nullable-column additions,
separate key/FK additions, and six CHECK additions. Therefore PR D does not claim
`ALGORITHM=INSTANT` or `LOCK=NONE` on either engine.

```text
DDL_LOCK_RISK_VERDICT=production preflight required; schedule a controlled maintenance window and inspect blockers/table size before any deployment
```

## Regression Evidence

```text
PHP_LINT=PASS (4 files)
PINT=PASS (4 files)
MYSQL_PR_A_B_C_D_SCHEMA_REGRESSION=PASS (47 tests, 1757 assertions)
MARIADB_PR_B_C_D_SCHEMA_REGRESSION=PASS (35 tests, 1295 assertions)
DEBT_OFFSET_DISCOVERED_REGRESSION=PASS (123 tests, 639 assertions)
PHASE1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
GIT_DIFF_CHECK=PASS
FRONTEND_BUILD_REQUIRED=no
```

The debt-offset discovery set included all 16 test files matching `DebtOffset`,
`debt_offsets`, `manualOffset`, `offsetDebts`, or `cancelOffset`. The focused PR D
suite directly verifies legacy manual, auto, and cancellation paths without any
application-code changes.

Local PHP emits pre-existing startup warnings for unavailable OCI/Firebird
extensions. They are unrelated to this Laravel/MySQL/MariaDB scope and did not
change test outcomes.

## Self-Reversal Limitation

No `CHECK (reverses_debt_offset_id <> id)` is present. MySQL does not permit the
required AUTO_INCREMENT self-reference contract to be enforced safely with the
requested CHECK approach. PR D keeps the self FK with `ON DELETE RESTRICT` and
the unique reversal reference.

Application work must still prevent self-reversal and arbitrary reversal cycles
inside the future locked outer transaction. It must also prove that the original
offset is applied, not already reversed, and eligible for full reversal.

## Deferred Application Invariants

The following are explicitly not implemented by this schema PR:

- requester/approver segregation and permission checks;
- workflow state-machine transitions and approved-record immutability;
- source-reference and amount derivation validation;
- partner/operation/offset row locking in one outer transaction;
- operation, participants, ledgers, cache, cash flow, and outbox atomicity;
- full-only reversal, self/cycle guards, and exactly-once retry semantics;
- approval queue query/index tuning;
- legacy-row workflow migration or current-data correction.

Additional P2 legacy findings remain unchanged: the customer FK still cascades,
the existing service lacks one outer transaction, code generation uses a
`max(id)+1` pattern, and auto-offset policy still requires application design.

## Safety Verdict

```text
LEGACY_TABLES_ALTERED_BEYOND_PR_D=no
LEGACY_DATA_MUTATED=no
BACKFILL=no
WORKFLOW_STATUS_BACKFILLED=no
APPLICATION_CODE_CHANGED=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=0
OUTBOX_CREATED=0
PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
```

Forbidden-file scan is clean: no `.env`, dump, log, `vendor`, `node_modules`,
`public/build`, storage artifact, backup, or PII artifact is part of the change.

## Mandatory Output Summary

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=feat/debt-integrity-pr-d-debt-offset-hardening
BASE_SHA=6ed1c198a38a2c8d31e2d67d6cc39e6662485700
HEAD_BEFORE=6ed1c198a38a2c8d31e2d67d6cc39e6662485700
HEAD_AFTER=see final Draft PR head
WORKTREE_CLEAN=pending report commit

DUPLICATE_DISCOVERY=PASS
NEAR_EQUIVALENT_HARDENING_FOUND=no
LEGACY_DEBT_OFFSET_ROWS=1
LEGACY_STATUS_VALUES=active,cancelled
LEGACY_CUSTOMER_FK_DELETE_RULE=CASCADE

MIGRATION_FILES=3
COLUMN_MIGRATION=PASS
KEY_FK_MIGRATION=PASS
CHECK_MIGRATION=PASS

NEW_COLUMNS_VERDICT=PASS
ALL_NEW_COLUMNS_NULLABLE=yes
LEGACY_ROWS_NEW_COLUMNS_ALL_NULL=yes
LEGACY_ROW_COUNT_UNCHANGED=yes
LEGACY_ROW_HASH_UNCHANGED=yes
LEGACY_STATUS_UNCHANGED=yes
LEGACY_CUSTOMER_FK_UNCHANGED=yes

IDEMPOTENCY_UNIQUE_VERDICT=PASS
REVERSAL_UNIQUE_VERDICT=PASS
ACTOR_FKS_VERDICT=PASS
OPERATION_FKS_VERDICT=PASS
SELF_FK_VERDICT=PASS

WORKFLOW_STATUS_CHECK=PASS
AMOUNT_PAIR_CHECK=PASS
AMOUNT_POSITIVE_CHECK=PASS
AMOUNT_EQUAL_CHECK=PASS
REJECTION_REASON_CHECK=PASS
IDEMPOTENCY_NONEMPTY_CHECK=PASS
JSON_SOURCE_REFERENCES_VERDICT=PASS

SELF_REVERSAL_DB_CHECK=not implemented by design
SELF_REVERSAL_APPLICATION_GUARD_DEFERRED=yes
REVERSAL_CYCLE_GUARD_DEFERRED=yes

MYSQL_VERSION=8.0.44
MYSQL_FRESH_MIGRATE=PASS
MYSQL_PR_D_SCHEMA_TESTS=PASS
MYSQL_LEGACY_OFFSET_COMPATIBILITY=PASS
MYSQL_CHECK_JSON_FK_PROBES=PASS
MYSQL_PR_D_ROLLBACK=PASS
MYSQL_DEBT_OFFSETS_TABLE_RETAINED=yes
MYSQL_PR_A_B_C_RETAINED=yes
MYSQL_PR_D_REMIGRATE=PASS
MYSQL_SECOND_MIGRATE_NOOP=PASS

MYSQL_CLONE_MIGRATE=PASS
MYSQL_CLONE_NON_OFFSET_HASHES_UNCHANGED=yes
MYSQL_CLONE_OFFSET_ROW_COUNT_UNCHANGED=yes
MYSQL_CLONE_OFFSET_LEGACY_HASH_UNCHANGED=yes
MYSQL_CLONE_NEW_COLUMNS_NULL=yes
MYSQL_CLONE_FINANCIAL_AGGREGATES_UNCHANGED=yes
MYSQL_CLONE_ROLLBACK_SCHEMA_EXACT=yes
MYSQL_CLONE_ROLLBACK_DATA_UNCHANGED=yes
MYSQL_CLONE_REMIGRATE=PASS

MARIADB_VERSION=10.11.10
MARIADB_CONNECTION_DRIVER=mariadb
MARIADB_FRESH_MIGRATE=PASS
MARIADB_PR_D_SCHEMA_TESTS=PASS
MARIADB_LEGACY_OFFSET_COMPATIBILITY=PASS
MARIADB_CHECK_JSON_FK_PROBES=PASS
MARIADB_PR_D_ROLLBACK=PASS
MARIADB_DEBT_OFFSETS_TABLE_RETAINED=yes
MARIADB_PR_A_B_C_RETAINED=yes
MARIADB_PR_D_REMIGRATE=PASS
MARIADB_SECOND_MIGRATE_NOOP=PASS

PHP_LINT=PASS
PINT=PASS
PR_A_B_C_SCHEMA_REGRESSION=PASS
DEBT_OFFSET_REGRESSION=PASS
PHASE1_DEBT_REGRESSION=PASS
FRONTEND_BUILD_REQUIRED=no
DIFF_CHECK=PASS
FORBIDDEN_FILES=none

LEGACY_TABLES_ALTERED_BEYOND_PR_D=no
LEGACY_DATA_MUTATED=no
BACKFILL=no
WORKFLOW_STATUS_BACKFILLED=no
APPLICATION_CODE_CHANGED=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=0
OUTBOX_CREATED=0

VALIDATION_REPORT=docs/debt/PR-D-DEBT-OFFSET-HARDENING-MIGRATION-VALIDATION.md
P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=application workflow/invariants, legacy service risks, queue index, self/cycle guards, and production DDL preflight remain deferred
FIXES_APPLIED=three additive failure-cleanup migrations plus MySQL/MariaDB schema and legacy compatibility tests

NEW_COMMIT=4c4e494 implementation; final report commit pending
COMMIT_PUSHED=pending
PR_D_URL=pending
PR_D_DRAFT=yes when opened
PR_D_MERGED=no

PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no

READY_FOR_PR_D_SENIOR_REVIEW=yes
READY_TO_MARK_PR_D=no
READY_TO_MERGE_PR_D=no
READY_FOR_PR_D_PRODUCTION_PREFLIGHT=no
READY_FOR_DEBT_OFFSET_APPLICATION_PR=no
READY_FOR_WRITE_PATH_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```
