# STEP-18.1B — Fix RR-13 Order Convert Stock

> **Bước:** 18.1B — Sửa RR-13 (Option B: patch hẹp tại `OrderController@processOrder`)
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 1 controller + 1 route + 1 test alignment

---

## 1. Vấn đề đã sửa

- ✅ Raw `$product->stock_quantity -= $orderItem->qty; $product->save();` (dòng 376-377 cũ).
- ✅ Không update `inventory_total_cost` → BQ inflate (giống RR-09).
- ✅ Không ghi `StockMovement` → thẻ kho thiếu out_invoice.
- ✅ Route `processOrder` chưa đăng ký.
- ✅ Serial/IMEI: trước fix chỉ check số lượng `in_stock`, không update status. Sau fix: **fail-safe** — throw nếu OrderItem không có `serial_ids` (schema chưa hỗ trợ → không chọn đại serial).

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Http/Controllers/OrderController.php` | Imports | Thêm `SerialImei`, `InvoiceItemSerial`, `MovingAvgCostingService`, `StockMovementService` |
| `app/Http/Controllers/OrderController.php` | `processOrder()` | Thay raw decrement bằng `MovingAvgCostingService::applySale()`. Tạo `InvoiceItem` TRƯỚC. Snapshot cost. Ghi `StockMovementService::record(TYPE_OUT_INVOICE)`. Serial: nhận `serial_ids` từ `$orderItem->serial_ids` (nếu schema có) hoặc throw fail-safe. |
| `routes/web.php` | Route | Đăng ký `Route::post('/orders/{order}/process', [OrderController::class, 'processOrder'])->name('orders.process')->middleware('permission:orders.edit')` |
| `tests/Feature/Orders/RR13OrderConvertStockTest.php` | Test alignment | TC-04 đổi từ "should mark serial as sold" → "without serial_ids should fail safely". Schema `OrderItem` không có `serial_ids` nên expected behavior an toàn là throw, không phải mark sold tự động. |

**Không sửa:** Migration, Model schema, InvoiceSaleService, các module khác.

---

## 3. Cách sửa

### 3.1. `OrderController@processOrder`

**Trước sửa (dòng 376-385):**
```php
$product->stock_quantity -= $orderItem->qty;
$product->save();

$invoice->items()->create([
    'product_id' => $orderItem->product_id,
    'quantity'   => $orderItem->qty,
    'price'      => $orderItem->price,
    'cost_price' => $product->cost_price ?? 0,
]);
```

**Sau sửa:**
```php
$product = $orderItem->product
    ? Product::lockForUpdate()->find($orderItem->product->id)
    : null;
if (!$product) continue;

$qty = (int) $orderItem->qty;
$allowOversell = Setting::get('inventory_allow_oversell', true);

// RR-13: Serial product — phải có serial_ids. Không chọn đại.
$serialIds = [];
if ($product->has_serial) {
    if (isset($orderItem->serial_ids) && is_array($orderItem->serial_ids)) {
        $serialIds = $orderItem->serial_ids;
    }
    if (empty($serialIds)) {
        throw new \Exception(
            "Sản phẩm '{$product->name}' là hàng Serial/IMEI nhưng đơn hàng "
            . 'chưa lưu serial_ids. Vui lòng chọn Serial/IMEI trước khi chuyển hóa đơn.'
        );
    }
    if (count($serialIds) !== $qty) { ... }
    // validate available
} elseif (!$allowOversell && $product->stock_quantity < $qty) {
    throw new \Exception(...);
}

// Snapshot cost TRƯỚC applySale
$costSnapshot = (float) ($product->cost_price ?? 0);

// Tạo InvoiceItem TRƯỚC
$invoiceItem = $invoice->items()->create([
    'product_id' => $orderItem->product_id,
    'quantity'   => $qty,
    'price'      => $orderItem->price,
    'cost_price' => $costSnapshot,
]);

// Tạo InvoiceItemSerial sau (id thật) + đánh dấu serial sold
if ($product->has_serial && !empty($serialIds)) {
    $soldSerials = SerialImei::whereIn('id', $serialIds)
        ->where('product_id', $product->id)
        ->get();
    foreach ($soldSerials as $serial) {
        InvoiceItemSerial::create([
            'invoice_item_id' => $invoiceItem->id,
            'serial_imei_id'  => $serial->id,
            'serial_number'   => $serial->serial_number,
            'cost_price'      => $costSnapshot,
        ]);
        $serial->status = 'sold';
        $serial->sold_at = now();
        $serial->invoice_id = $invoice->id;
        $serial->sold_cost_price = $costSnapshot;
        $serial->save();
    }
}

