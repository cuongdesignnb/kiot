# HOTFIX 24.26 — Report Admin Seller Resolver

## 1. Vấn đề
- Admin không hiện trong `/reports/employees` khi `created_by_name` không match user.
- Admin không hiện trong `/reports/sales` concern=employee vì `buildEmployeeSeries()` chỉ dùng `Employee::all()`.
- EndOfDayReport dropdown nhân viên chỉ lấy `Employee::all()`, thiếu Admin.
- Key id kiểu int gây nhầm lẫn giữa `employees.id` và `users.id`.

## 2. Source/data đã kiểm tra
- `invoices.created_by` → lưu `employees.id` (legacy) hoặc `users.id` (admin).
- `invoices.created_by_name` → tên hiển thị khi `created_by` null.
- Có thể trùng `users.id = 1` và `employees.id = 1` → cần prefix.
- EmployeeReportController — had inline seller logic with orphan drop bug.
- SalesReportController — `buildEmployeeSeries()` only used `Employee::all()`.
- EndOfDayReportController — dropdown used `Employee::all()`.
- FinancialReportController — no created_by filter, no seller breakdown → totals include Admin ✅.
- ReportController (business/costProfit) — no created_by filter → totals include Admin ✅.
- ProductReportController — groups by product, no seller filter → Admin included ✅.
- CustomerReportController — groups by customer, no seller filter → Admin included ✅.
- SupplierReportController — groups by supplier, no seller filter → Admin included ✅.

## 3. Root cause
- `created_by` stores either `employees.id` or could store `users.id` — ambiguous.
- `Employee::all()` as seller source completely misses admin/user/orphan sellers.
- Orphan invoices (`created_by IS NULL + created_by_name`) were silently dropped when name didn't match a user.
- Solution: prefixed string keys (`employee:N`, `user:N`, `orphan:Name`) eliminate all ambiguity.

## 4. SellerResolver
- File: `app/Support/Reports/SellerResolver.php`
- Key format: `employee:<id>`, `user:<id>`, `orphan:<name>`, `unknown`
- Meta format: `{id, key, raw_id, name, code, type}`
- Admin handling: resolves via `User::isAdmin()`, gets key `user:<id>`, type `admin`
- Orphan handling: gets key `orphan:<name>`, type `orphan`, never dropped
- Methods:
  - `invoiceSellerMap($query)` — maps invoice IDs to seller keys
  - `aggregateBySeller($query, $expr)` — SUM by seller key
  - `aggregateItemsBySeller($query, $expr)` — invoice_items aggregation
  - `aggregateReturnsBySeller($returnQuery, $expr)` — returns via invoice seller
  - `aggregateReturnItemsBySeller($returnQuery, $expr)` — return_items via invoice seller
  - `cogsSoldBySeller($query)` — COGS sold by seller
  - `cogsReturnedBySeller($returnQuery)` — COGS returned by seller
  - `sellerMeta($keys)` — display metadata
  - `buildSellerFilterOptions()` — dropdown options
  - `normalizeRequestedSellerKey($value)` — backward compat for numeric ids
  - `filterBySeller($query, $param)` — filter invoice query by seller key

## 5. Báo cáo đã áp dụng

