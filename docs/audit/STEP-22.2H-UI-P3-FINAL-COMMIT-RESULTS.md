# STEP 22.2H — UI P3 Final Commit Results

**Date:** 2026-05-04
**Branch:** main
**Before commit:** `f3f7d1e983f5941b4da132406def85cf00a3716d`
**Backup tag:** `before-ui-p3-final-20260504-060112`

---

## 1. Summary

### 22.1A — UI action buttons (đã commit trước)
- **`returns.cancel`**: Returns/Index có nút Hủy phiếu trả hàng → POST `returns/{return}/cancel` → rollback serial + stock.
- **`damages.cancel`**: Damages/Index có nút Hủy phiếu xuất hủy → POST `damages/{damage}/cancel` → trả serial về `in_stock`, hoàn stock.
- **`orders.process`**: Orders/Index có nút Xử lý → POST `orders/{order}/process` → tạo Invoice từ Order.

### 22.1B — Ledger/serial UI polish (đã commit trước)
- **Customer debt hybrid**: hiển thị cả lịch sử cũ (orders) + ledger mới (customer_debts) trong Customers/{id}/debt-history.
- **Return serial display**: Returns/Show liệt kê serial labels nếu có.
- **Damage serial display**: Damages/Index thấy serial bị hủy.
- **Order process error UX**: nút Xử lý hiện loading + alert lỗi cụ thể.

### 22.1C — Order serial selector (đã commit trước)
- Migration `order_items.serial_ids` (JSON nullable).
- `OrderItem` cast `serial_ids => array`.
- Orders/Create selector hiện tại UI; Orders/Index hiển thị serial labels đã chọn.
- RR-13 tests pass (3 success cases + serial flow fail-safe).

### 22.2A — SerialAvailabilityService (đã commit trước)
- `SerialAvailabilityService::findBlockedIds($ids, $productId)` — schema/legacy tolerant (`Schema::hasColumn` cho `status`/`current_status`/`branch_id`).
- POS endpoint `getProductSerials` dùng service.
- Order store/update/processOrder dùng service đồng nhất.

### 22.2B — Serial loading stuck fix (đã commit trước)
- **Root cause**: pass raw object thay vì Vue Proxy → mutation không reactive.
- Fix bind template `activeTab.items[index].*` thay vì `item.*`.
- 4 trạng thái selector: loading / error+retry / empty / list.

### 22.2E — Customer AJAX search (đã commit trước, hash `f3f7d1e`)
- API `GET /api/customers/search` (`api.customers.search`), schema-tolerant qua `Schema::hasColumn` cho `is_customer`/`status`.
- Frontend debounce 250ms + 4-state dropdown.
- Bỏ `Customer::all()` khỏi `OrderController@create` → giảm payload Inertia drastically trên prod.

### 22.2G — Require Serial/IMEI before Order save (commit này)
- **Frontend** (`Orders/Create.vue`): computed `orderItemsSerialStatus` + `validateOrderSerialSelection()` chặn `save()`/`saveAndPrint()`; banner cam dưới mỗi selector khi thiếu.
- **Backend pre-flight** (`OrderController::validateItemsSerials()`): chạy TRƯỚC `Order::create` (store) và TRƯỚC `items()->delete()` (update) để DB nhất quán khi fail.
- Foreach trong store/update enforce `count(serialIds) === qty` cho mọi item `has_serial`, không còn skip khi `serialIds` rỗng.
- **processOrder fail-safe** giữ nguyên — bức tường cuối cho Order cũ trước Step 22.1C.
- **Tests**: 6 mới (`RequireSerialOnOrderSaveTest`), 21 assertions, **all pass**. Bao gồm 1 test bổ sung `test_order_process_normal_product_without_serial_ids_should_succeed` xác nhận hàng thường processOrder → Invoice OK, stock giảm đúng, không tạo `InvoiceItemSerial`.

---

## 2. Build/Test

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ all caches cleared |
| `npm run build` | ✅ built in 6.26s |
| `php artisan test --env=testing --filter="CustomerSearch\|RR02\|RR06\|RR08\|RR09\|RR13\|SerialAvailability\|RequireSerial\|Order"` | ✅ **44 passed**, 2 skipped (190 assertions, 4.15s) |
| `php artisan route:list` (6 target routes) | ✅ all present |

### Test breakdown
| Suite | Status |
|---|---|
| `Tests\Feature\CashFlow\RR10CashFlowDeletionTest` | PASS |
| `Tests\Feature\CustomerDebt\RR06CustomerDebtLedgerTest` | PASS |
| `Tests\Feature\Customers\CustomerSearchApiTest` | PASS (4) |
| `Tests\Feature\Damage\RR09DamageStockTest` | PASS |
| `Tests\Feature\OrderReturn\RR08OrderReturnSerialRollbackTest` | PASS |
| `Tests\Feature\OrderReturn\RR11OrderReturnQtyTest` | PASS |
| `Tests\Feature\Orders\RR13OrderConvertStockTest` | PASS |
| `Tests\Feature\Orders\RequireSerialOnOrderSaveTest` | PASS (5 NEW) |
| `Tests\Feature\Sales\RR02InvoicePosCharacterizationTest` | PASS |
| `Tests\Feature\Serials\SerialAvailabilityServiceTest` | PASS (5 + 2 skip) |

