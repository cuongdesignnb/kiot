# STEP-6.1A — RR-03 Stock Transfer Test Results

> **Mã rủi ro:** RR-03  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **4/5 FAIL — RR-03 xác nhận**

---

## 1. Mục tiêu

Chứng minh nghiệp vụ chuyển kho:
- Không tạo StockMovement (thẻ kho thiếu dòng)
- Không cập nhật `inventory_total_cost` (giá vốn BQ sai)
- Tồn kho bị sai khi tạo phiếu status=received

---

## 2. Luồng code phát hiện

| Thành phần | File / Route / Table |
|---|---|
| **Route tạo** | `POST /stock-transfers` → `stock-transfers.store` |
| **Route nhận** | ❌ Không đăng ký route (method `receive()` tồn tại nhưng chưa route) |
| **Route hủy** | ❌ Không đăng ký route (method `cancel()` tồn tại nhưng chưa route) |
| **Controller** | `app/Http/Controllers/StockTransferController.php` |
| **Models** | `StockTransfer`, `StockTransferItem`, `Product`, `Branch` |
| **Bảng chuyển kho** | `stock_transfers`, `stock_transfer_items` |
| **Bảng tồn kho** | `products` (stock_quantity, inventory_total_cost, cost_price) |
| **Bảng stock movement** | `stock_movements` (tồn tại nhưng **KHÔNG được gọi** bởi controller) |

### Phát hiện chi tiết trong code

| Dòng | Code | Vấn đề |
|---|---|---|
| 137 | `$product->decrement('stock_quantity', ...)` | Chỉ trừ qty, không ghi StockMovement, không cập nhật `inventory_total_cost` |
| 237 | `$product->increment('stock_quantity', ...)` | Chỉ cộng qty khi nhận, không ghi StockMovement |
| 283-287 | `increment` / `decrement` khi cancel | Không ghi StockMovement đảo |
| N/A | Không có tham chiếu `StockMovement` | 0 lần gọi trong toàn bộ controller |
| N/A | Không có tham chiếu `inventory_total_cost` | 0 lần cập nhật trong toàn bộ controller |

### Lỗi bổ sung phát hiện

**`store()` với status='received' không gọi receive logic:**
- Dòng 119-139: Khi status != 'draft', chỉ `decrement` stock (xuất kho)
- Nhưng không có code `increment` khi status = 'received' (nhận kho)
- → Tồn bị trừ mà không cộng lại → stock_quantity giảm sai

---

## 3. Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| **Product A** | cost_price = 100.000, stock = 10, inventory_total_cost = 1.000.000 |
| **Branch A** (nguồn) | 'Kho A Test RR03' |
| **Branch B** (đích) | 'Kho B Test RR03' |
| **Số lượng chuyển** | 3 |
| **Giá vốn chuyển** | 3 × 100.000 = 300.000 |

---

## 4. Test đã tạo

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `test_stock_transfer_should_create_transfer_out_movement` | Có StockMovement transfer_out | NULL (không có) | ❌ **FAIL** |
| 2 | `test_stock_transfer_received_should_create_transfer_in_movement` | Có StockMovement transfer_in | NULL (không có) | ❌ **FAIL** |
| 3 | `test_stock_transfer_should_update_inventory_total_cost` | total_cost = 700.000 | 1.000.000 (không đổi) | ❌ **FAIL** |
| 4 | `test_stock_transfer_received_total_stock_should_not_change` | stock = 10 (không đổi) | 7 (bị trừ không cộng lại) | ❌ **FAIL** |
| 5 | `test_cancel_stock_transfer_should_be_idempotent` | Hủy lần 2 không đổi tồn | Đúng (guard status=cancelled) | ✅ **PASS** |

---

## 5. Kết quả chạy test

```
Tests:    4 failed, 1 passed (11 assertions)
Duration: 0.67s
```

### Existing tests (regression check)

```
CancelInvoiceTest: 10 PASS
RR01ReportControllerRegressionTest: 8 PASS
Tổng existing: 18 PASS, 0 FAIL
```

---

## 6. Nguyên nhân fail

### ❌ TC-01 + TC-02: Không tạo StockMovement
- Controller **không import** và **không gọi** StockMovement
- Chỉ dùng `decrement` / `increment` trực tiếp trên Product
- Thẻ kho (stock card) thiếu hoàn toàn dòng chuyển kho

### ❌ TC-03: Không cập nhật inventory_total_cost
- Controller **không có reference nào** đến `inventory_total_cost`
- Xuất 3 sản phẩm nhưng total_cost vẫn 1.000.000 → cost_price = 1.000.000 / 7 = 142.857 thay vì 100.000
- Giá vốn BQ bị inflate 42.8%

### ❌ TC-04: store() + status=received — tồn bị trừ không cộng lại
- `store()` dòng 134-139: `decrement` khi status != 'draft' (bao gồm 'received')
- Nhưng **không có code** `increment` tương ứng cho status = 'received'
- → stock_quantity = 10 - 3 = 7 thay vì 10 (phải trừ rồi cộng lại = net zero)
- **Lỗi nghiêm trọng**: tạo phiếu chuyển kho nhận ngay → mất tồn

### ✅ TC-05: Cancel idempotent — hoạt động đúng
- Guard `status === 'cancelled'` ngăn hủy lặp
- `increment` phục hồi đúng khi hủy lần 1

---

## 7. Kết luận

- ✅ **RR-03 đã được chứng minh bằng test** — 4/5 FAIL xác nhận lỗi.
- ✅ **Phát hiện thêm lỗi**: `store()` status=received không cộng tồn khi nhận → mất tồn.
- ✅ **Có đủ điều kiện chuyển sang Bước 6.1B** để sửa.

### Phạm vi sửa dự kiến cho Bước 6.1B

1. Tích hợp `StockMovementService::record()` (hoặc tạo trực tiếp `StockMovement::create()`) trong `store()` và `receive()`.
2. Cập nhật `inventory_total_cost` bằng `MovingAvgCostingService` hoặc tính trực tiếp.
3. Sửa `store()` status=received: phải gọi receive logic (increment stock) sau khi decrement.
4. Tích hợp StockMovement trong `cancel()` để ghi đảo.
