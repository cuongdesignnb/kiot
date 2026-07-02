# HOTFIX 24.4A-3 — Customer Group create/select/sidebar flow

## 1. Root cause

| Vấn đề | Nguyên nhân |
|---|---|
| Tạo mới group không được | Toàn bộ block JS modal (`showGroupModal`, `groupForm`, `openGroupModal`, `submitGroupModal`, mode computeds) nằm **sau** `</script>` ở line 546 → Vue SFC compiler coi như orphan template text, các identifier không tồn tại trên component context. Nút "Tạo mới" wire vào `openGroupModal` nhưng symbol `undefined` nên click bị nuốt. |
| Tạo khách chưa chọn được group | Customer create modal (line 2408) và edit modal (line 2738) dùng `<input type="text">` cho `customer_group` thay vì `<select>` từ master groups. |
| Sidebar vỡ layout | `<aside>` của `AppLayout` chỉ có `overflow-y-auto` (không có `overflow-x-hidden`). Các block date/number range dùng `flex gap-2` + `w-1/2` — native date input có intrinsic min-width ~120px, hai input ép sát ở sidebar 256px sinh horizontal scrollbar. |
| Reload sau create không cập nhật dropdown | `submitGroupModal` cũ chỉ alert generic và `router.reload({ only: ['filterOptions'] })`, không có local optimistic state — nếu Inertia partial reload chậm hoặc lỗi, group mới không xuất hiện. |

## 2. Fixed flow

| Flow | Kết quả |
|---|---|
| Create group from sidebar | ✅ Nút "Tạo mới" → modal mở; submit POST `/customer-groups`; success ⇒ optimistic add vào `localCustomerGroups` + `reloadCustomerGroups()` (axios GET options + Inertia partial reload) |
| Reload group options | ✅ `reloadCustomerGroups()` gọi GET `/customer-groups/options` → merge vào `localCustomerGroups`, đồng thời `router.reload({ only: ['filterOptions'] })` |
| Select group in customer create modal | ✅ Dropdown `<select>` populate từ `mergedCustomerGroups` (backend + local) |
| Select group in customer edit modal | ✅ Cùng dropdown, load `customer.customer_group` làm selected value |
| Filter customers by group | ✅ Sidebar dropdown submit `customer_group=name`, backend filter qua scalar `customer_group` column (không đổi schema) |

## 3. Permission

| Route | Permission | Ghi chú |
|---|---|---|
| GET `/customer-groups/options` | auth (no permission middleware) | Mọi user đăng nhập có thể đọc danh sách nhóm để render dropdown |
| POST `/customer-groups` | `permission:customers.edit` | Giữ nguyên — admin (wildcard `*`) pass; user chỉ có `customers.view` ⇒ 403 |
| PUT `/customer-groups/{id}` | `permission:customers.edit` | Giữ nguyên |

UI: nếu user nhận 403 khi submit, modal hiển thị message "Bạn không có quyền tạo nhóm khách hàng." trong banner đỏ trên cùng (không alert generic).

## 4. UI layout

| Block | Fix |
|---|---|
| Sidebar wrapper | `AppLayout.vue` aside thêm `overflow-x-hidden` (đi kèm `overflow-y-auto` hiện có) |
| Horizontal scroll trong các filter pair "Từ/Tới" | 6 block đổi từ `flex gap-2` + input `w-1/2` → `grid grid-cols-2 gap-2` + input `w-full min-w-0` (cho phép flex/grid shrink dưới intrinsic min-width của native date/number) |
| Unsupported filters | Đã ẩn từ HOTFIX 24.4A-2 (template dùng `hasCapability(...)`); supportsDebtDaysFilter/supportsPointsFilter ở backend = `false` ⇒ block không render |
| Group dropdown sidebar | `mergedCustomerGroups` (backend + local optimistic) thay cho `filterCustomerGroups` để group mới tạo hiện ngay |
| Create customer modal group field | Dropdown `<select>` với `mergedCustomerGroups` (placeholder "-- Chọn nhóm khách hàng --") |
| Edit customer modal group field | Dropdown `<select>` với `mergedCustomerGroups`, load current value |
| Group create modal errors | Banner đỏ generic + inline red text dưới field `name`/`code` cho 422 errors |

## 5. Files changed

