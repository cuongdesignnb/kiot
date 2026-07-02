# HOTFIX 24.4A-1 — Customers capabilities crash

## Root cause

- `Customers/Index.vue` template đọc `filterOptions.customerGroups`, `filterOptions.types`, ... và `capabilities.supportsBirthdayFilter` trực tiếp.
- Nếu Inertia partial reload trả filterOptions thiếu key (hoặc undefined trong race condition), Vue crash với `Cannot read properties of undefined (reading 'supportsBirthdayFilter')`.
- Backend `CustomerController::index` thực ra **đã** return `capabilities` đầy đủ — nhưng frontend không có defensive fallback.

## Fix

### Backend (`app/Http/Controllers/CustomerController.php`)

Đã verify `buildCapabilities()` luôn trả 7 boolean key (không change). 4 capability `false` đúng theo schema hiện tại:
- `supportsDebtDaysFilter=false` (chưa có debt aging engine)
- `supportsPointsFilter=false` (chưa có loyalty)
- `supportsCreatedByFilter` = `Schema::hasColumn('customers', 'created_by')` (true sau migration 24.4A)

Tất cả 7 capability luôn có trong response.

### Frontend (`resources/js/Pages/Customers/Index.vue`)

1. `filterOptions` prop có default `() => ({})` thay vì để undefined.
2. Thêm `safeFilterOptions` computed với fallback `{}`.
3. Thêm `filterCapabilities` computed với fallback `{}`.
4. Helper `hasCapability(key)` cho strict boolean.
5. Mỗi option list (customerGroups/types/genders/branches/creators/statuses/partnerTypes/deliveryCities/debtOptions) có computed riêng với fallback `[]`.
6. Template thay thế mọi `filterOptions.X` bằng `filterX` computed.

## Files changed

| File | Nội dung |
|---|---|
| `resources/js/Pages/Customers/Index.vue` | Default `filterOptions: () => ({})`, 11 computed safe (capabilities + 9 option lists), thay thế template references qua sed |
| `tests/Feature/Filters/Step244ACustomerFiltersHotfixTest.php` | NEW — 4 test cases |
| `docs/audit/STEP-24.4A-CUSTOMER-FILTERS-HOTFIX.md` | NEW — file này |

**Không sửa:** Backend controller (đã đúng), schema, nghiệp vụ, công nợ.

## Build/Test

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ DONE |
| `npm run build` | ✅ Built in 6.88s |
| `Step244A\|CustomerFiltersHotfix\|CustomerGroup` cluster | ✅ 22 PASS (333 assertions) — 18 existing + 4 hotfix |

### Hotfix test cases

| # | Test | Kết quả |
|---|---|---|
| 1 | `test_customers_index_always_returns_filter_capabilities` (8 keys) | ✅ |
| 2 | `test_capabilities_are_strict_booleans` | ✅ |
| 3 | `test_unsupported_filters_are_false` (debt_days, points) | ✅ |
| 4 | `test_filter_options_have_expected_keys` (9 lists) | ✅ |

## Production deploy

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
composer dump-autoload
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Rồi restart php-fpm. **Không cần migrate** (không schema change).

## Manual QA

- [ ] Reload `/customers` — không còn màn hình đỏ Vue error.
- [ ] Sidebar render đầy đủ.
- [ ] Filter unsupported (Số ngày nợ / Điểm) không hiện do `capability=false`.
- [ ] Nhóm khách hàng dropdown vẫn load options.
- [ ] Filter cơ bản (group, type, gender, status) vẫn hoạt động.
- [ ] Pagination giữ filter.
- [ ] Tạo customer mới → `created_by` = auth user.
