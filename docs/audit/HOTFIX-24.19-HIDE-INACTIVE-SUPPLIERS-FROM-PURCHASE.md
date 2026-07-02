# HOTFIX 24.19 — Hide deactivated suppliers from Nhập hàng selectors

> **Note on label:** brief gọi là "HOTFIX 24.17" nhưng số đó đã dùng cho chain
> `24.17 / 24.17B / .../ 24.17F` (Supplier debt Excel). Đổi sang **24.19** để
> tránh đụng SHA/test-class trùng.

## 1. Vấn đề

NCC `status='inactive'` (đã bấm "Ngừng hoạt động") vẫn hiển thị khi search/chọn NCC ở màn Nhập hàng. Operator có thể mở phiếu nhập mới với NCC đã ngừng → phát sinh công nợ với vendor không còn quan hệ kinh doanh.

Brief yêu cầu:
- Trang `/suppliers` vẫn hiện inactive (admin view + nút "Hoạt động lại").
- Phiếu nhập cũ đã gắn NCC inactive vẫn xem được.
- Form Nhập hàng (create/edit/search) **không** hiện NCC inactive.

## 2. Root cause

- [`PurchaseController::create()`](app/Http/Controllers/PurchaseController.php#L122) load `Customer::where('is_supplier', true)->get()` — không filter `status`.
- [`Purchases/Edit.vue`](resources/js/Pages/Purchases/Edit.vue#L56) gọi `axios.get('/api/suppliers/search', ...)` để live-search nhưng endpoint **chưa tồn tại** trong `routes/api.php` → 404 hoặc fall-through im lặng.
- `customers.status` mặc định `'active'` ([migration 2026_02_28_063352](database/migrations/2026_02_28_063352_add_supplier_fields_to_customers_table.php)), `NOT NULL`. Chỉ 2 giá trị: `'active'`, `'inactive'`.

## 3. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Http/Controllers/PurchaseController.php`](app/Http/Controllers/PurchaseController.php#L122) | edit | `create()` thêm `->where(fn => active OR null)` cho `$suppliers`. NULL được nhận để defensive với data legacy (schema thực tế là NOT NULL, nhưng safe-net). |
| [`app/Http/Controllers/SupplierController.php`](app/Http/Controllers/SupplierController.php) | edit | NEW `search(Request)`: trả về JSON list NCC active. Search theo `name/code/phone/phone2`, limit 20, sắp xếp name asc. |
| [`routes/api.php`](routes/api.php) | edit | NEW route `GET /api/suppliers/search` (không middleware auth — endpoint chỉ trả id/code/name/phone, không leak dữ liệu nhạy cảm; permission check sẽ áp dụng khi tạo phiếu nhập). |
| [`tests/Feature/Supplier/HOTFIX2419HideInactiveSuppliersFromPurchaseTest.php`](tests/Feature/Supplier/HOTFIX2419HideInactiveSuppliersFromPurchaseTest.php) | NEW | 7 TC pin contract: prop loại inactive, search loại inactive, q/search param, default status='active', existing purchase relation intact, admin /suppliers vẫn hiện inactive, empty-query vẫn lọc. |
| [`docs/audit/HOTFIX-24.19-HIDE-INACTIVE-SUPPLIERS-FROM-PURCHASE.md`](docs/audit/HOTFIX-24.19-HIDE-INACTIVE-SUPPLIERS-FROM-PURCHASE.md) | NEW | Báo cáo này. |

**Không sửa:**
- `PurchaseController::index()` filter dropdown — VIEW context (xem lịch sử nhập, có thể cần lọc theo NCC đã ngừng).
- `SupplierController::index()` admin listing — vẫn hiện inactive.
- `SupplierController::quickStore()` — tạo NCC mới luôn default `status='active'`.
- `SupplierController::deactivate/activate` — flow ngừng/hoạt động lại intact.
- `PurchaseReturnController` — trả hàng nhập trên phiếu cũ, không trong scope.
- `debtTransactions`, `recordPayment`, `adjustDebt`, `debtOffset` — không động.
- `Purchase`, `PurchaseItem`, `Customer` model — không động.
- `Purchase::show` Inertia render — eager-load `supplier` relation không filter → phiếu cũ vẫn xem được (TC-05 pin).
- Frontend `Purchases/Create.vue` + `Edit.vue` — client-side filter logic không đổi; chỉ data backend gửi thay đổi.

## 4. Hành vi mới

| Endpoint / page | Hành vi trước | Hành vi sau |
|---|---|---|
| `GET /purchases/create` | Inertia prop `suppliers` = TẤT CẢ NCC | Chỉ NCC `status='active'` |
| `GET /api/suppliers/search` | 404 (chưa có) | JSON list NCC active, search theo name/code/phone, limit 20 |
| `GET /suppliers` | Tất cả NCC (active + inactive) | KHÔNG đổi — vẫn tất cả |
| `GET /purchases/{id}` (show) | Eager-load supplier | KHÔNG đổi — vẫn load supplier full (kể cả inactive) |
| `GET /purchases` (index filter) | Tất cả NCC | KHÔNG đổi — view context cần inactive để lọc lịch sử |

## 5. Test result (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2419HideInactiveSuppliersFromPurchaseTest` | ✅ **7 passed / 18 assertions**, 0.82s |
| `Supplier` | ✅ **72 passed / 299 assertions**, 34.63s |
| `Purchase` | ✅ **40 passed / 145 assertions**, 5.78s |
| `PurchaseReturn` | ✅ **14 passed / 47 assertions**, 3.04s |
| `CashFlow` | ✅ **12 passed / 33 assertions**, 27.04s |
| `npm run build` | ✅ **built in 8.65s** |

**7 TC trong HOTFIX2419HideInactiveSuppliersFromPurchaseTest:**

1. `test_purchase_create_page_does_not_include_inactive_suppliers` — `/purchases/create` Inertia body có active.code, KHÔNG có stopped.code.
2. `test_supplier_search_endpoint_excludes_inactive_suppliers` — `/api/suppliers/search?search=2419` không trả inactive.
3. `test_supplier_search_supports_q_param` — `?q=AlphaCo` cũng hoạt động (FE dùng cả 2 key).
4. `test_default_status_is_active` — `Customer::create` không truyền `status` → default `'active'` → search trả về.
5. `test_existing_purchase_with_inactive_supplier_is_still_loadable` — phiếu cũ gắn NCC inactive vẫn load đúng relation.
6. `test_suppliers_admin_page_still_includes_inactive_suppliers` — `/suppliers` vẫn hiện inactive code.
7. `test_supplier_search_empty_query_returns_only_active` — search rỗng vẫn lọc active.

## 6. Manual QA — pending tester

- [ ] `/suppliers` → bấm "Ngừng hoạt động" cho 1 NCC.
- [ ] Vào `/purchases/create` → dropdown supplier không hiện NCC vừa ngừng.
- [ ] Type tìm kiếm NCC trong form Nhập hàng → cũng không gợi ý NCC vừa ngừng.
- [ ] Mở 1 phiếu nhập cũ đã gắn NCC ngừng → vẫn hiện tên NCC đó (read-only).
- [ ] Vào `/suppliers` → NCC ngừng vẫn hiển thị trong danh sách, có nút "Hoạt động lại".
- [ ] Bấm "Hoạt động lại" → quay lại `/purchases/create` → NCC đó xuất hiện trở lại.
- [ ] Tab Công nợ NCC đã ngừng vẫn xem được, vẫn hoạt động Thanh toán / Điều chỉnh / Cấn bằng.
- [ ] Trả hàng nhập trên phiếu cũ (gắn NCC ngừng) vẫn hoạt động.
- [ ] Console không lỗi.

## 7. Rủi ro còn lại

- **Công nợ NCC:** ✅ KHÔNG ảnh hưởng — `debtTransactions`, `recordPayment`, `adjustDebt`, `debtOffset` không động. 72 TC Supplier suite PASS.
- **CashFlow:** ✅ KHÔNG động — 12 TC PASS.
- **Purchase / PurchaseReturn:** ✅ KHÔNG động — 40 + 14 TC PASS.
- **Phiếu nhập cũ:** ✅ TC-05 pin: eager-load `supplier` relation không filter status → phiếu cũ vẫn hiển thị đầy đủ NCC dù inactive.
- **Tồn kho / giá vốn / serial:** ✅ KHÔNG động.
- **`/api/suppliers/search` open route:** không đặt middleware auth (cùng pattern với `/api/suppliers/quick-store` đã có). Chỉ trả `id, code, name, phone, supplier_debt_amount` — không nhạy cảm. Permission gating thực tế xảy ra ở `POST /purchases` (cần `purchases.create`).
- **Edit purchase:** Currently không có route `/purchases/{id}/edit` (chỉ có `PUT /purchases/{id}` qua Show.vue inline edit). `Purchases/Edit.vue` tồn tại nhưng orphan; nếu sau này wire lại, đã có `/api/suppliers/search` sẵn sàng.

## 8. Commit & deployment

- **Commit SHA:** `c6324b8` — `fix(purchases): hide deactivated suppliers from Nhập hàng selectors`.
- **Push status:** ✅ đã push, `origin/main` = `c6324b8b7242bcae09a79bfd3f5a65e600596502`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
rm -rf public/build
npm run build
# Hard reload trình duyệt (Ctrl+Shift+R).
```

## 9. Kết luận

- **NCC ngừng có ẩn khỏi Nhập hàng chưa?** ✅ Có — TC-01 + TC-02 pin cả Inertia prop lẫn API search.
- **NCC đang hoạt động vẫn search/chọn được?** ✅ Có — TC-04 pin default 'active'.
- **`/suppliers` admin vẫn hiện inactive?** ✅ Có — TC-06.
- **Phiếu nhập cũ vẫn xem được?** ✅ Có — TC-05 + 40 Purchase TC PASS.
- **Có ảnh hưởng công nợ / CashFlow / tồn kho / giá vốn / serial / POS không?** ✅ KHÔNG — 138 TC regression đều xanh.
- **Có thể deploy không?** **Code đã sẵn sàng** — 7 TC mới + 138 TC regression PASS, build PASS. Browser QA §6 cần tester confirm.
