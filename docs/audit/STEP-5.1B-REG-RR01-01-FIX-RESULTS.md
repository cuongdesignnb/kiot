# STEP-5.1B — Sửa REG-RR01-01: ReportController không được tính hóa đơn Đã hủy

> **Mã rủi ro:** RR-01 → REG-RR01-01  
> **Ngày thực hiện:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 18/18 test PASS**

---

## 1. Vấn đề

Sau khi sửa RR-01 (đổi từ `$invoice->delete()` sang `status = 'Đã hủy'`), regression report phát hiện `ReportController.php` có **19 queries** không lọc `status != 'Đã hủy'`.

**Hậu quả:** Tất cả báo cáo tổng quan (doanh thu, lợi nhuận, số lượng bán, phân loại KH, công nợ KH) đều **tính cả hóa đơn đã hủy**, gây sai lệch nghiêm trọng.

**Mức độ:** 🔴 P0 — Sai doanh thu báo cáo.

---

## 2. Nguyên nhân gốc

Trước đây HĐ hủy bị `delete()` nên tự biến mất khỏi query → không cần filter.  
Sau khi sửa RR-01, HĐ hủy vẫn nằm trong DB với `status = 'Đã hủy'` → các query không có filter sẽ tính sai.

---

## 3. File đã sửa

| File | Thay đổi | Dòng ảnh hưởng |
|---|---|---|
| `app/Models/Invoice.php` | Thêm `scopeActive()` | +11 dòng |
| `app/Http/Controllers/ReportController.php` | 19 query patches | 245, 259, 286, 304, 373, 484, 528, 541, 547, 555, 559, 579, 662, 667, 692, 706, 754, 776, 817, 824 |
| `tests/Feature/Report/RR01ReportControllerRegressionTest.php` | 8 test cases | Toàn bộ file |
| `docs/audit/REG-RR01-01-REPORTCONTROLLER-TEST-RESULTS.md` | Cập nhật trạng thái | Toàn bộ file |

---

## 4. Chi tiết sửa

### 4.1 Invoice Model — scopeActive()

```php
// app/Models/Invoice.php
public function scopeActive($query)
{
    return $query->where('status', '!=', 'Đã hủy');
}
```

- **Explicit scope** (không phải global scope) → không ảnh hưởng `InvoiceController@show`, `InvoiceController@index`
- Dùng: `Invoice::active()->whereBetween(...)` thay vì `Invoice::whereBetween(...)`

### 4.2 ReportController — 19 patches trên 5 methods

#### productOverview (5 patches)

| Query | Dòng | Pattern | Sửa |
|---|---|---|---|
| `soldItems` | 245-248 | `InvoiceItem::whereHas('invoice', ...)` | Thêm `->where('status', '!=', 'Đã hủy')` trong closure |
| `topGroupsBestSeller` | 259-262 | `InvoiceItem::whereHas('invoice', ...)` | Thêm filter trong closure |
| `soldCategoryIds` | 286-289 | `InvoiceItem::whereHas('invoice', ...)` | Thêm filter trong closure |
| `topGroupsSlowSeller` | 304-308 | `leftJoin('invoices', ...)` | Thêm `->where('invoices.status', '!=', 'Đã hủy')` |
| `soldProductIds` (dead stock) | 373-375 | `InvoiceItem::whereHas('invoice', ...)` | Thêm filter trong closure |

#### productCategory (1 patch)

| Query | Dòng | Pattern | Sửa |
|---|---|---|---|
| `categories join` | 484-488 | `leftJoin('invoices', ...)` | Thêm `->where('invoices.status', '!=', 'Đã hủy')` |

#### customerOverview (6 patches)

| Query | Dòng | Pattern | Sửa |
|---|---|---|---|
| `$invoiceQ` (base) | 528 | `Invoice::whereBetween(...)` | → `Invoice::active()->whereBetween(...)` |
| `newCustomerRevenue` | 541 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `oldCustomerRevQ` | 547 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `walkinRevenue` | 555 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `walkinCount` | 559 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `weekInvQ` (chart) | 579 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |

