# HOTFIX - Damage Serial Loading

## Scope
- Module: Xuat huy hang hoa (`Damage`).
- Screen: `/damages/create`.
- Issue: UI co the treo tai `Dang tai serial/IMEI kha dung...` trong khi POS load serial nhanh voi cung san pham.

## Sources Checked
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/Damages/Create.vue`
- `routes/web.php`
- `app/Http/Controllers/PosController.php`
- `app/Services/SerialAvailabilityService.php`
- `app/Http/Controllers/DamageController.php`

## Findings
- POS calls `GET /api/products/{product}/serials`.
- Route exists as `api.products.serials` and points to `PosController@getProductSerials`.
- `PosController@getProductSerials` returns a JSON array from `SerialAvailabilityService::querySellableForProduct()`.
- `SerialAvailabilityService` allows sellable statuses `in_stock`, `available`, `ready`; excludes blocked serial statuses and repair statuses `not_started`, `repairing`.
- Damage now imports `axios` explicitly and calls only `GET /api/products/${item.product_id}/serials`.

## Changes
- Hardened `loadSerialsForItem()` in `Damages/Create.vue`:
  - explicit `axios` import;
  - request URL fixed to `/api/products/${item.product_id}/serials`;
  - `Accept: application/json` and `X-Requested-With: XMLHttpRequest` headers;
  - `AbortController` timeout after 8 seconds;
  - force reload aborts any hanging previous request for the same item;
  - `finally` always sets `item.serial_loading = false`;
  - debug logs for request, response, and error;
  - clear messages for timeout, 403, 404, 500, empty response, and generic failures.
- UI renders:
  - loading text while request is active;
  - error message on failure;
  - empty message for `[]`;
  - `Tim thay X serial/IMEI kha dung` and selectable serial buttons when serials are returned.

## Network / Production Checks
- Production browser Network check: not run in this local session.
- Production read-only tinker check: not run in this local session.
- Expected request: `GET /api/products/{id}/serials`.
- Expected response shape: JSON array of normalized serial objects.

## Data Safety
- Migration: No.
- Backfill: No.
- Legacy data update: No.
- Direct `serial_imeis.status` update: No.
- Direct `products.stock_quantity` update: No.
- Inventory/cost/serial recalculation: No.
- Backend validation in `DamageController@store`: unchanged.

## Tests
- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`: blocked in local environment. MySQL connection refused with `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it`; 5 tests failed before assertions.
- `php artisan test tests/Feature/Damage/DamageCreateMetaTest.php`: blocked in local environment. Same MySQL connection refused error; 5 tests failed before assertions.
- `npm run build`: pass, Vite build completed in 7.33s.

## Manual QA
- Open DevTools Network.
- Open `/damages/create`.
- Select the affected serial product.
- Verify the request URL is `/api/products/{id}/serials`.
- Verify status/response.
- If response has serials, UI renders serial buttons.
- If response is empty/error/timeout, UI shows a clear message and does not stay loading.
- Compare with POS using the same product.

Manual QA has not been run in this local session.
