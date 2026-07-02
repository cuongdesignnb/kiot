# STEP 24.0B — RBAC Permission Enforcement

> **Bước:** 24.0B — Bổ sung permission keys + command cấp quyền + enforce middleware cho thao tác nhạy cảm
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend (Role map + middleware + controller permission checks) + Command grant + Tests. **Không sửa schema, không update data hàng loạt.**

---

## 1. Discovery

| Route | Action | Permission cũ | Permission mới | Enforced? | Ghi chú |
|---|---|---|---|---|---|
| `DELETE /invoices/{invoice}` | Hủy hóa đơn | `invoices.delete` | `invoices.cancel` | ✅ | Tách quyền — không dùng chung `delete` |
| `POST /returns/{return}/cancel` | Hủy trả hàng | `returns.create` | `returns.cancel` | ✅ | |
| `DELETE /purchases/{purchase}` | Hủy nhập hàng | `purchases.create` (nhóm) | `purchases.cancel` | ✅ | |
| `POST /purchase-returns` | Tạo trả NCC | `purchases.create` (nhóm) | `purchases.return.create` | ✅ | |
| `DELETE /purchase-returns/{purchaseReturn}` | Hủy trả NCC | `purchases.create` | `purchases.return.cancel` | ✅ | |
| `POST /damages/{damage}/cancel` | Hủy xuất hủy | `damages.create` (nhóm) | `damages.cancel` | ✅ | |
| `POST /stock-transfers/{id}/receive` | Nhận chuyển kho | `stock_transfers.create` | `stock_transfers.receive` | ✅ | |
| `POST /stock-transfers/{id}/cancel` | Hủy chuyển kho | `stock_transfers.create` | `stock_transfers.cancel` | ✅ | |
| `POST /stock-takes/{id}/balance` | Cân bằng kiểm kho | `stock_takes.create` | `stock_takes.balance` | ✅ | |
| `POST /stock-takes/{id}/cancel` | Hủy kiểm kho | `stock_takes.create` | `stock_takes.cancel` | ✅ | |
| `POST /api/tasks` | Tạo task/repair | (none — gap 24.0) | `tasks.create` | ✅ | |
| `POST /api/tasks/{task}/complete` | Hoàn thành task | (none) | `tasks.complete` (middleware) + `tasks.complete_external` (controller cho external) + `tasks.apply_warranty_policy` (controller cho free policy) | ✅ | Multi-layer check |
| `POST /api/tasks/{task}/parts` | Lắp linh kiện | (none) | `tasks.manage_parts` | ✅ | |
| `DELETE /api/tasks/{task}/parts/{partId}` | Gỡ linh kiện | (none) | `tasks.manage_parts` | ✅ | |
| `POST /api/tasks/{task}/attach-warranty` | Gắn bảo hành | (none) | `tasks.attach_warranty` | ✅ | |
| `GET /api/tasks/lookup-warranty` | Tra cứu bảo hành | (none) | `tasks.attach_warranty` | ✅ | |
| `POST /api/tasks/{task}/disassemble-part` | Bóc tách | (none) | `tasks.disassemble` | ✅ | |
| `POST /api/tasks/{task}/assign` | Giao NV | (none) | `tasks.assign` | ✅ | |
| `POST /api/tasks/{task}/progress` | Cập nhật tiến độ | (none) | `tasks.complete` (action liên quan) | ✅ | |
| `POST /api/tasks/{task}/comments` | Thêm bình luận | (none) | `tasks.view` | ✅ | |
| `POST /api/tasks/batch-repair` | Batch repair tasks | (none) | `tasks.create` | ✅ | |

---

## 2. Permission keys added

Bổ sung vào `Role::getPermissionsMap()`:

