# RR-04 Closure Report — Kiểm kho phải ghi StockMovement và giữ đúng giá vốn

> **Mã rủi ro:** RR-04  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 37 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** `StockTakeController` dùng `increment`/`decrement` trực tiếp trên `products.stock_quantity` khi cân bằng kiểm kho, không ghi `StockMovement` và không cập nhật `inventory_total_cost`.
- **Root cause:**
  - `store()` dòng 121/123: `Product::where(...)->increment/decrement(...)` — raw
  - `balance()` dòng 273/275: `$product->increment/decrement(...)` — raw
  - `cancel()` dòng 330/332: `$product->decrement/increment(...)` — raw đảo
  - **0 references** đến `StockMovement`, `inventory_total_cost`, hoặc `MovingAvgCostingService`
- **Ảnh hưởng:**
  - Thẻ kho (stock card) thiếu hoàn toàn dòng kiểm kho — không truy vết được
  - `inventory_total_cost` không đổi khi `stock_quantity` thay đổi:
    - Tăng 3 sản phẩm: cost_price = 1.000.000 / 13 = 76.923 thay vì 100.000 (deflate 23%)
    - Giảm 3 sản phẩm: cost_price = 1.000.000 / 7 = 142.857 thay vì 100.000 (inflate 43%)

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 7.1A** | Viết test chứng minh lỗi (5 test cases) | `tests/Feature/Inventory/RR04StockTakeTest.php` | 4 FAIL, 1 PASS |
| **Step 7.1B** | Tích hợp `applyAdjustment()` + `StockMovementService::record()` vào store/balance/cancel | `app/Http/Controllers/StockTakeController.php` | 5/5 PASS |

### Tổng file đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/StockTakeController.php` | Import services, sửa `store()`, `balance()`, `cancel()` — thay raw increment/decrement bằng CostingService + StockMovement |

---

## 3. Test verification

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Kết quả |
|---|---|---:|---|
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | ✅ **5 PASS** |
| RR-03 core | `RR03StockTransferTest.php` | 5 | ✅ **5 PASS** |
| RR-03 route | `RR03StockTransferRouteTest.php` | 3 | ✅ **3 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ **4 PASS** |
| **Tổng** | | **37** | ✅ **37 PASS, 0 FAIL** |

```
Tests:    37 passed (71 assertions)
Duration: 2.94s
```

---

## 4. Quy ước mới sau RR-04

### Luồng kiểm kho chuẩn

| Trạng thái | Stock | CostingService | StockMovement |
|---|---|---|---|
| `draft` | ❌ Không đụng | ❌ Không gọi | ❌ Không tạo |
| `balanced` (tăng tồn) | ✅ stock += diff | `applyAdjustment(+diff)` | `adjust_in` |
| `balanced` (giảm tồn) | ✅ stock -= diff | `applyAdjustment(-diff)` | `adjust_out` |
| `cancelled` | ✅ Đảo đúng chiều | `applyAdjustment(-diff)` | Movement đảo |

### Quy tắc bắt buộc

1. **Không dùng `increment`/`decrement` raw** cho tồn kho kiểm kê — phải qua `MovingAvgCostingService::applyAdjustment()`
2. **Mỗi chênh lệch kiểm kho phải ghi `StockMovement`** — `adjust_in` cho tăng, `adjust_out` cho giảm
3. **Phiếu `draft` không được đụng tồn** — chỉ lưu nháp
4. **Hủy kiểm kho phải đảo bằng `applyAdjustment(-diff)`** và ghi movement đảo
5. **Guard `status === 'cancelled'`** phải giữ nguyên — idempotent

### So sánh pattern với RR-03

| Khía cạnh | RR-03 (Transfer) | RR-04 (StockTake) |
|---|---|---|
| CostingService | `applySale()` + `applyPurchase()` | `applyAdjustment()` |
| Movement types | `transfer_out` / `transfer_in` | `adjust_out` / `adjust_in` |
| Pattern | 2 chiều (xuất + nhập) | 1 chiều (chênh lệch) |
| Routes | Phải thêm (6.2B) | Đã có sẵn |

---

## 5. P3/limitation còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Architecture | Tồn kho chỉ `products.stock_quantity` chung, chưa phân biệt branch | Limitation |
| 2 | Test | Chưa có test kiểm kho nhiều sản phẩm cùng lúc | P3 |
| 3 | Test | Chưa có test kiểm kho với cost_price = 0 | P3 |
| 4 | Test | Chưa có test UI kiểm kho | P3 |
| 5 | Audit | RR-07 (Repair) vẫn dùng raw decrement — cùng pattern lỗi | 🔴 P0 |
| 6 | Audit | RR-10 (CashFlow deletion) chưa xử lý | 🔴 P0 |
| 7 | Audit | RR-11 (OrderReturn qty validation) chưa xử lý | 🔴 P0 |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-04 = Fixed/Verified |
| `docs/test-cases/RR-04-stock-take.md` | Test case document (5 TCs) |
| `docs/audit/STEP-7.1A-RR04-STOCK-TAKE-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-7.1B-RR04-STOCK-TAKE-FIX-RESULTS.md` | Sửa controller |
| `docs/audit/RR-04-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-04 đã Fixed/Verified.**

- Lỗi gốc (raw increment/decrement, thiếu StockMovement, thiếu costing) đã sửa triệt để.
- Pattern giống hệt RR-03 — cùng root cause, cùng giải pháp.
- 5 regression tests bao phủ tăng tồn, giảm tồn, cost tracking, và cancel idempotent.
- Tổng 37/37 test PASS bao gồm RR-01 + RR-03 regression.
- P3/limitations đã ghi nhận vào backlog.
- **Sẵn sàng chuyển sang RR-07 (Repair)** hoặc **RR-10 (CashFlow deletion)**.

### Tổng kết tiến độ audit

| Mã | Module | Trạng thái |
|---|---|---|
| RR-01 | Invoice cancel | ✅ Fixed/Verified |
| RR-03 | Stock transfer | ✅ Fixed/Verified |
| RR-04 | Stock take | ✅ Fixed/Verified |
| RR-07 | Repair | 🔴 Chưa xử lý |
| RR-10 | CashFlow deletion | 🔴 Chưa xử lý |
| RR-11 | OrderReturn qty | 🔴 Chưa xử lý |
