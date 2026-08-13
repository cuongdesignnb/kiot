# P0 Partner Debt Excel Parity and Full Document Details

## Scope and safety boundary

This remediation aligns customer and supplier debt Excel exports with the
canonical partner-debt event/effect contract and resolves complete document
details from stable source identity. It does not change posting, inventory,
cashflow, partner-debt mutation, timeline ordering, or production data.

```text
BASE_SHA=f586be80fa180783f4f37aa8119161d233d866f7
CHARACTERIZED_CODE_HEAD=86752466990469746f5c7743273fe37a99f38107
BRANCH=codex/p0-partner-debt-excel-parity-document-details
MIGRATION_ADDED=NO
BACKFILL=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
```

## Root cause

The supplier export did not consistently use the canonical orientation-specific
effect used by the supplier timeline. Summary and body rows could therefore
disagree, especially for dual-role partners, synthetic payments, offsets, and
settlement events. A missing canonical orientation effect could also be
silently treated as zero.

Document detail resolution relied too heavily on legacy event-id prefixes and
did not consistently batch-load source documents, product units, or notes.
This caused missing detail rows, incomplete cancellation rows, and avoidable
legacy lookup queries. Payment and adjustment events could be mistaken for
product documents.

## Contract before and after

Before:

- Customer and supplier workbooks could select different semantic effect
  fields for the same canonical event.
- A missing canonical orientation effect could be hidden as a numeric zero.
- The body could use a raw amount path different from the summary path.
- Legacy document lookup could issue one query per event.
- Detail rows did not have a stable note column and could place values in
  financial columns.
- Unit, VAT provenance, cancellation linkage, and payment context were not
  consistently represented.

After:

- `PartnerDebtExportEffectResolver` is the single effect path for summary and
  body rows in both customer and supplier orientations.
- A canonical financial event missing the requested orientation effect fails
  closed with `PartnerDebtExportContractException`; it is never silently
  converted to zero. Explicit non-financial/reference-only events remain
  zero by contract.
- `PartnerDebtExportRunningBalanceResolver` is shared by both exporters and
  fails closed when a canonical running balance is missing.
- Synthetic payment fixtures use canonical effects of `20,000` and
  `2,060,000`; raw ledger amounts such as `18,400,000` and `18,600,000` are
  not used as debt effects.
- Purchase discounts are applied once in the shared effect resolver. Gross,
  net, summary, and both orientations reconcile to the same canonical result.
- Reference/source identity is preferred, followed by detail identity and a
  batched legacy-code fallback. Invoice, Purchase, PurchaseReturn, and
  OrderReturn documents and items are loaded in batches.
- Purchase, purchase-cancellation, purchase-return,
  purchase-return-cancellation, invoice, invoice-cancellation, sales-return,
  and sales-return-cancellation details are covered. Cancellation rows retain
  original-document linkage and do not create a second financial parent row.
- Product unit is exported from the persisted base unit or first available
  `ProductUnit`. VAT fields are explicitly audited; the current schema has no
  persisted VAT source for these fixtures, so the contract is
  `VAT_SOURCE=NOT_PERSISTED` and VAT cells are intentionally blank.
- Detail notes are written to dedicated column `N` (`Ghi chú`). Financial
  columns `K`, `L`, and `M` are blank on child rows. Parent rows carry the
  running balance in `M` (`Số dư sau GD`).
- Payment, refund, adjustment, and debt-offset rows keep context such as
  payment method, linked document code, persisted note, or raw metadata but
  never fabricate product rows.
- Customer and supplier exports for the same dual-role partner have the same
  event identity/order and inverse orientation effects/running balances.

## Required invariants

