# Backport PR10 Debt Display Contract to Production Snapshot 2d5bfc0

## Context

- Repo: `cuongdesignnb/kiot`
- Base branch: `prod-snapshot/sanitized-production-customer-group-2d5bfc0-20260702`
- Base commit used locally: `6a65855 chore: sanitized production snapshot 2d5bfc0 for PR10 backport`
- Backport branch: `hotfix/pr10-debt-display-backport-prod-snapshot`
- Source PR: `#10`
- Source commit: `f406abb71f188794fb0440a328e0fa11f39fcec8`
- Scope: display/API compatibility only for debt timeline balances and supplier picker payloads.

## Safety Boundaries

- No full merge from `main`.
- No migration added.
- No backfill.
- No production DB command.
- No DB update/delete.
- No debt rebuild.
- No real opening balance mutation.
- No MERGE cleanup.
- No stock movement, costing, serial/IMEI, invoice write-path, cashflow write-path, or payroll changes.
- `npm run build` was executed only as a local build gate; generated `public/build` files are not part of this change.

## Backported Files

| Area | File | Notes |
|---|---|---|
| Customer API/display | `app/Http/Controllers/CustomerController.php` | Adds display balance aliases for customer-oriented debt payloads. |
| Supplier API/display | `app/Http/Controllers/SupplierController.php` | Adds display balance aliases and summary compatibility keys. Duplicate `current_debt` key was cleaned during backport without changing effective output. |
| Purchase supplier picker | `app/Http/Controllers/PurchaseController.php` | Keeps raw supplier payable and adds supplier screen balance aliases for UI compatibility. |
| Customer timeline | `app/Services/CustomerDebtDocumentTimelineService.php` | Aligns latest display running balance with the customer screen balance without mutating data. |
| Supplier timeline | `app/Services/SupplierDebtDocumentTimelineService.php` | Aligns latest display running balance with the supplier screen balance while preserving generic supplier payment ambiguity warnings. |
| Shared helper | `app/Support/Debt/PartnerDebtDisplayBalance.php` | Centralizes customer/supplier/dual-role display balance formulas. |
| Customer UI | `resources/js/Pages/Customers/Index.vue` | Uses display aliases for debt columns and timeline labels. |
| Supplier UI | `resources/js/Pages/Suppliers/Index.vue` | Uses supplier-oriented display aliases. |
| Purchase UI | `resources/js/Pages/Purchases/Create.vue`, `resources/js/Pages/Purchases/Edit.vue` | Shows supplier picker debt using the supplier display contract. |
| Tests | `tests/Feature/CustomerDebt/*`, `tests/Feature/Suppliers/*`, `tests/Feature/Purchases/*` | Backports PR10 display contract tests and related assertions. |

The original PR10 audit file was not kept in this backport because it describes the `main` PR context. This snapshot-specific report replaces it for review.

## Conflict Resolution

Manual conflict resolved in:

```text
app/Services/SupplierDebtDocumentTimelineService.php
```

Reason:

- The production snapshot already had P0 supplier generic payment diagnostics.
- PR10 added virtual display alignment so the latest running balance matches the screen balance.

Resolution:

- Keep PR10 display alignment for read-only response compatibility.
- Keep snapshot diagnostics for generic supplier payments without persisted allocation evidence.
- If a generic supplier payment is unallocated/ambiguous and the raw document balance still mismatches the stored balance, `reconcile.has_mismatch` remains `true` and `severity` remains `warning`.
- FIFO reconstruction is not treated as actual allocation evidence.

## Display Contract

Customer screen:

```text
customer_screen_balance = customer.debt_amount - customer.supplier_debt_amount
```

Supplier screen:

```text
supplier_screen_balance = customer.supplier_debt_amount - customer.debt_amount
```

Timeline:

```text
latest_display_running_balance == screen_balance
raw_document_final_balance remains exposed for audit
display_alignment_amount is response-only
```

## Data Impact

None.

This backport changes response/display calculation only. It does not write debt balances, allocations, purchases, invoices, cashflows, payroll records, inventory, cost, or serial data.

## Verification

Local test database:

```text
Docker MySQL container: kiot_pr10_backport_mysql
Database: kiot_pr10_backport_test
Environment: APP_ENV=testing
```

Migration gate on local testing DB:

```text
php artisan migrate --force
Result: PASS
```

Required PHP tests:

```text
php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php \
  tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php \
  tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php \
  tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php \
  tests/Feature/CustomerDebt/SapoDebtParityTest.php \
  tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php --compact

Result: PASS, 53 passed, 269 assertions
```

