# HOTFIX 24.17F — Reconcile debt Excel details + cleaner borders + KiotViet-style footer

## 1. Root cause

### 1.1. Doc row Ghi nợ lệch với tổng chi tiết

`debtTransactions()` push `supplier_effect = $p->total_amount` cho entry `pur-*` — **gross** (trước khi trừ `purchases.discount`). HOTFIX 24.17B render doc row K bằng `supplier_effect`.

HOTFIX 24.17D append thêm row "Giảm giá hóa đơn" với `line_total = -$docDiscount` cho operator nhìn. Khi cộng cột J (Thành tiền) các dòng chi tiết:

- `Σ items.subtotal` (mỗi item là `qty*price - line_discount`) — bằng `total_amount`.
- `+ (-docDiscount)` từ row "Giảm giá hóa đơn".
- = `total_amount - docDiscount` = **net** = `debt_amount` của phiếu.

Nhưng doc row K vẫn là `total_amount` (gross). Lệch đúng `docDiscount`. Ảnh tester: `109,913,561` vs `109,913,550` ⇒ `docDiscount = 11đ` (legacy rounding).

### 1.2. Bảng kẻ chi chít

24.17B/C/D mỗi vòng writeRows gọi `$sheet->getStyle('A:L' . $row)->getBorders()->getAllBorders()->setBorderStyle(THIN/HAIR)` → cell nào cũng có 4 viền → bảng nhìn như lưới đen dày.

### 1.3. Chưa có footer

24.17B chỉ có header + table. Tester cần: dòng ngày xuất + 3 chữ ký.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Services/Exports/SupplierDebtExcelExportService.php`](app/Services/Exports/SupplierDebtExcelExportService.php) | edit | (a) Thêm `private array $purchaseDiscounts = []` cache, `preloadPurchaseDiscounts()` batch query. (b) `displayEffectFor($entry)` — net effect cho purchase. (c) `build()` dùng `displayEffectFor` cho period totals + `computeOpeningDebt`. (d) `writeRows()` dùng `displayEffectFor` cho doc K/L; xoá per-row `getAllBorders` styling; return `lastBodyRow`. (e) NEW `applyTableBorders($sheet, $headerRow, $lastBodyRow)` — outer medium + hair-bottom intra-row, không vertical inner. (f) NEW `writeFooter($sheet, $row)` — dòng ngày + 3 signature blocks. |
| [`tests/Feature/Supplier/HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest.php`](tests/Feature/Supplier/HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest.php) | edit | `test_purchase_discount_does_not_change_debt_amount` đổi assertion từ `10_000_000` (gross legacy) → `9_000_000` (net 24.17F). Update inline comment. |
| [`tests/Feature/Supplier/HOTFIX2417FSupplierDebtExcelReconcileAndFooterTest.php`](tests/Feature/Supplier/HOTFIX2417FSupplierDebtExcelReconcileAndFooterTest.php) | NEW | 6 TC pin: doc-detail match, summary-body match, discount row không touch K/L, outer/inner border style, footer text, không có synthetic row khi discount=0. |
| [`docs/audit/HOTFIX-24.17F-SUPPLIER-DEBT-EXCEL-RECONCILE-FOOTER.md`](docs/audit/HOTFIX-24.17F-SUPPLIER-DEBT-EXCEL-RECONCILE-FOOTER.md) | NEW | Báo cáo này. |

**Không sửa:**
- `debtTransactions()` — `supplier_effect` của ledger vẫn là gross `total_amount`. Không tự đổi core ledger (theo PHẦN B/C của brief).
- `recordPayment`/`adjustDebt`/`debtOffset`/Purchase/PurchaseReturn/CashFlow/POS/tồn kho/giá vốn/serial/Migration — không động.
- `loadDetailLines()` — không động (24.17D logic intact, line discount + doc discount synthetic row vẫn đúng).
- Modal FE, controller, route — không động.
- CSV legacy & HOTFIX 24.14 → 24.17E mapping khác — intact.

## 3. Field thực tế dùng để tính

| Khái niệm | Field | Ghi chú |
|---|---|---|
| Line subtotal | `purchase_items.subtotal` | Đã sau line-discount theo schema. |
| Line discount | `purchase_items.discount` | Đã trừ trong subtotal. |
| Doc discount | `purchases.discount` | KHÔNG nằm trong `total_amount`. |
| Doc gross | `purchases.total_amount` = `Σ items.subtotal` | Theo migration comment. |
| Doc net (display) | `total_amount - discount` | = `debt_amount + paid_amount`. |
| `supplier_effect` (ledger) | `total_amount` (gross) | Ledger contract hiện tại — chưa sửa. |
| `purchaseNetForExcel` | `displayEffectFor($entry)` = `supplier_effect - docDiscount` | Display lens. |

## 4. Quyết định scope

**Chỉ sửa Excel display.** Không touch core ledger trong 24.17F. Lý do:

- `debtTransactions()` push gross `supplier_effect` nhưng có thể bù đắp ngầm qua `SupplierDebtTransaction` (type=discount) hoặc nghiệp vụ khác — kiểm tra ngoài scope.
- Brief PHẦN C ghi rõ: "Nếu sau discovery thấy core công nợ thật cũng đang sai do dùng gross thay vì net: Không sửa luôn trong bước này. Ghi vào report".
- **Cần HOTFIX nghiệp vụ riêng** để rà soát: `debtTransactions()` có nên đẩy `supplier_effect = debt_amount` (net) thay vì `total_amount` (gross) cho entry purchase? Cần xác minh full ledger với cả `SupplierDebtTransaction(type=discount)` để không double-count.

Excel 24.17F **internally consistent**: tổng doc rows = `periodDebit/Credit` summary; doc row K = Σ detail J. Số liệu trong Excel khớp 100% với bản thân nó. Số có thể khác `debt_remain` JSON endpoint nếu ledger không bù discount ngầm — note để team kế toán đối chiếu sau.

## 5. Cách tính mới (Excel)

### 5.1. Doc row

```
displayEffectFor(entry):
  effect = entry.supplier_effect
  if entry.id startsWith "pur-":
    effect -= purchaseDiscounts[purchase_id]   // 0 if not set
  return effect

