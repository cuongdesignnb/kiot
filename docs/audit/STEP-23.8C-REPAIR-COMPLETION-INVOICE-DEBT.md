# STEP 23.8C — Repair Completion / Invoice / Payment / Debt

> **Ngày:** 06/05/2026
> **Trạng thái:** ✅ **10/10 tests PASS, 186 regression PASS**

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Rủi ro | Phương án |
|---|---|---|---|---|
| Task completion | `TaskService::markCompleted` | Chỉ set status=completed, không tạo invoice | OK cho internal | Giữ nguyên, thêm `completeExternalRepair()` riêng |
| Task invoice_id | `Task.php` fillable | Có sẵn từ Step 23.8A | OK | Dùng để link task→invoice |
| task_parts price | `TaskPart.php` | Chưa có sale_price | Không biết giá bán cho khách | Thêm sale_price nullable migration |
| Invoice creation | `InvoiceSaleService` | Tự trừ tồn kho qua `applySale` | ⚠️ Double deduction nếu dùng | **KHÔNG dùng.** Tạo Invoice::create() trực tiếp |
| InvoiceSaleService stock | `InvoiceSaleService::processItem` | applySale + serial update | ⚠️ P0 risk | Bypass hoàn toàn |
| CashFlow | `CashFlow.php` | fillable sẵn, pattern phiếu thu | OK | CashFlow::create() trực tiếp |
| CustomerDebtService | `CustomerDebtService` | `recordSale()` sẵn | OK | Dùng `recordSale()` cho debt |
| Internal repair | `markCompleted()` | Không tạo invoice | OK | Giữ nguyên 100% |

### Trả lời discovery:
1. **InvoiceSaleService có tự trừ tồn không?** → **Có**, qua `MovingAvgCostingService::applySale()` + serial status update.
2. **Dùng cho repair invoice?** → **KHÔNG** — sẽ trừ tồn lần 2.
3. **TaskPart có sale_price?** → **Chưa** — đã thêm migration.
4. **Invoice có source_type?** → **Chưa** — đã thêm migration.
5. **CustomerDebtService phù hợp?** → **Có** — `recordSale()` nhận signed amount + reference.
6. **CashFlow type?** → Dùng `receipt` + category `Thu tiền sửa chữa`.
7. **Route complete?** → `POST /{task}/complete` đã có route, chỉ cần implement method.

---

## 2. Business rules

### 2.1 Complete external repair

- Chỉ cho `task.external = true`, `task.type = repair`.
- Reject nếu `task.invoice_id != null` (idempotent).
- Reject nếu `task.status = cancelled`.
- Tất cả trong DB::transaction.

### 2.2 Labor fee

- `labor_fee >= 0`, nullable default 0.
- Tạo invoice item product_id=null, description='Tiền công sửa chữa'.

### 2.3 Parts total

- `parts_total = Σ(sale_price × quantity)` cho export parts.
- `sale_price` snapshot từ `part_prices[task_part_id]` nếu UI truyền, hoặc `product.retail_price`.
- Lưu vào `task_parts.sale_price`.

### 2.4 Invoice

- **Stock-neutral:** Tạo `Invoice::create()` trực tiếp, KHÔNG qua InvoiceSaleService.
- **source_type = 'repair'** — phân biệt với hóa đơn bán hàng.
- **Idempotent:** Reject nếu task.invoice_id đã có.

### 2.5 CashFlow

- Tạo nếu `paid_amount > 0`.
- Type `receipt`, category `Thu tiền sửa chữa`.
- Reference = invoice code.

### 2.6 Customer debt

- Tạo nếu `debt_amount > 0` và `customer_id` có.
- Fail nếu `debt_amount > 0` mà không có `customer_id`.
- Dùng `CustomerDebtService::recordSale()`.

### 2.7 Internal repair

- Giữ nguyên `markCompleted()` — không tạo invoice.
- Controller route tự dispatch: external → `completeExternalRepair`, internal → `markCompleted`.

---

## 3. Migration

File: `database/migrations/2026_05_06_000002_add_repair_completion_fields.php`

| Cột | Kiểu | Nullable/default | Lý do |
|---|---|---|---|
| `task_parts.sale_price` | decimal(15,2) | nullable, default 0 | Snapshot giá bán linh kiện cho khách |
| `invoices.source_type` | varchar(30) | nullable | Phân biệt invoice bán hàng vs sửa chữa |
| `invoice_items.description` | varchar(255) | nullable | Cho phép service line (tiền công) |
| `invoice_items.note` | varchar(255) | nullable | Ghi chú thêm nếu chưa có |

