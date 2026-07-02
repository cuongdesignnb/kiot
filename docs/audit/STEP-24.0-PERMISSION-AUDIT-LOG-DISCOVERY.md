# STEP 24.0 — Permission / Role / Audit Log Discovery

> **Bước:** 24.0 — Discovery hiện trạng RBAC + ActivityLog cho thao tác nhạy cảm
> **Ngày:** 06/05/2026
> **Phạm vi:** Discovery + vá nhỏ ActivityLog (pure observability, không enforce permission mới)

---

## 1. Current auth/permission discovery

| Thành phần | File | Hiện trạng | Rủi ro |
|---|---|---|---|
| User model | `app/Models/User.php` | Có `role_id`, `branch_id`, `branchAccess` (many-to-many). Helper `isAdmin()`, `hasPermission()`, `hasAnyPermission()`, `hasRole()`, `hasAnyRole()`, `getAccessibleBranchIds()`, `getPermissionsArray()`. **Admin = `role_id IS NULL` HOẶC role có permission `*`** (backward-compatible). | Một số seeded user `role_id = NULL` thực ra là admin, đảm bảo không bị khóa. |
| Role model | `app/Models/Role.php` | `permissions` JSON array. `getPermissionsMap()` định nghĩa **122+ permission keys** đầy đủ phân theo nhóm (Hàng hóa, Kho, Nhập, Đơn hàng, Khách hàng, Bán hàng, Sổ quỹ, Nhân viên, Công việc, Thiết lập). `hasPermission()` check với wildcard `*`. | Không có rủi ro — schema đầy đủ. |
| `CheckPermission` middleware | `app/Http/Middleware/CheckPermission.php` | Alias `permission:`. Trả 401 nếu chưa login, 403 nếu không có quyền. JSON-aware. | OK. |
| `CheckRole` middleware | `app/Http/Middleware/CheckRole.php` | Alias `role:`. | OK. |
| ActivityLog model | `app/Models/ActivityLog.php` | Static `log($action, $description, $subject, $properties, $userId, $employeeId)`. **45+ ACTION_** constants với label/icon Vietnamese. Auto resolve `auth()->user()` + `ip_address`. Polymorphic `subject`. | OK — service đầy đủ. |
| Policies | `app/Policies/` | **Không tồn tại** — toàn bộ phân quyền dùng middleware-based. | Không phải rủi ro: middleware-based đủ cho RBAC trên Laravel monolith Inertia. |

**Kết luận discovery:** Hạ tầng RBAC + Audit Log đã có **đầy đủ và mạnh**. Không cần build phase mới về infrastructure. Vấn đề chỉ là **coverage**.

---

## 2. Sensitive action matrix

