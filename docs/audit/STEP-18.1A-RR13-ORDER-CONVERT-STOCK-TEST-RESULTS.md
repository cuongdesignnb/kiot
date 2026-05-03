# STEP-18.1A — RR-13 Order Convert Stock Test Results

> **Bước:** 18.1A — Mở RR-13 + viết test chứng minh `OrderController@processOrder` raw decrement
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ nghiên cứu + viết test + cập nhật Risk Register. **Không sửa business code.**

---

## 1. Mục tiêu

Chứng minh `OrderController@processOrder` (chuyển Order → Invoice) **không** dùng sale engine chuẩn — pattern bug giống RR-09.

→ Kết quả: **RR-13 confirmed lỗi đa diện** (raw decrement, không update inventory_total_cost, không StockMovement, không xử lý Serial).

---

## 2. Discovery

| Nội dung | Kết quả |
|---|---|
| Route convert | ❌ **Chưa đăng ký** trong `routes/web.php`. Method `processOrder` tồn tại trong controller nhưng không có route name. |
| Controller method | `OrderController@processOrder($request, Order $order)` — dòng 314-432 |
| Order status flow | `draft`/`confirmed` → `processOrder` → `completed`. Method check `if status == 'completed'` return early; `if status in ['cancelled', 'ended']` return error. |
| Tạo Invoice | Inline (dòng 340-355): `Invoice::create(['code', 'order_id', 'subtotal', 'discount', 'total', 'customer_paid', 'customer_id', 'created_by_name', 'seller_name', 'sales_channel', 'price_book_name', 'payment_method', 'note', 'status'])`. |
| Tạo InvoiceItem | Inline (dòng 380-385): `$invoice->items()->create(['product_id', 'quantity', 'price', 'cost_price'])`. |
| Stock/cost xử lý | ❌ **Raw decrement** dòng 376-377: `$product->stock_quantity -= $orderItem->qty; $product->save();`. **Không** update `inventory_total_cost`. **Không** gọi `MovingAvgCostingService`. |
| StockMovement | ❌ **Không ghi** — `use StockMovementService` không có trong file. |
| Serial/IMEI | ❌ **Không xử lý**. Chỉ check **số lượng** serial in_stock (dòng 367-369): `SerialImei::where('product_id')->where('status', 'in_stock')->count()`. **Không** update `status='sold'`, **không** tạo `InvoiceItemSerial`, **không** snapshot `sold_cost_price`. OrderItem schema không có cột `serial_ids`. |
| CashFlow/debt | ✅ Có. Customer debt: increment `debt_amount` if != 0, increment `total_spent`. CashFlow tạo nếu `newPayment > 0` (chỉ payment lúc convert, không double với deposit). |
| Có thể dùng `InvoiceSaleService` không | ✅ Có thể — service đã sẵn sàng từ RR-02. Tuy nhiên cần xử lý CashFlow tinh tế (Order có `priorDeposit`, convert chỉ tạo CashFlow cho `newPayment`) → có thể cần thêm context flag hoặc override CashFlow logic. |
| Rủi ro phát hiện | (1) `inventory_total_cost` không update → BQ inflate (giống RR-09). (2) Thẻ kho thiếu hoàn toàn dòng out_invoice từ luồng convert. (3) Serial không được đánh dấu sold → không truy vết, không có `InvoiceItemSerial`. (4) OrderItem schema không có `serial_ids` → khó refactor full sang `InvoiceSaleService` nếu nghiệp vụ cần track serial qua Order. |

---

## 3. Risk Register

✅ **Đã thêm RR-13** vào `docs/audit/RISK_REGISTER.md`:

- **Vị trí thêm:** Bảng P1, sau RR-12.
- **Trạng thái:** 🚨 **Mới — phát hiện qua scan Bước 17**.
- **Mức độ:** P1 candidate.
- **Tổng quan:** Cập nhật `P1 — High` từ "5 đã đóng" sang "6 (5 original + 1 new RR-13) | 5 đã đóng".

Mô tả RR-13 ghi rõ:
- Bug `OrderController@processOrder` raw `stock_quantity -= qty` không qua service.
- Không update `inventory_total_cost`.
- Không ghi `StockMovement`.
- Không xử lý Serial/IMEI.
- Khuyến nghị: refactor sang `InvoiceSaleService::createSale()` hoặc tối thiểu `applySale + StockMovement::record`.

---

## 4. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Product thường | `cost=100_000`, `stock=10`, `total=1_000_000`, `has_serial=false` |
| Product serial | `cost=5_000_000`, `stock=1`, `total=5_000_000`, `has_serial=true` + 1 SerialImei in_stock |
| Order | `total_price = qty × price`, `amount_paid=0` ban đầu |
| OrderItem | `product_id, qty, price, discount=0, subtotal` |
| Convert payload | `amount_paid` (newPayment), `payment_method='cash'` |
| Method gọi | `app(OrderController::class)->processOrder($request, $order)` (route chưa đăng ký) |

---

## 5. Test đã tạo

