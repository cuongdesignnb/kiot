# Hotfix: POS dual-role customer/supplier unique validation

## Root cause

The POS quick-create flow used `PosController::quickCreateCustomer`, which ran
`unique:customers,code` and `unique:customers,phone` before persistence and then
always called `Customer::create()`. When the operator selected an existing
supplier, the request still contained that supplier's code/phone, so the request
failed before the existing partner could be promoted to customer role. The
`CustomerController::store` path had the same ordering problem and a separate
link implementation that could drift from POS. The modal then exposed the raw
validation key/message through `alert()`.

`customers` is the shared partner table. Adding the customer role to an existing
supplier is a role promotion, not a merge and not creation of a second row.

## Request/response contract

### Before

- `POST /api/pos/customers` and `POST /customers` validated `code` and `phone`
  as globally unique before looking at `supplier_linking_mode`.
- `link_existing_id`/`linked_supplier_id` could reach different controller
  implementations; POS ignored the linking fields.
- A duplicate commonly returned a generic validation response, and the modal
  showed it with browser `alert()`.

### After

Both endpoints use `PartnerRoleService::createOrLink()`.
The shared supplier quick-store endpoint used by purchase screens uses the same
contract so the modal cannot silently create a second supplier row in existing
mode.

For a new row:

```json
{
  "supplier_linking_mode": "new",
  "name": "Nguyễn Văn A",
  "code": "KH001",
  "phone": "0900000000",
  "is_customer": true,
  "is_supplier": true
}
```

If code or phone already belongs to a partner, the response is HTTP 422 and no
row is changed or inserted:

```json
{
  "success": false,
  "code": "PARTNER_ALREADY_EXISTS",
  "message": "Đối tác đã tồn tại. Vui lòng chọn đối tác có sẵn để liên kết.",
  "errors": {
    "phone": ["Số điện thoại đã tồn tại."]
  },
  "existing_partner": {
    "id": 10,
    "code": "NCC001",
    "name": "Nhà cung cấp A",
    "phone": "0900000000",
    "is_customer": false,
    "is_supplier": true
  },
  "suggested_action": "link_existing"
}
```

For an existing supplier:

```json
{
  "supplier_linking_mode": "link_existing",
  "linked_supplier_id": 10,
  "name": "Thông tin form chỉ dùng cho context UI",
  "code": "NCC001",
  "phone": "0900000000",
  "is_customer": true,
  "is_supplier": true
}
```

The response is HTTP 200 with `customer` equal to the selected row. The service
locks the target inside a database transaction, verifies that it exists, is a
supplier, is active, and has not been merged, then updates only
`is_customer=true` and `is_supplier=true`. Code, phone, profile identity,
financial totals, debt, documents, and row count are preserved.

Invalid link targets return HTTP 422 with:

```json
{
  "success": false,
  "code": "PARTNER_VALIDATION_FAILED",
  "message": "Thông tin đối tác chưa hợp lệ.",
  "errors": {
    "linked_supplier_id": ["Nhà cung cấp được chọn đang ngừng hoạt động."]
  }
}
```

No response in this flow contains `validation.*`.

## Files changed

- `app/Services/PartnerRoleService.php` — shared transactional create/link
  contract, duplicate detection, target validation, and safe role promotion.
- `app/Services/PartnerAlreadyExistsException.php` — structured duplicate
  contract data for both controllers.
- `app/Http/Controllers/PosController.php` — accepts linking fields and uses
  the shared service with Vietnamese JSON errors.
- `app/Http/Controllers/CustomerController.php` — removes premature unique
  validation and uses the same service contract for `/customers` parity.
- `app/Http/Controllers/SupplierController.php` — aligns supplier quick-store
  with the same create/link contract used by the shared modal.
- `resources/js/Components/QuickCreateCustomerModal.vue` — field-level errors,
  duplicate partner card/CTA, required existing selection, and no browser
  validation alert.
- `tests/Feature/Customers/P0PosCustomerSupplierDualRoleUniqueValidationTest.php`
  — P0 endpoint, role, count, financial-preservation, duplicate, and invalid
  target regression coverage.
- `docs/audit/HOTFIX-POS-DUAL-ROLE-CUSTOMER-SUPPLIER-UNIQUE.md` — this audit.

No migration or backfill was added.

## Test evidence

Executed against the repository's local MySQL test container on `127.0.0.1:3319`:

- `php artisan test tests/Feature/Customers/P0PosCustomerSupplierDualRoleUniqueValidationTest.php`
  — PASS, 6 tests / 51 assertions.
- `php artisan test tests/Feature/QuickCreate/Step2413QuickCreateEntityFlowTest.php tests/Feature/POS/Hotfix246CPosQuickCreateCustomerGroupDropdownTest.php`
  — PASS, 10 tests / 39 assertions.
- `php artisan test tests/Feature/Supplier/RR01SupplierDualRoleRegressionTest.php tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php`
  — PASS, 7 tests / 18 assertions.
- `npm run build` — PASS, Vite production build.
- `vendor/bin/pint` on all changed PHP files — PASS, 5 files.
- `php -l` on changed PHP files — PASS.
- `git diff --check` — PASS.

The first test attempt was blocked because the local MySQL test container was
stopped; it was started with the repo's `docker-compose.testing.yml`. No
production database command was run. PHP emitted pre-existing startup warnings
for unavailable OCI/Firebird extensions; they did not affect the passing tests.

The existing `Step2413QuickCreateEntityFlowTest` remains passing.

The broader existing `DualRolePartnerDebtTimelineTest` and
`SupplierDualRolePartnerTimelineTest` suites were also run and currently have
9 baseline failures in legacy timeline field/display contracts (for example
`customer_effect`, `supplier_ledger_mirror`, and `display_mode`). They do not
touch the changed create/link service or controller paths and were not changed
by this hotfix; they remain a release risk to resolve separately.

## Manual QA checklist

- [ ] POS: create a normal customer; customer is selected in the active POS tab.
- [ ] POS: create a new dual-role customer/supplier; exactly one partner row is
      created and the selected customer remains in the current tab.
- [ ] POS: select an active existing supplier, keep its code/phone, enable the
      customer role, and save; no duplicate row is created.
- [ ] POS: verify code, phone, customer debt, supplier debt, totals, invoices,
      purchases, notes, and cart/draft state are unchanged after linking.
- [ ] POS: submit duplicate new code/phone; verify inline Vietnamese field
      errors, found-partner card, and link CTA instead of browser alert.
- [ ] POS: choose existing mode without an entity; verify inline selection error
      and no request mutation.
- [ ] POS: try inactive, merged, non-supplier, and nonexistent targets; verify
      rejection and no mutation.
- [ ] Customers: repeat new, dual-role, existing-link, and duplicate flows and
      verify the same response semantics as POS.
- [ ] POS: after success, verify cart, active tab, note, and draft remain intact.

## Data safety statement

This hotfix adds no migration, backfill, delete, or merge operation. In
`link_existing`, only the two role flags on the locked target are updated. It
does not call `PartnerMergeService`, move documents, write debt/ledger rows, or
modify checkout, invoices, purchases, cashflow, or debt calculations. Duplicate
`new` requests are rejected before `Customer::create()` and therefore do not
mutate data.

## Rollback plan

Revert the hotfix commit or roll back the Draft PR. Because this change adds no
schema or data migration, rollback requires no database rollback and no
backfill. A role promotion made by this hotfix should be reviewed before any
manual reversal; do not delete or merge the partner as part of rollback.
