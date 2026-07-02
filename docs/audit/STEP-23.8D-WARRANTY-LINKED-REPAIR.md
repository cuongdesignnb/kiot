# STEP 23.8D — Warranty-linked Repair

> **Bước:** 23.8D — Gắn bảo hành vào phiếu sửa chữa khách ngoài + áp chính sách miễn phí
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend (Service + API + Migration) + Tests. UI để backlog.

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Cần sửa |
|---|---|---|---|
| Warranty model | `app/Models/Warranty.php` | Đầy đủ field `serial_imei`, `invoice_code`, `warranty_end_date`, `purchase_date`. Có cast datetime. | Không sửa (đọc-only) |
| Task warranty_id | `app/Models/Task.php` | Đã có `warranty_id` nullable + relation `warranty()` từ Step 23.8A | Thêm `warranty_policy`, `warranty_covered_amount`, `customer_payable_amount` + 4 hằng số `WARRANTY_POLICY_*` |
| Tasks migration | `database/migrations/2026_05_05_000001_add_external_repair_fields_to_tasks.php` | Đã có `warranty_id`. Không có cột policy/covered. | Tạo migration mới idempotent thêm 3 cột |
| TaskService | `app/Services/TaskService.php` | `completeExternalRepair()` đã có (Step 23.8C). Không có method attach warranty. | Thêm `attachWarranty()` + `isWarrantyValid()`. Mở rộng `completeExternalRepair` nhận `warranty_policy` |
| Attach warranty API | `app/Http/Controllers/Api/TaskController.php` | Chưa có. | Thêm `attachWarranty()` + `lookupWarranty()` |
| Complete repair policy | `app/Http/Controllers/Api/TaskController.php` `complete()` | Chưa nhận `warranty_policy`. | Thêm validate `warranty_policy` ∈ {none, free_labor, free_parts, full_free} |
| UI lookup warranty | `resources/js/Pages/Tasks/Show.vue` | Chưa có UI gắn bảo hành. | Backlog — không làm trong step này |
| Routes | `routes/api.php` | Chưa có `attach-warranty` / `lookup-warranty`. | Thêm 2 route mới |
| Tests | `tests/Feature/Tasks/Step238CRepairCompletionInvoiceTest.php` | Có template setup tốt. | Tạo `Step238DWarrantyLinkedRepairTest.php` 10 cases |

---

## 2. Business rules

### 2.1 Warranty lookup

- **Rule:** `GET /api/tasks/lookup-warranty?serial_imei=X` hoặc `?invoice_code=Y`. Tối thiểu 1 trong 2 tham số. Trả về tối đa 20 bản ghi mới nhất kèm `valid` flag (`warranty_end_date >= today`).
- Không tự tạo warranty mới.
- Nếu cùng serial/invoice có nhiều record → user nhìn thấy hết, tự chọn.

### 2.2 Attach warranty

- **Rule:**
  - `task.external = true` (chặn internal repair).
  - `task.type = repair`.
  - `task.status` không phải `completed`/`cancelled`.
  - Nếu `warranty.serial_imei` tồn tại VÀ task có `serial_imei_id` (qua `serialImei->serial_number`) → **bắt buộc khớp**, khác = throw.
  - Cho phép attach cả warranty **đã hết hạn** (lưu lịch sử). Chỉ chặn việc dùng policy `free_*` ở step complete.
  - Không sửa warranty record gốc — chỉ ghi `tasks.warranty_id`.

### 2.3 Warranty validity

- **Rule:** `valid = warranty_end_date >= now()->endOfDay()`.
- `warranty_end_date = NULL` → coi như **không xác định**, không tự miễn phí (`isWarrantyValid` trả `false`).

### 2.4 Warranty policies

| Policy | Hiệu ứng |
|---|---|
| `free_labor` | `payable_labor = 0`, parts vẫn tính tiền. Invoice item labor có `price=0` + note "Miễn công theo bảo hành". |
| `free_parts` | `payable_parts = 0`, labor vẫn tính tiền. Invoice items parts có `price=0` + note "Miễn linh kiện theo bảo hành". Linh kiện **vẫn đã trừ tồn ở `addPart`**, không hoàn kho. |
| `full_free` | `total_amount = 0`. Không cashflow (paid=0). Không customer debt. Vẫn tạo invoice với total=0 để có lịch sử. |
| `none` (default) | Tính phí bình thường — behavior như Step 23.8C. |

**Validate:**
- Nếu `task.warranty_id` null hoặc warranty hết hạn → chỉ cho `none`.
- `policy` không thuộc 4 giá trị → throw.

### 2.5 Invoice/cashflow/debt

- **Rule:**
  - Invoice tạo theo `payable` total (sau policy).
  - `labor_fee` snapshot ở task = **gross** (số gốc, để audit), nhưng invoice line dùng `payable_labor`.
  - `parts_total` snapshot ở task = **gross** parts. Invoice line parts dùng `payable_parts` (price=0 nếu free_parts).
  - CashFlow chỉ tạo khi `paid_amount > 0.01`.
  - CustomerDebt chỉ tạo khi `debt_amount > 0.01`.
  - Idempotent: nếu `task.invoice_id` đã có → throw "Đã hoàn thành".
  - Transaction rollback toàn bộ nếu fail.