`tests/Feature/Orders/RR13OrderConvertStockTest.php` — 4 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `order_convert_should_decrease_stock_and_inventory_total_cost` | total_cost = 700k | total_cost = 1M (không update) | ❌ FAIL (`1000000 !== 700000`) |
| `order_convert_should_create_stock_movement` | StockMovement count tăng | count = 0 | ❌ FAIL (`0 !> 0`) |
| `order_convert_should_not_allow_quantity_greater_than_stock` | Order không completed, Invoice không tạo, stock unchanged | Đúng (controller có guard `!allowOversell && stock < qty throw`) | ✅ PASS |
| `order_convert_serial_should_mark_serial_as_sold` | Serial status = 'sold' | Serial vẫn `'in_stock'` | ❌ FAIL |

---

## 6. Kết quả chạy test

```
Tests:    3 failed, 1 passed (7 assertions)
Duration: 0.58s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 4 |
| Pass | 1 |
| Fail | 3 |
| Skipped | 0 |

→ **3 test FAIL chứng minh RR-13** (cost integrity, stock movement, serial). 1 test PASS (validate quá tồn — controller có guard sẵn).

---

## 7. Nguyên nhân fail

| Test fail | Nguyên nhân |
|---|---|
| TC-01 (cost) | Controller chỉ `$product->stock_quantity -= $qty`. Không update `inventory_total_cost` → `total/qty` inflate. Cần `MovingAvgCostingService::applySale($product, $qty)`. |
| TC-02 (movement) | Controller không gọi `StockMovementService::record(...)`. Cần thêm record với `TYPE_OUT_INVOICE`. |
| TC-04 (serial) | Controller chỉ check số lượng serial in_stock, không update `status='sold'`, không tạo `InvoiceItemSerial`. OrderItem không có `serial_ids` → controller không biết serial nào sẽ ship. Limitation schema. |

---

## 8. Regression

Chạy theo từng filter riêng:

| Test | Kết quả |
|---|---|
| `RR02InvoicePosCharacterizationTest` | ✅ 5 PASS (48) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `RR12StockTransferCancelReceivedTest` | ✅ 5 PASS (23) |
| **Tổng regression** | ✅ **53 PASS** (subset đã chạy) |

→ Không có hồi quy do Bước 18.1A (vì không sửa code).

---

## 9. Kết luận

✅ **RR-13 là lỗi thật** — đa diện (4 mặt: costing, stock movement, serial, schema).

**Lỗi thuộc nhóm:**
- **Costing**: không update `inventory_total_cost` qua `MovingAvgCostingService`.
- **Stock card**: không ghi `StockMovement`.
- **Serial/IMEI**: không update status, không tạo `InvoiceItemSerial`, schema OrderItem thiếu `serial_ids`.
- **Lifecycle**: route `processOrder` chưa đăng ký (tương tự RR-08 ban đầu, đã có method nhưng không expose).

**Đủ điều kiện chuyển sang Bước 18.1B?** ✅ Có.

**Đề xuất cách sửa cho Bước 18.1B:**

**Option A (ưu tiên) — Tận dụng `InvoiceSaleService`:**
1. Build payload từ Order/OrderItem.
2. Gọi `app(InvoiceSaleService::class)->createSale($payload, $context)`.
3. Context với `validate_before_purchase_date=false` (Order đã validate ở store), `validate_stock_setting=false`.
4. Sau khi service tạo Invoice, link `invoice.order_id = $order->id`.
5. CashFlow tinh tế: service tạo CashFlow theo `customer_paid` total. Nhưng convert chỉ muốn CashFlow cho `newPayment` (extra ngoài priorDeposit).
   - **Giải pháp:** truyền `customer_paid = $newPayment` (không phải $totalPaid) để service chỉ tạo CashFlow cho phần mới. Sau đó Invoice update `customer_paid = $totalPaid` riêng.
   - Hoặc thêm context flag `skip_cashflow=true` rồi controller tạo CashFlow riêng.

**Option B (đơn giản, ít invasive) — Patch tại chỗ:**
1. Thay `$product->stock_quantity -= $qty; $product->save()` bằng `MovingAvgCostingService::applySale($product, $qty)`.
2. Thêm `StockMovementService::record($product, TYPE_OUT_INVOICE, $qty, $cost, $invoice, [...])`.
3. Serial/IMEI: nếu OrderItem hỗ trợ serial_ids thì update; nếu không, để limitation.
4. Test serial có thể fail tiếp (không có serial_ids trong order) → ghi backlog schema.

**Khuyến nghị:** **Option B trước** (sửa hẹp, an toàn). Option A là long-term (cần extend service hoặc giải quyết CashFlow tinh tế). Sau Option B, có thể migrate sang Option A nếu nghiệp vụ cần.

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 2 (tồn kho), 6 (serial) |
| `docs/audit/RISK_REGISTER.md` | RR-13 đã thêm vào P1 |
| `docs/audit/P0-P1-AUDIT-SUMMARY-REPORT.md` | Báo cáo tổng kết Bước 17 — phát hiện RR-13 |
| `docs/test-cases/RR-13-order-convert-stock.md` | Test case spec |
| `docs/audit/STEP-18.1A-RR13-ORDER-CONVERT-STOCK-TEST-RESULTS.md` | File này |
| `tests/Feature/Orders/RR13OrderConvertStockTest.php` | Feature test (1 PASS, 3 FAIL) |
| `app/Http/Controllers/OrderController.php` | Controller có bug ở `processOrder` dòng 376-377 |
| `app/Services/InvoiceSaleService.php` | Service nền, sẵn sàng cho Option A |
| `docs/audit/RR-09-CLOSURE-REPORT.md` | RR-09 closure — pattern tham khảo cho fix |
