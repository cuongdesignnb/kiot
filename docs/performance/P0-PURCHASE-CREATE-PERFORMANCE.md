# P0 — Purchase create supplier debt performance

## Scope and safety

This hotfix removes the supplier-list N+1 on `/purchases/create` and the
equivalent eager work on purchase edit. It does not change purchase posting,
supplier debt mutation, canonical event reduction, checkout, inventory,
cashflow, or debt calculations.

```text
BASE_BRANCH=production-customer-group
BASE_SHA=6d633a293b355d160ceb08fcd5be16bc6ca68b24
BRANCH=codex/p0-purchase-create-performance
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
MIGRATION_ADDED=NO
BACKFILL=NO
```

The production baseline below is the operator-provided observation from the
incident audit. No production query was run by this change.

```text
PRODUCTION_SUPPLIERS=69
PRODUCTION_CANONICAL_DEBT_QUERIES_BEFORE=1549
PRODUCTION_TOTAL_PURCHASE_CREATE_QUERIES_BEFORE=1559
PRODUCTION_PURCHASE_CREATE_MS_BEFORE=3805
```

## Root cause

`PurchaseController::create()` and `PurchaseController::edit()` loaded every
available supplier and called `PartnerDebtDisplayBalance::aliases()` for each
row. That helper intentionally reduces the persisted canonical supplier debt
event stream, so one initial page request performed the full debt read for
every supplier. `SupplierController::search()` repeated the same work for
edit-time search results.

The old implementation therefore mixed a lightweight picker query with a
full canonical ledger reduction for every possible supplier. It also made it
easy for the frontend to display a stored projection as if it were a canonical
balance.

## Contract before and after

Before:

```text
GET /purchases/create
GET /purchases/{purchase}/edit
GET /api/suppliers/search
```

Each supplier row carried canonical debt aliases, and the backend calculated
those aliases before the operator selected a supplier.

After:

```text
GET /purchases/create
GET /purchases/{purchase}/edit
GET /api/suppliers/search
```

The initial/search row is limited to:

```text
id, code, name, phone, is_customer, is_supplier
```

The selected supplier is hydrated through the authenticated, read-only
endpoint:

```text
GET /purchases/suppliers/{supplier}/debt-display
```

The endpoint accepts only an active, unmerged supplier and returns the exact
`PartnerDebtDisplayBalance::responseAliases()` contract. Supplier-only and
dual-role partners therefore continue to use canonical persisted evidence;
stored `debt_amount` and `supplier_debt_amount` are not used as a substitute.

The Create and Edit pages keep a per-supplier cache, issue no mass debt
requests, show `Đang tải công nợ...` while the selected request is pending,
show `Không thể tải công nợ nhà cung cấp.` on failure, and use request tokens
to prevent a stale response from overwriting a newer selection. Re-selecting a
supplier uses the cache. Purchase items, note, payment fields, and browser
draft state stay in the page component.

## Changed files

```text
app/Http/Controllers/PurchaseController.php
app/Http/Controllers/SupplierController.php
routes/web.php
resources/js/Pages/Purchases/Create.vue
resources/js/Pages/Purchases/Edit.vue
tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php
tests/Feature/Purchases/HOTFIX2421PurchaseSupplierBalanceDisplayTest.php
tests/Feature/Purchases/PurchaseCreateSupplierDebtPerformanceTest.php
docs/performance/P0-PURCHASE-CREATE-PERFORMANCE.md
```

No migration, dependency, production configuration, or data repair was added.

## Test evidence

### Fresh schema and contract tests

```text
MYSQL_8_FRESH_MIGRATIONS=PASS
MYSQL_8_TARGETED_TESTS=10 passed / 107 assertions
MARIADB_VERSION=10.11.18-MariaDB-ubu2204
MARIADB_10_11_FRESH_MIGRATIONS=PASS
MARIADB_10_11_TARGETED_TESTS=6 passed / 86 assertions
```

`PurchaseCreateSupplierDebtPerformanceTest` covers:

