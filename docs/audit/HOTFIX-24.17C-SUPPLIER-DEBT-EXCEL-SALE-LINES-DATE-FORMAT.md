# HOTFIX 24.17C — Sale detail lines + Vietnamese `dd/mm/yyyy` for debt export

## 1. Root cause

### 1.1. Dòng `Bán hàng` thiếu mã/tên hàng

`SupplierDebtExcelExportService::loadDetailLines()` nhánh `inv` ở HOTFIX 24.17B đã viết:

```php
'name' => $i->product_name ?? '',
'code' => '',
```

Nhưng bảng `invoice_items` chỉ có 5 cột nghiệp vụ: `invoice_id`, `product_id`, `quantity`, `price`, `cost_price`, `serial` ([migration `2026_02_27_163710_create_invoice_items_table`](database/migrations/2026_02_27_163710_create_invoice_items_table.php) + 2 migration sau bổ sung `cost_price` và `serial`). **KHÔNG có `product_name` cũng KHÔNG có `product_code`** → mọi field truy cập bằng magic getter đều trả `null` → mỗi dòng detail bán hàng đều rỗng.

Phiếu **nhập hàng** ở nhánh `pur` không bị (vì `purchase_items` thực sự lưu denormalized `product_name` + `product_code`) nên trông không thấy bug nếu test chỉ pin Purchase.

### 1.2. Modal hiện ngày kiểu Mỹ `04/30/2026`

Modal HOTFIX 24.17 dùng `<input type="date">`. Trình duyệt render theo locale OS — nếu OS là `en-US`, hiển thị thành `MM/DD/YYYY`; hơn nữa, browser native date picker không **bắt buộc** locale `vi-VN`. Người dùng VN thấy `04/01/2026` không biết là 4 tháng 1 hay 1 tháng 4. Đồng thời nếu copy/paste / autofill mất kiểm soát thì backend `Carbon::parse('01/04/2026')` cũng parse **kiểu Mỹ** (Jan 4) → lệch ngày.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`app/Services/Exports/SupplierDebtExcelExportService.php`](app/Services/Exports/SupplierDebtExcelExportService.php) | edit | Nhánh `inv` của `loadDetailLines`: eager-load `InvoiceItem::with('product:id,sku,name')`, resolve `code = product.sku`, `name = product.name`, append `serial` vào tên nếu có, `cost = cost_price` (snapshot lúc bán), `line_total = price * quantity`. |
| [`app/Http/Controllers/SupplierController.php`](app/Http/Controllers/SupplierController.php) | edit | (a) Bỏ rule `\|date` cho `date_from`/`date_to`; thay bằng regex chấp nhận `YYYY-MM-DD` HOẶC `dd/mm/yyyy`. (b) Thêm guard `parseExportDate()` reject ngày không hợp lệ (31/02…) → 422. (c) `resolveDebtExportRange` case `custom` dùng `parseExportDate` thay vì `Carbon::parse` ambiguous. (d) NEW helper `parseExportDate(?string $value): ?Carbon` strict, không bao giờ rơi vào parse Mỹ. |
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | edit | (a) Input custom date `type="date"` → `type="text"` + `placeholder="dd/mm/yyyy"` + `inputmode="numeric"` + `maxlength="10"`. (b) Thêm helper `parseVietnameseDateToIso(value)` validate + convert `dd/mm/yyyy` → `YYYY-MM-DD` (kiểm tra `checkdate` qua probe `Date`). (c) Computed `debtExportCustomDatesValid` gate nút "Đồng ý". (d) `confirmDebtExport` rewrite `date_from`/`date_to` thành ISO ngay trước khi gửi. (e) Viền đỏ nếu input không hợp lệ + dòng hint tiếng Việt. |
| [`tests/Feature/Supplier/HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest.php`](tests/Feature/Supplier/HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest.php) | NEW | 5 TC pin contract sale lines + dd/mm/yyyy. |
| [`docs/audit/HOTFIX-24.17C-SUPPLIER-DEBT-EXCEL-SALE-LINES-DATE-FORMAT.md`](docs/audit/HOTFIX-24.17C-SUPPLIER-DEBT-EXCEL-SALE-LINES-DATE-FORMAT.md) | NEW | Báo cáo này. |

**Không sửa:** `debtTransactions()`, `recordPayment`, `adjustDebt`, `debtOffset`, Purchase / PurchaseReturn / CashFlow / POS / tồn kho / giá vốn / serial, modal HOTFIX 24.17 (chỉ đổi input + helper). Layout Excel HOTFIX 24.17B intact.

