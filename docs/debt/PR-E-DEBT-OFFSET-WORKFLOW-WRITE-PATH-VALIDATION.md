# PR E Debt Offset Workflow and Controlled Write Path Validation

## Revision and scope

```text
REPOSITORY=cuongdesignnb/kiot
TASK_CODE=RR-DEBT-OFFSET-WRITE-PATH-01
BRANCH=feat/debt-offset-workflow-write-path
BASE_BRANCH=production-customer-group
BASE_SHA=2ca4408ee48de88c4c9daa090601c73b44d8bfc2
REMOTE_BASE_SHA=2ca4408ee48de88c4c9daa090601c73b44d8bfc2
FINAL_HEAD_SHA=recorded in PR body and handoff

ROOT_CAUSE=LEGACY_DEBT_OFFSET_WRITES_BYPASS_APPROVAL_LOCKING_IDEMPOTENCY_AND_OPERATION_EVIDENCE
ROOT_CAUSE_CONFIRMED=yes
RISK_LEVEL=CRITICAL_FINANCIAL
```

This PR adds a controlled write path for dual-role partner debt offsets. It does
not enable that path in production. The default remains `legacy`, and unknown
mode values fail closed.

```text
NEW_MIGRATION_CREATED=no
BACKFILL_RUN=no
LEGACY_DATA_UPDATED=no
PRODUCTION_WRITE_MODE_CHANGED=no
WRITE_MODE_DEFAULT=legacy
PRODUCTION_FEATURE_ENABLED=no
```

## Discovery

The existing public debt-offset write surface and the PR A-D evidence schema
were audited before implementation.

```text
LEGACY_DIRECT_WRITE_CALL_SITES=1
LEGACY_AUTO_OFFSET_CALL_SITES=0
LEGACY_CANCEL_CALL_SITES=1
UNKNOWN_DEBT_OFFSET_WRITE_CALL_SITE=no

DEBT_OFFSET_SCHEMA_VERIFIED=yes
OPERATION_SCHEMA_VERIFIED=yes
PARTICIPANT_SCHEMA_VERIFIED=yes
OUTBOX_SCHEMA_VERIFIED=yes
```

Legacy entry points remain available only in `legacy` mode:

- `POST /customers/{customer}/debt-offset`
- `POST /customers/{customer}/cancel-debt-offset/{debtOffset}`
- `DebtOffsetService::manualOffset()`
- `DebtOffsetService::offsetDebts()`
- `DebtOffsetService::cancelOffset()`

In `workflow` mode they return `LEGACY_DEBT_OFFSET_WRITE_DISABLED`; in
`disabled` mode they return `DEBT_OFFSET_WORKFLOW_DISABLED`.

## Architecture

- `DebtOffsetStateMachine` is the only transition map.
- `DecimalMoney` parses and calculates exact integer cents; the new write path
  does not use float arithmetic.
- `DebtOffsetWorkflowService` owns validation, row locks, state transitions,
  balances, financial evidence, operation evidence, outbox and activity logs.
- `DebtOffsetWorkflowController` is transport-only and uses dedicated form
  requests.
- `DebtOffsetWriteMode` centralizes `legacy`, `workflow` and `disabled` modes.
- Existing timeline services expose only applied workflow vouchers and
  consolidate their financial evidence to one document occurrence.
- The outbox publisher, scheduler and external transport remain deferred.

## State transition table

| From | Command | To | Financial effect |
|---|---|---|---:|
| none | create | draft | 0 |
| draft | update | draft | 0 |
| draft | submit | pending_approval | 0 |
| draft | void | void | 0 |
| pending_approval | approve | approved | 0 |
| pending_approval | reject | rejected | 0 |
| approved | apply | applied | customer `-amount`, supplier `-amount` |
| applied/legacy active | reverse | reversed plus HCB voucher | customer `+amount`, supplier `+amount` |

All other transitions return HTTP 409 with
`INVALID_DEBT_OFFSET_TRANSITION` or a more specific stable reversal error.

## Endpoint and permission matrix

