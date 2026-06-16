# PAYROLL KIOTVIET SALARY FLOW GAP ANALYSIS

## 1. Nguồn tham chiếu

- Link KiotViet: https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-nhan-vien/quan-ly-tinh-luong/
- Tên tài liệu: Quản lý tính lương - KiotViet Retail / Bán buôn, Bán lẻ
- Ngày đọc: 2026-06-16
- Branch hệ thống: `feature/employee-debt-advance-expand-ui`
- Commit: `95fb7beb74d8af22107b7683ab5ac7fbba31e8e2`
- Report local đã đọc: `docs/audit/PAYROLL-KIOTVIET-E2E-LOCAL-TEST-REPORT.md`

Ghi chú nguồn: tài liệu KiotViet mô tả tính năng tính lương gồm thiết lập quy tắc lương, tạo bảng lương, chốt lương, thanh toán, ứng lương từ bảng lương hoặc thông tin nhân viên, tự động tạo phiếu chi và ghi nhận lịch sử thanh toán.

## 2. Tóm tắt luồng KiotViet

### 2.1. Thiết lập tính lương chung

KiotViet có các cấu hình nền tảng:

- Ngày bắt đầu kỳ lương.
- Tự động tạo bảng lương khi đến kỳ.
- Tự động cập nhật bảng lương hằng ngày.
- Mẫu lương dùng chung cho nhiều nhân viên.
- Ngày lễ tết, ngày làm việc/không làm việc của chi nhánh.

### 2.2. Thiết lập mức lương nhân viên

KiotViet cho thiết lập lương ở chi tiết nhân viên, tab Thiết lập lương:

- Lương chính theo ca làm việc.
- Lương chính theo giờ làm việc.
- Lương chính theo ngày công chuẩn.
- Lương cố định theo kỳ.
- Lương làm thêm giờ.
- Thưởng theo doanh thu cá nhân, doanh thu chi nhánh/cửa hàng, lợi nhuận gộp.
- Hoa hồng.
- Phụ cấp.
- Giảm trừ.
- Lưu thành mẫu lương mới.

### 2.3. Tạo và cập nhật bảng lương

KiotViet tạo bảng lương từ menu Nhân viên -> Bảng lương:

- Chọn kỳ hạn trả lương.
- Chọn kỳ làm việc.
- Chọn phạm vi áp dụng: tất cả nhân viên hoặc tùy chọn.
- Hệ thống tạo bảng lương trạng thái đang tạo, sau đó chuyển thành phiếu tạm/tạm tính.
- Ở trạng thái tạm tính, người dùng được xem chi tiết, cập nhật thủ công, tải lại dữ liệu, lưu tạm.

### 2.4. Chốt bảng lương

Theo tài liệu, sau khi kiểm tra dữ liệu chính xác, người dùng nhấn Chốt lương. Bảng lương chuyển sang Đã chốt lương, dữ liệu lương/chấm công trong kỳ bị khóa, và hệ thống ghi nhận giá trị tạm ứng nếu có vào bảng lương.

Diễn giải nghiệp vụ cho hệ thống mình:

```text
Chốt bảng lương
-> tạo/khóa phiếu lương nhân viên
-> phát sinh khoản phải trả lương
-> cột Nợ và tạm ứng tăng ngay
```

Không được hiểu là chỉ khi thanh toán mới phát sinh nợ lương.

### 2.5. Thanh toán bảng lương

KiotViet yêu cầu bảng lương đã chốt rồi mới thanh toán:

- Vào chi tiết bảng lương đã chốt.
- Chuyển tab Phiếu lương.
- Nhấn Thanh toán.
- Popup Thanh toán lương hiển thị danh sách nhân viên và số tiền cần trả.
- Cho sửa thời gian, phương thức, tiền trả nhân viên, ghi chú.
- Nhấn Tạo phiếu chi.
- Hệ thống tự động tạo phiếu chi trong Sổ quỹ và cập nhật trạng thái thanh toán.

