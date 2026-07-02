# RR-02 — Characterization tests cho Invoice/POS sale logic

> **Mã rủi ro:** RR-02
> **Mức độ:** P1 — Duplicate logic + race condition tiềm ẩn ở POS (`InvoiceItemSerial.invoice_item_id=0` rồi update sau).
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Khóa hành vi hiện tại của hai luồng bán hàng (`InvoiceController@store` và `PosController@checkout`) **trước khi tách `InvoiceSaleService`**. Đảm bảo refactor không phá tồn/giá vốn/movement/serial/CashFlow/debt.

---

## Discovery (đã đọc code)

| Aspect | InvoiceController@store | PosController@checkout |
|---|---|---|
| Route | POST `/invoices` (`invoices.store`) | POST `/api/pos/checkout` (no route name) |
| Validation `payment_method` | nullable string (free) | required `in:cash,transfer` |
| Validation `customer_paid` | nullable | required |
| Code prefix | `HD` + `date('YmdHis')` + rand(10,99) | `HD` + `time()` + rand(10,99) |
| Set `Invoice.status` | ✅ `'Hoàn thành'` | ❌ không set |
| Set `Invoice.branch_id` | ✅ từ payload | ❌ không set (null) |
| Set `Invoice.sales_channel` | nullable | ✅ `'Bán trực tiếp'` |
| Set `Invoice.price_book_name` | ✅ resolve từ price_book_id | ❌ không set |
| Validate "trước ngày nhập" | ✅ Có | ❌ Không |
| Validate stock pre-transaction (Setting) | ✅ Có | ❌ Không (chỉ inline + allowOversell) |
| Tạo `InvoiceItem` | sau khi tính `$soldSerials`, trước khi tạo InvoiceItemSerial | sau khi tạo InvoiceItemSerial(invoice_item_id=0) |
| Tạo `InvoiceItemSerial` | sau khi có `$invoiceItem->id` (đúng thứ tự) | tạo trước với `invoice_item_id=0`, update sau khi có `$invoiceItem->id` (race-prone) |
| `MovingAvgCostingService::applySale` | sau tạo InvoiceItemSerial | sau update InvoiceItemSerial.invoice_item_id |
| `StockMovement.branch_id` | `invoice.branch_id` | `null` |
| `lockForUpdate()` Product | ✅ Có | ✅ Có |
| `recomputeFromSerials` | ✅ Có | ✅ Có |
| Customer debt: increment debt_amount nếu != 0 | ✅ | ✅ |
| Customer debt: increment total_spent | ✅ | ✅ |
| Auto enable dual-role | ✅ | ✅ |
| CashFlow tạo nếu paid > 0 | ✅ | ✅ (kèm CK info nếu transfer) |
| CashFlow `payment_method` | từ payload `payment_method` | từ payload `payment_method` |
| Response | redirect `invoices.index` | JSON `{success, invoice_code}` |

---

## Bug đã xác định

1. **POS `InvoiceItemSerial.invoice_item_id=0` rồi update**: Race condition tiềm ẩn — nếu nhiều POS checkout đồng thời cùng tạo records `invoice_item_id=0`, query update theo `whereIn('serial_imei_id', ...)` có thể conflict. Trong cùng 1 transaction được rollback nếu lỗi, nhưng pattern này vẫn xấu so với Invoice.
2. **POS không set `Invoice.status`** → mặc định DB column default (cần check). Có thể dẫn tới invoice "lửng" nếu báo cáo lọc theo status.
3. **POS không validate "trước ngày nhập"** + không validate stock theo Setting. Khác biệt behaviour có thể gây sai history khi backdate.

---

## Nhóm test InvoiceController

### TC-RR02-I01: Invoice sản phẩm thường — stock + cost + movement + cashflow + debt

**Setup:** Product stock=10, cost=100k, total=1M. Customer paid full.

**Action:** POST `/invoices` qty=3, payment_method='cash'.

**Kỳ vọng:**
- Invoice tạo: status='Hoàn thành', total = đúng.
- InvoiceItem qty=3, cost_price=100k.
- Product: stock=7, total=700k, cost=100k.
- StockMovement: type=`out_invoice`, qty=3.
- CashFlow tạo, type='receipt', amount=customer_paid.
- Customer.debt_amount = 0 (paid full), total_spent += total.

### TC-RR02-I02: Invoice serial — InvoiceItemSerial.invoice_item_id ≠ 0

**Setup:** Product has_serial, Serial A in_stock.

**Action:** POST `/invoices` qty=1, serial_ids=[A].

**Kỳ vọng:**
- Serial A: status='sold', invoice_id=invoice.id, sold_cost_price=cost.
- InvoiceItemSerial: tồn tại, `invoice_item_id != 0`, = invoiceItem.id, cost_price=snapshot.
- Sau commit, **không có** InvoiceItemSerial nào với invoice_item_id=0.

---

## Nhóm test PosController

### TC-RR02-P01: POS sản phẩm thường — tương đương Invoice TC-I01

**Setup:** giống I01.

**Action:** POST `/api/pos/checkout` qty=3, payment_method='cash'.

**Kỳ vọng:**
- Invoice tạo (POS dùng cùng model `Invoice`).
- InvoiceItem, Product, StockMovement, CashFlow, debt: như I01.
- (Lưu ý: Invoice.status có thể null/default vì POS không set — chỉ assert tồn tại.)

### TC-RR02-P02: POS serial — không còn InvoiceItemSerial.invoice_item_id=0 sau commit

**Setup:** Product has_serial, Serial A in_stock.

**Action:** POST `/api/pos/checkout` qty=1, serial_ids=[A], payment_method='cash'.

**Kỳ vọng:**
- Serial A: status='sold', invoice_id=invoice.id.
- InvoiceItemSerial: tồn tại, `invoice_item_id != 0`, = invoiceItem.id.
- Sau commit: **0 records** với `invoice_item_id=0` trong toàn bảng.

---

## Nhóm so sánh Invoice vs POS

### TC-RR02-C01: Cùng payload → cùng inventory effect

**Setup:** 2 product instances giống hệt; tạo 2 invoice (1 qua Invoice, 1 qua POS) với cùng qty.

**Kỳ vọng:**
- `stock_quantity` sau bán: bằng nhau.
- `inventory_total_cost`: bằng nhau.
- `cost_price`: bằng nhau.
- StockMovement type và qty: tương đương.

(Lưu ý: `branch_id` trên StockMovement có thể khác — Invoice set, POS để null. Đó là behavior hiện tại, không phải bug numerical.)

---

## Phạm vi sửa (Bước 16.1B kỳ vọng)

Tách `InvoiceSaleService` chứa logic chung:
- `createInvoice($payload, $context)` — tạo Invoice + items + serials + stock + costing + movement + debt + cashflow.
- `InvoiceController@store` và `PosController@checkout` đều gọi service này.
- Khác biệt thuộc context (route, response format, payload normalization, status default, validation extras) giữ ở controller.

Mục tiêu: cùng test characterization PASS sau refactor → đảm bảo behavior bằng nhau cho cả 2 luồng.
