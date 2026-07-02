# RR-13 — Order convert phải dùng sale engine chuẩn

> **Mã rủi ro:** RR-13 (mới — phát hiện qua scan Bước 17)
> **Mức độ:** P1 candidate — Sai tồn/giá vốn/StockMovement khi convert Order → Invoice
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Kiểm chứng `OrderController@processOrder` (chuyển Order → Invoice) có dùng sale engine chuẩn không:
- `MovingAvgCostingService::applySale()` cập nhật `stock_quantity` + `inventory_total_cost`.
- `StockMovementService::record(TYPE_OUT_INVOICE)` ghi thẻ kho.
- Xử lý Serial/IMEI (status='sold', `InvoiceItemSerial`).
- Hoặc gọi thẳng `InvoiceSaleService::createSale()` (đã sẵn sàng từ RR-02).

---

## Bug đã đọc trong code

`OrderController@processOrder` dòng 376-385:

```php
$product->stock_quantity -= $orderItem->qty;  // ← RAW, không update total_cost
$product->save();

$invoice->items()->create([
    'product_id' => $orderItem->product_id,
    'quantity'   => $orderItem->qty,
    'price'      => $orderItem->price,
    'cost_price' => $product->cost_price ?? 0,  // ← Đọc cost trước khi update
]);
```

**Vấn đề:**
1. **Không update `inventory_total_cost`** → BQ inflate (RR-09 pattern).
2. **Không ghi `StockMovement`** → thẻ kho thiếu out_invoice.
3. **Không xử lý Serial/IMEI**: chỉ check số lượng `in_stock` ở dòng 367-369, không update `status='sold'`, không tạo `InvoiceItemSerial`, không snapshot `sold_cost_price`.
4. **Không gọi `InvoiceSaleService`** dù service đã sẵn sàng.

---

## Test cases

### TC-RR13-01: Convert order sản phẩm thường phải giảm stock VÀ inventory_total_cost

**Setup:** Product `has_serial=false`, `stock=10`, `cost=100k`, `total=1M`.
Tạo Order qty=3, then `processOrder`.

**Kỳ vọng:**
- Invoice tạo thành công (status='Hoàn thành').
- InvoiceItem qty=3, cost_price=100k.
- Product `stock_quantity = 7`.
- Product `inventory_total_cost = 700_000` (giảm theo BQ).
- Product `cost_price = 100_000` (giữ BQ).

→ Hiện tại bug: total_cost vẫn 1M (không update) → cost_price inflate ~142,857.

### TC-RR13-02: Convert order phải ghi StockMovement out_invoice

**Setup:** giống TC-01.

**Kỳ vọng:** 1 `StockMovement` mới với:
- `product_id = product.id`
- `type = 'out_invoice'`
- `qty = 3`
- `unit_cost ≈ 100_000`

→ Hiện tại bug: 0 movement.

### TC-RR13-03: Convert order quá tồn phải fail

**Setup:** stock=2. Order qty=3. Setting `inventory_allow_oversell=false`.

**Action:** processOrder.

**Kỳ vọng:**
- Exception/back with error.
- Order status không đổi (hoặc rollback).
- Invoice không được tạo (rollback transaction).
- Stock không thay đổi.

→ Controller hiện có check `if (!$allowOversell && $product->stock_quantity < $orderItem->qty) throw` → có thể PASS.

### TC-RR13-04: Convert order với serial — InvoiceItemSerial đúng

**Setup:** Product `has_serial=true`, Serial A in_stock. Order qty=1.

**Action:** processOrder.

**Kỳ vọng:**
- Serial A `status='sold'`, `invoice_id=invoice.id`, `sold_cost_price=cost`.
- `InvoiceItemSerial` tồn tại với `invoice_item_id=invoiceItem.id` (id thật).

→ Hiện tại bug: controller không update serial, không tạo InvoiceItemSerial.

**Lưu ý:** OrderItem schema không có `serial_ids`. Controller chỉ check số lượng serial in_stock — không biết serial nào sẽ được "ship". Đây cũng là limitation cần xem xét trong fix.

---

## Phạm vi sửa (Bước 18.1B kỳ vọng)

**Ưu tiên Option A — Tận dụng `InvoiceSaleService`:**
1. Trong `processOrder`, build payload từ Order/OrderItem.
2. Gọi `app(InvoiceSaleService::class)->createSale($payload, $context)` thay vì tạo Invoice + items + raw decrement inline.
3. Pass context với `code_prefix='HD' . time()`, `default_status='Hoàn thành'`, `sales_channel=$order->sales_channel`, `created_by_name=$order->created_by_name`, `seller_name=$order->assigned_to_name`, `price_book_name=$order->price_book_name`, `validate_before_purchase_date=false` (Order đã validate ở store), `validate_stock_setting=false`.
4. Sau khi service tạo Invoice xong, link `invoice.order_id = $order->id` (giữ behavior hiện tại).
5. Order debt + extra CashFlow logic (newPayment vs priorDeposit) giữ ở controller — service chỉ tạo CashFlow cho `customer_paid` total. Cần điều chỉnh để không double count CashFlow.

**Lưu ý CashFlow tinh tế:**
- Order có thể đã có CashFlow ban đầu cho deposit (`order.amount_paid`).
- Khi convert, chỉ tạo CashFlow cho `newPayment = $validated['amount_paid']` (extra payment lúc convert), KHÔNG tạo cho total.
- Service hiện tại tạo CashFlow theo `payload['customer_paid']` → cần truyền `customer_paid = newPayment` thay vì `totalPaid` để không double.
- Hoặc thêm context flag `skip_cashflow=true` rồi controller tạo CashFlow riêng cho newPayment.

→ Phạm vi sửa cần tinh tế hơn các step trước. Có thể cần extend service hoặc giữ CashFlow riêng cho convert flow.

**Option B (đơn giản, ít invasive):**
Chỉ sửa raw decrement trong `processOrder`:
- Thay `$product->stock_quantity -= $qty; $product->save()` bằng `MovingAvgCostingService::applySale($product, $qty)`.
- Thêm `StockMovementService::record(TYPE_OUT_INVOICE, ...)`.
- Xử lý Serial/IMEI nếu cần (limitation: OrderItem không có serial_ids).

→ Bước 18.1B sẽ quyết định Option A hay B sau khi đánh giá rủi ro CashFlow.