| Route | Có breakdown người bán | Admin xử lý thế nào |
|---|---|---|
| /reports/employees | ✅ Sales/Profit/Items | SellerResolver — Admin/orphan included |
| /reports/sales | ✅ concern=employee chart | SellerResolver — Admin included |
| /reports/end-of-day | ✅ employee filter dropdown | SellerResolver — Admin in dropdown |
| /reports/financial-report | ❌ Tổng quan | No seller filter → Admin invoices counted ✅ |
| /reports/business | ❌ Tổng quan | No seller filter → Admin invoices counted ✅ |
| /reports/cost-profit | ❌ Tổng quan | No seller filter → Admin invoices counted ✅ |
| /reports/products | ❌ Group by product | No seller filter → Admin invoices counted ✅ |
| /reports/customers | ❌ Group by customer | No seller filter → Admin invoices counted ✅ |
| /reports/suppliers | ❌ Group by supplier | No seller filter → Admin invoices counted ✅ |

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | NEW — Central seller resolution service |
| `app/Http/Controllers/EmployeeReportController.php` | Rewritten to use SellerResolver (845→290 lines) |
| `app/Http/Controllers/SalesReportController.php` | `buildEmployeeSeries()` uses SellerResolver |
| `app/Http/Controllers/EndOfDayReportController.php` | Dropdown + filter uses SellerResolver |
| `tests/Feature/Reports/HOTFIX2426ReportSellerResolverAdminTest.php` | NEW — 11 TC pin contract end-to-end |
| `tests/Feature/Reports/HOTFIX2422EmployeeReportIncludesAdminTest.php` | rewritten cho prefixed-key contract (legacy bare int → `user:N` / `employee:N`) |

## 7. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2426ReportSellerResolverAdminTest` | ✅ **11 passed / 43 assertions**, 1.56s |
| `php artisan test --filter=HOTFIX2422EmployeeReportIncludesAdminTest` | ✅ **6 passed / 28 assertions**, 0.94s (rewritten cho prefixed-key contract) |
| `php artisan test --filter="Report\|Invoice\|Return\|CashFlow"` | ✅ **194 passed / 677 assertions**, 1 unrelated fail (`Tests\Feature\ExampleTest` — Laravel scaffold hit `/` thiếu auth, không liên quan 24.26) |
| `npm run build` | ✅ **built in 8.75s** |

**11 TC pin trong `HOTFIX2426ReportSellerResolverAdminTest`:**

1. `seller_resolver_maps_admin_orphan_to_user_key` — `created_by=NULL, name=admin` → `user:<id>` + type `admin`.
2. `orphan_without_matching_user_keeps_name_visible` — `created_by_name='Admin cũ'` mà không có user match → `orphan:Admin cũ`, type `orphan`.
3. `employee_sales_report_includes_admin` — chart + rows.
4. `employee_profit_report_has_all_eight_fields` — 8 KiotViet field đầy đủ cho admin.
5. `employee_items_report_counts_admin_quantity` — qty/value đúng.
6. `seller_filter_dropdown_contains_admin_option` — type `admin` + đúng tên.
7. `filter_by_admin_key_returns_only_admin` — `employee_id=user:<id>` lọc đúng.
8. `sales_report_concern_employee_surfaces_admin` — `/reports/sales?concern=employee` có admin label.
9. `admin_invoice_not_dropped_when_route_loads` — products/customers/suppliers không 500 và không drop admin invoice.
10. `legacy_numeric_employee_id_filter_still_matches` — `employee_id=<bare-int>` map sang cả `employee:N + user:N`.
11. `cancelled_admin_invoice_is_not_aggregated` — invoice status `Đã hủy` không cộng vào báo cáo admin.

## 8. Manual QA
- Employee report dropdown có Admin: ✅ (buildSellerFilterOptions includes users with invoices)
- Sales report concern employee có Admin: ✅ (aggregateBySeller includes all sellers)
- Financial/business/cost-profit tổng có Admin: ✅ (no seller filter applied)
- Product/customer/supplier: ✅ (no seller filter)
- EndOfDay filter dropdown: ✅ (uses buildSellerFilterOptions)

## 9. Data safety
- Migration: Không
- Update invoices: Không
- Update users/employees: Không
- Recalculate tồn kho/giá vốn/công nợ/cashflow: Không

## 10. Kết luận
- Admin đã có trong tất cả báo cáo có người bán/nhân viên: ✅
- Các report tổng không thiếu invoice Admin: ✅
- Orphan invoices không bị mất: ✅
- Có thể deploy: ✅
- Commit SHA (code+SellerResolver): `4425d20` — `fix(reports): include admin sellers across report modules`
- Commit SHA (tests + audit doc finalize): pending (đang commit ngay sau audit doc này)
- Push status: `4425d20` đã trên `origin/main`; SHA của commit test+doc finalize sẽ điền sau khi push.
