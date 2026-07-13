# Global debt parity audit and controlled reconciliation plan

## Scope and source

- Branch: `audit/global-debt-parity-and-reconciliation-plan`
- Base branch: `hotfix/supplier-timeline-customer-debt-adjustment-label`
- Base SHA: `4d649afa81e23f867ea71d3f666812cf7c01da49`
- Head SHA: use the PR head; the report is committed with the implementation.
- Scope: read-only customer/supplier/dual-role debt parity audit and proposal-only reconciliation planning.
- Frontend changed: no.
- Migration/backfill/seed: no.
- Production command or production database access: no.

## Files changed

- `app/Services/Debt/PartnerDebtParityAuditService.php`
- `app/Console/Commands/AuditDebtParityCommand.php`
- `app/Services/Debt/DebtReconciliationPlanService.php`
- `app/Console/Commands/DebtReconciliationPlanCommand.php`
- `tests/Unit/Services/PartnerDebtParityAuditServiceTest.php`
- `tests/Feature/Console/AuditDebtParityCommandTest.php`
- `tests/Unit/Services/DebtReconciliationPlanServiceTest.php`
- `tests/Feature/Console/DebtReconciliationPlanCommandTest.php`
- `docs/audit/STEP-GLOBAL-DEBT-AUDIT-AND-CONTROLLED-RECONCILIATION-PLAN.md`

No controller, existing timeline service, write-path, frontend, migration, stock, costing, serial/IMEI, invoice, CashFlow or payroll behavior was changed.

## Current source architecture

| Concern | Canonical source used by the audit |
|---|---|
| Raw customer receivable | `PartnerDebtDisplayBalance::customerReceivable()` |
| Raw supplier payable | `PartnerDebtDisplayBalance::supplierPayable()` |
| Customer screen balance | `PartnerDebtDisplayBalance::customerScreen()` |
| Supplier screen balance | `PartnerDebtDisplayBalance::supplierScreen()` |
| Customer document timeline | `CustomerDebtDocumentTimelineService::build(..., audit/include_technical)` |
| Supplier document timeline | `SupplierDebtDocumentTimelineService::build(..., audit/include_technical)` |
| Dual-role supplier document view | Same supplier service with `view=partner` |
| Customer ledger | `PartnerDebtLedgerService::buildCustomerNetLedger()` |
| Pure supplier ledger | `PartnerDebtLedgerService::buildSupplierPayableLedger()` |
| Dual-role supplier ledger | `PartnerDebtLedgerService::buildSupplierDualRolePartnerTimeline()` |

The audit keeps these values separate:

1. stored screen balance;
2. raw document final balance;
3. display final balance after read-only alignment/virtual opening;
4. raw ledger balance and display resolution state.

Display alignment is not treated as proof that historical data is complete.

## Commands

Global audit:

```bash
php artisan debt:audit-parity \
  --dry-run \
  --only-mismatch \
  --export=storage/app/audits/debt-parity-mismatch.csv \
  --json=storage/app/audits/debt-parity-mismatch.json
```

Proposal-only plan:

```bash
php artisan debt:reconcile-plan \
  --dry-run \
  --audit-file=storage/app/audits/debt-parity-mismatch.json \
  --export=storage/app/audits/debt-reconciliation-plan.csv \
  --json=storage/app/audits/debt-reconciliation-plan.json
```

Both commands fail without `--dry-run`. Input/output paths are restricted to `storage/app/audits`. There is no `--apply`, `--fix`, `--update` or `--backfill` option.

## Read-only proof

Static check:

```bash
rg -n "save\(|update\(|delete\(|insert\(|create\(|forceDelete\(|restore\(|DB::statement|DB::update|DB::insert|DB::delete" \
  app/Services/Debt/PartnerDebtParityAuditService.php \
  app/Console/Commands/AuditDebtParityCommand.php \
  app/Services/Debt/DebtReconciliationPlanService.php \
  app/Console/Commands/DebtReconciliationPlanCommand.php
```

Result: no matches.

