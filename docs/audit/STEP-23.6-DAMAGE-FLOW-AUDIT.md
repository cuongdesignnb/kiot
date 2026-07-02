# STEP 23.6 — Damage / Stock Disposal Flow Audit

**Date:** 2026-05-05
**Branch:** main (chưa commit)
**Scope:** Tạo phiếu xuất hủy draft/completed (POST `/damages`), hủy phiếu (POST `/damages/{id}/cancel`).

---

## 1. Discovery

| Luồng | Entry | Model | Service | Serial xử lý | Stock xử lý | Cost/Value | StockMovement | Rủi ro |
|---|---|---|---|---|---|---|---|---|
| **Tạo draft** | `DamageController@store(status=draft)` ([app/Http/Controllers/DamageController.php](app/Http/Controllers/DamageController.php#L100)) | `Damage`, `DamageItem` | — | Lưu `serial_ids` snapshot, không đổi `serial_imeis.status` | KHÔNG đổi | Lưu `cost_price` snapshot | KHÔNG ghi | OK sau patch |
| **Tạo completed** | `store(status=completed)` | + `applyAdjustment(-qty)` + StockMovement | `MovingAvgCostingService::applyAdjustment` + `StockMovementService::record` | Đổi serial_ids sang `defective` + `recomputeFromSerials` | Trừ qty global | Server-side `qty * cost_price` | `adjust_out` ref Damage, branch | **BUG-1..6** |
| **Hủy draft** | `cancel(Damage)` ([app/Http/Controllers/DamageController.php](app/Http/Controllers/DamageController.php#L210)) | Cùng | — | KHÔNG đụng | KHÔNG đụng | KHÔNG đụng | KHÔNG ghi | OK |
| **Hủy completed** | Cùng | + `applyAdjustment(+qty)` + StockMovement | Cùng | Đổi `serial_ids` từ `defective` → `in_stock` + `recomputeFromSerials` | Cộng qty global | Dùng `DamageItem.cost_price` snapshot | `adjust_in` ref Damage | **BUG-7** legacy serial_ids null |
| **Hàng thường** | Tất cả | — | — | N/A | OK sau patch | OK | OK | OK sau BUG-1, BUG-2 |
| **Hàng serial** | Tất cả | — | — | Strict validate count = qty, thuộc product, in_stock, không trùng | OK với serial detail | OK | OK | OK sau BUG-3..6 |

### Routes verified

```
GET  /damages                           damages.index
GET  /damages/create                    damages.create
POST /damages                           damages.store
POST /damages/{damage}/cancel           damages.cancel
GET  /damages/{damage}                  damages.show
GET  /damages/{damage}/print            damages.print
GET  /damages/export                    damages.export
```

---

## 2. Business rules verified

### 2.1 Draft
- ✅ `qty >= 1`, `branch_id` exists, status enum.
- ✅ Lưu phiếu + items, KHÔNG đổi `products.stock_quantity`, KHÔNG đổi `serial_imeis.status` (TC-23.6-01, TC-23.6-02).
- ✅ Hàng serial cho phép draft với `serial_ids` snapshot, không touch trạng thái.
- ✅ Cancel draft chỉ đổi status (TC-23.6-13).

### 2.2 Completed (sau Step 23.6 fix)
- ✅ Pre-flight chặn duplicate `product_id` (BUG-1, TC-23.6-05).
- ✅ Backend tự tính `cost_price = product.cost_price`, `total_value = qty * cost_price` (BUG-2, TC-23.6-06).
- ✅ Validate đủ tồn (TC-23.6-04).
- ✅ Hàng `has_serial`:
  - serial_ids bắt buộc (BUG-3, TC-23.6-08).
  - count(serial_ids) === qty (BUG-4, TC-23.6-07).
  - serial thuộc product (BUG-5a, TC-23.6-09).
  - serial status `in_stock` (BUG-5b, TC-23.6-10).
  - không duplicate trong request (BUG-6, TC-23.6-11).
  - validate STRICT — không filter silent (count thiếu = fail toàn bộ).
- ✅ `applyAdjustment(-qty)` + `SerialImei::update(['status'=>'defective'])` + `recomputeFromSerials` (TC-23.6-12).
- ✅ `StockMovement::record(TYPE_ADJUST_OUT)` với `unit_cost = costPriceBefore`.
- ✅ DamageItem.serial_ids lưu đủ snapshot.

### 2.3 Cancel
- ✅ Chặn cancel 2 lần — `status === CANCELLED` → 422 (TC-23.6-16).
- ✅ Cancel completed normal: `applyAdjustment(+qty)` + StockMovement `adjust_in` với `cost_price` snapshot (TC-23.6-14: stock 7 → 10).
- ✅ Cancel completed serial: serial defective → in_stock + recomputeFromSerials (TC-23.6-15).
- ✅ Legacy: `damage_item.serial_ids` null/empty cho hàng has_serial → **BLOCK** với 422 message (BUG-7, TC-23.6-17). KHÔNG đoán serial.

### 2.4 Cost snapshot
- ✅ `DamageItem.cost_price` lưu `product.cost_price` lúc create.
- ✅ Cancel dùng `DamageItem.cost_price` (snapshot), không phải current.

---

## 3. Bugs found & fix

| Mã | Mô tả | Mức | Cách xử lý |
|---|---|---|---|
| **BUG-23.6-1** | Không chặn duplicate `product_id` trong cùng phiếu → trừ tồn 2 lần. | Cao | Pre-flight `$seenProductIds`. |
| **BUG-23.6-2** | Tin client `cost_price`/`total_value` → có thể ghi `total_value=1` cho qty=3 cost=100k. | Cao | Server-side `cost_price = product.cost_price`, `total_value = qty * cost_price`. |
| **BUG-23.6-3** | Hàng has_serial completed thiếu `serial_ids` → trừ tồn nhưng không đổi serial → mismatch. | Cao | Bắt buộc serial_ids khi has_serial && completed. |
| **BUG-23.6-4** | Hàng has_serial: count(serial_ids) ≠ qty không bị chặn → trừ qty=2 nhưng chỉ đánh dấu 1 serial. | Cao | Validate count === qty. |
| **BUG-23.6-5** | Serial validation silent filter: `whereIn->where(product_id)->where(in_stock)->pluck` rồi lưu phần còn lại; không fail nếu serial sai. | Cao | Strict: nếu count(valid) ≠ count(input) → fail. |
| **BUG-23.6-6** | Duplicate `serial_ids` trong cùng request không bị chặn (qty=2 với cùng 1 serial). | Trung | `array_unique` + check count khác input → fail. |
| **BUG-23.6-7** | Cancel completed cho legacy `damage_item.serial_ids = null` cho hàng has_serial: applyAdjustment +qty cộng tồn nhưng không khôi phục serial → mismatch. | Cao | Block với 422 message rõ. KHÔNG đoán. |

### KHÔNG vá

- `MovingAvgCostingService::applyAdjustment` — đã đúng.
- `StockMovementService` — `TYPE_ADJUST_IN`/`TYPE_ADJUST_OUT` đã có.
- Schema — không thêm migration.
- Vue UI — Step 23.6 không đụng frontend. Toast lỗi từ `withErrors` đã hoạt động.

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/DamageController.php](app/Http/Controllers/DamageController.php) | + Pre-flight Step 23.6 trong `store()`: dedup product, server-side cost, strict serial validation. + `cancel()`: block legacy serial_ids null cho has_serial; trả 422 JSON khi cancel 2 lần. |
| [tests/Feature/Damage/Step236DamageFlowTest.php](tests/Feature/Damage/Step236DamageFlowTest.php) | NEW — 17 tests, 58 assertions. |
| [docs/audit/STEP-23.6-DAMAGE-FLOW-AUDIT.md](docs/audit/STEP-23.6-DAMAGE-FLOW-AUDIT.md) | NEW — report này. |

KHÔNG sửa: model, migration, MovingAvgCostingService, StockMovementService, Vue page.

---

## 5. Tests

| TC | Kết quả |
|---|---|
| TC-23.6-01 `damage_draft_normal_should_not_change_stock_or_movements` | ✅ |
| TC-23.6-02 `damage_draft_serial_should_not_change_serial_or_stock` | ✅ |
| TC-23.6-03 `damage_completed_normal_should_reduce_stock_and_record_adjust_out` | ✅ |
| TC-23.6-04 `damage_insufficient_stock_should_fail` | ✅ |
| TC-23.6-05 `damage_duplicate_product_lines_should_fail` (BUG-1) | ✅ |
| TC-23.6-06 `damage_should_recompute_cost_server_side_not_trust_client` (BUG-2) | ✅ |
| TC-23.6-07 `damage_serial_requires_count_equal_qty` (BUG-4) | ✅ |
| TC-23.6-08 `damage_serial_without_serial_ids_should_fail` (BUG-3) | ✅ |
| TC-23.6-09 `damage_serial_not_product_should_fail` (BUG-5a) | ✅ |
| TC-23.6-10 `damage_serial_not_in_stock_should_fail` (BUG-5b) | ✅ |
| TC-23.6-11 `damage_serial_duplicate_should_fail` (BUG-6) | ✅ |
| TC-23.6-12 `damage_serial_success_should_mark_defective_and_reduce_stock` | ✅ |
| TC-23.6-13 `cancel_draft_damage_should_not_change_stock` | ✅ |
| TC-23.6-14 `cancel_completed_normal_should_restore_stock_and_record_adjust_in` | ✅ |
| TC-23.6-15 `cancel_completed_serial_should_restore_serial_in_stock` | ✅ |
| TC-23.6-16 `cancel_twice_should_fail` | ✅ |
| TC-23.6-17 `cancel_legacy_serial_damage_without_serial_ids_should_not_guess` (BUG-7) | ✅ |

### Regression

| Cluster | Kết quả |
|---|---|
| `--filter="Damage\|RR09\|Inventory\|RR05"` | ✅ **86 passed**, 300 assertions |
| `--filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Step232\|Step233\|Step234\|Step235"` | ✅ **126 passed**, 2 skipped, 464 assertions |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 7.66s |

---

## 7. E2E self-test

Feature tests gọi route thực qua HTTP layer với MySQL live → tương đương E2E QA_AUTO. Các kịch bản negative (BUG-1..7) đều được covered. Không cần script tạm.

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu hàng loạt? | ❌ Không |
| Có sửa core service? | ❌ Không |
| Rollback plan? | ✅ revert hoặc reset về tag `stock-transfer-flow-clean-20260505` |
| Hàng serial bị chặn nếu thiếu serial detail? | ✅ store(completed): bắt buộc count=qty thuộc product in_stock |
| Legacy damage item serial_ids null xử lý? | **Policy A**: BLOCK cancel với 422 message "Phiếu có sản phẩm có serial nhưng không lưu serial_ids snapshot. Không thể tự động hủy; vui lòng xử lý thủ công." Không tự đoán. |
| Backward compat | ⚠️ User cũ KHÔNG còn cancel được phiếu legacy có hàng has_serial mà serial_ids=null. Cần admin xử lý DB tay nếu muốn cancel (cập nhật serial_ids cho item rồi thử lại). |

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

## 9. Manual QA sau deploy

- [ ] Tạo draft xuất hủy hàng thường → tồn không đổi.
- [ ] Tạo completed hàng thường qty=3 stock=10 → stock=7, có movement adjust_out.
- [ ] Hủy completed hàng thường → stock về 10, có movement adjust_in.
- [ ] Tạo completed hàng serial chọn đủ serial → serial defective, stock giảm đúng.
- [ ] Tạo completed serial thiếu serial_ids → toast lỗi "số lượng serial phải bằng số lượng".
- [ ] Tạo completed serial có serial của product khác → toast "serial không hợp lệ".
- [ ] Tạo completed serial có serial sold/defective → toast "phải đang in_stock".
- [ ] Tạo completed serial duplicate → toast "Serial bị trùng".
- [ ] Tạo phiếu cùng product 2 dòng → toast "Sản phẩm bị trùng".
- [ ] Client gửi cost_price=1 → backend lưu cost_price thật từ product.
- [ ] Hủy completed hàng serial → serial defective về in_stock, stock cộng lại.
- [ ] Hủy 2 lần → lần 2 toast "đã bị hủy trước đó".
- [ ] Hủy legacy phiếu có hàng has_serial mà serial_ids null → toast block.

---

## 10. Conclusion

| Mục | Trạng thái |
|---|---|
| Tạo draft an toàn | ✅ |
| Tạo completed an toàn (sau khi vá BUG-1..6) | ✅ |
| Hủy completed an toàn (sau khi vá BUG-7) | ✅ |
| Server-side cost/value | ✅ |
| Strict serial validation | ✅ |
| Legacy data không bị đoán | ✅ |
| Test mới + cũ pass | ✅ 86 damage cluster + 126 cross-flow |
| Build | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Luồng xuất hủy đã đóng đủ 7 lỗ hổng. Backend không tin client, validate serial strict, không filter silent. Cancel cho legacy serial null bị BLOCK an toàn (không đoán). Không sửa core service. Không có migration. Sẵn sàng commit + deploy.

**Backlog (P3):**
- UI Damages/Create cần selector serial cho hàng has_serial (hiện chỉ chặn ở backend qua toast).
- Tool admin để gán serial_ids cho phiếu legacy thiếu snapshot rồi mới cho cancel.
