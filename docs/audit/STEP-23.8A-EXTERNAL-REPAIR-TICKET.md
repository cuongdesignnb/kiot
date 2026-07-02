# STEP 23.8A — External Repair Ticket

> **Ngày:** 05/05/2026  
> **Trạng thái:** ✅ **7/7 tests PASS, 57/57 regression PASS**

---

## 1. Discovery

| Thành phần | File | Hiện trạng | Đã sửa |
|---|---|---|---|
| Task model | `app/Models/Task.php` | Chỉ có internal repair (serial required) | ✅ Thêm fillable/casts/relations cho external |
| TaskService createTask | `app/Services/TaskService.php` | `createRepairTask()` yêu cầu `serial_imei_id` in_stock | ✅ Thêm `createExternalRepair()`, dispatch theo `external` flag |
| API TaskController store | `app/Http/Controllers/Api/TaskController.php` | Repair = `serial_imei_id required` | ✅ Thêm branch external: customer/issue validation |
| TaskPageController | `app/Http/Controllers/TaskPageController.php` | Render Inertia pages | Không sửa |
| Migration tasks | `2026_03_15_000004_upgrade_device_repairs_to_tasks.php` | `serial_imei_id` NOT NULL | ✅ Migration mới: nullable + thêm cột |
| Tests | `tests/Feature/Repair/RR07RepairPartsTest.php` | 4 test internal flow | Không sửa — 4/4 PASS |

---

## 2. Business rules

### External repair (external=true):
- Không yêu cầu serial nội bộ (serial_imei_id nullable).
- Nhận thông tin khách qua `customer_name`/`customer_phone` hoặc `customer_id`.
- Nếu có `customer_id`, auto-snapshot `name`/`phone` vào task.
- `sub_status` = `received` khi tạo.
- `status` = `pending` khi tạo.
- Không đổi `stock_quantity`.
- Không tạo `StockMovement`.
- Không tạo Invoice/CashFlow/công nợ.
- `issue_description` bắt buộc.

### Internal repair giữ nguyên:
- Vẫn yêu cầu `serial_imei_id` in_stock.
- Vẫn check serial không có task active.
- Vẫn set `repair_status` trên serial.
- Flow addPart/removePart/disassemblePart không đổi.

### Stock/Serial effect khi tiếp nhận: **KHÔNG CÓ**
### Invoice/debt effect khi tiếp nhận: **KHÔNG CÓ**

---

## 3. Migration

File: `database/migrations/2026_05_05_000001_add_external_repair_fields_to_tasks.php`

| Cột | Kiểu | Nullable/default | Lý do |
|---|---|---|---|
| `serial_imei_id` | unsignedBigInteger | **nullable** (changed) | External không cần serial nội bộ |
| `product_id` | unsignedBigInteger | **nullable** (changed) | External không bắt buộc product |
| `external` | boolean | default false | Phân biệt internal/external |
| `sub_status` | string(30) | nullable | Stage chi tiết: received/checking/repairing/completed/returned/cancelled |
| `customer_id` | foreignId | nullable, FK customers | Liên kết khách hàng |
| `customer_name` | string | nullable | Snapshot tên khách |
| `customer_phone` | string(30) | nullable | Snapshot SĐT |
| `warranty_id` | foreignId | nullable, FK warranties | Liên kết bảo hành (future) |
| `invoice_id` | foreignId | nullable, FK invoices | Liên kết hóa đơn (future) |
| `received_at` | timestamp | nullable | Thời điểm tiếp nhận |
| `returned_at` | timestamp | nullable | Thời điểm trả máy |
| `labor_fee` | decimal(15,2) | default 0 | Tiền công sửa chữa (future) |
| `parts_total` | decimal(15,2) | default 0 | Tổng tiền linh kiện (future) |
| `total_amount` | decimal(15,2) | default 0 | Tổng cộng phiếu sửa (future) |
| `paid_amount` | decimal(15,2) | default 0 | Đã thu (future) |
| `debt_amount` | decimal(15,2) | default 0 | Công nợ sửa chữa (future) |

---

