# RR-09 — Xuất hủy / Damage phải trừ tồn, cập nhật giá vốn và ghi StockMovement

> **Mã rủi ro:** RR-09
> **Mức độ:** P1 — Có thể sai tồn kho, sai giá vốn, thiếu truy vết thẻ kho
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Kiểm tra `DamageController@store` (và cancel nếu có) tuân quy ước nghiệp vụ:
- Giảm `stock_quantity` đúng số lượng.
- Giảm `inventory_total_cost` theo BQ hiện tại.
- Ghi `StockMovement` (`adjust_out`).
- Xử lý đúng Serial/IMEI nếu sản phẩm có serial.
- Hỗ trợ cancel với rollback đầy đủ.

---

## Bug đã đọc trong code

### 1. Trừ tồn raw — `DamageController.php` dòng 119

```php
$product->stock_quantity -= $item['qty'];
$product->save();
```

**Vi phạm AGENT_RULES mục 2.2:**
> "Không được dùng `increment`/`decrement` trực tiếp trên `stock_quantity` nếu nghiệp vụ có ảnh hưởng giá vốn hoặc thẻ kho."

**Hậu quả:**
- `inventory_total_cost` không thay đổi → `cost_price = total_cost / stock_quantity` bị **inflate** (đúng pattern RR-04 đã sửa).
- Ví dụ: stock=10, total=1M, cost=100k. Damage 3 → stock=7, total=1M, cost=142,857 (sai, đáng ra 100k).

### 2. Không ghi StockMovement

`use App\Services\StockMovementService` — **không xuất hiện** trong DamageController. Thẻ kho thiếu hoàn toàn dòng damage_out.

### 3. Không gọi MovingAvgCostingService

Service `MovingAvgCostingService` — **không xuất hiện**. Pattern này đã được tích hợp đúng trong RR-03 (StockTransfer), RR-04 (StockTake), RR-07 (Repair) — Damage là module duy nhất còn sót.

### 4. Không xử lý Serial/IMEI

DamageController không nhận `serial_ids` từ request, không update `serial_imeis.status`, không có cột `serial_ids` trên `damage_items`. Sản phẩm `has_serial` xuất hủy → `stock_quantity` lệch số serial in_stock.

### 5. Không có cancel

`DamageStatus` enum có `CANCELLED` nhưng:
- Không có method `cancel`/`destroy` trong DamageController.
- Không có route hủy.
- Khi tạo nhầm phiếu Damage → không có cách rollback.

---

## Test cases

### TC-RR09-01: Damage sản phẩm thường phải trừ tồn VÀ giảm `inventory_total_cost`

**Setup:**
- Product `has_serial=false`, `stock_quantity=10`, `cost_price=100_000`, `inventory_total_cost=1_000_000`.

**Action:** POST `/damages` với `status=completed`, `items=[{product_id, qty=3, cost_price=100_000, total_value=300_000}]`.

**Kỳ vọng:**
- `product.stock_quantity = 7`
- `product.inventory_total_cost = 700_000`
- `product.cost_price = 100_000` (giữ BQ)

### TC-RR09-02: Damage phải ghi StockMovement (`adjust_out`)

**Setup:** giống TC-01.

**Action:** Tạo Damage `status=completed`.

**Kỳ vọng:** Có 1 `StockMovement` với:
- `product_id = product.id`
- `type = 'adjust_out'` (hoặc `damage_out` nếu convention dùng)
- `qty = 3`
- `unit_cost ≈ 100_000`

### TC-RR09-03: Không cho Damage quá tồn

**Setup:** `stock_quantity = 10`. Damage `qty = 15`.

**Kỳ vọng:**
- Không tạo `Damage` với status='completed' (rollback).
- Stock không thay đổi.
- Không tạo StockMovement.

→ Hiện tại controller có check `if ($product->stock_quantity < $item['qty']) throw ...` → test này nên PASS.

### TC-RR09-04: Damage Serial/IMEI phải xử lý đúng serial được chọn

**Setup:**
- Product `has_serial=true`, có Serial A và Serial B đều `in_stock`.
- Request Damage với `items.*.serial_ids=[serialA.id]`, qty=1.

**Kỳ vọng:**
- Serial A: `status` chuyển sang `damaged`/`defective`/`returned` (theo enum hiện có).
- Serial B: không bị động tới (`status = 'in_stock'`).
- Có StockMovement.

→ Hiện tại controller không nhận `serial_ids` → test FAIL chứng minh limitation.

### TC-RR09-05: Hủy phiếu Damage phải rollback (nếu có)

**Setup:** Damage đã completed.

**Action:** Gọi cancel.

**Kỳ vọng:**
- Stock cộng lại.
- `inventory_total_cost` cộng lại.
- StockMovement đảo (`adjust_in`).
- Damage status = `cancelled`.
- Idempotent.

→ Hiện tại không có method/route cancel → test FAIL chứng minh limitation.

---

## Phạm vi sửa (Bước 14.1B kỳ vọng)

1. **`DamageController@store`**: thay `$product->stock_quantity -= ...` bằng `MovingAvgCostingService::applyAdjustment($product, -$qty)` + `StockMovementService::record(..., TYPE_ADJUST_OUT, ...)`. Pattern giống RR-04 (StockTake).
2. **Migration mới** (nếu hỗ trợ Serial): thêm `damage_items.serial_ids` JSON nullable.
3. **DamageController@store**: nếu product `has_serial`, nhận `serial_ids`, update `SerialImei` status, lưu `serial_ids` vào DamageItem.
4. **Method cancel + route mới**: `DamageController@cancel` đảo nghiệp vụ + route `damages.cancel`.
5. **Idempotent guard** trong cancel.
