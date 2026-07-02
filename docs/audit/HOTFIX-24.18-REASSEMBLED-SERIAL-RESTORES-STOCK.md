# HOTFIX 24.18 — Restore reassembled serials to stock safely

## 1. Root cause

Tester báo serial `MTXNBZJ1WK`:
- `status = dismantled`
- `repair_status = ready`
- chưa bán / chưa trả NCC
- `product.stock_quantity = 0`
- UI Welcome.vue (đã HOTFIX 24.16 đúng) → hiện badge "⚠ Đã bóc tách" (đỏ), không phải "Sẵn bán". Ảnh tester có thể chụp **trước** HOTFIX 24.16 hoặc khi cache trình duyệt cũ.

Vấn đề thật sự là **dữ liệu kẹt** chứ không phải UI sai:
- [HOTFIX 24.16](docs/audit/HOTFIX-24.16-REPAIR-SERIAL-DISMANTLED-READY.md) wired `updateInternalRepairSerialStatus()` vào `Task::changeStatus(COMPLETED)`. Chỉ chạy khi tasks transition COMPLETED.
- Serial cũ kẹt `dismantled+ready` **trước** khi 24.16 deploy, hoặc task repair được hoàn thành rồi nhưng path 24.16 không restore (vì có `task_part direction='import'` còn lại trên cùng task).
- Sau khi anh em "bóc + lắp đồ xong" thật, dữ liệu vẫn không tự sửa — chưa có **lever thủ công** nào để hoàn nguyên.
- `rollbackDisassembledPart` đã có sẵn nhưng yêu cầu task chưa completed — không dùng được cho data đã closed.

⇒ Cần API operator-triggered "đã lắp lại xong" để bật serial về `in_stock` an toàn.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Services/TaskService.php`](app/Services/TaskService.php) | edit | Thêm method `restoreReassembledSerial(int $serialId, ?int $userId): array`. Bọc trong `DB::transaction` + `lockForUpdate`. Refuse: serial not dismantled / repair_status≠ready / sold / returned / có task mở mang import part. Set `status=in_stock`, `recomputeFromSerials()`, log `ActivityLog`. Idempotent: serial đã `in_stock+ready` → no-op `['restored' => false, 'reason' => 'already_in_stock']`. |
| [`app/Http/Controllers/Api/TaskController.php`](app/Http/Controllers/Api/TaskController.php) | edit | Thêm `restoreReassembledSerial(Request, SerialImei)` — thin wrapper. `RuntimeException` → 422 với message tiếng Việt. Trả về serial + product để FE refresh ngay. |
| [`routes/api.php`](routes/api.php#L221) | edit | `POST /api/tasks/serials/{serial}/restore-reassembled` — gated bằng permission `tasks.complete` (cùng nhóm với người đóng phiếu sửa). |
| [`tests/Feature/Tasks/HOTFIX2418ReassembledSerialRestoresStockTest.php`](tests/Feature/Tasks/HOTFIX2418ReassembledSerialRestoresStockTest.php) | NEW | 8 TC pin contract: happy path, active disassembly block, sold block, returned block, mid-repair block, availability service still blocks `dismantled+ready`, API endpoint OK, idempotent no-op. |
| [`docs/audit/HOTFIX-24.18-REASSEMBLED-SERIAL-RESTORES-STOCK.md`](docs/audit/HOTFIX-24.18-REASSEMBLED-SERIAL-RESTORES-STOCK.md) | NEW | Báo cáo này. |

**Không sửa:**
- `SerialAvailabilityService` — `dismantled` vẫn nằm trong `BLOCKED_STATUSES`, KHÔNG cho `dismantled` bán được bừa. TC-06 pin.
- `updateInternalRepairSerialStatus()` — HOTFIX 24.16 logic intact. 24.18 chỉ thêm path operator-triggered độc lập.
- `rollbackDisassembledPart()` — không động.
- `disassemblePart()`, `markCompleted()`, `addPart()` — không động.
- `recordPayment` / `adjustDebt` / `debtOffset` / Purchase / PurchaseReturn / CashFlow / POS / Invoice — không động.
- Welcome.vue badge — HOTFIX 24.16 đã guard đúng ở 2 chỗ (line 1420 + 1529). Operator có thể trigger restore qua API (curl/tinker/script). UI button thêm vào sau nếu cần (phase tiếp).
- Migration / DB schema / `stock_quantity` raw SQL — không động.

## 3. Luồng restore về `in_stock`

```
POST /api/tasks/serials/{serial}/restore-reassembled
  ↓
TaskController::restoreReassembledSerial(Request, SerialImei)
  ↓
TaskService::restoreReassembledSerial(int $serialId, ?int $userId)
  ↓
DB::transaction:
  serial = SerialImei::lockForUpdate()->find($serialId)
  guards (xem §4)
  serial.status = 'in_stock'; serial.save()
  serial.product->recomputeFromSerials()
  ActivityLog::create(action='serial_imei.restore_reassembled')
