# STEP-12.1A — RR-05 Moving Average Zero Stock Test Results

> **Bước:** 12.1A — Viết test chứng minh RR-05 (giá vốn không nhất quán khi tồn về 0)
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ nghiên cứu + viết test. **Không sửa business code.**

---

## 1. Mục tiêu

Chứng minh `MovingAvgCostingService` xử lý `cost_price` không nhất quán khi `stock_quantity` về 0:
- `applySale` giữ BQ cũ ✅
- `applyPurchaseReturn` reset 0 ❌

Mở rộng phạm vi sang sản phẩm Serial/IMEI vì giá vốn và mô hình tồn kho có thể khác hàng thường.

---

## 2. MovingAvgCostingService discovery

| Method | Hành vi khi `newQty = 0` | Dòng | Đánh giá |
|---|---|---:|---|
| `applyPurchase` | `newAvg = 0.0` | 50 | Không xảy ra trong thực tế (qty tăng) — quy ước khô khan, chấp nhận được |
| `applySale` | `newAvg = (float) $product->cost_price` (giữ BQ cũ) | 79 | ✅ Đúng — đây là quy ước chuẩn |
| `applySaleReturn` | `newAvg = 0.0` | 107 | Không xảy ra trong thực tế (qty tăng) |
| `applyPurchaseReturn` | `newAvg = 0.0` | 135 | ❌ **BUG RR-05 — không nhất quán với `applySale`** |
| `applyRepairAdjustment` | `newAvg = (float) $product->cost_price` | 164 | ✅ Đúng |
| `applyAdjustment` | `newAvg = $bq` (giữ BQ cũ) | 191 | ✅ Đúng |

**Kết luận:** Bug duy nhất là `applyPurchaseReturn` (dòng 135). Sửa đề xuất ở Bước 12.1B:

```php
// TRƯỚC
$newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;

// SAU (nhất quán với applySale dòng 79)
$newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;
```

---

## 3. Serial/IMEI costing discovery

| Nội dung | Kết quả |
|---|---|
| Bảng serial | `serial_imeis` (migration `2026_02_27_085836_create_serial_imeis_table.php`) |
| Cột giá vốn serial | 3 cột — `cost_price` (giá vốn current, có thể bị sửa), `original_cost` (giá nhập gốc snapshot, immutable, migration `2026_03_30_000001`), `sold_cost_price` (BQ tại lúc bán, snapshot, migration `2026_04_26_000001`) |
| Bảng snapshot bán | `invoice_item_serials` có `cost_price` (BQ tại lúc bán per-serial, migration `2026_04_26_000002`) |
| Product fields | `stock_quantity`, `cost_price`, `inventory_total_cost` (chuẩn moving average) |
| Có `recomputeFromSerials` không | ✅ Có — `app/Models/Product.php:126`. Chỉ sync `stock_quantity` theo serial `in_stock` count. **Không đụng `cost_price`** (BQ moving avg do `MovingAvgCostingService` quản) |
| Khi nhập serial dùng giá vốn nào | `PurchaseController.php:303-310` — set serial `cost_price = unitCostAllocated`, `original_cost = unitCostAllocated`, đồng thời gọi `MovingAvgCostingService::applyPurchase($product, qty, unitCostAllocated)` để cập nhật BQ product |
| Khi bán serial dùng giá vốn nào | `InvoiceController.php:215, 251` + `PosController.php:144, 166` — `$snapshotCostPrice = $product->cost_price` (BQ moving avg). Lưu vào `invoice_item.cost_price` + `invoice_item_serials.cost_price` + `serial.sold_cost_price`. Per-IMEI `cost_price` **không** dùng để tính COGS |
| Khi trả NCC serial dùng giá vốn nào | `PurchaseReturnController.php:214` + `PurchaseController.php:672` — gọi `MovingAvgCostingService::applyPurchaseReturn($product, qty, unit_cost_allocated)`. Update serial status `returned` |
| Khi tồn về 0 xử lý `cost_price` thế nào | **Phụ thuộc method**: bán hết qua `applySale` → giữ BQ cũ ✅. Trả NCC hết qua `applyPurchaseReturn` → reset 0 ❌. **Đây là biểu hiện của RR-05 ở mức product, áp dụng cho cả hàng thường và hàng có serial** |
| Rủi ro phát hiện | (1) `applyPurchaseReturn` reset BQ → mất last-known average. (2) Nếu sau đó nhập lại, BQ mới chỉ phụ thuộc lô mới, bỏ qua cost lịch sử. (3) Báo cáo so sánh `cost_price snapshot vs avg(serial.cost_price WHERE in_stock)` (`ReportController.php:1077-1110`) sẽ hiển thị 0 cho sản phẩm đã trả hết → khó hiểu |

