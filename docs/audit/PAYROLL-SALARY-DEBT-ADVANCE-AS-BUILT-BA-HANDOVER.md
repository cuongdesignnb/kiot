# TÀI LIỆU AS-BUILT CHO BA - NỢ LƯƠNG VÀ TẠM ỨNG NHÂN VIÊN

> Ngày tổng hợp: 13/06/2026
> Mục đích: Mô tả đúng phần hệ thống đã được triển khai để BA phân tích, nghiệm thu và phát hành yêu cầu bổ sung.
> Trạng thái: Đã triển khai trên source dev, chưa tự động chuyển đổi `employees.balance` trên production.

## 1. Tóm tắt nghiệp vụ đã triển khai

Hệ thống đã bổ sung sổ phát sinh độc lập cho nghiệp vụ nợ lương và tạm ứng nhân viên.

Nguồn dữ liệu đúng là:

```text
employee_salary_ledger_entries
```

Nguyên tắc quan trọng nhất:

```text
Mọi phép tính số dư chỉ lấy dòng is_effective = true.
Không dùng status = valid làm điều kiện duy nhất để tính số dư.
```

Quy ước số dư:

```text
Số dư > 0: Công ty còn phải trả nhân viên.
Số dư = 0: Không còn nghĩa vụ phải trả hoặc khoản tạm ứng.
Số dư < 0: Nhân viên đã tạm ứng vượt hoặc công ty còn khoản phải thu.
```

Công thức:

```text
Số dư sau phát sinh = Số dư trước phát sinh + amount

amount > 0: Tăng nghĩa vụ công ty phải trả.
amount < 0: Giảm nghĩa vụ phải trả hoặc phát sinh tạm ứng/thanh toán.
```

## 2. Luồng nghiệp vụ hiện có sau triển khai

### 2.1. Chốt bảng lương

Điều kiện:

```text
Chỉ paysheet.status = calculated được chốt.
```

Khi chốt:

1. Khóa dòng bảng lương và nhân viên liên quan.
2. Tạo một ledger `payroll_accrual` cho từng phiếu lương.
3. Giá trị ledger bằng đúng `payslip.total_salary`.
4. Tìm tạm ứng còn dư của nhân viên theo FIFO.
5. Tạo `salary_advance_applications` để ghi nhận phân bổ tạm ứng.
6. Không tạo thêm ledger âm khi phân bổ tạm ứng.
7. Cập nhật `applied_advance`, `remaining`, trạng thái thanh toán phiếu lương.
8. Chuyển bảng lương sang `locked`.
9. Ghi ActivityLog.

Ví dụ:

```text
Tạm ứng trước kỳ:       -1,000,000
Phiếu lương được chốt:  +5,000,000
Số dư còn phải trả:      4,000,000
```

Không có ledger `advance_offset` trong mô hình hiện tại.

### 2.2. Thanh toán lương

Điều kiện:

```text
Chỉ bảng lương locked được thanh toán.
amount > 0.
amount không vượt remaining do backend tính lại.
Phiếu lương phải thuộc bảng lương đang thanh toán.
```

Mỗi dòng thanh toán tạo:

```text
1 paysheet_payment
1 CashFlow chi tiền
1 ledger salary_payment có amount âm
```

Hệ thống hỗ trợ:

- Thanh toán một phần.
- Thanh toán nhiều lần.
- Một request thanh toán nhiều nhân viên.
- Idempotency để chống bấm/gửi lại request.
- Row lock để chống hai request cùng trả vượt số còn lại.

Frontend chỉ gửi số tiền muốn trả. Backend tự tính lại `paid_amount`, `remaining`, `payment_status` và tổng bảng lương.

### 2.3. Tạo tạm ứng

Điều kiện:

```text
amount > 0.
Có ngày tạm ứng.
Có phương thức chi.
Có chi nhánh hợp lệ theo quyền.
Có ghi chú tối thiểu.
Nhân viên phải đang hoạt động.
```

Khi tạo thành công:

```text
1 salary_advance
1 CashFlow chi tiền
1 ledger salary_advance có amount âm
1 ActivityLog
Cập nhật salary_balance_cache từ ledger
```

### 2.4. Hủy thanh toán