Diễn giải nghiệp vụ:

```text
Thanh toán lương không tạo nợ mới.
Thanh toán lương làm giảm khoản phải trả đã phát sinh từ phiếu lương đã chốt.
```

### 2.6. Chi tiết phiếu lương và lịch sử trả lương

KiotViet cho xem phiếu lương theo 2 đường:

- Từ tab Phiếu lương của Bảng lương, nhấn mã phiếu lương.
- Từ Nhân viên -> Danh sách nhân viên -> chi tiết nhân viên -> tab Phiếu lương.

Chi tiết phiếu lương hiển thị:

- Các khoản thu nhập.
- Các khoản giảm trừ.
- Chi tiết chấm công trong kỳ.
- In/xuất file/hủy nếu đủ điều kiện.

Tab Nợ và tạm ứng trong chi tiết nhân viên hiển thị tất cả:

- Phiếu lương đã chốt.
- Các lần thanh toán lương.
- Các lần tạm ứng lương.

### 2.7. Thanh toán/ứng lương ngoài bảng lương

KiotViet có flow từ chi tiết nhân viên:

```text
Nhân viên -> Danh sách nhân viên -> chọn nhân viên
-> tab Nợ và tạm ứng
-> Thanh toán lương
-> nhập số tiền chi, thời gian, phương thức, ghi chú
-> Tạo phiếu chi
```

Rule quan trọng:

```text
Nếu nhân viên còn phiếu lương cần thanh toán: khoản chi là thanh toán lương.
Nếu nhân viên không còn phiếu lương cần thanh toán: khoản chi là tạm ứng lương.
Tạm ứng sẽ tự động trừ vào kỳ lương tiếp theo khi chốt lương.
```

## 3. Sơ đồ nghiệp vụ chuẩn

```text
Thiết lập lương nhân viên
-> Tạo bảng lương theo kỳ
-> Bảng lương tạm tính
-> Kiểm tra/cập nhật/tải lại dữ liệu
-> Chốt bảng lương
-> Tạo khoản phải trả theo từng phiếu lương
-> Nợ và tạm ứng nhân viên tăng
-> Thanh toán từ bảng lương hoặc từ chi tiết nhân viên
-> Tạo phiếu chi/CashFlow
-> Nợ và tạm ứng giảm
-> Nếu còn nợ: tiếp tục cho thanh toán phần còn lại
-> Nếu hết nợ: số dư về 0
-> Nếu chi thêm khi không còn nợ: ghi nhận tạm ứng
-> Tạm ứng được cấn vào kỳ lương sau khi chốt
```

## 4. Đối chiếu hệ thống hiện tại