| Endpoint | Permission |
|---|---|
| `GET /debt-offsets` | `debt_offsets.view` |
| `GET /debt-offsets/{debtOffset}` | `debt_offsets.view` |
| `POST /customers/{customer}/debt-offsets` | `debt_offsets.create` |
| `PATCH /debt-offsets/{debtOffset}` | `debt_offsets.create` |
| `POST /debt-offsets/{debtOffset}/submit` | `debt_offsets.submit` |
| `POST /debt-offsets/{debtOffset}/approve` | `debt_offsets.approve` |
| `POST /debt-offsets/{debtOffset}/reject` | `debt_offsets.reject` |
| `POST /debt-offsets/{debtOffset}/apply` | `debt_offsets.apply` |
| `POST /debt-offsets/{debtOffset}/reverse` | `debt_offsets.reverse` |
| `POST /debt-offsets/{debtOffset}/void` | `debt_offsets.void` |

The backend does not infer these permissions from `customers.edit`. Admin `*`
continues to work through the existing permission middleware. Tests cover each
transition, unauthorized users, viewer-only users, admin, four-eyes approval,
optional distinct applier, and cross-branch denial.

## Idempotency and optimistic concurrency

Every mutating request requires `Idempotency-Key` of 16-191 characters.

- Create uses `debt_offsets.idempotency_key` and an operation envelope.
- Transitions use unique `(operation_type, idempotency_key)` operation evidence.
- A canonical SHA-256 request hash includes operation type, offset, partner,
  exact amount, reason/note and version token.
- Same key and same hash returns the committed result with no repeated effect.
- Same key and different hash returns `IDEMPOTENCY_KEY_REUSED`.
- Every command after create verifies a SHA-256 model version token after the
  row lock and before mutation.
- A second reversal with a different key is rejected before operation insert,
  while replay of the original reversal key remains idempotent.

## Transaction and lock contract

Each command uses one outer `DB::transaction(..., 5)` transaction. Apply and
reverse use the same lock order:

1. partner/customer row;
2. debt offset row;
3. related operation row;
4. existing reversal rows/query.

The transaction atomically covers balances, debt offset state, CashFlow,
SupplierDebtTransaction, operation, participant, outbox and ActivityLog. No
HTTP call, file write, shell command or external dispatch occurs inside it.

Apply re-reads current locked balances and rejects stale business amounts with
`OFFSET_AMOUNT_EXCEEDS_CURRENT_BALANCE`. Reverse preserves the original voucher
and creates one HCB reversal voucher plus reversing financial evidence.

## Exact-engine validation

Container versions were read directly from disposable local containers:

```text
MYSQL_VERSION=8.0.44
MARIADB_VERSION=10.11.10-MariaDB-ubu2204
```

| Suite | Engine/driver | Result |
|---|---|---|
| PR E unit, workflow, permission, failure injection, concurrency | MySQL 8.0.44 / `mysql` | PASS, 62 tests, 366 assertions |
| PR E unit, workflow, permission, failure injection, concurrency | MariaDB 10.11.10 / `mysql` | PASS, 62 tests, 366 assertions |
| PR E unit, workflow, permission, failure injection, concurrency | MariaDB 10.11.10 / `mariadb` | PASS, 62 tests, 366 assertions |
| PR A-D schema and rollback portability | MySQL 8.0.44 / `mysql` | PASS, 57 tests, 1774 assertions, 1 skipped |
| Sapo parity, customer/supplier timelines, dual-role and permission | MySQL 8.0.44 / `mysql` | PASS, 75 tests, 429 assertions |
| Phase 1 canonical debt, invariant, parity and audit command | MySQL 8.0.44 / `mysql` | PASS, 63 tests, 276 assertions |

The concurrency suite starts separate PHP processes and covers:

- two concurrent applies for the same offset;
- two concurrent offsets for the same partner;
- concurrent approve versus reject.

```text
CONCURRENT_SAME_OFFSET=PASS
CONCURRENT_SAME_PARTNER=PASS
CONCURRENT_APPROVE_REJECT=PASS
NEGATIVE_BALANCE=no
LOST_UPDATE=no
FINANCIAL_EFFECT_EXACTLY_ONCE=PASS
```

Failure injection passed at all required checkpoints:

```text
AFTER_PARTNER_BALANCE_UPDATE=PASS
AFTER_CASH_FLOW_CREATE=PASS
AFTER_SUPPLIER_TRANSACTION_CREATE=PASS
AFTER_OPERATION_PARTICIPANT_CREATE=PASS
BEFORE_OUTBOX_CREATE=PASS
BEFORE_OPERATION_COMMIT=PASS
```

Every injected exception left balances, offset state, CashFlow,
SupplierDebtTransaction, operation, participant, outbox and activity evidence
unchanged.

## Static and frontend validation

