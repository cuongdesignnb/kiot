# STEP-7.1A — RR-04 Stock Take Test Results

> **Mã rủi ro:** RR-04  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **4/5 FAIL — RR-04 xác nhận**

---

## 1. Mục tiêu

Chứng minh nghiệp vụ kiểm kho/cân bằng tồn:
- Không tạo StockMovement (thẻ kho thiếu dòng kiểm kho)
- Không cập nhật `inventory_total_cost` (giá vốn BQ sai)

---

## 2. Luồng code phát hiện

| Thành phần | File / Route / Table |
|---|---|
| **Route store** | `POST /stock-takes` → `stock-takes.store` |
| **Route balance** | `POST /stock-takes/{id}/balance` → `stock-takes.balance` |
| **Route cancel** | `POST /stock-takes/{id}/cancel` → `stock-takes.cancel` |
| **Route show** | `GET /stock-takes/{stockTake}` → `stock-takes.show` |
| **Controller** | `app/Http/Controllers/StockTakeController.php` |
| **Models** | `StockTake`, `StockTakeItem`, `Product` |
| **Bảng kiểm kho** | `stock_takes`, `stock_take_items` |
| **Bảng tồn kho** | `products` (stock_quantity, inventory_total_cost, cost_price) |
| **Bảng stock movement** | `stock_movements` (tồn tại nhưng **KHÔNG được gọi**) |

### Phát hiện chi tiết trong code

| Dòng | Code | Vấn đề |
|---|---|---|
| 121 | `Product::where('id', ...)->increment('stock_quantity', $diff)` | Raw increment khi store balanced, không ghi StockMovement, không cập nhật total_cost |
| 123 | `Product::where('id', ...)->decrement('stock_quantity', abs($diff))` | Raw decrement khi store balanced |
| 273 | `$product->increment('stock_quantity', $diff)` | Raw increment khi balance, không ghi StockMovement, không cập nhật total_cost |
| 275 | `$product->decrement('stock_quantity', abs($diff))` | Raw decrement khi balance |
| 330 | `$product->decrement('stock_quantity', $diff)` | Raw decrement khi cancel (đảo) |
| 332 | `$product->increment('stock_quantity', abs($diff))` | Raw increment khi cancel (đảo) |
| N/A | Không có tham chiếu `StockMovement` | 0 lần gọi trong toàn bộ controller |
| N/A | Không có tham chiếu `inventory_total_cost` | 0 lần cập nhật trong toàn bộ controller |
| N/A | Không có tham chiếu `MovingAvgCostingService` | 0 lần gọi |

### So sánh với RR-03 (StockTransfer)

| Khía cạnh | RR-03 | RR-04 |
|---|---|---|
| Pattern lỗi | Giống hệt | Giống hệt |
| Raw increment/decrement | ✅ Đã sửa | ❌ Chưa sửa |
| StockMovement | ✅ Đã tích hợp | ❌ Chưa gọi |
| CostingService | ✅ Đã tích hợp | ❌ Chưa gọi |
| Routes | ✅ Đầy đủ | ✅ Đầy đủ (balance + cancel đã có) |

---

## 3. Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| **Product A** | cost_price = 100.000, stock = 10, inventory_total_cost = 1.000.000 |
| **Tồn thực tế (tăng)** | 13 → diff = +3 |
| **Tồn thực tế (giảm)** | 7 → diff = -3 |

---

## 4. Test đã tạo

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `test_stocktake_increase_should_create_adjust_in_movement` | Có StockMovement adjust_in | NULL (không có) | ❌ **FAIL** |
| 2 | `test_stocktake_decrease_should_create_adjust_out_movement` | Có StockMovement adjust_out | NULL (không có) | ❌ **FAIL** |
| 3 | `test_stocktake_increase_should_update_inventory_total_cost` | total_cost = 1.300.000 | 1.000.000 (không đổi) | ❌ **FAIL** |
| 4 | `test_stocktake_decrease_should_update_inventory_total_cost` | total_cost = 700.000 | 1.000.000 (không đổi) | ❌ **FAIL** |
| 5 | `test_cancel_stocktake_should_be_idempotent` | Hủy lần 2 không đổi thêm | Đúng (guard status=cancelled) | ✅ **PASS** |

---

## 5. Kết quả chạy test

```
RR04StockTakeTest: 4 failed, 1 passed (12 assertions)
Duration: 0.62s
```

---

## 6. Nguyên nhân fail

### ❌ TC-01 + TC-02: Không tạo StockMovement
- Controller **không import** và **không gọi** `StockMovementService`
- Chỉ dùng `increment`/`decrement` trực tiếp trên Product
- Thẻ kho thiếu hoàn toàn dòng kiểm kho

### ❌ TC-03 + TC-04: Không cập nhật inventory_total_cost
- Controller **không có reference nào** đến `inventory_total_cost` hoặc `MovingAvgCostingService`
- Tăng 3 sản phẩm nhưng total_cost vẫn 1.000.000 → cost_price = 1.000.000 / 13 = 76.923 thay vì 100.000
- Giảm 3 sản phẩm nhưng total_cost vẫn 1.000.000 → cost_price = 1.000.000 / 7 = 142.857 thay vì 100.000

### ✅ TC-05: Cancel idempotent — hoạt động đúng
- Guard `status === 'cancelled'` ngăn hủy lặp
- Đảo chênh lệch raw hoạt động đúng về số lượng (nhưng không đảo inventory_total_cost)

---

## 7. Existing regression

```
RR03StockTransferTest: 5 PASS
RR03StockTransferRouteTest: 3 PASS
CancelInvoiceTest: 10 PASS
RR01ReportControllerRegressionTest: 8 PASS
RR01SupplierDualRoleRegressionTest: 2 PASS
RR01CashFlowCancelledRegressionTest: 4 PASS
Tổng existing: 32 PASS, 0 FAIL
```

---

## 8. Kết luận

- ✅ **RR-04 đã được chứng minh bằng test** — 4/5 FAIL xác nhận lỗi
- ✅ Pattern giống hệt RR-03: raw increment/decrement, thiếu CostingService + StockMovement
- ✅ Routes đã đầy đủ (balance + cancel có sẵn) — không cần thêm route
- ✅ **Có đủ điều kiện chuyển sang Bước 7.1B** để sửa

### Phạm vi sửa dự kiến cho Bước 7.1B

1. Thay `increment`/`decrement` bằng `MovingAvgCostingService::applyAdjustment()` trong `store()` và `balance()`
2. Ghi `StockMovementService::record()` type `adjust_in`/`adjust_out` cho mỗi thay đổi
3. Sửa `cancel()` dùng CostingService + StockMovement đảo
