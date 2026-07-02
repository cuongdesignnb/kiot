# STEP 23.3 — Purchase + Purchase Return Flow Audit

**Date:** 2026-05-05
**Branch:** main (chưa commit)
**Scope:** Tạo phiếu nhập (POST `/purchases`) + Tạo phiếu trả NCC (POST `/purchase-returns`).
**Goal:** Verify quy tắc nghiệp vụ; vá lỗ hổng nếu có; không sửa core service trừ khi có bug rõ.

---

## 1. Discovery

| Luồng | Entry point | Model | Service dùng | Serial xử lý ở | Stock xử lý ở | Giá vốn xử lý ở | Công nợ NCC | Cashflow | Rủi ro |
|---|---|---|---|---|---|---|---|---|---|
| **Nhập hàng thường** | `PurchaseController@store` ([app/Http/Controllers/PurchaseController.php](app/Http/Controllers/PurchaseController.php#L184)) | `Purchase`, `PurchaseItem` | `MovingAvgCostingService::applyPurchase`, `StockMovementService::record(IN_PURCHASE)` | N/A | applyPurchase → stock_quantity + cost BQ + inventory_total_cost | applyPurchase tính BQ di động, lưu `cost_price_after` snapshot | `customers.supplier_debt_amount` += debt_amount; ledger `customer_debts` type `purchase` | CashFlow `expense` nếu `paid_amount > 0` | OK sau patch |
| **Nhập hàng serial** | Cùng `store` | + `SerialImei` | + applyPurchase + per-serial create | Tạo SerialImei status=`in_stock` với `purchase_id`, `cost_price`, `original_cost` | applyPurchase | applyPurchase | Cùng | Cùng | **BUG-1, BUG-2 vá** |
| **Trả hàng nhập thường** | `PurchaseReturnController@store` ([app/Http/Controllers/PurchaseReturnController.php](app/Http/Controllers/PurchaseReturnController.php#L131)) | `PurchaseReturn`, `PurchaseReturnItem` | `MovingAvgCostingService::applySaleReturn` (chiều OUT), `StockMovementService::record(OUT_PURCHASE_RETURN)` | N/A | applySaleReturn → stock_quantity − qty | Cost dùng `purchase_item.unit_cost_allocated` snapshot | `supplier_debt_amount` −= (totalAmount − refund); ledger type `purchase_return` | CashFlow `income` nếu `refund_amount > 0` | RR-12 đã chặn over-return |
| **Trả hàng nhập serial** | Cùng `store` | + `SerialImei` | + per-serial mark `returned` | line 254: serial → status=`returned`, `purchase_return_id`=return.id | applySaleReturn | Cùng | Cùng | Cùng | **BUG-3, BUG-4, BUG-5, BUG-6 vá** |
| **Trả hàng nhập nhanh** | `PurchaseReturnController@quickStore` ([dead code]) | — | — | — | — | — | — | — | KHÔNG có route đăng ký → unreachable. Out of scope. |

**Ghi chú:** Cả 2 luồng store đều bọc trong `DB::beginTransaction` ⇒ rollback sạch. Validation Step 23.3 mới được đặt **TRƯỚC** transaction (fail-fast, không tạo phiếu rỗng).

### Routes verified

```
GET    /purchases                          purchases.index
GET    /purchases/create                   purchases.create
POST   /purchases                          purchases.store
GET    /purchases/{purchase}/show          purchases.show
GET    /purchases/{purchase}/edit          purchases.edit
PUT    /purchases/{purchase}                purchases.update
DELETE /purchases/{purchase}                purchases.destroy

GET    /purchase-returns                   purchase-returns.index
GET    /purchase-returns/create            purchase-returns.create
POST   /purchase-returns                   purchase-returns.store
GET    /purchase-returns/{purchaseReturn}/show  purchase-returns.show
```

`quickStore` không có route ⇒ bỏ qua.

---

## 2. Business rules verified

### 2.1 Nhập hàng thường
- ✅ `quantity > 0` (validate `min:1`).
- ✅ `stock_quantity` tăng đúng (TC-23.3-01).
- ✅ Cost BQ di động đúng: `(stockOld*costOld + qtyIn*priceIn) / (stockOld + qtyIn)` (TC-23.3-01: 10@100k + 5@200k → 15 @ 133333.33).
- ✅ `stock_movements` row `type='in_purchase'` (TC-23.3-01).
- ✅ `unit_cost_allocated` lưu trên `purchase_items` (verified TC-23.3-09).
- ✅ Công nợ NCC tăng đúng `total - paid` (TC-23.3-02: 1m-400k=600k).
- ✅ CashFlow `expense` khi `paid > 0` (verified qua existing tests).

### 2.2 Nhập hàng Serial/IMEI (sau Step 23.3 fix)
- ✅ `count(serials) === quantity` BẮT BUỘC (mới, BUG-1) — TC-23.3-03.
- ✅ Không cho serial rỗng (đã có từ trước).
- ✅ Không cho serial trùng trong cùng item / cross-item (mới, BUG-2) — TC-23.3-04.
- ✅ Không cho serial đã tồn tại DB (đã có từ trước) — TC-23.3-05.
- ✅ Tạo đúng số `SerialImei` status=`in_stock`, `purchase_id`, `original_cost` (TC-23.3-06).
- ✅ Stock = số serial in_stock (recomputeFromSerials).

### 2.3 Trả hàng nhập thường
- ✅ Chỉ trả được sản phẩm thuộc phiếu nhập (validate `purchase_item_id` exists trong purchase).
- ✅ Không trả vượt qty còn có thể trả (RR-12, line 152–162 cũ).
- ✅ `applySaleReturn` trừ tồn đúng + cập nhật cost (TC-23.3-07).
- ✅ `stock_movements` row `type='out_purchase_return'` đúng (TC-23.3-07).
- ✅ Cost dùng `purchase_item.unit_cost_allocated` snapshot, KHÔNG dùng `product.cost_price` hiện tại (TC-23.3-09).
- ✅ `supplier_debt_amount` giảm đúng theo `total − refund` (TC-23.3-07: debt 1m → 600k).

### 2.4 Trả hàng nhập Serial/IMEI (sau Step 23.3 fix)
- ✅ `count(serial_ids) === quantity` BẮT BUỘC (mới, BUG-4) — TC-23.3-10.
- ✅ Serial phải thuộc đúng `purchase_id` (mới, BUG-3) — TC-23.3-11.
- ✅ Serial phải đang `status='in_stock'` (mới rõ ràng, BUG-6) — TC-23.3-12.
- ✅ Không cho `serial_id` trùng trong cùng request (mới, BUG-5) — TC-23.3-13.
- ✅ Sau trả: `status='returned'`, `purchase_return_id`=return.id (TC-23.3-14).
- ✅ Stock giảm đúng số serial bị trả.

### 2.5 Cost snapshot policy
- ✅ Tạo return: lấy `purchase_item.unit_cost_allocated` (line 273), fallback `product.cost_price` chỉ khi không có purchase_item.
- ✅ Lưu vào `purchase_return_items.cost_price` làm snapshot riêng.
- ✅ TC-23.3-09 verify: thay `product.cost_price` 100k → 999k sau nhập, vẫn return + ghi movement với cost = 100k.

---

## 3. Bugs found & fix

| Mã lỗi | Mô tả | Mức độ | File | Cách xử lý |
|---|---|---|---|---|
| **BUG-23.3-1** | `PurchaseController@store` không enforce `count(serials) === quantity` ⇒ user qty=5 chỉ liệt 2 serial → tạo phiếu nhưng chỉ 2 SerialImei, stock sai. | **Cao** | [app/Http/Controllers/PurchaseController.php](app/Http/Controllers/PurchaseController.php#L187) | Pre-flight check trước create. |
| **BUG-23.3-2** | Không chặn duplicate serial trong cùng request (cùng item hoặc cross-item) ⇒ unique constraint DB sẽ throw exception thô. | Trung | Cùng | `array_unique` per-item + `$globalSeenSerials` cross-item. |
| **BUG-23.3-3** | `PurchaseReturnController@store` không filter `serial.purchase_id` ⇒ user có thể trả serial thuộc phiếu nhập KHÁC. Hậu quả: serial nhập từ phiếu A bị mark `returned` qua phiếu trả của phiếu B. | **Cao** | [app/Http/Controllers/PurchaseReturnController.php](app/Http/Controllers/PurchaseReturnController.php#L161) | Pre-flight `where('purchase_id', $purchase->id)` + double-check trong update body. |
| **BUG-23.3-4** | Không enforce `count(serial_ids) === quantity` ⇒ qty=2 serial_ids=[1] → stock −2 nhưng chỉ 1 serial bị mark returned. | **Cao** | Cùng | Pre-flight count check. |
| **BUG-23.3-5** | Không chặn cùng `serial_id` xuất hiện 2 lần trong cùng request. | Trung | Cùng | `$seenSerialIds` map. |
| **BUG-23.3-6** | Không chặn rõ serial đã `sold` hoặc đã `returned` ⇒ có thể tạo phiếu trả của serial đã bán. | **Cao** | Cùng | Pre-flight `where('status', 'in_stock')` + double-check trong update. |

### KHÔNG vá (nguyên tắc Step 23.3)

- `MovingAvgCostingService` — không có bug rõ. TC-23.3-01, TC-23.3-09 confirm BQ + snapshot chính xác.
- `CustomerDebtService` (supplier debt) — supplier_debt_amount cập nhật trực tiếp trong controller; logic dấu đúng.
- `StockMovementService` — TYPE đã đủ (`IN_PURCHASE`, `OUT_PURCHASE_RETURN`).
- Schema — không thêm migration; không động dữ liệu prod.
- `quickStore` (purchase return nhanh) — dead code, không có route đăng ký. Out of scope. Khuyến nghị xóa hoặc thêm route ở P3 sau.

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/PurchaseController.php](app/Http/Controllers/PurchaseController.php) | + ~30 dòng pre-flight Step 23.3: count===qty, dedup per-item & cross-item, giữ existing check serial-tồn-tại-DB. Thay block validate serial cũ (chỉ check empty + DB exists). |
| [app/Http/Controllers/PurchaseReturnController.php](app/Http/Controllers/PurchaseReturnController.php) | + 33 dòng pre-flight Step 23.3 sau RR-12 validation, trước `DB::beginTransaction`. Thêm `where('purchase_id', $purchase->id)` vào update SerialImei trong transaction (line 257). |
| [tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php](tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php) | NEW — 14 tests, 47 assertions. |
| [docs/audit/STEP-23.3-PURCHASE-PURCHASE-RETURN-FLOW-AUDIT.md](docs/audit/STEP-23.3-PURCHASE-PURCHASE-RETURN-FLOW-AUDIT.md) | NEW — report này. |

KHÔNG sửa: `Purchase`/`PurchaseItem`/`PurchaseReturn` model, migration, MovingAvg/StockMovement service, Vue pages.

---

## 5. Tests

| Test (Step 23.3) | Kết quả |
|---|---|
| TC-23.3-01 `purchase_normal_should_increase_stock_and_avg_cost` | ✅ PASS |
| TC-23.3-02 `purchase_credit_should_increase_supplier_debt` | ✅ PASS |
| TC-23.3-03 `purchase_serial_requires_count_equal_quantity` | ✅ PASS (BUG-1) |
| TC-23.3-04 `purchase_serial_duplicate_in_request_should_fail` | ✅ PASS (BUG-2) |
| TC-23.3-05 `purchase_serial_existing_in_db_should_fail` | ✅ PASS |
| TC-23.3-06 `purchase_serial_success_should_create_serials_and_stock` | ✅ PASS |
| TC-23.3-07 `purchase_return_normal_should_reduce_stock_and_supplier_debt` | ✅ PASS |
| TC-23.3-08 `purchase_return_more_than_purchased_should_fail` | ✅ PASS |
| TC-23.3-09 `purchase_return_uses_purchase_item_unit_cost_not_current_product_cost` | ✅ PASS |
| TC-23.3-10 `purchase_return_serial_requires_count_equal_quantity` | ✅ PASS (BUG-4) |
| TC-23.3-11 `purchase_return_serial_not_from_purchase_should_fail` | ✅ PASS (BUG-3) |
| TC-23.3-12 `purchase_return_serial_sold_should_fail` | ✅ PASS (BUG-6) |
| TC-23.3-13 `purchase_return_duplicate_serial_in_request_should_fail` | ✅ PASS (BUG-5) |
| TC-23.3-14 `purchase_return_serial_success_should_mark_returned_and_reduce_stock` | ✅ PASS |

### Regression

| Lệnh | Kết quả |
|---|---|
| `--filter="Purchase\|PurchaseReturn\|RR05\|RR06\|RR12\|SerialAvailability\|Step232\|Step233"` | ✅ **48 passed**, 2 skipped, 173 assertions |
| `--filter="RR02\|RR08\|RR09\|RR11\|RR13\|RequireSerial\|CustomerSearch\|Order"` | ✅ **48 passed**, 207 assertions |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 7.11s |

---

## 7. E2E self-test

E2E qua HTTP/Controller layer đã được phủ bằng feature tests (Step233PurchaseReturnFlowTest gọi `route('purchases.store')` + `route('purchase-returns.store')` thực) — tương đương kịch bản E2E QA_AUTO. Nếu cần script live trên DB sales_test, có thể tạo riêng ở Step 23.3C nhưng không commit.

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu hàng loạt? | ❌ Không |
| Có sửa service core? | ❌ Không (Moving/Debt/StockMovement nguyên vẹn) |
| Có sửa schema? | ❌ Không |
| Có rollback plan? | ✅ `git revert` commit 23.3 hoặc reset về tag `sales-return-cancel-e2e-clean-20260505` |
| Tác động backward compat? | ✅ Không. Validation thêm là fail-fast cho payload sai; payload hợp lệ (đúng UI hiện tại) vẫn pass. |

### Deploy commands

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git fetch origin --tags
git checkout main
git pull origin main
composer install --no-dev --optimize-autoloader
composer dump-autoload --optimize
php artisan migrate --force          # KHÔNG có migration mới
npm ci
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
sudo systemctl reload php-fpm
```

---

## 9. Manual QA checklist (sau deploy)

### Nhập hàng

- [ ] Nhập hàng thường qty=5, paid một phần → stock +5, công nợ NCC tăng đúng phần còn nợ, có CashFlow expense.
- [ ] Nhập hàng thường + chi phí khác → unit_cost_allocated phân bổ đúng, cost BQ tăng theo.
- [ ] Nhập hàng serial qty=2 chỉ điền 1 serial → toast lỗi `count===quantity`, không tạo phiếu.
- [ ] Nhập hàng serial 2 dòng có serial trùng → toast lỗi.
- [ ] Nhập hàng serial với serial đã có trong DB → toast lỗi.
- [ ] Nhập hàng serial qty=2 với 2 serial khác nhau → tạo 2 SerialImei in_stock, stock +2.

### Trả hàng nhập

- [ ] Trả hàng thường 1 phần → stock giảm, supplier_debt giảm đúng, có CashFlow income nếu refund > 0.
- [ ] Trả hàng thường vượt số đã nhập → toast lỗi RR-12.
- [ ] Trả hàng serial chọn 1 serial cho qty=2 → toast lỗi `count===qty`.
- [ ] Trả hàng serial chọn serial của phiếu khác → toast lỗi.
- [ ] Trả hàng serial chọn serial đã sold → toast lỗi.
- [ ] Trả hàng serial OK → serial về `returned`, stock giảm, không bán được nữa.

---

## 10. Conclusion

| Mục | Trạng thái |
|---|---|
| Luồng nhập hàng thường an toàn | ✅ |
| Luồng nhập hàng serial an toàn (sau khi vá BUG-1, BUG-2) | ✅ |
| Luồng trả hàng nhập thường an toàn | ✅ |
| Luồng trả hàng nhập serial an toàn (sau khi vá BUG-3, BUG-4, BUG-5, BUG-6) | ✅ |
| Cost snapshot dùng `unit_cost_allocated` lúc nhập | ✅ |
| Test mới + cũ pass | ✅ 48/48 (Step233 cluster) + 48/48 (Sales cluster) |
| Build | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Luồng nhập + trả NCC đã đóng đủ 6 lỗ hổng phát hiện. Hàng thường KHÔNG cần serial. Hàng serial BẮT BUỘC nhập đủ + chọn đúng phiếu nhập. Cost luôn dùng `unit_cost_allocated` snapshot. Không sửa core service nào. Không có migration. Sẵn sàng commit + deploy.
