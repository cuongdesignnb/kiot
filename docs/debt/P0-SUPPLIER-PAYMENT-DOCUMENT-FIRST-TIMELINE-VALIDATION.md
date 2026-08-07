# P0 supplier payment document-first timeline validation

```text
STATUS=P0_SUPPLIER_PAYMENT_TIMELINE_REMEDIATION_COMPLETE
REPOSITORY=cuongdesignnb/kiot
BASE_BRANCH=production-customer-group
BASE_SHA=095262ddce192923ffe00ab9e036c1841e2e61c1
REVIEWED_CODE_HEAD=1325fb31e3e24f46be24eae4c8b5709e9dbfde27
BRANCH=codex/p0-supplier-payment-document-first-timeline
PR_CREATED=TO_BE_CAPTURED_AFTER_DRAFT_CREATION
MERGE_RUN=NO
DEPLOY_RUN=NO
NEXT_GATE=PR_CI_AND_FINAL_REVIEW
```

## Scope and safety

The remediation is limited to supplier-payment display projection semantics and
its disposable regression fixtures. Canonical allocation events remain the
source of truth; consolidation occurs only after canonical selection. No
migration, backfill, manual debt correction, production connection by this
agent, production write, merge, or deployment was performed.
`DEBT_OFFSET_WRITE_MODE=legacy` was unchanged.

The operator separately supplied a read-only production audit/evidence snapshot.
That operator evidence is distinguished from agent activity below; no password,
host secret, `.env` value, or credential is recorded here.

```text
AGENT_PRODUCTION_ACCESSED=NO
AGENT_PRODUCTION_WRITE=NO
OPERATOR_PRODUCTION_READ_ONLY_AUDIT=YES
OPERATOR_PRODUCTION_DB_MUTATED=NO

PRODUCTION_DB_ENGINE=MariaDB
PRODUCTION_DB_VERSION=10.11.10-MariaDB-log
PRODUCTION_LARAVEL_CONNECTION=mysql
PRODUCTION_SERVER_CHARSET=utf8mb4
PRODUCTION_SERVER_COLLATION=utf8mb4_general_ci
PRODUCTION_DATABASE_CHARSET=utf8mb4
PRODUCTION_DATABASE_COLLATION=utf8mb4_general_ci
PRODUCTION_LARAVEL_MYSQL_CHARSET=utf8mb4
PRODUCTION_LARAVEL_MYSQL_COLLATION=utf8mb4_unicode_ci
PRODUCTION_TABLE_COLLATION=utf8mb4_unicode_ci
PRODUCTION_TABLE_COUNT=120
PRODUCTION_SUPPORTS_UTF8MB4_0900_AI_CI=NO
```

Docker was used only for disposable local validation. `kiot_pr31_mariadb` was
used for the MariaDB gate and `kiot_pr31_mysql8` for the MySQL gate. The local
browser server and Playwright Chromium used only the disposable MySQL database.
Both database containers and the local server are stopped before hand-off.

## Remediation contract

```text
P1_REFERENCE_SEMANTICS=PASS
CASHFLOW_REFERENCE_TYPE=SupplierPayment
CASHFLOW_REFERENCE_ID=922 (fixture assertion)
CASHFLOW_REFERENCE_CODE=PCPN260807154515978 (fixture assertion)
FIRST_PURCHASE_NOT_EXPOSED_AS_PARENT=PASS
DISPLAY_GROUP_KEY=cash_flows|922|supplier_payment (authoritative identity)
DOCUMENT_GROUP_TYPE=supplier_payment

EXACT_12_ALLOCATION_FIXTURE=PASS
EXACT_ALLOCATION_COUNT=12
EXACT_ALLOCATION_SUM=18400000
EXACT_PAYMENT_AMOUNT=18400000
EXACT_UNALLOCATED_AMOUNT=0
DISPLAY_PAYMENT_ROW_COUNT=1
DISPLAY_EFFECT=-18400000
PAYMENT_ALLOCATION_MISMATCH=FALSE
NEEDS_MANUAL_REVIEW=FALSE

MISMATCH_FIXTURE=PASS (18000000 allocated, 400000 residual)
DISTINCT_PAYMENTS_NOT_MERGED=PASS (CashFlow IDs 922 and 923 unit fixture)
PAYMENT_ALLOCATION_IDENTITIES_PRESERVED=YES
PAYMENT_ALLOCATION_DELTAS_PRESERVED=YES
PAYMENT_REVERSAL_LINKS_PRESERVED=YES (cancellation fixture)
FALSE_PAYMENT_CHECKPOINTS_REMOVED=YES
FALSE_DEBTOFFSET_CHECKPOINTS_REMOVED=YES
GENUINE_CHECKPOINT_PRESERVED=YES
ECONOMIC_DELTA_SUM_UNCHANGED=YES
FULL_IDENTITY_HASH_UNCHANGED=NO_EXPECTED (technical mirror checkpoints intentionally removed)
TARGET_BALANCE_UNCHANGED=YES
RAW_FINAL_BALANCE_UNCHANGED=YES
RECONCILE_DIFFERENCE_UNCHANGED=YES
```

The 27-line `PartnerDebtParityAuditService` change is retained because the
focused audit test proves technical historical checkpoint evidence remains
available to audit consumers while payment and DebtOffset mirrors are excluded
from the document balance. The audit change does not mutate balances or alter
financial classification.

## Database gates

