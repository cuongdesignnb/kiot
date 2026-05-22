# HOTFIX - Damage Load Serial Like POS

## Pham vi
- Module: Xuat huy hang hoa (`Damage`).
- Man hinh: `/damages/create`.
- Nghiep vu: load Serial/IMEI kha dung cho dong hang serial khi tao phieu xuat huy.

## Source da kiem tra
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/Damages/Create.vue`
- `routes/web.php`
- `app/Http/Controllers/PosController.php`
- `app/Services/SerialAvailabilityService.php`
- `app/Http/Controllers/DamageController.php`

## Root cause
- POS load serial qua `/api/products/{product}/serials`.
- Damage create dang goi endpoint rieng `/damages/products/{product}/serials`, khong giong POS nen production van co the khong tra serial theo dung flow dang hoat dong o POS.

## Thay doi
- `Damages/Create.vue` chi goi:
  - `GET /api/products/${item.product_id}/serials`
- Bo logic goi `/products/${item.product_id}/serials` va `/damages/products/${item.product_id}/serials` trong frontend.
- Giu timeout 8 giay va `finally` de khong ket UI o trang thai dang tai.
- Loi 403/404/network hien message ro.
- Neu endpoint tra mang rong, hien message khong co serial kha dung.
- Label serial uu tien `serial.label`, sau do `serial_number`, `imei`, `code`, `#id`.
- UI hien `Tim thay X serial/IMEI kha dung` khi co ket qua.

## Backend
- Khong sua backend trong hotfix nay.
- `/api/products/{product}/serials` da ton tai va dang dung `PosController@getProductSerials`.
- Endpoint POS dung `SerialAvailabilityService::querySellableForProduct()` va `normalizeForResponse()`.
- Khong sua `MovingAvgCostingService`, `StockMovementService`, `SerialAvailabilityService`, hay validate trong `DamageController@store`.

## Data safety
- Co migration khong: Khong.
- Co backfill khong: Khong.
- Co update du lieu cu khong: Khong.
- Co xoa du lieu khong: Khong.
- Co sua truc tiep `serial_imeis.status` khong: Khong.
- Co sua truc tiep `products.stock_quantity` khong: Khong.

## Tests
- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`: blocked in local environment. MySQL connection refused with `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it`; 5 tests failed before assertions.
- `php artisan test tests/Feature/Damage/DamageCreateMetaTest.php`: blocked in local environment. Same MySQL connection refused error; 5 tests failed before assertions.
- `npm run build`: pass, Vite build completed in 7.01s.

## Manual QA
- POS load serial duoc voi cung san pham.
- `/damages/create` chon san pham serial va thay danh sach serial tu endpoint POS.
- Chon serial, hoan thanh phieu, serial chuyen `defective`.
- Huy phieu, serial ve `in_stock`.

Manual browser QA chua chay trong moi truong nay.
