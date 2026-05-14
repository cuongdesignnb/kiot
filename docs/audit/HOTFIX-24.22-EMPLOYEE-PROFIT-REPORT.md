# HOTFIX 24.22 — Employee Report includes Admin/User sellers

## 1. Vấn đề

Báo cáo nhân viên ở `/reports/employees` (concern = sales / profit / items) đang **thiếu hoàn toàn các hóa đơn do Admin tạo**. Khi admin checkout ở POS mà không chọn nhân viên, `invoices.created_by = NULL` và `created_by_name = "Admin"` (hoặc tên user admin). Controller cũ filter `whereNotNull('created_by')` → drop sạch các row đó. Filter Người bán cũng chỉ lấy `Employee::orderBy('name')->get()` → không bao giờ thấy admin/user.

## 2. Source đã kiểm tra

| File | Phát hiện |
|---|---|
| [`app/Http/Controllers/POSController.php`](app/Http/Controllers/POSController.php#L135) | `'seller_id' => $employee?->id` — null khi admin sell không pick employee. |
| [`app/Services/InvoiceSaleService.php`](app/Services/InvoiceSaleService.php#L137) | `$attrs['created_by'] = $context['seller_id']` → invoices.created_by NHẬN employees.id (nullable). `created_by_name` = `auth()->user()?->name ?? 'Admin'`. |
| [`app/Http/Controllers/EmployeeReportController.php`](app/Http/Controllers/EmployeeReportController.php) | Cũ: `whereNotNull('created_by')`, `Employee::whereIn('id', $allIds)` → admin drop. |
| [`app/Models/User.php`](app/Models/User.php#L69) | `isAdmin()` — user `role_id IS NULL` HOẶC role có wildcard `*`. |
| [`app/Models/Invoice.php`](app/Models/Invoice.php#L44) | `belongsTo(Employee::class, 'created_by')` — chứng minh `created_by` map sang `employees.id`. |
| [`routes/web.php`](routes/web.php) | `/reports/employees` route CHƯA register. FE call `router.get('/reports/employees')` → 404. |

## 3. Root cause

- `invoices.created_by` = **`employees.id`** (verified via `POSController` + `InvoiceSaleService` + `Invoice::belongsTo(Employee, 'created_by')`).
- Admin không có Employee row → `seller_id = $employee?->id = null` → invoice row có `created_by = NULL`.
- Report controller drop tất cả NULL `created_by` → admin biến mất.
- Filter Người bán lấy từ `employees` only → admin không vào filter list.
- Bonus: route `/reports/employees` chưa register trong `routes/web.php` (chỉ EmployeeReport.vue tồn tại + controller code có sẵn) → user hit URL trả 404. Đã thêm.

## 4. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Http/Controllers/EmployeeReportController.php`](app/Http/Controllers/EmployeeReportController.php) | rewrite | (a) NEW `resolveSellerNames($ids)` — lookup ưu tiên employees → employees-via-user_id → users (gắn `type=admin` qua `User::isAdmin()`) → fallback `Người bán #id`. (b) NEW `buildSellerFilterOptions()` — gộp tất cả Employee + user xuất hiện qua `created_by`/orphan-name. (c) NEW `aggregateInvoicesBySeller($q, $expr)` — group `created_by` + fold `created_by IS NULL` theo `created_by_name → user.id`. (d) Refactor `getRevenueByEmployee` / `getCostByEmployee` / `getItemQtyByEmployee` / `getItemValueByEmployee` / `getReturnsByEmployee` cùng pattern. (e) `index()` filter `employee_id` mở rộng: nếu id là user.id thì OR thêm `created_by IS NULL && created_by_name = user.name`. (f) Tất cả `Employee::whereIn('id', ...)` ở `buildSalesReportRows`/`buildProfitReportRows`/`buildItemsReportRows` thay bằng `$this->resolveSellerNames($allIds)`, thêm field `seller_type` vào row. |
| [`routes/web.php`](routes/web.php) | edit | Thêm route `GET /reports/employees` → `EmployeeReportController@index`, gated `permission:reports.view`. |
| [`tests/Feature/Reports/HOTFIX2422EmployeeReportIncludesAdminTest.php`](tests/Feature/Reports/HOTFIX2422EmployeeReportIncludesAdminTest.php) | NEW | 6 TC pin: profit/sales/items đều có admin row + đúng số; filter chứa admin; chọn admin lọc đúng; user-có-employee không duplicate. |
| [`docs/audit/HOTFIX-24.22-EMPLOYEE-PROFIT-REPORT.md`](docs/audit/HOTFIX-24.22-EMPLOYEE-PROFIT-REPORT.md) | NEW | Báo cáo này. |

**Không sửa:**
- `invoices.created_by` schema / convention — vẫn là `employees.id (nullable)`.
- `created_by_name` không đổi.
- POSController / InvoiceSaleService / InvoiceController — không động.
- SalaryCalculationService, OrderReturnController, CashFlow — không động.
- DB schema / migration / Customer / Employee / User model.
- Không backfill / không tạo Employee giả cho admin.

## 5. Admin / User seller support

### 5.1. `invoices.created_by` map vào bảng nào?

`employees.id`. Verified qua POSController + InvoiceSaleService + `Invoice::belongsTo(Employee::class, 'created_by')`.

### 5.2. Admin có Employee row không?

KHÔNG yêu cầu. Convention hiện tại là admin có User row, có thể có Employee link (qua `employees.user_id`) nhưng KHÔNG bắt buộc. Khi không có, POS để `created_by = NULL`.

### 5.3. Vì sao trước đây admin không hiện?

Controller cũ:
```php
$invoiceQ->whereNotNull('created_by')   // drop admin
$employees = Employee::whereIn('id', $allIds)   // không lookup users
$employees = Employee::orderBy('name')->get()   // filter list không có admin
```

### 5.4. Cách resolve tên người bán mới (`resolveSellerNames`)

1. Match `employees.id = sellerId` → `type='employee'`, code `NV{id}`.
2. Match `employees.user_id = sellerId` → `type='employee_user'`.
3. Match `users.id = sellerId` → `type='admin'` nếu `User::isAdmin()`, ngược lại `'user'`.
4. Fallback `Người bán #id` + `type='unknown'`.

### 5.5. Báo cáo nào đã áp dụng

| Concern | Status |
|---|---|
| `sales` (Bán hàng theo nhân viên) | ✅ TC-02 pin |
| `profit` (Lợi nhuận theo nhân viên) | ✅ TC-01 pin |
| `items` (Hàng bán theo nhân viên) | ✅ TC-03 pin |

### 5.6. Filter Người bán đã có Admin chưa

✅ Có — `buildSellerFilterOptions()` gộp Employee + user xuất hiện qua orphan invoices. TC-04 pin.

### 5.7. Test admin đã chạy

✅ 6 TC trong `HOTFIX2422EmployeeReportIncludesAdminTest` PASS:
1. profit report includes admin
2. sales report includes admin
3. items report includes admin
4. filter includes admin + employee both
5. picking admin id filters correctly
6. employee-with-user không duplicate trong filter

## 6. Backend có sửa không?

**Có**, nhưng **KHÔNG đổi formula công nợ / cashflow / invoice store**:
- Chỉ refactor `EmployeeReportController`.
- Thêm 1 route `/reports/employees` (route trước đó không tồn tại, FE đang call sẽ 404).
- Không động `Invoice::create`, không động `created_by` assignment.

## 7. Data safety

- Migration: **Không.**
- Backfill: **Không.**
- Update dữ liệu cũ: **Không.**
- Tạo Employee giả cho admin: **Không.**
- Sửa công thức công nợ / cashflow: **Không.**

## 8. Test đã chạy (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2422EmployeeReportIncludesAdminTest` | ✅ **6 passed / 28 assertions**, 1.10s |
| `php artisan test --filter="Report\|Invoice"` | ✅ **93 passed, 2 skipped / 324 assertions**, 55.87s |
| `npm run build` | ✅ **built in 6.82s** |

## 9. Manual QA — pending tester

- [ ] Đăng nhập admin → tạo hóa đơn ở POS không chọn nhân viên.
- [ ] Vào `/reports/employees?concern=sales` → có dòng "Admin" (hoặc tên admin user) + doanh thu đúng.
- [ ] `?concern=profit` → có dòng admin + lợi nhuận đúng.
- [ ] `?concern=items` → có dòng admin + số lượng đúng.
- [ ] Filter "Người bán" dropdown → có option Admin cùng các nhân viên.
- [ ] Chọn Admin → bảng chỉ còn dòng Admin, không có nhân viên khác.
- [ ] Console không lỗi.

## 10. Ảnh hưởng nghiệp vụ

- **Công nợ NCC / khách hàng:** ✅ KHÔNG động — không touch `created_by` assignment, không động Invoice store/update.
- **CashFlow:** ✅ KHÔNG động.
- **Tồn kho / giá vốn / serial / IMEI:** ✅ KHÔNG động.
- **SalaryCalculationService:** ✅ Intact — vẫn dùng `Invoice::where('created_by', $employee->id)` đúng convention.

## 11. Kết luận

| Câu hỏi | Câu trả lời |
|---|---|
| Admin đã hiện trong profit report chưa | ✅ Có — TC-01 |
| Admin đã hiện trong sales report chưa | ✅ Có — TC-02 |
| Admin đã hiện trong items report chưa | ✅ Có — TC-03 |
| Filter Người bán đã có Admin chưa | ✅ Có — TC-04 |
| `invoices.created_by` map `users.id` hay `employees.id` | **`employees.id`** (verified qua source) |
| Có tạo/sửa dữ liệu admin/employee không | ❌ Không — chỉ refactor report controller + thêm route |

## 12. Commit & deployment

- **Commit SHA:** `d41931a` — `fix(reports): employee report includes admin/user sellers`.
- **Push status:** ✅ đã push, `origin/main` = `d41931aa8e8e95cfc11221515337fcd6e6f11a61`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan route:clear
php artisan optimize:clear
rm -rf public/build
npm run build
# Hard reload trình duyệt (Ctrl+Shift+R).
```
