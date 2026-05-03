# P0 Audit Summary Report

> **Loại tài liệu:** Báo cáo tổng kết kết thúc đợt audit P0
> **Phiên bản:** 1.0
> **Trạng thái:** ✅ P0 CLEAN

---

## 1. Tổng quan

| Mục | Giá trị |
|---|---|
| Ngày tổng kết | 02/05/2026 |
| Môi trường test | `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3319`, `DB_DATABASE=sales_test` |
| Tổng số rủi ro P0 trong RISK_REGISTER | **6** |
| Tổng số rủi ro P0 đã Fixed/Verified | **6 (100%)** |
| Tổng số test audit P0 | **50** |
| Kết quả test audit P0 | ✅ **50 PASS, 0 FAIL** |
| Full test suite (toàn project) | 51 PASS, 1 FAIL (`ExampleTest` legacy không liên quan) |
| Trạng thái kết thúc | ✅ **P0 CLEAN — sẵn sàng chuyển sang backlog P1/P2/P3** |

---

## 2. Danh sách P0 đã xử lý

| Mã | Module | Lỗi gốc | File đã sửa | Test verification | Trạng thái |
|---|---|---|---|---|---|
| **RR-01** | Invoice cancel | `$invoice->delete()` xóa vật lý chứng từ → mất lịch sử, sai báo cáo, sai sổ quỹ | `InvoiceController.php` (status-based cancel), `Invoice.php` (`scopeActive`), `ReportController.php` (23 query patches), `SupplierController.php` (1 patch), `CashFlow.php` (`scopeActive`) | `CancelInvoiceTest` (10), `RR01ReportControllerRegressionTest` (8), `RR01SupplierDualRoleRegressionTest` (2), `RR01CashFlowCancelledRegressionTest` (4) | ✅ Fixed/Verified |
| **RR-03** | Stock transfer | Raw `increment`/`decrement` trên `stock_quantity`, không ghi `StockMovement`, không cập nhật `inventory_total_cost`; thiếu route receive/cancel | `StockTransferController.php`, `routes/web.php` | `RR03StockTransferTest` (5), `RR03StockTransferRouteTest` (3) | ✅ Fixed/Verified |
| **RR-04** | Stock take | Raw `increment`/`decrement` khi cân bằng kiểm kho, không ghi `StockMovement`, không cập nhật `inventory_total_cost` | `StockTakeController.php` | `RR04StockTakeTest` (5) | ✅ Fixed/Verified |
| **RR-07** | Repair parts | `TaskService::addPart/removePart/disassemblePart` dùng `decrement`/`increment` raw cho linh kiện, không ghi `StockMovement`, không giảm `inventory_total_cost` của linh kiện | `TaskService.php` | `RR07RepairPartsTest` (4) | ✅ Fixed/Verified |
| **RR-10** | CashFlow deletion | CashFlow soft-delete khi hủy chứng từ nhưng `status` giữ `'active'` → báo cáo tính nhầm, restore nguy hiểm | `PurchaseController.php`, `OrderReturnController.php`, `PurchaseReturnController.php` (set `status='cancelled'` trước `delete()`), `CashFlow.php` (model safety net `runSoftDelete()` + `newEloquentBuilder()` + `scopeActive`) | `RR10CashFlowDeletionTest` (5) | ✅ Fixed/Verified |
| **RR-11** | OrderReturn qty | Trả hàng khách không validate qty đã trả, cho trả vượt qty bán, cho trả trên hóa đơn đã hủy | `OrderReturnController.php` (validation block: invoice status + qty gom theo product + cumulative check) | `RR11OrderReturnQtyTest` (4) | ✅ Fixed/Verified |

---

## 3. Quy ước nghiệp vụ mới

### 3.1. Chứng từ

- **Không xóa vật lý** chứng từ đã phát sinh nghiệp vụ (Invoice, Purchase, OrderReturn, PurchaseReturn, CashFlow).
- **Hủy hóa đơn** dùng `status = 'Đã hủy'` + giữ items + đảo tồn/giá vốn/công nợ/serial/CashFlow.
- **Hóa đơn hợp lệ cho báo cáo** dùng `Invoice::active()` (loại trừ `'Đã hủy'`).
- **Hủy mọi chứng từ phải idempotent** — guard `if ($record->status === 'cancelled') return;` trước khi đảo nghiệp vụ.

