# STEP 24.1 — Operational Dashboard

> **Bước:** 24.1 — Mở rộng Dashboard hiện có thành Dashboard kiểm soát vận hành
> **Ngày:** 06/05/2026
> **Phạm vi:** Backend service + Dashboard controller props + Vue UI block + Tests. **Read-only — KHÔNG mutate DB.**

---

## 1. Discovery

| Nhóm | Dữ liệu hiện có | File/model | Dashboard hiện đã có? | Cần thêm |
|---|---|---|---|---|
| Doanh thu / lợi nhuận | `MetricService::compute(start, end)` (gross_revenue, cogs_net, gross_profit) + `revenueChart 30d` | `Invoice`, `MetricService` | ✅ Có | Không thêm |
| Công nợ khách/NCC | `Customer.debt_amount`, `Customer.supplier_debt_amount` | `Customer` | ✅ Có | Bổ sung `repair_debt_total` riêng cho external repair |
| Tồn kho | `Product.stock_quantity`, `inventoryProducts`, `lowStockProducts` | `Product` | ✅ Có | Thêm `negative_stock_count`, `serial_stock_mismatch` |
| Serial/IMEI | `SerialImei.status` ENUM (in_stock/sold/in_transit/used_for_repair/dismantled/...) | `SerialImei` + `SerialAvailabilityService` | ❌ Chưa | ✅ Mới — count theo status, latest_in_transit list |
| Chuyển kho | `StockTransfer.status` (draft/transferring/received/cancelled), `sent_date`, `created_at` | `StockTransfer` | ❌ Chưa | ✅ Mới — transferring count, aging >24h/72h, latest list |
| Repair | `Task.type=repair`, `external`, `status`, `debt_amount`, `warranty_covered_amount` | `Task` | ❌ Chưa | ✅ Mới — open count, debt total, warranty covered, pending warranty attach |
| Warranty | `Warranty.warranty_end_date` | `Warranty` | ❌ Chưa | ✅ Mới — valid/expired/expiring 30d/7d count, latest expiring |
| ActivityLog | `ActivityLog.action` (Step 24.0C có 45+ ACTION_*) | `ActivityLog` | ❌ Chưa | ✅ Mới — high-risk count today/7d, latest 10 logs (gated by `system.audit.view`) |

---

## 2. Backend metrics

| Object | Metrics | Query source |
|---|---|---|
| `serialControl` | `in_transit_count`, `used_for_repair_count`, `dismantled_count`, `defective_count`, `returning_count`, `returned_count`, `latest_in_transit[]` | `SerialImei` GROUP BY status |
| `stockTransferControl` | `transferring_count`, `transferring_over_24h_count`, `transferring_over_72h_count`, `serial_transfer_in_transit_count`, `latest_transferring_transfers[]` (kèm `age_hours`) | `StockTransfer` where status=transferring + sub-query on items.serial_ids |
| `repairControl` | `external_open_count`, `internal_open_count`, `completed_this_month_count`, `repair_debt_total`, `warranty_covered_this_month`, `pending_warranty_attach_count`, `latest_open_repairs[]` | `Task` where type=repair |
| `warrantyControl` | `valid_count`, `expired_count`, `expiring_30_days_count`, `expiring_7_days_count`, `unknown_count`, `latest_expiring_warranties[]` | `Warranty` where warranty_end_date BETWEEN |
| `inventoryRisk` | `negative_stock_count`, `negative_stock_products[]`, `serial_stock_mismatch_count`, `serial_mismatch_products[]` | `Product` + `SerialImei` GROUP BY product_id (limit 200 has_serial products) |
| `financeControl` | `total_customer_debt`, `total_supplier_debt`, `repair_debt_total`, `cash_receipts_today`, `cash_payments_today`, `net_cash_today` | `Customer`, `Task`, `CashFlow` |
| `highRiskActivities` | `visible`, `count_today`, `count_7_days`, `latest_logs[]` | `ActivityLog` whereIn HIGH_RISK_ACTIONS |

**Hiệu năng:**
- Mọi count/sum dùng aggregate query, không loop full dataset.
- Inventory mismatch: limit 200 product `has_serial=true` mới nhất + 1 GROUP BY query trên `serial_imeis`. Sau đó so sánh trong PHP, không N+1.
- Latest lists giới hạn 10 entries.
- Stock transfer: eager load `fromBranch:id,name`, `toBranch:id,name`. Activity logs: eager load `user:id,name`, `employee:id,name,code`.
- Tổng số query thêm vào dashboard: ~13-15 SELECT, không có UPDATE/INSERT/DELETE.

### High-risk action keys (Step 24.1)

```
invoice_cancel, return_cancel, purchase_delete, purchase_return_cancel,
damage_cancel, stocktake_complete, stocktake_cancel, transfer_cancel,
part_disassemble, task_complete, task_warranty_attach, warranty_update,
customer_debt_adjust
```

---

## 3. Permission

