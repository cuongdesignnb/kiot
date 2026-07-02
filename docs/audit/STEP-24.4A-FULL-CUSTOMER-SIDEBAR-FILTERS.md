# STEP 24.4A — Full KiotViet-style Customer Sidebar Filters

> **Bước:** 24.4A — Chuẩn hóa toàn bộ sidebar Khách hàng theo KiotViet thật
> **Ngày:** 07/05/2026
> **Phạm vi:** Migration + Model + Controller + Vue UI + Tests. **Backend là source of truth cho filter options.**

---

## 1. Root cause

- Sidebar Khách hàng trước 24.4A chỉ có filter cơ bản: `type`, `gender`, `customer_group` (string), `branch_id`, `city`, `district`.
- Không có master `customer_groups` table — group là string distinct từ `customers.customer_group`.
- Không có `customers.created_by` → không filter người tạo.
- Không có capability gating — UI có thể render filter không backend support.
- Filter "Tổng bán theo thời gian", "Số ngày nợ", "Điểm" thiếu schema/service.
- Loại đối tác (`partner_type`), Net debt range chưa có.

---

## 2. KiotViet reference from screenshot

| Filter | Có trong Kiot | Đã implement | Nếu chưa, lý do |
|---|---|---|---|
| Nhóm khách hàng | ✅ | ✅ | Master `customer_groups` + legacy distinct merge |
| Loại khách hàng (Cá nhân/Công ty) | ✅ | ✅ | `customers.type` |
| Giới tính | ✅ | ✅ | `customers.gender` |
| Sinh nhật | ✅ | ✅ | `customers.birthday` (date range) |
| Ngày giao dịch cuối | ✅ | ✅ | Subquery `MAX(invoices.transaction_date)` |
| Tổng bán | ✅ | ✅ | `total_spent` lifetime hoặc subquery sum theo `transaction_date` |
| Thời gian tổng bán | ✅ | ✅ | Khi có `total_sales_date_*` thì query invoices theo range |
| Nợ hiện tại | ✅ | ✅ | `(debt_amount - supplier_debt_amount)` net debt |
| Số ngày nợ | ✅ | ❌ | Backend chưa có due_date trên `customer_debts`. Capability `supportsDebtDaysFilter=false`. Backlog: debt aging engine |
| Điểm hiện tại | ✅ | ❌ | Không có schema loyalty/points. Capability `false`. Backlog |
| Khu vực giao hàng | ✅ | ✅ | `customers.city` distinct (`delivery_city` filter) |
| Trạng thái (Đang hoạt động/Ngừng) | ✅ | ✅ | `customers.status` whereIn |
| Loại đối tác (KH/KH-NCC) | ✅ | ✅ | `partner_type` filter |
| Người tạo | ✅ | ✅ | `customers.created_by` (column mới) + filter |
| Chi nhánh tạo | ✅ | ✅ | `customers.branch_id` + branch lock |

---

## 3. Backend capabilities

| Capability | True/False | Source |
|---|---|---|
| `supportsBirthdayFilter` | ✅ true | `customers.birthday` exists |
| `supportsLastTransactionFilter` | ✅ true | Subquery `MAX(invoices.transaction_date)` |
| `supportsTotalSalesTimeFilter` | ✅ true | Subquery `SUM(invoices.total)` theo `transaction_date` |
| `supportsDebtDaysFilter` | ❌ false | Schema `customer_debts` không có due_date / aging engine |
| `supportsPointsFilter` | ❌ false | Không có loyalty/points schema |
| `supportsDeliveryAreaFilter` | ✅ true | `customers.city/district/ward` |
| `supportsCreatedByFilter` | ✅ true (sau migration) | `Schema::hasColumn('customers', 'created_by')` |

UI dựa `capabilities.*` để render/ẩn filter. Test TC-18 verify.

---

## 4. CustomerGroup schema

| Field | Ý nghĩa |
|---|---|
| `id` | PK |
| `code` | nullable unique — mã nhóm |
| `name` | unique — tên nhóm hiển thị |
| `discount_type` | nullable: `amount` \| `percent` |
| `discount_value` | decimal(15,2) default 0 |
| `note` | text nullable |
| `description` | text nullable |
| `conditions` | JSON nullable — auto-assign config (chỉ lưu, chưa enforce ở 24.4A) |
| `update_mode` | `none` \| `add_matching` \| `refresh_matching` (default `none`) |
| `auto_update` | boolean default false |
| `is_active` | boolean default true |
| `sort_order` | int default 0 |
| `created_by` | FK users.id nullable |
| `timestamps` | |

**Quan trọng:** 24.4A chỉ tạo group + lưu config. **KHÔNG** auto-assign customers theo conditions. **KHÔNG** mutate `customers` hàng loạt. 24.4B sẽ làm engine.

