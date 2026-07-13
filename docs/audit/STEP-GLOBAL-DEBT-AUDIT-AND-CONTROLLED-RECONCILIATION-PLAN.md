# Global debt parity audit and controlled reconciliation plan

## Scope and revision

- Branch: `audit/global-debt-parity-and-reconciliation-plan`
- Base branch: `hotfix/supplier-timeline-customer-debt-adjustment-label`
- Base SHA: `4d649afa81e23f867ea71d3f666812cf7c01da49`
- Old head SHA reviewed by Senior Audit: `1db335853d50c6d790adf240c008fb205264f6a4`
- New head SHA: use the final PR #16 head reported after push. A commit cannot embed its own resulting SHA.
- Scope: read-only customer/supplier/dual-role parity audit and proposal-only reconciliation planning.
- Frontend changed: no.
- Migration/backfill/seed/data mutation: no.
- Production command or production database access: no.

Files changed since Senior Audit:

- `app/Services/Debt/PartnerDebtParityAuditService.php`
- `app/Console/Commands/AuditDebtParityCommand.php`
- `app/Console/Commands/DebtReconciliationPlanCommand.php`
- `tests/Unit/Services/PartnerDebtParityAuditServiceTest.php`
- `tests/Feature/Console/AuditDebtParityCommandTest.php`
- `tests/Unit/Services/DebtReconciliationPlanServiceTest.php`
- `tests/Feature/Console/DebtReconciliationPlanCommandTest.php`
- this report

No controller, timeline service, ledger service, write path, frontend, migration, stock, costing, serial/IMEI, invoice, CashFlow or payroll behavior changed.

## UI timeline option contract

Discovery covered both controllers, both index pages, both document timeline services, `PartnerDebtLedgerService` and `PartnerDebtDisplayBalance`.

| Screen/audit side | UI request | Audit call used for parity |
|---|---|---|
| Customer debt tab | `mode=document`, no technical flag | `CustomerDebtDocumentTimelineService::build($partner, [])` |
| Supplier-only debt tab | document mode, no `view` or technical flag | `SupplierDebtDocumentTimelineService::build($partner, [])` |
| Dual-role supplier debt tab | document mode with `view=partner` | `SupplierDebtDocumentTimelineService::build($partner, ['view' => 'partner'])` |

The previous audit enabled `audit/include_technical`, which did not match production UI semantics. The parity pass now never asks either document timeline to include technical entries.

Stored screen values remain explicit evidence:

- customer screen: raw customer receivable minus raw supplier payable;
- supplier-only screen: raw supplier payable;
- dual-role supplier screen: raw supplier payable minus raw customer receivable.

Stored, document and technical ledger sources remain separate. None is declared universally authoritative.

## Technical ledger evidence

`PartnerDebtParityAuditService::technicalLedgerEvidence()` consumes only `reconcile.excluded_ledger_entries` returned by the normal UI timeline pass. It does not run a technical-enabled balance pass.

Evidence fields:

- `customer_technical_codes`
- `supplier_technical_codes`
- `excluded_technical_codes`
- `has_technical_ledger_exclusion`
- `technical_customer_total`
- `technical_supplier_total`

Customer technical evidence is taken from the customer UI pass and supplier technical evidence from the supplier UI pass, avoiding cross-timeline duplication for dual-role partners while preserving duplicate ledger rows as evidence. Source `customer_debts` is separated from `supplier_debt_transactions`; code arrays are unique. Technical entries affect parity balance: **no**. They remain visible through `TECHNICAL_LEDGER_EXCLUDED` and the evidence columns.

The existing technical detection/exclusion behavior in both timeline services was not modified.

## CSV and filters

The audit CSV now includes `partner_name` plus all drilldown arrays:

```text
suspect_invoice_codes
suspect_receipt_codes
suspect_return_codes
suspect_refund_codes
suspect_purchase_codes
suspect_supplier_payment_codes
suspect_purchase_return_codes
suspect_adjustment_codes
suspect_fallback_codes
customer_technical_codes
supplier_technical_codes
excluded_technical_codes
```

Arrays are serialized with `|`; JSON keeps arrays. Production names and phone numbers are not copied into this report.

`debt:audit-parity` supports:

- `--partner-id`
- `--role=all|customer|supplier|dual`
- `--classification=` matching primary classification **or** classification flags
- `--risk=CRITICAL|HIGH|MEDIUM|LOW|OK`
- `--only-mismatch`
- `--limit=` with positive-integer validation

Its summary separates total eligible, scanned, matched and exported. A partner audit error does not stop later partners, but the command exits failure if any scanned row is `AUDIT_ERROR`.

`debt:reconcile-plan` filters audit rows before plan generation with `--partner-id`, `--classification` (primary or flags) and `--risk`.

Both commands require `--dry-run`; input/output paths stay under `storage/app/audits`. There is no apply/fix/backfill/update/recalculate option.

## Read-only proof

Static grep over the four implementation files for model/DB write methods returned no matches:

```text
save( update( delete( insert( create( forceDelete( restore(
DB::statement DB::update DB::insert DB::delete
```

The feature test snapshots counts and financial sums for:

```text
customers
customer_debts
supplier_debt_transactions
cash_flows
invoices
returns
purchases
purchase_returns
debt_offsets
```

The imported local database received the same snapshot before and after all v2 commands. Both normalized snapshots have SHA-256:

```text
bab3e6c69b7e6d971d0bfed51d546e556b4cbc24858d86b5303ac7a7a0bcd32b
```

Result: `DB_SNAPSHOT_UNCHANGED=true`.

## Local database evidence

- Source: local `kiot.sql.zip` supplied for this audit.
- Imported database: isolated MariaDB 10.11 database `kiot_audit_20260713_100928`.
- Tables: 106.
- Partners: 331 total, 330 eligible under the audit query.
- No production connection or production command was used.
- MariaDB required local-only `DB_COLLATION=utf8mb4_unicode_ci`; no source/schema/data change was made.
- Generated CSV/JSON remain ignored under `storage/app/audits` and are not committed.

## Audit v1 versus v2

Baseline v1:

| Metric | Count |
|---|---:|
| Candidates | 49 |
| Critical | 21 |
| High | 17 |
| Medium | 11 |
| Blocked uncertain | 39 |
| Code review required | 10 |

UI-semantics v2:

| Primary classification | Count |
|---|---:|
| `CUSTOMER_STORED_VS_DOCUMENT` | 10 |
| `DUAL_ROLE_NET_MISMATCH` | 10 |
| `INVOICE_RECEIPT_ALLOCATION_MISMATCH` | 1 |
| `SUPPLIER_STORED_VS_DOCUMENT` | 11 |
| `SUPPLIER_STORED_VS_LEDGER` | 6 |
| `TARGET_TYPE_ALIAS_SUSPECT` | 10 |
| `TECHNICAL_LEDGER_EXCLUDED` | 2 |
| **Total candidates** | **50** |

| Risk | v1 | v2 | Change |
|---|---:|---:|---:|
| Critical | 21 | 21 | 0 |
| High | 17 | 16 | -1 |
| Medium | 11 | 13 | +2 |

Comparison by local partner ID, without including identifying data in the report:

- resolved false positives: 0;
- new candidates: 1;
- primary-classification changes: 1, `DUAL_ROLE_NET_MISMATCH` to `TECHNICAL_LEDGER_EXCLUDED`;
- risk changes: 1, `HIGH` to `MEDIUM`;
- partners carrying the technical-exclusion flag: 3.

The extra candidate is technical evidence that was previously hidden by the technical-enabled parity pass. One existing dual-role candidate is now correctly described as a technical exclusion rather than a document-balance mismatch. This explains the movement from High to Medium without forcing counts to match v1.

These remain audit candidates, not confirmed data defects.

## Reconciliation plan v2

| Proposed action | Count | Customer delta | Supplier delta | Voucher |
|---|---:|---:|---:|---:|
| `BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH` | 38 | 0 | 0 | 0 |
| `CODE_REVIEW_REQUIRED` | 12 | 0 | 0 | 0 |

All 50 rows have `status=PROPOSED`. Totals:

```text
customer_delta_total=0
supplier_delta_total=0
proposed_voucher_count=0
```

The plan remains limited to `NO_ACTION`, `CODE_REVIEW_REQUIRED`, `OPENING_BALANCE_REVIEW_ONLY` and `BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH`. It cannot write data.

## Tests and checks

New/updated PR16 tests:

```text
38 passed, 153 assertions
```

Coverage includes UI-parity technical fixtures for customer and supplier, dual-role partner-view, CSV headers/pipe serialization, classification primary-or-flags, Critical/High/invalid risk, limit validation, partner/classification/risk plan filters, mandatory dry-run, restricted paths, continued scanning after audit errors, database immutability and proposal-only zero-delta output.

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

Other checks:

- PHP lint: pass for all four implementation and four test files.
- `git diff --check`: pass.
- forbidden-file check: no matches.
- read-only grep: no matches.
- frontend build: not required; no frontend files changed.
- local PHP still reports unrelated missing optional OCI/Firebird extensions.

## Manual QA v2

| Audit | Eligible/scanned | Matched | Exported/result |
|---|---:|---:|---|
| Global `--only-mismatch` | 330/330 | 330 | 50 candidates |
| Customer `--only-mismatch` | 279/279 | 279 | 32 candidates |
| Supplier `--only-mismatch` | 66/66 | 66 | 30 candidates |
| Dual-role full | 15/15 | 15 | 12 candidates, 3 OK |
| Critical filter | 330/330 | 21 | 21 Critical rows |
| Reconciliation plan | 50 input rows | 50 | 38 blocked, 12 code review |

All generated evidence remains local and ignored. Database snapshot after QA equals the snapshot before QA.

## Known limitations

- Stored balance, raw UI document timeline and technical ledger are evidence sources; none is universally authoritative.
- Generic supplier payment allocation is not persisted per purchase. FIFO coverage is presentation inference and cannot prove historical manual allocation.
- Target type aliases are review evidence; this audit does not normalize stored values.
- Cancel reversal detection is a review signal, not permission to generate a reversal.
- Suspect-code lists are capped at 20 per type.
- A future data action requires a separate design, explicit owner approval, backup, bounded batch, post-audit and traceable rollback.
- No production audit has run. Local imported data can differ from current production after the dump timestamp.

## Conclusion

PR16 now calculates document parity with the same options used by the UI timeline. Technical ledger entries are evidence only and do not affect parity balances.

No data was changed. No backfill, migration, production audit, deployment or production command was run. PR #16 remains Draft and is ready for Senior Auditor re-audit, not production reconciliation.
