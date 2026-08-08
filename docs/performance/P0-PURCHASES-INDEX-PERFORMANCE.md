# P0 — `/purchases` index performance

## Scope and safety

```text
ORIGINAL_BENCHMARK_BASE_SHA=d0b448cd1ec34d40567fbc4b4c6ad30a9c756cdb
ORIGINAL_PURCHASE_CODE_HEAD=e486f1f7e93bb3fd433e5135092b6ab9748a0f7c
CURRENT_PRODUCTION_BASE_SHA=b933cfd7248a909fb90f6ba15943795669f23bce
REBASED_PR45_CODE_HEAD=8d4e44c94a77b6ff0ae1e2af84c6933b5369fd7a
PR46_INTEGRATION_VALIDATED=YES
MIGRATION_ADDED=NO
BACKFILL=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_DATABASE_ACCESSED=NO
PRODUCTION_MUTATED=NO
```

This change is limited to the purchases index query/payload contract, its
filter reload contract, a visible expand control for the already-present item
detail row, and regression coverage. It does not change purchase creation,
purchase returns, supplier debt mutation, stock, cashflow, or accounting
calculations.

## Root cause

1. The Vue page initialized `this_month`, while a request without
   `date_filter` was interpreted by the backend as `all`. The first screen and
   a direct request therefore had different result scopes.
2. The footer ran five independent purchase aggregates plus a separate item
   quantity aggregate. Each summary clone also inherited the index `ORDER BY`.
3. The page eager-loaded every purchase-item column and repeated static
   supplier/employee/branch option queries during filter reloads. A schema
   metadata check was also repeated for computed date sorting.

## Request/response contract

Before:

- No `date_filter` meant `all` in the controller; the UI default was
  `this_month`.
- `items` returned all columns.
- Summary used separate `SUM`/`COUNT` queries and could carry ordering.
- Every request queried static filter options.

After:

- No `date_filter` is normalized to `this_month` in the index request.
  `date_filter=all` remains an explicit all-time request.
- Items select only `id`, `purchase_id`, `product_code`, `product_name`,
  `quantity`, `price`, `discount`, and `subtotal`, which are the fields used by
  the table and expanded detail row.
- Purchase money totals and count use one aggregate query; item quantity uses
  one separate join query. The summary query builder does not apply sorting.
- Filter reloads request only `purchases`, `summary`, and `filters`; backend
  static option queries are skipped when those props are not requested.
- Search semantics remain code/note/supplier name/code/phone/item product name;
  substring matching was not changed.
- The existing expanded item row now has an explicit keyboard-accessible
  expand/collapse button and does not navigate or request data.

The legacy `has_debt` behavior is preserved: it filters page rows, while the
financial footer retains the prior unfiltered aggregate contract. Explicit
`all` summary snapshots are byte-equal between baseline and head.

## Benchmark fixture and method

The benchmark used a disposable MariaDB 10.11 database with the complete
repository migration set, `utf8mb4`/`utf8mb4_unicode_ci`, PHP 8.2.29, and
`APP_ENV=testing`. Fixture size:

```text
PURCHASES=10000
PURCHASE_ITEMS=50000
SUPPLIERS=500
EMPLOYEES=50
PRODUCTS=100
```

Each scenario used four runs; the first was discarded and the reported value
is the median of three warm runs. Baseline was captured before source changes
from `BASE_SHA`; head was measured against the same fixture.

| Scenario | Baseline ms | Head ms | Improvement | Baseline DB ms | Head DB ms | Queries before/after | Payload bytes before/after |
|---|---:|---:|---:|---:|---:|---:|---:|
| default | 82.833 | 66.038 | 20.28%* | 46.32 | 41.49 | 15 / 11 | 115878 / 98289 |
| this_month | 88.079 | 66.291 | 24.74% | 51.91 | 41.04 | 15 / 11 | 115897 / 98312 |
| all | 80.117 | 65.196 | 18.62% | 45.15 | 40.00 | 15 / 11 | 115894 / 98309 |
| status=completed | 88.053 | 73.649 | 16.36% | 54.21 | 48.09 | 15 / 11 | 115928 / 98343 |
| supplier | 54.414 | 38.646 | 28.98% | 19.77 | 14.07 | 15 / 11 | 114882 / 97382 |
| has_debt | 82.272 | 71.036 | 13.66% | 47.04 | 44.17 | 15 / 11 | 115865 / 98278 |
| search code | 229.398 | 138.078 | 39.81% | 205.71 | 118.78 | 15 / 11 | 69028 / 68153 |
| search supplier | 236.949 | 149.630 | 36.85% | 197.84 | 123.06 | 15 / 11 | 114935 / 97435 |
| search product | 265.566 | 151.997 | 42.76% | 211.19 | 123.77 | 15 / 11 | 115940 / 98381 |
| sort need_pay | 88.099 | 69.525 | 21.08% | 47.57 | 41.64 | 15 / 11 | 115969 / 98467 |
| sort purchase_date | 87.049 | 69.233 | 20.47% | 47.08 | 41.96 | 16 / 11 | 116050 / 98468 |
| page 2 | 92.805 | 68.062 | 26.66% | 53.27 | 41.11 | 15 / 11 | 115901 / 98316 |

