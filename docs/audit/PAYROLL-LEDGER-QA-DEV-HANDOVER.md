# QA DEV HANDOVER - NỢ LƯƠNG & TẠM ỨNG

## 1. Phạm vi đã hoàn thiện

- Chuẩn hóa CashFlow active, bao gồm dữ liệu legacy có `status = NULL`.
- Loại CashFlow thanh toán lương và tạm ứng khỏi P&L; dòng tiền vẫn giữ CashFlow active.
- Hoàn thiện timeline, detail, export, adjustment, danh sách/hủy tạm ứng và applied advance.
- Thêm đối soát read-only qua service, API, CSV, command và trang quản trị.
- Không convert `employees.balance`, không cấp quyền role production và không chạy migration production.

Bất biến được giữ:

```text
Balance = SUM(amount WHERE is_effective = true)
```

Không có phép tính balance nào dùng `status = valid` làm điều kiện thay thế.

## 2. Các file chính đã thay đổi

- CashFlow/P&L: `CashFlow`, `PayrollCashFlowClassifier`, Financial Report, cost-profit và audit profit command.
- Payroll API: ledger controller/service, advance controller, date policy và access service.
- Reconciliation: `PayrollReconciliationService`, controller và `AuditSalaryLedger` command.
- UI: Employee ledger tab, Paysheets, `CancelReasonModal` và Payroll Reconciliation page.
- Regression công nợ: sửa fallback summary và compatibility fields của supplier document timeline.

## 3. UI/UX đã hoàn thiện

- Timeline có filter ngày, loại, trạng thái, chi nhánh, keyword và phân trang.
- Summary tài chính chỉ chịu filter ngày/chi nhánh; `filtered_summary` phản ánh danh sách lọc.
- Có chú giải số dư dương, bằng 0 và âm.
- Mã phiếu mở paysheet hoặc modal detail read-only.
- Có export UTF-8 BOM CSV theo quyền.
- Có form điều chỉnh tăng/giảm; frontend chỉ gửi amount dương.
- Có danh sách tạm ứng và modal hủy; tạm ứng đã phân bổ bị khóa hủy.
- Payslip hiển thị riêng tổng lương, tạm ứng đã cấn, đã thanh toán và còn cần trả.
- Prompt hủy paysheet/payment được thay bằng modal lý do chuẩn.

## 4. API đã bổ sung

```http
GET /api/employee-salary-ledger-entries/{entry}
GET /api/employees/{employee}/salary-advances
GET /api/employees/{employee}/salary-ledger/export
GET /api/payroll/date-policy
GET /api/payroll/reconciliation
GET /api/payroll/reconciliation/export
GET /employees/payroll/reconciliation
```

Các API có permission middleware và branch scope ở backend.

## 5. Đối soát/audit command

```bash
php artisan payroll:audit-salary-ledger \
  --section=all|cache|payments|advances|legacy \
  --branch= \
  --employee= \
  --format=table|csv|json \
  --output=
```

Command chỉ đọc. Repair cache tiếp tục dùng command rebuild riêng.

Nhóm issue đã triển khai:

```text
CACHE_MISMATCH
MISSING_CACHE
LEGACY_BALANCE_EXISTS
NEGATIVE_BALANCE
OUTSTANDING_ADVANCE
PAYMENT_WITHOUT_LEDGER
PAYMENT_WITHOUT_CASHFLOW
CASHFLOW_AMOUNT_MISMATCH
ADVANCE_WITHOUT_LEDGER
ADVANCE_WITHOUT_CASHFLOW
ADVANCE_APPLICATION_MISMATCH
```

`IGNORE_AS_DEMO` không được tự suy luận. Legacy có chứng từ hoặc ledger được đề xuất `NEED_MANUAL_REVIEW`.

## 6. Quy tắc CashFlow/P&L sau chỉnh

- Active: `deleted_at IS NULL AND (status IS NULL OR status != cancelled)`.
- Payroll-related được nhận diện bằng `reference_type`, `category` và `source_type` nếu có.
- Không đọc description/note để phân loại.
- Financial Report, cost-profit và audit profit dùng chung `nonPayrollForExpense()`.
- Chi phí lương P&L chỉ lấy bảng lương `locked`.
- CashFlow payment/advance active vẫn xuất hiện trong báo cáo dòng tiền.

## 7. Kết quả test

Đạt:

```text
Payroll và targeted financial/CashFlow/debt regression:
92 tests, 522 assertions, tất cả pass.
Frontend production build: pass, 920 modules transformed.
```

Các case chính đã pass:

- Balance dựa trên `is_effective`.
- Reversed và cancel_reverse cùng tham gia số dư.
- Không double-count advance.
- Payment idempotency.
- Timeline summary không đổi theo keyword.
- API detail/list/export/date-policy.
- Reconciliation API và command read-only, kết quả thống nhất.
- Legacy CashFlow `status = NULL` vẫn active.
- P&L không double-count payroll CashFlow.
- Regression case Anh Thanh Thiên Phú đã pass sau khi sửa fallback supplier summary.

## 8. Regression tài chính/công nợ

Targeted suites liên quan thay đổi đã pass:

- Financial report payroll expense.
- P&L CashFlow exclusions.
- Cancelled/deleted CashFlow.
- Customer/supplier reconciliation case Anh Thanh Thiên Phú.

Full suite chạy trực tiếp bằng:

```bash
php -d memory_limit=512M vendor/bin/phpunit
```

Kết quả:

```text
Tests: 1202
Assertions: 6007
Errors: 19
Failures: 27
Skipped: 5
```

Các lỗi còn lại tập trung ở contract legacy customer/supplier debt timeline: thiếu compatibility keys, virtual opening, running balance, offset deduplication và legacy export. Đây không phải lỗi ledger payroll mới, nhưng là blocker production theo yêu cầu BA vì full financial/debt regression chưa xanh.

Lệnh `php artisan test` còn bị tiến trình con dùng memory limit 128M; chạy trực tiếp PHPUnit với 512M mới hoàn tất suite.

## 9. Ma trận quyền đề xuất

- `super_admin`: toàn bộ.
- Kế toán trưởng: ledger/export/pay/cancel/advance/adjust/reconciliation/override.
- Kế toán: ledger/export/pay/advance/reconciliation view.
- HR: payroll và ledger view, chưa mặc định adjustment.
- `branch_admin`: trong branch; không mặc định adjust/rebuild/override.
- Cashier/warehouse/task manager: không xem lương.
- Employee self-view: chưa triển khai.

Quyền mới:

```text
payroll.reconciliation.view
payroll.reconciliation.export
```

Chỉ thêm vào permission catalog, chưa cấp cho role production.

## 10. Anomaly report mẫu

```json
{
  "employee_code": "NV-QA",
  "salary_balance_cache": 1,
  "ledger_balance": 300000,
  "difference": -299999,
  "issues": ["CACHE_MISMATCH"],
  "primary_status": "CACHE_MISMATCH",
  "suggested_action": null
}
```

## 11. Các quyết định cần BA/Owner xác nhận

- Option A/B cho `employees.balance` legacy.
- Backfill chứng từ hay opening balance, không dùng đồng thời cho cùng giai đoạn.
- Ma trận quyền production.
- Hạn mức và quyền override tạm ứng.
- Chính sách nhân viên nghỉ việc còn số dư.
- Quyền hủy payment/advance tại chi nhánh.

## 12. Production readiness checklist

```text
[x] Payroll targeted tests pass.
[x] CashFlow/P&L targeted tests pass.
[x] Frontend build pass.
[x] Reconciliation read-only đã có.
[x] Không convert legacy hoặc cấp quyền production.
[ ] Full financial/debt regression pass hoặc được Tech Lead loại trừ chính thức.
[ ] Có anomaly report staging.
[ ] BA/Owner chốt legacy và permission matrix.
[ ] Có rollback plan production migration.
```

## 13. Rủi ro còn lại

- Full suite còn 46 lỗi/error ở debt timeline legacy nên chưa đủ điều kiện production.
- Reconciliation hiện tối ưu cho QA/read-only; dữ liệu rất lớn cần bổ sung chunking/export job.
- Migration cho phép CashFlow legacy `status = NULL` mới chỉ được tạo, chưa áp dụng production.
- Chưa có browser E2E framework; UI được xác nhận qua Vite build và API tests.
- PHP CLI phát cảnh báo extension OCI/Firebird không tồn tại; không ảnh hưởng test MySQL nhưng nên dọn cấu hình môi trường.

## 14. Đề xuất bước tiếp theo

1. Chạy audit command trên staging và lưu CSV/JSON làm baseline.
2. Tách xử lý 46 lỗi full-suite debt legacy thành workstream riêng.
3. BA/Owner phê duyệt legacy option và permission matrix.
4. Chỉ lập production rollout sau khi full regression được xử lý hoặc có biên bản loại trừ.

Kết luận:

```text
CORE DEV: ĐẠT
QA DEV PAYROLL TARGETED: ĐẠT
PRODUCTION READINESS: CHƯA ĐẠT
```
