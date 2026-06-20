# Cần xác nhận trước khi triển khai — Production Payroll Write UAT & Backfill Approval Plan

Cần xác nhận trước khi triển khai

## Executive Summary

This document is an approval plan only. It does not authorize or execute production write UAT, permission apply, backfill, rebuild, opening balance, SQL mutation, cleanup, migration, build, cache, restart, or deploy.

Production technical deploy was reported complete at commit:

```text
b6136a801891a8f122b506f608ed6515547d3f56
```

Current repository `origin/main` has a newer docs-only commit after production deploy:

```text
a33eed0591379a176d7e089a8c095fcb49fed555
```

There is no code commit newer than the production source in this review. Production does not need a functional source sync for this approval plan. Syncing production only for documentation is not required and must not be done automatically.

Gate status:

| Gate | Scope | Recommendation |
|---|---|---|
| Gate A | Payroll new-data write UAT on production | CONDITIONAL GO only after Owner approval |
| Gate B | Production payroll permission apply | CONDITIONAL GO only after Owner approval and permission export |
| Gate C | Legacy payroll ledger backfill | NO-GO by default |
| Gate D | Payroll CashFlow metadata backfill | NO-GO by default |
| Gate E | Opening balance / salary balance rebuild | NO-GO by default |

## Current Production Technical Status

Based on the deployment confirmation provided in the step:

| Item | Status |
|---|---|
| Production path | `/www/wwwroot/kiot.cuongdesign.net` |
| Production source | `b6136a801891a8f122b506f608ed6515547d3f56` |
| Debt/payroll migrations | `Ran` |
| `npm run build` | PASS |
| Laravel cache clear/rebuild | PASS |
| Queue restart | PASS |
| PHP-FPM 8.3 restart | PASS |
| Nginx/vhost | PASS |
| Public login | PASS |
| Log after login | No new error reported |

This report did not re-run those production checks.

## Source/Commit Verification

Commands run locally in the `main` worktree:

```text
git fetch origin main
git pull origin main
git rev-parse HEAD
git rev-parse origin/main
git status --short
```

Result:

| Item | Value |
|---|---|
| Local branch | `main` |
| HEAD before report | `a33eed0591379a176d7e089a8c095fcb49fed555` |
| `origin/main` before report | `a33eed0591379a176d7e089a8c095fcb49fed555` |
| Production running commit | `b6136a801891a8f122b506f608ed6515547d3f56` |
| Docs-only commit after production deploy | Yes, `a33eed0591379a176d7e089a8c095fcb49fed555` |
| Code newer than production | No |
| Need production sync | No functional sync required for this report. Do not sync automatically. |
| Working tree before report | Clean tracked files |

## Scope

This approval plan covers only controlled production rollout planning for:

- Gate A: payroll new-data write UAT.
- Gate B: production payroll permission apply.
- Gate C: legacy payroll ledger backfill.
- Gate D: payroll CashFlow metadata backfill.
- Gate E: opening balance and salary balance rebuild.

Out of scope:

- Code changes.
- Migration changes.
- Config changes.
- Production command execution.
- Production SQL mutation.
- Data cleanup.
- Inventory, costing, serial/IMEI, stock movement, customer/supplier debt logic, invoice/return, purchase, repair, or payroll logic changes.

## Data Impact Summary

These gates can affect production financial/accounting data. Code rollback alone is not enough once write/backfill/rebuild operations are executed.

Primary risks:

- Financial/accounting mismatch.
- Wrong employee salary debt balance.
- Wrong CashFlow / cash book state.
- Double-counted payroll expense in financial reports.
- Incorrect legacy opening balance.
- Permission leak exposing salary data.
- Irreversible or expensive rollback if backup/restore is not rehearsed.

Safer order:

1. Staging first.
2. Restored production-copy dry-run.
3. Production read-only verification.
4. Production write UAT with test data only.
5. Backfill/rebuild/opening only after Owner/BA/accounting approval.

## Affected Tables/Columns

