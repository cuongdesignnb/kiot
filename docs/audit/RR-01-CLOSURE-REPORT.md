# RR-01 Closure Report — Hủy hóa đơn không xóa vật lý

> **Mã rủi ro:** RR-01  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 24 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** Hủy hóa đơn gọi `$invoice->delete()` — xóa vật lý record khỏi database, kéo theo cascade delete invoice_items, và hard-delete CashFlow liên quan.
- **Root cause:** `InvoiceController@destroy` dùng `$invoice->delete()` thay vì status-based cancellation. Không có SoftDeletes trên Invoice model.
- **Ảnh hưởng:**
  - Mất lịch sử chứng từ (invoice + items biến mất hoàn toàn)
  - Không thể truy vết giao dịch đã hủy
  - Báo cáo doanh thu/lợi nhuận không thể tái tạo chính xác
  - Sổ quỹ (CashFlow) mất dòng tiền đã phát sinh

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 3** | Viết test chứng minh lỗi (4 FAIL) | `tests/Feature/Invoice/CancelInvoiceTest.php` | 4 FAIL, 6 PASS |
| **Step 4** | Sửa `InvoiceController@destroy`: thay `delete()` bằng `status = 'Đã hủy'`, guard idempotent, CashFlow `status = 'cancelled'` | `app/Http/Controllers/InvoiceController.php` | 10/10 PASS |
| **Step 5 (Regression)** | Audit toàn bộ hệ thống tìm query ảnh hưởng | `docs/audit/RR-01-REGRESSION-REPORT.md` | Phát hiện P0 + P1 |
| **Step 5.1B** | Sửa ReportController P0: 19 query patches + `Invoice::scopeActive()` | `app/Models/Invoice.php`, `app/Http/Controllers/ReportController.php` | 18/18 PASS |
| **Step 5.2A** | Viết test chứng minh 2 lỗi P1 (6 FAIL) | `tests/Feature/Supplier/RR01SupplierDualRoleRegressionTest.php`, `tests/Feature/Report/RR01CashFlowCancelledRegressionTest.php` | 6 FAIL |
| **Step 5.2B** | Sửa SupplierController P1 + CashFlow P1 + `CashFlow::scopeActive()` | `app/Http/Controllers/SupplierController.php`, `app/Http/Controllers/ReportController.php`, `app/Models/CashFlow.php` | 24/24 PASS |

### Tổng file business code đã sửa

| File | Dòng sửa | Nội dung |
|---|---|---|
| `app/Http/Controllers/InvoiceController.php` | 554-651 | Thay `delete()` bằng status-based cancellation |
| `app/Models/Invoice.php` | +11 dòng | Thêm `scopeActive()` |
| `app/Http/Controllers/ReportController.php` | 23 patches | 19 Invoice + 4 CashFlow queries |
| `app/Http/Controllers/SupplierController.php` | 1 patch (dòng 331) | Dual-role invoice query |
| `app/Models/CashFlow.php` | +11 dòng | Thêm `scopeActive()` |

---

## 3. Test verification

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Assertions | Kết quả |
|---|---|---|---|---|
| Hủy hóa đơn cơ bản | `CancelInvoiceTest.php` | 10 | 20 | ✅ **10 PASS** |
| ReportController P0 | `RR01ReportControllerRegressionTest.php` | 8 | 9 | ✅ **8 PASS** |
| Supplier dual-role P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | 4 | ✅ **2 PASS** |
| CashFlow cancelled P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | 4 | ✅ **4 PASS** |
| **Tổng** | | **24** | **37** | ✅ **24 PASS, 0 FAIL** |

```
Tests:    24 passed (37 assertions)
Duration: 1.77s
```

---

## 4. P2 còn lại đưa vào backlog

Các phần sau **KHÔNG chặn** việc đóng RR-01, nhưng nên xử lý trong sprint sau:

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | `DashboardController` dòng ~156 | `recentInvoices` query có thể hiện HĐ Đã hủy trong danh sách gần đây | P2 |
| 2 | Filter dropdowns | `salesChannels` / `paymentMethods` distinct query tính cả HĐ hủy | P2 |
| 3 | `InvoiceController` export CSV | Export có thể xuất cả HĐ Đã hủy nếu không lọc | P2 |
| 4 | CashFlow listing | `CashFlowController@index` nên có badge/filter hiện trạng thái cancelled | P2 |
| 5 | `SalesReportController` | Cần audit riêng để xác nhận có query thiếu lọc không | P2 |

---

## 5. Quy ước mới sau RR-01

### Scopes đã thiết lập

| Model | Scope | Mục đích | Cú pháp |
|---|---|---|---|
| `Invoice` | `scopeActive()` | Loại trừ `status = 'Đã hủy'` | `Invoice::active()->...` |
| `CashFlow` | `scopeActive()` | Loại trừ `status = 'cancelled'` | `CashFlow::active()->...` |

### Quy tắc bắt buộc

1. **Muốn lấy hóa đơn hợp lệ để tính báo cáo** → dùng `Invoice::active()`
2. **Muốn lấy CashFlow hợp lệ để tính tiền** → dùng `CashFlow::active()`
3. **Hóa đơn đã hủy vẫn được giữ trong DB** để audit trail — KHÔNG bao giờ xóa vật lý
4. **Không được dùng `delete()` vật lý** với chứng từ đã phát sinh nghiệp vụ (invoice, cashflow, purchase, return)
5. **Scope là explicit** (không phải global scope) → controller views (show, index) vẫn hiện HĐ hủy cho người dùng xem

### Convention status

| Model | Status hợp lệ | Status hủy |
|---|---|---|
| Invoice | `'Hoàn thành'`, `'Đã thanh toán'`, `'Ghi nợ'` | `'Đã hủy'` |
| CashFlow | `'active'` | `'cancelled'` |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-01 = Fixed/Verified |
| `docs/audit/STEP-3-RR01-TEST-RESULTS.md` | Test ban đầu chứng minh lỗi |
| `docs/audit/STEP-4-RR01-FIX-RESULTS.md` | Sửa InvoiceController@destroy |
| `docs/audit/RR-01-REGRESSION-REPORT.md` | Audit regression sau fix |
| `docs/audit/REG-RR01-01-REPORTCONTROLLER-TEST-RESULTS.md` | Test ReportController P0 |
| `docs/audit/STEP-5.1B-REG-RR01-01-FIX-RESULTS.md` | Sửa ReportController P0 |
| `docs/audit/STEP-5.2A-P1-REGRESSION-TEST-RESULTS.md` | Test P1 (Supplier + CashFlow) |
| `docs/audit/STEP-5.2B-P1-FIX-RESULTS.md` | Sửa P1 |
| `docs/audit/RR-01-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-01 đã Fixed/Verified** về P0 + P1.

- Lỗi gốc (hard-delete invoice) đã sửa triệt để.
- 24 regression tests bao phủ toàn bộ luồng hủy + báo cáo + công nợ.
- 2 scope patterns (`Invoice::active()`, `CashFlow::active()`) thiết lập convention tái sử dụng.
- P2 items đã ghi nhận vào backlog, không chặn tiến độ.
- **Sẵn sàng chuyển sang RR-03 (StockTransfer)** hoặc RR tiếp theo theo thứ tự Risk Register.
