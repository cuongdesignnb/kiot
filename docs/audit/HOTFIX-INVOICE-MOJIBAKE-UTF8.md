# HOTFIX - Invoice Mojibake UTF-8

## Scope

- Repo: `cuongdesignnb/kiot`
- Branch: `hotfix/invoice-mojibake-debt-export-parity`
- Base used locally: `origin/prod-snapshot/sanitized-production-customer-group-2d5bfc0-20260702`
- Production branch/commit named in the request was not available in the local remote object database, so this is a source hotfix against the sanitized production snapshot only.

## Problem

`resources/js/Pages/Invoices/Index.vue` contained mojibake literals such as `HÃ³a Ä‘Æ¡n`, `Thá»i gian`, `Chi nhÃ¡nh`, and `KhÃ¡ch Ä‘Ã£ tráº£`.

The impact was display-only: invoice list labels, filters, button text, modal text, and warning strings rendered incorrectly.

## Source Checked

- `resources/js/Pages/Invoices/Index.vue`
- `tests/Feature/Invoices/InvoiceIndexUtf8Test.php`

## Root Cause

Vietnamese UI literals in the invoice index source had already been saved as mojibake text. The browser and font stack were not the cause.

## Change

- Re-decoded the invoice index Vue file to valid UTF-8.
- Kept behavior and routing unchanged.
- Added a static regression test to prevent common mojibake patterns from returning.

## Files

- `resources/js/Pages/Invoices/Index.vue`
- `tests/Feature/Invoices/InvoiceIndexUtf8Test.php`

## Data Safety

- Migration: no.
- Backfill: no.
- Seed: no.
- Production DB read/write: no.
- Production command: no.
- Stock/costing/serial logic: unchanged.

## Verification

PASS:

```bash
php artisan test tests/Feature/Invoices/InvoiceIndexUtf8Test.php --compact
```

Result:

```text
Tests: 1 passed (18 assertions)
```

PASS:

```bash
npm run build
```

Result:

```text
vite build completed successfully
```

## Notes

`git diff --check` passed with exit code 0. Git printed a local line-ending warning for `resources/js/Pages/Invoices/Index.vue`; no whitespace error was reported.

## Rollback Plan

Rollback the hotfix commit that changes `resources/js/Pages/Invoices/Index.vue` and removes `tests/Feature/Invoices/InvoiceIndexUtf8Test.php`. No database rollback is required.
