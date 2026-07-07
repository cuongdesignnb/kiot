# HOTFIX - Customer Debt Return Refund Target Type No Accent

## Scope

- Repo: `cuongdesignnb/kiot`
- PR: `#12`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Area: customer debt document timeline only.

## Production Read-Only Finding

Production dump imported locally from `D:\Kiot\kiot.sql.zip` into MariaDB database `kiot_prod_latest_20260707`.

Customer Minh Vuong-Thanh Hoa has:

- Customer `KH177460366865`, `id = 38`, current `debt_amount = 0`.
- Return `TH2026062611405938`, total `3,800,000`, `paid_to_customer = 3,800,000`.
- Real refund cashflow `PC2026062611405949`, amount `3,800,000`.
- Cashflow `target_type` is `Khach hang` without accent.
- Cashflow `reference_type` is `OrderReturn`.
- Cashflow `reference_code` is `TH2026062611405938`.

## Root Cause

PR #12 suppressed virtual `PCTH...` fallback only when the real refund cashflow was present in the `$payments` collection.

The collection was filtered by `target_type = Khách hàng`. Production refund uses `target_type = Khach hang`, so the real `PC...` refund could be missed before the fallback suppression logic runs.

## Change

- Customer receipt/payment cashflow queries now accept both `Khách hàng` and `Khach hang`.
- The query still keeps `target_id = customer.id`.
- The query still keeps `type = receipt/payment`.
- The query does not remove `target_type`, so supplier cashflows remain excluded.
- Existing fallback behavior is preserved when no real refund cashflow exists.

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB write: no.
- Debt rebuild: no.
- Cashflow write-path: unchanged.
- Return/invoice write-path: unchanged.
- Production command: no.

## Tests

Commands and final results:

```text
php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --filter=real_refund --compact
PASS: 2 passed (17 assertions)

php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --filter=minh_vuong --compact
PASS: 1 passed (5 assertions)

php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --filter=no_accent_target_type --compact
PASS: 1 passed (9 assertions)

php artisan test tests/Feature/CustomerDebt/CustomerDebtTimelineDisplayBalanceContractTest.php tests/Feature/Suppliers/SupplierDebtTimelineDisplayBalanceContractTest.php tests/Feature/Purchases/PurchaseCreateSupplierDebtDisplayContractTest.php tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/CustomerDebt/SapoDebtParityTest.php tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php --compact
PASS: 60 passed (333 assertions)

php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php --compact
PASS: 25 passed (105 assertions)

php artisan test tests/Feature/Invoices/InvoiceIndexUtf8Test.php --compact
PASS: 1 passed (18 assertions)
```

## Build

```text
npm run build
PASS
```

## Manual QA

- Customer Minh Vuong timeline should show `TH2026062611405938`.
- Customer Minh Vuong timeline should show real refund `PC2026062611405949`.
- Customer Minh Vuong timeline should not show virtual fallback `PCTH2026062611405938`.
- Final debt timeline balance should match current debt `0`.

## Rollback Plan

Rollback the PR12 target_type hotfix commit. No DB rollback is required.
