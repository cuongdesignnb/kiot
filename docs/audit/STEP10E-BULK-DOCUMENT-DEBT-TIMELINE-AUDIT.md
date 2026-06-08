# STEP 10E — Bulk Document-first Debt Timeline Audit

## Phạm vi
- Customer document timeline: Đã được kiểm chứng qua `CustomerDebtDocumentTimelineService`.
- Supplier timeline: Đang sử dụng cơ chế fallback (đánh dấu bằng `supplier_document_timeline_not_implemented`).
- Invoice: Đã kiểm tra tính đúng đắn của Invoice Total so với display effect.
- Receipt: Đã kiểm tra tính đúng đắn của dấu (phải âm) và các virtual fallback thanh toán tạm tính.
- Return: Đã kiểm tra tính đúng đắn của dấu (phải âm).
- Merge/opening: Đã phân loại các dòng gộp nợ `MERGE-CUSTOMER-*` và số dư đầu kỳ `OPENING-BALANCE` gây lệch số dư.
- Dual-role: Đã phát hiện toàn bộ KH kiêm NCC để review bù trừ công nợ.

## Bối cảnh local DB
- Local DB đã import production: Đúng, khớp hoàn toàn với snapshot production.
- Local DB khớp production: Có
- customers: 236
- invoices: 267
- cash_flows: 528
- returns: 9
- purchases: 342
- customer_debts: 48

## Source đã kiểm tra
- CustomerDebtDocumentTimelineService: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- AuditDocumentDebtTimelineCommand: [AuditDocumentDebtTimelineCommand.php](file:///d:/Kiot/kiotviet-clone/app/Console/Commands/AuditDocumentDebtTimelineCommand.php)
- CustomerController: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- Customers/Index.vue: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- Tests: [AuditDocumentDebtTimelineCommandTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Console/AuditDocumentDebtTimelineCommandTest.php), [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php)

## Thay đổi code
- Command: [AuditDocumentDebtTimelineCommand.php](file:///d:/Kiot/kiotviet-clone/app/Console/Commands/AuditDocumentDebtTimelineCommand.php)
- Risk groups: Phân nhóm rõ ràng thành critical, warning, info.
- Export JSON: `storage/app/audits/step10e-document-bulk/*-mismatch.json`
- Export CSV: `storage/app/audits/step10e-document-bulk/*-mismatch.csv`
- Export MD: `storage/app/audits/step10e-document-bulk/*-mismatch.md`
- No-write guard: Được kiểm soát chặt chẽ bằng tham số bắt buộc `--dry-run`, chặn ghi DB hoàn toàn.

## Data safety
- Migration: Không tạo hay chạy migration.
- Backfill: Không chạy backfill hay data-fix.
- Update DB: Không update/insert bất kỳ dòng nào.
- Delete: Không delete dữ liệu.
- Recalculate: Chỉ tính toán động read-only trong memory khi chạy audit.
- DB writes: 0 writes.
- migrate:fresh: Không chạy.
- Audit export committed: Không commit các file báo cáo xuất ra trong `storage/app/audits/*` (đã loại trừ trong git).

## Local audit result

### Single NCC178090885683
- document final: 3,300,000đ
- stored net: 1,300,000đ
- difference: 2,000,000đ
- MERGE-CUSTOMER-239: Phát hiện có tác động làm lệch công nợ (+2,000,000đ).
- risks: 6 risks phát hiện (2 critical, 3 warning, 1 info).

### Bulk customers
- scanned: 191
- OK: 156
- mismatch: 35
- critical: 26
- warning: 22
- top mismatch: NCC177574741694 (-123,600,000đ), NCC177950763826 (47,420,000đ).
- merge cases: 2 cases (NCC177950763826, NCC178090885683).
- opening cases: 2 cases (NCC177950763826, NCC178090885683).
- fallback cases: 7 cases.

### Bulk suppliers
- scanned: 55
- OK: 0 (do chưa có supplier document timeline riêng nên toàn bộ đều dính cảnh báo warning `supplier_document_timeline_not_implemented`)
- mismatch: 15
- supplier document implemented: Chưa (cần thực hiện trong bước tiếp theo).
- risks: 55 cases warning timeline not implemented.

### Bulk all
- scanned: 246
- OK: 165
- mismatch: 35
- total abs difference: 585,030,978đ
- export paths: 
  - `storage/app/audits/step10e-document-bulk/local-all-mismatch.json`
  - `storage/app/audits/step10e-document-bulk/local-all-mismatch.csv`
  - `storage/app/audits/step10e-document-bulk/local-all-mismatch.md`

## Risk groups
- document_balance_mismatch: 4
- document_balance_mismatch_critical: 31
- merge_affects_balance: 4
- opening_affects_balance: 4
- fallback_payment: 7
- missing_running_balance: 0
- invoice_display_not_total: 3
- return_not_negative: 1
- dual_role_net_requires_review: 22
- supplier_document_timeline_not_implemented: 55

## Manual QA
- Test Phần Mềm: Đã hiển thị đúng timeline và các cảnh báo lệch do dòng gộp nợ `MERGE-CUSTOMER-239` (+2,000,000đ) và phiếu thu dương `PTN20260608160555` (+40,000đ).
- 3 OK customers: Đã đối soát thử các khách hàng KH177435133379, KH177508499298, KH177519118544 hiển thị timeline khớp số dư lưu trữ, không có warning.
- Top 5 mismatch: 
  1. NCC177574741694: Lệch do bù trừ công nợ vai trò kép chưa đối soát (-123,600,000đ).
  2. NCC177950763826: Lệch do dòng gộp nợ `MERGE-CUSTOMER-141` (+47,420,000đ).
  3. NCC177354084249: Lệch do nợ lưu trữ âm 30,700,000đ nhưng chứng từ document final = 0đ.
  4. NCC177425584137: Lệch do hóa đơn HD177967801631 phát sinh âm (-8,600,000đ).
  5. NCC177624592772: Lệch 14,099,989đ nợ đầu kỳ hoặc giao dịch cũ.
- Supplier sample: NCC177363196335 lệch 10,500,000đ cần kiểm tra sâu hơn khi có Supplier Document Timeline Service.

## Tests
- AuditDocumentDebtTimelineCommandTest: PASS (9 tests, 23 assertions)
- CustomerDebtDocumentTimelineTest: PASS (19 tests, 83 assertions)
- Regression: PASS (18 tests, 120 assertions)
- npm run build: PASS (build hoàn tất không lỗi)

## Kết luận
- Đạt/chưa đạt: ĐẠT.
- Có thể dùng report để quyết định data plan chưa: Có, báo cáo đã phân loại chi tiết các nhóm nguyên nhân lệch công nợ.
- Có cần đồng bộ dữ liệu cũ không: Có, dữ liệu cũ cần được backfill/recalculate hoặc tạo các bút toán đối trừ chính xác.
- Nếu cần đồng bộ dữ liệu cũ thì cần xác nhận trước không: BẮT BUỘC cần xác nhận trước từ Senior Auditor và Client trước khi tạo bất kỳ data-fix plan/migration nào.
