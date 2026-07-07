# HOTFIX - Dual-role Debt Adjustment KiotViet Style

## Scope

- Repo: `cuongdesignnb/kiot`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Area: customer/supplier manual debt adjustment for dual-role partners.
- Production command: not run.
- Production DB write: not run.

## Production Read-only Finding

The local imported production dump shows partner `NCC177950763826` / Anh Thanh Thien Phu is dual-role:

- `is_customer = 1`
- `is_supplier = 1`
- `debt_amount = 47,400,000`
- `supplier_debt_amount = -2,700,000`

For dual-role display:

- Customer screen debt = `debt_amount - supplier_debt_amount`
- Supplier screen debt = `supplier_debt_amount - debt_amount`

Therefore, a customer-screen adjustment to `0` must set raw `debt_amount` to `-2,700,000`, not `0`.

## Root Cause

The UI already displays dual-role net debt, but the adjustment modal and backend write-path used raw fields:

- Customer adjustment used raw `customers.debt_amount`.
- Supplier adjustment used raw `customers.supplier_debt_amount`.

This caused a KiotViet-style mismatch: the operator entered the desired displayed final debt, while the backend treated it as a raw column value.

## Policy

Manual debt adjustment `amount` means:

- On customer screen: desired final `customerScreen = debt_amount - supplier_debt_amount`.
- On supplier screen: desired final `supplierScreen = supplier_debt_amount - debt_amount`.

For dual-role partners, backend converts the displayed target back to the raw field for the screen being edited:

- Customer screen writes only `debt_amount`.
- Supplier screen writes only `supplier_debt_amount`.
- The opposite raw field is not changed.

For non-dual-role partners, existing behavior is unchanged.

## Change

- Customer adjustment now uses `PartnerDebtDisplayBalance::customerScreen()` as current debt.
- Supplier adjustment now uses `PartnerDebtDisplayBalance::supplierScreen()` as current debt.
- Customer adjustment cashflow amount uses the raw receivable delta required to reach the displayed target.
- Supplier adjustment ledger `amount` and `debt_remain` use the raw payable delta/target required to reach the displayed target.
- Customer adjustment modal defaults to `customerNetDebt(customer)`.
- Supplier adjustment modal defaults to `supplierNetDebt(supplier)`.

## Files

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `tests/Feature/Customers/DualRoleDebtAdjustmentKiotStyleTest.php`

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB write: no.
- Production command: no.
- Debt rebuild: no.
- No stock/costing/serial/IMEI changes.
- No invoice, purchase, cashflow, or payroll write-path changes outside manual debt adjustment.

## Verification

PASS:

```text
php artisan test tests/Feature/Customers/DualRoleDebtAdjustmentKiotStyleTest.php --compact
```

```text
PASS: 2 passed (26 assertions)
```

PASS:

```text
php artisan test tests/Feature/Customers/DualRoleDebtAdjustmentKiotStyleTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php tests/Feature/Suppliers/SupplierDebtDocumentTimelineTest.php tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --compact
```

```text
PASS: 50 passed (304 assertions)
```

PASS:

```text
php -l app/Http/Controllers/CustomerController.php
php -l app/Http/Controllers/SupplierController.php
php -l tests/Feature/Customers/DualRoleDebtAdjustmentKiotStyleTest.php
git diff --check
npm run build
```

## Manual QA

After approved deploy:

1. Open customer screen for a dual-role partner with `debt_amount = 47,400,000` and `supplier_debt_amount = -2,700,000`.
2. Confirm current customer debt displays `50,100,000`.
3. Adjust customer debt to `0`.
4. Confirm customer screen debt becomes `0`.
5. Confirm supplier screen debt also becomes `0` for the same raw pair.
6. Repeat from supplier screen: adjust supplier-oriented debt to `0`, confirm both oriented screens show `0`.

## Rollback

Revert this hotfix commit. No database rollback is required because the change adds no schema and no automatic data mutation.
