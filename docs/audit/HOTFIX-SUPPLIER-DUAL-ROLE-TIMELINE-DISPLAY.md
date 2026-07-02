# Hotfix Supplier Dual-role Timeline Display

## Summary

- Scope: supplier dual-role timeline response/display compatibility only.
- Base commit: `78c4455c1c5ea33c033105f290e7d9d5575c24ef`.
- Branch: `hotfix/supplier-dual-role-timeline-display`.
- Code changed: `app/Services/SupplierDebtDocumentTimelineService.php`.
- Data changed: none.

## Source Checked

- Files:
  - `app/Services/SupplierDebtDocumentTimelineService.php`
  - `app/Services/PartnerDebtLedgerService.php`
  - `app/Services/CustomerDebtDocumentTimelineService.php`
  - `app/Http/Controllers/SupplierController.php`
  - `app/Http/Controllers/CustomerController.php`
  - `app/Services/Exports/CustomerDebtExcelExportService.php`
- Tests:
  - `tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php`
  - `tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`
  - CustomerDebt, Customers, Supplier, and Suppliers regression suites.
- Prior reports:
  - `docs/audit/SAPO-TO-KIOT-DEBT-PARITY-FINAL-GAP-AUDIT.md`
  - `docs/audit/HOTFIX-CUSTOMER-LEGACY-TIMELINE-COMPATIBILITY.md`

## Baseline Before Fix

- Environment note: initial attempts failed because the default local env had an empty `APP_KEY` and DB config pointed at an unavailable local MySQL port. No production DB was used.
- Test environment used for confirmed baseline: local Docker MySQL `sales_mysql_test` on `127.0.0.1:3319`, database `sales_test`, with inline testing env vars and a temporary inline `APP_KEY`.
- PHP emitted warnings for missing optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`. These warnings did not block tests.
- `SupplierDualRoleTimelineFinancialDisplayTest.php`: FAIL before fix, supplier-side customer reference document lacked `supplier_balance_effect`.
- `SupplierDualRoleTimelineNoDashTest.php`: FAIL before fix, final display running balance was `19,600,000` instead of expected supplier-oriented balance `27,600,000`.
- Broader regression baseline was already known from the PR #7 follow-up audit; this hotfix targeted only the two supplier compatibility failures.

## Root Cause

- Financial display: supplier partner document timeline preserved customer-side financial display entries, but did not expose the supplier legacy aliases expected by compatibility tests and legacy consumers.
- No-dash running balance: the supplier partner document timeline calculated display running balance from raw display effects only. It did not reconcile non-empty document timelines to the stored supplier-oriented balance when the document set omitted earlier balance context.
- Out of scope: write-path debt logic, payment allocation, cashflow cancel logic, order/POS payment writes, payroll, inventory, costing, serial/IMEI, migrations, data cleanup, and production commands.

## Implementation

- Files changed:
  - `app/Services/SupplierDebtDocumentTimelineService.php`
- Helpers added:
  - `normalizeSupplierPartnerDisplayAliases()`
  - `shiftSupplierDisplayRunningAliases()`
  - `virtualOpeningTime()`
- Response fields changed:
  - Adds/normalizes `supplier_display_balance_effect`.
  - Adds/normalizes `supplier_balance_effect`.
  - Keeps `supplier_display_effect` as the financial display value.
  - Keeps `supplier_display_running_balance`, `supplier_running_balance`, `partner_running_balance`, `supplier_partner_running_balance`, and `debt_remain` aligned for display compatibility.
- Canonical fields preserved:
  - No write-path service was changed.
  - No ledger amount, stored debt amount, cashflow amount, invoice amount, purchase amount, or canonical transaction field is mutated.
- Reference-only behavior:
  - Customer-side documents shown on the supplier partner screen keep financial display values, but set `supplier_balance_effect = 0`.
  - These entries are marked `reference_only`, `is_reference_only`, and `affects_debt_balance = false`.
  - A read-only virtual opening display row may be prepended when needed to bridge a non-empty document timeline to the stored supplier-oriented balance. This is not a database row and not a real opening balance.

## Data Safety

- Migration: no.
- Backfill: no.
- DB update: no.
- DB delete: no.
- Rebuild debt: no.
- Opening balance: no real opening balance; only a read-only virtual display row in API response when required.
- MERGE cleanup: no.
- Production DB used: no.

## Test Results

- Targeted:
  - `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php`: PASS, 1 passed, 30 assertions.
  - `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`: PASS, 1 passed, 50 assertions.
- Regression:
  - `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php`: PASS, 12 passed, 41 assertions.
  - `php artisan test tests/Feature/CustomerDebt`: PASS, 17 passed, 55 assertions.
  - `php artisan test tests/Feature/Supplier`: PASS, 59 passed, 243 assertions.
  - `php artisan test tests/Feature/Customers`: PASS, 148 passed, 1 skipped, 799 assertions.
  - `php artisan test tests/Feature/Suppliers`: PASS, 44 passed, 335 assertions.
  - `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers`: PASS, 268 passed, 1 skipped, 1432 assertions.
- npm build if any: not run because there were no frontend changes.
- Static checks:
  - `php -l app/Services/SupplierDebtDocumentTimelineService.php`: PASS.
  - `git diff --check`: PASS.

## Remaining Failures

- Customer: none found in the executed CustomerDebt and Customers suites.
- Supplier: none found in the executed Supplier and Suppliers suites.
- Broad: one existing skipped test in the Customers suite; no new failure from this hotfix.

## Production

- Production touched: no.
- Deploy required now: no; this branch is ready for PR review only.
- Production migration required: no.
- Production command required: no.

## Conclusion

- PASS/PARTIAL: PASS for the requested supplier dual-role timeline compatibility hotfix.
- Ready for PR review: yes.
- Need Senior Auditor decision: yes, for review/merge approval before any production rollout decision.