---

## 4. Files changed

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_06_000002_add_repair_completion_fields.php` | Migration: sale_price + source_type + description + note |
| `app/Models/TaskPart.php` | +sale_price fillable + cast |
| `app/Services/TaskService.php` | +completeExternalRepair() — stock-neutral invoice + cashflow + debt |
| `app/Http/Controllers/Api/TaskController.php` | Replace old complete() with new dual-path: external→complete, internal→markCompleted |
| `tests/Feature/Tasks/Step238CRepairCompletionInvoiceTest.php` | 10 test cases |

---

## 5. Tests

| # | Test | Kết quả |
|---|---|---|
| 1 | `complete_external_repair_labor_only_paid_full_should_create_invoice_and_cashflow` | ✅ PASS |
| 2 | `complete_external_repair_with_normal_parts_should_not_deduct_stock_again` | ✅ PASS |
| 3 | `complete_external_repair_with_serial_parts_should_keep_serial_used_for_repair` | ✅ PASS |
| 4 | `complete_external_repair_partial_payment_should_create_customer_debt` | ✅ PASS |
| 5 | `complete_external_repair_debt_without_customer_should_fail` | ✅ PASS |
| 6 | `complete_external_repair_paid_amount_capped_at_total` | ✅ PASS |
| 7 | `complete_external_repair_is_idempotent` | ✅ PASS |
| 8 | `internal_repair_mark_completed_should_not_create_invoice` | ✅ PASS |
| 9 | `complete_cancelled_repair_should_fail` | ✅ PASS |
| 10 | `complete_external_repair_transaction_rolls_back_on_failure` | ✅ PASS |

### Regression

| Cluster | Tests | Kết quả |
|---|---|---|
| Step238C | 10 | ✅ 10 PASS |
| Step238B | 10 | ✅ 10 PASS |
| Step238A | 7 | ✅ 7 PASS |
| RR07 | 4 | ✅ 4 PASS |
| SerialAvailability | 5 | ✅ 5 PASS |
| Full regression | 186 | ✅ 186 PASS (618 assertions) |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ OK |
| `npm run build` | ✅ OK — 6.44s |
| `php artisan migrate --env=testing` | ✅ OK |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | Có — 3 nullable cột + 1 nullable cột |
| Có update dữ liệu cũ không? | **Không** |
| Có trừ tồn lần 2 không? | **Không** — bypass InvoiceSaleService hoàn toàn |
| Có đổi serial lần 2 không? | **Không** — serial vẫn used_for_repair |
| Có tạo invoice trùng không? | **Không** — reject nếu invoice_id đã set |
| Có ghi nợ khách vãng lai không? | **Không** — require customer_id khi debt > 0 |
| Có ảnh hưởng repair nội bộ không? | **Không** — internal → markCompleted() giữ nguyên |

---

## 8. Manual QA sau deploy

- [ ] Tạo external repair.
- [ ] Hoàn thành chỉ có tiền công, khách trả đủ → có invoice/cashflow, không công nợ.
- [ ] External repair có linh kiện thường → complete không trừ tồn lần 2.
- [ ] External repair có linh kiện serial → serial vẫn used_for_repair sau complete.
- [ ] Khách trả thiếu → công nợ tăng đúng.
- [ ] Trả thiếu mà không có customer_id → bị chặn.
- [ ] Complete 2 lần → không tạo trùng invoice/cashflow/debt.
- [ ] Internal repair complete cũ vẫn không tạo invoice.

---

## 9. Backlog

| Step | Nội dung | Priority |
|---|---|---|
| 23.8D | Warranty-linked repair | P2 |
| 23.8E | Hardening bóc tách linh kiện | P3 |
| UI | Màn hoàn thành sửa chữa (form nhập tiền công, giá linh kiện, thanh toán) | P1 |
| UI | Serial selector trong modal thêm linh kiện | P1 |
| Cancel | Hủy/hoàn tiền invoice sửa chữa nếu phát sinh nghiệp vụ | P2 |

---

## 10. Conclusion

- ✅ **Complete external repair hoạt động** — 10/10 test PASS.
- ✅ **Invoice stock-neutral** — bypass InvoiceSaleService, không trừ tồn lần 2.
- ✅ **Công nợ/thu tiền đúng** — CashFlow receipt + CustomerDebtService.
- ✅ **Internal repair không ảnh hưởng** — markCompleted() giữ nguyên.
- ✅ **Có thể deploy production** — chạy migration + npm build.
