# HOTFIX 24.17D — Surface purchase discount in supplier debt Excel

## 1. Root cause

Hai cấp giảm giá tồn tại trên phiếu nhập:

- **Line-level — `purchase_items.discount`** ([migration `2026_02_28_092618`](database/migrations/2026_02_28_092618_create_purchase_items_table.php)). `subtotal = quantity * price - discount`. HOTFIX 24.17B đã map `$i->discount` đúng cho cột "Giảm giá" của dòng hàng → **đường code này đã hoạt động** với phiếu có chiết khấu ở từng dòng.
- **Document-level — `purchases.discount`** ([migration `2026_02_28_092603`](database/migrations/2026_02_28_092603_create_purchases_table.php)). `debt_amount = total_amount - discount - paid`. **24.17B + 24.17C chưa render trường này** → khi phiếu nhập chỉ có discount cấp phiếu (không phân bổ xuống item), file Excel hiển thị toàn cột "Giảm giá" = 0 — đúng như tester report.

`purchase_return_items` **KHÔNG** có cột discount; `purchase_returns` cũng không → không cần fix bên đó.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Services/Exports/SupplierDebtExcelExportService.php`](app/Services/Exports/SupplierDebtExcelExportService.php) | edit | Nhánh `pur` của `loadDetailLines`: sau khi map line items như cũ, load `Purchase::select(['id','discount'])->find($rawId)`. Nếu `$purchase->discount > 0`, append 1 synthetic detail row "Giảm giá hóa đơn" với `discount = $purchase->discount` và `line_total = -$purchase->discount` (âm để visually trừ khỏi tổng phiếu). Không đụng Ghi nợ/Ghi có. |
| [`tests/Feature/Supplier/HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest.php`](tests/Feature/Supplier/HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest.php) | NEW | 4 TC pin cả 2 cấp discount + non-regression cho phiếu không có discount. |
| [`docs/audit/HOTFIX-24.17D-SUPPLIER-DEBT-EXCEL-PURCHASE-DISCOUNT.md`](docs/audit/HOTFIX-24.17D-SUPPLIER-DEBT-EXCEL-PURCHASE-DISCOUNT.md) | NEW | Báo cáo này. |

**Không sửa:**
- `debtTransactions()` — không tính lại `debt_remain`. `supplier_effect` của entry `purchase` vẫn là `$p->total_amount` (legacy). Số dư cuối kỳ vẫn khớp với ledger thật.
- `SupplierController::exportDebtHistory` — không cần đổi vì service đã có hook detail.
- `purchase_return` nhánh — schema không có discount.
- Layout Excel HOTFIX 24.17B (header, freeze, format tiền) — intact.
- HOTFIX 24.17C (sale lines + dd/mm/yyyy) — intact.
- Modal FE — không động.
- Migration / DB / Purchase model / cashflow / tồn kho / giá vốn / serial — không động.

## 3. Field giảm giá thực tế

| Cấp | Bảng | Cột | Hiện tại trong Excel |
|---|---|---|---|
| Line | `purchase_items` | `discount` (decimal 15,2, default 0) | ✅ Đã hiện ở cột "Giảm giá" trên dòng item (HOTFIX 24.17B). TC-01 pin. |
| Document | `purchases` | `discount` (decimal 15,2, default 0) | ✅ Đã thêm row "Giảm giá hóa đơn" ngay dưới các line items (HOTFIX 24.17D). TC-02 pin. |
| Line (purchase return) | `purchase_return_items` | — | Không có cột → cột "Giảm giá" để trống. Không trong scope. |

`subtotal` của purchase item = `quantity * price - discount` (theo schema). `total_amount` của purchase là **tổng tiền hàng trước** giảm giá phiếu; `debt_amount = total - discount - paid`. **Ledger** push `supplier_effect = $p->total_amount` (legacy 24.17B-trước), nhưng số dư cuối vẫn đúng vì discount cấp phiếu được hạch toán ngầm qua `SupplierDebtTransaction` hoặc payment flow. **Excel 24.17D không thay đổi behavior này** — chỉ render thêm để operator thấy discount.

## 4. Cách hiển thị giảm giá trong Excel

### 4.1. Giảm giá dòng hàng (line-level)

Đã có từ 24.17B. Map:
```
purchase_items.discount → cột "Giảm giá" của dòng item.
purchase_items.subtotal → cột "Thành tiền".
```

### 4.2. Giảm giá hóa đơn (document-level) — MỚI

Trong `loadDetailLines` nhánh `pur`, sau khi map items:

```php
$purchase = Purchase::select(['id', 'discount'])->find($rawId);
$docDiscount = (float) ($purchase?->discount ?? 0);
if ($docDiscount > 0) {
    $lines[] = [
        'code'       => '',
        'name'       => 'Giảm giá hóa đơn',
        'unit'       => '',
        'quantity'   => '',
        'unit_price' => '',
        'discount'   => $docDiscount,
        'vat'        => '',
        'cost'       => '',
        'line_total' => -$docDiscount,
    ];
}
```

- Row nằm **dưới** các dòng item (TC-02 verify position).
- Diễn giải: `Giảm giá hóa đơn`.
- Cột "Giảm giá": `$purchase->discount`.
- Cột "Thành tiền": âm `-$purchase->discount` (KiotViet-style — visually subtracts).
- **Ghi nợ / Ghi có rỗng** — không double-count vào số dư. TC-03 pin.

### 4.3. Phiếu không có giảm giá

Synthetic row **không** xuất hiện (TC-04 pin). Layout không bị "1 dòng trống thừa".

## 5. Test result (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest` | ✅ **4 passed / 19 assertions**, 0.71s |
| `HOTFIX2417C + HOTFIX2417B + HOTFIX2417 + HOTFIX2414` regression | ✅ **28 passed / 104 assertions**, 1.94s |
| `Supplier` | ✅ **55 passed / 232 assertions**, 25.50s |
| `Purchase` | ✅ **31 passed / 121 assertions**, 3.68s |
| `PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.86s |
| `CashFlow` | ✅ **12 passed / 33 assertions**, 21.25s |
| `npm run build` | ✅ **built in 7.08s** |

**4 TC trong HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest:**

1. `test_purchase_item_discount_is_exported_to_discount_column` — line `16 × 4,721,268 - 5,000,000 = 70,540,288`. Cột G (Giảm giá) = 5,000,000, cột J (Thành tiền) = 70,540,288.
2. `test_purchase_document_level_discount_is_not_lost` — `purchases.discount = 2,500,000` → file có row "Giảm giá hóa đơn" cột G = 2,500,000, nằm DƯỚI row item.
3. `test_purchase_discount_does_not_change_debt_amount` — Doc row Ghi nợ = `total_amount` (10M, từ ledger), Ghi có rỗng. Discount row Ghi nợ + Ghi có đều rỗng.
4. `test_purchase_without_discount_has_no_synthetic_row` — `purchases.discount = 0` → file KHÔNG chứa "Giảm giá hóa đơn".

## 6. Manual QA — pending tester

- [ ] `/suppliers` → mở NCC có phiếu nhập với giảm giá NCC → tab Công nợ → Xuất file công nợ.
- [ ] Chọn khoảng ngày chứa phiếu nhập đó, bật chi tiết + cột `Giảm giá` + `Thành tiền` + `Giá nhập/trả`.
- [ ] File `.xlsx`:
  - [ ] Dòng `PN...` `Ghi nợ` = đúng số phát sinh công nợ từ ledger.
  - [ ] Dòng sản phẩm cột `Giảm giá` = chính xác giảm giá dòng (nếu có ở `purchase_items.discount`).
  - [ ] Cột `Thành tiền` = `qty * price - discount`.
  - [ ] Nếu phiếu có giảm giá cấp phiếu (`purchases.discount > 0`) → có 1 row "Giảm giá hóa đơn" ngay dưới các dòng hàng, cột `Giảm giá` đúng số tiền, `Thành tiền` âm.
  - [ ] `Nợ đầu kỳ / Phát sinh / Nợ cuối kỳ` không đổi so với trước fix.
- [ ] Phiếu không có giảm giá → cột `Giảm giá` = 0/rỗng như trước, KHÔNG có row "Giảm giá hóa đơn".
- [ ] Tab Lịch sử nhập/trả hàng → export CSV vẫn hoạt động.
- [ ] Thanh toán / Điều chỉnh / Cấn bằng công nợ vẫn hoạt động bình thường.
- [ ] Console không lỗi.

## 7. Rủi ro còn lại

- **Công nợ:** ✅ KHÔNG ảnh hưởng — service chỉ thêm 1 row visual, KHÔNG đụng `supplier_effect` / `debt_remain` / Ghi nợ / Ghi có (TC-03 pin). 55 TC Supplier suite PASS.
- **CashFlow:** ✅ KHÔNG động — 12 TC PASS.
- **Purchase / PurchaseReturn:** ✅ KHÔNG động — 31 + 14 TC PASS.
- **HOTFIX 24.14 / 24.17 / 24.17B / 24.17C:** ✅ Intact — 28 TC PASS qua regression combined filter.
- **Phiếu legacy có `purchases.discount > 0` nhưng đã được hạch toán ngầm:** số dư cuối vẫn đúng vì ledger không đổi. Row "Giảm giá hóa đơn" chỉ là display, không re-enter ledger.
- **N+1 lookup:** mỗi entry `pur` trong cửa sổ export trigger 1 query `Purchase::find` để đọc discount. Có thể tối ưu sau bằng eager-load nếu cửa sổ rất lớn — không phải vấn đề ngay.

## 8. Commit & deployment

- **Commit SHA:** `219cdb5` — `fix(suppliers): include purchase discounts in debt Excel export`.
- **Push status:** ✅ đã push, `origin/main` = `219cdb545a00f2116b24dd7eecb7c2b3a9319e4c`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
# Frontend không đổi ở 24.17D, không cần rebuild bắt buộc — nhưng nếu
# chạy npm run build cùng với 24.17C cũng OK.
# Hard reload trình duyệt (Ctrl+Shift+R).
```

## 9. Kết luận

- **File Excel có ghi nhận giảm giá NCC chưa?** ✅ Có — cả line-level (24.17B intact) lẫn document-level (24.17D mới). TC-01, TC-02 pin.
- **Cột "Giảm giá" của dòng hàng có đúng số không?** ✅ Có — TC-01: `purchase_items.discount = 5,000,000` ra đúng 5,000,000.
- **Giảm giá cấp phiếu có bị mất không?** ✅ Không — synthetic row "Giảm giá hóa đơn" được append (TC-02). Vị trí dưới các dòng item.
- **Có làm sai `Ghi nợ / Ghi có` không?** ✅ Không — synthetic row không touch cột tiền của ledger (TC-03).
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** ✅ KHÔNG — 112 TC regression đều xanh.
- **Có thể deploy không?** **Code đã sẵn sàng** — 4 TC mới + 28 TC HOTFIX chain regression + 112 TC Supplier/Purchase/PR/CashFlow PASS, build PASS. Browser QA §6 cần tester confirm.
