# HOTFIX 24.XB - Product import file picker

## Pham vi
- Module: Hang hoa.
- Man hinh: `/products`.
- Nghiep vu: chon file Excel/CSV trong modal Product Import va preview file.
- Rui ro: UI khong nhan file, nut `Kiem tra file` bi disabled, khong goi duoc `/products/import-preview`.

## Root cause
- Product import dang dung chung hidden input `importFile` voi legacy import.
- Button trong modal goi truc tiep `importFile.click()`, trong khi product flow can state rieng `selectedImportFile`.
- Hidden input khong reset `value` truoc khi mo picker nen chon lai cung file co the khong trigger `change`.
- Expression inline `@change="isProductExcel ? handleProductFile : handleLegacyImport"` lam product/legacy flow phu thuoc chung mot input, kho co lap khi modal product thay doi.

## Source da sua
- `resources/js/Components/ExcelButtons.vue`
- `docs/audit/HOTFIX-24.XB-PRODUCT-IMPORT-FILE-PICKER.md`

## Thay doi da lam
- Tach input file rieng cho Product Import: `productImportFile`.
- Legacy import van dung `importFile` va `handleLegacyImport`.
- Them `openProductFilePicker()` de reset input value truoc khi click file picker.
- Them `openImportModal()` va `resetProductImportState()` de reset file, preview, error va option khi mo modal.
- `closeImportModal()` reset ca legacy input va product input.
- `handleProductFile()` gan file vao `selectedImportFile`, validate extension `.xlsx`, `.xls`, `.csv`, `.txt`.
- UI hien ten file mau xanh va dung luong KB sau khi chon.
- Nut `Kiem tra file` co `type="button"` va enabled theo `selectedImportFile`.

## Backend
- Khong sua backend.
- Route import van ton tai:

```text
POST      products/import
POST      products/import-commit
POST      products/import-preview
GET|HEAD  products/import-template
```

## Data safety
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co ghi DB khong: Khong khi chi chon file/preview.
- Co dung ton kho khong: Khong.
- Co dung gia von khong: Khong.
- Co dung serial khong: Khong.
- Co tao stock movement khong: Khong.
- Co can backup DB khong: Khong cho hotfix frontend nay.

## Test da chay
| Lenh | Ket qua |
|---|---|
| `php artisan route:list --path=products \| Select-String -Pattern 'products/import'` | Pass, thay du route import product |
| `npm run build` | Pass, Vite built successfully |
| `php artisan test --filter=ProductExcelImportTest` | Pass 18 tests, 51 assertions |
| `php artisan test --filter=ProductExcelExportTest` | Pass 8 tests, 24 assertions |

Ghi chu: cac lenh PHP tren may hien tai van co warning startup do thieu extension local `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; warning khong lam fail test.

## Manual QA
- [ ] Chon file `.xlsx` hien thi ten file.
- [ ] Nut `Kiem tra file` enabled.
- [ ] Bam `Kiem tra file` goi preview.
- [ ] Dong/mo lai modal reset state.
- [ ] Chon lai cung file van trigger change.
- [ ] File sai dinh dang bao loi.
- [ ] Khong loi console.
- [x] `npm run build` pass.

Manual QA tren browser that chua chay trong phien nay vi repo khong co Playwright/browser automation setup va khong co browser tool callable truc tiep. Khong bao pass gia cho cac muc can thao tac OS file picker.

## Ket luan
- Dat/chua dat: Dat build va automated Product Excel tests; chua dat manual QA browser gate.
- Co the deploy chua: Co the merge hotfix frontend sau khi tester chay manual QA tren browser that; neu policy yeu cau manual QA bat buoc thi chua nen goi la deploy gate pass.
- Can lam tiep: tester vao `/products`, mo modal import, chon file that va xac nhan preview goi `/products/import-preview`.
