# STEP-5.2B — Fix P1 regression after RR-01

> **Ngày thực hiện:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 24/24 test PASS**

---

## 1. Vấn đề đã sửa

- **REG-RR01-02** (P1): SupplierController dual-role invoice query dòng 331 tính cả HĐ `status = 'Đã hủy'` vào sổ cái công nợ NCC.
- **REG-RR01-04** (P1): ReportController@costProfit 4 queries CashFlow tính cả phiếu thu/chi `status = 'cancelled'` vào báo cáo chi phí/lợi nhuận.

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Models/CashFlow.php` | Thêm `scopeActive()` — `where('status', '!=', 'cancelled')` |
| `app/Http/Controllers/SupplierController.php` | Dòng 331: `Invoice::where(...)` → `Invoice::active()->where(...)` |
| `app/Http/Controllers/ReportController.php` | 4 queries CashFlow: `CashFlow::where(...)` → `CashFlow::active()->where(...)` |
| `tests/Feature/Report/RR01CashFlowCancelledRegressionTest.php` | Cập nhật tests verify `CashFlow::active()` scope |

---

## 3. Cách sửa

### CashFlow Model — scopeActive()

```php
// app/Models/CashFlow.php
public function scopeActive($query)
{
    return $query->where('status', '!=', 'cancelled');
}
```

Explicit scope (không global), an toàn cho `CashFlowController` views.

### SupplierController — REG-RR01-02 (1 patch)

| Dòng | Trước | Sau |
|---|---|---|
| 331 | `Invoice::where('customer_id', $id)` | `Invoice::active()->where('customer_id', $id)` |

Dùng `Invoice::scopeActive()` đã tạo ở bước 5.1B.

### ReportController — REG-RR01-04 (4 patches)

| Query | Dòng | Trước | Sau |
|---|---|---|---|
| `totalExpenses` | 173 | `CashFlow::where(...)` | `CashFlow::active()->where(...)` |
| `expenseCategories` | 179 | `CashFlow::where(...)` | `CashFlow::active()->where(...)` |
| `otherIncome` | 193 | `CashFlow::where(...)` | `CashFlow::active()->where(...)` |
| `prevExpenses` | 201 | `CashFlow::where(...)` | `CashFlow::active()->where(...)` |

---

## 4. Kết quả test

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### Tổng kết

| Test file | Trước sửa | Sau sửa |
|---|---|---|
| `RR01SupplierDualRoleRegressionTest` (2 tests) | 0 PASS, **2 FAIL** | **2 PASS**, 0 FAIL ✅ |
| `RR01CashFlowCancelledRegressionTest` (4 tests) | 0 PASS, **4 FAIL** | **4 PASS**, 0 FAIL ✅ |
| `RR01ReportControllerRegressionTest` (8 tests) | 8 PASS | **8 PASS** ✅ |
| `CancelInvoiceTest` (10 tests) | 10 PASS | **10 PASS** ✅ |
| **Tổng** | 18 PASS, 6 FAIL | **24 PASS, 0 FAIL** ✅ |

```
Tests:    24 passed (37 assertions)
Duration: 1.75s
```

---

## 5. Rủi ro còn lại

| Mã | Mức độ | Khu vực | Trạng thái |
|---|---|---|---|
| — | P2 | Dashboard `recentInvoices` (DashboardController dòng ~156) | Chưa sửa |
| — | P2 | Filter dropdowns `salesChannels` / `paymentMethods` | Chưa sửa |
| — | P2 | InvoiceController export CSV | Chưa sửa |
| — | P2 | SalesReportController (nếu có query chưa lọc) | Cần audit |

---

## 6. Kết luận

- ✅ **REG-RR01-02 Fixed** — SupplierController dual-role không còn tính HĐ Đã hủy.
- ✅ **REG-RR01-04 Fixed** — ReportController@costProfit không còn tính CashFlow cancelled.
- ✅ **24/24 test PASS** — Toàn bộ RR-01 P0+P1 đã remediated.
- ✅ **Có thể mark RR-01 là Verified/Fixed** và chuyển sang audit RR-03 (StockTransfer) hoặc xử lý P2 nếu cần.

### Scope pattern đã thiết lập

| Model | Scope | Filter |
|---|---|---|
| `Invoice` | `scopeActive()` | `where('status', '!=', 'Đã hủy')` |
| `CashFlow` | `scopeActive()` | `where('status', '!=', 'cancelled')` |

Các module khác cần lọc Invoice/CashFlow hợp lệ chỉ cần dùng `::active()`.
