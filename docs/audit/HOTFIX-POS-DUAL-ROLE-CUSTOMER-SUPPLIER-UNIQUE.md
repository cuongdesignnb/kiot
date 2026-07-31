# Hotfix: POS dual-role customer/supplier unique validation

## Root cause

`POST /api/pos/customers` and `POST /customers` validated `code` and `phone`
as globally unique before inspecting the linking intent. POS then always called
`Customer::create()`, so selecting an existing supplier with the same code or
phone failed before the partner could be promoted. The customer controller had
the same ordering problem and a separate persistence implementation. The
frontend displayed the raw validation key with browser `alert()`. The supplier
Index page also retained a separate inline create form, so it did not expose
the existing-customer selector used by the shared modal.

`customers` is the shared partner table. Adding the other role is a role
promotion, not a merge and not creation of a second row.

## Request/response contract

### Before

- `code` and `phone` uniqueness ran before `supplier_linking_mode`.
- POS ignored the linking fields and created a second row.
- `/customers` and POS could diverge in link behavior.
- Duplicate responses could expose `validation.unique`; the modal used browser
  alert instead of field-level errors.

### After

All customer and supplier create/link paths use
`PartnerRoleService::createOrLink()`:

- `new`: creates exactly one row. Customer context generates `KH...` codes;
  supplier quick-store generates `NCC...` codes.
- `link_existing`: locks the selected row in a DB transaction and updates only
  the missing role flag. Customer context requires target `is_supplier=true`
  and promotes `is_customer=true`; supplier context requires target
  `is_customer=true` and promotes `is_supplier=true`.
- A target must exist, have the counterpart role, have `merged_into_id IS NULL`,
  and have status NULL or not `inactive`.
- Link mode never copies code, phone, name, debt, totals, documents, history,
  or any financial fields, and never calls `PartnerMergeService`.
- `link_existing` without the counterpart role flag is rejected as
  `PARTNER_VALIDATION_FAILED` with Vietnamese field errors and no mutation.

New customer request example:

```json
{
  "supplier_linking_mode": "new",
  "name": "Customer A",
  "code": "KH001",
  "phone": "0900000000",
  "is_customer": true,
  "is_supplier": true
}
```

Customer-context link example:

```json
{
  "supplier_linking_mode": "link_existing",
  "linked_supplier_id": 10,
  "is_customer": true,
  "is_supplier": true
}
```

Successful link returns HTTP 200 and the selected row in `customer` (or
`supplier` for supplier quick-store). The row count is unchanged.

Duplicate `new` returns HTTP 422 without mutation:

```json
{
  "success": false,
  "code": "PARTNER_ALREADY_EXISTS",
  "message": "A partner with this information already exists.",
  "errors": {
    "phone": ["Phone number already exists."]
  },
  "existing_partner": {
    "id": 10,
    "code": "NCC001",
    "name": "Supplier A",
    "phone": "0900000000",
    "is_customer": false,
    "is_supplier": true
  },
  "suggested_action": "link_existing"
}
```

Validation failures return HTTP 422 with `code` set to
`PARTNER_VALIDATION_FAILED`, Vietnamese messages in `errors`, and no
`validation.*` key. No response in this flow contains `validation.unique`.

## Files changed

- `app/Services/PartnerRoleService.php` - shared transactional create/link
  contract for both directions, duplicate detection, target validation, code
  prefixes, and safe role promotion.
- `app/Services/PartnerTransactionGuard.php` - canonical availability rule;
  NULL status remains available while inactive and merged partners are blocked.
- `app/Http/Controllers/PosController.php` - accepts linking fields and uses
  the shared service with structured Vietnamese JSON errors.
- `app/Http/Controllers/CustomerController.php` - removes premature unique
  validation and uses the same contract for `/customers` parity.
- `app/Http/Controllers/SupplierController.php` - aligns supplier quick-store
  and full `/suppliers` store with the shared create/link contract, including
  `linked_customer_id` for supplier-context links.
- `resources/js/Pages/Suppliers/Index.vue` - removes the duplicate inline create
  form and uses `QuickCreateCustomerModal` with supplier context; reloads the
  supplier list and summary while preserving filters and page state.
- `resources/js/Components/QuickCreateCustomerModal.vue` - computed dual-role
  guard, stale-state reset, field errors, duplicate partner CTA, required
  selection, and created-entity emission without browser alert.
- `tests/Feature/Customers/P0PosCustomerSupplierDualRoleUniqueValidationTest.php`
  - endpoint parity, both link directions, role constraints, count and
  financial/document preservation, duplicate, stale payload, invalid target,
  prefix, and Vietnamese validation coverage.
- `tests/Feature/Supplier/SupplierStoreParityTest.php` - full `/suppliers`
  store parity, both supplier directions, duplicate/stale/invalid targets,
  row-count and financial-history preservation.
- `docs/audit/HOTFIX-POS-DUAL-ROLE-CUSTOMER-SUPPLIER-UNIQUE.md` - this audit.

Supplier entry-point inventory:

- `Suppliers/Index.vue` -> `POST /suppliers` (full form, shared modal).
- `Purchases/Create.vue` -> `POST /api/suppliers/quick-store` (shared modal,
  purchase draft callback).
- `Purchases/Edit.vue` -> `POST /api/suppliers/quick-store` (shared modal,
  purchase draft callback).
- No separate supplier quick-create entry was found under PurchaseOrders.

No migration or backfill was added.

## Test evidence

Executed against the local MySQL test container at `127.0.0.1:3319`:

- P0 suite: PASS, 12 tests / 96 assertions.
- `SupplierStoreParityTest`: PASS, 4 tests / 41 assertions.
- `Step2413QuickCreateEntityFlowTest` plus POS customer-group suite: PASS,
  10 tests / 39 assertions.
- RR01 supplier dual-role plus RR06 customer debt suites: PASS, 7 tests / 18
  assertions.
- `npm run build`: PASS.
- Pint on changed PHP files: PASS.
- PHP lint on changed PHP files: PASS.
- `git diff --check`: PASS.

The exact two-file timeline run at BASE_SHA and at the updated HEAD both had
9 failures / 12 tests / 52 assertions, with identical failure names and
locations (4 customer and 5 supplier legacy timeline contracts). Therefore no
new timeline regression was introduced by this hotfix. Those pre-existing
failures include `customer_effect`, `supplier_ledger_mirror`, `display_mode`,
event-kind/display labels, and pagination expectations.

The local test schema declares `customers.status` NOT NULL, so the NULL-status
test asserts the canonical availability predicate directly and runs link
coverage with the available active fixture. Production legacy rows with NULL
status are accepted by that same predicate. PHP emitted pre-existing warnings
for unavailable OCI/Firebird extensions; they did not affect the passing
targeted suites. No production write command was run; the authorized
production read-only result is recorded below.

## Manual QA checklist

Browser: local app at `http://127.0.0.1:8000`, authenticated admin session,
branch `codex/p0-fix-pos-customer-supplier-dual-role`, 2026-07-31. The browser
session used local MySQL only. Evidence is recorded below; no production
partner was used or changed.

| Case | EXPECTED | ACTUAL | Result |
|---|---|---|---|
| POS draft preservation + normal customer | Customer is selected; cart, invoice tab, payment, note and draft remain. | Cart retained `Sạc Dell Type C 65W` x1, tab remained `Hóa đơn 1 (1)`, payment remained `500.000`, note remained `QA draft note preserved`; selected `QA POS Customer 20260731`. | PASS |
| POS new dual-role | One new row, selected in POS, customer and supplier roles set. | Selected `QA POS Dual Role 20260731`; local read-only query returned `KH2026073116483852`, `is_customer=1`, `is_supplier=1`. | PASS |
| POS CTA customer→existing supplier | Existing row is promoted, no second row, financial fields unchanged. | CTA selected the supplier, save selected `QA Supplier 20260731`; local count was 325 before and after; id 1086 retained code/phone/debt/totals and changed only role to `is_customer=1,is_supplier=1`. | PASS |
| Existing target then dual-role off | Target selection and link mode are cleared; no implicit link. | Selected target chip disappeared, checkbox became unchecked, modal closed without submit; no browser alert. | PASS |
| Suppliers Index shared modal | Supplier creation uses the shared modal and list reload preserves the current page/filter state. | `/suppliers` opened `Tạo nhà cung cấp` with the same dual-role controls; successful create returned to the list with the pagination context intact. | PASS |
| Supplier quick-create new | New supplier is persisted with `NCC` code prefix. | Created `QA Shared Supplier 20260731`; local read-only query returned id 1087, `NCC2026073118541196`, `is_supplier=1,is_customer=1`; no alert. | PASS |
| Supplier quick-create link existing customer | UI offers existing-customer selection and links one row. | On `/suppliers`, selected `QA POS Customer 20260731` (id 1084), saved, and list reloaded; total customer rows stayed 326 and id 1084 changed only to `is_supplier=1` while code/name/financial fields stayed unchanged. | PASS |
| Supplier duplicate code + phone | HTTP 422 with Vietnamese field errors, found partner and CTA; no browser alert or mutation. | Modal showed `Mã đối tác đã tồn tại.`, `Số điện thoại đã tồn tại.`, found `QA Supplier 20260731 · NCC178549138335 · 0991234567`, CTA `Dùng đối tác này và liên kết`; `getJsDialog()` was undefined. | PASS |
| Supplier existing target then dual-role off | Target selection and link mode are cleared; no implicit link. | After selecting id 1084 then turning off dual-role, existing option and target selection disappeared, checkbox was unchecked, and the modal was closed without submit. | PASS |
| Purchases/Create supplier entry | Shared modal opens and purchase draft remains intact. | Fresh-schema rerun passed on the disposable QA database below; no `categories.deleted_at` error appeared and the draft remained unchanged through both supplier flows. | PASS |
| Purchases/Edit supplier entry | Shared modal opens; entered purchase fields remain after close. | `/purchases/428/edit` rendered; `Ghi chú đơn nhập` stayed `QA purchase edit draft note` and quantity `5` after opening/closing the shared supplier modal. | PASS |
| Customer auto-code | New customer gets `KH` prefix. | Local read-only query returned `KH2026073116480060` and `KH2026073116483852` for the two POS-created customers. | PASS |
| Browser alert validation | Validation must remain inline. | Duplicate and link flows had no native JS dialog; errors stayed in the modal. | PASS |

