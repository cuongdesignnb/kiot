# STEP 23.1 — Audit luồng bán hàng trực tiếp Invoice/POS sau UI P3

**Date:** 2026-05-04
**Branch:** main
**Scope:** Invoice (manual) + POS direct sale + Order→Invoice conversion
**Goal:** Xác minh 10 yêu cầu nghiệp vụ; vá lỗ hổng nếu có; không động đến MovingAvg/CustomerDebt/StockMovement service trừ khi có bug rõ.

---

## 1. Discovery table

| Luồng | Entry point | Service chính | Serial xử lý ở | Stock xử lý ở | Debt xử lý ở | Rủi ro phát hiện |
|---|---|---|---|---|---|---|
| **POS Direct** | `PosController@checkout` ([app/Http/Controllers/PosController.php](app/Http/Controllers/PosController.php#L72)) | `InvoiceSaleService::createSale` ([app/Services/InvoiceSaleService.php](app/Services/InvoiceSaleService.php#L40)) | `processItem` line 149 (InvoiceItemSerial sau khi InvoiceItem có id) | `MovingAvgCostingService::applySale` (line 192) | `CustomerDebtService::recordSale` (line 235) | RISK-01 (đã vá) |
| **Invoice manual** | `InvoiceController@store` ([app/Http/Controllers/InvoiceController.php](app/Http/Controllers/InvoiceController.php#L119)) | Cùng service `InvoiceSaleService::createSale` | Cùng | Cùng | Cùng | RISK-01 (đã vá), RISK-02 frontend |
| **Order → Invoice** | `OrderController@processOrder` ([app/Http/Controllers/OrderController.php](app/Http/Controllers/OrderController.php#L445)) | Direct implementation (RR-13 patch, không qua service) | line 532–542 InvoiceItemSerial sau `$invoiceItem->id` | `MovingAvgCostingService::applySale` line 547 | `CustomerDebtService::recordSale` line 575 | OK (đã được verify ở Step 22.2G + RR-13) |

Toàn bộ 3 luồng đều bọc trong `DB::transaction` ⇒ rollback sạch khi lỗi.

### Routes liên quan (verified bằng `route:list`)

```
POST  /invoices                          → invoices.store
POST  /api/pos/checkout                  → (PosController@checkout)
POST  /orders/{order}/process            → orders.process
GET   /api/products/{product}/serials    → api.products.serials
GET   /api/products/search               → api.products.search
GET   /api/customers/search              → api.customers.search
GET   /api/pos/products                  → api.pos.products
GET   /api/pos/customers                 → api.pos.customers
POST  /api/pos/customers                 → (quickCreateCustomer)
GET   /pos                               → pos.index
GET   /invoices                          → invoices.index
GET   /invoices/{invoice}/show           → invoices.show
GET   /invoices/{invoice}/print          → invoices.print
GET   /invoices/{invoice}/payment-history → invoices.payment-history
DELETE /invoices/{invoice}               → invoices.destroy
```

---

## 2. Kết quả audit theo 10 yêu cầu

| # | Yêu cầu | Trạng thái | Bằng chứng |
|---|---|---|---|
| 1 | Bán sản phẩm thường | ✅ OK | `InvoiceSaleService::processItem` line 159 (`$invoice->items()->create`); RR02-I01/P01 PASS. |
| 2 | Bán sản phẩm Serial/IMEI | ✅ OK | line 175–185 (InvoiceItemSerial + SerialImei.status='sold'); RR02-I02/P02 PASS. |
| 3 | Thanh toán đủ | ✅ OK | `createCashFlowIfPaid` line 246, RR06 PASS. |
| 4 | Bán ghi nợ | ✅ OK | `updateCustomerDebt` line 191; RR06 *invoice_credit_sale* / *pos_credit_sale* PASS. |
| 5 | Ghi `stock_movements` | ✅ OK | `StockMovementService::record(TYPE_OUT_INVOICE)` line 198. |
| 6 | Ghi `customer_debts` | ✅ OK | `CustomerDebtService::recordSale` (debt_amount + ledger row). |
| 7 | Ghi `invoice_item_serial` | ✅ OK | line 175–185, dùng `$invoiceItem->id` thật. |
| 8 | Không tạo `invoice_item_id=0` | ✅ OK | RR02-I02 + RR02-P02 assertion `invoice_item_id != 0` PASS. |
| 9 | Không tự chọn đại serial | ✅ OK | Code path không có auto-pick; `assertSerialsValid` chỉ verify ID user chọn. |
| 10 | Không làm mất dữ liệu cũ | ✅ OK | Không sửa schema, không migrate. Toàn bộ test cũ (RR02/RR06/RR08/RR09/RR13/SerialAvailability) còn PASS. |

---

## 3. Lỗ hổng phát hiện và xử lý

### RISK-01 — Thiếu enforce `count(serial_ids) === quantity` ở backend (đã vá)

- **File:** [app/Services/InvoiceSaleService.php](app/Services/InvoiceSaleService.php#L42)
- **Triệu chứng:** Trước fix, nếu client POST `has_serial=true, quantity=N, serial_ids=[]` (hoặc <N), service vẫn:
  - Tạo `Invoice` + `InvoiceItem` quantity=N.
  - Tạo `InvoiceItemSerial` chỉ cho các serial được chọn (có thể 0).
  - Trừ stock theo `quantity=N` đầy đủ.
  - ⇒ **N đơn vị stock biến mất nhưng chỉ ≤N serial chuyển `sold`, phần còn lại serial vẫn `in_stock` ⇒ orphan + hụt tồn theo serial.**
- **Fix:** Thêm `assertSerialSelectionComplete($items)` chạy ngay đầu `createSale` (trong `DB::transaction`):
  - has_serial=true ⇒ bắt buộc `count(serial_ids) === quantity`.
  - Mọi serial phải sellable (`SerialAvailabilityService::findBlockedIds` rỗng).
  - has_serial=false ⇒ bỏ qua.
  - Throw `\Exception` ⇒ rollback ⇒ KHÔNG tạo Invoice rỗng.
- **Tác động lan tỏa:** POS + Invoice manual đều dùng chung service ⇒ vá 1 nơi áp dụng cả 2.

### RISK-02 — Frontend manual Invoice không có gate UI bắt serial (chưa vá)

- **File:** [resources/js/Pages/Invoices/Index.vue](resources/js/Pages/Invoices/Index.vue) chỉ là list/show; tạo Invoice manual hiện đi qua flow khác (modal hoặc external) — **không nằm trong scope Step 23.1**.
- **Mitigation hiện tại:** Backend `assertSerialSelectionComplete` đã chặn ở DB ⇒ không tạo dữ liệu sai.
- **Khuyến nghị (chưa làm):** Bổ sung gate UI tương tự Order/Create.vue (Step 22.2G) để báo trước cho user. Để Step 23.x sau nếu user yêu cầu.

### Không vá (theo nguyên tắc Step 23.1)

- `MovingAvgCostingService`, `CustomerDebtService`, `StockMovementService` — không phát hiện lỗi rõ, không sửa.
- POS checkout `allow_oversell` cho hàng thường giữ nguyên (đã tồn tại từ trước).

---

## 4. File đã sửa

| File | Sửa | Dòng |
|---|---|---|
| [app/Services/InvoiceSaleService.php](app/Services/InvoiceSaleService.php) | + gọi `assertSerialSelectionComplete` đầu `createSale`; + helper method dùng `SerialAvailabilityService::findBlockedIds`. | ~+50 dòng |
| [tests/Feature/Sales/RequireSerialOnSaleTest.php](tests/Feature/Sales/RequireSerialOnSaleTest.php) | NEW — 5 test, 15 assertions. | 230 dòng |

KHÔNG sửa MovingAvg/CustomerDebt/StockMovement service. KHÔNG sửa controller. KHÔNG sửa migration.

---

## 5. Test / Build kết quả

| Lệnh | Kết quả |
|---|---|
| `php artisan test --env=testing tests/Feature/Sales/RequireSerialOnSaleTest.php` | ✅ **5 passed**, 15 assertions, 0.68s |
| `php artisan test --env=testing --filter="RR02\|RR06\|RR13\|SerialAvailability\|RequireSerial"` | ✅ **31 passed**, 2 skipped, 150 assertions, 2.83s |
| `php artisan test --env=testing --filter="CustomerSearch\|RR02\|RR06\|RR08\|RR09\|RR13\|SerialAvailability\|RequireSerial\|Order"` | ✅ **49 passed**, 2 skipped, 205 assertions |
| `npm run build` | ✅ built in 7.76s |

### Test mới (TC-23.1)

| TC | Mô tả | Kết quả |
|---|---|---|
| 23.1-01 | Invoice serial KHÔNG kèm serial_ids → bị chặn, không tạo Invoice, stock giữ nguyên. | ✅ PASS |
| 23.1-02 | Invoice serial chọn THIẾU (qty=2, chọn 1) → bị chặn, serial vẫn `in_stock`. | ✅ PASS |
| 23.1-03 | POS serial KHÔNG kèm serial_ids → 500 + `success=false` + không tạo Invoice. | ✅ PASS |
| 23.1-04 | POS hàng thường (has_serial=false) không cần serial_ids → 200, stock giảm đúng, không tạo InvoiceItemSerial. | ✅ PASS |
| 23.1-05 | POS hàng serial chọn ĐỦ → 200, serial sold, stock=0, có stock_movement. | ✅ PASS |

---

## 6. Manual QA checklist (sau deploy)

### POS

- [ ] Bán hàng thường, thanh toán đủ → Invoice `Hoàn thành`, stock giảm, không tạo InvoiceItemSerial, có CashFlow receipt.
- [ ] Bán hàng thường, ghi nợ (customer_paid < total) → có row `customer_debts` type `sale`, `customers.debt_amount` tăng đúng.
- [ ] Bán hàng Serial/IMEI, chọn đủ serial → serial chuyển `sold`, `invoice_item_serial.invoice_item_id` không bằng 0, `cost_price` snapshot đúng.
- [ ] Bán hàng Serial/IMEI, KHÔNG chọn serial → bấm Thanh toán → toast lỗi, không có Invoice mới trong DB.
- [ ] Bán hàng Serial/IMEI, chọn THIẾU serial (qty=2 chọn 1) → bị chặn.

### Invoice manual

- [ ] Tạo Invoice từ form manual với hàng thường → tương tự POS.
- [ ] Tạo Invoice với hàng serial, không chọn → bị chặn ở backend (vào `back()->with('error', ...)`).

### Order → Invoice

- [ ] Order chỉ chứa hàng thường → bấm Xử lý → Invoice tạo OK, stock giảm đúng, không có InvoiceItemSerial.
- [ ] Order có hàng serial đã chọn từ Step 22.2G → Xử lý → Invoice + serial sold đúng.

### Rollback / dữ liệu cũ

- [ ] Hủy Invoice → stock hoàn lại, debt giảm (nếu có), serial về `in_stock` (theo logic InvoiceController::cancel hiện tại).
- [ ] Báo cáo công nợ KH cũ vẫn hiển thị đầy đủ (ledger hybrid Step 22.2E).

---

## 7. Kết luận

| Mục | Trạng thái |
|---|---|
| Toàn bộ 10 yêu cầu nghiệp vụ | ✅ Pass (sau khi vá RISK-01) |
| Test mới + cũ | ✅ 49/49 passed (2 skipped không liên quan) |
| Build | ✅ OK |
| Có thay đổi service nguy hiểm? | ❌ Không (Moving/Debt/StockMovement giữ nguyên) |
| Có thay đổi schema? | ❌ Không |
| Có rủi ro production? | ❌ Không phát sinh thêm; vá thêm 1 lớp bảo vệ |

**Kết luận:** Luồng Invoice/POS an toàn để deploy. Lỗ hổng `count(serial_ids) ≠ qty` đã được khoá ở backend service dùng chung, áp dụng cho cả POS, Invoice manual và (gián tiếp) Order→Invoice (Order đã có riêng gate Step 22.2G). Hàng thường KHÔNG cần serial. Hàng serial BẮT BUỘC chọn đủ.
