# KIOT PC Integration V1 — Provider Verification Evidence

## Scope and decision

This verification covers only `D:\Kiot\kiotviet-clone` on `integration/pc-products-orders-v1`. The website PC repository was not opened for write, no production database was used, the Draft PR was not merged, and the production feature flag was not enabled.

| Decision | Value | Reason |
| --- | --- | --- |
| KIOT provider contract verified | YES | Contract, automated coverage, live signed HTTP, DB deltas and log scan agree. |
| Ready for PC-side verification | YES | The provider gate is satisfied; PC may now verify its sender separately. |
| Ready for review | YES | Scoped changes and evidence are ready for review in Draft PR #32. |
| Safe to merge | NO | Repository-wide test/Pint baselines are red and required reviewer/UAT sign-off is pending. |
| Safe to deploy with flags off | NO | Deployment authorization and UAT sign-off are outside this verification. |
| Safe to enable production | NO | No production configuration, rollout or operational sign-off was performed. |

## Repository and environment

- Base: `origin/production-customer-group` at `0e68f13807aaa69b97e5c90b45b2131944b9c196`.
- Initial integration head: `f8fce9fc0ea11a1449f0ed17df30c15ab01f7fdb` (local and remote matched before verification).
- Draft PR: `https://github.com/cuongdesignnb/kiot/pull/32`.
- Test runtime: PHP 8.2.29, MySQL 8.0.44, Node 20.15.1, npm 10.7.0.
- Test database: `mysql://127.0.0.1:3319/sales_test`; credentials were not printed or stored in evidence.
- Automated cache/queue: `array` / `sync`. Live replay smoke used database cache so two HTTP requests shared an atomic nonce store.
- Docker: only the existing KIOT container `sales_mysql_test` was started because port 3319 was initially unavailable. No app or phpMyAdmin container was started. The container was stopped after the DB gates and port 3319 was verified closed.
- The isolated base worktree was `C:\tmp\kiot-pc-baseline`; it was removed after JUnit comparison. Other worktrees and backup directories were not changed.

Machine-readable request/JUnit/snapshot artifacts were stored under `storage/app/audit/pc-provider-verification-20260720/`. This ignored directory is not committed and contains no integration secret or full signature.

## Contract parity

| Content | Documentation | Implementation | Test/evidence | Result |
| --- | --- | --- | --- | --- |
| Endpoints/methods | Five V1 routes | `routes/api.php` controllers match | `route:list -vv` shows exactly five, all HMAC protected | PASS |
| Headers | Client, timestamp, nonce, signature; idempotency on mutation | Middleware and Form Requests | Missing-header and missing-idempotency tests | PASS |
| HMAC canonical | `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_RAW_BODY` | Raw `getContent()`, `getPathInfo()`, `hash_equals` | Independent client, empty GET, raw POST and tamper tests | PASS |
| Request schema | UUID/date/customer/item/non-negative money | Integration Form Requests | Valid/invalid mutation coverage | PASS |
| Response schema | Stable success/error envelopes | `PcIntegrationResponse` | Feature and live HTTP assertions | PASS |
| Error codes | 401/404/409/422/429/503 | Middleware/controllers/services | Negative contract suite | PASS |
| Idempotency | raw-body hash, event ID and key | `integration_events` plus unique claims | duplicate/conflict tests and live smoke | PASS |
| Exact-case SKU | trim only | binary/exact defensive filtering | wrong-case and URL-encoded tests | PASS |
| Reservation | active/released/expired/consumed | transactional reservation service | lifecycle, rollback and concurrency tests | PASS |
| Cancellation | external source only, guarded terminal states | transactional cancel service | API/UI cancel coverage | PASS |

Three contract drifts were reproduced with failing tests before the fixes:

1. Service Products leaked from `include_inactive`, `updated_since`, and detail responses. They are now always excluded from Product V1.
2. `updated_since` was converted to UTC before comparison with MySQL application-timezone timestamps, widening the incremental window by seven hours. It is now normalized to the configured application timezone, with inclusive boundary and same-timestamp cursor tests.
3. External Order conversion could create an Invoice inside a locked accounting period. The external conversion transaction now calls `LockPeriodService` before stock/Invoice mutation; the rollback test proves Order/reservation/stock remain unchanged.