Khi hủy:

1. Không xóa payment gốc.
2. Payment gốc chuyển `status = cancelled`.
3. CashFlow gốc chuyển `status = cancelled`, ghi người/lúc/lý do hủy và soft delete.
4. Ledger `salary_payment` gốc chuyển trạng thái hiển thị thành `reversed`.
5. Tạo ledger `cancel_reverse` có số tiền đối ứng dương.
6. Cả dòng gốc và dòng đảo vẫn có `is_effective = true`.
7. Tính lại phiếu lương, bảng lương và cache nhân viên.

### 2.5. Hủy bảng lương

Rule đã triển khai:

```text
Nếu còn payment status = active thì không được hủy bảng lương.
Phải hủy từng payment trước.
```

Sau khi không còn payment hợp lệ:

1. Tạo reversal cho từng `payroll_accrual`.
2. Hủy các bản ghi phân bổ tạm ứng.
3. Trả số tiền đã phân bổ về `remaining_amount` của tạm ứng.
4. Đưa `payslip.applied_advance` về 0.
5. Chuyển bảng lương sang `cancelled`.

### 2.6. Hủy tạm ứng

Hệ thống đã có API hủy tạm ứng.

Rule hiện tại:

```text
Chỉ hủy được tạm ứng chưa được phân bổ vào phiếu lương.
```

Khi hủy:

- Chuyển chứng từ tạm ứng sang cancelled.
- Hủy CashFlow theo status.
- Tạo ledger `cancel_reverse`.
- Không xóa lịch sử.

### 2.7. Điều chỉnh thủ công

Đã có API cho:

```text
adjustment_increase
adjustment_decrease
```

Điều kiện:

- Có quyền `payroll.adjust`.
- Số tiền lớn hơn 0.
- Lý do tối thiểu 10 ký tự.
- Tạo ledger, không sửa cache trực tiếp.
- Ghi ActivityLog.

Hiện chưa có form điều chỉnh trên UI nhân viên.

## 3. Ledger effective và reversal

Các type đã triển khai:

| Type | Ý nghĩa | Dấu tiền |
|---|---|---:|
| `opening_balance` | Số dư chuyển đổi đầu kỳ | Dương/âm/0 |
| `payroll_accrual` | Lương phát sinh khi chốt | Dương |
| `salary_payment` | Thanh toán lương | Âm |
| `salary_advance` | Tạm ứng lương | Âm |
| `adjustment_increase` | Điều chỉnh tăng phải trả | Dương |
| `adjustment_decrease` | Điều chỉnh giảm phải trả | Âm |
| `cancel_reverse` | Bút toán đảo chứng từ | Đối dấu dòng gốc |

Quy tắc:

```text
is_effective = true: Được tính vào số dư.
is_effective = false: Không được tính vào số dư.

status = valid/reversed/cancelled chỉ phục vụ vòng đời và hiển thị.
```

Ví dụ hủy phiếu lương:

```text
Phiếu lương gốc: +5,000,000 | status=reversed | is_effective=true
Dòng đảo:        -5,000,000 | type=cancel_reverse | is_effective=true
Tổng số dư:               0
```

## 4. Append-only và balance_after

Ledger không sửa/xóa bản chất phát sinh sau khi ghi sổ.

Không được sửa qua nghiệp vụ thông thường:

```text
employee_id
amount
type
reference_type
reference_id
paysheet_id
payslip_id
event_at
```

`balance_after` được phép tính lại khi:

- Có chứng từ nhập lùi ngày.
- Rebuild số dư.
- Migration hoặc đối soát.

Rebuild:

```text
Chỉ lấy is_effective = true.
Sắp xếp event_at ASC, id ASC.
Chạy trong transaction.
Lock nhân viên.
Không thay đổi amount.
Cập nhật employees.salary_balance_cache.
```

Không có API sửa `event_at`. Nếu sai ngày nghiệp vụ, hướng xử lý là hủy/đảo chứng từ cũ và tạo chứng từ mới.

## 5. Timeline và summary

API:

```text
GET /api/employees/{employee}/salary-ledger
```

Bộ lọc backend hỗ trợ:

```text
from_date
to_date
type
status
branch_id
keyword
per_page
page
```

