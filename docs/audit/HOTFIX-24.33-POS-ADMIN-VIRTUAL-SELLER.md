# HOTFIX 24.33 — POS Admin Virtual Seller

## 1. Vấn đề

- Trên màn hình POS, dropdown Người bán không có `Trần Văn Tiến`.
- Source: `PosController@index()` chỉ truyền `Employee::where('is_active', true)` cho prop `employees`. POS Vue render `v-for="emp in employees"`. User `Trần Văn Tiến` không có active employee record (hoặc không linked) → không xuất hiện.
- Checkout/quickOrder validate `employee_id => exists:employees,id` nên không thể gửi user id thuần.
- HOTFIX 24.32 đã thêm virtual `admin_user:<id>` cho chi tiết hóa đơn nhưng POS chưa dùng cùng cơ chế.

## 2. Source đã kiểm tra

- `app/Http/Controllers/PosController.php` (`index`, `checkout`, `quickOrder`)
- `resources/js/Pages/POS/Index.vue` (dropdown + draft localStorage + payload)
- `app/Support/Reports/SellerResolver.php` (đã có `buildInvoiceSellerOptions` từ 24.30/24.32)
- `app/Services/InvoiceSaleService.php` (`buildInvoiceAttributes` — `seller_id`/`seller_name`/`created_by_name` đã chuẩn theo contract)
- `app/Models/User.php` (`isAdmin()`: `role_id IS NULL` hoặc role có `*`)
- `app/Models/Role.php`
- `app/Models/Employee.php`
- `routes/web.php`, `routes/api.php`

## 3. Role audit

Không có quyền chạy SELECT trên production data trong session này. Cần tester chạy 2 query SELECT-only ở brief:

- Query #1 trả lời ai có `role_id IS NULL` hoặc role.permissions chứa `*` → đó là user quyền cao nhất theo `User::isAdmin()`. Tổng quan: Vũ Hồng Nhung vẫn admin nếu role `Quản trị hệ thống` có permission `*` (hệ thống không tự đụng vào role/permission).
- Query #2 trả lời employees có linked user nào tới Trần Văn Tiến/Vũ Hồng Nhung/Admin.

Code fix không phụ thuộc kết quả query — dùng đúng `User::isAdmin()` để filter.

## 4. Root cause

- POS chỉ lấy `employees` active.
- User admin không có employee → không hiện.
- Validation `employee_id => exists:employees,id` cũng cản admin user thuần.

## 5. Phương án đã làm

- `PosController@index()`: prop `sellerOptions` mới, build từ `SellerResolver::buildInvoiceSellerOptions()` (đã trả `employee:<id>` + `admin_user:<id>` cho admin chưa có employee active linked).
- Giữ prop legacy `employees` cho backward compat (chưa có ai khác đọc, nhưng không xoá để tránh side-effect).
- `PosController::resolveSellerForPos()` helper riêng — nhận `seller_key` (`admin_user:<id>` hoặc `employee:<id>`), fallback `employee_id` legacy. Trả `[seller_id, seller_name]`. Validate:
  - `admin_user:<id>` → user tồn tại, active, `isAdmin()`, và **chưa** có linked employee active (nếu đã có thì bắt dùng `employee:<id>` để giữ canonical key).
  - `employee:<id>` → employee tồn tại + active.
  - Không match → throw `InvalidArgumentException` → 422.
- `PosController@checkout()`: thêm `seller_key` vào rules; resolve seller qua helper; truyền `seller_id`/`seller_name` cho `InvoiceSaleService` (`InvoiceSaleService::buildInvoiceAttributes` đã set `invoices.created_by = context.seller_id`, `invoices.seller_name = context.seller_name`, `invoices.created_by_name = auth user` — không đụng).
- `PosController@quickOrder()`: tương tự — resolve seller, set `orders.assigned_to_name = sellerName`, `orders.created_by_name = auth user` (giữ rõ creator ≠ seller). QuickOrder không trừ kho/công nợ — vẫn vậy.
- `POS/Index.vue`:
  - `props.sellerOptions: Array`.
  - `selectedSellerKey: ref('')` (canonical). `selectedEmployeeId` thành computed alias chỉ để legacy code/draft đọc.
  - Dropdown render `sellerOptions` với `display_name`.
  - Draft localStorage lưu `selectedSellerKey`; convert legacy `selectedEmployeeId` → `employee:<id>`.
  - Payload `checkout` và `quickOrder` gửi cả `seller_key` (mới) và `employee_id` (legacy) cho backward compat.
