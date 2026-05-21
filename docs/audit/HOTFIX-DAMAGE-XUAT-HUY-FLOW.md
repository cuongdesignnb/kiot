# HOTFIX - Damage / Xuất hủy flow

## Phạm vi audit
- Module: Xuất hủy hàng hóa (`Damage`).
- Màn hình: `/damages`, `/damages/create`, in phiếu và export danh sách.
- Nghiệp vụ: tạo phiếu tạm, hoàn thành xuất hủy, chọn serial/IMEI, hủy phiếu rollback.
- Rủi ro chính: tồn kho, giá vốn bình quân, serial/IMEI, thẻ kho.

## Source đã kiểm tra
- `routes/web.php`
- `app/Http/Controllers/DamageController.php`
- `app/Models/Damage.php`
- `app/Models/DamageItem.php`
- `app/Enums/DamageStatus.php`
- `app/Models/Role.php`
- `resources/js/Pages/Damages/Create.vue`
- `resources/js/Pages/Damages/Index.vue`
- `app/Services/MovingAvgCostingService.php`
- `app/Services/StockMovementService.php`
- `tests/Feature/Damage/RR09DamageStockTest.php`

## Hiện trạng
- Backend đã có route `/damages/export`, route tạo, route hủy, print/show.
- Permission `damages.export` đã có trong permission map, không cần thêm DB/seeder trong hotfix này.
- `DamageController@store` đã validate serial, trừ tồn qua `MovingAvgCostingService`, ghi stock movement `adjust_out`, chuyển serial đã chọn sang `defective`.
- `DamageController@cancel` đã rollback tồn qua `adjust_in`, trả serial đã chọn về `in_stock`, chặn phiếu serial legacy thiếu snapshot.
- Frontend tạo phiếu lỗi runtime vì dùng `watch` nhưng chưa import từ Vue.
- Frontend tạo phiếu chưa có UI chọn `serial_ids`, trong khi backend yêu cầu với phiếu completed của hàng serial.
- Detail trên danh sách có các nút `Sao chép`, `Xuất file`, `Lưu` chưa có handler thật.
- Màn tạo phiếu đang hardcode người xuất hủy thay vì cho chọn nhân viên/current user.

## Root cause
- `Create.vue` thiếu import `watch`, làm trang tạo phiếu có thể lỗi runtime.
- `Create.vue` chỉ gửi `product_id` và `qty`, không có selector serial/IMEI cho hàng `has_serial`.
- UI chi tiết hiển thị các action chưa hoàn thiện, tạo cảm giác chức năng đã hoạt động dù chưa có handler.

## Thay đổi
- Sửa import `watch` trong `Create.vue`.
- Thêm serial selector dùng endpoint sản phẩm `/products/{product}/serials?status=ready`.
- Serial selector có fallback sang API read-only `/api/products/{product}/serials`, timeout và nút `Tải lại` để không kẹt loading khi endpoint chính lỗi.
- Payload tạo phiếu gửi `items[].serial_ids`.
- Thêm chọn nhân viên xuất hủy bằng select ổn định, gửi `employee_id` về backend.
- Backend lưu `created_by_name`/`destroyed_by_name` theo nhân viên đã chọn hoặc current user fallback; `action_date` tiếp tục điều khiển `created_at`/`destroyed_date`.
- Nút xóa sản phẩm trên dòng hàng luôn hiển thị, không chỉ hiện khi hover.
- Frontend validate:
  - không cho lưu khi chưa chọn hàng;
  - không cho thiếu chi nhánh;
  - số lượng phải lớn hơn 0;
  - số lượng không vượt tồn kho;
  - completed + hàng serial phải chọn đúng số serial/IMEI bằng số lượng hủy.
- Table tạo phiếu render từ state thật thay vì computed copy để số lượng nhập được ghi đúng.
- Disable các nút detail chưa hoàn thiện: `Sao chép`, `Xuất file` từng phiếu, `Lưu`; giữ nút `Hủy phiếu` và `In`.
- Thêm test export route CSV cho `/damages/export`.

## Data safety
- Có migration không: Không.
- Có backfill không: Không.
- Có update dữ liệu cũ không: Không.
- Có xóa dữ liệu không: Không.
- Có sửa trực tiếp `products.stock_quantity` ngoài service không: Không.
- Có sửa serial data trong hotfix code không: Không, chỉ dùng logic hiện có khi tạo/hủy phiếu thật.
- Rollback plan: revert commit. Vì hotfix chỉ sửa UI/test/report và thêm test export, không có thay đổi schema hay backfill.

## Ảnh hưởng nghiệp vụ
- Giao dịch mới completed vẫn đụng tồn kho, giá vốn bình quân, serial và stock movements theo logic backend hiện có.
- Draft không trừ tồn.
- Hủy phiếu completed rollback theo logic backend hiện có.
- Không thay đổi dữ liệu production cũ.

## Tests
- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`: PASS, 5 tests, 12 assertions.
- `php artisan test tests/Feature/Damage/DamageExportRouteTest.php`: PASS, 1 test, 4 assertions.
- `php artisan test tests/Feature/Damage/DamageCreateMetaTest.php`: PASS, 1 test, 5 assertions.
- `php artisan test tests/Feature/Damage/DamageIndexFilterTest.php`: PASS, 2 tests, 32 assertions.
- `php artisan test tests/Feature/Damage`: PASS, 26 tests, 111 assertions.
- `npm run build`: PASS, Vite built successfully in 6.93s.

Ghi chú môi trường test: PHP local có warning thiếu extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; các warning này không làm fail test.

## Manual QA checklist
- Mở `/damages/create` không lỗi console.
- Tạo draft hàng thường.
- Tạo completed hàng thường.
- Tạo completed hàng serial, chọn đúng serial.
- Thử completed hàng serial không chọn serial, backend/frontend phải chặn.
- Hủy phiếu completed, tồn/serial rollback.
- Export danh sách.
- Print phiếu.

Manual browser QA chưa chạy trong môi trường này.

## Remaining risks
- Clone phiếu xuất hủy chưa được triển khai trong hotfix này, nút đã disable để tránh UI giả.
- Export từng phiếu riêng chưa triển khai, danh sách vẫn dùng `/damages/export`.
- Nếu production thiếu migration `damage_items.serial_ids`, cần backup DB và chạy migration đã có theo quy trình deploy; hotfix này không tự chạy migration.

## Production readiness
- Có thể deploy sau khi tests và `npm run build` pass.
- Trước production cần kiểm tra route cache/build mới và xác nhận không có migration pending ngoài ý muốn.