Summary:

```text
opening_balance = SUM(amount trước from_date, is_effective=true)
total_increase = SUM(amount > 0 trong kỳ, is_effective=true)
total_decrease = ABS(SUM(amount < 0 trong kỳ, is_effective=true))
net_change = SUM(amount trong kỳ, is_effective=true)
current_balance = opening_balance + net_change
```

Các bộ lọc hiển thị `type`, `status`, `keyword` không làm thay đổi summary tài chính của khoảng ngày.

Timeline sắp xếp:

```text
event_at ASC, id ASC
```

Mapping trạng thái UI:

| Điều kiện | Label |
|---|---|
| Dòng thường, chưa đảo | Hợp lệ |
| Dòng gốc đã có reversal | Đã đảo |
| `type = cancel_reverse` | Dòng đảo |

## 6. Trạng thái bảng lương và phiếu lương

### Bảng lương

```text
paysheet.status:
- draft
- calculating
- calculated
- locked
- cancelled
```

```text
paysheet.payment_status:
- unpaid
- partial
- paid
```

### Phiếu lương

```text
payslip.payment_status:
- unpaid
- partial
- paid
```

Công thức đang triển khai:

```text
settled = paid_amount + applied_advance
remaining = MAX(total_salary - settled, 0)
```

Lưu ý BA cần xác nhận cách gọi tên trên UI:

- `paid_amount` hiện chỉ là tiền đã chi qua payment.
- `applied_advance` là tạm ứng được phân bổ.
- `remaining` là số tiền còn cần thanh toán cho phiếu lương.

## 7. CashFlow và báo cáo

Phase hiện tại chọn:

```text
1 payment = 1 nhân viên = 1 CashFlow
1 advance = 1 CashFlow
```

Khi hủy payment/advance:

```text
CashFlow gốc chuyển status = cancelled.
Không tạo CashFlow đảo.
Ledger vẫn tạo cancel_reverse.
```

`CashFlow::active()` loại:

- CashFlow `status = cancelled`.
- CashFlow đã soft delete.

Chốt bảng lương không tạo CashFlow vì chưa thực chi.

Báo cáo chi phí lương đã được giới hạn lấy bảng lương `locked`, không lấy bảng lương mới `calculated`.

BA cần kiểm tra lại toàn bộ báo cáo tùy biến khác để đảm bảo không có báo cáo nào tự query CashFlow mà bỏ qua status.

## 8. Backdate và kỳ khóa sổ

Ưu tiên dùng `Setting.lock_date` hiện có.

Nếu ngày nghiệp vụ thuộc kỳ khóa:

```text
User thường bị chặn.
User có payroll.override_locked_period được override.
Override bắt buộc nhập lý do tối thiểu 10 ký tự.
```

Nếu chưa thuộc kỳ khóa nhưng lùi quá giới hạn:

```text
Setting payroll_backdate_limit_days, mặc định 30 ngày.
Quá giới hạn cần payroll.override_backdate_limit.
Override bắt buộc có lý do.
```

Đang áp dụng cho:

- Thanh toán lương.
- Hủy thanh toán.
- Tạo/hủy tạm ứng.
- Điều chỉnh số dư.
- Hủy bảng lương.

## 9. employees.balance legacy và cache mới

Kết quả kiểm tra source:

```text
employees.balance trước đây chủ yếu được dùng để hiển thị.
Không có chứng từ nguồn đáng tin để giải thích toàn bộ số dư.
```

Đã triển khai:

```text
employees.salary_balance_cache
employees.salary_balance_calculated_at
```

Rule:

- Source of truth là ledger.
- Cache chỉ dùng hiển thị nhanh.
- Không có nghiệp vụ sửa cache trực tiếp.
- Rebuild cache từ tổng ledger `is_effective = true`.
- Code mới không ghi vào `employees.balance`.

Hai phương án production:

### Option A - Chuyển legacy balance thành opening balance

```text
employees.balance != 0
=> tạo ledger opening_balance tại ngày go-live
```

### Option B - Không chuyển, chỉ xuất anomaly report

```text
Ledger bắt đầu từ 0.
employees.balance chỉ xuất danh sách để BA/Owner kiểm tra.
```

