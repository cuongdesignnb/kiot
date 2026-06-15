# PAYROLL KIOTVIET E2E LOCAL TEST REPORT

## 1. Môi trường test

| Hạng mục | Giá trị |
| --- | --- |
| Branch | `feature/employee-debt-advance-expand-ui` |
| Base commit | `e8b71f96ab8583bfff3b2289230617de9d9cae85` |
| Ngày test | `2026-06-15` |
| APP_ENV | `testing` |
| Database | `kiot_payroll_e2e_local` |
| Database server | MySQL 8 Docker `sales_mysql_test`, port `3319` |
| PHP | `8.2.29` |
| Node.js | `20.15.1` |

Database này được tạo riêng cho automated test. Không dùng database production, không
convert `employees.balance`, không sửa trực tiếp `salary_balance_cache` và không insert
ledger để giả lập kết quả nghiệp vụ.

Nguồn sự thật được kiểm tra:

```text
Balance = SUM(amount WHERE is_effective = true)
```

`status` chỉ biểu diễn vòng đời của dòng ledger.

## 2. Dữ liệu test

Mỗi test dùng `RefreshDatabase` và tạo lại dữ liệu độc lập:

| Mã nhân viên | Mục đích |
| --- | --- |
| `NV-E2E-001` | Chốt lương, trả một phần, trả toàn bộ |
| `NV-E2E-002` | Tạm ứng trước lương |
| `NV-E2E-DOUBLE` | Chống trừ tạm ứng hai lần |
| `NV-E2E-CANCEL` | Hủy payment và hủy bảng lương |

Điều kiện ban đầu của từng test:

```text
salary_balance_cache = 0
không có ledger
không có CashFlow
không có payment
không có advance/application
```

Các bảng lương và phiếu lương test được tạo ở trạng thái `calculated`. Mọi phát sinh
ledger sau đó đều đi qua API/service nghiệp vụ thật:

```text
PUT  /api/paysheets/{id}/lock
POST /api/paysheets/{id}/pay
POST /api/employees/{employee}/salary-advances
POST /api/paysheet-payments/{payment}/cancel
PUT  /api/paysheets/{id}/cancel
```

## 3. Kết quả test case

| Test case | Kết quả | Đối chiếu chính |
| --- | --- | --- |
| TC01 - Chốt lương 1.000.000 | PASS | `payroll_accrual = +1.000.000`, balance/cache `1.000.000` |
| TC02 - Trả một phần 400.000 | PASS | `salary_payment = -400.000`, balance/cache `600.000`, 1 CashFlow |
| TC03 - Trả tiếp 600.000 | PASS | Timeline `+1.000.000, -400.000, -600.000`, balance/cache `0` |
| TC04 - Tạm ứng 500.000 | PASS | `salary_advance = -500.000`, balance/cache `-500.000`, 1 CashFlow |
| TC05 - Chốt lương 2.000.000 sau tạm ứng | PASS | Timeline `-500.000, +2.000.000`, balance/cache `1.500.000` |
| TC06 - Chống double-count | PASS | Advance `-300.000`, accrual `+1.000.000`, balance `700.000`; không có `advance_offset` |
| TC07 - Hủy payment 400.000 | PASS | Dòng gốc giữ lại và chuyển `reversed`; dòng đảo `+400.000`; balance về `1.000.000` |
| TC08 - Hủy bảng lương đã chốt | PASS | Accrual gốc giữ lại; dòng đảo `-1.000.000`; balance/cache về `0` |

Tất cả dòng gốc bị đảo và dòng `cancel_reverse` vẫn có `is_effective = true`, vì vậy
hai dòng tự triệt tiêu theo tổng ledger thay vì bị loại bằng `status`.

## 4. Kết quả UI

| UI case | Kết quả | Bằng chứng |
| --- | --- | --- |
| UI01 - Cột Nợ và tạm ứng | PASS | Dùng formatter tiền Việt Nam, hiển thị hậu tố `đ` |
| UI02 - Expand row | PASS | Mở inline, lazy-load API, không reload trang, có summary/timeline/phân trang |
| UI03 - Tab trong modal nhân viên | PASS | Dùng cùng panel timeline, không có input sửa trực tiếp số dư |
| UI04 - Nhân viên không phát sinh | PASS | Panel có empty state và summary mặc định bằng 0 |

Screenshot browser đã kiểm tra:

