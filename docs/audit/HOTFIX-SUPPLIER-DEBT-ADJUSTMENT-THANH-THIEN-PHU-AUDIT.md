# HOTFIX - Supplier Debt Adjustment Thanh Thien Phu Audit

## Scope

- Repo: `cuongdesignnb/kiot`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Audit type: source/local-test only.

## Production Status

No production command was run.

The production commit/branch named in the task was not available from the local remote, so this pass did not inspect production data. This report records the source-level behavior and the SELECT-only checks that BA/owner can run separately if production confirmation is required.

## Source Findings

Supplier debt export now uses the document timeline by default, so supplier adjustments emitted by `SupplierDebtDocumentTimelineService` are included in default export output when they appear in the same document timeline as the screen.

The regression test creates a supplier adjustment and verifies:

- the document timeline API contains the adjustment code;
- the default `export-debt` output contains the same adjustment code;
- no schema or data mutation is required.

## Source Checked

- `app/Http/Controllers/SupplierController.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Models/SupplierDebtTransaction.php`
- `app/Models/DebtOffset.php`
- `app/Models/CashFlow.php`
- `resources/js/Pages/Suppliers/Index.vue`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- `docs/audit/SAPO-KIOT-SUPPLIER-DEBT-PARITY.md`

## Root Cause

Source-level risk was export/read-model divergence: an adjustment can be visible in the document timeline but missing or differently balanced in an export built from the legacy ledger. Production-specific confirmation for Thanh Thien Phu was not performed because production commands were not approved.

## Files

- `app/Http/Controllers/SupplierController.php`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB read/write: no.
- Production command: no.
- Legacy MERGE cleanup: no.
- Debt rebuild: no.

## Verification

PASS:

```bash
php artisan test tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php --compact
```

Result:

```text
Tests: 11 passed (52 assertions)
```

PASS:

```bash
php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php --compact
```

Result:

```text
Tests: 25 passed (105 assertions)
```

## Optional Production Read-Only Checks For BA Approval

These are examples only. They were not run by Codex in this step.

```sql
select id, code, name, supplier_debt_amount, debt_amount
from customers
where name like '%Thanh Thien Phu%'
   or name like '%Thành Thiên Phú%';
```

```sql
select *
from supplier_debt_transactions
where supplier_id = :supplier_id
order by coalesce(recorded_at, created_at), id;
```

```sql
select *
from cash_flows
where target_id = :supplier_id
  and target_type in ('Nhà cung cấp', 'Nha cung cap')
order by coalesce(time, created_at), id;
```

## Conclusion

The code path now aligns supplier debt export with the document timeline used by the UI. Production-specific adjustment existence or data quality for Thanh Thien Phu still requires a separate read-only production audit approved by BA/owner.

## Rollback Plan

Rollback the supplier export hotfix commit. This restores the previous export read model. No database rollback is required.