| Gate | Table | Column/Data type | New insert | Update old data | Delete | Risk |
|---|---|---|---|---|---|---|
| A | `employees` | Test employee or salary balance cache | Possible | Possible cache update | No | Test data may touch real employee if not isolated |
| A | `paysheets` | Test paysheet status/payment status | Yes | Test row updates | No | Wrong period/status if user selects real period |
| A | `payslips` | Salary, remaining, payment status, applied advance | Yes | Test row updates | No | Wrong employee debt if real employee used |
| A | `paysheet_payments` | Payment rows/cancel metadata | Yes | Test row status update | No | Wrong payment/cancel state |
| A | `salary_advances` | Advance amount/status/cashflow link | Yes | Test row status update | No | Wrong advance balance |
| A | `salary_advance_applications` | Advance application rows | Yes | Status reversal on cancel | No | Wrong applied advance |
| A/E | `employee_salary_ledger_entries` | Ledger entries/reversals/balance_after | Yes | Rebuild may update balance_after | No | Double entry or wrong running balance |
| A/D | `cash_flows` | Payroll payment/advance cashflow, status, branch/idempotency metadata | Yes | Metadata/status update possible | No | Cash book/report mismatch |
| B | role/permission tables | Role permission assignments | Possible | Yes | Possible rollback remove | Salary data exposure |
| C/E | `employees` | `salary_balance_cache`, `salary_balance_calculated_at`, legacy `balance` as source only | Possible | Yes | No | Wrong current salary debt |
| C | `paysheets`, `payslips`, `paysheet_payments` | Historical payroll/payment docs | No/possible | Possible via backfill metadata | No | Legacy data misread |
| C/D | `cash_flows` | Payroll cashflow links/metadata | No/possible | Yes | No | Double-count or wrong cashflow classification |
| Debt regression | `customers`, `customer_debts`, `supplier_debt_transactions`, `invoices`, `orders` | Read-only regression only in this plan | No | No | No | Must not mutate during payroll gates |

## Gate A — Payroll New-data Write UAT

Objective: verify the production payroll write flow using controlled test data only.

Default status: CONDITIONAL GO after Owner approval. Prefer staging first.

If production is used:

```text
Cần xác nhận trước khi triển khai
```

Gate details:

| Requirement | Plan |
|---|---|
| Affected data | `employees`, `paysheets`, `payslips`, `paysheet_payments`, `salary_advances`, `salary_advance_applications`, `employee_salary_ledger_entries`, `cash_flows` |
| Financial risk | Wrong employee salary debt, wrong cashflow, wrong payroll expense |
| Preconditions | Owner approval, named test account, named test employee, named test payroll period, rollback owner, stop condition |
| Backup mandatory | Recommended for any production write UAT; mandatory if real employee/period could be affected |
| Dry-run mandatory | Not available for UI write flow; staging rehearsal is mandatory equivalent |
| Expected runner | To be assigned |
| Expected approver | Owner/BA/accounting |
| Time window | To be assigned |
| Rollback | Use supported business reversal/cancel flows only; no manual delete |
| Stop condition | Any unexpected ledger/cashflow/report delta, 500 error, permission bypass, or inability to reverse |
| Logs/checks | Laravel log, Nginx log, payroll ledger, cashflow rows, reconciliation, financial report |

Proposed UAT flow:

1. Create or select a clearly named test employee.
2. Create a clearly named test payroll period.
3. Create a test paysheet.
4. Recalculate the paysheet.
5. Lock the paysheet.
6. Create a test salary advance.
7. Apply advance into salary through paysheet flow.
8. Pay part of the salary.
9. Pay the remaining salary.
10. Attempt overpayment and expect validation rejection.
11. Cancel one payment.
12. Verify reversal ledger.
13. Verify payment/advance CashFlow status.
14. Verify reconciliation.
15. Verify financial report has no payroll double-count.
16. Verify logs have no new errors.

Expected PASS/FAIL:

| Check | PASS | FAIL |
|---|---|---|
| Ledger accrual | Lock creates `payroll_accrual`; create paysheet alone does not post ledger | Ledger appears before lock or duplicates |
| Salary payment | Payment creates `salary_payment` ledger and payroll CashFlow | Missing ledger/CashFlow or wrong amount |
| Payment cancel | Cancel creates reversal and cancels related CashFlow | Balance changes twice or CashFlow remains active |
| Salary advance | Advance creates negative ledger and CashFlow | Missing link or wrong sign |
| Advance cancel | Unallocated advance cancellation reverses ledger and cancels CashFlow | Allocated advance cancels incorrectly |
| Overpayment | Server rejects amount above remaining | Overpayment accepted |
| Reconciliation | No mismatch for test employee/docs | Any unexplained mismatch |
| Financial report | Payroll expense is not double-counted from both paysheet and CashFlow | Payroll expense doubles |

Backend behavior from source:

- Paysheet creation/recalculate prepares paysheet/payslip data; ledger posts when paysheet is locked.
- Lock creates payroll accrual ledger entries and applies active advances FIFO.
- Salary payment creates `paysheet_payments`, appends `salary_payment` ledger, and creates payroll CashFlow.
- Payment cancellation locks rows, reverses salary payment ledger, cancels related CashFlow, and updates payment status.
- Salary advance creates payroll CashFlow and negative salary ledger entry.
- Unallocated salary advance cancellation reverses ledger and cancels CashFlow.
- Idempotency keys exist for payment, advance, ledger, and CashFlow creation paths.
- Rollback for test flow should be via cancel/reversal, not hard delete.

## Gate B — Production Permission Apply

Objective: assign payroll permissions safely to production roles.

Default status: CONDITIONAL GO after Owner approval.

No permission is applied by this report.

| Requirement | Plan |
|---|---|
| Affected data | role/permission assignment tables, users inheriting roles |
| Financial/privacy risk | Unauthorized salary visibility or write access |
| Preconditions | Owner-approved role matrix, export current permissions, test role first |
| Backup mandatory | Export/backup role-permission mapping before and after |
| Dry-run mandatory | Use test role/staging first |
| Expected runner | To be assigned |
| Expected approver | Owner |
| Time window | To be assigned |
| Rollback | Remove added permissions and restore exported mapping |
| Stop condition | Any unauthorized salary view or write endpoint access |
| Logs/checks | Role export, user 403 checks, authorized route checks, activity logs if available |

Permission proposal:

| Role | Permission | Grant now? | Need Owner approval? | Reason | Rollback |
|---|---|---|---|---|---|
| Kế toán trưởng | `employee.view_salary_balance` | No | Yes | Salary debt visibility | Remove permission |
| Kế toán trưởng | `payroll.view`, `payroll.ledger.view`, `payroll.ledger.export` | No | Yes | Payroll read/export | Remove permission |
| Kế toán trưởng | `payroll.pay`, `payroll.advance.create` | No | Yes | Financial write actions | Remove permission |
| Kế toán trưởng | `payroll.reconciliation.view`, `payroll.reconciliation.export` | No | Yes | Reconciliation/export | Remove permission |
| Kế toán trưởng | `payroll.adjust`, `payroll.rebuild_balance`, `payroll.override_locked_period`, `payroll.override_backdate_limit`, `payroll.pay.cancel`, `payroll.advance.cancel` | No | Separate explicit approval | Very sensitive | Remove permission |
| Kế toán | `employee.view_salary_balance`, `payroll.view`, `payroll.ledger.view`, `payroll.ledger.export`, `payroll.pay`, `payroll.advance.create`, `payroll.reconciliation.view` | No | Yes | Normal accounting workflow | Remove permission |
| Kế toán | cancel/adjust/rebuild/override | No | Separate explicit approval | High-risk reversal/admin rights | Remove permission |
| HR/quản lý nhân sự | `payroll.view`, `payroll.create`, `payroll.edit`, `payroll.lock` | No | Yes | Payroll preparation | Remove permission |
| HR/quản lý nhân sự | `employee.view_salary_balance` | No | Optional approval | Salary debt privacy | Remove permission |
| HR/quản lý nhân sự | payment/cancel/advance cancel/adjust/rebuild/override | No | Separate explicit approval | Segregation of duties | Remove permission |
| `cashier`, `warehouse_staff`, `task_manager` | Any payroll salary/debt permission | No | No by default | No salary visibility | Remove permission if found |