The contract and UAT checklist were updated for these decisions. Order intake itself remains outside accounting-period locking because it creates only a temporary Order and reservation, not an Invoice, debt, CashFlow or StockMovement.

## Route and fail-closed evidence

`php artisan route:list --path=api/integrations/v1/pc -vv` returned exactly:

- `GET /api/integrations/v1/pc/products`
- `GET /api/integrations/v1/pc/products/{sku}`
- `POST /api/integrations/v1/pc/orders`
- `GET /api/integrations/v1/pc/orders/{externalOrderId}`
- `POST /api/integrations/v1/pc/orders/{externalOrderId}/cancel`

Every route includes `VerifyPcIntegrationSignature`; there is no debug/bypass route. Automated tests prove feature-off, missing client, missing secret, nonexistent branch and soft-deleted branch all fail closed with `503` and no fallback branch.

## HMAC and privacy evidence

- Independent `PcIntegrationSignedClient` signs the exact body bytes and parses only the URL path into the canonical string.
- Positive coverage includes empty-body GET, Unicode Order payload, integer/decimal-preserving JSON, exact URL-encoded SKU and lowercase 64-character hex signature.
- Negative coverage includes wrong client/signature, each missing header, old/future timestamp, 129-byte nonce, body/method/path tampering, missing mutation idempotency key, replay and rate-limit window reset.
- Middleware uses `Cache::add`, not `has()` followed by `put()`. Live concurrent requests with identical nonce produced one `200` and one `409 REPLAYED_NONCE`.
- Evidence masks integration client and idempotency keys as short SHA-256 values and replaces signatures with `[REDACTED]`.
- A post-smoke log scan found no test secret, full client ID, phone, email, signature header or idempotency header. Rejection logs contain only error code, client hash, method, path and IP.

## Product evidence

The guarded fixture script creates the required testing-only matrix: normal, serial with two in-stock serials, stock-one, stock-zero, inactive, non-sellable, exact-case, soft-deleted and service SKUs. Each has controlled retail price, barcode, weight, warranty and timestamp; the script refuses any environment/database not explicitly named testing.

Automated and live evidence proves:

- Default list returns only active, sell-directly, non-deleted, non-service Products.
- `include_inactive` and `updated_since` include inactive and deleted tombstones, but never service Products.
- Detail uses exact-case SKU and supports URL encoding; wrong case and service return `UNKNOWN_SKU`.
- Cursor is stable by `(updated_at,id)` with no duplicate/omission for equal timestamps.
- `updated_since` includes the exact boundary and excludes a Product one second before it after timezone conversion.
- Only active reservations reduce availability; `available = max(0, physical - active reservations)`.
- List/detail omit cost, inventory total cost, supplier data and serial numbers.

## Order, reservation and serial evidence

- Valid intake returns `201`, creates one confirmed external Order in the configured branch, website-price OrderItem(s), processed IntegrationEvent and one active reservation per item.
- Phone normalization, phone-first reuse, lowercase-email fallback, supplier-role preservation, inactive/merged/ambiguous fail-closed behavior are covered.
- Line total, subtotal, the two shipping regions and final total reject differences over `0.01` without partial Order/reservation state.
- Exact retry returns `200 duplicate=true`; changed external Order returns `EXTERNAL_ORDER_CONFLICT`; reused event/key returns `IDEMPOTENCY_KEY_CONFLICT`.
- Cancel releases reservations without stock/finance mutation. Retry is duplicate success; new events on cancelled/invoiced/terminal/internal Orders are guarded.
- Expiry is idempotent, does not cancel the Order or mutate stock, and skips completed/invoiced Orders.
- External serial Order intake leaves `serial_ids=null` and does not auto-select/sell a serial. Missing selection rolls back. Explicit valid selection creates one InvoiceItemSerial/StockMovement, sells once and consumes the reservation. Injected failure keeps the reservation active and rolls back all mutations.
- Accounting lock test returns `422` during external conversion and proves no Invoice, StockMovement, stock change or reservation consume.

## Live HTTP and database delta

The live smoke ran against `http://127.0.0.1:8099` with database-backed cache and the standalone signed client. Results:

| Scenario | Result |
| --- | --- |
| Product list with query excluded from HMAC | `200` |
| Product detail | `200` |
| Invalid signature | `401 INVALID_SIGNATURE` |
| Concurrent same nonce | exactly one `200`, one `409 REPLAYED_NONCE` |
| Order create | `201`, `duplicate=false` |
| Exact retry | `200`, `duplicate=true` |
| Changed external Order | `409 EXTERNAL_ORDER_CONFLICT` |
| Order status | `200`, active reservation, no customer/payment payload |
| Cancel | `200`, reservation released |
| Availability after cancel | `200`, physical/available both restored to 10 |

Snapshot delta for that run:

| Table/field | Delta |
| --- | ---: |
| customers | +1 |
| orders | +1 |
| order_items | +1 |
| integration_events | +3 |
| external_inventory_reservations | +1 |
| activity_logs | +6 |
| invoices | 0 |
| invoice_items | 0 |
| invoice_item_serials | 0 |
| cash_flows | 0 |
| stock_movements | 0 |
| customer_debts | 0 |
| warranties | 0 |
| Product physical stock | 0 |
| Product cost price | 0 |
| Product inventory total cost | 0 |

The live run used a non-serial fixture, so serial-sold safety is additionally asserted by the serial intake test and the fixture snapshot (`sold=0`, `in_stock=2`) before any POS conversion.

## Concurrency and migration evidence

- MySQL race fixture: physical stock 1, two separate PHP sender processes, two different Orders of quantity 1.
- Result on each run: one success, one `INSUFFICIENT_AVAILABLE_STOCK`, one external Order, one active reservation, physical stock still 1, no negative availability.
- Repeated 5/5 times without an unhandled deadlock or raw SQL error.
- `migrate:fresh` succeeded, the three integration migrations rolled back in reverse order, then migrated up again successfully.
- Migration review confirms both IntegrationEvent unique claims, the external Order composite unique, reservation indexes and restrictive audit foreign keys. Feature configuration remains default-off and no production expiry schedule is registered.

## Automated and static gates

| Gate | Result |
| --- | --- |
| Composer install | PASS; locked dependencies installed, existing PHP extension/PSR-4 warnings recorded |
| Focused PcIntegration | PASS: 36 tests, 261 assertions, 22.777 s |
| MySQL stock-one concurrency | PASS 5/5, 8 assertions per run |
| Base related regression | 612 tests, 2,240 assertions, 84 failures, 5 errors, 5 skipped |
| Integration related regression | 630 tests, 2,801 assertions, 27 failures, 5 errors, 5 skipped; 0 defect cases new versus base |
| Full suite | RED baseline: 1,743 tests, 10,959 assertions, 99 failures, 19 errors, 18 skipped, 556.721 s; 0 PcIntegration defects |
| Pint changed PHP files | PASS |
| Pint whole repository | RED baseline: 841 files scanned, 540 style issues and 2 pre-existing parse errors |
| PHP lint changed files | PASS |
| `git diff --check` | PASS |
| `npm ci` | PASS; Node engine warning and 9 audit findings (4 moderate, 5 high) retained as baseline/out-of-scope |
| Frontend build | PASS: 925 modules, 24.65 s |
| Route review | PASS, exactly five protected routes |
| Source/log credential scan | PASS |

One apparent OrderReturn regression appeared in an earlier order-dependent broad run, but passed five isolated retries on integration and one isolated retry on base. The final JUnit name-set comparison contains zero new related defects.

## Known limitations and release boundary

- Repository-wide test and Pint gates are not green; they are documented, not hidden or weakened.
- Node 20.15.1 is below the warning threshold declared by `@vitejs/plugin-vue` (`20.19+`), although the Vite build passes.
- npm reports 9 dependency vulnerabilities. Package upgrades are outside this scoped provider verification.
- PHP emits existing startup warnings for unavailable OCI/Firebird extensions; MySQL integration tests are unaffected.
- Product detail intentionally hides service Products entirely. Import returns `PRODUCT_NOT_SELLABLE` for service SKUs.
- No production deploy, feature enable, expiry schedule, PC sender work, merge, force push or production-data test was performed.
- Backend review, product-owner UAT and operations sign-off remain mandatory before merge/deploy/enablement.

Therefore `READY_FOR_PC_VERIFICATION=YES`, while `SAFE_TO_MERGE=NO`, `SAFE_TO_DEPLOY_WITH_FLAGS_OFF=NO`, and `SAFE_TO_ENABLE_PRODUCTION=NO`.
