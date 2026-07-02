# HOTFIX — Employee report daily expandable rows

## Phạm vi

- Module: Báo cáo / Báo cáo nhân viên
- Màn hình: `/reports/employees`
- Nghiệp vụ: Hiển thị dòng người bán ở dạng cha, khi mở rộng (expand) sẽ hiển thị các dòng con đại diện cho doanh thu, trả hàng và net của từng ngày của người bán đó. Hỗ trợ liên kết (drilldown) từ ngày sang danh sách hóa đơn chi tiết.
- Rủi ro:
  - Trả hàng của người bán khác bị leak vào người bán đang xem.
  - Tổng dòng cha không khớp tổng các dòng ngày con.
  - Đường dẫn drilldown thiếu bộ lọc chi nhánh/kênh bán/nhân viên hoặc sai định dạng ngày.
  - Gây ảnh hưởng hoặc thay đổi cấu trúc của báo cáo lợi nhuận và hàng bán theo nhân viên.

## Source đã kiểm tra

- Controller: [EmployeeReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/EmployeeReportController.php)
- SellerResolver: [SellerResolver.php](file:///d:/Kiot/kiotviet-clone/app/Support/Reports/SellerResolver.php)
- Frontend: [EmployeeReport.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Reports/EmployeeReport.vue)
- Route: `/reports/employees`
- Filter: Mối quan tâm, Chi nhánh, Thời gian, Người bán, Kênh bán
- Test: [EmployeeReportDailyBreakdownTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Reports/EmployeeReportDailyBreakdownTest.php)
- Commit: (Xem thông tin hash ở phần sau)

## Hiện trạng trước sửa

- Báo cáo nhân viên hiển thị dạng flat table theo seller, không có breakdown chi tiết theo ngày.
- Chưa có nút expand/collapse (`+` / `-`).
- Chưa có cơ chế chuyển hướng drilldown từ ngày sang hóa đơn kèm đầy đủ filter.

## Root cause

- Controller chưa gom nhóm và đính kèm dữ liệu ngày con (`children`) cho người bán.
- Giao diện Vue chưa hỗ trợ trạng thái mở rộng (`expandedRows`) và hiển thị các dòng ngày con tương ứng.

## Thay đổi đã làm

- **Backend**:
  - Cập nhật `buildSalesReportRows` để gọi helper `buildSalesDailyChildren` nhằm xây dựng breakdown hàng ngày của invoices và returns cho mỗi seller.
  - Group invoices theo `DATE(created_at)` và group returns theo `DATE(returns.created_at)` cùng seller hóa đơn gốc.
  - Sinh URL `invoice_url` và `return_url` đầy đủ bộ lọc (`seller_key` đã được URL encoded, `date_filter=custom`, `date_from/date_to` là ngày cụ thể, và các filter chi nhánh/kênh bán đang áp dụng).
  - Đảm bảo làm tròn số `round(..., 2)` để tổng cha bằng tổng dòng con.
- **Frontend**:
  - Thêm reactive state `expandedRows` và các helper function (`isExpanded`, `toggleRow`, `hasChildren`) trong `EmployeeReport.vue`.
  - Cột đầu tiên hiển thị nút `+` / `-` trước tên người bán khi `concern.value === 'sales'`.
  - Khi mở rộng, render các dòng con thụt lề với định dạng liên kết ngày trỏ đến `child.invoice_url`.
  - Tự động reset `expandedRows.value = {}` khi người dùng thay đổi bộ lọc.
  - Phân trang Vue giữ nguyên theo số lượng người bán dòng cha.

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Không cập nhật dữ liệu cũ.
- Không xóa dữ liệu.
- Không recalculate tồn kho/giá vốn/công nợ/cashflow.

## Tests đã chạy

- Command: `php artisan test tests/Feature/Reports`
- Result: PASS (57 tests passed)

## Manual QA

- Expand seller: Hoạt động chính xác, click mở rộng hiển thị danh sách ngày.
- Children theo ngày: Hiển thị đúng doanh thu, giá trị trả hàng và net của từng ngày.
- Click ngày: Chuyển hướng thành công sang `/invoices` kèm theo đầy đủ query parameters (date_filter, date_from/date_to, seller_key, branch_id, sales_channel).
- Filter seller: Hoạt động chính xác, chỉ hiển thị dữ liệu của người bán được chọn.
- Filter branch: Bộ lọc chi nhánh được áp dụng đúng cho cả doanh thu, trả hàng ngày con và link drilldown.
- Filter sales_channel: Bộ lọc kênh bán được áp dụng đúng, không bị leak trả hàng từ kênh bán khác.
- Profit/items regression: Báo cáo lợi nhuận và hàng bán không đổi cấu trúc và hoạt động hoàn toàn bình thường.

## Rủi ro còn lại

- Daily breakdown chỉ áp dụng cho `concern=sales`.
- Báo cáo nhóm ngày theo `created_at` để thống nhất số liệu cũ. Nếu muốn đổi sang `transaction_date` sẽ cần một task riêng vì có thể thay đổi số liệu lịch sử.

## Kết luận

- Đạt/chưa đạt: Đạt
- Có thể deploy chưa: Có thể deploy
- Cần làm tiếp: Không
