# STEP 17 - Material Debt Root Cause Drilldown

## Branch and PR

- Branch: `audit/material-debt-root-cause-drilldown`
- Base branch: `audit/global-debt-parity-and-reconciliation-plan`
- Base SHA: `938d1ecf4ff5b21745e1f73ecba6774a8c23ea92`
- Implementation head SHA at initial PR creation: `1fd1a9cea4e678c94f0d16da2d664c3c649dfbef`.
- PR URL: `https://github.com/cuongdesignnb/kiot/pull/17`.
- PR status: open, Draft, mergeable, stacked on PR16; not merged.

The final docs-only metadata commit SHA is reported in the handoff because a commit cannot embed its own SHA.

## Production Audit Source

This implementation uses only the aggregate evidence supplied for STEP 17. No production system was accessed.

- Partners scanned: 331.
- Audit candidates: 294.
- Material review cases: 39.
- Compatibility/technical-only cases: 255.
- Reconciliation plans blocked: 39.
- Customer delta total: 0.
- Supplier delta total: 0.
- Proposed vouchers: 0.

No production partner name, code, phone, address, email, CSV, or JSON is committed in this branch.

## Discovery Summary

The implementation was compared with the existing customer/supplier document timelines, partner ledgers, display-balance helper, status normalizer, controllers, models, and parity/reconciliation services.

### Document Timeline Contracts

- Customer evidence uses `CustomerDebtDocumentTimelineService::build($partner, [])`.
- Supplier-only evidence uses `SupplierDebtDocumentTimelineService::build($partner, [])`.
- Dual-role supplier evidence uses `SupplierDebtDocumentTimelineService::build($partner, ['view' => 'partner'])`.
- The balance pass never enables audit or technical-entry options.
- Raw document balance, display alignment, virtual opening metadata, reconcile diagnostics, and normalized entries are exported separately.

### Ledger Contracts

- Customer uses `PartnerDebtLedgerService::buildCustomerNetLedger()`.
- Supplier-only uses `buildSupplierPayableLedger()`.
- Dual-role supplier uses `buildSupplierDualRolePartnerTimeline()`.
- Stored screen balances use `PartnerDebtDisplayBalance`; formulas are not duplicated.

### Cancellation and Reversal Matching

- Cancelled status is evaluated through `BusinessStatus`.
- Source discovery confirms the current invoice cancellation flow writes a signed `CustomerDebt` reversal, normally `type=adjustment` with an invoice-cancellation/debt-reversal note. It then marks the original Invoice CashFlows cancelled and soft-deletes them; those receipts are historical payment evidence, not reversal vouchers.
- A `CustomerDebt` is accepted only when it is `sale_reversal`, or an `adjustment` with explicit invoice cancellation/debt reversal semantics, has invoice code/order linkage, and has the expected signed direction. A generic code-only adjustment is rejected.
- A CashFlow is accepted only as legacy explicit reversal evidence when `type=payment`, `reference_type=InvoiceCancellation`, amount is positive, and the document reference resolves. A normal `type=receipt`, `reference_type=Invoice` row never counts as reversal.
- Expected reversal is the absolute value of `invoice.total - invoice.customer_paid`. Exact requires active valid evidence and an amount match within tolerance. Historical cancelled/deleted evidence cannot satisfy exact, partial, or missing-reversal state.
- Every CashFlow evidence row exposes `is_deleted`, `is_cancelled`, `is_active_for_balance`, and `evidence_scope=active|historical`, using `BusinessStatus` semantics.
- The cancellation matrix separates original receipt codes, active/historical reversal codes, CustomerDebt reversal codes, active/historical amounts, match method, conflict, and warnings.

### Payment Allocation Limitations

- Customer allocation uses persisted `customer_payment_allocations` when available, then an explicit Invoice CashFlow reference.
- A direct supplier Purchase CashFlow reference is explicit evidence.
- Direct references use `reference_id` as authoritative when the attribute exists; `reference_code` is used only when ID is null. If ID and code disagree, the ID result is retained and a typed conflict warning is exported.
- The current `cash_flows` schema has no `reference_id` column. No schema change was made; the resolver remains compatible with snapshots/models where that attribute exists and is covered with in-memory model fixtures.
- Generic `SupplierPayment` has no persisted purchase-level allocation table in the current schema.
- Supplier FIFO candidates are exported as `fifo_projection_only`, with `inferred` or `unknown` confidence. They are never labelled actual allocation or source of truth.
- Cancelled/deleted receipts and supplier payments remain historical detail only. They are not explicitly allocated under the active contract and do not trigger or suppress active allocation patterns.

