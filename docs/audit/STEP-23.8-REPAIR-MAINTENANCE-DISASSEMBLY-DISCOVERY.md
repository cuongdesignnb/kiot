# STEP 23.8 — Repair / Maintenance / Disassembly Discovery

**Date:** 2026-05-05
**Status:** Discovery only — không code lớn, không migration mới.

---

## 1. Current code discovery

| Nhóm nghiệp vụ | Có code chưa? | File liên quan | DB table liên quan | Trạng thái | Rủi ro |
|---|---|---|---|---|---|
| **Sửa chữa máy nội bộ (Repair Task)** | ✅ ĐÃ CÓ | [app/Models/Task.php](app/Models/Task.php), [app/Services/TaskService.php](app/Services/TaskService.php), [app/Http/Controllers/Api/TaskController.php](app/Http/Controllers/Api/TaskController.php), [app/Http/Controllers/TaskPageController.php](app/Http/Controllers/TaskPageController.php), [resources/js/Pages/Tasks/Show.vue](resources/js/Pages/Tasks/Show.vue) | `tasks` (rename từ `device_repairs`), `task_assignments`, `task_categories`, `task_comments` | ổn định, có test `RR07RepairPartsTest` | Chỉ áp dụng cho **serial nội bộ in_stock** — KHÔNG cover repair cho khách hàng |
| **Linh kiện thay thế** | ✅ ĐÃ CÓ | `TaskService::addPart`, `TaskService::removePart`, [app/Models/TaskPart.php](app/Models/TaskPart.php) | `task_parts` (rename từ `device_repair_parts`), có cột `direction` (export/import) | OK, dùng MovingAvg + StockMovement | Linh kiện **không hỗ trợ Serial/IMEI** ở task_parts — khi linh kiện thay thế là sản phẩm có serial sẽ thiếu chọn serial cụ thể |
| **Bóc tách linh kiện (Disassembly)** | ✅ ĐÃ CÓ | `TaskService::disassemblePart`, `TaskController::disassemblePart`, modal `Tasks/Show.vue` | Dùng cùng `task_parts` với `direction='import'`, log `ActivityLog::ACTION_PART_DISASSEMBLE` | OK | Chỉ cho phép trên **task type=repair** với serial input. Output linh kiện cũng KHÔNG có serial cụ thể (chỉ tăng tồn theo qty). Không có cap "cost output ≤ cost input". |
| **Stock movements cho repair/disassembly** | ✅ ĐÃ CÓ | [app/Services/StockMovementService.php](app/Services/StockMovementService.php), `RebuildMovingAvgCosting` | `stock_movements` với kind: `repair_part`, `disassemble_part`, `repair_on_machine_in`, `repair_on_machine_out`, `repair_in`, `repair_out` | OK | Không có rủi ro lệch tồn nội tại |
| **Yêu cầu bảo hành (Warranty Claim)** | ❌ CHƯA CÓ | (chỉ có module `Warranty` Step 23.7 — danh sách bảo hành read-only) | `warranties` (read-only) | Thiếu | Chưa có flow tiếp nhận bảo hành; không link `tasks.warranty_id` |
| **Repair ngoài bảo hành (Customer-facing)** | ❌ CHƯA CÓ | – | Tasks không có `customer_id`/`customer_name`/`customer_phone`/`warranty_id`/`invoice_id`/`labor_fee`/`paid_amount`/`debt_amount` | Thiếu | Hiện tại không thể nhận máy của khách bên ngoài, không thu tiền công, không công nợ |
| **Bảo trì định kỳ (Maintenance)** | ❌ CHƯA CÓ | – | – | Thiếu | Chưa có lịch bảo trì / nhắc bảo trì |
| **Lắp ráp / BOM** | ⚠ MỘT PHẦN | UI label `"Nguyên vật liệu cấu thành (BOM)"` ở [resources/js/Pages/Products/Create.vue#L554](resources/js/Pages/Products/Create.vue#L554) | KHÔNG có table BOM | UI label trống logic | UI có gợi ý nhưng không có schema/service/test → giả lập |
| **Serial/IMEI trong sửa chữa** | ✅ ĐÃ CÓ | `serial_imeis.repair_status` (`not_started`/`repairing`/`ready`), validation `Task::TYPE_REPAIR` cấm serial trùng task active | `serial_imeis` | OK | Tốt — repair active block bán & block tạo task trùng |

---

## 2. Existing behavior (state-of-the-art ngay tại HEAD `c3fe05c`)

- **Sửa chữa nội bộ:** `Task::TYPE_REPAIR` chỉ tạo cho **serial trong kho** (`status='in_stock'`); validation chặn serial sold/returned/damaged và chặn task active trùng. `original_cost` = `serial.cost_price`. Status: `pending → in_progress → completed | cancelled`.
- **Bảo trì định kỳ:** Không có module riêng; có thể tận dụng `Task::TYPE_GENERAL` nhưng chưa có scheduler/recurring.
- **Bảo hành:** Module `Warranty` (Step 23.7/23.7B) là **danh sách bảo hành** chỉ-đọc + auto-sinh từ sale. Chưa có "warranty claim" flow.
- **Linh kiện:** `TaskService::addPart` xuất tồn linh kiện (qty, unit_cost) → MovingAvg trừ tồn + ghi `StockMovement::TYPE_REPAIR_OUT` + cộng vào `serial.cost_price` & `inventory_total_cost` qua `MovingAvgCostingService::applyRepairAdjustment`. `removePart` đảo ngược đầy đủ.
- **Bóc tách:** `disassemblePart` cộng tồn linh kiện thu hồi (MovingAvg) + ghi `StockMovement::TYPE_REPAIR_IN` + trừ giá vốn máy. Không có cap "tổng output ≤ input cost"; không track serial của linh kiện thu hồi.
- **Serial/IMEI:** `serial_imeis.repair_status` (`not_started/repairing/ready`) hiển thị badge ở Welcome.vue + Tasks UI. Serial đang repair không bán được (validate ở sale flow Step 23.x).

---

## 3. Gap analysis

| Nghiệp vụ cần có | Hiện trạng | Thiếu gì | Mức độ ưu tiên |
|---|---|---|---|
| Repair ngoài bảo hành (khách gửi máy) | Không có | `customer_id`, `customer_phone`, `customer_name` snapshot trên tasks; cho phép task không có serial nội bộ | **Cao** |
| Repair trong bảo hành | Không có | `tasks.warranty_id`, `tasks.invoice_id`; logic check warranty còn hiệu lực; chính sách miễn phí công | **Cao** |
| Thu tiền công + tiền linh kiện + công nợ | Không có | `labor_fee`, `parts_total`, `total_amount`, `paid_amount`, `debt_amount`; tạo invoice dịch vụ; cash flow | **Cao** |
| Bóc tách: cap "output cost ≤ input cost" + serial mới cho output | Một phần | Validate tổng `output.cost ≤ input.cost`; nếu output là sản phẩm có serial → bắt buộc nhập serial | **Trung** |
| Linh kiện thay thế là hàng có serial | Một phần | `task_parts.serial_ids`/serial pivot; bắt buộc chọn serial cụ thể nếu product.has_serial | **Trung** |
| Sản phẩm chính sau bóc tách → trạng thái dismantled | Không có | `serial_imeis.status='dismantled'`; chặn bán | **Trung** |
| Bảo trì định kỳ (recurring) | Không có | Bảng `maintenance_schedules` hoặc tận dụng tasks.deadline + cron tạo task | **Thấp** |
| BOM (cấu thành sản phẩm) | UI label trống | Bảng `product_components` hoặc `product_boms`; service nhập kho lắp ráp | **Thấp** |
| Hủy repair → rollback linh kiện | Có (removePart) | OK, đã rollback đầy đủ | – |

---

## 4. Proposed business flows

### 4.1 Repair ticket ngoài bảo hành
- Khách mang máy → `POST /api/tasks` với `type=repair_external`, `customer_id` hoặc snapshot, `product_id` nullable, `serial_imei` nullable (text), `issue_description`.
- Status mặc định `received`. Không động tồn kho.
- Khi check máy → `diagnosis_note`, có thể quote `estimated_cost`.
- Khi sửa → `addPart` xuất linh kiện (như flow nội bộ), nhưng cộng vào `parts_total` riêng (không cộng vào serial in_stock).
- Hoàn thành → tạo `Invoice` dịch vụ (linh kiện + công), thu tiền (cash flow) hoặc ghi nợ → `paid_amount`/`debt_amount`.
- Trả máy → `returned_at` + đóng phiếu.

### 4.2 Repair ticket trong bảo hành
- Lookup warranty theo `serial_imei` hoặc `invoice_code`. Kiểm tra `warranty_end_date >= now`.
- Set `tasks.warranty_id`. Áp `policy`: free labor / charge parts only / full free.
- Linh kiện vẫn xuất tồn, ghi giá vốn (lợi nhuận âm cho phần bảo hành) — báo cáo riêng "Chi phí bảo hành".
- Không phát sinh doanh thu nếu policy free; vẫn ghi `task_parts` để truy vết.

### 4.3 Xuất linh kiện thay thế
- Reuse `TaskService::addPart` (đã có) — mở rộng: nếu `Product::has_serial` → bắt buộc `serial_id`; tạo `task_part_serials` pivot hoặc `task_parts.serial_id`.
- Đánh dấu `serial.status='used_for_repair'` (không bán lại).

### 4.4 Hoàn thành sửa chữa và thu tiền
- `tasks.status='completed'` → tự sinh `Invoice` dịch vụ:
  - Item 1: "Tiền công sửa chữa" (`labor_fee`).
  - Item N: từng linh kiện trong `task_parts.direction='export'`.
- Reuse `InvoiceSaleService::createSale` style → cash flow + công nợ + warranty chain.
- Nếu `policy=warranty_free` → invoice tổng=0 nhưng vẫn ghi `task_parts`.

### 4.5 Bóc tách linh kiện
- Reuse `TaskService::disassemblePart` (đã có) — bổ sung:
  - Validate: tổng `Σ output.unit_cost*qty ≤ task.original_cost - Σ part_used.cost`.
  - Nếu output `Product::has_serial` → bắt buộc nhập serial mới.
  - Khi task complete + có disassembly: serial input gốc chuyển `status='dismantled'` + chặn bán.

---

## 5. Proposed database design

### 5.1 `tasks` — thêm cột (migration mới khi triển khai 23.8A)
- `customer_id` UNSIGNED BIGINT NULL FK customers.id
- `customer_name` VARCHAR(255) NULL  *(snapshot)*
- `customer_phone` VARCHAR(50) NULL
- `warranty_id` UNSIGNED BIGINT NULL FK warranties.id
- `invoice_id` UNSIGNED BIGINT NULL FK invoices.id  *(invoice dịch vụ)*
- `labor_fee` DECIMAL(18,0) DEFAULT 0
- `parts_total` DECIMAL(18,0) DEFAULT 0  *(snapshot Σ task_parts.total_cost direction=export)*
- `total_amount` DECIMAL(18,0) DEFAULT 0
- `paid_amount` DECIMAL(18,0) DEFAULT 0
- `debt_amount` DECIMAL(18,0) DEFAULT 0
- `received_at`, `returned_at` TIMESTAMP NULL
- `external` BOOLEAN DEFAULT 0  *(true=khách ngoài, false=internal serial in_stock)*
- Mở rộng status enum: `received`, `checking`, `waiting_customer`, `repairing`, `completed`, `returned`, `cancelled`.
  - **CHỌN cách an toàn:** giữ enum hiện tại (`pending/in_progress/completed/cancelled`) là **lifecycle gốc**, thêm `tasks.sub_status` VARCHAR (text) cho stage chi tiết → KHÔNG đổi enum cũ → tránh break `RR07RepairPartsTest`.

### 5.2 `task_parts` — thêm cột
- `serial_id` UNSIGNED BIGINT NULL FK serial_imeis.id  *(serial linh kiện thay)*
- `sale_price` DECIMAL(18,0) DEFAULT 0  *(giá bán thu khách, khác cost)*

### 5.3 `disassemblies` (mới — Phase 23.8E)
- Có thể bỏ qua nếu mở rộng `task_parts` đủ; hoặc tạo bảng riêng nếu nghiệp vụ có "phiếu bóc tách độc lập" không gắn task.
- Đề xuất: **giữ trên `task_parts.direction='import'` như hiện tại**. Bóc tách là sub-flow của task, không cần bảng riêng.

### 5.4 `disassembly_inputs`, 5.5 `disassembly_outputs`
- Không cần. Input là `tasks.serial_imei_id`. Output là các `task_parts.direction='import'`. Tránh duplicate model.

### 5.6 `product_components` — BOM (Phase ngoài 23.8, nếu cần)
- `product_id` (sản phẩm cha)
- `component_product_id` (linh kiện)
- `quantity`, `note`
- Service `assembleProduct(product_id, qty)` → trừ tồn linh kiện theo BOM, tăng tồn cha.

---

## 6. Inventory / Serial / Cost rules

| Nghiệp vụ | Stock effect | Serial effect | Cost effect | Debt/Invoice effect |
|---|---|---|---|---|
| Tiếp nhận sửa chữa (external) | Không đổi | Không đổi (serial là text snapshot) | Không đổi | Không đổi |
| Tiếp nhận sửa chữa (internal) | Không đổi | `serial.repair_status='not_started'` | Không đổi | Không đổi |
| Thay linh kiện (addPart) | Trừ tồn linh kiện | Nếu có serial → mark `used_for_repair` (mới) | Cộng `serial.cost_price` (internal) hoặc cộng `parts_total` (external) | Không đổi |
| Hủy linh kiện (removePart) | Hoàn tồn linh kiện | Reset serial nếu có | Trừ ngược cost | Không đổi |
| Hoàn thành sửa chữa internal | Không đổi tồn | `serial.repair_status='ready'` | Cost máy = original + parts | Không đổi (chờ bán lại) |
| Hoàn thành sửa chữa external | Không đổi tồn | – | Không đổi | Tạo invoice dịch vụ + cash flow + debt + warranty (nếu có) |
| Hủy sửa chữa | Hoàn tồn linh kiện đã xuất | Reset serial repair_status | Trừ ngược cost | Không đổi |
| Bóc tách (disassemblePart) | Tăng tồn linh kiện thu hồi | Input serial → `dismantled` (mới); output có serial → tạo serial mới `in_stock` (mới) | Trừ giá vốn máy, cộng vào linh kiện theo MovingAvg | Không đổi |

---

## 7. Risks

| Rủi ro | Mức độ | Cách kiểm soát |
|---|---|---|
| Trừ linh kiện nhưng không ghi stock movement | Thấp (đã có) | `addPart` đã ghi `StockMovementService::TYPE_REPAIR_OUT` |
| Hàng serial thay thế không chọn serial cụ thể | Cao | 23.8B: thêm `task_parts.serial_id` + validate `Product::has_serial` bắt buộc serial |
| Sửa chữa miễn phí nhưng vẫn mất giá vốn linh kiện | Trung | Reuse logic xuất tồn cho cả flow free; chỉ khác `total_amount=0` ở invoice |
| Bóc tách không phân bổ giá vốn | Cao | 23.8E: validate `Σ output.cost ≤ task.original_cost - Σ part_used.cost`. Báo cáo lệch giá vốn |
| Hủy phiếu sửa chữa không rollback linh kiện | Thấp | `removePart` đã rollback đầy đủ. Cần thêm: hủy task → tự gọi removePart cho mọi part export |
| Sản phẩm chính có serial sau bóc tách vẫn bán được | Cao | 23.8E: chuyển `serial.status='dismantled'`; thêm guard ở `InvoiceSaleService::assertSerialSelectionComplete` |
| Tạo invoice sửa chữa trùng doanh thu | Trung | 23.8C: link `tasks.invoice_id`; idempotent: nếu đã có invoice → không tạo lại |
| Output disassembly có serial nhưng không sinh serial mới | Trung | 23.8E: nếu output product.has_serial → bắt buộc nhập serial number(s) |
| BOM giả lập ở UI Products/Create không lưu được | Thấp (UI hint) | Step ngoài 23.8: nếu cần thật → tạo `product_components` |
| Repair ngoài bảo hành không gắn customer → mất công nợ | Cao | 23.8A: thêm `customer_id` trên tasks |

---

## 8. Recommended implementation plan

### Phase 23.8A — Repair Ticket khách ngoài (cơ bản)
- Migration: thêm `customer_id`, `customer_name`, `customer_phone`, `warranty_id`, `invoice_id`, `received_at`, `returned_at`, `sub_status`, `external` flag, `labor_fee`, `parts_total`, `total_amount`, `paid_amount`, `debt_amount` lên `tasks` (nullable, idempotent).
- Service: nhánh `createExternalRepair(...)` không yêu cầu serial in_stock.
- UI: form tiếp nhận máy khách (customer search, product/serial text snapshot, mô tả lỗi).
- Test: `Step238ARepairExternalTest`.
- **Không đụng** flow internal hiện có (giữ enum status cũ).

### Phase 23.8B — Repair parts với Serial
- Migration: thêm `task_parts.serial_id` (nullable FK), `task_parts.sale_price`.
- Service: `addPart` validate `Product::has_serial` bắt buộc serial.
- Test: `Step238BRepairPartsSerialTest`.

### Phase 23.8C — Repair completion + Invoice + Debt
- Service: `completeRepairTask` tự tạo `Invoice` (dùng `InvoiceSaleService` style), cash flow, công nợ.
- Idempotent qua `tasks.invoice_id`.
- Test: `Step238CRepairCompletionInvoiceTest`.

### Phase 23.8D — Warranty-linked repair
- Service: `attachWarrantyToTask`, kiểm `warranty_end_date`.
- Policy: free labor / free parts / full free.
- Test: `Step238DRepairWarrantyTest`.

### Phase 23.8E — Disassembly hardening
- Migration: thêm `serial_imeis.status='dismantled'`.
- Service `disassemblePart`: cap output cost; nếu output has_serial → bắt buộc serial; mark serial gốc dismantled.
- Test: `Step238EDisassemblyHardeningTest`.

### Phase 23.8F (Optional) — BOM & Assembly
- Migration `product_components`. Service `assemble`. Out of immediate scope.

---

## 9. Build/Test (verify không phá hiện tại)

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 7.54s |
| `--filter="Warranty\|Step237B"` | ✅ 17 passed, 56 assertions |
| Regression cluster (RR02..RR13, Order, Purchase, Damage, StockTake, StockTransfer, Step232..237) | ✅ 160 passed, 2 skipped, 578 assertions |

Step 23.8 là discovery thuần, KHÔNG sửa code → 0 risk regression.

---

## 10. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Có module sửa chữa hiện hữu không? | ✅ CÓ — `tasks`/`task_parts` (rename từ `device_repairs`/`device_repair_parts`), TaskService đầy đủ addPart/removePart/disassemblePart, có test RR07. |
| Có module bóc tách hiện hữu không? | ✅ CÓ — `disassemblePart` trên `task_parts.direction='import'`, có UI modal "Bóc LK" ở `Tasks/Show.vue`. |
| Có module bảo hành claim không? | ❌ KHÔNG — chỉ có Warranty list (Step 23.7) + auto-gen (Step 23.7B). |
| Có repair cho khách ngoài không? | ❌ KHÔNG — tasks chỉ áp dụng serial in_stock nội bộ. |
| Có thu tiền công / công nợ sửa chữa không? | ❌ KHÔNG — tasks không có labor_fee/paid_amount/debt_amount/invoice_id. |
| Có BOM thật không? | ❌ KHÔNG — chỉ label UI ở Products/Create, không có schema. |
| Nên triển khai phase nào trước? | **23.8A** (repair khách ngoài + customer/warranty/invoice link) — vì đây là gap nghiệp vụ lớn nhất, các phase B/C/D/E xây trên hạ tầng A. |
| Có cần migration không? | Không cần ở 23.8 (discovery). 23.8A trở đi sẽ cần migration **idempotent thêm cột nullable** lên `tasks` + `task_parts`, không drop cột cũ. |
| Có thể bắt đầu 23.8A không? | ✅ SẴN SÀNG — schema-tolerant qua `Schema::hasColumn`, không phá enum status cũ, không phá test RR07. |

**Kết luận tổng thể:**
- Repository đã có **xương sống** repair + disassembly ở mức **internal-only** (sửa máy nội bộ trước khi bán lại).
- Gap chính: chưa cover **repair khách ngoài** (customer-facing) + **warranty-linked repair** + **invoice dịch vụ + công nợ**.
- Phương án triển khai: 5 phase (23.8A → 23.8E) **mở rộng dần** trên schema có sẵn, không tạo bảng `repair_tickets` mới (tránh duplicate `tasks`).
- KHÔNG cần migrate:fresh, KHÔNG cần backfill.
