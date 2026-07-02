# STEP 23.8B — Repair Parts Serial/IMEI Tracking

> **Ngày:** 06/05/2026  
> **Trạng thái:** ✅ **10/10 tests PASS, 191/191 regression PASS**

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Đã sửa |
|---|---|---|---|
| TaskPart model | `app/Models/TaskPart.php` | Không có serial_ids | ✅ Thêm `serial_ids` fillable + cast array |
| TaskService::addPart | `app/Services/TaskService.php` | Không validate serial cho linh kiện has_serial | ✅ Thêm `validatePartSerials()`, mark `used_for_repair`, save serial_ids |
| TaskService::removePart | `app/Services/TaskService.php` | Không restore serial | ✅ Restore serial về `in_stock` + recompute |
| SerialImei status enum | DB migration | Chỉ có 6 giá trị | ✅ Thêm `used_for_repair` |
| API addPart | `TaskController.php` | Không nhận serial_ids | ✅ Nhận `serial_ids` nullable array |
| Tests | không có | — | ✅ 10 test cases mới |

---

## 2. Business rules

### 2.1 Normal parts (has_serial=false)

- Hoạt động như cũ — không yêu cầu serial_ids.
- addPart trừ tồn, ghi stock movement, serial_ids = null.
- removePart hoàn tồn, ghi movement đảo.

### 2.2 Serial/IMEI parts (has_serial=true)

- **Required:** `serial_ids` array, `count(serial_ids) === quantity`.
- **Validation:**
  - Không duplicate serial trong request.
  - Serial phải thuộc đúng `product_id`.
  - Serial phải `status = 'in_stock'`.
  - Serial không tồn tại → fail.
- **Status after add:** Serial → `used_for_repair`.
- **Status after remove:** Serial → `in_stock` (chỉ nếu đang `used_for_repair`).
- **Cost:** Tính từ `sum(cost_price)` thực tế của serial được chọn.
- **recomputeFromSerials:** Gọi sau add/remove để sync `stock_quantity`.
- **Không tự chọn serial** — nếu không truyền serial_ids thì fail.

### 2.3 External repair

- Vẫn trừ tồn linh kiện bình thường.
- Nếu linh kiện has_serial → bắt buộc serial_ids.
- Không cộng cost vào serial máy chính (vì external không có serial_imei_id).

### 2.4 Internal repair

- Giữ logic cũ: chi phí linh kiện cộng vào cost máy/serial.
- Nếu linh kiện has_serial → bắt buộc serial_ids.

---

## 3. Migration

File: `database/migrations/2026_05_06_000001_add_serial_tracking_to_repair_parts.php`

| Cột/Change | Kiểu | Nullable/default | Lý do |
|---|---|---|---|
| `task_parts.serial_ids` | json | nullable | Lưu danh sách Serial/IMEI ID đã dùng |
| `serial_imeis.status` enum | +`used_for_repair` | — | Đánh dấu serial linh kiện đã dùng cho sửa chữa |

---

## 4. Files changed

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_06_000001_add_serial_tracking_to_repair_parts.php` | Migration: serial_ids + enum expansion |
| `app/Models/TaskPart.php` | +serial_ids fillable + cast array |
| `app/Services/TaskService.php` | +validatePartSerials(), serial handling in addPart/removePart |
| `app/Http/Controllers/Api/TaskController.php` | +serial_ids param in addPart |
| `tests/Feature/Tasks/Step238BRepairPartsSerialTest.php` | 10 test cases |

---

## 5. Tests

| # | Test | Kết quả |
|---|---|---|
| 1 | `add_normal_part_without_serial_should_still_work` | ✅ PASS |
| 2 | `add_serial_part_without_serial_ids_should_fail` | ✅ PASS |
| 3 | `add_serial_part_count_mismatch_should_fail` | ✅ PASS |
| 4 | `add_serial_part_duplicate_serial_should_fail` | ✅ PASS |
| 5 | `add_serial_part_wrong_product_should_fail` | ✅ PASS |
| 6 | `add_serial_part_not_in_stock_should_fail` | ✅ PASS |
| 7 | `add_serial_part_success_should_mark_serial_used_and_reduce_stock` | ✅ PASS |
| 8 | `remove_serial_part_should_restore_serial_and_stock` | ✅ PASS |
| 9 | `external_repair_add_serial_part_should_not_require_internal_machine_serial` | ✅ PASS |
| 10 | `internal_repair_existing_flow_still_passes` | ✅ PASS |

### Regression

| Cluster | Tests | Kết quả |
|---|---|---|
| Step238B | 10 | ✅ 10 PASS |
| RR07RepairPartsTest | 4 | ✅ 4 PASS |
| Step238A | 7 | ✅ 7 PASS |
| Full regression (191 tests) | 191 | ✅ 191 PASS, 2 skip (638 assertions) |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ OK |
| `npm run build` | ✅ OK — 7.70s |
| `php artisan migrate --env=testing` | ✅ OK |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | Có — thêm cột JSON + expand enum |
| Có update dữ liệu cũ không? | Không |
| Có sửa core CostingService? | Không |
| Có tự chọn serial không? | **Không** — luôn yêu cầu client truyền |
| Có ảnh hưởng repair nội bộ? | Không — RR07 4/4 PASS |
| Enum change an toàn? | Có — chỉ thêm giá trị, không đổi/xóa |
| Existing task_parts? | Không đổi — serial_ids null hợp lệ |

---

## 8. Manual QA sau deploy

- [ ] Thêm linh kiện thường vào phiếu sửa chữa → tồn giảm
- [ ] Thêm linh kiện serial không chọn serial → bị chặn
- [ ] Chọn thiếu serial → bị chặn
- [ ] Chọn serial sai product → bị chặn
- [ ] Chọn serial không in_stock → bị chặn
- [ ] Chọn đủ serial đúng → add thành công, serial status=used_for_repair
- [ ] Remove linh kiện serial → serial về in_stock, tồn hoàn lại
- [ ] External repair thêm linh kiện serial OK nếu chọn đủ serial
- [ ] Repair nội bộ cũ vẫn chạy (RR07)

---

## 9. Backlog

| Step | Nội dung | Priority |
|---|---|---|
| 23.8C | Hoàn thành sửa chữa → tạo invoice/thu tiền/công nợ | P1 |
| 23.8D | Warranty-linked repair | P2 |
| 23.8E | Hardening bóc tách linh kiện | P3 |
| UI | Serial selector trong modal thêm linh kiện | P1 |

---

## 10. Conclusion

- ✅ **Repair parts serial tracking an toàn** — 10/10 test PASS.
- ✅ **Serial policy:** `in_stock` → `used_for_repair` (add), `used_for_repair` → `in_stock` (remove).
- ✅ **RR07 backward compat:** 4/4 PASS, normal part flow không đổi.
- ✅ **Regression:** 191 PASS, 0 FAIL.
- ✅ **Có thể deploy production** sau khi chạy migration trên MySQL.
