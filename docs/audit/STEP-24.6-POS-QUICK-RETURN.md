# STEP 24.6 — POS Quick Return

## 1. Root cause

- POS hiện không có nút "Trả hàng nhanh" như KiotViet. User muốn ngồi tại màn bán hàng có thể tìm hóa đơn, chọn item/serial, và tạo phiếu trả ngay mà không phải chuyển sang module Trả hàng riêng.
- Luồng trả hàng hiện có đã đầy đủ business logic (RR-08 serial rollback, RR-11 over-return guard, Step 23.2 serial-belongs-to-invoice + count(serial_ids)=qty + no-duplicate, time-limit gating, MovingAvgCostingService stock+cost restore, CustomerDebtService ledger, CashFlow record). Tất cả nằm ở `OrderReturnController@store`.
- Vì vậy Step 24.6 **không** viết logic mới — chỉ build payload đúng và gọi `POST /returns`.

## 2. Scope

| Đã làm | Không làm |
|---|---|
| Nút "Trả hàng" ở header POS | Không trả hàng không gắn invoice |
| Modal Quick Return: search invoice → chọn item/serial → submit | Không đổi hàng bù tiền |
| 2 API read-only: `GET /api/pos/returnable-invoices` + `GET /api/pos/invoices/{id}/returnable-items` | Không sửa `MovingAvgCostingService` / `StockMovementService` / `CustomerDebtService` |
| Reuse `POST /returns` cho mọi mutation | Không bypass validation backend |
| Permission `returns.create` cho 2 API mới | Không sửa logic hủy phiếu trả |
| 12 test cases mới | Không condition engine / sửa giá vốn lịch sử |

## 3. Existing return rules reused

| Rule | Backend hiện xử lý ở đâu |
|---|---|
| Không over-return | `OrderReturnController@store` lines 200-221 (RR-11) — soldQty − alreadyReturned, fail nếu requested > remaining |
| Không trả invoice canceled | `OrderReturnController@store` line 185 — fail nếu `$invoice->status === 'Đã hủy'` |
| Serial phải thuộc invoice | `OrderReturnController@store` Step 23.2 — `SerialImei::whereIn(...)->where('product_id', ...)->where('status','sold')->where('invoice_id', $invoice_id)` |
| Serial count = qty | Step 23.2 — pre-transaction validation, `count($serial_ids) === $qty` |
| Không trùng serial trong cùng phiếu | Step 23.2 — `$seenSerialIds[]` map check |
| Stock/cost restore | `MovingAvgCostingService::applySaleReturn()` + `StockMovementService::record(TYPE_IN_INVOICE_RETURN)` |
| Customer debt | `CustomerDebtService::recordReturn()` — decrement debt, ledger row |
| CashFlow | Tạo `CashFlow` row type=payment, category='Chi tiền trả hàng khách' khi `paid_to_customer > 0` |
| Time-limit | `Setting::get('return_time_limit_enabled' / '_days' / '_action')` — block hoặc warn |

## 4. API added

| Method | URL | Purpose | Permission |
|---|---|---|---|
| GET | `/api/pos/returnable-invoices?search=<term>` | Tìm hóa đơn (mã / khách / phone / serial), exclude `status=Đã hủy`, limit 20 | `returns.create` |
| GET | `/api/pos/invoices/{invoice}/returnable-items` | Trả `items[]` với `sold_qty`, `already_returned_qty`, `remaining_qty`, `serials[]` (chỉ serial của invoice đó, status=sold). Refuse 422 nếu invoice canceled | `returns.create` |

Cả 2 đều **read-only**, không mutate DB. Công thức `remaining_qty` mirror chính xác `OrderReturnController@store` (RR-11).

## 5. POS UI

| Thành phần | Kết quả |
|---|---|
| Button "Trả hàng" trên header (right controls, cạnh DateTimePicker saleDate) | ✓ icon undo/return + label "Trả hàng" |
| Search invoice (mã / khách / phone / serial) | ✓ debounce 250ms, 20 kết quả, error 403 hiện "Bạn không có quyền tạo phiếu trả hàng." |
| Return item table | ✓ cột Sản phẩm / Đã bán / Đã trả / Còn được trả / Đơn giá / input qty hoặc serial selector |
| Serial selector | ✓ pill UI, click toggle, đánh dấu `already_returned` (line-through, disabled), đếm "X / Y" |
| Paid to customer + giảm trừ + phí + ghi chú | ✓ |
| Submit return | ✓ nút disable khi qty=0 hoặc serial mismatch; success → alert + mở `/returns?search=<code>` ở tab mới |
| Block over-return ở UI | ✓ qty input clamped `max=remaining_qty`; backend vẫn là source of truth |

## 6. Payload

| Field | Source |
|---|---|
| `invoice_id` | Selected invoice id |
| `customer_id` | invoice.customer_id |
| `branch_id` | invoice.branch_id |
| `subtotal` | computed = sum(qty*price − line.discount) |
| `discount` | user input (default 0) |
| `fee` | user input (default 0) |
| `total` | subtotal − discount + fee, clamped ≥ 0 |
| `paid_to_customer` | user input (default 0) |
| `note` | user input (default null) |
| `items[].product_id` | invoice line product_id |
| `items[].qty` | normal: user input; serial: serial_ids.length |
| `items[].price` | invoice line price |
| `items[].discount` | invoice line discount |
| `items[].invoice_item_id` | invoice line id |
| `items[].serial_ids` | empty for non-serial; selected serial ids for serial product |

