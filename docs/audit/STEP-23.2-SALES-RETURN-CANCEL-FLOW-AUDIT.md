# STEP 23.2 — Sales Return + Cancel Return Flow Audit

**Date:** 2026-05-04
**Branch:** main (chưa commit)
**Scope:** Tạo phiếu trả hàng (POST `/returns`) + Hủy phiếu trả hàng (POST `/returns/{id}/cancel`).
**Goal:** Verify 5 quy tắc nghiệp vụ; vá lỗ hổng nếu có; không sửa core service trừ khi có bug rõ.

---

## 1. Discovery

| Luồng | Entry point | Model | Service dùng | Serial xử lý ở | Stock xử lý ở | Debt xử lý ở | Cashflow xử lý ở | Rủi ro |
|---|---|---|---|---|---|---|---|---|
| **Tạo trả hàng thường** | `OrderReturnController@store` ([app/Http/Controllers/OrderReturnController.php](app/Http/Controllers/OrderReturnController.php#L169)) | `OrderReturn`, `ReturnItem` | `MovingAvgCostingService::applySaleReturn` (line 333), `StockMovementService::record(TYPE_IN_INVOICE_RETURN)` (line 348), `CustomerDebtService::recordReturn` (line 372) | N/A | line 333–340 (lock product, applySaleReturn) | line 372 (recordReturn ledger + customers.debt_amount) | line 386–402 (CashFlow type=payment nếu paid_to_customer>0) | RR-11 đã chặn over-return |
| **Tạo trả hàng serial** | Cùng `store` | + `SerialImei` | + per-serial mark `in_stock` (line 341–347) | line 341–347 | line 333–340 | line 372 | line 386–402 | **BUG-1, BUG-2 phát hiện & vá** ở Step 23.2 |
| **Hủy trả hàng thường** | `OrderReturnController@cancel` ([app/Http/Controllers/OrderReturnController.php](app/Http/Controllers/OrderReturnController.php#L494)) | `OrderReturn`, `ReturnItem` | `MovingAvgCostingService::applyPurchaseReturn` (line 519), `StockMovementService::record(TYPE_OUT_INVOICE)` (line 535), `CustomerDebtService::recordAdjustment` (line 555) | N/A | line 519–525 (đảo applySaleReturn) | line 555 (recordAdjustment +amount) | line 567–573 (delete CashFlow theo ref_code) | Status check chặn cancel 2 lần (line 497) |
| **Hủy trả hàng serial** | Cùng `cancel` | + `SerialImei` | + mark serial về `sold` theo `serial_ids` snapshot (RR-08, line 543–553) | line 543–553 | line 519–525 | line 555 | line 567–573 | OK |

**Toàn bộ 4 luồng** bọc trong `DB::transaction` (store: line 226; cancel: line 499) ⇒ rollback sạch khi lỗi.

### Routes verified

```
GET    /returns                       returns.index
POST   /returns                       returns.store
GET    /returns/{return}/show         returns.show
GET    /returns/{return}/print        returns.print
POST   /returns/{return}/cancel       returns.cancel
GET    /returns/export                returns.export
```

---

## 2. Business rules verified

### 2.1 Trả hàng thường
- ✅ Qty ≤ sold_qty − already_returned_qty (RR-11, line 184–225).
- ✅ Stock tăng đúng qty (`applySaleReturn` line 333).
- ✅ `stock_movements.type = 'in_invoice_return'` (line 348).
- ✅ Cost dùng `invoice_item.cost_price` snapshot (line 305–311), không lấy `product.cost_price` hiện tại.
- ✅ Verified bằng TC-23.2-08.

### 2.2 Trả hàng Serial/IMEI (sau Step 23.2 fix)
- ✅ `count(serial_ids) === qty` bắt buộc (mới, BUG-2 vá).
- ✅ Serial phải thuộc `invoice_id` của return (mới, BUG-1 vá).
- ✅ Serial phải đang `status='sold'` (đã có từ trước line 281–286).
- ✅ Không cho serial trùng giữa các dòng cùng request (mới, Step 23.2).
- ✅ Mark `in_stock` chỉ đúng các serial đã pass (line 341–347).
- ✅ `recomputeFromSerials()` audit khớp số lượng (line 351).

### 2.3 Công nợ khách hàng
- ✅ `recordReturn` → ledger row type `return` âm (giảm nợ) + cập nhật `customers.debt_amount` qua service (line 372).
- ✅ `recordAdjustment` ở cancel → ledger row dương khôi phục nợ (line 555).
- ✅ TC-23.2-01 + TC-23.2-05 verify đầy đủ.

### 2.4 Hủy trả hàng
- ✅ Status check chặn cancel 2 lần (line 497).
- ✅ Stock đảo (`applyPurchaseReturn` line 519).
- ✅ Serial về `sold` theo `serial_ids` snapshot RR-08 (line 543–553) — KHÔNG đoán.
- ✅ Debt khôi phục qua `recordAdjustment`.
- ✅ CashFlow xóa theo `reference_code` (line 567–573).
- ✅ Stock movement đảo chiều: tạo row mới `TYPE_OUT_INVOICE` (line 535).
- ✅ TC-23.2-07 verify cancel 2 lần KHÔNG double-reverse (status check ở đầu method ngăn lần 2 vào transaction).

### 2.5 Cost snapshot policy
- ✅ Tạo return: lấy `invoice_item.cost_price` (line 305–311); fallback `product.cost_price` chỉ khi không có invoice_item (manual return).
- ✅ Lưu vào `return_items.cost_price` (line 333) làm snapshot riêng cho phiếu trả.
- ✅ Cancel return: dùng `return_items.cost_price` snapshot (line 517) — không phụ thuộc product.cost_price hiện tại.
- ✅ TC-23.2-08 verify: thay product.cost_price 100k → 250k sau bán, vẫn return + ghi movement với cost = 100k.

### 2.6 Dữ liệu cũ
- ✅ Phiếu trả cũ thiếu `serial_ids` (legacy trước RR-08): cancel skip serial rollback, không fallback đoán bừa (line 553 comment).
- ✅ Migration `2026_05_02_120000_add_serial_ids_to_return_items_table.php` (đã áp prod) thêm cột nullable.

---

## 3. Bugs found & fix

| Mã lỗi | Mô tả | Mức độ | File | Cách xử lý |
|---|---|---|---|---|
| **BUG-23.2-1** | `OrderReturnController@store` không filter `serial.invoice_id` ⇒ user có thể trả serial thuộc invoice KHÁC. Hậu quả: serial của người khác bị chuyển về `in_stock`, đẩy stock dư, debt khách KH này giảm sai. | **Cao** | [app/Http/Controllers/OrderReturnController.php](app/Http/Controllers/OrderReturnController.php#L228) line 282–286 cũ | Thêm pre-flight `where('invoice_id', $invoice_id)` + `count===qty` + chống trùng (line 228–273 mới). Chạy TRƯỚC `DB::transaction`. |
| **BUG-23.2-2** | Không enforce `count(serial_ids) === qty` ⇒ user có thể qty=3, serial_ids=[1] ⇒ stock −3 nhưng chỉ 1 serial bị xử lý. | **Cao** | Cùng file | Cùng pre-flight (count===qty). |
| **BUG-23.2-3** | Cùng serial xuất hiện 2 dòng trong cùng request không bị chặn. | Trung | Cùng file | `$seenSerialIds` map chống trùng trong cùng request. |

### KHÔNG vá (theo nguyên tắc Step 23.2)

- `MovingAvgCostingService` — không có bug rõ (TC-23.2-08 confirm cost snapshot chính xác).
- `CustomerDebtService` — RR-06 đã verify; recordReturn/recordAdjustment dùng đúng dấu.
- `StockMovementService` — TYPE đã có `IN_INVOICE_RETURN` (in) + `OUT_INVOICE` (cancel). Không cần TYPE mới.
- Schema — không thêm migration; không động dữ liệu prod.
- Race condition cancel-2-process song song: status check ngoài transaction là known limitation, P3, ngoài scope.

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/OrderReturnController.php](app/Http/Controllers/OrderReturnController.php) | + ~50 dòng pre-flight Step 23.2: count===qty, invoice_id filter, chống trùng. Đặt sau RR-11 validation, trước `DB::transaction`. KHÔNG động `cancel` method. |
| [tests/Feature/OrderReturn/Step232SalesReturnFlowTest.php](tests/Feature/OrderReturn/Step232SalesReturnFlowTest.php) | NEW — 8 test, 33 assertions. |
| [docs/audit/STEP-23.2-SALES-RETURN-CANCEL-FLOW-AUDIT.md](docs/audit/STEP-23.2-SALES-RETURN-CANCEL-FLOW-AUDIT.md) | NEW — report này. |

KHÔNG sửa: `OrderReturn`/`ReturnItem` model, migration, MovingAvg/CustomerDebt/StockMovement service, Returns Vue pages.

---

## 5. Tests

| Test | Kết quả |
|---|---|
| TC-23.2-01 `return_normal_product_should_restore_stock_and_reduce_debt` | ✅ PASS |
| TC-23.2-02 `return_serial_product_should_mark_serial_in_stock` | ✅ PASS |
| TC-23.2-03 `return_serial_not_in_invoice_should_fail` | ✅ PASS (xác minh BUG-1 vá) |
| TC-23.2-04 `return_more_than_sold_qty_should_fail` | ✅ PASS |
| TC-23.2-05 `cancel_return_normal_product_should_reverse_stock_and_debt` | ✅ PASS |
| TC-23.2-06 `cancel_return_serial_should_mark_serial_back_to_sold` | ✅ PASS |
| TC-23.2-07 `cancel_return_twice_should_fail` | ✅ PASS |
| TC-23.2-08 `return_uses_invoice_item_cost_price_not_current_product_cost` | ✅ PASS |

### Regression

| Lệnh | Kết quả |
|---|---|
| `php artisan test --env=testing --filter="RR08\|RR11"` | ✅ **8 passed**, 23 assertions, 1.0s |
| `php artisan test --env=testing tests/Feature/OrderReturn/Step232SalesReturnFlowTest.php` | ✅ **8 passed**, 33 assertions, 1.45s |
| `php artisan test --env=testing --filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR13\|SerialAvailability\|RequireSerial\|Step232\|CustomerSearch\|Order"` | ✅ **57 passed**, 2 skipped, 238 assertions |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `npm run build` | ✅ built in 6.12s |

---

## 7. Manual QA checklist (sau deploy)

### Tạo phiếu trả

- [ ] Bán hàng thường rồi trả 1 phần → tồn tăng đúng, công nợ giảm, ledger có dòng `return`.
- [ ] Bán hàng thường thanh toán đủ → trả → có CashFlow `payment`.
- [ ] Bán hàng serial → trả đúng serial đã chọn → serial đó về `in_stock`, các serial khác vẫn `sold`.
- [ ] Trả serial KHÔNG thuộc hóa đơn → toast lỗi, không tạo phiếu.
- [ ] Trả qty=2 chỉ chọn 1 serial → toast lỗi `count===qty`, không tạo phiếu.
- [ ] Trùng cùng serial 2 dòng → toast lỗi.

### Hủy phiếu trả

- [ ] Hủy phiếu trả hàng thường → tồn giảm lại, công nợ về như cũ, ledger có dòng `adjustment`, CashFlow xóa nếu có.
- [ ] Hủy phiếu trả serial → serial về `sold`, gắn lại `invoice_id`.
- [ ] Hủy 2 lần → lần 2 báo "Phiếu đã bị hủy", không double-reverse.

### Dữ liệu cũ

- [ ] Phiếu trả tạo trước migration RR-08 (`serial_ids` NULL) khi hủy → KHÔNG tự đoán, log bình thường, không lỗi.
- [ ] Báo cáo công nợ KH vẫn đầy đủ (lịch sử cũ + ledger mới).

---

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có update dữ liệu hàng loạt? | ❌ Không |
| Có sửa service core? | ❌ Không (Moving/Debt/StockMovement nguyên vẹn) |
| Có sửa schema? | ❌ Không |
| Có rollback plan? | ✅ `git revert` commit 23.2 hoặc reset về tag `invoice-pos-serial-guard-20260504` |
| Tác động backward compat? | ✅ Không. Validation thêm là fail-fast cho payload sai; payload hợp lệ (đúng UI hiện tại) vẫn pass. |

### Deploy commands (sau khi commit + push)

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
composer dump-autoload
php artisan migrate --force          # KHÔNG có migration mới
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart            # nếu có queue worker
```

---

## 9. Conclusion

| Mục | Trạng thái |
|---|---|
| Luồng tạo trả hàng thường an toàn | ✅ |
| Luồng tạo trả hàng serial an toàn (sau khi vá BUG-1, BUG-2, BUG-3) | ✅ |
| Luồng hủy trả hàng (cả 2 loại) an toàn | ✅ |
| Cost snapshot dùng đúng giá lúc bán | ✅ |
| Test mới + cũ pass | ✅ 57/57 (2 skipped) |
| Build | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Luồng trả hàng + hủy trả hàng đã đóng đủ 3 lỗ hổng phát hiện (serial không thuộc invoice, count≠qty, trùng serial). Hàng thường KHÔNG cần serial. Hàng serial BẮT BUỘC chọn đủ + thuộc đúng invoice. Hủy chỉ thực thi 1 lần. Cost luôn dùng snapshot.