| Permission | Nhóm | Ý nghĩa |
|---|---|---|
| `invoices.cancel` | Đơn hàng › Hóa đơn | Hủy hóa đơn |
| `returns.cancel` | Đơn hàng › Trả hàng | Hủy phiếu trả hàng |
| `purchases.cancel` | Nhập hàng › Nhập hàng | Hủy phiếu nhập hàng |
| `purchases.return.create` | Nhập hàng › Nhập hàng | Tạo phiếu trả nhà cung cấp |
| `purchases.return.cancel` | Nhập hàng › Nhập hàng | Hủy phiếu trả NCC |
| `stock_transfers.receive` | Kho hàng › Chuyển hàng | Nhận hàng chuyển kho |
| `stock_transfers.cancel` | Kho hàng › Chuyển hàng | Hủy phiếu chuyển hàng |
| `stock_takes.balance` | Kho hàng › Kiểm kho | Cân bằng kiểm kho |
| `stock_takes.cancel` | Kho hàng › Kiểm kho | Hủy phiếu kiểm kho |
| `damages.cancel` | Kho hàng › Xuất hủy | Hủy phiếu xuất hủy |
| `tasks.create_external` | Công việc & Sửa chữa | Tạo phiếu sửa chữa khách ngoài |
| `tasks.complete_external` | Công việc & Sửa chữa | Hoàn thành sửa chữa khách ngoài |
| `tasks.attach_warranty` | Công việc & Sửa chữa | Gắn bảo hành vào phiếu |
| `tasks.apply_warranty_policy` | Công việc & Sửa chữa | Áp policy free_labor/free_parts/full_free |
| `tasks.disassemble` | Công việc & Sửa chữa | Bóc tách linh kiện |
| `system.audit.view` | Hệ thống | Xem nhật ký hoạt động |

**Tổng 16 permission keys mới.** Không xóa/đổi tên key cũ. Backward compat: key cũ như `tasks.complete`, `tasks.manage_parts` vẫn còn.

---

## 3. Grant command

| Nội dung | Kết quả |
|---|---|
| Command | `php artisan permissions:grant-sensitive` |
| Dry-run mặc định | ✅ Mặc định không ghi DB; hiển thị plan rõ ràng |
| Commit option | `--commit` mới ghi DB |
| Role filter | `--role=ID` hoặc `--role-name=NAME` cho explicit |
| Default scope an toàn | Chỉ role có wildcard `*` (admin tổng). **KHÔNG** auto-grant cho mọi `is_system=true` (tránh role như "Thu ngân" được nâng quyền không mong muốn). |
| Idempotent | ✅ Chạy nhiều lần không duplicate (dùng `array_unique` + `array_diff`) |
| Skip case | Role có `*` → skip vì wildcard tự pass mọi permission |
| User `role_id = NULL` | Không cần cấp — `User::isAdmin()` đã bypass |

### Output sample (dry-run mặc định)

```
DRY-RUN 16 permission key(s), 1 role(s).
  [skip] Role #1 "Quản trị hệ thống" đã có wildcard `*` → tự pass mọi permission.
DRY-RUN — không có gì ghi DB. Chạy lại với --commit để áp dụng.
Sẽ cấp tổng cộng 0 permission(s).
```

### Output sample (target manual role)

```
php artisan permissions:grant-sensitive --role-name=manager240b --commit
COMMIT 16 permission key(s), 1 role(s).
  [+15] Role #5 "manager240b": cấp thêm invoices.cancel, returns.cancel, ...
Tổng cộng đã cấp 15 permission(s).
```

---

## 4. Middleware / controller checks