Trạng thái hiện tại:

```text
Mặc định command chỉ report.
Chưa tự động chọn Option A.
```

## 10. Migration dữ liệu cũ

Command:

```bash
php artisan payroll:migrate-salary-ledger
```

Mặc định là dry-run.

Các chế độ:

```bash
# Chỉ báo cáo employees.balance legacy
php artisan payroll:migrate-salary-ledger --legacy-balance=report

# Chuyển employees.balance thành opening balance
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=2026-06-01 \
  --apply

# Backfill chứng từ bảng lương/payment cũ
php artisan payroll:migrate-salary-ledger \
  --backfill-documents \
  --legacy-balance=report \
  --apply
```

Command chặn chạy đồng thời:

```text
--backfill-documents
và
--legacy-balance=opening
```

Lý do: hai nguồn có thể cùng đại diện cho số dư cũ và gây double-count.

Mọi dòng migration có idempotency key, chạy lại không tạo trùng.

## 11. API đã triển khai

| Method | Endpoint | Quyền |
|---|---|---|
| GET | `/api/employees/{employee}/salary-ledger` | `payroll.ledger.view` |
| POST | `/api/employees/{employee}/salary-ledger/adjust` | `payroll.adjust` |
| POST | `/api/employees/{employee}/salary-advances` | `payroll.advance.create` |
| POST | `/api/salary-advances/{advance}/cancel` | `payroll.advance.cancel` |
| POST | `/api/employees/{employee}/rebuild-salary-balance` | `payroll.rebuild_balance` |
| POST | `/api/payroll/rebuild-salary-balances` | `payroll.rebuild_balance` |
| PUT | `/api/paysheets/{id}/lock` | `payroll.lock` |
| PUT | `/api/paysheets/{id}/cancel` | `payroll.cancel` |
| POST | `/api/paysheets/{id}/pay` | `payroll.pay` |
| POST | `/api/paysheet-payments/{payment}/cancel` | `payroll.pay.cancel` |

Backend không tin các field tổng do frontend gửi.

## 12. UI đã triển khai

### Danh sách nhân viên

- Cột “Nợ và tạm ứng” dùng `salary_balance_cache`.
- Chỉ hiển thị với quyền `employee.view_salary_balance`.
- Không còn dùng `employees.balance` để hiển thị số dư mới.

### Chi tiết nhân viên

Đã có tab “Nợ và tạm ứng” với:

- Summary đầu kỳ, tăng, giảm, biến động ròng, hiện tại.
- Bộ lọc từ ngày, đến ngày, loại phát sinh.
- Timeline số tiền và `balance_after`.
- Trạng thái Hợp lệ/Đã đảo/Dòng đảo.
- Form tạo tạm ứng nếu có quyền.

Chưa hoàn chỉnh:

- Chưa có link mở chứng từ từ mã phiếu.
- Chưa có điều khiển phân trang trên UI dù API đã paginate.
- Chưa có filter trạng thái, chi nhánh, keyword trên UI.
- Chưa có nút hủy tạm ứng trên UI.
- Chưa có form điều chỉnh tăng/giảm trên UI.
- Chưa có export timeline.

### Bảng lương

Đã triển khai:

- Chỉ hiện nút thanh toán khi bảng lương `locked`.
- Modal nhập số tiền trả riêng từng phiếu lương.
- Hỗ trợ thanh toán một phần.
- Lịch sử payment hiển thị trạng thái.
- Có thao tác hủy payment.
- Hủy bảng lương yêu cầu nhập lý do.

Chưa hoàn chỉnh:

- UI chưa hiển thị thành cột riêng phần `applied_advance`.
- Chưa có màn hình chi tiết phân bổ tạm ứng theo từng phiếu lương.
- Hộp nhập lý do hủy hiện dùng prompt, cần BA/UX xác nhận có cần modal chuẩn.

## 13. Phân quyền đã bổ sung

```text
employee.view_salary_balance
payroll.view
payroll.create
payroll.edit
payroll.lock
payroll.cancel
payroll.pay
payroll.pay.cancel
payroll.adjust
payroll.advance.create
payroll.advance.cancel
payroll.ledger.view
payroll.ledger.export
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
```

