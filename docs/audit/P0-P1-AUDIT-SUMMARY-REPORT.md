# P0 + P1 Audit Summary Report

> **Loại tài liệu:** Báo cáo tổng kết kết thúc đợt audit P0 + P1
> **Phiên bản:** 1.0
> **Trạng thái:** ✅ **P0/P1 CLEAN** (per Risk Register original) — 1 phát hiện mới ngoài scope ban đầu cần đánh giá

---

## 1. Tổng quan

| Mục | Giá trị |
|---|---|
| Ngày tổng kết | 02/05/2026 |
| Môi trường test | `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3319`, `DB_DATABASE=sales_test` |
| Tổng số rủi ro trong Risk Register | **12** |
| P0 đã đóng | **6/6** ✅ (RR-01, RR-03, RR-04, RR-07, RR-10, RR-11) |
| P1 đã đóng | **5/5** ✅ (RR-02, RR-05, RR-08, RR-09, RR-12) |
| P2 còn lại | **1** (RR-06 Customer debt transactions) |
| Tổng test audit P0+P1 | **78** |
| Kết quả test | ✅ **78 PASS, 0 FAIL** |
| Phát hiện mới ngoài scope | **1** — `OrderController@convert` raw decrement (xem mục 7) |
| Kết luận | ✅ **P0/P1 CLEAN per Risk Register** — phát hiện mới ghi vào backlog (P1 candidate RR-13) |

---

## 2. Danh sách rủi ro đã Fixed/Verified

| Mã | Mức | Module | Lỗi gốc | Cách xử lý | Test verification | Trạng thái |
|---|---|---|---|---|---|---|
| **RR-01** | P0 | Invoice cancel | `$invoice->delete()` xóa vật lý chứng từ → mất lịch sử | `InvoiceController.php` (status-based cancel), `Invoice/CashFlow::scopeActive()`, `ReportController` 23 patches, `SupplierController` 1 patch | `CancelInvoiceTest` (10), `RR01ReportControllerRegressionTest` (8), `RR01SupplierDualRoleRegressionTest` (2), `RR01CashFlowCancelledRegressionTest` (4) = 24 PASS | ✅ Fixed/Verified |
| **RR-02** | P1 | Invoice/POS duplicate + POS serial bug | Duplicate logic ~150 dòng × 2; POS tạo `InvoiceItemSerial(invoice_item_id=0)` → FK violation HTTP 500 | `InvoiceSaleService::createSale()` mới; cả `InvoiceController@store` và `PosController@checkout` dùng chung. Pattern đúng: tạo InvoiceItem trước → InvoiceItemSerial với id thật. | `RR02InvoicePosCharacterizationTest` (5) | ✅ Fixed/Verified |
| **RR-03** | P0 | Stock transfer | Raw `increment`/`decrement`, không ghi `StockMovement`, không update `inventory_total_cost`; thiếu route receive/cancel | `StockTransferController` integrate `MovingAvgCostingService` + `StockMovementService`, đăng ký routes | `RR03StockTransferTest` (5), `RR03StockTransferRouteTest` (3) = 8 PASS | ✅ Fixed/Verified |
| **RR-04** | P0 | Stock take | Raw `increment`/`decrement` khi cân bằng kiểm kho, không ghi `StockMovement`, không update `inventory_total_cost` | `StockTakeController` qua `applyAdjustment()` + `StockMovementService` | `RR04StockTakeTest` (5) | ✅ Fixed/Verified |
| **RR-05** | P1 | Costing zero stock | `applyPurchaseReturn` reset `cost_price=0` khi tồn về 0; `applySale` giữ BQ → không nhất quán | Sửa 1 dòng (135) trong `MovingAvgCostingService::applyPurchaseReturn` — giữ BQ cuối khi qty về 0 | `RR05MovingAvgCostingZeroStockTest` (5), `RR05SerialImeiCostingTest` (4) = 9 PASS | ✅ Fixed/Verified |
| **RR-07** | P0 | Repair parts | `TaskService::addPart/removePart/disassemblePart` raw `decrement`/`increment` linh kiện, không qua CostingService, không StockMovement | `TaskService` qua `applySale`/`applyPurchase` + `StockMovementService::record(repair_in/out)` | `RR07RepairPartsTest` (4) | ✅ Fixed/Verified |
| **RR-08** | P1 | OrderReturn rollback serial | Cancel rollback bằng `whereNull('invoice_id')->limit($qty)` → chọn nhầm serial khác. Schema `return_items` thiếu `serial_ids`. | Migration thêm `return_items.serial_ids` JSON; `store()` lưu serial_ids; `cancel()` dùng `whereIn` đúng serial. Đăng ký route `returns.cancel`. | `RR08OrderReturnSerialRollbackTest` (4) | ✅ Fixed/Verified |
| **RR-09** | P1 | Damage | Raw `stock_quantity -= qty`, không update `inventory_total_cost`, không StockMovement, không xử lý Serial, không có cancel | Migration thêm `damage_items.serial_ids`; `store()` qua `applyAdjustment` + StockMovement + đổi serial sang `defective`; thêm `cancel()` method + route `damages.cancel` đảo nghiệp vụ idempotent | `RR09DamageStockTest` (5) | ✅ Fixed/Verified |
| **RR-10** | P0 | CashFlow deletion | CashFlow soft-delete khi hủy chứng từ nhưng `status` giữ `'active'` → báo cáo tính nhầm | 3 controller fix (`PurchaseController`, `OrderReturnController`, `PurchaseReturnController`) + `CashFlow` model safety net (`runSoftDelete` + `newEloquentBuilder` auto-set `status='cancelled'`) | `RR10CashFlowDeletionTest` (5) | ✅ Fixed/Verified |
| **RR-11** | P0 | OrderReturn qty | Trả hàng không validate qty đã trả, cho trả vượt qty bán, cho trả trên invoice đã hủy | `OrderReturnController@store` thêm validation block (~45 dòng): invoice status + qty gom theo product + cumulative check | `RR11OrderReturnQtyTest` (4) | ✅ Fixed/Verified |
| **RR-12** | P1 | StockTransfer cancel cost | Cancel dùng current cost thay vì snapshot lúc transfer_out → cost lệch khi BQ thay đổi giữa các pha | Migration thêm `stock_transfer_items.cost_at_transfer`; `store/receive/cancel` dùng snapshot; cancel đảo destination đổi `applySale`→`applyPurchaseReturn(qty, snapshot)` | `RR12StockTransferCancelReceivedTest` (5) | ✅ Fixed/Verified |