## 3. Cách resolve `InvoiceItem` → mã/tên hàng

```php
InvoiceItem::with('product:id,sku,name')->where('invoice_id', $rawId)->get()
  ->map(fn ($i) => [
      'code'       => $i->product?->sku ?? '',
      'name'       => trim($i->product?->name . ($i->serial ? ' (' . $i->serial . ')' : '')),
      'quantity'   => (int) $i->quantity,
      'unit_price' => (float) $i->price,
      'cost'       => $i->cost_price !== null ? (float) $i->cost_price : (float) $i->price,
      'line_total' => (float) $i->price * (int) $i->quantity,
      // discount/vat/unit không tồn tại trên schema → '' / 0.
  ]);
```

- **Mã hàng:** `product.sku` (qua eager-load). Không có sku rỗng → blank ô — đúng, không hard-code.
- **Tên hàng:** `product.name`. Nếu invoice item có `serial` (text, có thể CSV nhiều serial), append `(serial)` vào tên để operator audit được máy nào đã ra kho. Không phá mapping discount/vat cũ.
- **Giá vốn:** field `cost_price` đã snapshot lúc bán ([migration `2026_03_27`](database/migrations/2026_03_27_000001_add_cost_price_to_invoice_items_table.php)). Lấy thẳng. Nếu `null` (legacy data) → fall về `price` (đã ghi rõ trong audit). Không tự tính giá vốn nếu không chắc.
- **Thành tiền:** `price × quantity`. `invoice_items` không có cột `discount` per-line → để 0 cho cột "Giảm giá" (phù hợp schema hiện tại).
- **Số dòng:** nếu hóa đơn có N items → emit N dòng detail dưới dòng `HD…`. TC-02 pin.
- **Không có item:** return `[]` → chỉ doc row, không 500. (Đã có sẵn từ 24.17B.)

## 4. Cách parse/format ngày `dd/mm/yyyy`

### 4.1. Frontend (`Suppliers/Index.vue`)

```js
const parseVietnameseDateToIso = (value) => {
    if (!value) return null;
    const m = String(value).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return null;
    const dd = +m[1], mm = +m[2], yyyy = +m[3];
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
    const probe = new Date(yyyy, mm - 1, dd);
    if (probe.getFullYear() !== yyyy || probe.getMonth() !== mm - 1 || probe.getDate() !== dd) return null;
    return `${yyyy}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
};
```

- Input là `<input type="text" placeholder="dd/mm/yyyy">` — browser không tự render kiểu Mỹ.
- Viền đỏ + hint hiển thị nếu user đã gõ gì đó mà parse fail.
- Computed `debtExportCustomDatesValid` disable nút Đồng ý nếu không pass parse.
- Submit luôn chuyển sang ISO trước khi gửi API → backend nhận ISO ổn định.

### 4.2. Backend (`SupplierController`)

```php
private function parseExportDate(?string $value): ?\Carbon\Carbon
{
    if (!$value) return null;
    $value = trim($value);
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $value, $m)) {
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) return null;
    return \Carbon\Carbon::create($y, $mo, $d, 0, 0, 0);
}
```

- Strict 2 format, **không bao giờ** rơi vào `Carbon::parse` (lib này parse `01/04/2026` theo kiểu Mỹ → Jan 4 sai).
- `checkdate(mo, d, y)` reject 31/02, 30/02, 31/04, 29/02 năm không nhuận → trả 422 chứ không 500.
- Validation rule `regex` chặn input không thuộc 2 dạng → 422 với message tiếng Việt.

## 5. Test result (MySQL:3319, thật)

| Lệnh | Kết quả |
|---|---|
| `HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest` | ✅ **5 passed / 12 assertions**, 0.84s |
| `HOTFIX2417B + HOTFIX2417 + HOTFIX2414` regression | ✅ **23 passed / 92 assertions**, 1.64s |
| `Supplier` | ✅ **51 passed / 213 assertions**, 27.53s |
| `Purchase` | ✅ **27 passed / 102 assertions**, 3.36s |
| `PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.55s |
| `CashFlow` | ✅ **12 passed / 33 assertions**, 28.34s |
| `npm run build` | ✅ **built in 7.18s** |

**5 TC trong HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest:**

