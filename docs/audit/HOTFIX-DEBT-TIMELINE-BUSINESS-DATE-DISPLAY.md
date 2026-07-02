# HOTFIX: Debt Timeline Business Date Display

## Scope

Debt timeline APIs, UI helpers, CSV exports, and XLSX exports now use the business/document date for display and date filtering.

Priority order:

1. `display_time`
2. `time`
3. `recorded_at`
4. `transaction_date`
5. `purchase_date`
6. `return_date`
7. `created_at`
8. legacy `date`

## Data Sources

- Customer invoice rows use `invoices.transaction_date`, falling back to `created_at`.
- Customer payment rows use `cash_flows.time`, falling back to `created_at`.
- Customer debt ledger rows use `customer_debts.recorded_at`, while preserving `created_at` as the system record timestamp.
- Sales return rows use `returns.return_date` only when the column exists, falling back to `created_at`.
- Supplier purchase rows use `purchases.purchase_date`, falling back to `created_at`.
- Purchase return rows use `purchase_returns.return_date`, falling back to `created_at`.
- Supplier payment/debt rows use `supplier_debt_transactions.recorded_at` only when the column exists, falling back to `created_at`.

## Constraints

- No migration.
- No backfill.
- No update/delete/recalculate command.
- No `migrate:fresh`.
- `created_at` remains the system creation timestamp where a separate business date exists.

## Verification

Verified local HEAD:

- `ffbd108 fix(debt): use business date in debt timeline and exports`

Source checks:

- PASS: `display_time` is present in timeline services, export controllers/services, Vue debt time helpers, and focused tests.
- PASS: export controllers/services only use `created_at` as a fallback after `display_time`, `time`, `recorded_at`, and document-date fields.
- PASS: customer debt UI helper prioritizes `display_time`.
- PASS: supplier debt UI helper prioritizes `display_time`.

Environment note:

- PHP emits startup warnings for missing optional extensions: `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`.
- These warnings did not block tests and are not related to this hotfix.

### Tests Run

- PASS: `php artisan test tests/Feature/Customers/CustomerDebtTimelineBusinessTimeTest.php`
  - Result: 5 passed, 1 skipped, 20 assertions.
  - Skip reason: `returns.return_date` is not present in the current local schema.
- PASS: `php artisan test tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php`
  - Result: 1 passed, 19 assertions.
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDebtVirtualOpeningTimelineTest.php`
  - Result: 1 passed, 18 assertions.
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`
  - Result: 1 passed, 55 assertions.
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php`
  - Result: 1 passed, 30 assertions.
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php`
  - Result: 2 passed, 14 assertions.
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php`
  - Result: 1 passed, 17 assertions.
- PASS: `php artisan test tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php`
  - Result: 1 passed, 7 assertions.
- PASS: `php artisan test tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`
  - Result: 6 passed, 56 assertions.
- PASS: `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
  - Result: 1 passed, 36 assertions.

### Build

- PASS: `npm run build`
  - Result: Vite built 918 modules successfully.

### Manual QA

- HD ngay nhap 24/5: PASS via `CustomerDebtTimelineBusinessTimeTest`; visual browser check was not run because the referenced screenshot/customer id was not provided in this request.
- Phieu thu ngay nhap 29/5: PASS via `CustomerDebtTimelineBusinessTimeTest`; visual browser check was not run because the referenced screenshot/customer id was not provided in this request.
- Export CSV: PASS via `CustomerDebtTimelineBusinessTimeTest`.
- Export XLSX: PASS via `CustomerDebtTimelineBusinessTimeTest`.
- Anh Thanh customer: PASS via `AnhThanhThienPhuDebtReconcileTest`.
- Anh Thanh supplier: PASS via `AnhThanhThienPhuDebtReconcileTest`.

## Data Safety

- Migration: No.
- Backfill: No.
- Update old data: No.
- Delete: No.
- Recalculate: No.
- Create document/voucher: No.
- Modified `created_at`: No.
- Destructive migration command run: No.
- `php artisan migrate:fresh` run: No.

## Conclusion

- Pass/fail: PASS for automated hotfix business-date display/export verification.
- Can deploy staging: Yes.
- Can deploy production: Yes after normal pull/build/cache deployment steps; run the visual customer screenshot check if that specific fixture is required by operations.
- Next action: no code/data change required.