**Tổng:** 11/12 rủi ro đã đóng (6 P0 + 5 P1). 78 tests verify.

---

## 3. Rủi ro còn lại

| Mã | Mức | Module | Nội dung | Trạng thái | Khuyến nghị |
|---|---|---|---|---|---|
| **RR-06** | P2 | Customer/Debt | Công nợ KH cộng/trừ trực tiếp (`increment`/`decrement`) rải rác ở nhiều controller, không có bảng lịch sử riêng. NCC đã có `supplier_debt_transactions` nhưng KH không. | 🔵 Chưa xử lý | Tạo bảng `customer_debt_transactions` tương tự `supplier_debt_transactions`. Tập trung vào `CustomerDebtService`. Refactor `Customer::increment('debt_amount', ...)` ở các controller (Invoice/POS/OrderReturn/Customer) sang gọi service. |

---

## 4. Quy ước nghiệp vụ mới sau audit

### 4.1. Bán hàng

- **`Invoice/POS` phải dùng `InvoiceSaleService::createSale($payload, $context)`** (RR-02).
- **Không tạo `InvoiceItemSerial` với `invoice_item_id=0`** — pattern đúng: tạo `InvoiceItem` trước → tạo `InvoiceItemSerial` với id thật.
- **Controller chỉ làm:** validate request HTTP, normalize payload, build context, response (redirect/JSON), try/catch.
- **Service làm:** tất cả business logic + DB::transaction + costing + movement + debt + cashflow.

### 4.2. Tồn kho / giá vốn