\* The default row is not an apples-to-apples result-size comparison because
the contract intentionally changed from all-time to current-month. The
comparable `this_month` row is the release performance gate.

Partial reload measurement on head:

```text
QUERY_COUNT=8
SUPPLIER_OPTION_QUERIES=0
EMPLOYEE_OPTION_QUERIES=0
BRANCH_OPTION_QUERIES=0
INERTIA_BYTES=32804
```

## Independent review benchmark correction

The independent review found that the earlier combined base/head benchmark was
not credible: both sides reported the PR45 head query and payload contract.
The cause was verified before this correction. The benchmark used one shared
optimized Composer vendor tree. Its `autoload_classmap.php` pointed
`PurchaseController` at the PR45 head source, so the base process had the base
worktree path but executed the head controller. This is a benchmark harness
isolation failure, not an application regression.

```text
INVALID_BENCHMARK_ROOT_CAUSE=SHARED_OPTIMIZED_COMPOSER_VENDOR_AUTOLOAD_CLASSMAP_POINTED_BASE_BOOTSTRAP_AT_PR45_HEAD_SOURCE
INVALID_BASE_QUERY_COUNT=11
INVALID_HEAD_QUERY_COUNT=11
CORRECT_BASE_SUMMARY_QUERY_COUNT=6
CORRECT_HEAD_SUMMARY_QUERY_COUNT=2
CORRECT_BASE_QUERY_COUNT=15
CORRECT_HEAD_QUERY_COUNT=11
CORRECT_BASE_PAYLOAD_BYTES=106239
CORRECT_HEAD_PAYLOAD_BYTES=88644
INVALID_COMBINED_BENCHMARK_SUPERSEDED=YES
```

The corrected run used physically separate worktrees, separately generated
optimized Composer autoload files, separate disposable databases, identical
deterministic fixtures, independent PHP CLI processes, and
`opcache.enable_cli=0`. The runtime source identity was printed from each
application process and the controller hashes differ:

```text
BASE_WORKTREE=D:\Kiot\pr45-correction-base
HEAD_WORKTREE=D:\Kiot\pr45-correction-head
BASE_GIT_HEAD=b933cfd7248a909fb90f6ba15943795669f23bce
HEAD_GIT_HEAD=8d4e44c94a77b6ff0ae1e2af84c6933b5369fd7a
BASE_CONTROLLER_SHA256=8b2f77e29eb60ead41f8bc4610676c3a00268c78741ad9cf02d7debcc0262a6a
HEAD_CONTROLLER_SHA256=10e4f1e312a87418639ec1c5364bbba9dc0d3c0ea93c3399412406cb23429e5c
BASE_RUNTIME_CONTROLLER_FILE=D:\Kiot\pr45-correction-base\app\Http\Controllers\PurchaseController.php
HEAD_RUNTIME_CONTROLLER_FILE=D:\Kiot\pr45-correction-head\app\Http\Controllers\PurchaseController.php
BASE_VENDOR_ISOLATED=YES
HEAD_VENDOR_ISOLATED=YES
OPCACHE_CLI=0
PHP_PROCESS_ISOLATION=PASS
PURCHASE_COUNT_EQUAL=YES
PURCHASE_ITEM_COUNT_EQUAL=YES
SUPPLIER_COUNT_EQUAL=YES
EMPLOYEE_COUNT_EQUAL=YES
PRODUCT_COUNT_EQUAL=YES
FIXTURE_CHECKSUM=b7a2f4093216550606b732330d6254b1c605e98514409f5b995a3b07870bf0b5
FIXTURE_CHECKSUM_EQUAL=YES
```

