# Post Production UAT Plan — Payroll/Debt/Permission

## Executive Summary

This is a report/plan-only step after the production technical deploy of commit `b6136a801891a8f122b506f608ed6515547d3f56`.

Current recommendation:

| Area | Go/No-Go | Decision |
|---|---|---|
| Normal non-payroll system usage | GO | Technical deploy is reported complete; run read-only smoke and monitor logs. |
| Payroll read-only UAT | GO | Allowed for approved users; no payroll write actions. |
| Payroll new-data business UAT | CONDITIONAL GO | Owner approval required before creating/changing payroll data on production. Prefer staging. |
| Legacy payroll backfill | NO-GO | Requires backup, restored-copy rehearsal, dry-run evidence, exact command, and Owner approval. |
| Opening balance | NO-GO | Requires separate BA/Owner data strategy and go-live date. |
| Salary balance rebuild | NO-GO | `payroll:rebuild-salary-balances` writes by default unless `--dry-run`; production use requires explicit approval. |
| Payroll cashflow metadata backfill | NO-GO | Requires backup, dry-run, exact command, and Owner approval. |
| Production permission rollout | NO-GO for apply | This report proposes a matrix only. No permission is applied in this step. |

No code logic was changed. No migration, backfill, rebuild, production write command, permission apply, or data cleanup was run by this step.

## Production Deploy Technical Status

Production status below is based on the provided deployment confirmation for production path `/www/wwwroot/kiot.cuongdesign.net`.

| Check | Status |
|---|---|
| Production source at commit `b6136a8` | Confirmed by deployment step |
| Debt/payroll migrations | Confirmed `Ran` by deployment step |
| `npm run build` | PASS by deployment step |
| Laravel cache clear/cache rebuild | PASS by deployment step |
| Queue restart | PASS by deployment step |
| PHP-FPM 8.3 restart | PASS by deployment step |
| Nginx/vhost | PASS by deployment step |
| Public login | PASS by deployment step |
| Laravel log after login | No new error reported; only old 2026-06-11 and 2026-06-15 errors remained |

This report did not rerun production deploy, migration, build, cache, queue, PHP-FPM, Nginx, or log commands.

## Source/Commit Verified

Local `main` worktree checked for this report:

```text
D:\Kiot\kiotviet-clone.worktrees\port-sapo-debt-logic-to-kiot
```

Verification:

| Item | Value |
|---|---|
| Branch before report | `main` |
| HEAD before report | `b6136a801891a8f122b506f608ed6515547d3f56` |
| `origin/main` after `git fetch origin main` | `b6136a801891a8f122b506f608ed6515547d3f56` |
| Commit newer than `b6136a8` before report | No |
| Scope re-audit needed due to newer main | No |

Note: this report commit will make repository `main` newer than the deployed production source by documentation only. Production source remains at `b6136a8` until a later source sync is explicitly approved.

## Scope

UAT and permission rollout scope:

- Employee salary ledger.
- Salary advance.
- Paysheet creation, edit, lock, payment, cancellation, payment cancellation.
- Payroll reconciliation.
- Payroll-related CashFlow metadata and report filtering.
- Customer/supplier debt read-only regression.
- Financial report read-only regression.
- Permission visibility and backend authorization.

Out of scope:

- Code logic changes.
- New migrations.
- Production write/backfill/rebuild commands.
- Permission apply on production.
- Legacy data cleanup.
- Inventory, costing, serial/IMEI, stock movement, purchase, repair, or unrelated payroll logic changes.

## Data Safety

| Question | Answer |
|---|---|
| Có migration mới trong bước này không? | Không. |
| Có backfill không? | Không. |
| Có update dữ liệu cũ không? | Không. |
| Có xóa dữ liệu không? | Không. |
| Có apply permission production không? | Không. |
| Có rollback plan không? | Có. Nếu chỉ review/UAT read-only thì rollback không cần. Nếu sau này chạy write/backfill/rebuild thì rollback phải dựa trên DB backup/restore hoặc forward-fix có Owner. |
| Có cần backup DB cho bước report/read-only không? | Không bắt buộc. |
| Có cần backup DB cho write/backfill/rebuild sau này không? | Bắt buộc. |
| Có cần xác nhận trước khi triển khai không? | Có, với mọi command `--apply`, rebuild, recalculate, opening balance, backfill, permission apply, hoặc update dữ liệu cũ. |