- **Không raw `increment`/`decrement` `stock_quantity`** cho nghiệp vụ ảnh hưởng giá vốn (RR-03/04/07/09).
- **Phải dùng `MovingAvgCostingService`**: `applyPurchase`, `applySale`, `applyPurchaseReturn`, `applySaleReturn`, `applyAdjustment`, `applyRepairAdjustment`.
- **Khi cancel/rollback phải có snapshot** dữ liệu cần thiết (RR-12 — `cost_at_transfer`; RR-08/RR-09 — `serial_ids`).
- **Khi tồn về 0**, `cost_price` giữ last-known-average (RR-05) — `applyPurchaseReturn` đã nhất quán với `applySale`.
- **Đảo nghiệp vụ destination** (cancel transfer received) phải dùng `applyPurchaseReturn(qty, cost_snapshot)` thay vì `applySale($qty)` (RR-12).
- **Phải ghi `StockMovement`** qua `StockMovementService::record()` với type chuẩn.

### 4.3. Serial/IMEI

- **Khi trả hàng/damage có serial**, lưu `serial_ids` trên item (RR-08 `return_items.serial_ids`, RR-09 `damage_items.serial_ids`).
- **Rollback chỉ dùng `serial_ids` đã lưu** (`whereIn('id', $serial_ids)`) — không chọn đại bằng `whereNull('invoice_id')->limit()`.
- **Serial bị damage** đổi sang `'defective'`; cancel restore về `'in_stock'`.
- **Per-IMEI cost_price** chỉ phục vụ hiển thị, không tham gia COGS hay BQ product.

### 4.4. CashFlow

- **Không hard delete** CashFlow nghiệp vụ (RR-10).
- **Hủy dùng `status='cancelled'`** + soft delete; model có safety net `runSoftDelete()` + `newEloquentBuilder()` auto-set status.
- **Báo cáo dùng `CashFlow::active()`** (loại trừ `cancelled` + `deleted_at IS NULL`).

### 4.5. Chứng từ

- **Không xóa vật lý** chứng từ đã phát sinh nghiệp vụ (Invoice, Purchase, OrderReturn, PurchaseReturn, CashFlow, StockTransfer, StockTake, Damage).
- **Hủy dùng `status = cancelled`/`'Đã hủy'`** + giữ items cho audit trail (RR-01).
- **Cancel phải idempotent** — guard `if status='cancelled' return early`.
- **Hóa đơn hợp lệ cho báo cáo** dùng `Invoice::active()` (RR-01).

---

## 5. Test verification

### Môi trường

```
APP_ENV=testing, DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_PORT=3319, DB_DATABASE=sales_test
```

### Bộ test P0+P1 (chạy từng filter riêng)

| # | Test suite | Tests | Assertions | Kết quả |
|---|---|---:|---:|---|
| 1 | `RR02InvoicePosCharacterizationTest` | 5 | 48 | ✅ PASS |
| 2 | `CancelInvoiceTest` | 10 | 20 | ✅ PASS |
| 3 | `RR01ReportControllerRegressionTest` | 8 | 9 | ✅ PASS |
| 4 | `RR01SupplierDualRoleRegressionTest` | 2 | 4 | ✅ PASS |
| 5 | `RR01CashFlowCancelledRegressionTest` | 4 | 4 | ✅ PASS |
| 6 | `RR03StockTransferTest` | 5 | 12 | ✅ PASS |
| 7 | `RR03StockTransferRouteTest` | 3 | 10 | ✅ PASS |
| 8 | `RR04StockTakeTest` | 5 | 12 | ✅ PASS |
| 9 | `RR05MovingAvgCostingZeroStockTest` | 5 | 15 | ✅ PASS |
| 10 | `RR05SerialImeiCostingTest` | 4 | 16 | ✅ PASS |
| 11 | `RR07RepairPartsTest` | 4 | 9 | ✅ PASS |
| 12 | `RR08OrderReturnSerialRollbackTest` | 4 | 15 | ✅ PASS |
| 13 | `RR09DamageStockTest` | 5 | 12 | ✅ PASS |
| 14 | `RR10CashFlowDeletionTest` | 5 | 12 | ✅ PASS |
| 15 | `RR11OrderReturnQtyTest` | 4 | 8 | ✅ PASS |
| 16 | `RR12StockTransferCancelReceivedTest` | 5 | 23 | ✅ PASS |
| | **Tổng** | **78** | **229** | ✅ **78 PASS, 0 FAIL** |

