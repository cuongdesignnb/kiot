# HOTFIX - Supplier Debt Export Document Timeline Parity

## Scope

- Repo: `cuongdesignnb/kiot`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Base used locally: `origin/prod-snapshot/sanitized-production-customer-group-2d5bfc0-20260702`
- Scope is display/API compatibility only.

## Problem

Supplier debt screen and supplier debt export could diverge because export still defaulted to legacy ledger output. The debt tab is document-first, while export could omit or calculate rows differently unless query parameters forced the newer path.

This was risky for NCC cases where the screen displays purchase/payment/return/adjustment chronology from `SupplierDebtDocumentTimelineService`, but CSV/XLSX export used a different source.

## Source Checked

- `routes/api.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/Exports/SupplierDebtExcelExportService.php`
- `resources/js/Pages/Suppliers/Index.vue`
- `resources/js/Components/ExcelButtons.vue`
- `tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php`
- `tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- `tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php`
- `tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php`

## Root Cause

The UI debt tab and export endpoint did not share one default read model. The UI used the document timeline, while export could still use the legacy partner ledger unless callers supplied newer query options.

## Policy

- Default `export-debt` uses the same document timeline contract as the supplier debt tab.
- Legacy export remains available only with explicit `mode=legacy`.
- `view=partner` remains supported for dual-role partner timeline export.
- No database mutation is performed by export.

## Change

- `SupplierController::exportDebtHistory()` now builds default export entries from `SupplierDebtDocumentTimelineService`.
- Added normalization for document timeline aliases:
  - `supplier_display_effect`
  - `supplier_effect`
  - `display_effect`
  - `supplier_display_running_balance`
  - `supplier_running_balance`
  - `debt_remain`
- Kept legacy CSV behind `?mode=legacy`.
- Added `mode=document|legacy` and `view=partner` validation.
- Updated CSV/XLSX detail loaders to support both legacy ids and document ids:
  - `pur-*` and `purchase-*`
  - `pret-*` and `purchase_return-*`
  - `inv-*` and `invoice-*`

## Files

- `app/Http/Controllers/SupplierController.php`
- `app/Services/Exports/SupplierDebtExcelExportService.php`
- `tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php`
- `tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB read/write: no.
- Production command: no.
- Stock/costing/serial logic: unchanged.

## Verification

PASS:

```bash
php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php --compact
```

Result:

```text
Tests: 25 passed (105 assertions)
```

PASS:

```bash
php artisan test tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/CustomerDebt/SapoDebtParityTest.php tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php --compact
```

Result:

```text
Tests: 59 passed (324 assertions)
```

PASS:

```bash
npm run build
```

## Manual QA Checklist

- Supplier debt tab and CSV export show the same document codes.
- Default export includes document timeline entries.
- `mode=legacy` still downloads the legacy format.
- Dual-role supplier export with `view=partner` includes partner-facing entries.

Manual browser QA was not performed in this local source pass.

## Rollback Plan

Rollback the supplier export hotfix commit. This returns default `export-debt` to the previous legacy behavior. No migration rollback or data restoration is required.
