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
- CashFlow matching prefers an available Invoice reference ID/type and then falls back to reference code/type.
- CustomerDebt reversal matching uses supported reversal types plus invoice code or order linkage available in the current schema.
- Missing, partial, and exact matches are evidence only. The service never creates a reversal.

### Payment Allocation Limitations

- Customer allocation uses persisted `customer_payment_allocations` when available, then an explicit Invoice CashFlow reference.
- A direct supplier Purchase CashFlow reference is explicit evidence.
- Generic `SupplierPayment` has no persisted purchase-level allocation table in the current schema.
- Supplier FIFO candidates are exported as `fifo_projection_only`, with `inferred` or `unknown` confidence. They are never labelled actual allocation or source of truth.

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
19 passed
97 assertions
```

Coverage includes all 18 required groups: dry-run, path safety, divergence, generic supplier allocation, cancellation missing/exact reversal, document/ledger gaps, legacy opening heuristic, dual-role, technical evidence, aliases, PII, DB snapshot, filters, error continuation, deterministic output, and plan contract.

### Regression

```text
81 passed
397 assertions
```

The required PR16, reconciliation-plan, customer timeline, supplier timeline, dual-role supplier, and dual-role adjustment suites passed.

### Local Manual QA

Manual QA used the isolated local import `kiot_audit_20260713_100928` in Docker, not production.

- Local eligible partners: 330.
- Local material rows selected by the supplied material classifications: 38.
- Full drilldown: 38 processed, 0 errors.
- Critical filter: 21 eligible, first 5 processed, 0 errors.
- Single-partner filter: 1 processed, 0 errors.
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
- The tool does not determine whether stored, document, or ledger balance is correct.
- Production results must be reviewed manually and must not be committed.

## Readiness

- Production deployment readiness: no; Senior Auditor review is required first.
- Production drilldown readiness: no; this Draft PR has not been reviewed or merged.
- Data reconciliation readiness: no; source of truth remains unresolved and apply is unsupported.

STEP 17 adds a read-only root-cause drilldown tool for material debt cases. It collects evidence and classifies observed patterns only. It does not select a source of truth, generate deltas or vouchers, modify data, or run production commands.