The feature test snapshots counts and financial sums for `customers`, `customer_debts`, `supplier_debt_transactions`, `cash_flows`, `invoices`, `returns`, `purchases`, `purchase_returns` and `debt_offsets` before and after command execution. Result: unchanged.

The imported local database was also snapshotted before and after the full audit. Result: `DB_SNAPSHOT_UNCHANGED=True`.

## Local database evidence

- Source archive: local `kiot.sql.zip`, timestamp `2026-07-13 10:09:43`.
- SQL member timestamp/name indicates `2026-07-13 10:09:28`.
- Imported to isolated MariaDB 10.11 database `kiot_audit_20260713_100928`.
- Imported tables: 106.
- Total partners in dump: 331.
- Eligible partners scanned: 330. One record had no customer/supplier role and zero stored debt, so it was outside the audit query.
- Latest imported `cash_flows.created_at`: `2026-07-11 08:34:33`.
- No production connection or production command was used.

Generated CSV/JSON remain local under `storage/app/audits` and are not committed.

## Classifications and risk

Supported classifications are the constants in `PartnerDebtParityAuditService::CLASSIFICATIONS`, including stored/document/ledger differences, dual-role symmetry, virtual opening/alignment, missing history, duplicate/fallback, allocation, refund, cancellation reversal, target aliases, technical exclusions and audit errors.