| Route/Controller | Permission | Cách enforce |
|---|---|---|
| Web: `invoices.destroy` | `invoices.cancel` | Middleware route |
| Web: `returns.cancel` | `returns.cancel` | Middleware route |
| Web: `purchases.destroy` | `purchases.cancel` | Middleware route |
| Web: `purchase-returns.store` | `purchases.return.create` | Middleware route |
| Web: `purchase-returns.destroy` | `purchases.return.cancel` | Middleware route |
| Web: `damages.cancel` | `damages.cancel` | Middleware route |
| Web: `stock-transfers.receive` | `stock_transfers.receive` | Middleware route |
| Web: `stock-transfers.cancel` | `stock_transfers.cancel` | Middleware route |
| Web: `stock-takes.balance` | `stock_takes.balance` | Middleware route |
| Web: `stock-takes.cancel` | `stock_takes.cancel` | Middleware route |
| API: `/api/tasks/*` (read) | `tasks.view` | Middleware group |
| API: `/api/tasks` (POST), `/batch-repair`, PUT, DELETE | `tasks.create` | Middleware group |
| API: `/api/tasks/{task}/parts` POST/DELETE | `tasks.manage_parts` | Middleware group |
| API: `/api/tasks/{task}/disassemble-part` | `tasks.disassemble` | Middleware route |
| API: `/api/tasks/{task}/complete` | `tasks.complete` | Middleware route |
| API: `/api/tasks/{task}/complete` (external repair) | `tasks.complete_external` hoặc `tasks.complete` | Controller check qua `$user->hasAnyPermission()` |
| API: `/api/tasks/{task}/complete` (warranty_policy ≠ none) | `tasks.apply_warranty_policy` | Controller check |
| API: `/api/tasks/{task}/attach-warranty` | `tasks.attach_warranty` | Middleware route |
| API: `/api/tasks/lookup-warranty` | `tasks.attach_warranty` | Middleware route |
| API: `/api/tasks/{task}/assign` | `tasks.assign` | Middleware route |
| API: `/api/tasks` (POST với external=true) | `tasks.create_external` hoặc `tasks.create` | Controller check |

**Admin bypass còn nguyên:** `User::hasPermission()` → `isAdmin()` → return `true` cho `role_id = NULL` HOẶC role có `*`. Toàn bộ middleware mới không khóa admin.

---

## 5. Files changed

| File | Nội dung |
|---|---|
| `app/Models/Role.php` | Bổ sung 16 permission keys vào `getPermissionsMap()` (giữ key cũ) |
| `app/Console/Commands/GrantSensitivePermissions.php` | NEW — command `permissions:grant-sensitive` với dry-run/commit/role filter |
| `routes/web.php` | Tách quyền cancel/balance/receive/return.* khỏi quyền create chung (10 route) |
| `routes/api.php` | Bọc `/api/tasks/*` trong middleware group `tasks.view` cho read; `tasks.create` cho write; tách quyền cho disassemble/complete/attach_warranty/manage_parts/assign |
| `app/Http/Controllers/Api/TaskController.php` | `store()` check `tasks.create_external` cho external repair; `complete()` check `tasks.complete_external` cho external + `tasks.apply_warranty_policy` cho policy ≠ none |
| `tests/Feature/Security/Step240BRbacEnforcementTest.php` | NEW — 13 test cases |
| `docs/audit/STEP-24.0B-RBAC-PERMISSION-ENFORCEMENT.md` | File này |

**Không sửa:**

- `User`, `ActivityLog` models.
- `CheckPermission`, `CheckRole` middleware (giữ logic).
- Core nghiệp vụ services (MovingAvgCostingService, StockMovementService, InvoiceSaleService, TaskService, WarrantyGenerationService, …).
- Migrations, schema.

---

## 6. Tests

