# HOTFIX — VN date picker, damage date layout, product report 404

## Phạm vi

- Module: Báo cáo (Reports) & Xuất hủy (Damages).
- Màn hình:
  - `/reports/employees`
  - `/reports/sales`
  - `/reports/products`
  - `/reports/customers`
  - `/reports/suppliers`
  - `/damages/create`
- Nghiệp vụ:
  - Chuẩn hóa ngày chọn lọc trên báo cáo theo chuẩn hiển thị Việt Nam (`dd/MM/yyyy`).
  - Sửa lỗi layout ô datetime picker trong Xuất hủy không bị co/kéo, hiển thị gọn gàng.
  - Sửa lỗi điều hướng trang báo cáo hàng hóa gây 404 (do gọi sai route `/reports/products-report` thay vì `/reports/products`).
- Rủi ro chính:
  - Nếu date picker bị lỗi hoặc parse sai định dạng có thể dẫn đến lọc sai/lệch dữ liệu báo cáo trên UI.

## Source đã kiểm tra

- Date utils: `resources/js/utils/dateTime.js` (Hợp quy format và parse độc lập locale).
- DatePicker: `resources/js/Components/DatePicker.vue` (Component mới tạo cho lọc ngày).
- DateTimePicker: `resources/js/Components/DateTimePicker.vue` (Đã thêm prop `wrapperClass`).
- EmployeeReport.vue: `resources/js/Pages/Reports/EmployeeReport.vue` (Tích hợp `DatePicker`).
- SalesReport.vue: `resources/js/Pages/Reports/SalesReport.vue` (Tích hợp `DatePicker`).
- ProductReport.vue: `resources/js/Pages/Reports/ProductReport.vue` (Tích hợp `DatePicker` + Fix route).
- CustomerReport.vue: `resources/js/Pages/Reports/CustomerReport.vue` (Tích hợp `DatePicker`).
- SupplierReport.vue: `resources/js/Pages/Reports/SupplierReport.vue` (Tích hợp `DatePicker`).
- Create.vue (Damages): `resources/js/Pages/Damages/Create.vue` (Tối ưu layout wrapper).
- DamageController.php: `app/Http/Controllers/DamageController.php` (Thêm validation strict cho `action_date`).

## Hiện trạng trước sửa

- Các báo cáo nhân viên, bán hàng, hàng hóa, khách hàng, nhà cung cấp sử dụng native `<input type="date">`, dẫn đến định dạng hiển thị phụ thuộc locale trình duyệt (như `MM/DD/YYYY`).
- Ô Ngày hủy trên panel tạo phiếu xuất hủy có kích thước co kéo chưa cân đối, gây khó nhìn.
- Bấm các nút điều hướng hoặc filter ở trang Báo cáo hàng hóa gửi request lên `/reports/products-report` (gây 404 do route đúng là `/reports/products`).

## Root cause

- Thiếu component `DatePicker.vue` tùy chỉnh hiển thị chuẩn Việt Nam giống `DateTimePicker.vue`.
- Component `DateTimePicker` có layout wrapper cứng (`w-full`), chưa hỗ trợ truyền `wrapperClass` từ ngoài.
- `ProductReport.vue` gọi sai API/router endpoint.

## Thay đổi đã làm

1. **DatePicker Component:**
   - Tạo mới `resources/js/Components/DatePicker.vue` sử dụng format chuẩn hiển thị `dd/MM/yyyy` và submit `yyyy-MM-dd`.
2. **DateTimePicker Component:**
   - Bổ sung prop `wrapperClass` để cho phép tùy chỉnh chiều rộng bên ngoài.
3. **Reports Integration:**
   - Thay thế toàn bộ `<input type="date">` thành `<DatePicker>` tại:
     - `EmployeeReport.vue`
     - `SalesReport.vue`
     - `ProductReport.vue`
     - `CustomerReport.vue`
     - `SupplierReport.vue`
4. **Damages Layout & Parsing:**
   - Căn chỉnh wrapper class của `DateTimePicker` trong `Damages/Create.vue` thành `w-[190px] shrink-0` để hiển thị vừa vặn trong sidebar.
   - Thêm validation strict `'action_date' => 'nullable|date'` tại `DamageController@store`.
5. **Product Report 404:**
   - Cập nhật route gọi ở `ProductReport.vue` thành `/reports/products` thay cho `/reports/products-report`.

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Dữ liệu xuất hủy và các dữ liệu báo cáo cũ hoàn toàn an toàn và không bị biến đổi.

## Tests đã chạy

- Command:
  ```bash
  php artisan test tests/Feature/Reports/ProductReportRouteTest.php
  php artisan test tests/Feature/Damage/DamageActionDateTest.php
  ```
- Result: **PASS** (Tất cả các test case đều đã kiểm thử thành công).

## Manual QA

- **Employee report date**: Hiển thị text dạng `dd/MM/yyyy` (ví dụ `01/04/2026`), truyền payload lên backend đúng dạng `yyyy-MM-dd`.
- **Sales report date**: Hoạt động bình thường, hiển thị dạng `dd/MM/yyyy`.
- **Product report route**: Đổi các view và filter chuyển hướng mượt mà, giữ nguyên url `/reports/products`, không bị 404.
- **Customer/Supplier report**: Định dạng ngày đã được cập nhật thành `DatePicker` chuẩn Việt Nam.
- **Damage date layout**: Ô chọn thời gian ngày hủy hiển thị vừa vặn, chuẩn nét `dd/MM/yyyy HH:mm`, không bị cắt hay nhảy dòng.
- **Damage action_date**: Truyền đúng chuỗi datetime, lưu chính xác `created_at` và `destroyed_date` trong DB.

## Production check

- `php artisan route:list | Select-String -Pattern "reports/products"`
- `npm run build`: Hoàn thành không lỗi biên dịch.
- `php artisan optimize:clear`: Nên thực hiện sau khi deploy để cập nhật cache route mới.

## Rủi ro còn lại

- Một số trang quản lý chứng từ khác có thể vẫn còn native date input, cần các task sau quét sạch. Hotfix này đã giải quyết triệt để toàn bộ phần báo cáo được chỉ định.

## Kết luận

- Đạt/chưa đạt: **Đạt**
- Có thể deploy chưa: **Có thể deploy ngay**
