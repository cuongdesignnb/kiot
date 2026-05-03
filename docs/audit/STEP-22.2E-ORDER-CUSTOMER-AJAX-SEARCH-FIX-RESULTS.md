# STEP 22.2E — Order Customer AJAX Search Fix

**Date:** 2026-05-04
**Branch/HEAD:** main (apply trên working tree, chưa commit cho đến khi user duyệt)
**Status:** ✅ All checks pass

---

## A. Problem

User báo: trên `Orders/Create`, ô **Tìm khách hàng (F4)** không trả về kết quả tương ứng với input — mỗi lần dùng phải gặp lỗi tương tự, "không vá tạm".

### Triệu chứng
- Gõ tên/SĐT/mã KH vào input → dropdown vẫn hiển thị đúng dãy KH ban đầu (toàn bộ KH server gửi xuống), không lọc theo từ khoá.
- Trên dữ liệu production (≥ vài nghìn KH) → dropdown lag/không hiển thị, lựa chọn KH bất khả thi.

---

## B. Root cause (3 lớp)

| # | Lớp | Hỏng cái gì |
|---|---|---|
| 1 | **Frontend** | `Orders/Create.vue` template dùng `<div v-for="c in customers">` với `customers = props.customers`. Không có filter theo `searchCustomer`, không gọi API. Dropdown thực ra chỉ là render tất cả KH server đẩy xuống. |
| 2 | **Backend (controller)** | `OrderController@create` truyền `'customers' => Customer::all()` vào Inertia → trên prod là payload hàng MB, vẫn không giúp gì cho việc filter. |
| 3 | **Backend (route/API)** | Không có endpoint `/api/customers/search` cho web. Chỉ có:<br>• `/api/pos/customers` — gated `permission:pos.use` (Orders/Create không có quyền này).<br>• `/customers/search-for-merge` — logic merge, format khác. |

→ Hậu quả: UI **không bao giờ** filter được. Đây không phải bug AJAX flake; đây là **thiếu hẳn pipeline tìm kiếm**.

---

## C. Fix theo hợp đồng (contract-driven, không vá tạm)

### C.1 Backend: thêm endpoint typeahead `app/Http/Controllers/CustomerController.php`

Method mới `apiSearch(Request $request)`:
- Trim `search` query; rỗng → `[]`.
- `Schema::hasColumn('customers', 'is_customer')` → áp `where is_customer=true`.
- `Schema::hasColumn('customers', 'status')` → loại `status='inactive'` (`null` vẫn cho qua).
- `WHERE` group OR theo `name | code | phone | phone2 | email | tax_code` (`LIKE %x%`).
- Cột `select` lọc qua `Schema::hasColumn` (defensive vì DB cũ có thể thiếu).
- `orderBy('name')->limit(20)`.
- Response shape:
  ```json
  [{ "id": int, "code": string|null, "name": string,
     "phone": string|null, "phone2": string|null, "email": string|null,
     "address": string|null, "debt_amount": number, "total_spent": number,
     "display_label": "Name — Phone" }]
  ```

### C.2 Route `routes/web.php`
```php
Route::get('/api/customers/search', [CustomerController::class, 'apiSearch'])
    ->name('api.customers.search');
```
Nằm trong group `Route::middleware('auth')` chính (line 34 trở đi). Không cần `permission:pos.use`. Read-only.

### C.3 Backend: bỏ Customer::all() trong `OrderController@create`
```diff
- 'customers' => Customer::all(),
+ 'customers' => [],   // Step 22.2E: dùng AJAX api.customers.search
```

### C.4 Frontend `resources/js/Pages/Orders/Create.vue`

**State mới (script setup):**
- `customerResults: ref([])`
- `customerLoading: ref(false)`
- `customerError: ref('')`
- `customerSearchTimer` (debounce handle).

**Helpers:**
- `customerDisplay(c)` → `c.display_label || c.name || c.phone || c.code`.
- `selectCustomer(c)` → set `selectedCustomer + searchCustomer + receiverName/Phone (chỉ khi rỗng)` + đóng dropdown.
- `fetchCustomers(q)` → `axios.get('/api/customers/search', { params:{search:q}, timeout:8000, headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'} })` với HTML-detection (string body hoặc `content-type` chứa `text/html`) + xử lý 401/419/403/404/ECONNABORTED riêng từng case.
- `retryCustomerSearch()` → fetch lại với q hiện tại.

**Watch:**
- `watch(() => activeTab.value?.searchCustomer)`:
  1. Trim q.
  2. Nếu đang có `selectedCustomer` và q === `customerDisplay(selected)` → no-op (đang là nhãn hiển thị, không phải user gõ tìm).
  3. Nếu q ≠ display(selected) và đang có selected → clear selected (user đang sửa lại).
  4. q rỗng → clear results/error/loading.
  5. Else: debounce 250ms gọi `fetchCustomers`.

