# Debt manual diff - NCC177354084249

## Scope

- Partner: `NCC177354084249 - Hưng Hoa Mai`.
- Generated at: `2026-06-04T12:29:08+07:00`.
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
| Documents | 22 |
| Ledger rows | 15 |
| Cashflow rows | 19 |
| Timeline rows | 49 |
| Issues | 30 |

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
| purchase | PN20260315085945 | 0 | 8000000 | 8000000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260318091723 | 0 | 3500000 | 3500000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260318092133 | 0 | 5500000 | 5500000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260318092654 | 0 | 7500000 | 7500000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260323084157 | 0 | 23000000 | 23000000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260324093633 | 0 | 6000000 | 6000000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260326141354 | 0 | 32500000 | 32500000 | AMOUNT_MISMATCH | Document expected effect and ledger amount differ. |
| purchase | PN20260327160826 | 0 |  | 27830000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260327163143 | 0 |  | 2500000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260327163501 | 0 |  | 8270000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260327172157 | 0 |  | 2000000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260327172411 | 0 |  | 11000000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260330230446 | 0 |  | 100000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260331163444 | 4018750 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260331163818 | 0 |  | 18000000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260403113315 | 0 |  | 2550000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260411150349 | 0 |  | 7210000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260421164439 | 0 |  | 36450000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260421170122 | 0 |  | 4500000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260504143614 | 0 |  | 17000000 | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260512143210 | 30400000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| purchase | PN20260603101848 | 300000 |  |  | MISSING_LEDGER | No ledger row matched document code/reference. |
| ledger | PCPN20260315085945 | -8000000 | -8000000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260318092654 | -7500000 | -7500000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260318091723 | -3500000 | -3500000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260318092133 | -5500000 | -5500000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260324093633 | -6000000 | -6000000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260323084157 | -23000000 | -23000000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | PCPN20260326141354 | -32500000 | -32500000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |
| ledger | DCNCC260413432 | -8000000 | -8000000 |  | MISSING_DOCUMENT | Ledger row has no matching document by code/reference. |

## Detected issues

| Severity | Type | Evidence | Suggested action | Auto fix |
|---|---|---|---|---|
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260315085945","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260318091723","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260318092133","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260318092654","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260323084157","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260324093633","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | AMOUNT_MISMATCH | {"source_type":"purchase","source_code":"PN20260326141354","issue":"Document expected effect and ledger amount differ.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260327160826","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260327163143","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260327163501","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260327172157","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260327172411","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260330230446","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260331163444","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260331163818","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260403113315","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260411150349","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260421164439","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260421170122","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260504143614","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260512143210","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_LEDGER | {"source_type":"purchase","source_code":"PN20260603101848","issue":"No ledger row matched document code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260315085945","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318092654","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318091723","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260318092133","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260324093633","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260323084157","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"PCPN20260326141354","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
| high | MISSING_DOCUMENT | {"source_type":"ledger","source_code":"DCNCC260413432","issue":"Ledger row has no matching document by code/reference.","fix_group":"C_LEDGER_DOCUMENT_MISMATCH"} | Manual review each document, ledger, and cashflow row before selecting authority. | no |