## Gate C — Legacy Payroll Ledger Backfill

Objective: create or reconcile ledger entries for legacy payroll documents.

Default status: NO-GO.

```text
Cần xác nhận trước khi triển khai
```

| Requirement | Plan |
|---|---|
| Affected data | `employee_salary_ledger_entries`, `employees.salary_balance_cache`, `paysheets`, `payslips`, `paysheet_payments`, possibly `cash_flows` |
| Financial risk | Wrong opening/current employee debt, duplicate accrual/payment ledger, wrong reports |
| Preconditions | Fresh backup, restored DB rehearsal, dry-run output, go-live date if opening balance, BA/accounting source-of-truth approval |
| Backup mandatory | Yes |
| Restore rehearsal mandatory | Yes |
| Dry-run mandatory | Yes |
| Expected runner | To be assigned |
| Expected approver | Owner + BA/accounting |
| Time window | To be assigned |
| Rollback | DB restore or forward-fix with signed reconciliation; code rollback is not enough |
| Stop condition | Unexpected affected employee count, large mismatch, duplicate idempotency, or report delta |
| Logs/checks | Command output, before/after totals, reconciliation export, ledger count, employee balance diff |

Source commands:

| Command | Mode | Target DB | Writes data | Run condition | Output to save |
|---|---|---|---|---|---|
| `php artisan payroll:migrate-salary-ledger --legacy-balance=report` | Dry-run/report | Restored copy first | No | Before any apply | Employee count, legacy balances, mismatch list |
| `php artisan payroll:migrate-salary-ledger --backfill-documents --legacy-balance=report` | Dry-run/report unless `--apply` | Restored copy first | No without `--apply` | Backfill rehearsal | Payslip/payment candidates, expected ledger entries |
| `php artisan payroll:migrate-salary-ledger ... --apply` | Apply | Production only after approval | Yes | Backup + exact approval only | Created/skipped rows, idempotency, totals |
| `php artisan payroll:backfill-paid-payslip-ledger --dry-run` | Dry-run | Restored copy first | No | Candidate review | Candidate count, affected payslips, total amount |
| `php artisan payroll:backfill-paid-payslip-ledger --apply` | Apply | Production only after approval | Yes | Backup + exact approval only | Created/skipped ledger entries, errors |

Required dry-run output:

- Number of affected employees.
- Number of affected payslips.
- Number of ledger entries expected.
- Total debit/credit.
- Skipped rows.
- Error rows.
- Large mismatch list.
- Confirmation that production was not written.

## Gate D — Payroll CashFlow Metadata Backfill

Objective: identify legacy payroll payment CashFlows and attach/classify metadata safely.

Default status: NO-GO.

```text
Cần xác nhận trước khi triển khai
```

| Requirement | Plan |
|---|---|
| Affected data | `cash_flows`, `paysheet_payments.cash_flow_id`, payroll category/reference/idempotency metadata |
| Financial risk | Wrong cashflow classification, cash book mismatch, payroll expense double-count |
| Preconditions | Fresh backup, dry-run, affected row estimate, report comparison before/after |
| Backup mandatory | Yes |
| Restore rehearsal mandatory | Recommended; mandatory for broad apply |
| Dry-run mandatory | Yes |
| Expected runner | To be assigned |
| Expected approver | Owner + accounting |
| Time window | To be assigned |
| Rollback | DB restore or forward-fix metadata correction |
| Stop condition | Ambiguous match, high unmatched count, report delta not approved |
| Logs/checks | Dry-run output, CashFlow counts, PnL payroll expense check, reconciliation |

Source command:

| Command | Mode | Writes data | Notes |
|---|---|---|---|
| `php artisan payroll:backfill-payment-cashflow --dry-run` | Dry-run | No | Required first; save candidate and mismatch output |
| `php artisan payroll:backfill-payment-cashflow --paysheet=CODE --dry-run` | Scoped dry-run | No | Safer for one paysheet |
| `php artisan payroll:backfill-payment-cashflow --apply` | Apply | Yes | Blocked until backup and Owner approval |
| `php artisan payroll:backfill-payment-cashflow --paysheet=CODE --apply` | Scoped apply | Yes | Safer than broad apply, still blocked until approval |

Required analysis before apply:

- Can legacy payroll CashFlows be identified by payment id, paysheet id, reference code, category, amount, employee, date?
- Are any matches ambiguous?
- Are there duplicate amount/date matches?
- Does report payroll expense remain sourced from locked paysheets rather than active payroll payment CashFlows?
- Does `cash_flows.status` being nullable remain compatible with active/cancelled filtering?

## Gate E — Opening Balance / Salary Balance Rebuild

Objective: establish approved beginning salary debt balances or rebuild cached balances from ledger.

Default status: NO-GO.

```text
Cần xác nhận trước khi triển khai
```

Opening balance requirements:

- Cutoff/go-live date.
- Source-of-truth balance signed by accounting.
- Employee list.
- Balance per employee.
- Total employee debt/advance balance.
- Approval owner.
- Decision whether to create ledger opening entries.
- Report impact confirmation for periods before cutoff.

Rebuild requirements:

- Use `php artisan payroll:rebuild-salary-balances --dry-run` first.
- Treat `php artisan payroll:rebuild-salary-balances` without `--dry-run` as a write command.
- Compare before/after expected cache and ledger balance.
- Run write only with explicit Owner approval.

| Requirement | Plan |
|---|---|
| Affected data | `employee_salary_ledger_entries.balance_after`, `employees.salary_balance_cache`, `employees.salary_balance_calculated_at` |
| Financial risk | Wrong current salary debt for all employees |
| Preconditions | Backup, dry-run, BA/accounting sign-off, exact employee scope if possible |
| Backup mandatory | Yes |
| Restore rehearsal mandatory | Yes for broad rebuild/opening |
| Dry-run mandatory | Yes |
| Expected runner | To be assigned |
| Expected approver | Owner + BA/accounting |
| Time window | To be assigned |
| Rollback | DB restore or forward-fix with accounting-approved deltas |
| Stop condition | Unexpected delta, negative/positive sign mismatch, missing source of truth |
| Logs/checks | Dry-run diff, employee balance report, reconciliation, accounting sign-off |

Opening/rebuild approval template:

| Employee | Balance before | Expected balance | Delta | Source of truth | Approved? |
|---|---:|---:|---:|---|---|
| TEMPLATE ONLY |  |  |  |  | Chờ duyệt |

Do not populate this table from production queries without explicit production data access approval.

## Backend Route / Command Risk Matrix