### 2.6 Inventory/serial safety

- **Rule:**
  - `completeExternalRepair` **KHÔNG** trừ tồn — đã trừ tại `addPart` (Step 23.8B).
  - **KHÔNG** đổi serial status — đã set `used_for_repair` ở `addPart`.
  - Miễn phí (free_*) chỉ ảnh hưởng doanh thu/khách phải trả, **KHÔNG** hoàn kho linh kiện.
  - **KHÔNG** ghi StockMovement mới khi complete (verified bằng test TC-10).

---

## 3. Migration

| Cột | Kiểu | Nullable/default | Lý do |
|---|---|---|---|
| `tasks.warranty_policy` | `string(20)` | nullable | Lưu chính sách áp dụng khi complete (`none/free_labor/free_parts/full_free`) |
| `tasks.warranty_covered_amount` | `decimal(15,2)` | default 0 | Số tiền được miễn theo bảo hành — phục vụ báo cáo & audit |
| `tasks.customer_payable_amount` | `decimal(15,2)` | default 0 | Số tiền khách phải trả sau khi áp policy |

File: `database/migrations/2026_05_06_000001_add_warranty_policy_to_tasks.php`. Idempotent qua `Schema::hasColumn`. Không update dữ liệu cũ. Không backfill.

---

## 4. Files changed

| File | Loại | Nội dung |
|---|---|---|
| `database/migrations/2026_05_06_000001_add_warranty_policy_to_tasks.php` | NEW | Migration thêm 3 cột (policy, covered, payable) |
| `app/Models/Task.php` | EDIT | Thêm 3 fillable + 2 cast + 4 hằng số `WARRANTY_POLICY_*` + array `WARRANTY_POLICIES` |
| `app/Services/TaskService.php` | EDIT | Thêm `use Warranty`. Mở rộng `completeExternalRepair()` xử lý policy. Thêm `attachWarranty()` + `isWarrantyValid()` |
| `app/Http/Controllers/Api/TaskController.php` | EDIT | Thêm `use Warranty`. Validate `warranty_policy` ở `complete()`. Thêm `lookupWarranty()` + `attachWarranty()` |
| `routes/api.php` | EDIT | Thêm `GET /api/tasks/lookup-warranty` + `POST /api/tasks/{task}/attach-warranty` |
| `tests/Feature/Tasks/Step238DWarrantyLinkedRepairTest.php` | NEW | 10 test cases |
| `docs/audit/STEP-23.8D-WARRANTY-LINKED-REPAIR.md` | NEW | File này |

**Không sửa:**
- `app/Services/InvoiceSaleService.php` (không touch core sale)
- `app/Services/MovingAvgCostingService.php`, `StockMovementService.php`
- `app/Services/WarrantyGenerationService.php` (không thay đổi cách tạo warranty từ sale)
- `app/Models/Warranty.php`, `app/Models/TaskPart.php`
- Controllers khác (Invoice, Order, OrderReturn, …)
- Audit tests cũ (RR-01..13, Step 23.7, Step 23.8A/B/C)

---

## 5. Tests

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_attach_valid_warranty_to_external_repair_should_succeed` | ✅ PASS |
| 2 | `test_attach_warranty_to_internal_repair_should_fail` | ✅ PASS |
| 3 | `test_attach_warranty_to_completed_task_should_fail` | ✅ PASS |
| 4 | `test_attach_warranty_serial_mismatch_should_fail` | ✅ PASS |
| 5 | `test_complete_valid_warranty_free_labor_should_zero_labor_fee_only` | ✅ PASS |
| 6 | `test_complete_valid_warranty_free_parts_should_zero_parts_only` | ✅ PASS |
| 7 | `test_complete_valid_warranty_full_free_should_create_zero_payable` | ✅ PASS |
| 8 | `test_expired_warranty_cannot_use_free_policy` | ✅ PASS |
| 9 | `test_no_warranty_cannot_use_free_policy` | ✅ PASS |
| 10 | `test_warranty_policy_does_not_deduct_stock_again` | ✅ PASS |

**Tổng:** 10/10 PASS, 35 assertions, 20.28s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step238A|B|C + Step237B|W` (sibling 23.8 + warranty) | 44 | ✅ 44 PASS |
| `RR06|RR08|RR09|RR11|RR12|RR13` | 28 | ✅ 28 PASS |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (pre-existing batch-isolation issue khi chạy chung filter, không do 23.8D) |
| `SerialAvailability|RequireSerial|CustomerSearch|Order|Purchase|PurchaseReturn|StockTake|StockTransfer|Damage` | 136 | ✅ 136 PASS, 2 skipped |
| `Step232|Step233|Step234|Step235|Step236|Step237` | 87 | ✅ 87 PASS |

