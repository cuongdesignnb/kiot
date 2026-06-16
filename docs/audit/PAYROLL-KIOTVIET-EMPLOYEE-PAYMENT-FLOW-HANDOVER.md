# PAYROLL KIOTVIET EMPLOYEE PAYMENT FLOW HANDOVER

## 1. Tóm tắt phạm vi đã làm

Phase 1 đã bổ sung luồng chi tiền từ chi tiết nhân viên, tab `Nợ & Tạm ứng`, theo logic KiotViet:

```text
Nếu còn phiếu lương locked có remaining > 0
-> mode = salary_payment
-> thanh toán vào đúng payslip remaining hiện hữu.

Nếu không còn phiếu lương cần trả
-> mode = salary_advance
-> tạo tạm ứng lương, ledger âm, cấn vào kỳ lương sau.
```

Không thay đổi core ledger:

```text
Nợ & Tạm ứng = SUM(amount WHERE is_effective = true)
```

Không ghi ledger trực tiếp trong controller. Luồng mới gọi lại:

- `SalaryPaymentService`
- `SalaryAdvanceService`
- `EmployeeSalaryLedgerService`

## 2. File đã sửa

- `app/Http/Controllers/EmployeeSalaryPaymentController.php`
- `routes/api.php`
- `resources/js/Pages/Employees/Index.vue`
- `tests/Feature/Payroll/EmployeeSalaryPaymentFromProfileTest.php`
- `docs/audit/PAYROLL-KIOTVIET-EMPLOYEE-PAYMENT-FLOW-HANDOVER.md`

## 3. API đã thêm

```http
GET /api/employees/{employee}/salary-payment-preview
POST /api/employees/{employee}/salary-payments
```

### Preview

Trả mode theo dữ liệu thật:

- `salary_payment` nếu nhân viên còn payslip thuộc paysheet `locked` và `remaining > 0`.
- `salary_advance` nếu không còn payslip cần trả.

Response gồm:

```text
employee
mode
current_balance
total_remaining
payslips[]
```

### Submit

`mode = salary_payment`:

- validate payslip thuộc đúng nhân viên.
- validate bảng lương đã locked.
- validate amount > 0.
- validate tổng amount theo payslip không vượt remaining.
- gọi `SalaryPaymentService::pay()`.
- tạo `PaysheetPayment`, `CashFlow`, ledger `salary_payment` âm.

`mode = salary_advance`:

- chỉ cho chạy khi `total_remaining = 0`.
- validate amount > 0.
- gọi `SalaryAdvanceService::create()`.
- tạo `SalaryAdvance`, `CashFlow`, ledger `salary_advance` âm.

## 4. Luồng salary_payment từ chi tiết nhân viên

UI tab `Nợ & Tạm ứng` có CTA:

```text
Thanh toán lương
```

Khi nhấn:

1. Gọi preview.
2. Nếu mode `salary_payment`, mở modal `Thanh toán lương`.
3. Modal hiển thị:
   - nhân viên.
   - số dư Nợ & Tạm ứng hiện tại.
   - tổng còn cần trả.
   - thời gian.
   - phương thức.
   - ghi chú.
   - danh sách phiếu lương còn cần trả.
4. Mỗi phiếu có checkbox và tiền trả mặc định bằng `remaining_amount`.
5. Submit bằng nút `Tạo phiếu chi`.

Kết quả:

```text
Thanh toán từ bảng lương và từ nhân viên dùng chung payslip.remaining.
Đã trả đủ ở bảng lương thì không còn payslip trong preview payment.
Không thể trả lại cùng phiếu đã remaining = 0.
```

## 5. Luồng salary_advance khi không còn nợ lương

Nếu preview trả `mode = salary_advance`, UI hiển thị cảnh báo:

```text
Nhân viên không còn phiếu lương cần thanh toán.
Khoản chi này sẽ được ghi nhận là tạm ứng lương và tự cấn trừ vào kỳ lương tiếp theo.
```

Submit bằng nút:

```text
Tạo phiếu chi tạm ứng
```

Kết quả:

- không tạo `salary_payment`.
- tạo `salary_advance`.
- tạo CashFlow chi tạm ứng.
- tạo ledger `salary_advance` amount âm.

## 6. Kết quả test backend

Môi trường:

```text
APP_ENV=testing
DB_DATABASE=kiot_payroll_e2e_local
DB server=sales_mysql_test Docker, MySQL 8, port 3319
```

Lệnh:

```bash
php artisan test tests/Feature/Payroll/PayrollQaApiTest.php \
  tests/Feature/Payroll/PayrollLedgerKiotVietFlowTest.php \
  tests/Feature/Payroll/EmployeeSalaryPaymentFromProfileTest.php
```

