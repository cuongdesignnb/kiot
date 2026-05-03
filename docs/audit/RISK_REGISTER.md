# RISK REGISTER — Audit hệ thống quản lý bán hàng (KiotViet Clone)

> **Ngày tạo:** 02/05/2026  
> **Nguồn:** Audit Report Bước 1  
> **Phiên bản:** 1.0  
> **Quy tắc:** Mỗi rủi ro phải được xử lý riêng theo AGENT_RULES.md → Mục 8

---

## Tổng quan

| Mức độ | Số lượng | Đã đóng | Mô tả |
|---|---|---|---|
| **P0 — Critical** | 6 | 6 ✅ | Sai tồn kho, sai giá vốn, mất lịch sử chứng từ, giao dịch không truy vết |
| **P1 — High** | 6 (5 original + 1 new RR-13) | 6 ✅ (RR-02, RR-05, RR-08, RR-09, RR-12, RR-13) | Sai báo cáo, logic duplicate, rollback chưa chính xác. RR-13 phát hiện mới qua Bước 17 scan, đã đóng ở Bước 18.2. |
| **P2 — Medium** | 1 | 1 ✅ | Cải thiện kiến trúc, tách bảng, refactor dài hạn |

---

## Bảng rủi ro chi tiết

### P0 — Critical (Ảnh hưởng tính đúng đắn dữ liệu)

| Mã | Module | Vấn đề | File liên quan | Ảnh hưởng | Hướng xử lý đề xuất | Trạng thái |
|---|---|---|---|---|---|---|
| **RR-01** | Invoice | Hủy hóa đơn **xóa hẳn record** (`$invoice->delete()`) thay vì đổi trạng thái `cancelled` | `InvoiceController.php` dòng 644 | Mất lịch sử chứng từ. Không thể truy vết giao dịch đã hủy. Báo cáo doanh thu/lợi nhuận không thể tái tạo. | Đổi sang `$invoice->status = 'Đã hủy'; $invoice->save();` + guard idempotent + CashFlow `update(['status' => 'cancelled'])`. | ✅ **Fixed/Verified** — 24/24 test PASS. Đã sửa InvoiceController, ReportController (P0), SupplierController (P1), CashFlow expense (P1). Thêm `Invoice::scopeActive()` + `CashFlow::scopeActive()`. P2 backlog. |
| **RR-03** | StockTransfer | Chuyển kho **không ghi StockMovement**, không cập nhật `inventory_total_cost` | `StockTransferController.php` dòng 134–139 (store), dòng 234–238 (receive) | Thẻ kho (stock card) thiếu dòng chuyển kho. `inventory_total_cost` lệch với `stock_quantity` → giá vốn BQ sai. | Gọi `MovingAvgCostingService` khi xuất/nhận kho. Gọi `StockMovementService::record()` với type `transfer_out` / `transfer_in`. | ✅ **Fixed/Verified** — 8/8 RR03 test PASS + 24 RR01 regression PASS = 32 PASS. Đã tích hợp `MovingAvgCostingService` + `StockMovementService`. Đã thêm route receive/cancel. |
| **RR-04** | StockTake | Kiểm kho **không ghi StockMovement**, không cập nhật `inventory_total_cost` | `StockTakeController.php` dòng 117–126 (store), dòng 270–277 (balance) | `stock_quantity` thay đổi nhưng `inventory_total_cost` giữ nguyên → `cost_price = total_cost / qty` bị sai. Thẻ kho thiếu dòng kiểm kho. | Gọi `MovingAvgCostingService::applyAdjustment()` thay vì `increment`/`decrement` trực tiếp. Gọi `StockMovementService::record()` với type `adjust_in`/`adjust_out`. | ✅ **Fixed/Verified** — 5/5 RR04 test PASS + 32 existing regression PASS = 37 PASS. Đã tích hợp `MovingAvgCostingService::applyAdjustment()` + `StockMovementService`. |
| **RR-07** | Repair | Sửa chữa trừ tồn linh kiện bằng **`decrement` trực tiếp**, không qua CostingService | `TaskService.php` dòng 289 (addPart), dòng 323 (removePart), dòng 372 (disassemblePart) | `inventory_total_cost` của linh kiện không giảm → BQ linh kiện tăng sai (qty giảm, total_cost giữ nguyên). Thẻ kho linh kiện thiếu dòng xuất sửa chữa. | Thay `decrement` bằng `MovingAvgCostingService::applySale()` cho linh kiện. Gọi `StockMovementService::record()` type `repair_out`. Tương tự cho `removePart` và `disassemblePart`. | ✅ **Fixed/Verified** — 4/4 RR07 test PASS + 37 existing regression PASS = 41 PASS. Đã tích hợp `applySale()` / `applyPurchase()` + `StockMovementService` vào TaskService. |
| **RR-10** | CashFlow | CashFlow bị **soft-delete** khi hủy chứng từ nhưng không set `status='cancelled'` | `PurchaseController.php` dòng 710–712, `OrderReturnController.php` dòng 389–391, `PurchaseReturnController.php` dòng 474–476 | CashFlow bị trashed nhưng status giữ 'active' → `withTrashed()->active()` tính nhầm. Restore sẽ làm CashFlow xuất hiện lại với số tiền đầy đủ. | Set `status='cancelled'` trước `delete()`. Thêm model safety net: `runSoftDelete()` + `newEloquentBuilder()` override auto-set status. Cập nhật `scopeActive()` lọc cả `deleted_at`. | ✅ **Fixed/Verified** — 5/5 RR10 test PASS + 41 existing regression PASS = 46 PASS. Đã sửa 3 controllers + CashFlow model safety net. |
| **RR-11** | OrderReturn | Trả hàng khách **không validate trùng số lượng đã trả** cho cùng invoice | `OrderReturnController.php` dòng 96–117 (store validation) | Có thể tạo nhiều phiếu trả vượt quá qty gốc → tồn kho tăng vô lý, công nợ KH bị âm quá mức. | Thêm validation: check invoice status, tính `already_returned` từ ReturnItem, so sánh `requested + already_returned <= sold_qty`. Gom qty theo product trước khi validate. | ✅ **Fixed/Verified** — 4/4 RR11 test PASS + 46 existing regression PASS = 50 PASS. Đã thêm qty validation + invoice status check. |

