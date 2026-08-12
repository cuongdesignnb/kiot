# P0 Partner Debt Excel Parity and Document Details

## Scope

This hotfix aligns customer and supplier debt Excel exports with the canonical
partner debt event/effect contract and resolves full document detail from
stable source identity. It does not change posting, inventory, cashflow,
partner debt mutation, timeline ordering, or production data.

```text
BASE_SHA=f586be80fa180783f4f37aa8119161d233d866f7
BRANCH=codex/p0-partner-debt-excel-parity-document-details
MIGRATION_ADDED=NO
BACKFILL=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
```

## Root cause

The supplier exporter used a supplier-side effect field that was not always
the canonical display effect used by the supplier timeline. That caused
dual-role customer and supplier workbooks to disagree on signs and closing
balances. The exporter also inferred documents from legacy event-id prefixes,
which failed for canonical event identities and left supplier detail rows
missing or incomplete.

The correction centralizes both concerns. `PartnerDebtExportEffectResolver`
reads the orientation-specific canonical display effect and only uses the
legacy `amount` field when the entry has no canonical identity/effect
evidence. `PartnerDebtExportDocumentResolver` resolves reference/source
identity first, then supported legacy identities, and batch-loads Invoice,
Purchase, PurchaseReturn, and OrderReturn documents and their items.

## Contract before and after

Before:

- Customer and supplier exports could use different semantic effect fields.
- Supplier detail resolution depended on legacy id parsing.
- Payment, refund, adjustment, and offset context could be confused with
  product-document detail.
- Running balance was not exported as a stable parent-row column.

After:

- Both orientations use the same canonical event population and resolver
  contract. For a dual-role partner, customer effect plus supplier effect is
  zero and supplier closing is the inverse of customer closing.
- Document identity priority is `reference_type/reference_id`, then
  `source_type/source_id`, then detail identity and legacy fallback.
- Purchase, purchase-return, invoice, and sales-return item rows are
  informational children. Payment, refund, adjustment, and offset rows remain
  context-only and do not invent product rows; their event code and existing
  type/note fields remain available on the parent row.
- Parent rows carry running balance in column `M` (`Số dư sau GD`); child rows
  leave that column blank. Parent rows remain the only financial rows.
- Purchase document discounts are applied symmetrically in both orientations.

Date presets, custom date windows, selected columns, CSV output, timeline
ordering, footer formatting, and freeze/header behavior remain unchanged.

## Files changed

- `app/Http/Controllers/SupplierController.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `app/Services/Exports/SupplierDebtExcelExportService.php`
- `app/Services/Exports/PartnerDebtExportDocumentResolver.php`
- `app/Services/Exports/PartnerDebtExportEffectResolver.php`
- `tests/Feature/Exports/PartnerDebtExcelCancellationDetailsTest.php`
- `tests/Feature/Exports/PartnerDebtExcelDocumentDetailsTest.php`
- `tests/Feature/Exports/PartnerDebtExcelParityTest.php`
- `tests/Unit/Services/Exports/DualRolePartnerDebtExcelParityTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelCancellationDetailsTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelDocumentDetailsTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelPaymentEffectTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelSummaryReconciliationTest.php`
- `docs/audit/P0-PARTNER-DEBT-EXCEL-PARITY-DOCUMENT-DETAILS.md`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- `tests/Feature/Supplier/HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest.php`

The list above distinguishes existing coverage from files modified in this
branch; the customer test is not modified by this hotfix.

## Test evidence

QA used a disposable local MySQL 8 database (`kiot_pr_partner_debt_qa`) on
port `3337`, migrated from the repository schema. No production connection,
dump, command, migration, or data was used.

The generated workbooks were inspected with PhpSpreadsheet:

```text
CUSTOMER_PRIMARY_EVENT_COUNT=3
SUPPLIER_PRIMARY_EVENT_COUNT=3
CUSTOMER_DETAIL_ROWS=2
SUPPLIER_DETAIL_ROWS=2
CUSTOMER_CLOSING=-300
SUPPLIER_CLOSING=300
DUAL_ROLE_EXPORT_PARITY=PASS
RUNNING_BALANCE_COLUMN=M
RUNNING_BALANCE_PARITY=PASS
EXPORT_N_PLUS_ONE=PASS
```

Artifacts were written outside the repository:

```text
C:\Users\cuong\AppData\Local\Temp\kiot-pr-partner-debt-qa\customer-debt-parity.xlsx
C:\Users\cuong\AppData\Local\Temp\kiot-pr-partner-debt-qa\supplier-debt-parity.xlsx
```

Focused export and resolver suite: `58 tests / 233 assertions PASS`.
`vendor/bin/pint --dirty --test` passed for 15 files. PHP lint,
`git diff --check`, the frontend production build, mojibake scan, and
debug/secret scan passed.

The broader timeline/debt characterization run was `66 tests / 247
assertions`, with 5 errors and 18 failures. Those failures reproduce existing
canonical timeline/checkpoint contract expectations outside this diff (for
example old `document_first`/mirror-source assertions and missing legacy
metadata keys); no timeline core file was changed. They are retained as
baseline risk and are not claimed as an export regression gate.

Covered explicitly:

- customer/supplier dual-role parity and opposite closing balance;
- Invoice and Purchase detail rows in both orientations;
- PurchaseReturn and OrderReturn canonical detail identity;
- purchase cancellation identity and original-document linkage;
- payment context without product detail;
- canonical effect selection without unsafe generic amount fallback;
- batch loading of purchase items without per-document item queries;
- summary reconciliation from orientation effects.

## Manual QA checklist

- [ ] Customer export with `include_detail=0` contains parent rows only.
- [ ] Customer and supplier export with `include_detail=1` contains matched
      canonical parent identities and product detail rows.
- [ ] Purchase, purchase-return, invoice, and sales-return details show the
      correct SKU/name/quantity/price/discount/cost/line total.
- [ ] Cancellation rows retain the original document identity and do not
      create a second financial parent row.
- [ ] Payment, refund, adjustment, and debt-offset rows show context but no
      fabricated product rows.
- [ ] Column selection, date presets/custom dates, CSV, footer, freeze, and
      pagination behavior remain unchanged.
- [ ] Column M is populated only on parent rows and reconciles with the
      customer/supplier running-balance contract.
- [ ] Customer and supplier workbooks for the same dual-role partner have
      equal event identity/order and inverse display effects.

## Data safety

This change is read-only at export time. It adds no migration, index,
backfill, delete, merge, or production command. It does not alter invoices,
returns, purchases, inventory, serials, cashflow, debt calculations, or
partner ledger records.

## Rollback plan

Before merge, close the Draft PR or revert the hotfix commit. After merge,
rollback is a normal code revert of the PR squash commit. No schema rollback,
data repair, or production backfill is required.
