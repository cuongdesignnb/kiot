# STEP-8.1B — Fix RR-07 Repair Parts

> **Mã rủi ro:** RR-07  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 4/4 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ Không tạo StockMovement cho linh kiện xuất/hoàn → Ghi đúng `repair_out` / `repair_in`
- ❌→✅ Không cập nhật `inventory_total_cost` linh kiện → Dùng `applySale()` / `applyPurchase()`
- ❌→✅ Raw `decrement` trong addPart → Qua `MovingAvgCostingService::applySale()`
- ❌→✅ Raw `increment` trong removePart → Qua `MovingAvgCostingService::applyPurchase()`
- ❌→✅ Raw `increment` trong disassemblePart → Qua `MovingAvgCostingService::applyPurchase()`

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Services/TaskService.php` | Import services, sửa `addPart()`, `removePart()`, `disassemblePart()` |
| `tests/Feature/Repair/RR07RepairPartsTest.php` | Fix `$movement->quantity` → `$movement->qty` (column name mismatch) |

---

## 3. Cách sửa

### addPart() — dòng 289

**Trước:** `$product->decrement('stock_quantity', $quantity)` — raw

**Sau:**
1. `MovingAvgCostingService::applySale($product, $quantity)` — cập nhật stock + total_cost + cost_price
2. `$product->refresh()`
3. `StockMovementService::record($product, TYPE_REPAIR_OUT, $quantity, $unitCost, $task)`

### removePart() — dòng 323

**Trước:** `Product::where('id', ...)->increment('stock_quantity', ...)` — raw

**Sau:**
1. Lấy `$restoreCost` từ `$part->unit_cost` (snapshot lúc xuất)
2. `MovingAvgCostingService::applyPurchase($product, qty, $restoreCost)` — phục hồi stock + total_cost
3. `$product->refresh()`
4. `StockMovementService::record($product, TYPE_REPAIR_IN, qty, $restoreCost, $task)`

### disassemblePart() — dòng 372

**Trước:** `$product->increment('stock_quantity', $quantity)` — raw

**Sau:**
1. `MovingAvgCostingService::applyPurchase($product, $quantity, $cost)` — nhập tồn linh kiện thu hồi
2. `$product->refresh()`
3. `StockMovementService::record($product, TYPE_REPAIR_IN, $quantity, $cost, $task)`

### StockMovement types

| Action | Type |
|---|---|
| Xuất linh kiện cho sửa chữa | `repair_out` |
| Hoàn linh kiện (removePart) | `repair_in` |
| Thu hồi linh kiện (disassemblePart) | `repair_in` |

### Giá vốn / inventory_total_cost

| Action | CostingService | Công thức |
|---|---|---|
| Xuất 3 linh kiện (cost=100K) | `applySale(product, 3)` | total_cost -= 3×BQ; stock -= 3; BQ giữ nguyên |
| Hoàn 3 linh kiện | `applyPurchase(product, 3, 100K)` | total_cost += 3×100K; stock += 3; BQ = total/stock |
| Thu hồi linh kiện | `applyPurchase(product, qty, cost)` | Nhập kho giống purchase |

---

## 4. Kết quả test

### RR07RepairPartsTest — 4/4 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `add_part_should_update_part_inventory_total_cost` | ❌ FAIL (1M→1M) | ✅ PASS |
| 2 | `add_part_should_create_repair_out_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 3 | `remove_part_should_restore_stock_and_create_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 4 | `add_part_should_not_allow_exceeding_stock` | ✅ PASS | ✅ PASS |

### Existing regression — 37/37 PASS

| Test Suite | Kết quả |
|---|---|
| RR04StockTakeTest (5) | ✅ 5 PASS |
| RR03StockTransferTest (5) | ✅ 5 PASS |
| RR03StockTransferRouteTest (3) | ✅ 3 PASS |
| CancelInvoiceTest (10) | ✅ 10 PASS |
| RR01ReportControllerRegressionTest (8) | ✅ 8 PASS |
| RR01SupplierDualRoleRegressionTest (2) | ✅ 2 PASS |
| RR01CashFlowCancelledRegressionTest (4) | ✅ 4 PASS |

### Tổng

```
Tests:    41 passed (80 assertions)
Duration: 3.26s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Deprecated | `RepairService` cũ vẫn dùng `device_repair_id` + raw decrement — cần deprecate hoặc sửa | P2 |
| 2 | Test | Chưa có test cho `disassemblePart()` (đã sửa cùng pattern) | P3 |
| 3 | Test | Chưa có test update số lượng linh kiện | P3 |
| 4 | Test | Chưa có test nhiều linh kiện cùng phiếu | P3 |
| 5 | Test | Chưa có test UI/API đầy đủ cho repair parts | P3 |

---

## 6. Kết luận

- ✅ **RR-07 đã Fixed** — 4/4 test PASS, 37/37 regression PASS, tổng 41/41.
- ✅ Tích hợp `applySale()` / `applyPurchase()` + `StockMovementService::record()` cho 3 methods.
- ✅ Có thể chuyển sang **closure RR-07** hoặc **RR-10 (CashFlow deletion)**.