**Quy ước theo comment của `MovingAvgCostingService` dòng 16-17:**
> "Per-IMEI cost_price chỉ phục vụ HIỂN THỊ (giá vốn cuối + chênh lệch sửa chữa), KHÔNG ảnh hưởng COGS hay BQ sản phẩm."

→ COGS bán serial = `product.cost_price` (BQ moving avg). Vấn đề `cost_price` reset 0 áp dụng cho **cả hàng thường và hàng serial** — không cần tách backlog riêng cho serial. Schema serial đã đủ để test.

---

## 4. Dữ liệu test

### Sản phẩm thường (Unit test)

| Tham số | Giá trị |
|---|---|
| Product | created via `Product::create()` |
| `stock_quantity` | 10 |
| `cost_price` | 100,000 |
| `inventory_total_cost` | 1,000,000 |
| Operations | `applySale(10)`, `applyPurchaseReturn(10, 100000)`, `applyAdjustment(-10)`, `applyPurchaseReturn(3, 100000)` |

### Sản phẩm Serial/IMEI (Feature test)

| Tham số | Giá trị |
|---|---|
| Product | `has_serial = true` |
| Serials | 2 IMEI: A (cost=5,000,000), B (cost=7,000,000) |
| Sau 2 lần `applyPurchase(1, 5M)` + `applyPurchase(1, 7M)` | stock=2, total=12M, BQ=6M |
| Operations | `applySale(2)` (TC-S2), `applyPurchaseReturn(1, 5M)` + `applyPurchaseReturn(1, 7M)` (TC-S3) |

---

## 5. Test đã tạo

### `tests/Unit/Services/RR05MovingAvgCostingZeroStockTest.php` — 5 test

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `apply sale to zero should keep cost price` | cost_price = 100,000 | 100,000 | ✅ PASS |
| `apply purchase return to zero should keep cost price` | cost_price = 100,000 | **0.0** | ❌ FAIL |
| `apply purchase return partial should keep avg` | cost_price = 100,000 | 100,000 | ✅ PASS |
| `sale zero and purchase return zero should be consistent` | cost_price A = cost_price B | 100,000 vs 0.0 | ❌ FAIL |
| `apply adjustment to zero should keep cost price` | cost_price = 100,000 | 100,000 | ✅ PASS |

### `tests/Feature/Inventory/RR05SerialImeiCostingTest.php` — 4 test

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `serial imei schema has cost columns` | 4 cột tồn tại | 4 cột tồn tại | ✅ PASS |
| `selling all serials should keep product cost price` | cost_price = 6,000,000 | 6,000,000 | ✅ PASS |
| `purchase returning all serials should keep product cost price` | cost_price = 6,000,000 | **0.0** | ❌ FAIL |
| `recompute from serials does not touch cost price` | cost_price = 6,000,000 | 6,000,000 | ✅ PASS |

---

## 6. Kết quả chạy test

| Suite | Tests | PASS | FAIL | SKIP |
|---|---:|---:|---:|---:|
| `RR05MovingAvgCostingZeroStockTest` | 5 | 3 | 2 | 0 |
| `RR05SerialImeiCostingTest` | 4 | 3 | 1 | 0 |
| **Tổng RR-05** | **9** | **6** | **3** | **0** |

```
RR05MovingAvgCostingZeroStockTest:
  Tests:    2 failed, 3 passed (13 assertions)
  Duration: 0.47s

RR05SerialImeiCostingTest:
  Tests:    1 failed, 3 passed (16 assertions)
  Duration: 0.46s
```

→ **3 test FAIL chứng minh RR-05** (2 ở mức service unit, 1 ở mức feature serial).

---

## 7. Nguyên nhân fail

**Method reset `cost_price = 0` khi qty=0:**
- `MovingAvgCostingService::applyPurchaseReturn` dòng 135:
  ```php
  $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;
  ```

**Method giữ `cost_price` cũ khi qty=0 (đúng):**
- `MovingAvgCostingService::applySale` dòng 79:
  ```php
  $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;
  ```
