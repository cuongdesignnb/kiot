# STEP 24.X - Product Excel Import/Export KiotViet-like

## Pham vi
- Module: Hang hoa.
- Man hinh: `/products`.
- Nghiep vu: export XLSX co chon cot, import template, import preview/dry-run, import commit phase 1.
- Rui ro: ton kho, gia von, serial/IMEI, trung SKU/barcode, quyen xem gia von.

## Source da kiem tra
- Route: `routes/web.php`.
- Controller: `app/Http/Controllers/ProductController.php`.
- Service: `app/Services/ProductExcel/*`.
- Model: `Product`, `ProductUnit`, `ProductVariant`, `ProductAttribute`, `ProductAttributeValue`, `Role`.
- Migration: products, product_units, warranty/maintenance config.
- Frontend: `resources/js/Pages/Welcome.vue`, `resources/js/Components/ExcelButtons.vue`, `resources/js/utils/money.js`.
- Test: `tests/Feature/Products/ProductExcelExportTest.php`, `tests/Feature/Products/ProductExcelImportTest.php`, regression filters.
- Commit audit baseline: `d680a79 docs(audit): record debt business date test results`.

## Hien trang truoc khi sua
- `/products/export` dang xuat CSV thieu style.
- `/products/import` dang commit truc tiep bang `updateOrCreate`.
- Import cu co the update ton kho/gia von va tu tao category/brand.
- Chua co template, preview/dry-run, modal option va field catalog.

## Thay doi da lam
- Backend:
  - Them route import template, preview, commit.
  - Giu `/products/import` la alias preview, khong commit truc tiep.
  - Export doi sang XLSX co title, sheet `hang_hoa`, freeze header, filter, auto width, number format.
  - Import preview khong ghi DB.
  - Import commit dung transaction va policy phase 1.
- Frontend:
  - `ExcelButtons` giu flow cu cho module khac.
  - Rieng product co modal chon cot export va modal import option/preview.
  - Luu lua chon cot export bang `localStorage`.
- Service:
  - `ProductExcelFieldCatalog`.
  - `ProductExcelExportService`.
  - `ProductExcelImportService`.
- Test:
  - Them export/import feature tests moi.

## Field catalog
| Field | Export | Import | Permission | Ghi chu |
|---|---|---|---|---|
| sku | Co | Co | - | Rong thi tu sinh khi tao moi |
| name | Co | Co | - | Bat buoc |
| type | Co | Co | - | Default `standard` |
| category | Co | Co | - | Phase 1 chi map category da co |
| brand | Co | Co | - | Phase 1 chi map brand da co |
| barcode | Co | Co | - | Dung de nhan dien trung |
| cost_price | Co | Co | `products.view_cost_price` | Khong update hang cu |
| retail_price | Co | Co | - | Default 0 |
| stock_quantity | Co | Co gioi han | - | Hang cu khong bi update |
| min_stock/max_stock | Co | Co | - | Ap dung khi tao moi |
| has_serial | Co | Co | - | Khong tao serial batch |
| description | Co | Co | - | Chi update neu bat option |
| warranty_months | Co | Co | - | Field co san |

## Import options
| Option | Default | Co update du lieu cu khong | Policy |
|---|---|---|---|
| duplicate_name_strategy | error | Co, neu `replace_name` | Chi update `name` |
| duplicate_barcode_sku_strategy | error | Co, neu `replace_sku` | Chi update `sku` neu SKU moi chua ton tai |
| update_stock | false | Khong | Chi warning, khong update ton hang cu |
| update_cost_price | false | Khong | Chi warning, khong update gia von hang cu |
| update_description | false | Co, neu true | Chi update `description` |

## Chinh sach an toan du lieu
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co update du lieu cu khong: Khong mac dinh; chi `name`, `sku`, `description` khi user bat option va preview hop le.
- Co dung ton kho khong: Chi set ton kho khi tao product moi; khong update hang cu.
- Co dung gia von khong: Chi set gia von khi tao product moi; khong update hang cu.
- Co dung serial khong: Chi set flag `has_serial`; khong tao serial/IMEI.
- Co tao stock movement khong: Khong.
- Co can backup khong: Nen backup truoc khi bat import commit tren production; bat buoc neu sau nay mo update ton/gia von hang cu.

## Test da chay that
| Lenh | Ket qua |
|---|---|
| `php -l app/Http/Controllers/ProductController.php` va cac service/test moi | Pass, co warning PHP extension thieu `oci8`, `pdo_oci`, `pdo_firebird` |
| `php artisan test --filter=ProductExcelExportTest` | Pass 8 tests, 24 assertions |
| `php artisan test --filter=ProductExcelImportTest` | Pass 18 tests, 51 assertions |
| `php artisan test --filter=Product` | Pass 151 tests, 1299 assertions |
| `php artisan test --filter=Purchase` | Pass 87 tests, 449 assertions |
| `php artisan test --filter=Invoice` | Failed 1 existing test: `CancelInvoicePaymentDebtFlowTest::debt_history_maps_cancel_label_and_excludes_cancelled_legacy_invoices`; 166 passed, 2 skipped |
| `php artisan test --filter=CancelInvoicePaymentDebtFlowTest` | Failed same test, 3 passed |
| `php artisan test --filter=OrderReturn` | Pass 53 tests, 213 assertions |
| `php artisan test --filter=Stock` | Pass 158 tests, 558 assertions |
| `php artisan test --filter=Serial` | Pass 177 tests, 648 assertions; 2 skipped |
| `npm run build` | Pass |

## Manual QA
- [ ] Vao `/products`.
- [ ] Bam `Xuat file`, chon cot, tai XLSX va mo bang Excel.
- [ ] Kiem tra sheet `hang_hoa`, title, filter, freeze header, auto width.
- [ ] Login user khong co `products.view_cost_price` va xac nhan khong export `Gia von`.
- [ ] Bam `Nhap file`, tai template, upload file chi co `Ten hang`.
- [ ] Preview hop le, DB chua co product.
- [ ] Confirm import tao product moi, SKU tu sinh, type standard, ton 0.
- [ ] Test trung SKU/barcode theo option.
- [ ] Test bat update ton/gia von chi hien warning va khong doi hang cu.
- [ ] Test update description chi doi khi bat option.

## Rui ro con lai
- `php artisan test --filter=Invoice` dang co 1 failure on dinh o luong debt history huy hoa don, khong nam trong code product import/export vua sua.
- Chua thuc hien manual QA tren trinh duyet/Excel thuc.
- Phase 1 khong tu tao category/brand de tranh sai master data; file co category/brand moi se tao product voi id null.

## Deploy note
- Khong migration.
- Can `composer dump-autoload` neu autoload cache cu.
- Can `npm run build`.
- Can `php artisan optimize:clear`, sau do cache lai route/config/view neu production dang cache.
- Khong commit `public/build`, `.env`, log, dump, vendor, node_modules.

## Ket luan
- Dat/chua dat: Backend/frontend/tests moi dat; regression con 1 failure ngoai pham vi.
- Co the deploy chua: Chua nen noi la deploy duoc den khi failure `CancelInvoicePaymentDebtFlowTest` duoc xu ly hoac chap nhan rieng va manual QA hoan tat.
- Can lam tiep: Manual QA va xu ly regression Invoice neu yeu cau gate full pass.