| Route/Command | Controller/Service | Permission | Writes data? | Risk | Gate |
|---|---|---|---|---|---|
| `POST /api/paysheets` | `PaysheetController@store` | `payroll.create` | Yes | Creates payroll docs | A/B |
| `POST /api/paysheets/{id}/recalculate` | `PaysheetController@recalculate` | `payroll.edit` | Yes | Recomputes payslips | A/B |
| `PUT /api/paysheets/{id}/lock` | `PaysheetController@lock`, `PayrollPostingService` | `payroll.lock` | Yes | Posts accrual ledger/applies advances | A/B |
| `PUT|POST /api/paysheets/{id}/cancel` | `PaysheetController@cancel`, `PayrollPostingService` | `payroll.cancel` | Yes | Reverses accrual/applications | A/B |
| `POST /api/paysheets/{id}/pay` | `PaysheetController@pay`, `SalaryPaymentService` | `payroll.pay` | Yes | Creates payment, ledger, CashFlow | A/B |
| `POST /api/paysheet-payments/{payment}/cancel` | `PaysheetController@cancelPayment`, `SalaryPaymentService` | `payroll.pay.cancel` | Yes | Reversal/cashflow cancel | A/B |
| `GET /api/employees/{employee}/salary-ledger` | `EmployeeSalaryLedgerController@index` | `payroll.ledger.view` | No | Salary data exposure | B |
| `POST /api/employees/{employee}/salary-ledger/adjust` | `EmployeeSalaryLedgerController@adjust` | `payroll.adjust` | Yes | Manual salary debt adjustment | B/E |
| `GET /api/employees/{employee}/salary-ledger/export` | `EmployeeSalaryLedgerController@export` | `payroll.ledger.export` | No | Sensitive export | B |
| `POST /api/employees/{employee}/salary-advances` | `SalaryAdvanceController@store` | `payroll.advance.create` | Yes | Advance ledger/CashFlow | A/B |
| `POST /api/salary-advances/{advance}/cancel` | `SalaryAdvanceController@cancel` | `payroll.advance.cancel` | Yes | Advance reversal/CashFlow cancel | A/B |
| `GET /api/payroll/reconciliation` | `PayrollReconciliationController@index` | `payroll.reconciliation.view` | No | Reveals mismatches | B/C/D/E |
| `GET /api/payroll/reconciliation/export` | `PayrollReconciliationController@export` | `payroll.reconciliation.export` | No | Sensitive export | B |
| `POST /api/payroll/rebuild-salary-balances` | `EmployeeSalaryLedgerController@rebuildAll` | `payroll.rebuild_balance` | Yes | Broad balance cache rebuild | E |
| `payroll:migrate-salary-ledger` | `MigrateSalaryLedger` | Ops approval | Yes only with `--apply` | Legacy/opening migration | C/E |
| `payroll:backfill-paid-payslip-ledger` | `BackfillPaidPayslipLedger` | Ops approval | Yes only with `--apply` | Ledger backfill | C |
| `payroll:backfill-payment-cashflow` | `BackfillPaymentCashFlow` | Ops approval | Yes only with `--apply` | CashFlow metadata backfill | D |
| `payroll:rebuild-salary-balances` | `RebuildSalaryBalances` | Ops approval | Yes unless `--dry-run` | Broad balance rebuild | E |
| `salary:recalculate` | `RecalculatePaysheet` | Ops approval | Yes | Legacy recalculation | Blocked |

No missing payroll authorization was identified in this report from the searched route mappings. UI hiding must still be treated as secondary; backend middleware is the control.

## Frontend Write Action Matrix

| Screen | URL/Route | Button/Action | Required permission | Writes data? | Gate test |
|---|---|---|---|---|---|
| Employees list/detail | `/employees` | Create/edit/delete employee | employee permissions outside payroll matrix | Yes | Out of this payroll gate unless test employee approved |
| Employee salary ledger tab | `/employees` modal/API | View ledger | `payroll.ledger.view` | No | B |
| Employee salary ledger tab | `/employees` modal/API | `+ Điều chỉnh` | `payroll.adjust` | Yes | B/E, blocked by default |
| Employee salary ledger tab | `/employees` modal/API | Export ledger | `payroll.ledger.export` | No | B |
| Employee salary payment modal | `/employees` | Pay employee open payslips | `payroll.pay` | Yes | A/B |
| Employee advance modal | `/employees` | Create salary advance | `payroll.advance.create` | Yes | A/B |
| Employee advance list | `/employees` | Cancel advance | `payroll.advance.cancel` | Yes | A/B |
| Paysheets list | `/employees/paysheets` | Create paysheet | `payroll.create` | Yes | A/B |
| Paysheets list | `/employees/paysheets` | Recalculate | `payroll.edit` | Yes | A/B |
| Paysheets list/detail | `/employees/paysheets`, `/employees/paysheets/{id}/edit` | Lock paysheet | `payroll.lock` | Yes | A/B |
| Paysheets list/detail | `/employees/paysheets`, `/employees/paysheets/{id}/edit` | Cancel paysheet | `payroll.cancel` | Yes | A/B |
| Paysheets payments | `/employees/paysheets` | Pay selected | `payroll.pay` | Yes | A/B |
| Paysheets payments | `/employees/paysheets` | Cancel payment | `payroll.pay.cancel` | Yes | A/B |
| Payroll reconciliation | `/employees/payroll/reconciliation` | View | `payroll.reconciliation.view` | No | B/C/D/E |
| Payroll reconciliation | `/employees/payroll/reconciliation` | Export | `payroll.reconciliation.export` | No | B |
| CashFlow | `/cash-flows` | Create/edit/delete cashflow | cashflow permissions | Yes | Block payroll UAT unless explicitly included |
| Financial report | `/reports/financial-report` | Filter/read | report permission | No | A/C/D/E verification |