| Nghiệp vụ | KiotViet xử lý | Hệ thống hiện tại | Trạng thái | Rủi ro | Đề xuất |
| --- | --- | --- | --- | --- | --- |
| Chốt bảng lương | Chốt lương khóa dữ liệu và tạo cơ sở phải trả lương | `PayrollPostingService::lock()` chỉ cho `calculated`, tạo `payroll_accrual` dương theo `total_salary`, cập nhật cache | PASS | Thấp | Giữ core ledger hiện tại |
| Thanh toán bảng lương | Chỉ sau khi đã chốt; popup có danh sách nhân viên, tiền cần trả, tiền trả, thời gian, phương thức, ghi chú; tạo phiếu chi | `Paysheets.vue` có modal thanh toán, rows theo `remaining`, cho sửa amount, gọi `/api/paysheets/{id}/pay`; backend tạo `PaysheetPayment`, `CashFlow`, ledger âm | PASS/GAP UI | Nếu UI thiếu cột/label giống KiotViet, kế toán có thể hiểu nhầm số đã cấn tạm ứng | Chuẩn hóa modal nhãn "Tạo phiếu chi", hiển thị tổng tiền trả, đã trả, còn cần trả rõ hơn |
| Trả lương một phần | Cho sửa tiền trả nhân viên | UI cho sửa `row.amount`, backend chặn amount > remaining | PASS | Thấp | Thêm test UI cho partial payment nếu chưa có |
| Trả đủ lương | Cập nhật trạng thái thanh toán, không còn nợ | Backend `syncSlip()` set `paid` khi remaining = 0; E2E đã PASS | PASS | Thấp | Giữ |
| Thanh toán từ nhân viên | Từ tab Nợ và tạm ứng, nhấn Thanh toán lương, nếu còn nợ thì trả vào nợ lương | Hiện tab nhân viên có tạo tạm ứng, điều chỉnh, export, list advance; chưa thấy nút/flow "Thanh toán lương" từ tab nhân viên dùng chung remaining payslip | GAP | Người dùng phải quay về bảng lương để trả nợ; nếu tự tạo tạm ứng/điều chỉnh thay thế có thể sai nghiệp vụ | Phase 1 thêm flow thanh toán lương từ nhân viên, dùng `SalaryPaymentService` và cùng payslip remaining |
| Tạm ứng khi không còn nợ lương | Cùng thao tác chi tiền từ nhân viên; nếu nợ cần trả = 0 thì ghi nhận tạm ứng | Hệ thống có form "Tạo tạm ứng" riêng trong tab nhân viên; chưa có auto-classify từ nút "Thanh toán lương" | GAP | UX khác KiotViet; người dùng có thể không hiểu khi nào là thanh toán, khi nào là tạm ứng | Phase 1/2 thêm CTA thống nhất hoặc cảnh báo rõ: còn nợ thì thanh toán, hết nợ thì tạm ứng |
| Tab Phiếu lương nhân viên | Chi tiết nhân viên có tab Phiếu lương, liệt kê phiếu theo kỳ, mã phiếu mở chi tiết | Modal nhân viên hiện có `Thông tin`, `Thiết lập lương`, `Nợ & Tạm ứng`; expand row giả lập thanh tab có `Phiếu lương` nhưng chưa phải tab chức năng trong modal nhân viên | GAP | BA kỳ vọng xem payslip ngay trong nhân viên, nhưng hiện chưa có bảng phiếu lương chuẩn như KiotViet trong modal | Phase 2 thêm tab Phiếu lương nhân viên, dùng payslip/paysheet thật |
| Tab Nợ & tạm ứng | Hiển thị phiếu lương đã chốt, thanh toán, tạm ứng | Timeline dùng `employee_salary_ledger_entries`, có type labels, status, filter, export; E2E PASS | PASS | Thấp | Giữ; bổ sung CTA thanh toán thống nhất |
| Hủy thanh toán | KiotViet cho hủy nếu xử lý phiếu chi liên quan; lịch sử cần minh bạch | Backend hủy payment, cancel CashFlow, tạo `cancel_reverse`, giữ original | PASS | Thấp | Giữ |
| Hủy bảng lương | Có hủy bỏ; bảng lương đã chốt cần kiểm soát thanh toán liên quan | Backend chặn hủy nếu còn payment active; tạo reversal cho accrual; hủy application | PASS | Nếu CashFlow bị xử lý ngoài hệ thống có thể lệch đối soát | Giữ, tiếp tục audit CashFlow |
| Chi phí lương trên báo cáo tài chính | Bảng lương đã chốt được tự động ghi nhận vào chi phí lương | Đã có classifier/P&L phase trước theo report; cần regression tiếp tục | PASS cần giám sát | Double-count nếu P&L tính thêm CashFlow lương | Duy trì regression financial |

## 5. Gaps phát hiện

### GAP-01: Chưa có flow "Thanh toán lương" từ tab Nợ & tạm ứng nhân viên giống KiotViet