Có alias tương thích cho một số quyền `paysheets.*` cũ. Các quyền nhạy cảm mới như ledger, advance và adjust phải được cấp rõ ràng.

BA/Owner cần cung cấp ma trận role nào được cấp từng quyền trước production.

## 14. Nhân viên nghỉ việc và xóa nhân viên

Đã triển khai:

```text
Không cho tạo tạm ứng mới cho nhân viên inactive.
Vẫn cho thanh toán khoản lương còn nợ của nhân viên inactive.
Không xóa cứng nhân viên đã có ledger, payslip, payment hoặc advance.
```

Khi chuyển nhân viên sang inactive mà cache khác 0:

- Vẫn cho chuyển trạng thái.
- Ghi ActivityLog.
- Trả thông báo còn số dư cần xử lý.
- Không xóa timeline.

BA cần xác nhận có muốn chuyển cảnh báo này thành bước xác nhận bắt buộc hay chỉ cảnh báo như hiện tại.

## 15. Transaction, lock và idempotency

Các nghiệp vụ chính chạy trong transaction:

- Chốt bảng lương.
- Hủy bảng lương.
- Thanh toán/hủy thanh toán.
- Tạo/hủy tạm ứng.
- Ghi điều chỉnh.
- Append/reverse/rebuild ledger.

Row lock đang dùng cho:

- Paysheet.
- Payslip.
- Payment.
- Employee.
- Advance.

Idempotency đã áp dụng cho:

- Payroll accrual theo payslip.
- Payment theo request key và payslip.
- Advance.
- Reversal.
- Opening balance.
- Migration.

## 16. Kết quả kiểm thử

Test nghiệp vụ mới:

```text
9 tests
41 assertions
PASS
```

Các case đã có test tự động:

1. Tạm ứng không bị double-count.
2. Accrual dùng `total_salary`.
3. Opening balance và summary timeline.
4. Payment idempotent, chỉ tạo một CashFlow.
5. Không hủy paysheet khi còn payment active.
6. Hủy payment rồi hủy paysheet đưa số dư về đúng.
7. Dòng `status = valid` nhưng `is_effective = false` không được tính.
8. Dòng `status = reversed` nhưng `is_effective = true` vẫn được tính.
9. Opening balance không tạo trùng.
10. Backdate quá 30 ngày bị chặn nếu thiếu quyền.
11. Nhân viên inactive không được tạm ứng.
12. Nhân viên có dữ liệu lương không được xóa cứng.

Kết quả kỹ thuật:

| Kiểm tra | Kết quả |
|---|---|
| Payroll feature tests | PASS |
| PHP lint | PASS |
| Laravel Pint | PASS |
| `npm run build` | PASS |
| Migration command dry-run | PASS |
| Full repository suite | Chưa xanh hoàn toàn |

Full suite còn một lỗi độc lập tại:

```text
Tests\Feature\Customers\AnhThanhThienPhuDebtReconcileTest
Expected supplier summary net = 75,000,000 nhưng nhận 0.
```

Sau đó full suite chạm giới hạn PHP memory 128 MB. Test payroll chạy riêng với 512 MB vẫn pass.

## 17. Các điểm BA cần phân tích và phát hành yêu cầu

### Bắt buộc trước production

- [ ] Chọn Option A hay Option B cho `employees.balance`.
- [ ] Chốt ngày go-live nếu dùng opening balance.
- [ ] Xác nhận có backfill chứng từ cũ hay chỉ opening balance.
- [ ] Cung cấp ma trận phân quyền theo role.
- [ ] Xác nhận rule tạm ứng có được vượt một hạn mức hay không.
- [ ] Xác nhận rule hủy tạm ứng đã phân bổ: tiếp tục chặn hay cho phép quy trình đảo phân bổ.
- [ ] Xác nhận cảnh báo nhân viên nghỉ việc còn số dư là warning hay blocking.
- [ ] Xác nhận báo cáo nào là “chi phí lương phát sinh” và báo cáo nào là “tiền lương thực chi”.

### Yêu cầu UI/UX đề nghị BA viết tiếp

