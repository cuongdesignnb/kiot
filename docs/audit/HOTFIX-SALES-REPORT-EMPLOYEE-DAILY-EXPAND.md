# HOTFIX — Sales report employee daily expandable rows

## Phạm vi

- Module: Báo cáo / Báo cáo bán hàng
- Màn hình: `/reports/sales?concern=employee`
- Nghiệp vụ: Báo cáo bán hàng theo nhân viên, mở rộng dòng người bán thành các dòng con theo ngày, click ngày để xem chi tiết hóa đơn (drilldown) trỏ đến màn `/invoices`.
- Rủi ro:
  - Trả hàng của người bán khác bị leak vào người bán đang xem.
  - Tổng dòng cha không khớp tổng các ngày con.
  - Link ngày sang hóa đơn thiếu bộ lọc chi nhánh/kênh bán/nhân viên hoặc sai định dạng ngày.
  - Gây ảnh hưởng hoặc thay đổi cấu trúc của các mối quan tâm khác trong báo cáo bán hàng (Thời gian, Lợi nhuận, Giảm giá HĐ, Trả hàng).

## Source đã kiểm tra

- Controller: [SalesReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/SalesReportController.php)
- SellerResolver: [SellerResolver.php](file:///d:/Kiot/kiotviet-clone/app/Support/Reports/SellerResolver.php)
- Frontend: [SalesReport.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Reports/SalesReport.vue)
- Route: `/reports/sales`
- Filter: Mối quan tâm, Chi nhánh, Thời gian, Phương thức bán hàng
- Test: [SalesReportEmployeeDailyBreakdownTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Reports/SalesReportEmployeeDailyBreakdownTest.php)
- Commit: (Xem thông tin hash ở phần sau)

## Hiện trạng trước sửa

- `/reports/sales?concern=employee` chỉ hiển thị danh sách flat theo nhân viên.
- `SalesReportController::buildEmployeeSeries()` chỉ trả về chartData labels/datasets.
- `SalesReport.vue` report view tự map chartData thành flat rows.
- Chưa có children theo ngày, chưa có expand/collapse.
- Chưa có link ngày sang hóa đơn.
- Header cột đầu tiên vẫn hiển thị là `Thời gian`.

## Root cause

- Backend chưa tổng hợp dữ liệu ngày con (`children`) cho người bán.
- Frontend chưa có UI cho expandable rows và hiển thị cột đầu là `Người bán`.

## Thay đổi đã làm

- **Backend**:
  - Cập nhật `buildEmployeeSeries()` nhận thêm `$salesChannel` và đính kèm `rows` và `summary` cho concern nhân viên.
  - Thêm helper `buildSalesDailyChildren()` tổng hợp doanh thu theo ngày (`DATE(created_at)`) và trả hàng theo seller của hóa đơn gốc.
  - Tạo liên kết `invoice_url` với đầy đủ bộ lọc (`seller_key` đã được URL encoded, `date_filter=custom`, `date_from/date_to`, `branch_id` và `sales_channel` nếu có).
  - Parent row được tổng hợp trực tiếp từ daily children để đảm bảo khớp số tuyệt đối.
- **Frontend**:
  - Thêm reactive state `expandedRows` và các helpers toggle.
  - Khi `concern === 'employee'`, render bảng expandable riêng với các cột `Người bán`, `Doanh thu`, `Giá trị trả`, `Doanh thu thuần`.
  - Dòng con ngày được thụt lề và hiển thị link trỏ tới `child.invoice_url`.
  - Tự động reset `expandedRows` khi thay đổi bộ lọc.
  - Phân trang tính theo số dòng cha.

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Không cập nhật dữ liệu cũ.
- Không xóa dữ liệu.
- Không recalculate tồn kho/giá vốn/công nợ/cashflow.

## Tests đã chạy

- Command: `php artisan test --filter=SalesReportEmployeeDailyBreakdownTest`
- Result: PASS (9 tests passed)

## Manual QA

- Employee report: Màn hình `/reports/sales?concern=employee` hiển thị bảng với cột `Người bán`, nút expand hoạt động đúng.
- Expand seller: Bấm `+` mở rộng hiển thị các ngày con chính xác.
- Children daily: Mỗi dòng ngày con hiển thị đúng doanh thu, giá trị trả và net.
- Click ngày: Liên kết mở đúng màn `/invoices` kèm theo filter chi nhánh/kênh bán/nhân viên chính xác.
- Branch filter: Hoạt động chính xác, lọc cả doanh thu/trả hàng và link drilldown.
- Sales channel filter: Hoạt động chính xác, không bị leak trả hàng từ kênh bán khác.
- Chart regression: Chế độ xem biểu đồ (`view=chart`) vẫn hoạt động tốt.
- Other concerns regression: Các concern khác (`time`, `profit`, `discount`, `returns`) không thay đổi cấu trúc và chạy bình thường.

## Rủi ro còn lại

- Phase này giữ date logic theo `created_at` để khớp số liệu báo cáo cũ.
- Drilldown trỏ sang `/invoices` theo ngày bán. Ngày chỉ có return click sang có thể rỗng hóa đơn do ngày trả khác ngày bán; nếu cần xem danh sách trả hàng thì có thể drilldown sang phiếu trả ở phase sau.

## Kết luận

- Đạt/chưa đạt: Đạt
- Có thể deploy chưa: Có thể deploy
- Cần làm tiếp: Không
