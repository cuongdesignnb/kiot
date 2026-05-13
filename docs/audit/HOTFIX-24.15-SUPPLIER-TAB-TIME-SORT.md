# HOTFIX 24.15 — Supplier Expanded Tab Time Sort

## 1. Vấn đề đã sửa

- Tab **Công nợ** trong expanded row của NCC hiển thị theo thứ tự cũ → mới (15/4, 16/4, 20/4, 22/4, 25/4, 27/4) — user muốn mới nhất lên đầu.
- Tab **Lịch sử nhập/trả hàng** không có UI để toggle sort theo thời gian.
- User yêu cầu: **mặc định mới nhất trước**, có **header cột "Thời gian" clickable** để toggle giữa mới-trước / cũ-trước.

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | (a) Thêm `supplierTabSorts` reactive state + helpers `getSupplierTabSort` / `toggleSupplierTabTimeSort` / `parseSupplierTabTime` / `sortedSupplierHistory` / `sortedSupplierDebt`. (b) Đổi `v-for` của tab History từ `supplierHistory[supplier.id]` → `sortedSupplierHistory(supplier.id)`. (c) Đổi `v-for` của tab Debt từ `filteredDebt(supplier.id)` → `sortedSupplierDebt(supplier.id)`. (d) 2 header cột "Thời gian" thành clickable `@click.stop="toggleSupplierTabTimeSort(...)"` với mũi tên ▼/▲. |
| [`tests/Feature/Supplier/HOTFIX2415SupplierTabSortTest.php`](tests/Feature/Supplier/HOTFIX2415SupplierTabSortTest.php) | NEW — 4 TC pin contract backend mà FE sort phụ thuộc. |
| [`docs/audit/HOTFIX-24.15-SUPPLIER-TAB-TIME-SORT.md`](docs/audit/HOTFIX-24.15-SUPPLIER-TAB-TIME-SORT.md) | NEW — báo cáo này. |

**Không sửa:** [`routes/api.php`](routes/api.php), [`SupplierController.php`](app/Http/Controllers/SupplierController.php), `CashFlow`, `Purchase`, `PurchaseReturn`, công thức `debt_remain`, `recordPayment`, `adjustDebt`, `debtOffset`, sort của bảng cha danh sách NCC.

## 3. Discovery

