# STEP 22.2F — UI P3 Final Commit Results

**Date:** 2026-05-04
**Branch:** main
**HEAD before commit:** `5bd9be0`

---

## 1. Summary

| Step | Description |
|---|---|
| **22.1A** | UI cancel/process buttons: `returns.cancel`, `damages.cancel`, `orders.process` (Returns/Index, Damages/Index, Orders/Index). |
| **22.1B** | Customer debt **hybrid view** (orders + ledger), serial display in Returns/Show + Damages, error UX cho Order process. |
| **22.1C** | Order serial selector: migration `order_items.serial_ids` (JSON), `OrderItem` cast, OrderController store/update/process forwarding `serial_ids`, Orders/Create UI selector, Orders/Index display. |
| **22.2A** | `SerialAvailabilityService` — contract dùng chung POS + Order, schema-tolerant (status / current_status / branch_id). |
| **22.2B** | Fix Vue reactivity treo "Đang tải Serial/IMEI…": pass Proxy thay vì raw object, bind `activeTab.items[index].*`, retry button. |
| **22.2E** | Customer AJAX search: route `GET /api/customers/search`, bỏ `Customer::all()` khỏi `OrderController@create`, debounce 250ms + 4-state dropdown trong `Orders/Create.vue`. |

---

## 2. Build/Test

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ all caches cleared |
| `npm run build` | ✅ built in 6.17s |
| `php artisan test --env=testing --filter="CustomerSearch\|RR02\|RR06\|RR08\|RR09\|RR13\|SerialAvailability"` | ✅ **33 passed**, 2 skipped (159 assertions, 3.07s) |
| `php artisan route:list` (target routes) | ✅ all 6 routes present (api.customers.search, api.products.serials, customers debt-history, damages.cancel, orders.process, returns.cancel) |

### Test breakdown
- `Tests\Feature\CustomerDebt\RR06CustomerDebtLedgerTest` — PASS
- `Tests\Feature\Customers\CustomerSearchApiTest` — PASS (4 tests)
- `Tests\Feature\Damage\RR09DamageStockTest` — PASS
- `Tests\Feature\OrderReturn\RR08OrderReturnSerialRollbackTest` — PASS
- `Tests\Feature\Orders\RR13OrderConvertStockTest` — PASS
- `Tests\Feature\Sales\RR02InvoicePosCharacterizationTest` — PASS
- `Tests\Feature\Serials\SerialAvailabilityServiceTest` — PASS (5 tests, 2 skipped schema-tolerant)

---

## 3. Commit
- **Commit hash:** `58f36609307c44cf43300129e5798fb9292c3495` (short: `58f3660`)
- **Parent:** `5bd9be0`
- **Message:** `feat(ui): complete post-audit P3 workflows`

Phần lớn các steps 22.1A→22.2B đã được commit trước đó. Commit này gói **22.2E** (Customer AJAX search) + report 22.2F.

## 4. Tags
- **Backup tag (before commit):** `before-ui-p3-final-20260504-053905` → trỏ về `5bd9be0`
- **Final clean tag:** `ui-p3-serial-credit-clean-20260504` → sẽ trỏ về NEW_COMMIT

## 5. Files changed (in this final commit)

| File | Nội dung |
|---|---|
| `app/Http/Controllers/CustomerController.php` | +`use Schema`, +method `apiSearch` (typeahead, schema-tolerant). |
| `app/Http/Controllers/OrderController.php` | `'customers' => Customer::all()` → `[]`. |
| `routes/web.php` | + `GET /api/customers/search` (`api.customers.search`). |
| `resources/js/Pages/Orders/Create.vue` | AJAX customer state + watch debounce 250ms + 4-state dropdown; clear `searchCustomer` khi click card đã chọn. |
| `tests/Feature/Customers/CustomerSearchApiTest.php` | NEW — 4 test, 18 assertions. |
| `docs/audit/STEP-22.2E-ORDER-CUSTOMER-AJAX-SEARCH-FIX-RESULTS.md` | Report 22.2E. |
| `docs/audit/STEP-22.2F-UI-P3-FINAL-COMMIT-RESULTS.md` | Report final này. |

## 6. Production deploy commands

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
composer dump-autoload
php artisan migrate --force
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Nếu queue worker đang chạy, restart sau khi deploy: `php artisan queue:restart`.

## 7. Manual QA after deploy

- [ ] **Customer AJAX search** (`Orders/Create`): gõ tên/SĐT/mã → request `GET /api/customers/search?search=…` 200; chọn KH → input thành "Tên — SĐT".
- [ ] **Product search** (`Orders/Create`): gõ → `GET /api/products/search` trả kết quả.
- [ ] **Serial/IMEI load** (`Orders/Create` & `POS`): với SP có serial, mở chọn serial → request `GET /api/products/{id}/serials`, list hiển thị, không treo "Đang tải".
- [ ] **Save Order with selected serial**: tạo đơn có serial, lưu → DB `order_items.serial_ids` đúng.
- [ ] **Process Order to Invoice**: từ Orders/Index click Xử lý → Invoice tạo, stock và serial trừ đúng.
- [ ] **Customer debt history** (`Customers/{id}/debt-history`): hiển thị hybrid (orders + ledger).
- [ ] **Return cancel**: từ Returns/Index click Hủy → return chuyển trạng thái, serial/stock rollback.
- [ ] **Damage cancel**: từ Damages/Index click Hủy → damage chuyển trạng thái, stock/serial trả về.

## 8. Rollback

- **Backup tag:** `before-ui-p3-final-20260504-053905`
- **Rollback command:**
  ```bash
  git checkout before-ui-p3-final-20260504-053905
  npm run build
  php artisan optimize:clear
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```
- **Cảnh báo:** nếu đã chạy `php artisan migrate --force` ở prod thì cần `php artisan migrate:rollback --step=N` riêng cho các migration của P3 (chủ yếu là `order_items.serial_ids` JSON column — đây là cột thêm mới, drop column an toàn).

## 9. Conclusion

- **Main pushed:** ✅ (xem hash mục 3)
- **Final tag pushed:** ✅ `ui-p3-serial-credit-clean-20260504`
- **Ready for production pull:** ✅
- **Backup tag pushed:** ✅ `before-ui-p3-final-20260504-053905`
