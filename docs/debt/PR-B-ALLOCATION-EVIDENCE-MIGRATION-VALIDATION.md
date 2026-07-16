# PR B Allocation Evidence Migration Validation

## Decision

```text
VALIDATION_DATE=2026-07-16
P0_BLOCKERS=0
P1_BLOCKERS=0
READY_FOR_PR_B_SENIOR_REVIEW=yes
READY_TO_MARK_PR_B=no
READY_TO_MERGE_PR_B=no
READY_FOR_PR_B_PRODUCTION_PREFLIGHT=no
READY_FOR_WRITE_PATH_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

This is a schema-only migration PR. It does not enable any allocation write
path and does not change current debt data.

## Git Scope

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=feat/debt-integrity-pr-b-allocation-evidence-schema
BASE_BRANCH=production-customer-group
BASE_SHA=601b708cb2b521fd101211a8baf3db8018b048e6
HEAD_BEFORE=601b708cb2b521fd101211a8baf3db8018b048e6
IMPLEMENTATION_COMMIT=abbeb73e1823c40a002c228c195166a0274e15e4
```

Allowed implementation files:

```text
database/migrations/2026_07_16_090000_create_supplier_payment_allocations_table.php
database/migrations/2026_07_16_090100_create_supplier_payment_allocation_reversals_table.php
database/migrations/2026_07_16_090200_create_customer_payment_allocation_reversals_table.php
tests/Feature/Migrations/PartnerDebtAllocationEvidenceSchemaTest.php
docs/debt/PR-B-ALLOCATION-EVIDENCE-MIGRATION-VALIDATION.md
```

No model, service, controller, command, job, worker, scheduler, feature flag,
frontend file, or legacy migration was changed.

## Duplicate and Contract Discovery

Repository-wide discovery found no existing or near-equivalent schema for:

```text
supplier_payment_allocations
supplier_payment_allocation_reversals
customer_payment_allocation_reversals
```

The existing `customer_payment_allocations` table remains the customer
allocation parent. It is not replaced or altered. Existing key types were
read from migrations and MySQL metadata before implementation:

| Parent key | Type | PR B usage |
|---|---|---|
| `cash_flows.id` | `BIGINT UNSIGNED` | supplier allocation payment FK |
| `purchases.id` | `BIGINT UNSIGNED` | supplier allocation purchase FK |
| `customers.id` | `BIGINT UNSIGNED` | supplier allocation supplier FK |
| `users.id` | `BIGINT UNSIGNED` | nullable actor FK |
| `partner_debt_operations.id` | `BIGINT UNSIGNED` | operation FK |
| `customer_payment_allocations.id` | `BIGINT UNSIGNED` | customer reversal allocation FK |

Current customer allocation uniqueness remains
`UNIQUE(cash_flow_id, invoice_id)`. The current supplier payment path can
update purchases using manual or FIFO selection but persists no mapping. PR B
does not infer or backfill that historical mapping.

## Migration Inventory

### `supplier_payment_allocations`

Columns:

```text
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
payment_id BIGINT UNSIGNED NOT NULL
purchase_id BIGINT UNSIGNED NOT NULL
supplier_id BIGINT UNSIGNED NOT NULL
amount DECIMAL(15,2) NOT NULL
allocation_source VARCHAR(16) NOT NULL
idempotency_key VARCHAR(191) NOT NULL
operation_id BIGINT UNSIGNED NOT NULL
allocated_at DATETIME(6) NOT NULL
created_by BIGINT UNSIGNED NULL
created_at TIMESTAMP(6) NULL
updated_at TIMESTAMP(6) NULL
```

Indexes and constraints:

```text
spa_payment_purchase_uq UNIQUE(payment_id, purchase_id)
spa_idempotency_uq UNIQUE(idempotency_key)
spa_supplier_purchase_idx (supplier_id, purchase_id)
spa_purchase_allocated_idx (purchase_id, allocated_at, id)
spa_operation_idx (operation_id)
spa_amount_positive_chk CHECK(amount > 0)
spa_source_chk CHECK(allocation_source IN ('manual', 'auto'))
```