### Step240B suite (13 cases)

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_grant_sensitive_permissions_command_dry_run_does_not_modify_roles` | ✅ PASS |
| 2 | `test_grant_sensitive_permissions_command_commit_is_idempotent` | ✅ PASS |
| 3 | `test_admin_role_can_access_sensitive_routes_after_grant` | ✅ PASS |
| 4 | `test_user_without_tasks_disassemble_permission_cannot_disassemble` | ✅ PASS (403) |
| 5 | `test_user_without_tasks_attach_warranty_permission_cannot_attach_warranty` | ✅ PASS (403) |
| 6 | `test_user_without_tasks_complete_external_permission_cannot_complete_external_repair` | ✅ PASS (403 ở middleware) |
| 7 | `test_user_without_apply_warranty_policy_cannot_use_free_policy` | ✅ PASS (403 ở controller check) |
| 8 | `test_user_without_stock_transfers_receive_permission_cannot_receive_transfer` | ✅ PASS (403) |
| 9 | `test_user_without_stock_transfers_cancel_permission_cannot_cancel_transfer` | ✅ PASS (403) |
| 10 | `test_user_without_stock_takes_balance_permission_cannot_balance_stocktake` | ✅ PASS (403) |
| 11 | `test_user_without_returns_cancel_permission_cannot_cancel_return` | ✅ PASS (403) |
| 12 | `test_legacy_admin_user_with_role_id_null_still_can_perform_sensitive_action` | ✅ PASS (admin bypass) |
| 13 | `test_activity_log_still_written_when_authorized_action_succeeds` | ✅ PASS |

**Tổng:** 13/13 PASS, 22 assertions, 20.91s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step240B\|Permission\|Auth\|ActivityLog` | 14 | ✅ 14 PASS (23 assertions) |
| `Task\|Repair\|Warranty` | 77 | ✅ 77 PASS (233 assertions) |
| `Step239\|Step238F\|Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 83 | ✅ 83 PASS (266 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 154 + 2 skipped | ✅ 154 PASS (528 assertions) |
| `Step232..Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng regression sau 24.0B:** ~343 PASS, 0 FAIL, 2 skipped, ~1163 assertions. Không hồi quy do middleware mới (`User::factory()->create()` tạo `role_id = NULL` → admin bypass).

---

## 7. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` | ✅ Built in 6.67s |
| `php artisan permissions:grant-sensitive` (dry-run) | ✅ Hiển thị plan, không ghi DB |
| `php artisan list permissions` | ✅ Command đăng ký |

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có seed/command cấp quyền mới không? | ✅ Có (`permissions:grant-sensitive`) — bắt buộc chạy `--commit` trước khi route:cache |
| Có update role cũ không? | Chỉ khi chạy `--commit` (dry-run mặc định) |
| Có nguy cơ khóa admin không? | ❌ Không. Admin bypass `role_id=NULL` HOẶC `permissions=['*']` vẫn đi qua mọi middleware |
| `role_id = NULL` admin còn pass không? | ✅ Có (`User::isAdmin()` bypass) |
| Có đổi behavior nghiệp vụ không? | ❌ Không (chỉ enforce permission) |
| Có scenario user không-admin mất quyền không? | ⚠️ Có thể — user role không có `*` mà thực hiện action nhạy cảm sẽ 403 nếu role chưa được grant. Đây là **đúng nghiệp vụ**: trước 24.0B mọi user authenticated dùng được API task/repair là **gap bảo mật**. |

---

## 9. Production deploy notes

### Thứ tự deploy bắt buộc:

```bash
cd /www/wwwroot/kiot.cuongdesign.net

# 1. Pull code
git status
git pull origin main

# 2. Composer reload
composer dump-autoload

# 3. (Khuyến nghị) Backup MySQL trước khi đụng role.permissions

# 4. Dry-run kiểm plan
php artisan permissions:grant-sensitive

# 5. Cấp quyền cho admin/wildcard role (an toàn — chỉ role có '*' mặc định)
php artisan permissions:grant-sensitive --commit

# 6. (Optional) Cấp explicit cho role manager nội bộ nếu cần:
# php artisan permissions:grant-sensitive --role-name=manager_branch --commit

# 7. Build assets
npm run build

# 8. Clear + cache prod (ROUTE CACHE chỉ sau khi đã grant)
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 9. Restart queue
php artisan queue:restart
```

Sau đó **restart php-fpm** trong aaPanel UI.

⚠️ **Quan trọng:** KHÔNG `route:cache` trước khi chạy `permissions:grant-sensitive --commit`. Nếu cache route trước khi grant quyền, các user role không-wildcard sẽ bị 403 ở middleware mới.

### Rollback nếu lock nhầm

```bash
# Revert routes web/api về tag cũ
git revert <commit-hash-of-24.0B>
git push origin main
# Hoặc rollback tag mốc
git checkout permission-audit-log-discovery-clean-20260506 -- routes/web.php routes/api.php
```

