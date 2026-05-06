# STEP 23.9 — Stock Transfer Serial/IMEI Flow

> **Bước:** 23.9 — Cho phép chuyển kho hàng Serial/IMEI bằng cách chọn serial cụ thể.
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend (Migration + Controller + Model + SerialAvailabilityService) + UI Create + Tests. **Không thiết kế branch_inventory.**

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Rủi ro | Cần sửa |
|---|---|---|---|---|
| `StockTransferController@store` | `app/Http/Controllers/StockTransferController.php` | Step 23.5 chặn cứng `has_serial && status != draft` (BUG-3) | Production không chuyển kho được hàng có serial | ✅ Refactor: nhận `serial_ids` per item, validate, mark in_transit |
| `StockTransferController@receive` | cùng | Hỗ trợ partial cho hàng thường, không xử lý serial | Hàng có serial chưa hỗ trợ | ✅ Chặn partial cho has_serial, transition `in_transit → in_stock` |
| `StockTransferController@cancel` | cùng | Rollback stock + cost qua snapshot, không xử lý serial | Serial in_transit bị bỏ rơi sau cancel | ✅ Pre-flight check serial cho `received` (nếu đã sold sau receive → fail), rollback `in_transit → in_stock` cho `transferring` |
| `stock_transfer_items` | migration cũ | Có `quantity`, `cost_at_transfer`, KHÔNG có `serial_ids` | Không truy ngược được serial khi cancel | ✅ Migration mới idempotent thêm `serial_ids` JSON nullable |
| `SerialImei.status` ENUM | migration `2026_05_06_000002` | Có `dismantled` từ Step 23.8E, KHÔNG có `in_transit` | DB sẽ throw khi update | ✅ ALTER ENUM idempotent thêm `in_transit` |
| `SerialAvailabilityService::BLOCKED_STATUSES` | `app/Services/SerialAvailabilityService.php` | Đã có `dismantled`, `used_for_repair`. KHÔNG có `in_transit` | Serial in_transit có thể bị bán | ✅ Thêm `in_transit` |
| UI `StockTransfers/Create.vue` | resources/js | Không có serial picker | User không tạo được transfer hàng có serial | ✅ Thêm serial selector inline mỗi item has_serial |
| UI `StockTransfers/Show.vue` | — | **Không tồn tại** (controller render `Show` nhưng file không có) | Receive/Cancel hiện làm từ Index | Backlog — UI Show có thể làm sau |

---

## 2. Business rules

### 2.1 Draft

- **Rule:** Status `draft`. Hàng has_serial: cho phép tạo phiếu mà không có `serial_ids` (để user lưu nháp). Không đổi tồn, không transition serial, không stock movement. Hàng thường: giữ nguyên Step 23.5.

### 2.2 Transfer out serial

- **Rule:** Status `transferring` hoặc `received`. Hàng has_serial:
  - `serial_ids` bắt buộc, `count(serial_ids) === quantity`.
  - Không trùng (de-dupe + check raw count).
  - Mỗi serial phải `product_id` đúng và `status = 'in_stock'`.
  - Tất cả validate ở pre-flight (trước `DB::beginTransaction`) — fail là toàn bộ phiếu reject, không ghi gì.
- Sau khi tạo phiếu thành công ở status `transferring`:
  - `serial_imeis.status: in_stock → in_transit` cho các serial đã chọn.
  - `Product::recomputeFromSerials()` sync `stock_quantity`.
  - StockMovement `transfer_out` với `branch_id = from_branch_id`.
  - `cost_at_transfer` snapshot từ `product.cost_price` (RR-12 convention).
- Status `received` (transfer + receive ngay):
  - Serial KHÔNG đi qua trạng thái `in_transit` (op atomic) — giữ `in_stock`.
  - Vẫn ghi đầy đủ `transfer_out` + `transfer_in` movement.
  - `recomputeFromSerials()` ở cuối.

### 2.3 Receive serial

- **Rule:** Chỉ receive khi `transfer.status = 'transferring'`. Hàng has_serial:
  - **Không hỗ trợ partial receive** ở step này — `received_quantity` phải bằng `item.quantity`. Fail rõ message "chưa hỗ trợ nhận một phần".
  - Mọi serial trong `item.serial_ids` phải đang `in_transit` (re-check trong transaction). Nếu khác → throw RuntimeException, rollback toàn bộ.
  - Update `serial_imeis.status: in_transit → in_stock`.
  - StockMovement `transfer_in` với `branch_id = to_branch_id`, cost = `cost_at_transfer` snapshot.
  - `recomputeFromSerials()`.

### 2.4 Cancel serial

- **Rule (theo trạng thái phiếu):**
  - `draft` → chỉ đổi `status = cancelled`. Không đụng serial.
  - `transferring` →
    - Restore stock + cost ở branch nguồn (RR-12 logic giữ nguyên).
    - Serial `in_transit → in_stock` (chỉ những serial vẫn đang in_transit, idempotent qua `where('status', 'in_transit')`).
    - StockMovement đảo.
  - `received` → **pre-flight** kiểm: mọi serial đã nhận phải vẫn `in_stock`. Nếu serial đã bán/dùng/dismantled sau receive → return 422 "Không thể hủy: serial X đã không còn in_stock".
    - Nếu pre-flight pass → rollback stock/cost qua applyPurchaseReturn (giữ logic RR-12), restore source. Serial giữ `in_stock` (đang ở branch đích, sẽ "ngược về" theo logical convention global stock).