1. `test_invoice_sale_detail_lines_show_product_code_and_name` — Bán hàng → file có `SKU-WH1000XM5`, `Tai nghe Sony WH-1000XM5`, line_total `16000000`.
2. `test_invoice_sale_multiple_items_are_all_exported` — Hóa đơn 2 items → file có cả `Bàn phím cơ Keychron K8` và `Chuột Logitech MX Master 3S`.
3. `test_custom_date_accepts_vietnamese_dd_mm_yyyy` — `date_from=01/04/2026&date_to=30/04/2026` → 200 OK.
4. `test_custom_date_does_not_parse_as_us_format` — Phiếu Jan 2 không rò vào cửa sổ Apr 1–30 (chứng minh backend không parse kiểu Mỹ).
5. `test_invalid_vietnamese_date_returns_422` — `31/02/2026` → 422 (không 500).

## 6. Manual QA — pending tester

- [ ] `/suppliers` → mở rộng NCC có giao dịch bán hàng (dual-role).
- [ ] Tab Công nợ → **Xuất file công nợ** → chọn `Lựa chọn khác`.
- [ ] Input ngày phải hiện `dd/mm/yyyy` (placeholder + text), không phải MM/DD picker của browser.
- [ ] Nhập `01/04/2026` → `30/04/2026` → nút **Đồng ý** enabled.
- [ ] Nhập `31/02/2026` → viền đỏ + nút disabled, không submit được.
- [ ] Bấm **Đồng ý** → file `.xlsx` tải về.
- [ ] Mở file → dòng `HD…` có dòng detail bên dưới: SKU + tên sản phẩm + SL + đơn giá + thành tiền.
- [ ] Hóa đơn nhiều sản phẩm → file có đủ tất cả tên SP.
- [ ] Nhập hàng + thanh toán vẫn render đúng (regression manual).
- [ ] Số dư `Nợ đầu kỳ / Phát sinh / Nợ cuối kỳ` không sai so với HOTFIX 24.17B.
- [ ] Tab Lịch sử nhập/trả hàng → xuất file vẫn hoạt động.
- [ ] Console không lỗi.

## 7. Rủi ro còn lại

- **Công nợ:** ✅ KHÔNG ảnh hưởng — service chỉ đọc full ledger, không tính lại `debt_remain`. 51 TC Supplier suite PASS.
- **CashFlow:** ✅ KHÔNG động — 12 TC PASS.
- **Purchase / PurchaseReturn:** ✅ KHÔNG động — 27 + 14 TC PASS.
- **HOTFIX 24.14 (export lịch sử nhập/trả):** ✅ Intact.
- **HOTFIX 24.17 (modal + CSV):** ✅ Intact, chỉ đổi input element + helper.
- **HOTFIX 24.17B (layout xlsx):** ✅ Intact, 9/9 TC PASS lại.
- **Legacy `Carbon::parse`:** đã loại khỏi đường code export — không còn risk lệch ngày M/D vs D/M.
- **`invoice_items.discount`:** schema chưa có → tạm để 0 cho cột "Giảm giá" của dòng bán hàng. Khi nghiệp vụ thêm cột này (chiết khấu line-level) thì update mapping ở `loadDetailLines` nhánh `inv`.
- **Multi-serial cho 1 line:** field `serial` là text — nếu lưu CSV `S1,S2` thì tên hiển thị thành `Tai nghe Sony WH-1000XM5 (S1,S2)`. Đủ context cho audit.

## 8. Commit & deployment

- **Commit SHA:** `38abf50` — `fix(suppliers): include sale item details and Vietnamese dates in debt export`.
- **Push status:** ✅ đã push, `origin/main` = `38abf50c41a7c56b6b4bd3eb36d58852ce212043` (verified `git ls-remote`).

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
rm -rf public/build
npm run build
php artisan optimize:clear
# Hard reload trình duyệt (Ctrl+Shift+R)
```

## 9. Kết luận

- **Dòng bán hàng có đủ mã/tên hàng chưa?** ✅ Có — TC-01 pin SKU + name + line_total. TC-02 pin multi-item.
- **Modal hiện `dd/mm/yyyy` chưa?** ✅ Có — input text + placeholder + validator.
- **Backend nhận `dd/mm/yyyy` đúng không?** ✅ Có — `parseExportDate` strict, TC-04 pin (Jan rows không rò vào April window).
- **`31/02/2026` có lỗi 500 không?** ✅ Không — `checkdate` reject → 422 (TC-05).
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** ✅ KHÔNG — 109+ TC regression đều xanh.
- **Endpoint cũ còn tương thích?** ✅ Còn — TC `legacy_csv_without_query_still_works` từ 24.17B vẫn PASS (regression batch).
- **Có thể deploy không?** **Code đã sẵn sàng** — 5 TC mới + 23 TC regression chuỗi HOTFIX + 109 TC Supplier/Purchase/PR/CashFlow PASS, build PASS. Browser QA §6 cần tester confirm.