```text
docs/audit/screenshots/employee-ledger-expand.png
docs/audit/screenshots/employee-ledger-modal-tab.png
```

Việc mở expand/modal đã được đối chiếu read-only: không tăng số ledger, CashFlow,
payment, advance hoặc application.

## 5. API test

`GET /api/employees/{employee}/salary-ledger` trả:

```text
employee
summary.opening_balance
summary.total_increase
summary.total_decrease
summary.net_change
summary.current_balance
summary.entry_count
entries[]
data (paginator tương thích UI hiện tại)
```

Mỗi phần tử `entries[]` có:

```text
code
type
type_label
amount
increase_amount
decrease_amount
balance_after
is_effective
status
status_label
event_at
note
created_at
```

`entries[]` và `data.data` chứa cùng tập dòng đã trình bày. Việc thêm alias không phá
contract paginator mà UI hiện tại đang sử dụng.

## 6. Automated test

Lệnh bắt buộc:

```text
php artisan test tests/Feature/Payroll/PayrollQaApiTest.php
php artisan test tests/Feature/Payroll/PayrollLedgerKiotVietFlowTest.php
```

Kết quả gộp:

```text
PASS - 16 tests, 170 assertions
```

Regression ledger bổ sung:

```text
php artisan test tests/Feature/Payroll/SalaryLedgerFlowTest.php
PASS - 9 tests, 41 assertions
```

Frontend:

```text
npm run build
PASS - 921 modules transformed
```

## 7. Full suite

Lệnh:

```text
php -d memory_limit=1G vendor/bin/phpunit
```

Kết quả:

```text
1175 tests
6271 assertions
4 failures
5 skipped
```

Các failure còn lại:

1. `CustomerDebtVoucherDetailTest::test_debt_history_contains_detail_available_and_virtual_payment_flags`
2. `HOTFIXFollowUpDebtOffsetMirrorTest::test_customer_net_view_mirrors_cb_to_positive_effect`
3. `ExampleTest::test_the_application_returns_a_successful_response` (`302` thay vì `200`)
4. `CancelInvoicePaymentDebtFlowTest::test_debt_history_maps_cancel_label_and_excludes_cancelled_legacy_invoices`

Các failure nằm ở customer/invoice debt và route mặc định. Thay đổi trong workstream
này chỉ chạm presentation của employee salary ledger và test payroll mới; không sửa
customer debt, invoice debt hoặc route `/`. Không có failure trong payroll test.

Full suite vì vậy chưa thể báo PASS toàn bộ repository.

## 8. Đối chiếu KiotViet

| Quy tắc | Kết quả |
| --- | --- |
| Chốt bảng lương cộng số dư | PASS |
| Trả lương trừ số dư | PASS |
| Trả hết đưa số dư về 0 | PASS |
| Tạm ứng làm số dư âm | PASS |
| Chốt lương sau tạm ứng ra số còn lại đúng | PASS |
| Không double-count tạm ứng | PASS |
| Hủy payment tạo dòng đảo và giữ lịch sử | PASS |
| Hủy bảng lương tạo dòng đảo và giữ lịch sử | PASS |
| Timeline/API trả đủ dòng effective | PASS |
| Cache khớp tổng ledger effective | PASS |

## 9. Lỗi phát hiện và thay đổi

API timeline trước thay đổi có paginator `data` nhưng chưa có alias `entries[]` và các
field trình bày bắt buộc cho client độc lập.

Đã bổ sung tại `EmployeeSalaryLedgerService`:

```text
entries[]
type_label
increase_amount
decrease_amount
status_label
```

Không thay đổi amount, event_at, balance algorithm, ledger posting hoặc cancellation
flow. Không phát hiện lỗi core payroll trong TC01-TC08.

## 10. Kết luận

Logic Nợ và tạm ứng đã khớp mô hình KiotViet trong các luồng được kiểm tra:

```text
chốt bảng lương
trả lương một phần/toàn phần
tạm ứng trước lương
chống double-count
hủy và tạo dòng đảo
timeline/API đối soát
UI expand và tab chi tiết
```

Module đủ điều kiện chuyển sang UAT với tập dữ liệu nhỏ, tách biệt production.

Chưa tuyên bố toàn repository production-ready vì full suite vẫn còn 4 failure ngoài
payroll cần được owner/tech lead xử lý hoặc phê duyệt loại trừ.
