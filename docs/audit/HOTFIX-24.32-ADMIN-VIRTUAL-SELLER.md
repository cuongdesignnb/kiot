# HOTFIX 24.32 — Admin Virtual Seller

## 1. Vấn đề

- Trước 24.32, `SellerResolver::buildInvoiceSellerOptions()` chỉ trả `Employee::where('is_active', true)`. Hệ quả: user quyền cao nhất (vd `Trần Văn Tiến / admin@kiotviet.vn`) không xuất hiện trong dropdown Người bán nếu chưa có employee linked.
- Yêu cầu mới: không bắt buộc tạo/link employee cho Admin. User quyền cao nhất phải có thể được chọn làm Người bán mà không cần phụ thuộc bảng `employees`.

## 2. Contract

```txt
invoices.created_by      = seller employee id (nếu seller là employee)
invoices.seller_name     = seller snapshot (lưu tên seller dù là employee hay admin)
invoices.created_by_name = creator snapshot — KHÔNG dùng làm seller
```

`admin_user:<id>` virtual seller (HOTFIX 24.32):

```txt
created_by  = NULL
seller_name = <user.name hiện tại>
```

Khi resolve: `invoiceSellerMap()` → `snapshot:<seller_name>` (cùng pipeline với mọi snapshot khác). Filter từ FE với `admin_user:<id>` được map sang `WHERE created_by IS NULL AND seller_name = user.name`.

## 3. Source đã kiểm tra

- `app/Support/Reports/SellerResolver.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/EmployeeReportController.php`
- `resources/js/Pages/Invoices/Index.vue`
- `app/Models/User.php` (`isAdmin()`: `role_id IS NULL` hoặc role có wildcard `*`)
- `app/Models/Employee.php`
- `app/Models/Role.php`
- `routes/web.php`

## 4. Phương án đã làm

- Thêm `SellerResolver::virtualAdminSellerOptions(array $excludeUserIds)` — quét tất cả user `status=active` và filter bằng `User::isAdmin()` ở PHP layer để khớp đúng định nghĩa admin trong code (role_id null hoặc role có wildcard `*`). User đã có employee active linked thì bỏ qua để tránh duplicate.
- `buildInvoiceSellerOptions()` và `buildSellerFilterOptions()` append virtual admin options sau danh sách employees, dedup theo `user_id` đã được employee active linked phủ.
- `filterBySeller($q, 'admin_user:<id>')` validate user (tồn tại, active, isAdmin) và filter `WHERE created_by IS NULL AND seller_name = user.name`. Nếu user không thoả → `whereRaw('1=0')` (an toàn).
- `normalizeRequestedSellerKey()` nhận thêm prefix `admin_user:`.
- `InvoiceController@updateSeller` thêm nhánh `admin_user:<id>`:
  - Validate `User::isAdmin()` + active.
  - Nếu admin đã có active linked Employee → trả 422 yêu cầu dùng `employee:<id>` (giữ key canonical).
  - Update: `created_by=NULL`, `seller_name=user.name`. **Không** đụng `created_by_name`.
  - Audit log với `action_detail=seller_change_admin_user` + `admin_user_id` + `actor_user_id`.
  - Response trả `seller_key=admin_user:<id>` để FE re-select đúng.
- Frontend `Invoices/Index.vue`: thêm `currentSellerKey(invoice)` ưu tiên `invoice.seller_key` (set sau PATCH), fallback `employee:<created_by>` cho data render lần đầu. Select `:value` gọi helper này. Sau khi PATCH thành công, lưu lại `invoice.seller_key`.
- Snapshot Admin cũ (`seller_name='Admin'`) không tự merge với user `Trần Văn Tiến` — vẫn là `snapshot:Admin` riêng biệt.