- Snapshot Admin cũ (`seller_name='Admin'`) không merge với `Trần Văn Tiến` — vẫn là `snapshot:Admin` riêng.

## 6. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update hóa đơn cũ | Không |
| Thay đổi dữ liệu khi user tạo hóa đơn mới | Có — chỉ với hóa đơn vừa tạo trên POS, đúng theo seller user chọn. Không đụng `created_by_name`. |
| Recalculate tồn kho / giá vốn / công nợ / cashflow | Không |
| Sửa serials / invoice_items / return_items | Không |
| Đổi quyền user / role / permission | Không |

## 7. File đã sửa

| File | Nội dung |
|---|---|
| `app/Http/Controllers/PosController.php` | `index` truyền `sellerOptions`. Helper `resolveSellerForPos()`. `checkout`/`quickOrder` dùng `seller_key` (fallback `employee_id`). |
| `resources/js/Pages/POS/Index.vue` | Props `sellerOptions`. `selectedSellerKey` canonical, `selectedEmployeeId` computed alias. Dropdown + draft + payload sửa theo. |
| `tests/Feature/POS/HOTFIX2433PosAdminVirtualSellerTest.php` | NEW — 9 TC. |

## 8. Tests

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2433PosAdminVirtualSellerTest` | ✅ **9 passed / 31 assertions**, 1.59s |
| `php artisan test --filter="HOTFIX2433\|HOTFIX2432\|HOTFIX2431\|HOTFIX2430\|EmployeeReport\|Invoice\|Report\|POS\|Pos"` | ✅ **188 passed / 2 skipped / 673 assertions**, 46.36s |
| `npm run build` | ✅ **built in 7.56s** |

**9 TC trong `HOTFIX2433PosAdminVirtualSellerTest`:**

1. `pos_seller_options_include_admin_without_employee` — admin role_id=null + không employee → option `admin_user:<id>` với display "Name — Admin".
2. `normal_user_not_in_pos_seller_options` — user role thường không xuất hiện.
3. `admin_with_linked_employee_no_duplicate` — admin có employee active linked → chỉ `employee:<id>`, không duplicate `admin_user:<id>`.
4. `checkout_with_admin_user_writes_snapshot_seller` — POST `/api/pos/checkout` với `seller_key=admin_user:<id>` → invoice `created_by=NULL`, `seller_name=user.name`. `created_by_name` = auth user.
5. `checkout_with_employee_key_writes_employee_seller` — `seller_key=employee:<id>` → invoice `created_by=emp.id`, `seller_name=emp.name`.
6. `checkout_rejects_admin_user_for_normal_user` — `seller_key=admin_user:<normal_user_id>` → 422, không tạo invoice.
7. `quick_order_accepts_admin_user` — `/api/pos/quick-order` với `admin_user` → order `assigned_to_name=user.name`, `created_by_name=auth.user.name`, stock không bị trừ.
8. `created_by_name_never_promoted_to_seller` — invoice với `created_by=NULL+seller_name=NULL+created_by_name=admin name` → resolver trả `unknown`.
9. `report_filter_admin_user_after_pos_checkout` — sau POS checkout admin_user, `/reports/employees?employee_id=admin_user:<id>` thấy row `snapshot:<user.name>` đúng doanh thu.

## 9. Manual QA

- POS dropdown: đăng nhập user quyền cao nhất chưa có employee → thấy option `<Tên> — Admin`.
- Chọn admin seller → tạo hóa đơn → DB thấy `created_by=NULL, seller_name=<Tên user>, created_by_name=<auth user>`.
- `/reports/employees` filter Admin → có HĐ vừa tạo; lọc seller khác → không có.
- Snapshot cũ (`seller_name='Admin'`): vẫn hiện riêng là `Admin — snapshot người bán`, không gộp với user `Trần Văn Tiến`.

## 10. Kết luận

- Admin quyền cao nhất đã xuất hiện trong POS Người bán: ✅ (qua `admin_user:<id>`, không cần Employee).
- Báo cáo nhận seller admin mới: ✅ (`snapshot:<user.name>`, filter qua `admin_user:<id>`).
- Snapshot cũ không bị merge: ✅.
- Quyền cao nhất theo `User::isAdmin()` — không tự đổi role/permission.
- Có thể deploy: ✅
- Commit SHA: `509e087` — `fix(pos): allow super admin as virtual seller`.
- Push status: chưa push (chờ user xác nhận push to origin/main).
