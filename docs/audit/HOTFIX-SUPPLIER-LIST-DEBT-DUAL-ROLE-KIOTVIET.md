# HOTFIX - Supplier list debt column for dual-role partners

## Audit scope
- Module: partner debt.
- Screens: `/suppliers`, `/customers`.
- Business rule: supplier list must display supplier-oriented debt for dual-role partners.
- Main risk: showing gross supplier payable on the supplier list while KiotViet shows payable minus receivable.

## User feedback
- Supplier list still displayed `75,000,000` gross payable.
- KiotViet displays supplier-oriented balance.
- Anh Thanh must be `27,600,000`, not `75,000,000`.

## Source checked
- SupplierController: `app/Http/Controllers/SupplierController.php`
- Suppliers page: `resources/js/Pages/Suppliers/Index.vue`
- CustomerController: `app/Http/Controllers/CustomerController.php`
- Customers page: `resources/js/Pages/Customers/Index.vue`
- Tests:
  - `tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php`
  - `tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php`
- Base commit before this hotfix: `92068e9`

## Root cause
- `supplierNetDebt()` used `supplier_debt_amount` directly.
- `SupplierController@index` exposed gross payable and customer-oriented net, but did not expose an explicit supplier list balance.

## Standard formulas
| Screen | Column | Formula |
|---|---|---|
| Customers list | Current debt | `customer_receivable - supplier_payable` |
| Suppliers list | Current payable | `supplier_payable - customer_receivable` for dual-role partners; `supplier_payable` for supplier-only partners |

## Data impact
- No migration.
- No backfill.
- No existing data update.
- No delete.
- No recalculation.
- No CB/HCB creation.
- Read/display/API props only.

## Changes
- Added `supplier_screen_debt`, `supplier_oriented_balance`, and `supplier_list_debt_amount` to supplier list props.
- Updated `supplierNetDebt()` to prefer supplier-oriented list props and fall back to computed supplier orientation for dual-role suppliers.
- Preserved customer list formula and supplier-only gross payable behavior.
- Left top summary bar unchanged; it still needs separate confirmation if KiotViet expects the top total to sum the displayed supplier-oriented column.

## Tests run
- `php artisan test tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php tests/Feature/Suppliers/SupplierPayableLedgerTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
  - PASS: 20 tests, 204 assertions.
- `php artisan test --filter=Supplier`
  - PASS: 111 tests, 555 assertions.
- `php artisan test --filter=CustomerDebt`
  - PASS: 37 tests, 195 assertions.
- `php artisan test --filter=Purchase`
  - PASS: 86 tests, 444 assertions.
- `php artisan test --filter=CashFlow`
  - PASS: 37 tests, 204 assertions.
- Note: host PHP prints startup warnings for missing optional `oci8_*`, `pdo_oci`, and `pdo_firebird` extensions. Tests still pass.

## Build
- `npm run build`
  - PASS.

## Manual QA
- Runtime container: `localhost:8081`, container `kiotviet-app-clone`.
- Anh Thanh read-only data check:
  - `customer_receivable`: `47,400,000`.
  - `supplier_payable`: `75,000,000`.
  - Customer list debt: `-27,600,000`.
  - Supplier list debt: `27,600,000`.
- Runtime Suppliers page asset includes `supplier_screen_debt`, `supplier_list_debt_amount`, and `supplier_oriented_balance`.
- Supplier detail expected final running balance: `27,600,000`.
- Customer detail expected final running balance: `-27,600,000`.
- Runtime container was patched by copying source files and running `npm run build`; no container recreate and no migration.

## Conclusion
- Status: passed local verification.
- Staging: allowed after tests pass.
- Production: not evaluated in this local-only hotfix.
