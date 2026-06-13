# Production Readiness After PR2 Merge

Audit date: 2026-06-13

This report is a readiness review only. No production command, deploy,
migration, audit, payroll recalculation, backfill, cleanup, or data mutation
was performed.

## 1. Source State

- Latest `origin/main`: `917be063f09f7273307f0b44be33f9964b565463`
- PR #1 debt merge: `8d56564c0e9046f226f2558e93020a45d0392a27`
- PR #2 payroll integration merge: `917be063f09f7273307f0b44be33f9964b565463`
- Production current branch, last known:
  `prod-hotfix-attendance-payroll`
- Production current HEAD, last known:
  `ce15714903aa5eb25646672acd74e67bda933420`
- Production branch/HEAD reverified in this step: No
- Reason: BA has not provided the exact approval phrase
  `OK chay production preflight read-only`.
- Backup branch:
  `origin/backup/prod-before-debt-main-sync-20260613-1657`
- Backup tag: `prod-before-debt-main-sync-20260613-1657`
- Backup branch and tag verified locally at:
  `ce15714903aa5eb25646672acd74e67bda933420`

The local `main` worktree was clean and matched `origin/main` before this
report was added.

## 2. Main Verification

- PR #1 debt merge is an ancestor of `origin/main`: Yes
- PR #2 payroll merge is an ancestor of `origin/main`: Yes
- Payroll files present: Yes
- Debt services and models present: Yes
- Debt migrations present: Yes
- Payroll integration report present: Yes
- Post-merge staging debt UAT report present: Yes
- `tests/Feature/Attendance` exists: No

Verified payroll files:

- `app/Services/SalaryCalculationService.php`
- `app/Services/TimekeepingService.php`
- `app/Http/Controllers/TimekeepingRecordController.php`
- `resources/js/Pages/Employees/Attendance.vue`
- `tests/Feature/Payroll/ManualTimekeepingTest.php`
- `docs/audit/INTEGRATE-PROD-PAYROLL-HOTFIX-WITH-DEBT-MAIN.md`

Verified debt files:

- `app/Services/CustomerPaymentService.php`
- `app/Services/OrderPaymentSummaryService.php`
- `app/Services/PartnerMergeService.php`
- `app/Services/PartnerTransactionGuard.php`
- `app/Models/CustomerPaymentAllocation.php`
- `app/Models/PartnerMerge.php`

Verified debt migrations:

- `database/migrations/2026_06_12_120000_create_customer_payment_allocations_table.php`
- `database/migrations/2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
- `database/migrations/2026_06_12_120200_add_partner_merge_provenance.php`

## 3. Local/Staging Tests

Tests were run after PR #2 was squash-merged, on a dedicated local MySQL
testing database named `sales_test_payroll_debt_integration`. No production
database was used and `migrate:fresh` was not run.

| Test group | Result |
|---|---|
| ManualTimekeepingTest | PASS: 21 tests, 95 assertions |
| Payroll | PASS: 51 tests, 193 assertions |
| Attendance | Not run: folder does not exist |
| SapoDebtParityTest | PASS: 12 tests, 41 assertions |
| Orders | PASS: 23 tests, 117 assertions |
| POS | PASS: 65 tests, 273 assertions |
| CashFlow(s) | PASS: 14 tests, 60 assertions |
| Reports | PASS: 109 tests, 628 assertions |
| `npm run build` | PASS |
| Modules transformed | 915 |
| `git diff --check` | PASS |

The first Orders invocation lacked an application key in the test process.
It was rerun with a temporary process-only test `APP_KEY` and all tests
passed. No `.env` file was modified.

The PHP runtime emitted existing warnings for unavailable Oracle and
Firebird extensions. MySQL testing was unaffected.

## 4. Manual QA

- Payroll/Attendance: Not rerun after the squash merge.
- Previous local browser QA used the same reviewed source tree and passed:
  the attendance page rendered, 480 minutes produced `work_units = 1.00`,
  downgrade to 240 minutes returned HTTP 422 with confirmation required,
  and no unexpected browser console error was observed.
- Payroll evidence outside the repository:
  `D:\Kiot\integration-payroll-uat-evidence`
- Full gross-profit paysheet UI flow: Not manually completed. Invoice and
  return behavior is covered by payroll regression tests.
- Debt six-case browser smoke: Not rerun because PR #2 did not change debt
  files.
- Debt evidence:
  `docs/audit/POST-MERGE-STAGING-DEBT-LOGIC-UAT.md`

## 5. Production Preflight

- BA approval for read-only preflight received: No
- Production read-only commands run: No
- Production current branch: Last known
  `prod-hotfix-attendance-payroll`; not reverified
- Production current HEAD: Last known
  `ce15714903aa5eb25646672acd74e67bda933420`; not reverified
- Production `origin/main`: Not fetched or inspected in this step
- Production working tree clean: Not reverified

The next allowed production action requires the explicit approval phrase:

```text
OK chay production preflight read-only
```

After that approval, the preflight must remain read-only: inspect the path,
working tree, branch, HEAD, recent history, and fetched `origin/main`.
It must not pull, checkout, deploy, migrate, build, clear caches, restart
services, or run application audit commands.

## 6. Proposed Deployment Gates

This is a plan only. None of these steps were executed.

1. Read-only production preflight after explicit BA approval.
2. Confirm production working tree is clean and backup refs remain
   available.
3. Prepare and verify a production database backup and restore procedure.
4. Obtain separate BA approval for code deployment.
5. Sync production source to the approved `origin/main` SHA without running
   migrations.
6. Inspect `php artisan migrate:status` and `php artisan migrate --pretend`
   only after separate approval for migration preflight.
7. Review the read-only legacy order audit separately before deciding on
   migrations. Do not remediate legacy data in this deployment.
8. Obtain explicit BA approval before `php artisan migrate --force`.
9. Run build, cache, restart, and production UAT only under the approved
   deployment runbook.

Source rollback should use the verified backup branch/tag. Database rollback
must not rely only on migration `down()` methods; a tested database restore
or forward-fix plan is required before migration approval.

## 7. Data Safety

- Production deploy run: No
- Production migration run: No
- Production audit run: No
- Production DB touched: No
- Recalculate old payroll: No
- Backfill: No
- Update/delete old data: No
- Cleanup legacy data: No
- Inventory, stock movement, costing, serial/IMEI, warranty, repair changed:
  No

## 8. Risks

1. Payroll and attendance behavior is high-risk and needs a controlled
   production deployment and UAT plan.
2. The three debt migrations require a separate approval, database backup,
   restore plan, and reviewed pretend output.
3. The legacy order audit may identify cumulative `orders.amount_paid`
   records requiring BA review; remediation is outside this scope.
4. Production branch, HEAD, and working-tree cleanliness remain last-known
   values until read-only preflight is approved.
5. Full gross-profit paysheet UI QA remains incomplete.

## 9. Recommendation

- Ready for production preflight: Yes, after explicit BA approval
- Ready for production code deploy: No
- Ready for production migration: No
- Ready for full production deploy: No
- BA approval needed before next step: Yes

Recommended next approval is read-only preflight only. Code deployment,
migration preflight, migration execution, and production UAT must remain
separate approval gates.
