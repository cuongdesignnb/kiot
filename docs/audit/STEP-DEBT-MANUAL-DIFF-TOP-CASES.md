# STEP - Debt manual diff top cases

## Phạm vi

- Module: công nợ khách hàng, nhà cung cấp, dual-role partner.
- Cases: `NCC177624592772`, `NCC177354084249`, `NCC177950763826`, `KH177518347435`.
- Groups: `C_LEDGER_DOCUMENT_MISMATCH`, `D_CUSTOMER_ONLY_REVIEW`.
- Rủi ro: ledger/chứng từ lệch, thiếu ledger, ledger không có chứng từ gốc rõ ràng, cashflow chưa match đủ.

## Data safety

- Migration: không chạy.
- Backfill: không chạy.
- Update dữ liệu cũ: không chạy.
- Delete: không chạy.
- Recalculate: không chạy.
- Ghi DB: không.
- Export JSON/Markdown: có. JSON evidence nằm trong `storage/app/audits/manual-diff` và không commit; Markdown evidence nằm trong `docs/audit/diff`.
- Có chạy migrate:fresh không: không.

## Input

- Plan JSON: `storage/app/audits/20260604-113305/debt-fix-plan.json`.
- Inspect JSON: command tự inspect read-only theo partner code và export evidence JSON.
- Snapshot: `20260604-113305`.
- Commit SHA: this commit, `feat(debt): add manual diff and guarded apply preview commands`.

## Summary

| Code | Name | Group | Diff status | Issue count | Candidate for apply | Reason |
|---|---|---|---|---:|---|---|
| `NCC177624592772` | Trọng Hùng | `C_LEDGER_DOCUMENT_MISMATCH` | `manual_review` | 36 | No | Group C mặc định blocked; matrix có nhiều `MISSING_LEDGER`, `MISSING_DOCUMENT`, `UNMATCHED`. |
| `NCC177354084249` | Hưng Hoa Mai | `C_LEDGER_DOCUMENT_MISMATCH` | `manual_review` | 30 | No | Group C mặc định blocked; có `AMOUNT_MISMATCH`, `MISSING_LEDGER`, `MISSING_DOCUMENT`. |
| `NCC177950763826` | Anh Thanh Thiên Phú | `C_LEDGER_DOCUMENT_MISMATCH` | `manual_review` | 12 | No | Group C mặc định blocked; có 2 dòng matched nhưng vẫn còn thiếu ledger/chứng từ. |
| `KH177518347435` | An-Lê Đức Thọ | `D_CUSTOMER_ONLY_REVIEW` | `manual_review` | 5 | No | Group D blocked mặc định; cần review customer ledger/documents/cashflows trước khi chọn authority. |

## Case details

### NCC177624592772 - Trọng Hùng

- Group: `C_LEDGER_DOCUMENT_MISMATCH`.
- Stored balance: customer receivable `2,050,000`, supplier payable `882,453,000`, customer view `-880,403,000`, supplier view `880,403,000`.
- Documents: `17`.
- Ledger: `12`.
- Cashflow: `7`.
- Matched: `0`.
- Missing ledger: `17`.
- Duplicate ledger: `0`.
- Amount mismatch: `0`.
- Sign mismatch: `0`.
- Status mismatch: `0`.
- Proposed resolution: `manual_review`.
- Candidate for apply: no.
- Required confirmation: yes.
- Rollback requirement: required before any real fix.

### NCC177354084249 - Hưng Hoa Mai

- Group: `C_LEDGER_DOCUMENT_MISMATCH`.
- Stored balance: customer receivable `0`, supplier payable `30,700,000`, customer view `-30,700,000`, supplier view `30,700,000`.
- Documents: `22`.
- Ledger: `15`.
- Cashflow: `19`.
- Matched: `0`.
- Missing ledger: `15`.
- Duplicate ledger: `0`.
- Amount mismatch: `7`.
- Sign mismatch: `0`.
- Status mismatch: `0`.
- Proposed resolution: `manual_review`.
- Candidate for apply: no.
- Required confirmation: yes.
- Rollback requirement: required before any real fix.

### NCC177950763826 - Anh Thanh Thiên Phú

- Group: `C_LEDGER_DOCUMENT_MISMATCH`.
- Stored balance: customer receivable `47,400,000`, supplier payable `75,000,000`, customer view `-27,600,000`, supplier view `27,600,000`.
- Documents: `12`.
- Ledger: `4`.
- Cashflow: `1`.
- Matched: `2`.
- Missing ledger: `10`.
- Duplicate ledger: `0`.
- Amount mismatch: `0`.
- Sign mismatch: `0`.
- Status mismatch: `0`.
- Proposed resolution: `manual_review`.
- Candidate for apply: no.
- Required confirmation: yes.
- Rollback requirement: required before any real fix.

### KH177518347435 - An-Lê Đức Thọ

- Group: `D_CUSTOMER_ONLY_REVIEW`.
- Stored balance: customer receivable `0`, supplier payable `0`, customer view `0`, supplier view `0`.
- Documents: `3`.
- Ledger: `2`.
- Cashflow: `3`.
- Matched: `0`.
- Missing ledger: `2`.
- Duplicate ledger: `1`.
- Amount mismatch: `0`.
- Sign mismatch: `0`.
- Status mismatch: `0`.
- Proposed resolution: `manual_review`.
- Candidate for apply: no.
- Required confirmation: yes.
- Rollback requirement: required before any real fix.

## Apply readiness

| Group | Ready count | Blocked count | Notes |
|---|---:|---:|---|
| `C_LEDGER_DOCUMENT_MISMATCH` | 0 | 3 | Blocked mặc định; cần manual diff đầy đủ và Senior Auditor duyệt bằng văn bản. |
| `D_CUSTOMER_ONLY_REVIEW` | 0 | 1 | Blocked mặc định; chưa chọn authority. |
| `A_OPENING_BALANCE_REVIEW` | 0 | 0 | Không nằm trong 4 case diff bắt buộc ở bước này. |
| `B_DOCUMENTS_NO_LEDGER` | 0 | 0 | Apply-preview dry-run đã chạy riêng trên plan snapshot, nhưng plan hiện chưa có proposed write operations. |

## Apply preview guard

- Command: `php artisan debt:apply-fix-plan`.
- Preview command đã chạy: `php artisan debt:apply-fix-plan --dry-run --plan-json=storage/app/audits/20260604-113305/debt-fix-plan.json --group=B_DOCUMENTS_NO_LEDGER --limit=1 --rollback-export=storage/app/audits/manual-diff/apply-preview-B_DOCUMENTS_NO_LEDGER.rollback.json`.
- Selected partner: `KH177460073148`.
- Apply enabled: `false`.
- Write DB: `false`.
- Write operations preview: `0`.
- Rollback preview: có export local, không commit JSON.

## Kết luận

- Đạt/chưa đạt: đạt cho manual diff và apply-preview nếu tests/build PASS.
- Có case nào đủ candidate chưa: chưa.
- Có thể chạy fix thật chưa: chưa.
- Cần xác nhận gì: Senior Auditor phải duyệt group, partner allowlist, backup DB đã verify, rollback export, confirmation code thật, và candidate evidence trước khi triển khai apply thật.