---

## 5. FilterOptions

| Option | Source |
|---|---|
| `customerGroups` | `CustomerGroup` master (active) **UNION** distinct `customers.customer_group` legacy không trùng |
| `types` | Hardcode `['individual', 'company']` (Vietnamese label) |
| `genders` | Hardcode `['male', 'female', 'none']` |
| `branches` | `Branch::all()`, hoặc chỉ branch của user nếu `customer_manage_by_branch=true` |
| `creators` | Users xuất hiện trong `customers.created_by` distinct |
| `statuses` | Hardcode `['active', 'inactive']` |
| `partnerTypes` | Hardcode `['customer', 'customer_supplier']` |
| `deliveryCities` | distinct `customers.city` |
| `debtOptions` | Hardcode `['yes', 'no']` (legacy shortcut) |
| `capabilities` | Object 7 boolean flags |

---

## 6. Backend filter rules

| Query param | Rule |
|---|---|
| `customer_group` | `where customer_group = ?` (string match) |
| `type` | `where type = ?` (individual/company) |
| `gender` | `where gender = ?` |
| `status[]` | `whereIn status` (array) |
| `partner_type` | `customer`: is_customer=true AND (is_supplier=false OR null); `customer_supplier`: is_customer=true AND is_supplier=true |
| `birthday_from`/`to` | `whereDate birthday BETWEEN` |
| `last_transaction_from`/`to` | Subquery `MAX(COALESCE(invoices.transaction_date, invoices.created_at))` whereColumn `customer_id = customers.id` |
| `total_sales_from`/`to` (no time) | `where total_spent BETWEEN` (lifetime) |
| `total_sales_from`/`to` + `total_sales_date_from`/`to` | Subquery `COALESCE(SUM(invoices.total), 0)` filtered by `transaction_date` BETWEEN — **không** dùng `total_spent` lifetime khi có time scope |
| `net_debt_from`/`to` | `where (COALESCE(debt_amount, 0) - COALESCE(supplier_debt_amount, 0)) BETWEEN` |
| `has_debt=yes/no` | Legacy shortcut dùng net debt |
| `delivery_city` | `where city = ?` |
| `delivery_district` | `where district = ?` |
| `created_by` | `where created_by = ?` (qua FilterableIndex creatorColumn) |
| `branch_id` | `where branch_id = ?` (qua FilterableIndex scalar) |
| `date_filter=custom&date_from`/`date_to` | `where created_at BETWEEN` (qua FilterableIndex) |
| `search` | `searchable` columns: code, name, phone, phone2, email, tax_code |
| `sort_by`/`sort_dir` | qua FilterableIndex |
| **`debt_days_*` / `points_*`** | **Không enforce** ở 24.4A. UI ẩn filter qua `capabilities` |

---

## 7. UI implemented (Customers/Index.vue)

| UI block | Kết quả |
|---|---|
| Customer group searchable dropdown | ✅ + nút "Tạo mới" |
| Create group modal | ✅ 2 tab: "Thông tin" + "Thiết lập nâng cao" (qua `POST /customer-groups`) |
| Type pills (Cá nhân/Công ty) | ✅ |
| Gender pills (Nam/Nữ) | ✅ |
| Birthday filter (Toàn thời gian/Tùy chỉnh + date range) | ✅ (gated `supportsBirthdayFilter`) |
| Last transaction filter | ✅ (gated `supportsLastTransactionFilter`) |
| Total sales filter (range + thời gian) | ✅ (time scope gated `supportsTotalSalesTimeFilter`) |
| Net debt filter | ✅ (cho phép âm) |
| Debt days filter | ❌ Ẩn qua capability |
| Points filter | ❌ Ẩn qua capability |
| Delivery area filter | ✅ Dropdown từ `deliveryCities` distinct |
| Status filter (Active/Inactive) | ✅ |
| Partner type filter | ✅ |
| Creator filter | ✅ Dropdown từ `creators` |
| Branch filter | ✅ Respect branch lock |

UI rules:
- Mọi filter qua URL query → pagination giữ query.
- Không hardcode option nếu backend trả options.
- Filter nào `capabilities.*=false` thì `<div v-if>` không render → không có filter giả.
- Clear filter reset đúng.

---

## 8. Summary policy

- `summary.total_debt` = `SUM(debt_amount where > 0)` **theo filtered query** (clone trước paginate).
- `summary.total_spent` = `SUM(total_spent)` **theo filtered query**.
- `summary.total_returns` = `SUM(total_returns)` **theo filtered query**.
- Không tính summary sau paginate.
- Khi có `total_sales_date_*` filter, summary vẫn dùng `total_spent` lifetime — vì summary là rollup của customers, không phải invoice. Filter quyết định **danh sách customer matched**, summary tính lifetime values trên matched customers. Test TC-14 verify.

