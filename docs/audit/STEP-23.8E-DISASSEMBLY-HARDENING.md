# STEP 23.8E — Disassembly Hardening

> **Bước:** 23.8E — Siết rule bóc tách linh kiện qua `TaskService::disassemblePart`
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend (Service + API + Migration + SerialAvailabilityService) + Tests. UI để backlog.

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Rủi ro | Cần sửa |
|---|---|---|---|---|
| `TaskService::disassemblePart` | `app/Services/TaskService.php` | Đã có. Tăng tồn output, ghi stock_movement TYPE_REPAIR_IN, trừ serial.cost_price (clamp `max(0)`). | Không có cost cap (silent clamp). Không validate serial output. Không block external repair. Không đánh dấu serial gốc dismantled. | ✅ Refactor |
| TaskPart import | `app/Models/TaskPart.php` | `direction='import'` + `serial_ids` JSON đã có từ Step 23.8B. | Không có | Không sửa schema, dùng lại |
| Serial máy gốc | `app/Models/SerialImei.php` + ENUM `status` | ENUM `('in_stock','sold','returning','warranty','defective','returned','used_for_repair')` | Thiếu `dismantled` → không block bán được khi đã bóc. | ✅ Migration mở rộng enum |
| Output product thường | TaskService.disassemblePart | Tăng tồn qua `MovingAvgCostingService::applyPurchase`. | Không validate `quantity > 0`, `unit_cost >= 0`. | ✅ Validate |
| Output product has_serial | TaskService.disassemblePart | **Không** tạo SerialImei mới. **Không** validate serial_numbers. | Output có serial nhưng không có serial cụ thể → không truy vết được. | ✅ Bắt buộc serial_numbers, tạo SerialImei mới |
| Stock movement | StockMovementService::record TYPE_REPAIR_IN | OK. Đã ghi với reference task. | Không có | Giữ nguyên |
| Cost allocation | TaskService.disassemblePart | Trừ serial.cost_price + applyRepairAdjustment. | Không cap → có thể cho output_total > input_total → giá vốn output sai. | ✅ Cost cap |
| UI bóc tách | `resources/js/Pages/Tasks/Show.vue` | Chưa có UI serial_numbers cho output has_serial. | UX, không phải data risk | Backlog P3 |
| `SerialAvailabilityService` | `app/Services/SerialAvailabilityService.php` | BLOCKED_STATUSES chưa có `dismantled` (và cũng thiếu `used_for_repair`). | Serial dismantled vẫn được phép bán nếu không thêm. | ✅ Thêm `dismantled` + `used_for_repair` |

---

## 2. Business rules

### 2.1 Allowed task

- **Rule:** Chỉ `task.external = false` AND `task.type = repair` AND `task.serial_imei_id != null` AND `task.serialImei != null` AND status NOT IN (`completed`, `cancelled`).
- External repair không được bóc tách ở step này (máy khách gửi, không thuộc tồn nội bộ). Backlog nếu cần sau.

### 2.2 Input serial

- **Rule:** Sau lần bóc tách thành công đầu tiên, `serialImei.status = 'dismantled'`.
- Idempotent: lần 2+ không re-set (giữ nguyên `dismantled`).
- `serialImei.cost_price` giảm theo `output_total` mỗi lần bóc, clamp `max(0)`.
- `Product::recomputeFromSerials()` sync `stock_quantity` của product gốc theo serial in_stock thực tế (sau dismantled, count giảm 1).

### 2.3 Output normal product

- **Rule:** `quantity >= 1`, `unit_cost >= 0`. Không nhận `serial_numbers` (throw nếu client gửi).
- Tăng tồn output qua `MovingAvgCostingService::applyPurchase($product, $qty, $unit_cost)`.
- Stock movement `repair_in` ghi với reference task + note "Bóc linh kiện từ máy — nhập kho".

### 2.4 Output serial product

- **Rule:** Output `product.has_serial = true` → bắt buộc:
  - `serial_numbers` array, `count(serial_numbers) === quantity`.
  - Không trùng trong array.
  - Mọi `serial_number` chưa tồn tại trong DB.
- Tạo `SerialImei` mới cho từng serial: `product_id` = output, `serial_number` = input, `status='in_stock'`, `cost_price = unit_cost`.
- `task_part.serial_ids` lưu JSON array các id mới tạo.
- `Product::recomputeFromSerials()` sync `stock_quantity` cho product output.
- **Không tự sinh serial.** **Không tự chọn serial.**

### 2.5 Cost cap

- **Rule:** `output_total ≤ available`.
- `available = task.original_cost + sum(export parts.total_cost) - sum(import parts.total_cost)`.
- Nếu vượt → throw `RuntimeException` với message rõ: "Tổng giá vốn linh kiện bóc tách (X) vượt giá vốn khả dụng của máy (Y).".
- **Không silently clamp.** Toàn bộ transaction rollback (không tăng tồn, không tạo serial, không đổi serial input, không movement).

### 2.6 Rollback/remove output

