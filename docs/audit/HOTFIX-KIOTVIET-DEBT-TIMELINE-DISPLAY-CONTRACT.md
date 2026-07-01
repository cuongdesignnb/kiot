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
| Purchase create/edit payload | `app/Http/Controllers/PurchaseController.php` | Adds supplier display aliases to supplier picker props |
| Purchase frontend | `resources/js/Pages/Purchases/Create.vue`, `resources/js/Pages/Purchases/Edit.vue` | Uses supplier-oriented display balance for old/projected supplier balance and search results |
| Tests | `tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php`, `tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php`, `tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php` | Adds contract coverage for customer timeline, supplier timeline, and purchase supplier picker |

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

| Command | Result |
|---|---|
| `php -l app/Support/Debt/PartnerDebtDisplayBalance.php` | PASS, with local PHP extension startup warnings for missing `oci8_*`, `pdo_oci`, `pdo_firebird` |
| `php -l app/Services/CustomerDebtDocumentTimelineService.php` | PASS, same extension warnings |
| `php -l app/Services/SupplierDebtDocumentTimelineService.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/CustomerController.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/SupplierController.php` | PASS, same extension warnings |
| `php -l app/Http/Controllers/PurchaseController.php` | PASS, same extension warnings |
| `php -l` on new test files | PASS, same extension warnings |
| `git diff --check` | PASS |
| `npm run build` | PASS |
| `php artisan test tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php` | BLOCKED by local DB connection refused: `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it` |

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
5. Targeted PHPUnit tests are added but need a running local/test DB to complete assertion verification.
