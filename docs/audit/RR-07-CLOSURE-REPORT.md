# RR-07 Closure Report — Repair Parts phải ghi StockMovement và giữ đúng giá vốn

> **Mã rủi ro:** RR-07  
> **Mức độ ban đầu:** 🔴 P0 — Critical  
> **Trạng thái cuối:** ✅ **Fixed/Verified**  
> **Ngày đóng:** 02/05/2026  
> **Test verification:** 41 PASS, 0 FAIL

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** `TaskService` dùng `$product->decrement()` / `Product::increment()` raw khi xuất/hoàn/thu hồi linh kiện sửa chữa, không ghi `StockMovement` và không cập nhật `inventory_total_cost` của linh kiện.
- **Root cause:**
  - `addPart()` dòng 289: `$product->decrement('stock_quantity', $quantity)` — raw
  - `removePart()` dòng 323: `Product::where(...)->increment('stock_quantity', ...)` — raw
  - `disassemblePart()` dòng 372: `$product->increment('stock_quantity', $quantity)` — raw
  - **0 references** đến `StockMovementService` trong toàn bộ `TaskService`
  - `applyRepairAdjustment()` chỉ cập nhật cost cho **sản phẩm được sửa** (serial's product), KHÔNG cho **linh kiện bị tiêu hao**
- **Ảnh hưởng:**
  - Xuất 3 linh kiện (cost_price=100K): stock giảm 7 nhưng total_cost vẫn 1M → cost_price tính sai = 142.857 (inflate 43%)
  - Thẻ kho linh kiện thiếu hoàn toàn dòng xuất/hoàn sửa chữa
  - Hoàn linh kiện không phục hồi inventory_total_cost

### Phát hiện bổ sung

- `RepairService` (deprecated) cũng có cùng lỗi + thêm bug dùng `device_repair_id` thay `task_id` → insert fail
- `disassemblePart()` (bóc linh kiện từ máy) cũng dùng raw increment — cùng pattern

---

## 2. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 8.1A** | Viết test chứng minh lỗi (4 test cases) | `tests/Feature/Repair/RR07RepairPartsTest.php` | 3 FAIL, 1 PASS |
| **Step 8.1B** | Tích hợp `applySale()`/`applyPurchase()` + `StockMovementService` vào addPart/removePart/disassemblePart | `app/Services/TaskService.php` | 4/4 PASS |

### Tổng file đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Services/TaskService.php` | Import services, sửa `addPart()` (applySale + repair_out), `removePart()` (applyPurchase + repair_in), `disassemblePart()` (applyPurchase + repair_in) |
| `tests/Feature/Repair/RR07RepairPartsTest.php` | Fix column name `$movement->quantity` → `$movement->qty` |

---

## 3. Test verification

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Kết quả |
|---|---|---:|---|
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | ✅ **4 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | ✅ **5 PASS** |
| RR-03 core | `RR03StockTransferTest.php` | 5 | ✅ **5 PASS** |
| RR-03 route | `RR03StockTransferRouteTest.php` | 3 | ✅ **3 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ **4 PASS** |
| **Tổng** | | **41** | ✅ **41 PASS, 0 FAIL** |

```
Tests:    41 passed (80 assertions)
Duration: 3.21s
```

---

## 4. Quy ước mới sau RR-07

### Luồng linh kiện sửa chữa chuẩn

| Action | CostingService | StockMovement |
|---|---|---|
| Xuất linh kiện (addPart) | `applySale(product, qty)` | `repair_out` |
| Hoàn linh kiện (removePart) | `applyPurchase(product, qty, unit_cost)` | `repair_in` |
| Thu hồi linh kiện (disassemblePart) | `applyPurchase(product, qty, cost)` | `repair_in` |

### Quy tắc bắt buộc

1. **Xuất linh kiện không được dùng `decrement` raw** — phải qua `MovingAvgCostingService::applySale()`
2. **Hoàn/thu hồi linh kiện không được dùng `increment` raw** — phải qua `MovingAvgCostingService::applyPurchase()`
3. **Mỗi lần xuất linh kiện phải ghi `StockMovement` type `repair_out`**
4. **Mỗi lần hoàn/thu hồi phải ghi `StockMovement` type `repair_in`**
5. **Giữ validation không cho xuất quá tồn** — `stock_quantity < quantity` → throw `RuntimeException`
6. **removePart ưu tiên `unit_cost` snapshot** trên TaskPart để hoàn giá vốn chính xác
7. `inventory_total_cost` của linh kiện phải giảm khi xuất, tăng khi hoàn

### So sánh pattern với RR-03/RR-04

| Khía cạnh | RR-03 (Transfer) | RR-04 (StockTake) | RR-07 (Repair) |
|---|---|---|---|
| CostingService | `applySale()` + `applyPurchase()` | `applyAdjustment()` | `applySale()` + `applyPurchase()` |
| Movement types | `transfer_out/in` | `adjust_out/in` | `repair_out/in` |
| Pattern | 2 sản phẩm (kho A→B) | 1 sản phẩm (chênh lệch) | 1 linh kiện (xuất/hoàn) |
| Validation | N/A | N/A | Quá tồn → Exception |

---

## 5. P2/P3 còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Deprecated | `RepairService` cũ dùng `device_repair_id` + raw decrement — cần deprecate rõ hoặc xóa | P2 |
| 2 | Test | `disassemblePart()` đã sửa nhưng chưa có test riêng | P3 |
| 3 | Test | Chưa có test update số lượng linh kiện | P3 |
| 4 | Test | Chưa có test nhiều linh kiện cùng phiếu | P3 |
| 5 | Test | Chưa có test UI/API đầy đủ cho repair parts | P3 |
| 6 | Audit | RR-10 (CashFlow deletion) chưa xử lý | 🔴 P0 |
| 7 | Audit | RR-11 (OrderReturn qty validation) chưa xử lý | 🔴 P0 |

---

## 6. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-07 = Fixed/Verified |
| `docs/test-cases/RR-07-repair-parts.md` | Test case document (4 TCs) |
| `docs/audit/STEP-8.1A-RR07-REPAIR-PARTS-TEST-RESULTS.md` | Test chứng minh lỗi |
| `docs/audit/STEP-8.1B-RR07-REPAIR-PARTS-FIX-RESULTS.md` | Sửa TaskService |
| `docs/audit/RR-07-CLOSURE-REPORT.md` | File này — closure report |

---

## 7. Kết luận

✅ **RR-07 đã Fixed/Verified.**

- Lỗi gốc (raw decrement/increment cho linh kiện, thiếu StockMovement, thiếu costing) đã sửa triệt để trong `TaskService`.
- 3 methods đã tích hợp: `addPart()`, `removePart()`, `disassemblePart()`.
- 4 regression tests bao phủ cost update, movement ghi nhận, movement đảo, và validation quá tồn.
- Tổng 41/41 test PASS bao gồm RR-01 + RR-03 + RR-04 regression.
- P2/P3 items đã ghi nhận vào backlog.
- **Sẵn sàng chuyển sang RR-10 (CashFlow deletion)** hoặc **RR-11 (OrderReturn qty)**.

### Tổng kết tiến độ audit

| Mã | Module | Trạng thái |
|---|---|---|
| RR-01 | Invoice cancel | ✅ Fixed/Verified |
| RR-03 | Stock transfer | ✅ Fixed/Verified |
| RR-04 | Stock take | ✅ Fixed/Verified |
| RR-07 | Repair parts | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | 🔴 Chưa xử lý |
| RR-11 | OrderReturn qty | 🔴 Chưa xử lý |