Foreign keys:

```text
spa_payment_fk -> cash_flows.id ON DELETE RESTRICT
spa_purchase_fk -> purchases.id ON DELETE RESTRICT
spa_supplier_fk -> customers.id ON DELETE RESTRICT
spa_operation_fk -> partner_debt_operations.id ON DELETE RESTRICT
spa_created_by_fk -> users.id ON DELETE SET NULL
```

### `supplier_payment_allocation_reversals`

The table stores one immutable, full-only reversal per supplier allocation.
It has `DECIMAL(15,2)` amount, `DATETIME(6)` reversal time, unique allocation
and idempotency keys, operation and time indexes, and these controls:

```text
spar_allocation_fk -> supplier_payment_allocations.id ON DELETE RESTRICT
spar_operation_fk -> partner_debt_operations.id ON DELETE RESTRICT
spar_reversed_by_fk -> users.id ON DELETE SET NULL
spar_amount_positive_chk CHECK(amount > 0)
spar_reason_nonempty_chk CHECK(CHAR_LENGTH(TRIM(reason)) > 0)
```

### `customer_payment_allocation_reversals`

The table mirrors supplier reversal evidence while retaining the legacy
customer allocation table as its parent:

```text
cpar_allocation_fk -> customer_payment_allocations.id ON DELETE RESTRICT
cpar_operation_fk -> partner_debt_operations.id ON DELETE RESTRICT
cpar_reversed_by_fk -> users.id ON DELETE SET NULL
cpar_amount_positive_chk CHECK(amount > 0)
cpar_reason_nonempty_chk CHECK(CHAR_LENGTH(TRIM(reason)) > 0)
```

All explicit identifiers are at most 64 characters. Each migration removes
only its own newly created table if raw CHECK creation fails. MySQL and
MariaDB DDL implicit commits mean the three migrations are not represented as
one atomic database transaction.

## MySQL 8.0.44 Proof

Engine:

```text
MYSQL_VERSION=8.0.44
MYSQL_CHECK_ENFORCED=yes
DATABASE=local disposable test databases only
```

Fresh database results:

```text
MYSQL_FRESH_MIGRATE=PASS
MYSQL_FRESH_SCHEMA_TESTS=PASS (10 tests, 372 assertions)
MYSQL_FRESH_CHECK_PROBES=PASS
MYSQL_FRESH_ROLLBACK=PASS (three PR B tables removed)
MYSQL_FRESH_REMIGRATE=PASS
MYSQL_FRESH_SECOND_MIGRATE_NOOP=PASS
```

Functional probes rejected:

```text
zero and negative amounts
unknown and NULL allocation sources
empty and whitespace-only reversal reasons
duplicate payment/purchase allocations
duplicate idempotency keys
second reversal for the same allocation
invalid parent and actor references
hard deletion of referenced evidence parents
```

Actor deletion was verified to set `created_by` or `reversed_by` to NULL.

## Production-like MySQL Clone Proof

Source was the latest approved local production-like clone. PR A migrations
were applied first so the test baseline matched the deployed schema. No
production connection was used.

The proof captured exact row counts, deterministic data dump SHA-256 values,
and schema dump SHA-256 values for:

```text
customers
users
cash_flows
invoices
purchases
customer_debts
supplier_debt_transactions
customer_payment_allocations
debt_offsets
partner_debt_operations
partner_debt_operation_participants
partner_debt_outbox_events
```

Results:

```text
MYSQL_CLONE_MIGRATE=PASS
MYSQL_CLONE_SCHEMA_TESTS=PASS (10 tests, 372 assertions)
MYSQL_CLONE_NEW_TABLE_INITIAL_ROWS=0
MYSQL_CLONE_LEGACY_COUNTS_UNCHANGED=yes
MYSQL_CLONE_LEGACY_DATA_HASHES_UNCHANGED=yes
MYSQL_CLONE_LEGACY_SCHEMA_HASHES_UNCHANGED=yes
MYSQL_CLONE_PR_A_COUNTS_UNCHANGED=yes
MYSQL_CLONE_PR_A_DATA_HASHES_UNCHANGED=yes
MYSQL_CLONE_PR_A_SCHEMA_HASHES_UNCHANGED=yes
MYSQL_CLONE_ROLLBACK=PASS
MYSQL_CLONE_REMIGRATE=PASS
```

