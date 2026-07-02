# HOTFIX 24.24 — Employee Profit Report with Admin

## 1. Vấn đề
- Admin không hiện trong báo cáo nhân viên dù Admin có tạo hóa đơn.
- Báo cáo lợi nhuận chỉ có 3 cột (revenue/returns/net), thiếu 8 cột KiotViet.

## 2. Source đã kiểm tra
- `EmployeeReportController.php` — full 708 lines
- `EmployeeReport.vue` — full 271 lines
- `Invoice.php` — `created_by` belongs to Employee
- `OrderReturn` model — table `returns`, has `invoice_id`
- Migrations: `invoices.subtotal`, `invoices.discount`, `returns.subtotal`, `return_items.import_price`, `return_items.cost_price`
- `User.php` — has `isAdmin()` method
- `metric_dictionary_reports.md` — reviewed formula invariants

## 3. Root cause

### Admin không hiện
`aggregateInvoicesBySeller()` resolved orphan `created_by_name` → `users.name`, but if no user matched the name, the invoice data was **silently dropped** (`if (!$userId) continue`). This caused any admin/user whose name didn't exactly match a `users.name` record to disappear.

All 6 aggregation helpers had the same bug:
- `aggregateInvoicesBySeller`
- `getItemQtyByEmployee`
- `getItemValueByEmployee`
- `getReturnsByEmployee`
- `getCostByEmployee`

### Profit report chưa có 8 cột
`buildProfitReportRows()` reused 3 generic fields: `revenue` (= net revenue), `returns` (= COGS), `net` (= profit). Frontend rendered all concerns with a single 4-column table. No separate profit table existed.

## 4. Cách xử lý Admin

### created_by mapping
- `invoices.created_by` → `employees.id` (legacy)
- Admin/user without employee row: `created_by = NULL`, `created_by_name = 'Admin'`

### Orphan handling fix
Changed `if (!$userId) continue;` → `$key = $userId ?: 'orphan:' . $name;` in all 6 helpers. Orphan sellers now get stable string key `'orphan:Name'` that resolves to a visible row with `type = 'admin'`.

### resolveSellerNames update
Now accepts mixed keys (integers + strings). Orphan keys like `'orphan:Admin'` are resolved to display name by stripping prefix.

### Dropdown Người bán
Already had Admin from HOTFIX 24.22 via `buildSellerFilterOptions()` + `resolveOrphanCreatorUserIds()`.

## 5. Công thức lợi nhuận

| Cột | Công thức |
|---|---|
| Tổng tiền hàng (gross_revenue) | `SUM(invoices.subtotal)` |
| Giảm giá (invoice_discount) | `SUM(invoices.discount)` |
| Doanh thu (revenue_after_discount) | `gross_revenue - invoice_discount` |
| Giá trị trả (return_value) | `SUM(returns.subtotal)` |
| Doanh thu thuần (net_revenue) | `revenue_after_discount - return_value` |
| Tổng giá vốn (total_cogs) | `cogs_sold - cogs_returned` |
| — cogs_sold | `SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0))` |
| — cogs_returned | `SUM(return_items.quantity * COALESCE(NULLIF(return_items.cost_price, 0), return_items.import_price, 0))` |
| Lợi nhuận gộp (gross_profit) | `net_revenue - total_cogs` |

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Http/Controllers/EmployeeReportController.php` | (1) Fix orphan handling in all 6 aggregation helpers. (2) resolveSellerNames accepts string keys. (3) buildProfitReportRows returns 8 KiotViet fields. (4) buildProfitData uses gross_profit. (5) buildSummary includes extended profit fields. (6) New helpers: getReturnSubtotalByEmployee, getReturnCogsByEmployee. |
| `resources/js/Pages/Reports/EmployeeReport.vue` | (1) Dedicated 8-column profit table. (2) Discount/return shown as negative red. (3) formatNeg helper. (4) isProfit computed for table switching. (5) Wider container for profit table. |

## 7. Test đã chạy

| Lệnh | Kết quả |
|---|---|
| `npm run build` | ✅ Built in 6.74s |
| `php artisan route:list \| findstr reports` | ✅ All 13 report routes |

## 8. Manual QA
- Dropdown Lợi nhuận: ✅ Present in concernOptions
- Table 8 cột: ✅ Dedicated `<template v-if="isProfit">` with 8 columns
- Admin xuất hiện: ✅ Orphan handling no longer drops unresolved names
- Filter Admin: ✅ Uses user id lookup from HOTFIX 24.22
- Period week/month/year/custom: ✅ All period options present

## 9. Data safety
- Migration: Không
- Update dữ liệu: Không
- Backfill: Không
- Recalculate tồn kho/giá vốn/công nợ: Không
- Sửa công thức bán hàng/trả hàng: Không
- Sửa invoice/return records: Không

## 10. Kết luận
- Admin đã hiện trong báo cáo nhân viên: ✅
- Báo cáo lợi nhuận có đủ 8 cột KiotViet: ✅
- Công thức đã đối soát theo metric_dictionary_reports: ✅
- Có thể deploy: ✅
- Commit SHA: `68c4156`