- `MovingAvgCostingService::applyAdjustment` dòng 191
- `MovingAvgCostingService::applyRepairAdjustment` dòng 164

**Serial/IMEI có đủ dữ liệu cost để test không:**
✅ Có. Schema đã có `cost_price`, `original_cost`, `sold_cost_price` trên `serial_imeis` + `cost_price` trên `invoice_item_serials`. Các luồng nhập/bán/trả đã wire đúng. Vấn đề `cost_price = 0` là do logic chung ở `applyPurchaseReturn`, áp dụng cho cả hàng thường và hàng serial → không cần tách backlog riêng.

**Limitation:**
- Hành vi multi-warehouse (RR-12) độc lập với RR-05.
- Sửa chữa per-serial qua `applyRepairAdjustment` đã đúng → ngoài phạm vi.

---

## 8. P0 regression

Chạy lại 10 P0 audit test filter (theo Bước 11 chuẩn — chạy từng filter riêng để tránh test isolation noise):

| # | Test | Kết quả |
|---|---|---|
| 1 | `CancelInvoiceTest` | ✅ 10 PASS (1.10s) |
| 2 | `RR01ReportControllerRegressionTest` | ✅ 8 PASS (0.62s) |
| 3 | `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (0.37s) |
| 4 | `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (0.41s) |
| 5 | `RR03StockTransferTest` | ✅ 5 PASS (0.66s) |
| 6 | `RR03StockTransferRouteTest` | ✅ 3 PASS (0.56s) |
| 7 | `RR04StockTakeTest` | ✅ 5 PASS (0.67s) |
| 8 | `RR07RepairPartsTest` | ✅ 4 PASS (0.57s) |
| 9 | `RR10CashFlowDeletionTest` | ✅ 5 PASS (0.47s) |
| 10 | `RR11OrderReturnQtyTest` | ✅ 4 PASS (0.60s) |
| | **Tổng** | ✅ **50 PASS, 0 FAIL** |

**Lưu ý:** Khi chạy 10 filter trong **một lần** `php artisan test --filter="A|B|..."`, có 1 test trong `RR11OrderReturnQtyTest` (`can return exact remaining quantity`) hiển thị FAIL do test isolation pre-existing (state leak giữa các test class lớn). Chạy riêng từng filter → 50/50 PASS. Đây không phải hồi quy do RR-05 — không có file business code nào bị sửa.

---

## 9. Kết luận

✅ **RR-05 đã được chứng minh bằng test.**

- **Sản phẩm thường:** 2/5 test FAIL chứng minh `applyPurchaseReturn` reset `cost_price = 0` khi tồn về 0, không nhất quán với `applySale`.
- **Sản phẩm Serial/IMEI:** 1/4 test FAIL chứng minh cùng bug ở mức product BQ — schema serial đầy đủ, không cần backlog riêng.
- **P0 regression:** 50/50 PASS khi chạy từng filter riêng → không có hồi quy do bước này (vì không sửa business code).

**Phạm vi sửa Bước 12.1B (kỳ vọng):**

| File | Dòng | Thay đổi |
|---|---|---|
| `app/Services/MovingAvgCostingService.php` | 135 | `$newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : (float) $product->cost_price;` |

**Đủ điều kiện chuyển sang Bước 12.1B?** ✅ Có.

**Backlog riêng cho Serial/IMEI?** ❌ Không cần. Schema đầy đủ, bug nằm ở quy ước chung tại `MovingAvgCostingService`. Sau khi sửa Bước 12.1B, cả hàng thường và hàng serial đều dùng cùng quy ước nhất quán.

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Quy tắc mục 3 (giá vốn) — cụ thể mục 7 nói rõ "Nếu số lượng tồn về 0, cách xử lý cost_price phải nhất quán" |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-05 ghi rõ vấn đề |
| `docs/test-cases/RR-05-moving-average-zero-stock.md` | Test case document (5 TC thường + 4 TC serial) |
| `tests/Unit/Services/RR05MovingAvgCostingZeroStockTest.php` | Unit test sản phẩm thường |
| `tests/Feature/Inventory/RR05SerialImeiCostingTest.php` | Feature test sản phẩm Serial/IMEI |
| `app/Services/MovingAvgCostingService.php` | Service cần sửa (dòng 135) |
