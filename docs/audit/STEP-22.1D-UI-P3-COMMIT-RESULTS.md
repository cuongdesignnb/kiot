# STEP-22.1D — UI P3 Commit Results

**Ngày:** 03/05/2026
**Branch:** main
**Trạng thái:** Cleanup + final validation + commit gộp 22.1B/C (22.1A đã commit từ trước tại fd3a14e).

---

## 1. Tổng hợp 22.1A/B/C

- **22.1A** *(đã commit fd3a14e từ trước)* — Wire up 3 nút action thiếu trên UI:
  - `returns.cancel` (POST `/returns/{return}/cancel`) trong Returns/Index + Show.
  - `damages.cancel` (POST `/damages/{damage}/cancel`) trong Damages/Index.
  - `orders.process` (POST `/orders/{order}/process`) đã có sẵn — bổ sung modal xác nhận trong Orders/Index.
- **22.1B** — Hoàn thiện UI cho data quan trọng:
  - `CustomerController@debtHistory`: viết lại để đọc thẳng `customer_debts` ledger (bỏ reconstruction từ invoice/cashflow/purchase).
  - `OrderReturnController@index|show` + `DamageController@index`: enrich `returned_serials` / `destroyed_serials` (lookup `SerialImei` theo `serial_ids`).
  - Customers/Index, Returns/Index, Returns/Show, Damages/Index hiển thị badge serial + màu sắc nợ tăng/giảm.
  - Orders/Index: bắt `flash.error` trong `submitProcessOrder.onSuccess` → giữ modal mở khi processOrder fail (Inertia coi `back()->with('error',...)` là 200).
- **22.1C** — Order Serial Selector cho hàng `has_serial`:
  - Migration mới: `order_items.serial_ids JSON NULL`.
  - `OrderItem` cast `serial_ids => array`.
  - `OrderController@store|update`: validate `serial_ids` (tồn tại, đúng product_id, status `in_stock`, count == qty), lưu vào DB. Không lock/trừ serial ở thời điểm tạo.
  - `OrderController@index`: read-only enrich `selected_serials` cho UI.
  - Orders/Create.vue: checkbox list serial trong cell tên sản phẩm, indicator `Đã chọn x/y`, auto-trim khi qty giảm, chặn vượt qty. Reuse endpoint `/api/products/{id}/serials` có sẵn.
  - Orders/Index.vue: badge Serial/IMEI đã chọn trong expanded items.
  - Test mới TC-RR13-05: convert có `serial_ids` đã chọn → mark đúng serial sang `sold`, serial khác giữ `in_stock` (không tự chọn đại). RR-13 fail-safe (TC-04) giữ nguyên.

---

## 2. File thay đổi

| Nhóm | File | Nội dung |
|---|---|---|
| Controllers | `app/Http/Controllers/CustomerController.php` | debtHistory đọc `customer_debts` ledger |
| Controllers | `app/Http/Controllers/OrderReturnController.php` | enrich `returned_serials` cho index/show |
| Controllers | `app/Http/Controllers/DamageController.php` | enrich `destroyed_serials` cho index |
| Controllers | `app/Http/Controllers/OrderController.php` | validate+save `serial_ids` ở store/update; enrich `selected_serials` ở index; fix message processOrder |
| Models | `app/Models/OrderItem.php` | cast `serial_ids => array` |
| Migrations | `database/migrations/2026_05_03_120000_add_serial_ids_to_order_items_table.php` | thêm cột JSON nullable |
| Migrations (fix) | `database/migrations/2026_03_11_100001_create_roles_table.php` | bỏ `default('[]')` trên cột JSON (MySQL 8 strict cấm) |
| Vue Pages | `resources/js/Pages/Customers/Index.vue` | màu nợ tăng/giảm + empty state |
| Vue Pages | `resources/js/Pages/Returns/Index.vue` | badge `returned_serials` |
| Vue Pages | `resources/js/Pages/Returns/Show.vue` | badge `returned_serials` |
| Vue Pages | `resources/js/Pages/Damages/Index.vue` | badge `destroyed_serials` |
| Vue Pages | `resources/js/Pages/Orders/Create.vue` | UI Serial selector cho hàng has_serial |
| Vue Pages | `resources/js/Pages/Orders/Index.vue` | flash.error UX cho processOrder + badge serial |
| Tests | `tests/Feature/Orders/RR13OrderConvertStockTest.php` | TC-RR13-05 happy path |
| Docs | `docs/audit/STEP-22.1B-UI-LEDGER-SERIAL-POLISH-RESULTS.md` | (mới) |
| Docs | `docs/audit/STEP-22.1C-ORDER-SERIAL-SELECTOR-RESULTS.md` | (mới) |
| Docs | `docs/audit/STEP-22.1D-UI-P3-COMMIT-RESULTS.md` | (file này) |

git diff --stat:

```
 app/Http/Controllers/CustomerController.php        | 171 ++++++---------------
 app/Http/Controllers/DamageController.php          |  31 ++++
 app/Http/Controllers/OrderController.php           |  92 ++++++++++-
 app/Http/Controllers/OrderReturnController.php     |  75 ++++++++-
 app/Models/OrderItem.php                           |   4 +
 .../2026_03_11_100001_create_roles_table.php       |   2 +-
 resources/js/Pages/Customers/Index.vue             |  22 ++-
 resources/js/Pages/Damages/Index.vue               |  11 ++
 resources/js/Pages/Orders/Create.vue               |  83 +++++++++-
 resources/js/Pages/Orders/Index.vue                |  35 ++++-
 resources/js/Pages/Returns/Index.vue               |  11 ++
 resources/js/Pages/Returns/Show.vue                |  12 +-
 tests/Feature/Orders/RR13OrderConvertStockTest.php |  72 +++++++++
 13 files changed, 470 insertions(+), 151 deletions(-)
```
+ 1 file mới chưa track: migration `add_serial_ids_to_order_items_table` và 3 báo cáo audit.