```

`recomputeFromSerials()` đếm lại số serial sellable cho product → `stock_quantity` tăng đúng. Không dùng raw `update stock_quantity = ...`. Không động `inventory_total_cost` (giá vốn moving-avg giữ nguyên — đã được điều chỉnh khi `disassemblePart`/`rollback`).

## 4. Điều kiện an toàn (5 lớp guard)

| # | Guard | Hành vi khi fail | TC |
|---|---|---|---|
| 1 | `serial.status === 'dismantled'` | Throw RuntimeException ("hiện ở trạng thái X") → 422 | TC-01/-08 |
| 2 | `serial.repair_status === 'ready'` | Throw ("repair_status=… — không thể hoàn nguyên") → 422 | TC-05 |
| 3 | `invoice_id IS NULL && sold_at IS NULL` | Throw ("đã bán") → 422 | TC-03 |
| 4 | `purchase_return_id IS NULL` | Throw ("đã trả NCC") → 422 | TC-04 |
| 5 | Không có task mở (status ∉ {completed, cancelled}) trên cùng serial với `task_parts.direction='import'` | Throw ("còn phiếu sửa đang mở — đóng/hoàn tất phiếu trước") → 422 | TC-02 |

Idempotent: serial đã `in_stock+ready` → no-op trả `{ restored: false, reason: 'already_in_stock' }` (TC-08). Không 422, không sửa gì.

**Tại sao chấp nhận task closed có import part:** một khi task đã COMPLETED hoặc CANCELLED, linh kiện bóc ra đã được hạch toán xong (vào kho của product output). Operator nói "máy đã lắp lại" = họ đã thay linh kiện khác vào máy. OLD linh kiện đã thành inventory độc lập, không còn ràng buộc serial gốc. Đó là semantics nghiệp vụ đã match với rule 4-5 trong PHẦN B của brief.

## 5. UI badge (đã có từ 24.16)

Welcome.vue có 2 nơi render serial badge — cả hai đã guard chuẩn từ HOTFIX 24.16:

```vue
<!-- Line ~1420 (full row) + ~1529 (compact) -->
<span v-else-if="s.repair_status === 'ready' && s.status === 'dismantled'"
      class="bg-red-100 text-red-700">⚠ Đã bóc tách</span>
<span v-else-if="s.repair_status === 'ready'"
      class="bg-green-100 text-green-600">✓ Sẵn bán</span>
```

24.18 KHÔNG đụng FE — operator gọi API qua Postman/curl/tinker/script. Phase sau có thể thêm nút "Hoàn nguyên (đã lắp lại)" trong Welcome.vue khi `s.status === 'dismantled' && s.repair_status === 'ready' && !s.invoice_id && !s.sold_at && !s.purchase_return_id`.

## 6. Data repair an toàn

### 6.1. Dry-run — tìm serial kẹt

```sql
SELECT si.id, si.serial_number, si.product_id, si.status, si.repair_status,
       si.invoice_id, si.sold_at, si.purchase_return_id, si.updated_at
FROM serial_imeis si
WHERE si.status = 'dismantled'
  AND si.repair_status = 'ready'
  AND si.invoice_id IS NULL
  AND si.sold_at IS NULL
  AND si.purchase_return_id IS NULL
ORDER BY si.updated_at DESC;
```

### 6.2. Per-serial verify — có task mở với import part không?

```sql
SELECT t.id AS task_id, t.code, t.status AS task_status,
       tp.id AS task_part_id, tp.direction, tp.product_id, tp.quantity, tp.serial_ids,
       tp.created_at
FROM tasks t
LEFT JOIN task_parts tp ON tp.task_id = t.id AND tp.direction = 'import'
WHERE t.serial_imei_id = <SERIAL_ID>
  AND t.status NOT IN ('completed', 'cancelled')
ORDER BY t.id DESC, tp.id DESC;
```

Nếu query trên trả 0 row → safe để restore qua API.

### 6.3. Restore qua API (recommended)

```bash
curl -X POST -b "session_cookie=..." \
  https://kiot.cuongdesign.net/api/tasks/serials/<SERIAL_ID>/restore-reassembled
```

API tự chạy 5 guards + recompute + log. Không cần raw SQL.

### 6.4. Restore qua tinker (cho DBA không có quyền HTTP)

```bash
php artisan tinker
> app(\App\Services\TaskService::class)->restoreReassembledSerial(<SERIAL_ID>, null);
```

### 6.5. Cảnh báo

- **Không** chạy `UPDATE serial_imeis SET status='in_stock' WHERE status='dismantled' AND repair_status='ready'` — bỏ qua 5 guards, có thể phá data nếu có task mở.
- **Không** raw update `stock_quantity` — phải qua `recomputeFromSerials()`.
- **Không** chạy hàng loạt — duyệt từng serial, dry-run §6.1+§6.2 trước.

## 7. Test result (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2418ReassembledSerialRestoresStockTest` | ✅ **8 passed / 22 assertions**, 34.71s |
| `HOTFIX2416RepairSerialReadyStatusTest + Step238E + Step2411B` | ✅ **30 passed / 81 assertions**, 54.64s |
| `Task` | ✅ **88 passed / 254 assertions**, 28.06s |
| `Serial` | ✅ **122 passed, 2 skipped / 414 assertions**, 33.29s |
| `Stock` | ✅ **147 passed / 506 assertions**, 45.52s |
| `Repair + MovingAvgCosting` | ✅ **58 passed / 181 assertions**, 27.07s |
| `Purchase + Invoice + CashFlow` | ✅ **116 passed, 2 skipped / 420 assertions**, 53.43s |
| `npm run build` | ✅ **built in 9.20s** |