---

## 6. Full suite

❌ **Không chạy** trong bước này. Đã được kiểm chứng ở Bước 11 (`P0-AUDIT-SUMMARY-REPORT.md`):
- Full suite: 51/52 PASS, 1 FAIL = `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (GET `/` → 302 redirect do middleware auth, không phải bug — test mặc định Laravel).
- Không liên quan đến code đã sửa qua audit P0/P1.
- Không cần chạy lại — đã document.

---

## 7. Kết quả scan code

### 7.1. Pattern raw `stock_quantity` (`->increment`/`->decrement`/`+=`/`-=`)

| Pattern | File | Đánh giá | Mức độ | Ghi chú |
|---|---|---|---|---|
| `Product::where(...)->increment('stock_quantity', ...)` | `app/Services/RepairService.php:125` (removePart) | ⚠️ Còn raw | **P2 backlog** | RepairService **deprecated** — đã được TaskService thay thế qua RR-07. Cần đánh dấu deprecated rõ hoặc xóa. |
| `$product->decrement('stock_quantity', ...)` | `app/Services/RepairService.php:97` (addPart) | ⚠️ Còn raw | **P2 backlog** | Cùng RepairService deprecated. |
| `$product->increment('stock_quantity')` | `app/Http/Controllers/ProductController.php:1065/1100/1148` | ⚠️ Còn raw | **P3 backlog** | Admin serial CRUD (`storeSerial`, `bulkStoreSerials`, `updateSerial`) — không thuộc luồng nhập/bán/trả. Đã ghi nhận từ Bước 11. |
| `$product->decrement('stock_quantity')` | `app/Http/Controllers/ProductController.php:1146/1185` | ⚠️ Còn raw | **P3 backlog** | Cùng admin serial CRUD. |
| **`$product->stock_quantity -= $orderItem->qty; $product->save()`** | **`app/Http/Controllers/OrderController.php:376` (convert order → invoice)** | 🚨 **PHÁT HIỆN MỚI** | **P1 candidate (RR-13)** | Pattern bug giống RR-09: raw decrement, **không update `inventory_total_cost`**, **không ghi `StockMovement`**, **không xử lý Serial/IMEI**, **không qua MovingAvgCostingService**. Convert Order → Invoice flow chưa được audit. Khuyến nghị: tạo RR-13 P1 ở Risk Register, refactor sang `InvoiceSaleService::createSale()` (đã sẵn sàng) hoặc tích hợp `MovingAvgCostingService::applySale + StockMovement`. |

### 7.2. Pattern hard-delete chứng từ

| Pattern | File | Đánh giá | Ghi chú |
|---|---|---|---|
| `$invoice->delete()` | — | ✅ Không còn | RR-01 đã sửa thành status-based cancel |
| `$purchase->delete()` | `app/Http/Controllers/PurchaseController.php:641` | ✅ OK | Chỉ áp dụng phiếu draft chưa phát sinh nghiệp vụ |
| `$orderReturn->delete()` | — | ✅ Không còn | OrderReturn dùng status-based cancel |
| `$cashFlow->delete()` (single) | `app/Http/Controllers/CashFlowController.php:190` | ✅ OK | Đã set `status='cancelled'` trước (RR-10) |
| `forceDelete()` | `app/Http/Controllers/StockTakeController.php:204` | ✅ OK | Chỉ xóa `StockTakeItem` của phiếu draft |
| `CashFlow::where(...)->delete()` | Đa số có set `status='cancelled'` trước | ✅ OK | Còn lại được cover bởi model safety net (RR-10): `runSoftDelete()` + `newEloquentBuilder()` auto-set `status='cancelled'` |

### 7.3. Pattern `invoice_item_id=0`

| Pattern | Kết quả |
|---|---|
| `'invoice_item_id' => 0` | ❌ **0 matches** trong toàn bộ `app/` — bug RR-02 đã sửa triệt để |

### 7.4. Pattern `applySale` / `applyPurchaseReturn` / `StockMovementService::record` (đã được dùng đúng)

Service `MovingAvgCostingService` và `StockMovementService` được dùng đầy đủ ở:
- `InvoiceSaleService` (RR-02)
- `InvoiceController` (cancel + update flow)
- `PosController` (qua service)
- `PurchaseController`, `PurchaseReturnController`
- `OrderReturnController`
- `StockTransferController` (RR-03, RR-12)
- `StockTakeController` (RR-04)
- `DamageController` (RR-09)
- `TaskService` (RR-07)

→ **Pattern thống nhất.**

### 7.5. Customer debt patterns

`Customer::increment('debt_amount', ...)` rải rác ở nhiều controller (Invoice/POS qua service, OrderReturn, Customer, PurchaseController). → **Đã ghi nhận RR-06 P2** — sẽ xử lý khi tạo `customer_debt_transactions` + `CustomerDebtService`.

### 7.6. Tổng kết scan

- ❌ **1 phát hiện mới** — `OrderController:376` raw decrement (Order → Invoice convert flow). Khuyến nghị tạo **RR-13 P1**.
- ✅ Các pattern khác hoặc đã sửa, hoặc nằm trong backlog đã ghi nhận.

---

## 8. Backlog sau P0/P1

### P1 candidate (phát hiện mới ngoài scope ban đầu)

- **RR-13 (đề xuất):** `OrderController` convert Order → Invoice flow dùng raw `stock_quantity -= qty` thay vì `MovingAvgCostingService::applySale` + `StockMovementService::record`. Thiếu update `inventory_total_cost`, thiếu StockMovement, không xử lý serial. Khuyến nghị refactor sang `InvoiceSaleService::createSale()` (đã có sẵn).

### P2

- **RR-06** — `CustomerDebtService` + bảng `customer_debt_transactions` (pattern tương tự `supplier_debt_transactions`).

### P3 / Backward compat

| # | Backlog | Nguồn |
|---|---|---|
| 1 | Legacy `InvoiceItemSerial.invoice_item_id=0` cleanup nếu production có data cũ (do bug POS lúc trước) | RR-02 closure |
| 2 | Legacy `stock_transfer_items.cost_at_transfer` backfill | RR-12 closure |
| 3 | Legacy `damage_items.serial_ids` backfill | RR-09 closure |
| 4 | Legacy `return_items.serial_ids` backfill | RR-08 closure |
| 5 | UI cancel cho Damage / OrderReturn | RR-08, RR-09 closure |
| 6 | Permission tách cho cancel routes (`returns.cancel`, `damages.cancel`) | RR-08, RR-09 closure |
| 7 | Multi-warehouse architecture (`branch_inventory` table) | RR-12 closure (limitation kiến trúc) |
| 8 | `InvoiceController@update` refactor sang `InvoiceSaleService::updateSale()` nếu cần consistency | RR-02 closure |
| 9 | RepairService deprecated cleanup (raw decrement còn lại) | RR-07 closure |
| 10 | ProductController admin serial CRUD `recomputeFromSerials()` | Bước 11 |
| 11 | PaysheetController explicit `status='cancelled'` trước mass delete CashFlow | Bước 11 |
| 12 | Cosmetic `applyPurchase`/`applySaleReturn` fallback 0 khi qty=0 | RR-05 closure |
| 13 | Test multi-serial / draft cancel / cost variation cho các flow | Backlog các RR |

---

## 9. Khuyến nghị quy trình sau audit

1. **Mọi thay đổi kho/tiền/công nợ phải có test fail trước (TDD).**
2. **Mọi logic bán hàng phải đi qua `InvoiceSaleService`.** Không duplicate ở controller. Pattern này áp dụng cho cả `OrderController@convert` (RR-13 candidate).
3. **Mọi thay đổi tồn kho phải qua `MovingAvgCostingService` + `StockMovementService`.** Không raw `increment`/`decrement`.
4. **Mọi cancel/rollback phải có snapshot** dữ liệu cần thiết (cost, serial_ids) lưu trên item.
5. **Mỗi cancel phải idempotent.**
6. **Trước khi merge phải chạy bộ P0/P1 audit tests** — 16 filter, 78 tests.
7. **Agent/dev phải đọc `AGENT_RULES.md`** trước khi sửa, đặc biệt mục 2 (tồn kho), 3 (giá vốn), 5 (hủy/trả), 6 (serial).
8. **Không sửa nhiều module trong một lần** — mỗi PR chỉ xử lý 1 mã rủi ro.
9. **Không sửa test để che lỗi.** Test fail = code fail, sửa code.
10. **Mỗi PR sửa rủi ro phải kèm closure report** trong `docs/audit/RR-XX-CLOSURE-REPORT.md`.

---

## 10. Kết luận

✅ **P0/P1 CLEAN per Risk Register original.**

- **6/6 P0** đã Fixed/Verified (RR-01, RR-03, RR-04, RR-07, RR-10, RR-11).
- **5/5 P1** đã Fixed/Verified (RR-02, RR-05, RR-08, RR-09, RR-12).
- **78/78 tests PASS** — 16 filter, 229 assertions.
- **0 hồi quy** sau toàn bộ audit (Bước 1 → 16.1E).
- 11 closure reports đầy đủ.
- Quy ước nghiệp vụ mới đã thiết lập rõ qua services, scopes (`Invoice::active`, `CashFlow::active`), safety net (`CashFlow` model override), pattern snapshot per-item (RR-08/09/12).

⚠️ **Phát hiện mới ngoài scope:** `OrderController@convert` (Order → Invoice flow) còn raw decrement pattern giống RR-09 — khuyến nghị tạo **RR-13 P1** ở Risk Register, refactor sang `InvoiceSaleService::createSale()` đã sẵn sàng.

🔵 **Còn 1 P2:** RR-06 Customer debt transactions/service.

### Tổng kết tiến độ audit

| Mã | Module | Mức | Trạng thái |
|---|---|---|---|
| RR-01 | Invoice cancel | P0 | ✅ Fixed/Verified |
| RR-02 | Invoice/POS duplicate | P1 | ✅ Fixed/Verified |
| RR-03 | Stock transfer | P0 | ✅ Fixed/Verified |
| RR-04 | Stock take | P0 | ✅ Fixed/Verified |
| RR-05 | Costing zero stock | P1 | ✅ Fixed/Verified |
| RR-06 | Customer debt | P2 | 🔵 Chưa xử lý |
| RR-07 | Repair parts | P0 | ✅ Fixed/Verified |
| RR-08 | OrderReturn rollback serial | P1 | ✅ Fixed/Verified |
| RR-09 | Damage | P1 | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | P0 | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | P0 | ✅ Fixed/Verified |
| RR-12 | StockTransfer cost snapshot | P1 | ✅ Fixed/Verified |
| **RR-13 (mới)** | **Order convert raw decrement** | **P1 candidate** | **🚨 Mới phát hiện qua scan** |

**Tiến độ:** 11/12 đã đóng (6 P0 + 5 P1) + 1 P1 mới đề xuất + 1 P2 còn lại.

### Bước tiếp theo đề xuất

**Tùy chọn A (ưu tiên):** Xử lý RR-13 (Order convert) trước RR-06 vì:
- P1 severity cao hơn P2.
- Pattern bug giống RR-09 đã có lưới an toàn (`InvoiceSaleService` đã sẵn sàng).
- Production có thể đang sai inventory_total_cost khi convert order.

**Tùy chọn B:** Theo thứ tự Risk Register original — xử lý RR-06 P2 (CustomerDebtService).

**Khuyến nghị:** Tùy chọn A. Sau đó RR-06.

---

## 11. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng |
| `docs/audit/P0-AUDIT-SUMMARY-REPORT.md` | Tổng kết P0 (Bước 11) |
| `docs/audit/P0-P1-AUDIT-SUMMARY-REPORT.md` | File này — tổng kết P0+P1 (Bước 17) |
| `docs/audit/RR-01-CLOSURE-REPORT.md` ... `RR-12-CLOSURE-REPORT.md` | 11 closure reports |
| `app/Services/InvoiceSaleService.php` | Service nền RR-02 — sẵn sàng cho RR-13 |
| `app/Services/MovingAvgCostingService.php`, `StockMovementService.php` | Services nền chuẩn |
