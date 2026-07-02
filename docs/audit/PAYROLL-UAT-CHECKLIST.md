# PAYROLL UAT CHECKLIST

## 0. Bằng chứng môi trường UAT ngày 2026-06-14

| Hạng mục | Giá trị |
|---|---|
| APP_ENV | `staging` |
| DB_HOST | `127.0.0.1:3320` |
| DB_DATABASE | `kiot_payroll_uat_20260614_165946` |
| Database source | Clone từ `kiot_prod_copy_payroll_20260614_003011` |
| Production live | Không |
| Git commit/version | `891d707` (working tree có thay đổi QA payroll chưa commit) |
| UAT apply | Đã chạy trên UAT DB, không phải production |
| Apply output | `storage/app/audit/payroll-uat-opening-balance-apply.txt` |
| Verify output | `storage/app/audit/payroll-uat-opening-balance-verify.json` |
| App UAT Docker | `kiot-payroll-uat-app`, `http://localhost:8082` |
| Browser account | Admin có sẵn trong production copy; không tạo/cấp quyền mới |

Dry-run opening balance đã chạy trên DB UAT riêng, dùng ngày mô phỏng `2026-06-14`:

```text
Expected opening entries: 1
Employee: NV000012
Amount: 50,000,000
Idempotency key: opening_balance:7:2026-06-14
Ledger before/after: 0/0
Payment before/after: 0/0
CashFlow before/after: 543/543
--apply: Không dùng
```

UAT apply ngày mô phỏng `2026-06-14` đã tạo đúng một opening balance. Lần chạy
đầu bị chặn trước khi ghi dữ liệu do collation MySQL 8 không tương thích
MariaDB. Sau khi override kết nối UAT sang `utf8mb4_unicode_ci`, apply thành
công. Một mismatch note được phát hiện, sửa bằng code có test, sau đó UAT DB
được restore từ baseline sạch và apply lại để tạo bằng chứng cuối.

## 1. Thông tin UAT

| Hạng mục | Giá trị |
|---|---|
| Môi trường | Local Docker UAT cô lập (`APP_ENV=staging`) |
| Ngày test | 2026-06-14: clone, dry-run và technical apply; business UI UAT chưa sign-off |
| Người test BA | Chờ chỉ định |
| Người test kế toán | Chờ chỉ định |
| Người test HR | Chờ chỉ định |
| Version/commit | `891d707` + working tree QA payroll |
| Database source | `kiot_payroll_uat_20260614_165946`, clone từ production copy |

## 2. UAT bảng lương

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Tạo bảng lương kỳ mới | Tạo được paysheet ở trạng thái đúng | | Chưa test | |
| Tính lương | Payslip sinh đúng theo dữ liệu chấm công/cấu hình | | Chưa test | |
| Chốt bảng lương | Paysheet chuyển `locked`, sinh `payroll_accrual` | | Chưa test | |
| Không sửa bảng lương locked nếu không có quyền | User bị chặn | | Chưa test | |
| Hủy bảng lương khi chưa có payment | Tạo reversal accrual, không xóa lịch sử | | Chưa test | |
| Không hủy bảng lương khi còn payment active | Hệ thống chặn và yêu cầu hủy payment trước | | Chưa test | |

## 3. UAT tạm ứng

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Tạo tạm ứng nhân viên active | Sinh `salary_advance`, CashFlow, ledger âm | | Chưa test | |
| Không tạo tạm ứng nhân viên inactive | Bị chặn | | Chưa test | |
| Tạm ứng hiển thị timeline | Timeline có dòng `salary_advance` | | Chưa test | |
| Tạm ứng được cấn FIFO | Advance application đúng thứ tự | | Chưa test | |
| Hủy tạm ứng chưa phân bổ | CashFlow cancelled, ledger có `cancel_reverse` | | Chưa test | |
| Không hủy tạm ứng đã phân bổ | Nút hủy bị khóa hoặc API bị chặn | | Chưa test | |

## 4. UAT thanh toán lương

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Thanh toán một phần | `paid_amount` tăng, `remaining` giảm đúng | | Chưa test | |
| Thanh toán nhiều lần | Nhiều payment hợp lệ, không vượt `remaining` | | Chưa test | |
| Không thanh toán vượt remaining | Backend chặn | | Chưa test | |
| Hủy payment | Payment/CashFlow cancelled, ledger có `cancel_reverse` | | Chưa test | |
| CashFlow payment cancelled không còn active | Báo cáo dòng tiền loại dòng cancelled | | Chưa test | |
| Timeline có salary_payment và cancel_reverse | Hiển thị đủ dòng gốc và dòng đảo | | Chưa test | |

## 5. UAT số dư/timeline

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Số dư dương | Hiển thị công ty còn phải trả nhân viên | | Chưa test | |
| Số dư âm | Hiển thị nhân viên tạm ứng vượt/công ty phải thu | | Chưa test | |
| Số dư bằng 0 | Hiển thị đã tất toán | | Chưa test | |
| Timeline running balance | `balance_after` đúng sau từng dòng | | Chưa test | |
| Export CSV | Mở Excel không lỗi tiếng Việt, số tiền đúng | | Chưa test | |

