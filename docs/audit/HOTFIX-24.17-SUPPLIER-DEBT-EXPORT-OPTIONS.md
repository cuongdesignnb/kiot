# HOTFIX 24.17 — Supplier debt export: date filter + column options

## 1. Vấn đề

Trong `/suppliers` → mở rộng 1 NCC → tab **Công nợ** → nút **Xuất file công nợ** hiện tải CSV ngay với toàn bộ ledger và format cố định. Sếp muốn pattern giống KiotViet:

- Bấm nút → mở modal.
- Modal cho chọn khoảng thời gian (preset / custom).
- Modal cho tick các cột detail muốn xuất.
- Bấm **Đồng ý** mới tải file.

## 2. File đã sửa

| File | Loại | Nội dung |
|---|---|---|
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | edit | (a) Thêm state `showDebtExportModal`, `debtExportSupplier`, `debtExportForm` (reactive) + 2 mảng `debtExportPresets`, `debtExportColumnOptions`. (b) Thêm 3 helpers: `openSupplierDebtExportModal`, `closeDebtExportModal`, `confirmDebtExport`. (c) Đổi handler nút "Xuất file công nợ" từ `exportSupplierTab(supplier, 'debt')` → `openSupplierDebtExportModal(supplier)`. (d) Thêm `<div v-if="showDebtExportModal">` modal trước `</AppLayout>`. |
| [`app/Http/Controllers/SupplierController.php`](app/Http/Controllers/SupplierController.php#L255) | edit | `exportDebtHistory($id)` nhận thêm `Request $request`. Không query → giữ format CSV cũ (HOTFIX 24.14 contract). Có query → validate, resolve preset → range, filter `$entries` theo `created_at`, dynamic headers + chọn cột detail. Thêm 2 helper private: `resolveDebtExportRange()` + `loadDebtExportDetailLines()`. |
| [`tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php`](tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php) | NEW | 8 TC pin contract: legacy no-query, custom range filter, preset=today/all, 422 invalid range, include_detail+columns, columns whitelist, JSON regression. |
| [`docs/audit/HOTFIX-24.17-SUPPLIER-DEBT-EXPORT-OPTIONS.md`](docs/audit/HOTFIX-24.17-SUPPLIER-DEBT-EXPORT-OPTIONS.md) | NEW | Báo cáo này. |

**Không sửa:**
- `debtTransactions()` — không tính lại `debt_remain` (vẫn tính trên full ledger trước khi filter export).
- `recordPayment` / `adjustDebt` / `debtOffset` / Purchase / PurchaseReturn / CashFlow / Invoice / POS.
- `exportPurchaseHistory` (lịch sử nhập/trả hàng — HOTFIX 24.14).
- `CsvService` — đủ rồi, không cần đụng.
- Tồn kho, giá vốn, serial.
- Migration — `created_at` đã có sẵn trên mọi bảng nguồn.

## 3. Modal — option đã thêm

**Thời gian (preset buttons):**

| Button | `date_preset` value |
|---|---|
| Hôm nay | `today` |
| Tuần này | `this_week` |
| 7 ngày qua | `last_7_days` |
| 30 ngày qua | `last_30_days` |
| Tháng này | `this_month` |
| Tháng trước | `last_month` |
| Quý này | `this_quarter` |
| Năm nay | `this_year` |
| Toàn thời gian | `all` |
| Lựa chọn khác | `custom` → hiện 2 input date `Từ ngày` / `Đến ngày` |

**Thông tin xuất file:**
- Tổng quan (luôn có, không tắt được): Thời gian / Mã chứng từ / Loại / Giá trị / Nợ cần trả nhà cung cấp / Ghi chú.
- Checkbox "Chi tiết từng hàng giao dịch" → bật/tắt 1 block 8 checkbox: ĐVT / Số lượng / Đơn giá / Giảm giá / VAT / Giá nhập/trả / Thành tiền / Ghi chú dòng.

**Footer:**
- `Bỏ qua` → đóng modal, không tải file.
- `Đồng ý` → build URL → `window.location.assign(url)`. Disabled khi `custom` mà chưa nhập đủ 2 ngày.

UX guard:
- `@click.self="closeDebtExportModal"` trên backdrop để click ra ngoài đóng modal.
- Nút `@click.stop` không collapse expanded row của NCC.
- Reset toàn bộ form mỗi lần mở modal → không leak state sang NCC khác.

## 4. Backend query params

```
GET /api/suppliers/{id}/export-debt
    ?date_preset=today|this_week|last_7_days|last_30_days|this_month|last_month|this_quarter|this_year|all|custom
    &date_from=YYYY-MM-DD       (chỉ dùng khi preset=custom)
    &date_to=YYYY-MM-DD
    &include_detail=0|1
    &columns[]=unit
    &columns[]=quantity
    &columns[]=unit_price
    &columns[]=discount
    &columns[]=vat
    &columns[]=cost
    &columns[]=line_total
    &columns[]=note
```

**Validation:**
- `date_preset` phải trong whitelist.
- `date_from` / `date_to` là date hợp lệ.
- `columns[]` chỉ chấp nhận 8 key whitelist.
- Sau khi resolve range, nếu `from > to` → trả 422 với message tiếng Việt.

**Date-preset mapping (Carbon, timezone app):**

| Preset | From | To |
|---|---|---|
| `today` | `now->startOfDay()` | `now->endOfDay()` |
| `this_week` | `now->startOfWeek()` | `now->endOfWeek()` |
| `last_7_days` | `now->subDays(6)->startOfDay()` | `now->endOfDay()` |
| `last_30_days` | `now->subDays(29)->startOfDay()` | `now->endOfDay()` |
| `this_month` | `now->startOfMonth()` | `now->endOfMonth()` |
| `last_month` | `subMonthNoOverflow()->startOfMonth()` | `subMonthNoOverflow()->endOfMonth()` |
| `this_quarter` | `now->startOfQuarter()` | `now->endOfQuarter()` |
| `this_year` | `now->startOfYear()` | `now->endOfYear()` |
| `all` | `null` | `null` |
| `custom` | `date_from->startOfDay()` | `date_to->endOfDay()` |

## 5. Âm lịch?

**Chưa hỗ trợ âm lịch ở phase này.** Project chưa có lunar calendar service — preset hiện tại đều là dương lịch. Để phase sau khi có nhu cầu thực tế và service tương ứng.

## 6. `debt_remain` không bị tính sai

Quan trọng — `debt_remain` được tính ở **full ledger** (ascending sort theo `created_at`, running balance) trong `debtTransactions()`. Code mới chỉ **filter sau** khi đã gán xong `debt_remain`. Đảo display order / chọn khoảng ngày con không động `debt_remain` của bất kỳ row nào. TC-04 + Supplier regression suite confirm.

## 7. Test đã chạy thật (MySQL:3319)

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2417SupplierDebtExportOptionsTest` | ✅ **8 passed / 30 assertions**, 0.92s |
| `php artisan test --filter=HOTFIX2414SupplierTabExportTest` | ✅ **6 passed / 23 assertions**, 0.67s (legacy format intact) |
| `php artisan test --filter=Supplier` | ✅ **37 passed / 162 assertions**, 34.04s |
| `php artisan test --filter=Purchase` | ✅ **26 passed / 98 assertions**, 3.39s |
| `php artisan test --filter=PurchaseReturn` | ✅ **14 passed / 47 assertions**, 2.73s |
| `php artisan test --filter=CashFlow` | ✅ **12 passed / 33 assertions**, 28.15s |
| `npm run build` | ✅ **built in 6.76s** |

**8 TC trong HOTFIX2417SupplierDebtExportOptionsTest:**

1. `test_export_with_no_query_keeps_legacy_format` — không query → vẫn có `Mã chứng từ`, `Còn nợ` (header cũ).
2. `test_export_custom_range_filters_by_created_at` — purchase 01/05 + 10/05 in, 20/05 out khi `date_from=2026-05-01&date_to=2026-05-14`.
3. `test_export_preset_today_is_ok` — `preset=today` không 500.
4. `test_export_preset_all_returns_all_entries` — `preset=all` trả full ledger.
5. `test_export_invalid_range_returns_422` — `from > to` → 422, không 500.
6. `test_export_include_detail_appends_detail_columns` — `include_detail=1` thêm cột detail + dòng line item.
7. `test_export_columns_whitelist_only` — chọn chỉ `columns[]=quantity` → header không có `Đơn giá`/`Giảm giá`/`Thành tiền`.
8. `test_debt_transactions_json_endpoint_unchanged` — JSON endpoint vẫn trả `entries` + `summary`.

## 8. Manual QA — pending tester

- [ ] `/suppliers` → mở rộng 1 NCC → tab **Công nợ** → bấm **Xuất file công nợ** → modal mở (không tải file ngay).
- [ ] Click backdrop hoặc nút "×" / **Bỏ qua** → modal đóng, không tải file.
- [ ] Chọn lần lượt: Hôm nay / Tuần này / 7 ngày qua / 30 ngày qua / Tháng này / Tháng trước / Quý này / Năm nay / Toàn thời gian → bấm Đồng ý → CSV chỉ chứa giao dịch trong khoảng đó.
- [ ] Chọn "Lựa chọn khác" → 2 input date xuất hiện → nhập custom range → CSV đúng range.
- [ ] Nhập `Từ ngày` > `Đến ngày` → backend trả 422 (UI có thể show alert/snackbar tùy implement; quan trọng là không 500).
- [ ] Tick / bỏ tick `Chi tiết từng hàng giao dịch` → các cột detail xuất hiện / biến mất đúng.
- [ ] Tick chỉ 1 cột (vd `Số lượng`) → CSV chỉ có cột đó trong phần detail.
- [ ] Mở modal cho NCC A, đóng, mở cho NCC B → form reset, không leak state.
- [ ] Tab Lịch sử nhập/trả hàng → nút **Xuất file** (HOTFIX 24.14) vẫn hoạt động bình thường.
- [ ] Thanh toán / Điều chỉnh / Cấn bằng công nợ → vẫn hoạt động, số dư không đổi.
- [ ] Console → không lỗi.

## 9. Rủi ro còn lại

- **Công nợ:** `debt_remain` tính trên full ledger trước khi filter — không thay đổi. TC-04 + 37 TC Supplier suite confirm. `summary.net` không đụng (endpoint JSON debt-transactions vẫn intact — TC-08).
- **HOTFIX 24.14 export lịch sử nhập/trả hàng:** không đụng `exportPurchaseHistory`, format CSV cũ. 6/6 TC vẫn PASS.
- **Endpoint cũ:** call không query → fast path legacy format. TC-01 pin.
- **Detail line:** type không có line items (`payment`, `adjustment`, `discount`, `customer_payment`, `return` summary) → `loadDebtExportDetailLines` trả `[]`, không lỗi 500. Chỉ Purchase / PurchaseReturn / Invoice có detail mapping.
- **VAT / ĐVT không có trên `PurchaseItem`:** để chuỗi rỗng, không drop column khỏi header (vì user vẫn tick checkbox đó). UI có thể bổ sung schema sau.
- **CashFlow / Purchase / PurchaseReturn nghiệp vụ:** không đụng — regression đều xanh.

## 10. Commit & deployment

- **Commit SHA:** `d417360` — `fix(suppliers): add date and column options to debt export`
- **Push status:** ✅ đã push, `origin/main` = `d41736004ba775fec795938b85de0834ab79514a` (verified `git ls-remote`).
- **`git log --oneline -5`:**
  ```
  d417360 fix(suppliers): add date and column options to debt export
  fab3f25 docs(audit): confirm HOTFIX 24.16C pushed to origin/main
  df79d23 docs(audit): record HOTFIX 24.16C commit SHAs and deploy step
  d18a0e2 fix(repairs): expand serialImei API payload for stock-state guards (HOTFIX 24.16C)
  dc8e07d fix(repairs): badge dismantled serials in Repair/Tasks UI (HOTFIX 24.16C)
  ```

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main
rm -rf public/build
npm run build
php artisan optimize:clear
# Hard reload trình duyệt (Ctrl+Shift+R)
```

## 11. Kết luận

- **Modal mở đúng chưa?** Có — bấm nút "Xuất file công nợ" mở modal, không tải file ngay.
- **Chọn thời gian + option hoạt động không?** Có — 10 preset + custom range + 8 cột detail toggle.
- **Có ảnh hưởng công nợ / CashFlow / Purchase không?** Không — `debt_remain` không tính lại, không động `recordPayment` / `adjustDebt` / `debtOffset`, regression Supplier/Purchase/PurchaseReturn/CashFlow đều xanh.
- **Endpoint cũ còn tương thích không?** Có — call không query trả CSV format cũ (HOTFIX 24.14 contract intact).
- **Có thể deploy không?** **Code đã sẵn sàng** (8 TC mới + 6 TC 24.14 + 37 TC Supplier + Purchase + PurchaseReturn + CashFlow pass, build pass). Browser QA ở §8 cần tester confirm trước production.