```text
CANONICAL_MISSING_EFFECT_FAILS_CLOSED=YES
CANONICAL_MISSING_EFFECT_SILENT_ZERO=NO
CUSTOMER_SUMMARY_BODY_EFFECT_PARITY=PASS
MERGED_SETTLEMENT_EXPORT_RECONCILIATION=PASS
PRODUCTION_SHAPED_PAYMENT_PARITY_FIXTURE=PASS
RAW_PAYMENT_AMOUNT_USED_AS_DEBT_EFFECT=NO
PRODUCT_UNIT_EXPORTED_WHEN_AVAILABLE=YES
VAT_SOURCE_AUDITED=YES
VAT_SOURCE=NOT_PERSISTED
NOTE_COLUMN=N
NOTE_WRITTEN_IN_FINANCIAL_COLUMN=NO
DETAIL_ROW_FINANCIAL_COLUMNS_BLANK=YES
PURCHASE_DISCOUNT_SINGLE_APPLICATION=PASS
CANONICAL_RUNNING_BALANCE_FAILS_CLOSED=YES
DUAL_ROLE_EFFECT_INVERSE=PASS
DUAL_ROLE_RUNNING_BALANCE_INVERSE=PASS
UNEXPLAINED_BLANK_DETAIL_FIELDS=0
```

`UNEXPLAINED_BLANK_DETAIL_FIELDS=0` applies to the audited fields in the
fixture. VAT is not an unexplained blank: it is explicitly justified by
`VAT_SOURCE=NOT_PERSISTED`.

## Files changed by the remediation

Application/runtime:

- `app/Exceptions/PartnerDebtExportContractException.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `app/Services/Exports/PartnerDebtExportDocumentResolver.php`
- `app/Services/Exports/PartnerDebtExportEffectResolver.php`
- `app/Services/Exports/PartnerDebtExportRunningBalanceResolver.php`
- `app/Services/Exports/SupplierDebtExcelExportService.php`

Tests:

- `tests/Feature/Exports/PartnerDebtExcelCancellationDetailsTest.php`
- `tests/Feature/Exports/PartnerDebtExcelCancellationMatrixTest.php`
- `tests/Feature/Exports/PartnerDebtExcelDocumentDetailsTest.php`
- `tests/Feature/Exports/PartnerDebtExcelLegacyFallbackPerformanceTest.php`
- `tests/Feature/Exports/PartnerDebtExcelParityTest.php`
- `tests/Feature/Exports/PartnerDebtExcelPurchaseDiscountContractTest.php`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- `tests/Feature/Supplier/HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest.php`
- `tests/Feature/Supplier/HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest.php`
- `tests/Unit/Services/Exports/DualRolePartnerDebtExcelParityTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelCancellationDetailsTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelDocumentDetailsTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelFinancialContractTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelPaymentEffectTest.php`
- `tests/Unit/Services/Exports/PartnerDebtExcelSummaryReconciliationTest.php`

Documentation:

- `docs/audit/P0-PARTNER-DEBT-EXCEL-PARITY-DOCUMENT-DETAILS.md`

No migration, index, dependency, backfill, or production configuration was
added.

## Test and workbook evidence

The focused export/HOTFIX suite on disposable MySQL 8 passed:

```text
FOCUSED_EXPORT_TESTS=59
FOCUSED_EXPORT_ASSERTIONS=263
FOCUSED_EXPORT_RESULT=PASS

FINANCIAL_CONTRACT_TESTS=5
FINANCIAL_CONTRACT_ASSERTIONS=19
CANCELLATION_MATRIX_TESTS=1
CANCELLATION_MATRIX_ASSERTIONS=17
LEGACY_DOCUMENT_TESTS=4
LEGACY_DOCUMENT_ASSERTIONS=28
PURCHASE_DISCOUNT_TESTS=1
PURCHASE_DISCOUNT_ASSERTIONS=7
```

The focused suite covers customer/supplier parity, dual-role orientation,
summary/body reconciliation, payment effects, cancellation matrices,
document details, unit/VAT provenance, notes, legacy lookup batching,
purchase discount single application, and explicit fail-closed behavior.

The generated customer and supplier workbooks were inspected with
PhpSpreadsheet outside the repository. The audited workbook fixture verified
parent/detail row structure, column `N` notes, blank child financial columns,
unit output, cancellation linkage, and inverse dual-role effects.

```text
WORKBOOK_PROGRAMMATIC_QA=PASS
DUAL_ROLE_EXPORT_PARITY=PASS
RUNNING_BALANCE_COLUMN=M
RUNNING_BALANCE_PARITY=PASS
EXPORT_N_PLUS_ONE=PASS
```

Artifacts were kept outside source control under:

```text
C:\Users\cuong\AppData\Local\Temp\kiot-pr-partner-debt-qa
C:\Users\cuong\AppData\Local\Temp\kiot-pr51-qa
```

## Base-versus-head runtime characterization

Base and head used independent disposable MySQL 8 databases, the same
repository migrations, the same PHP/runtime configuration, and the same
command. No production dump or production connection was used.

```text
CHARACTERIZATION_COMMAND=vendor/bin/phpunit tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php tests/Feature/Report/RR01CashFlowCancelledRegressionTest.php tests/Feature/Supplier/HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest.php