### Routes verified
```
GET|HEAD  api/customers/search             api.customers.search
GET|HEAD  api/products/{product}/serials   api.products.serials
GET|HEAD  customers/{customer}/debt-history
POST      damages/{damage}/cancel          damages.cancel
POST      orders/{order}/process           orders.process
POST      returns/{return}/cancel          returns.cancel
```

---

## 3. Commit

- **Before commit:** `f3f7d1e983f5941b4da132406def85cf00a3716d`
- **Backup tag:** `before-ui-p3-final-20260504-060112` → trỏ về `f3f7d1e`
- **New commit (final, sau amend bổ sung TC-06 + cập nhật bảng test 43→44):** `0a8aa5c732258ab826bde92084df9e388ee5e3b3` (short `0a8aa5c`)
- **Commit gốc trước amend (đã thay thế, không còn trên main):** `00aafd47e231ae6a8a9e860f738f5b4faecbb9f8` (short `00aafd4`)
- **Commit message:** `feat(ui): complete post-audit P3 order workflows`

## 4. Final tag

- **Final tag:** `ui-p3-order-workflows-clean-20260504`
- Trỏ về NEW_COMMIT.

## 5. Files changed (commit này)

| File | Nội dung |
|---|---|
| `app/Http/Controllers/OrderController.php` | + helper `validateItemsSerials()` pre-flight; gọi trước `Order::create` (store) và trước `items()->delete()` (update); foreach enforce serial cho mọi item has_serial. |
| `resources/js/Pages/Orders/Create.vue` | + computed `orderItemsSerialStatus`/`orderHasSerialMissing` + `validateOrderSerialSelection()` chặn `save()`/`saveAndPrint()`; banner cam UI per-item khi thiếu. |
| `tests/Feature/Orders/RequireSerialOnOrderSaveTest.php` | NEW — 5 test, 16 assertions. |
| `docs/audit/STEP-22.2G-REQUIRE-SERIAL-BEFORE-ORDER-SAVE-RESULTS.md` | Report 22.2G. |
| `docs/audit/STEP-22.2H-UI-P3-FINAL-COMMIT-RESULTS.md` | Report final này. |

---

## 6. Production deploy commands

```bash
cd /www/wwwroot/kiot.cuongdesign.net

git status
git pull origin main

composer dump-autoload
php artisan migrate --force

npm run build

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate:status
php artisan route:list | grep -E "api/customers/search|api/products/.*/serials|orders.process|returns.cancel|damages.cancel"
```

> Nếu queue worker đang chạy: `php artisan queue:restart` sau cùng.

---

## 7. Manual QA after deploy

1. **Orders/Create — tìm khách hàng**:
   - [ ] Tìm theo tên (gõ 1 phần tên).
   - [ ] Tìm theo SĐT (gõ 1 phần SĐT).
   - [ ] Tìm theo mã KH.
   - [ ] Chọn KH → lưu Order → xác nhận `customer_id` đúng trong DB.
2. **Orders/Create — serial**:
   - [ ] Sản phẩm Serial/IMEI: serial load được trong selector.
   - [ ] Không tick serial → bấm Lưu → alert + KHÔNG tạo Order.
   - [ ] Tick thiếu (qty=2 chọn 1) → bấm Lưu → alert "1/2".
   - [ ] Tick đủ → Lưu OK; serial vẫn `in_stock`.
3. **Process Order**:
   - [ ] Click Xử lý → Invoice tạo, serial chuyển `sold`, stock giảm đúng.
4. **Customer debt**:
   - [ ] `/customers/{id}/debt-history` còn thấy lịch sử cũ + giao dịch ledger mới.
5. **Cancel actions**:
   - [ ] Hủy phiếu trả hàng → serial trả `in_stock`, stock giảm đúng.
   - [ ] Hủy phiếu xuất hủy → stock hoàn lại.

---

## 8. Rollback

### Rollback toàn bộ về before-tag (không reset main):
```bash
git fetch origin --tags
git checkout before-ui-p3-final-20260504-060112
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Rollback hard main về before commit:
```bash
git checkout main
git reset --hard f3f7d1e983f5941b4da132406def85cf00a3716d
git push origin main --force-with-lease
```

> Cảnh báo: nếu đã chạy migration mới ở prod, drop column `order_items.serial_ids` an toàn (chỉ là JSON column, không có constraint). Migration `order_items.serial_ids` đã có từ Step 22.1C — không phát sinh migration mới ở 22.2G/22.2H.

---

## 9. Conclusion

- **Main pushed:** ✅ `0a8aa5c` (amend của `00aafd4` để bổ sung TC-22.2G-06 + bảng test 43→44; đã verify ở Step 22.2I)
- **Backup tag pushed:** ✅ `before-ui-p3-final-20260504-060112`
- **Final tag pushed:** ✅ `ui-p3-order-workflows-clean-20260504`
- **Ready for production pull:** ✅
- **Remaining risk:**
  - Order cũ (trước Step 22.1C) vẫn có thể có item serial mà `serial_ids = null`. Khi user mở edit + Save → backend chặn và bắt chọn serial trước khi save (đúng contract mới). processOrder fail-safe vẫn bảo vệ.
  - Customer search dependency: nếu route cache prod stale → cần `php artisan route:clear && php artisan route:cache` lại.
  - Không có migration mới ở step này → an toàn.
