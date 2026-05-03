# STEP-13.1A — RR-08 OrderReturn Serial Rollback Test Results

> **Bước:** 13.1A — Viết test chứng minh RR-08
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ nghiên cứu + viết test. **Không sửa business code, schema, route.**

---

## 1. Mục tiêu

Chứng minh `OrderReturnController@cancel` rollback Serial/IMEI **không chính xác** — chọn nhầm serial khác đang `in_stock` thay vì serial thực sự đã được trả.

---

## 2. Serial rollback discovery

| Nội dung | Kết quả |
|---|---|
| Bán serial lưu field nào | `serial_imeis`: `status='sold'`, `sold_at`, `invoice_id`, `sold_cost_price`. Đồng thời tạo `invoice_item_serials` (invoice_item_id, serial_imei_id, serial_number, cost_price) |
| Trả hàng serial nhận input nào | `OrderReturnController@store` nhận `items.*.serial_ids` (array of serial_imei_id) + `items.*.invoice_item_id` (FK) |
| ReturnItem có lưu serial không | ❌ **Không**. `return_items` columns: `id, return_id, product_id, invoice_item_id, quantity, price, discount, import_price, cost_price, timestamps`. Không có cột `serial_id` / `serial_ids` |
| Có bảng `return_item_serials` không | ❌ **Không tồn tại** |
| `serial_imeis.return_id` / `order_return_id` | ❌ Không có. Chỉ có `purchase_id`, `purchase_return_id` |
| Hủy trả hàng rollback bằng cách nào | `OrderReturnController@cancel` dòng 397-407: `SerialImei::where('product_id', ...)->where('status', 'in_stock')->whereNull('invoice_id')->limit($qty)->update([status='sold', invoice_id=...])`. **Không deterministic** — chọn serial bất kỳ. |
| Rủi ro chọn sai serial có thật không | ✅ **Có thật**. Test chứng minh: nếu có Serial B với id < Serial A (in_stock, chưa từng thuộc invoice), cancel sẽ pick Serial B (theo MySQL default ordering bằng PK ASC), gán Serial B vào invoice, để Serial A `invoice_id=null` |
| Có cần migration để fix triệt để không | ✅ **Có**. Cần thêm cột `return_items.serial_ids` (JSON) hoặc bảng trung gian `return_item_serials`, hoặc cột `serial_imeis.order_return_id` để biết serial nào đã được trả ở phiếu nào |

---

## 3. Lưu ý route

`OrderReturnController@cancel` đã có method nhưng **route chưa đăng ký** trong `routes/web.php` (P1 backlog đã ghi từ RR-11 closure). Test gọi controller method **trực tiếp** qua `app(OrderReturnController::class)->cancel($return)`. Bước 13.1B cần kèm việc đăng ký route `returns.cancel`.

---

## 4. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Product | `has_serial = true`, `cost_price = 5_000_000` |
| Serial B | created **trước** (id nhỏ hơn), `status='in_stock'`, `invoice_id=null`, **chưa từng thuộc invoice** |
| Serial A | created **sau** (id lớn hơn), bán qua invoice → `status='sold'`, `invoice_id=invoice.id` |
| Invoice | bán Serial A, `total=8_000_000` |
| InvoiceItem | qty=1, price=8M, cost=5M |
| InvoiceItemSerial | link `invoice_item_id` ↔ `serial_imei_id` (Serial A) |
| OrderReturn | tạo qua route `returns.store` với `serial_ids=[serialA.id]` |

Sau khi tạo phiếu trả Serial A: cả Serial A và Serial B đều `status='in_stock'`, `invoice_id=null`. Khi cancel, query bug sẽ pick một trong hai theo PK ASC → pick **Serial B** (id nhỏ hơn).

---

## 5. Test đã tạo

`tests/Feature/OrderReturn/RR08OrderReturnSerialRollbackTest.php` — 4 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `cancel_order_return_should_restore_exact_returned_serial` | Serial A.invoice_id = invoice.id | `null` | ❌ FAIL (`null !== 1`) |
| `cancel_order_return_should_not_pick_another_available_serial` | Linked serial id = serialA.id | linked id = serialB.id (3 thay vì 4) | ❌ FAIL (`3 !== 4`) |
| `cancel_order_return_should_be_idempotent_for_serials` | Cancel lần 2 không đổi state | giữ state | ✅ PASS |
| `return_items_schema_should_persist_returned_serial_reference` | Có cột/bảng lưu serial trả | Không có | ❌ FAIL (`false !== true`) |

