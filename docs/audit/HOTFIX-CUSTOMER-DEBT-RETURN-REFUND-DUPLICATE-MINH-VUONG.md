# HOTFIX - Customer Debt Return Refund Duplicate Minh Vuong

## Scope

- Repo: `cuongdesignnb/kiot`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Area: customer debt document timeline only.

## Problem

For paid sales returns, `CustomerDebtDocumentTimelineService` could synthesize a virtual refund row `PCTH...` from `OrderReturn.paid_to_customer` even when a real `PC...` cashflow refund already existed.

That created a display double-count in the document timeline:

- real return document reduced debt;
- real refund cashflow existed;
- virtual refund fallback could also be added.

The fallback is useful only when the real refund voucher cannot be found.

## Source Checked

- `app/Http/Controllers/CustomerController.php`
- `app/Services/CustomerDebtDocumentTimelineService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Models/OrderReturn.php`
- `app/Models/CashFlow.php`
- `app/Models/CustomerDebt.php`
- `app/Models/DebtOffset.php`
- `resources/js/Pages/Customers/Index.vue`
- `tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php`
- `tests/Feature/CustomerDebt/SapoDebtParityTest.php`

## Root Cause

The virtual refund fallback was keyed only from `paid_to_customer > 0`. It did not first prove whether a real refund cashflow already represented the same return/refund event.

## Change

- Before creating a virtual `PCTH...` fallback, the service now searches existing customer payment cashflows for a matching real refund.
- Exact match strategies:
  - `reference_type_and_id`
  - `reference_type_and_code`
  - `reference_code`
- Controlled fuzzy match:
  - same customer context because the payments collection is customer-scoped;
  - same amount;
  - code starts with `PC`;
  - cashflow time is within 60 minutes of return time.
- If a real refund is found, the return entry is annotated with diagnostics:
  - `fallback_suppressed_by_real_refund`
  - `real_refund_code`
  - `real_refund_id`
  - `refund_match_strategy`

## Files

- `app/Services/CustomerDebtDocumentTimelineService.php`
- `tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php`

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB read/write: no.
- Production command: no.
- No debt rebuild.
- No legacy cleanup.

## Verification

PASS:

```bash
php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --filter=sales_return_with_real_refund --compact
```

Result:

```text
Tests: 1 passed (8 assertions)
```

PASS:

```bash
php artisan test tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php --filter=minh_vuong_like_return_refund --compact
```

Result:

```text
Tests: 1 passed (5 assertions)
```

PASS in broader regression:

```text
Tests: 59 passed (324 assertions)
```

## Residual Risk

The fuzzy match is intentionally conservative but still inferred. It is used only to suppress the virtual fallback in the document timeline presentation. It does not update data and does not claim to create or repair accounting allocations.

## Rollback Plan

Rollback the customer timeline hotfix commit. This restores the previous fallback behavior. No database rollback is required because the change is read-only presentation logic.