| Dữ liệu | Permission |
|---|---|
| Dashboard chung (route `/`) | `dashboard.view` (giữ nguyên) |
| Operational metrics tổng (cards, lists trừ activity) | `dashboard.view` |
| Activity log chi tiết (`latest_logs`) | `system.audit.view` (Step 24.0B). Nếu thiếu → `visible=false`, `latest_logs=[]`, vẫn show count tổng để user biết có hoạt động |
| `canViewAuditLog` prop | `auth()->user()->hasPermission('system.audit.view')` |

---

## 4. UI implemented

| Khu vực | Nội dung |
|---|---|
| Operational cards (8 cards) | Serial in_transit / used_for_repair / dismantled / Chuyển kho chờ nhận / Repair đang mở / Công nợ sửa chữa / Bảo hành sắp hết hạn / Sản phẩm rủi ro tồn |
| Warning lists (4 tables) | Chuyển kho chờ nhận (kèm `age_hours` highlight đỏ >72h, vàng >24h), Repair đang mở (kèm badge external/internal), Bảo hành sắp hết hạn ≤30d, Sản phẩm lệch serial |
| Quick links | `/products`, `/tasks`, `/stock-transfers`, `/warranties`, `/activity-logs` (chỉ link nếu `canViewAuditLog`) |
| High-risk activities | Block đỏ với count today/7d + table latest 10 logs (chỉ render đầy đủ nếu `canViewAuditLog=true`) |
| Empty states | "Không có cảnh báo" trong mỗi list khi rỗng |
| Color scheme | Đỏ cho rủi ro cao (>72h, expiring 7d, mismatch), vàng cho cần theo dõi (>24h, expiring 30d, in_transit), xám/xanh cho bình thường |

UI đặt sau toàn bộ block dashboard hiện có — không phá bố cục cũ.

---

## 5. Files changed

| File | Nội dung |
|---|---|
| `app/Support/Reports/OperationalDashboardService.php` | NEW — 7 method aggregate metrics |
| `app/Http/Controllers/DashboardController.php` | EDIT — inject service + thêm 8 props |
| `resources/js/Pages/Dashboard/Index.vue` | EDIT — thêm props mới + section "Kiểm soát vận hành" với cards + tables + permission-gated activity block |
| `tests/Feature/Dashboard/Step241OperationalDashboardTest.php` | NEW — 11 test cases |
| `docs/audit/STEP-24.1-OPERATIONAL-DASHBOARD.md` | NEW — file này |

**Không sửa:**

- `MetricService` (giữ nguyên KPI cũ).
- Models (`Product`, `SerialImei`, `Task`, `Warranty`, `StockTransfer`, `ActivityLog`, …).
- Migrations.
- Routes/middleware (`/` vẫn `permission:dashboard.view`).
- Core nghiệp vụ services.

---

## 6. Tests

