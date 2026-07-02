# HOTFIX 24.17E — Align supplier debt Excel summary with Ghi nợ / Ghi có

## 1. Root cause

`writeSupplierAndSummary()` ở HOTFIX 24.17B đặt row "Phát sinh trong kỳ" như sau:

```php
$sheet->setCellValue('J' . $row, $debit);
$sheet->setCellValue('K' . $row, '');
$sheet->setCellValue('L' . $row, $credit);
```

→ `debit` rơi vào **cột J** (`Thành tiền`) thay vì cột K (`Ghi nợ`). Khi mở file, người dùng thấy tổng tăng nợ nằm dưới header "Thành tiền" — lệch nguyên 1 cột so với body bảng (dòng `Nhập hàng` ghi vào K, dòng `Thanh toán` ghi vào L). 24.17C/24.17D không touch hàm này.

`Nợ đầu kỳ` và `Nợ cuối kỳ` được set ở K nhưng KHÔNG handle sign — số âm cũng đẩy vào K, đọc khó.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Services/Exports/SupplierDebtExcelExportService.php`](app/Services/Exports/SupplierDebtExcelExportService.php) | edit | `writeSupplierAndSummary()` viết lại 3 row trong khối tổng hợp: `period.debit` → K, `period.credit` → L (bỏ ghi vào J); `opening`/`closing` >= 0 → K, < 0 → `abs()` vào L. Thêm right-align cho cột K:L của 3 row tổng hợp. Header bảng K = `Ghi nợ`, L = `Ghi có` không đổi. |
| [`tests/Feature/Supplier/HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest.php`](tests/Feature/Supplier/HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest.php) | NEW | 4 TC pin: debit ở K, credit ở L, opening/closing dương ở K, body row vẫn dùng K/L đúng (regression 24.17B). |
| [`docs/audit/HOTFIX-24.17E-SUPPLIER-DEBT-EXCEL-SUMMARY-ALIGNMENT.md`](docs/audit/HOTFIX-24.17E-SUPPLIER-DEBT-EXCEL-SUMMARY-ALIGNMENT.md) | NEW | Báo cáo này. |

**Không sửa:**
- `debtTransactions()` — `debt_remain`, `supplier_effect`, summary math (opening/debit/credit/closing) intact.
- `loadDetailLines` + 4 nhánh `pur/pret/inv` — không đụng → HOTFIX 24.17C (sale lines) + 24.17D (discount) intact.
- Cách compute opening/debit/credit/closing trong `build()` — chỉ thay vị trí render.
- Layout store-header, title, table-header, freeze pane, format tiền — không động.
- Modal FE, controller, route — không động.
- DB / migration / cashflow / Purchase / PurchaseReturn / tồn kho / giá vốn / serial — không động.

## 3. Mapping mới của khối tổng hợp

| Row | Cột A:B (trái) | Cột I (label) | Cột K (Ghi nợ) | Cột L (Ghi có) |
|---|---|---|---|---|
| 1 | Nhà cung cấp: + name | `Nợ đầu kỳ:` | `opening` nếu ≥ 0 | `abs(opening)` nếu < 0 |
| 2 | Mã NCC: + code | `Phát sinh trong kỳ:` | `periodDebit` | `periodCredit` |
| 3 | Điện thoại: + phone | `Nợ cuối kỳ:` | `closing` nếu ≥ 0 | `abs(closing)` nếu < 0 |

- **Cột J (Thành tiền) ở khối tổng hợp:** trống — TC-01 pin assertion `assertEmpty($row['J'])`.
- **Format:** `#,##0` cho K:L của cả 3 row.
- **Align:** right-align cột K:L.
- **Label cột I:** bold.
- Header bảng phía dưới (cột K = `Ghi nợ`, L = `Ghi có`) không đổi → khối tổng hợp và body bây giờ thẳng cột với nhau.

**Bảo toàn số liệu:** không tính lại `periodDebit` / `periodCredit` / `opening` / `closing`. Chỉ thay vị trí cell trong sheet.

**Số âm:** opening hoặc closing < 0 (NCC nợ ngược DN) → đưa `abs()` sang L thay vì để dấu `-` ở K. Hợp lý vì cột "Ghi nợ" theo định nghĩa kế toán không nên carry số âm.

