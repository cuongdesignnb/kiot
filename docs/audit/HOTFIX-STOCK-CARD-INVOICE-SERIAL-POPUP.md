# HOTFIX ? Stock Card Invoice Serial Popup

## Summary
Hi?n th? Serial/IMEI theo t?ng d?ng h?ng trong popup h?a ??n m? t? Th? kho.

## Files changed
- `D:\Kiot\kiotviet-clonepp\Http\Controllers\ProductController.php`
- `D:\Kiot\kiotviet-cloneesources\js\Pages\Welcome.vue`
- `D:\Kiot\kiotviet-clone	ests\Feature\Products\Step247StockCardDocumentResolverTest.php`
- `D:\Kiot\kiotviet-clone\docsudit\HOTFIX-STOCK-CARD-INVOICE-SERIAL-POPUP.md`

## Data safety
- C? migration kh?ng: **Kh?ng**
- C? backfill kh?ng: **Kh?ng**
- C? update d? li?u c? kh?ng: **Kh?ng**
- C? s?a `serial_imeis` kh?ng: **Kh?ng**
- C? s?a t?n kho / gi? v?n / lu?ng b?n h?ng-POS kh?ng: **Kh?ng**

## Backend
- `documentDetail()` case `invoice` eager-load th?m `items.serials`.
- Map `serials` + `serial_count` v?o t?ng item.
- Fallback ??c `invoice_items.serial` string khi kh?ng c? snapshot trong `invoice_item_serials`.
- Kh?ng ?nh h??ng c?c case document kh?c.

## Frontend
- Th?m state `openDocSerialIndex`.
- Reset state khi m?/??ng popup.
- Hi?n th? link `X serial/IMEI. Xem chi ti?t` d??i t?n h?ng.
- Expand danh s?ch serial d?ng chip ngay d??i d?ng item.

## Tests
### Ran
1. `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`
   - **PASS** ? 5 tests, 12 assertions
2. `php artisan test --filter=DocumentDetail`
   - **PASS** ? 2 tests, 9 assertions
   - Covered:
     - invoice item serial snapshots from `invoice_item_serials`
     - legacy fallback from `invoice_items.serial`

## Build
1. `npm run build`
   - **PASS**

## Manual QA
- ?? ch?y hay ch?a: **Ch?a ch?y**
- Suggested flow:
  - `/products`
  - m? s?n ph?m c? h?a ??n serial
  - tab `Th? kho`
  - click m? h?a ??n
  - verify link `X serial/IMEI. Xem chi ti?t`
  - click link ? list serial chips hi?n ??ng

## Remaining risk
- H?a ??n legacy s? ch? hi?n serial n?u c? ?t nh?t 1 trong 2 ngu?n:
  1. `invoice_item_serials`
  2. `invoice_items.serial`
- N?u h?a ??n c? kh?ng c? c? snapshot l?n string fallback th? popup kh?ng th? t? d?ng l?i serial, v? HOTFIX n?y **kh?ng** s?a d? li?u DB.