Kết quả:

```text
PASS - 24 tests, 253 assertions
```

Riêng Phase 1:

```text
PASS - 8 tests, 83 assertions
```

Các case đã cover:

- preview trả `salary_payment` khi còn remaining.
- payment từ nhân viên dùng đúng payslip remaining.
- đã trả đủ ở bảng lương thì không trả lại bằng salary_payment.
- trả một phần ở bảng lương, trả tiếp ở nhân viên cập nhật cùng payslip.
- không còn remaining thì tạo salary_advance.
- không cho trả vượt remaining.
- double submit cùng idempotency key không tạo trùng.
- không sửa trực tiếp `employees.balance`.

## 7. Kết quả test UI

```bash
npm run build
```

Kết quả:

```text
PASS - vite build, 921 modules transformed
```

UI đã bổ sung:

- CTA `Thanh toán lương` trong tab `Nợ & Tạm ứng`.
- Modal salary payment khi còn nợ.
- Modal salary advance khi không còn nợ.
- Validate client cho amount <= remaining.
- Disable submit khi dữ liệu không hợp lệ/submitting.
- Idempotency key giữ ổn định trong một modal để double-click không tạo trùng.

## 8. Screenshot

Chưa chụp lại screenshot browser trong phiên này. Cần bổ sung bằng browser/UAT:

- tab Nợ & Tạm ứng còn nợ.
- modal thanh toán từ nhân viên.
- sau khi thanh toán hết về 0.
- trường hợp hết nợ chuyển sang tạm ứng.

## 9. Xác nhận không trả trùng

Đã test idempotency:

```text
Gửi POST /api/employees/{employee}/salary-payments hai lần cùng Idempotency-Key
-> chỉ tạo 1 PaysheetPayment
-> chỉ tạo 1 CashFlow
-> chỉ tạo 1 ledger salary_payment
```

Controller mới nhận diện payment idempotent trước bước validate remaining để request lặp không bị 422 sau khi lần đầu đã trả hết.

## 10. Xác nhận không tạo payment/CashFlow/ledger trùng

Kết quả test:

```text
paysheet_payments count = 1
cash_flows count = 1
employee_salary_ledger_entries tăng đúng 1 dòng salary_payment
```

Advance mode cũng dùng `SalaryAdvanceService` với idempotency key từ header.

## 11. Xác nhận không sửa employees.balance

Test set `employees.balance = 123456`, sau đó thanh toán từ chi tiết nhân viên.

Kết quả:

```text
employees.balance vẫn = 123456
salary_balance_cache cập nhật theo SUM ledger effective
```

## 12. Còn thiếu gì nếu muốn giống KiotViet hơn

- Tab `Phiếu lương` trong chi tiết nhân viên chưa hoàn thiện đầy đủ như KiotViet.
- Modal salary payment từ nhân viên chưa cho user chọn rule tách phần vượt remaining thành advance; Phase 1 đang chặn trả vượt.
- Cần chụp lại screenshot/UAT browser cho flow mới.
- Cần tiếp tục rà financial full suite ngoài payroll trước production readiness.

## 13. Full suite regression

Đã chạy full PHPUnit suite trên Docker MySQL test DB:

```bash
php -d memory_limit=1G vendor/bin/phpunit
```

Kết quả:

```text
FAIL - 1183 tests, 6358 assertions, 4 failures, 5 skipped
```

4 failure còn lại thuộc nhóm customer/invoice/default route, không phát sinh từ payroll flow mới:

- `Tests\Feature\Customers\CustomerDebtVoucherDetailTest::test_debt_history_contains_detail_available_and_virtual_payment_flags`
- `Tests\Feature\Customers\HOTFIXFollowUpDebtOffsetMirrorTest::test_customer_net_view_mirrors_cb_to_positive_effect`
- `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
- `Tests\Feature\Invoices\CancelInvoicePaymentDebtFlowTest::test_debt_history_maps_cancel_label_and_excludes_cancelled_legacy_invoices`

Log lưu tại:

```text
storage/logs/payroll-employee-payment-full-suite.log
```

So với trước khi thêm Phase 1, số test tăng thêm 8 case và vẫn còn cùng 4 failure ngoài payroll. Không ghi nhận failure mới trong payroll/CashFlow/P&L do flow thanh toán từ chi tiết nhân viên.

## 14. Kết luận

Phase 1 đạt mục tiêu nghiệp vụ:

```text
Thanh toán từ Bảng lương và từ Nhân viên dùng chung một khoản còn cần trả.
Đã trả ở Bảng lương thì vào Nhân viên không còn khoản đó để trả lại.
Nếu hết nợ mà chi tiền, hệ thống ghi nhận là Tạm ứng lương.
```