### Step241 suite (11 cases)

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_dashboard_requires_dashboard_view_permission` | ✅ PASS (302/403) |
| 2 | `test_admin_can_view_dashboard` | ✅ PASS |
| 3 | `test_dashboard_includes_operational_props` | ✅ PASS (Inertia assert 8 props) |
| 4 | `test_serial_control_counts_statuses` | ✅ PASS |
| 5 | `test_inventory_risk_detects_serial_stock_mismatch` | ✅ PASS (diff=3) |
| 6 | `test_stock_transfer_control_counts_transferring_and_aging` | ✅ PASS (>24h, >72h) |
| 7 | `test_repair_control_counts_open_external_and_repair_debt` | ✅ PASS |
| 8 | `test_warranty_control_counts_expiring_warranties` | ✅ PASS (expired+expiring 7d/30d+valid) |
| 9 | `test_high_risk_activities_hidden_without_system_audit_view` | ✅ PASS (`visible=false`, latest_logs=[]) |
| 10 | `test_high_risk_activities_visible_with_system_audit_view` | ✅ PASS (`visible=true`) |
| 11 | `test_dashboard_does_not_mutate_inventory_or_serials` | ✅ PASS (snapshot stock/cost/serial count trước/sau 2 lần GET) |

**Tổng:** 11/11 PASS, 76 assertions, 21.22s.

### Regression clusters

| Cluster | Tests | Result |
|---|---:|---|
| `Step241\|Dashboard` | 11 | ✅ 11 PASS (76 assertions) |
| `Step240C\|Step240B\|Step239\|Step238F\|Step238E\|Step238D\|Step238C\|Step238B\|Step238A\|Step237B\|Warranty` | 105 | ✅ 105 PASS (341 assertions) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 154 + 2 skipped | ✅ 154 PASS (528 assertions) |
| `Step232..Step237` | 87 | ✅ 87 PASS (298 assertions) |
| `RR02InvoicePosCharacterizationTest` (chạy riêng) | 5 | ✅ 5 PASS (48 assertions) |

**Tổng:** ~362 PASS, 0 FAIL, 2 skipped, ~1291 assertions. Không hồi quy.

---

## 7. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE 6/6 |
| `npm run build` (Vite) | ✅ Built in 6.44s |
| `php artisan migrate --env=testing --force` | (không có migration mới) |

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu cũ không? | ❌ Không |
| Dashboard có mutate DB không? | ❌ Không (TC-11 verify: stock_quantity/inventory_total_cost/serial count không đổi sau 2 lần GET) |
| Có query nặng không? | ❌ Không nặng — tổng ~13-15 aggregate query, mismatch limit 200 product, latest list limit 10. Tất cả đều có index sẵn (`status`, `created_at`, `subject_type+subject_id`, `action`) |
| Có lộ activity log cho user không quyền không? | ❌ Không. `highRiskActivities.visible=false` + `latest_logs=[]` cho user thiếu `system.audit.view`; chỉ trả count tổng (không có description/IP/user_name) |
| Có thay đổi route/middleware không? | ❌ Không (`/` vẫn `permission:dashboard.view`) |
| Có ảnh hưởng nghiệp vụ không? | ❌ Không (read-only service) |

---

## 9. Manual QA sau deploy

- [ ] Admin (`role_id=NULL` hoặc `*`) vào `/` thấy section "Kiểm soát vận hành" với 8 cards + 4 tables.
- [ ] User thiếu `dashboard.view` → 302/403.
- [ ] Tạo serial `in_transit` (qua chuyển kho) → card "Serial đang chuyển kho" hiển thị +1.
- [ ] Repair add part `has_serial` → card "Serial đang dùng sửa chữa" hiển thị +1.
- [ ] Disassemble từ máy → card "Serial đã bóc tách" hiển thị +1.
- [ ] StockTransfer `transferring` >24h → card "Chuyển kho chờ nhận" highlight vàng/đỏ + table list age_hours đúng.
- [ ] Repair external open → card "Repair đang mở" + table latest_open_repairs có badge "Khách ngoài".
- [ ] Repair external có `debt_amount > 0` → card "Công nợ sửa chữa" hiển thị tổng đúng.
- [ ] Warranty với `warranty_end_date` trong 30 ngày → card "Bảo hành sắp hết hạn" + table latest_expiring_warranties.
- [ ] Product `has_serial=true` lệch serial in_stock vs stock_quantity → card "Sản phẩm rủi ro tồn" + table mismatch.
- [ ] User có `system.audit.view` → block "⚠ Thao tác rủi ro cao" hiển thị đầy đủ với latest 10 logs.
- [ ] User thiếu `system.audit.view` → block hiển thị message "Cần quyền `system.audit.view` để xem chi tiết" + count tổng.
- [ ] GET `/` 2 lần liên tiếp: `SELECT * FROM products` cho 1 sample product → `stock_quantity` không đổi.
- [ ] Click vào quick link `/stock-transfers`, `/tasks`, `/warranties`, `/products`, `/activity-logs` (nếu có quyền) → navigate đúng.

---

## 10. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | Export operational dashboard CSV/PDF | P3 |
| 2 | Drill-down từng card → trang report chi tiết với filter | P3 |
| 3 | Báo cáo serial in_transit aging riêng (>72h, sender vs receiver branch) | P3 |
| 4 | Báo cáo chi phí bảo hành/sửa chữa theo kỳ (sum `warranty_covered_amount`) | P3 |
| 5 | Alert realtime/email khi `transferring_over_72h_count > 0` hoặc `negative_stock_count > 0` | P3 |
| 6 | Cache dashboard metrics (Redis 1-5 min) nếu data lớn để giảm load | P3 |
| 7 | Dashboard layout responsive cho mobile | P3 |
| 8 | Chart trend cho high-risk activities (count theo ngày 30d) | P3 |
| 9 | Filter dashboard theo branch_id (chỉ xem vận hành của 1 chi nhánh) | P3 |

---

## 11. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Dashboard vận hành đã dùng được chưa? | ✅ Có. 8 cards + 4 warning tables + high-risk activity block (gated). Quick links sang trang chi tiết. |
| Có an toàn production không? | ✅ Có. Read-only — không mutate DB (TC-11 verify). Permission gate giữ nguyên. Activity log chi tiết bị che cho user thiếu `system.audit.view`. Hiệu năng OK với index hiện có. |
| Có thể deploy không? | ✅ Có. Không migration. Deploy chỉ cần `git pull && composer dump-autoload && npm run build && optimize:clear && config:cache && route:cache && view:cache && queue:restart`. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc — task này read-only, tuân thủ mục 1.10 + mục 9 |
| `docs/audit/STEP-24.0-PERMISSION-AUDIT-LOG-DISCOVERY.md` | 24.0A — log gap fixes (đã thêm log cho các action high-risk) |
| `docs/audit/STEP-24.0B-RBAC-PERMISSION-ENFORCEMENT.md` | 24.0B — `system.audit.view` permission key |
| `docs/audit/STEP-24.0C-ACTIVITY-LOG-STANDARDIZATION-VIEWER.md` | 24.0C — ActivityLog standardization (constants/labels/icons) |
| `docs/audit/STEP-24.1-OPERATIONAL-DASHBOARD.md` | File này |
| `app/Support/Reports/OperationalDashboardService.php` | Read-only service |
| `app/Support/Reports/MetricService.php` | KPI cũ (không sửa) |
| `tests/Feature/Dashboard/Step241OperationalDashboardTest.php` | 11 test cases |