Schema reported as present after technical deploy:

- `2026_06_12_120000_create_customer_payment_allocations_table`
- `2026_06_12_120100_add_order_deposit_applied_amount_to_invoices`
- `2026_06_12_120200_add_partner_merge_provenance`
- `2026_06_13_000001_create_employee_salary_ledger_system`
- `2026_06_13_000002_allow_legacy_null_cash_flow_status`
- `2026_06_18_000001_add_payroll_cashflow_metadata`

Data status:

| Data area | Status |
|---|---|
| Legacy payroll data backfill | Not approved in this step |
| Opening balance | Not created in this step |
| `employees.balance` conversion | Not approved in this step |
| Salary balance rebuild | Not run in this step |
| Payroll cashflow metadata backfill | Not run in this step |

Tables that require a fresh backup before any later write/backfill/rebuild:

- `employees`
- `paysheets`
- `payslips`
- `paysheet_payments`
- `salary_advances`
- `salary_advance_applications`
- `employee_salary_ledger_entries`
- `cash_flows`
- `customers`
- `customer_debts`
- `supplier_debt_transactions`
- `invoices`
- `orders`

If any next step needs to touch production data:

```text
Cần xác nhận trước khi triển khai
```

Required confirmation must name affected tables/columns/data, risk, safe plan, backup requirement, rollback owner, and whether the safer option is new-data only, dry-run, scoped UAT, or stop.

## Permission Matrix

No permission is applied by this report. All Owner decisions default to `Chờ duyệt`.

| Role | Proposed allowed permissions | Proposed blocked by default | Notes |
|---|---|---|---|
| `super_admin` | All payroll permissions | None by role, but sensitive actions still require operational care | Full system role; still audit sensitive usage. |
| Kế toán trưởng | `employee.view_salary_balance`, `payroll.view`, `payroll.ledger.view`, `payroll.ledger.export`, `payroll.pay`, `payroll.advance.create`, `payroll.reconciliation.view`, `payroll.reconciliation.export` | `payroll.adjust`, `payroll.rebuild_balance`, `payroll.override_locked_period`, `payroll.override_backdate_limit`, `payroll.pay.cancel`, `payroll.advance.cancel` until Owner approves | Financial high-trust role. |
| Kế toán | `employee.view_salary_balance`, `payroll.view`, `payroll.ledger.view`, `payroll.ledger.export`, `payroll.pay`, `payroll.advance.create`, `payroll.reconciliation.view` | Cancel, adjust, rebuild, override, and advanced admin actions | Can process normal payments if Owner approves. |
| HR/quản lý nhân sự | `payroll.view`, `payroll.create`, `payroll.edit`, optionally `payroll.lock`, optionally `employee.view_salary_balance` | `payroll.pay`, `payroll.pay.cancel`, `payroll.advance.create`, `payroll.advance.cancel`, `payroll.adjust`, rebuild, override | Should not combine HR create/lock with payment unless Owner approves. |
| `branch_admin` | Read-only payroll/ledger/reconciliation within branch if backend branch scope is accepted | Payment/cancel/adjust/rebuild/override by default | Branch scope must be validated before production apply. |
| `cashier` | None by default for salary data | All payroll/debt salary permissions | Default no salary visibility. |
| `warehouse_staff` | None by default for salary data | All payroll/debt salary permissions | Default no salary visibility. |
| `task_manager` | None by default for salary data | All payroll/debt salary permissions | Default no salary visibility. |

Sensitive permissions that must not be granted silently:

- `payroll.adjust`
- `payroll.rebuild_balance`
- `payroll.override_locked_period`
- `payroll.override_backdate_limit`
- `payroll.pay.cancel`
- `payroll.advance.cancel`

Owner approval table:

| Permission | Sensitivity | Proposed role | Owner decision | Applied production? |
|---|---|---|---|---|
| `employee.view_salary_balance` | High | super_admin, kế toán trưởng, kế toán, HR if approved | Chờ duyệt | No |
| `payroll.view` | Medium | super_admin, kế toán trưởng, kế toán, HR, branch_admin scoped | Chờ duyệt | No |
| `payroll.create` | High | super_admin, HR if approved | Chờ duyệt | No |
| `payroll.edit` | High | super_admin, HR if approved | Chờ duyệt | No |
| `payroll.lock` | High | super_admin, HR/kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.cancel` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.pay` | High | super_admin, kế toán trưởng, kế toán if approved | Chờ duyệt | No |
| `payroll.pay.cancel` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.advance.create` | High | super_admin, kế toán trưởng, kế toán if approved | Chờ duyệt | No |
| `payroll.advance.cancel` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.adjust` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.ledger.view` | High | super_admin, kế toán trưởng, kế toán, HR if approved | Chờ duyệt | No |
| `payroll.ledger.export` | High | super_admin, kế toán trưởng, kế toán if approved | Chờ duyệt | No |
| `payroll.reconciliation.view` | High | super_admin, kế toán trưởng, kế toán | Chờ duyệt | No |
| `payroll.reconciliation.export` | High | super_admin, kế toán trưởng | Chờ duyệt | No |
| `payroll.rebuild_balance` | Very high | super_admin only if approved | Chờ duyệt | No |
| `payroll.override_locked_period` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |
| `payroll.override_backdate_limit` | Very high | super_admin, kế toán trưởng if approved | Chờ duyệt | No |

## UAT Level 1 — Read-only Smoke

Allowed immediately for approved production users:

- Login.
- Open dashboard.
- Open employee/payroll/paysheet/cashflow/customer/supplier/report pages.
- Open read-only detail pages and exports only if role has approved read/export access.
- Check no `500`, `403`, `404`, blank page, Vite manifest error, missing table/column error, or new production log error.

Blocked:

- Create paysheet.
- Recalculate paysheet.
- Lock payroll.
- Pay salary.
- Cancel payment.
- Create/cancel advance.
- Adjust salary ledger.
- Rebuild salary balance.
- Backfill.
- Opening balance.
- Permission apply.

Backend read-only checklist:

| Area | File/Route/Command | Risk | UAT allowed | UAT blocked |
|---|---|---|---|---|
| Employee salary ledger | `GET /api/employees/{employee}/salary-ledger`, `EmployeeSalaryLedgerController@index` | Salary data exposure | View only with `payroll.ledger.view` | Adjust/export unless approved |
| Salary advance | `GET /api/employees/{employee}/salary-advances` | Exposes advance history | View only with `payroll.ledger.view` | Create/cancel advance |
| Paysheet list/detail | `GET /api/paysheets`, `GET /api/paysheets/{id}` | Salary data exposure | View with `payroll.view` | Create/edit/lock/pay/cancel |
| Payroll reconciliation | `/employees/payroll/reconciliation`, `GET /api/payroll/reconciliation` | Highlights financial mismatches | View with `payroll.reconciliation.view` | Export/rebuild |
| Cashflow metadata | `/cash-flows` | Payroll cashflows visible in cash ledger | Filter/view existing rows | Create/edit/delete cashflows |
| Customer debt regression | `/customers`, debt history APIs | Financial display regression | Open/read, verify negative credit display remains stable | Debt payment/adjust/merge |
| Supplier debt regression | `/suppliers`, debt history APIs | Financial display regression | Open/read, verify dual-role display remains stable | Supplier payment/adjust/merge |
| Financial report regression | `/reports/financial-report` | Payroll expense/report filter regression | View report | Data correction/backfill |

Frontend smoke checklist:

| Screen | URL/Route | Test role | Read-only check | Write-data check | Expected |
|---|---|---|---|---|---|
| Dashboard | `/` | approved user | Page loads, no blank page | None | No new errors |
| Employees list | `/employees` | payroll read user | Employee table loads, salary balance visibility follows permission | None | VND money format; no leak to unauthorized roles |
| Employee salary balance | `/employees`, employee modal/API | payroll ledger user | Ledger/advance tab loads | No adjust/pay/advance create | Permissions hide blocked actions |
| Payroll/Paysheets list | `/employees/paysheets` | payroll view user | List loads and filters work | No create/recalculate/lock/pay | No missing column/table |
| Paysheet detail/edit | `/employees/paysheets/{id}/edit` | payroll view user | Detail loads | No edit/lock/cancel/pay | Dates use VN format |
| Payroll reconciliation | `/employees/payroll/reconciliation` | reconciliation view user | Summary/issues load | No rebuild | Export only if approved |
| CashFlow/Sổ quỹ | `/cash-flows` | finance read user | Existing rows load, payroll cashflow labels are readable | No create/edit/delete | No new financial mutation |
| Customer debt | `/customers` | debt read user | Debt and negative credit display load | No debt payment/adjust | Existing debt logic unchanged |
| Supplier debt | `/suppliers` | debt read user | Supplier debt and dual-role display load | No supplier payment/adjust | Mirror sign remains correct |
| Financial report | `/reports/financial-report` | report read user | Payroll expense/filter display loads | No correction command | No double-count payroll cashflows |
| POS/Orders regression | `/pos`, `/orders` | sales read/smoke user | Pages load read-only | Avoid checkout/order create in production smoke | No asset/runtime error |

Read-only quality gates:

- Asset loads correctly.
- No Vite manifest error.
- No wrong permission error for authorized read users.
- Unauthorized user cannot see salary/debt payroll data.
- Money displays in VND format.
- Dates display VN style `dd/MM/yyyy HH:mm`, not AM/PM.

## UAT Level 2 — Permission/Role

Preferred environment: staging.

If run on production, use only approved test accounts and approved test permissions. Do not apply real production role changes without Owner sign-off.

Checks:

- User without `employee.view_salary_balance` cannot see salary balance in employee list/detail.
- User without `payroll.ledger.view` cannot call ledger APIs directly.
- User without `payroll.pay` cannot call payment endpoint even if UI is manipulated.
- User without `payroll.pay.cancel` cannot cancel salary payments.
- User without `payroll.advance.create` cannot create salary advance.
- User without `payroll.advance.cancel` cannot cancel salary advance.
- User without `payroll.rebuild_balance` cannot access rebuild endpoint.
- Branch-scoped user cannot see other-branch payroll data if branch scope is accepted for production rollout.

Backend authorization references:

- Permission definitions: `app/Models/Role.php`.
- Payroll page route: `routes/web.php`.
- Payroll API routes: `routes/api.php`.
- Date override guard: `app/Services/PayrollDateGuard.php`.
- Branch/employee access: `app/Services/PayrollAccessService.php`.

## UAT Level 3 — New Data Business Flow

Default status: blocked until Owner approval.

Preferred environment: staging. If production is used, create clearly named test records only, in an approved window, with rollback/cleanup decision documented before execution.

Proposed flow after approval:

1. Create a new test paysheet.
2. Recalculate the paysheet.
3. Lock the paysheet.
4. Create a salary advance for a test employee.
5. Apply advance through payroll lock/payment flow.
6. Pay salary partially.
7. Pay remaining salary.
8. Attempt overpayment and confirm it is rejected.
9. Cancel one payment and verify reversal.
10. Verify payroll CashFlow row status/metadata.
11. Verify salary ledger balance and timeline.
12. Verify payroll reconciliation.
13. Verify financial report payroll expense does not double-count payroll payment cashflows.

If this flow runs on production:

```text
Cần xác nhận trước khi triển khai
```

Affected data includes `employees`, `paysheets`, `payslips`, `paysheet_payments`, `salary_advances`, `salary_advance_applications`, `employee_salary_ledger_entries`, and `cash_flows`.

## UAT Level 4 — Legacy Backfill/Rebuild/Open Balance

Default status: NO-GO.

Only propose, do not run.

Requirements before any legacy data operation:

- Fresh production backup.
- Restore rehearsal to a separate database.
- Dry-run output on restored copy.
- Exact command and flags.
- Employee/paysheet scope if not full-system.
- BA/Owner sign-off.
- Rollback owner.
- Before/after reconciliation checklist.
- Written acceptance of expected deltas.

Legacy operation decisions still pending:

| Operation | Current status | Required before GO |
|---|---|---|
| Backfill salary ledger from legacy paysheets | NO-GO | Backup, restored-copy rehearsal, dry-run, exact command, Owner approval |
| Backfill payroll payment CashFlow metadata | NO-GO | Backup, dry-run, affected rows, report impact review |
| Opening balance | NO-GO | Go-live date, legacy balance source, reconciliation sign-off |
| Rebuild salary balances | NO-GO | Use `--dry-run` first; write run requires Owner approval |
| Convert `employees.balance` | NO-GO | Separate data strategy; not part of this UAT plan |

## Blocked Commands

These commands are blocked on production unless a separate Owner approval names the exact command, flags, target, expected output, backup, and rollback owner.

| Command | Production status | Reason |
|---|---|---|
| `php artisan payroll:migrate-salary-ledger --apply` | Blocked | Writes ledger/opening/backfill data. |
| `php artisan payroll:backfill-paid-payslip-ledger --apply` | Blocked | Writes payroll ledger rows. |
| `php artisan payroll:backfill-payment-cashflow --apply` | Blocked | Writes/links payroll CashFlow rows. |
| `php artisan payroll:rebuild-salary-balances` without `--dry-run` | Blocked | Writes balance cache by default. |
| `php artisan salary:recalculate` | Blocked | Legacy write-capable recalculation. |
| Any data command with `--apply` | Blocked | Potential production mutation. |
| `php artisan migrate:fresh` | Never allowed | Destructive. |

Dry-run only candidates after approval:

| Command | Allowed mode | Notes |
|---|---|---|
| `php artisan payroll:migrate-salary-ledger --legacy-balance=report` | Dry-run/report only | No `--apply`. Prefer restored copy first. |
| `php artisan payroll:backfill-paid-payslip-ledger --dry-run` | Dry-run only | Production dry-run still requires approval. |
| `php artisan payroll:backfill-payment-cashflow --dry-run` | Dry-run only | Production dry-run still requires approval. |
| `php artisan payroll:rebuild-salary-balances --dry-run` | Dry-run only | Required because command writes by default otherwise. |

No command above was run in this step.

## Required Owner Decisions

| Decision | Default | Owner action needed |
|---|---|---|
| Accept production technical deploy as ready for read-only smoke | Pending | Confirm |
| Allow production read-only payroll smoke | Pending | Confirm test users/roles |
| Allow production permission test accounts | Pending | Confirm accounts and temporary permissions |
| Apply production payroll permissions to real roles | No | Approve role matrix |
| Allow new-data payroll UAT on production | No | Approve scenario, data naming, rollback/cleanup stance |
| Allow backfill paid payslips | No | Approve exact dry-run/apply plan |
| Allow payroll payment cashflow backfill | No | Approve exact dry-run/apply plan |
| Allow opening balance | No | Approve go-live date and source of truth |
| Allow salary balance rebuild | No | Approve dry-run first, then write if needed |
| Allow any `--apply` command | No | Approve exact command and backup |

## Test Plan

Recommended local/staging commands, not production write:

```bash
php artisan test tests/Feature/Payroll
php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php
php artisan test --filter=CashFlow
php artisan test --filter=Order
php artisan test --filter=Report
npm run build
git diff --check
```

Actual tests run in this step:

| Test/check | Result |
|---|---|
| `git fetch origin main` | PASS |
| `git rev-parse HEAD` | PASS, `b6136a8` before report |
| `git rev-parse origin/main` | PASS, `b6136a8` before report |
| Application tests | Not run in this report-only step |
| `npm run build` | Not run in this report-only step |
| Production command | Not run |

Previous evidence from PR #3 integration audit:

- Payroll feature tests: PASS.
- `CustomerDebt/SapoDebtParityTest`: PASS.
- Orders/POS/CashFlow/Reports regressions: PASS.
- `npm run build`: PASS.
- Clone migration safety: PASS.
- Local UAT via authenticated HTTP + DB readback: PASS.

## Manual QA Checklist

Read-only smoke:

- [ ] Login production
- [ ] Dashboard load
- [ ] Employees load
- [ ] Employee salary balance visibility follows permission
- [ ] Paysheets load
- [ ] Paysheet detail load
- [ ] Payroll reconciliation load
- [ ] CashFlow load
- [ ] Customer debt load
- [ ] Supplier debt load
- [ ] Financial report load
- [ ] POS/Orders read-only regression pages load
- [ ] No new Laravel `production.ERROR` after smoke
- [ ] No new Nginx error after smoke
- [ ] No payroll write/backfill/rebuild command executed

Permission UAT:

- [ ] User without salary permissions cannot view salary balance
- [ ] User without ledger permission cannot call ledger API
- [ ] User without pay permission cannot call pay API
- [ ] User without cancel permission cannot cancel payment/advance
- [ ] User without rebuild permission cannot call rebuild endpoint
- [ ] Branch-scoped visibility verified if branch rollout is approved

New-data UAT, only after Owner approval:

- [ ] Test paysheet created
- [ ] Paysheet recalculated
- [ ] Paysheet locked
- [ ] Salary advance created
- [ ] Advance applied
- [ ] Salary paid partially
- [ ] Salary paid fully
- [ ] Overpayment rejected
- [ ] Payment cancelled and reversed
- [ ] CashFlow metadata verified
- [ ] Salary ledger verified
- [ ] Reconciliation verified
- [ ] Financial report verified

## Rollback/Recovery Plan

For report/read-only UAT:

- No database rollback is required because no data should be mutated.
- If UI read-only smoke fails, stop UAT and collect error URL, user role, timestamp, Laravel log line, and browser console evidence.

For production permission apply:

- Record role/permission changes before apply.
- Apply to test role first if possible.
- Roll back by removing newly assigned permissions.
- Verify unauthorized endpoints return forbidden after rollback.

For new-data UAT:

- Use named test records.
- Document whether test records stay as audit evidence or require approved cleanup.
- If cleanup is required, define exact records and owner before UAT starts.

For backfill/rebuild/opening balance:

- Fresh backup is mandatory.
- Restore rehearsal is mandatory.
- Rollback is DB restore or forward-fix only; code rollback alone is not enough after financial data writes.

## Go/No-Go

| Activity | Recommendation |
|---|---|
| Normal system usage | GO with monitoring |
| Payroll read-only smoke | GO for approved users |
| Permission/role UAT | GO on staging; CONDITIONAL on production with test accounts |
| Payroll new-data UAT | CONDITIONAL GO after Owner approval |
| Apply production permission matrix | NO-GO until Owner approves |
| Legacy backfill | NO-GO |
| Opening balance | NO-GO |
| Salary balance rebuild write | NO-GO |
| Payroll cashflow metadata backfill write | NO-GO |
| Any production `--apply` | NO-GO |

## Final Recommendation

Proceed with Level 1 read-only smoke and Level 2 permission verification only after Owner confirms the test users/roles.

Do not run payroll write UAT, permission apply, backfill, rebuild, opening balance, or legacy conversion until Owner approval is explicit and includes exact scope, backup, rollback owner, and commands.

No production command was run by this report.