### 3.2. Tồn kho

- **Không dùng raw `increment`/`decrement` trực tiếp** trên `stock_quantity` nếu nghiệp vụ ảnh hưởng giá vốn hoặc thẻ kho.
- **Mọi thay đổi tồn kho phải đi qua `MovingAvgCostingService`**:
  - `applyPurchase()` — nhập hàng
  - `applySale()` — bán hàng / xuất linh kiện
  - `applySaleReturn()` — KH trả hàng / hủy HĐ
  - `applyPurchaseReturn()` — trả NCC / hủy nhập
  - `applyAdjustment()` — kiểm kho
  - `applyRepairAdjustment()` — sửa chữa cộng/trừ cost
- **Mọi thay đổi tồn kho phải ghi `StockMovement`** qua `StockMovementService::record()` với type chuẩn (`in_purchase`, `out_invoice`, `transfer_in/out`, `adjust_in/out`, `repair_in/out`, `in_invoice_return`, `out_purchase_return`).
- `stock_quantity` và `inventory_total_cost` **phải đồng bộ**: `cost_price = inventory_total_cost / stock_quantity`.

### 3.3. CashFlow

- **CashFlow đã phát sinh không hard-delete** (`forceDelete()` cấm với CashFlow nghiệp vụ).
- **Hủy CashFlow dùng `status = 'cancelled'`** — set trước khi `delete()`.
- **Model CashFlow có safety net 2 lớp**: `runSoftDelete()` (single instance) + `newEloquentBuilder()` (mass) auto-set `status='cancelled'`.
- **Báo cáo tiền/quỹ dùng `CashFlow::active()`** — loại trừ `cancelled` VÀ `deleted_at IS NOT NULL`.
- **Audit trail dùng `withTrashed()`** — thấy cả CashFlow đã hủy.

### 3.4. Trả hàng

- **Không cho trả vượt số lượng đã bán** trên cùng hóa đơn.
- **Phải tính tổng đã trả trước đó** (`already_returned` từ `ReturnItem` join `OrderReturn` chưa hủy).
- **Không trả hàng trên invoice đã hủy** (`invoice.status != 'Đã hủy'`).
- **Gom qty theo `product_id`** trước khi validate (không validate từng dòng riêng lẻ).
- **Validation fail → không tạo gì** (không OrderReturn, ReturnItem, StockMovement, CashFlow, không cập nhật tồn/công nợ).

---

## 4. Kết quả test

### 4.1. Test audit P0 (chạy từng filter)

| # | Test suite | File | Tests | Kết quả |
|---|---|---|---:|---|
| 1 | `CancelInvoiceTest` | `tests/Feature/Invoice/CancelInvoiceTest.php` | 10 | ✅ PASS |
| 2 | `RR01ReportControllerRegressionTest` | `tests/Feature/Report/RR01ReportControllerRegressionTest.php` | 8 | ✅ PASS |
| 3 | `RR01SupplierDualRoleRegressionTest` | `tests/Feature/Supplier/RR01SupplierDualRoleRegressionTest.php` | 2 | ✅ PASS |
| 4 | `RR01CashFlowCancelledRegressionTest` | `tests/Feature/Report/RR01CashFlowCancelledRegressionTest.php` | 4 | ✅ PASS |
| 5 | `RR03StockTransferTest` | `tests/Feature/Inventory/RR03StockTransferTest.php` | 5 | ✅ PASS |
| 6 | `RR03StockTransferRouteTest` | `tests/Feature/Inventory/RR03StockTransferRouteTest.php` | 3 | ✅ PASS |
| 7 | `RR04StockTakeTest` | `tests/Feature/Inventory/RR04StockTakeTest.php` | 5 | ✅ PASS |
| 8 | `RR07RepairPartsTest` | `tests/Feature/Repair/RR07RepairPartsTest.php` | 4 | ✅ PASS |
| 9 | `RR10CashFlowDeletionTest` | `tests/Feature/CashFlow/RR10CashFlowDeletionTest.php` | 5 | ✅ PASS |
| 10 | `RR11OrderReturnQtyTest` | `tests/Feature/OrderReturn/RR11OrderReturnQtyTest.php` | 4 | ✅ PASS |
| | **Tổng test audit P0** | | **50** | ✅ **50 PASS, 0 FAIL** |

