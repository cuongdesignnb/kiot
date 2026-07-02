# HOTFIX 24.14 — Supplier Expanded Tab Export

## 1. Vấn đề đã sửa

- Nút **"Xuất file"** trong expanded row của NCC (cả tab **Lịch sử nhập/trả hàng** lẫn tab **Công nợ**) báo lỗi Vue:
  ```
  Cannot read properties of undefined (reading 'open')
  ```
- Root cause chính xác:
  - 2 button binding `@click="window.open(`/api/suppliers/${supplier.id}/export-...`)"` ở [`resources/js/Pages/Suppliers/Index.vue:905`](resources/js/Pages/Suppliers/Index.vue#L905) và [`:953`](resources/js/Pages/Suppliers/Index.vue#L953).
  - **Vue 3 template expressions resolve identifiers qua component instance scope, không tự expose `window`.** Khi compiler dựng function cho `@click`, `window` trở thành `undefined`, gọi `.open(...)` trên `undefined` → throw đúng lỗi user thấy.
  - Lỗi chỉ xảy ra ở expanded tab vì 2 button này là 2 chỗ duy nhất trong Suppliers/Index dùng pattern `window.X` inline trong template.

## 2. File đã sửa

| File | Nội dung sửa |
|---|---|
| [`resources/js/Pages/Suppliers/Index.vue`](resources/js/Pages/Suppliers/Index.vue) | (a) Thêm helper `supplierTabExportUrl(supplier, tab)` + `exportSupplierTab(supplier, tab)` trong `<script setup>` (module scope nơi `window` luôn là global thật). (b) Đổi 2 button từ `@click="window.open(...)"` sang `@click.stop="exportSupplierTab(supplier, 'history'\|'debt')"`. (c) Thêm `v-if="supplier?.id"` để guard trường hợp data chưa load. |
| [`tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php`](tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php) | NEW — 6 TC pin backend contract. |
| [`docs/audit/HOTFIX-24.14-SUPPLIER-TAB-EXPORT.md`](docs/audit/HOTFIX-24.14-SUPPLIER-TAB-EXPORT.md) | NEW — báo cáo này. |

**Không sửa:** `routes/api.php`, `SupplierController.php` (export methods chạy đúng — chỉ FE gọi sai), `CsvService.php`, `Customer/Purchase/PurchaseReturn/SupplierDebtTransaction/CashFlow` model, công thức công nợ, recordPayment, adjustDebt, debtOffset.

## 3. Cách sửa frontend

- Helper export URL: `supplierTabExportUrl(supplier, tab)` — trả `'/api/suppliers/{id}/export-purchases'` cho `tab='history'`, `'/api/suppliers/{id}/export-debt'` cho `tab='debt'`, chuỗi rỗng nếu thiếu `supplier.id`.
- Tab history dùng endpoint: `GET /api/suppliers/{id}/export-purchases`
- Tab debt dùng endpoint: `GET /api/suppliers/{id}/export-debt`
- Vì sao dùng `@click.stop`: dòng cha là clickable expand/collapse (`@click="toggleExpanded(supplier.id)"` trên `<tr>`); không có `.stop` thì click vào button cũng làm collapse → mất tab đang xem. Lỗi này có sẵn từ trước nhưng bị ẩn vì click ném exception trước khi propagate.
- Có còn dùng `.open` trên ref/object có thể undefined không: **Không** trong Suppliers/Index. Helper gọi `window.location.assign(url)` từ module scope nơi `window` là `globalThis` thật.

## 4. Backend export

- Route export purchases: `GET /api/suppliers/{id}/export-purchases` — [`routes/api.php:127`](routes/api.php#L127) (đã có sẵn, không sửa).
- Route export debt: `GET /api/suppliers/{id}/export-debt` — [`routes/api.php:126`](routes/api.php#L126) (đã có sẵn, không sửa).
- Method controller: `SupplierController::exportPurchaseHistory($id)` + `::exportDebtHistory($id)` — không sửa.
- Có sửa backend không: **Không**.
- Có thay đổi nghiệp vụ công nợ không: **Không** — export chỉ là CSV wrapper quanh `purchaseHistory()` / `debtTransactions()` đã có sẵn.

## 5. Test đã chạy

Chạy thật trên MySQL:3319:

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2414SupplierTabExportTest` | ✅ **6 PASS / 23 assertions** |
| `php artisan test --filter=Supplier` | ✅ **25 PASS / 103 assertions** |
| `php artisan test --filter=Purchase` | ✅ **25 PASS / 83 assertions** |
| `php artisan test --filter=PurchaseReturn` | ✅ **14 PASS / 47 assertions** |
| `php artisan test --filter=CashFlow` | ✅ **12 PASS / 33 assertions** |
| `npm run build` | ✅ 6.91s pass |

**6 TC trong HOTFIX2414SupplierTabExportTest:**

1. `test_supplier_purchase_history_export_downloads_csv` — CSV header `Content-Type: text/csv`, có cột "Mã phiếu nhập" + chứa code phiếu.
2. `test_supplier_debt_export_downloads_csv` — CSV với cột "Mã chứng từ"/"Còn nợ" + chứa code purchase.
3. `test_supplier_tab_export_does_not_include_other_supplier_data` — export NCC A không leak phiếu của NCC B.
4. `test_supplier_purchase_history_json_still_works` — endpoint JSON `/purchase-history` vẫn 200 + array.
5. `test_supplier_debt_transactions_json_still_works` — endpoint JSON `/debt-transactions` vẫn có `entries`.
6. `test_supplier_tab_export_missing_supplier_does_not_500` — `/export-purchases` + `/export-debt` cho ID không tồn tại trả < 500.

## 6. Manual QA

Hai mức kiểm tra: **automated** (do tôi chạy được, kết quả thực) và **browser** (yêu cầu user verify trên dev/local trước khi chốt deploy production).

### Automated verification (đã chạy thật trên DB MySQL:3319)

| Check | Phương thức | Kết quả |
|---|---|---|
| `window.open` trong template Suppliers/Index đã biến mất | `Grep` toàn file — chỉ còn 1 comment + 1 `window.location.assign` trong script scope (module global, không phải template scope) | ✅ |
| Export tab Lịch sử nhập/trả → CSV hợp lệ, đúng NCC | TC-01 + TC-03 | ✅ |
| Export tab Công nợ → CSV hợp lệ | TC-02 | ✅ |
| Cross-supplier data leak (NCC A export không lẫn NCC B) | TC-03 | ✅ |
| NCC không tồn tại → không 500 | TC-06 (HTTP < 500 cho cả 2 endpoint) | ✅ |
| Endpoint JSON `/purchase-history` không bị ảnh hưởng | TC-04 | ✅ |
| Endpoint JSON `/debt-transactions` không bị ảnh hưởng | TC-05 | ✅ |
| `recordPayment`, `adjustDebt`, `debtOffset`, dual-role debt | `php artisan test --filter=Supplier` — 25 PASS / 103 assertions | ✅ |
| `Purchase`, `PurchaseReturn`, `CashFlow` regression | 25 + 14 + 12 PASS | ✅ |

### Browser QA (cần user thực hiện sau khi pull `a5dff11` + rebuild)

Code-level confidence cao (`@click.stop` chặn propagation chuẩn Vue idiom; `window.location.assign` ở module scope không thể `undefined`), nhưng tôi **không có browser** nên không tự đánh dấu các mục dưới đây — chờ user verify:

- [ ] `/suppliers` → mở rộng NCC có lịch sử → tab "Lịch sử nhập/trả hàng" → "Xuất file" → CSV tải về, dòng cha vẫn expanded, không có Vue error trong console.
- [ ] Same NCC → tab "Công nợ" → "Xuất file công nợ" → CSV tải về, dữ liệu đúng NCC.
- [ ] NCC không có dữ liệu → "Xuất file" → CSV header-only, không 500, không Vue error.
- [ ] Toolbar danh sách NCC chính (`/suppliers/export`) vẫn hoạt động.
- [ ] Nút "Thanh toán", "Điều chỉnh", "Cấn bằng công nợ" vẫn hoạt động.
- [ ] Đổi tab Info ↔ History ↔ Debt vẫn ổn.

**Quy trình deploy production:** chỉ thực hiện sau khi tick xong 6 box trên ở dev/local:
```bash
cd /www/wwwroot/kiot.cuongdesign.net
git pull origin main          # phải thấy a5dff11
rm -rf public/build
npm run build
php artisan optimize:clear
# Hard reload trình duyệt (Ctrl+Shift+R)
```

## 7. Rủi ro còn lại

- Dữ liệu: **không** — chỉ đổi cách FE gọi URL, không động đến controller/query/CsvService.
- Công nợ: **không** — không sửa công thức `debt_remain`, `supplier_effect`, `summary`.
- Cashflow: **không**.
- Tồn kho/giá vốn/serial: **không ảnh hưởng**.
- **Same bug pattern ở Customers/Index.vue:2004** (`window.open` trong template) — out-of-scope HOTFIX 24.14 nhưng nên follow-up bằng hotfix 24.14B nếu user xác nhận lỗi xuất file công nợ KH cũng xảy ra. Người dùng chưa báo lỗi này.

## 8. Commit & deployment

- **Commit SHA:** `a5dff11d7c28707823725fc7257fb640a0135a7a` — `fix(suppliers): export tab buttons no longer throw on .open`
- **Push status:** ✅ trên `origin/main` (verified: `git ls-remote origin refs/heads/main` = `a5dff11...`).
- **Recent main:**
  ```
  a5dff11 fix(suppliers): export tab buttons no longer throw on .open
  3b0b7a6 fix(forms): wire Orders + Purchases quick create to full modals
  d009f81 fix(payroll): make all salary adjustments editable (HOTFIX 24.12C)
  1cdc8dd fix(forms): unify quick create product customer supplier flows
  ff1a52b fix(payroll): persist manual adjustment overrides (HOTFIX 24.12B)
  ```

## 9. Kết luận

- Đã fix lỗi Vue chưa: **Có** — không còn `window.X` trong template Suppliers/Index (verified bằng grep).
- Export 2 tab backend hoạt động: **Có** — 6 TC backend pass / 23 assertions trên MySQL:3319 thật.
- Regression không bị ảnh hưởng: **Có** — Supplier (25) + Purchase (25) + PurchaseReturn (14) + CashFlow (12) đều xanh.
- **Có thể deploy production chưa: CHƯA chốt** — vẫn còn 6 box browser QA ở §6 mà tôi không tự thực hiện được. Khi user (hoặc tester) tick xong 6 box đó trên dev/local thì đủ điều kiện chạy lệnh deploy ghi ở cuối §6.
