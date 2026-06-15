# PAYROLL LEDGER PHASE 2 UI HANDOVER

## Phạm vi hoàn thiện

- Bổ sung expand row tại danh sách nhân viên, mở bằng chevron hoặc số dư `Nợ và tạm ứng`.
- Expand row tải lazy, cache kết quả theo nhân viên và không reload toàn trang.
- Giao diện bám mẫu KiotViet: thanh tab ngang, bảng phẳng, mã phiếu màu xanh, tiền và số dư căn phải, footer phân trang.
- Giữ tab `Nợ & Tạm ứng` trong modal nhân viên và chuẩn hóa nhãn loại phát sinh, cột tăng/giảm.
- Không thêm input sửa trực tiếp số dư.

## File thay đổi

- `app/Http/Controllers/EmployeeSalaryLedgerController.php`
- `app/Services/EmployeeSalaryLedgerService.php`
- `resources/js/Components/EmployeeSalaryLedgerPanel.vue`
- `resources/js/Pages/Employees/Index.vue`
- `tests/Feature/Payroll/PayrollQaApiTest.php`

## API

Tái sử dụng endpoint read-only:

```http
GET /api/employees/{employee}/salary-ledger
GET /api/employees/{employee}/salary-ledger/export
```

Response timeline được bổ sung:

- `employee`: `id`, `code`, `name`, `phone`.
- `summary.entry_count`.

Tất cả dòng và summary tiếp tục chỉ lấy:

```text
is_effective = true
```

`status` không được dùng để quyết định dòng có tham gia số dư.

## Kiểm tra NV000012

Database kiểm tra là `kiot_phase2_ui_demo`, tách biệt production.

```text
employee: NV000012 - Vũ Thị Thu Thủy
ledger code: SDDK-NV000012-20260615
type: opening_balance
amount: 50000000
balance_after: 50000000
is_effective: true
status: valid
```

Sau khi đăng nhập, mở/đóng expand và mở tab chi tiết:

```text
ledger_rows = 1
opening_balance_rows = 1
effective_balance = 50000000
salary_balance_cache = 50000000
cash_flows = 0
paysheet_payments = 0
salary_advances = 0
salary_advance_applications = 0
```

Kết luận: thao tác xem UI không tạo thêm ledger, CashFlow, payment, advance hoặc application.

## Screenshot

- `docs/audit/screenshots/employee-ledger-expand.png`
- `docs/audit/screenshots/employee-ledger-modal-tab.png`

## Kết quả test

```text
php artisan test tests/Feature/Payroll/PayrollQaApiTest.php
PASS - 7 tests, 48 assertions

npm run build
PASS

Docker image
kiotviet-clone:employee-ledger-expand-ui
BUILD PASS

Browser verification
PASS - page có nội dung, không có error overlay, không có browser error
```

Full suite:

```text
php -d memory_limit=1G vendor/bin/phpunit
1166 tests, 6149 assertions, 4 failures, 5 skipped
```

Các failure xuất hiện ở regression ngoài phạm vi UI payroll:

- `CustomerDebtVoucherDetailTest::test_debt_history_contains_detail_available_and_virtual_payment_flags`
- `ExampleTest::test_the_application_returns_a_successful_response` (`302` thay vì `200`)

Khi chạy riêng hai file trên có 2 failure tương ứng. Không có failure mới trong `PayrollQaApiTest`.

## Giới hạn

- Không chạy migration production.
- Không sửa dữ liệu production.
- Không thay đổi công thức `salary_balance_cache`.
- Không tạo payment/CashFlow khi mở lịch sử.
