# STEP 24.6B - POS Return + Exchange Atomic Transaction

## Scope
- Enable the POS Return tab exchange flow.
- Add `POST /api/pos/return-exchange`.
- Create the return voucher and exchange invoice in one database transaction.
- Clarify business boundaries:
  - Customer return: one return voucher belongs to one sales invoice.
  - POS exchange: customer return plus a new sale invoice for exchange items.
  - Supplier return stays in `purchase-returns`; it is not mixed into POS exchange.
- No migration, no backfill, no legacy data update, no production apply.

## Source Read
- `resources/js/Pages/POS/Index.vue`
- `routes/web.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Services/InvoiceSaleService.php`
- `app/Services/MovingAvgCostingService.php`
- `app/Services/StockMovementService.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/ReturnTotalCalculator.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceItem.php`
- `app/Models/OrderReturn.php`
- `app/Models/ReturnItem.php`
- `app/Models/Product.php`
- `app/Models/SerialImei.php`
- POS and return regression tests under `tests/Feature/POS`, `tests/Feature/OrderReturn`, `tests/Feature/Invoice`, `tests/Feature/Invoices`.

## Root Cause
- STEP 24.6 intentionally left the F7 exchange input disabled because there was no backend service that could atomically combine return creation and a new sale invoice.
- Return creation logic lived in `OrderReturnController@store`, so POS exchange needed a reusable service instead of posting exchange data to `/returns` or making two separate requests.

## Architecture
- `OrderReturnCreationService`
  - Extracts return creation responsibilities from `OrderReturnController@store`.
  - Recomputes return totals through `ReturnTotalCalculator`.
  - Keeps RR-11 over-return guard, cancelled invoice guard, Step 23.2 serial validation, stock restore, serial restore, moving average cost restore, stock movement, debt ledger, cashflow, and activity log.
  - `OrderReturnController@store` now delegates return-only creation to this service.
- `PosReturnExchangeService`
  - Wraps the whole return + exchange flow in `DB::transaction`.
  - Calls `OrderReturnCreationService` first, then `InvoiceSaleService::createSale()`.
  - Uses `InvoiceSaleService` for stock, serial, COGS, cashflow, debt, and warranty side effects of the exchange invoice.
  - Locks and validates exchange products before sale creation.
  - Forces `allow_oversell=false` for exchange invoices so exchange cannot sell more than current stock even if normal POS sale settings allow oversell.
  - Validates exchange serials through `SerialAvailabilityService`, blocking sold, returned-to-supplier, and under-repair serials.
  - Writes only document notes to cross-reference the return and exchange invoice because no schema link was added.
- `PosController@returnExchange`
  - Validates request shape and seller context.
  - Calls the service and returns JSON.
- `resources/js/Pages/POS/Index.vue`
  - Enables F7 product search via `/api/pos/products`.
  - Maintains `returnState.exchangeItems`.
  - HOTFIX 24.6B.1: allows hiding individual source invoice lines from the return voucher without deleting invoice data; hidden lines are reset to qty 0 and are not submitted.
  - HOTFIX 24.6B.1: when exchange items exist, `paid_to_customer` is shown as readonly net refund after exchange so stale full-return values are not displayed.
  - Shows exchange item table headers and per-line `Tồn: X | Đặt: 0`.
  - Allows direct quantity input plus `+/-` for non-serial exchange items, clamped to available stock.
  - Disables selecting out-of-stock exchange products.
  - Requires serial selection for serial exchange items.
  - Uses sellable serial availability for exchange serial picking.
  - Sends numeric payload to `/api/pos/return-exchange`.

## Payload Contract
- Return-only remains `POST /returns`.
- Exchange uses `POST /api/pos/return-exchange`.
- Backend recomputes return subtotal, fee, return total, exchange subtotal, exchange total, customer pays, and refund to customer.
- Frontend sends money as numbers, not formatted VND strings.
- Frontend may display `Đặt: 0`; reserved/order stock is not yet part of the POS product API.
- HOTFIX 24.6B.1: F3 return invoice search now matches serials through both `invoice_item_serials` links and the `serial_imeis.invoice_id` fallback path.
- HOTFIX 24.6B.1: backend rejects stale `return.paid_to_customer` or `exchange.customer_paid` amounts that do not match the recomputed net settlement.

