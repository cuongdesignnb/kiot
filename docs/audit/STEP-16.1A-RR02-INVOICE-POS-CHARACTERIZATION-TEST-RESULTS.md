# STEP-16.1A — RR-02 Invoice/POS Characterization Test Results

> **Bước:** 16.1A — Viết characterization tests cho duplicate logic Invoice/POS
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ viết test characterization. **Không sửa business code, schema, route, refactor.**

---

## 1. Mục tiêu

Khóa behavior hiện tại của `InvoiceController@store` và `PosController@checkout` trước khi tách `InvoiceSaleService` (Bước 16.1B). Đảm bảo refactor không phá tồn/giá vốn/movement/serial/CashFlow/debt.

**Kết luận sớm:** Phát hiện **bug nghiêm trọng trong POS** ngoài duplicate logic — POS hoàn toàn **không bán được hàng serial** trong DB có FK strict mode. Bước 16.1B sẽ vừa refactor vừa sửa bug này.

---

## 2. Discovery

| Aspect | InvoiceController@store | PosController@checkout | Nhận xét |
|---|---|---|---|
| Route | `POST /invoices` (`invoices.store`) | `POST /api/pos/checkout` (no name) | POS không có route name |
| Validation `payment_method` | nullable string | required `in:cash,transfer` | POS strict hơn |
| Validation `customer_paid` | nullable | required | POS strict hơn |
| Code prefix | `HD` + `date('YmdHis')` + rand | `HD` + `time()` + rand | Format khác |
| Set `Invoice.status` | ✅ `'Hoàn thành'` | ❌ không set | POS để DB default |
| Set `Invoice.branch_id` | ✅ từ payload | ❌ null | Khác |
| Set `Invoice.sales_channel` | nullable | ✅ `'Bán trực tiếp'` | Khác |
| Validate "trước ngày nhập" | ✅ Có | ❌ Không | POS có thể backdate sai |
| Validate stock pre-tx (Setting) | ✅ Có | ❌ Không (chỉ inline) | POS bỏ qua setting |
| Tạo `InvoiceItemSerial` | sau khi có `invoiceItem->id` (đúng) | tạo `invoice_item_id=0` trước, update sau | **POS race-prone + FK violation** |
| `MovingAvgCostingService::applySale` | ✅ Có | ✅ Có | Equivalent |
| `StockMovement.branch_id` | invoice.branch_id | null | Khác |
| `lockForUpdate()` Product | ✅ Có | ✅ Có | Equivalent |
| `recomputeFromSerials` | ✅ Có | ✅ Có | Equivalent |
| Customer debt logic | increment debt + total_spent | increment debt + total_spent | Equivalent |
| Auto enable dual-role | ✅ Có | ✅ Có | Equivalent |
| CashFlow tạo nếu paid > 0 | ✅ Có | ✅ Có (kèm CK info nếu transfer) | Format mô tả khác |
| Response | redirect `invoices.index` | JSON | Phù hợp use case |

**Bug rõ rệt:**
1. **POS FK violation với serial:** `InvoiceItemSerial::create(['invoice_item_id' => 0, ...])` vi phạm FK constraint `invoice_item_serials_invoice_item_id_foreign` → toàn bộ POS checkout với hàng serial **fail 500** trong DB có FK strict mode. Bug confirmed bằng test P02 + log:
   ```
   SQLSTATE[23000]: Integrity constraint violation: 1452
   Cannot add or update a child row: a foreign key constraint fails
   (sales_test.invoice_item_serials, CONSTRAINT invoice_item_serials_invoice_item_id_foreign
    FOREIGN KEY (invoice_item_id) REFERENCES invoice_items (id) ON DELETE CASCADE)
   ```
2. **POS không set `Invoice.status`** → có thể tạo invoice với status null/empty (chưa critical).
3. **POS không validate "trước ngày nhập" + stock setting** → khác behavior với Invoice.

---

## 3. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Product thường | `cost_price=100_000`, `stock=10`, `total=1M`, `has_serial=false` |
| Product serial | `cost_price=5_000_000`, `stock=1`, `total=5M`, `has_serial=true` |
| Customer | mới mỗi test, `debt_amount=0`, `total_spent=0` |
| Payment | `customer_paid=full`, `payment_method='cash'` |
| Invoice payload | `subtotal, discount, total, customer_paid, customer_id, items[product_id, quantity, price, discount, serial_ids]` |
| POS payload | `subtotal, discount, total, customer_paid, customer_id, payment_method, items[product_id, quantity, price, discount, serial_ids]` |

---

## 4. Test đã tạo

`tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` — 5 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `invoice_sale_normal_product_creates_expected_inventory_and_movement` | Stock+cost+movement+cashflow+debt đúng | đúng | ✅ PASS |
| `invoice_sale_serial_creates_valid_invoice_item_serial` | InvoiceItemSerial.invoice_item_id != 0 | đúng | ✅ PASS |
| `pos_sale_normal_product_creates_expected_inventory_and_movement` | Tương đương Invoice (sản phẩm thường) | đúng | ✅ PASS |
| `pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` | POS bán serial thành công, không có invoice_item_id=0 | **HTTP 500** (FK violation) | ❌ FAIL |
| `invoice_and_pos_sale_have_equivalent_inventory_effects_for_same_payload` | Cùng inventory/cost effect | đúng | ✅ PASS |

