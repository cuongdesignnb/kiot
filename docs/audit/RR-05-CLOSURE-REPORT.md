# RR-05 Closure Report — Giá vốn khi tồn về 0 phải nhất quán

> **Mã rủi ro:** RR-05
> **Mức độ ban đầu:** 🟡 P1 — High
> **Trạng thái cuối:** ✅ **Fixed/Verified**
> **Ngày đóng:** 02/05/2026
> **Test verification:** 59 PASS, 0 FAIL (9 RR-05 + 50 P0 regression)

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi gì:** `MovingAvgCostingService::applyPurchaseReturn` reset `cost_price = 0` khi `stock_quantity` về 0, trong khi `applySale` giữ BQ cuối. Hai method không nhất quán → trả NCC hết tồn xóa luôn last-known-average.
- **Root cause:** Tại `app/Services/MovingAvgCostingService.php` dòng 135:
  ```php
  $newAvg = $newQty > 0 ? round($newTotal / $newQty, 2) : 0.0;
  ```
  Pattern này khác với `applySale` dòng 79 (`: (float) $product->cost_price`), `applyAdjustment` dòng 191 (`: $bq`), và `applyRepairAdjustment` dòng 164 (`: (float) $product->cost_price`).
- **Ảnh hưởng:**
  - Trả NCC hết tồn → `cost_price = 0`. Nếu sau đó nhập lại lô mới, BQ chỉ phụ thuộc lô mới — bỏ qua context lịch sử.
  - Báo cáo so sánh `cost_price snapshot vs avg(serial cost)` (`ReportController.php:1077-1110`) hiển thị 0 cho sản phẩm đã trả hết → khó hiểu cho người đọc.
  - Vi phạm quy tắc 7 mục 3 trong `AGENT_RULES.md`: "Nếu số lượng tồn về 0, cách xử lý cost_price phải nhất quán".

### Ảnh hưởng tới sản phẩm thường

`applyPurchaseReturn(qty=hết tồn)` → `stock=0`, `total=0`, **`cost_price=0`** (sai). Khi nhập lại, BQ tính như mới, bỏ history.

### Ảnh hưởng tới Serial/IMEI

Cùng bug — vì COGS bán/trả serial dùng `product.cost_price` (BQ moving avg), per-IMEI cost chỉ phục vụ hiển thị/snapshot. Khi tất cả serial bị trả NCC, product `cost_price` = 0 → nhập lại serial mới sẽ không được moving average với BQ cũ.

---

## 2. Discovery Serial/IMEI

| Nội dung | Kết quả |
|---|---|
| Bảng serial | `serial_imeis` |
| Cột giá vốn serial | `cost_price` (current, có thể bị sửa khi sửa chữa), `original_cost` (giá nhập gốc snapshot, immutable), `sold_cost_price` (BQ tại lúc bán snapshot) |
| Snapshot bán per-serial | `invoice_item_serials.cost_price` |
| Product fields | `stock_quantity`, `cost_price`, `inventory_total_cost` (chuẩn moving average) |
| `recomputeFromSerials()` | Có — chỉ sync `stock_quantity`, **không đụng `cost_price`** (do `MovingAvgCostingService` quản) |
| Khi nhập serial dùng giá vốn nào | `serial.cost_price = serial.original_cost = unit_cost_allocated` + gọi `applyPurchase()` cập nhật product BQ |
| Khi bán serial dùng giá vốn nào | COGS = `product.cost_price` (BQ moving avg). Snapshot vào `invoice_item.cost_price` + `invoice_item_serials.cost_price` + `serial.sold_cost_price`. Per-IMEI `cost_price` **không** dùng để tính COGS |
| Khi trả NCC serial dùng giá vốn nào | `applyPurchaseReturn(qty, unit_cost_allocated)` từ `purchase_item` snapshot |
| Khi tồn về 0 xử lý cost_price thế nào | Trước fix: phụ thuộc method (applySale giữ, applyPurchaseReturn reset 0) ❌. **Sau fix: cả hai cùng giữ BQ cuối ✅** |
| Rủi ro phát hiện | Bug `cost_price = 0` áp dụng chung cho cả hàng thường và hàng serial (cùng product-level moving avg). **Không cần backlog riêng cho Serial/IMEI**, không đổi schema |

**Quy ước (theo comment `MovingAvgCostingService.php` dòng 16-17):**
> "Per-IMEI cost_price chỉ phục vụ HIỂN THỊ (giá vốn cuối + chênh lệch sửa chữa), KHÔNG ảnh hưởng COGS hay BQ sản phẩm."

---