---

### P1 — High (Sai báo cáo, logic duplicate, rollback chưa chính xác)

| Mã | Module | Vấn đề | File liên quan | Ảnh hưởng | Hướng xử lý đề xuất | Trạng thái |
|---|---|---|---|---|---|---|
| **RR-02** | Invoice/POS | Logic bán hàng **duplicate** giữa InvoiceController và PosController | `InvoiceController.php` dòng 117–335, `PosController.php` dòng 74–262 | Sửa 1 chỗ quên chỗ kia → inconsistent. POS tạo `InvoiceItemSerial` với `invoice_item_id=0` rồi update sau — race condition tiềm ẩn. | Tách logic bán hàng vào `InvoiceSaleService` dùng chung. Cả InvoiceController và PosController gọi cùng 1 service. | ✅ **Fixed/Verified** — Đã tạo `InvoiceSaleService::createSale($payload, $context)`. Cả `InvoiceController@store` và `PosController@checkout` dùng chung service. **Bug POS FK violation đã sửa** (pattern: tạo InvoiceItem trước → InvoiceItemSerial với id thật, không bao giờ `invoice_item_id=0`). 5/5 RR-02 + 50 P0 + 23 P1 = 78/78 PASS. |
| **RR-05** | Costing | Giá vốn BQ **reset về 0** khi trả hàng nhập hết tồn, nhưng giữ nguyên khi bán hết | `MovingAvgCostingService.php` dòng 135 (`applyPurchaseReturn`: newAvg = 0.0 khi newQty = 0) vs dòng 79 (`applySale`: giữ BQ cũ khi newQty = 0) | Logic không nhất quán. Trả NCC hết → cost_price = 0 → nếu nhập lại, BQ bị ảnh hưởng. | Thống nhất: khi qty = 0, giữ nguyên BQ cũ (giống `applySale`). Hoặc định nghĩa quy tắc rõ ràng cho từng case. | ✅ **Fixed/Verified** — 9/9 RR-05 tests PASS (5 Unit + 4 Serial) + 50/50 P0 regression PASS = 59/59. Đã sửa `MovingAvgCostingService::applyPurchaseReturn` (1 dòng, 135) — giữ last-known-average khi qty=0. Áp dụng cho cả sản phẩm thường và Serial/IMEI (cùng product-level moving avg). |
| **RR-08** | OrderReturn | Hủy phiếu trả hàng KH — **rollback serial không chính xác** | `OrderReturnController.php` dòng 350–359 (cancel) | Khi hủy trả hàng, rollback serial bằng `whereNull('invoice_id')->limit($qty)` → có thể gán sai serial vào invoice. | Lưu `serial_ids` vào return_item khi tạo phiếu trả. Khi hủy, rollback đúng serial đã lưu. | ✅ **Fixed/Verified** — 4/4 RR-08 PASS + 4 RR-11 + 9 RR-05 + 50 P0 = 67/67. Đã thêm migration `return_items.serial_ids` JSON, sửa `OrderReturnController@store` lưu serial_ids và `cancel()` dùng `whereIn` đúng serial, đăng ký route `returns.cancel`. Bỏ fallback mơ hồ. |
| **RR-09** | Damage | Xuất hủy (Damage) **cần kiểm chứng** có trừ tồn kho hay không | `DamageController.php` (toàn file ~160 dòng) | Nếu không trừ tồn → tồn kho hệ thống lớn hơn thực tế. Nếu trừ nhưng không qua CostingService → BQ sai. | Cần đọc chi tiết `DamageController@store` để xác nhận. Nếu thiếu → bổ sung logic giống các module khác. | ✅ **Fixed/Verified** — confirmed lỗi đa diện (raw decrement, thiếu cost+movement+serial+cancel). Đã sửa: migration `damage_items.serial_ids`, `DamageController@store` qua `applyAdjustment(-qty)` + StockMovement adjust_out + xử lý serial sang `defective`, thêm `cancel()` đảo nghiệp vụ + idempotent + route `damages.cancel`. 5/5 RR-09 + 22/22 P1 + 50/50 P0 = 72/72 PASS. |
| **RR-12** | StockTransfer | Hủy phiếu chuyển kho `received` — **tính sai stock vì chưa có multi-warehouse** | `StockTransferController.php` dòng 278–288 (cancel) | Cộng source + trừ destination trên cùng 1 product record (products.stock_quantity dùng chung, không phân biệt branch). Kết quả net = +qty - received_qty, có thể sai nếu qty ≠ received_qty. | Đây là limitation kiến trúc. Tạm thời chỉ cho hủy nếu qty == received_qty, hoặc thêm validation. Dài hạn: tách inventory theo branch. | ✅ **Fixed/Verified** — phạm vi bug điều chỉnh: tổng tồn product-level OK, idempotent OK; **bug thật là cost integrity** khi BQ thay đổi giữa các pha. Đã thêm `stock_transfer_items.cost_at_transfer`, sửa `store/receive/cancel` dùng snapshot, đổi `applySale`→`applyPurchaseReturn(qty, snapshot)` cho đảo destination. 5/5 RR-12 + 30 P1 + 50 P0 = 77/77 PASS. Multi-warehouse vẫn là backlog kiến trúc dài hạn. |
| **RR-13** | Order convert | `OrderController@processOrder` (Order → Invoice) raw `$product->stock_quantity -= $orderItem->qty` không qua `MovingAvgCostingService`, không update `inventory_total_cost`, không ghi `StockMovement`, không xử lý Serial/IMEI (status, sold_cost_price, InvoiceItemSerial) | `OrderController.php` dòng 376–377 (processOrder) | Sai giá vốn BQ (qty giảm, total_cost giữ → cost_price inflate giống RR-09 trước fix). Thẻ kho thiếu dòng out_invoice từ luồng convert. Serial của hàng có serial không được đánh dấu `sold` → không truy vết. | Refactor convert gọi `InvoiceSaleService::createSale($payload, $context)` (đã sẵn sàng từ RR-02). Hoặc tối thiểu thay raw decrement bằng `applySale` + `StockMovement::record`. | ✅ **Fixed/Verified** — patch hẹp Option B: thay raw decrement bằng `applySale + StockMovement::record(TYPE_OUT_INVOICE)`, snapshot cost trước applySale, tạo InvoiceItem trước rồi InvoiceItemSerial với id thật, đăng ký route `orders.process`, serial fail-safe (throw nếu OrderItem chưa có serial_ids). 4/4 RR-13 + 78 audit regression = 82/82 PASS. Long-term: refactor sang `InvoiceSaleService` sau khi chuẩn hóa priorDeposit/newPayment. |