- **Tab Lịch sử dùng field thời gian nào?** `date` (đã format `d/m/Y H:i` ở [`SupplierController::purchaseHistory`](app/Http/Controllers/SupplierController.php#L298) line 308) — fallback `purchase_date` / `created_at` cho an toàn.
- **Tab Công nợ dùng field thời gian nào?** `created_at` ISO string (set ở [`SupplierController::debtTransactions`](app/Http/Controllers/SupplierController.php#L324) cho mọi loại entry: purchase line 346, payment line 361, return line 380, supplier-tx line 404, invoice line 421...).
- **Backend có đang tính running balance theo ascending không?** Có — [line 465](app/Http/Controllers/SupplierController.php#L465): `$sorted = $entries->sortBy('created_at')->values()` rồi reduce tính `debt_remain`. **Bắt buộc giữ ASC** để running balance đúng.
- **Vì sao không được sort trước khi tính balance?** `debt_remain[i] = Σ supplier_effect[0..i]` theo thời gian — đảo sort = balance sai (số dư đầu kỳ trộn với cuối kỳ).
- **Backend có trả về theo ASC không?** Có — sau khi compute, trả `$ledger->values()` (giữ ASC). Đó là lý do user thấy cũ-trước.

## 4. Cách sửa

### Frontend (Option A — chính)

- **Sort state:** `supplierTabSorts` reactive object, key = `${supplierId}:${tab}`, value = `{ field: 'time', direction: 'desc'|'asc' }`. Mỗi NCC × mỗi tab có sort riêng — đổi tab khác / NCC khác không ảnh hưởng.
- **Default direction:** `'desc'` (mới nhất trước) qua `getSupplierTabSort()` fallback.
- **Parse date:** `parseSupplierTabTime()` xử lý ISO (`2026-04-27T10:43:00.000000Z`) và `dd/mm/yyyy HH:mm` (format BE trả cho history). Trả `0` cho null/invalid → empty rows xếp cuối khi DESC, đầu khi ASC.
- **Không mutate data gốc:** dùng `[...data].sort(...)` — tạo copy trước khi sort. Cache `supplierHistory[id]` / `supplierDebt[id].entries` không bị động.
- **Header toggle:** `@click.stop="toggleSupplierTabTimeSort(supplier.id, 'history'|'debt')"`. `.stop` ngăn click lan ra dòng cha (không collapse). Mũi tên `▼` (desc) / `▲` (asc) + `title` tiếng Việt giải thích.

### Backend

- **Không sửa** — frontend sort là đủ vì:
  - Mỗi entry đã carry sẵn `debt_remain` (computed server-side theo thứ tự đúng). Reorder display không động `debt_remain`.
  - History đã có `orderByDesc('purchase_date')` ở BE — FE sort thêm chỉ cho phép user toggle.
  - Export HOTFIX 24.14 vẫn dùng raw response từ controller → CSV vẫn theo thứ tự BE (không phụ thuộc state FE).

## 5. Bảo toàn nghiệp vụ công nợ

- `debt_remain`: **không tính lại** — vẫn theo thời gian tăng dần ở backend, FE chỉ đảo thứ tự render. TC-03 verify từng entry.
- `summary.net`: **không đổi** — TC-04 confirm = 1,000,000 cho 3 purchases test.
- `supplier_debt_amount`: **không động** — không có code path FE/BE nào trong HOTFIX 24.15 sửa column này.
- CashFlow: **không động**.

## 6. Test đã chạy (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2415SupplierTabSortTest` | ✅ **4 PASS / 29 assertions** |
| `php artisan test --filter=HOTFIX2414SupplierTabExportTest` | ✅ **6 PASS / 23 assertions** (regression — export HOTFIX 24.14 không bị ảnh hưởng) |
| `php artisan test --filter=Supplier` | ✅ **29 PASS / 132 assertions** (full Supplier suite, bao gồm cả 2 HOTFIX) |
| `php artisan test --filter=Purchase` | ✅ **26 PASS / 98 assertions** |
| `php artisan test --filter=PurchaseReturn` | ✅ **14 PASS / 47 assertions** |
| `php artisan test --filter=CashFlow` | ✅ **12 PASS / 33 assertions** |
| `npm run build` | ✅ pass 8.48s |

**4 TC trong HOTFIX2415SupplierTabSortTest:**

1. `test_supplier_purchase_history_items_carry_time_field` — mỗi row có `date`, `code`, `total` để FE sort/render.
2. `test_supplier_debt_transactions_carry_created_at_and_debt_remain` — mỗi entry có `created_at` (FE sort key) + `debt_remain` (an toàn khi reorder).
3. `test_supplier_debt_running_balance_is_chronological` — debt_remain[i] = Σ supplier_effect[0..i] theo thời gian tăng dần; flipping BE sort sẽ phá test này.
4. `test_supplier_debt_summary_unchanged_regardless_of_display_order` — `summary.net` đúng bất kể display order.

## 7. Manual QA

### Automated verification (đã chạy thật)

| Check | Kết quả |
|---|---|
| BE response có `date` cho history rows | ✅ TC-01 |
| BE response có `created_at` + `debt_remain` cho debt entries | ✅ TC-02 |
| `debt_remain` chronological intact | ✅ TC-03 |
| `summary.net` không đổi | ✅ TC-04 |
| Export HOTFIX 24.14 vẫn hoạt động | ✅ 6/6 PASS |
| Supplier / Purchase / PurchaseReturn / CashFlow regression | ✅ 81 PASS |
| Build pass | ✅ |

### Browser QA — pending user verify

Code-level confidence cao (`[...arr].sort` immutable, `@click.stop` chặn parent click), nhưng tôi không có browser, các mục dưới chờ tester tick:

- [ ] `/suppliers` → mở rộng NCC có nhiều phiếu → tab "Lịch sử nhập/trả hàng" → mặc định phiếu mới nhất nằm trên cùng. Click "Thời gian" lần 1 → asc; lần 2 → desc. Mũi tên ▼/▲ thay đổi theo.
- [ ] Click header "Thời gian" KHÔNG collapse dòng cha.
- [ ] Tab History → "Xuất file" vẫn hoạt động (HOTFIX 24.14 regression).
- [ ] Tab "Công nợ" → mặc định giao dịch mới nhất nằm trên cùng (27/4 ở top thay vì 15/4).
- [ ] Click "Thời gian" tab Debt toggle asc/desc. Cột "Nợ cần trả nhà cung cấp" của mỗi dòng vẫn đúng số (không đổi theo display order).
- [ ] Filter "Tất cả giao dịch" → các loại khác vẫn hoạt động kết hợp với sort.
- [ ] Tab Debt → "Xuất file công nợ" vẫn hoạt động.
- [ ] Đóng NCC này, mở NCC khác → sort state không leak (NCC mới vẫn default desc).
- [ ] Đổi tab Info ↔ History ↔ Debt không lỗi console.
- [ ] Toolbar export/import danh sách NCC chính + nút Thanh toán / Điều chỉnh / Cấn bằng công nợ vẫn hoạt động.

## 8. Rủi ro còn lại

- **Công nợ:** không ảnh hưởng — `debt_remain`, `summary.net`, `supplier_debt_amount` đều intact (TC-03, TC-04, full Supplier regression PASS).
- **Export HOTFIX 24.14:** còn hoạt động — TC export vẫn 6/6 PASS, sort là FE-only nên CSV không bị ảnh hưởng.
- **Table cha (danh sách NCC):** không ảnh hưởng — `supplierTabSorts` tách hoàn toàn khỏi `filters.sort_by` của parent.
- **CashFlow / Purchase / PurchaseReturn:** không ảnh hưởng — không động backend, regression đều xanh.

## 9. Commit & deployment

- **Commit SHA:** _(cập nhật sau khi push)_
- **Push status:** _(cập nhật sau khi push)_

## 10. Kết luận

- **Mặc định mới nhất lên trước chưa?** Có — `sortedSupplierHistory` / `sortedSupplierDebt` default `direction: 'desc'`.
- **Sort header hoạt động chưa?** Có (code-level), browser QA cần xác nhận.
- **Có thể deploy chưa?** **CHƯA chốt** — backend đã verify đầy đủ bằng test thật trên MySQL:3319, nhưng 10 box browser QA ở §7 vẫn cần human tick trước khi production deploy.