- Mô tả: KiotViet cho thanh toán/ứng lương ngoài bảng lương ngay trong tab Nợ và tạm ứng. Hệ thống hiện có form tạo tạm ứng và điều chỉnh, nhưng chưa thấy nút thanh toán lương từ nhân viên dùng chung các payslip còn phải trả.
- Ảnh hưởng nghiệp vụ: Kế toán phải quay về bảng lương để thanh toán nợ lương cũ. Nếu dùng nhầm tạm ứng hoặc điều chỉnh để chi tiền thì timeline và CashFlow có thể sai loại chứng từ.
- Mức độ: High.
- File/màn hình liên quan:
  - `resources/js/Pages/Employees/Index.vue`
  - `app/Services/SalaryPaymentService.php`
  - `routes/api.php`
- Đề xuất sửa: thêm modal "Thanh toán lương" trong tab Nợ & tạm ứng nhân viên. Modal phải lấy danh sách payslip remaining > 0 của nhân viên, cho trả một phần, tạo `PaysheetPayment`, CashFlow và ledger `salary_payment` qua cùng service.

### GAP-02: Chưa có auto-classification "hết nợ thì thành tạm ứng" theo đúng KiotViet

- Mô tả: KiotViet dùng cùng luồng chi tiền từ nhân viên; nếu không còn phiếu lương cần thanh toán thì ghi nhận là tạm ứng lương. Hệ thống hiện tách rõ "Tạo tạm ứng" riêng, chưa có một CTA thống nhất.
- Ảnh hưởng nghiệp vụ: UX khác KiotViet và người dùng có thể hiểu rằng mọi chi tiền từ nhân viên đều là tạm ứng, kể cả khi còn nợ lương cần trả.
- Mức độ: High.
- File/màn hình liên quan:
  - `resources/js/Pages/Employees/Index.vue`
  - `SalaryPaymentService`
  - `SalaryAdvanceService`
- Đề xuất sửa: trong modal chi tiền từ nhân viên, backend quyết định:
  - còn payslip remaining > 0: tạo salary payment;
  - không còn payslip remaining: tạo salary advance;
  - nếu số tiền lớn hơn tổng remaining: cần BA quyết định chặn hay tách phần vượt thành advance.

### GAP-03: Tab Phiếu lương trong chi tiết nhân viên chưa đủ như KiotViet

- Mô tả: KiotViet có tab Phiếu lương trong chi tiết nhân viên, hiển thị phiếu theo kỳ và mở chi tiết phiếu lương. Modal nhân viên hiện chưa có tab Phiếu lương thật; expand row chỉ có thanh tab tham chiếu giao diện, không phải chức năng đầy đủ.
- Ảnh hưởng nghiệp vụ: Người dùng không xem được toàn bộ lịch sử payslip của một nhân viên theo cách KiotViet, khó đối chiếu Thành tiền / Đã trả / Còn cần trả ngay trong hồ sơ nhân viên.
- Mức độ: Medium.
- File/màn hình liên quan:
  - `resources/js/Pages/Employees/Index.vue`
  - `resources/js/Pages/Employees/Paysheets.vue`
  - `app/Http/Controllers/PaysheetController.php`
- Đề xuất sửa: thêm API `GET /api/employees/{employee}/payslips` hoặc reuse endpoint hiện có có filter employee, thêm tab Phiếu lương trong modal nhân viên.

### GAP-04: Modal thanh toán bảng lương đang đúng core nhưng label/chứng từ chưa thật sát KiotViet

- Mô tả: KiotViet dùng "Tạo phiếu chi"; modal hiện là "Xác nhận thanh toán". Backend có tạo CashFlow nhưng UI chưa nhấn mạnh phiếu chi/Sổ quỹ.
- Ảnh hưởng nghiệp vụ: Kế toán có thể không nhận ra thao tác này tạo phiếu chi và ảnh hưởng Sổ quỹ.
- Mức độ: Medium.
- File/màn hình liên quan:
  - `resources/js/Pages/Employees/Paysheets.vue`
