# HOTFIX — Supplier Dual-role Partner Timeline

## Scope

- Module: Customers, Suppliers, partner debt timeline.
- Screens: `/suppliers`, supplier `Công nợ` tab, dual-role partners only.
- API: `/api/suppliers/{id}/debt-transactions`.
- Risk: mixing customer receivable documents into the pure supplier payable ledger can corrupt the supplier debt column/export if not kept behind an explicit view mode.

## Root Cause

- The supplier debt tab had been hardened to pure supplier-side payable entries only.
- For dual-role partners, QA expected the expanded supplier tab to also show the partner financial timeline with customer-side `HD...` and `TTHD...` rows.
- The missing distinction was between the canonical supplier payable ledger and the opt-in partner net timeline shown for dual-role partners.

## Business Rule

- Default supplier payable ledger remains pure supplier-side:
  - Purchases increase payable.
  - Supplier payments decrease payable.
  - Purchase returns decrease payable.
  - Supplier adjustments/discounts/debt offsets follow signed `supplier_effect`.
- Dual-role supplier partner timeline is opt-in with `view=partner`:
  - Customer receivable entries keep customer-side signs.
  - Supplier payable entries are mirrored into partner net position.
  - `partner_net_position = customer_receivable_balance - supplier_payable_balance`.
- Exports and non-dual suppliers continue using pure payable mode.

## Source Checked

- `app/Http/Controllers/SupplierController.php`
- `app/Services/PartnerDebtLedgerService.php`
- `resources/js/Pages/Suppliers/Index.vue`
- `tests/Feature/Suppliers/SupplierPayableLedgerTest.php`
- `tests/Feature/Suppliers/HOTFIXFollowUpSupplierLedgerHardeningTest.php`
- `tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`
- `tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`

## Changes Made

- Added `PartnerDebtLedgerService::buildSupplierDualRoleDebtTimeline()` as a wrapper over the existing customer net ledger.
- Added supplier API `view=partner` mode for dual-role partners.
- Kept default `/api/suppliers/{id}/debt-transactions` as pure supplier payable.
- Added partner timeline metadata:
  - `display_mode=partner_net_timeline`
  - `partner_effect`
  - `partner_running_balance`
  - `source_ledger`
  - `is_mirror`
  - `affects_customer_receivable`
  - `affects_supplier_payable`
- Updated supplier frontend to request `view=partner` for dual-role suppliers and render:
  - final column as `Vị thế ròng`
  - values from `partner_effect`
  - balances from `partner_running_balance`
  - domain badges for receivable/payable entries.
- Added regression test file `tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`.

## Data Safety

- Migration: No.
- Backfill: No.
- Recalculate: No.
- Update old data: No.
- Delete data: No.
- Stock/cost/serial logic: Not touched.

## Tests

- `php artisan test tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/SupplierPayableLedgerTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/HOTFIXFollowUpSupplierLedgerHardeningTest.php`: PASS.
- `php artisan test tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`: PASS.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS.
- `php artisan test tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php`: PASS.
- `php artisan test --filter=Supplier`: PASS.
- `php artisan test --filter=DebtOffset`: PASS.
- `php artisan test --filter=CashFlow`: PASS.
- `php artisan test --filter=Purchase`: PASS.
- `php artisan test --filter=CustomerDebt`: PASS.
- `npm run build`: PASS.
- Note: PHP CLI prints startup warnings for missing optional `oci8_*`, `pdo_oci`, and `pdo_firebird` extensions in this local environment. They did not fail the test process.

## Manual QA

- Not run locally against production/staging in this hotfix.
- Expected QA after deploy:
  - Dual-role supplier tab shows `HD...`, `TTHD...`, `PN...`, `PCPN...` in partner mode.
  - Default supplier payable API/export does not include `HD...` or `TTHD...`.
  - Non-dual supplier tab remains payable-only.
  - Final partner column shows net position, not pure payable.

## Conclusion

- The implementation separates supplier payable source-of-truth from dual-role partner timeline display.
- This hotfix is code/test/UI only and does not mutate production data.
