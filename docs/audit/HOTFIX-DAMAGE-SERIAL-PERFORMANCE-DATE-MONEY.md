# HOTFIX ? Damage Serial Performance, Date, Money

## T?m t?t
- T?ng t?c picker Serial/IMEI ? `Damages/Create`
- ??i hi?n th? ng?y sang chu?n Vi?t Nam b?ng `DateTimePicker`
- Gi? format ti?n VND nh?t qu?n

## Root cause
- **Kh? n?ng cao root cause n?m ? FE render qu? nhi?u serial button, kh?ng ph?i API backend**.
- `Damages/Create` tr??c ?? render to?n b? `item.serials` tr?c ti?p b?ng `v-for="serial in item.serials"`.
- POS d?ng c?ng ngu?n endpoint serial nh?ng ?i theo ki?u search/filter thay v? render full list ngay l?p t?c.

## Endpoint th?c t? ?ang g?i
- `GET /api/products/{id}/serials`
- ??y ch?nh l? endpoint POS ?ang d?ng (`PosController@getProductSerials`).
- Kh?ng ??i sang `/products/{id}/serials` ho?c `/damages/products/{id}/serials`.

## Discovery / network notes
### X?c nh?n code path
- `Damages/Create` ?ang g?i `GET /api/products/{id}/serials`.
- `routes/web.php` map endpoint n?y sang `PosController@getProductSerials`.
- `PosController@getProductSerials` ti?p t?c g?i `SerialAvailabilityService`.

### Local endpoint timing (non-browser check)
?o local route dispatch v?i:
- `/api/products/321/serials`

Observed:
- run 1: status `200`, count `10`, ~`61.61ms` (cold-ish)
- run 2: status `200`, count `10`, ~`8.93ms`
- run 3: status `200`, count `10`, ~`8.26ms`

K?t lu?n:
- Route backend local tr? nhanh sau warm-up.
- K?t h?p v?i vi?c POS d?ng c?ng endpoint nh?ng kh?ng b? c?m gi?c ch?m nh? Damage, nguy?n nh?n h?p l? nh?t l? **chi?n l??c render FE c?**.

## Changes made
### Frontend
File:
- `D:/Kiot/kiotviet-clone/resources/js/Pages/Damages/Create.vue`

?? l?m:
- Th?m `serial_search` cho t?ng line item
- Th?m helper `visibleSerialsForItem(item)`
- Gi? serial ?? ch?n lu?n hi?n ra
- Ch? render:
  - to?n b? serial ?? ch?n
  - c?ng t?i ?a 50 serial match theo filter
- Th?m ? t?m Serial/IMEI
- Gi? nguy?n API endpoint c?
- Th?m timing log:
  - `console.info('[Damage serial] loaded', { product_id, count, ms })`
- Thay native `date` + `time` input b?ng `DateTimePicker`
- Gi? payload `transactionDate` d?ng canonical `yyyy-MM-ddTHH:mm`
- Ti?p t?c d?ng `formatVND as formatCurrency` cho VND display

### Backend
- Kh?ng s?a logic backend
- Kh?ng b? validate trong `DamageController@store`

## Files changed
- `D:/Kiot/kiotviet-clone/resources/js/Pages/Damages/Create.vue`
- `D:/Kiot/kiotviet-clone/docs/audit/HOTFIX-DAMAGE-SERIAL-PERFORMANCE-DATE-MONEY.md`

## Data safety
- C? migration kh?ng: **Kh?ng**
- C? backfill kh?ng: **Kh?ng**
- C? update d? li?u c? kh?ng: **Kh?ng**
- C? x?a d? li?u kh?ng: **Kh?ng**
- C? s?a tr?c ti?p `serial_imeis.status` kh?ng: **Kh?ng**
- C? s?a tr?c ti?p `products.stock_quantity` kh?ng: **Kh?ng**
- C? recalculate t?n kho / gi? v?n / serial kh?ng: **Kh?ng**

## Tests run
1. `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`
   - **PASS** ? 5 tests, 12 assertions
2. `node --test resources/js/tests/moneyInput.test.mjs`
   - **PASS** ? 7 tests
3. `php artisan test tests/Feature/DateTime/Step245DateTimeFormatTest.php`
   - **PASS** ? 5 tests, 18 assertions

## Build
1. `npm run build`
   - **PASS**

## Manual QA
- Browser manual QA: **Ch?a ch?y trong phi?n n?y**
- DevTools Network capture tr?c ti?p tr?n browser: **Ch?a ch?y trong phi?n n?y**
- ?? x?c nh?n thay th? b?ng:
  - code path ??ng endpoint
  - local route timing nhanh
  - root cause ph? h?p v?i kh?c bi?t UI gi?a Damage vs POS

## Remaining risk
- N?u production c? s?n ph?m v?i s? serial r?t l?n, FE hi?n t?i v?n t?i full payload t? API r?i m?i gi?i h?n render.
- Tuy nhi?n HOTFIX n?y ?? lo?i b? bottleneck FE l?n nh?t: **render to?n b? serial button c?ng l?c**.
- N?u sau HOTFIX v?n c?n ch?m ? m?i tr??ng th?c t?, b??c ti?p theo n?n l? endpoint backend c? search/pagination ri?ng cho serial. B??c ?? **ch?a tri?n khai trong HOTFIX n?y**.