## 4. Files changed

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_05_000001_add_external_repair_fields_to_tasks.php` | Migration thêm cột + nullable serial/product |
| `app/Models/Task.php` | Thêm fillable, casts, customer/warranty/invoice relations |
| `app/Services/TaskService.php` | Thêm `createExternalRepair()`, dispatch từ `createTask()` |
| `app/Http/Controllers/Api/TaskController.php` | Thêm external branch trong `store()` |
| `tests/Feature/Tasks/Step238AExternalRepairTicketTest.php` | 7 test cases |

---

## 5. Tests

| # | Test | Kết quả |
|---|---|---|
| 1 | `create_external_repair_with_customer_snapshot_should_succeed` | ✅ PASS |
| 2 | `create_external_repair_with_customer_id_should_snapshot_customer` | ✅ PASS |
| 3 | `external_repair_does_not_require_internal_serial_in_stock` | ✅ PASS |
| 4 | `external_repair_requires_issue_description` | ✅ PASS |
| 5 | `internal_repair_existing_flow_still_requires_in_stock_serial` | ✅ PASS |
| 6 | `external_repair_does_not_create_invoice_or_debt` | ✅ PASS |
| 7 | `external_repair_can_be_cancelled_without_stock_effect` | ✅ PASS |

### Regression

| Test Suite | Tests | Kết quả |
|---|---|---|
| Step238AExternalRepairTicketTest | 7 | ✅ 7 PASS |
| RR07RepairPartsTest | 4 | ✅ 4 PASS |
| RR11OrderReturnQtyTest | 4 | ✅ 4 PASS |
| RR10CashFlowDeletionTest | 5 | ✅ 5 PASS |
| RR04StockTakeTest | 5 | ✅ 5 PASS |
| RR03StockTransferTest | 5 | ✅ 5 PASS |
| RR03StockTransferRouteTest | 3 | ✅ 3 PASS |
| CancelInvoiceTest | 10 | ✅ 10 PASS |
| RR01ReportControllerRegressionTest | 8 | ✅ 8 PASS |
| RR01SupplierDualRoleRegressionTest | 2 | ✅ 2 PASS |
| RR01CashFlowCancelledRegressionTest | 4 | ✅ 4 PASS |
| **Tổng** | **57** | **57 PASS, 0 FAIL** |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ OK |
| `npm run build` | ✅ OK — built in 7.64s |
| `php artisan migrate --env=testing` | ✅ OK |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | Có, nullable/idempotent |
| Có update dữ liệu cũ không? | Không |
| Có đổi enum status cũ không? | Không |
| Có đụng stock/serial khi tiếp nhận không? | Không |
| Có tạo invoice/debt không? | Không |
| Có phá flow repair nội bộ không? | Không — RR07 4/4 PASS |
| serial_imei_id changed to nullable? | Có — safe, existing rows đã có value |
| product_id changed to nullable? | Có — safe, existing rows đã có value |

---

## 8. Backlog

| Step | Nội dung | Mức độ |
|---|---|---|
| 23.8B | Repair parts có Serial/IMEI tracking | P2 |
| 23.8C | Hoàn thành sửa chữa → tạo invoice/thu tiền/công nợ | P1 |
| 23.8D | Warranty-linked repair (auto-fill từ warranty) | P2 |
| 23.8E | Hardening bóc tách linh kiện cho external repair | P3 |
| UI | Thêm "Sửa chữa khách ngoài" vào Tasks/Index.vue + create form | P1 |

---

## 9. Manual QA sau deploy

- [ ] Tạo phiếu sửa chữa khách ngoài với tên/sđt khách.
- [ ] Nhập serial text không có trong kho vẫn tạo được.
- [ ] Không đổi tồn kho.
- [ ] Không đổi serial_imeis.
- [ ] Không tạo hóa đơn/công nợ.
- [ ] Flow sửa chữa nội bộ cũ vẫn hoạt động.
- [ ] Cancel external repair không ảnh hưởng kho.

---

## 10. Conclusion

- ✅ **External repair ticket đã tạo được** — 7/7 test PASS.
- ✅ **Flow internal cũ không bị ảnh hưởng** — RR07 4/4 PASS.
- ✅ **Migration an toàn**: chỉ thêm cột nullable, change serial/product nullable.
- ✅ **Regression 57/57 PASS**: không phá bất kỳ module nào.
- ✅ **Có thể deploy production** sau khi chạy migration trên MySQL.