## 7. Files changed

| File | Nội dung |
|---|---|
| `app/Http/Controllers/PosController.php` | NEW methods `returnableInvoices()` + `returnableItems(Invoice)`. Imports `Invoice`, `InvoiceItem`, `ReturnItem`, `SerialImei`. |
| `routes/web.php` | NEW route group `permission:returns.create` containing 2 endpoints |
| `resources/js/Pages/POS/Index.vue` | Button "Trả hàng" trong right controls + Quick Return modal (search → select → items → serials → submit). State: `showQuickReturnModal`, `returnSearch`, `returnInvoiceResults`, `selectedReturnInvoice`, `returnLineState`, `returnNote`, `returnDiscount`, `returnFee`, `returnPaidToCustomer`. Functions: `openQuickReturn`, `searchReturnableInvoices` (250ms debounce), `selectReturnInvoice`, `setReturnQty`, `toggleReturnSerial`, `submitQuickReturn`. |
| `tests/Feature/POS/Step246PosQuickReturnTest.php` | NEW — 12 test cases |
| `docs/audit/STEP-24.6-POS-QUICK-RETURN.md` | NEW — file này |

**Không sửa:** `OrderReturnController` core, `MovingAvgCostingService`, `CustomerDebtService`, `StockMovementService`, schema, tồn kho, công nợ, merge khách/NCC, lịch sử giao dịch, Step 24.4A customer hotfix, Step 24.5 datetime work.

## 8. Tests

| Test | Result |
|---|---|
| TC-01 `returnable_invoices_search_requires_returns_create_permission` | ✅ 403 |
| TC-02 `returnable_invoices_search_by_code_customer_phone` | ✅ |
| TC-03 `returnable_items_show_remaining_qty_after_partial_return` | ✅ remaining=2 sau khi trả 1/3 |
| TC-04 `returnable_items_refuses_cancelled_invoice` | ✅ 422 |
| TC-05 `quick_return_normal_product_creates_return_and_restores_stock` | ✅ stock +1, DB row created |
| TC-06 `quick_return_cannot_exceed_remaining_qty` | ✅ validation error, no mutation |
| TC-07 `quick_return_serial_count_must_match_qty` | ✅ validation error |
| TC-08 `quick_return_serial_must_belong_to_invoice` | ✅ validation error |
| TC-09 `quick_return_serial_success_marks_serial_in_stock` | ✅ status sold→in_stock |
| TC-10 `returnable_invoices_excludes_cancelled` | ✅ |
| TC-11 `returnable_items_only_lists_serials_for_that_invoice` | ✅ serial của invoice khác không xuất hiện |
| TC-12 `pos_quick_return_routes_are_registered` | ✅ |

## 9. Build

| Command | Result |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ Built in 7.34s |
| Step246 + OrderReturn + RR08 + RR11 cluster | ✅ **28 PASS**, 88 assertions |
| Regression cluster (RR02-13, Order, Purchase, Step232-245, Customer hotfix, Auth, Permission, Dashboard, ActivityLog, …) | ✅ **326 PASS**, 2121 assertions, 3 pre-existing skipped, **0 fail** |

## 10. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có sửa core return service không? | **Không** — POST /returns vẫn là `OrderReturnController@store` |
| Có sửa MovingAvg / Stock / Debt không? | **Không** |
| Có bypass backend validation không? | **Không** — UI clamp là tiện ích; backend vẫn validate |
| Có ảnh hưởng POS bán hàng không? | **Không** — modal tách hoàn toàn, không động cart hiện tại |
| Có ảnh hưởng Customers/Invoicing hotfix không? | **Không** |

## 11. Manual QA

- [ ] User có `returns.create` thấy nút "Trả hàng" trên POS header.
- [ ] User thiếu `returns.create`: 2 API trả 403, modal hiển thị "Bạn không có quyền tạo phiếu trả hàng.".
- [ ] Bấm "Trả hàng" → modal mở.
- [ ] Search mã hóa đơn → ra đúng.
- [ ] Search SĐT khách → ra đúng.
- [ ] Search serial → ra đúng hóa đơn chứa serial.
- [ ] Hóa đơn đã hủy không xuất hiện trong kết quả search.
- [ ] Bấm vào kết quả search → load items + remaining_qty.
- [ ] Hàng thường qty 3, trả 1 → tạo phiếu OK; tồn +1; công nợ giảm; cashflow đúng nếu trả tiền khách.
- [ ] Trả quá số lượng còn lại bị backend chặn (UI cũng clamp).
- [ ] Hàng serial: chỉ chọn được serial thuộc hóa đơn; trả thành công serial về `in_stock`; serial không thuộc hóa đơn bị backend chặn.
- [ ] Hủy phiếu trả vẫn rollback đúng (logic cũ giữ nguyên).
- [ ] POS bán hàng bình thường vẫn tạo hóa đơn được.
- [ ] `/customers` vẫn OK.
- [ ] `/invoices` vẫn OK.

## 12. Conclusion

- Quick return đã dùng được chưa: **Có** — modal tích hợp đầy đủ, gọi đúng `POST /returns`.
- Có bảo toàn logic trả hàng hiện có không: **Có** — không sửa controller core, không bypass, mọi rule (RR-08/RR-11/Step 23.2) vẫn được enforce server-side.
- Có thể commit/deploy không: **Có** — build pass, 28 hotfix + 326 regression pass, 0 fail.