| Module | Action nhạy cảm | Route/Controller | Permission hiện có | Audit log hiện có | Rủi ro |
|---|---|---|---|---|---|
| Invoice/POS | `invoice.create` | `POST /invoices`, `POST /api/pos/checkout` (qua `InvoiceSaleService`) | ✅ `invoices.create`, `pos.use` | ❌ → ✅ **Vá** (`InvoiceController::store`) | Đã vá log |
| Invoice/POS | `invoice.cancel` (destroy) | `DELETE /invoices/{invoice}` | ✅ `invoices.delete` | ❌ → ✅ **Vá** (`InvoiceController::destroy`) | Đã vá log |
| Sales return | `return.create` | `POST /returns` | ✅ `returns.create` | ❌ → ✅ **Vá** (`OrderReturnController::store`) | Đã vá log |
| Sales return | `return.cancel` | `POST /returns/{return}/cancel` | ✅ `returns.create` (chia chung) | ❌ → ✅ **Vá** (`OrderReturnController::cancel`) | Đã vá log; permission tách riêng = backlog |
| Purchase | `purchase.create` | `POST /purchases` | ✅ `purchases.create` | ❌ → ✅ **Vá** (`PurchaseController::store`) | Đã vá log |
| Purchase | `purchase.cancel` (destroy) | `DELETE /purchases/{purchase}` | ✅ `purchases.create` (chia chung) | ❌ → ✅ **Vá** (`PurchaseController::destroy`) | Đã vá log |
| Purchase return | `purchase_return.create` | `POST /purchase-returns` | ✅ `purchases.create` | ❌ → ✅ **Vá** (`PurchaseReturnController::store`) | Đã vá log |
| Purchase return | `purchase_return.cancel` (destroy) | `DELETE /purchase-returns/{purchaseReturn}` | ✅ `purchases.create` | ❌ → ✅ **Vá** (`PurchaseReturnController::destroy`) | Đã vá log |
| StockTake | `stocktake.create/balance/cancel` | `POST /stock-takes` + balance/cancel | ✅ `stock_takes.create` | ✅ Đã có (StockTakeController) | OK |
| StockTransfer | `transfer.create/receive/cancel` | `POST /stock-transfers` + receive/cancel | ✅ `stock_transfers.create` | ✅ Đã có (StockTransferController) | OK |
| Damage | `damage.create` | `POST /damages` | ✅ `damages.create` | ❌ → ✅ **Vá** (`DamageController::store`) | Đã vá log |
| Damage | `damage.cancel` | `POST /damages/{damage}/cancel` | ✅ `damages.create` | ❌ → ✅ **Vá** (`DamageController::cancel`) | Đã vá log |
| Warranty | `warranty.update` | `PUT /warranties/{warranty}` | ✅ `warranties.edit` | ❌ Không có log | Mid-priority — backlog |
| Repair | `task.create` | `POST /api/tasks` (Api) | ❌ **Thiếu permission** trên API route | ✅ Đã có log từ TaskService base | API thiếu enforce |
| Repair | `task.complete` | `POST /api/tasks/{task}/complete` | ❌ **Thiếu permission** | ❌ → ✅ **Vá** (`TaskService::completeExternalRepair`) | Đã vá log |
| Repair | `repair.add_part` | `POST /api/tasks/{task}/parts` | ❌ **Thiếu permission** | ❌ → ✅ **Vá** (`TaskService::addPart`) | Đã vá log |
| Repair | `repair.remove_part` | `DELETE /api/tasks/{task}/parts/{partId}` | ❌ **Thiếu permission** | ❌ → ✅ **Vá** (`TaskService::removePart`) | Đã vá log |
| Repair | `repair.attach_warranty` | `POST /api/tasks/{task}/attach-warranty` | ❌ **Thiếu permission** | ❌ → ✅ **Vá** (`TaskService::attachWarranty`) | Đã vá log |
| Disassembly | `repair.disassemble` | `POST /api/tasks/{task}/disassemble-part` | ❌ **Thiếu permission** | ❌ → ✅ **Vá** (`TaskService::disassemblePart`) | Đã vá log |
| CashFlow | `cashflow.create/delete` | `POST /cash-flows` + `DELETE` | ✅ `cash_flows.create/delete` | ✅ Đã có (CashFlowController) | OK |
| CustomerDebt | `debt.payment/adjust` | `POST /customers/{c}/debt-*` | ✅ `customers.debt_payment/adjust` | ❌ Không có log | Mid-priority — backlog |
| Product price | `product.update` | `PUT /products/{product}` | ✅ `products.edit` | ✅ Đã có (ProductController) | OK |

### Coverage stats

- **routes/web.php:** 122 use `permission:` middleware → coverage **tốt** trên web routes
- **routes/api.php:** **0** use `permission:` middleware → **gap lớn** trên API routes (đặc biệt `/api/tasks/*`, `/api/device-repairs/*`, `/api/my-tasks/*`)
- **ActivityLog calls trong controllers:** trước 24.0 = 11 file. Sau 24.0 = 11 + 5 (Invoice, OrderReturn, Damage, Purchase, PurchaseReturn) + TaskService = ~17 file.

---

## 3. Proposed permission matrix

Hệ thống đã có nhiều keys phù hợp; đề xuất bổ sung các keys mới (chưa enforce ở 24.0, để phase B):