Hash comparisons were made before running fixture-based schema tests. This
keeps test-only `AUTO_INCREMENT` advancement from being misclassified as a
migration data or schema mutation.

## MariaDB 10.11.10 Proof

Engine:

```text
MARIADB_VERSION=10.11.10-MariaDB-ubu2204
LARAVEL_CONNECTION_DRIVER=mariadb
CHECK_CONSTRAINT_CHECKS=1
CHARACTER_SET=utf8mb4
COLLATION=utf8mb4_uca1400_ai_ci
MARIADB_CHECK_ENFORCED=yes
DATABASE=local disposable Docker database only
```

Results:

```text
MARIADB_FRESH_MIGRATE=PASS
MARIADB_SCHEMA_TESTS=PASS (10 tests, 366 assertions)
MARIADB_CHECK_PROBES=PASS
MARIADB_ROLLBACK=PASS (three PR B tables removed)
MARIADB_REMIGRATE=PASS
MARIADB_SECOND_MIGRATE_NOOP=PASS
```

The lower assertion count than MySQL is expected because MariaDB does not
expose the MySQL `ENFORCED` metadata column. Named CHECK discovery and
functional rejection were both verified.

An implementation gap was found and fixed during validation: the raw CHECK
guard originally accepted only Laravel driver name `mysql`. It now accepts
both `mysql` and `mariadb`, and the final MariaDB proof uses the actual Laravel
`mariadb` driver rather than a MySQL-named connection pointed at MariaDB.

## Regression and Repository Validation

```text
PHP_LINT=PASS (three migrations and schema test)
PR_A_SCHEMA_REGRESSION=PASS (12 tests, 441 assertions)
PHASE1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
GIT_DIFF_CHECK=PASS
FORBIDDEN_FILE_SCAN=PASS
FRONTEND_BUILD_REQUIRED=no
```

The local PHP binary emitted pre-existing startup warnings for unavailable
Oracle and Firebird extensions. PHPUnit and every required command exited
successfully; these warnings are unrelated to PR B.

## Data Safety

```text
LEGACY_TABLES_ALTERED=no
LEGACY_DATA_MUTATED=no
PR_A_DATA_MUTATED=no
BACKFILL=no
SEED=no
FIFO_INFERENCE_CREATED=no
OPERATION_ROWS_CREATED_BY_MIGRATION=no
OUTBOX_ROWS_CREATED_BY_MIGRATION=no
PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
```

Rollback drops only the three PR B tables in reverse dependency order. A
production preflight must still measure DDL timing and confirm backup and
rollback procedures before any later deployment decision.

## Deferred Application Invariants

The schema intentionally does not claim to enforce cross-row business rules.
The later application/write-path PR must enforce all of the following under
row locks and one outer transaction:

```text
payment is an active SupplierPayment owned by supplier_id
purchase is active and owned by supplier_id
request contains no duplicate purchase IDs
sum of allocations does not exceed payment amount
active allocations do not exceed collectible purchase balance
reversal amount equals original allocation amount
original allocation is active and reversed exactly once
operation type/state/ownership are valid
document, ledger, cache and outbox writes are atomic
```

No Supplier Payment V2 write path may be enabled by this PR.

## Findings

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=
- application ownership and over-allocation guards required
- reversal equality and operation-state guards required
- final operation/allocation source enum registry required
- production index selectivity and DDL timing require preflight
```

## Final Gate

```text
READY_FOR_PR_B_SENIOR_REVIEW=yes
READY_TO_MARK_PR_B=no
READY_TO_MERGE_PR_B=no
READY_FOR_PR_B_PRODUCTION_PREFLIGHT=no
READY_FOR_WRITE_PATH_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```
