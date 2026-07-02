# HOTFIX 24.28 — Seller / Creator Data Contract Audit

## 1. Vấn đề
- **Tên user đổi nhưng UI/bộ lọc không đổi ra sao?** Màn hình bộ lọc và báo cáo vẫn hiện tên "Admin" thay vì "Trần Văn Tiến" do đang đọc snapshot lịch sử từ `invoices.created_by_name`.
- **Người bán/người tạo bị lẫn ở đâu?** `SellerResolver` đang coi `created_by_name` (tên người tạo hóa đơn) là tên người bán khi hóa đơn không có seller.
- **Vì sao có nguy cơ sai toàn bộ báo cáo?** Doanh số của hóa đơn đang bị gán nhầm cho "Người tạo" (Admin) thay vì "Người bán" (Nhân viên), dẫn đến sai lệch hoa hồng và báo cáo doanh thu.

## 2. Source đã kiểm tra
- `app/Services/InvoiceSaleService.php`: Nơi lưu dữ liệu seller/creator khi tạo hóa đơn.
- `app/Http/Controllers/PosController.php`: Nơi POS truyền dữ liệu `seller_id` và `created_by_name`.
- `app/Http/Controllers/InvoiceController.php`: Filter hóa đơn.
- `app/Support/Reports/SellerResolver.php`: Logic map người bán cho báo cáo.
- DB Schema (`invoices`, `users`, `employees`).

## 3. Schema hiện tại
| Cột | Có không | Ý nghĩa thực tế | Rủi ro |
|---|---|---|---|
| `created_by` | ✅ Có | **Seller Employee ID** (ID nhân viên bán hàng) | Tên cột gây hiểu lầm là ID người tạo. Rất dễ bị nhầm thành `user_id` của auth. |
| `created_by_name` | ✅ Có | **Creator Name Snapshot** (Tên auth user lúc tạo) | Đang bị `SellerResolver` dùng làm fallback để tìm Người bán → Sai logic. |
| `seller_name` | ✅ Có | **Seller Name Snapshot** (Tên nhân viên bán) | Có nhưng bị `SellerResolver` bỏ qua, không dùng để resolve orphan. |
| `employee_id` | ❌ Không | | |
| `seller_id` | ❌ Không | | |
| `creator_user_id` | ❌ Không | | |
| `seller_employee_id`| ❌ Không | | |

## 4. Data audit
- **Users**: Admin có ID = 1.
- **Employees**: Các nhân viên bán hàng thực tế.
- **Invoices**: Hóa đơn tạo từ POS luôn có `created_by_name = 'Admin'` (hoặc tên auth user). `created_by` chứa ID employee (nếu có chọn ở POS) hoặc NULL (nếu không chọn).
- **Case Admin đổi tên**: Snapshot cũ trong DB vẫn là "Admin". DB không tự cập nhật snapshot.
- **Case ambiguous**: Hóa đơn có `created_by = NULL`, `seller_name = NULL` nhưng `created_by_name = 'Admin'`.

## 5. Data contract kết luận
- **Người bán hiện lấy từ**: `created_by` (employee ID) và `seller_name` (snapshot).
- **Người tạo hiện lấy từ**: `created_by_name` (snapshot). Không có cột lưu ID người tạo.
- **`created_by` thực tế là**: ID của Nhân viên bán hàng (Employee ID).
- **`created_by_name` thực tế là**: Tên của tài khoản đăng nhập tạo hóa đơn (User Name).
- **`seller_name` thực tế là**: Tên của Nhân viên bán hàng.
- **Có đủ dữ liệu để báo cáo chính xác không**: Đủ để sửa logic hiển thị mà không cần migration.

## 6. Root cause
- **Vì sao filter còn tên cũ**: UI đang lấy danh sách seller option bằng cách aggregate từ cột `created_by_name` (chứa snapshot cũ "Admin").
- **Vì sao người bán/người tạo bị lẫn**: Cột `created_by` thực chất là lưu `seller_id`, nhưng dev trước hiểu nhầm theo tên cột và cho rằng nó lưu creator.
- **Vì sao report có thể gán sai người bán**: Khi hóa đơn không được gán nhân viên (ở POS), `created_by` = NULL. `SellerResolver` tự động lấy `created_by_name` (Tên người tạo) để đưa vào bucket orphan, rồi tạo ra một Option người bán có tên là Admin.

## 7. Phương án đã làm (Đề xuất)
- **Seller**: Chỉ dựa vào `created_by` (Employee ID) và `seller_name`. Không bao giờ fallback về `created_by_name`.
- **Creator**: Chỉ dùng `created_by_name` để làm filter "Người tạo" (dạng text snapshot).
- **Snapshot**: Ghi rõ "(Lịch sử)" hoặc "— snapshot" nếu bắt buộc phải hiện tên cũ trong dropdown filter.
- **Unknown seller**: Gom tất cả hóa đơn có `created_by` = NULL và `seller_name` = NULL vào 1 bucket ID là `orphan:unknown`, hiển thị tên là "Chưa xác định người bán".
- **Filter hóa đơn**: Tách riêng filter Người bán (theo SellerResolver) và filter Người tạo (theo `created_by_name`).
- **Báo cáo nhân viên**: Loại bỏ việc gán doanh thu cho "Admin" nếu Admin chỉ là người bấm nút tạo hóa đơn.

## 8. Nếu cần migration/backfill
- **Có cần không**: **Không bắt buộc**. Ta có thể giải quyết bằng cách định nghĩa lại Contract thông qua code (như mục 7).
- **Cần xác nhận trước khi triển khai**: 
  - Đề xuất áp dụng phương án sửa logic code (Phương án 1 trong prompt).
  - Rủi ro duy nhất: Các báo cáo cũ sẽ mất dòng "Admin" (doanh thu chuyển về "Chưa xác định người bán") - nhưng đây mới là con số đúng bản chất nghiệp vụ.

---
Vui lòng xem báo cáo trên và xác nhận để tôi tiến hành sửa code theo Phương án 1 (Chỉ update UI/filter/report).
