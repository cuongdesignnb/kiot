# HOTFIX 24.XE - Product Import Error Logs

## 1. Van de da sua

- Man hinh import hang hoa da co preview loi, nhung thong tin loi chua du ro de nguoi dung biet phai sua cot nao, dong nao, gia tri nao.
- Nguoi dung can xem chi tiet loi theo dong, ma hang, ma vach, ten hang, cot loi, gia tri hien tai, ly do va goi y sua.
- Can co nut tai file loi rieng de doi soat nhanh tren Excel/CSV.

## 2. Pham vi

Module: import Excel hang hoa.

File da sua:

| File | Noi dung |
|---|---|
| `app/Services/ProductExcel/ProductExcelImportService.php` | Bo sung `error_logs`, `warning_logs`, `error_groups`, `warning_groups`; gan context field/label/value/message/suggestion cho tung loi. |
| `resources/js/Components/ExcelButtons.vue` | Them khu "Chi tiet loi can sua", hien thi nhom loi/canh bao, bang chi tiet loi va nut "Tai file loi". |
| `tests/Feature/Products/ProductExcelImportTest.php` | Them test invalid type, thieu ten hang, va dam bao preview co loi khong ghi database. |
| `docs/audit/HOTFIX-24.XE-PRODUCT-IMPORT-ERROR-LOGS.md` | Bao cao hotfix nay. |

Khong sua:

- Khong them commit import backend moi.
- Khong thay doi migration/backfill.
- Khong thay doi ton kho, gia von, serial/IMEI.
- Khong commit `public/build`.

## 3. Backend behavior

Preview import tra them:

- `error_logs`
- `warning_logs`
- `error_groups`
- `warning_groups`

Moi detail log gom:

- `row`
- `sku`
- `barcode`
- `name`
- `field`
- `field_label`
- `value`
- `message`
- `suggestion`
- `level`

Da map goi y cho cac truong hop chinh:

- Thieu `Ten hang`: bo sung ten hang hoac xoa dong.
- `Loai` khong hop le: dung `standard`, `service`, `combo`, `manufactured`; neu dang nhap nhom hang thi dua sang cot `Nhom hang`.
- Trung SKU/barcode: kiem tra va doi ma hang/ma vach hoac loai dong trung.
- Canh bao ton kho, gia von, serial/IMEI: hien thi theo warning log, khong ghi DB o preview.

## 4. Frontend behavior

- Preview hien thi them section `Chi tiet loi can sua` khi co `error_logs` hoac `warning_logs`.
- Section co tong hop nhom loi/canh bao theo message.
- Bang chi tiet hien toi da 100 dong dau de tranh UI qua nang.
- Nut `Tai file loi` xuat CSV co BOM UTF-8, ten file `loi_import_hang_hoa.csv`.

## 5. An toan du lieu

- Preview van chi phan tich file, khong ghi database khi co loi.
- Test `test_import_preview_with_errors_does_not_write_database` pin lai contract nay.
- Khong thay doi logic ton kho, gia von, serial/IMEI, migration hay backfill.

## 6. Verification

Da chay tai repo `kiotviet-clone`:

| Lenh | Ket qua |
|---|---|
| `php artisan test --filter=ProductExcelImportTest` | PASS - 21 tests, 68 assertions |
| `php artisan test --filter=ProductExcelExportTest` | PASS - 8 tests, 24 assertions |
| `npm run build` | PASS |
| `git diff --check` | PASS |
| `rg "Chi tiet loi can sua|loi_import_hang_hoa|error_logs" public/build/assets` | PASS - marker co trong asset build |

Ghi chu: PHP co canh bao startup ve cac extension chua load (`oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`), nhung test khong fail vi cac warning nay.

Manual QA trinh duyet/real file: chua thuc hien trong phien nay.

## 7. Ket luan

Hotfix bo sung du error log chi tiet cho preview import hang hoa, giup nguoi dung biet ro dong/cot/gia tri can sua va tai CSV loi. Thay doi duoc gioi han trong preview/import UI va test, khong tac dong ghi du lieu ngoai luong preview.
