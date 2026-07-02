# PAYROLL LEGACY 50M RECONCILIATION HANDOVER

## 1. Mục tiêu

Đối chiếu nhân viên có `employees.balance = 50,000,000` trước khi chọn migration
strategy cho module Nợ lương/Tạm ứng.

## 2. Môi trường chạy

| Hạng mục | Giá trị |
|---|---|
| Audit DB | `kiot_prod_copy_payroll_20260614_003011` |
| Database engine | MariaDB 10.11 trong Docker cô lập |
| APP_ENV | `staging` |
| Production live | Không |
| Ngày chạy | 2026-06-14 |
| Người chạy | Codex Agent |

Hai migration payroll đang ở trạng thái `Ran`, batch 52 và 53.

## 3. Thông tin nhân viên

| Hạng mục | Giá trị |
|---|---|
| Employee ID | 7 |
| Employee code | `NV000012` |
| Employee name | `V***` |
| Trạng thái | Active |
| Legacy balance | 50,000,000 |
| Salary balance cache | 0 |
| Ledger balance hiện tại | 0 |

Không đưa PII đầy đủ vào Git. Dữ liệu raw nằm trong `storage/app/audit` và bị
Git ignore.

## 4. Payslip locked summary

Nhân viên có tổng cộng 5 payslip: 4 thuộc paysheet `cancelled`, 1 thuộc
paysheet `locked`.

| Chỉ số | Giá trị |
|---|---:|
| Locked payslip count | 1 |
| Locked total salary | 0 |
| Locked paid amount | 0 |
| Locked applied advance | 0 |
| Locked remaining | 0 |
| Expected remaining | 0 |

Locked payslip thuộc kỳ tháng 04/2026 nhưng tất cả giá trị tài chính đều bằng 0.

## 5. Payment/CashFlow/Advance/Ledger summary

| Nhóm | Số lượng | Tổng tiền | Ghi chú |
|---|---:|---:|---|
| Paysheet payments | 0 | 0 | Không có payment legacy |
| Salary-related CashFlow | 0 | 0 | Không có CashFlow payroll nhận diện bằng field cấu trúc |
| Salary advances | 0 | 0 | Bảng mới rỗng |
| Advance applications | 0 | 0 | Bảng mới rỗng |
| Ledger effective entries | 0 | 0 | Chưa chạy data migration |

CashFlow chỉ được kiểm tra bằng `reference_type`, `target_type` và `target_id`;
không phân loại bằng nội dung mô tả.

## 6. So sánh số dư

| Công thức | Kết quả |
|---|---:|
| Legacy balance | 50,000,000 |
| Ledger balance | 0 |
| Locked remaining | 0 |
| Expected remaining | 0 |
| Legacy - Locked remaining | 50,000,000 |
| Legacy - Expected remaining | 50,000,000 |

Legacy balance không được giải thích bởi payslip locked hiện có.

## 7. Nhận định BA

```text
[ ] Legacy balance trùng locked remaining -> nghiêng về Backfill documents, không opening_balance cùng kỳ.
[x] Legacy balance là số dư thật từ KiotViet -> OPENING_BALANCE_APPROVED.
[ ] Legacy balance không khớp locked payslip -> NEED_MANUAL_REVIEW.
[ ] Owner xác nhận demo/test -> Option B anomaly only.
```

BA/Owner đã xác nhận khoản 50,000,000 là số dư thật cũ chuyển từ hệ thống
KiotViet. Khoản này không được giải thích bằng payslip locked hiện tại vì locked
total salary/remaining đều bằng 0. Khoản này cần được đưa vào ledger mới bằng
một entry `opening_balance` tại go-live/cutoff được duyệt.

## 8. Khuyến nghị migration

```text
[ ] Đề xuất Backfill documents.
[x] Đề xuất Opening balance.
[ ] Đề xuất Option B anomaly only.
[ ] Đề xuất kế toán review thủ công trước.
```

Không dùng backfill documents để tạo khoản 50,000,000. Nếu cần backfill chứng từ
khác, chỉ backfill sau cutoff hoặc phải chứng minh không chồng lấn với opening
balance.

## 9. Rủi ro nếu chọn sai

| Rủi ro | Ảnh hưởng | Cách tránh |
|---|---|---|
| Double-count nợ lương | Số dư nhân viên bị tăng sai | Không dùng opening balance và backfill cùng phạm vi/cutoff |
| Bỏ sót nợ lương cũ | Công ty thiếu khoản phải trả nhân viên | Kế toán xác nhận nguồn và cutoff |
| Kéo dữ liệu demo vào ledger | Báo cáo tài chính sai | Owner xác nhận dữ liệu thật/demo |
| Sai cache sau migration | UI hiển thị lệch | Chỉ rebuild bằng command sau khi strategy được duyệt |

## 10. File output liên quan

```text
storage/app/audit/payroll-legacy-balance-employees-raw.jsonl
storage/app/audit/payroll-legacy-50m-payslips.json
storage/app/audit/payroll-legacy-50m-payslip-summary.json
storage/app/audit/payroll-legacy-50m-payments.json
storage/app/audit/payroll-legacy-50m-cashflows.json
storage/app/audit/payroll-legacy-50m-advances.json
storage/app/audit/payroll-legacy-50m-advance-applications.json
storage/app/audit/payroll-legacy-50m-ledger.json
storage/app/audit/payroll-legacy-50m-reconciliation-summary.json
```

## 11. Kết luận

```text
[x] Đủ dữ liệu để Owner/BA chọn migration strategy cho khoản 50,000,000.
[ ] Chưa đủ dữ liệu, cần kế toán review thêm.
```

Việc tiếp theo:

1. Chốt go-live date/cutoff.
2. Chạy opening balance dry-run theo go-live date.
3. UAT timeline/số dư nhân viên `NV000012` sau migration.
4. Chỉ apply production khi đủ điều kiện rollout.

Không chạy data migration `--apply`, không rebuild cache và không tác động
production live.