## 4. Test result (MySQL:3319 thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest` | ✅ **4 passed / 22 assertions**, 0.68s |
| HOTFIX2417D + 24.17C + 24.17B + 24.17 + 24.14 regression | ✅ **32 passed / 123 assertions**, 2.44s |
| `Supplier` | ✅ **59 passed / 254 assertions**, 27.14s |
| `Purchase` | ✅ **31 passed / 121 assertions**, 3.61s |
| `PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.59s |
| `CashFlow` | ✅ **12 passed / 33 assertions**, 21.85s |
| `npm run build` | ✅ **built in 6.81s** |

**4 TC trong HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest:**

1. `test_summary_period_debit_is_under_ghi_no_column` — `period.debit = 7,000,000` → cột K, J rỗng.
2. `test_summary_period_credit_is_under_ghi_co_column` — `debit=7,000,000` → K; `credit=2,000,000` → L.
3. `test_opening_and_closing_debt_align_with_summary_debt_column` — `opening=0` ở K, L rỗng; `closing=4,000,000` ở K, L rỗng.
4. `test_body_document_rows_still_use_k_and_l` — regression 24.17B: dòng `Nhập hàng` debit ở K, L rỗng; dòng `Thanh toán` credit ở L, K rỗng.

## 5. Manual QA — pending tester

- [ ] `/suppliers` → mở NCC có tăng nợ + thanh toán → tab Công nợ → Xuất file `.xlsx`.
- [ ] Mở file Excel/WPS:
  - [ ] Header bảng cột K = `Ghi nợ`, L = `Ghi có` (không đổi).
  - [ ] Khối tổng hợp: số "Phát sinh trong kỳ" tăng nợ thẳng cột với "Ghi nợ"; giảm nợ thẳng cột với "Ghi có"; không còn số nằm dưới "Thành tiền".
  - [ ] `Nợ đầu kỳ` và `Nợ cuối kỳ` nằm cùng cột "Ghi nợ" khi dương.
- [ ] Dòng `PN…` vẫn ghi tiền cột `Ghi nợ`.
- [ ] Dòng `PCPN…` vẫn ghi tiền cột `Ghi có`.
- [ ] Dòng hàng hóa của 24.17C (Bán hàng) + 24.17D (Giảm giá hóa đơn) không bị lệch.
- [ ] Custom date dd/mm/yyyy (24.17C) vẫn hoạt động.
- [ ] Console không lỗi.

## 6. Rủi ro còn lại

- **Công nợ:** ✅ KHÔNG ảnh hưởng — chỉ đổi vị trí render, math hoàn toàn không đụng. TC-04 + 59 TC Supplier suite confirm.
- **CashFlow / Purchase / PurchaseReturn:** ✅ KHÔNG động — 12 + 31 + 14 TC PASS.
- **HOTFIX 24.14 / 24.17 / 24.17B / 24.17C / 24.17D:** ✅ Intact — 32 TC regression combined đều PASS.
- **Số âm hiển thị:** opening/closing < 0 đi vào L. Nếu có giai đoạn NCC nợ ngược (DN trả thừa), khối tổng hợp sẽ show số dương ở cột "Ghi có". Đây là convention kế toán. UI nào cần đếm dấu sẽ phải đọc cột L.

## 7. Commit & deployment

- **Commit SHA:** `9d83141` — `fix(suppliers): align debt Excel summary with debit credit columns`.
- **Push status:** ✅ đã push, `origin/main` = `9d8314155b2abda1a992b0ed73a88875c7314054`.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
php artisan optimize:clear
# FE không đổi ở 24.17E — không cần rebuild bắt buộc. Nếu rebuild
# cùng đợt cũng OK. Hard reload trình duyệt (Ctrl+Shift+R).
```

## 8. Kết luận

- **Khối tổng hợp có thẳng cột Ghi nợ / Ghi có chưa?** ✅ Có — TC-01..03 pin.
- **Body bảng vẫn đúng cột không?** ✅ Có — TC-04 regression PASS.
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** ✅ KHÔNG — 116 TC regression đều xanh.
- **Có thể deploy không?** **Code sẵn sàng** — 4 TC mới + 32 TC HOTFIX chain regression + 116 TC Supplier/Purchase/PR/CashFlow PASS, build PASS. Browser QA §5 cần tester confirm.