UI UAT requirements:

- Money displays in VND.
- Date/time displays `dd/MM/yyyy HH:mm`, not AM/PM.
- Salary data is hidden from users without permission.
- Dangerous buttons are hidden or disabled without permission.
- Backend rejects direct API calls without permission.
- Write actions produce audit/reversal evidence.

## Blocked Commands

| Command | Status |
|---|---|
| `php artisan migrate:fresh` | Never allowed |
| `php artisan payroll:migrate-salary-ledger --apply` | Blocked until Gate C/E approval |
| `php artisan payroll:backfill-paid-payslip-ledger --apply` | Blocked until Gate C approval |
| `php artisan payroll:backfill-payment-cashflow --apply` | Blocked until Gate D approval |
| `php artisan payroll:rebuild-salary-balances` without `--dry-run` | Blocked until Gate E approval |
| `php artisan salary:recalculate` | Blocked |
| Any command with `--apply` | Blocked without exact approval |
| Manual production SQL insert/update/delete | Blocked |
| Production permission apply | Blocked until Gate B approval |
| Opening balance creation | Blocked until Gate E approval |
| `employees.balance` conversion | Blocked |

## Dry-run Commands

Dry-run commands are not automatically approved for production. They are candidates after Owner approval, preferably on a restored production copy first.

| Command | Supports dry-run? | Writes by default? | Notes |
|---|---|---|---|
| `payroll:migrate-salary-ledger --legacy-balance=report` | Yes, default report/dry-run unless `--apply` | No | Can inspect legacy balance/backfill candidates |
| `payroll:backfill-paid-payslip-ledger --dry-run` | Yes | No if dry-run/no mode | Apply writes ledger |
| `payroll:backfill-payment-cashflow --dry-run` | Yes | No if dry-run/no mode | Apply writes CashFlow metadata |
| `payroll:rebuild-salary-balances --dry-run` | Yes | Yes if `--dry-run` omitted | High-risk command |
| `salary:recalculate` | No safe production dry-run in this plan | Yes | Keep blocked |

## Owner Approval Requirements

Each approval must include:

- Gate letter.
- Scope.
- Production or staging target.
- Backup requirement.
- Restore rehearsal requirement.
- Exact commands/actions.
- Runner.
- Approver.
- Time window.
- Stop condition.
- Rollback/forward-fix owner.
- Expected PASS/FAIL.

Missing approvals now:

- Gate A new-data production UAT.
- Gate B production permission apply.
- Gate C legacy payroll ledger backfill.
- Gate D payroll CashFlow metadata backfill.
- Gate E opening balance / rebuild salary balances.

## Backup Requirements

| Gate | Backup required? | Backup scope |
|---|---|---|
| A | Recommended; mandatory if real production employee/period is used | Full DB or at least payroll/cashflow affected tables |
| B | Required as role/permission export | Role, permission, user-role/user-permission tables |
| C | Mandatory | Full DB |
| D | Mandatory | Full DB, with focus on `cash_flows` and payroll payment tables |
| E | Mandatory | Full DB |

## Restore Rehearsal Requirements