**Template dropdown** (replace lines 809–815 cũ):
- Chỉ hiển thị khi `searchCustomer` non-empty.
- 4 trạng thái: `loading` / `error+retry` / `empty` / `list`.
- Click item dùng `@mousedown.prevent="selectCustomer(c)"` để chạy trước `blur`.
- Mỗi item: name + (·code) | phone/email | (debt nếu > 0).

**Bonus**: card "đã chọn KH" giờ click clear cả `searchCustomer` (trước chỉ clear `selectedCustomer`).

---

## D. Tests

`tests/Feature/Customers/CustomerSearchApiTest.php` — 4 test, 18 assertion:

| TC | Tên | Mục đích |
|---|---|---|
| 1 | `test_api_customer_search_returns_matches_by_name_phone_code` | Match name fragment + phone fragment + code; verify shape (id/name/phone/display_label). |
| 2 | `test_api_customer_search_returns_empty_for_blank` | `?search=` và `?search=%20%20%20` đều trả `[]`. |
| 3 | `test_api_customer_search_does_not_return_inactive_if_status_exists` | Skip nếu cột `status` không có; nếu có thì KH `inactive` bị loại. |
| 4 | `test_api_customer_search_requires_auth` | Guest → redirect 302 (or 401/419). |

```
PASS  Tests\Feature\Customers\CustomerSearchApiTest
  ✓ api customer search returns matches by name phone code
  ✓ api customer search returns empty for blank
  ✓ api customer search does not return inactive if status exists
  ✓ api customer search requires auth
Tests: 4 passed (18 assertions)
```

### Regression suite (filter `CustomerSearch|RR06|RR13|SerialAvailability`)
```
Tests: 2 skipped, 19 passed (84 assertions)
Duration: 1.92s
```

### Build
```
✓ built in 6.48s
```

### Route registration
```
GET|HEAD  api/customers/search  api.customers.search › CustomerController@apiSearch
```

---

## E. Files changed

| File | Change |
|---|---|
| `app/Http/Controllers/CustomerController.php` | +`use Schema`, +method `apiSearch` (~50 LOC). |
| `app/Http/Controllers/OrderController.php` | `Customer::all()` → `[]` trong `create()`. |
| `routes/web.php` | +Route `/api/customers/search` (`api.customers.search`). |
| `resources/js/Pages/Orders/Create.vue` | +state/watch/helpers AJAX (~85 LOC), template dropdown viết lại (4 state), clear cả searchCustomer khi click card. |
| `tests/Feature/Customers/CustomerSearchApiTest.php` | NEW — 4 test. |

---

## F. Constraints respected

- ✅ Không sửa business logic Order (chỉ bỏ load list KH dư thừa trong `create()`).
- ✅ Không động `CustomerDebtService`, `SerialAvailabilityService`.
- ✅ Không sửa data production.
- ✅ Bỏ `Customer::all()` (giảm payload Inertia drastically trên prod).
- ✅ GET endpoint read-only, không tạo/sửa data.
- ✅ Build + test xanh trước khi báo cáo.

---

## G. Manual QA checklist (cho user)

1. Vào `Đặt hàng → Đặt hàng mới`. Mở DevTools → Network.
2. Click ô "Tìm khách hàng (F4)" → gõ một ký tự → 250ms sau thấy request `GET /api/customers/search?search=…` 200, body JSON ≤ 20 item.
3. Verify dropdown hiển thị name + code/phone + debt; đúng KH gõ vào.
4. Click 1 KH → input chuyển thành "Tên — SĐT", dropdown đóng, card "đã chọn KH" hiển thị.
5. Click vào card đã chọn → KH clear, input rỗng.
6. Gõ chuỗi không khớp ai → dropdown hiển thị "Không tìm thấy khách hàng phù hợp."
7. (Optional) Tắt mạng → gõ → thấy lỗi + nút "Thử lại".
8. Khôi phục đơn từ hoá đơn (`?invoice_id=…`) → KH cũ hiện đúng trong card; gõ thay → AJAX hoạt động bình thường.

---

## H. Rollback

Chưa commit. Để rollback đơn giản: `git checkout -- app/Http/Controllers/CustomerController.php app/Http/Controllers/OrderController.php routes/web.php resources/js/Pages/Orders/Create.vue && rm tests/Feature/Customers/CustomerSearchApiTest.php`.

Nếu đã commit: revert commit hash đơn lẻ. Backup tag từ Step 22.2C (`before-ui-p3-serial-20260503-223620`) vẫn còn nguyên.
