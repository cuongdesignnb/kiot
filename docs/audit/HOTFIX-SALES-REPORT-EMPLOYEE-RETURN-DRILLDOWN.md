# HOTFIX — Sales report employee return drilldown

## Phạm vi

- Module: Báo cáo bán hàng (Sales Report) & Trả hàng (Order Returns).
- Màn hình: 
  - `/reports/sales?concern=employee&period=this_month&view=report`
- Nghiệp vụ: 
  - Xem chi tiết (drilldown) ngày phát sinh giao dịch của nhân viên.
  - Ngày chỉ có trả hàng (revenue = 0, returns > 0) -> Click vào ngày dẫn sang danh sách trả hàng `/returns`.
  - Ngày có hóa đơn bán (revenue > 0) -> Click vào ngày dẫn sang danh sách hóa đơn `/invoices`.
  - Ngày có trả hàng -> Cột "Giá trị trả hàng" wrap link dẫn trực tiếp sang danh sách trả hàng `/returns`.
  - Tất cả các link drilldown từ báo cáo này đều mở ở tab mới (`target="_blank"`).
  - Lọc danh sách trả hàng theo `seller_key` của hóa đơn gốc.
- Rủi ro:
  - Lọc sai `seller_key` dẫn tới rò rỉ dữ liệu hoặc trống kết quả.

## Source đã kiểm tra

- SalesReportController: `app/Http/Controllers/SalesReportController.php`
- EmployeeReportController: `app/Http/Controllers/EmployeeReportController.php`
- SalesReport.vue: `resources/js/Pages/Reports/SalesReport.vue`
- EmployeeReport.vue: `resources/js/Pages/Reports/EmployeeReport.vue`
- OrderReturnController: `app/Http/Controllers/OrderReturnController.php`
- Routes: `routes/web.php`
- SellerResolver: `app/Support/Reports/SellerResolver.php`
- Test: `tests/Feature/Reports/SalesReportEmployeeDailyBreakdownTest.php`, `tests/Feature/Reports/EmployeeReportDailyBreakdownTest.php`

## Hiện trạng trước sửa

- Chi tiết các ngày trong báo cáo nhân viên chỉ sinh `invoice_url` và link trực tiếp sang `/invoices`.
- Ngày chỉ có trả hàng vẫn click sang `/invoices` dẫn đến danh sách rỗng (vì hóa đơn được bán ở ngày khác).
- Link drilldown mở cùng tab làm mất trạng thái báo cáo đang xem.
- Danh sách trả hàng `/returns` chưa hỗ trợ lọc theo `seller_key` (người bán của hóa đơn gốc).

## Root cause

- Chưa sinh metadata `return_url`, `drilldown_url`, `drilldown_type` và `drilldown_label` ở backend.
- Frontend component Vue luôn trỏ anchor `:href` tới `child.invoice_url` mà không mở tab mới.
- `OrderReturnController` chưa đón query param `seller_key` và áp dụng filter quan hệ.

## Thay đổi đã làm

1. **Backend - Controllers:**
   - Bổ sung metadata `return_url`, `drilldown_url`, `drilldown_type`, `drilldown_label`, `has_invoices`, `has_returns` cho children rows trong `SalesReportController@buildSalesDailyChildren` và `EmployeeReportController@buildSalesDailyChildren`.
   - Cập nhật `OrderReturnController@index()` để kiểm tra query param `seller_key` và áp dụng `SellerResolver::filterReturnsBySeller` trước khi phân trang.
   - Trả lại `seller_key` trong Inertia props `filters` ở `OrderReturnController`.

2. **Frontend - Vue Components:**
   - Cập nhật `SalesReport.vue` và `EmployeeReport.vue`:
     - Anchor của ngày sử dụng `child.drilldown_url` thay vì `child.invoice_url`.
     - Thêm `target="_blank"` và `rel="noopener noreferrer"`.
     - Thêm title tooltip giải thích link dẫn tới đâu (`Xem hóa đơn` / `Xem phiếu trả hàng`).
     - Hiển thị badge nhỏ màu đỏ `Trả hàng` bên cạnh ngày chỉ có trả hàng.
     - Định dạng cột "Giá trị trả hàng" thành anchor link mở tab mới tới `child.return_url` nếu giá trị trả hàng > 0.

3. **Feature Tests:**
   - Bổ sung 5 tests mới bao quát toàn bộ hành vi drilldown, lọc `seller_key` tại `/returns`, các filter kết hợp (branch, channel), và tính chính xác của metadata URL.

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Không sửa/xóa/thêm bất kỳ row hóa đơn hay trả hàng nào trong cơ sở dữ liệu.
- Không thay đổi công thức tính doanh thu/trả hàng/net.

## Tests đã chạy

- Command:
  ```bash
  php artisan test tests/Feature/Reports/SalesReportEmployeeDailyBreakdownTest.php
  php artisan test tests/Feature/Reports/EmployeeReportDailyBreakdownTest.php
  ```
- Result:
  - `tests/Feature/Reports/SalesReportEmployeeDailyBreakdownTest.php`: 14 passed (97 assertions)
  - `tests/Feature/Reports/EmployeeReportDailyBreakdownTest.php`: 8 passed (49 assertions)

## Manual QA

- Ngày bán hàng: Drilldown sang `/invoices` ở tab mới, lọc đúng ngày và seller, có kết quả.
- Ngày chỉ có trả hàng: Drilldown sang `/returns` ở tab mới, lọc đúng ngày và seller, có kết quả.
- Cột giá trị trả: Wrap link sang `/returns` ở tab mới, lọc đúng ngày và seller.
- Mở tab mới: Đã xác nhận hoạt động chính xác với `target="_blank"` và `rel="noopener noreferrer"`.
- Filter branch/channel: Link `/returns` và `/invoices` đều truyền đầy đủ filter.
- Regression concern khác: Không lỗi, biểu đồ và các dạng báo cáo khác hoạt động bình thường.

## Rủi ro còn lại

- **Sales Channel Filter:** Vì `returns.sales_channel` trong cơ sở dữ liệu hiện tại hầu hết là `null`, việc lọc `/returns?sales_channel=...` ở danh sách trả hàng (sử dụng filter cột trực tiếp `returns.sales_channel` trên table) có thể không trả về kết quả khớp với invoice gốc. Tuy nhiên, theo yêu cầu, chúng ta không can thiệp/sửa đổi schema hay backfill dữ liệu cũ trong hotfix này. Việc đồng bộ hoặc query qua relation cho cột này của returns index cần được triển khai riêng.

## Kết luận

- Đạt/chưa đạt: Đạt.
- Có thể deploy chưa: Có thể deploy ngay.