## 3. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 12.1A** | Discovery + viết test chứng minh lỗi (5 unit + 4 feature serial) | `tests/Unit/Services/RR05MovingAvgCostingZeroStockTest.php`, `tests/Feature/Inventory/RR05SerialImeiCostingTest.php`, `docs/test-cases/RR-05-moving-average-zero-stock.md`, `docs/audit/STEP-12.1A-RR05-MOVING-AVG-ZERO-STOCK-TEST-RESULTS.md` | 6 PASS, 3 FAIL |
| **Step 12.1B** | Sửa `MovingAvgCostingService::applyPurchaseReturn` (1 dòng) + alignment TC-S3 sang approach 2 (gộp 1 lần với cost trung bình) | `app/Services/MovingAvgCostingService.php`, `tests/Feature/Inventory/RR05SerialImeiCostingTest.php`, `docs/audit/STEP-12.1B-RR05-MOVING-AVG-ZERO-STOCK-FIX-RESULTS.md` | 9 PASS, 0 FAIL |
| **Step 12.2** | Closure: cập nhật RISK_REGISTER + tạo closure report | `docs/audit/RISK_REGISTER.md`, `docs/audit/RR-05-CLOSURE-REPORT.md` (file này) | 59 PASS, 0 FAIL (9 RR-05 + 50 P0 regression) |

---

## 4. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Services/MovingAvgCostingService.php` | Business code | 1 dòng (135). Đổi fallback BQ khi `newQty=0` từ `0.0` sang `(float) $product->cost_price` (last-known average). Nhất quán với `applySale` dòng 79. |
| `tests/Feature/Inventory/RR05SerialImeiCostingTest.php` | Test alignment | TC-S3 đổi từ "2 lần `applyPurchaseReturn` riêng" sang "1 lần `applyPurchaseReturn(2, 6_000_000)` cost trung bình" (approach 2 trong spec doc). Cả hai approach đều ≠ 0; chọn approach gộp để khớp expected `6,000,000`. |

**Không sửa:** Service khác, Controller, Model, migration, schema Serial/IMEI, ProductController.

---

## 5. Test verification

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Assertions | Kết quả |
|---|---|---:|---:|---|
| RR-05 unit (sản phẩm thường) | `RR05MovingAvgCostingZeroStockTest.php` | 5 | 15 | ✅ **5 PASS** |
| RR-05 feature (Serial/IMEI) | `RR05SerialImeiCostingTest.php` | 4 | 16 | ✅ **4 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | 20 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | 9 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | 4 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | 4 | ✅ **4 PASS** |
| RR-03 stock transfer | `RR03StockTransferTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-03 stock transfer route | `RR03StockTransferRouteTest.php` | 3 | 10 | ✅ **3 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | 9 | ✅ **4 PASS** |
| RR-10 cashflow deletion | `RR10CashFlowDeletionTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-11 order return qty | `RR11OrderReturnQtyTest.php` | 4 | 8 | ✅ **4 PASS** |
| **Tổng** | | **59** | **131** | ✅ **59 PASS, 0 FAIL** |

---

## 6. Quy ước mới sau RR-05

### Quy tắc nhất quán giá vốn khi tồn về 0

| Tình huống | `stock_quantity` | `inventory_total_cost` | `cost_price` |
|---|---|---|---|
| `applySale` rút hết tồn | 0 | 0 | **giữ last-known average** ✅ |
| `applyPurchaseReturn` trả hết tồn | 0 | 0 | **giữ last-known average** ✅ (sau fix) |
| `applyAdjustment` rút hết tồn | 0 | 0 | giữ BQ cũ ✅ |
| `applyRepairAdjustment` (qty=0) | 0 | 0 | giữ BQ cũ ✅ |

### Quy tắc bắt buộc

1. **Khi `stock_quantity` về 0**, `cost_price` giữ last-known-average (không reset 0).
2. **`applySale` và `applyPurchaseReturn` phải nhất quán** quy ước này.
3. **`inventory_total_cost` về 0** khi qty=0, nhưng `cost_price` không reset 0.
4. **Sản phẩm Serial/IMEI dùng cùng quy ước** vì COGS lấy từ `product.cost_price` (BQ moving avg).
5. **Per-IMEI `cost_price` / `original_cost` / `sold_cost_price`** chỉ là snapshot/hiển thị — không tham gia tính BQ product.
6. **Không đổi schema Serial/IMEI** cho RR-05.
7. **Khi nhập lại sau khi tồn=0**, BQ mới sẽ là weighted average giữa lô mới và last-known-average — không bắt đầu từ 0.

---

## 7. Rủi ro còn lại đưa vào backlog

