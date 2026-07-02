# STEP 24.7 - Global Advanced Product Search

## Phạm vi audit
- Module: Hang hoa, POS, tra hang POS, bang gia, bao cao gia von, sua chua/task linh kien.
- Man hinh: danh sach hang hoa, POS ban hang/F7 doi hang, API search san pham dung cho nhap hang/don hang/xuat huy/kiem kho/chuyen kho, bang gia, quick return F3 khi search theo hang hoa.
- Nghiep vu: tim san pham theo ten, SKU, barcode, serial/IMEI bang nhieu token roi rac.
- Rui ro chinh: token search dung nhieu dieu kien `LIKE`, co the cham hon full phrase neu du lieu lon.

## Source da kiem tra
- File: `app/Http/Controllers/PosController.php`
- File: `app/Http/Controllers/ProductController.php`
- File: `app/Http/Controllers/Api/DeviceRepairController.php`
- File: `app/Http/Controllers/Api/TaskController.php`
- File: `app/Http/Controllers/PriceSettingController.php`
- File: `app/Http/Controllers/ReportController.php`
- File: `app/Models/Product.php`
- File: `app/Models/SerialImei.php`
- File: `app/Models/ProductVariant.php`
- File: `app/Models/ProductAttribute.php`
- Route: `/products`, `/products/export`, `/api/pos/products`, `/api/pos/returnable-invoices`, `/api/products/search`, `/api/device-repairs/search-products`, `/api/tasks/search-products`
- Service: `app/Services/ProductSearchService.php`
- Test: `tests/Feature/Products/Step247AdvancedProductSearchTest.php`
- Migration: khong co.
- Commit: xem output cuoi sau commit.

## Hien trang
- Backend cu: nhieu controller dung full phrase `LIKE "%keyword%"` tren `name/sku/barcode`, nen query `man 13.3 dom` khong match ten co tu chen giua.
- Frontend: khong can thay layout; cac man dang goi API cu tiep tuc dung endpoint cu.
- Database local: `sales_mysql_test`, DB `kiot_db`, `products.name` collation `utf8mb4_unicode_ci`, table `products` collation `utf8mb4_unicode_ci`.
- Permission: giu nguyen middleware hien co; khong bo guard nao.
- Production/deploy: chua deploy production trong buoc nay.

## Root cause
- Search cu dung full phrase LIKE nen chi match khi chuoi tim nam lien mach trong ten/SKU/barcode.
- Vi du ten `man 13.3 fhd-hd cu spa dom` khong match full phrase `man 13.3 dom`.

## Co anh huong du lieu dang co khong?
- Khong co migration.
- Khong backfill.
- Khong update du lieu cu.
- Khong sua ton kho, serial, gia von, stock movement, cong no, cashflow.
- Thay doi chi nam o query/search va response read-only.

## Phuong an an toan
- Tao `ProductSearchService` dung chung.
- Normalize khoang trang va cac ky tu tach `space`, `-`, `_`, `/`, `\`, `,`, `;`, `:`.
- Token AND across query: tat ca token phai match.
- Field OR per token: moi token co the match `name`, `sku`, `barcode`, hoac `serial_number`.
- Giu full phrase boost trong `applyScore()` de ket qua exact/prefix van len truoc.
- Guard hieu nang: cat query 120 ky tu, toi da 8 token, escape `%`, `_`, `\`.
- Khong them index/fulltext/generated column trong phase nay.

## Noi da chuyen sang ProductSearchService
- `PosController@searchProducts`: POS ban hang va F7 doi hang, giu `sellable_quantity`, `repairing_count`, `matched_serials`.
- `PosController@returnableInvoices`: search hoa don tra hang theo product token trong invoice items, giu search invoice/customer/serial cu.
- `ProductController@index`: danh sach hang hoa.
- `ProductController@apiSearch`: API dung cho nhap hang, dat hang, xuat huy, kiem kho, chuyen kho va cac form dung `/api/products/search`.
- `ProductController@export`: export hang hoa theo filter search.
- `PriceSettingController@index/export`: bang gia.
- `ReportController@costAnalysis`: phan tich gia von theo san pham/serial.
- `Api\DeviceRepairController@searchProducts`: tim linh kien sua chua.
- `Api\TaskController@searchProducts`: tim linh kien/task.

## Khong duoc lam
- Khong sua ton kho/serial/cong no/cashflow.
- Khong migration/index khi chua co xac nhan.
- Khong copy-paste logic moi rai rac; logic token nam trong service dung chung.

## Tests bat buoc
- `php artisan test tests/Feature/Products/Step247AdvancedProductSearchTest.php`: PASS, 12 tests, 30 assertions.
- `php artisan test tests/Feature/POS`: PASS, 61 tests, 254 assertions.
- `php artisan test tests/Feature/Product`: folder khong ton tai.
- `php artisan test tests/Feature/Products`: PASS, 39 tests, 120 assertions.
- `php artisan test tests/Feature/Repair`: PASS, 4 tests, 9 assertions.
- `php artisan test tests/Feature/Purchase`: PASS, 14 tests, 47 assertions.
- `php artisan test tests/Feature/Purchases`: PASS, 4 tests, 18 assertions.
- `npm run build`: PASS, Vite built successfully in 7.74s.
- Ghi chu: moi lan chay PHP co warning extension local thieu `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; test van pass.

## Manual QA
- Chua chay browser UI QA trong buoc nay.
- Test tu dong da verify case `man 13.3 dom` o `/products`, `/api/pos/products`, `/api/products/search`, `/api/pos/returnable-invoices`, `/api/device-repairs/search-products`, `/api/tasks/search-products`.
- Browser QA can chay sau deploy/staging cho POS, danh sach hang hoa, bang gia, nhap hang, sua chua/task.

## Ket luan
- Dat ve code/test local.
- Chua ket luan deploy production neu chua browser QA.
- Co the deploy sau khi owner chap nhan rui ro hieu nang token `LIKE` va chay QA tren staging/production cache dung.
- Phase sau neu production cham: can xac nhan rieng de them FULLTEXT/generated normalized column/product_search_keywords/Scout.
