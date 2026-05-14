# HOTFIX 24.16 — Repair Serial dismantled/ready mismatch

## 1. Vấn đề

- Serial **CD0TVG3** (id=371, product_id=321):
  ```
  status              = dismantled
  repair_status       = ready
  invoice_id          = NULL
  sold_at             = NULL
  purchase_return_id  = NULL
  cost_price          = 7,216,063
  ```
- UI danh sách Serial/IMEI hiển thị mâu thuẫn:
  - Badge bên trái: **"✓ Sẵn bán"** (xanh) — tính dựa trên `repair_status='ready'`.
  - Cột status bên phải: **"dismantled"** (đỏ) — tính dựa trên `status`.
- Serial chưa bán (no invoice/sold_at), chưa trả NCC (no purchase_return_id) → đáng ra phải sellable hoặc rõ ràng là bóc tách, không được vừa-vừa.

## 2. Root cause

**Backend** — [`TaskService::changeStatus`](app/Services/TaskService.php#L283) (trước fix, lines 283-290) khi internal repair `STATUS_COMPLETED` chỉ chạy:
```php
$task->serialImei?->update(['repair_status' => 'ready']);
```
Không bao giờ đụng đến `status`. Nếu trước đó có path nào set `status='dismantled'` (ví dụ `disassemblePart()` đã chạy nhưng task_part `direction='import'` đã bị xoá / rollback dở dang), serial bị kẹt mãi ở `dismantled` + `ready`.

**Frontend** — [`Welcome.vue:1417`](resources/js/Pages/Welcome.vue#L1417) + [`:1524`](resources/js/Pages/Welcome.vue#L1524) quyết định badge "✓ Sẵn bán" CHỈ dựa trên `repair_status === 'ready'`:
```vue
<span v-else-if="s.repair_status === 'ready'" class="...">✓ Sẵn bán</span>
```
Không check `status` vật lý — nên `dismantled/ready` vẫn hiện xanh.

## 3. File đã sửa

| File | Nội dung |
|---|---|
| [`app/Services/TaskService.php`](app/Services/TaskService.php) | Thay block update inline trong `changeStatus` bằng helper `updateInternalRepairSerialStatus($task, $newStatus)` (lines 282-287 + helper mới 306-374). Helper lock-for-update serial, set `repair_status` đúng, và CHỈ restore `status='in_stock'` khi: chưa rời kho (no invoice/sold_at/purchase_return_id) **AND** không có task_part `direction='import'` còn hiệu lực. Nếu status đổi → `product->recomputeFromSerials()`. |
| [`resources/js/Pages/Welcome.vue`](resources/js/Pages/Welcome.vue) | 2 badge "Sẵn bán" (line ~1417 và compact ~1524) thêm guard `s.repair_status === 'ready' && s.status === 'dismantled'` → hiện "⚠ Đã bóc tách" (đỏ) thay vì "✓ Sẵn bán". |
| [`tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php`](tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php) | NEW — 6 TC pin contract. |
| [`docs/audit/HOTFIX-24.16-REPAIR-SERIAL-DISMANTLED-READY.md`](docs/audit/HOTFIX-24.16-REPAIR-SERIAL-DISMANTLED-READY.md) | NEW — báo cáo này. |

**Không sửa:** `SerialAvailabilityService` (đã đúng — `dismantled` vẫn trong `BLOCKED_STATUSES`, `dismantled/ready` được TC-05 verify là NOT sellable), `disassemblePart`, `rollbackDisassembledPart`, Purchase/PurchaseReturn/Invoice/POS, `recordPayment`/`adjustDebt`/`debtOffset`.

## 4. Cách sửa backend

**Helper `updateInternalRepairSerialStatus($task, $newStatus)`** trong `TaskService`:

| Tình huống | Hành động |
|---|---|
| `newStatus = in_progress`, `serial.status = in_stock` | Chỉ set `repair_status = 'repairing'`. Giữ nguyên `status`. |
| `newStatus = in_progress`, `serial.status != in_stock` | No-op (đã có lệch trạng thái nào đó, không tự sửa). |
| `newStatus = completed`, serial đã rời kho (invoice_id/sold_at/purchase_return_id) | Chỉ set `repair_status = 'ready'`. **KHÔNG** đụng `status`. |
| `newStatus = completed`, task có task_part `direction='import'` | Chỉ set `repair_status = 'ready'`. Giữ `status = 'dismantled'` (máy thật sự đã bóc). |
| `newStatus = completed`, serial chưa rời kho, không có import parts, `status ∈ {in_stock, dismantled}` | Set `repair_status = 'ready'` **VÀ** `status = 'in_stock'` → recompute product. |
| `newStatus` khác (paused, cancelled, ...) | No-op trên serial. |

`SerialImei::lockForUpdate()` đảm bảo race-safe khi nhiều phiếu repair đồng thời.

## 5. Cách sửa UI

`Welcome.vue` — badge ưu tiên check status vật lý trước:

```vue
<span v-else-if="s.repair_status === 'ready' && s.status === 'dismantled'"
      class="bg-red-100 text-red-700">⚠ Đã bóc tách</span>
<span v-else-if="s.repair_status === 'ready'"
      class="bg-green-100 text-green-600">✓ Sẵn bán</span>
```

Trong UI compact (icon-only), `⚠` thay cho `✓` khi `status=dismantled`. Cùng pattern, không ẩn thông tin — vẫn cho nhân viên thấy serial không bán được.

## 6. Data repair cho CD0TVG3 + dòng tương tự

**Tôi KHÔNG chạy update production.** Cung cấp query dry-run + UPDATE để team DBA chạy thủ công sau khi kiểm tra:

### Dry-run (xem những dòng kẹt không có import parts còn hiệu lực)

```sql
SELECT si.id, si.serial_number, si.product_id, si.status, si.repair_status,
       si.invoice_id, si.sold_at, si.purchase_return_id,
       t.id AS task_id, t.code AS task_code, t.status AS task_status,
       COUNT(tp.id) AS import_parts_count
FROM serial_imeis si
LEFT JOIN tasks t ON t.serial_imei_id = si.id
LEFT JOIN task_parts tp ON tp.task_id = t.id AND tp.direction = 'import'
WHERE si.status = 'dismantled'
  AND si.repair_status = 'ready'
  AND si.invoice_id IS NULL
  AND si.sold_at IS NULL
  AND si.purchase_return_id IS NULL
GROUP BY si.id, t.id
HAVING import_parts_count = 0;
```

### UPDATE chỉ những dòng đã verify

```sql
UPDATE serial_imeis si
LEFT JOIN tasks t ON t.serial_imei_id = si.id
LEFT JOIN task_parts tp ON tp.task_id = t.id AND tp.direction = 'import'
SET si.status = 'in_stock',
    si.repair_status = 'ready',
    si.updated_at = NOW()
WHERE si.status = 'dismantled'
  AND si.repair_status = 'ready'
  AND si.invoice_id IS NULL
  AND si.sold_at IS NULL
  AND si.purchase_return_id IS NULL
  AND tp.id IS NULL;
```

### Riêng CD0TVG3

```sql
-- Nếu dry-run trên cho thấy CD0TVG3 không có import parts còn hiệu lực:
UPDATE serial_imeis
SET status = 'in_stock',
    repair_status = 'ready',
    updated_at = NOW()
WHERE serial_number = 'CD0TVG3'
  AND status = 'dismantled'
  AND repair_status = 'ready'
  AND invoice_id IS NULL
  AND sold_at IS NULL
  AND purchase_return_id IS NULL;
```

### Recompute product 321 sau update

```bash
php artisan tinker
> \App\Models\Product::find(321)?->recomputeFromSerials();
```

Không dùng raw increment/decrement `stock_quantity`.

## 7. Test đã chạy (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `--filter=HOTFIX2416RepairSerialReadyStatusTest` | ✅ **6 PASS / 16 assertions** |
| `--filter=Step238EDisassemblyHardeningTest\|Step2411BDisassemblyRollbackTest` | ✅ **24 PASS / 65 assertions** |
| `--filter=Task` | ✅ **80 PASS / 232 assertions** |
| `--filter=Serial` | ✅ **114 PASS, 2 skipped / 392 assertions** |
| `--filter=Stock` | ✅ **139 PASS / 484 assertions** |
| `--filter="Repair\|MovingAvgCosting"` | ✅ **57 PASS / 179 assertions** |
| `--filter="Purchase\|Invoice\|CashFlow"` | ✅ **107 PASS, 2 skipped / 384 assertions** |
| `npm run build` | ✅ 6.75s pass |

**6 TC trong HOTFIX2416RepairSerialReadyStatusTest:**

1. `test_internal_repair_completion_restores_in_stock_when_no_disassembly_outputs` — happy path: in_stock→repairing→completed → status=in_stock + repair_status=ready, isSellable=true.
2. `test_internal_repair_completion_restores_dismantled_to_in_stock_when_no_active_import_parts` — CD0TVG3 scenario (kẹt dismantled không import parts) → repair complete → about-face về in_stock.
3. `test_internal_repair_completion_does_not_restore_dismantled_when_import_parts_exist` — `disassemblePart` thật → status=dismantled + import task_part → complete → giữ dismantled, isSellable=false.
4. `test_completed_repair_does_not_restore_sold_serial_to_in_stock` — serial.status=sold + invoice_id → giữ sold dù complete repair.
5. `test_serial_availability_blocks_dismantled_ready` — `isSellable(dismantled+ready)` = false.
6. `test_disassembled_serial_keeps_dismantled_status_in_model_payload` — sau disassembly + complete, model row giữ dismantled, `querySellableForProduct()` không trả về id này.

## 8. Manual QA

### Automated verification (đã chạy thật, ✅)

| Check | Phương thức | Kết quả |
|---|---|---|
| Repair complete restore in_stock khi không có import parts | TC-01, TC-02 | ✅ |
| Repair complete KHÔNG restore khi có import parts (disassembly thật) | TC-03 | ✅ |
| Repair complete KHÔNG restore sold serial | TC-04 | ✅ |
| `dismantled/ready` không sellable | TC-05 | ✅ |
| Sellable scope không leak dismantled/ready | TC-06 | ✅ |
| Disassembly/rollback regression (Step238E + 2411B) | 24 PASS | ✅ |
| Task / Serial / Stock / Repair / MovingAvg regression | 390 PASS | ✅ |
| Purchase / Invoice / CashFlow regression | 107 PASS | ✅ |
| `npm run build` pass | ✅ | ✅ |

### Browser QA — pending user verify

Tôi không có browser, các mục dưới chờ tester:

- [ ] Tạo serial in_stock + repair_status=repairing → hoàn thành phiếu sửa → list serial của product hiện "✓ Sẵn bán" (xanh), status cột phải là "in_stock".
- [ ] Với serial `status=dismantled, repair_status=ready` (mô phỏng disassembly thật, có task_part import) → list hiện "⚠ Đã bóc tách" (đỏ), KHÔNG còn "✓ Sẵn bán".
- [ ] POS / chọn sản phẩm: serial sellable chọn được, serial dismantled/ready KHÔNG chọn được.
- [ ] Rollback bóc tách: chạy rollback xong, hoàn thành lại task → serial trở lại in_stock đúng.
- [ ] Sau khi DBA chạy data repair query, riêng CD0TVG3:
  - `status` = `in_stock`
  - `repair_status` = `ready`
  - badge UI = "✓ Sẵn bán"
  - product 321 `stock_quantity` khớp count sellable serials sau khi `recomputeFromSerials()`.
- [ ] Bán hàng/Tasks/Repair/Warranty/Purchase/Invoice/CashFlow regression — không có thay đổi hành vi.

## 9. Rủi ro còn lại

- **Máy đã bóc tách thật:** TC-03 + TC-06 đảm bảo helper không tự đưa về in_stock nếu còn import task_part. SerialAvailabilityService vẫn block `dismantled`.
- **Phân biệt repair vs disassembly:** Repair có thể chứa cả disassembly part — helper check riêng từng tình huống dựa trên `task_parts.direction='import'`, không nhầm.
- **Data repair:** chỉ chạy với điều kiện `import_parts_count = 0` — không update bừa toàn bộ `dismantled`.
- **Recompute:** mỗi lần helper đổi `status`, gọi `product->recomputeFromSerials()` để `stock_quantity` khớp count sellable. Không touch `inventory_total_cost` (giữ logic moving-avg cũ).

## 10. Commit & deployment

- **Commit SHA:** `dea98e1e6aae162e7e4873e8f8d7921b0cd7d4c5` — `fix(tasks): sync serial status + repair_status on internal repair complete`
- **Push status:** ✅ trên `origin/main` (verified: `git ls-remote origin refs/heads/main` = `dea98e1...`).
- **Recent main:**
  ```
  dea98e1 fix(tasks): sync serial status + repair_status on internal repair complete
  1987961 docs(audit): record HOTFIX 24.15 commit SHA and deploy step
  57ec2aa fix(suppliers): show newest entries first in expanded tabs
  d6770b0 docs(audit): record HOTFIX 24.14 commit SHA and split QA section
  a5dff11 fix(suppliers): export tab buttons no longer throw on .open
  ```
- **Production deploy step:**
  ```bash
  cd /www/wwwroot/kiot.cuongdesign.net
  git pull origin main          # phải thấy dea98e1
  rm -rf public/build
  npm run build
  php artisan optimize:clear
  # DBA: chạy dry-run query ở §6, verify từng dòng, sau đó UPDATE
  # php artisan tinker > \App\Models\Product::find(321)?->recomputeFromSerials();
  # Hard reload trình duyệt (Ctrl+Shift+R)
  ```

## 11. Kết luận

- **CD0TVG3 đã hết mâu thuẫn chưa?** Code-level: có — đường code mới đảm bảo `completed` repair sẽ set status đúng. **Dữ liệu cũ (row hiện đang dismantled/ready trên prod) cần DBA chạy data repair SQL ở §6.**
- **Serial sửa xong còn tồn bán được chưa?** Có — TC-01, TC-02 PASS, helper restore status='in_stock'.
- **Serial bóc tách thật có bị bán nhầm không?** Không — TC-03, TC-05, TC-06 pin: dismantled + active import parts vẫn không sellable; SerialAvailabilityService chặn ở mọi nhánh.
- **Có thể deploy không?** **Code đã sẵn sàng** (test xanh trên DB thật, build pass, regression không hỏng). **Browser QA + DBA data repair** vẫn cần xác nhận trước production deploy.

---

## 12. ADDENDUM 24.16C — Mở rộng badge guard sang module Sửa chữa / Tasks

### 12.1. Bối cảnh

HOTFIX 24.16 mới chỉ guard badge "Sẵn bán" trong [`Welcome.vue`](resources/js/Pages/Welcome.vue) (danh sách Serial/IMEI ở trang chủ). Các trang khác vẫn render `repair_status` mà không check `serial.status` vật lý:

- [`/repairs`](resources/js/Pages/Repairs/Index.vue) — cột "Serial ST".
- [`/repairs/{id}`](resources/js/Pages/Repairs/Show.vue) — block "Trạng thái serial".
- [`/my-tasks`](resources/js/Pages/Tasks/MyTasks.vue) — chip device info trong card "Việc của tôi".

Thêm nữa, 2 API list endpoint (`/api/tasks`, `/api/device-repairs`) chỉ select `serialImei:id,serial_number,repair_status,...` — **không include `status`** → FE không thể tự guard kể cả có sửa template.

### 12.2. File đã sửa (24.16C)

24.16C gồm 2 commit nối tiếp trên `main`:

| Commit | SHA | Nội dung |
|---|---|---|
| **24.16C-1** | `dc8e07d` | FE badge guard + thêm `status` vào API selects |
| **24.16C-2** | `d18a0e2` | Mở rộng API payload (`invoice_id`, `sold_at`, `purchase_return_id`, `cost_price`, `product_id`) cho cả `/api/tasks` và `/api/device-repairs` để FE có đủ trường suy diễn "đã rời kho" |

| File | Commit | Nội dung |
|---|---|---|
| [`app/Http/Controllers/Api/TaskController.php`](app/Http/Controllers/Api/TaskController.php#L32) | `dc8e07d` + `d18a0e2` | `index()` (line 32) và `show()` (line 95) → cuối cùng select: `serialImei:id,serial_number,status,repair_status,cost_price,product_id,invoice_id,sold_at,purchase_return_id`. |
| [`app/Http/Controllers/Api/DeviceRepairController.php`](app/Http/Controllers/Api/DeviceRepairController.php#L28) | `dc8e07d` + `d18a0e2` | `index()` (line 28) và `show()` (line 70) → cùng tập field như TaskController. |
| [`resources/js/Pages/Repairs/Show.vue`](resources/js/Pages/Repairs/Show.vue#L48) | `dc8e07d` | `repairStatusBadge` chuyển sang nhận serial object (vẫn nhận string cho BC); khi `repair_status='ready' && status='dismantled'` → trả về `{ label: '⚠ Đã bóc tách', cls: 'bg-red-100 text-red-700' }`. Call site truyền `repair.serial_imei`. |
| [`resources/js/Pages/Repairs/Index.vue`](resources/js/Pages/Repairs/Index.vue#L129) | `dc8e07d` | Cùng pattern như Show: helper nhận serial object, call site truyền `r.serial_imei`. |
| [`resources/js/Pages/Tasks/MyTasks.vue`](resources/js/Pages/Tasks/MyTasks.vue#L122) | `dc8e07d` | `getRepairStatusLabel(t)` và `getRepairStatusCls(t)` ưu tiên check `serial_imei.status === 'dismantled'` trước khi map `repair_status`. |

**Không sửa:** `SerialAvailabilityService`, `TaskService::updateInternalRepairSerialStatus` (helper backend 24.16 không cần đụng tiếp), Welcome.vue (đã guard rồi), Tasks/Index.vue + Tasks/Show.vue (không render `repair_status` của serial), POS/Purchase/Invoice/CashFlow.

### 12.2.1. Payload mới của serialImei

`/api/tasks` (index + show) và `/api/device-repairs` (index + show) đều trả:

```
serial_imei: {
  id, serial_number,
  status,               // in_stock | dismantled | sold | ...
  repair_status,        // not_started | repairing | ready
  cost_price,
  product_id,
  invoice_id,           // != null → đã bán
  sold_at,              // != null → đã bán
  purchase_return_id    // != null → đã trả NCC
}
```

3 trường mới (`invoice_id`, `sold_at`, `purchase_return_id`) đều có sẵn trên `serial_imeis` (migrations `2026_03_23_100000_add_sold_fields_to_serial_imeis_table` + `2026_04_05_003313_add_purchase_return_id_to_serial_imeis_table`). Không cần schema change. FE chưa render trường mới ở turn này — chuẩn bị cho guard tiếp theo nếu cần phân biệt "đã bán" vs "còn tồn".

### 12.3. Hành vi mới

| Tình huống | Badge cũ | Badge mới |
|---|---|---|
| `status=in_stock, repair_status=ready` | Sẵn bán (xanh) | Sẵn bán (xanh) — **không đổi** |
| `status=in_stock, repair_status=repairing` | Đang xử lý (vàng) | Đang xử lý (vàng) — **không đổi** |
| `status=dismantled, repair_status=ready` | **Sẵn bán (xanh)** ❌ | **⚠ Đã bóc tách (đỏ)** ✅ |
| `status=sold, repair_status=ready` | Sẵn bán (xanh) — không xảy ra ở /repairs vì serial đã rời kho | Sẵn bán (xanh) — bug data, không trong scope guard |

`SerialAvailabilityService` vẫn block mọi case `dismantled` — không ai bán nhầm được kể cả badge sai. 24.16C chỉ sửa hiển thị.

### 12.4. Test đã chạy thật sau commit `d18a0e2` (MySQL:3319)

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2416RepairSerialReadyStatusTest` | ✅ **6 passed / 16 assertions**, 32.97s |
| `php artisan test --filter=Task` | ✅ **80 passed / 232 assertions**, 44.09s |
| `php artisan test --filter=Serial` | ✅ **114 passed, 2 skipped / 392 assertions**, 34.00s |
| `npm run build` | ✅ **built in 7.23s** |

**Ghi chú lệnh:** không dùng `--env=testing` vì repo này dùng `phpunit.xml` thiết lập sẵn test connection (MySQL:3319) ở env mặc định — truyền `--env=testing` sẽ override sang một connection không tồn tại và báo `2002 connection refused` ở tất cả TC.

Không thêm TC backend mới — 24.16C là pure FE rendering + mở rộng field API, không đổi nghiệp vụ. TC-01 → TC-06 trong [`HOTFIX2416RepairSerialReadyStatusTest`](tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php) đã pin đủ contract backend.

### 12.5. Manual QA (24.16C) — pending tester

- [ ] `/repairs` → cột "Serial ST" của phiếu mà serial có `status=dismantled, repair_status=ready` → hiện "⚠ Đã bóc tách" (đỏ).
- [ ] `/repairs/{id}` → block "Trạng thái serial" cùng pattern.
- [ ] `/my-tasks` → card có serial dismantled/ready → chip "Đã bóc tách" đỏ.
- [ ] Serial đã hoàn thành sửa thật sự (in_stock + ready) → vẫn hiện "Sẵn bán" xanh.
- [ ] Serial đang sửa → vẫn hiện "Đang xử lý" vàng.
- [ ] DevTools → response của `/api/tasks?type=repair` chứa `serial_imei.status`, `serial_imei.invoice_id`, `serial_imei.sold_at`, `serial_imei.purchase_return_id`.
- [ ] DevTools → response của `/api/device-repairs` (index + show) cùng tập field như trên.

### 12.6. Rủi ro 24.16C

- **API contract broaden, không break:** chỉ thêm field vào select — client cũ không sử dụng vẫn hoạt động (Vue templates không bind trường nào sẽ bỏ qua).
- **Helper accept cả string lẫn object:** giữ backward compat nếu có call site khác trong code không quét được — fallback nhánh string.
- **Không đụng `SerialAvailabilityService` / disassemble / rollback:** không có khả năng `dismantled` bị "sellable hoá".

### 12.7. Commit & deployment 24.16C

- **Commit SHA — 24.16C-1:** `dc8e07d` — `fix(repairs): badge dismantled serials in Repair/Tasks UI (HOTFIX 24.16C)`
- **Commit SHA — 24.16C-2:** `d18a0e2` — `fix(repairs): expand serialImei API payload for stock-state guards (HOTFIX 24.16C)`
- **Commit SHA — 24.16C-doc:** `df79d23` — `docs(audit): record HOTFIX 24.16C commit SHAs and deploy step`
- **Push status:** ✅ **đã push lên `origin/main`** — verified `git ls-remote origin refs/heads/main` = `df79d232295f01c22d2399cdc27e89eeb1c5d9d4`.
- **`git log --oneline -5` (remote = local):**
  ```
  df79d23 docs(audit): record HOTFIX 24.16C commit SHAs and deploy step
  d18a0e2 fix(repairs): expand serialImei API payload for stock-state guards (HOTFIX 24.16C)
  dc8e07d fix(repairs): badge dismantled serials in Repair/Tasks UI (HOTFIX 24.16C)
  9fbe39f docs(audit): record HOTFIX 24.16 commit SHA and deploy step
  dea98e1 fix(tasks): sync serial status + repair_status on internal repair complete
  ```
- **Production deploy step:**
  ```bash
  cd /www/wwwroot/kiot.cuongdesign.net
  git pull origin main          # phải thấy d18a0e2 (24.16C-2) ở top
  rm -rf public/build
  npm run build
  php artisan optimize:clear
  # Hard reload trình duyệt (Ctrl+Shift+R)
  ```