| # | Khu vực | Mô tả | Mức độ |
|---|---|---|---|
| 1 | `MovingAvgCostingService::applyPurchase` dòng 50 | `$newAvg = ... : 0.0` khi qty=0 — cosmetic, không xảy ra trong nghiệp vụ vì `applyPurchase` luôn tăng qty | Cosmetic / Backlog tùy chọn |
| 2 | `MovingAvgCostingService::applySaleReturn` dòng 107 | Cùng pattern cosmetic — `applySaleReturn` luôn tăng qty | Cosmetic / Backlog tùy chọn |
| 3 | `ProductController` admin serial CRUD raw `increment`/`decrement` (`storeSerial`, `bulkStoreSerials`, `updateSerial`, `destroySerial`) | Đã ghi nhận trong P0-AUDIT-SUMMARY backlog. Không thuộc luồng nhập/bán/trả nghiệp vụ. | P3 |
| 4 | `RR-12` multi-warehouse | Hủy phiếu chuyển kho `received` tính sai stock (limitation kiến trúc) | P1 — độc lập với RR-05 |
| 5 | Rounding edge-case | Chưa có test rounding cho cost_price quá nhỏ/quá lớn — không phát hiện vấn đề trong RR-05 | P3 |
| 6 | `RR-02` duplicate Invoice/POS | Logic bán hàng duplicate giữa InvoiceController và PosController | P1 |
| 7 | `RR-08` rollback serial trả hàng KH | Rollback bằng `whereNull('invoice_id')->limit($qty)` có thể chọn sai serial | P1 |
| 8 | `RR-09` Damage | Cần kiểm chứng có trừ tồn không, có qua CostingService không | P1 |
| 9 | `RR-06` customer_debt_transactions | Tách bảng + service | P2 |

---

## 8. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Quy tắc mục 3.7 — "Nếu số lượng tồn về 0, cách xử lý cost_price phải nhất quán" |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-05 = Fixed/Verified |
| `docs/test-cases/RR-05-moving-average-zero-stock.md` | Test case document (5 TC thường + 4 TC serial) |
| `docs/audit/STEP-12.1A-RR05-MOVING-AVG-ZERO-STOCK-TEST-RESULTS.md` | Test chứng minh lỗi (6 PASS, 3 FAIL) |
| `docs/audit/STEP-12.1B-RR05-MOVING-AVG-ZERO-STOCK-FIX-RESULTS.md` | Sửa lỗi (9 PASS, 0 FAIL) |
| `docs/audit/RR-05-CLOSURE-REPORT.md` | File này — closure report |
| `app/Services/MovingAvgCostingService.php` | Business code đã sửa |
| `tests/Unit/Services/RR05MovingAvgCostingZeroStockTest.php` | Unit test (5 PASS) |
| `tests/Feature/Inventory/RR05SerialImeiCostingTest.php` | Feature test Serial/IMEI (4 PASS) |

---

## 9. Kết luận

✅ **RR-05 đã Fixed/Verified.**

- Sửa đúng 1 dòng business code (`MovingAvgCostingService.php:135`) + 1 alignment test theo spec doc.
- 9/9 RR-05 tests PASS (5 unit + 4 feature serial).
- 50/50 P0 audit regression PASS (chạy từng filter riêng).
- Tổng **59/59 PASS, 0 FAIL**.
- Sản phẩm thường + sản phẩm Serial/IMEI đều dùng cùng quy ước nhất quán (last-known-average khi tồn=0).
- Không đổi schema, không sửa controller/model.
- Backlog cosmetic + RR-12/RR-02/RR-08/RR-09 vẫn nằm trong RISK_REGISTER, không bị ảnh hưởng.

### Tổng kết tiến độ audit

| Mã | Module | Mức | Trạng thái |
|---|---|---|---|
| RR-01 | Invoice cancel | P0 | ✅ Fixed/Verified |
| RR-02 | Invoice/POS duplicate | P1 | 🟡 Chưa xử lý |
| RR-03 | Stock transfer | P0 | ✅ Fixed/Verified |
| RR-04 | Stock take | P0 | ✅ Fixed/Verified |
| RR-05 | Costing zero stock | P1 | ✅ **Fixed/Verified (Bước 12.2)** |
| RR-06 | Customer debt | P2 | 🔵 Chưa xử lý |
| RR-07 | Repair parts | P0 | ✅ Fixed/Verified |
| RR-08 | OrderReturn rollback serial | P1 | 🟡 Chưa xử lý |
| RR-09 | Damage | P1 | 🟡 Cần kiểm chứng |
| RR-10 | CashFlow deletion | P0 | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | P0 | ✅ Fixed/Verified |
| RR-12 | StockTransfer multi-warehouse | P1 | 🟡 Chưa xử lý |

**Sẵn sàng chuyển sang P1 tiếp theo** (gợi ý theo thứ tự ưu tiên: RR-08 rollback serial → RR-09 Damage kiểm chứng → RR-02 duplicate logic → RR-12 multi-warehouse).