- **Idempotent:** đã `cancelled` → 422 "Phiếu đã bị hủy trước đó".

### 2.5 Normal product compatibility

- **Rule:** Hàng thường (`has_serial = false`):
  - Không nhận `serial_ids` (throw nếu client gửi để tránh ambiguity).
  - Toàn bộ logic Step 23.5 giữ nguyên — `applySale` ở store, partial receive hợp lệ với note, RR-12 cost snapshot ở cancel.
  - Tests Step235 + RR03 + RR12 vẫn PASS.

### 2.6 Sale guard

- **`in_transit` blocked:** thêm vào `SerialAvailabilityService::BLOCKED_STATUSES`. Mọi luồng `isSellable()` / `findBlockedIds()` / `querySellableForProduct()` đều block tự động (POS, Invoice, Order, Repair). Verified TC-12.

---

## 3. Migration

| Change | Kiểu | Lý do |
|---|---|---|
| `stock_transfer_items.serial_ids` | `JSON nullable` | Snapshot serial khi chuyển kho hàng has_serial — dùng để rollback khi cancel + verify khi receive |
| `serial_imeis.status` ENUM thêm `in_transit` | `ALTER TABLE ... MODIFY COLUMN` | Đánh dấu serial đang trên đường giữa 2 branch — không sellable |

File: `database/migrations/2026_05_06_000003_add_serial_to_stock_transfer.php`. Idempotent qua `Schema::hasColumn` + `information_schema.COLUMN_TYPE`. Skip với SQLite. Không update dữ liệu cũ. Existing rows `serial_ids = null` vẫn hợp lệ (hàng thường + legacy serial không có snapshot).

---

## 4. Files changed

| File | Loại | Nội dung |
|---|---|---|
| `database/migrations/2026_05_06_000003_add_serial_to_stock_transfer.php` | NEW | Migration thêm cột + ALTER ENUM |
| `app/Models/StockTransferItem.php` | EDIT | Thêm `serial_ids` vào fillable + cast `array` |
| `app/Services/SerialAvailabilityService.php` | EDIT | BLOCKED_STATUSES thêm `in_transit` |
| `app/Http/Controllers/StockTransferController.php` | EDIT | Refactor `store/receive/cancel` xử lý serial flow đầy đủ |
| `resources/js/Pages/StockTransfers/Create.vue` | EDIT | Thêm `import watch`, load serial qua `/api/tasks/product-serials`, render checkbox row dưới mỗi item has_serial, validate trước submit |
| `tests/Feature/Inventory/Step239StockTransferSerialFlowTest.php` | NEW | 13 test cases |
| `docs/audit/STEP-23.9-STOCK-TRANSFER-SERIAL-FLOW.md` | NEW | File này |

**Không sửa:**

- `MovingAvgCostingService`, `StockMovementService` (giữ logic).
- `StockTransfer` model (giữ schema).
- `Product`, `SerialImei` models (chỉ dùng method/cột đã có).
- Routes, middleware, permissions.
- Tests cũ (RR03, RR12, Step235, Step238*, Step237*, RR02/06/08/09/11/13, …).

---

## 5. Tests

### Step239 suite (13 cases)

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_transfer_serial_draft_can_be_created_without_serial_ids` | ✅ PASS |
| 2 | `test_transfer_serial_transferring_requires_serial_ids` | ✅ PASS |
| 3 | `test_transfer_serial_count_mismatch_should_fail` | ✅ PASS |
| 4 | `test_transfer_serial_duplicate_should_fail` | ✅ PASS |
| 5 | `test_transfer_serial_wrong_product_should_fail` | ✅ PASS |
| 6 | `test_transfer_serial_not_in_stock_should_fail` | ✅ PASS |
| 7 | `test_transfer_serial_transferring_success_should_mark_in_transit` | ✅ PASS |
| 8 | `test_receive_serial_transfer_should_mark_in_stock` | ✅ PASS |
| 9 | `test_receive_serial_transfer_partial_should_fail_for_now` | ✅ PASS |
| 10 | `test_cancel_transferring_serial_should_restore_in_stock` | ✅ PASS |
| 11 | `test_cancel_received_serial_should_fail_if_serial_already_sold_after_receive` | ✅ PASS |
| 12 | `test_in_transit_serial_cannot_be_sold` | ✅ PASS |
| 13 | `test_normal_stock_transfer_existing_flow_still_passes` | ✅ PASS |

**Tổng:** 13/13 PASS, 47 assertions, 22.43s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step239\|StockTransfer\|Transfer\|SerialAvailability` | 49 + 2 skipped | ✅ 49 PASS (172 assertions) |
| `Step238F\|Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 68 | ✅ 68 PASS (217 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|Damage` | 109 | ✅ 109 PASS (378 assertions) |
| `Step232\|Step233\|Step234\|Step235\|Step236\|Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng regression sau 23.9:** 318 PASS, 0 FAIL, 2 skipped (~1113 assertions). Không hồi quy.

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` (Vite) | ✅ Built in 6.82s |
| `php artisan migrate --env=testing --force` | ✅ Migration `2026_05_06_000003_add_serial_to_stock_transfer` ran 66.78ms |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ✅ 1 file (idempotent: `Schema::hasColumn` + `information_schema` check, skip SQLite) |
| Có update dữ liệu cũ không? | ❌ Không |
| Có tự chọn serial không? | ❌ Không (UI bắt buộc user chọn) |
| Có thiết kế branch_inventory không? | ❌ Không (theo đúng spec, vẫn dùng `products.stock_quantity` global + `stock_movements.branch_id`) |
| Có chặn bán serial in_transit không? | ✅ Có (`SerialAvailabilityService::BLOCKED_STATUSES`) |
| Có ảnh hưởng hàng thường không? | ❌ Không (`Step235` + `RR03` + `RR12` regression PASS — TC-13 verify) |
| Có sửa core service (MovingAvg/StockMovement) không? | ❌ Không |
| Có tạo invoice/cashflow/debt mới không? | ❌ Không |

