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

Planned focused coverage:

- Customer invoice timeline returns `display_time/time = transaction_date` and keeps `created_at`.
- Customer payment timeline returns `display_time/time = cash_flows.time` and keeps `created_at`.
- Supplier purchase timeline returns `display_time/time = purchase_date` and keeps `created_at`.
- Sales return timeline uses `return_date` when the current schema has that column.
- Customer CSV and XLSX debt exports show/filter by the business date, not `created_at`.

Regression set:

- `tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php`
- `tests/Feature/Suppliers/SupplierDebtVirtualOpeningTimelineTest.php`
- `tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`
- `tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php`
- `tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php`
- `tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php`
- `tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php`
- `tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`
- `tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- `npm run build`