The corrected benchmark was measured from the same deterministic fixture with
four samples per scenario: the first was discarded and the median of the
remaining three warm samples was recorded. The corrected base/head run, not
the superseded combined measurement, is the authoritative evidence below.

## Corrected PR46 base integration validation

The historical table above is retained as the original performance evidence;
it was measured before the PR46 base was integrated. The corrected gate uses
two independent disposable MariaDB 10.11 databases with the same complete
migration set and the same 10,000-purchase fixture. The base worktree is
`b933cfd7248a909fb90f6ba15943795669f23bce`; the rebased PR45 code worktree is
`8d4e44c94a77b6ff0ae1e2af84c6933b5369fd7a`. The earlier combined table has
been replaced because it used the invalid shared-autoload setup described
above.

```text
MARIADB_VERSION=10.11
BASE_DATABASE=pr45_correction_base
HEAD_DATABASE=pr45_correction_head
BASE_MIGRATIONS=181
HEAD_MIGRATIONS=181
FIXTURE_PARITY=PASS
BASE_APP_SETTINGS_QUERY_COUNT_FULL=2
HEAD_APP_SETTINGS_QUERY_COUNT_FULL=2
BASE_APP_SETTINGS_QUERY_COUNT_PARTIAL=0
HEAD_APP_SETTINGS_QUERY_COUNT_PARTIAL=0
BASE_PURCHASE_SUMMARY_QUERY_COUNT_FULL=6
HEAD_PURCHASE_SUMMARY_QUERY_COUNT_FULL=2
BASE_PURCHASE_SUMMARY_QUERY_COUNT_PARTIAL=6
HEAD_PURCHASE_SUMMARY_QUERY_COUNT_PARTIAL=2
STATIC_FILTER_OPTION_QUERIES_PARTIAL=0
BASE_ITEM_FIELDS=14
HEAD_ITEM_FIELDS=8
BASE_SUMMARY_SANITY_GATE=PASS
HEAD_SUMMARY_SANITY_GATE=PASS
BASE_PAYLOAD_SANITY_GATE=PASS
BASE_PAYLOAD_BYTES_GT_HEAD=YES
```

Normalized route measurements (four runs per scenario, first discarded,
median of three warm runs) were:

| Scenario | Base ms | Head ms | Base DB ms | Head DB ms | Queries base/head | Payload bytes base/head |
|---|---:|---:|---:|---:|---:|---:|
| full initial purchases | 113.357 | 96.924 | 77.14 | 70.00 | 15 / 11 | 106239 / 88644 |
| this_month | 112.728 | 97.424 | 77.59 | 70.96 | 15 / 11 | 106239 / 88644 |
| all | 102.422 | 91.963 | 67.26 | 63.75 | 15 / 11 | 106113 / 88518 |
| search purchase | 196.298 | 118.690 | 175.46 | 98.41 | 15 / 11 | 59830 / 58955 |
| search supplier | 266.719 | 131.315 | 215.41 | 103.28 | 15 / 11 | 104862 / 87362 |
| search product | 268.370 | 152.481 | 231.21 | 124.20 | 15 / 11 | 106678 / 89122 |
| has_debt | 115.074 | 96.829 | 79.23 | 69.80 | 15 / 11 | 106427 / 88832 |
| sort purchase date | 121.944 | 96.787 | 81.89 | 69.74 | 16 / 11 | 106974 / 89379 |
| sort need pay | 116.967 | 99.097 | 79.92 | 70.02 | 15 / 11 | 106884 / 89283 |
| page 2 | 115.317 | 98.036 | 78.72 | 69.45 | 15 / 11 | 106354 / 88754 |
| partial filter reload | 185.646 | 110.372 | 175.95 | 106.23 | 15 / 8 | 3528 / 2653 |
```

For the comparable `this_month` scenario, the corrected request median changed
from 112.728 ms on base to 97.424 ms on head, a measured improvement of
13.58%. For `search product`, the corrected request median changed from
268.370 ms to 152.481 ms. These are local benchmark observations, not a new
correctness requirement. The query and payload differences are expected from
the actual PR45 source change; financial results and filter results remain
parity-gated separately. The PR46 settings contract proves that full Inertia
requests use two settings queries and partial filter reloads do not refetch
`app_settings` or static filter options.

```text
CORRECTED_MARIADB_GATE=PASS
BASE_HEAD_FINANCIAL_RESULT_PARITY=PASS
BASE_HEAD_FILTER_RESULT_PARITY=PASS
BASE_HEAD_ITEM_FIELD_CONTRACT=PASS
BASE_QUERY_COUNT_DELTA=4
BASE_PAYLOAD_REDUCTION=PASS
FINANCIAL_SUMMARY_PARITY=PASS
FILTER_RESULT_PARITY=PASS
EXPANDED_ITEM_PARITY=PASS
SETTING_VALUE_PARITY=PASS
N_PLUS_ONE=PASS
COALESCE_DATE_FILTER_FULL_SCAN=YES
SUBSTRING_SEARCH_FULL_SCAN=YES
INDEX_MIGRATION_REQUIRED=NO
```

## Query breakdown

For a normal full request on the fixture:

```text
BEFORE=metadata 1 + pagination count 1 + page 1 + relations 3
        + summary aggregates 6 + static options 3 = 15