---

## 8. Manual QA sau deploy

- [ ] `/stock-transfers/create` → tìm sản phẩm có Serial/IMEI → row badge "Serial/IMEI" + dòng selector serial xuất hiện bên dưới.
- [ ] Nếu `quantity > số serial chọn` → bấm "Hoàn thành" sẽ alert "cần chọn đủ N serial".
- [ ] Lưu tạm (draft) hàng serial mà không chọn serial → OK, không đổi tồn.
- [ ] Hoàn thành (transferring) hàng serial chọn đủ serial → phiếu tạo, serial → `in_transit`, stock_quantity giảm, có movement `transfer_out` ở branch nguồn.
- [ ] Trước khi receive: thử bán serial in_transit qua POS/Invoice/Order → bị chặn (SerialAvailabilityService).
- [ ] Receive (full) → serial → `in_stock`, có movement `transfer_in` ở branch đích.
- [ ] Receive partial cho hàng has_serial → bị chặn.
- [ ] Cancel khi `transferring`: serial về `in_stock`, stock restore, movement đảo.
- [ ] Cancel khi `received` mà serial vẫn `in_stock`: cho phép, restore stock nguồn.
- [ ] Cancel khi `received` mà có serial đã `sold/dismantled/used_for_repair` sau khi nhận: 422 "Không thể hủy: serial X đã không còn in_stock".
- [ ] Hàng thường: tạo / receive / cancel vẫn chạy như Step 23.5 (TC-13 cover).
- [ ] Verify trong DB: `stock_transfer_items.serial_ids` JSON đầy đủ id; `stock_movements.branch_id` đúng nguồn/đích.

---

## 9. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | Branch inventory thật (tồn theo chi nhánh) — kiến trúc lớn, chưa cần ngay | P3 |
| 2 | Partial receive serial-level (cho phép nhận từng serial trong N) | P3 |
| 3 | UI Show.vue cho phiếu chuyển kho (hiện chỉ có Index + Create) | P3 |
| 4 | Báo cáo serial in_transit / aging | P3 |
| 5 | Cancel sau receive: tự revert serial về branch nguồn nếu mở rộng branch_inventory | P3 |
| 6 | Permission tách: `stock_transfers.cancel`, `stock_transfers.receive` (hiện ăn theo `stock_transfers.create`) | P3 |
| 7 | UI gợi ý "Tất cả serial in_stock" để chọn hàng loạt | P3 |

---

## 10. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Chuyển kho serial đã an toàn chưa? | ✅ Có. Pre-flight validate đầy đủ (count, dup, product, status). Transition `in_stock ↔ in_transit` idempotent. Cancel có pre-flight check trước khi rollback. Sale guard chặn `in_transit`. |
| Có thể deploy production không? | ✅ Có. Migration idempotent (cột nullable + ENUM thêm value). Không update data cũ. Không sửa core service. 318 regression tests PASS. UI dùng được cho serial transfer. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này tuân thủ mục 5.5 (idempotent cancel) + mục 6 (serial state machine) |
| `docs/audit/STEP-23.5-...` | Step 23.5 audit transfer cũ (BUG-3 đã được Step 23.9 unblock đúng cách) |
| `docs/audit/STEP-23.8E-DISASSEMBLY-HARDENING.md` | Pattern serial state machine + SerialAvailabilityService extension |
| `docs/audit/STEP-23.9-STOCK-TRANSFER-SERIAL-FLOW.md` | File này |
| `tests/Feature/Inventory/Step239StockTransferSerialFlowTest.php` | 13 test cases |
| `app/Http/Controllers/StockTransferController.php` | Refactor store/receive/cancel |
| `app/Services/SerialAvailabilityService.php` | BLOCKED_STATUSES extended |
| `database/migrations/2026_05_06_000003_add_serial_to_stock_transfer.php` | Migration mới |
| `resources/js/Pages/StockTransfers/Create.vue` | UI serial selector |
