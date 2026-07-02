# Báo cáo Kiểm toán: HOTFIX — Hàng hóa: bộ lọc trạng thái và nút Ngừng kinh doanh / Kinh doanh lại

## 1. Nguồn Gốc và Bối cảnh (Background & Root cause)
- **Repo**: `cuongdesignnb/kiot`
- **Màn hình**: `/products`
- **Vấn đề**: Người dùng gặp khó khăn khi tìm kiếm hàng hóa đã ngừng kinh doanh vì mặc định danh sách chỉ hiện hàng hóa đang kinh doanh. Đồng thời, trong chi tiết hàng hóa chỉ có tùy chọn `Cập nhật` và `Xóa hàng` dẫn đến người dùng phải sử dụng chức năng xóa sản phẩm thay vì ngừng kinh doanh, gây ảnh hưởng đến tính toàn vẹn dữ liệu giao dịch cũ.
- **Giải pháp**: 
  - Chuẩn hóa bộ lọc trạng thái hàng hóa ở Sidebar gồm: "Đang kinh doanh" (`active`), "Ngừng kinh doanh" (`inactive`), "Tất cả" (`all`).
  - Mặc định danh sách hiển thị hàng hóa "Đang kinh doanh".
  - Bổ sung nút "Ngừng kinh doanh" / "Kinh doanh lại" đúng nghiệp vụ vào chi tiết sản phẩm.
  - Cảnh báo rõ ràng cho người dùng sự khác biệt giữa "Xóa hàng" và "Ngừng kinh doanh".

---

## 2. Các nguồn đã kiểm tra (Source Checked)
- [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
- [ProductController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/ProductController.php)
- [Product.php](file:///d:/Kiot/kiotviet-clone/app/Models/Product.php)
- [Welcome.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Welcome.vue)
- [HOTFIXProductActiveStatusTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Products/HOTFIXProductActiveStatusTest.php)

---

## 3. Danh sách file thay đổi (Files Changed)
- `routes/web.php`: Đăng ký thêm route POST `/products/{product}/deactivate` và `/products/{product}/activate` kiểm tra quyền `permission:products.edit`.
- `app/Http/Controllers/ProductController.php`: 
  - Chuẩn hóa logic lọc theo trạng thái trong phương thức `index()`.
  - Đảm bảo tham số `status` luôn được trả về trong prop `filters` cho frontend (mặc định là `active`).
  - Thêm phương thức `deactivate` và `activate` để cập nhật cột `is_active` cho duy nhất một sản phẩm và ghi log hoạt động (Activity Log) nếu class tồn tại.
- `resources/js/Pages/Welcome.vue`:
  - Thêm combo-box bộ lọc 3 trạng thái.
  - Hiện badge nhỏ kế bên tên sản phẩm biểu thị trạng thái kinh doanh.
  - Bổ sung nút bấm "Ngừng kinh doanh" (với sản phẩm `is_active !== false`) và "Kinh doanh lại" (với sản phẩm `is_active === false`) đi kèm hộp thoại xác nhận chi tiết.
  - Cập nhật thông điệp cảnh báo rõ ràng khi xóa hàng.
- `tests/Feature/Products/HOTFIXProductActiveStatusTest.php`: Sửa lỗi constraint do database testing không có cột `role_id` và `status` trong bảng `users`, đồng thời cung cấp đầy đủ thông tin bắt buộc khi chèn `purchase_items` ảo.

---

## 4. Cam kết an toàn dữ liệu (Data Safety Commitments)
- **Có migration không**: Không. Không tạo/sửa đổi bất kỳ bảng hay schema database nào.
- **Có backfill không**: Không. Không chạy bất kỳ tập lệnh cập nhật dữ liệu hàng loạt nào.
- **Có update dữ liệu cũ không**: Không cập nhật hàng loạt. Chỉ thực hiện update cột `is_active` (`true`/`false`) cho duy nhất một bản ghi sản phẩm khi người dùng kích hoạt thao tác thủ công và đồng ý qua hộp thoại xác nhận.
- **Có xóa dữ liệu không**: Không. Không thực hiện xóa cứng hay thay đổi SKU/barcode/tên sản phẩm.
- **Có ảnh hưởng tồn kho/giá vốn/serial không**: Không. Hoàn toàn không đụng chạm đến tồn kho vật lý, giá vốn bình quân di động, serial/IMEI, hoặc lịch sử giao dịch hóa đơn/nhập/trả hàng.

---

## 5. Kết quả kiểm thử & Biên dịch (Test & Build Results)

### Kiểm thử tự động (Automated Tests)
Cả hai bộ kiểm thử chuyên biệt và kiểm thử hồi quy tồn kho đều chạy thành công tốt đẹp:
1. `php artisan test --filter=HOTFIXProductActiveStatusTest`
   - **Kết quả**: PASS (7 tests passed, 22 assertions)
2. `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`
   - **Kết quả**: PASS (5 tests passed, 12 assertions)

### Biên dịch Assets (Vite Production Build)
- `npm run build`
  - **Kết quả**: Thành công không gặp lỗi biên dịch (built in 8.99s). Bundle `Welcome-DgmbXbU9.js` được tạo thành công với kích thước 55.17 kB.

---

## 6. Hướng dẫn kiểm tra thủ công (Manual QA Guide)
1. Đăng nhập với quyền quản trị viên, đi tới `/products`.
2. Xác minh bộ lọc trạng thái mặc định được chọn là **"Đang kinh doanh"**.
3. Chọn một sản phẩm đang kinh doanh và bấm **"Ngừng kinh doanh"**. Xác nhận thông báo. Sản phẩm đó phải lập tức biến mất khỏi danh sách mặc định.
4. Chuyển bộ lọc trạng thái sang **"Ngừng kinh doanh"**, tìm lại sản phẩm đó và kiểm tra hiển thị badge màu xám "Ngừng kinh doanh".
5. Bấm nút **"Kinh doanh lại"** trong chi tiết sản phẩm. Sản phẩm phải trở về danh sách "Đang kinh doanh".
6. Chọn bộ lọc **"Tất cả"** để xem song song cả hai trạng thái.
7. Đảm bảo toàn bộ thẻ kho, lịch sử chứng từ và tồn kho/giá vốn của sản phẩm ngừng kinh doanh vẫn hoạt động chính xác và mở bình thường.

---

## 7. Rủi ro còn lại & Khuyến nghị (Residual Risks & Recommendations)
- **Rủi ro khi ẩn sản phẩm ngừng kinh doanh ở POS/API giao dịch**: Hiện tại, POS và các màn hình giao dịch khác chỉ lọc tìm kiếm các sản phẩm có `is_active` hợp lệ (không chứa sản phẩm inactive). Nếu trong tương lai cần thắt chặt hơn hoặc cho phép giao dịch lại với hàng ngừng kinh doanh (ví dụ: thanh lý nốt tồn kho), cần rà soát kỹ lưỡng các API search giao dịch để tránh ảnh hưởng diện rộng.
- **Khuyến nghị**: Đối với môi trường Production, luôn backup database trước khi deploy code hotfix mới mặc dù các thay đổi hoàn toàn an toàn và không thay đổi cấu trúc dữ liệu.