## Senior Audit Hardening

The follow-up started from reviewed head `0d00e31e23173a44c19a43d11d08f403d3131013` and addresses all identified integrity blockers:

- Original Invoice receipts cannot satisfy cancellation reversal matching.
- Active and historical CashFlows are separated before pattern evaluation.
- Artifact `partner_id`, `partner_code`, and calculated role must match the current DB record before the service runs.
- Invalid IDs/codes/roles, missing records, identity mismatches, duplicate IDs, and execution errors use a closed sanitized error taxonomy.
- No service runs for duplicate IDs; one deterministic error is emitted per duplicate ID and no partner JSON can be overwritten.
- `OK`, alias-only, technical-only, and virtual-display-only input rows are skipped before material review output.
- Input relative path and SHA-256 plus row/processing counters are recorded in both aggregate JSON files and `command.log`; SHA-256 is also included in summary CSV rows.
- Output directory must be absent or completely empty before `partners/` is created. Existing output is never removed, merged, or overwritten.

### Dual-role and Technical Rules

- Dual-role output includes raw receivable/payable, customer/supplier screen balances, expected symmetry, and actual symmetry.
- No side is automatically netted, corrected, or persisted.
- Technical CustomerDebt and SupplierDebtTransaction rows remain visible as evidence but are excluded from the UI document balance pass.
- Genuine duplicate technical rows are retained; the drilldown does not collapse evidence by code and amount.

## Files Changed

- `app/Services/Debt/MaterialDebtRootCauseDrilldownService.php`
- `app/Console/Commands/MaterialDebtRootCauseDrilldownCommand.php`
- `tests/Unit/Services/MaterialDebtRootCauseDrilldownServiceTest.php`
- `tests/Feature/Console/MaterialDebtRootCauseDrilldownCommandTest.php`
- `docs/audit/STEP-MATERIAL-DEBT-ROOT-CAUSE-DRILLDOWN.md`

No frontend file, migration, model, controller, or existing reconciliation service was changed.

## Command Contract

```text
debt:drilldown-material
  --dry-run
  --audit-file=
  --partner-id=
  --role=all|customer|supplier|dual
  --risk=CRITICAL|HIGH|MEDIUM
  --classification=
  --limit=
  --export-dir=
```

Both input and output paths are restricted to `storage/app/audits`. The command continues after a partner-level error, exports a sanitized error row, and returns failure if any partner failed.

Allowed row error codes:

```text
INVALID_PARTNER_ID
INVALID_PARTNER_CODE
INVALID_PARTNER_ROLE
DUPLICATE_PARTNER_ID
PARTNER_NOT_FOUND
AUDIT_ARTIFACT_PARTNER_MISMATCH
DRILLDOWN_EXECUTION_ERROR
```

Aggregate metadata includes normalized input path, SHA-256, input/material/non-material counts, unique/duplicate IDs, identity mismatch count, processed/error count, and generation time. Error output never contains raw exception messages, SQL, stack traces, absolute paths, or partner PII.

Generated files:

```text
material-root-cause-summary.csv
material-root-cause-summary.json
material-root-cause-detail.json
manual-review-queue.csv
partners/<partner-id>.json
command.log
```

Arrays in CSV output use `|`. JSON output is whitelist-based and excludes partner name, phone, email, address, password, token, free-form notes, and target names.

## Pattern Taxonomy

```text
LEGACY_OPENING_BALANCE_GAP
STORED_BALANCE_WITHOUT_COMPLETE_DOCUMENT_HISTORY
DOCUMENT_HISTORY_WITHOUT_LEDGER
LEDGER_HISTORY_WITHOUT_DOCUMENT
CANCELLED_INVOICE_REVERSAL_GAP
CUSTOMER_RECEIPT_ALLOCATION_GAP
GENERIC_SUPPLIER_PAYMENT_UNALLOCATED
SUPPLIER_PAYMENT_ALLOCATION_INFERENCE
RETURN_REFUND_MAPPING_GAP
PURCHASE_RETURN_REFUND_MAPPING_GAP
DUAL_ROLE_NETTING_INCONSISTENCY
TECHNICAL_LEDGER_EXCLUDED
TARGET_TYPE_ALIAS_PRESENT
MULTI_SOURCE_DIVERGENCE
UNRESOLVED
```

