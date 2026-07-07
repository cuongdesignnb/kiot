# HOTFIX - Supplier Timeline Customer Debt Adjustment Label

## Scope

- Branch: `hotfix/supplier-timeline-customer-debt-adjustment-label`
- Base: `origin/hotfix/dual-role-debt-adjustment-kiot-style`
- Scope: backend display/API compatibility for supplier partner timeline only.
- No frontend change.
- No migration.
- No production command.
- No database mutation.

## Production Finding

Dual-role partner timeline can show a customer-side debt adjustment receipt (`CashFlow.type = receipt`, `reference_type = DebtAdjustment`, `category = Điều chỉnh công nợ`) as a normal customer payment:

```text
PT26070714010866 | Điều chỉnh công nợ | effect=47.400.000 | domain=customer
```

The amount/effect is already part of the document timeline, but the display metadata was misleading:

- `display_type`: `Khách thanh toán`
- `event_kind`: `invoice_payment`
- badge: `Thanh toán`

## Root Cause

`SupplierDebtDocumentTimelineService` mirrors customer receipts into supplier partner view for dual-role partners. Before this hotfix, every real customer receipt was labeled as an invoice payment, regardless of whether the receipt represented a customer debt adjustment.

## Fix

Updated mirrored customer receipt display metadata:

- `DebtAdjustment` or `category = Điều chỉnh công nợ` is labeled as:
  - `display_type = Điều chỉnh công nợ`
  - `event_kind = customer_debt_adjustment`
  - `badge_label = Điều chỉnh`
- Real invoice receipts remain:
  - `display_type = Khách thanh toán`
  - `event_kind = invoice_payment`
  - `badge_label = Thanh toán`
- Existing supplier partner alias logic still keeps virtual/customer reference rows separate.
- Mirrored customer receipt lookup accepts both `Khách hàng` and `Khach hang` target type variants.

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB write: no.
- Balance recalculation: no.
- CashFlow/customer/supplier debt mutation: no.
- Balance logic changed: no. Only display metadata and target type compatibility were changed.

## Files Changed

- `app/Services/SupplierDebtDocumentTimelineService.php`
- `tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`
- `docs/audit/HOTFIX-SUPPLIER-TIMELINE-CUSTOMER-DEBT-ADJUSTMENT-LABEL.md`

## Tests

```text
php -l app\Services\SupplierDebtDocumentTimelineService.php
PASS - No syntax errors detected

php -l tests\Feature\Suppliers\SupplierDualRolePartnerTimelineTest.php
PASS - No syntax errors detected

php artisan test tests\Feature\Suppliers\SupplierDualRolePartnerTimelineTest.php --compact
PASS - 6 passed, 74 assertions

php artisan test tests\Feature\Customers\DualRoleDebtAdjustmentKiotStyleTest.php tests\Feature\Suppliers\SupplierDualRolePartnerTimelineTest.php tests\Feature\Suppliers\SupplierDebtDocumentTimelineTest.php tests\Feature\Customers\CustomerDebtDocumentTimelineTest.php tests\Feature\Suppliers\SupplierDualRoleTimelineFinancialDisplayTest.php --compact
PASS - 44 passed, 275 assertions
```

Local PHP emitted startup warnings for missing optional extensions (`oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`). They did not affect lint or PHPUnit execution.

## Build

`npm run build` was not run because this hotfix is PHP-only and does not modify frontend assets.

## Manual QA Checklist

- Open supplier detail for a dual-role partner.
- Switch to partner/debt timeline view.
- Confirm customer debt adjustment receipt `PT26070714010866` is displayed as `Điều chỉnh công nợ`, not `Khách thanh toán`.
- Confirm normal real customer invoice receipts still display as `Khách thanh toán`.
- Confirm document amount/effect/running balance are unchanged.

## Rollback

Revert the hotfix commit. No schema or data rollback is required.

