# HOTFIX 24.6B.2 - POS Return Exchange Net Settlement UI

## Scope
- POS > Tra hang > Doi hang F7.
- Clarify net settlement UI so exchange equal value shows no cash movement.
- Block unsafe exchange item price/discount both in frontend and backend.
- No production deploy or production data mutation.

## Source Read
- `resources/js/Pages/POS/Index.vue`
- `app/Http/Controllers/PosController.php`
- `app/Services/PosReturnExchangeService.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Services/InvoiceSaleService.php`
- `app/Services/ReturnTotalCalculator.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/SerialAvailabilityService.php`
- `app/Models/Product.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceItem.php`
- `app/Models/SerialImei.php`
- `tests/Feature/POS/Step246BPosReturnExchangeTest.php`
- `tests/Feature/POS/Step246PosQuickReturnTest.php`
- `tests/Feature/POS/Step246DPosMoneyFormatTest.php`
- `docs/audit/STEP-24.6B-POS-RETURN-EXCHANGE.md`

## Discovery
- `/api/pos/products` currently returns full product model fields including `retail_price`, `cost_price`, `stock_quantity`, `has_serial`, `sku`, `barcode`, and `name`.
- Sample local response for active product included `retail_price: "500000.00"` and did not include `price`, `sale_price`, or `selling_price`.
- Existing `addExchangeItem()` only read `product.retail_price`, so any future or partial API response without that field fell back to `0`.
- Existing backend `calculateExchange()` used `max(0, qty * price - discount)`, which masked invalid line totals instead of rejecting them.

## Root Cause
- Exchange item price resolution was too narrow and had no save blocker for `price <= 0`.
- Exchange item discount was not normalized or rejected when greater than line gross.
- Settlement UI reused a disabled input for net refund, making return-only `paid_to_customer` look like an editable or stale exchange settlement.

## Frontend Changes
- Added `resolveExchangeDefaultPrice()` with fallback fields: `retail_price`, `selling_price`, `sale_price`, `price`, `unit_price`.
- Added exchange line gross/discount helpers:
  - `exchangeLineGross()`
  - `normalizeExchangeLineDiscount()`
  - `normalizeAllExchangeLines()`
- `exchangeLineAmount()` now calculates from clamped line discount without hiding validation errors.
- Price, discount, quantity, and serial count changes normalize line discount.
- Save is blocked when exchange line price is `0`, discount is negative, or discount exceeds line gross.
- The return total row is labeled `Gia tri hang tra` when exchange items exist.
- Added prominent net settlement cards:
  - blue: customer pays extra;
  - red: refund customer;
  - emerald: no cash movement.
- Removed the disabled `Tien tra khach sau doi` input in exchange flow.
- Return-only still shows the editable `paid_to_customer` input.

## Backend Changes
- `PosReturnExchangeService::calculateExchange()` now rejects:
  - `quantity <= 0`;
  - `price <= 0`;
  - `discount < 0`;
  - `discount > quantity * price`.
- Backend settlement stale validation messages are now explicit reload guidance:
  - `return.paid_to_customer`;
  - `exchange.customer_paid`.
- Transaction structure is unchanged: source invoice lock, return total calculation, exchange total calculation, return creation, exchange invoice creation, document annotation all remain inside `DB::transaction`.

## Data Safety
- Migration: no.
- Backfill: no.
- Update old data: no.
- Delete data: no.
- Production data impact: only new transactions if deployed and used.
- Rollback plan: validation errors throw before document creation; service errors rollback the full DB transaction for return, exchange invoice, inventory, serial, stock movement, customer debt, cashflow, and warranty side effects.
- Production backup required before deployment: yes.

## Affected Modules
- New `returns` / `return_items` transactions.
- New exchange `invoices` / `invoice_items`.
- `products.stock_quantity`.
- `serial_imeis` status/invoice linkage.
- `stock_movements`.
- `customer_debts`.
- `cash_flows`.
- Warranty generation via `InvoiceSaleService`.

## Tests Run
| Command | Result |
|---|---|
| `php -l app/Services/PosReturnExchangeService.php` | PASS - no syntax errors |
| `php -l app/Http/Controllers/PosController.php` | PASS - no syntax errors |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 20 passed, 99 assertions |
| `php artisan test tests/Feature/POS/Step246PosQuickReturnTest.php` | PASS - 15 passed, 39 assertions |
| `php artisan test tests/Feature/POS/Step246DPosMoneyFormatTest.php` | PASS - 4 passed, 12 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS - 30 passed, 101 assertions |
| `php artisan test tests/Feature/Invoice tests/Feature/Invoices` | PASS - 53 passed, 2 skipped, 163 assertions |
| `php artisan test tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php` | PASS - 14 passed, 47 assertions |
| `npm run build` | PASS - Vite built successfully |

PHP emitted local startup warnings for missing `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`; commands exited successfully.

## Manual QA Checklist
- Case A - equal value exchange: not browser-tested in this run.
- Case B - exchange item price 0: backend covered by test; frontend build passed; browser QA pending.
- Case C - discount greater than gross: backend covered by test; frontend build passed; browser QA pending.
- Case D - cheaper exchange refund card: covered by feature test for backend settlement; browser QA pending.
- Case E - more expensive exchange customer-pays card: covered by feature test for backend settlement; browser QA pending.
- Case F - return-only paid_to_customer input remains: covered by regression tests; browser QA pending.

## Remaining Risks
- Browser UI QA has not been run, so final visual layout and exact POS ergonomics still need manual verification.
- Product API currently returns `retail_price`; if a product has `retail_price = 0` in data, the UI correctly blocks save until staff enters a price.

## Production Readiness
- Can deploy production now: no.
- Blocker: browser QA for POS return/exchange has not been completed.
- Before production: backup DB, deploy code only after browser QA, verify equal/cheaper/more-expensive exchange, serial return/exchange, cashflow, debt, and stock movement.