---

## 9. Migration

| Table | Field | Nullable/default | Backfill |
|---|---|---|---|
| `customer_groups` (NEW) | `id`, `code`, `name`, `discount_type`, `discount_value`, `note`, `description`, `conditions` JSON, `update_mode`, `auto_update`, `is_active`, `sort_order`, `created_by` FK users, `timestamps` | All idempotent qua `Schema::hasTable` | Không (master mới, rỗng) |
| `customers` | `created_by` FK users.id nullable | idempotent qua `Schema::hasColumn` | **Không** backfill — legacy customers giữ `created_by = NULL` |

File: `database/migrations/2026_05_07_100000_create_customer_groups_add_created_by_to_customers.php`. Idempotent.

**Quan trọng:** Không update customers cũ. Không tự động gán `created_by` cho legacy. Không mutate hàng loạt.

---

## 10. Files changed

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_07_100000_create_customer_groups_add_created_by_to_customers.php` | NEW migration |
| `app/Models/CustomerGroup.php` | NEW model |
| `app/Models/Customer.php` | Thêm `created_by` vào fillable + relation `creator()` |
| `app/Http/Controllers/CustomerGroupController.php` | NEW (options, store, update) |
| `app/Http/Controllers/CustomerController.php` | Thêm `applyAdvancedCustomerFilters()` 90+ dòng (partner_type, net_debt, has_debt, birthday, last_transaction, total_sales lifetime/time, delivery area). `buildCapabilities()`. Mở rộng `filterOptions` với customerGroups (master+legacy), creators, deliveryCities, partnerTypes, statuses, capabilities. Summary từ filtered query (clone). `store()` set `created_by = auth()->id()`. |
| `routes/web.php` | Thêm 3 route customer-groups (options/store/update) |
| `resources/js/Pages/Customers/Index.vue` | Sidebar đầy đủ KiotViet-style với capability gating, modal tạo group 2 tab, pills type/gender/status/partner_type, range inputs, custom date pickers |
| `tests/Feature/Filters/Step244AFullCustomerSidebarFiltersTest.php` | NEW 18 test cases |
| `docs/audit/STEP-24.4A-FULL-CUSTOMER-SIDEBAR-FILTERS.md` | NEW file này |

**Không sửa:**
- Core nghiệp vụ services (CustomerDebtService, DebtOffsetService).
- Schema customers ngoài việc thêm `created_by`.
- Logic merge khách/NCC, công nợ, lịch sử bán hàng.

---

## 11. Tests

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_customer_group_options_include_master_and_legacy_groups` | ✅ |
| 2 | `test_create_customer_group_modal_api_saves_info_and_advanced_config` | ✅ (no customer mutation) |
| 3 | `test_customer_filter_by_group_type_gender_status_partner_type` | ✅ (6 sub-assertions) |
| 4 | `test_customer_created_by_filter_and_options` | ✅ |
| 5 | `test_customer_created_date_filter` | ✅ (date_filter=custom + date_from) |
| 6 | `test_customer_birthday_filter` | ✅ |
| 7 | `test_customer_last_transaction_filter_uses_invoice_transaction_date` | ✅ (skip if column missing) |
| 8 | `test_customer_total_sales_range_lifetime_uses_total_spent` | ✅ |
| 9 | `test_customer_total_sales_range_with_time_uses_invoice_sum_not_total_spent` | ✅ |
| 10 | `test_customer_net_debt_filter_uses_debt_minus_supplier_debt` | ✅ |
| 11 | `test_customer_debt_days_capability_is_false` | ✅ |
| 12 | `test_customer_points_capability_is_false` | ✅ |
| 13 | `test_customer_delivery_area_filter_uses_city` | ✅ |
| 14 | `test_customer_summary_uses_filtered_query` | ✅ |
| 15 | `test_branch_lock_limits_query_and_filter_options` | ✅ |
| 16 | `test_unknown_filter_values_do_not_500` | ✅ |
| 17 | `test_pagination_preserves_all_customer_filter_query` | ✅ |
| 18 | `test_no_fake_filters_are_rendered_without_backend_capability` | ✅ (7 capabilities) |

**Tổng:** 18/18 PASS, 273 assertions, 23.74s.

### Regression cluster