doc_K = max(displayEffect, 0)
doc_L = max(-displayEffect, 0)
```

### 5.2. Detail rows

Không đổi từ 24.17C/24.17D:

- Mỗi `PurchaseItem` → row với `J = subtotal`.
- Nếu `purchases.discount > 0` → append row "Giảm giá hóa đơn" với `G = docDiscount`, `J = -docDiscount`.
- `Σ detail.J = Σ items.subtotal − docDiscount = doc_K`.

### 5.3. Khối tổng hợp (HOTFIX 24.17E intact + 24.17F refinement)

```
opening = Σ displayEffectFor(entries OUT of window)
debit   = Σ max(displayEffectFor(e), 0)  for e IN window
credit  = Σ max(-displayEffectFor(e), 0) for e IN window
closing = opening + debit - credit
```

24.17E layout giữ nguyên: opening/closing trong K (≥0) hoặc L (abs), period trong K + L.

### 5.4. Preload purchase discounts (no N+1)

`preloadPurchaseDiscounts()` chạy 1 lần đầu `build()`. Quét entries lấy mọi `purchase_id` (prefix `pur-`), batch query `Purchase::whereIn('id', $ids)->pluck('discount', 'id')`. Cache vào `$this->purchaseDiscounts[$id] = (float) discount`.

## 6. Cách kẻ border mới

```
applyTableBorders($sheet, $headerRow, $lastBodyRow):
  - Reset toàn bộ vùng A{headerRow}:L{lastBodyRow} → BORDER_NONE.
  - Body rows A{headerRow+1}:L{lastBodyRow} → border BOTTOM = HAIR
    (chỉ kẻ ngang nhẹ giữa các dòng).
  - Outline vùng A{headerRow}:L{lastBodyRow} → BORDER_MEDIUM
    (viền ngoài bao bảng).
  - Header row A{headerRow}:L{headerRow} → border BOTTOM = MEDIUM
    (tách header với body).
```

Kết quả:
- Outer frame: medium.
- Header underline: medium.
- Inter-row separator: hair.
- **Không vertical inner borders** — cột giữ layout bằng width + alignment.

Per-row `setBorderStyle` trong `writeRows()` đã bị xoá hoàn toàn.

## 7. Footer đã thêm

```
[3 blank rows after lastBodyRow]

Row N (merged J:L, center, italic): "Ngày dd tháng mm năm yyyy"
       (lấy now() trong timezone app)

[1 blank row]

Row N+2 (3 blocks):
  A:B center, bold:  "Nhà cung cấp"
  F:G center, bold:  "Người lập biểu"
  J:L center, bold:  "TM Công ty"

Row N+3 (3 blocks):
  A:B center, italic: "(Ký, họ tên)"
  F:G center, italic: "(Ký, họ tên)"
  J:L center, italic: "(Ký, họ tên)"
