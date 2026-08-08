# P0 — `/purchases` index performance

## Scope and safety

```text
BASE_SHA=d0b448cd1ec34d40567fbc4b4c6ad30a9c756cdb
HEAD_SHA=e486f1f7e93bb3fd433e5135092b6ab9748a0f7c
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

## Browser QA evidence

```text
BROWSER_ENGINE=Codex In-app Browser
ORIGIN=http://127.0.0.1:8895
AUTHENTICATED_SESSION=PASS (login UI, then dashboard)
PURCHASES_RENDER=PASS
DEFAULT_FILTER=PASS — Tháng này
STATUS_FILTER=PASS
SEARCH=PASS
SORT=PASS
PAGE_2=PASS — 25-row synthetic fixture, rows 21–25 shown
EXPAND_COLLAPSE=PASS — local state only, no navigation/request
CONSOLE_ERRORS=0
```

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