// Trừ tồn + costing qua service
MovingAvgCostingService::applySale($product, $qty);
$product->refresh();
if ($product->has_serial) {
    $product->recomputeFromSerials();
}

// Ghi StockMovement
StockMovementService::record(
    $product->fresh(),
    StockMovementService::TYPE_OUT_INVOICE,
    $qty,
    $costSnapshot,
    $invoice,
    [
        'branch_id' => $invoice->branch_id ?? null,
        'ref_code'  => $invoice->code,
        'moved_at'  => $invoice->created_at ?? now(),
        'note'      => "Xuất bán từ đơn hàng {$order->code} sang hóa đơn {$invoice->code}",
    ]
);
```

- **Cost snapshot lấy lúc nào?** TRƯỚC khi gọi `applySale` (giống pattern RR-02 InvoiceSaleService).
- **Gọi applySale thế nào?** `MovingAvgCostingService::applySale($product, $qty)` — service giảm `stock_quantity` + `inventory_total_cost`, giữ `cost_price` BQ.
- **Ghi StockMovement thế nào?** `TYPE_OUT_INVOICE`, ref = invoice, ref_code = invoice.code, branch_id từ invoice.

### 3.2. Serial/IMEI

- **OrderItem có `serial_ids` không?** ❌ **Không có** trong schema migration `order_items` (`product_id, qty, price, discount, subtotal, timestamps`). Model dùng `$guarded = ['id']` nên nếu DB column tồn tại thì sẽ accessible, nhưng hiện không.
- **Nếu có (tương lai schema thêm),** controller đã chuẩn bị: lấy từ `$orderItem->serial_ids`, validate count + ownership + status, tạo InvoiceItemSerial với id thật, mark serial sold.
- **Nếu không có (hiện tại),** controller throw exception fail-safe: `"Sản phẩm '{$name}' là hàng Serial/IMEI nhưng đơn hàng chưa lưu serial_ids. Vui lòng chọn Serial/IMEI trước khi chuyển hóa đơn."`. Transaction rollback → không tạo Invoice/InvoiceItem/Movement, không trừ tồn, không động vào Serial.
- **Vì sao không chọn đại serial?** AGENT_RULES mục 6.4: "Khi trả hàng/cancel, phải rollback đúng serial ban đầu. ❌ Chọn đại serial bằng `->limit($qty)->get()`" — quy ước này áp dụng tương tự cho convert. Chọn đại serial qua `whereNull('invoice_id')->limit($qty)` là pattern bug RR-08 đã fix. Không tái phạm.

### 3.3. Route

| Aspect | Giá trị |
|---|---|
| Method | POST |
| URI | `/orders/{order}/process` |
| Name | `orders.process` |
| Middleware | `permission:orders.edit` (cùng group orders.update) |

### 3.4. CashFlow / debt

✅ **Giữ nguyên** logic `priorDeposit` / `newPayment` của controller cũ:
- `$priorDeposit = $order->amount_paid ?? 0` (deposit đã ghi CashFlow lúc Order store).
- `$newPayment = $validated['amount_paid']` (extra lúc convert).
- `$totalPaid = $priorDeposit + $newPayment` → set vào `Invoice.customer_paid` và `Order.amount_paid`.
- Customer debt: `increment('debt_amount', total_payment - totalPaid)`.
- CashFlow: chỉ tạo nếu `$newPayment > 0` (không double count priorDeposit).

→ **Không double CashFlow.** Đây là lý do KHÔNG dùng `InvoiceSaleService` (Option A) — service tạo CashFlow theo `customer_paid` total, không phân biệt prior vs new. Patch hẹp tránh ảnh hưởng logic payment đã ổn.

---

## 4. Kết quả test

### 4.1. RR-13

| Test | Trước Step 18.1B | Sau Step 18.1B |
|---|---|---|
| `order_convert_should_decrease_stock_and_inventory_total_cost` | ❌ FAIL (1M ≠ 700k) | ✅ PASS |
| `order_convert_should_create_stock_movement` | ❌ FAIL (0 movements) | ✅ PASS |
| `order_convert_should_not_allow_quantity_greater_than_stock` | ✅ PASS | ✅ PASS |
| `order_convert_serial_without_serial_ids_should_fail_safely` | ❌ FAIL (test cũ kỳ vọng "mark sold") | ✅ PASS (test mới kỳ vọng fail-safe) |
| **Tổng** | 1 PASS, 3 FAIL | ✅ **4 PASS, 0 FAIL** (19 assertions, 0.55s) |

### 4.2. Audit regression

| Test | Kết quả |
|---|---|
| `RR02InvoicePosCharacterizationTest` | ✅ 5 PASS (48) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `RR12StockTransferCancelReceivedTest` | ✅ 5 PASS (23) |
| **Tổng audit regression** | ✅ **78 PASS** |

### 4.3. Tổng

| Mục | Kết quả |
|---|---|
| **RR-13** | ✅ 4 PASS, 0 FAIL |
| **P0 audit + P1 regression** | ✅ 78 PASS |
| **Tổng tests sau Bước 18.1B** | ✅ **82 PASS, 0 FAIL** |

---

## 5. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Order serial convert chưa hỗ trợ đầy đủ | P3 backlog | Schema `order_items` không có `serial_ids`. Nếu nghiệp vụ cần convert order hàng serial, phải thêm migration `order_items.serial_ids` + UI Order Create cho phép chọn serial. Hiện tại fail-safe (throw exception) — đúng. |
| 2 | Order serial workaround | P3 | Nếu user cần convert order serial gấp: sửa thủ công OrderItem trong DB thêm `serial_ids` qua `order_items` table, hoặc tạo Invoice trực tiếp từ POS/Invoice flow. |
| 3 | Long-term refactor sang `InvoiceSaleService` | P3 | Hiện không refactor vì CashFlow priorDeposit/newPayment cần xử lý tinh tế. Có thể migrate sau khi chuẩn hóa Order payment flow. |
| 4 | UI cho `orders.process` | P3 | Backend route đã có; UI `Orders/Show` hoặc `Orders/Index` cần thêm nút "Xử lý" gọi route này. |
| 5 | Permission tách | P3 | Hiện dùng chung `orders.edit`. Có thể tách `orders.process` riêng nếu phân quyền chi tiết. |
| 6 | RR-06 P2 còn lại | P2 | Customer debt transactions/service. |

---

## 6. Kết luận

✅ **RR-13 đã Fixed/Verified.**

- Stock + cost + StockMovement: đã đúng qua `MovingAvgCostingService::applySale()` + `StockMovementService::record(TYPE_OUT_INVOICE)`.
- Route `orders.process` đã đăng ký.
- Serial xử lý an toàn: nếu OrderItem có `serial_ids` (schema tương lai) → tạo InvoiceItemSerial với id thật + mark sold. Nếu không có (hiện tại) → throw fail-safe, không chọn đại serial.
- Customer debt + CashFlow + priorDeposit logic giữ nguyên, không double payment.
- 4/4 RR-13 + 78 audit regression = **82/82 PASS, 0 FAIL.**
- Phạm vi sửa hẹp: 1 controller (~70 dòng patch + 4 imports) + 1 route + 1 test alignment. Không refactor module khác, không sửa migration.
- Pattern thống nhất với RR-02 (`InvoiceSaleService`), RR-09 (`Damage`): tạo InvoiceItem trước → InvoiceItemSerial với id thật → applySale → StockMovement.

**Có thể chuyển sang RR-13 closure report (Bước 18.2)** hoặc tiếp tục với RR-06 P2.

---

## 7. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 2 (tồn kho), 6 (serial) |
| `docs/audit/RISK_REGISTER.md` | RR-13 sẽ chuyển sang Fixed/Verified ở Bước 18.2 |
| `docs/test-cases/RR-13-order-convert-stock.md` | Test case spec |
| `docs/audit/STEP-18.1A-RR13-ORDER-CONVERT-STOCK-TEST-RESULTS.md` | Test chứng minh lỗi (1 PASS, 3 FAIL) |
| `docs/audit/STEP-18.1B-RR13-ORDER-CONVERT-STOCK-FIX-RESULTS.md` | File này (4 PASS, 0 FAIL) |
| `tests/Feature/Orders/RR13OrderConvertStockTest.php` | Feature test (4 PASS) |
| `app/Http/Controllers/OrderController.php` | Đã sửa `processOrder` + imports |
| `routes/web.php` | Đã đăng ký `orders.process` |
| `app/Services/InvoiceSaleService.php` | Long-term refactor target — chưa dùng ở step này |