Risk levels: `CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `OK`. Tolerance is greater than 1 VND.

Local imported-data result after correcting a false-positive real/fallback heuristic:

| Primary classification | Count |
|---|---:|
| `CUSTOMER_STORED_VS_DOCUMENT` | 10 |
| `DUAL_ROLE_NET_MISMATCH` | 11 |
| `INVOICE_RECEIPT_ALLOCATION_MISMATCH` | 1 |
| `SUPPLIER_STORED_VS_DOCUMENT` | 11 |
| `SUPPLIER_STORED_VS_LEDGER` | 6 |
| `TARGET_TYPE_ALIAS_SUSPECT` | 10 |
| Total exported mismatches | 49 |

| Risk | Count |
|---|---:|
| `CRITICAL` | 21 |
| `HIGH` | 17 |
| `MEDIUM` | 11 |

These are audit candidates, not confirmed data defects.

## Top 20 anonymized risks

| Partner | Role | Risk | Primary classification | Maximum raw document difference |
|---|---|---|---|---:|
| P-17 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 239,057,497 |
| P-7 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 130,400,000 |
| P-75 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 123,600,000 |
| P-1 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 94,000,000 |
| P-210 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 57,020,000 |
| P-80 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 40,800,000 |
| P-16 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 30,270,000 |
| P-9 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 29,310,000 |
| P-78 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 29,000,000 |
| P-33 | customer_only | CRITICAL | CUSTOMER_STORED_VS_DOCUMENT | 23,460,000 |
| P-35 | customer_only | CRITICAL | CUSTOMER_STORED_VS_DOCUMENT | 15,000,000 |
| P-81 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 14,099,989 |
| P-6 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 13,170,500 |
| P-59 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 13,000,000 |
| P-29 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 12,042,200 |
| P-57 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 11,210,000 |
| P-4 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_LEDGER | 10,500,000 |
| P-64 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_DOCUMENT | 10,000,000 |
| P-74 | dual_role | CRITICAL | DUAL_ROLE_NET_MISMATCH | 9,000,000 |
| P-52 | supplier_only | CRITICAL | SUPPLIER_STORED_VS_LEDGER | 0 raw document difference; ledger-only gap |

No partner name, phone or other personal data is included in this report.

## CSV and JSON contract

CSV columns include partner identity/code/role, four stored balances, customer/supplier document raw/display/reconciliation metrics, customer/supplier ledger metrics, parity differences, source counts/totals, evidence flags, classification, risk, recommended action and audit error.

JSON additionally preserves arrays for classification flags, suspect document codes and excluded technical codes. The output includes `generated_at`, `dry_run=true`, tolerance and rows.

An anonymized example:

```json
{
  "partner_id": 9001,
  "partner_code": "PARTNER-9001",
  "role": "customer_only",
  "stored_customer_screen": 2370000,
  "customer_document_raw_final": -6700000,
  "customer_stored_vs_document_raw": 9070000,
  "primary_classification": "CUSTOMER_STORED_VS_DOCUMENT",
  "risk_level": "HIGH"
}
```

## Reconciliation plan design

The plan command consumes audit JSON and only writes proposal files. Every row remains `PROPOSED`.

Local plan result:

| Proposed action | Count | Data delta |
|---|---:|---:|
| `BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH` | 39 | 0 |
| `CODE_REVIEW_REQUIRED` | 10 | 0 |

The plan never infers an actual supplier purchase allocation from generic `SupplierPayment`. The existing timeline can expose FIFO inference for presentation diagnostics, but this is not persisted allocation evidence.

No customer delta, supplier delta or voucher is proposed automatically. A later data-apply design would require a separate approval, backup, approved plan hash, small batch, application-service transaction, post-audit and traceable reversal. No apply command is included in this change.

## Tests and checks

New tests:

```text
24 passed, 69 assertions
```

Covered: clean customer/supplier/dual-role, 9,070,000 mismatch, missing opening, document-vs-ledger variants, screen asymmetry, duplicate/fallback, refund duplicate, cancel reversal, target aliases, allocation warnings, mandatory dry-run, output path restriction, database snapshot immutability, uncertain-source blocking and proposal-only plan output.

Selected debt regression:

```text
50 passed, 272 assertions
```

Regression files:

- `AuditDebtLedgerCommandTest`
- `CustomerDebtDocumentTimelineTest`
- `SupplierDebtDocumentTimelineTest`
- `SupplierDualRolePartnerTimelineTest`
- `DualRoleDebtAdjustmentKiotStyleTest`
- `CustomerDebtTimelineDisplayBalanceContractTest`

PHP lint: pass for all four implementation files and four test files.

`git diff --check`: required before commit.

Frontend build: not required because no frontend assets changed.

Environment warning: local PHP reports missing optional OCI/Firebird extensions. MySQL/MariaDB tests and commands completed successfully; these optional extension warnings are unrelated to debt audit behavior.

## Manual QA on the imported local dump

| Audit | Scanned | Exported/result |
|---|---:|---|
| Global `--only-mismatch` | 330 | 49 candidates |
| Customer `--only-mismatch` | 279 | 31 candidates |
| Supplier `--only-mismatch` | 66 | 29 candidates |
| Dual-role full | 15 | 11 mismatch candidates, 4 OK |
| Single anonymized local partner | 1 | command completed; no database mutation |
| Reconciliation plan | 49 rows | 39 blocked, 10 code review, zero data delta |

All generated files remained under `storage/app/audits`. They are ignored local evidence and are not part of the commit.

## Known limitations

- Stored balance, raw document timeline and ledger are evidence sources; none is treated as universally authoritative.
- Generic supplier payment allocation is not persisted per purchase. FIFO coverage remains inferred and cannot prove historical manual allocation.
- Target type aliases are reported for code/query compatibility review; the audit does not normalize stored values.
- Cancel reversal detection uses available invoice/ledger reference codes and is a review signal, not permission to generate a reversal.
- Suspect-code lists are capped at 20 per type.
- The audit can identify candidates and evidence but cannot decide opening balances or data deltas without manual source-of-truth review.
- No production audit has been run. Imported local data may differ from production after the dump timestamp.

## Approval, backup and rollback gate

No backup is required for this read-only audit. A database backup is mandatory before any future approved data apply. Git rollback for this change is code revert only because this PR does not alter data.

Any future apply must stop for explicit Senior Auditor/owner approval and report affected partners, tables, total deltas, voucher counts, backup path, batch ID, rollback records and manual QA evidence.

## Conclusion

Global debt parity audit and reconciliation planning are ready for Senior Auditor review.

No data was changed. No backfill was run. No production deployment or production command was run. A separate explicit confirmation is required before any production debt adjustment.