## 6. UAT phân quyền

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| User không có quyền không xem được ledger | UI ẩn, API 403 | | Chưa test | |
| User không có quyền không export được | UI ẩn, API 403 | | Chưa test | |
| User branch A không xem được branch B | API bị chặn | | Chưa test | |
| User không có quyền adjust không gọi được API | API 403 | | Chưa test | |

## 7. UAT báo cáo

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Dòng tiền có payment/advance active | CashFlow active được tính | | Chưa test | |
| P&L không double-count payroll payment/advance | Chi phí lương lấy từ payroll locked/accrual | | Chưa test | |
| Reconciliation report đọc được | Báo cáo hiển thị đúng anomaly | | Chưa test | |
| Anomaly report được kế toán xác nhận | Có ký duyệt hoặc ghi chú xử lý | | Chưa test | |

## 8. UAT nhân viên nghỉ việc

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| Inactive employee còn số dư | Hiển thị cảnh báo theo rule được Owner duyệt | | Chưa test | |
| Inactive employee tạo advance mới | Hệ thống chặn | | Chưa test | |
| Inactive employee còn nợ lương | Vẫn cho thanh toán nợ cũ nếu user có quyền | | Chưa test | |

## 9. UAT opening balance từ KiotViet

| Case | Kết quả mong đợi | Người test | Kết quả | Ghi chú |
|---|---|---|---|---|
| UAT-OB-01 | Timeline `NV000012` có `opening_balance` 50,000,000 | Agent | Pass | Docker UI/API hiển thị đúng code, amount, balance, note và trạng thái |
| UAT-OB-02 | `salary_balance_cache` sau rebuild = 50,000,000 nếu không có phát sinh khác | Agent | Pass | `append()` tự rebuild cache trong transaction; cache khớp ledger |
| UAT-OB-03 | Ledger balance = SUM effective ledger = 50,000,000 | Agent | Pass | 1 dòng effective, balance và `balance_after` đều 50,000,000 |
| UAT-OB-04 | Không tạo payment/CashFlow giả từ opening balance | Agent | Pass | Payment 0; CashFlow giữ nguyên 543; opening/payroll CashFlow 0 |
| UAT-OB-05 | Báo cáo nợ lương hiển thị số dư mở đầu đúng | Agent | Pass | Docker UI/API reconciliation: cache=ledger=50M, difference=0 |

## 10. Kết luận UAT

| Hạng mục | Kết quả |
|---|---|
| Tổng số case opening balance | 5 |
| Passed | 5 |
| Partially Passed | 0 |
| Failed | 0 |
| Bug còn mở | Không phát hiện bug Critical/High trong scope opening balance |
| Kết luận | Opening balance technical và business UI/API UAT Pass; chưa sign-off con người |

### Bằng chứng sau apply

```text
opening_balance_count_after = 1
opening_balance_amount_after = 50,000,000
effective_ledger_balance_after = 50,000,000
salary_balance_cache = 50,000,000
duplicate_opening_balance_count = 0
paysheet_payment_count_after = 0
cash_flow_count_before/after = 543/543
note = Số dư lương chuyển đổi từ hệ thống KiotViet
```

Reconciliation backend:

```text
salary_balance_cache = 50,000,000
ledger_balance = 50,000,000
difference = 0
payment/advance document issues = 0
LEGACY_BALANCE_EXISTS vẫn được báo vì employees.balance chỉ dùng audit/migration và không bị sửa.
```

### Bằng chứng Docker UI/API

```text
Employees list: NV000012 hiển thị Nợ và tạm ứng = 50,000,000.
Timeline UI: 1 dòng SDDK-NV000012-20260614.
Type: opening_balance.
Amount/balance_after: 50,000,000.
Note: Số dư lương chuyển đổi từ hệ thống KiotViet.
Detail modal: CashFlow = -, Payment/Advance = -.
Reconciliation UI/API: cache=50,000,000, ledger=50,000,000, difference=0.
Ledger export: HTTP 200, UTF-8 BOM, đúng code/type/amount/note.
Reconciliation export: HTTP 200, UTF-8 BOM, đúng employee/cache/difference.
Browser error overlay: Không.
```

Sign-off pack: `docs/audit/PAYROLL-UAT-SIGNOFF-PACK.md`.

Điều kiện sign-off:

```text
[ ] Không còn bug Critical/High.
[ ] Bug Medium có phương án và Owner chấp nhận.
[ ] Kế toán xác nhận CashFlow/P&L.
[ ] HR xác nhận payroll lifecycle.
[ ] BA xác nhận permission/branch scope.
```

## 11. Sign-off

| Vai trò | Họ tên | Ký duyệt | Ngày |
|---|---|---|---|
| BA | | | |
| Kế toán | | | |
| HR/Quản lý nhân sự | | | |
| Owner | | | |
