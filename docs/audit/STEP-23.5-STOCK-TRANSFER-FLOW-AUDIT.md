# STEP 23.5 — Stock Transfer Flow Audit

**Date:** 2026-05-05
**Branch:** main (chưa commit)
**Scope:** Tạo phiếu chuyển kho (POST `/stock-transfers`), nhận hàng (POST `/receive`), hủy phiếu (POST `/cancel`).
**Phương án:** Hệ thống hiện chưa có tồn kho riêng theo chi nhánh; chỉ có `products.stock_quantity` global + `stock_movements.branch_id`. Audit này KHÔNG thiết kế `branch_inventory`. Chỉ khóa quy trình chuyển kho theo convention hiện tại.

---

## 1. Discovery

| Luồng | Entry | Cập nhật stock_quantity | StockMovement | Cost snapshot | Serial xử lý | Rủi ro |
|---|---|---|---|---|---|---|
| **Tạo draft** | `store(status=draft)` ([app/Http/Controllers/StockTransferController.php](app/Http/Controllers/StockTransferController.php#L77)) | ❌ Không đổi | ❌ Không ghi | Lưu `cost_at_transfer` cho item | Cho phép draft (không touch tồn) | OK |
| **Tạo transferring** | `store(status=transferring)` | Trừ qty global qua `MovingAvgCostingService::applySale` | `transfer_out` ref `from_branch_id` | snapshot `product.cost_price` lúc create | Vá Step 23.5: chặn nếu `has_serial` | Trước Step 23.5: thiếu duplicate check, thiếu serial guard, server tin client price |
| **Tạo received ngay** | `store(status=received)` | Trừ rồi cộng (net 0) | 1 transfer_out + 1 transfer_in | applySale dùng BQ; applyPurchase dùng `cost_at_transfer` | Vá Step 23.5: chặn `has_serial` | Cùng |
| **Nhận hàng** | `receive($id)` ([app/Http/Controllers/StockTransferController.php](app/Http/Controllers/StockTransferController.php#L222)) | Cộng qty đích = `received_quantity` | `transfer_in` ref `to_branch_id` | dùng `cost_at_transfer` snapshot, fallback current cost (legacy) | N/A (already blocked at store) | Trước Step 23.5: clamp <0 và >quantity âm thầm — BUG-4 |
| **Hủy** | `cancel($id)` ([app/Http/Controllers/StockTransferController.php](app/Http/Controllers/StockTransferController.php#L325)) | Đảo cả 2 phía nếu received; chỉ hoàn nguồn nếu transferring | Reverse movements với `cost_at_transfer` | dùng `cost_at_transfer`, fallback current | N/A | OK (status guard `cancelled` 422) |

### Routes verified

```
GET  /stock-transfers                       stock-transfers.index
GET  /stock-transfers/create                stock-transfers.create
POST /stock-transfers                       stock-transfers.store
POST /stock-transfers/{id}/receive          stock-transfers.receive
POST /stock-transfers/{id}/cancel           stock-transfers.cancel
GET  /stock-transfers/{stock_transfer}      stock-transfers.show
GET  /stock-transfers/{stock_transfer}/print stock_transfers.print
GET  /stock-transfers/export                stock-transfers.export
```

---

## 2. Business rules verified

### 2.1 Draft
- ✅ Cho phép tạo draft kể cả product `has_serial`.
- ✅ KHÔNG đổi tồn, KHÔNG ghi movement.
- ✅ Cancel draft chỉ đổi status, không đảo gì (TC-23.5-12).

### 2.2 Transferring
- ✅ Validate `from_branch_id != to_branch_id` (BUG-1, TC-23.5-01).
- ✅ Validate duplicate `product_id` (BUG-2, TC-23.5-03).
- ✅ Chặn `has_serial` (BUG-3, TC-23.5-16) — backlog 23.5B sẽ làm UI chọn serial.
- ✅ Validate đủ tồn (TC-23.5-06).
- ✅ Trừ tồn nguồn qua `MovingAvgCostingService::applySale` + ghi `transfer_out`.
- ✅ Snapshot `cost_at_transfer` vào item để dùng cho receive/cancel.
- ✅ Backend tự tính `total_quantity` và `total_price = sum(qty * cost_price)`. KHÔNG tin client `price` (BUG-5, TC-23.5-04).

### 2.3 Received ngay
- ✅ Tồn global net = 0 (trừ rồi cộng) (TC-23.5-05).
- ✅ Có cả 2 movement transfer_out + transfer_in.

### 2.4 Receive (nhận hàng từ phiếu transferring)
- ✅ Status check `transferring` → 422 (chặn nhận 2 lần; TC-23.5-11).
- ✅ Validate `received_quantity >= 0` → 422 nếu âm (BUG-4, TC-23.5-09).
- ✅ Validate `received_quantity <= quantity` → 422 nếu vượt (BUG-4, TC-23.5-10). KHÔNG clamp.
- ✅ Partial bắt buộc `receive_note` → 422 nếu thiếu (TC-23.5-08).
- ✅ Cộng tồn đích = `received_quantity` (không phải `quantity`) qua `applyPurchase($costAtTransfer)`.
- ✅ Ghi `transfer_in` với `unit_cost = cost_at_transfer` (BUG-cost, TC-23.5-18).

### 2.5 Cancel
- ✅ Status check `cancelled` → 422 (TC-23.5-15).
- ✅ Draft cancel: chỉ đổi status (TC-23.5-12).
- ✅ Transferring cancel: hoàn tồn nguồn = `quantity` lúc xuất, dùng `cost_at_transfer` (TC-23.5-13).
- ✅ Received cancel: đảo cả 2 phía (TC-23.5-14: tồn về 10 sau cancel).

### 2.6 Cost snapshot
- ✅ `cost_at_transfer` lưu vào `stock_transfer_items` lúc create.
- ✅ Receive dùng `cost_at_transfer` (snapshot), không phải `product.cost_price` hiện tại (TC-23.5-18).
- ✅ Cancel dùng `cost_at_transfer` để đảo đúng cost.

---

## 3. Bugs found & fix

| Mã lỗi | Mô tả | Mức độ | Cách xử lý |
|---|---|---|---|
| **BUG-23.5-1** | `from_branch_id == to_branch_id` được phép → phiếu không có ý nghĩa, vẫn trừ + cộng cùng branch trong stock_movements. | **Trung** | Validate `different:from_branch_id` ở store. |
| **BUG-23.5-2** | Duplicate `product_id` trong cùng phiếu được phép → trừ tồn 2 lần. | **Cao** | Pre-flight `$seenProductIds` map; `withErrors`. |
| **BUG-23.5-3** | Hàng `has_serial` chuyển kho transferring/received → `applySale`/`applyPurchase` đổi `stock_quantity` nhưng KHÔNG đụng `serial_imeis` → mismatch hệ thống serial nghiêm trọng. | **Cao** | Pre-flight: status != draft && product.has_serial → withErrors. Cho phép draft (không đổi tồn). |
| **BUG-23.5-4** | `receive()` clamp `recvQty < 0 → 0`, `recvQty > quantity → quantity` âm thầm → user gửi -5 hay 999 đều "thành công" sai dữ liệu. | **Cao** | Bỏ clamp, return 422 với message rõ. |
| **BUG-23.5-5** | `total_price` = `array_sum(array_column(items, 'price'))` → tin client. | **Trung** | Backend tự tính `qty * cost_price` từ DB. |

### KHÔNG vá

- `MovingAvgCostingService` — `applySale`/`applyPurchase`/`applyPurchaseReturn` đã đúng, không có bug rõ.
- `StockMovementService` — `TYPE_TRANSFER_IN`/`TYPE_TRANSFER_OUT` đã có.
- Schema `stock_transfers`/`stock_transfer_items` — không thêm migration. `cost_at_transfer` cột đã tồn tại từ Step RR-12.
- Vue UI — Step 23.5 không đụng frontend. UI hiện đã hiển thị toast lỗi từ `withErrors`.
- KHÔNG thiết kế `branch_inventory` (theo phương án "an toàn hiện tại").

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/StockTransferController.php](app/Http/Controllers/StockTransferController.php) | + Pre-flight Step 23.5 trong `store()`: validate `to != from`, dedup product, chặn has_serial, server-side total_price. + `receive()`: bỏ clamp, return 422 strict. |
| [tests/Feature/Inventory/Step235StockTransferFlowTest.php](tests/Feature/Inventory/Step235StockTransferFlowTest.php) | NEW — 18 tests, 60 assertions. |
| [docs/audit/STEP-23.5-STOCK-TRANSFER-FLOW-AUDIT.md](docs/audit/STEP-23.5-STOCK-TRANSFER-FLOW-AUDIT.md) | NEW — report này. |

KHÔNG sửa: model, migration, MovingAvgCostingService, StockMovementService, Vue page, SerialAvailabilityService.

---

## 5. Tests / build

### Step 23.5 (mới)

| TC | Kết quả |
|---|---|
| TC-23.5-01 `transfer_from_branch_must_differ_to_branch` | ✅ |
| TC-23.5-02 `transfer_draft_should_not_change_stock_or_movements` | ✅ |
| TC-23.5-03 `transfer_duplicate_product_lines_should_fail` | ✅ |
| TC-23.5-04 `transfer_transferring_should_deduct_stock_and_record_out_movement` (server-side total_price) | ✅ |
| TC-23.5-05 `transfer_received_immediately_should_record_out_and_in_movements` | ✅ |
| TC-23.5-06 `transfer_insufficient_stock_should_fail` | ✅ |
| TC-23.5-07 `receive_full_should_add_stock_and_record_in_movement` | ✅ |
| TC-23.5-08 `receive_partial_requires_note` | ✅ |
| TC-23.5-09 `receive_negative_quantity_should_fail_not_clamp` | ✅ |
| TC-23.5-10 `receive_over_quantity_should_fail_not_clamp` | ✅ |
| TC-23.5-11 `receive_twice_should_fail` | ✅ |
| TC-23.5-12 `cancel_draft_should_not_change_stock` | ✅ |
| TC-23.5-13 `cancel_transferring_should_restore_stock` | ✅ |
| TC-23.5-14 `cancel_received_should_reverse_both_sides` | ✅ |
| TC-23.5-15 `cancel_twice_should_fail` | ✅ |
| TC-23.5-16 `transfer_serial_without_serial_detail_should_fail` | ✅ |
| TC-23.5-17 `transfer_serial_draft_should_not_change_stock` | ✅ |
| TC-23.5-18 `receive_uses_cost_at_transfer_not_current_cost` | ✅ |

### Regression

| Cluster | Kết quả |
|---|---|
| `--filter="StockTransfer\|Transfer\|RR05\|RR12"` | ✅ **40 passed**, 136 assertions |
| `--filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|Step232\|Step233\|Step234"` | ✅ **100 passed**, 2 skipped, 382 assertions |

### Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 6.78s |

---

## 6. Serial policy — current state

**Hiện tại (sau Step 23.5):**

- Phiếu chuyển kho status `transferring`/`received` cho hàng `has_serial` → **bị chặn** ở backend (toast: "Sản phẩm có serial/IMEI: chưa hỗ trợ chuyển kho khi chưa khai báo serial cụ thể").
- Phiếu chuyển kho status `draft` cho hàng `has_serial` → **được phép** (snapshot only, không đụng tồn/serial).
- KHÔNG tự chọn serial. KHÔNG sinh trạng thái mới `in_transit`. KHÔNG đụng `SerialAvailabilityService`.

**Lý do:**

Schema hiện chưa có `stock_transfer_serials` (pivot serial_imei_id ↔ stock_transfer_id) và `serial_imeis.status` enum chưa có `in_transit`. Nếu cho phép chuyển kho `has_serial` bằng số lượng trống thì `stock_quantity` đổi nhưng `serial_imeis` đứng im → mismatch.

---

## 7. Backlog STEP 23.5B — Serial Transfer chi tiết

**Mục tiêu:** Cho phép chuyển kho hàng `has_serial` an toàn theo serial cụ thể.

**Plan:**

1. **Migration:**
   - `serial_imeis.status` thêm enum value `in_transit`.
   - Bảng pivot `stock_transfer_serial` (`stock_transfer_id`, `serial_imei_id`, `received` boolean).
2. **`SerialAvailabilityService`:** thêm rule `in_transit` không bán được, không kiểm kho được.
3. **Controller `store(transferring)`:** nhận `items[].serial_ids[]`, validate serial đang `in_stock` thuộc product, → set `in_transit` + lock.
4. **Controller `receive`:** với mỗi serial trong pivot, nếu được nhận → set `in_stock` (đến đích), nếu thiếu → giữ `in_transit` + ghi note.
5. **Controller `cancel`:** đảo serial về `in_stock` (nguồn).
6. **Vue UI `StockTransfers/Create`:** với product `has_serial`, hiện modal chọn serial cụ thể.
7. **Tests:** ≥ 10 TC mới (chọn đúng serial, sai serial product khác, serial đã sold, partial receive, cancel reverse status).

**KHÔNG làm ở Step 23.5B:** branch-level inventory (vẫn dùng global `stock_quantity` + branch trong `stock_movements`).

---

## 8. Production safety & deploy

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu hàng loạt? | ❌ Không |
| Có sửa service core? | ❌ Không |
| Có sửa schema? | ❌ Không |
| Rollback plan? | ✅ `git revert` hoặc reset về tag `stocktake-adjustment-clean-20260505` |
| Backward compat? | ⚠️ User cũ KHÔNG còn chuyển kho được hàng `has_serial` qua UI nữa. Trước đây làm được nhưng SAI (serial không update). Phải hướng dẫn team kho: dùng draft tạm thời, đợi Step 23.5B. |

### Deploy commands

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git fetch origin --tags
git checkout main
git pull origin main
composer install --no-dev --optimize-autoloader
composer dump-autoload --optimize
php artisan migrate --force          # KHÔNG có migration mới ở Step 23.5
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

- [ ] Tạo draft chuyển kho A→B (hàng thường) → tồn không đổi, không movement.
- [ ] Tạo transferring A→B 3 cái (stock=10) → tồn còn 7, có 1 movement transfer_out.
- [ ] Tạo received ngay A→B 3 cái → tồn vẫn 10, có 2 movement.
- [ ] Cùng product 2 dòng → toast "Sản phẩm bị trùng".
- [ ] from_branch == to_branch → toast "Chi nhánh nhận phải khác chi nhánh chuyển".
- [ ] Tồn không đủ → toast "không đủ tồn kho".
- [ ] Hàng has_serial transferring → toast "chưa hỗ trợ chuyển kho khi chưa khai báo serial cụ thể".
- [ ] Hàng has_serial draft → tạo OK.
- [ ] Receive đủ → status received, có transfer_in.
- [ ] Receive thiếu chưa note → 422.
- [ ] Receive thiếu có note → status received, status note ghép.
- [ ] Receive với qty âm hoặc vượt → 422 (không clamp).
- [ ] Receive 2 lần → lần 2 422.
- [ ] Cancel draft → status cancelled.
- [ ] Cancel transferring → tồn nguồn về cũ.
- [ ] Cancel received → tồn cả 2 phía về cũ.
- [ ] Cancel 2 lần → lần 2 422.

---

## 10. Conclusion

| Mục | Trạng thái |
|---|---|
| Validate from != to | ✅ |
| Dedup product trong phiếu | ✅ |
| Chặn has_serial chuyển không có serial detail | ✅ |
| Receive không clamp âm thầm | ✅ |
| Backend tự tính total_price | ✅ |
| Cost snapshot dùng `cost_at_transfer` | ✅ |
| Cancel đảo đúng cả 2 phía | ✅ |
| Test mới + cũ pass | ✅ 40 stocktransfer + 100 cross-flow |
| Build OK | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Luồng chuyển kho đã đóng đủ 5 lỗ hổng. Backend không tin client, validate strict. Hàng has_serial được bảo vệ (chỉ cho draft) đến khi Step 23.5B làm UI chọn serial chi tiết. Không sửa core service. Không có migration. Sẵn sàng commit + deploy.