| File | Nội dung |
|---|---|
| `resources/js/Pages/Customers/Index.vue` | Hoist modal JS vào trong `<script setup>` block (delete stray `</script>` at line 546). Thêm `localCustomerGroups`, `mergedCustomerGroups`, `groupErrors`, `reloadCustomerGroups()`. Cải thiện `submitGroupModal` xử lý 403/422/500. Sidebar: 6 cặp filter range chuyển sang grid + min-w-0. Customer create/edit modal: text input → select dropdown. Inline error UI cho group modal. Bỏ alias `capabilities` không dùng. |
| `resources/js/Layouts/AppLayout.vue` | Aside thêm `overflow-x-hidden` |
| `tests/Feature/Filters/Step244ACustomerGroupUiFlowTest.php` | NEW — 11 test cases |
| `docs/audit/STEP-24.4A-CUSTOMER-GROUP-UI-FLOW-FIX.md` | NEW — file này |

**Không sửa:** Backend controller `CustomerController`/`CustomerGroupController` (đã đúng), schema `customers.customer_group`, routes, công nợ, merge KH/NCC, lịch sử giao dịch, model relations.

## 6. Tests

| Test | Kết quả |
|---|---|
| TC-01 `customer_group_options_route_returns_active_groups` | ✅ |
| TC-02 `create_customer_group_returns_json_group` | ✅ |
| TC-03 `create_customer_group_percent_over_100_fails` | ✅ (422) |
| TC-04 `create_customer_group_duplicate_name_fails` | ✅ (422) |
| TC-05 `create_customer_with_existing_group_name_saves_customer_group_string` | ✅ |
| TC-06 `update_customer_group_from_dropdown_saves_string` | ✅ |
| TC-07 `customers_index_filter_options_include_master_groups_after_create` | ✅ |
| TC-08 `customers_filter_by_group_after_customer_created_with_group` | ✅ |
| TC-09 `customer_group_create_does_not_mutate_existing_customers` | ✅ — chứng minh không có mutation hàng loạt |
| TC-10 `unsupported_filter_capabilities_are_false_or_hidden_policy` | ✅ |
| TC-11 `create_customer_group_without_permission_returns_403` | ✅ — guard `permission:customers.edit` |

## 7. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ Built in 6.30s |
| `php artisan test --filter="Step244A\|CustomerGroupUiFlow\|CustomerFiltersHotfix\|CustomerGroup"` | ✅ **33 PASS** (388 assertions) — 4 hotfix-1 + 11 ui-flow + 18 sidebar |
| Regression cluster (Step232–243, RR02–13, Warranty, Order, Purchase, ...) | ✅ **285 PASS**, 3 skipped (pre-existing), 0 fail |

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** — schema `customer_groups` đã tồn tại từ STEP 24.4A; `customers.customer_group` vẫn là string column |
| Có update customers cũ không? | **Không** (TC-09 chứng minh) |
| Có tự động gán group không? | **Không** — engine 24.4B chưa làm; modal chỉ lưu cấu hình `conditions/update_mode/auto_update` |
| Có ảnh hưởng công nợ không? | **Không** |
| Có ảnh hưởng merge khách/NCC không? | **Không** |
| Có hardcode group trong Vue không? | **Không** — dropdown bind `mergedCustomerGroups` từ backend + state |

## 9. Manual QA sau deploy

- [ ] Bấm "Tạo mới" nhóm khách hàng mở modal.
- [ ] Nhập tên nhóm + Lưu thành công (không còn alert silent).
- [ ] Thử nhập trùng tên ⇒ inline error đỏ dưới field.
- [ ] Thử % > 100 ⇒ banner đỏ trên cùng.
- [ ] Thử user không có `customers.edit` ⇒ banner "Bạn không có quyền tạo nhóm khách hàng."
- [ ] Group mới xuất hiện ở sidebar filter ngay sau khi đóng modal.
- [ ] Group mới xuất hiện trong modal Tạo khách hàng (dropdown).
- [ ] Tạo khách chọn group mới ⇒ DB lưu đúng `customer_group = group.name`.
- [ ] Sửa khách đổi group ⇒ DB lưu đúng.
- [ ] Lọc theo group ở sidebar trả đúng tập khách.
- [ ] Sidebar không còn scrollbar ngang ở mọi viewport.
- [ ] Filter "Số ngày nợ" / "Điểm hiện tại" không hiện (capability=false).
- [ ] Không có lỗi Vue/JS console.

## 10. Conclusion

- **Tạo nhóm đã hoạt động chưa:** Có — root cause là JS block nằm ngoài `<script setup>`, đã hoist vào.
- **Tạo khách đã chọn được group chưa:** Có — replaced text input bằng `<select>` populate từ `mergedCustomerGroups`.
- **Sidebar đã ổn chưa:** Có — `overflow-x-hidden` trên aside + grid cols + min-w-0 trên input ranges.
- **Có thể deploy production chưa:** Có — 33+285 tests pass, không migration, không mutation customer cũ, không đụng công nợ/merge.