Backported PR10 contract tests:

```text
php artisan test tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php \
  tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php \
  tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php --compact

Result: PASS, 4 passed, 42 assertions
```

Frontend build:

```text
npm run build
Result: PASS
```

Diff hygiene:

```text
git diff --check
Result: PASS
```

Additional broad regression note:

```text
php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers tests/Feature/Purchases --compact
```

Initial broad run surfaced:

- `SupplierDebtTimelineParityTest::ambiguous generic payment does not cover future purchase or mutate data`: fixed in this backport and confirmed PASS in the required test run above.
- `Step248SupplierActionsTest::user without suppliers edit permission is blocked`: still outside this backport scope. The failure is a permission response expectation mismatch (`403` vs redirect `/`) and is not in touched display/timeline/write-path code.

PHP startup warnings about missing optional extensions (`oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`) appeared in local output. They did not fail the required tests.

## Senior Auditor Follow-up

- Reverted the non-scope CSV header change in `SupplierController`; purchase history export now uses the snapshot/base header `Mã phiếu`.
- Preserved raw `supplier_debt_amount` in purchase supplier picker payloads.
- Added response-only display alias `supplier_picker_display_balance`.
- Updated `Purchases/Create.vue` and `Purchases/Edit.vue` so local/search supplier mapping no longer overwrites `supplier_debt_amount`.
- Purchase picker display now uses `supplierDisplayBalance(...)` / `supplier_picker_display_balance` for display only.
- Re-ran required PHP tests: PASS, 53 passed, 269 assertions.
- Re-ran PR10 contract tests: PASS, 4 passed, 42 assertions.
- Re-ran `npm run build`: PASS.
- Re-ran `git diff --check`: PASS.

## Final Ready-for-review Gate

- PR base/head verified: PASS.
  - Base branch: `prod-snapshot/sanitized-production-customer-group-2d5bfc0-20260702`
  - Base SHA: `6a65855c9001e92fb18dec0638262ac17bf2a647`
  - Head branch: `hotfix/pr10-debt-display-backport-prod-snapshot`
  - Head SHA verified locally and on GitHub before this report update: `a04db817b65d5af387a5f942328534dcf13c8cd3`
- Files changed verified: PASS. The PR diff only includes the expected debt display/API compatibility files listed in this report.
- No migration/backfill/DB write: PASS.
- No production command/deploy: PASS.
- No forbidden file: PASS. No `.env`, logs, dumps, zip files, `vendor`, `node_modules`, `public/build`, `storage/logs`, or `bootstrap/cache` files are in the PR diff.
- Raw `supplier_debt_amount` preserved: PASS.
- `supplier_picker_display_balance` used for purchase picker display only: PASS.
- Supplier CSV header scope leak reverted: PASS.
- Supplier generic payment ambiguity warning preserved: PASS.
- Required PHP tests: PASS, 53 passed, 269 assertions.
- Contract tests: PASS, 4 passed, 42 assertions.
- `npm run build`: PASS.
- `git diff --check`: PASS.
- Manual QA:
  - Done by local automated HTTP/feature-test coverage on testing DB for customer/supplier display balances, dual-role zero balance, supplier-only raw payable, supplier picker display alias, latest timeline running balance, and generic supplier payment ambiguity.
  - Browser click-through QA was not run in this final gate.
- Production deploy: not run.
- PR merge: not run.
- Ready for review: yes, all code/test/scope gates passed for Senior Auditor review.

## Review Checklist

- [x] Backport is based on the production snapshot branch, not full `main`.
- [x] No migration file changed.
- [x] No production DB used.
- [x] No write-path changed for invoice, purchase, cashflow, payroll, stock, cost, or serial.
- [x] Supplier timeline conflict resolved without suppressing ambiguous payment warnings.
- [x] Raw `supplier_debt_amount` is preserved; display balance uses alias fields.
- [x] Non-scope supplier CSV header change was reverted.
- [x] Required PHP tests pass.
- [x] `npm run build` passes.
- [x] `git diff --check` passes.

## Rollback

Rollback is code-only:

```text
git revert <backport-commit-sha>
```

No data rollback is required because this change does not mutate existing data.

## Conclusion

Ready for Senior Auditor review as a scoped backport PR into:

```text
prod-snapshot/sanitized-production-customer-group-2d5bfc0-20260702
```

Not deployed. No production migration or production command was run.
