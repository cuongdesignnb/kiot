# STEP 23.4 — StockTake / Inventory Adjustment Audit

**Date:** 2026-05-05
**Branch:** main (chưa commit)
**Scope:** Tạo phiếu kiểm kho draft/balanced (POST `/stock-takes`), sửa draft (PUT), cân bằng draft (POST `/balance`), hủy phiếu (POST `/cancel`).
**Goal:** Verify quy tắc nghiệp vụ; vá lỗ hổng nếu có; không sửa core service trừ khi có bug rõ.

---

## 1. Discovery

| Luồng | Entry point | Model | Service dùng | Serial xử lý | Stock xử lý | Giá vốn xử lý | StockMovement | Rủi ro |
|---|---|---|---|---|---|---|---|---|
| **Tạo draft kiểm kho** | `StockTakeController@store` (status=draft) ([app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php#L70)) | `StockTake`, `StockTakeItem` | LockPeriodService | N/A | KHÔNG đổi | KHÔNG đổi | KHÔNG ghi | OK — pre-flight server-side, draft chỉ snapshot |
| **Tạo balanced ngay** | Cùng `store` (status=balanced) | + applyAdjustment + StockMovement | `MovingAvgCostingService::applyAdjustment` (line 178) + `StockMovementService::record(ADJUST_IN/OUT)` | N/A trước Step 23.4; vá: chặn `has_serial && diff != 0` | applyAdjustment lock product, cập nhật stock + cost BQ | applyAdjustment dùng BQ hiện tại lúc balance | record với type `adjust_in`/`adjust_out`, qty, unit_cost, ref=StockTake | **BUG-1, BUG-2, BUG-3 vá** |
| **Cân bằng draft** | `StockTakeController@balance` ([app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php#L246)) | Cùng | Cùng | Vá: chặn `has_serial && diff != 0` ngay đầu loop | recompute current_stock từ DB, lock product trong applyAdjustment | Cùng | Cùng | Status check `draft` → 422 nếu khác (chặn 2 lần) |
| **Sửa draft** | `StockTakeController@update` ([app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php#L189)) | `StockTakeItem` | — | N/A | KHÔNG đổi product stock | KHÔNG đổi cost | KHÔNG ghi | Status check draft, chỉ update items |
| **Hủy phiếu** | `StockTakeController@cancel` ([app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php#L313)) | `StockTake` | applyAdjustment(reverse) + StockMovement | Vá: skip serial product (legacy) + log warning | Đảo `actual_stock - system_stock` snapshot từ stock_take_items | Cùng cost_price hiện tại | Movement ngược với note "Hủy kiểm kho" | Status check `cancelled` → 422 (chặn 2 lần). Draft cancel chỉ đổi status |
| **Hàng thường** | Tất cả luồng | — | — | N/A | OK | OK | OK | OK sau patch BUG-1, BUG-2 |
| **Hàng serial** | Tất cả luồng | — | — | Step 23.4 chặn `diff != 0` ở store balanced + balance + cancel skip | OK với diff=0 | OK với diff=0 | Diff=0 không ghi | **Trước Step 23.4: lỗi nặng** — tự động cộng/trừ stock_quantity nhưng không tạo/xóa SerialImei → mismatch nghiêm trọng |

### Routes verified

```
GET    /stock-takes                       stock-takes.index
GET    /stock-takes/create                stock-takes.create
POST   /stock-takes                       stock-takes.store
PUT    /stock-takes/{id}                  stock-takes.update
POST   /stock-takes/{id}/balance          stock-takes.balance
POST   /stock-takes/{id}/cancel           stock-takes.cancel
GET    /stock-takes/{stockTake}           stock-takes.show
GET    /stock-takes/{stockTake}/print     stock-takes.print
GET    /stock-takes/export                stock-takes.export
```

---

## 2. Business rules verified

### 2.1 Draft
- ✅ `actual_stock >= 0` (validate `min:0`).
- ✅ Tạo draft KHÔNG đổi `products.stock_quantity` (TC-23.4-01).
- ✅ Update draft KHÔNG đổi stock (chỉ update items).
- ✅ Không ghi StockMovement khi draft.

### 2.2 Balanced ngay (sau Step 23.4 fix)
- ✅ Backend tự tính `system_stock`/`diff_qty`/`diff_value` từ DB, **KHÔNG tin client** (BUG-1, TC-23.4-05).
- ✅ Chặn duplicate `product_id` trong cùng request (BUG-2, TC-23.4-06).
- ✅ Chặn cân bằng `has_serial && diff != 0` (BUG-3, TC-23.4-09).
- ✅ Diff > 0 → `applyAdjustment(+diff)` + StockMovement `adjust_in` (TC-23.4-03).
- ✅ Diff < 0 → `applyAdjustment(-diff)` + StockMovement `adjust_out` (TC-23.4-04).
- ✅ Diff = 0 → không ghi movement (TC-23.4-10).
- ✅ Lock product row qua `Product::lockForUpdate()` trong `applyAdjustment` (line 184).

### 2.3 Cân bằng draft
- ✅ Recompute `current_stock` từ DB tại thời điểm balance (TC-23.4-07: stock đổi 10 → 12 sau draft, balance vẫn dùng 12).
- ✅ Chặn balance lần 2 (status check `!= draft` → 422; TC-23.4-08).
- ✅ Chặn balance hàng `has_serial && diff != 0` (TC-23.4-11) — phiếu vẫn ở status draft sau fail, không adjust stock.

### 2.4 Hủy phiếu
- ✅ Status check `cancelled` → 422 (chặn cancel 2 lần; TC-23.4-13).
- ✅ Draft cancel: chỉ đổi status, không đụng stock.
- ✅ Balanced cancel: đảo diff từ `stock_take_items` snapshot (TC-23.4-12: 10 → 7 sau balance, → 10 sau cancel; có 2 movement).
- ✅ Hàng `has_serial`: skip rollback + log warning (an toàn, vì Step 23.4 chặn balance ngay từ đầu nên trường hợp này chỉ xảy ra với legacy data).

### 2.5 Cost snapshot
- ✅ `MovingAvgCostingService::applyAdjustment` dùng `cost_price` BQ hiện tại của product (đúng nghiệp vụ).
- ✅ `StockMovement.unit_cost` = cost_price snapshot lúc balance (TC-23.4-14: đổi cost sau balance không ảnh hưởng movement cũ).

---

## 3. Bugs found & fix

| Mã lỗi | Mô tả | Mức độ | File | Cách xử lý |
|---|---|---|---|---|
| **BUG-23.4-1** | `store(balanced)` tin client `system_stock`/`diff_qty`/`diff_value`. Client gửi `system_stock=999` trong khi DB=10 sẽ adjust stock sai. | **Cao** | [app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php#L80) | Pre-flight tự tính từ DB, ignore client values. |
| **BUG-23.4-2** | Không chặn duplicate `product_id` trong cùng phiếu ⇒ 2 dòng cùng SP gây double-adjust. | **Cao** | Cùng | `$seenProductIds` map. |
| **BUG-23.4-3** | Hàng `has_serial` cân bằng `diff != 0` qua store/balance ⇒ stock_quantity bị adjust nhưng KHÔNG tạo/xóa SerialImei tương ứng → mismatch. | **Cao** | Cùng + `balance()` line 261 | Chặn `has_serial && diff !== 0` ở cả store(balanced), balance(), cancel skip. |
| **BUG-23.4-4** | `cancel()` cho hàng has_serial: applyAdjustment đảo stock nhưng không khôi phục SerialImei status. | **Cao** | Cùng `cancel()` line 320 | Skip + log warning (sẽ không xảy ra vì BUG-3 chặn balance từ đầu; chỉ áp cho legacy). |

### KHÔNG vá (nguyên tắc Step 23.4)

- `MovingAvgCostingService::applyAdjustment` — đã lock product row, BQ tính đúng, không có bug rõ.
- `StockMovementService` — type `ADJUST_IN`/`ADJUST_OUT` đã có, không cần thêm.
- Schema — không thêm migration; không động dữ liệu prod.
- UI Vue — Step 23.4 không sửa frontend. Backlog: cảnh báo UI khi product `has_serial` để hướng user dùng phiếu nhập/trả NCC thay vì kiểm kho.

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/StockTakeController.php](app/Http/Controllers/StockTakeController.php) | + Pre-flight Step 23.4 trong `store()`: server-side recompute, dedup product_id, chặn has_serial diff. + `balance()`: chặn has_serial diff. + `cancel()`: skip serial product với log. |
| [tests/Feature/Inventory/Step234StockTakeFlowTest.php](tests/Feature/Inventory/Step234StockTakeFlowTest.php) | NEW — 13 tests, 44 assertions. |
| [docs/audit/STEP-23.4-STOCKTAKE-INVENTORY-ADJUSTMENT-AUDIT.md](docs/audit/STEP-23.4-STOCKTAKE-INVENTORY-ADJUSTMENT-AUDIT.md) | NEW — report này. |

KHÔNG sửa: model, migration, MovingAvgCostingService, StockMovementService, Vue page.

---

## 5. Tests

| Test (Step 23.4) | Kết quả |
|---|---|
| TC-23.4-01 `stocktake_draft_should_not_change_stock` | ✅ PASS |
| TC-23.4-03 `stocktake_balanced_normal_increase_should_adjust` | ✅ PASS |
| TC-23.4-04 `stocktake_balanced_normal_decrease_should_adjust` | ✅ PASS |
| TC-23.4-05 `stocktake_should_recompute_server_side_not_trust_client` | ✅ PASS (BUG-1) |
| TC-23.4-06 `stocktake_duplicate_product_lines_should_fail` | ✅ PASS (BUG-2) |
| TC-23.4-07 `balance_draft_should_use_current_stock_at_balance_time` | ✅ PASS |
| TC-23.4-08 `balance_twice_should_fail` | ✅ PASS |
| TC-23.4-09 `stocktake_serial_diff_via_store_should_fail` | ✅ PASS (BUG-3) |
| TC-23.4-10 `stocktake_serial_no_diff_should_pass` | ✅ PASS |
| TC-23.4-11 `balance_serial_diff_should_fail` | ✅ PASS (BUG-3) |
| TC-23.4-12 `cancel_balanced_normal_should_reverse_adjustment` | ✅ PASS |
| TC-23.4-13 `cancel_stocktake_twice_should_fail` | ✅ PASS |
| TC-23.4-14 `stocktake_adjustment_uses_cost_snapshot` | ✅ PASS |

### Regression

| Lệnh | Kết quả |
|---|---|
| `--filter="StockTake\|Inventory\|RR04\|RR05\|Step234"` | ✅ **47 passed**, 173 assertions |
| `--filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|Step232\|Step233"` | ✅ **82 passed**, 2 skipped, 326 assertions |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 7.00s |

---

## 7. E2E self-test

Feature tests gọi `route('stock-takes.store')`, `route('stock-takes.balance')`, `route('stock-takes.cancel')` thực qua HTTP layer với data live MySQL → tương đương E2E QA_AUTO. Không cần script tạm riêng cho Step 23.4.

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu hàng loạt? | ❌ Không |
| Có sửa service core? | ❌ Không (MovingAvg/StockMovement nguyên vẹn) |
| Có sửa schema? | ❌ Không |
| Có rollback plan? | ✅ `git revert` hoặc reset về tag `purchase-return-flow-clean-20260505` |
| Hàng serial chặn nếu chưa có serial detail? | ✅ Có (BUG-3 fix) |
| Tác động backward compat? | ⚠️ User cũ **không thể** cân bằng kiểm kho hàng has_serial qua UI nữa (trước đây làm được nhưng SAI). Đây là fix bảo vệ dữ liệu. Phải hướng dẫn user: dùng phiếu nhập/trả NCC để điều chỉnh hàng serial. |

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

- [ ] Tạo draft kiểm kho hàng thường → tồn KHÔNG đổi.
- [ ] Cân bằng draft hàng thường (qty tăng) → tồn +, có movement adjust_in, cost BQ cập nhật đúng.
- [ ] Cân bằng draft hàng thường (qty giảm) → tồn −, có movement adjust_out.
- [ ] Tạo balanced ngay hàng thường → tương tự.
- [ ] Cùng product 2 dòng → toast lỗi "Sản phẩm bị trùng".
- [ ] Client/Postman gửi `system_stock` SAI → backend vẫn dùng DB stock (verify qua `stock_take_items.system_stock`).
- [ ] Cân bằng hàng has_serial diff != 0 → toast lỗi "chưa hỗ trợ cân bằng chênh lệch nếu không khai báo serial cụ thể".
- [ ] Cân bằng hàng has_serial actual = current → pass, không movement.
- [ ] Cân bằng draft 2 lần → lần 2 báo "Chỉ có thể cân bằng phiếu tạm".
- [ ] Hủy phiếu balanced hàng thường → tồn về cũ, có 2 movement.
- [ ] Hủy 2 lần → lần 2 báo "Phiếu đã bị hủy".

---

## 10. Conclusion

| Mục | Trạng thái |
|---|---|
| Tạo draft an toàn | ✅ |
| Tạo balanced ngay an toàn (sau khi vá BUG-1, BUG-2, BUG-3) | ✅ |
| Cân bằng draft an toàn (sau khi vá BUG-3) | ✅ |
| Hủy phiếu hàng thường an toàn | ✅ |
| Hủy phiếu hàng serial: skip + log (an toàn theo nguyên tắc "không đoán dữ liệu") | ✅ |
| Server-side recompute, không tin client | ✅ |
| Cost snapshot đúng | ✅ |
| Test mới + cũ pass | ✅ 47/47 stocktake + 82/82 cross-flow |
| Build | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Luồng kiểm kho đã đóng đủ 4 lỗ hổng. Backend giờ tự tính diff từ DB. Hàng has_serial bị chặn cân bằng nếu chưa có serial detail (đúng nguyên tắc "không tự đoán serial"). Cancel đảo đúng. Cost snapshot ổn. Không sửa core service. Không có migration. Sẵn sàng commit + deploy.

**Backlog cho Step sau (P3):**
- UI cảnh báo khi product has_serial (hiện chỉ chặn ở backend qua toast).
- UI kiểm kho serial chi tiết (chọn serial cụ thể để đánh dấu lost/missing/found).
