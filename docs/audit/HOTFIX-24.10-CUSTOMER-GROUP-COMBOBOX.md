# HOTFIX 24.10 — Customer Group Combobox

## 1. Root cause

- **Create customer group field:** Modal "+ Khách hàng" trong [Customers/Index.vue:2440](resources/js/Pages/Customers/Index.vue#L2440) dùng `<select>` thường — không có ô search, không có shortcut tạo nhóm mới ngay trong dropdown.
- **Edit customer group field:** Modal "Sửa khách hàng" tại [Customers/Index.vue:2772](resources/js/Pages/Customers/Index.vue#L2772) cùng vấn đề.
- **Existing group flow:** `mergedCustomerGroups`, `localCustomerGroups`, `openGroupModal`, `submitGroupModal`, `reloadCustomerGroups` đã tồn tại từ Step 24.4A — chỉ thiếu UI binding gói gọn lại thành combobox.

## 2. UX policy

| Vị trí | Behavior |
|---|---|
| Create customer | Combobox: search + dropdown master groups + "+ Tạo mới" inline (nếu user gõ tên chưa có thì hiện "+ Tạo mới: <query>") |
| Edit customer | Combobox tương tự, prefill với `customer.customer_group` hiện tại |
| Sidebar filter | **Không đổi** — vẫn `<select>` với `mergedCustomerGroups` (filter list không cần create) |
| Group modal nâng cao | **Giữ nguyên** — sidebar "Tạo mới" link vẫn mở `openGroupModal()`. Combobox cũng emit `create('')` (rỗng) khi user click "Tạo nhóm khách hàng mới" → fallback opens modal advanced cho user fill description/conditions/etc. |

## 3. Component

| File | Purpose |
|---|---|
| `resources/js/Components/CustomerGroupCombobox.vue` | NEW — single-select combobox; v-model = group name string; emits `create(query)` for parent to handle quick-create |

Props: `modelValue`, `groups[]`, `placeholder`, `allowCreate=true`, `disabled=false`, `inputClass`.
Emits: `update:modelValue`, `create`.

UX:
- Click/focus mở dropdown.
- Gõ → filter case-insensitive.
- Selected group hiện check icon; hover row có hover bg.
- Esc + click ngoài đóng.
- Footer: "+ Tạo nhóm khách hàng mới" (rỗng → mở modal advanced); nếu user gõ query chưa match thì hiển thị "+ Tạo mới: \"{query}\"".
- Clear button (×) khi đã chọn + dropdown đóng.

## 4. Backend

| Endpoint | Used for |
|---|---|
| `POST /customer-groups` | Quick-create: `createGroupQuick(name, assign)` gửi payload đầy đủ ({name, code:'', discount_type:'', discount_value:0, ...}) |
| `GET /customer-groups/options` | `reloadCustomerGroups()` sau khi tạo (Step 24.4A đã có) |
| `POST /customers` + `PUT /customers/{id}` | `customer_group` vẫn là string nullable — không thay đổi |

Validation backend không đổi: `customer_group` nullable string. Quick-create handler:
- Tên rỗng → `openGroupModal()` (fallback advanced flow).
- Tên trùng (case-insensitive trong `mergedCustomerGroups`) → chọn group đó, không gọi API.
- 422 duplicate từ backend → reload + select group đã có.
- 403 → alert "Bạn không có quyền tạo nhóm khách hàng.".

## 5. Files changed

| File | Nội dung |
|---|---|
| `resources/js/Components/CustomerGroupCombobox.vue` | NEW — combobox component |
| `resources/js/Pages/Customers/Index.vue` | Import combobox; add `createGroupQuick(name, assign)` + 2 wrapper helpers (`createCustomerGroupAndSelect`, `createCustomerGroupAndSelectForEdit`); replace 2 `<select>` blocks (form Tạo + form Sửa) với `<CustomerGroupCombobox>`. Sidebar filter `<select>` không đổi. |
| `tests/Feature/Customers/Step2410CustomerGroupComboboxTest.php` | NEW — 5 cases |
| `docs/audit/HOTFIX-24.10-CUSTOMER-GROUP-COMBOBOX.md` | NEW — file này |

**Không sửa:** `CustomerController` (validation đã accept string nullable), `CustomerGroupController` (POST/options đã có), schema, sidebar filter UI, group modal advanced, debt service, merge KH/NCC, POS, Supplier.

## 6. Tests

| Test | Result |
|---|---|
| TC-01 customer create accepts existing customer group name | ✅ |
| TC-02 customer update accepts existing customer group name | ✅ |
| TC-03 customer create keeps customer_group nullable | ✅ |
| TC-04 customer-groups/options returns created group | ✅ |
| TC-05 duplicate group name doesn't block customer create với group sẵn có | ✅ — combobox handler chuyển sang select-existing |

Cluster:
- Step2410: ✅ **5 PASS** (15 assertions)
- Customer regression (Step2410 + CustomerGroup + CustomerFiltersHotfix + CustomerGroupUiFlow + Step244A + Customer + Auth + Permission): ✅ **88 PASS** (569 assertions), 0 fail
- `npm run build`: ✅ 6.54s

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có đổi customer_group schema không? | **Không** — vẫn string column |
| Có ảnh hưởng công nợ không? | **Không** |
| Có ảnh hưởng filter sidebar không? | **Không** — sidebar `<select>` giữ nguyên |
| Có ảnh hưởng POS/Supplier không? | **Không** |
| Có ảnh hưởng group modal advanced không? | **Không** — sidebar "Tạo mới" + combobox empty-query fallback đều mở modal advanced |

## 8. Manual QA

- [ ] /customers → "+ Khách hàng" → Field "Nhóm khách hàng" là combobox.
- [ ] Click field → dropdown hiện danh sách nhóm.
- [ ] Gõ "web" → filter ra "Khách website" (nếu có).
- [ ] Click row → field hiển thị tên nhóm + check icon.
- [ ] Lưu khách → DB lưu đúng `customer_group`.
- [ ] Tạo khách mới, gõ "Mới chưa có" → footer hiện "+ Tạo mới: \"Mới chưa có\"".
- [ ] Click "+ Tạo mới: ..." → API tạo nhóm + tự chọn vào field + dropdown đóng.
- [ ] Lưu khách → cả 2 record (nhóm + KH) đúng trong DB.
- [ ] Mở chi tiết KH → "Cập nhật" → field cũng combobox; prefill đúng nhóm.
- [ ] Đổi nhóm → lưu → reload → nhóm mới đúng.
- [ ] Sidebar filter "Nhóm khách hàng" vẫn hoạt động bình thường.
- [ ] Sidebar "Tạo mới" link vẫn mở modal advanced (không đổi).
- [ ] /pos search khách → không lỗi.
- [ ] /suppliers → không bị ảnh hưởng.
- [ ] Console không có lỗi mới.

## 9. Conclusion

- **Đã giống KiotViet chưa:** Có — combobox single-select với search + inline create, fallback modal advanced khi user cần điền nhiều field.
- **Có an toàn production không:** Có — không migration, không đổi schema, không đụng business logic. Backend validation y nguyên.
- **Có thể deploy không:** Có — 5 hotfix + 88 regression test pass, 0 fail.