---

### P2 — Medium (Cải thiện kiến trúc dài hạn)

| Mã | Module | Vấn đề | File liên quan | Ảnh hưởng | Hướng xử lý đề xuất | Trạng thái |
|---|---|---|---|---|---|---|
| **RR-06** | Customer/Debt | Công nợ KH **cộng/trừ trực tiếp** (`increment`/`decrement`) rải rác ở nhiều controller, không có bảng lịch sử riêng | `InvoiceController.php` dòng 303, `PosController.php` dòng 223, `OrderReturnController.php` dòng 259, `CustomerController.php` dòng 469/521 | Nếu crash giữa giao dịch (sau tạo invoice nhưng trước increment debt), công nợ lệch vĩnh viễn. Không có cách reconcile tự động. NCC đã có `supplier_debt_transactions` nhưng KH thì không. | Tạo bảng `customer_debt_transactions` tương tự `supplier_debt_transactions`. Tập trung logic vào `CustomerDebtService`. Dài hạn lớn nhưng cần ghi chú rõ. | ✅ **Fixed/Verified** — Bảng `customer_debts` đã có sẵn từ migration 2026_03_01. Đã tạo `App\Models\CustomerDebt` + `App\Services\CustomerDebtService` (5 methods: recordSale/Return/Payment/SaleReversal/Adjustment). Refactor toàn bộ 13 chỗ direct customer debt update sang service: `InvoiceSaleService`, `OrderReturnController` store+cancel, `InvoiceController` cancel+update (3 patches), `OrderController@processOrder`, `CustomerController` (5 chỗ: merge×2, addDebt, reduceDebt, debtAdjust). Scan: 0 direct customer debt update còn lại. 5/5 RR-06 + 82 audit regression = 87/87 PASS. |

