# RR-07 — Sửa chữa xuất linh kiện phải ghi StockMovement và cập nhật giá vốn

> **Mã rủi ro:** RR-07  
> **Mức độ:** 🔴 P0 — Critical  
> **Module:** RepairService (DeviceRepair)

---

## Mục tiêu

Kiểm tra nghiệp vụ xuất linh kiện sửa chữa:
- Phải tạo StockMovement cho mỗi linh kiện xuất (repair_out)
- Phải cập nhật `inventory_total_cost` của linh kiện khi stock_quantity giảm
- Hoàn linh kiện (removePart) phải ghi movement đảo
- Không cho xuất quá tồn

---

## Luồng code phát hiện

| Thành phần | Giá trị |
|---|---|
| **Route addPart** | `POST /api/device-repairs/{id}/parts` → `DeviceRepairController@addPart` |
| **Route removePart** | `DELETE /api/device-repairs/{id}/parts/{partId}` → `DeviceRepairController@removePart` |
| **Service** | `app/Services/RepairService.php` — `addPart()` dòng 97, `removePart()` dòng 125 |
| **Models** | `DeviceRepair` (extends `Task`), `DeviceRepairPart` (extends `TaskPart`), `SerialImei` |
| **StockMovement** | ❌ **KHÔNG được gọi** — 0 references trong RepairService |
| **Part inventory_total_cost** | ❌ **KHÔNG được cập nhật** cho linh kiện bị trừ tồn |
| **applyRepairAdjustment** | ✅ Có gọi nhưng chỉ cho **sản phẩm sửa chữa** (serial's product), KHÔNG cho linh kiện |

### Vấn đề cụ thể

- `addPart()` dòng 97: `$product->decrement('stock_quantity', $quantity)` — raw, thiếu movement + cost
- `removePart()` dòng 125: `Product::where(...)->increment('stock_quantity', ...)` — raw, thiếu movement + cost

---

## Test cases

### TC-RR07-01: Xuất linh kiện phải trừ tồn và cập nhật giá vốn
- Part stock = 10, cost = 100.000, total_cost = 1.000.000
- Xuất 3
- Kỳ vọng: stock = 7, total_cost = 700.000, StockMovement repair_out qty=3

### TC-RR07-02: Xuất linh kiện phải tạo StockMovement
- Kỳ vọng: có record type repair_out trong stock_movements

### TC-RR07-03: Hoàn linh kiện (removePart) phải cộng lại tồn + movement đảo
- Kỳ vọng: stock về 10, total_cost về 1.000.000, StockMovement repair_in qty=3

### TC-RR07-04: Không cho xuất linh kiện quá tồn
- Part stock = 10, xuất 15
- Kỳ vọng: Exception, stock vẫn 10
