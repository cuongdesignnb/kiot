# Hotfix: POS dual-role customer/supplier unique validation

## Root cause

`POST /api/pos/customers` and `POST /customers` validated `code` and `phone`
as globally unique before inspecting the linking intent. POS then always called
`Customer::create()`, so selecting an existing supplier with the same code or
phone failed before the partner could be promoted. The customer controller had
the same ordering problem and a separate persistence implementation. The
frontend displayed the raw validation key with browser `alert()`.

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

All three quick-create paths use `PartnerRoleService::createOrLink()`:

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
  with the shared create/link contract.
- `resources/js/Components/QuickCreateCustomerModal.vue` - computed dual-role
  guard, stale-state reset, field errors, duplicate partner CTA, required
  selection, and created-entity emission without browser alert.
- `tests/Feature/Customers/P0PosCustomerSupplierDualRoleUniqueValidationTest.php`
  - endpoint parity, both link directions, role constraints, count and
  financial/document preservation, duplicate, stale payload, invalid target,
  prefix, and Vietnamese validation coverage.
- `docs/audit/HOTFIX-POS-DUAL-ROLE-CUSTOMER-SUPPLIER-UNIQUE.md` - this audit.

No migration or backfill was added.

## Test evidence

Executed against the local MySQL test container at `127.0.0.1:3319`:

- P0 suite: PASS, 12 tests / 96 assertions.
- `Step2413QuickCreateEntityFlowTest` plus POS customer-group suite: PASS,
  10 tests / 39 assertions.
- RR01 supplier dual-role plus RR06 customer debt suites: PASS, 7 tests / 18
  assertions.
- `npm run build`: PASS.
- Pint on changed PHP files: PASS, 6 files.
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
targeted suites. No production database command was run.

## Manual QA checklist

- [ ] POS normal customer creation selects the returned customer and preserves
      cart, active tab, note, and draft state.
- [ ] POS new dual-role creation creates exactly one row and selects it.
- [ ] POS customer-context link to an existing active supplier preserves code,
      phone, name, debts, totals, documents, history, and row count.
- [ ] Supplier quick-store link to an existing active customer preserves the
      same fields and row count.
- [ ] Duplicate new code/phone shows inline Vietnamese errors, the found partner,
      and a link-existing CTA instead of browser alert.
- [ ] Existing mode without a selected entity shows an inline selection error.
- [ ] Turning dual-role off after selecting an entity resets mode, entity, link
      id, search query, results, and link errors.
- [ ] Inactive, merged, nonexistent, and wrong-role targets are rejected with
      no mutation in both contexts.
- [ ] `/customers` has the same response and persistence behavior as POS.

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