```text
PHP_LINT=PASS (39 changed/new PHP files)
PINT_NEW_PHP_FILES=PASS (29 files)
NPM_BUILD=PASS (922 modules transformed)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
FORBIDDEN_FILE_SCAN=PASS
MIGRATION_CHANGES=0
```

Pint was intentionally scoped to new PHP files. Running Pint against entire
modified legacy controllers/routes/services reports pre-existing style issues
and would rewrite unrelated lines, which is prohibited by `AGENT_RULES.md`.
All changed PHP files pass lint and all diff hunks pass whitespace validation.

## Acceptance matrix

```text
STATE_MACHINE=PASS
VALID_TRANSITIONS=PASS
INVALID_TRANSITIONS=PASS
FOUR_EYES=PASS
RBAC=PASS
BRANCH_SCOPE=PASS
VERSION_TOKEN=PASS
IDEMPOTENCY=PASS
ROW_LOCKING=PASS
TRANSACTION_ATOMICITY=PASS

CREATE_DRAFT=PASS
UPDATE_DRAFT=PASS
SUBMIT=PASS
APPROVE=PASS
REJECT=PASS
APPLY=PASS
REVERSE=PASS
VOID=PASS
LEGACY_ACTIVE_REVERSE=PASS
LEGACY_DIRECT_WRITE_GUARD=PASS

CASH_FLOW_EVIDENCE=PASS
SUPPLIER_TRANSACTION_EVIDENCE=PASS
OPERATION_EVIDENCE=PASS
PARTICIPANT_EVIDENCE=PASS
OUTBOX_EVIDENCE=PASS
ACTIVITY_LOG_EVIDENCE=PASS
CUSTOMER_TIMELINE_EXACTLY_ONCE=PASS
SUPPLIER_TIMELINE_EXACTLY_ONCE=PASS
FAILURE_INJECTION_ROLLBACK=PASS
STALE_BALANCE_GUARD=PASS
```

## Senior Review Amendment

```text
PREVIOUS_HEAD=418c63ba496e231b5fa1eafd2b45d27e13399313
FINAL_HEAD_SHA=recorded in PR body and handoff

P1_BRANCH_READ_SCOPE=RESOLVED
P1_LEGACY_HISTORY_FILTER=RESOLVED
P1_APPLY_REPLAY_AFTER_REVERSE=RESOLVED

BRANCH_READ_INDEX_SCOPE=PASS
BRANCH_READ_SHOW_SCOPE=PASS
LEGACY_HISTORY_FINANCIAL_ONLY=PASS
APPLY_REPLAY_AFTER_REVERSE=PASS
NO_DUPLICATE_FINANCIAL_EFFECT=PASS
PENDING_OPERATION_NOT_REPLAYED_AS_SUCCESS=PASS
FAILED_OPERATION_NOT_REPLAYED_AS_SUCCESS=PASS
SAME_KEY_DIFFERENT_HASH_REMAINS_409=PASS
```

Amendment files:

```text
app/Http/Controllers/DebtOffsetWorkflowController.php
app/Http/Controllers/CustomerController.php
app/Services/Debt/DebtOffsetWorkflowService.php
tests/Feature/DebtOffsets/DebtOffsetWorkflowPermissionTest.php
tests/Feature/DebtOffsets/DebtOffsetWorkflowTest.php
docs/debt/PR-E-DEBT-OFFSET-WORKFLOW-WRITE-PATH-VALIDATION.md
```

The read API now derives branch scope only from the authenticated user and
server-side partner data. A null partner branch remains visible for backward
compatibility. The legacy customer history endpoint exposes only legacy,
applied and reversed vouchers. Retrying an apply operation after its reversal
returns an idempotent response with the current `reversed` offset resource and
does not repeat any financial or audit evidence.

## Remaining risks and deferred work

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
```

P2 findings:

1. `OUTBOX_PUBLISHER_NOT_IMPLEMENTED`: explicitly deferred; no publisher,
   scheduler or external transport is enabled by this PR.
2. A repository-wide fresh migration remains blocked by the historical
   `2026_04_26_000004_add_unit_cost_allocated_to_purchase_items_table` duplicate
   column issue. PR E creates no migration; canonical PR A-D schema regression
   passes on the prepared production-like clone.
3. Some legacy PR A-D schema metadata assertions are MySQL-specific (display
   widths, JSON metadata and CHECK error metadata). The PR E workflow,
   transactions and locking pass on both required engines and both MariaDB
   connection drivers.
4. GitHub Actions status is not claimed by local validation.

## Commands executed

The commands below were run against disposable local databases only:

```text
git -c safe.directory=D:/Kiot/kiotviet-clone.worktrees/debt-offset-workflow-write-path fetch origin production-customer-group feat/debt-offset-workflow-write-path

