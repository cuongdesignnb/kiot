# PR D MariaDB Rollback Portability Validation

## Revision

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=fix/pr-d-mariadb-rollback-portability
BASE_BRANCH=production-customer-group
BASE_SHA=af54d07af83b863c1bb8b726c5ce9b0a581ea91c
FINAL_HEAD_SHA=recorded in the PR body and final handoff
```

The branch was created from the exact approved base SHA. The final head cannot be
embedded in a file that is part of that same commit without making the value
self-invalidating.

## Root Cause And Fix

```text
ROOT_CAUSE=LARAVEL_DRIVER_DOES_NOT_IDENTIFY_ACTUAL_DATABASE_FAMILY
ROOT_CAUSE_CONFIRMED=yes
LARAVEL_DRIVER=mysql
ACTUAL_ENGINE_CAN_BE=MariaDB
SERVER_FAMILY_DETECTION=PASS
```

Laravel may report the `mysql` connection driver while the server is MariaDB.
The rollback previously selected its DDL from the Laravel driver and therefore
used `DROP CHECK` against MariaDB, which requires `DROP CONSTRAINT`.

The migration now reads `VERSION()` and `@@version_comment`, detects the actual
MySQL-compatible server family, and selects only these allowlisted operations:

```text
MYSQL_8_ROLLBACK_SYNTAX=DROP CHECK
MARIADB_10_11_ROLLBACK_SYNTAX=DROP CONSTRAINT
CONSTRAINT_NAMES_ALLOWLISTED=yes
USER_INPUT_IN_DDL=no
UNSUPPORTED_DATABASE_FAMILY_FAILS_CLOSED=yes
ROLLBACK_ERRORS_SUPPRESSED=no
```

The forward migration (`up()`), constraint names and expressions, columns,
indexes, foreign keys, application code, and release runner are unchanged.

```text
FORWARD_MIGRATION_BEHAVIOR_CHANGED=no
FORWARD_SCHEMA_CHANGED=no
SCHEMA_CONTRACT_CHANGED=no
APPLICATION_BEHAVIOR_CHANGED=no
```

## Exact-engine Roundtrip

Each roundtrip started with PR D present, captured legacy and PR A/B/C data and
schema evidence, rolled back only the three PR D migrations, compared the exact
normalized baseline, remigrated, and verified a second migrate was a no-op.

```text
MYSQL_VERSION=8.0.44
MYSQL_LARAVEL_DRIVER=mysql
MYSQL_SERVER_FAMILY_DETECTED=mysql
MYSQL_8_0_44_FORWARD=PASS
MYSQL_8_0_44_ROLLBACK=PASS
MYSQL_8_0_44_DIALECT=DROP CHECK
BASELINE_SCHEMA_RESTORED_MYSQL=PASS

MARIADB_VERSION=10.11.10
MARIADB_LARAVEL_DRIVER=mysql
MARIADB_SERVER_FAMILY_DETECTED=mariadb
MARIADB_10_11_10_DRIVER_MYSQL_FORWARD=PASS
MARIADB_10_11_10_DRIVER_MYSQL_ROLLBACK=PASS
MARIADB_10_11_10_DIALECT=DROP CONSTRAINT
BASELINE_SCHEMA_RESTORED_MARIADB=PASS

MARIADB_DRIVER_NATIVE_TEST=PASS
MARIADB_NATIVE_DRIVER=mariadb
```

The required MySQL and MariaDB-with-`mysql`-driver tests each passed with 112
assertions. The additional MariaDB native-driver run also passed with 112
assertions.

## Data And Schema Invariance

```text
LEGACY_ROW_COUNT_UNCHANGED=PASS
LEGACY_ROW_HASH_UNCHANGED=PASS
DEBT_OFFSETS_AMOUNT_UNCHANGED=PASS
NON_TARGET_TABLE_DATA_UNCHANGED=PASS
PR_A_TABLES_RETAINED=PASS
PR_B_TABLES_RETAINED=PASS
PR_C_TABLES_RETAINED=PASS
PR_A_B_C_RETAINED=PASS
CONSTRAINT_METADATA_AFTER_UP=PASS
CONSTRAINT_METADATA_AFTER_DOWN=PASS
NO_NON_PR_D_CHECK_REMOVED=PASS
REMIGRATE=PASS
SECOND_MIGRATE_NOOP=PASS
```

## Regression Results

```text
PHP_LINT=PASS (3 files)
PINT=PASS (3 files)
ROLLBACK_DIALECT_UNIT=PASS (9 tests, 17 assertions)
PR_A_B_C_D_SCHEMA_REGRESSION=PASS (56 tests, 1774 assertions; includes dialect unit)
PHASE_1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
FORBIDDEN_FILE_SCAN=PASS
```

Exact integration test entry point:

```text
php artisan test tests/Feature/Migrations/DebtOffsetRollbackPortabilityIntegrationTest.php --group=debt-offset-rollback-integration
```

It was run in disposable Docker environments with these required combinations:

```text
DB_CONNECTION=mysql; MySQL 8.0.44
DB_CONNECTION=mysql; MariaDB 10.11.10
DB_CONNECTION=mariadb; MariaDB 10.11.10 (supplementary)
```

Regression entry points:

```text
php artisan test tests/Unit/Migrations/DebtOffsetCheckRollbackDialectTest.php tests/Feature/Migrations/PartnerDebtOperationOutboxSchemaTest.php tests/Feature/Migrations/PartnerDebtAllocationEvidenceSchemaTest.php tests/Feature/Migrations/PartnerDebtOpeningIncidentSchemaTest.php tests/Feature/Migrations/DebtOffsetHardeningSchemaTest.php
php artisan test tests/Unit/Services/CanonicalPartnerDebtServiceTest.php tests/Unit/Services/PartnerDebtInvariantCheckerTest.php tests/Unit/Services/PartnerDebtParityAuditServiceTest.php tests/Unit/Services/PartnerDebtScreenRawFixtureTest.php tests/Feature/Console/CheckPartnerDebtInvariantsCommandTest.php tests/Feature/Report/DebtReconciliationReportTest.php tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php
```

## Safety Boundary

```text
PRODUCTION_ACCESSED=no
PRODUCTION_ROLLBACK_RUN=no
PRODUCTION_MIGRATIONS_RUN=no
PRODUCTION_DATA_CHANGED=no
BACKFILL=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=no
OUTBOX_CREATED=no
CURRENT_DEBT_DATA_CHANGED=no
NEW_MIGRATION_CREATED=no
FRONTEND_CHANGED=no
FRONTEND_BUILD_REQUIRED=no
```

## Review State

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
READY_FOR_ROLLBACK_FIX_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
READY_FOR_PRODUCTION_ROLLBACK=no
READY_FOR_APPLICATION_WRITE_PATH=no
```
