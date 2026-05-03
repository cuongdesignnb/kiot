# STEP-7.1B — Fix RR-04 Stock Take

> **Mã rủi ro:** RR-04  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 5/5 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ Không tạo StockMovement → Tạo đúng `adjust_in` / `adjust_out`
- ❌→✅ Không cập nhật `inventory_total_cost` → Dùng `MovingAvgCostingService::applyAdjustment()`
- ❌→✅ Raw `increment`/`decrement` trong store/balance/cancel → Qua CostingService
- ❌→✅ Hủy phiếu chưa đảo giá vốn/movement → Ghi movement đảo + đảo cost

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/StockTakeController.php` | Import services, sửa `store()`, `balance()`, `cancel()` |

**Không sửa file nào khác.** Dùng services hiện có:
- `MovingAvgCostingService::applyAdjustment($product, $deltaQty)` — cộng/trừ tồn ở BQ hiện tại
- `StockMovementService::record()` — ghi thẻ kho

---

## 3. Cách sửa

### store() — dòng 116-135

**Trước:** `Product::where(...)->increment('stock_quantity', $diff)` / `->decrement(...)` — raw

**Sau:**
- `balanced`: gọi `applyAdjustment($product, $diff)` + `StockMovementService::record()` type `adjust_in` hoặc `adjust_out`
- `draft`: không đụng tồn (giữ nguyên)

### balance() — dòng 270-283

**Trước:** `$product->increment('stock_quantity', $diff)` / `$product->decrement(...)` — raw

**Sau:** `applyAdjustment($product, $diff)` + `StockMovementService::record()` type `adjust_in` / `adjust_out`

### cancel() — dòng 323-343

**Trước:** `$product->decrement('stock_quantity', $diff)` / `$product->increment(...)` — raw đảo

**Sau:** Tính `$reverseDiff = -$diff`, gọi `applyAdjustment($product, $reverseDiff)` + `StockMovementService::record()` với note 'Hủy kiểm kho — đảo chênh lệch'

### StockMovement types

| Action | Type |
|---|---|
| Kiểm kho tăng tồn | `adjust_in` |
| Kiểm kho giảm tồn | `adjust_out` |
| Hủy kiểm kho tăng → đảo giảm | `adjust_out` |
| Hủy kiểm kho giảm → đảo tăng | `adjust_in` |

### Giá vốn / inventory_total_cost

| Action | Method | Công thức |
|---|---|---|
| Tăng tồn +N | `applyAdjustment(product, +N)` | total_cost += N × BQ; stock += N; BQ giữ nguyên |
| Giảm tồn -N | `applyAdjustment(product, -N)` | total_cost -= N × BQ; stock -= N; BQ giữ nguyên |
| Hủy | `applyAdjustment(product, -diff)` | Đảo đúng chiều |

---

## 4. Kết quả test

### RR04StockTakeTest — 5/5 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `stocktake_increase_should_create_adjust_in_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 2 | `stocktake_decrease_should_create_adjust_out_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 3 | `stocktake_increase_should_update_inventory_total_cost` | ❌ FAIL (1M→1M) | ✅ PASS |
| 4 | `stocktake_decrease_should_update_inventory_total_cost` | ❌ FAIL (1M→1M) | ✅ PASS |
| 5 | `cancel_stocktake_should_be_idempotent` | ✅ PASS | ✅ PASS |

### Existing regression — 32/32 PASS

| Test Suite | Kết quả |
|---|---|
| RR03StockTransferTest (5) | ✅ 5 PASS |
| RR03StockTransferRouteTest (3) | ✅ 3 PASS |
| CancelInvoiceTest (10) | ✅ 10 PASS |
| RR01ReportControllerRegressionTest (8) | ✅ 8 PASS |
| RR01SupplierDualRoleRegressionTest (2) | ✅ 2 PASS |
| RR01CashFlowCancelledRegressionTest (4) | ✅ 4 PASS |

### Tổng

```
Tests:    37 passed (71 assertions)
Duration: 2.80s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Architecture | Tồn kho chỉ `products.stock_quantity` chung, không phân biệt branch | Limitation |
| 2 | Test | Chưa có test kiểm kho nhiều sản phẩm cùng lúc | P3 |
| 3 | Test | Chưa có test kiểm kho với cost_price = 0 | P3 |
| 4 | Audit | RR-07 (Repair) vẫn dùng raw decrement cho linh kiện | P0 |
| 5 | Audit | RR-10 (CashFlow deletion) chưa xử lý | P0 |

---

## 6. Kết luận

- ✅ **RR-04 đã Fixed** — 5/5 test PASS, 32/32 regression PASS.
- ✅ Tích hợp đúng `MovingAvgCostingService::applyAdjustment()` + `StockMovementService::record()`.
- ✅ Có thể chuyển sang **Bước 7.2 closure** hoặc **RR-07/RR-10**.
