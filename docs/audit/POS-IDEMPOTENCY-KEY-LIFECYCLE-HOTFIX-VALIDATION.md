# POS Idempotency-Key Lifecycle Hotfix Validation

## Release metadata

```text
STATUS=IMPLEMENTED_AND_AUTOMATED_TESTED
REPOSITORY=cuongdesignnb/kiot
BASE_BRANCH=production-customer-group
BASE_SHA=0e68f13807aaa69b97e5c90b45b2131944b9c196
WORK_BRANCH=hotfix/pos-idempotency-key-lifecycle
DRAFT_PR=pending
PR_URL=pending
MIGRATION_CREATED=no
BACKFILL_CREATED=no
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
PR_MERGED=no
READY_FOR_OWNER_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
```

Validation date: 2026-07-20 (Asia/Ho_Chi_Minh).

## Root cause and fix

The backend invariant was correct: one idempotency key may only replay the same request hash. The POS tab stored a single `idempotencyKey` for the lifetime of the tab, persisted it to LocalStorage, and did not clear it after a successful checkout. A later sale on the same tab therefore sent a committed key with a different payload.

The hotfix:

- replaces the tab-lifetime string with `{ key, fingerprint }` bound to the canonical endpoint and normalized payload;
- reuses the key only for an exact retry and rotates it when the endpoint or payload changes;
- creates the attempt only inside the `process_order` and normal/delivery request branches;
- explicitly skips attempt creation for `quick_order`;
- stores draft schema version 2, rejects stale legacy keys, and restores only complete version-2 pending attempts;
- clears the attempt and all transaction-specific tab state after a known success;
- preserves attempts after timeout/network/5xx outcomes so an exact retry remains safe;
- returns structured HTTP 409 code `POS_IDEMPOTENCY_PAYLOAD_MISMATCH` without exposing the key, request hash, or payload;
- clears only the stale attempt on that conflict, preserves the cart, and does not auto-submit.

`PartnerDebtMutationCoordinator` was not changed and continues to reject the same key with a different request hash.

## Changed files

- `app/Http/Controllers/PosController.php`
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/POS/posIdempotency.js`
- `resources/js/tests/posIdempotency.test.mjs`
- `tests/Feature/POS/PosCheckoutIdempotencyTest.php`
- `docs/audit/POS-IDEMPOTENCY-KEY-LIFECYCLE-HOTFIX-VALIDATION.md`

Diff classification: scoped application hotfix, frontend helper/tests, backend feature tests, and validation documentation. No schema, migration, dependency, production-data, coordinator, or deployment changes.

## Acceptance evidence

```text
ROOT_CAUSE_CONFIRMED=yes
LEGACY_KEY_REUSE_REPRODUCED=confirmed_by_code_path_and_regression_test_design
EXACT_RETRY_REUSES_KEY=yes
NEW_PAYLOAD_ROTATES_KEY=yes
SUCCESS_CLEARS_ATTEMPT=yes
LEGACY_DRAFT_SANITIZED=yes
STRUCTURED_CONFLICT_STATUS=409
STRUCTURED_CONFLICT_CODE=POS_IDEMPOTENCY_PAYLOAD_MISMATCH

