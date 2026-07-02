# HOTFIX 24.17B — Supplier debt export as KiotViet-style `.xlsx`

## 1. Vấn đề

HOTFIX 24.17 đã có modal chọn thời gian + chọn cột detail, nhưng file ra vẫn là CSV thô. Sếp muốn file phải là `.xlsx` có layout giống KiotViet:

- Header cửa hàng.
- Tiêu đề "Công nợ chi tiết nhà cung cấp".
- Khoảng thời gian.
- Thông tin NCC + khối tổng hợp `Nợ đầu kỳ / Phát sinh trong kỳ / Nợ cuối kỳ`.
- Bảng 12 cột: Thời gian / Mã / Diễn giải / ĐVT / SL / Đơn giá / Giảm giá / VAT / Giá nhập/trả / Thành tiền / Ghi nợ / Ghi có.
- Phiếu nhập có dòng hàng hóa bên dưới, phiếu thanh toán có giá trị ở cột Ghi có.
- Merge, border, format tiền, font đậm cho header.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| `composer.json` / `composer.lock` | edit | Thêm dep `phpoffice/phpspreadsheet:^5.7`. |
| [`app/Services/Exports/SupplierDebtExcelExportService.php`](app/Services/Exports/SupplierDebtExcelExportService.php) | NEW | Service render workbook KiotViet-style. Constructor nhận full ledger entries (đã có `debt_remain` đúng), supplier model, range, includeDetail flag, danh sách cột. `build()` → `Spreadsheet`. `download($filename)` → `StreamedResponse` với MIME xlsx. |
| [`app/Http/Controllers/SupplierController.php`](app/Http/Controllers/SupplierController.php) | edit | (a) `hasQuery` check thêm `'format'`. (b) Validation thêm `'format' => 'nullable|string|in:csv,xlsx'`. (c) Nếu `format=xlsx` → instantiate service, return `download(...)`. Path CSV legacy + path query CSV vẫn intact. |
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | edit | `confirmDebtExport()` thêm `params.set('format', 'xlsx')`. |
| [`tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`](tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php) | NEW | 9 TC pin contract. |
| [`docs/audit/HOTFIX-24.17B-SUPPLIER-DEBT-EXCEL-FORMAT.md`](docs/audit/HOTFIX-24.17B-SUPPLIER-DEBT-EXCEL-FORMAT.md) | NEW | Báo cáo này. |

**Không sửa:**
- `debtTransactions()` — không tính lại `debt_remain`.
- `CsvService` — vẫn dùng cho legacy + CSV-có-query path.
- `recordPayment` / `adjustDebt` / `debtOffset` / Purchase / PurchaseReturn / CashFlow / Invoice / POS / tồn kho / giá vốn / serial.
- `exportPurchaseHistory` (HOTFIX 24.14 lịch sử nhập/trả hàng).
- Modal HOTFIX 24.17 — chỉ thêm 1 param `format=xlsx`.
- Migration — không cần.

## 3. Thư viện Excel

**`phpoffice/phpspreadsheet:^5.7`** (cài bằng `composer require phpoffice/phpspreadsheet`). Chọn vì project chưa có thư viện Excel, và yêu cầu layout chi tiết (merge cell, border, format tiền, freeze pane) — Maatwebsite/Laravel-Excel sẽ là wrapper trên cùng PhpSpreadsheet, thừa cho 1 export.

Không ghi XML xlsx thủ công, không lưu file `public/`, response stream trực tiếp về client.

## 4. Bố cục Excel

**Sheet name:** `CNCT`

**Layout từ trên xuống:**