## Settlement Policy
- If `exchange_total > return_total`:
  - New invoice receives `customer_paid = exchange_total - return_total`.
  - Only the net extra payment is recorded as a receipt cashflow.
  - Debt net remains unchanged when the customer pays the difference.
- If `return_total > exchange_total`:
  - Return voucher receives `paid_to_customer = return_total - exchange_total`.
  - Only the net refund is recorded as a payment cashflow.
  - A debt adjustment offsets the refunded credit so customer debt does not remain negative.
- If totals are equal:
  - No additional receipt or payment cashflow.
  - Debt net remains unchanged.

## Data Safety
- Migration: no.
- Backfill/update old data: no.
- Delete data: no.
- Production apply/deploy: no.
- Rollback plan: transaction rollback covers return, invoice, inventory, serial, stock movement, debt, cashflow, and warranty side effects if any step throws.

## Tests Run
| Command | Result |
|---|---|
| `php -l app/Services/PosReturnExchangeService.php` | PASS - no syntax errors |
| `php -l app/Http/Controllers/PosController.php` | PASS - no syntax errors |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 17 passed, 71 assertions; includes stale net settlement validation, out-of-stock normal product, quantity over available stock, under-repair serial, returned-to-supplier serial |
| `php artisan test tests/Feature/POS/Step246PosQuickReturnTest.php` | PASS - 15 passed, 39 assertions; includes F3 serial search via `invoice_item_serials` and `serial_imeis.invoice_id` fallback |
| `php artisan test tests/Feature/OrderReturn` | PASS - 30 passed, 101 assertions |
| `php artisan test tests/Feature/POS/Step246DPosMoneyFormatTest.php` | PASS - 4 passed, 12 assertions |
| `php artisan test tests/Feature/Invoice tests/Feature/Invoices` | PASS - 53 passed, 2 skipped, 163 assertions |
| `php artisan test tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php` | PASS - 14 passed, 47 assertions |
| `npm run build` | PASS - Vite built successfully |

PHP emitted startup warnings for missing local extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`; test/build commands still exited successfully.

HOTFIX 24.6B.1 note:
- The first test run failed because local Docker DB `sales_mysql_test` was stopped; after `docker start sales_mysql_test`, the test suite above passed.
- Browser UI QA still must be run before production deployment.

## Manual QA Checklist
- POS tab Hoa don: not manually browser-tested.
- POS tab Dat hang: not manually browser-tested.
- POS tab Tra hang return-only: covered by feature tests, not manually browser-tested.
- F3 return invoice search: covered by feature tests.
- Return serial selection: covered by feature tests.
- F7 exchange search: build passed, not manually browser-tested.
- Exchange normal item: covered by feature tests.
- Exchange normal item over-stock block: covered by feature tests.
- Exchange out-of-stock normal item block: covered by feature tests.
- Exchange serial item requiring serial selection: covered by feature tests.
- Exchange serial under repair block: covered by feature tests.
- Exchange serial returned to supplier block: covered by feature tests.
- VND display and numeric payload: covered by existing money-format tests and build.
- More expensive exchange, cheaper exchange, equal exchange: covered by feature tests.
- Inventory, serial, debt, cashflow rollback: covered by feature tests.

## Remaining Risks
- Browser UI QA has not been run, so layout/ergonomics for the F7 exchange panel still need manual verification.
- No schema link was added between `returns` and `invoices`; the relationship is currently stored in notes and in the JSON response.
- `OrderReturnController@store` delegates to the new service, but the old controller code remains below the early return as historical dead code and should be removed in a cleanup-only pass if desired.

## Production Readiness
- Can deploy production now: no.
- Blocker: browser UI manual QA has not been completed, so this is not production-ready yet.
- Before production: backup DB, deploy code only after review, run POS return/exchange smoke tests, and confirm no migration/backfill is required.
