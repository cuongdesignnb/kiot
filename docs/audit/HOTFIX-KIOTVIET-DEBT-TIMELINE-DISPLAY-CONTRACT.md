# HOTFIX — KiotViet debt timeline display contract

## Scope

Normalize read-side debt display formulas for customer/supplier timelines and the purchase supplier picker.

This hotfix is response/display only:

- No migration.
- No backfill.
- No seed.
- No production command.
- No production DB write.
- No stock movement, costing, serial/IMEI, payroll, or write-path change.

## Base

- Branch: `hotfix/kiotviet-debt-timeline-display-contract`
- Base checked locally: `origin/main` at `e52012f3349f80e114396f9a84aae5722dc592f3`
- Note: the handoff mentioned deployed commit `05fa129011c2f29ed3a9071f93101211c4bb0fe5`, but local `origin/main` had already advanced to `e52012f3349f80e114396f9a84aae5722dc592f3`.

## Business Contract

| Screen | Formula | Meaning |
|---|---:|---|
| Customer | `debt_amount - supplier_debt_amount` | Positive = customer owes store; negative = store owes customer/partner |
| Supplier only | `supplier_debt_amount` | Store payable to supplier |
| Supplier dual-role | `supplier_debt_amount - debt_amount` | Supplier-oriented payable after customer receivable netting |

The latest visible timeline running balance must match the current screen balance. Raw document totals remain available for audit.

## Source Checked

| File | Finding |
|---|---|
| `app/Services/CustomerDebtDocumentTimelineService.php` | Summary already used customer net, but displayed running balance could remain raw document reconstruction. |
| `app/Services/SupplierDebtDocumentTimelineService.php` | Dual-role supplier timeline used a visible virtual opening row for non-empty mismatch; this could be mistaken for a voucher. |
| `app/Services/PartnerDebtLedgerService.php` | Existing compatibility ledger already carries target/final/virtual-opening concepts; no write-path change needed. |
| `app/Http/Controllers/CustomerController.php` | Customer list calculated net manually; replaced with shared helper aliases. |
| `app/Http/Controllers/SupplierController.php` | Supplier list calculated oriented balance manually; supplier search returned only raw `supplier_debt_amount`. |
| `app/Http/Controllers/PurchaseController.php` | Purchase create/edit supplier props did not attach supplier-oriented aliases. |
| `resources/js/Pages/Customers/Index.vue` | Customer list formula was directionally correct; now prefers backend aliases and a fuller running-balance key order. |
| `resources/js/Pages/Suppliers/Index.vue` | Supplier list already preferred oriented aliases; now includes `supplier_display_balance`. |
| `resources/js/Pages/Purchases/Create.vue` | Picker/old balance used raw `supplier_debt_amount`; now uses supplier-oriented helper/fallback. |
| `resources/js/Pages/Purchases/Edit.vue` | Same raw supplier balance issue as create/search; now uses supplier-oriented helper/fallback. |

## Changes

| Area | Files | Result |
|---|---|---|
| Shared formula helper | `app/Support/Debt/PartnerDebtDisplayBalance.php` | Adds canonical customer/supplier display formula and response aliases |
| Customer timeline | `app/Services/CustomerDebtDocumentTimelineService.php` | Aligns display running balance to customer screen balance; keeps `raw_document_final_balance` and `document_final_balance_before_alignment` |
| Supplier timeline | `app/Services/SupplierDebtDocumentTimelineService.php` | Replaces non-empty virtual opening row with read-only display alignment; keeps raw audit fields |
| Customer list API | `app/Http/Controllers/CustomerController.php` | Uses shared aliases |
| Supplier list/search/API | `app/Http/Controllers/SupplierController.php` | Uses shared aliases; supplier search now returns raw debt plus supplier-oriented aliases |
| Supplier purchase CSV | `app/Http/Controllers/SupplierController.php` | Fixes purchase-history CSV header from `Mã phiếu` to `Mã phiếu nhập`; response/export label only |
| Purchase create/edit payload | `app/Http/Controllers/PurchaseController.php` | Adds supplier display aliases to supplier picker props |
| Purchase frontend | `resources/js/Pages/Purchases/Create.vue`, `resources/js/Pages/Purchases/Edit.vue` | Uses supplier-oriented display balance for old/projected supplier balance and search results |
| Tests | `tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php`, `tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php`, `tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php`, `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php` | Adds contract coverage for customer timeline, supplier timeline, and purchase supplier picker; updates RR06 cancellation request to include required `cancel_reason` |

## Display Alignment Policy

When document reconstruction final balance differs from the stored screen balance:

- Do not mutate DB.
- Do not create financial voucher.
- Do not add a virtual opening row when real entries already exist.
- Shift only response running-balance aliases so the latest visible row matches the screen balance.
- Preserve raw audit values:
  - `raw_document_final_balance`
  - `document_final_balance_before_alignment`
  - `raw_document_balance`
  - `raw_has_mismatch`
  - `display_alignment_amount`
  - `has_virtual_display_alignment`

The existing virtual opening row remains only for the no-document/no-history case with a stored balance, for backward compatibility with current tests and audit tooling.

Chosen policy: Option B, align display running balance without a visible alignment row when real entries already exist.

Manual cases covered by new fixtures:

- Customer production shape: list/current debt `12_700_000`, document reconstruction `1_900_000`, latest timeline display balance expected `12_700_000`.
- Supplier production shape: dual-role payable `205_000`, receivable `205_000`, supplier screen debt expected `0`; purchase picker must not show raw `205_000` as supplier debt.

## Verification

Local disposable database used:

- Docker container: `kiot_debt_contract_mysql`
- MySQL: `8.0`
- Host: `127.0.0.1:3321`
- Database: `kiot_debt_contract_test`
- User: `kiot_test`
- Production was not accessed.

| Command | Result |
|---|---|
| `php artisan config:clear` with Docker test DB env | PASS |
| `php artisan migrate --force` with Docker test DB env | PASS |
| `php -l app/Support/Debt/PartnerDebtDisplayBalance.php` | PASS, with local PHP extension startup warnings for missing `oci8_*`, `pdo_oci`, `pdo_firebird` |
| `php -l app/Services/CustomerDebtDocumentTimelineService.php` | PASS, same extension warnings |
| `php -l app/Services/SupplierDebtDocumentTimelineService.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/CustomerController.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/SupplierController.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/PurchaseController.php` | PASS, same extension warnings |
| `php -l` on new test files | PASS, same extension warnings |
| `git diff --check` | PASS |
| `npm run build` | PASS |
| `php artisan test tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php` | PASS: 4 passed, 39 assertions |
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | PASS: 12 passed, 41 assertions |
| `php artisan test tests/Feature/CustomerDebt` | PASS: 18 passed, 65 assertions |
| `php artisan test tests/Feature/Supplier` | PASS: 59 passed, 243 assertions |
| `php artisan test tests/Feature/Customers` | PASS: 148 passed, 1 skipped, 799 assertions |
| `php artisan test tests/Feature/Suppliers` | PASS: 45 passed, 346 assertions |
| `php artisan test tests/Feature/Purchases` | PASS: 6 passed, 34 assertions |
| `php artisan test tests/Feature/Customers tests/Feature/Suppliers tests/Feature/Purchases` | PASS: 199 passed, 1 skipped, 1179 assertions |
| `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers tests/Feature/Purchases` | PASS: 276 passed, 1 skipped, 1487 assertions |

## Regression Blocker Triage

Baseline worktree:

- Path: `D:\Kiot\kiot-main-baseline`
- Commit: `origin/main` at `e52012f3349f80e114396f9a84aae5722dc592f3`
- Dependency note: local baseline used a `vendor` junction to `D:\Kiot\kiotviet-clone\vendor` only to run PHPUnit; no production access.

| Test | Hotfix before triage | `origin/main` baseline | Classification | Action |
|---|---|---|---|---|
| `Tests\Feature\CustomerDebt\RR06CustomerDebtLedgerTest::cancel_invoice_should_create_customer_debt_reverse_transaction` | FAIL: `debt_amount` stayed `600000` | FAIL: same result | Pre-existing test/request compatibility issue, not caused by display hotfix | Updated the test request to include required `cancel_reason`; no `InvoiceController` or write-path change |
| `Tests\Feature\Supplier\HOTFIX2414SupplierTabExportTest::supplier_purchase_history_export_downloads_csv` | FAIL: CSV header `Mã phiếu`; expected `Mã phiếu nhập` | FAIL: same result | Pre-existing low-risk export label mismatch | Changed only the purchase-history CSV header label |

Post-triage blocker rerun:

| Test | Result |
|---|---|
| `php artisan test tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php --filter=cancel_invoice_should_create_customer_debt_reverse_transaction` | PASS: 1 passed, 2 assertions |
| `php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php --filter=supplier_purchase_history_export_downloads_csv` | PASS: 1 passed, 6 assertions |

RR06 root cause:

- The controller currently validates `cancel_reason` before entering the cancellation transaction.
- The failing RR06 test did not send `cancel_reason`, so the request returned through validation and never exercised debt reversal.
- After the test sends `cancel_reason`, the existing `CustomerDebtService::recordInvoiceBalanceReversal()` path passes and creates the reverse debt effect.
- No invoice cancellation production code, stock movement, cashflow, serial, costing, or debt write-path was changed in this triage.

Supplier CSV root cause:

- `SupplierController::exportPurchaseHistory()` emitted a generic `Mã phiếu` header.
- The pinned supplier purchase-history export contract expects `Mã phiếu nhập`.
- Fix is label-only; row data and JSON loaders are unchanged.

## Production Safety

- Production was not accessed.
- Production migration was not run.
- Production audit command was not run.
- No data cleanup/remediation was performed.
- No DB update/backfill was performed.
- No deploy was performed.

## BA Review Notes

1. This hotfix standardizes display/API contract only.
2. `supplier_debt_amount` remains raw stored payable for backward compatibility.
3. New aliases are the preferred read contract:
   - `customer_receivable_balance`
   - `supplier_payable_balance`
   - `partner_net_position`
   - `customer_screen_debt`
   - `supplier_screen_debt`
   - `supplier_oriented_balance`
4. Timeline response now separates raw document reconstruction from displayed running balance.
5. Targeted PHPUnit tests now pass on a disposable Docker MySQL test DB.
6. The two broad-regression blockers were confirmed pre-existing on `origin/main`, then resolved without production data changes.
7. Full selected debt/supplier/purchase regression is now green on local Docker MySQL.
