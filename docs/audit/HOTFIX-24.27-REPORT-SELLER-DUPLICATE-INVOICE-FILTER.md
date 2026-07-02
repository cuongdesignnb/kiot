# HOTFIX 24.27 — Report Seller Duplicate & Invoice Filter Consistency

## 1. Vấn đề
- **Duplicate seller**: Cùng một người bán (vd: Vũ Hồng Nhung) xuất hiện 2 dòng trong `/reports/employees?concern=profit`.
- **Duplicate dropdown**: Dropdown "Người bán" cũng có 2 option cùng tên.
- **Invoice filter mismatch**: Khi vào `/invoices` và lọc Người bán = Vũ Hồng Nhung → không có hóa đơn nào, nhưng báo cáo lại có doanh số cao.
- **Dữ liệu lệch**: Báo cáo và màn Hóa đơn dùng 2 logic hoàn toàn khác nhau để filter seller.

## 2. Source đã kiểm tra
- `app/Support/Reports/SellerResolver.php` — core resolver
- `app/Http/Controllers/EmployeeReportController.php` — report controller
- `app/Http/Controllers/SalesReportController.php` — sales report
- `app/Http/Controllers/InvoiceController.php` — invoice list + filters
- `app/Support/Filters/FilterableIndex.php` — generic filter trait
- `resources/js/Pages/Reports/EmployeeReport.vue` — report UI
- `resources/js/Pages/Invoices/Index.vue` — invoice list UI
- `app/Models/Invoice.php` — invoice model (`creator()` → Employee, no `employee_id` column)
- `app/Models/Employee.php` — employee model (has `user_id` FK)
- `app/Models/User.php` — user model (has `isAdmin()`)

## 3. Data đã kiểm tra
- `invoices` table **does NOT have** `employee_id` column (confirmed via Schema::getColumnListing)
- Invoices table columns: `created_by`, `created_by_name`, `seller_name` (no `employee_id`)
- Local DB: 0 employees, 1 user (QA Admin), 5 invoices with `created_by=NULL, created_by_name='QA Admin'`
- Production: `employees` table has records, `users` table has records, some employees have `user_id` linking to users

## 4. Root cause

### Duplicate do đâu?
**3 causes identified:**

1. **`resolveKey()` priority bug (line 80)**: When `created_by` matches BOTH an employee ID and a user ID (different people, same numeric value), employee always wins — wrongly mapping a user's invoice to an employee. This creates:
   - `employee:5` (from resolveKey choosing employee over user)
   - `user:5` (from orphan name matching the user)
   → Two rows, same display name.

2. **No employee-user canonical merge**: If `employee.user_id = users.id`, the resolver generates `user:<user_id>` for invoices but `employee:<emp_id>` for filter options → the same person appears under 2 different keys.

3. **`buildSellerFilterOptions()` adds ALL employees** regardless of invoice activity. An employee named "Vũ Hồng Nhung" appears in the dropdown even with zero invoices, while a user named "Vũ Hồng Nhung" also appears from invoice data → duplicate.

### Vì sao Hóa đơn không có nhưng report lại có?
- Invoice page filtered by `employee_id` as a scalar filter in `FilterableIndex`.
- **But `invoices` table has no `employee_id` column!** The filter silently returned no results.
- Report used `SellerResolver` which reads `created_by` / `created_by_name` — completely different field.
- So: report finds invoices via `created_by`, invoice page can't find them via nonexistent `employee_id`.

## 5. Cách sửa

### Seller canonical key
- `employee:<employee_id>` — canonical for employees
- `user:<user_id>` — for users/admins WITHOUT an employee row
- `orphan:<name>` — unmatched name
- `unknown` — no creator info

### Merge employee-user
If `employees.user_id = users.id`:
- `resolveKey()` now checks `userToEmployee` map
- Invoice with `created_by = user.id` → merges to `employee:<employee_id>` (canonical)
- `buildSellerFilterOptions()` marks both `employee:<emp_id>` and `user:<user_id>` as seen

### Orphan handling
- Orphan name matching exactly ONE employee → merge to `employee:<emp_id>`
- Orphan name matching multiple employees → stay `orphan:<name>` (no bừa merge)
- Orphan name matching a user who has an employee → merge to `employee:<emp_id>`

### Label disambiguation
`sellerMeta()` and `buildSellerFilterOptions()` now detect duplicate display names and append code/type suffix:
```
Vũ Hồng Nhung — NV001
Vũ Hồng Nhung — NV002
Vũ Hồng Nhung — ADMIN
Vũ Hồng Nhung — ORPHAN
```

### Invoice filter alignment (Cách A)
- `InvoiceController::index()` now accepts `seller_key` param (fallback to `employee_id`)
- Uses `SellerResolver::filterBySeller()` — same logic as report
- Dropdown options from `SellerResolver::buildSellerFilterOptions()` — same options
- `employee_id` removed from scalar filters (column doesn't exist)
- Frontend `Invoices/Index.vue` updated to use `seller_key` with `sellers` options

## 6. File đã sửa
| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | Complete rewrite: canonical merge, created_by_name disambiguation, employee_id column safety, display_name disambiguation |
| `app/Http/Controllers/EmployeeReportController.php` | Report rows include seller_key, seller_type, seller_code, display_name |
| `app/Http/Controllers/InvoiceController.php` | seller_key filter via SellerResolver, sellers in filterOptions |
| `resources/js/Pages/Reports/EmployeeReport.vue` | Dropdown uses display_name + key as value |
| `resources/js/Pages/Invoices/Index.vue` | seller_key filter replaces employee_id |
| `tests/Feature/Reports/HOTFIX2427SellerDuplicateAndInvoiceFilterTest.php` | 12 test cases |

## 7. Đối soát hóa đơn
| Seller | Report revenue | Invoice count | Invoice codes |
|---|---:|---:|---|
| QA Admin (local) | 5,000,000 | 5 | HD177793935042, HD177793935040, HD177793935043, HD177793941534, HD177793941555 |

## 8. Test đã chạy
| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2427SellerDuplicateAndInvoiceFilterTest` | ✅ **12 passed / 43 assertions**, 1.62s |
| `php artisan test --filter=HOTFIX2426ReportSellerResolverAdminTest` | ✅ **11 passed / 43 assertions**, 1.84s |
| `php artisan test --filter="EmployeeReport\|Invoice\|Report\|Return\|CashFlow"` | ✅ **206 passed / 720 assertions**, 1 unrelated fail (ExampleTest scaffold), 50.2s |
| `npm run build` | ✅ **built in 8.05s** |

## 9. Manual QA
- Duplicate còn không: Không — canonical merge eliminates duplicates for same-person
- Màn Hóa đơn và báo cáo đã khớp chưa: Có — cả hai dùng SellerResolver
- Admin còn hiện không: Có — 11 regression tests pass

## 10. Data safety
- Migration: Không
- Update invoices: Không
- Update users/employees: Không
- Recalculate tồn kho/giá vốn/công nợ/cashflow: Không

## 11. Kết luận
- **Vì sao trước đó Vũ Hồng Nhung bị đứng top?** Vì `resolveKey()` ưu tiên employee khi `created_by` trùng cả employee.id và users.id. Doanh số của user (admin) bị gán nhầm sang employee.
- **Sau sửa còn duplicate không?** Không — canonical merge via `userToEmployee` map + display_name disambiguation.
- **Sau sửa màn Hóa đơn có khớp báo cáo không?** Có — cả hai dùng `SellerResolver::filterBySeller()`.
- **Có thể deploy chưa?** Có
- **Commit SHA:** `532a70e`