---

## Ma trận rủi ro theo module

| Module | P0 | P1 | P2 | Tổng |
|---|---|---|---|---|
| Invoice (bán hàng) | RR-01 | RR-02 | — | 2 |
| POS | — | RR-02 | — | 1 |
| OrderReturn (trả hàng KH) | RR-11 | RR-08 | — | 2 |
| Purchase (nhập hàng) | — | — | — | 0 |
| PurchaseReturn (trả hàng NCC) | — | — | — | 0 |
| StockTransfer (chuyển kho) | RR-03 | RR-12 | — | 2 |
| StockTake (kiểm kho) | RR-04 | — | — | 1 |
| Repair (sửa chữa) | RR-07 | — | — | 1 |
| CashFlow (sổ quỹ) | RR-10 | — | — | 1 |
| Costing (giá vốn) | — | RR-05 | — | 1 |
| Damage (xuất hủy) | — | RR-09 | — | 1 |
| Customer/Debt (công nợ) | — | — | RR-06 | 1 |

---

## Thứ tự xử lý đề xuất

### Đợt 1 — P0 Critical (xử lý trước)

1. **RR-01** — Hủy hóa đơn: thay `delete()` bằng `status = cancelled`
2. **RR-10** — CashFlow: thay `delete()` bằng `status = cancelled`  
   *(RR-01 và RR-10 liên quan chặt, nên xử lý cùng đợt)*