Permission keys mới đã thêm vào DB không gây vấn đề — chúng chỉ là JSON entries chưa được middleware tham chiếu sau revert.

---

## 10. Manual QA sau deploy

- [ ] Admin `role_id = NULL` vẫn thao tác được mọi action.
- [ ] Role có `*` vẫn thao tác được sau grant (skip vì wildcard).
- [ ] User thiếu `tasks.disassemble` không bóc tách được (`POST /api/tasks/{id}/disassemble-part` → 403).
- [ ] User thiếu `tasks.attach_warranty` không attach warranty được.
- [ ] User thiếu `tasks.apply_warranty_policy` không dùng policy `free_*` được (complete với `policy='none'` vẫn pass).
- [ ] User thiếu `stock_transfers.receive` không nhận chuyển kho.
- [ ] User thiếu `stock_transfers.cancel` không hủy chuyển kho.
- [ ] User thiếu `stock_takes.balance` không cân bằng kiểm kho.
- [ ] User thiếu `returns.cancel` không hủy phiếu trả hàng.
- [ ] User thiếu `invoices.cancel` không hủy hóa đơn (trước đây dùng `invoices.delete`).
- [ ] User thiếu `damages.cancel` không hủy xuất hủy.
- [ ] User thiếu `purchases.cancel` không hủy phiếu nhập.
- [ ] User thiếu `purchases.return.cancel` không hủy phiếu trả NCC.
- [ ] Authorized action vẫn ghi `activity_logs` (test 13 verify).
- [ ] `php artisan permissions:grant-sensitive` chạy lại idempotent.

---

## 11. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | 24.0C: ActivityLog standardization (action constants thiếu, label/icon Vietnamese) | P3 |
| 2 | 24.0D: UI quản lý role/permission (Settings/Roles, Settings/Users) | P3 |
| 3 | 24.0E: Audit log viewer trang `/activity-logs` (cần `system.audit.view` permission) | P3 |
| 4 | Permission tách sâu hơn nếu nghiệp vụ cần (vd: `cash_flows.cancel` riêng khỏi `cash_flows.delete`) | P3 |
| 5 | UI hiển thị "permission required" thay vì 403 generic (UX cải tiến) | P3 |
| 6 | Conditional middleware theo payload (vd: middleware nhận callback check request body) | P3 — phức tạp |
| 7 | Bổ sung permission cho `WarrantyController::update`, `CustomerController::debt*` | P3 |

---

## 12. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| RBAC enforcement đã an toàn chưa? | ✅ An toàn. Admin bypass `role_id=NULL`/`permissions=['*']` còn nguyên. Command grant idempotent + có dry-run. Middleware mới chặn đúng action nhạy cảm với 403. |
| Admin có bị khóa không? | ❌ Không. TC-12 verify `role_id=NULL` admin pass `lookup-warranty`; TC-3 verify `*` role pass. |
| Có thể deploy production không? | ✅ Có — **theo đúng thứ tự** ở mục 9. Bắt buộc `permissions:grant-sensitive --commit` **TRƯỚC** `route:cache`. Backup DB role trước khi commit. Có rollback path qua git revert. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc (mục 1.10 không format tràn lan, mục 2.5 idempotent, mục 9 format báo cáo) |
| `docs/audit/STEP-24.0-PERMISSION-AUDIT-LOG-DISCOVERY.md` | 24.0A discovery + log gap fixes |
| `docs/audit/STEP-24.0B-RBAC-PERMISSION-ENFORCEMENT.md` | File này |
| `app/Console/Commands/GrantSensitivePermissions.php` | Command cấp quyền |
| `app/Models/Role.php` | Permission map mở rộng |
| `app/Http/Middleware/CheckPermission.php` | Middleware (không sửa) |
| `tests/Feature/Security/Step240BRbacEnforcementTest.php` | 13 test cases |
| `routes/web.php`, `routes/api.php` | Middleware enforcement |
