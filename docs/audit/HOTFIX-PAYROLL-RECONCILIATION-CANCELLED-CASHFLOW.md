# Hotfix Payroll Reconciliation - Cancelled CashFlow

## Executive Summary

- Date: 2026-06-20 Asia/Saigon.
- Branch: `hotfix/payroll-reconciliation-cancelled-cashflow`.
- Base HEAD before work: `5cc7cee974aa7d3aec47ddc0d1e07ddbb0196d0b`.
- Production currently known synced source before this hotfix: `b6136a801891a8f122b506f608ed6515547d3f56`.
- Scope: code/test hotfix only for payroll reconciliation document cashflow detection.
- Migration: No.
- Backfill: No.
- Production command: No.
- Production DB touched: No.
- Broad Customer/Supplier debt 50 failures from Local Gate A: not triaged in this hotfix.

Result: PASS for scoped hotfix.

## Source/Commit Verified

Pre-work checklist:

```bash
git fetch origin main
git checkout main
git pull origin main
git rev-parse HEAD
git status --short
git checkout -b hotfix/payroll-reconciliation-cancelled-cashflow
```

Result:

- `main` was up to date with `origin/main`.
- HEAD before hotfix: `5cc7cee974aa7d3aec47ddc0d1e07ddbb0196d0b`.
- Worktree was clean before changes.

## Bug Evidence

The bug was found during Local Payroll Gate A UAT with the latest dump and documented in:

- `docs/audit/LOCAL-PAYROLL-GATE-A-UAT-WITH-LATEST-DUMP.md`

Evidence from that run:

- Payment `TTPL000006` was cancelled.
- CashFlow `PCPL000006` existed for the payment.
- The cashflow had `status = cancelled` and was soft-deleted.
- `PayrollReconciliationService::audit(['employee' => 12])` incorrectly returned document issue `PAYMENT_WITHOUT_CASHFLOW`.

After this hotfix, rerunning the same local dump check for employee `12` returns:

```json
"document_issues": [],
"summary": {
  "issue_count": 0,
  "payment_cashflow_issue_count": 0,
  "advance_issue_count": 0
}
```

## Root Cause

`PayrollReconciliationService` eager-loaded `cashFlow` with the default relation:

```php
PaysheetPayment::query()->with(['cashFlow', 'employee:id,code,name,branch_id'])
SalaryAdvance::query()->with(['cashFlow', 'employee:id,code,name,branch_id'])
```

`CashFlow` uses `SoftDeletes`. Cancelled payroll payment and salary advance cashflows are marked `status = cancelled` and soft-deleted, so the default relation does not return them. Reconciliation then treated the valid cancelled cashflow as missing.

## Scope

In scope:

- Payroll payment reconciliation.
- Payroll advance reconciliation with the same soft-deleted cashflow pattern.
- Regression tests for cancelled soft-deleted cashflow recognition.
- Guard tests confirming active missing/mismatch cases still report issues.

Out of scope:

- Customer/Supplier debt regression failures.
- Financial report logic changes.
- Core payment/cancel/cashflow creation changes.
- Frontend changes.
- Migration/backfill/rebuild/opening balance.

## Fix Summary

Files changed:

- `app/Services/PayrollReconciliationService.php`
- `tests/Feature/Payroll/PayrollPaymentCashFlowTest.php`
- `docs/audit/HOTFIX-PAYROLL-RECONCILIATION-CANCELLED-CASHFLOW.md`

Implementation:

- Reconciliation now eager-loads payroll document cashflows with `withTrashed()` locally inside the audit queries.
- It does not change global `cashFlow()` relations on `PaysheetPayment` or `SalaryAdvance`.
- Active payments/advances still require an active, not-deleted cashflow.
- Cancelled payments/advances accept a cancelled or soft-deleted cashflow.
- Payment amount mismatches still report `CASHFLOW_AMOUNT_MISMATCH`.

## Data Safety

| Question | Answer |
|---|---|
| Migration added? | No |
| Backfill added/run? | No |
| Old data updated? | No |
| Data deleted? | No |
| Production command run? | No |
| Production DB touched? | No |
| Rollback plan | Revert code commit |
| Backup required for this local code/test hotfix | No |

## Tests

Commands were run with inline local testing env vars pointed to Docker MySQL `127.0.0.1:3319/sales_test`.

| Command | Result |
|---|---|
| `php artisan test tests\Feature\Payroll\PayrollPaymentCashFlowTest.php --filter=reconciliation` | PASS, 4 tests / 18 assertions |
| `php artisan test tests\Feature\Payroll` | PASS, 118 tests / 628 assertions |
| `php artisan test --filter=PayrollReconciliation` | PASS command, no tests found for that exact filter |
| `php artisan test --filter=CashFlow` | PASS, 61 tests / 303 assertions |
| Local dump verification for employee `12` | PASS, `document_issues=[]`, `issue_count=0` |
| `npm run build` | PASS |
| `git diff --check` | PASS |

PHP CLI still prints startup warnings for missing optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`. The warnings did not fail tests/build.

## Manual QA

Manual browser QA was not executed in this hotfix step.

Backend verification on the local imported dump covered the exact UAT false critical:

- Employee: `NV-UAT-PAYROLL-LOCAL-20260620110440`
- Cancelled payment: `TTPL000006`
- Cancelled/soft-deleted cashflow: `PCPL000006`
- Reconciliation after fix: no payment cashflow issue.

Recommended staging/local manual check before production rollout:

- Open Payroll Reconciliation for an employee with a cancelled payroll payment.
- Confirm no false `PAYMENT_WITHOUT_CASHFLOW` appears when the cancelled cashflow exists.
- Confirm active payroll payments still show normally.
- Confirm cancelled payroll cashflows are still excluded from active financial expense reports.

## Remaining Risks

- This hotfix does not triage the 50 Customer/Supplier debt regression failures from the prior broad suite.
- `php artisan test --filter=PayrollReconciliation` has no exact matching test name/class in the current suite; the actual reconciliation regression cases were added under `PayrollPaymentCashFlowTest`.
- No production deploy/migration/command was performed here.

## Go/No-Go

| Area | Status |
|---|---|
| Scoped payroll reconciliation hotfix | GO |
| Payroll regression | GO |
| CashFlow regression | GO |
| Build | GO |
| Production deploy | Not approved by this step |
| Broad Customer/Supplier debt gate | Still separate / not handled |

## Final Conclusion

The scoped payroll reconciliation hotfix is ready for review. It fixes the false `PAYMENT_WITHOUT_CASHFLOW` report for cancelled payroll payments with cancelled/soft-deleted cashflows, covers the analogous cancelled salary advance path, and preserves active missing/mismatch detection.