### Fresh-schema Purchases/Create QA

The stale local database was not repaired. A disposable, local-only database
`pr37_fresh_qa_20260731` was created in the non-production MySQL test container
at `127.0.0.1:3319`. It was separate from the old local database and was never
connected to production. The complete repository migration set contained 179
files and the fresh database contained 179 applied migrations in batch 1.
Only minimum browser-QA seed data was added: the standard seeders, one branch,
one existing customer with one invoice for preservation checks, and no
production data.

| Case | EXPECTED | ACTUAL | Result |
|---|---|---|---|
| Fresh schema gate | `categories` exists with `deleted_at`; `customers.status` exists. | Read-only schema inspection returned `categories=YES`, `categories.deleted_at=YES`, `customers.status=YES`, and migrations `179/179`. | PASS |
| Purchases/Create render | Page renders without `categories.deleted_at` error. | Authenticated browser opened `http://127.0.0.1:8019/purchases/create` successfully on the fresh database. | PASS |
| Purchase draft setup | Supplier/product/quantity/note/payment can be entered. | Product `Hp 830 G5` x2, payment `100.000`, and note `PR37 fresh schema draft note` were visible before each modal operation. | PASS |
| Supplier quick-create new | Success, selected supplier, generated code starts `NCC`, draft unchanged. | Created id 2 `NCC2026073123013218` / `PR37 Fresh Supplier`; it became selected. Product, quantity, payment, and note remained unchanged. | PASS |
| Link existing customer as supplier | Same id; row count, identity, financial fields, and history unchanged; selected supplier and draft preserved. | Customer id 1 `KHPR370001` stayed the same; count stayed 2; only `is_supplier` changed `0→1`. Code/name/phone, debt/totals, and invoice count `1→1` were unchanged. It became selected and the draft remained unchanged. | PASS |
| Existing target then dual-role off | Target and link mode clear; no implicit link. | After selecting id 1 and disabling dual-role, the target chip and link radios disappeared, checkbox was unchecked, modal closed without save, and DB state stayed unchanged. | PASS |
| Browser validation alert | No native browser alert; validation remains in UI. | `getJsDialog()` was `undefined` after create, link, and stale-toggle flows. | PASS |

The fresh QA server used `SESSION_DRIVER=database` against the disposable
database so the authenticated browser session and CSRF token were persisted.
No migration was added to the PR and the old local database was not changed.

## Production read-only status audit

The production audit was performed read-only by the authorized operator. The
following is the approved read-only handoff command; it performs only `SELECT`,
returns count plus `id`/`code` samples, and must not be replaced with an
application write command:

```sh
mysql --defaults-extra-file=/secure/prod-readonly.cnf --batch --raw \
  --database="$DB_DATABASE" \
  --execute='SELECT COUNT(*) AS null_status_count FROM customers WHERE status IS NULL; SELECT id, code FROM customers WHERE status IS NULL ORDER BY id LIMIT 50;'
```

Production result: `SUPERSEDED - production read-only audit completed; see authoritative result below`. No production data was modified. The local QA
database is not a production substitute.

```text
PRODUCTION_CATEGORIES_TABLE_EXISTS=YES
PRODUCTION_CATEGORIES_DELETED_AT=YES
PRODUCTION_CUSTOMERS_STATUS_COLUMN=YES
PRODUCTION_NULL_STATUS_COUNT=0
PRODUCTION_NULL_STATUS_SAMPLE=[]
PRODUCTION_MIGRATIONS_PENDING=0
PRODUCTION_SCHEMA_GATE=PASS
```

Production `categories.deleted_at` is present and there are no
`customers.status IS NULL` rows requiring remediation. A staging clone is not
required for a status-NULL repair because the read-only count is zero; it
remains appropriate for any future schema or data change.

## Final release-gate evidence

- Targeted P0, SupplierStoreParity, Step2413/POS, RR01/RR06, build, Pint, PHP
  lint and diff checks are rerun after the final code/document update; results
  are recorded in the PR handoff.
- GitHub Actions status is checked for the final commit through the GitHub
  connector; the PR remains Draft and is not merged or deployed.
- No migration, backfill, delete, merge, checkout, invoice, purchase,
  cashflow or debt-calculation change was made.

## Data safety statement

This hotfix adds no migration, backfill, delete, or merge operation. Link mode
updates only one locked partner row and only the missing role flag. It does not
move documents, write debt/ledger rows, alter checkout, invoices, purchases,
cashflow, or debt calculations. Duplicate new requests are rejected before
`Customer::create()`.

## Rollback plan

Revert the hotfix commit or roll back the Draft PR. No database rollback or
backfill is needed because there is no schema or data migration. Any role
promotion already performed should be reviewed before a manual reversal; do
not delete or merge production partners as part of rollback.
