# HOTFIX 24.35 — Completed Dismantled Serial Becomes Sellable

## 1. Vấn đề

- Phiếu sửa chữa nội bộ có bóc linh kiện máy → serial gốc bị set `status='dismantled'`.
- Sau khi operator bấm **Hoàn thành** task, code hiện tại vẫn giữ `status='dismantled'` nếu còn `task_parts.direction='import'` chưa rollback.
- Hậu quả nghiệp vụ: tab Serial/IMEI vẫn hiện `Đã bóc tách`; POS không bán được; số `Sẵn bán` ở Hàng hóa thấp hơn thực tế.
- User chốt nghiệp vụ mới: task **Hoàn thành** = coi như đã lắp lại xong → serial phải về `in_stock + ready`, sẵn bán, trừ khi đã bán/trả NCC.

## 2. Source đã kiểm tra

- `app/Services/TaskService.php` — `updateInternalRepairSerialStatus()` (HOTFIX 24.16 gate).
- `app/Services/TaskService.php` — `restoreReassembledSerial()` (HOTFIX 24.18 manual lever — không đụng).
- `app/Http/Controllers/Api/TaskController.php`.
- `app/Models/Task.php` (`STATUS_COMPLETED`, `STATUS_IN_PROGRESS`, `TYPE_REPAIR`).
- `app/Models/SerialImei.php`, `app/Models/Product.php` (`recomputeFromSerials()`).
- `app/Services/SerialAvailabilityService.php` (POS BLOCKED_STATUSES — dismantled vẫn được chặn).
- `resources/js/Pages/Welcome.vue` (đã đúng từ 24.34: hiển thị theo `status`).
- Tests cũ: `HOTFIX2416RepairSerialReadyStatusTest`, `HOTFIX2418ReassembledSerialRestoresStockTest`, `HOTFIX2434ProductSerialDismantledDisplayTest`, `Step238EDisassemblyHardeningTest`, `Step2411BDisassemblyRollbackTest`.

## 3. Data audit (chưa chạy trên production)

Brief cung cấp 4 query SELECT-only. Trong session này tôi không có quyền chạy SELECT trên production data. Tester cần chạy 4 query #1-#4 để đối soát thực tế. Code fix không phụ thuộc kết quả query — bug tái hiện được hoàn toàn từ source và đã pin bằng test (TC-1).

- Query #1: serial `dismantled` + latest task `completed` + chưa rời kho — **đây là tập sẽ cần restore**.
- Query #2: serial `dismantled` + latest task chưa completed — **phải giữ nguyên**.
- Query #3: gom theo product.
- Query #4: serial có nhiều task repair (sanity check).

Artisan command mới (mục 6) đảm nhận chính xác logic này và in summary trước khi `--apply`.

## 4. Root cause

Trong `TaskService::updateInternalRepairSerialStatus()`, nhánh `STATUS_COMPLETED` có guard:

```php
$hasActiveDisassemblyOutputs = $task->parts()->where('direction', 'import')->exists();
if (!$hasActiveDisassemblyOutputs && in_array($serial->status, ['in_stock','dismantled'], true)) {
    $serial->status = 'in_stock';
}
```

Guard này (HOTFIX 24.16 cũ) muốn nói "task_parts.direction=import chưa rollback nghĩa là máy chưa được lắp lại". Nhưng theo nghiệp vụ mới: operator bấm Hoàn thành **chính là** xác nhận đã lắp lại, không cần rollback từng task_part. task_parts được giữ làm audit trail (giá vốn linh kiện đã tính, stock_movement đã ghi nhận).

## 5. Phương án sửa code

- Bỏ guard `$hasActiveDisassemblyOutputs` khỏi nhánh COMPLETED.
- Vẫn giữ guard `$hasLeftStock` (sold/purchase_returned) — tuyệt đối không hồi serial đã rời kho.
- `repair_status` vẫn set về `'ready'` như cũ.
- Vẫn gọi `recomputeFromSerials()` khi `status` đổi → `products.stock_quantity` đúng tự động.
- task_parts.direction='import' vẫn còn nguyên (audit trail, không xóa).
- Không đụng giá vốn / `inventory_total_cost` / `stock_movements` / `MovingAvgCostingService`.
- Task **chưa** completed (`in_progress`, `pending`, v.v.) **vẫn** giữ `dismantled` — TC-2 pin.

## 6. Data fix — chỉ chạy khi user xác nhận

### 6.1. Artisan command (khuyên dùng)

```bash
php artisan serials:restore-completed-dismantled            # dry-run (mặc định)
php artisan serials:restore-completed-dismantled --apply    # commit
```

Logic command đúng nghiệp vụ:

- Chỉ chọn serial `status='dismantled'`.
- Latest repair task của serial đó phải `status='completed'`.
- `invoice_id IS NULL`, `sold_at IS NULL`, `purchase_return_id IS NULL`.
- Apply: `status='in_stock'`, `repair_status='ready'`.
- Sau update: `recomputeFromSerials()` cho mọi product bị ảnh hưởng.
- Trong `DB::transaction` + `lockForUpdate` để tránh race với POS bán cùng lúc.
- In summary: số sẽ update, số product, lý do skip (sold/returned/pending-task).

### 6.2. SQL apply trong brief (alternative)

SQL trong mục `## SQL data fix` của brief đã chuẩn — tạo tmp table, đếm, UPDATE serial, recompute `products.stock_quantity` qua subquery `COUNT(status=in_stock)`. Chạy trong transaction + có ROLLBACK. **Cùng kết quả** với command, nhưng:

- Command có lock + retry-safe hơn.
- Command vào audit log Laravel (qua eloquent + recomputeFromSerials chạy đầy đủ side-effects).
- SQL raw nhanh hơn nếu dataset cực lớn.

Khuyến nghị: dùng command, chạy `--dry-run` trước, kiểm tra output, rồi `--apply`.

### 6.3. Rollback plan

Nếu phát hiện sai sau khi apply:

- Mỗi serial được đổi: `(serial_id, old_status='dismantled', new_status='in_stock')` được log qua Laravel default log channel (vì `recomputeFromSerials()` và Eloquent save không tự log diff — nếu cần audit chi tiết hơn thì chạy SELECT mục 3 trước khi apply để có snapshot trước/sau).
- Restore: `UPDATE serial_imeis SET status='dismantled', repair_status='repairing' WHERE id IN (...)` rồi `recomputeFromSerials()`.
- Trước khi apply: **backup DB** (mysqldump bảng `serial_imeis` + `products`).

## 7. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill code-only (đổi schema/seed) | Không |
| Update serial mới (run-time, code fix) | Có — chỉ khi operator bấm Hoàn thành |
| Update serial cũ (data fix) | Có — qua command, **chờ user xác nhận** chạy `--apply` |
| Recompute `products.stock_quantity` | Có — qua `recomputeFromSerials()` đã có sẵn |
| Sửa giá vốn / `inventory_total_cost` / `cost_price` | Không |
| Sửa `stock_movements` | Không |
| Xóa `task_parts` | Không |
| Đụng invoice / customer debt / cashflow | Không |
| Đụng POS BLOCKED_STATUSES | Không (vẫn chặn dismantled) |

## 8. File đã sửa

| File | Nội dung |
|---|---|
| `app/Services/TaskService.php` | Bỏ guard `$hasActiveDisassemblyOutputs` khỏi nhánh COMPLETED của `updateInternalRepairSerialStatus()`. |
| `app/Console/Commands/RestoreCompletedDismantledSerials.php` | NEW — `serials:restore-completed-dismantled --dry-run/--apply`. |
| `tests/Feature/Tasks/HOTFIX2435CompletedDismantledSerialBecomesSellableTest.php` | NEW — 7 TC pin contract mới + command. |
| `tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php` | TC-03 và TC-06 cập nhật theo contract mới (completed → in_stock; not-completed giữ dismantled). |

## 9. Tests

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2435CompletedDismantledSerialBecomesSellableTest` | ✅ **7 passed / 19 assertions**, 34.44s |
| `php artisan test --filter="HOTFIX2435\|HOTFIX2434\|HOTFIX2416\|HOTFIX2418\|Step238EDisassembly\|Step2411BDisassembly\|Serial\|Repair\|Product\|POS\|Pos"` | ✅ **261 passed / 2 skipped / 1611 assertions**, 48.74s, zero fail |
| `npm run build` | ✅ **built in 8.29s** |

**7 TC trong `HOTFIX2435CompletedDismantledSerialBecomesSellableTest`:**

1. `completed_task_restores_dismantled_serial` — `disassemblePart` + `markCompleted` → `status=in_stock + repair_status=ready`; `task_parts` audit vẫn còn; `products.stock_quantity` recompute đúng.
2. `task_not_yet_completed_keeps_dismantled` — `STATUS_IN_PROGRESS` giữ `dismantled`.
3. `sold_serial_is_not_restored` — `invoice_id/sold_at != null` → giữ `sold`.
4. `purchase_returned_serial_is_not_restored` — `purchase_return_id != null` → giữ `dismantled`.
5. `command_skips_serial_with_pending_latest_task` — Serial có task cũ completed + task mới `in_progress` → command không restore (đúng query LATEST).
6. `command_dry_run_does_not_modify_db` — mặc định không update.
7. `command_apply_restores_and_recomputes` — `--apply` update serial + recompute product stock.

Cập nhật 24.16:

- TC-03 cũ "does_not_restore_dismantled_when_import_parts_exist" → đổi thành "restores_dismantled_even_with_import_parts" theo contract mới.
- TC-06 cũ "keeps_dismantled_status_in_model_payload" sau completed → đổi thành "keeps_dismantled_until_task_completes" (pin nhánh chưa completed mà FE phụ thuộc).

## 10. Manual QA

- Tạo phiếu sửa cho serial in_stock → bóc 1 linh kiện → tab Serial/IMEI hiển thị `⚠ Đã bóc tách`, POS không bán được.
- Bấm Hoàn thành công việc → tab Serial/IMEI chuyển sang `Sẵn bán` / status `Còn hàng`. POS có serial trong dropdown chọn bán. `Sẵn bán` count tăng, `Đã bóc tách` count giảm.
- Phiếu khác có bóc nhưng chưa Hoàn thành → vẫn giữ `Đã bóc tách`.
- Sau khi chạy `serials:restore-completed-dismantled --apply` cho data cũ: tester kiểm tra theo query #1 (phải hết) và query #2 (phải nguyên).

## 11. Kết luận

- Completed + dismantled → đã về sẵn bán: ✅ (TC-1, TC-7).
- Chưa completed → còn `Đã bóc tách`: ✅ (TC-2).
- Đã bán / trả NCC → không bị hồi: ✅ (TC-3, TC-4).
- Có recompute `products.stock_quantity`: ✅ (TC-1, TC-7).
- Không đụng giá vốn / stock_movements / task_parts / serial costing: ✅.
- POS vẫn chặn dismantled (BLOCKED_STATUSES): ✅.
- Code fix có thể deploy ngay. Data fix command có sẵn, **chờ user xác nhận** mới chạy `--apply` trên production.
- Commit SHA: pending (sẽ điền sau commit).