BASE_TESTS=66
BASE_ASSERTIONS=251
BASE_FAILURES=17
BASE_ERRORS=5
BASE_SKIPPED=0

HEAD_TESTS=66
HEAD_ASSERTIONS=252
HEAD_FAILURES=15
HEAD_ERRORS=5
HEAD_SKIPPED=0

BASE_FAILURE_SIGNATURE_COUNT=22
HEAD_FAILURE_SIGNATURE_COUNT=20
SHARED_FAILURE_SIGNATURE_COUNT=20
NEW_HEAD_ONLY_FAILURES=0
NEW_HEAD_ONLY_ERRORS=0
RESOLVED_HEAD_ONLY_FAILURES=2
MESSAGE_DRIFT_COUNT=0
BASELINE_FAILURES_REPRODUCED=YES
PR_INTRODUCED_TIMELINE_FAILURES=0
FAILURE_FINGERPRINT_PARITY=PASS_WITH_TWO_BASE_ONLY_EXPECTATION_CORRECTIONS
```

The two base-only signatures are the pre-remediation expectations for the
persisted-ledger checkpoint test; the head test records the corrected
canonical checkpoint semantics. The remaining 20 normalized signatures are
shared baseline failures/errors in the broad characterization suite. There
are no head-only failures or errors and no timeline-core files were changed.

## Static and quality gates

```text
PINT_DIRTY_TEST=PASS
PHP_LINT_CHANGED_FILES=PASS
GIT_DIFF_CHECK=PASS
FRONTEND_BUILD=PASS
MOJIBAKE_SCAN=PASS
SECRET_SCAN=PASS
DEBUG_OUTPUT_SCAN=PASS
```

## Manual QA checklist

- [ ] Customer export with `include_detail=0` contains parent rows only.
- [ ] Customer and supplier exports with `include_detail=1` contain matched
      canonical parent identities and product detail rows.
- [ ] Purchase, purchase-return, invoice, and sales-return details show the
      correct SKU/name/quantity/price/discount/cost/line total.
- [ ] Cancellation rows retain original document identity and do not create a
      second financial parent row.
- [ ] Payment, refund, adjustment, and debt-offset rows show context but no
      fabricated product rows.
- [ ] Notes are in column `N`; child financial columns `K:M` remain blank.
- [ ] Unit is populated when a persisted source is available; VAT is blank
      only because `VAT_SOURCE=NOT_PERSISTED`.
- [ ] Column selection, date presets/custom dates, CSV, footer, freeze, and
      pagination behavior remain unchanged.
- [ ] Column `M` is populated only on parent rows and reconciles with the
      customer/supplier running-balance contract.
- [ ] Customer and supplier workbooks for the same dual-role partner have
      equal event identity/order and inverse display effects.

## Data safety statement

This change is read-only at export time. It adds no migration, index,
backfill, delete, merge, or production command. It does not alter invoices,
returns, purchases, inventory, serials, cashflow, debt calculations, or
partner-ledger records. QA databases were disposable and isolated from
production.

## Rollback plan

Before merge, close the Draft PR or revert the remediation commit(s). After
merge, rollback is a normal code revert of the PR squash commit. No schema
rollback, data repair, or production backfill is required.