| Permission key | Module | Action | Ai nên có |
|---|---|---|---|
| `invoices.cancel` | Sales | Hủy hóa đơn (tách khỏi `invoices.delete`) | Manager+ |
| `returns.cancel` | Sales | Hủy phiếu trả hàng | Manager+ |
| `purchases.cancel` | Purchases | Hủy phiếu nhập | Manager+ |
| `purchases.return.cancel` | Purchases | Hủy phiếu trả NCC | Manager+ |
| `damages.cancel` | Inventory | Hủy phiếu xuất hủy | Manager+ |
| `stock_transfers.cancel` | Inventory | Hủy chuyển kho | Manager+ |
| `stock_transfers.receive` | Inventory | Nhận chuyển kho | Branch staff destination |
| `stock_takes.balance` | Inventory | Cân bằng kiểm kho (commit) | Manager+ |
| `stock_takes.cancel` | Inventory | Hủy kiểm kho | Manager+ |
| `tasks.disassemble` | Repair | Bóc tách linh kiện | Repair tech (audited) |
| `tasks.attach_warranty` | Repair | Gắn bảo hành vào phiếu | Repair tech |
| `tasks.complete_external` | Repair | Hoàn thành external repair (tạo invoice) | Repair manager |
| `tasks.apply_warranty_policy` | Repair | Áp policy free_labor/free_parts/full_free | Repair manager |
| `customers.debt_offset` | Customer | Cấn trừ công nợ dual-role | Accounting |
| `system.audit.view` | System | Xem activity_logs | Owner/Admin |

**Cách enforce an toàn (cho phase B):**
1. Thêm các key mới vào `Role::getPermissionsMap()`.
2. Tạo seeder/command **`php artisan permissions:grant-admin`** auto cấp các key mới cho mọi role có `*` hoặc `is_system=true`.
3. Mới apply middleware ở route — không bao giờ apply trước khi cấp quyền cho admin.

---

## 4. Proposed audit log matrix

Mỗi log entry hiện đã có: `user_id`, `employee_id`, `action`, `description`, `subject_type`, `subject_id`, `properties` (JSON), `ip_address`, `created_at`. **Đầy đủ theo spec.** Bổ sung field `user_agent` không bắt buộc (đa số trùng nhau theo session).

### Action constants đã thêm dùng ở 24.0

| Action key | Subject | Field cần log (qua `properties`) | Mức độ |
|---|---|---|---|
| `invoice_create` | Invoice | total | Cao |
| `invoice_cancel` | Invoice | total | Cao |
| `return_create` | OrderReturn | total | Cao |
| `return_cancel` (string) | OrderReturn | total | Cao |
| `purchase_create` | Purchase | total | Cao |
| `purchase_delete` | Purchase | total | Cao |
| `purchase_return_create` (string) | PurchaseReturn | total | Cao |
| `purchase_return_cancel` (string) | PurchaseReturn | total | Cao |
| `damage_create` (string) | Damage | total_quantity | Cao |
| `damage_cancel` (string) | Damage | — | Cao |
| `task_complete` | Task | invoice_id, total_amount, paid_amount, debt_amount, warranty_policy, covered_amount | Cao |
| `task_warranty_attach` (string) | Task | warranty_id, invoice_code, serial_imei | Cao |
| `part_install` | Task | task_part_id, product_id, quantity, unit_cost, total_cost, serial_ids | Trung |
| `part_remove` | Task | task_part_id, product_id, quantity, unit_cost, total_cost, serial_ids | Trung |
| `part_disassemble` | Task | task_part_id, output_product_id, quantity, unit_cost, total_cost, output_serial_ids, input_serial_id | Cao |

Action keys dạng string (`damage_create`, `damage_cancel`, `purchase_return_*`, `return_cancel`, `task_warranty_attach`) chưa có trong `ActivityLog::ACTION_*` constants — backlog phase C để bổ sung constant + label/icon map.

---

## 5. Bugs found

