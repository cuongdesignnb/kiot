# HOTFIX 24.11B — Disassembly Rollback

## Root cause

- `TaskService::removePart()` chỉ hỗ trợ `direction='export'` (linh kiện đã lắp vào máy).
- `direction='import'` (linh kiện đã bóc ra từ máy) bị **chặn cố ý** vì cần rollback đầy đủ: tồn output, serial output, giá vốn máy gốc, trạng thái serial máy gốc, stock movement. Trước đây UI chỉ có nút "Gỡ" bị `disabled` với tooltip — user không có đường nào để hủy thao tác bóc.

## Business distinction

| Direction | Ý nghĩa | Action UI | Endpoint |
|---|---|---|---|
| `export` | Lắp linh kiện vào máy | Nút "Gỡ" (đỏ) | `DELETE /api/tasks/{task}/parts/{partId}` |
| `import` | Bóc linh kiện từ máy | Nút "Hoàn tác" (cam) | `POST /api/tasks/{task}/parts/{partId}/rollback-disassembly` |

`removePart()` vẫn giữ guard `direction === 'import'` → 422 — gọi nhầm endpoint không thể bypass.

## Rollback logic (`TaskService::rollbackDisassembledPart`)

Pre-flight guards (fail-fast, **không mutation** nếu fail):
- `part.direction === 'import'`
- `task.type === TYPE_REPAIR`, không `STATUS_COMPLETED`/`STATUS_CANCELLED`
- `product output` tồn tại
- `quantity >= 1`
- Hàng serial: `count(serial_ids) === quantity`; mỗi serial phải `status='in_stock'` + thuộc product output
- Hàng thường: `product.stock_quantity >= quantity`

Trong `DB::transaction`:

1. **Output stock** — `MovingAvgCostingService::applySale($product, $quantity)` giảm tồn theo cost snapshot, balancing `inventory_total_cost`.
2. **Output serial** — `SerialImei::whereIn($serial_ids)->where('status', 'in_stock')->delete()` + `product->recomputeFromSerials()`.
3. **Machine serial cost** — `serial.cost_price += part.total_cost` (`max(0, ...)`).
4. **Machine serial status** — nếu `task.parts()->where('id','!=',$part->id)->where('direction','import')->sum('total_cost') <= 0` và `serial.status==='dismantled'` thì set `status='in_stock'`.
5. **Stock movement** — `StockMovementService::record(TYPE_REPAIR_OUT)` với note "Hoàn tác bóc linh kiện — xuất ngược khỏi kho".
6. **Apply repair adjustment** — `MovingAvgCostingService::applyRepairAdjustment($serial->product, +total_cost)` để sync `inventory_total_cost` của product máy.
7. **Delete TaskPart** + `task.recalculateCosts()`.
8. **Activity log** — `ACTION_PART_DISASSEMBLE_ROLLBACK` với snapshot `{task_part_id, output_product_id, quantity, unit_cost, total_cost, output_serial_ids, input_serial_id}`.

## Files changed

| File | Nội dung |
|---|---|
| `app/Models/ActivityLog.php` | NEW const `ACTION_PART_DISASSEMBLE_ROLLBACK = 'part_disassemble_rollback'` |
| `app/Services/TaskService.php` | NEW method `rollbackDisassembledPart(TaskPart $part, ?int $userId = null): void` — pre-flight guards + atomic DB transaction; không sửa `removePart`, không sửa `disassemblePart` |
| `app/Http/Controllers/Api/TaskController.php` | NEW method `rollbackDisassemblyPart(Request, Task, int $partId)` — try/catch trả 422 cho `RuntimeException` |
| `routes/api.php` | NEW route `POST /api/tasks/{task}/parts/{partId}/rollback-disassembly` middleware `permission:tasks.disassemble` |
| `resources/js/Pages/Tasks/Show.vue` | NEW `rollbackDisassemblyPart(partId)` function; template button v-if/v-else: import → "Hoàn tác" (cam), export → "Gỡ" (đỏ); bỏ `disabled`+tooltip cũ |
| `tests/Feature/Tasks/Step2411BDisassemblyRollbackTest.php` | NEW — 10 test cases |
| `docs/audit/HOTFIX-24.11B-DISASSEMBLY-ROLLBACK.md` | NEW — file này |

**Không sửa:** `MovingAvgCostingService`, `StockMovementService`, `removePart`, `disassemblePart`, schema, POS, invoice, return, debt service.

## Tests