Every pattern includes confidence, evidence codes/IDs, and a reason. The default and only source-of-truth result in STEP 17 is `UNRESOLVED`.

## Data Safety

- Migration: no.
- Schema change: no.
- Backfill: no.
- Existing data update/delete: no.
- Opening balance/reversal/allocation creation: no.
- Delta creation: no.
- Voucher creation: no.
- Apply support: false.
- Production commands: no.
- Rollback: revert the code commit; no database rollback is required.

`DebtReconciliationPlanService` is unchanged. Regression confirms uncertain rows remain `BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH`, both deltas remain zero, voucher remains null, and status remains `PROPOSED`.

## Verification

### Static Read-only Grep

The required mutation-call grep on the new service and command returned no matches.

### PHP Lint

All four new PHP source/test files passed `php -l`. The local PHP installation emits unrelated missing OCI/Firebird extension warnings; MySQL execution is unaffected.

### New Tests

```text
Before hardening: 19 passed, 97 assertions
After hardening: 41 passed, 211 assertions
```

Coverage now includes original active/deleted receipts, current signed cancellation adjustment, exact/partial/historical explicit reversal, generic adjustment rejection, ID-only references, ID/code conflicts, historical allocation exclusion, FIFO inference, artifact code/role mismatch, invalid rows, missing partners, duplicate IDs, SHA-256 provenance, non-empty output rejection, non-material guard, PII, deterministic output, DB snapshot, zero delta, and null voucher contracts.

### Regression

```text
81 passed
397 assertions
```

The required PR16, reconciliation-plan, customer timeline, supplier timeline, dual-role supplier, and dual-role adjustment suites passed.

### Invoice Cancellation Regression Debt

The four discovered legacy invoice/cashflow cancellation files were run explicitly:

```text
11 passed
14 failed
1 skipped
74 assertions
```

The failures occur because legacy HTTP fixtures do not pass the current invoice/cashflow cancellation permission and status contracts, so the cancel route returns without mutating the fixture. They fail before PR17 drilldown code is called and none of the four failing files is changed by this hotfix. This baseline regression debt is reported, not hidden; the new source-semantics cancellation tests pass independently.

### Local Manual QA

Manual QA used the isolated local import `kiot_audit_20260713_100928` in Docker, not production.

- Local eligible partners: 330.
- Local material rows selected by the supplied material classifications: 38.
- Full drilldown: 38 processed, 0 errors.
- Critical filter: first 5 processed, 0 errors.
- Single-partner filter: 1 processed, 0 errors.
- Wrong artifact code: `AUDIT_ARTIFACT_PARTNER_MISMATCH`, exit failure, service not run.
- Duplicate ID artifact: `DUPLICATE_PARTNER_ID`, exit failure, zero partner files.
- Non-empty output directory: rejected before `partners/` creation; existing file retained.
- Every summary source-of-truth status: `UNRESOLVED`.
- Required output files: present.
- JSON PII key scan: no matches.
- Count/sum snapshot across all required debt tables before/after a command run: unchanged.

The local import has 38 material rows while the supplied production artifact has 39. This is treated as a local snapshot-version difference; no conclusion about the missing production case is inferred.

### Repository Checks

- `git diff --check`: pass.
- Forbidden files: none staged.
- Frontend build: not required; frontend is unchanged.

## Known Limitations and Remaining Uncertainty

- Generic supplier payment allocation cannot be proven at purchase level without persisted allocation evidence.
- Opening-balance and history-gap fields are low/medium-confidence heuristics, not confirmed facts.
- Cancellation matching is limited to reference fields and relationships available in the current schema.
- Because `cash_flows.reference_id` is absent in this schema, live local direct references resolve by code; ID precedence is compatibility behavior tested with synthetic model attributes, not evidence that current rows persist an ID.
- The discovered legacy HTTP cancellation suites contain 14 pre-existing fixture/permission-status failures and require a separate test-maintenance decision; PR17 does not change invoice/cashflow write paths.
- The tool does not determine whether stored, document, or ledger balance is correct.
- Production results must be reviewed manually and must not be committed.

## Readiness

- Production deployment readiness: no; Senior Auditor review is required first.
- Production drilldown readiness: no; this Draft PR has not been reviewed or merged.
- Data reconciliation readiness: no; source of truth remains unresolved and apply is unsupported.

STEP 17 adds a read-only root-cause drilldown tool for material debt cases. It collects evidence and classifies observed patterns only. It does not select a source of truth, generate deltas or vouchers, modify data, or run production commands.