| Mã lỗi | Mô tả | Mức độ | File | Cách xử lý |
|---|---|---|---|---|
| AUDIT-1 | API task/repair endpoints không có permission middleware → bất kỳ user authenticated đều dùng được full repair flow | P2 | `routes/api.php` | Backlog phase B (cần seeder cấp quyền admin trước) |
| AUDIT-2 | `returns.cancel` ăn theo permission `returns.create` → user có quyền tạo phiếu trả hàng cũng có thể hủy | P3 | `routes/web.php:592` | Backlog phase B (tách `returns.cancel` permission) |
| AUDIT-3 | `purchases.destroy/cancel` ăn theo `purchases.create` | P3 | `routes/web.php:424` | Backlog phase B |
| AUDIT-4 | InvoiceController, OrderReturnController, DamageController, PurchaseController, PurchaseReturnController, TaskService thiếu ActivityLog trên các thao tác cancel/complete/attach/disassemble | P2 | (5 controller + 1 service) | ✅ **Đã vá ở 24.0** |
| AUDIT-5 | `warranty.update`, `customers.debt_payment/adjust` chưa log | P3 | `WarrantyController`, `CustomerController` | Backlog phase C |
| AUDIT-6 | Một số action key dùng string thay vì hằng số → không có label tiếng Việt khi hiển thị | P3 | `ActivityLog::ACTION_*` | Backlog phase C |

---

## 6. Files changed

| File | Nội dung |
|---|---|
| `app/Http/Controllers/InvoiceController.php` | Thêm `ActivityLog::log(ACTION_INVOICE_CREATE)` ở `store()` sau `createSale()`. Thêm `ActivityLog::log(ACTION_INVOICE_CANCEL)` ở `destroy()` sau `DB::commit`. |
| `app/Http/Controllers/OrderReturnController.php` | Capture `$createdReturn` qua reference từ `DB::transaction` closure. Log `ACTION_RETURN_CREATE` ở `store()`, log `'return_cancel'` ở `cancel()` sau khi update status. |
| `app/Http/Controllers/DamageController.php` | Log `'damage_create'` ở `store()` sau `DB::commit`. Log `'damage_cancel'` ở `cancel()` sau transaction. |
| `app/Http/Controllers/PurchaseController.php` | Log `ACTION_PURCHASE_CREATE` ở `store()`, `ACTION_PURCHASE_DELETE` ở `destroy()` (cancel logical). |
| `app/Http/Controllers/PurchaseReturnController.php` | Log `'purchase_return_create'` ở `store()`, `'purchase_return_cancel'` ở `destroy()`. |
| `app/Services/TaskService.php` | Import `ActivityLog`. Log `ACTION_TASK_COMPLETE` trong `completeExternalRepair()`, `'task_warranty_attach'` trong `attachWarranty()`, `ACTION_PART_INSTALL` trong `addPart()`, `ACTION_PART_REMOVE` trong `removePart()`, `ACTION_PART_DISASSEMBLE` trong `disassemblePart()`. |
| `docs/audit/STEP-24.0-PERMISSION-AUDIT-LOG-DISCOVERY.md` | File này. |

**Không sửa:**

- `User`, `Role`, `ActivityLog` models — schema đã đầy đủ.
- Middleware `CheckPermission`, `CheckRole` — đã hoạt động đúng.
- `routes/web.php`, `routes/api.php` — **không thêm permission middleware mới ở 24.0** (rủi ro khóa user không phải admin chưa được seed quyền).
- Core services nghiệp vụ (MovingAvgCostingService, StockMovementService, InvoiceSaleService, WarrantyGenerationService, CustomerDebtService, SerialAvailabilityService) — chỉ thêm log từ caller controllers.

---

## 7. Tests

Không tạo test mới ở 24.0 vì các vá là pure observability (chỉ thêm log call, không đổi behavior). Verify qua regression cluster:

| Cluster | Tests | Result |
|---|---:|---|
| `Task\|Repair\|Warranty\|ActivityLog\|Auth` | 74 | ✅ 74 PASS (230 assertions) |
| `Step239\|Step238F\|Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 81 | ✅ 81 PASS (264 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 153 + 2 skipped | ✅ 153 PASS (527 assertions) |
| `Step232..Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng:** 400 PASS, 0 FAIL, 2 skipped, ~1367 assertions. **Không hồi quy do thêm log calls.**

---

## 8. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` | ✅ Built in 6.99s, không lỗi |

---