DUPLICATE_INVOICES=0
DUPLICATE_CASH_FLOWS=0
DUPLICATE_STOCK_MOVEMENTS=0
DUPLICATE_DEBT_EFFECTS=0
SERIAL_DOUBLE_SALE=0
```

The focused backend test snapshots invoice, invoice-item, cash-flow, stock-movement, debt-operation, and operation-participant counts after the first commit, then proves an exact retry and a rejected mismatch leave every count unchanged. Product stock changes once for an exact replay and twice only when two different keys intentionally create two transactions.

## Automated validation

### Focused idempotency feature tests

Command:

```text
php artisan test tests/Feature/POS/PosCheckoutIdempotencyTest.php
```

Result: PASS — 3 tests, 29 assertions.

- exact retry returns the same invoice code and creates no duplicate side effect;
- same key with changed payload returns structured 409 and changes no side effect;
- changed payload with a new key creates the second transaction exactly once.

### Related regression tests

Command:

```text
php artisan test tests/Feature/Debt/PartnerDebtMutationCoordinatorTest.php tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php tests/Feature/Sales/RequireSerialOnSaleTest.php tests/Feature/Orders/ProcessOrderViaPosTest.php tests/Feature/POS/Step246CPosNoteAndDateFormatTest.php tests/Feature/POS/Step246DPosMoneyFormatTest.php
```

Result: PASS — 32 tests, 159 assertions.

Covered coordinator replay/mismatch/rollback, Invoice and POS inventory effects, cash flow, stock movement, Serial/IMEI sales, order processing through POS, sale time/note, quick order, and numeric money contracts.

### Frontend helper tests

Command:

```text
node --test resources/js/tests/posIdempotency.test.mjs resources/js/tests/moneyInput.test.mjs
```

Result: PASS — 16 tests.

Covered stable canonical serialization, exact-key reuse, rotation on payload or endpoint change, per-tab isolation, full success reset, legacy draft sanitization, current pending-attempt restoration, and explicit quick-order idempotency bypass without calling the key factory.

### Build and static gates

```text
npm run build
php -l app/Http/Controllers/PosController.php
php -l tests/Feature/POS/PosCheckoutIdempotencyTest.php
php vendor/bin/pint --test app/Http/Controllers/PosController.php tests/Feature/POS/PosCheckoutIdempotencyTest.php
git diff --check
```

Results:

```text
NPM_BUILD=PASS (923 modules, built in 9.81s on final run)
PHP_LINT=PASS
PINT=PASS
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS (no matches in changed code/test files)
```

Local PHP emits baseline startup warnings for missing optional Oracle/Firebird extensions (`oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`). They do not affect the MySQL test execution or PHP syntax result.

## UAT status

No browser UAT is claimed in this validation run. Automated equivalents cover the underlying state transitions and exactly-once database effects:

| Scenario | Automated evidence | Browser UAT |
| --- | --- | --- |
| UAT-01 consecutive invoices on one reused tab | success reset helper + changed-payload/new-key feature test | Pending |
| UAT-02 refresh after success | success clears attempt + schema-v2 draft test | Pending |
| UAT-03 stale legacy LocalStorage | legacy draft sanitization test | Pending |
| UAT-04 timeout after commit, exact retry | same fingerprint reuses key + exact replay feature test | Pending failure-injection UAT |
| UAT-05 edit after timeout | changed fingerprint rotates key + new-key feature test | Pending failure-injection UAT |
| UAT-06 multi-tab | separate-tab key isolation test | Pending |
| UAT-07 process order | endpoint-bound attempt + `ProcessOrderViaPosTest` regression | Pending |
| UAT-08 quick order | explicit no-idempotency/key-factory test + quick-order regression | Pending |

The Draft PR must not be marked ready for merge or production deployment until the owner completes the browser/failure-injection UAT appropriate for the release environment.

## Docker lifecycle

Only the existing `sales_mysql_test` MySQL container was started for migration and focused tests. No phpMyAdmin or application container was started. After all database tests passed:

```text
docker stop sales_mysql_test=success
running_containers_after_stop=0
docker desktop stop=success
```

Docker Desktop was stopped to release RAM before lint, reporting, and Git operations.

## Residual risk

```text
RESIDUAL_RISK_ANONYMOUS_CHECKOUT=present_out_of_scope
```

`InvoiceSaleService` still bypasses `PartnerDebtMutationCoordinator` when `customer_id <= 0`, so anonymous POS checkout does not currently have the same durable replay store. Repository inspection found no generic invoice/POS request-idempotency store independent of partner debt. A follow-up design should add generic invoice/POS idempotency without weakening or overloading the partner-debt invariant; no migration for that broader change is included here.

Manual browser UAT and concurrency/double-click testing remain release gates. No production access, deployment, merge, migration, or backfill was performed.