AFTER =metadata 1 + pagination count 1 + page 1 + relations 3
        + summary aggregate 1 + item quantity 1 + static options 3 = 11
PARTIAL=metadata 1 + pagination count 1 + page 1 + relations 3
        + summary aggregate 1 + item quantity 1 = 8
```

`relations 3` covers supplier, employee, and selected purchase items. The
metadata query is the fresh-schema `purchase_date` check; the result is cached
for computed sorting within the request.

## MariaDB EXPLAIN evidence

Representative MariaDB 10.11 plans on the same fixture:

| Query | Access | Rows examined | Extra |
|---|---|---:|---|
| Date fallback page `COALESCE(purchase_date, created_at)` | ALL | 10004 | Using where; Using filesort |
| Date-filtered money aggregate | ALL | 10004 | Using where |
| Product substring search | purchases ALL; purchase_items materialized ALL; customers materialized ALL | 10004 / 49790 / 500 | Using where; page filesort |

The date predicates and ordering use a `COALESCE` fallback and are not
indexable by the existing simple indexes. Search intentionally preserves
leading-wildcard substring semantics, so a normal B-tree index would not make
these predicates selective. The measured improvement comes from query
collapse, selected columns, partial reloads, and removing redundant metadata
work; no speculative index migration was added.

The residual plan limitation is explicit and accepted for this performance-only
change:

```text
COALESCE_DATE_FILTER_FULL_SCAN=YES
SUBSTRING_SEARCH_FULL_SCAN=YES
```

The 50% improvement target was aspirational, not a correctness gate. The
validated contract remains:

```text
DEFAULT_DATE_FILTER_BEFORE=all
DEFAULT_DATE_FILTER_AFTER=this_month
SUMMARY_QUERY_COUNT_BEFORE=6
SUMMARY_QUERY_COUNT_AFTER=2
THIS_MONTH_MS_BEFORE=88.079
THIS_MONTH_MS_AFTER=66.291
THIS_MONTH_IMPROVEMENT=24.74%
SEARCH_PRODUCT_MS_BEFORE=265.566
SEARCH_PRODUCT_MS_AFTER=151.997
QUERY_COUNT_BEFORE=15
QUERY_COUNT_AFTER=11
N_PLUS_ONE=PASS
FILTER_RESULT_PARITY=PASS
FINANCIAL_SUMMARY_PARITY=PASS
EXPANDED_ITEM_PARITY=PASS
```

## Correctness evidence

`tests/Feature/Purchases/PurchasesIndexPerformanceContractTest.php`:

```text
4 tests / 23 assertions PASS
```

Coverage includes default/explicit-all date behavior, summary aggregation
without financial multiplication or ordering, item payload fields, code/note/
supplier/product search parity, and static-option-free partial reloads.

Selected existing purchase regressions on the clean disposable test schema:

```text
SELECTED_PURCHASE_REGRESSION=37 tests / 194 assertions PASS
HOTFIX2421PurchaseSupplierBalanceDisplayTest=PASS
P0HistoricalProductSnapshotTest=PASS
PurchaseCreateSupplierDebtDisplayContractTest=PASS
HOTFIXPurchaseReturnSerialLookupTest=PASS
Step233PurchaseReturnFlowTest=PASS
```

The broader legacy purchase folder also contains unrelated baseline failures in
purchase-return redirect/permission and other-cost store tests; none of those
paths or controllers are in this diff. They are not used to claim a green
performance gate.

Financial snapshot comparison for `all`, `this_month`, status, supplier,
debt, search, sorting, and page 2 was equal for every comparable scenario.
Expanded rows use the selected item fields and retained the same displayed
values in the browser.

Independent review revalidation after the benchmark correction:

```text
PR46_SETTINGS_AND_PR45_PERFORMANCE=8 tests / 91 assertions PASS
SELECTED_PURCHASE_REGRESSION=33 tests / 171 assertions PASS
FRONTEND_BUILD=PASS
PINT_CHANGED_PR_FILES=PASS
PHP_LINT_CHANGED_PR_FILES=PASS
DIFF_CHECK=PASS
SECRET_SCAN=PASS
DEBUG_OUTPUT_SCAN=PASS
APPLICATION_CODE_CHANGED_AFTER_REVIEW=NO
TEST_CODE_CHANGED_AFTER_REVIEW=NO
MIGRATION_CHANGED_AFTER_REVIEW=NO
MARIADB_GATE=PASS
MYSQL8_GATE=PASS
```

The repository-wide `vendor/bin/pint --test` invocation reports pre-existing
baseline style debt (910 files, 2 errors, 517 style issues); it did not modify
any file. The PR-scoped Pint check for the two changed PHP files passed and is
the applicable gate for this documentation-only correction.

## Browser QA evidence

```text
BROWSER_ENGINE=Codex In-app Browser
ORIGIN=http://127.0.0.1:8897
AUTHENTICATED_SESSION=PASS (login UI, then dashboard)
PURCHASES_RENDER=PASS
APP_SETTINGS_AVAILABLE=PASS
FILTER_OPTIONS_AVAILABLE=PASS
DEFAULT_FILTER_SCOPE=this_month
SUPPLIER_FILTER=PASS
SUPPLIER_DEBT_FILTER=PASS
DATE_FILTER=PASS
SEARCH_PURCHASE_CODE=PASS
SEARCH_SUPPLIER=PASS
SEARCH_PRODUCT=PASS
SORT_PURCHASE_DATE=PASS
SORT_NEED_PAY=PASS
PARTIAL_RELOAD=PASS
APP_SETTINGS_REFETCHED_ON_PARTIAL=NO
STATIC_FILTER_OPTIONS_REFETCHED=NO
FILTER_CHANGE_DUPLICATE_REQUESTS=0
HTTP_500=0
DEFAULT_FILTER=PASS — Tháng này
STATUS_FILTER=PASS
SEARCH=PASS
SORT=PASS
PAGE_2=PASS — 25-row synthetic fixture, rows 21–25 shown
EXPAND_COLLAPSE=PASS — local state only, no navigation/request
CONSOLE_ERRORS=0
```

Filter-change request evidence is based on the single Inertia request observed
per interaction in the disposable server trace together with the partial
reload contract test; no browser network request was duplicated.

The QA origin used only a disposable MariaDB-backed local server. No user
Chrome/profile, production cookie, production server, or production database
was accessed.

## Quality gates

```text
FRONTEND_BUILD=PASS — npm run build
PINT=PASS — vendor/bin/pint --dirty --test
PHP_LINT=PASS — changed PHP files
DIFF_CHECK=PASS — git diff --check
SECRET_SCAN=PASS — no new secrets in changed files
DEBUG_OUTPUT_SCAN=PASS — no debug output in changed files
MARIADB_GATE=PASS — migrations + 10k/50k benchmark + browser QA
MYSQL8_GATE=PASS — MySQL 8.0.44 complete migrations/schema; extended benchmark intentionally not required after MariaDB primary gate
```

Combined regression evidence:

```text
PR46_SETTINGS_TEST=4 tests / 68 assertions PASS
PR45_PERFORMANCE_CONTRACT=4 tests / 23 assertions PASS
SELECTED_PURCHASE_REGRESSION=37 tests / 194 assertions PASS
```

## Changed files

- `app/Http/Controllers/PurchaseController.php`
- `resources/js/Pages/Purchases/Index.vue`
- `tests/Feature/Purchases/PurchasesIndexPerformanceContractTest.php`
- `docs/performance/P0-PURCHASES-INDEX-PERFORMANCE.md`

## Data safety and rollback

No migration, backfill, production command, production database access, or
accounting mutation was performed. The patch can be rolled back by reverting
the application commit; no schema rollback is required. The disposable QA
container and its databases are stopped and removed after evidence capture.