- Đề xuất sửa: đổi CTA thành "Tạo phiếu chi", hiển thị mã phiếu chi sau tạo, và link tới chứng từ CashFlow/payment.

### GAP-05: Tài liệu KiotViet có tính năng nâng cao chưa hoàn chỉnh/hoặc cần tiếp tục giám sát

- Mô tả: KiotViet có tự động tạo bảng lương, tự động cập nhật bảng lương, mẫu lương, ngày lễ tết, ngày làm việc/không làm việc, báo cáo tài chính chi phí lương. Hệ thống đã có một số phần, nhưng cần audit riêng từng phần.
- Ảnh hưởng nghiệp vụ: Nếu BA yêu cầu giống KiotViet trọn bộ, các phần này sẽ cần phase riêng.
- Mức độ: Medium.
- File/màn hình liên quan:
  - payroll settings
  - salary settings
  - financial report
- Đề xuất sửa: không nhét vào phase thanh toán/nợ lương; tách phase cấu hình lương nâng cao.

## 6. Trả lời câu hỏi BA

### Q1. Theo KiotViet, khi nào cột Nợ & Tạm ứng tăng?

Khi bảng lương được chốt và phiếu lương nhân viên trở thành khoản phải trả. KiotViet mô tả chốt lương làm bảng lương chuyển Đã chốt lương, khóa dữ liệu kỳ lương và ghi nhận tạm ứng nếu có.

### Q2. Thanh toán lương có tạo nợ mới không?

Không. Thanh toán lương xảy ra sau khi đã chốt bảng lương; nó tạo phiếu chi và cập nhật trạng thái thanh toán, làm giảm khoản phải trả.

### Q3. Nếu thanh toán ở Bảng lương rồi, vào nhân viên có được thanh toán lại không?

Không được thanh toán lại cùng khoản nếu `remaining = 0`. KiotViet dùng lịch sử trả lương/Nợ và tạm ứng để phản ánh phiếu lương, thanh toán, tạm ứng. Hệ thống mình backend đã chặn trả vượt remaining.

### Q4. Nếu nhân viên không còn nợ lương mà chi tiền thì nghiệp vụ là gì?

Theo KiotViet, khoản chi đó được ghi nhận là tạm ứng lương và tự động trừ vào kỳ lương tiếp theo khi chốt lương.

### Q5. Hệ thống mình hiện xử lý giống hay khác?

Giống ở core ledger và payment từ bảng lương:

- Chốt lương tạo ledger dương.
- Thanh toán tạo ledger âm, CashFlow, payment.
- Tạm ứng tạo ledger âm.
- Hủy tạo dòng đảo.
- Không double-count tạm ứng.

Khác/GAP ở UI/flow chi tiền từ chi tiết nhân viên:

- Chưa có flow thanh toán lương từ tab nhân viên dùng chung payslip remaining.
- Chưa có auto-classification hết nợ thì thành tạm ứng.
- Chưa có tab Phiếu lương nhân viên đầy đủ như KiotViet.

### Q6. Modal thanh toán bảng lương của mình đã đủ giống KiotViet chưa?

Core đủ: có thời gian, phương thức, danh sách payslip/nhân viên, còn phải trả, số tiền trả, ghi chú, cho trả một phần. GAP UI: cần đổi nhãn/nhấn mạnh "Tạo phiếu chi", link chứng từ phiếu chi, và có thể bổ sung tổng tiền thanh toán.

### Q7. Tab Phiếu lương trong chi tiết nhân viên đã có chưa?

Chưa đủ. Bảng lương có tab Phiếu lương; chi tiết nhân viên hiện có tab Nợ & Tạm ứng và Thiết lập lương, nhưng chưa có tab Phiếu lương riêng liệt kê payslip theo kỳ giống KiotViet.

### Q8. Tab Nợ & Tạm ứng đã hiển thị đủ lịch sử chưa?