---

## 6. Kết quả chạy test

```
Tests:    3 failed, 1 passed (12 assertions)
Duration: 0.75s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 4 |
| Pass | 1 |
| Fail | 3 |
| Skipped | 0 |

→ **3 test FAIL chứng minh RR-08** (bug rollback serial + thiếu schema). 1 test PASS (idempotent — vì cancel có guard `if status='Đã hủy' return`).

---

## 7. Nguyên nhân fail

| Test fail | Nguyên nhân |
|---|---|
| TC-01 (restore exact serial) | Cancel pick Serial B (id nhỏ hơn) thay vì Serial A. Serial A vẫn `invoice_id=null`. |
| TC-02 (not pick another) | Cùng nguyên nhân — Serial B bị gán `invoice_id=invoice.id` sai |
| TC-04 (schema) | `return_items` không có `serial_ids`; không có bảng `return_item_serials`; `serial_imeis` không có `return_id`/`order_return_id` |

**Root cause:** `OrderReturnController@store` nhận `serial_ids` qua request và update `serial_imeis` đúng, nhưng **không lưu** `serial_ids` vào ReturnItem để cancel có thể truy ngược. Cancel dùng query mơ hồ `whereNull('invoice_id')->limit($qty)` → chọn nhầm.

**Schema thiếu:** Để fix triệt để, Bước 13.1B cần migration thêm `return_items.serial_ids` (JSON) hoặc tạo bảng `return_item_serials`. Hoặc đơn giản hơn — vì `return_items.invoice_item_id` đã tồn tại, có thể dùng `invoice_item_serials` để rebuild list serial khi cancel (tuy nhiên `invoice_item_serials` không track serial nào đã được trả vs chưa trả; nên cách an toàn nhất vẫn là lưu trực tiếp `serial_ids` trên `return_items`).

---

## 8. Regression

Chạy theo từng filter riêng (chuẩn audit):

| Test | Kết quả |
|---|---|
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8 assertions) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15 assertions) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16 assertions) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| **Tổng regression** | ✅ **59 PASS, 0 FAIL** (50 P0 + 4 RR11 + 5 RR05 + 4 RR05 Serial) |

→ **Không có hồi quy do Bước 13.1A** (vì không sửa code).

---

## 9. Kết luận

✅ **RR-08 đã được chứng minh bằng test.**

- `OrderReturnController@cancel` rollback nhầm serial — confirmed.
- Schema thiếu để lưu serial reference — confirmed.
- Route `returns.cancel` chưa đăng ký — confirmed (đã ghi từ RR-11 closure).

**Đủ điều kiện chuyển sang Bước 13.1B?** ✅ Có.

**Có cần migration/schema change?** ✅ Có.

**Phạm vi sửa Bước 13.1B (kỳ vọng):**
1. **Migration mới:** Thêm cột `return_items.serial_ids` (JSON, nullable) để lưu danh sách `serial_imei_id` đã trả.
2. **`OrderReturnController@store`**: Khi tạo `ReturnItem`, ghi `serial_ids` vào cột mới.
3. **`OrderReturnController@cancel`**: Thay query mơ hồ bằng `SerialImei::whereIn('id', $returnItem->serial_ids)` để rollback **đúng** serial.
4. **`routes/web.php`**: Đăng ký route `returns.cancel` (POST `/returns/{return}/cancel`).
5. **Model `ReturnItem`**: Thêm cast `serial_ids => 'array'`.

(Phạm vi cụ thể quyết định ở Bước 13.1B.)

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 6.4 — "Khi hủy trả hàng, phải rollback đúng serial ban đầu. ❌ Chọn đại serial bằng `->limit($qty)->get()`. ✅ Lưu `serial_ids` trên return_item, rollback đúng những serial đó" |
| `docs/audit/RISK_REGISTER.md` | RR-08 trong P1 backlog |
| `docs/test-cases/RR-08-order-return-serial-rollback.md` | Test case spec |
| `tests/Feature/OrderReturn/RR08OrderReturnSerialRollbackTest.php` | Feature test (1 PASS, 3 FAIL) |
| `app/Http/Controllers/OrderReturnController.php` | Controller có bug ở `cancel()` dòng 397-407 + `store()` không lưu serial_ids |
| `database/migrations/2026_03_02_000000_create_returns_table.php` | Schema gốc returns/return_items |
| `database/migrations/2026_04_26_000003_add_cost_price_to_return_items_table.php` | Migration đã thêm cost_price + invoice_item_id (chưa có serial_ids) |
