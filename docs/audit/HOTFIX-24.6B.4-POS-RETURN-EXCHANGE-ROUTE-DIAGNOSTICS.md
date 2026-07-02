# HOTFIX 24.6B.4 - POS Return Exchange Route Diagnostics

## Scope
- Investigate `POST /api/pos/return-exchange` returning 404 on production.
- Add backend diagnostics for validation/server failures.
- Add frontend diagnostics for 404/419/422/500 and success verification after exchange.
- No migration, no backfill, no old data update, no old data delete.

## Source Read
- `routes/web.php`
- `app/Http/Controllers/PosController.php`
- `app/Services/PosReturnExchangeService.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Services/InvoiceSaleService.php`
- `resources/js/Pages/POS/Index.vue`
- `tests/Feature/POS/Step246BPosReturnExchangeTest.php`
- `docs/audit/STEP-24.6B-POS-RETURN-EXCHANGE.md`
- `docs/audit/HOTFIX-24.6B.2-POS-RETURN-EXCHANGE-NET-SETTLEMENT-UI.md`

## QA Error
- Browser showed `POST https://kiot.cuongdesign.net/api/pos/return-exchange 404 (Not Found)`.
- Local curl reproduced current production response as nginx 404:
  - `curl -I https://kiot.cuongdesign.net/api/pos/return-exchange` -> `HTTP/1.1 404 Not Found`, `Server: nginx`.
  - `curl -i -X POST ... --data "{}"` -> `HTTP/1.1 404 Not Found`, nginx HTML body.

## Local Source Diagnostics
- Local source has route:
  - `routes/web.php`: `Route::post('/api/pos/return-exchange', [PosController::class, 'returnExchange'])`.
- Local controller has:
  - `PosController::returnExchange(Request $request, PosReturnExchangeService $service)`.
- Local frontend calls:
  - `axios.post('/api/pos/return-exchange', ...)`.
- Local route list confirms:
  - `POST api/pos/return-exchange ... PosController@returnExchange`.
- Local build contains:
  - `return-exchange`.
  - `Không phát sinh thu/trả`.

## Production Discovery
- SSH command attempted:
  - `ssh -o BatchMode=yes -o ConnectTimeout=10 root@kiot.cuongdesign.net ...`
- Result:
  - `banner exchange: Connection to UNKNOWN port -1: Connection refused`.
- Production filesystem/source/cache route checks could not be completed from this environment.

## Root Cause Status
- Confirmed: local `main` source registers the route and local route-list sees it.
- Confirmed: public production URL currently returns nginx 404 for both HEAD and POST.
- Not fully confirmed from SSH: exact production root cause.
- Most likely based on evidence:
  - production has not pulled the route commit; or
  - production route cache/config cache is stale; or
  - production web server/domain is serving a different app/document root or not passing this path to Laravel.

## Code Changes
- Backend `PosController@returnExchange`:
  - wraps validation in structured JSON 422 response;
  - returns `success=false`, `error_code=POS_RETURN_EXCHANGE_VALIDATION_FAILED`, `message`, and `errors`;
  - returns `success=false`, `error_code=POS_RETURN_EXCHANGE_FAILED`, `debug_id`, and safe message on 500;
  - logs debug context without raw payload.
- Frontend `resources/js/Pages/POS/Index.vue`:
  - adds `formatAxiosError()` for 404/419/403/422/debug_id responses;
  - shows error title plus detailed message;
  - specifically explains `/api/pos/return-exchange` 404 as deploy/route-cache mismatch;
  - replaces exchange success `alert()` with success modal containing return code, exchange invoice code, settlement, and links;
  - does not reset exchange tab before user sees the result.
- Tests:
  - route existence / not-404 validation test;
  - success response document + settlement structure test;
  - clear 422 diagnostics test;
  - forced 500 debug id test.

## Commands Run
| Command | Result |
|---|---|
| `rg -n "return-exchange" routes app resources tests docs` | PASS - local source has route/frontend/tests/docs |
| `rg -n "returnExchange" app/Http/Controllers routes` | PASS - controller method and route found |
| `rg -n "PosReturnExchangeService" app tests docs` | PASS - service references found |
| `php artisan route:list | Select-String -Pattern "return-exchange"` | PASS - local route registered |
| `curl -I https://kiot.cuongdesign.net/api/pos/return-exchange` | Production returns nginx 404 |
| `curl -i -X POST https://kiot.cuongdesign.net/api/pos/return-exchange --data "{}"` | Production returns nginx 404 |
| `ssh -o BatchMode=yes root@kiot.cuongdesign.net ...` | Failed - connection refused |

## Tests Run
| Command | Result |
|---|---|
| `php -l app/Http/Controllers/PosController.php` | PASS - no syntax errors |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 24 passed, 137 assertions |
| `php artisan test tests/Feature/POS/Step246PosQuickReturnTest.php` | PASS - 15 passed, 39 assertions |
| `php artisan test tests/Feature/POS/Step246DPosMoneyFormatTest.php` | PASS - 4 passed, 12 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS - 30 passed, 101 assertions |
| `php artisan test tests/Feature/Invoice tests/Feature/Invoices` | PASS - 53 passed, 2 skipped, 163 assertions |
| `php artisan test tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php` | PASS - 14 passed, 47 assertions |
| `npm run build` | PASS - Vite built successfully |

PHP emitted local startup warnings for missing `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`; commands exited successfully.

## Manual QA
- Browser QA with serial `PF1RMTWJ`: not run from this environment.
- Production route still returns 404 publicly, so end-to-end exchange was not attempted.
- No production transaction was created.

## Production Fix Plan
1. SSH into `/www/wwwroot/kiot.cuongdesign.net`.
2. Check current commit:
   - `git status`
   - `git rev-parse HEAD`
   - `git log -1 --oneline`
3. Check route:
   - `php artisan route:list | grep -i return-exchange || true`
4. Clear cache:
   - `php artisan optimize:clear`
   - `php artisan route:list | grep -i return-exchange || true`
5. If route still missing, deploy the correct `main` commit after confirmation:
   - `git pull origin main`
   - `composer dump-autoload`
   - `php artisan optimize:clear`
   - `npm run build`
   - `php artisan config:cache`
   - `php artisan route:cache`
   - `php artisan view:cache`
   - `php artisan queue:restart`
6. If route list has the route but public curl still returns nginx 404, inspect nginx document root/rewrite and PHP-FPM target.

## Data Safety
- Migration: no.
- Backfill/update old data: no.
- Delete old data: no.
- Production data mutation: no in this run.
- Production E2E test would create real return/invoice data and needs explicit confirmation first.

## Production Readiness
- Can declare production fixed: no.
- Blocker: production SSH/source/cache route diagnostics could not be completed, and public endpoint still returns nginx 404.
- Next required action: perform production deploy/cache verification with SSH access, then rerun curl and browser QA.
