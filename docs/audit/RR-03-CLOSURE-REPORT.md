# RR-03 Closure Report — Chuyển kho phải ghi StockMovement và giữ đúng giá vốn

> **Mã rủi ro:** RR-03  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 32 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** `StockTransferController` dùng `increment`/`decrement` trực tiếp trên `products.stock_quantity` khi chuyển kho, không ghi `StockMovement` và không cập nhật `inventory_total_cost`.
- **Root cause:**
  - `store()` dòng 137: `$product->decrement('stock_quantity', ...)` — raw decrement
  - `receive()` dòng 237: `$product->increment('stock_quantity', ...)` — raw increment
  - `cancel()` dòng 283-287: raw increment/decrement
  - **0 references** đến `StockMovement` hoặc `inventory_total_cost` trong toàn bộ controller
- **Ảnh hưởng:**
  - Thẻ kho (stock card) thiếu hoàn toàn dòng chuyển kho — không truy vết được
  - `inventory_total_cost` không đổi khi `stock_quantity` thay đổi → `cost_price = total_cost / qty` sai lệch (inflate 42.8% trong test)
  - `store()` status=received chỉ decrement mà **không increment** → mất tồn (10 → 7)
  - Route `receive` và `cancel` chưa đăng ký → UI không gọi được

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 6.1A** | Viết test chứng minh lỗi (5 test cases) | `tests/Feature/Inventory/RR03StockTransferTest.php` | 4 FAIL, 1 PASS |
| **Step 6.1B** | Tích hợp `MovingAvgCostingService` + `StockMovementService` vào store/receive/cancel. Sửa store() status=received. | `app/Http/Controllers/StockTransferController.php` | 5/5 PASS |
| **Step 6.2A** | Viết route integration test (3 test cases) | `tests/Feature/Inventory/RR03StockTransferRouteTest.php` | 2 FAIL, 1 PASS |
| **Step 6.2B** | Đăng ký route receive/cancel | `routes/web.php` | 32/32 PASS |

### Tổng file đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/StockTransferController.php` | Import services, sửa `store()`, `receive()`, `cancel()` để dùng CostingService + MovementService |
| `routes/web.php` | +2 routes: `stock-transfers.receive`, `stock-transfers.cancel` |

---

## 3. Test verification

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Kết quả |
|---|---|---:|---|
| RR-03 core | `RR03StockTransferTest.php` | 5 | ✅ **5 PASS** |
| RR-03 route | `RR03StockTransferRouteTest.php` | 3 | ✅ **3 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ **4 PASS** |
| **Tổng** | | **32** | ✅ **32 PASS, 0 FAIL** |

```
Tests:    32 passed (59 assertions)
Duration: 2.55s
```

---

## 4. Quy ước mới sau RR-03

### Luồng chuyển kho chuẩn

| Trạng thái | Stock | CostingService | StockMovement |
|---|---|---|---|
| `draft` | ❌ Không đụng | ❌ Không gọi | ❌ Không tạo |
| `transferring` | ✅ Trừ kho nguồn | `applySale()` | `transfer_out` |
| `received` (tạo ngay) | ✅ Trừ + Cộng | `applySale()` + `applyPurchase()` | `transfer_out` + `transfer_in` |
| `received` (nhận sau) | ✅ Cộng kho đích | `applyPurchase()` | `transfer_in` |
| `cancelled` | ✅ Đảo đúng chiều | Reverse đúng | Movement đảo |

### Quy tắc bắt buộc

1. **Không dùng `increment`/`decrement` raw** cho tồn kho — phải qua `MovingAvgCostingService`
2. **Mỗi thay đổi tồn kho phải ghi `StockMovement`** — gọi `StockMovementService::record()`
3. **Phiếu `draft` không được đụng tồn** — chỉ lưu chứng từ nháp
4. **Status `received` tạo ngay phải xử lý đủ xuất + nhập** — tổng tồn không đổi
5. **Hủy phiếu phải có guard idempotent** — check `status === 'cancelled'` trước

### Services đã dùng

| Service | Method | Khi nào |
|---|---|---|
| `MovingAvgCostingService` | `applySale()` | Xuất chuyển kho (transfer_out) |
| `MovingAvgCostingService` | `applyPurchase()` | Nhận chuyển kho (transfer_in) |
| `StockMovementService` | `record()` | Mỗi thay đổi tồn kho |

---

## 5. P2/P3 còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | UI | `Show.vue` chưa tồn tại — không có trang chi tiết phiếu chuyển kho | P2 |
| 2 | UI | `Index.vue` chưa có nút Nhận hàng / Hủy phiếu (route đã có nhưng UI chưa gọi) | P2 |
| 3 | Architecture | Tồn kho chỉ có `products.stock_quantity` chung, chưa phân biệt branch | Limitation |
| 4 | Test | Receive partial (nhận 1 phần) cần test bổ sung | P3 |
| 5 | Permission | Receive/cancel dùng chung `stock_transfers.create` — có thể cần permission riêng | P3 |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-03 = Fixed/Verified |
| `docs/test-cases/RR-03-stock-transfer.md` | Test case document (5 TCs) |
| `docs/audit/STEP-6.1A-RR03-STOCK-TRANSFER-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-6.1B-RR03-STOCK-TRANSFER-FIX-RESULTS.md` | Sửa controller |
| `docs/audit/STEP-6.2A-RR03-ROUTE-TEST-RESULTS.md` | Test route |
| `docs/audit/STEP-6.2B-RR03-ROUTE-FIX-RESULTS.md` | Sửa route |
| `docs/audit/RR-03-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-03 đã Fixed/Verified.**

- Lỗi gốc (raw increment/decrement, thiếu StockMovement, thiếu costing) đã sửa triệt để.
- 8 regression tests bao phủ core logic + route integration.
- Tổng 32/32 test PASS bao gồm cả RR-01 regression.
- P2/P3/UI items đã ghi nhận vào backlog, không chặn tiến độ.
- **Sẵn sàng chuyển sang RR-04 (StockTake)** — cùng pattern lỗi, cần tích hợp tương tự.
