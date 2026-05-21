# HOTFIX 24.6J — Customer Sales/Return History Sort Desc

## Phạm vi audit
- Module: Customers.
- Màn hình: Khách hàng > chi tiết khách > tab Lịch sử bán/trả hàng.
- Nghiệp vụ: Hiển thị lịch sử hóa đơn bán hàng và phiếu trả hàng theo thời gian mới nhất trước.
- Rủi ro chính: render hóa đơn trước rồi phiếu trả sau làm sai thứ tự thời gian tổng thể.

## Source đã kiểm tra
- `resources/js/Pages/Customers/Index.vue`
- `app/Http/Controllers/CustomerController.php`
- `routes/web.php`
- Tests liên quan Customers/OrderReturn/Invoices.

## Hiện trạng
- Backend: `CustomerController::salesHistory()` trả 2 mảng `invoices` và `returns`, mỗi mảng đã `orderBy(created_at, desc)` riêng.
- Frontend: tab history render toàn bộ `invoices` trước, sau đó mới render `returns`.
- Database: không thay đổi.
- Permission: không thay đổi.
- Production/deploy: chưa chạy browser QA trong phiên này.

## Root cause
- Frontend không merge 2 nguồn giao dịch trước khi hiển thị.
- Vì vậy phiếu trả hàng mới hơn vẫn nằm dưới các hóa đơn cũ nếu nó thuộc mảng `returns`.

## Có ảnh hưởng dữ liệu đang có không?
- Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không sửa hóa đơn/trả hàng.
- Không sửa công nợ/cashflow.
- Không sửa tồn kho/serial.
- Chỉ thay đổi cách sort/display ở frontend.

## Phương án an toàn
- Giữ nguyên API hiện có để tránh ảnh hưởng backend.
- Thêm helper `getCustomerSalesReturnEntries(customerId)` ở frontend.
- Helper merge `invoices` và `returns`, gắn metadata hiển thị, rồi sort giảm dần theo `created_at`.
- Thay 2 block `v-for` riêng bằng một `v-for` chung.
- Giữ click mở chi tiết hóa đơn qua `showInvoiceDetail(entry.id)`.
- Không thêm click detail cho phiếu trả hàng.

## Không được làm
- Không sửa logic tạo hóa đơn.
- Không sửa logic trả hàng.
- Không sửa `OrderReturnCreationService`.
- Không sửa `CustomerDebtService`.
- Không sửa cashflow/tồn kho/serial.
- Không sửa dữ liệu production.

## Tests bắt buộc
- Static grep:
  - `getCustomerSalesReturnEntries` có trong `Customers/Index.vue`.
  - Trong table history không còn render riêng `salesHistoryData[...].invoices` rồi `salesHistoryData[...].returns`.
- Regression:
  - `php artisan test tests/Feature/Customers`
  - `php artisan test tests/Feature/OrderReturn`
  - `php artisan test tests/Feature/Invoices`
  - `npm run build`

## Manual QA
- Chưa chạy browser QA trong phiên này.
- Cần kiểm tra khách `An-Lê Đức Thọ`:
  - `TH2026052109324156` ngày `21/5/2026` đứng trên.
  - `HD177528993031` ngày `30/3/2026` đứng sau.
  - `HD177518348764` ngày `2/3/2026` đứng sau nữa.
- Cần kiểm tra khách chỉ có hóa đơn, chỉ có trả hàng, empty state và click hóa đơn.

## Kết luận
- Đạt về source change: lịch sử bán/trả đã merge và sort desc ở frontend.
- Chưa kết luận production-ready nếu chưa browser QA.