```

Không truyền tên user vào service (giữ stateless). Operator điền tên tay vào file sau khi mở.

## 8. Test result (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2417FSupplierDebtExcelReconcileAndFooterTest` | ✅ **6 passed / 27 assertions**, 1.79s |
| Full HOTFIX 24.17 chain (F→E→D→C→B→24.17→24.14) | ✅ **42 passed / 172 assertions**, 3.44s |
| `Supplier` | ✅ **65 passed / 281 assertions**, 26.93s |
| `Purchase` | ✅ **33 passed / 127 assertions**, 3.92s |
| `PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.73s |
| `CashFlow` | ✅ **12 passed / 33 assertions**, 22.70s |
| `npm run build` | ✅ **built in 7.95s** |

**6 TC trong HOTFIX2417FSupplierDebtExcelReconcileAndFooterTest:**

1. `test_purchase_doc_row_matches_detail_sum_when_document_discount_exists` — 3 items + docDiscount=11. Doc K = 29,299,989 = Σ(items.J) + discountRow.J.
2. `test_summary_period_debit_matches_visible_document_debits` — 2 purchases (4M + 6M net 5.5M sau discount 500k) + 1 payment 1M. Summary K = 9.5M = Σ doc-row K.
3. `test_discount_row_does_not_touch_debit_credit_columns` — discount row G/J có giá trị, K/L rỗng.
4. `test_table_has_outer_border_and_lighter_inner_borders` — header top/left = MEDIUM, body cột C left ≠ MEDIUM (xác minh không còn kẻ chi chít).
5. `test_footer_contains_export_date_and_signature_blocks` — sheet chứa "Ngày dd tháng mm năm yyyy" + 3 nhãn + "(Ký, họ tên)".
6. `test_purchase_without_discount_has_no_synthetic_row` — `purchases.discount=0` → không có row "Giảm giá hóa đơn" (regression 24.17D).

**Thay đổi TC legacy:** `HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest::test_purchase_discount_does_not_change_debt_amount` đổi expected từ `10_000_000` (gross legacy) sang `9_000_000` (net 24.17F). Đây là **intentional behavior change** — TC cũ pin sai contract (doc K = gross + discount row = double display). Đã ghi rõ inline comment.

## 9. Manual QA — pending tester

- [ ] `/suppliers` → mở NCC có phiếu nhập có giảm giá NCC → tab Công nợ → Xuất file `.xlsx`.
- [ ] File Excel:
  - [ ] Dòng `PN…` Ghi nợ = tổng "Thành tiền" các dòng item + dòng `Giảm giá hóa đơn` âm. **Khớp đến từng đồng.**
  - [ ] Khối tổng hợp `Phát sinh trong kỳ` cột K = tổng cột K của các doc rows trong kỳ.
  - [ ] Bảng có viền ngoài đậm, bên trong chỉ kẻ ngang nhẹ giữa các dòng, không còn lưới rối.
  - [ ] Cuối file: dòng "Ngày dd tháng mm năm yyyy" bên phải.
  - [ ] 3 block chữ ký: Nhà cung cấp / Người lập biểu / TM Công ty, mỗi block có "(Ký, họ tên)" italic phía dưới.
- [ ] Phiếu nhập KHÔNG giảm giá:
  - [ ] Không có row "Giảm giá hóa đơn".
  - [ ] Doc K = Σ items.subtotal.
- [ ] Bán hàng (24.17C) vẫn có mã/tên hàng + serial.
- [ ] Custom date dd/mm/yyyy (24.17C) vẫn nhận đúng.
- [ ] Thanh toán nằm cột Ghi có (L).
- [ ] Xuất lịch sử nhập/trả hàng tab History vẫn ra CSV bình thường.
- [ ] Console không lỗi.

## 10. Rủi ro còn lại

- **Công nợ hiển thị vs ledger gốc:** Excel render `net` cho dòng purchase; `debt_remain` JSON endpoint vẫn dựa trên `supplier_effect = gross`. Nếu hệ thống không có offset ngầm, summary Excel có thể khác `debt_remain` JSON khi NCC có nhiều phiếu giảm giá. **Cần HOTFIX nghiệp vụ riêng để rà soát core ledger** — không trong scope 24.17F.
- **CashFlow:** ✅ KHÔNG động — 12 TC PASS.
- **Purchase / PurchaseReturn:** ✅ KHÔNG động — 33 + 14 TC PASS.
- **HOTFIX 24.14 / 24.17 / 24.17B / 24.17C / 24.17E:** ✅ Intact — 42 TC regression PASS.
- **HOTFIX 24.17D TC-03:** Đã update intentionally để pin contract mới (net). 3 TC khác của 24.17D vẫn PASS không đổi.
- **Performance:** `preloadPurchaseDiscounts()` thêm 1 batch query (`whereIn`) cho mỗi export — O(số purchases trong full ledger). Không N+1.
- **Footer date:** lấy `Carbon::now()` trong timezone app — phù hợp với expectation "ngày xuất file".

## 11. Commit & deployment

- **Commit SHA:** `f356613` — `fix(suppliers): reconcile debt Excel details and add footer`.
- **Push status:** ✅ đã push, `origin/main` = `f3566132972a7ba854a0c49b71eafbcbbf190783`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
# FE không đổi ở 24.17F — không cần rebuild bắt buộc.
# Hard reload trình duyệt (Ctrl+Shift+R).
```

## 12. Kết luận

- **Doc row khớp tổng chi tiết chưa?** ✅ Có — TC-01 pin tới từng đồng (giảm 11đ vẫn khớp).
- **Khối tổng hợp khớp body chưa?** ✅ Có — TC-02 pin Σ visible K = summary K.
- **Bảng đẹp hơn chưa?** ✅ Có — outer medium + inner hair, không vertical, header có underline đậm.
- **Có footer ngày + chữ ký chưa?** ✅ Có — TC-05 pin đủ 5 thành phần.
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** ✅ KHÔNG ảnh hưởng nghiệp vụ — 124 TC regression đều xanh. Có chú thích về Excel display vs ledger gross trong §10 cho team kế toán đối chiếu.
- **Có thể deploy không?** **Code đã sẵn sàng** — 6 TC mới + 42 TC HOTFIX chain regression + 124 TC Supplier/Purchase/PR/CashFlow PASS, build PASS. Browser QA §9 cần tester confirm.