Đạt phần lịch sử ledger: phiếu lương đã chốt, thanh toán, tạm ứng, điều chỉnh, dòng đảo. API summary và E2E đã pass. Cần bổ sung flow thao tác thanh toán lương từ tab này để giống KiotViet hơn.

### Q9. Có rủi ro double payment hoặc double advance không?

Backend core đang giảm rủi ro tốt: payment có idempotency, lock row, chặn vượt remaining; advance có idempotency; advance application không tạo ledger âm lần hai. Rủi ro còn lại là UX: nếu người dùng không có flow thanh toán từ nhân viên, họ có thể tạo tạm ứng/điều chỉnh sai nghiệp vụ.

### Q10. Cần sửa gì trước, sửa gì sau?

Trước:

1. Flow chi tiền từ tab Nợ & tạm ứng nhân viên: còn nợ thì thanh toán lương, hết nợ thì tạm ứng.
2. Tab Phiếu lương trong chi tiết nhân viên.
3. Chuẩn hóa modal thanh toán bảng lương thành "Tạo phiếu chi".

Sau:

1. Export/in phiếu lương nâng cao.
2. Mẫu lương, tự động tạo/cập nhật bảng lương.
3. Báo cáo tài chính chi phí lương đầy đủ theo KiotViet.

## 7. Business rules đề xuất

- BR01. Chốt bảng lương là thời điểm phát sinh nợ lương.
- BR02. Mỗi phiếu lương đã chốt tạo một khoản phải trả cho nhân viên.
- BR03. Cột Nợ & Tạm ứng = `SUM(amount WHERE is_effective = true)`.
- BR04. Số dư dương là công ty còn nợ lương nhân viên.
- BR05. Số dư âm là nhân viên đang nợ công ty do tạm ứng.
- BR06. Thanh toán lương chỉ làm giảm khoản phải trả, không tạo khoản nợ mới.
- BR07. Một phiếu lương chỉ có một số "Còn cần trả" duy nhất.
- BR08. Thanh toán từ Bảng lương và từ Nhân viên phải cùng trừ vào cùng payslip remaining.
- BR09. Nếu Còn cần trả = 0 thì không cho thanh toán lương tiếp.
- BR10. Nếu chi tiền khi không còn nợ lương thì ghi nhận là tạm ứng lương.
- BR11. Không cho trả vượt số còn cần trả, trừ khi BA chốt rule tách phần vượt thành tạm ứng.
- BR12. Hủy thanh toán phải tạo dòng đảo, không xóa lịch sử.
- BR13. Hủy bảng lương đã chốt phải tạo dòng đảo, không xóa lịch sử.
- BR14. Không sửa trực tiếp `employees.balance`.
- BR15. Không sửa trực tiếp `salary_balance_cache` ngoài command/service rebuild hợp lệ.
- BR16. Tạm ứng đã phát sinh ledger âm không được tạo thêm ledger âm khi cấn vào phiếu lương.
- BR17. Báo cáo P&L không được double-count CashFlow lương và payroll accrual.
- BR18. Mọi thao tác chi tiền lương/tạm ứng phải tạo hoặc liên kết CashFlow/phiếu chi để đối soát Sổ quỹ.

## 8. Test checklist sau khi sửa

### Core existing regression

- Chốt bảng lương tạo `payroll_accrual` dương.
- Thanh toán một phần tạo `salary_payment` âm.
- Thanh toán đủ đưa remaining và salary balance về 0.
- Tạm ứng trước lương tạo số dư âm.
- Chốt lương sau tạm ứng không double-count.
- Hủy payment tạo `cancel_reverse`.
- Hủy bảng lương tạo `cancel_reverse`.
- `salary_balance_cache` khớp SUM ledger effective.

### New tests cho gap

