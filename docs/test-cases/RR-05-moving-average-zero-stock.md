# RR-05 — Giá vốn khi tồn về 0 phải nhất quán, bao gồm sản phẩm thường và Serial/IMEI

> **Mã rủi ro:** RR-05
> **Mức độ:** P1 — Sai logic giá vốn / không nhất quán công thức
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Kiểm tra `MovingAvgCostingService` và luồng Serial/IMEI xử lý `cost_price` nhất quán khi `stock_quantity` về 0:

- Cùng quy ước cho `applySale` và `applyPurchaseReturn` (và các method liên quan).
- Sản phẩm thường và sản phẩm Serial/IMEI có hành vi nhất quán trên product-level moving average.

---

## Quy ước nghiệp vụ kỳ vọng

### Sản phẩm thường

| Method | qty > 0 (còn tồn) | qty = 0 (hết tồn) |
|---|---|---|
| `applyPurchase` | BQ = newTotal/newQty | (không xảy ra: qty tăng) |
| `applySale` | BQ giữ nguyên | **giữ BQ cũ** ✅ |
| `applySaleReturn` | BQ = newTotal/newQty | (không xảy ra: qty tăng) |
| `applyPurchaseReturn` | BQ = newTotal/newQty | **giữ BQ cũ** (kỳ vọng — hiện tại reset 0) |
| `applyAdjustment` | BQ giữ nguyên | giữ BQ cũ ✅ |
| `applyRepairAdjustment` | BQ tăng theo ΔC/qty | giữ BQ cũ ✅ |

→ **`applyPurchaseReturn` phải nhất quán với `applySale`**: khi tồn về 0, giữ BQ cuối cùng làm last-known-average.

### Sản phẩm Serial/IMEI

Schema hiện có:
- `serial_imeis.cost_price` — giá vốn current của serial (có thể bị sửa trong sửa chữa).
- `serial_imeis.original_cost` — giá nhập gốc snapshot (immutable).
- `serial_imeis.sold_cost_price` — BQ tại lúc bán (snapshot).
- `invoice_item_serials.cost_price` — BQ tại lúc bán (snapshot).
- `products.cost_price`, `stock_quantity`, `inventory_total_cost` — moving average cấp product.

Quy ước theo `MovingAvgCostingService.php` dòng 16-17:
> "Per-IMEI cost_price chỉ phục vụ HIỂN THỊ (giá vốn cuối + chênh lệch sửa chữa), KHÔNG ảnh hưởng COGS hay BQ sản phẩm."

→ COGS bán serial = `product.cost_price` (BQ moving avg), không phải per-IMEI.
→ Khi bán hết / trả NCC hết serial, `product.cost_price` phải tuân cùng quy ước nhất quán như sản phẩm thường.

---

## Nhóm 1: Sản phẩm thường (`tests/Unit/Services/RR05MovingAvgCostingZeroStockTest.php`)

### TC-RR05-01: `applySale` bán hết tồn phải giữ `cost_price`

**Dữ liệu:**
- `stock_quantity = 10`
- `cost_price = 100000`
- `inventory_total_cost = 1000000`
- Gọi `applySale($product, 10)`

**Kỳ vọng:**
- `stock_quantity = 0`
- `inventory_total_cost = 0`
- `cost_price = 100000` (giữ BQ cuối)

### TC-RR05-02: `applyPurchaseReturn` trả hết tồn phải giữ `cost_price` (nhất quán với `applySale`)

**Dữ liệu:**
- `stock_quantity = 10`
- `cost_price = 100000`
- `inventory_total_cost = 1000000`
- Gọi `applyPurchaseReturn($product, 10, 100000)`

**Kỳ vọng:**
- `stock_quantity = 0`
- `inventory_total_cost = 0`
- `cost_price = 100000` (giữ BQ cuối, **không reset 0**)

### TC-RR05-03: `applyPurchaseReturn` trả một phần vẫn giữ BQ đúng

**Dữ liệu:**
- `stock_quantity = 10`
- `cost_price = 100000`
- `inventory_total_cost = 1000000`
- Gọi `applyPurchaseReturn($product, 3, 100000)`

**Kỳ vọng:**
- `stock_quantity = 7`
- `inventory_total_cost = 700000`
- `cost_price = 100000`

### TC-RR05-04: Kết hợp — Mua → Bán hết → Trả NCC còn lại phải nhất quán