- **Policy đã chọn:** **CHẶN** `removePart` cho `direction='import'`.
- Lý do: rollback đúng cần xóa SerialImei output đã tạo + decrement stock + revert serial input dismantled. Phức tạp và dễ tạo race với serial output đã được dùng tiếp ở task khác → block để tránh inconsistency.
- Throw `RuntimeException`: "Không thể gỡ linh kiện đã bóc tách (direction=import). Cần thao tác rollback riêng để xóa serial output đã tạo."
- Backlog: thiết kế rollback chính thức (Step sau) — kiểm xem serial output có đã được dùng/bán hay chưa.

### 2.7 Sale guard

- **Rule:** `SerialAvailabilityService::BLOCKED_STATUSES` thêm `dismantled` (và `used_for_repair` cũng bổ sung cho đầy đủ).
- Mọi luồng dùng `SerialAvailabilityService::isSellable()` / `findBlockedIds()` / `querySellableForProduct()` đều block serial dismantled tự động (POS, Invoice, Order).
- Test TC-12 verify.

---

## 3. Migration

| Change | Kiểu | Lý do |
|---|---|---|
| `serial_imeis.status` ENUM thêm `dismantled` | `ALTER TABLE` MODIFY ENUM | Đánh dấu serial máy gốc đã bị bóc, không còn bán được |

File: `database/migrations/2026_05_06_000002_add_dismantled_to_serial_imeis_status.php`. Idempotent qua check `information_schema.COLUMNS.COLUMN_TYPE`. Skip với SQLite (varchar). Không update dữ liệu cũ. Không drop/rename column. Existing rows vẫn hợp lệ.

---

## 4. Files changed

| File | Loại | Nội dung |
|---|---|---|
| `database/migrations/2026_05_06_000002_add_dismantled_to_serial_imeis_status.php` | NEW | Mở rộng ENUM `serial_imeis.status` thêm `dismantled` |
| `app/Services/TaskService.php` | EDIT | Refactor `disassemblePart` với cost cap, serial output validate, mark dismantled. Block `removePart` cho `direction='import'` |
| `app/Services/SerialAvailabilityService.php` | EDIT | BLOCKED_STATUSES thêm `dismantled` + `used_for_repair` |
| `app/Http/Controllers/Api/TaskController.php` | EDIT | `disassemblePart` validate nhận `serial_numbers` array |
| `tests/Feature/Tasks/Step238EDisassemblyHardeningTest.php` | NEW | 14 test cases |
| `docs/audit/STEP-23.8E-DISASSEMBLY-HARDENING.md` | NEW | File này |

**Không sửa:**

- `MovingAvgCostingService`, `StockMovementService` (không có bug — chỉ refactor caller).
- `InvoiceSaleService`, `WarrantyGenerationService`, `CustomerDebtService`.
- `Task`, `TaskPart`, `Product`, `SerialImei` models (chỉ dùng method/cột đã có).
- Tests cũ (RR07, Step 23.7/23.8A/B/C/D).
- Routes (endpoint `disassemblePart` đã có sẵn từ trước).
- UI `Tasks/Show.vue` (Backlog P3).

---

## 5. Tests

### Step238E suite (14 cases)

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_disassemble_normal_part_should_increase_stock_and_record_movement` | ✅ PASS |
| 2 | `test_disassemble_should_mark_input_serial_dismantled` | ✅ PASS |
| 3 | `test_disassemble_output_cost_cannot_exceed_input_cost` | ✅ PASS |
| 4 | `test_disassemble_multiple_outputs_total_cost_cannot_exceed_input_cost` | ✅ PASS |
| 5 | `test_disassemble_serial_output_requires_serial_numbers` | ✅ PASS |
| 6 | `test_disassemble_serial_output_count_mismatch_should_fail` | ✅ PASS |
| 7 | `test_disassemble_serial_output_duplicate_should_fail` | ✅ PASS |
| 8 | `test_disassemble_serial_output_existing_serial_should_fail` | ✅ PASS |
| 9 | `test_disassemble_serial_output_success_should_create_serials_in_stock` | ✅ PASS |
| 10 | `test_disassemble_external_repair_should_fail` | ✅ PASS |
| 11 | `test_disassemble_completed_or_cancelled_task_should_fail` | ✅ PASS |
| 12 | `test_dismantled_serial_cannot_be_sold` (qua SerialAvailabilityService) | ✅ PASS |
| 13 | `test_remove_disassembled_serial_output_should_be_blocked` | ✅ PASS |
| 14 | `test_rr07_internal_repair_parts_still_pass` | ✅ PASS |

**Tổng:** 14/14 PASS, 34 assertions, 20.11s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step238E\|RR07\|Task\|Repair\|SerialAvailability` (cluster trực tiếp + adjacent) | 60 + 2 skipped | ✅ 60 PASS (190 assertions) |
| `Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 54 | ✅ 54 PASS (183 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 135 | ✅ 135 PASS (460 assertions) |
| `Step232\|Step233\|Step234\|Step235\|Step236\|Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng regression sau 23.8E:** 341 PASS, 0 FAIL, 2 skipped, ~ 1179 assertions.

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE (6/6 cache nhóm) |
| `npm run build` | ✅ Built in 6.11s, không lỗi |
| `php artisan migrate --env=testing --force` | ✅ `2026_05_06_000002_add_dismantled_to_serial_imeis_status` ran 25.46ms |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ✅ 1 file ALTER ENUM, idempotent qua `information_schema` check, skip với SQLite |
| Có update dữ liệu cũ không? | ❌ Không |
| Có tự sinh serial không? | ❌ Không |
| Có cho output cost vượt input không? | ❌ Không (cost cap throw) |
| Có chặn bán serial dismantled không? | ✅ Có (SerialAvailabilityService BLOCKED_STATUSES) |
| Có ảnh hưởng repair cũ không? | ❌ Không (RR07 tests vẫn PASS) |
| Có tự đổi serial input ngoài trường hợp bóc tách hợp lệ không? | ❌ Không |
| Có tạo invoice/cashflow/debt ở step này không? | ❌ Không |

