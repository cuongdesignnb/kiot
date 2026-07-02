# HOTFIX 24.XD - Sync Product import file picker

## Pham vi
- Repo: `cuongdesignnb/kiot`.
- Local path: `D:\Kiot\kiotviet-clone`.
- Module: Hang hoa.
- Man hinh: `/products`.
- Nghiep vu: dong bo Product Import file picker voi repo production `sapo`.
- Rui ro: hai repo lech logic frontend lam production khong nhan file sau khi user chon file.

## Source truoc khi sua
- HEAD: `56da85c fix(products): make import file picker reliable`.
- Remote: `origin https://github.com/cuongdesignnb/kiot.git`.
- Branch: `main`.
- File picker co fix chua: Co.
- Marker da co:
  - `productImportFile`
  - `openProductFilePicker`
  - `handleProductFile(event)`
- Logic cu `isProductExcel ? handleProductFile : handleLegacyImport`: Khong con.

## Thay doi da lam
- `resources/js/Components/ExcelButtons.vue`: khong sua trong 24.XD vi repo da co fix tu commit `56da85c`.
- Report audit: them file nay de ghi nhan sync hai repo.

## Data safety
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co ghi DB khong: Khong.
- Co dung ton kho khong: Khong.
- Co dung gia von khong: Khong.
- Co dung serial khong: Khong.
- Co tao stock movement khong: Khong.

## Tests da chay
| Lenh | Ket qua |
|---|---|
| `php artisan route:list --path=products \| Select-String -Pattern 'products/import'` | Pass, co du 4 route import product |
| `php artisan test --filter=ProductExcelImportTest` | Pass 18 tests, 51 assertions |
| `php artisan test --filter=ProductExcelExportTest` | Pass 8 tests, 24 assertions |
| `npm run build` | Pass, Vite built successfully |
| `rg -n "productImportFile\|Khong mo duoc bo chon file" public/build/assets` | Pass, build asset co marker `productImportFile` |

Ghi chu: cac lenh PHP co warning startup do thieu extension local `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; test van pass.

## Manual QA
- [ ] Chon file hien ten file.
- [ ] Nut kiem tra enabled.
- [ ] Preview goi import-preview.
- [ ] Dong/mo modal reset state.
- [ ] Chon lai cung file van nhan.
- [ ] File sai dinh dang bao loi.
- [ ] Console khong loi.

Manual QA browser that chua chay trong phien local nay.

## Commit
- SHA fix frontend hien co: `56da85c`.
- SHA report 24.XD: se cap nhat sau commit.

## Ket luan
- Dat/chua dat: Dat sync logic trong repo `kiot`, automated tests va build.
- Co the deploy chua: Repo `kiot` khong phai repo server production dang pull; production can deploy tu repo `sapo`.
- Can lam tiep: dam bao repo `sapo` cung co report/fix va production pull commit moi.