## 5. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ hàng loạt | Không |
| Có sửa invoice khi user thao tác không | Có — chỉ qua endpoint manual `PATCH /invoices/{id}/seller` đã có sẵn từ 24.30, với confirm dialog. |
| Recalculate tồn kho / giá vốn / công nợ / cashflow | Không |
| Sửa serials / invoice_items / return_items | Không |

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | `normalizeRequestedSellerKey` + `filterBySeller` nhận `admin_user:<id>`. `buildInvoiceSellerOptions` + `buildSellerFilterOptions` append virtual admin sellers. Tách helper `virtualAdminSellerOptions()`. |
| `app/Http/Controllers/InvoiceController.php` | `updateSeller()` thêm nhánh `admin_user:<id>` (validate isAdmin, reject nếu đã có linked employee, ghi snapshot). |
| `resources/js/Pages/Invoices/Index.vue` | `currentSellerKey()` helper; bind `:value`, lưu `invoice.seller_key` sau PATCH. |
| `tests/Feature/Invoices/HOTFIX2432AdminUserVirtualSellerTest.php` | NEW — 8 TC. |
| `tests/Feature/Reports/HOTFIX2427SellerDuplicateAndInvoiceFilterTest.php` | TC-12 cập nhật theo contract mới — admin xuất hiện với `type=admin_user` (không phải `creator_snapshot`). |
| `tests/Feature/Reports/HOTFIX2428SellerCreatorContractTest.php` | TC-12 tương tự — admin là `admin_user`, không phải `creator_snapshot`. |
| `tests/Feature/Reports/HOTFIX2431EmployeeReportReturnSellerScopeTest.php` | TC-6 đổi sang "non-admin user without employee NOT in options" — admin case đã chuyển sang 24.32 contract. |

## 7. Tests

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2432AdminUserVirtualSellerTest` | ✅ **8 passed / 26 assertions**, 0.87s |
| `php artisan test --filter="HOTFIX2432\|HOTFIX2431\|HOTFIX2430\|HOTFIX2428\|HOTFIX2427\|EmployeeReport\|Invoice\|Report"` | ✅ **158 passed / 2 skipped / 571 assertions**, 35.10s — clean |
| `npm run build` | ✅ **built in 6.64s** |

**8 TC trong `HOTFIX2432AdminUserVirtualSellerTest`:**

1. `super_admin_without_employee_is_virtual_seller_option` — user role_id=null + không employee → option `admin_user:<id>`, display "Name — Admin".
2. `normal_user_is_not_a_virtual_seller_option` — user role thường không xuất hiện.
3. `admin_with_linked_employee_has_no_admin_user_duplicate` — admin có employee active linked → chỉ option `employee:<id>`, không duplicate `admin_user:<id>`.
4. `update_seller_to_admin_user` — PATCH `seller_key=admin_user:<id>` → `created_by=NULL`, `seller_name=user.name`, `created_by_name` không đổi.
5. `reject_admin_user_key_for_normal_user` — PATCH `admin_user:<normal_user_id>` → 422, invoice không đổi.
6. `report_can_filter_by_admin_user` — `/reports/employees?employee_id=admin_user:<id>` ra row `snapshot:<user.name>` đúng doanh thu.
7. `old_admin_snapshot_not_merged_with_renamed_user` — `snapshot:Admin` cũ và `snapshot:Trần Văn Tiến 32C` mới là 2 row riêng.
8. `created_by_name_never_treated_as_seller` — `created_by=NULL + seller_name=NULL + created_by_name=Admin` → `unknown`, không lọt vào admin_user filter.

## 8. Manual QA

- Admin dropdown trong chi tiết Hóa đơn: đăng nhập user quyền cao nhất chưa có employee → thấy option `<Tên> — Admin`.
- Chọn Admin làm seller: confirm → invoice.seller_name = tên user; người tạo (`created_by_name`) không đổi.
- Reload trang `/invoices`: select Người bán mặc định trỏ về option Admin vừa chọn (qua `seller_key` từ response).
- Báo cáo `/reports/employees`: chọn filter Admin → ra hóa đơn vừa đổi; lọc seller cũ → không còn.
- Snapshot Admin cũ (nếu DB còn `seller_name='Admin'` chưa được đổi thủ công): vẫn hiện là `Admin — snapshot người bán` riêng, không gộp vào user `Trần Văn Tiến`.

## 9. Kết luận

- Admin quyền cao nhất đã chọn làm Người bán được: ✅ (không cần tạo/link employee).
- Có ảnh hưởng dữ liệu cũ: Không — chỉ ảnh hưởng hóa đơn mà user manual thao tác qua endpoint `PATCH /invoices/{id}/seller`.
- Snapshot cũ không bị merge bừa: ✅.
- Có thể deploy: ✅
- Commit SHA: `2ee9a2f` — `fix(invoices): allow super admin as virtual seller`
- Push status: chưa push (chờ user xác nhận push to origin/main).
