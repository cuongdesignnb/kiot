# STEP 23.7B — Warranty Generation From Sales

**Date:** 2026-05-05
**Branch:** main (uncommitted)
**Scope:** Auto-sinh `warranties` từ POS / Invoice manual / Order process. In-transaction, idempotent, rollback-safe.

---

## 1. Discovery

| Luồng bán hàng | Entry point | Tạo invoice ở đâu | Serial xử lý | Warranty hook |
|---|---|---|---|---|
| **POS** | `POST /api/pos/checkout` → `PosController@checkout` | `InvoiceSaleService::createSale` (DB transaction) | `InvoiceItem::create` rồi `InvoiceItemSerial::create` mỗi serial, mark `serial_imeis.status='sold'` | **NEW**: cuối transaction `WarrantyGenerationService::generateForInvoice($invoice)` |
| **Invoice manual** | `POST /invoices` → `InvoiceController@store` | `InvoiceSaleService::createSale` (cùng) | Cùng | Cùng (1 nơi cover 2 luồng) |
| **Order process** | `POST /orders/{order}/process` → `OrderController@processOrder` | Inline trong `DB::beginTransaction` | Inline `InvoiceItem::create` + `InvoiceItemSerial::create` | **NEW**: trước `$order->update(status='completed')`, sau loop items |

### Schema discovery

| Bảng | Có `warranty_months`? | Ghi chú |
|---|---|---|
| `products` | ❌ | Không có cột — cần thêm trong tương lai (backlog). |
| `invoice_items` | ❌ | Không có cột snapshot — backlog. |
| `purchase_items` | ✅ | Đã có (Step `2026_03_17_000001_add_purchase_employee_date_warranty.php`). Dùng làm fallback. |
| `serial_imeis.warranty_expires_at` | ✅ | Có nhưng độc lập — không động đến trong step này. |
| `warranties` | ✅ | `invoice_code`, `product_id`, `serial_imei`, `warranty_period`, `purchase_date`, `warranty_end_date`. |

**Kết luận:** Schema chỉ có `purchase_items.warranty_months`. Resolver fallback chain: `invoice_items.warranty_months` (nếu sau này thêm) → `products.warranty_months` (nếu sau này thêm) → `purchase_items.warranty_months` (latest theo `id` desc) → 0.

---

## 2. Business rules

- **Hàng serial (`product.has_serial=true`):** với mỗi `InvoiceItemSerial` của item → 1 record `Warranty` với `serial_imei = serial_number`. (TC-1, TC-2, TC-3)
- **Hàng thường (`has_serial=false`):** 1 record `Warranty` per `InvoiceItem` nếu `warranty_months > 0` (không tạo theo `quantity` để tránh nhân bản — backlog nếu cần). `serial_imei = null`. (TC-4)
- **`warranty_months` source:** chain theo `Schema::hasColumn` để schema-tolerant. Hiện thực tế dùng `purchase_items.warranty_months` mới nhất theo `product_id`. (TC-1..4)
- **`purchase_date` source:** `invoice.sale_time ?? invoice.created_at ?? now()`.
- **`customer_name`:** `invoice.customer.name` nếu có, không thì null.
- **`warranty_end_date`:** `purchase_date + warranty_period months` (Carbon `addMonths`). (TC-8)
- **Idempotency:** unique tổ hợp `(invoice_code, product_id, serial_imei)`. Gọi nhiều lần không tạo trùng. (TC-6)
- **Rollback safety:** hook nằm BÊN TRONG `DB::transaction` của `InvoiceSaleService::createSale` và `OrderController::processOrder`. Sale fail → warranty cũng rollback. (TC-7)
- **Skip khi `warranty_months <= 0`:** áp dụng cho cả serial và normal — không tạo record warranty rỗng kỳ hạn. (TC-5)

---

## 3. Files changed

| File | Nội dung |
|---|---|
| [app/Services/WarrantyGenerationService.php](app/Services/WarrantyGenerationService.php) | **NEW** — Service `generateForInvoice(Invoice $invoice)` + resolver `warranty_months` schema-tolerant + upsert idempotent. |
| [app/Services/InvoiceSaleService.php](app/Services/InvoiceSaleService.php) | Hook `app(WarrantyGenerationService::class)->generateForInvoice($invoice)` trước `return $invoice->load(...)`, trong `DB::transaction`. |
| [app/Http/Controllers/OrderController.php](app/Http/Controllers/OrderController.php) | Hook tương tự sau loop items, trước `ActivityLog::log`, trong `DB::beginTransaction`. |
| [tests/Feature/Warranty/Step237BGenerateWarrantyFromSalesTest.php](tests/Feature/Warranty/Step237BGenerateWarrantyFromSalesTest.php) | **NEW** — 8 tests, 27 assertions. |
| [docs/audit/STEP-23.7B-WARRANTY-GENERATION-FROM-SALES.md](docs/audit/STEP-23.7B-WARRANTY-GENERATION-FROM-SALES.md) | **NEW** — báo cáo. |

