# HOTFIX 24.30 — Seller Consistency & Invoice Seller Update

## 1. Vấn đề

Hai vấn đề liên quan trực tiếp:

1. **Báo cáo nhân viên lệch với Settings User.**
   - `/reports/employees` vẫn có dòng `Admin`.
   - Settings > Tài khoản người dùng không còn user `Admin`; user admin đã đổi tên thành `Trần Văn Tiến`.
   - Settings hiển thị bảng `users`. Báo cáo hiển thị seller từ `employees` + `invoices`. Nếu `employees.name` không tự đồng bộ theo `users.name` thì lệch là đúng theo DB hiện tại, nhưng sai kỳ vọng vận hành.

2. **Chi tiết Hóa đơn không đổi được Người bán.**
   - Trong `/invoices`, mở chi tiết hóa đơn, field `Người bán` là select nhưng chỉ render 1 option (seller hiện tại + fallback hardcode `"Trần Văn Tiến"`).
   - Không có endpoint để cập nhật seller cho hóa đơn đã tạo.

## 2. Source đã kiểm tra

- `app/Support/Reports/SellerResolver.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/EmployeeReportController.php`
- `app/Models/User.php`, `app/Models/Employee.php`, `app/Models/Invoice.php`
- `app/Models/Role.php`
- `app/Http/Middleware/CheckPermission.php`
- `resources/js/Pages/Invoices/Index.vue`
- `routes/web.php`
- Audit doc cũ: `HOTFIX-24.28-SELLER-CREATOR-DATA-CONTRACT.md`, `HOTFIX-24.28B-SELLER-CREATOR-CONTRACT-CODE-FIX.md`

## 3. Data contract hiện tại (sau HOTFIX 24.28B)

```txt
invoices.created_by      = seller employee id (nullable cho legacy/snapshot)
invoices.seller_name     = seller name snapshot
invoices.created_by_name = creator name snapshot (auth user name) — NOT seller
```

`created_by_name` không bao giờ được dùng làm người bán.

## 4. Root cause

### 4.1. Dòng `Admin` trong báo cáo

Có 3 nguồn có thể (tuỳ data thực tế):

- **(a) Employee linked user** — `employees.name='Admin'`, `employees.user_id=<id>`, `users.name='Trần Văn Tiến'`. Đây là khả năng cao nhất khi user đã đổi tên trên Settings nhưng employee record không tự update.
- **(b) Employee độc lập** — `employees.name='Admin'`, `user_id=NULL`. Báo cáo đang đúng theo DB.
- **(c) Snapshot seller** — `invoices.created_by IS NULL` + `invoices.seller_name='Admin'`. Đây là hóa đơn legacy.

Trước HOTFIX 24.30, `SellerResolver::sellerMeta()` và `buildSellerFilterOptions()` chỉ trả `employees.name`, nên nguồn (a) hiện ra là `Admin` dù user đã đổi tên.

### 4.2. Dropdown Người bán chi tiết hóa đơn chỉ có 1 option

`resources/js/Pages/Invoices/Index.vue` hardcode `<option>{{ invoice.seller_name || "Trần Văn Tiến" }}</option>`. Không gọi backend, không có danh sách employee.

### 4.3. Không có endpoint đổi seller

`InvoiceController` chưa có route/method nào cho phép sửa riêng người bán; `update()` hiện có yêu cầu items[] đầy đủ và đi qua `InvoiceUpdateService` (tái chạy logic kho/giá vốn) — không phù hợp cho thao tác “chỉ đổi người bán”.

## 5. Phương án chọn — Hướng A (display-based, không update DB)

- Trong `SellerResolver::sellerMeta()` và `buildSellerFilterOptions()`: nếu `employees.user_id` set và user active → display `users.name`. Key vẫn là `employee:<id>` (không đổi data).
- Thêm `SellerResolver::buildInvoiceSellerOptions()` trả toàn bộ active employees (cùng quy ước display) để frontend dropdown đầy đủ option.
- Thêm route `PATCH /invoices/{invoice}/seller` + `InvoiceController@updateSeller`: chỉ cập nhật `created_by` và `seller_name` cho từng hóa đơn theo thao tác manual của user có quyền.
- Frontend gọi PATCH với confirm dialog, cập nhật UI in-place.

**Không** update hàng loạt, **không** backfill, **không** migration, **không** đổi `created_by_name`, **không** sửa snapshot legacy, **không** merge user/employee.

Snapshot/unknown bucket vẫn được giữ riêng (không merge bừa sang user `Trần Văn Tiến`).

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | `sellerMeta()` + `buildSellerFilterOptions()` — Hướng A: display theo linked user name. Thêm `buildInvoiceSellerOptions()` cho dropdown chi tiết hóa đơn. |
| `app/Http/Controllers/InvoiceController.php` | Thêm `updateSeller()`. `index()` truyền thêm `filterOptions.invoiceSellerOptions`. |
| `routes/web.php` | Thêm `PATCH /invoices/{invoice}/seller` với middleware `permission:invoices.cancel`. |
| `resources/js/Pages/Invoices/Index.vue` | Dropdown Người bán load `invoiceSellerOptions`, gọi PATCH, confirm dialog, disable trên invoice đã hủy. Bỏ fallback hardcode `"Trần Văn Tiến"`. |
| `tests/Feature/Invoices/HOTFIX2430SellerConsistencyAndInvoiceSellerUpdateTest.php` | NEW — 14 TC. |

## 7. API update seller