Để chứng minh vấn đề cross-method:

**Kịch bản A: bán hết → BQ giữ:**
- Sau `applyPurchase(10, 100000)`: stock=10, total=1M, BQ=100k
- Sau `applySale(10)`: stock=0, total=0, BQ=100000 ✅

**Kịch bản B: trả NCC hết → BQ reset:**
- Sau `applyPurchase(10, 100000)`: stock=10, total=1M, BQ=100k
- Sau `applyPurchaseReturn(10, 100000)`: stock=0, total=0, BQ=**0** ❌

→ Test khẳng định BQ ở kịch bản B phải bằng kịch bản A (= 100000).

### TC-RR05-05: `applyAdjustment` rút hết tồn giữ BQ (regression — đã đúng)

**Dữ liệu:**
- `stock_quantity = 10`, `cost_price = 100000`, `inventory_total_cost = 1000000`
- Gọi `applyAdjustment($product, -10)`

**Kỳ vọng:**
- `stock_quantity = 0`, `inventory_total_cost = 0`, `cost_price = 100000`

---

## Nhóm 2: Sản phẩm Serial/IMEI (`tests/Feature/Inventory/RR05SerialImeiCostingTest.php`)

### TC-RR05-S1: Discovery — schema serial cost

**Mục tiêu:** Khẳng định trên `serial_imeis` đang tồn tại 3 cột:
- `cost_price`
- `original_cost`
- `sold_cost_price`

→ Hệ thống có đủ infrastructure per-serial cost. Không cần đổi schema.

### TC-RR05-S2: Bán hết các serial của một product không được làm `cost_price` reset sai

**Dữ liệu:**
- Product `has_serial = true`
- 2 serial in_stock với `cost_price = 5_000_000` và `7_000_000` (qua `applyPurchase` 2 lần)
- Sau 2 lần `applyPurchase`: `stock_quantity = 2`, `inventory_total_cost = 12_000_000`, `cost_price = 6_000_000`
- Bán cả 2 (qua `applySale($product, 2)`)

**Kỳ vọng:**
- `stock_quantity = 0`, `inventory_total_cost = 0`
- `cost_price = 6_000_000` (giữ BQ cuối)

### TC-RR05-S3: Trả NCC hết các serial không được reset `cost_price` về 0 nếu `applySale` không reset

**Dữ liệu:**
- Product `has_serial = true`
- 2 serial in_stock với `original_cost = 5_000_000` và `7_000_000`
- Trả NCC cả 2 → mỗi serial gọi `applyPurchaseReturn($product, 1, original_cost)`
- Hoặc gọi 1 lần `applyPurchaseReturn($product, 2, 6_000_000)` — tương đương total = 12M

**Kỳ vọng:**
- `stock_quantity = 0`, `inventory_total_cost = 0`
- `cost_price = 6_000_000` (last known average, **không reset 0**)

### TC-RR05-S4: `recomputeFromSerials` không can thiệp `cost_price`

**Mục tiêu:** Sau khi `cost_price` đã được service set thành last known average, gọi `recomputeFromSerials()` không reset về 0.

---

## Limitation / Backlog

- **Không có cột giá vốn ở `purchase_return_items`** mà ở `purchase_items.unit_cost_allocated` (snapshot per-purchase). Test ở mức service trực tiếp, không qua controller.
- **Cost-tracking per-serial khi sửa chữa** đã được `MovingAvgCostingService::applyRepairAdjustment` xử lý → ngoài phạm vi RR-05.
- **Hành vi multi-warehouse** (RR-12) độc lập với RR-05.

---

## Phạm vi sửa (Bước 12.1B)

`app/Services/MovingAvgCostingService.php` — sửa duy nhất `applyPurchaseReturn` (dòng 135) để khi `newQty = 0`, giữ `cost_price` cũ giống `applySale` (dòng 79).

Tham chiếu — fix kỳ vọng:

```php
// applyPurchaseReturn — TRƯỚC
$newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;

// applyPurchaseReturn — SAU (nhất quán với applySale dòng 79)
$newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;
```

(Optional cho nhất quán toàn service — `applySaleReturn` và `applyPurchase` cũng có thể giữ BQ cũ khi qty=0, nhưng hai method này không bao giờ đưa qty về 0 trong nghiệp vụ thực tế nên ưu tiên thấp.)
