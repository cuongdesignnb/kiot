# Debt manual diff - NCC177354084249

## Scope

- Partner: `NCC177354084249 - Hưng Hoa Mai`.
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
| Documents | 22 |
| Ledger rows | 15 |
| Cashflow rows | 19 |
| Timeline rows | 49 |
| Issues | 11 |
| Possible matches | 33 |
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
| PN20260315085945 | PN20260315085945 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260315085945 | PCPN20260315085945 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260315085945 | PC20260315091850 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260318091723 | PN20260318091723 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260318091723 | PCPN20260318091723 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260318091723 | PC20260318092043 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260318092133 | PN20260318092133 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260318092133 | PCPN20260318092133 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260318092133 | PC20260318092411 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260318092654 | PN20260318092654 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260318092654 | PCPN20260318092654 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260318092654 | PC20260318094223 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260323084157 | PN20260323084157 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260323084157 | PCPN20260323084157 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260323084157 | PC2026032308484489 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260324093633 | PN20260324093633 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260324093633 | PCPN20260324093633 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260324093633 | PC20260324094002 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260326141354 | PN20260326141354 | ledger | exact_reference | 100 | high | Direct FK/reference id matches. |
| PN20260326141354 | PCPN20260326141354 | ledger | normalized_base_code | 100 | high | Direct FK/reference id matches. |
| PN20260326141354 | PC20260326141857 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260327160826 | PC20260327161934 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260327163143 | PC20260327163337 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260327163501 | PC20260327164024 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260327172157 | PC20260327172301 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260327172411 | PC20260327172527 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260330230446 | PC20260330230615 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260331163818 | PC2026033116500172 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260403113315 | PC20260403113541 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |
| PN20260411150349 | PC20260411150611 | cashflow | exact_reference | 100 | high | Cashflow/payment pair explains document settlement. |

## Matching matrix

| Source type | Source code | Base code | Expected | Ledger | Cashflow | Best match | Score | Match status | Severity | Auto candidate | Issue |
|---|---|---|---:|---:|---:|---|---:|---|---|---|---|
| purchase | PN20260315085945 | PN20260315085945 | 0 | 8000000 | 8000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260318091723 | PN20260318091723 | 0 | 3500000 | 3500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260318092133 | PN20260318092133 | 0 | 5500000 | 5500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260318092654 | PN20260318092654 | 0 | 7500000 | 7500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260323084157 | PN20260323084157 | 0 | 23000000 | 23000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260324093633 | PN20260324093633 | 0 | 6000000 | 6000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260326141354 | PN20260326141354 | 0 | 32500000 | 32500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260327160826 | PN20260327160826 | 0 |  | 27830000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260327163143 | PN20260327163143 | 0 |  | 2500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260327163501 | PN20260327163501 | 0 |  | 8270000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260327172157 | PN20260327172157 | 0 |  | 2000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260327172411 | PN20260327172411 | 0 |  | 11000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260330230446 | PN20260330230446 | 0 |  | 100000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260331163444 | PN20260331163444 | 4018750 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260331163818 | PN20260331163818 | 0 |  | 18000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260403113315 | PN20260403113315 | 0 |  | 2550000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260411150349 | PN20260411150349 | 0 |  | 7210000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260421164439 | PN20260421164439 | 0 |  | 36450000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260421170122 | PN20260421170122 | 0 |  | 4500000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260504143614 | PN20260504143614 | 0 |  | 17000000 | exact_reference | 100 | ZERO_EFFECT_DOCUMENT | info | no | Zero-effect document should not create a high missing-ledger issue. |
| purchase | PN20260512143210 | PN20260512143210 | 30400000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| purchase | PN20260603101848 | PN20260603101848 | 300000 |  |  |  | 0 | MISSING_LEDGER | high | yes | No ledger candidate found after exact/FK/normalized/amount-date strategies. |
| ledger | PCPN20260315085945 | PN20260315085945 | -8000000 | -8000000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260318092654 | PN20260318092654 | -7500000 | -7500000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260318091723 | PN20260318091723 | -3500000 | -3500000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260318092133 | PN20260318092133 | -5500000 | -5500000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260324093633 | PN20260324093633 | -6000000 | -6000000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260323084157 | PN20260323084157 | -23000000 | -23000000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | PCPN20260326141354 | PN20260326141354 | -32500000 | -32500000 |  |  | 0 | MISSING_DOCUMENT | high | no | Ledger row has no matching document. |
| ledger | DCNCC260413432 | DCNCC260413432 | -8000000 | -8000000 |  | manual_merge_ledger | 0 | MANUAL_LEDGER_REQUIRES_AUTHORITY | critical | no | Manual/merge ledger requires source authority before any fix. |

## Detected issues

| Severity | Type | Evidence | Suggested action | Auto fix |
|---|---|---|---|---|
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260331163444","source_base_code":"PN20260331163444","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260512143210","source_base_code":"PN20260512143210","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260603101848","source_base_code":"PN20260603101848","issue":"No ledger candidate found after exact/FK/normalized/amount-date strategies.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Review candidate preview only if group is B and no ambiguity remains. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260315085945","source_base_code":"PN20260315085945","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318092654","source_base_code":"PN20260318092654","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318091723","source_base_code":"PN20260318091723","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318092133","source_base_code":"PN20260318092133","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260324093633","source_base_code":"PN20260324093633","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260323084157","source_base_code":"PN20260323084157","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260326141354","source_base_code":"PN20260326141354","issue":"Ledger row has no matching document.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Find source document or keep as manual review; do not delete automatically. | no |
| critical | MANUAL_LEDGER_REQUIRES_AUTHORITY | {"source_type":"ledger","source_code":"DCNCC260413432","source_base_code":"DCNCC260413432","issue":"Manual/merge ledger requires source authority before any fix.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH","best_match_score":0} | Manual/merge ledger must be reviewed by authority before any write. | no |
