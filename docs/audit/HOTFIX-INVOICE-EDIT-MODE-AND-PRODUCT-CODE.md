# HOTFIX — Invoice edit mode and product code display

## Phạm vi

- Module: Hóa đơn / Bán hàng / Đặt hàng
- Màn hình:
  - `/invoices`
  - `/invoices/{invoice}/show`
  - `/orders/create?action=edit&invoice_id={invoiceId}`
- Nghiệp vụ:
  - Xem chi tiết hóa đơn.
  - Chỉnh sửa hóa đơn.
  - Restore đúng tab/mode bán hàng khi sửa.
- Rủi ro:
  - Sửa hóa đơn ảnh hưởng tồn kho, giá vốn, serial/IMEI, công nợ khách, sổ quỹ.

## Source đã kiểm tra

- Route: `routes/web.php`
- Controller:
  - `app/Http/Controllers/InvoiceController.php`
  - `app/Http/Controllers/OrderController.php`
- Service:
  - `app/Services/InvoiceUpdateService.php`
  - `app/Services/InvoiceSaleService.php`
  - `app/Services/MovingAvgCostingService.php`
  - `app/Services/StockMovementService.php`
- Frontend:
  - `resources/js/Pages/Invoices/Index.vue`
  - `resources/js/Pages/Invoices/Show.vue`
  - `resources/js/Pages/Orders/Create.vue`
- Model:
  - `app/Models/Invoice.php`
  - `app/Models/InvoiceItem.php`
  - `app/Models/Product.php`
  - `app/Models/Role.php`
  - `app/Models/SerialImei.php`
- Test:
  - `tests/Feature/Invoice/InvoiceEditRouteTest.php`

## Hiện trạng trước sửa

- Ở màn chi tiết hóa đơn `/invoices/{id}/show` và danh sách expand, cột **Mã hàng** đang bị trống vì Backend map từ `product.code`, trong khi database chỉ lưu mã hàng ở `sku` và `barcode` (`code` không tồn tại hoặc rỗng).
- Khi bấm **Chỉnh sửa hóa đơn**, hệ thống mở sang `/orders/create?action=edit&invoice_id=...`, nhưng do `isDelivery` luôn mặc định là `true` trong tab setup, hệ thống tự động nhảy sang bán giao hàng và ẩn đi các trường giao hàng đã lưu cũ (chỉ hiển thị panel Ahamove trống).
- Khi bấm **Cập nhật hóa đơn** hiện bị lỗi `405 Method Not Allowed` do frontend gửi request `PUT /invoices/{id}` nhưng route này chưa được đăng ký trong backend `routes/web.php`.

## Root cause

- Thiếu fallback `sku/code/barcode` cho `product_code` mapping ở backend và frontend.
- Thiếu route `PUT /invoices/{invoice}` cho `InvoiceController@update`.
- `Orders/Create.vue` mặc định `isDelivery = true` và thiếu gán các trường giao hàng đã lưu từ hóa đơn vào active tab state.
- Giao diện chỉnh sửa chưa tự ẩn/hiện panel giao hàng và đối tác Ahamove phù hợp với mode giao hàng/bán thường.

## Thay đổi đã làm

- Sửa product_code mapping trong `InvoiceController::show()` và `detail()` sử dụng fallback chain: `sku ?: code ?: barcode ?: ''`.
- Sửa hiển thị mã hàng ở `Show.vue` và `Index.vue` với fallback tương ứng.
- Đăng ký route `PUT /invoices/{invoice}` trỏ về `InvoiceController@update` và phân quyền qua middleware `permission:invoices.edit`.
- Khai báo permission `invoices.edit` trong `Role.php` và command `GrantSensitivePermissions.php`.
- Cập nhật `Orders/Create.vue` để:
  - Đặt `isDelivery: false` mặc định khi khởi tạo tab.
  - Cập nhật `selectInvoiceForEdit(invoice)` để restore đúng mode (`is_delivery`), điền đầy đủ các trường thông tin người nhận, SĐT, địa chỉ, COD, phí giao hàng, kích thước/trọng lượng, bưu tá note.
  - Cho phép người dùng chuyển đổi tab "Bán thường" và "Bán giao hàng" bằng cách nhấn chuột, cập nhật trực quan qua các class active CSS.
  - Bọc các trường người nhận và panel giao hàng Ahamove bằng `v-show="activeTab.isDelivery"` và `v-if="activeTab.isDelivery"` để tự động ẩn khi ở chế độ Bán thường.
  - Bổ sung nút "CẬP NHẬT HÓA ĐƠN" / "ĐẶT HÀNG" phụ ở dưới cùng Middle Side khi ở chế độ Bán thường và ẩn hoàn toàn cột 3.
  - Cập nhật tiêu đề trang động và icon xe tải trên tab dựa vào trạng thái giao hàng.
- Viết 11 test cases bao quát toàn bộ yêu cầu trong `tests/Feature/Invoice/InvoiceEditRouteTest.php`.

## Có ảnh hưởng dữ liệu đang có không?

- Hiển thị mã hàng: Không.
- Thêm route sửa hóa đơn: Có thể ảnh hưởng dữ liệu khi người dùng sửa hóa đơn thật, nhưng do đi qua `InvoiceUpdateService` nên các validation, tồn kho, giá vốn, công nợ được đảm bảo đúng đắn.
- Không migration.
- Không backfill.
- Không thay đổi thủ công dữ liệu cũ.

## Tests đã chạy

- Command: `./vendor/bin/phpunit tests/Feature/Invoice/InvoiceEditRouteTest.php`
- Result: `OK (11 tests, 99 assertions)`

## Manual QA

- Mã hàng ở `/invoices/{id}/show`: Hiển thị đúng mã hàng (SKU) thay vì bị trống.
- Mã hàng ở `/invoices` expand: Hiển thị đúng mã hàng (SKU) trong bảng chi tiết sản phẩm.
- Edit delivery invoice: Khôi phục đúng mode "Bán giao hàng", hiển thị đầy đủ thông tin người nhận đã lưu, phí giao hàng, Ahamove panel. Tiêu đề hiển thị "Sửa giao hàng HD...".
- Edit normal invoice: Khôi phục đúng mode "Bán thường", ẩn panel Ahamove và thông tin người nhận. Tiêu đề hiển thị "Sửa HĐ HD...".
- Update invoice no 405: Nhấn Cập nhật hóa đơn hoạt động bình thường, gọi PUT request thành công, không còn lỗi 405.
- Return invoice flow: Phiên trả hàng vẫn hoạt động đúng như trước.

## Rủi ro còn lại

- Sửa hóa đơn trong quá khứ có thể làm thay đổi giá vốn, số lượng tồn kho của sản phẩm (được quản lý bởi `InvoiceUpdateService` rollback và apply).
- Nếu dữ liệu cũ bị thiếu các trường người nhận, giao diện sẽ hiển thị trống các trường này nhưng không gây lỗi crash.

## Kết luận

- Đạt.
- Có thể deploy lên production.