3. **RR-03** — Chuyển kho: tích hợp CostingService + StockMovementService
4. **RR-04** — Kiểm kho: tích hợp CostingService + StockMovementService  
   *(RR-03 và RR-04 cùng pattern, xử lý liên tiếp)*
5. **RR-07** — Sửa chữa linh kiện: thay `decrement` bằng `applySale` + StockMovement
6. **RR-11** — Trả hàng KH: thêm validation qty đã trả

### Đợt 2 — P1 High

7. **RR-05** — Giá vốn BQ: thống nhất logic khi qty = 0
8. **RR-02** — Duplicate logic bán hàng: tách service dùng chung
9. **RR-08** — Rollback serial trả hàng: lưu serial_ids
10. **RR-09** — Damage: kiểm chứng và bổ sung logic
11. **RR-12** — Chuyển kho cancel: thêm validation

### Đợt 3 — P2 Medium

12. **RR-06** — Tách bảng customer_debt_transactions + service

---

## Changelog

| Ngày | Thay đổi | Người thực hiện |
|---|---|---|
| 02/05/2026 | Tạo RISK_REGISTER v1.0 từ Audit Report Bước 1 | AI Agent (Audit) |
| 02/05/2026 | RR-01: Fixed InvoiceController@destroy (Step 4) — 10/10 PASS | AI Agent |
| 02/05/2026 | RR-01: Fixed ReportController P0 + Invoice::scopeActive() (Step 5.1B) — 18/18 PASS | AI Agent |
| 02/05/2026 | RR-01: Fixed SupplierController P1 + CashFlow::scopeActive() (Step 5.2B) — 24/24 PASS | AI Agent |
| 02/05/2026 | RR-01: **Closed — Fixed/Verified**. P2 items moved to backlog. | AI Agent |
| 02/05/2026 | RR-03: Test chứng minh lỗi (Step 6.1A) — 4 FAIL, 1 PASS | AI Agent |
| 02/05/2026 | RR-03: Fixed StockTransferController + CostingService + MovementService (Step 6.1B) — 5/5 PASS | AI Agent |
| 02/05/2026 | RR-03: Test route receive/cancel (Step 6.2A) — 2 FAIL (404) | AI Agent |
| 02/05/2026 | RR-03: Fixed routes receive/cancel (Step 6.2B) — 32/32 PASS | AI Agent |
| 02/05/2026 | RR-03: **Closed — Fixed/Verified**. UI/architecture items moved to backlog. | AI Agent |
| 02/05/2026 | RR-04: Test chứng minh lỗi (Step 7.1A) — 4 FAIL, 1 PASS | AI Agent |
| 02/05/2026 | RR-04: Fixed StockTakeController + applyAdjustment() + StockMovement (Step 7.1B) — 5/5 PASS | AI Agent |
| 02/05/2026 | RR-04: **Closed — Fixed/Verified**. P3/limitations moved to backlog. | AI Agent |
| 02/05/2026 | RR-07: Test chứng minh lỗi (Step 8.1A) — 3 FAIL, 1 PASS | AI Agent |
| 02/05/2026 | RR-07: Fixed TaskService addPart/removePart/disassemblePart + CostingService + StockMovement (Step 8.1B) — 4/4 PASS | AI Agent |
| 02/05/2026 | RR-07: **Closed — Fixed/Verified**. P2/P3 items moved to backlog. | AI Agent |
| 02/05/2026 | RR-10: Test chứng minh lỗi (Step 9.1A) — 4 FAIL, 1 PASS | AI Agent |
| 02/05/2026 | RR-10: Fixed 3 controllers + CashFlow model safety net (Step 9.1B) — 5/5 PASS | AI Agent |
| 02/05/2026 | RR-10: **Closed — Fixed/Verified**. P3 items moved to backlog. | AI Agent |
| 02/05/2026 | RR-11: Test chứng minh lỗi (Step 10.1A) — 3 FAIL, 1 PASS | AI Agent |
| 02/05/2026 | RR-11: Fixed OrderReturnController@store qty validation (Step 10.1B) — 4/4 PASS | AI Agent |
| 02/05/2026 | RR-11: **Closed — Fixed/Verified**. P1/P3 items moved to backlog. | AI Agent |
| 02/05/2026 | **Step 11 — P0 Audit Summary**: 50/50 P0 tests PASS, full suite 51/52 (1 legacy ExampleTest), không P0 mới. Backlog mới: PaysheetController explicit status (P2), ProductController serial CRUD recompute (P3). Báo cáo: `P0-AUDIT-SUMMARY-REPORT.md`. **P0 CLEAN.** | AI Agent |
| 02/05/2026 | RR-05: Test chứng minh lỗi (Step 12.1A) — 6 PASS, 3 FAIL (Unit `applyPurchaseReturn` reset 0 khi qty=0; Serial/IMEI cùng bug). Schema serial đầy đủ → không cần backlog riêng. | AI Agent |
| 02/05/2026 | RR-05: Fixed `MovingAvgCostingService::applyPurchaseReturn` (Step 12.1B) — 9/9 RR-05 PASS (5 Unit + 4 Serial), 50/50 P0 regression PASS. Sửa 1 dòng (135) — giữ BQ cuối khi qty về 0, nhất quán với `applySale`. | AI Agent |
| 02/05/2026 | RR-05: **Closed — Fixed/Verified** (Step 12.2). Schema serial không đổi. Cosmetic fallback ở `applyPurchase`/`applySaleReturn` ghi nhận backlog (không xảy ra trong nghiệp vụ thực tế). | AI Agent |
| 02/05/2026 | RR-08: Test chứng minh lỗi (Step 13.1A) — 1 PASS, 3 FAIL. `cancel()` chọn nhầm serial in_stock (id ASC) thay vì serial đã trả. Schema `return_items` thiếu cột `serial_ids`. Route `returns.cancel` chưa đăng ký. Bước 13.1B cần migration + sửa controller + đăng ký route. | AI Agent |
| 02/05/2026 | RR-08: Fixed (Step 13.1B) — migration thêm `return_items.serial_ids` JSON, sửa `OrderReturnController@store` lưu serial_ids và `cancel()` dùng `whereIn` đúng serial, đăng ký route `returns.cancel`. 4/4 RR-08 PASS, 50/50 P0 + 4 RR-11 + 9 RR-05 = 67/67 PASS. | AI Agent |
| 02/05/2026 | RR-08: **Closed — Fixed/Verified** (Step 13.2). Đóng luôn P1 backlog `returns.cancel route` cũ từ RR-11. Backlog mới: legacy backfill serial_ids, UI hiển thị, permission tách. | AI Agent |
| 02/05/2026 | RR-09: Test kiểm chứng (Step 14.1A) — 1 PASS, 4 FAIL. Confirmed lỗi đa diện: (1) raw `stock_quantity -= qty` không update `inventory_total_cost`, (2) thiếu `StockMovement`, (3) không xử lý Serial/IMEI, (4) thiếu method+route cancel. Pattern giống RR-04. | AI Agent |
| 02/05/2026 | RR-09: Fixed (Step 14.1B) — migration thêm `damage_items.serial_ids`, sửa `DamageController@store` qua `applyAdjustment(-qty)` + StockMovement adjust_out + xử lý serial, thêm method `cancel()` đảo nghiệp vụ + idempotent + route `damages.cancel`. 5/5 RR-09 PASS, 50/50 P0 + 22 P1 = 72/72. | AI Agent |
| 02/05/2026 | RR-09: **Closed — Fixed/Verified** (Step 14.2). Backlog mới: legacy backfill `damage_items.serial_ids`, UI cancel, permission tách, lifecycle draft→completed, test multi-serial/draft cancel, audit báo cáo Damage. | AI Agent |
| 02/05/2026 | RR-12: Test kiểm chứng (Step 15.1A) — 4 PASS, 1 FAIL. Phạm vi bug hẹp hơn dự kiến: tổng tồn (numerical) product-level OK; bug duy nhất là **cost integrity** khi BQ thay đổi giữa các pha (cancel dùng current cost thay vì snapshot transfer_out). Schema thiếu `stock_transfer_items.cost_at_transfer`. | AI Agent |
| 02/05/2026 | RR-12: Fixed (Step 15.1B) — migration thêm `stock_transfer_items.cost_at_transfer`, sửa `store()` lưu snapshot, `receive()` + `cancel()` dùng snapshot. Cancel đảo destination đổi `applySale`→`applyPurchaseReturn` để rút tồn theo cost snapshot. 5/5 RR-12 PASS, 30 P1 + 50 P0 = 77/77. | AI Agent |
| 02/05/2026 | RR-12: **Closed — Fixed/Verified** (Step 15.2). Multi-warehouse architecture (branch_inventory) giữ ở backlog kiến trúc dài hạn. Backlog mới: legacy backfill `cost_at_transfer`, partial cancel "fabricate" missing units, UI cảnh báo partial cancel. | AI Agent |
| 02/05/2026 | RR-02: Characterization tests (Step 16.1A) — 4 PASS, 1 FAIL. Confirmed POS bug nghiêm trọng: tạo `InvoiceItemSerial(invoice_item_id=0)` trước rồi update sau → vi phạm FK constraint, POS với hàng serial **broken hoàn toàn** trong DB FK strict. Bug này sẽ tự sửa khi refactor sang `InvoiceSaleService` ở Bước 16.1B (áp dụng pattern Invoice tạo invoice_item trước). | AI Agent |
| 02/05/2026 | RR-02: Design `InvoiceSaleService` (Step 16.1B) — interface `createSale($payload, $context): Invoice`, parameterize khác biệt qua context, không cần migration. Plan refactor 3 step: 16.1C POS → 16.1D Invoice → 16.1E cleanup. | AI Agent |
| 02/05/2026 | RR-02: Tạo `InvoiceSaleService` + chuyển POS sang dùng service (Step 16.1C) — POS serial bug FK violation đã fixed. 5/5 RR-02 PASS (TC-P02 chuyển từ FAIL → PASS), 50 P0 + 23 P1 = 78/78. InvoiceController **chưa refactor** — Step 16.1D. | AI Agent |
| 02/05/2026 | RR-02: Chuyển InvoiceController@store sang dùng `InvoiceSaleService` (Step 16.1D) — duplicate logic đã xóa. Cả Invoice và POS dùng chung sale engine. 5/5 RR-02 + 50 P0 + 23 P1 = 78/78 PASS. Sẵn sàng cleanup + closure ở Step 16.1E. | AI Agent |
| 02/05/2026 | RR-02: **Closed — Fixed/Verified** (Step 16.1E). Cleanup: xóa 2 imports không còn dùng trong PosController (`MovingAvgCostingService`, `StockMovementService`). 78/78 PASS giữ nguyên. **Tất cả P0+P1 đã đóng** (6/6 P0 + 5/5 P1). Còn 1 P2 (RR-06). | AI Agent |
| 02/05/2026 | **Step 17 — P0+P1 Audit Summary**: 78/78 P0+P1 tests PASS. 11/12 rủi ro đã đóng. Phát hiện mới qua scan: **OrderController@convert** (Order→Invoice) còn raw `stock_quantity -= qty` không qua service — đề xuất **RR-13 P1 candidate**. Báo cáo: `P0-P1-AUDIT-SUMMARY-REPORT.md`. **P0/P1 CLEAN per Risk Register original.** | AI Agent |
| 02/05/2026 | RR-13: Mở RR-13 + Test chứng minh lỗi (Step 18.1A) — 1 PASS, 3 FAIL. Confirmed `OrderController@processOrder` raw `stock_quantity -= qty`, không update `inventory_total_cost`, không ghi `StockMovement`, không xử lý Serial. Pattern giống RR-09. Bước 18.1B sẽ sửa (đề xuất Option B: patch tại chỗ). | AI Agent |
| 02/05/2026 | RR-13: Fixed (Step 18.1B) — patch hẹp `OrderController@processOrder`: `applySale` thay raw decrement + `StockMovement::record(TYPE_OUT_INVOICE)` + tạo `InvoiceItem` trước rồi `InvoiceItemSerial` với id thật. Đăng ký route `orders.process`. Serial fail-safe (throw nếu OrderItem không có `serial_ids`). 4/4 RR-13 + 78 audit regression = 82/82 PASS. | AI Agent |
| 02/05/2026 | RR-13: **Closed — Fixed/Verified** (Step 18.2). Backlog mới: schema `order_items.serial_ids` để hỗ trợ convert order serial đầy đủ; UI Order Create cho phép chọn serial; long-term refactor sang `InvoiceSaleService`. **Tất cả P0+P1 đã đóng** (6/6 P0 + 6/6 P1 bao gồm RR-13). Còn 1 P2 (RR-06). | AI Agent |
| 02/05/2026 | RR-06: Discovery + test chứng minh thiếu ledger (Step 19.1A) — 0 PASS, 5 FAIL. Confirmed bảng `customer_debts` tồn tại từ migration 2026_03_01 nhưng chưa có Model `CustomerDebt`, chưa có `CustomerDebtService`, KHÔNG được populate ở 9 chỗ update `customers.debt_amount`. Bất đối xứng với `SupplierDebtTransaction` (đã có ledger đầy đủ). Bước 19.1B: tạo Model + Service + refactor các luồng. | AI Agent |
| 02/05/2026 | RR-06: Core fix (Step 19.1B) — tạo `App\Models\CustomerDebt` + `App\Services\CustomerDebtService` (5 methods: recordSale/Return/Payment/SaleReversal/Adjustment). Refactor 4 luồng core: `InvoiceSaleService::updateCustomerDebt`, `OrderReturnController@store/@cancel`, `InvoiceController@cancel`, `OrderController@processOrder`. 5/5 RR-06 PASS + 82 audit regression PASS = 87/87. Còn 8 chỗ direct update (CustomerController×5 + InvoiceController@update×3) → Step 19.1C. | AI Agent |
| 02/05/2026 | RR-06: **Closed — Fixed/Verified** (Step 19.1C). Refactor nốt 8 chỗ còn lại: `CustomerController` (mergeFromImport, recordPayment manual+auto, debtAdjust, merge), `InvoiceController@update` (oldCustomer reverse + sameCustomer diff + newCustomer assign). Scan: **0 direct customer debt update còn lại** (chỉ supplier_debt_amount + Purchase.debt_amount). 87/87 PASS. **Toàn bộ 13/13 rủi ro đã đóng** (6 P0 + 6 P1 + 1 P2). | AI Agent |
| 02/05/2026 | **Bước 20 — Final Audit Summary**: Tạo `FINAL-AUDIT-SUMMARY-REPORT.md` tổng kết toàn bộ RR-01→RR-13. 87/87 audit tests PASS (262 assertions, 18 suites). 13 closure reports đầy đủ. 4 services nền chuẩn (`InvoiceSaleService`, `MovingAvgCostingService`, `StockMovementService`, `CustomerDebtService`). Risk Register sạch sẽ. Sẵn sàng deploy + phát triển tính năng mới có kiểm soát. | AI Agent |