```text
MARIADB_VERSION=10.11.18-MariaDB-ubu2204
MARIADB_CONNECTION_DRIVER=mysql
MARIADB_CHARSET=utf8mb4
MARIADB_COLLATION=utf8mb4_unicode_ci
MARIADB_DATABASE_COLLATION=utf8mb4_unicode_ci
MARIADB_FOCUSED_TESTS=PASS (4 tests, 36 assertions supplier fixture; 15 tests, 779 assertions projection/canonical; 6 tests, 23 assertions export)

MYSQL_VERSION=8.0.44
MYSQL_CONNECTION_DRIVER=mysql
MYSQL_CHARSET=utf8mb4
MYSQL_COLLATION=utf8mb4_unicode_ci (explicit Laravel connection; disposable DB default set likewise)
MYSQL_FOCUSED_TESTS=PASS (19 tests, 815 assertions)
```

The earlier MariaDB local failure was caused by the disposable environment
falling back to MySQL-only `utf8mb4_0900_ai_ci`. Production explicitly uses
`utf8mb4_unicode_ci` through `DB_COLLATION`, and all 120 production tables are
`utf8mb4_unicode_ci`. The corrected MariaDB 10.11 local gate explicitly used
`DB_CONNECTION=mysql`, `DB_CHARSET=utf8mb4`, and
`DB_COLLATION=utf8mb4_unicode_ci`, reproduced the compatible configuration, and
passed.

## Regression gates

```text
PURCHASE_CANCEL_EXACT_REVERSAL=PASS
PURCHASE_CANCEL_REGRESSION=PASS (existing test: 1 test, 5 assertions; new exact fixture: 1 test, 8 assertions)
PURCHASE_CANCEL_CONTRACT=A remains -2000000; B original -3000000 plus exactly +3000000 reversal; C remains -4000000; no +9000000 reversal; allocations preserved; CashFlow active

CSV_DOCUMENT_EXPORT_ONE_PAYMENT_ONE_ROW=PASS
XLSX_DOCUMENT_EXPORT_ONE_PAYMENT_ONE_ROW=PASS
EXPORT_REGRESSION=PASS (new fixture: 1 test; existing supplier export suite: 6 tests, 23 assertions)
EXACT_12_ALLOCATION_FIXTURE=PASS
RESIDUAL_ALLOCATION_METADATA=PASS
STANDALONE_PAYMENT_ALLOCATION_METADATA=PASS
MYSQL_FOCUSED=PASS
MARIADB_FOCUSED=PASS

LEGACY_ROLE_ASSERTION_BASELINE_PROVEN=YES
BASE_EXIT_CODE=1
HEAD_EXIT_CODE=1
BASE_FAILURE_TEST=PartnerDebtParityAuditServiceTest::test_persisted_customer_evidence_upgrades_supplier_flag_to_dual_role
HEAD_FAILURE_TEST=PartnerDebtParityAuditServiceTest::test_persisted_customer_evidence_upgrades_supplier_flag_to_dual_role
BASE_FAILURE_ASSERTION=tests/Unit/Services/PartnerDebtParityAuditServiceTest.php:271 expected dual_role, actual supplier_only
HEAD_FAILURE_ASSERTION=tests/Unit/Services/PartnerDebtParityAuditServiceTest.php:271 expected dual_role, actual supplier_only
FAILURE_SIGNATURE_EQUIVALENT=YES

AUDIT_SCOPE_TESTS=18/19 PASS; the one failure is the proven legacy baseline above
DEBT_REGRESSION=PASS_OR_PROVEN_BASELINE_ONLY
DUAL_ROLE_REGRESSION=PASS_OR_PROVEN_BASELINE_ONLY
```

## Isolated browser QA

Playwright 1.49.1 and its managed Chromium were installed only under a
disposable `C:\tmp` QA directory. No package or lockfile in the repository was
changed, and the user's Chrome was not accessed.

```text
BROWSER_QA=PASS
USER_CHROME_ACCESSED=NO
PCPN_A_ROWS=1
PCPN_B_ROWS=1
PCPN_B_VALUE=-18400000
ALLOCATIONS_VISIBLE=NO
CHECKPOINT_ROWS_VISIBLE=NO
CLICKED_DETAIL_TYPE=cashflow
CLICKED_DETAIL_CODE=PCPN-B
```

The local app emitted an unrelated 500 from `/api/notifications/unread-count`
because the disposable browser user has no notification fixture; it did not
affect the supplier page, debt endpoint, voucher row contract, or CashFlow
detail click assertions above.

## Static validation

```text
TARGETED_TESTS=PASS
PINT_DIRTY=PASS (4 PHP files)
PHP_LINT=PASS
FRONTEND_BUILD=PASS (Vite 5.4.21)
DIFF_CHECK=PASS
MIGRATION_ADDED=NO
BACKFILL_RUN=NO
AGENT_PRODUCTION_ACCESS=NO
OPERATOR_PRODUCTION_READ_ONLY_AUDIT=YES
PRODUCTION_DB_MUTATED=NO
PRODUCTION_DEPLOYED=NO
```

```text
MIGRATION=NO
BACKFILL=NO
MANUAL_DB_UPDATE=NO
DEBT_RECALCULATION=NO
DEPENDENCY_CHANGED=NO
FRONTEND_SOURCE_CHANGED=NO
PRODUCTION_CONFIG_CHANGED=NO
```

The full canonical identity hash is not claimed unchanged: false technical
payment/DebtOffset checkpoint identities are intentionally removed. Economic
allocation identities, deltas, reversal links, target, raw final, and
reconcile difference are the preserved invariants.