| Gate | Restore rehearsal |
|---|---|
| A | Recommended on staging with same flow |
| B | Use staging/test role first |
| C | Mandatory on restored production copy |
| D | Mandatory for broad apply; strongly recommended even for scoped apply |
| E | Mandatory for broad rebuild/opening |

## Rollback / Forward-fix Plan

Code rollback is not enough after any production write/backfill/rebuild.

Rollback strategy:

- Gate A: use business cancel/reversal flows. Do not hard-delete test records.
- Gate B: restore exported permission mapping or remove added permissions.
- Gate C: DB restore or accounting-approved forward-fix ledger entries.
- Gate D: DB restore or scoped metadata correction with report verification.
- Gate E: DB restore or forward-fix from approved source-of-truth balances.

Emergency stop:

- Stop if a command/action affects unexpected row counts.
- Stop if ledger and employee balance diverge unexpectedly.
- Stop if CashFlow amount/status/category is wrong.
- Stop if financial report double-counts payroll expense.
- Stop if unauthorized user can access salary data or write endpoints.
- Stop if reversal/cancel path fails.

## Test Plan

Recommended local/staging tests, not production write:

```bash
php artisan test tests/Feature/Payroll
php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php
php artisan test --filter=CashFlow
php artisan test --filter=Order
php artisan test --filter=Report
npm run build
git diff --check
```

Tests run in this report step:

| Command | Result |
|---|---|
| `git fetch origin main` | PASS |
| `git pull origin main` | PASS, already up to date |
| `git status --short` | PASS, no tracked changes before report |
| Application tests | Not run |
| `npm run build` | Not run |
| Production command | Not run |

## Manual QA Checklist

Before every gate:

- [ ] Owner approval exists in writing/chat
- [ ] Backup DB/export completed as required
- [ ] Production source/HEAD recorded
- [ ] Runner assigned
- [ ] Time window assigned
- [ ] Rollback owner assigned
- [ ] Stop condition documented
- [ ] Log capture command/process documented

Gate A:

- [ ] Test account identified
- [ ] Test employee identified
- [ ] Test payroll period identified
- [ ] Test data avoids real employees if possible
- [ ] Payment/cancel/reversal expected values documented
- [ ] No manual delete cleanup
- [ ] Reconciliation checked
- [ ] Financial report checked

Gate B:

- [ ] Permissions exported before apply
- [ ] Test role applied first
- [ ] User without permission gets 403
- [ ] User with permission can access correct screens
- [ ] Sensitive permissions remain blocked unless separately approved
- [ ] Permission rollback tested

Gate C/D/E:

- [ ] Restored rehearsal DB ready
- [ ] Dry-run output saved
- [ ] Deltas approved by BA/accounting
- [ ] Exact apply/write command approved
- [ ] Fresh backup taken immediately before apply
- [ ] Post-apply verification done
- [ ] Rollback/forward-fix owner ready

## Go/No-Go Matrix

| Gate | Status | Reason |
|---|---|---|
| Gate A — Payroll new-data write UAT | CONDITIONAL GO | Needs Owner approval, test data, runner, stop condition |
| Gate B — Production permission apply | CONDITIONAL GO | Needs Owner approval and permission export |
| Gate C — Legacy payroll ledger backfill | NO-GO | Needs backup, restore rehearsal, dry-run, accounting approval |
| Gate D — Payroll CashFlow metadata backfill | NO-GO | Needs backup, dry-run, report comparison, Owner approval |
| Gate E — Opening balance / salary balance rebuild | NO-GO | Needs source-of-truth balances, dry-run, backup, Owner/BA approval |

## Final Recommendation

Do not start any production write gate yet.

Next safest step is to get Owner approval for exactly one gate at a time, starting with either:

1. Gate B on a test role only, after permission export; or
2. Gate A on staging, then production test data if Owner accepts the data footprint.

Gate C, Gate D, and Gate E should remain NO-GO until a fresh backup, restored-copy rehearsal, dry-run output, accounting review, and exact command approval are complete.

No production command, production SQL, production permission apply, backfill, rebuild, opening balance, migration, build, cache, restart, or deploy was run by this report.
