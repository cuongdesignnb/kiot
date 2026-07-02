# Debt manual diff - NCC177950763826

## Scope

- Partner: `NCC177950763826 - Anh Thanh Thiên Phú`.
- Generated at: `2026-06-04T15:33:06+07:00`.
- Dry-run: `true`.
- Evidence matching version: `v2`.
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
| Issues | 11 |
| Possible matches | 3 |
| Critical issues | 1 |
| Candidate ready | no |
| Write preview ops | 0 |

## Candidate preview

- Candidate ready: `no`.
- Allowed group: `null`.
- Blocked reason: `group blocked by default`.
- Write operations preview: `0`.
- Requires backup: `true`.
- Rollback required: `true`.
- Legacy proposed status: `manual_review`.

## Possible matches

| Source | Candidate | Type | Strategy | Score | Confidence | Reason |
|---|---|---|---|---:|---|---|
| HD177727497421 | PT177727497423 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260523105400 | PN20260523105400 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260523143050 | PN20260523143050 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |

## Matching matrix

| Source type | Source code | Base code | Expected | Ledger | Cashflow | Best match | Score | Match status | Severity | Auto candidate | Issue |
|---|---|---|---:|---:|---:|---|---:|---|---|---|---|
| invoice | HD177727497421 | HD177727497421 | 0 |  | 7200000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| invoice | HD177932991721 | HD177932991721 | 42320000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| invoice | HD177933240323 | HD177933240323 | 7000000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| invoice | HD177933714532 | HD177933714532 | 5100000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| order_return | TH2026052216141861 | TH2026052216141861 | -7000000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260523105400 | PN20260523105400 | 62100000 | 62100000 |  | exact_reference | 100 | MATCHED_EXACT | info | no |  |
| purchase | PN20260523143050 | PN20260523143050 | 2100000 | 2100000 |  | exact_reference | 100 | MATCHED_EXACT | info | no |  |
| purchase | PN20260527150940 | PN20260527150940 | 5400000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260527163153 | PN20260527163153 | 2700000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260528090703 | PN20260528090703 | 2700000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260602151028 | PN20260602151028 | 2700000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase_return | PTN20260602150641 | PTN20260602150641 | -2700000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| ledger | MERGE-CUSTOMER-141 | MERGECUSTOMER141 | 47420000 | 47420000 |  | manual_merge_ledger | 0 | MANUAL_LEDGER_REQUIRES_AUTHORITY | critical | no | Manual/merge ledger requires source authority before any fix. |
| ledger | CKTT26052510573737 | CKTT26052510573737 | -20000 | -20000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |

## Detected issues

| Severity | Type | Evidence | Suggested action | Auto fix |
|---|---|---|---|---|
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177932991721","source_base_code":"HD177932991721","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177933240323","source_base_code":"HD177933240323","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"invoice","source_code":"HD177933714532","source_base_code":"HD177933714532","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"order_return","source_code":"TH2026052216141861","source_base_code":"TH2026052216141861","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260527150940","source_base_code":"PN20260527150940","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260527163153","source_base_code":"PN20260527163153","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260528090703","source_base_code":"PN20260528090703","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260602151028","source_base_code":"PN20260602151028","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase_return","source_code":"PTN20260602150641","source_base_code":"PTN20260602150641","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| critical | MANUAL_LEDGER_REQUIRES_AUTHORITY | {"source_type":"ledger","source_code":"MERGE-CUSTOMER-141","source_base_code":"MERGECUSTOMER141","issue":"Manual/merge ledger requires source authority before any fix.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Manual/merge ledger must be reviewed by authority before any write. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"CKTT26052510573737","source_base_code":"CKTT26052510573737","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