| Cluster | Tests | Result |
|---|---:|---|
| `Step244A\|FullCustomerSidebarFilters\|CustomerGroup` | 18 | ✅ 18 PASS (273) |
| `Step242\|Step241\|Step240C\|Step240B\|Step239\|Step238*\|Step237B\|Warranty` | 126 | ✅ 126 PASS (1110) |
| `RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage` | 154 + 2 skipped | ✅ 154 PASS (528) |
| `Step232..Step237` | 87 | ✅ 87 PASS (298) |
| `RR02` (chạy riêng) | 5 | ✅ 5 PASS (48) |

**Tổng regression sau 24.4A:** **390 PASS, 0 FAIL, 2 skipped** (~2257 assertions). Không hồi quy.

---

## 12. Production safety

| Mục | Trạng thái |
|---|---|
| Có update customers cũ không? | ❌ Không |
| Có backfill không? | ❌ Không |
| Có tự động gán nhóm không? | ❌ Không trong 24.4A (24.4B sẽ có engine) |
| Có filter fake không? | ❌ Không (capability gate) |
| Có hardcode group/creator không? | ❌ Không (backend trả options) |
| Có ảnh hưởng công nợ không? | ❌ Không |
| Có ảnh hưởng merge khách/NCC không? | ❌ Không |
| Có query nặng không? | Subquery cho last_transaction + total_sales time-scoped — index trên `invoices.customer_id` + `transaction_date` (đã có từ Step 24.3). Acceptable cho dataset cỡ vừa. |

---

## 13. Manual QA sau deploy

- [ ] Sidebar render đúng 13 filter chính (debt_days/points ẩn).
- [ ] Nhóm khách hàng có search và nút "Tạo mới".
- [ ] Modal tạo nhóm 2 tab giống KiotViet.
- [ ] Lọc nhóm/loại/giới tính/trạng thái/loại đối tác đúng.
- [ ] Lọc sinh nhật đúng.
- [ ] Lọc ngày giao dịch cuối đúng (theo `transaction_date`).
- [ ] Lọc tổng bán lifetime đúng.
- [ ] Lọc tổng bán theo thời gian dùng invoices (không phải total_spent).
- [ ] Lọc nợ hiện tại = debt - supplier_debt (cho phép âm).
- [ ] Lọc khu vực giao hàng (delivery_city).
- [ ] Lọc người tạo (chỉ user có customers).
- [ ] Lọc chi nhánh tạo (respect branch lock nếu setting bật).
- [ ] Summary tổng nợ/tổng bán thay đổi theo filter.
- [ ] Pagination giữ query.
- [ ] Clear filter reset đúng.
- [ ] Tạo customer mới → `created_by` = auth user ID.

---

## 14. Backlog

| # | Mục | Mức |
|---|---|---|
| 1 | 24.4B: Customer Group Condition Engine (auto-assign customers theo conditions + update_mode) | P3 |
| 2 | Debt aging engine (`due_date` + days overdue) → enable `supportsDebtDaysFilter` | P3 |
| 3 | Loyalty points service → enable `supportsPointsFilter` | P3 |
| 4 | Delivery area chuẩn (tỉnh/huyện/xã master data thay distinct string) | P3 |
| 5 | Saved filter views (KiotViet "Lưu bộ lọc") | P3 |
| 6 | Áp dụng pattern này (capability gating) sang Invoices/Orders/Products | P3 |
| 7 | Birthday filter theo MM-DD (sinh nhật trong năm hiện tại) thay vì full date | P3 |
| 8 | Filter "total_returns" range nếu KiotViet có | P3 |

---

## 15. Conclusion

| Câu hỏi | Trả lời |
|---|---|
| Customer sidebar đã đủ KiotViet chưa? | ✅ 13/15 filter chính đã làm. 2 filter (Số ngày nợ, Điểm) ẩn qua capability vì thiếu schema. |
| Filter nào chưa làm vì thiếu schema/service? | Số ngày nợ (cần debt aging engine), Điểm hiện tại (cần loyalty points). |
| Có filter fake nào còn hiển thị không? | ❌ Không. UI strict gate qua `capabilities.*`. |
| Có thể deploy production không? | ✅ Có. Migration idempotent, không backfill. Backward compat: legacy `customers.customer_group` string + new `CustomerGroup` master cùng tồn tại. 390 regression tests vẫn PASS. |

---

## Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc |
| `app/Models/CustomerGroup.php` | NEW master model |
| `app/Http/Controllers/CustomerGroupController.php` | NEW CRUD |
| `app/Http/Controllers/CustomerController.php` | Mở rộng filter logic |
| `database/migrations/2026_05_07_100000_create_customer_groups_add_created_by_to_customers.php` | Migration |
| `tests/Feature/Filters/Step244AFullCustomerSidebarFiltersTest.php` | 18 test cases |
| `docs/audit/STEP-24.4A-FULL-CUSTOMER-SIDEBAR-FILTERS.md` | File này |