```text
- 5-to-100 supplier initial payload query scaling
- zero canonical event queries during the initial supplier list request
- lightweight create payload and supplier search response
- canonical alias parity for supplier-only and dual-role fixtures
- purchase, purchase-return, payment, and debt-offset evidence
- rejection of inactive, merged, and non-supplier targets without mutation
```

On MariaDB 10.11 with 69 suppliers, a direct controller measurement recorded
7 total initial create queries and 0 canonical debt-event queries. The
production incident baseline was 1,559 total queries and 1,549 canonical
queries for the same supplier count; the before values are operator-provided,
not a production run by this agent.

### Debt and purchase regressions

```text
CANONICAL_PARTNER_DEBT_TIMELINE_CONTRACT=PASS
INACTIVE_SUPPLIER_PURCHASE_REGRESSION=PASS
RELATED_DEBT_REGRESSIONS=22 passed / 7 pre-existing baseline failures
```

The seven residual failures are in legacy
`SupplierDebtTimelineKiotStandardTest` and
`DualRolePartnerDebtTimelineTest` expectations for the older orientation
payload/mirror shape. This hotfix does not change any timeline core file or
orientation service, so those failures remain explicitly outside this P0
scope and are not counted as a new green claim.

### Frontend and static gates

```text
FRONTEND_BUILD=PASS
PINT_CHANGED_PHP=PASS
PHP_LINT=PASS
GIT_DIFF_CHECK=PASS
```

## Browser QA evidence

Environment:

```text
BROWSER_ENGINE=Codex managed in-app browser
ORIGIN=http://127.0.0.1:8894
DATABASE=disposable QA only
USER_CHROME_ACCESSED=NO
```

Expected / actual / result:

```text
CASE=Login and authenticated purchase-create page
EXPECTED=Authenticated local page renders without schema or debt error
ACTUAL=Login succeeded; /purchases/create rendered on loopback
PASS=YES

CASE=Initial supplier list
EXPECTED=Lightweight rows; no mass canonical debt request; no fake zero debt
ACTUAL=Supplier rows showed code/name/phone and neutral “Chọn để xem công nợ”; server log showed no mass debt requests
PASS=YES

CASE=Select supplier
EXPECTED=Loading text, then canonical debt display from one lazy request
ACTUAL=Selected supplier showed loading state and then “Nợ cũ NCC”; one endpoint request was observed
PASS=YES

CASE=Re-select supplier
EXPECTED=Cache hit, no second debt request
ACTUAL=After clearing and selecting the same supplier again, the balance card remained and no additional endpoint request was observed
PASS=YES

CASE=Purchase draft preservation
EXPECTED=Product and note remain after supplier selection changes
ACTUAL=Product “Sản phẩm Browser QA” and note “QA draft note preserved” remained in the form; selected supplier remained selected
PASS=YES

CASE=Purchase edit
EXPECTED=Existing supplier hydrates canonical debt lazily without eager list reduction
ACTUAL=/purchases/10/edit rendered and showed canonical “Nợ hiện tại NCC” after the selected-supplier request
PASS=YES

CASE=Browser validation alerts
EXPECTED=No validation alert is used for debt loading failure
ACTUAL=No browser alert appeared during create/edit lazy debt checks
PASS=YES
```

## Data safety statement

The endpoint is read-only. Initial and search payload changes only reduce
selected columns. Selecting a supplier does not update a partner, ledger,
purchase, product, stock, cashflow, or debt row. No production server,
production database, production dump, migration command, backfill, delete, or
merge was used.

QA used disposable MySQL 8 and MariaDB 10.11 containers with fresh migrations;
the containers are not application production services.

## Residual risks

The canonical endpoint still performs the intentionally complete debt reduction
for the one supplier selected by the operator. That is the retained correctness
contract. It is not a substitute for a future canonical debt read model.

The existing legacy orientation regression failures listed above remain a
baseline risk and should be handled in a separate timeline-contract change.

## Rollback plan

If the hotfix must be rolled back, revert the application commit containing
the lazy endpoint/list/frontend changes and redeploy the previous application
artifact through the normal operator-controlled release process. No database
rollback or data repair is required because this change adds no migration and
performs no writes to supplier debt data.
