# Hotfix Customer Legacy Timeline Compatibility

## Summary

- Scope: Customer debt history/timeline response compatibility after Sapo debt parity port.
- Base commit: `1f84d16dadaf8a2766663089f09367230fd7de6c`.
- Branch: `hotfix/customer-legacy-timeline-compatibility`.
- Code changed: yes, response/controller/display adapter only.
- Data changed: no.

## Source Checked

- Files:
  - `docs/audit/SAPO-TO-KIOT-DEBT-PARITY-FINAL-GAP-AUDIT.md`
  - `app/Services/PartnerDebtLedgerService.php`
  - `app/Services/CustomerDebtDocumentTimelineService.php`
  - `app/Http/Controllers/CustomerController.php`
  - `app/Http/Controllers/SupplierController.php`
  - `app/Services/Exports/CustomerDebtExcelExportService.php`
  - Read-only context: `CustomerPaymentService`, `CustomerDebtService`, `OrderPaymentSummaryService`, `PartnerMergeService`, `PartnerTransactionGuard`
- Tests:
  - `tests/Feature/CustomerDebt/SapoDebtParityTest.php`
  - `tests/Feature/CustomerDebt`
  - `tests/Feature/Customers`
  - `tests/Feature/Supplier`
  - `tests/Feature/Suppliers`
  - Targeted failing Customer legacy timeline tests listed in PR #6 audit.
- Report PR #6: `docs/audit/SAPO-TO-KIOT-DEBT-PARITY-FINAL-GAP-AUDIT.md`.

## Baseline Before Fix

Testing DB:

- Local Docker MySQL: `sales_mysql_test`, `127.0.0.1:3319`.
- Disposable DB: `kiot_pr7_customer_legacy`.
- User: `test_user`.
- Production DB used: no.

Baseline commands/results:

- `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php`: PASS, 12 passed, 41 assertions.
- `php artisan test tests/Feature/CustomerDebt`: PASS, 17 passed, 55 assertions.
- `php artisan test tests/Feature/Customers`: FAIL, 27 failed, 1 skipped, 121 passed, 662 assertions.
- `php artisan test tests/Feature/Supplier`: PASS, 59 passed, 243 assertions.
- `php artisan test tests/Feature/Suppliers`: FAIL, 2 failed, 42 passed, 279 assertions.
- `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers`: FAIL, 29 failed, 1 skipped, 239 passed, 1239 assertions.
- `git diff --check`: PASS.

## Root Cause

- Group 1: Customer legacy tests use JSON debt-history API as the legacy-compatible partner ledger, while document-first tests still rely on the document timeline. The controller default treated all no-mode requests as document mode.
- Group 2: Customer net view for dual-role debt offset needed a display-only virtual opening only for zero-target CB/HCB mirror timelines, not for ordinary return/purchase timelines.
- Group 3: Supplier debt API document entries did not always expose read-only `affects_debt_balance`; one Customer reconciliation test reads that supplier API alias.
- Out of scope: Supplier dual-role partner timeline display running balance contract remains in `tests/Feature/Suppliers`.

## Implementation

- Files changed:
  - `app/Http/Controllers/CustomerController.php`
  - `app/Http/Controllers/SupplierController.php`
  - `app/Services/PartnerDebtLedgerService.php`
- Helpers added: none.
- Response fields changed:
  - Customer debt-history no explicit mode now keeps document mode for non-JSON requests and uses legacy adapter for JSON requests.
  - Supplier debt API entries now include read-only `affects_debt_balance` alias when missing.
  - Customer zero-target virtual opening is limited to supplier-ledger mirrored debt-offset rows.
- Canonical fields preserved:
  - Explicit document-first mode remains available.
  - Document timeline service was not changed.
  - Debt write services were not changed.

## Data Safety

- Migration: no.
- Backfill: no.
- DB update: no.
- DB delete: no.
- Rebuild debt: no.
- Opening balance: no real opening balance; only response-only virtual display row.
- MERGE cleanup: no.
- Production DB used: no.

## Test Results

Targeted:

- `php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php tests/Feature/Customers/KiotStyleCustomerDebtTimelineTest.php tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php tests/Feature/Customers/CustomerDebtHistoryReturnSettlementDisplayTest.php tests/Feature/Customers/CustomerDebtUnresolvedMismatchWarningTest.php tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Customers/HOTFIXFollowUpDebtHistoryPaginationTest.php tests/Feature/Customers/HOTFIXFollowUpDebtOffsetMirrorTest.php tests/Feature/Customers/PartnerFinancialTimelineTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- Result: PASS, 78 passed, 483 assertions.

Regression:

- `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php`: PASS, 12 passed, 41 assertions.
- `php artisan test tests/Feature/CustomerDebt`: PASS, 17 passed, 55 assertions.
- `php artisan test tests/Feature/Customers`: PASS, 1 skipped, 148 passed, 799 assertions.
- `php artisan test tests/Feature/Supplier`: PASS, 59 passed, 243 assertions.
- `php artisan test tests/Feature/Suppliers`: FAIL, 2 failed, 42 passed, 319 assertions.
- `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers`: FAIL, 2 failed, 1 skipped, 266 passed, 1416 assertions.
- `git diff --check`: PASS.

npm build:

- Not run. No frontend files changed.

## Remaining Failures

- Customer: none in `tests/Feature/Customers`.
- Supplier:
  - `SupplierDualRoleTimelineFinancialDisplayTest::test_dual_role_reference_documents_keep_financial_values_on_both_screens`
  - `SupplierDualRoleTimelineNoDashTest::test_dual_role_financial_entries_have_display_running_balance_on_both_orientations`
- Out of scope: Supplier dual-role partner timeline display/running-balance compatibility. This should be a separate PR because it changes supplier-oriented display contract beyond Customer legacy timeline compatibility.

## Production

- Production touched: No.
- Deploy required now: No.
- Production migration required: No.
- Production command required: No.

## Conclusion

- PASS for Customer legacy timeline compatibility.
- PARTIAL for full Customer/Supplier broad regression because 2 pre-existing Supplier dual-role display failures remain.
- Ready for PR review: yes, as Customer compatibility PR.
- Need Senior Auditor decision: yes, before a separate Supplier dual-role timeline compatibility PR.