**Không có hồi quy mới do 23.8D.**

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE (cache/compiled/config/events/routes/views) |
| `npm run build` | ✅ Built in 6.01s, không lỗi |
| `php artisan migrate --env=testing --force` | ✅ `2026_05_06_000001_add_warranty_policy_to_tasks` ran 216ms |
| `php artisan route:list` | ✅ 2 route mới đăng ký: `api/tasks/lookup-warranty`, `api/tasks/{task}/attach-warranty` |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ✅ 1 file, idempotent qua `Schema::hasColumn` |
| Có update dữ liệu cũ không? | ❌ Không |
| Có backfill warranty không? | ❌ Không |
| Có tự tạo warranty không? | ❌ Không (chỉ tra cứu + attach) |
| Có trừ tồn lần 2 không? | ❌ Không (verified TC-10) |
| Có tạo invoice trùng không? | ❌ Không (idempotent guard `task.invoice_id` đã có từ 23.8C) |
| Có ảnh hưởng repair nội bộ không? | ❌ Không (`attachWarranty` chặn `external=false`; `completeExternalRepair` chặn `external=false` từ 23.8C) |
| Có ảnh hưởng InvoiceSaleService / WarrantyGenerationService không? | ❌ Không touch core sale |
| Có thay đổi enum/cột cũ không? | ❌ Chỉ thêm cột mới nullable/default an toàn |

---

## 8. Manual QA sau deploy

- [ ] Tạo external repair có serial khách (dùng `customer_id` + `customer_name`).
- [ ] Lookup warranty: `GET /api/tasks/lookup-warranty?serial_imei=...` → trả list kèm `valid`.
- [ ] Attach warranty còn hạn: `POST /api/tasks/{task}/attach-warranty` body `{warranty_id: X}` → 200.
- [ ] Attach warranty với serial mismatch (task có serialImei khác warranty.serial_imei) → 422.
- [ ] Attach warranty hết hạn → vẫn 200 (cho phép lưu lịch sử).
- [ ] Internal repair attach warranty → 422.
- [ ] Completed task attach warranty → 422.
- [ ] Complete với policy `free_labor`: invoice total = `parts_total`, labor line price=0 + note "Miễn công".
- [ ] Complete với policy `free_parts`: invoice total = `labor_fee`, parts line price=0 + note "Miễn linh kiện". Tồn linh kiện không đổi.
- [ ] Complete với policy `full_free`: invoice total=0, không cashflow, không debt.
- [ ] Complete với policy `free_labor` mà warranty hết hạn → 422 "phiếu chưa gắn bảo hành hoặc bảo hành đã hết hạn".
- [ ] Complete với policy `free_labor` mà task chưa attach warranty → 422.
- [ ] Sau khi complete với free_parts: kiểm tra `tasks.warranty_covered_amount > 0`, `tasks.customer_payable_amount = labor_fee`.
- [ ] Verify trong `stock_movements`: không có dòng mới khi complete (chỉ có dòng từ `addPart`).
- [ ] Verify serial linh kiện vẫn `used_for_repair` (không quay về `in_stock`).

---

## 9. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | UI tra cứu warranty + attach trên trang Tasks/Show.vue | P3 |
| 2 | UI chọn `warranty_policy` ở dialog complete repair | P3 |
| 3 | Báo cáo chi phí bảo hành theo kỳ (sum `warranty_covered_amount`) | P3 |
| 4 | Hủy/hoàn tiền invoice sửa chữa nếu khách yêu cầu (Step 23.8E?) | P3 |
| 5 | Hardening bóc tách linh kiện 23.8E | P2 |
| 6 | Permission tách: `tasks.attach_warranty`, `tasks.complete_warranty_policy` (hiện đang ăn theo `tasks.complete`) | P3 |
| 7 | Notification khi attach warranty (cho người tạo phiếu / khách) | P3 |

---

## 10. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Warranty-linked repair đã hoạt động chưa? | ✅ Có. `attachWarranty()` + `lookupWarranty()` API hoạt động. `completeExternalRepair` nhận `warranty_policy`. |
| Chính sách miễn phí đúng chưa? | ✅ free_labor / free_parts / full_free / none — verified bằng 10 tests. |
| Có an toàn production không? | ✅ Migration idempotent. Không update data cũ. Không trừ tồn lần 2. Không sửa core service. |
| Có thể deploy không? | ✅ Có. Pre-flight: chạy `git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && npm run build` rồi restart php-fpm. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này tuân thủ mục 1.1 (test trước khi sửa), 5.1 (không xóa vật lý — chỉ thêm cột) |
| `docs/audit/STEP-23.8D-WARRANTY-LINKED-REPAIR.md` | File này |
| `tests/Feature/Tasks/Step238DWarrantyLinkedRepairTest.php` | 10 test cases |
| `app/Services/TaskService.php` | `attachWarranty()`, `isWarrantyValid()`, `completeExternalRepair()` mở rộng |
| `app/Http/Controllers/Api/TaskController.php` | `lookupWarranty()`, `attachWarranty()` |
| `routes/api.php` | 2 route mới |
| `database/migrations/2026_05_06_000001_add_warranty_policy_to_tasks.php` | Migration mới |
