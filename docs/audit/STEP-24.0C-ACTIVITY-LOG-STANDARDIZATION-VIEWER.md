# STEP 24.0C — ActivityLog Standardization + Viewer

> **Bước:** 24.0C — Chuẩn hóa ActivityLog constants/labels/icons + bổ sung user_agent + tạo Audit Log Viewer
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend Model + Controller + Migration + Routes + Tests. UI đã có sẵn (Pages/ActivityLogs/Index.vue).

---

## 1. Discovery

| Action key | Đang được log ở đâu | Có constant? (trước) | Có label? | Có icon? | Cần sửa |
|---|---|---|---|---|---|
| `return_cancel` | OrderReturnController::cancel | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_RETURN_CANCEL` + label/icon + refactor |
| `damage_create` | DamageController::store | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_DAMAGE_CREATE` + label/icon + refactor |
| `damage_cancel` | DamageController::cancel | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_DAMAGE_CANCEL` + label/icon + refactor |
| `purchase_return_create` | PurchaseReturnController::store | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_PURCHASE_RETURN_CREATE` + refactor |
| `purchase_return_cancel` | PurchaseReturnController::destroy | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_PURCHASE_RETURN_CANCEL` + refactor |
| `task_warranty_attach` | TaskService::attachWarranty | ❌ String | ❌ | ❌ | ✅ Đã thêm `ACTION_TASK_WARRANTY_ATTACH` + refactor |
| `warranty_update` | (chưa log) | ❌ | ❌ | ❌ | ✅ Đã thêm constant + log call ở `WarrantyController::update` với old/new/changed_fields |
| `customer_debt_payment` | (chưa log) | ❌ | ❌ | ❌ | Đã thêm constant + label/icon. **Backlog log call** (xem Backlog mục 11) |
| `customer_debt_adjust` | (chưa log) | ❌ | ❌ | ❌ | Đã thêm constant + label/icon. Backlog |
| `customer_debt_offset` | (chưa log) | ❌ | ❌ | ❌ | Đã thêm constant + label/icon. Backlog |

---

## 2. Standardized actions

| Action constant | Key | Label | Icon |
|---|---|---|---|
| `ACTION_RETURN_CANCEL` | `return_cancel` | Hủy phiếu trả hàng | 🚫 |
| `ACTION_PURCHASE_RETURN_CREATE` | `purchase_return_create` | Tạo phiếu trả nhà cung cấp | 📤 |
| `ACTION_PURCHASE_RETURN_CANCEL` | `purchase_return_cancel` | Hủy phiếu trả nhà cung cấp | 🚫 |
| `ACTION_DAMAGE_CREATE` | `damage_create` | Tạo phiếu xuất hủy | 🧯 |
| `ACTION_DAMAGE_CANCEL` | `damage_cancel` | Hủy phiếu xuất hủy | 🚫 |
| `ACTION_WARRANTY_UPDATE` | `warranty_update` | Cập nhật bảo hành | 🛠️ |
| `ACTION_TASK_WARRANTY_ATTACH` | `task_warranty_attach` | Gắn bảo hành vào phiếu sửa chữa | 🛡️ |
| `ACTION_CUSTOMER_DEBT_PAYMENT` | `customer_debt_payment` | Thanh toán công nợ khách hàng | 💵 |
| `ACTION_CUSTOMER_DEBT_ADJUST` | `customer_debt_adjust` | Điều chỉnh công nợ khách hàng | ⚖️ |
| `ACTION_CUSTOMER_DEBT_OFFSET` | `customer_debt_offset` | Cấn trừ công nợ | 🔄 |

**Quan trọng:** Giá trị string giữ nguyên (`return_cancel`, `damage_create`, …) — **không đổi action key cũ trong DB**. Logs cũ vẫn render đúng label/icon.

---

## 3. Viewer route

| Route | Controller | Middleware | Permission |
|---|---|---|---|
| `GET /activity-logs` | `ActivityLogController@index` (Inertia render `Pages/ActivityLogs/Index`) | `auth` + `permission:system.audit.view` | `system.audit.view` (Step 24.0B) |
| `GET /api/activity-logs` | `ActivityLogController@api` (paginated JSON) | `permission:system.audit.view` | `system.audit.view` |
| `GET /api/activity-logs/action-types` | `ActivityLogController@actionTypes` | `permission:system.audit.view` | `system.audit.view` |

---

## 4. Filters

| Filter | Backend support | UI support |
|---|---|---|
| `action` (key) | ✅ where `action = ?` | ✅ dropdown (UI Index.vue đã có) |
| `user_id` | ✅ where `user_id = ?` | (manual ID — UI có thể mở rộng dropdown) |
| `employee_id` | ✅ where `employee_id = ?` | ✅ dropdown employees |
| `subject_type` | ✅ where `subject_type = ?` | (manual — backlog UI dropdown) |
| `subject_id` | ✅ where `subject_id = ?` | (manual) |
| `from` (date) | ✅ `created_at >= from` | ✅ datepicker |
| `to` (date) | ✅ `created_at <= to` | ✅ datepicker |
| `search` / `q` | ✅ LIKE `description` hoặc `action` | ✅ input |

Pagination: mặc định 30/page, max 200/page (validate). Sort `latest('id')`.

Eager load: `user:id,name,email`, `employee:id,name,code` — không N+1.

Response transform: thêm `action_label` + `action_icon` ngay vào mỗi log entry (UI không cần lookup map riêng cho text + icon).

---

## 5. Migration

| Change | Kiểu | Lý do |
|---|---|---|
| `activity_logs.user_agent` | `string(500) nullable` | Audit kỹ hơn — tracking client. Mặc định null. Tự cắt 500 ký tự để không tràn. |

File: `database/migrations/2026_05_06_000004_add_user_agent_to_activity_logs.php`. Idempotent qua `Schema::hasColumn`. Không update logs cũ — null cho mọi row hiện có.

`ActivityLog::log()` tự capture `request()->userAgent()` qua schema-tolerant check (`Schema::hasColumn` cache). Skip cho test SQLite cũng OK.

---

## 6. Files changed

| File | Nội dung |
|---|---|
| `app/Models/ActivityLog.php` | Thêm 10 ACTION_* constants mới + 10 entries trong `ACTION_LABELS` + 10 trong `ACTION_ICONS`. Thêm `user_agent` vào fillable. `log()` schema-tolerant capture user_agent (cắt 500 ký tự). |
| `database/migrations/2026_05_06_000004_add_user_agent_to_activity_logs.php` | NEW — idempotent |
| `app/Http/Controllers/OrderReturnController.php` | Refactor `'return_cancel'` string → `ACTION_RETURN_CANCEL` constant |
| `app/Http/Controllers/DamageController.php` | Refactor `'damage_create'`/`'damage_cancel'` → constants |
| `app/Http/Controllers/PurchaseReturnController.php` | Refactor `'purchase_return_create'`/`'purchase_return_cancel'` → constants |
| `app/Services/TaskService.php` | Refactor `'task_warranty_attach'` → `ACTION_TASK_WARRANTY_ATTACH` |
| `app/Http/Controllers/WarrantyController.php` | Thêm `ActivityLog::log(ACTION_WARRANTY_UPDATE, …)` ở `update()` với `old_values`/`new_values`/`changed_fields` |
| `app/Http/Controllers/ActivityLogController.php` | NEW — Inertia `index`, JSON `api` paginated với filters, `actionTypes` map |
| `routes/web.php` | NEW route `GET /activity-logs` middleware `permission:system.audit.view` |
| `routes/api.php` | NEW prefix `/api/activity-logs` group middleware `permission:system.audit.view` (2 endpoints) |
| `tests/Feature/Security/Step240CActivityLogViewerTest.php` | NEW — 10 test cases |
| `docs/audit/STEP-24.0C-ACTIVITY-LOG-STANDARDIZATION-VIEWER.md` | File này |

**Không sửa:**

- `User`, `Role` models (24.0B đã thêm `system.audit.view` permission key).
- `CheckPermission` middleware.
- Core nghiệp vụ services (MovingAvgCostingService, StockMovementService, InvoiceSaleService, …).
- UI `Pages/ActivityLogs/Index.vue` (đã có sẵn, đúng API endpoint mới).
- Action keys cũ trong DB (string giữ nguyên, chỉ thêm constant để code đọc dễ).

---

## 7. Tests

### Step240C suite (10 cases)

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_activity_log_constants_have_labels_and_icons` | ✅ PASS |
| 2 | `test_activity_log_log_captures_user_agent_if_column_exists` | ✅ PASS |
| 3 | `test_activity_logs_index_requires_auth` | ✅ PASS (302/401) |
| 4 | `test_user_without_system_audit_view_cannot_access_activity_logs` | ✅ PASS (302/403) |
| 5 | `test_user_with_system_audit_view_can_access_activity_logs` | ✅ PASS (200) |
| 6 | `test_activity_logs_index_filters_by_action` | ✅ PASS |
| 7 | `test_activity_logs_index_filters_by_date_range` | ✅ PASS |
| 8 | `test_activity_logs_index_searches_description` | ✅ PASS |
| 9 | `test_warranty_update_writes_activity_log` | ✅ PASS |
| 10 | `test_activity_log_action_types_endpoint_returns_label_icon_map` | ✅ PASS |

