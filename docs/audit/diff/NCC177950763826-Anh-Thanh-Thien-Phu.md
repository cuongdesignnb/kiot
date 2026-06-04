# Debt manual diff - NCC177950763826

## Scope

- Partner: `NCC177950763826 - Anh Thanh Thiên Phú`.
- Generated at: `2026-06-04T12:29:09+07:00`.
- Dry-run: `true`.
- Plan JSON: `storage/app/audits/20260604-113305/debt-fix-plan.json`.
- Fix group: `C_LEDGER_DOCUMENT_MISMATCH`.
- Diagnosis: `ledger_and_documents_mismatch`.

## Data safety

- Migration: no.
- Backfill: no.
- Update old data: no.
- Delete: no.
- Recalculate: no.
- Write DB: no.
- Requires confirmation before fix: yes.

## Summary

| Metric | Value |
|---|---:|
| Documents | 12 |
| Ledger rows | 4 |
| Cashflow rows | 1 |
| Timeline rows | 39 |
| Issues | 12 |

## Proposed resolution

- Status: `manual_review`.
- Allowed group: `null`.
- Candidate for apply: `no`.
- Write operations preview: `0`.
- Requires backup: `true`.
- Rollback required: `true`.

## Matching matrix

| Source type | Source code | Expected | Ledger | Cashflow | Match status | Issue |
|---|---|---:|---:|---:|---|---|
| invoice | HD177727497421 | 0 |  | 7200000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| invoice | HD177932991721 | 42320000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| invoice | HD177933240323 | 7000000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| invoice | HD177933714532 | 5100000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| order_return | TH2026052216141861 | -7000000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260523105400 | 62100000 | 62100000 |  | MATCHED |  |
| purchase | PN20260523143050 | 2100000 | 2100000 |  | MATCHED |  |
| purchase | PN20260527150940 | 5400000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260527163153 | 2700000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260528090703 | 2700000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260602151028 | 2700000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase_return | PTN20260602150641 | -2700000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| ledger | MERGE-CUSTOMER-141 | 47420000 | 47420000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | CKTT26052510573737 | -20000 | -20000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |

## Detected issues

| Severity | Type | Evidence | Suggested action | Auto fix |
|---|---|---|---|---|
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177727497421","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177932991721","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177933240323","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177933714532","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"order_return","source_code":"TH2026052216141861","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260527150940","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260527163153","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260528090703","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260602151028","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase_return","source_code":"PTN20260602150641","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"MERGE-CUSTOMER-141","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"CKTT26052510573737","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
