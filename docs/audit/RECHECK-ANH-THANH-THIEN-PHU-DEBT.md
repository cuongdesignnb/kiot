# RECHECK - Anh Thanh Thiên Phú debt

## Phạm vi

- Partner: `NCC177950763826 - Anh Thanh Thiên Phú`.
- ID hiện tại: `210`.
- Mục tiêu: kiểm tra lại vì report cũ ghi `VIRTUAL_OPENING_REQUIRED`, còn plan sau import DB mới ghi `DOCUMENT_LEDGER_MISMATCH`.
- Data safety: chỉ chạy read-only audit/inspect/plan, không ghi DB, không backfill, không update số dư.

## Input đã chạy

- Inspect JSON: `storage/app/audits/recheck-anh-thanh-inspect.json`.
- Audit CSV: `storage/app/audits/recheck-anh-thanh-audit.csv`.
- Plan JSON: `storage/app/audits/recheck-anh-thanh-plan.json`.
- Plan Markdown: `docs/audit/RECHECK-ANH-THANH-THIEN-PHU-DEBT.md`.
- Snapshot: ad-hoc, cùng local DB sau khi import `kiot_db.sql.zip`.

## Kết quả hiện tại

| Field | Value |
|---|---|
| Code | `NCC177950763826` |
| Name | `Anh Thanh Thiên Phú` |
| Audit classification | `DOCUMENT_LEDGER_MISMATCH` |
| Inspect diagnosis | `ledger_and_documents_mismatch` |
| Plan fix group | `C_LEDGER_DOCUMENT_MISMATCH` |
| Authority candidate | `manual_review` |
| Ledger count | `4` |
| Document count | `13` |
| Cash flow count | `1` |
| Customer virtual opening | `true` |
| Supplier virtual opening | `true` |

## Vì sao report cũ là VIRTUAL_OPENING_REQUIRED

- Report drill-down trước được tạo từ snapshot cũ, trước khi import DB mới nhất từ `kiot_db.sql.zip`.
- Ở snapshot cũ, virtual opening giải thích được hiển thị số dư nên được phân loại `VIRTUAL_OPENING_REQUIRED` / `virtual_opening_display_resolved`.
- Kết quả đó không còn là authority mới nhất sau khi DB đã được nhập lại.

## Vì sao plan mới là DOCUMENT_LEDGER_MISMATCH

- Recheck sau import DB mới thấy partner này có cả ledger và chứng từ: `ledger_count=4`, `document_count=13`.
- Inspect diagnosis hiện tại là `ledger_and_documents_mismatch`, nghĩa là vừa có ledger vừa có chứng từ nhưng reconcile vẫn lệch.
- Vì vậy plan không được phép tự chọn virtual opening, ledger hay document là đúng; phải manual review từng chứng từ/ledger/cashflow.

## Kiểm tra khả năng đọc nhầm JSON

- Plan riêng đã chạy từ CSV một dòng `recheck-anh-thanh-audit.csv`.
- Inspect JSON riêng được export theo đúng code `NCC177950763826`.
- Không dùng lại thư mục top-20 cũ cho kết luận này.
- Kết luận: không phải plan đọc nhầm JSON; khác biệt đến từ input DB/snapshot mới hơn.

## Có được phép fix không

- Có thể chạy fix thật chưa: chưa.
- Lý do: group hiện tại là `C_LEDGER_DOCUMENT_MISMATCH`, thuộc nhóm cần manual review và confirmation trước khi write DB.
- Cần xác nhận trước khi triển khai.

## Kết luận

- Classification cuối cùng theo DB mới: `DOCUMENT_LEDGER_MISMATCH`.
- Diagnosis cuối cùng theo DB mới: `ledger_and_documents_mismatch`.
- Action đúng hiện tại: so từng chứng từ với ledger/cashflow, xác định missing/duplicate/status/sign sai trước khi lập lệnh fix thật.
- Không backfill, không insert ledger thật, không tạo opening balance thật trong bước này.