**Tổng:** 10/10 PASS, 47 assertions, 20.16s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step240C\|Step240B\|ActivityLog\|Audit\|Auth\|Permission` | 24 | ✅ 24 PASS (70 assertions) |
| `Step239\|Step238F\|Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 84 | ✅ 84 PASS (270 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 154 + 2 skipped | ✅ 154 PASS (528 assertions) |
| `Step232..Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng regression sau 24.0C:** ~354 PASS, 2 skipped, ~1214 assertions. Không hồi quy.

---

## 8. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` | ✅ Built in 7.16s |
| `php artisan migrate --env=testing --force` | ✅ `2026_05_06_000004_add_user_agent_to_activity_logs` ran 56.30ms |
| `php artisan route:list \| grep activity-logs` | ✅ 3 route đăng ký |

---

## 9. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ✅ 1 file (cột user_agent nullable, idempotent qua hasColumn) |
| Có update log cũ không? | ❌ Không (logs hiện có user_agent NULL) |
| Có đổi action key cũ không? | ❌ Không (string giữ nguyên — chỉ thêm constants ở code) |
| Có route public không? | ❌ Không (`auth` + `permission:system.audit.view`) |
| Có permission `system.audit.view` không? | ✅ Có (đã thêm ở 24.0B) |
| Có ảnh hưởng nghiệp vụ không? | ❌ Không (chỉ thêm log + viewer + refactor string→constant) |
| Logs cũ render đúng label/icon không? | ✅ Có — string key giữ nguyên |

---

## 10. Manual QA sau deploy

- [ ] User chưa login → `/activity-logs` redirect login.
- [ ] User thiếu `system.audit.view` → 302/403.
- [ ] User có `system.audit.view` (hoặc admin) → render UI Index.vue với danh sách logs.
- [ ] Filter theo action (dropdown) hoạt động.
- [ ] Filter theo employee dropdown hoạt động.
- [ ] Filter theo ngày from/to hoạt động.
- [ ] Search description hoạt động.
- [ ] Xem properties JSON (nếu UI có expand).
- [ ] Action mới (`return_cancel`, `damage_create`, `task_warranty_attach`, …) hiển thị label tiếng Việt + icon đúng.
- [ ] Update warranty (PUT `/warranties/{id}`) tạo log `warranty_update` với `properties.changed_fields`.
- [ ] Log mới có cột `user_agent` filled (verify qua DB hoặc UI nếu hiển thị).
- [ ] Logs cũ trước migration vẫn render OK (user_agent NULL).

---

## 11. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | Log call cho `CustomerController::debtPayment` (action `customer_debt_payment` đã có constant) | P3 |
| 2 | Log call cho `CustomerController::debtAdjust` (action `customer_debt_adjust`) | P3 |
| 3 | Log call cho `CustomerController::debtOffset` / `cancelDebtOffset` (action `customer_debt_offset`) | P3 |
| 4 | Export activity logs CSV | P3 |
| 5 | Filter module (group nhiều action thành module — vd "Sales" gồm invoice_*, return_*, …) | P3 |
| 6 | Log role/permission changes (`UserController`/`RoleController`) | P3 |
| 7 | Alert thao tác rủi ro cao (vd batch cancel > N) | P3 |
| 8 | Retention policy / archive logs cũ (vd > 1 năm) | P3 |
| 9 | UI hiển thị `user_agent` (truncate) trong table | P3 |

---

## 12. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| ActivityLog đã chuẩn hóa chưa? | ✅ 10 ACTION_* constants mới + label/icon đầy đủ. Refactor string→constant ở 5 controller + 1 service. Action keys cũ trong DB không đổi. |
| Viewer đã dùng được chưa? | ✅ Route `GET /activity-logs` (Inertia) + `GET /api/activity-logs` (paginated JSON) + `GET /api/activity-logs/action-types`. UI Index.vue đã có sẵn, gọi đúng endpoint. |
| Có an toàn production không? | ✅ Migration idempotent, không backfill. `permission:system.audit.view` chặn truy cập. Không đổi action key cũ — logs cũ render đúng. |
| Có thể deploy không? | ✅ Có. `composer dump-autoload && php artisan migrate --force && php artisan optimize:clear && route:cache && view:cache && config:cache && npm run build && queue:restart`. Không cần grant permission mới (24.0B đã thêm `system.audit.view` từ trước). |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc |
| `docs/audit/STEP-24.0-PERMISSION-AUDIT-LOG-DISCOVERY.md` | 24.0A discovery + log gap fixes |
| `docs/audit/STEP-24.0B-RBAC-PERMISSION-ENFORCEMENT.md` | 24.0B RBAC enforcement (đã thêm `system.audit.view` permission) |
| `docs/audit/STEP-24.0C-ACTIVITY-LOG-STANDARDIZATION-VIEWER.md` | File này |
| `app/Models/ActivityLog.php` | Constants/labels/icons + user_agent capture |
| `app/Http/Controllers/ActivityLogController.php` | Inertia + JSON viewer |
| `database/migrations/2026_05_06_000004_add_user_agent_to_activity_logs.php` | Migration |
| `tests/Feature/Security/Step240CActivityLogViewerTest.php` | 10 test cases |