## 9. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có seed permission mới không? | ❌ Không |
| Có nguy cơ khóa admin không? | ❌ Không (không thêm middleware mới ở 24.0) |
| Có update dữ liệu cũ không? | ❌ Không |
| Có đổi middleware route không? | ❌ Không |
| Có thay đổi behavior nghiệp vụ không? | ❌ Không (pure log additions) |

---

## 10. Recommended phases

### 24.0A — ✅ **DONE ở STEP này**

- Discovery + report.
- Vá ActivityLog cho 5 controller + TaskService (5 method) — pure observability.

### 24.0B — RBAC permission enforcement (proposed P2)

- Bổ sung permission keys mới vào `Role::getPermissionsMap()`:
  - `invoices.cancel`, `returns.cancel`, `purchases.cancel`, `purchases.return.cancel`, `damages.cancel`, `stock_transfers.cancel`, `stock_transfers.receive`, `stock_takes.balance`, `stock_takes.cancel`.
  - `tasks.disassemble`, `tasks.attach_warranty`, `tasks.complete_external`, `tasks.apply_warranty_policy`.
- Tạo seeder/command `permissions:grant-admin` cấp tự động cho admin role + role có `*`.
- Apply middleware `permission:` lên các route nhạy cảm:
  - Web: `returns.cancel`, `purchases.destroy`, `purchase-returns.destroy`, `damages.cancel`, `stock-transfers.{receive,cancel}`, `stock-takes.{balance,cancel}`.
  - API: `/api/tasks/*` (toàn bộ block) — sau khi seed quyền cho user hiện có.
- Test phase này bắt buộc có:
  - test admin pass mọi route.
  - test user thiếu quyền 403.
  - test seeder cấp quyền cho `is_system=true` role.

### 24.0C — ActivityLog standardization (proposed P3)

- Bổ sung `ActivityLog::ACTION_*` constants cho các action string còn thiếu: `damage_create`, `damage_cancel`, `purchase_return_create`, `purchase_return_cancel`, `return_cancel`, `task_warranty_attach`.
- Thêm vào `ACTION_LABELS` + `ACTION_ICONS` map.
- Thêm log cho `WarrantyController::update`, `CustomerController::debtPayment/debtAdjust/debtOffset`.
- Thêm cột `user_agent` (optional) vào migration mới idempotent nếu cần.

### 24.0D — UI quản lý vai trò/quyền (proposed P3)

- Trang Settings/Roles để CRUD role + assign permissions từ `getPermissionsMap()`.
- Trang Settings/Users để gán role + branch_access.

### 24.0E — Audit log viewer (proposed P3)

- Trang `/activity-logs` Inertia với filter theo user/action/subject/date.
- Permission `system.audit.view` (owner/admin only).
- Export CSV.

---

## 11. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Hệ thống permission hiện đủ chưa? | **Hạ tầng đủ** (RBAC + 122 permission keys + middleware). **Coverage chưa đủ** trên API routes (gap chính). Web routes đã tốt. |
| Audit log hiện đủ chưa? | **Service đủ** (45+ action constants + label/icon). **Coverage chưa đủ** trên Invoice/Purchase/Return/Damage/Repair/Disassembly trước 24.0; **đã vá ở 24.0** cho các action quan trọng nhất. |
| Nên làm phase nào tiếp? | **24.0B** (RBAC enforcement) là priority P2 vì gap API task routes là rủi ro thật (mọi user authenticated có thể disassemble/attach warranty/complete repair). Cần seeder cấp quyền admin trước khi apply middleware. |
| Có thể deploy không? | ✅ Có (24.0A). Pure log additions, không enforce, không migration, không khóa user. 400 regression tests vẫn PASS. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này tuân thủ mục 1.1 (test trước khi sửa), không hardcode user_id |
| `app/Models/User.php` + `Role.php` + `ActivityLog.php` | Hạ tầng RBAC + log |
| `app/Http/Middleware/CheckPermission.php` + `CheckRole.php` | Enforce middleware |
| `docs/audit/STEP-24.0-PERMISSION-AUDIT-LOG-DISCOVERY.md` | File này |
| 5 controller + `TaskService.php` | Đã vá ActivityLog |