---

## 5. Kết quả chạy test

```
Tests:    1 failed, 4 passed (40 assertions)
Duration: 0.74s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 5 |
| Pass | 4 |
| Fail | 1 |
| Skipped | 0 |

---

## 6. Phân tích fail

**TC-RR02-P02 FAIL** không phải do test viết sai — đây là **bug thật, severity HIGH**:

- **Fail do bug thật?** ✅ Có. POS với hàng serial broken hoàn toàn trong DB có FK strict.
- **Fail do khác biệt payload?** ❌ Không. Payload đã đúng theo PosController validation.
- **Fail do POS/Invoice behavior không tương đương?** ✅ Có — Invoice tạo invoice_item trước rồi InvoiceItemSerial (đúng); POS tạo InvoiceItemSerial(invoice_item_id=0) trước rồi update sau (FK reject).
- **Có cần sửa trước refactor không?**
  - **Cách 1 (đề xuất):** Bước 16.1B refactor sang `InvoiceSaleService` sẽ tự sửa bug này — service sẽ tạo invoice_item trước rồi mới tạo InvoiceItemSerial (theo pattern Invoice).
  - **Cách 2:** Hot-fix POS controller đảo thứ tự tạo trước khi refactor — cách này không tối ưu vì sẽ phải sửa lại khi refactor.
  - → Chọn cách 1: **bug sẽ được sửa tự động trong Bước 16.1B**, characterization test P02 sẽ pass sau refactor.

**Lưu ý quan trọng cho Bước 16.1B:**
- Refactor `InvoiceSaleService` PHẢI áp dụng pattern Invoice (tạo invoiceItem trước → tạo InvoiceItemSerial với id thật).
- Test P02 hiện FAIL → sẽ pass sau refactor → đó là tín hiệu refactor đã sửa được bug.
- Các test khác đang PASS → refactor phải giữ chúng PASS.

---

## 7. Regression

Chạy theo từng filter riêng (chuẩn audit):

| Test | Kết quả |
|---|---|
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
| **Tổng regression** | ✅ **73 PASS, 0 FAIL** |

→ Không có hồi quy do Bước 16.1A (vì không sửa code).

---

## 8. Kết luận

### Đã có đủ characterization test để refactor chưa?
✅ **Có.** 5 test bao phủ:
- Sản phẩm thường: Invoice + POS (TC-I01, P01)
- Sản phẩm serial: Invoice + POS (TC-I02, P02)
- So sánh tương đương: Invoice vs POS (TC-C01)

4/5 tests PASS lock được behavior hiện tại. 1/5 FAIL chỉ ra bug POS serial — **bug này nằm trong scope refactor**, sẽ tự sửa khi tách `InvoiceSaleService`.

### Có bug thật phải sửa trước không?
❌ **Không cần fix riêng**. POS serial broken có thể được sửa tự nhiên khi refactor sang `InvoiceSaleService` áp dụng pattern Invoice (tạo invoice_item trước → tạo InvoiceItemSerial với id thật).

Tuy nhiên cần ghi nhận trong Bước 16.1B:
- **Refactor PHẢI áp dụng pattern Invoice** (tạo invoice_item trước).
- **Test P02 phải pass sau refactor** — là tín hiệu xác nhận bug đã được sửa.

### Có đủ điều kiện chuyển sang Bước 16.1B?
✅ **Có.** Phạm vi sửa kỳ vọng cho Bước 16.1B:

1. **Tạo `app/Services/InvoiceSaleService.php`** chứa logic chung:
   - `createInvoice($payload, $context = [])` — tạo Invoice + items + serials + stock + costing + movement + debt + cashflow.
   - Method này áp dụng pattern Invoice (tạo invoice_item trước, sau đó InvoiceItemSerial với id thật).
2. **`InvoiceController@store`**: gọi `InvoiceSaleService::createInvoice()` thay vì inline logic.
3. **`PosController@checkout`**: gọi cùng service, truyền context riêng (sales_channel='Bán trực tiếp', payment_method default 'cash', response JSON).
4. **Khác biệt thuộc context giữ ở controller**:
   - Validation rules (POS strict hơn).
   - Code prefix (Invoice date vs POS time).
   - Status default ('Hoàn thành' cho Invoice, similar cho POS).
   - Branch ID, price_book, validate "trước ngày nhập" — context-specific.
   - Response format (redirect vs JSON).
5. **Đảm bảo characterization test 5/5 PASS sau refactor.**

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 8 (quy tắc làm việc, refactor cần test) |
| `docs/audit/RISK_REGISTER.md` | RR-02 trong P1 backlog (P1 cuối cùng) |
| `docs/test-cases/RR-02-invoice-pos-duplicate.md` | Test case spec |
| `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` | Characterization test (4 PASS, 1 FAIL — fail = bug POS serial) |
| `app/Http/Controllers/InvoiceController.php` | Pattern đúng (tham chiếu cho refactor) |
| `app/Http/Controllers/PosController.php` | Có bug FK violation `invoice_item_id=0` |
| `app/Services/MovingAvgCostingService.php`, `app/Services/StockMovementService.php` | Services đã thiết lập, sẽ được dùng trong InvoiceSaleService |