```
Row 1     LAPTOPPLUS.VN                                           (bold, size 13)
Row 2     Địa chỉ: ...                                            (nếu có)
Row 3     Điện thoại: ...                                         (nếu có)
Row 5     CÔNG NỢ CHI TIẾT NHÀ CUNG CẤP                           (merge A:L, center, bold, size 16)
Row 6     Từ ngày dd/mm/yyyy đến ngày dd/mm/yyyy                  (merge A:L, center, italic)
                                                                   hoặc "Toàn thời gian" nếu không filter

Row 8     Nhà cung cấp: <name>                Nợ đầu kỳ:        <opening>
Row 9     Mã NCC:       <code>                Phát sinh trong kỳ: <debit>  <credit>
Row 10    Điện thoại:   <phone>               Nợ cuối kỳ:       <closing>

Row 12    [Header bảng — bold + border + nền xám nhạt #E7EEF7]
          Thời gian | Mã | Diễn giải | ĐVT | SL | Đơn giá | Giảm giá | VAT | Giá nhập/trả | Thành tiền | Ghi nợ | Ghi có

Row 13+   [Data rows — border thin cho doc rows, border hair cho detail rows]
          Doc row:    Thời gian | PN... | Nhập hàng |     |    |          |          |      |              |            | <debit> | <credit>
          Detail row:              SKU   | Tên SP    | ĐVT | SL | đơn giá  | giảm giá | VAT  | giá nhập/trả | thành tiền |         |
```

**Format:**
- Cột số tiền (`F:L`): `#,##0`.
- Cột "Thời gian" (A): center.
- Cột "Mã" + "Diễn giải" của doc row: **bold**.
- Cột "Diễn giải" của detail row: *italic*.
- Border `thin` cho doc rows, `hair` (mỏng hơn) cho detail rows → phân tầng visually.
- Freeze pane ngay sau header bảng → cuộn dữ liệu mà header vẫn dính.
- Column widths: A=14, B=18, C=35, D=10, E=8, F=14, G=14, H=12, I=14, J=14, K=15, L=15.

**Mapping dòng chứng từ → Ghi nợ / Ghi có:** dựa trên `supplier_effect` từ ledger (đã computed bởi `debtTransactions()`):
- `supplier_effect > 0` (nợ NCC tăng) → cột `Ghi nợ`.
- `supplier_effect < 0` (nợ NCC giảm) → cột `Ghi có` (abs).

| Loại chứng từ | type_label | Effect | Cột tiền |
|---|---|---|---|
| Purchase | `Nhập hàng` | +total | Ghi nợ |
| Payment (PCPN…) | `Thanh toán` | -amount | Ghi có |
| Purchase return | `Trả hàng` | -total | Ghi có |
| Adjustment | `Điều chỉnh` | ± amount | Ghi nợ nếu +, Ghi có nếu - |
| Discount | `Chiết khấu TT` | -amount (theo ledger) | Ghi có |
| Sale (dual-role) | `Bán hàng` | -total | Ghi có |
| Customer payment (dual-role) | `Thanh toán` | +paid | Ghi nợ |
| Loại khác không có line | — | — | Chỉ doc row, không 500 |

**Mapping dòng hàng hóa:** chỉ render khi `include_detail=1` và phiếu có line items:
- Purchase → `PurchaseItem` (`product_code`, `product_name`, `quantity`, `price`, `discount`, `subtotal`).
- Purchase return → `PurchaseReturnItem` (cùng map).
- Invoice (dual-role) → `InvoiceItem` (`product_name`, `quantity`, `price`, `discount`).

Checkbox column toggle (ĐVT, SL, Đơn giá, Giảm giá, VAT, Giá nhập/trả, Thành tiền, Ghi chú dòng) chỉ kiểm soát từng ô có được populate hay không. Cột vẫn xuất hiện trên header → layout vẫn nhất quán, người dùng có thể tự ẩn cột trong Excel.

## 5. Tính `Nợ đầu kỳ / Phát sinh / Nợ cuối kỳ`

Tính trên FULL ledger được hand từ `debtTransactions()` (đã có `debt_remain` đúng):

- **`openingDebt`:**
  - Nếu có `date_from`: tổng `supplier_effect` của tất cả entries có `created_at < from`.
  - Nếu không (preset `all` hoặc không filter): `0` — toàn bộ ledger vào kỳ.
- **`periodDebit`:** tổng `supplier_effect > 0` của entries TRONG khoảng.
- **`periodCredit`:** tổng `|supplier_effect|` (chuyển dương) của entries `< 0` TRONG khoảng.
- **`closingDebt = openingDebt + periodDebit - periodCredit`.**