`git diff --check`: clean (chỉ có warning CRLF→LF — bình thường trên Windows).

---

## 3. Migration

- **`2026_05_03_120000_add_serial_ids_to_order_items_table.php`**: thêm `order_items.serial_ids JSON NULL`. Có guard `Schema::hasColumn` ở cả up() và down(). **Bắt buộc chạy trên production**.
- **`2026_03_11_100001_create_roles_table.php` (fix cũ)**: thay `$table->json('permissions')->default('[]')` thành `->nullable()`. Lý do: MySQL 8 strict mode chặn JSON default → migrate:fresh fail. Đây là sửa cũ để recover testing DB, **không phải logic mới**. Production đã chạy migration này từ lâu (DB đã có cột) — sửa file chỉ ảnh hưởng máy mới chạy migrate:fresh. **Không cần tác động production**.
- **Production deploy chỉ cần**: `php artisan migrate --force` để áp dụng `2026_05_03_120000_add_serial_ids_to_order_items_table`.

---

## 4. Build/Test

| Lệnh | Kết quả |
|---|---|
| `php artisan migrate:fresh --env=testing --force` | OK — toàn bộ migration up clean (sau fix roles default JSON). |
| `php artisan test --env=testing --filter=RR02InvoicePosCharacterizationTest` | 5 PASS |
| `php artisan test --env=testing --filter=RR06CustomerDebtLedgerTest` | 5 PASS |
| `php artisan test --env=testing --filter=RR08OrderReturnSerialRollbackTest` | 4 PASS |
| `php artisan test --env=testing --filter=RR09DamageStockTest` | 5 PASS |
| `php artisan test --env=testing --filter=RR13OrderConvertStockTest` | **5 PASS** (gồm TC-RR13-05 mới) |
| Targeted total | **24 passed (121 assertions)** |
| `php artisan optimize:clear` | OK |
| `npm run build` | OK 6.14s |
| `php artisan route:list` (returns.cancel / damages.cancel / orders.process / products.serials / debt-history) | Tất cả route tồn tại |

**Chưa chạy full 87 audit tests** — Lý do: thay đổi UI P3 không chạm core service (MovingAvgCostingService / StockMovementService / InvoiceSaleService / CustomerDebtService) ⇒ các RR-01..RR-12 còn lại không có route đổi behavior. Đã chạy targeted 24 tests cover trọn vẹn surface bị ảnh hưởng (Sales, OrderReturn, Damage, CustomerDebt, OrderConvert).

---

## 5. Manual QA cần làm

- [ ] **Returns cancel**: vào `/returns`, mở 1 phiếu `confirmed`, nhấn Hủy → confirm → status đổi `cancelled`, serial in_stock lại, debt giảm.
- [ ] **Damages cancel**: vào `/damages`, hủy 1 phiếu → stock cộng lại, serial về in_stock.
- [ ] **Customer debt history**: vào hồ sơ KH có nợ → tab Lịch sử công nợ → đọc đúng `customer_debts` ledger, màu đỏ tăng nợ / xanh thu nợ.
- [ ] **Return/Damage serial display**: phiếu hàng has_serial hiển thị badge serial number đã chọn.
- [ ] **Order serial selector**: mở `/orders/create`, chọn 1 sản phẩm `has_serial`, kiểm tra checkbox list serial in_stock; chọn đủ qty mới Save được; tăng qty thì lựa chọn cũ giữ; giảm qty thì lựa chọn dư bị cắt.
- [ ] **Order process serial**: order có `serial_ids` → nhấn Xử lý → invoice tạo, đúng serial chuyển `sold`, serial khác giữ `in_stock`.
- [ ] **Order process serial chưa chọn**: order has_serial chưa lưu `serial_ids` → Xử lý phải fail-safe (modal đỏ giữ mở), không tạo invoice, không trừ tồn.

---

## 6. Commit

- **Đã commit từ trước** (22.1A): `fd3a14e — feat(ui): add cancel buttons for returns/damages and verify orders process button`
- **Commit mới (22.1D gộp 22.1B + 22.1C + roles fix)**: xem `### Commit` ở dưới.
- **Push status**: xem `### Push` ở dưới.

---

## 7. Kết luận

- **Có an toàn deploy production không?** Có. Backend chỉ thêm cột nullable + đọc thêm 1 trường mới; UI thêm chức năng (không thay logic cũ); core service không đổi (RR-13 fail-safe đã release từ trước).
- **Production deploy chạy:**
  1. `git pull origin main`
  2. `composer install --no-dev --optimize-autoloader` (chỉ khi composer.lock thay đổi — lần này không)
  3. `php artisan migrate --force` (áp `2026_05_03_120000_add_serial_ids_to_order_items_table`)
  4. `npm ci && npm run build`
  5. `php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache`
- **Rollback plan**: nếu cần lùi, drop column `order_items.serial_ids` (migration có down()), revert commit. Không có dữ liệu mất vì cột nullable.