---

## 8. Manual QA sau deploy

- [ ] Bóc linh kiện thường (không serial) → tồn linh kiện tăng đúng + có dòng `repair_in` trong stock_movements.
- [ ] Bóc output cost vượt input → bị chặn với message "vượt giá vốn khả dụng".
- [ ] Bóc nhiều lần tổng cost vượt input → lần thứ N bị chặn.
- [ ] Bóc linh kiện has_serial thiếu `serial_numbers` → bị chặn.
- [ ] Bóc has_serial nhập đủ serial_numbers → tạo SerialImei mới với status `in_stock` + cost_price = unit_cost. `task_part.serial_ids` có danh sách id.
- [ ] Sau lần bóc đầu tiên, serial máy gốc → status `dismantled`.
- [ ] Cố bán serial máy gốc đã `dismantled` (qua POS/Invoice/Order) → bị chặn (SerialAvailabilityService).
- [ ] External repair không cho bóc tách → 422 "Chỉ hỗ trợ bóc tách với phiếu sửa chữa nội bộ".
- [ ] Task completed/cancelled không cho bóc → 422.
- [ ] Remove output `direction=import` → 422 "Không thể gỡ linh kiện đã bóc tách".
- [ ] RR-07 repair internal cũ (addPart/removePart `direction=export`) vẫn chạy.
- [ ] Verify trong DB: `tasks.original_cost + parts_cost = total_cost` vẫn đúng sau bóc tách (recalculateCosts).

---

## 9. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | UI modal `serial_numbers` cho output `has_serial` trong `Tasks/Show.vue` | P3 |
| 2 | UI hiển thị `available_for_disassembly` để user biết còn được bóc bao nhiêu | P3 |
| 3 | Báo cáo lợi nhuận/lỗ từ bóc tách (sum disassembly outputs - device cost) | P3 |
| 4 | Phiếu bóc tách độc lập ngoài task (nếu nghiệp vụ cần thanh lý không qua repair) | P3 |
| 5 | BOM/assembly nếu cần lắp ráp linh kiện thành sản phẩm mới | P3 |
| 6 | Rollback serial output: thiết kế thao tác xóa SerialImei output + decrement stock + revert dismantled, kiểm xem serial đã bán/dùng tiếp chưa | P2 |
| 7 | Notification khi máy gốc bị `dismantled` (cho người tạo phiếu) | P3 |
| 8 | Permission tách: `tasks.disassemble` (hiện ăn theo `tasks.complete` / `tasks.add-part`) | P3 |

---

## 10. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Bóc tách đã an toàn chưa? | ✅ Có. Cost cap throw, không silent clamp. Validate serial output đầy đủ. Idempotent serial input dismantled. |
| Cost cap đã đúng chưa? | ✅ Đúng. `available = original_cost + exportTotal - importTotal`. Verified qua TC-03 + TC-04. |
| Serial input/output đã đúng chưa? | ✅ Input chuyển `dismantled` → SerialAvailabilityService block bán. Output có serial → tạo `SerialImei` mới `in_stock`, `task_part.serial_ids` lưu id. |
| Có thể deploy production chưa? | ✅ Có. Migration idempotent ENUM. Không backfill. Không sửa core sale/inventory. 14 test cases + 341 regression PASS. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật (mục 5.5: idempotent cancel — applied `dismantled` cũng phải idempotent) |
| `docs/audit/STEP-23.8E-DISASSEMBLY-HARDENING.md` | File này |
| `tests/Feature/Tasks/Step238EDisassemblyHardeningTest.php` | 14 test cases |
| `app/Services/TaskService.php` | `disassemblePart()` refactored, `removePart()` block import |
| `app/Services/SerialAvailabilityService.php` | BLOCKED_STATUSES extended |
| `app/Http/Controllers/Api/TaskController.php` | `disassemblePart()` validate `serial_numbers` |
| `database/migrations/2026_05_06_000002_add_dismantled_to_serial_imeis_status.php` | Migration ENUM |
