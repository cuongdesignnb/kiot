# HOTFIX 24.XA - Verify Product Excel import/export routes

## Pham vi
- Module: Hang hoa.
- Man hinh: `/products`.
- Endpoint can xac minh:
  - `GET /products/export`
  - `POST /products/import`
  - `GET /products/import-template`
  - `POST /products/import-preview`
  - `POST /products/import-commit`

## Ket luan root cause
- Khong tai hien duoc loi "thieu route" tren source hien tai.
- `HEAD` va `origin/main` deu dang o commit `5c92bed feat(products): add KiotViet-like Excel import export options`.
- `routes/web.php` da co du 5 route Product Excel import/export.
- `ProductController` da co du cac method: `export`, `importTemplate`, `importPreview`, `importCommit`, `import`.
- Frontend `/products` da truyen du URL cho `ExcelButtons`.

Viec can lam trong hotfix nay la xac minh route gate va ghi audit report. Khong sua route code de tranh churn khong can thiet.

## Source da kiem tra
| File | Ket qua |
|---|---|
| `routes/web.php` | Co du 5 route Product Excel tai lines 437-441 |
| `app/Http/Controllers/ProductController.php` | Co du handler tai lines 1115, 1175, 1180, 1194, 1208 |
| `resources/js/Pages/Welcome.vue` | Co du props URL tai lines 615-619 |
| `origin/main:routes/web.php` | Co du 5 route sau khi fetch |

## Route list
`php artisan route:list --path=products`:

```text
GET|HEAD  products/export            products.export          ProductController@export
POST      products/import            products.import          ProductController@import
POST      products/import-commit     products.import-commit   ProductController@importCommit
POST      products/import-preview    products.import-preview  ProductController@importPreview
GET|HEAD  products/import-template   products.import-template ProductController@importTemplate
```

`php artisan route:list --path=products -v` middleware:

```text
products/export          Authenticate, CheckPermission:products.export
products/import          Authenticate, CheckPermission:products.import
products/import-commit   Authenticate, CheckPermission:products.import
products/import-preview  Authenticate, CheckPermission:products.import
products/import-template Authenticate, CheckPermission:products.import
```

Ghi chu moi lan chay `php artisan` tren may hien tai deu co warning PHP startup do thieu extension local `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`. Warning nay khong lam fail command.

## Thay doi source
| File | Loai | Noi dung |
|---|---|---|
| `docs/audit/HOTFIX-24.XA-PRODUCT-EXCEL-ROUTES.md` | New | Ghi lai ket qua xac minh route gate, test/build, rui ro con lai |

Khong sua:
- `routes/web.php` vi route da ton tai.
- `ProductController.php` vi handler da ton tai.
- Frontend Product Excel vi URL props da ton tai.
- Migration/data vi khong lien quan.

## Chinh sach an toan du lieu
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co ghi DB khong: Khong trong hotfix nay.
- Co dung ton kho/gia von/serial khong: Khong.
- Co thay doi permission khong: Khong; chi xac minh permission middleware da dung.

## Test da chay that
| Lenh | Ket qua |
|---|---|
| `php artisan route:list --path=products` | Pass, thay du 5 route Product Excel |
| `php artisan route:list --path=products -v` | Pass, middleware dung `products.export` / `products.import` |
| `php artisan test --filter=ProductExcelExportTest` | Pass 8 tests, 24 assertions |
| `php artisan test --filter=ProductExcelImportTest` | Pass 18 tests, 51 assertions |
| `php artisan test --filter=Product` | Pass 151 tests, 1299 assertions |
| `php artisan test --filter=Purchase` | Pass 87 tests, 449 assertions |
| `php artisan test --filter=Stock` | Pass 158 tests, 558 assertions |
| `php artisan test --filter=Serial` | Pass 177 tests, 648 assertions; 2 skipped |
| `php artisan test --filter=CancelInvoicePaymentDebtFlowTest` | Failed 1 existing test, 3 passed |
| `php artisan test --filter=Invoice` | Failed same existing test; 166 passed, 2 skipped |
| `npm run build` | Pass, Vite built successfully |

Failure con lai:

```text
Tests\Feature\Invoices\CancelInvoicePaymentDebtFlowTest
debt history maps cancel label and excludes cancelled legacy invoices
tests/Feature/Invoices/CancelInvoicePaymentDebtFlowTest.php:212
Expected non-null invoice_cancel_reversal, got null.
```

Failure nay thuoc luong debt history huy hoa don da ghi nhan tu lan truoc, khong nam trong route Product Excel.

## Manual QA
- [ ] Vao `/products` bang user co `products.export`, bam `Xuat file`, xac nhan khong con 404/RouteNotFound.
- [ ] Vao `/products`, bam `Nhap file`, tai template, xac nhan endpoint `/products/import-template` hoat dong.
- [ ] Upload file hop le, preview qua `/products/import-preview`.
- [ ] Commit import qua `/products/import-commit`.
- [ ] Test user khong co `products.import` bi chan import.
- [ ] Test user khong co `products.export` bi chan export.

## Deploy note
- Khong migration.
- Neu production dang cache route/config/view, chay `php artisan optimize:clear` sau khi deploy commit `5c92bed` tro len.
- Can `npm run build` trong pipeline frontend.
- Route gate dat, nhung deploy gate tong the van can quyet dinh rieng cho failure Invoice neu policy yeu cau full regression xanh.

## Ket luan
- Route Product Excel import/export da co san va da duoc xac minh tren local + `origin/main`.
- Hotfix nay khong can sua route code.
- Dat route gate cho Product Excel.
- Chua ket luan full regression gate do con 1 failure Invoice ngoai pham vi va manual QA chua chay.
