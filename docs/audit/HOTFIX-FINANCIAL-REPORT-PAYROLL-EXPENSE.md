# HOTFIX — Financial report payroll expense

## Phạm vi

- Module: Phân tích / Báo cáo tài chính
- Màn hình: `/reports/financial-report`
- Nghiệp vụ: Chi phí lương nhân viên trong báo cáo lãi/lỗ (P&L)
- Rủi ro chính:
  - Không cộng lương vào P&L → lợi nhuận bị cao ảo.
  - Cộng cả `paysheets.total_salary` và `CashFlow Chi lương nhân viên` → double count.
  - Cộng bảng lương bị hủy/cancelled → sai chi phí.
  - Tự recalculate bảng lương cũ → ảnh hưởng dữ liệu payroll production.
  - Filter theo chi nhánh sai nếu không dùng `paysheets.branch_id`.

## Source đã kiểm tra

- Route: [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
- Controller: [FinancialReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/FinancialReportController.php), [PaysheetController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/PaysheetController.php)
- Model: [Paysheet.php](file:///d:/Kiot/kiotviet-clone/app/Models/Paysheet.php), [CashFlow.php](file:///d:/Kiot/kiotviet-clone/app/Models/CashFlow.php)
- Test: [FinancialReportPayrollExpenseTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Report/FinancialReportPayrollExpenseTest.php)

## Hiện trạng trước sửa

- Chi phí (6) trong báo cáo tài chính chỉ lấy chi phí từ các giao dịch thanh toán thực tế trong `cash_flows`.
- Chi phí lương nhân viên chỉ xuất hiện khi người dùng thực hiện thanh toán bảng lương và phát sinh phiếu chi CashFlow.
- Nếu bảng lương đã tính/chốt (`status = calculated / locked`) nhưng chưa thanh toán thì chi phí lương không được phản ánh trên P&L.

## Root cause

- `FinancialReportController` chưa truy vấn và tổng hợp chi phí lương từ bảng lương `paysheets` mà hoàn toàn phụ thuộc vào CashFlow.

## Thay đổi đã làm

- Tích hợp chi phí lương từ bảng lương vào Chi phí (6) của P&L:
  - Chỉ lấy các bảng lương có status là `calculated` hoặc `locked`.
  - Lọc theo khoảng thời gian dựa trên `period_start` và `period_end` của bảng lương.
  - Lọc theo chi nhánh (`branch_id`) nếu người dùng chọn chi nhánh cụ thể.
  - Tự động cộng chi phí lương vào breakdown `$expensesByCategory` dưới tên `'Chi lương nhân viên'`.
- Loại bỏ double-counting cashflow lương:
  - Cập nhật helper `pnlCashFlowBaseQuery` để loại trừ hoàn toàn các cashflow liên quan đến lương nhân viên (như các reference_type: `paysheet`, `Paysheet`, `PaysheetPayment` và các category: `Chi lương nhân viên`, `Chi luong nhan vien`, `Lương nhân viên`, `Luong nhan vien`, `Thanh toán lương`, `Thanh toan luong`).
- Viết test case kiểm thử tự động tại [FinancialReportPayrollExpenseTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Report/FinancialReportPayrollExpenseTest.php).

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không xóa dữ liệu.
- Không recalculate bảng lương.
- Không recalculate tồn kho/giá vốn/công nợ/cashflow.

## Tests đã chạy

- Lệnh: `php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php`
- Kết quả: PASS toàn bộ 9/9 tests.

## Manual QA

- Đã kiểm tra:
  - Tạo bảng lương calculated/locked trong tháng 4/2026. Báo cáo tài chính hiển thị đúng dòng `Chi lương nhân viên` bằng tổng lương của bảng lương đó.
  - Bảng lương cancelled/calculating không bị tính.
  - Thanh toán một phần hoặc toàn bộ bảng lương (tạo CashFlow lương) không làm nhân đôi chi phí lương trong P&L.
  - Lọc chi nhánh và khoảng thời gian hoạt động đúng.

## Rủi ro còn lại

- Kỳ báo cáo tùy chỉnh (custom date range) chỉ lấy các bảng lương nằm trọn trong kỳ đó, không phân bổ theo tỷ lệ (pro-rate) nếu bảng lương chỉ bị giao thoa (overlap) một phần. Đây là nghiệp vụ kế toán tiêu chuẩn của hệ thống.
- Bảng lương có flag `needs_recalc` vẫn lấy dữ liệu `total_salary` hiện tại trên DB, không tự recalculate khi chạy báo cáo để tránh thay đổi dữ liệu ngầm.

## Kết luận

- Đạt. Có thể deploy.