- **Route**: `PATCH /invoices/{invoice}/seller` → `InvoiceController@updateSeller`
- **Permission middleware**: `permission:invoices.cancel` (gần nhất với edit invoice; admin role_id=null bypass mọi check).
- **Body**: `seller_key` — chỉ chấp nhận `employee:<id>` (rejects `creator_snapshot:*`, `snapshot:*`, `unknown`, numeric bare).
- **Validation**:
  - Invoice không bị hủy.
  - Employee tồn tại và `is_active=true`.
  - Time-lock check theo `order_change_time` setting — block nếu quá hạn và user không có `invoices.override_time_lock`.
- **Fields updated**: `invoices.created_by`, `invoices.seller_name` (snapshot = `employees.name`, không dùng linked user name để giữ tính lịch sử của seller_name).
- **Fields NOT updated**: `invoices.created_by_name` (creator snapshot).
- **Audit log**: `ActivityLog::ACTION_INVOICE_UPDATE` với `action_detail='seller_change'` + old/new `created_by`/`seller_name`/`employee_code`.

## 8. Có ảnh hưởng dữ liệu không?

| Loại | Có/Không |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update DB hàng loạt | Không |
| Tự đổi `employees.name` | Không |
| Tự link `employees.user_id` | Không |
| Tự update `invoices.created_by_name` | Không |
| Update từng invoice manual (user thao tác qua UI) | ✅ Có — qua endpoint mới, chỉ khi user có quyền và confirm. |
| Cần xác nhận trước khi triển khai | Không — Hướng A không tự đụng data cũ. |

## 9. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2430SellerConsistencyAndInvoiceSellerUpdateTest` | ✅ **14 passed / 44 assertions**, 1.32s |
| `php artisan test --filter="HOTFIX2428SellerCreatorContractTest\|Invoice\|EmployeeReport\|Report\|CashFlow"` | ✅ **148 passed / 2 skipped / 529 assertions**, 35.94s |
| `npm run build` | ✅ **built in 8.25s** |

**14 TC trong `HOTFIX2430SellerConsistencyAndInvoiceSellerUpdateTest`:**

1. `employee_linked_user_uses_current_user_name` — đổi tên user → meta hiển thị tên mới (Hướng A).
2. `employee_without_user_keeps_employee_name` — không có user link → giữ `employees.name`.
3. `snapshot_seller_not_merged_to_user` — `created_by=NULL + seller_name='Admin'` không bị merge sang user `Trần Văn Tiến`.
4. `unknown_seller_bucket` — không có cả `created_by` lẫn `seller_name` → bucket `unknown`.
5. `invoice_page_returns_full_active_employee_options` — dropdown chi tiết HĐ load tất cả active employees, không có inactive.
6. `update_seller_to_different_employee` — PATCH đổi seller; `created_by`/`seller_name` đổi, `created_by_name` không đổi.
7. `reject_creator_snapshot_seller_key` — `seller_key=creator_snapshot:Admin` → 422.
8. `cannot_change_seller_on_cancelled_invoice` — invoice `Đã hủy` → 422.
9. `staff_without_permission_cannot_change_seller` — role chỉ có `invoices.view` → 403.
10. `report_reflects_seller_change` — đổi seller A→B; `/reports/employees` doanh số sang B.
11. `invoice_filter_reflects_seller_change` — `/invoices?seller_key=employee:<B>` có HĐ, `<A>` không có.
12. `creator_snapshot_unchanged_after_seller_update` — `created_by_name` không đổi.
13. `duplicate_names_disambiguated_by_code` — 2 employee cùng tên khác code → display name có code.
14. `report_totals_unchanged_with_display_change` — đổi `users.name` không làm đổi tổng doanh thu (data không đổi, chỉ display).

## 10. Manual QA (cần tester confirm trên production)

- Settings user: xác định user `Trần Văn Tiến`, lấy id.
- Query: `SELECT id, user_id, name, code FROM employees WHERE name LIKE '%Admin%' OR user_id = <id>`.
- Báo cáo `/reports/employees`: nếu employee có `user_id` trỏ user `Trần Văn Tiến` → bây giờ dropdown + rows hiển thị `Trần Văn Tiến`.
- Invoice filter `/invoices`: dropdown Người bán hiển thị cùng tên với report.
- Chi tiết hóa đơn: dropdown có đủ active employees.
- Đổi seller hóa đơn: confirm → reload → seller mới hiển thị, người tạo không đổi.
- Báo cáo sau đổi seller: doanh số/lợi nhuận chuyển sang seller mới.
- Invoice filter sau đổi seller: lọc seller cũ → không còn HĐ; lọc seller mới → có HĐ.

## 11. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update hàng loạt | Không |
| Recalculate tồn kho | Không |
| Recalculate giá vốn | Không |
| Recalculate công nợ | Không |
| Recalculate cashflow | Không |
| Sửa items/returns/serials | Không |

## 12. Kết luận

- Báo cáo nhân viên display đồng bộ với Settings User (Hướng A — qua `employees.user_id` linked user): ✅
- Báo cáo còn `Admin` chỉ khi employee thực sự độc lập (không linked user) hoặc snapshot legacy — phân biệt rõ qua `code` / `SNAPSHOT` suffix: ✅
- Chi tiết hóa đơn chọn được người bán khác: ✅ (dropdown đầy đủ active employees)
- API đổi seller có guard: cancelled / time-lock / non-employee key / no-permission: ✅
- `created_by_name` không bị động đến: ✅
- Báo cáo & invoice filter đồng bộ sau đổi seller: ✅
- Không migration / backfill / update hàng loạt: ✅
- Có thể deploy: ✅
- Commit SHA: `c215508` — `fix(invoices): align seller display and allow seller updates`
- Push status: chưa push (chờ user xác nhận push to origin/main).