$env:APP_BASE_PATH='D:\Kiot\kiotviet-clone.worktrees\debt-offset-workflow-write-path'
$env:APP_KEY='base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI='
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='3319'
$env:DB_DATABASE='kiot_pr_e_mysql'
$env:DB_USERNAME='root'
$env:DB_PASSWORD='root'
$env:DB_COLLATION='utf8mb4_0900_ai_ci'
php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Unit/Domain/DebtOffset tests/Unit/Services/DebtOffsetWriteModeTest.php tests/Feature/DebtOffsets

$env:DB_CONNECTION='mysql'
$env:DB_PORT='3321'
$env:DB_DATABASE='kiot_pr_e_mariadb'
$env:DB_COLLATION='utf8mb4_unicode_ci'
php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Unit/Domain/DebtOffset tests/Unit/Services/DebtOffsetWriteModeTest.php tests/Feature/DebtOffsets

$env:DB_CONNECTION='mariadb'
php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Unit/Domain/DebtOffset tests/Unit/Services/DebtOffsetWriteModeTest.php tests/Feature/DebtOffsets

$env:DB_CONNECTION='mysql'
$env:DB_PORT='3319'
$env:DB_DATABASE='kiot_pr_e_regression_mysql'
$env:DB_COLLATION='utf8mb4_0900_ai_ci'
php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Unit/Migrations/DebtOffsetCheckRollbackDialectTest.php tests/Feature/Migrations/PartnerDebtOperationOutboxSchemaTest.php tests/Feature/Migrations/PartnerDebtAllocationEvidenceSchemaTest.php tests/Feature/Migrations/PartnerDebtOpeningIncidentSchemaTest.php tests/Feature/Migrations/DebtOffsetHardeningSchemaTest.php tests/Feature/Migrations/DebtOffsetRollbackPortabilityIntegrationTest.php

php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Feature/CustomerDebt/SapoDebtParityTest.php tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Customers/HOTFIXFollowUpDebtOffsetMirrorTest.php tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/Security/EmployeePermissionIsolationTest.php

php -d variables_order=EGPCS D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\phpunit\phpunit\phpunit -c phpunit.xml tests/Unit/Services/CanonicalPartnerDebtServiceTest.php tests/Unit/Services/PartnerDebtInvariantCheckerTest.php tests/Unit/Services/PartnerDebtParityAuditServiceTest.php tests/Unit/Services/PartnerDebtScreenRawFixtureTest.php tests/Feature/Console/CheckPartnerDebtInvariantsCommandTest.php

php -l app/Http/Controllers/DebtOffsetWorkflowController.php
php -l app/Http/Controllers/CustomerController.php
php -l app/Services/Debt/DebtOffsetWorkflowService.php
php -l tests/Feature/DebtOffsets/DebtOffsetWorkflowPermissionTest.php
php -l tests/Feature/DebtOffsets/DebtOffsetWorkflowTest.php
php D:\Kiot\kiotviet-clone.worktrees\debt-single-source-invariant\vendor\bin\pint --test app/Http/Controllers/DebtOffsetWorkflowController.php app/Services/Debt/DebtOffsetWorkflowService.php tests/Feature/DebtOffsets/DebtOffsetWorkflowPermissionTest.php tests/Feature/DebtOffsets/DebtOffsetWorkflowTest.php
npm run build
git diff --check
git diff --name-only -- database/migrations resources/js config/debt.php
```

Database settings used only local ports `3319` and `3321`. No production host,
credential, backup, `.env` or database was accessed.

## Safety conclusion

```text
PRODUCTION_ACCESSED=no
PRODUCTION_MIGRATIONS_RUN=no
PRODUCTION_ROLLBACK_RUN=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
PRODUCTION_FEATURE_ENABLED=no
OUTBOX_PUBLISHER_ENABLED=no

READY_FOR_WRITE_PATH_SENIOR_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
READY_FOR_WORKFLOW_ENABLEMENT=no
READY_FOR_PRODUCTION_FINANCIAL_WRITE_UAT=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```