#### customerCategory / RFM (4 patches)

| Query | Dòng | Pattern | Sửa |
|---|---|---|---|
| `invoiceCount` | 662 | `Invoice::where(...)` | → `Invoice::active()->where(...)` |
| `lastInvoice` | 667 | `Invoice::where(...)` | → `Invoice::active()->where(...)` |
| `custRevenue` | 692 | `Invoice::where(...)` | → `Invoice::active()->where(...)` |
| `costItems` | 706-710 | `InvoiceItem::whereHas('invoice', ...)` | Thêm `->where('status', '!=', 'Đã hủy')` |

#### customerDebt (4 patches)

| Query | Dòng | Pattern | Sửa |
|---|---|---|---|
| `yearRevenue` | 754 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `monthRev` | 776 | `Invoice::whereBetween(...)` | → `Invoice::active()->...` |
| `lastInv` per debtor | 817 | `Invoice::where(...)` | → `Invoice::active()->where(...)` |
| `custYearRevenue` | 824 | `Invoice::where(...)` | → `Invoice::active()->where(...)` |

---

## 5. Kết quả test

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### RR01ReportControllerRegressionTest — 8/8 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `customer_overview_total_revenue_should_exclude_cancelled_invoices` | ❌ FAIL (10M thay vì 1M) | ✅ PASS |
| 2 | `customer_overview_new_customer_revenue_should_exclude_cancelled` | ❌ FAIL (10M thay vì 1M) | ✅ PASS |
| 3 | `customer_category_rfm_invoice_count_should_exclude_cancelled` | ❌ FAIL (2 thay vì 1) | ✅ PASS |
| 4 | `customer_category_rfm_revenue_should_exclude_cancelled` | ❌ FAIL (10M thay vì 1M) | ✅ PASS |
| 5 | `debt_report_year_revenue_should_exclude_cancelled` | ❌ FAIL (10M thay vì 1M) | ✅ PASS |
| 6 | `product_overview_items_sold_should_exclude_cancelled` | ❌ FAIL (11 thay vì 2) | ✅ PASS |
| 7 | `product_overview_sold_revenue_should_exclude_cancelled` | ❌ FAIL (10M thay vì 1M) | ✅ PASS |
| 8 | `customer_category_cost_items_should_exclude_cancelled` | ❌ FAIL (1.1M thay vì 200K) | ✅ PASS |

### CancelInvoiceTest — 10/10 PASS (regression check)

| # | Test | Kết quả |
|---|---|---|
| 1-6 | TC-RR01-01: Hủy HĐ thanh toán đủ (6 tests) | ✅ PASS |
| 7-9 | TC-RR01-02: Hủy HĐ ghi nợ (3 tests) | ✅ PASS |
| 10 | TC-RR01-03: Hủy lặp idempotent (1 test) | ✅ PASS |

### Tổng kết

```
Tests:    18 passed (29 assertions)
Duration: 1.49s
```

---

## 6. Rủi ro còn lại

| Mã | Mức độ | Khu vực | Trạng thái |
|---|---|---|---|
| REG-RR01-02 | ⚠️ P1 | SupplierController dual-role query (dòng 331) | Chưa sửa |
| REG-RR01-04 | ⚠️ P1 | CashFlow expense queries trong ReportController | Chưa sửa |
| — | ⚠️ P2 | Dashboard recentInvoices (dòng 156) | Chưa sửa |
| — | ⚠️ P2 | Filter dropdowns (salesChannels/paymentMethods) | Chưa sửa |
| — | ⚠️ P2 | InvoiceController export CSV | Chưa sửa |

---

## 7. Kết luận

✅ **REG-RR01-01 đã Fixed.**

- 19 queries trong ReportController đã được patch.
- `Invoice::scopeActive()` tạo ra pattern tái sử dụng cho tất cả module.
- 18/18 test PASS, không có regression.
- Sẵn sàng chuyển sang **Bước 5.2** xử lý P1 (SupplierController + CashFlow).
