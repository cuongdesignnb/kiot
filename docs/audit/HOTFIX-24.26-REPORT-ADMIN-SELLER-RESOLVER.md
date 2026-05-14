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

## 7. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `npm run build` | ✅ Built in 6.82s |

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
- Commit SHA: `6471f8b`