### 4.2. Full test suite (`php artisan test --env=testing`)

```
Tests:    1 failed, 51 passed (102 assertions)
Duration: 3.85s
```

| Phân loại lỗi | File | Đánh giá |
|---|---|---|
| Lỗi P0 audit | — | Không có |
| Lỗi legacy không liên quan | `tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (GET `/` → 302 thay vì 200) | Test mặc định Laravel; route `/` redirect do middleware auth — không phải bug, không liên quan P0 audit |
| Lỗi môi trường | — | Không có |

→ **51/52 PASS. Lỗi duy nhất là `ExampleTest` (test mặc định Laravel kiểm GET `/` trả 200, nhưng project có middleware auth nên redirect 302).** Không cần sửa trong phạm vi audit P0.

---

## 5. Kết quả scan code

### 5.1. Pattern kho (`->increment`/`->decrement` trên `stock_quantity`)

| Pattern | File | Đánh giá | Mức độ | Ghi chú |
|---|---|---|---|---|
| `Product::where('id', $part->product_id)->increment('stock_quantity', ...)` | `app/Services/RepairService.php:125` (removePart) | ⚠️ Còn raw | P2 | RepairService **deprecated** — đã được TaskService thay thế (RR-07). Cần xóa hoặc đánh dấu deprecated rõ. |
| `$product->decrement('stock_quantity', $quantity)` | `app/Services/RepairService.php:97` (addPart) | ⚠️ Còn raw | P2 | Cùng RepairService deprecated như trên. |
| `$product->increment('stock_quantity')` | `app/Http/Controllers/ProductController.php:1065` (storeSerial) | ⚠️ Còn raw | P3 | Tạo serial admin → sync `stock_quantity` theo số serial. Không có nghiệp vụ nhập đi kèm; **không update `inventory_total_cost`**. Có thể gây lệch BQ với sản phẩm có serial. |
| `$product->increment('stock_quantity', $created)` | `app/Http/Controllers/ProductController.php:1100` (bulkStoreSerials) | ⚠️ Còn raw | P3 | Cùng pattern serial admin như trên. |
| `$product->decrement('stock_quantity')` | `app/Http/Controllers/ProductController.php:1146` (updateSerial) | ⚠️ Còn raw | P3 | Đổi status serial → sync stock. Không qua CostingService. |
| `$product->increment('stock_quantity')` | `app/Http/Controllers/ProductController.php:1148` (updateSerial) | ⚠️ Còn raw | P3 | Cùng updateSerial. |
| `$product->decrement('stock_quantity')` | `app/Http/Controllers/ProductController.php:1185` (destroySerial) | ⚠️ Còn raw | P3 | Xóa serial admin. |

**Ghi chú:** Các pattern còn raw đều thuộc nghiệp vụ **quản trị serial admin** (CRUD serial độc lập, không phải luồng nhập/bán/trả). **Không phải P0 mới.** Tuy nhiên, để đảm bảo `inventory_total_cost` luôn đồng bộ với `stock_quantity` trên sản phẩm có serial, nên gọi `recomputeFromSerials()` ở cuối — `updateSerial` đã làm, `storeSerial` / `bulkStoreSerials` / `destroySerial` chưa làm → ghi vào backlog P3.

### 5.2. Pattern `inventory_total_cost`

| File | Đánh giá | Ghi chú |
|---|---|---|
| `app/Models/Product.php` | ✅ OK | Định nghĩa cột |
| `app/Services/MovingAvgCostingService.php` | ✅ OK | Service chuẩn cập nhật BQ |
| `app/Services/StockMovementService.php` | ✅ OK | Reference cho movement |
| `app/Http/Controllers/PurchaseController.php` | ✅ OK | Đã dùng CostingService |
| `app/Http/Controllers/PosController.php` | ✅ OK | Đã dùng CostingService |
| `app/Services/TaskService.php` | ✅ OK | Đã sửa qua RR-07 |
| `app/Services/RepairService.php` | ⚠️ Deprecated | P2 — gọi `applyRepairAdjustment()` cho serial product nhưng không cho linh kiện (cùng pattern lỗi đã sửa trong TaskService) |
| `app/Console/Commands/RebuildMovingAvgCosting.php` | ✅ OK | Lệnh rebuild |
| `app/Console/Commands/SyncSerialCostFromTasks.php` | ✅ OK | Lệnh sync |

### 5.3. Pattern `StockMovementService` (đã được dùng đúng)

| File | Trạng thái |
|---|---|
| `app/Http/Controllers/InvoiceController.php` | ✅ Dùng (RR-01) |
| `app/Http/Controllers/PosController.php` | ✅ Dùng |
| `app/Http/Controllers/PurchaseController.php` | ✅ Dùng |
| `app/Http/Controllers/StockTransferController.php` | ✅ Dùng (RR-03) |
| `app/Http/Controllers/StockTakeController.php` | ✅ Dùng (RR-04) |
| `app/Http/Controllers/OrderReturnController.php` | ✅ Dùng |
| `app/Http/Controllers/PurchaseReturnController.php` | ✅ Dùng |
| `app/Services/TaskService.php` | ✅ Dùng (RR-07) |

### 5.4. Pattern hard-delete chứng từ

| Pattern | File | Đánh giá | Mức độ | Ghi chú |
|---|---|---|---|---|
| `$invoice->delete()` | — | ✅ Không còn | — | Đã được RR-01 sửa thành status-based. |
| `$purchase->delete()` | `app/Http/Controllers/PurchaseController.php:641` | ✅ OK | — | Chỉ xóa khi `$purchase->status !== 'completed'` (phiếu draft chưa phát sinh nghiệp vụ). |
| `$orderReturn->delete()` | — | ✅ Không còn | — | OrderReturnController dùng status-based cancel. |
| `$purchaseReturn->delete()` | — | ✅ Không còn | — | PurchaseReturnController dùng status-based cancel. |
| `$cashFlow->delete()` (single) | `app/Http/Controllers/CashFlowController.php:190` | ✅ OK | — | Đã `update(['status'=>'cancelled'])` trước (RR-10 đã verify). |
| `forceDelete()` | `app/Http/Controllers/StockTakeController.php:204` | ✅ OK | — | Chỉ xóa `StockTakeItem` của phiếu **draft** (theo guard dòng 190). |

### 5.5. Pattern mass delete CashFlow

| Pattern | File | Đánh giá | Mức độ | Ghi chú |
|---|---|---|---|---|
| `CashFlow::where('reference_type', 'Purchase')->...->delete()` | `PurchaseController.php:710-715` | ✅ OK | — | Có `update(['status'=>'cancelled'])` trước rồi mới `delete()` (RR-10). |
| `CashFlow::where('reference_type', 'OrderReturn')->...->delete()` | `OrderReturnController.php:437-440` | ✅ OK | — | RR-10 đã sửa. |
| `CashFlow::where('reference_type', 'PurchaseReturn')->...->delete()` | `PurchaseReturnController.php:474-477` | ✅ OK | — | RR-10 đã sửa. |
| `CashFlow::where('reference_type', 'Invoice')->...->delete()` (update flow) | `InvoiceController.php:524-526` | ⚠️ Không set status explicit | P3 | Nhưng **safety net** `runSoftDelete()` + `newEloquentBuilder()` của model `CashFlow` đã tự set `status='cancelled'` → an toàn. Đã ghi nhận trong RR-10 backlog. |
| `CashFlow::where('reference_type', 'paysheet')->...->delete()` (cancel) | `PaysheetController.php:332-334` | ⚠️ Không set status explicit | P2 | Tương tự — model safety net auto-set. Cần thêm explicit `update(['status'=>'cancelled'])` để dễ đọc & tránh phụ thuộc model override. |
| `CashFlow::where('reference_type', 'paysheet')->...->delete()` (destroy) | `PaysheetController.php:425-427` | ⚠️ Không set status explicit | P2 | Cùng PaysheetController. |
| `DB::table('cash_flows')->...->delete()` | — | ✅ Không có | — | Bypass model safety net — không có pattern này. |

### 5.6. Tổng kết scan

- **Không phát hiện P0 mới chưa xử lý.**
- Có **3 pattern P2** cần ghi vào backlog: RepairService deprecated, PaysheetController cancel/destroy thiếu explicit status update.
- Có **5 pattern P3** ở `ProductController` (admin serial CRUD).

---

## 6. Backlog P1/P2/P3 còn lại

### P1 — High

| # | Mã / Khu vực | Mô tả | Nguồn |
|---|---|---|---|
| 1 | RR-02 | Logic bán hàng duplicate giữa `InvoiceController` và `PosController` — nên gom vào `InvoiceSaleService` | RISK_REGISTER |
| 2 | RR-05 | `MovingAvgCostingService::applyPurchaseReturn` reset cost_price=0 khi qty=0, nhưng `applySale` giữ BQ → không nhất quán | RISK_REGISTER |
| 3 | RR-08 | Hủy phiếu trả hàng KH rollback serial bằng `whereNull('invoice_id')->limit($qty)` → có thể chọn sai serial. Cần lưu `serial_ids` trên return_item | RISK_REGISTER |
| 4 | RR-09 | `DamageController` cần kiểm chứng có trừ tồn không, nếu trừ thì có qua CostingService không | RISK_REGISTER |
| 5 | RR-12 | Hủy phiếu chuyển kho `received` tính sai stock vì products dùng chung qty (chưa multi-warehouse) | RISK_REGISTER |
| 6 | OrderReturn cancel route | `OrderReturnController@cancel` đã có method nhưng chưa đăng ký route | RR-11 closure |

### P2 — Medium

| # | Khu vực | Mô tả | Nguồn |
|---|---|---|---|
| 1 | RR-06 | Tách bảng `customer_debt_transactions` + tập trung qua `CustomerDebtService` (giống `supplier_debt_transactions`) | RISK_REGISTER |
| 2 | `DashboardController` `recentInvoices` | Có thể hiện HĐ Đã hủy trong "gần đây" | RR-01 closure |
| 3 | `InvoiceController` export CSV / filter dropdowns | `salesChannels` / `paymentMethods` distinct query có thể tính cả HĐ hủy; export có thể xuất HĐ hủy | RR-01 closure |
| 4 | CashFlow listing UI | Cần badge/filter trạng thái cancelled | RR-01 closure |
| 5 | `SalesReportController` | Cần audit riêng để xác nhận có query thiếu lọc không | RR-01 closure |
| 6 | StockTransfer UI | `Show.vue` chưa tồn tại; `Index.vue` chưa có nút Nhận hàng / Hủy phiếu | RR-03 closure |
| 7 | `RepairService` deprecated | Còn raw `decrement`/`increment` linh kiện ở `addPart`/`removePart`; cần xóa hoặc đánh dấu deprecated rõ; `RepairService::createRepair` còn dùng `device_repair_id` | RR-07 closure |
| 8 | `PaysheetController` cancel/destroy | Thêm explicit `update(['status'=>'cancelled'])` trước `delete()` CashFlow để không phụ thuộc model safety net | Bước 11 (mới) |
| 9 | OrderReturn cancel test | Chưa có test hủy OrderReturn idempotent | RR-11 closure |

### P3 — Low

| # | Khu vực | Mô tả | Nguồn |
|---|---|---|---|
| 1 | StockTake | Test nhiều sản phẩm cùng lúc; test với cost_price=0; test UI | RR-04 closure |
| 2 | StockTransfer | Test receive partial (nhận 1 phần); permission riêng cho receive/cancel | RR-03 closure |
| 3 | Repair | Test riêng cho `disassemblePart`; test update qty linh kiện; test nhiều linh kiện cùng phiếu; test UI/API repair parts | RR-07 closure |
| 4 | OrderReturn | Test multi-line cùng product; test serial/IMEI validate; test trả hàng không HĐ (`invoice_id` nullable) | RR-11 closure |
| 5 | CashFlow | Route-level test cho Purchase/OrderReturn/PurchaseReturn cancel flow; test CashFlow khi sửa hóa đơn (`InvoiceController@update`); helper `CashFlow::cancelByReference()` | RR-10 closure |
| 6 | `ProductController` serial CRUD (`storeSerial`, `bulkStoreSerials`, `updateSerial`, `destroySerial`) | Còn raw `increment`/`decrement` `stock_quantity` không đi qua CostingService — chỉ ảnh hưởng admin serial CRUD, không phải nghiệp vụ nhập/bán/trả; nên gọi `recomputeFromSerials()` cuối hàm để đồng bộ | Bước 11 (mới) |
| 7 | Architecture | Tồn kho chỉ `products.stock_quantity` chung, chưa phân biệt branch (limitation kiến trúc) | RR-03/RR-04 closure |

---

## 7. Khuyến nghị quy trình sau audit

1. **Mỗi lỗi mới phải có test fail trước (red-green-refactor).** Không sửa code khi chưa có bằng chứng tái hiện được.
2. **Không sửa business logic nếu chưa có expected result rõ ràng.** Phải thống nhất kỳ vọng (input/output) trước khi đụng controller/service.
3. **Mọi module ảnh hưởng kho/tiền phải có regression test.** Bất kỳ thay đổi nào tới `MovingAvgCostingService`, `StockMovementService`, `CashFlow` model, hoặc các controller liên quan tồn kho/sổ quỹ đều phải kéo theo test mới hoặc verify test cũ.
4. **Trước khi merge phải chạy bộ P0 audit tests.** Lệnh tham chiếu:
   ```
   php artisan test --env=testing --filter="CancelInvoiceTest|RR01ReportController|RR01SupplierDualRole|RR01CashFlowCancelled|RR03StockTransfer|RR03StockTransferRoute|RR04StockTake|RR07RepairParts|RR10CashFlowDeletion|RR11OrderReturnQty"
   ```
   Bắt buộc 50/50 PASS.
5. **AI Agent / dev phải đọc `AGENT_RULES.md` trước khi sửa.** Đặc biệt mục 2 (tồn kho), mục 3 (giá vốn), mục 5 (hủy/trả/đảo), mục 8 (quy tắc làm việc).
6. **Không bypass model safety net của `CashFlow`.** Tránh dùng `DB::table('cash_flows')->delete()` — sẽ không auto-set `status='cancelled'`.
7. **Mỗi PR sửa P0 / P1 phải kèm closure report** trong `docs/audit/RR-XX-CLOSURE-REPORT.md` theo cấu trúc đã có.

---

## 8. Kết luận

✅ **P0 CLEAN.**

- Tất cả **6 rủi ro P0 đã Fixed/Verified** (RR-01, RR-03, RR-04, RR-07, RR-10, RR-11).
- **50/50 test audit P0 PASS, 0 FAIL.**
- Full test suite **51/52 PASS** (lỗi duy nhất là `ExampleTest` legacy không liên quan audit).
- Scan code **không phát hiện P0 mới**. Các pattern còn lại đều thuộc P2 (RepairService deprecated, PaysheetController) hoặc P3 (admin serial CRUD trong ProductController) và đã được ghi vào backlog.
- Quy ước nghiệp vụ mới đã được thiết lập rõ qua scopes (`Invoice::active`, `CashFlow::active`), services (`MovingAvgCostingService`, `StockMovementService`), và safety net (`CashFlow` model override).
- Hệ thống **sẵn sàng chuyển sang xử lý backlog P1/P2/P3** hoặc phát triển tính năng mới có kiểm soát theo quy trình đề xuất ở mục 7.

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc cho AI Agent / dev |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng |
| `docs/audit/RR-01-CLOSURE-REPORT.md` | Closure RR-01 (Invoice cancel) |
| `docs/audit/RR-03-CLOSURE-REPORT.md` | Closure RR-03 (Stock transfer) |
| `docs/audit/RR-04-CLOSURE-REPORT.md` | Closure RR-04 (Stock take) |
| `docs/audit/RR-07-CLOSURE-REPORT.md` | Closure RR-07 (Repair parts) |
| `docs/audit/RR-10-CLOSURE-REPORT.md` | Closure RR-10 (CashFlow deletion) |
| `docs/audit/RR-11-CLOSURE-REPORT.md` | Closure RR-11 (OrderReturn qty) |
| `docs/audit/P0-AUDIT-SUMMARY-REPORT.md` | File này — tổng kết kết thúc đợt P0 |