- [ ] Form điều chỉnh tăng/giảm số dư.
- [ ] Danh sách tạm ứng và thao tác hủy tạm ứng.
- [ ] Chi tiết phân bổ tạm ứng vào phiếu lương.
- [ ] Hiển thị `applied_advance` trên bảng/phiếu lương.
- [ ] Link từ timeline sang chứng từ nguồn.
- [ ] Bộ lọc timeline đầy đủ và phân trang.
- [ ] Export timeline.
- [ ] Modal chuẩn cho lý do hủy, thay prompt.
- [ ] Thông báo rõ số dư dương/âm bằng màu và diễn giải.

### Yêu cầu kiểm soát/đối soát đề nghị BA viết tiếp

- [ ] Báo cáo anomaly giữa ledger và cache.
- [ ] Báo cáo đối chiếu payment với CashFlow.
- [ ] Báo cáo tạm ứng chưa phân bổ.
- [ ] Quy trình sửa ngày nghiệp vụ bằng reversal và chứng từ mới.
- [ ] Quy trình vận hành khi override kỳ khóa/backdate.
- [ ] Kịch bản rollback migration production.

## 18. Tiêu chí nghiệm thu đề xuất cho BA

```text
1. Mọi số dư khớp SUM(amount WHERE is_effective = true).
2. Không có query nghiệp vụ tính số dư chỉ bằng status = valid.
3. Tạm ứng -1 triệu, lương +5 triệu, trả -4 triệu => số dư 0.
4. Hủy chứng từ không xóa lịch sử và luôn có cancel_reverse.
5. Payment/advance tạo đúng một CashFlow.
6. Payment gọi lại cùng idempotency key không tạo trùng.
7. Không trả vượt remaining khi có request đồng thời.
8. Timeline lọc ngày vẫn có opening_balance.
9. Dòng reversed và cancel_reverse cùng xuất hiện và triệt tiêu đúng.
10. Nhân viên inactive vẫn xem được timeline và nhận thanh toán nợ cũ.
11. Nhân viên inactive không tạo được tạm ứng mới.
12. User thiếu quyền không xem hoặc thao tác được dữ liệu lương.
13. Conversion production chỉ chạy sau khi BA chọn rõ chiến lược dữ liệu cũ.
```

## 19. Danh sách file chính đã thay đổi

### Database và model

- `database/migrations/2026_06_13_000001_create_employee_salary_ledger_system.php`
- `app/Models/EmployeeSalaryLedgerEntry.php`
- `app/Models/SalaryAdvance.php`
- `app/Models/SalaryAdvanceApplication.php`
- `app/Models/Employee.php`
- `app/Models/Paysheet.php`
- `app/Models/Payslip.php`
- `app/Models/PaysheetPayment.php`
- `app/Models/CashFlow.php`

### Service và controller

- `app/Services/EmployeeSalaryLedgerService.php`
- `app/Services/PayrollPostingService.php`
- `app/Services/SalaryPaymentService.php`
- `app/Services/SalaryAdvanceService.php`
- `app/Services/PayrollDateGuard.php`
- `app/Http/Controllers/EmployeeSalaryLedgerController.php`
- `app/Http/Controllers/SalaryAdvanceController.php`
- `app/Http/Controllers/PaysheetController.php`
- `app/Http/Controllers/EmployeeController.php`

### Route, permission, UI và test

- `routes/api.php`
- `app/Models/Role.php`
- `app/Models/User.php`
- `resources/js/Pages/Employees/Index.vue`
- `resources/js/Pages/Employees/Paysheets.vue`
- `app/Console/Commands/MigrateSalaryLedger.php`
- `tests/Feature/Payroll/SalaryLedgerFlowTest.php`

## 20. Kết luận cho BA

Core nghiệp vụ nợ lương và tạm ứng đã đủ để tiếp tục QA dev:

- Ledger độc lập.
- Không double-count tạm ứng.
- Thanh toán một phần.
- Reversal append-only.
- CashFlow theo từng nhân viên.
- Backdate, lock, idempotency và phân quyền.
- Timeline và cache nhân viên.

Chưa được xem là sẵn sàng production cho đến khi BA/Owner chốt chiến lược dữ liệu legacy, ma trận quyền và các rule vận hành còn mở tại mục 17.
