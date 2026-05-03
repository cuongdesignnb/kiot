# STEP-5.2A — P1 regression tests after RR-01

> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **6/6 FAIL — Cả 2 rủi ro P1 được xác nhận**

---

## 1. Mục tiêu

Chứng minh 2 rủi ro P1 còn lại sau RR-01:
- **REG-RR01-02**: SupplierController dual-role invoice query thiếu lọc `status != 'Đã hủy'`
- **REG-RR01-04**: ReportController@costProfit CashFlow expense queries thiếu lọc `status != 'cancelled'`

---

## 2. Test REG-RR01-02 — Supplier dual-role

### Route/method kiểm tra
- **Route:** `GET /api/suppliers/{id}/debt-transactions`
- **Method:** `SupplierController@debtTransactions`
- **Dòng lỗi:** 331-333 — `Invoice::where('customer_id', $id)->orderBy(...)->get([...])`
- **Thiếu:** `->where('status', '!=', 'Đã hủy')`

### Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| Dual-role entity | `is_customer = true`, `is_supplier = true` |
| Invoice hợp lệ | status = `'Hoàn thành'`, total = **1.000.000** |
| Invoice đã hủy | status = `'Đã hủy'`, total = **9.000.000** |

### Kết quả

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `test_supplier_debt_ledger_should_not_include_cancelled_invoices` | 1 sale entry | 2 sale entries | ❌ **FAIL** |
| 2 | `test_supplier_debt_ledger_effect_should_exclude_cancelled` | supplier_effect = -1.000.000 | -10.000.000 | ❌ **FAIL** |

### Phân tích
- Query trả về **2 invoice entries** thay vì 1 → HĐ Đã hủy xuất hiện trong sổ cái NCC.
- `supplier_effect` bị **gấp 10x** (-1M → -10M) → công nợ NCC sai nghiêm trọng.

---

## 3. Test REG-RR01-04 — CashFlow cancelled expense

### Route/method kiểm tra
- **Method:** `ReportController@costProfit`
- **Dòng lỗi:**
  - 173-176: `CashFlow::where('type', 'payment')->...->sum('amount')` — totalExpenses
  - 179-181: `CashFlow::where('type', 'payment')->...->groupBy('category')` — category breakdown
  - 193-196: `CashFlow::where('type', 'receipt')->...->sum('amount')` — otherIncome
  - 201-203: `CashFlow::where('type', 'payment')->...->sum('amount')` — prevExpenses
- **Thiếu:** `->where('status', '!=', 'cancelled')`
- **Đặc biệt:** CashFlow model có `SoftDeletes` nhưng RR-01 fix chỉ `update(['status' => 'cancelled'])` mà **KHÔNG soft-delete** → record vẫn hiện trong query.

### Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| Expense active | amount = 1.000.000, status = `'active'`, deleted_at = NULL |
| Expense cancelled | amount = 9.000.000, status = `'cancelled'`, deleted_at = NULL |
| Receipt active | amount = 500.000, status = `'active'` |
| Receipt cancelled | amount = 4.500.000, status = `'cancelled'` |
| Prev period active | amount = 2.000.000, status = `'active'` |
| Prev period cancelled | amount = 8.000.000, status = `'cancelled'` |

### Kết quả

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `test_cashflow_expense_query_should_exclude_cancelled` | 1.000.000 | 10.000.000 | ❌ **FAIL** |
| 2 | `test_cashflow_expense_categories_should_exclude_cancelled` | 1.000.000 | 10.000.000 | ❌ **FAIL** |
| 3 | `test_cashflow_other_income_should_exclude_cancelled` | 500.000 | 5.000.000 | ❌ **FAIL** |
| 4 | `test_cashflow_prev_period_expenses_should_exclude_cancelled` | 2.000.000 | 10.000.000 | ❌ **FAIL** |

### Phân tích
- Tất cả 4 queries tính cả CashFlow `status = 'cancelled'`.
- SoftDeletes KHÔNG giúp ích vì record KHÔNG bị soft-delete (deleted_at = NULL).
- Sai lệch **5-10x** trên mọi metric chi phí/thu nhập.

---

## 4. Kết quả chạy test tổng

### Tests mới (P1 regression)

| Test file | Pass | Fail | Ghi chú |
|---|---:|---:|---|
| `RR01SupplierDualRoleRegressionTest` | 0 | 2 | Lỗi xác nhận |
| `RR01CashFlowCancelledRegressionTest` | 0 | 4 | Lỗi xác nhận |
| **Tổng P1** | **0** | **6** | **Cả 2 rủi ro P1 chứng minh được** |

### Tests hiện có (regression check)

| Test file | Pass | Fail | Ghi chú |
|---|---:|---:|---|
| `CancelInvoiceTest` | 10 | 0 | ✅ Không bị ảnh hưởng |
| `RR01ReportControllerRegressionTest` | 8 | 0 | ✅ P0 vẫn fixed |
| **Tổng existing** | **18** | **0** | ✅ |

---

## 5. Kết luận

- ✅ **REG-RR01-02 đã chứng minh được** — SupplierController dual-role tính cả HĐ Đã hủy vào sổ cái NCC. 2/2 FAIL.
- ✅ **REG-RR01-04 đã chứng minh được** — ReportController@costProfit tính cả CashFlow cancelled vào báo cáo chi phí/lợi nhuận. 4/4 FAIL.
- ✅ **Sẵn sàng chuyển sang Bước 5.2B** để sửa cả hai lỗi P1.

### Đề xuất cách sửa Bước 5.2B:
1. **REG-RR01-02:** Thêm `->where('status', '!=', 'Đã hủy')` vào SupplierController dòng 331 (hoặc dùng `Invoice::active()`).
2. **REG-RR01-04:** Thêm `->where('status', '!=', 'cancelled')` vào 4 queries CashFlow trong `costProfit`. Có thể tạo `CashFlow::scopeActive()` tương tự Invoice.
