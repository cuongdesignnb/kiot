# P0 supplier payment document-first timeline validation

```text
STATUS=BLOCKED
BASE_SHA=095262ddce192923ffe00ab9e036c1841e2e61c1
IMPLEMENTATION_HEAD_SHA=36810689ee94bc2450d3f420b403453c98812823
BRANCH=codex/p0-supplier-payment-document-first-timeline
PR_URL=NOT_CREATED_GATES_BLOCKED
```

## Scope and safety

This change is display-only. Canonical allocation events, identities, deltas,
and cancellation links remain intact. The change does not add a migration,
backfill, manual debt correction, production connection, production write,
merge, or deployment. `DEBT_OFFSET_WRITE_MODE` was not changed.

Docker was used only for disposable local validation. MySQL 8.0.44 was used on
`kiot_pr31_mysql8` at port 3322. MariaDB 10.11.18 was started only for its
gate, failed before any test transaction because the disposable schema uses
the MySQL-only `utf8mb4_0900_ai_ci` collation, and was stopped immediately.

## Root cause and implementation

```text
ROOT_CAUSE_ALLOCATION_ROWS=real CashFlow allocations were rendered as separate UI rows
ROOT_CAUSE_CHECKPOINT_MIRRORS=payment and DebtOffset ledger mirrors remained checkpoint candidates
```

`PartnerDebtTimelineOrientationService` now consolidates only real supplier
payment events by `cash_flows|<CashFlow.id>|supplier_payment`, after canonical
selection and before display running-balance projection. The representative
row opens the original CashFlow and carries allocation count, total, purchase
IDs/codes, canonical identity list, and residual/manual-review diagnostics.
Different CashFlow IDs cannot merge even when code, amount, or timestamp
collide. Canonical hashes and event deltas are calculated before this display
projection.

`SupplierDebtDomainEventSource::addPersistedLedgerCheckpoints()` now rejects a
ledger row only when its persisted payment or offset identity is proven to
match a real document. Standalone historical adjustments still produce a
persisted checkpoint. Technical mirror evidence remains available to audit
consumers without entering document balance.

## Focused results

```text
CANONICAL_EVENTS_PRESERVED=PASS
CANONICAL_IDENTITY_HASH_UNCHANGED=PASS
CANONICAL_SUPPLIER_DELTA_UNCHANGED=PASS
CANONICAL_CUSTOMER_DELTA_UNCHANGED=PASS
ONE_PAYMENT_ONE_DISPLAY_ROW=PASS
DISTINCT_PAYMENTS_NOT_MERGED=PASS
PAYMENT_ALLOCATION_METADATA=PASS
PAYMENT_MIRROR_CHECKPOINT_SUPPRESSED=PASS
GENUINE_CHECKPOINT_PRESERVED=PASS
PURCHASE_CANCEL_EXACT_REVERSAL=NOT_RUN_IN_THIS_HOTFIX_GATE

PRODUCTION_CASE_PAYMENT_AMOUNT=18400000
PRODUCTION_CASE_ALLOCATION_SUM=18400000 (focused full-allocation fixture)
PRODUCTION_CASE_EXPECTED_DISPLAY_ROWS=1
MISMATCH_FIXTURE_PAYMENT_AMOUNT=18400000
MISMATCH_FIXTURE_ALLOCATION_SUM=18000000
MISMATCH_FIXTURE_UNALLOCATED=400000

TARGET_BALANCE_UNCHANGED=PASS (focused projection contract)
RAW_FINAL_BALANCE_UNCHANGED=PASS (focused projection contract)
RECONCILE_DIFFERENCE_UNCHANGED=PASS (focused projection contract)
```

Focused MySQL results:

```text
CANONICAL_AND_DISPLAY_UNIT=PASS (19 tests, 791 assertions before the exact-allocation addition)
SUPPLIER_PAYMENT_DISPLAY_UNIT=PASS (5 tests, 29 assertions)
SUPPLIER_TIMELINE_FEATURE=PASS (5 tests, 24 assertions)
```

The canonical contract plus the new projection and supplier fixtures pass
together as `19 tests, 791 assertions`; the new exact-allocation test is also
green independently. The endpoint/debt contract plus parity audit ran as
`31 tests, 176 assertions` with one pre-existing assertion that expects an
evidence-only invoice to promote the persisted audit `role` to `dual_role`.
The current P0 role contract intentionally keeps persisted role authoritative
(`evidence_role` reports the discrepancy and never changes UI scope), so that
assertion was not weakened.

## Gates and blockers

```text
TARGETED_TESTS=PASS (focused suites above)
DEBT_REGRESSION=BLOCKED (legacy role assertion; broad clone run also has existing contract/schema failures)
DUAL_ROLE_REGRESSION=BLOCKED (legacy response-shape/fixture expectations)
PURCHASE_CANCEL_REGRESSION=NOT_RUN_IN_THIS_HOTFIX_GATE
EXPORT_REGRESSION=BLOCKED (broad suite has pre-existing fixture/schema failures)
PINT_DIRTY=PASS (6 changed files)
PHP_LINT=PASS (all 6 changed files)
FRONTEND_BUILD=PASS (npm run build; frontend source unchanged)
DIFF_CHECK=PASS
BROWSER_QA=BLOCKED (no Playwright/Puppeteer dependency or isolated browser harness in this checkout)
MARIADB_TESTS=BLOCKED (utf8mb4_0900_ai_ci unsupported by MariaDB 10.11.18 clone)
MYSQL_TESTS=PASS (focused suites on MySQL 8.0.44)
```

No browser or production session was used. A Draft PR was not created because
the requested workflow allows publishing only after all local gates pass.

```text
MIGRATION_ADDED=NO
BACKFILL_RUN=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_WRITE=NO
MERGE_RUN=NO
DEPLOY_RUN=NO
NEXT_GATE=FINAL_INDEPENDENT_DEBT_REVIEW
```