**8 TC trong HOTFIX2418ReassembledSerialRestoresStockTest:**

1. `test_reassembled_serial_restores_to_in_stock` — happy path, stock = 0 → 1, isSellable = true.
2. `test_dismantled_serial_with_active_import_parts_is_not_restored` — disassemblePart trên task chưa completed → block.
3. `test_sold_serial_is_never_restored_to_in_stock` — `invoice_id` set → block.
4. `test_returned_serial_is_never_restored_to_in_stock` — `purchase_return_id` set → block.
5. `test_mid_repair_serial_is_not_restored` — `repair_status=repairing` → block.
6. `test_serial_availability_blocks_dismantled_ready` — `dismantled/ready` vẫn KHÔNG sellable qua `SerialAvailabilityService`.
7. `test_api_endpoint_restores_serial` — POST → 200, serial.status = in_stock, product.stock_quantity = 1.
8. `test_api_endpoint_no_op_on_already_in_stock` — serial đã in_stock+ready → 200, `restored=false`, `reason=already_in_stock`.

## 8. Manual QA — pending tester

- [ ] Dry-run §6.1 trên prod → liệt kê serial kẹt (ưu tiên kiểm `MTXNBZJ1WK`).
- [ ] Với từng serial, chạy §6.2 → 0 row open task.
- [ ] Restore qua API §6.3 hoặc tinker §6.4.
- [ ] `/products` → mở product → tab Serial/IMEI: badge "✓ Sẵn bán" (xanh), trạng thái phải "Còn hàng/in_stock", product.stock_quantity tăng đúng.
- [ ] POS: serial restored chọn bán được, dismantled khác vẫn không.
- [ ] Serial vẫn còn task mở: API trả 422 với message tiếng Việt, không 500.
- [ ] Serial đã bán/trả NCC: API trả 422, không 500.
- [ ] Console không lỗi.
- [ ] `git log` trên prod thấy commit 24.18.

## 9. Rủi ro còn lại

- **Tồn kho:** ✅ KHÔNG ảnh hưởng — chỉ tăng `stock_quantity` qua `recomputeFromSerials()` cho product có serial vừa restore. Không động `inventory_total_cost`.
- **Giá vốn:** ✅ KHÔNG động — moving-avg `inventory_total_cost` đã hạch toán xong khi disassemblePart/rollback. 58 TC Repair+MovingAvg PASS.
- **POS / Invoice / Purchase / CashFlow:** ✅ KHÔNG động — 116 TC PASS, 88 Task TC PASS.
- **Serial nghiệp vụ:** ✅ 122 TC Serial PASS. `SerialAvailabilityService` vẫn block `dismantled` ở mọi nhánh.
- **HOTFIX 24.16:** ✅ Intact — `updateInternalRepairSerialStatus` không đụng. 6 TC 24.16 PASS lại.
- **Data integrity:** 5 guards + idempotent → không thể tự lừa restore sai. Hard refuse những case ranh giới (active task open).
- **Audit trail:** mỗi restore log vào `activity_logs` với `action='serial_imei.restore_reassembled'` + user_id → đối soát được sau.

## 10. Commit & deployment

- **Commit SHA:** `842954e` — `fix(repairs): restore reassembled serials to stock safely`.
- **Push status:** ✅ đã push, `origin/main` = `842954e353ca40f8491ce137ed15608009443244`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
# FE không đổi ở 24.18 — không cần rebuild. Build vẫn pass cho safety.
# Sau deploy, DBA chạy dry-run §6 và restore từng serial qua API/tinker.
```

## 11. Kết luận

- **Serial sau "bóc + lắp xong" hoàn nguyên được chưa?** ✅ Có — operator gọi `POST /api/tasks/serials/{id}/restore-reassembled`. TC-01 + TC-07 pin happy path.
- **Có cho dismantled bán bừa không?** ✅ KHÔNG — 5 guards + `SerialAvailabilityService` vẫn block.
- **Có update hàng loạt không?** ✅ KHÔNG — từng serial qua API/tinker, có dry-run guidance.
- **Có ảnh hưởng tồn kho / giá vốn / POS không?** ✅ KHÔNG — 88 Task + 122 Serial + 147 Stock + 58 Repair/MovingAvg + 116 Purchase/Invoice/CashFlow PASS. Chỉ tăng `stock_quantity` đúng count.
- **Có thể deploy không?** **Code đã sẵn sàng** — 8 TC mới + 30 TC adjacent regression + 531 TC nghiệp vụ PASS, build PASS. DBA cần chạy dry-run §6 trước khi restore production serials.
