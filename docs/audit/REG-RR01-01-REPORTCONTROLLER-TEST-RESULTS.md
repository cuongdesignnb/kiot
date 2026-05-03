# REG-RR01-01 — ReportController cancelled invoice regression test

> **Mã regression:** REG-RR01-01  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** ✅ **8/8 PASS — Đã sửa xong**

---

## 1. Mục tiêu

Chứng minh rằng `ReportController.php` **không còn** tính hóa đơn có `status = 'Đã hủy'` trong các query doanh thu, số lượng bán, giá vốn, phân loại khách hàng.

---

## 2. Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| **Product A** | cost_price = 100.000, retail_price = 500.000, stock = 20 |
| **Customer A** | is_customer = true, debt = 0 |
| **Category** | 'Test Category REG' |
| **Invoice hợp lệ** | status = `'Hoàn thành'`, total = **1.000.000**, qty = **2**, cost_price = 100.000 |
| **Invoice đã hủy** | status = `'Đã hủy'`, total = **9.000.000**, qty = **9**, cost_price = 100.000 |

---

## 3. File đã sửa

| File | Thay đổi |
|---|---|
| `app/Models/Invoice.php` | Thêm `scopeActive()` — `where('status', '!=', 'Đã hủy')` |
| `app/Http/Controllers/ReportController.php` | 19 query patches: thêm `Invoice::active()` hoặc `->where('status', '!=', 'Đã hủy')` |
| `tests/Feature/Report/RR01ReportControllerRegressionTest.php` | 8 test cases verify filter hoạt động |

---

## 4. Chi tiết sửa theo method

### Invoice Model
```php
public function scopeActive($query)
{
    return $query->where('status', '!=', 'Đã hủy');
}
```

### productOverview (5 patches)
- ✅ `soldItems` — InvoiceItem::whereHas → thêm `->where('status', '!=', 'Đã hủy')`
- ✅ `topGroupsBestSeller` — InvoiceItem::whereHas → thêm filter
- ✅ `soldCategoryIds` — InvoiceItem::whereHas → thêm filter
- ✅ `topGroupsSlowSeller` — leftJoin invoices → thêm `->where('invoices.status', '!=', 'Đã hủy')`
- ✅ `soldProductIds` (dead stock) — InvoiceItem::whereHas → thêm filter

### productCategory (1 patch)
- ✅ leftJoin invoices → thêm `->where('invoices.status', '!=', 'Đã hủy')`

### customerOverview (6 patches)
- ✅ `$invoiceQ` base query → `Invoice::active()->whereBetween(...)`
- ✅ `newCustomerRevenue` → `Invoice::active()->...`
- ✅ `oldCustomerRevQ` → `Invoice::active()->...`
- ✅ `walkinRevenue` → `Invoice::active()->...`
- ✅ `walkinCount` → `Invoice::active()->...`
- ✅ `weekInvQ` (chart) → `Invoice::active()->...`

### customerCategory / RFM (4 patches)
- ✅ `invoiceCount` → `Invoice::active()->...`
- ✅ `lastInvoice` → `Invoice::active()->...`
- ✅ `custRevenue` → `Invoice::active()->...`
- ✅ `costItems` — InvoiceItem::whereHas → thêm `->where('status', '!=', 'Đã hủy')`

### customerDebt (3 patches)
- ✅ `yearRevenue` → `Invoice::active()->...`
- ✅ `monthRev` → `Invoice::active()->...`
- ✅ `lastInv` per debtor → `Invoice::active()->...`
- ✅ `custYearRevenue` per debtor → `Invoice::active()->...`

---

## 5. Kết quả test

### RR01ReportControllerRegressionTest — 8 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `customer_overview_total_revenue_should_exclude_cancelled_invoices` | ❌ FAIL (10M) | ✅ PASS (1M) |
| 2 | `customer_overview_new_customer_revenue_should_exclude_cancelled` | ❌ FAIL (10M) | ✅ PASS (1M) |
| 3 | `customer_category_rfm_invoice_count_should_exclude_cancelled` | ❌ FAIL (2) | ✅ PASS (1) |
| 4 | `customer_category_rfm_revenue_should_exclude_cancelled` | ❌ FAIL (10M) | ✅ PASS (1M) |
| 5 | `debt_report_year_revenue_should_exclude_cancelled` | ❌ FAIL (10M) | ✅ PASS (1M) |
| 6 | `product_overview_items_sold_should_exclude_cancelled` | ❌ FAIL (11) | ✅ PASS (2) |
| 7 | `product_overview_sold_revenue_should_exclude_cancelled` | ❌ FAIL (10M) | ✅ PASS (1M) |
| 8 | `customer_category_cost_items_should_exclude_cancelled` | ❌ FAIL (1.1M) | ✅ PASS (200K) |

### CancelInvoiceTest — 10 PASS (regression check)

| Test | Kết quả |
|---|---|
| 10 tests, 20 assertions | ✅ **ALL PASS** |

---

## 6. Kết luận

✅ **REG-RR01-01 đã Fixed** — ReportController không còn tính hóa đơn Đã hủy.

### Rủi ro còn lại (ngoài phạm vi bước này):
- **REG-RR01-02** (P1): SupplierController dual-role query dòng 331 — chưa sửa
- **REG-RR01-04** (P1): CashFlow expense queries trong ReportController — chưa sửa
- **P2**: Dashboard recentInvoices, filter dropdowns, export CSV — chưa sửa
