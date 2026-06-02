# HOTFIX - Debt columns for dual-role partners

## Audit scope
- Module: customers and suppliers debt.
- Screens: `/customers`, `/suppliers`.
- Business rule: dual-role partner debt must keep the customer and supplier screens in their own orientations.
- Main risk: accidentally showing net debt in the supplier list, or supplier-oriented debt in the customer screen.

## Source checked
- Controller: `app/Http/Controllers/CustomerController.php`
- Controller: `app/Http/Controllers/SupplierController.php`
- Service: `app/Services/PartnerDebtLedgerService.php`
- Service: `app/Services/PartnerFinancialTimelineService.php`
- Frontend: `resources/js/Pages/Customers/Index.vue`
- Frontend: `resources/js/Pages/Suppliers/Index.vue`
- Tests: `tests/Feature/Customers/*Debt*`, `tests/Feature/Suppliers/*Debt*`
- Base commit before this hotfix: `82c0c7c`

## User feedback
- Remove the three summary cards in the debt tabs.
- Prioritize correct list columns: customer current debt and supplier current payable.
- Supplier list must use supplier-oriented balance for dual-role partners.
- Customer list must use customer-oriented net.

## Main list column rules
| Screen | Column | Formula |
|---|---|---|
| Customers | Current debt | `debt_amount - supplier_debt_amount` for dual-role partners |
| Suppliers | Current payable | `supplier_debt_amount - debt_amount` for dual-role partners; `supplier_debt_amount` for supplier-only partners |

## Detail table rules
| Screen | Final column | Formula |
|---|---|---|
| Customers debt tab | Debt | `customer_receivable - supplier_payable` |
| Suppliers debt tab | Supplier payable | `supplier_payable - customer_receivable` |

## Data impact
- Migration: no.
- Backfill: no.
- Existing data update: no.
- Delete: no.
- Recalculate: no.
- Create CB/HCB: no.

## Changes
- Removed the dual-role receivable/payable/net summary cards from both debt tabs.
- Updated follow-up: supplier list display uses supplier-oriented balance for dual-role partners.
- Kept customer list display on `debt_amount - supplier_debt_amount`.
- Added explicit supplier list props: `customer_receivable_balance`, `supplier_payable_balance`, `partner_net_position`.
- Added list-column regression tests for both screens.

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
- Anh Thanh Thien Phu read-only data check:
  - `customer_receivable`: `47,400,000`.
  - `supplier_payable`: `75,000,000`.
  - Customer screen current debt: `-27,600,000`.
  - Supplier screen current payable: `27,600,000`.
- Runtime Suppliers page asset includes `supplier_screen_debt`, `supplier_list_debt_amount`, and `supplier_oriented_balance`.
- Supplier debt tab: no three-card summary block in source or built page asset.
- Customer debt tab: no three-card summary block in source or built page asset.
- Runtime container was patched by copying source files and running `npm run build`; no container recreate and no migration.

## Conclusion
- Status: passed local verification.
- Staging: allowed after tests pass.
- Production: not evaluated in this local-only hotfix.
