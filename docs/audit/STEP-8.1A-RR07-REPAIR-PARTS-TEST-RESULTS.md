# STEP-8.1A — RR-07 Repair Parts Test Results

> **Mã rủi ro:** RR-07  
> **Ngày test:** 02/05/2026  
> **Trạng thái:** 🔴 **3/4 FAIL — RR-07 xác nhận**

---

## 1. Mục tiêu

Chứng minh nghiệp vụ xuất linh kiện sửa chữa:
- Không tạo StockMovement cho linh kiện xuất/hoàn
- Không cập nhật `inventory_total_cost` của linh kiện khi trừ/cộng tồn

---

## 2. Luồng code phát hiện

| Thành phần | File / Route / Table |
|---|---|
| **Route addPart** | `POST /api/device-repairs/{id}/parts` → `DeviceRepairController@addPart` |
| **Route removePart** | `DELETE /api/device-repairs/{id}/parts/{partId}` → `DeviceRepairController@removePart` |
| **Controller** | `app/Http/Controllers/Api/DeviceRepairController.php` |
| **Service (hiện tại)** | `app/Services/TaskService.php` — `addPart()` dòng 289, `removePart()` dòng 323 |
| **Service (deprecated)** | `app/Services/RepairService.php` — `addPart()` dòng 97, `removePart()` dòng 125 |
| **Models** | `Task` (= DeviceRepair), `TaskPart` (= DeviceRepairPart), `SerialImei` |
| **Bảng** | `tasks`, `task_parts`, `serial_imeis`, `products`, `stock_movements` |

### Phát hiện chi tiết — TaskService (service chính)

| Dòng | Code | Vấn đề |
|---|---|---|
| 289 | `$product->decrement('stock_quantity', $quantity)` | Raw decrement linh kiện — không ghi StockMovement, không cập nhật inventory_total_cost |
| 323 | `Product::where('id', ...)->increment('stock_quantity', ...)` | Raw increment khi hoàn linh kiện — không ghi StockMovement, không cập nhật inventory_total_cost |
| 372 | `$product->increment('stock_quantity', $quantity)` | Raw increment khi bóc linh kiện (disassemble) — cùng lỗi |
| N/A | Không có tham chiếu `StockMovement` | 0 lần gọi StockMovementService trong toàn bộ TaskService |

### Phát hiện bổ sung — RepairService (deprecated)

| Dòng | Code | Vấn đề |
|---|---|---|
| 87 | `'device_repair_id' => $repair->id` | Bug: dùng field cũ thay vì `task_id` → insert fail |
| 97 | `$product->decrement('stock_quantity', $quantity)` | Cùng lỗi raw decrement |
| 125 | `Product::where(...)->increment(...)` | Cùng lỗi raw increment |

### Lưu ý quan trọng

- `applyRepairAdjustment()` **CÓ** được gọi nhưng cho **sản phẩm được sửa** (serial's parent product), KHÔNG phải cho **linh kiện bị tiêu hao**
- Khi xuất 3 linh kiện (Part): `Part.stock_quantity` giảm 3 nhưng `Part.inventory_total_cost` vẫn 1.000.000 → cost_price sai thành 142.857

---

## 3. Dữ liệu test

| Thành phần | Giá trị |
|---|---|
| **Device** | cost = 5.000.000, serial in_stock |
| **Part (linh kiện)** | cost_price = 100.000, stock = 10, total_cost = 1.000.000 |
| **Số lượng xuất** | 3 |

---

## 4. Test đã tạo

| # | Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|---|
| 1 | `test_add_part_should_update_part_inventory_total_cost` | total_cost = 700.000 | 1.000.000 (không đổi) | ❌ **FAIL** |
| 2 | `test_add_part_should_create_repair_out_movement` | Có StockMovement repair_out | NULL (không có) | ❌ **FAIL** |
| 3 | `test_remove_part_should_restore_stock_and_create_movement` | StockMovement repair_in | NULL (không có) | ❌ **FAIL** |
| 4 | `test_add_part_should_not_allow_exceeding_stock` | RuntimeException khi xuất > tồn | Đúng — throw exception | ✅ **PASS** |

---

## 5. Kết quả chạy test

```
RR07RepairPartsTest: 3 failed, 1 passed (8 assertions)
Duration: 0.70s
```

---

## 6. Nguyên nhân fail

### ❌ TC-01: inventory_total_cost không cập nhật
- `TaskService::addPart()` dùng `$product->decrement()` raw
- Chỉ giảm `stock_quantity` mà **không giảm** `inventory_total_cost`
- Kết quả: stock = 7, total_cost = 1.000.000 → cost_price tính sai = 142.857

### ❌ TC-02: Không tạo StockMovement repair_out
- 0 references đến `StockMovementService` trong `TaskService`
- Thẻ kho linh kiện thiếu hoàn toàn dòng xuất sửa chữa

### ❌ TC-03: removePart không tạo movement đảo
- `removePart()` dùng `Product::increment()` raw
- Không ghi StockMovement `repair_in`
- inventory_total_cost không phục hồi

### ✅ TC-04: Validation quá tồn — hoạt động đúng
- Guard `if ($product->stock_quantity < $quantity)` throw RuntimeException

---

## 7. Existing regression

```
RR04StockTakeTest: 5 PASS
RR03StockTransferTest: 5 PASS
RR03StockTransferRouteTest: 3 PASS
CancelInvoiceTest: 10 PASS
RR01ReportControllerRegressionTest: 8 PASS
RR01SupplierDualRoleRegressionTest: 2 PASS
RR01CashFlowCancelledRegressionTest: 4 PASS
Tổng existing: 37 PASS, 0 FAIL
```

---

## 8. Phát hiện bổ sung

### RepairService deprecated bug

`RepairService::addPart()` dùng `'device_repair_id' => $repair->id` nhưng `TaskPart` model chỉ có `task_id` trong fillable. Khi insert, `device_repair_id` bị ignore → `task_id` = NULL → SQLSTATE error. Đây là bug riêng của service cũ, không ảnh hưởng luồng hiện tại qua `TaskService`.

### disassemblePart() cùng lỗi

`TaskService::disassemblePart()` dòng 372 cũng dùng `$product->increment('stock_quantity', $quantity)` raw — cùng pattern lỗi thiếu CostingService + StockMovement.

---

## 9. Kết luận

- ✅ **RR-07 đã được chứng minh bằng test** — 3/4 FAIL xác nhận lỗi
- ✅ Pattern giống RR-03/RR-04: raw increment/decrement, thiếu CostingService + StockMovement
- ✅ **Có đủ điều kiện chuyển sang Bước 8.1B** để sửa

### Phạm vi sửa dự kiến cho Bước 8.1B

1. `TaskService::addPart()`: Thay `$product->decrement()` bằng `applySale()` + `StockMovementService::record(type=repair_out)`
2. `TaskService::removePart()`: Thay `Product::increment()` bằng `applyPurchase()` + `StockMovementService::record(type=repair_in)`
3. `TaskService::disassemblePart()`: Thay `$product->increment()` bằng `applyPurchase()` + `StockMovementService::record(type=repair_in)`
4. (Tùy chọn) `RepairService` deprecated: Fix `device_repair_id` → `task_id` hoặc mark @deprecated rõ ràng