KHÔNG sửa: schema (no migration), `Warranty` model, `Invoice` model, `Product` model, controllers warranty UI, sales/inventory core logic.

---

## 4. Tests

| Test | Kết quả |
|---|---|
| TC-1 `pos_sale_serial_product_should_create_warranty_per_serial` | ✅ |
| TC-2 `invoice_manual_serial_product_should_create_warranty_per_serial` | ✅ |
| TC-3 `order_process_serial_product_should_create_warranty_per_serial` | ✅ |
| TC-4 `normal_product_with_warranty_months_should_create_one_warranty_per_invoice_item` | ✅ |
| TC-5 `product_without_warranty_months_should_not_create_warranty` | ✅ |
| TC-6 `warranty_generation_is_idempotent` | ✅ |
| TC-7 `warranty_generation_rolls_back_if_invoice_transaction_fails` | ✅ |
| TC-8 `warranty_end_date_calculated_from_purchase_date` | ✅ |

### Regression

| Cluster | Kết quả |
|---|---|
| `--filter="Warranty\|Step237B"` (incl. Step 23.7 + 23.7B) | ✅ **17 passed**, 56 assertions |
| `--filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage\|Step232\|Step233\|Step234\|Step235\|Step236\|Step237"` | ✅ **160 passed**, 2 skipped, 578 assertions |

---

## 5. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 6.48s |

---

## 6. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có backfill production không? | ❌ Không (chỉ apply cho invoice tạo MỚI sau deploy) |
| Có sửa dữ liệu cũ không? | ❌ Không |
| Có tạo warranty trùng không? | ❌ Idempotent theo `(invoice_code, product_id, serial_imei)` |
| Có rollback transaction không? | ✅ Hook nằm trong `DB::transaction` của `InvoiceSaleService::createSale` + `OrderController::processOrder`. Test TC-7 verify. |

### Lưu ý production

- Hệ thống production có sản phẩm `has_serial=true` mà chưa có `purchase_items.warranty_months` → khi bán sẽ KHÔNG tạo warranty (theo rule). Đây là behavior an toàn (không sinh record kỳ hạn 0). Admin nhập kỳ hạn ở phiếu nhập tới là bắt đầu sinh.
- Invoice cũ (trước Step 23.7B) sẽ KHÔNG có warranty. Backfill làm ở Step 23.7C qua command dry-run.

---

## 7. Backlog

- **STEP 23.7C** — Backfill warranty từ invoices cũ qua `php artisan warranty:backfill --dry-run` (in báo cáo trước, --commit sau). Tận dụng `WarrantyGenerationService::generateForInvoice` (đã idempotent).
- **STEP 23.7D** — Thêm cột `warranties.invoice_id`, `warranty.invoice_item_id`, `warranties.serial_imei_id` (FK nullable) để truy ngược chính xác hơn `serial_imei` text.
- **STEP 23.7E** — Module repair/maintenance (ticket sửa chữa, lịch hẹn, lịch sử).
- **STEP 23.7F** — Thêm `products.warranty_months` (snapshot mặc định ở product) và `invoice_items.warranty_months` (snapshot tại sale time) để tránh dependency vào `purchase_items` mới nhất (rủi ro: phiếu nhập mới đổi kỳ hạn → ảnh hưởng lookup).
- **STEP 23.7G** — Tạo warranty theo `quantity` cho hàng thường nếu nghiệp vụ yêu cầu (hiện 1 warranty/item).
- **STEP 23.7H** — Thông báo nhắc bảo trì (cron + notify) khi `warranty_end_date` gần hết.

---

## 8. Manual QA sau deploy

- [ ] POS bán hàng serial → tự có warranty (1 record/serial) trong `/warranties`.
- [ ] Invoice manual bán serial → tự có warranty.
- [ ] Order process sang invoice → tự có warranty.
- [ ] Bán hàng thường có `warranty_months > 0` → có 1 warranty với `serial_imei=null`.
- [ ] Bán hàng thường không có `warranty_months` → không tạo warranty.
- [ ] Refresh trang/gọi lại không tạo trùng (idempotent — chỉ kiểm khi có command Step 23.7C).
- [ ] Sale fail (thiếu serial / hết stock) → không có warranty rác.
- [ ] `warranty_end_date` đúng = `purchase_date + warranty_period months`.

---

## 9. Conclusion

| Mục | Trạng thái |
|---|---|
| Auto warranty generation đã hoạt động chưa | ✅ POS + Invoice manual + Order process |
| Có an toàn production không | ✅ in-transaction, idempotent, schema-tolerant, không backfill, không migration |
| Có thể commit/deploy không | ✅ Sẵn sàng |

**Kết luận:** Module warranty đã được nối vào 3 luồng bán hàng chính. Service mới `WarrantyGenerationService` chuẩn — schema-tolerant, idempotent, rollback-safe. Không động vào core sales/inventory. Backfill và FK thật để Step sau.