Quan trọng:
- **KHÔNG** tính lại `debt_remain` trên subset. `debt_remain` của từng row trong ledger gốc vẫn intact (TC HOTFIX 24.15 + 24.17 confirm).
- `closingDebt` được tính từ effects chứ không lấy `debt_remain` của entry cuối — vì nếu có entry sau `to` thì `debt_remain` của entry cuối-trong-cửa-sổ vẫn còn dư phần trước, nhưng sum effects mới ra balance "đến hết ngày `to`". Khi đến entry cuối kỳ thực sự, `closing` sẽ khớp với `debt_remain` tương ứng (vì `opening + Σ effects = debt_remain` theo định nghĩa running balance).

## 6. Backend query params đầy đủ

```
GET /api/suppliers/{id}/export-debt
    ?format=xlsx                    (24.17B - kích hoạt Excel branch)
    &date_preset=today|...|custom   (24.17)
    &date_from=YYYY-MM-DD
    &date_to=YYYY-MM-DD
    &include_detail=0|1
    &columns[]=unit|quantity|unit_price|discount|vat|cost|line_total|note
```

**Backwards compat:**
- Không query → CSV legacy (`Mã chứng từ` / `Còn nợ`).
- Query không có `format=xlsx` (hoặc `format=csv`) → CSV mới HOTFIX 24.17 (`Thời gian` / `Nợ cần trả nhà cung cấp`).
- Query có `format=xlsx` → workbook KiotViet-style (HOTFIX 24.17B).

`from > to` → 422 (validation cũ + check pre-format).

## 7. Test đã chạy thật (MySQL:3319)

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2417BSupplierDebtExcelFormatTest` | ✅ **9 passed / 39 assertions**, 1.18s |
| `php artisan test --filter=HOTFIX2417SupplierDebtExportOptionsTest` + `HOTFIX2414SupplierTabExportTest` | ✅ **14 passed / 53 assertions**, 1.10s |
| `php artisan test --filter=Supplier` | ✅ **46 passed / 201 assertions**, 31.24s |
| `php artisan test --filter=Purchase` | ✅ **27 passed / 102 assertions**, 3.70s |
| `php artisan test --filter=PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.82s |
| `php artisan test --filter=CashFlow` | ✅ **12 passed / 33 assertions**, 25.10s |
| `npm run build` | ✅ **built in 7.69s** |

**9 TC trong HOTFIX2417BSupplierDebtExcelFormatTest:**

1. `test_export_xlsx_returns_excel_response` — Content-Type chứa `spreadsheetml.sheet`, filename `.xlsx`.
2. `test_workbook_has_cnct_sheet` — sheet name = `CNCT`.
3. `test_workbook_has_report_title` — chứa text "Công nợ chi tiết nhà cung cấp".
4. `test_workbook_has_kiotviet_like_headers` — đủ 12 header.
5. `test_purchase_entry_has_line_items` — dòng `PN…` rồi ngay dưới là dòng "Linh kiện 2417B".
6. `test_payment_entry_has_credit_amount` — mã `PCPN…`, `Diễn giải = Thanh toán`, Ghi nợ rỗng, Ghi có = 200,000.
7. `test_custom_date_filter_excludes_out_of_range_entries` — 05/05 in, 20/05 out khi `date_from=2026-05-01&date_to=2026-05-10`.
8. `test_legacy_csv_without_query_still_works` — không query → vẫn CSV cũ.
9. `test_debt_transactions_json_endpoint_unchanged` — JSON shape preserved.

**Lưu ý lệnh test:** không dùng `--env=testing` (override sang config không tồn tại → connection refused). Repo dùng `phpunit.xml` test setup trong env mặc định (MySQL:3319) — pattern các HOTFIX trước.

## 8. Manual QA — pending tester

- [ ] `/suppliers` → mở rộng NCC → tab **Công nợ** → bấm **Xuất file công nợ** → modal mở.
- [ ] Bấm **Đồng ý** → file tải về có đuôi `.xlsx`.
- [ ] Mở file bằng Excel/WPS → có:
  - [ ] Tên cửa hàng ở đầu file.
  - [ ] Tiêu đề "Công nợ chi tiết nhà cung cấp" merge + center bold.
  - [ ] Dòng "Từ ngày … đến ngày …" hoặc "Toàn thời gian".
  - [ ] Khối NCC (tên / mã / SĐT) bên trái.
  - [ ] Khối tổng hợp (Nợ đầu kỳ / Phát sinh / Nợ cuối kỳ) bên phải.
  - [ ] Header 12 cột in đậm có nền nhạt.
  - [ ] Phiếu nhập (PN…) có dòng hàng hóa ngay bên dưới.
  - [ ] Phiếu thanh toán (PCPN…) có số tiền nằm ở cột **Ghi có**.
  - [ ] Cột tiền format `#,##0`, cột Thời gian căn giữa.