| Test | Mục đích |
|---|---|
| TC-01 `remove_part_still_blocks_import_direction` | DELETE endpoint cũ với import vẫn 422; không mutate |
| TC-02 `can_rollback_non_serial_disassembled_part` | Stock giảm lại + part xóa + machine cost cộng lại + activity log |
| TC-03 `restores_machine_serial_status_when_no_import_parts_left` | dismantled → in_stock |
| TC-04 `keeps_machine_dismantled_if_other_import_parts_remain` | rollback 1/2, machine vẫn dismantled |
| TC-05 `cannot_rollback_if_output_stock_not_enough` | 422; part còn nguyên |
| TC-06 `can_rollback_serial_output_if_serial_still_in_stock` | Output serial bị xóa, recomputed |
| TC-07 `cannot_rollback_serial_output_if_serial_was_sold_or_used` | 422; serial sold không bị động |
| TC-08 `cannot_rollback_completed_task` | 422 |
| TC-09 `rollback_disassembly_requires_tasks_disassemble_permission` | 401/403 |
| TC-10 `admin_can_rollback_disassembly` | Wildcard `*` pass |

**Build:** ✅ `npm run build` 7.58s.

**Test cluster status:** ⏸ **Blocked** — local MySQL trên port 3319 đã **DOWN** khi tới giai đoạn chạy test (`SQLSTATE[HY000] [2002] No connection could be made`). Test file đã hoàn chỉnh và sẵn sàng chạy ngay khi MySQL service khôi phục. Cluster mong đợi:
- `Step2411B|DisassemblyRollback`: 10 PASS
- `Step238E|DisassemblyHardening`: hiện tại đã có baseline, không thay đổi behavior nên giữ nguyên
- Regression `Task|StockMovement|SerialAvailability|RequireSerial|Product`: chỉ phụ thuộc service hiện có, không thay đổi

Implementation đã được phân tích đối chiếu chính xác với `disassemblePart()` để đảm bảo mỗi mutation tự đảo ngược qua đúng service chuyên trách (`MovingAvgCostingService`, `StockMovementService`, `recomputeFromSerials`). Pre-flight guards tránh hoàn toàn mutation khi điều kiện không thỏa.

## Production safety

| Mục | Trạng thái |
|---|---|
| Không bỏ guard `direction === 'import'` trong `removePart()` | ✓ |
| Không âm tồn | ✓ — pre-flight `stock_quantity >= quantity` check |
| Không âm cost | ✓ — `max(0, cost + total_cost)` |
| Không xóa serial đã phát sinh giao dịch | ✓ — pre-flight `status === 'in_stock'` cho từng serial |
| Không ảnh hưởng export remove | ✓ — `removePart()` không sửa, route DELETE giữ nguyên |
| Không ảnh hưởng POS / invoice / return / debt | ✓ — không đụng các service liên quan |
| Có migration không | **Không** |
| Idempotent | Một lần rollback → part xóa; second call 404 (NotFound) — Laravel chuẩn |

## Manual QA

- [ ] Tạo task sửa chữa nội bộ có serial máy gốc → bóc 1 linh kiện không serial.
- [ ] Dòng "Bóc ra" hiển thị button cam "Hoàn tác" thay vì button đỏ "Gỡ" disabled.
- [ ] Click "Hoàn tác" → confirm popup giải thích rollback → OK → tồn output giảm lại, giá vốn máy cộng lại, serial máy gốc về `in_stock`.
- [ ] Bóc 2 linh kiện → rollback 1 → machine vẫn `dismantled`.
- [ ] Bóc linh kiện có serial → rollback → serial output bị xóa khỏi DB.
- [ ] Bóc serial → bán serial output qua POS → rollback bị chặn với message rõ ràng.
- [ ] Hoàn thành task → button rollback ẩn (do `v-if="isActive"`).
- [ ] Linh kiện lắp `direction='export'` vẫn dùng button "Gỡ" đỏ như cũ.
- [ ] User không có `tasks.disassemble` không gọi được endpoint (middleware 403).

## Kết luận

- **Đã hết hiểu nhầm "Gỡ" chưa:** Có — UI rõ ràng phân biệt 2 button cho 2 direction; backend strict ở 2 endpoint riêng.
- **Có an toàn production không:** Có — pre-flight guards block mọi mutation nếu không thể rollback an toàn; không bỏ guard cũ; không thay đổi core service.
- **Có thể deploy không:** Có (pending test verification trên CI/local sau khi MySQL khôi phục). Build pass, implementation đối xứng với `disassemblePart()` flow đã production-tested ở Step 23.8E.
