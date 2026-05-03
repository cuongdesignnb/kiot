# STEP-6.1B — Fix RR-03 Stock Transfer

> **Mã rủi ro:** RR-03  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ **FIXED — 5/5 test PASS**

---

## 1. Vấn đề đã sửa

- ❌→✅ Không tạo StockMovement → Tạo đúng `transfer_out` / `transfer_in`
- ❌→✅ Không cập nhật `inventory_total_cost` → Dùng `MovingAvgCostingService`
- ❌→✅ `store()` status=received trừ tồn nhưng không cộng lại → Cộng lại qua `applyPurchase()`
- ❌→✅ `cancel()` chưa ghi StockMovement đảo → Ghi movement đảo đúng cả 2 chiều

---

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| `app/Http/Controllers/StockTransferController.php` | Import services, sửa `store()`, `receive()`, `cancel()` |

**Không sửa file nào khác.** Dùng services hiện có:
- `App\Services\MovingAvgCostingService` — quản lý giá vốn BQ
- `App\Services\StockMovementService` — ghi thẻ kho

---

## 3. Cách sửa

### store() — dòng 117-161

**Trước:** `$product->decrement('stock_quantity', ...)` — chỉ trừ số lượng raw.

**Sau:**
- `draft`: không đụng tồn, không movement
- `transferring`: gọi `applySale()` → trừ stock + giảm inventory_total_cost + giữ BQ. Gọi `StockMovementService::record()` type `transfer_out`.
- `received`: sau transfer_out, gọi thêm `applyPurchase()` → cộng stock + tăng inventory_total_cost. Gọi `StockMovementService::record()` type `transfer_in`. Cập nhật `received_quantity`.

### receive() — dòng 224-250

**Trước:** `$product->increment('stock_quantity', $recvQty)` — chỉ cộng raw.

**Sau:** Gọi `applyPurchase($product, $recvQty, $costPerUnit)` + `StockMovementService::record()` type `transfer_in` với `branch_id = to_branch_id`.

### cancel() — dòng 278-311

**Trước:** `increment` / `decrement` raw trực tiếp.

**Sau:**
1. Nếu đã received: gọi `applySale()` (đảo nhận) + movement `transfer_out` với note 'Hủy chuyển kho — đảo nhận'
2. Luôn: gọi `applyPurchase()` (hoàn kho nguồn) + movement `transfer_in` với note 'Hủy chuyển kho — hoàn kho nguồn'

Guard `status === 'cancelled'` giữ nguyên → idempotent.

### StockMovement types

| Action | Type | Branch |
|---|---|---|
| Xuất chuyển kho | `transfer_out` | `from_branch_id` |
| Nhận chuyển kho | `transfer_in` | `to_branch_id` |
| Hủy — đảo nhận | `transfer_out` | `to_branch_id` |
| Hủy — hoàn nguồn | `transfer_in` | `from_branch_id` |

### Giá vốn / inventory_total_cost

| Action | Method | Công thức |
|---|---|---|
| Transfer out | `applySale()` | total_cost -= qty × BQ; stock -= qty; BQ giữ nguyên |
| Transfer in | `applyPurchase()` | total_cost += qty × cost; stock += qty; BQ tính lại |
| Net-zero (received ngay) | applySale + applyPurchase | Stock không đổi, total_cost không đổi |

---

## 4. Kết quả test

### RR03StockTransferTest — 5/5 PASS

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | `stock_transfer_should_create_transfer_out_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 2 | `stock_transfer_received_should_create_transfer_in_movement` | ❌ FAIL (NULL) | ✅ PASS |
| 3 | `stock_transfer_should_update_inventory_total_cost` | ❌ FAIL (1M → 1M, kỳ vọng 700K) | ✅ PASS |
| 4 | `stock_transfer_received_total_stock_should_not_change` | ❌ FAIL (10 → 7) | ✅ PASS |
| 5 | `cancel_stock_transfer_should_be_idempotent` | ✅ PASS | ✅ PASS |

### RR-01 regression — 24/24 PASS

| Test Suite | Kết quả |
|---|---|
| CancelInvoiceTest (10) | ✅ 10 PASS |
| RR01ReportControllerRegressionTest (8) | ✅ 8 PASS |
| RR01SupplierDualRoleRegressionTest (2) | ✅ 2 PASS |
| RR01CashFlowCancelledRegressionTest (4) | ✅ 4 PASS |

### Tổng

```
Tests:    29 passed (49 assertions)
Duration: ~2.5s
```

---

## 5. Rủi ro còn lại

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | Routes | `receive()` và `cancel()` chưa đăng ký route (method tồn tại nhưng không có registered route) | P1 |
| 2 | Tồn kho theo branch | Hệ thống chỉ có `products.stock_quantity` chung, không phân biệt branch. StockMovement ghi `branch_id` nhưng Product không có tồn riêng per-branch. | Limitation |
| 3 | RR-04 (Kiểm kho) | Cùng pattern: `increment/decrement` trực tiếp, thiếu CostingService + StockMovement | P0 — Cần audit tiếp |
| 4 | Receive partial | Khi nhận 1 phần (received_qty < quantity), giá trị cost phân bổ theo BQ hiện tại — có thể cần review | P2 |

---

## 6. Kết luận

- ✅ **RR-03 đã Fixed** — 5/5 test PASS, 24/24 regression PASS.
- ✅ Tích hợp đúng `MovingAvgCostingService` + `StockMovementService` theo convention hiện tại.
- ✅ Có thể chuyển sang **Bước 6.2** (regression/closure RR-03) hoặc **RR-04** (kiểm kho).