- Từ tab nhân viên, còn payslip remaining 1.000.000, chi 400.000 -> tạo salary payment, remaining 600.000.
- Từ tab nhân viên, remaining = 0, chi 500.000 -> tạo salary advance, balance -500.000.
- Từ tab nhân viên, còn remaining 300.000, chi 500.000:
  - nếu BA chọn chặn: API 422;
  - nếu BA chọn tách: payment 300.000 + advance 200.000.
- Thanh toán từ bảng lương xong, tab nhân viên hiển thị remaining = 0 và không cho thanh toán lại.
- Tab Phiếu lương nhân viên hiển thị đúng mã phiếu, kỳ làm việc, tổng lương, tạm ứng đã cấn, đã trả, còn cần trả, trạng thái.
- Nút mã phiếu lương mở đúng chi tiết payslip.
- CashFlow được tạo đúng khi thanh toán từ nhân viên.
- Không có payment/CashFlow trùng khi double-click submit.

## 9. Đề xuất phase triển khai

### Phase 1: Đồng bộ thanh toán từ nhân viên với bảng lương

- Thêm API hoặc service method thanh toán theo employee.
- Lấy các payslip locked còn remaining > 0.
- Thanh toán FIFO hoặc cho user chọn payslip; BA cần chọn.
- Nếu không có remaining thì route sang tạo tạm ứng.
- Reuse `SalaryPaymentService` và `SalaryAdvanceService`, không viết ledger trực tiếp.

### Phase 2: Hoàn thiện UI chi tiết nhân viên giống KiotViet

- Thêm tab Phiếu lương trong modal nhân viên.
- Danh sách payslip theo kỳ: Mã phiếu, Kỳ làm việc, Tổng lương, Tạm ứng đã cấn, Đã trả, Còn cần trả, Trạng thái.
- Link mã phiếu mở chi tiết.
- Trong tab Nợ & tạm ứng, thêm CTA "Thanh toán lương" thống nhất.

### Phase 3: Chuẩn hóa modal thanh toán bảng lương

- Đổi CTA thành "Tạo phiếu chi".
- Hiển thị tổng tiền trả.
- Hiển thị rõ "Đã trả", "Còn cần trả", "Tiền trả nhân viên".
- Sau khi tạo, show/link payment/CashFlow.

### Phase 4: Nâng cao và báo cáo

- Hoàn thiện mẫu lương, tự động tạo/cập nhật bảng lương nếu BA yêu cầu.
- Rà P&L/Financial report để chắc chắn chi phí lương lấy từ bảng lương đã chốt, không double-count CashFlow thanh toán/tạm ứng.
- Export/In phiếu lương và bảng kê hoa hồng nếu cần giống KiotViet.

## 10. Kết luận BA

### Kết luận

`GAP`, không phải `FAIL`.

Core ledger hiện tại đã đúng hướng KiotViet cho các bất biến tài chính quan trọng:

- Chốt bảng lương phát sinh khoản phải trả.
- Thanh toán làm giảm khoản phải trả.
- Tạm ứng tạo số dư âm/giảm nghĩa vụ phải trả.
- Hủy tạo dòng đảo, không xóa lịch sử.
- Không double-count tạm ứng.

Tuy nhiên nếu mục tiêu là giống KiotViet về vận hành end-to-end, còn thiếu:

1. Thanh toán lương từ tab Nợ & tạm ứng nhân viên.
2. Rule UI/API "không còn nợ thì chi tiền thành tạm ứng".
3. Tab Phiếu lương trong chi tiết nhân viên.
4. Modal thanh toán bảng lương cần nhấn mạnh tạo phiếu chi/Sổ quỹ.

### Có được code tiếp không?

Có, nhưng nên code theo phase, bắt đầu từ Phase 1: đồng bộ thanh toán/ứng lương từ chi tiết nhân viên với cùng nguồn payslip remaining và salary ledger. Không nên sửa core ledger trước, vì test hiện tại đang chứng minh core đúng.