- [ ] Chọn `Lựa chọn khác` → custom range → CSV chỉ có giao dịch trong khoảng.
- [ ] Tick chỉ `Số lượng` + `Thành tiền` → các cột khác trong dòng detail trống.
- [ ] Bỏ tick `Chi tiết từng hàng giao dịch` → chỉ có doc rows.
- [ ] Tab Lịch sử nhập/trả hàng → nút **Xuất file** (HOTFIX 24.14) vẫn tải CSV bình thường.
- [ ] Thanh toán / Điều chỉnh / Cấn bằng công nợ vẫn hoạt động, số dư không đổi.
- [ ] Console không lỗi.

## 9. Rủi ro còn lại

- **Công nợ:** ✅ KHÔNG ảnh hưởng — service chỉ đọc full ledger từ `debtTransactions()` rồi render. `debt_remain` không tính lại. Effects sum trong service không động database.
- **CashFlow / Purchase / PurchaseReturn:** ✅ KHÔNG động — 27 + 14 + 12 TC PASS.
- **HOTFIX 24.14 export lịch sử nhập/trả hàng:** ✅ KHÔNG ảnh hưởng — 6 TC vẫn PASS (qua filter `HOTFIX2414SupplierTabExportTest` cùng `HOTFIX2417SupplierDebtExportOptionsTest`).
- **HOTFIX 24.17 modal & CSV-có-query:** ✅ KHÔNG ảnh hưởng — 8 TC vẫn PASS. Modal chỉ thêm `format=xlsx` ngầm khi bấm Đồng ý, không thay đổi UI.
- **Endpoint cũ:** ✅ tương thích — no-query call vẫn trả CSV format cũ (HOTFIX 24.14 contract).
- **Cửa hàng:** chưa có config chính thức → fallback `APP_NAME` từ `config('app.name')`, nếu là default `Laravel` thì fall lại `LAPTOPPLUS.VN` (legacy hard-coded label đã thấy trong `purchaseHistory()`). Không hard-code dữ liệu production fake. Có thể bổ sung config sau.
- **Bộ nhớ:** PhpSpreadsheet load full ledger vào memory. Với NCC có >10k entries có thể chậm — chưa optimize, để watch.

## 10. Commit & deployment

- **Commit SHA:** `41c918e` — `fix(suppliers): export supplier debt as formatted Excel`.
- **Push status:** ✅ đã push, `origin/main` chứa commit này (xác minh qua `git push` output).

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
composer install --no-dev --optimize-autoloader   # phpoffice/phpspreadsheet mới
rm -rf public/build
npm run build
php artisan optimize:clear
# Hard reload trình duyệt (Ctrl+Shift+R)
```

## 11. Kết luận

- **File tải về có phải `.xlsx` chưa?** ✅ Có — TC-01 pin Content-Type + filename. Modal FE gửi `format=xlsx`.
- **Layout có giống KiotViet không?** ✅ Có — sheet `CNCT`, title merge, khối tổng hợp, 12 cột header, doc/detail rows phân tầng. TC-02→TC-04 pin.
- **Phiếu nhập có dòng hàng hóa bên dưới không?** ✅ Có — TC-05 pin (detail row position > doc row position).
- **Phiếu thanh toán có Ghi có không?** ✅ Có — TC-06 pin (`L = 200000`, `K` rỗng).
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** ✅ KHÔNG — toàn bộ regression xanh.
- **Endpoint cũ còn tương thích không?** ✅ Còn — TC-08 pin.
- **Có thể deploy không?** **Code đã sẵn sàng** — 9 TC mới + 14 TC HOTFIX trước + 99 TC Supplier/Purchase/PR/CashFlow PASS, build PASS. Browser QA §8 cần tester confirm.
