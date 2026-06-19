# Integrate Payroll Employee Debt With Latest Main Audit

## 1. Source

- Repo: `cuongdesignnb/kiot`
- Base main SHA: `031ab576262103675c45b878629196b1ea4b6e64`
- Source branch: `origin/hotfix/payroll-standard-work-minutes-full-day`
- Source branch SHA: `12ac4a902c60b2e850ed2ce66041f20d388b3028`
- Integration branch: `integrate/payroll-employee-debt-with-main-after-pr2`
- Integration SHA: final branch HEAD in Draft PR
- Merge-base: `182243703b6297d5a2c11d41cab09c7907c1d2ca`
- Ahead/behind before integration: main-only `4`, source-only `14`
- Draft PR URL: `https://github.com/cuongdesignnb/kiot/pull/3`

## 2. Changed Files

- Backend: payroll ledger/payment/advance services, paysheet posting/cancel, employee salary ledger/payment controllers, payroll reconciliation, CashFlow classifier, financial report filters.
- Frontend: employee list salary debt UI, paysheets, paysheet edit, payroll reconciliation page, cancel reason modal.
- Migrations:
  - `database/migrations/2026_06_13_000001_create_employee_salary_ledger_system.php`
  - `database/migrations/2026_06_13_000002_allow_legacy_null_cash_flow_status.php`
  - `database/migrations/2026_06_18_000001_add_payroll_cashflow_metadata.php`
- Tests: new payroll ledger/payment/cancel/opening balance/cashflow tests plus report regression.
- Reports/docs: payroll migration, production readiness, rollback, UAT, BA handover docs from source branch plus this integration audit.

## 3. Conflict Handling

- Conflicts: yes.
- Conflict files:
  - `app/Http/Controllers/TimekeepingRecordController.php`
  - `docs/audit/HOTFIX-PAYROLL-MANUAL-ATTENDANCE-DOWNGRADE-GUARD.md`
  - `docs/audit/HOTFIX-PAYROLL-PERSONAL-GROSS-PROFIT-BONUS-INVOICE-SOURCE.md`
  - `resources/js/Pages/Employees/Attendance.vue`
  - `tests/Feature/Payroll/ManualTimekeepingTest.php`
- Resolution:
  - Kept latest main for attendance controller, attendance UI, manual timekeeping test, and downgrade guard doc because conflicts were whitespace/main-cleanup only.
  - Kept source branch version for personal gross profit bonus audit doc because it contained newer local/remote verification details.
  - Added a small integration safety patch so cancelled paysheets are also blocked from recalculate, inline payslip edit, delete, and adjustment mutations.
- Debt logic preserved: yes, Sapo debt parity regression passed.
- Payroll hotfix preserved: yes, full payroll suite including `ManualTimekeepingTest` passed.
- CashFlow logic reviewed: payroll CashFlow has active/cancelled filtering and report exclusion tests passed.

## 4. Business Logic Audit

- Employee salary ledger: separate employee ledger, append/reversal model, signed amount convention, stable rebuild by `event_at ASC, id ASC`, `balance_after`, `is_effective`, idempotency, and references to paysheet/payslip/payment/advance.
- Salary advance: creates payroll CashFlow and negative ledger entry; allocated advances are blocked from cancellation; unallocated advance cancellation reverses ledger and cancels CashFlow.
- Paysheet lock/payment/cancel: lock only from calculated state, creates payroll accrual ledger without CashFlow; payment only for locked paysheets and validates remaining server-side; cancel blocks active payments and appends reversal instead of deleting history.
- CashFlow: payroll payment/advance CashFlows are identified by structured reference/category and excluded from non-payroll expense PnL to avoid double count.
- Reports: locked paysheets drive payroll expense; cancelled/calculating sheets excluded; payroll payment CashFlows excluded from non-payroll expense.
- Permission: new permissions cover salary balance view, advance create/cancel, ledger view/export, reconciliation view/export, rebuild balance, payroll pay/cancel.
- Backdate/locked period: `PayrollDateGuard` blocks >30 day backdate unless permission/override is present.

## 5. Database Impact

| Table | Column/Index/FK | Risk | Safe plan |
|---|---|---|---|
| `employees` | add `salary_balance_cache`, `salary_balance_calculated_at` | additive columns on existing table; cache starts at default 0 and may not reflect legacy debt until approved migration/backfill | run only after backup; do not convert `employees.balance` without BA/Owner approval |
| `paysheets` | add `payment_status` | additive non-null default | migrate in controlled window |
| `payslips` | add `payment_status`, `applied_advance` | additive non-null default | migrate in controlled window |
| `paysheet_payments` | add `code` unique nullable, `status`, `cash_flow_id` FK, cancel metadata, `idempotency_key` unique nullable | unique nullable should be safe on MySQL; FK nullable; table lock possible | test restore DB first; no production migration before BA approval |
| `cash_flows` | add cancel metadata in first migration; make `status` nullable via `change()`; add nullable FK `branch_id`; add unique nullable `idempotency_key` | `change()` may require platform support; nullable unique and FK are additive but affect high traffic table | restore rehearsal first; confirm existing indexes/columns |
| `salary_advances` | new table with FKs and unique nullable `code`/`idempotency_key` | new table | safe if migration order maintained |
| `salary_advance_applications` | new table with unique advance/payslip pair | new table | safe if migration order maintained |
| `employee_salary_ledger_entries` | new ledger table with reversal uniqueness and idempotency | new table | safe; no automatic backfill |

## 6. Data Safety

- New migration: yes, 3 migrations.
- Backfill: not run.
- Command `--apply`: not run.
- Update/delete old data: no production data touched.
- Recalculate old payroll: not run.
- `employees.balance` conversion: not run.
- Opening balance: command exists but not run; requires separate BA/Owner approval.
- CashFlow mutation: code paths can create/cancel payroll CashFlows after deploy; no production CashFlow changed in this task.
- Customer/supplier debt impact: no code changes to customer/supplier debt beyond regression-preserved main debt logic.
- Production touched: no.
- Backup required before production: yes.
- Rollback plan: revert PR + rollback migrations on restored/approved path; data created after deploy must be handled by BA-approved runbook.
- BA/Owner approval required: yes before merge to main and again before production deploy/migration/backfill.

High-risk commands present:

- `payroll:migrate-salary-ledger --apply`
- `payroll:backfill-paid-payslip-ledger --apply`
- `payroll:backfill-payment-cashflow --apply`
- `payroll:rebuild-salary-balances` writes by default unless `--dry-run` is supplied.

## 7. Tests

Environment:

- Local testing DB: `sales_test_payroll_debt_integration` on `sales_mysql_test` port `3319`.
- Production deploy/migration/DB: not touched.
- Direct `migrate:fresh`: not run.
- Note: Laravel tests using `RefreshDatabase` may reset the testing schema internally.

| Test group | Result |
|---|---|
| `php artisan migrate --env=testing` | PASS, 3 new migrations ran |
| `php artisan test tests/Feature/Payroll` | PASS, 114 PHPUnit warnings |
| `ManualTimekeepingTest` | PASS as part of Payroll suite |
| `SalaryLedgerFlowTest` | PASS as part of Payroll suite |
| `PayrollPaymentCashFlowTest` | PASS as part of Payroll suite |
| `PaidPaysheetShouldNotRemainEmployeeDebtTest` | PASS as part of Payroll suite |
| `PaysheetCancelReversalTest` | PASS as part of Payroll suite |
| `PayrollLedgerOpeningBalanceTest` | PASS as part of Payroll suite |
| `PayrollCashFlowClassifierTest` | PASS as part of Payroll suite |
| `CustomerDebt/SapoDebtParityTest` | PASS, 12 warnings |
| `Orders` | PASS, 23 warnings |
| `POS` | PASS |
| `CashFlow` | PASS |
| `CashFlows` | PASS |
| `Report` | PASS |
| `Reports` | PASS, 73 warnings |
| `npm run build` | PASS |
| `git diff --check` | PASS |

Warnings observed:

- PHP startup warns about missing local extensions: `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`.
- PHPUnit warning markers are present but commands exited `0`.
- `npm ci` reported Node `v20.15.1` below `@vitejs/plugin-vue` engine `^20.19.0 || >=22.12.0`; build still passed.

## 8. Manual QA

- Payroll employee debt: not run in browser; covered by feature tests.
- Salary advance: not run in browser; covered by feature tests.
- Payment/cancel: not run in browser; covered by feature tests.
- CashFlow/report: not run in browser; covered by feature tests.
- Attendance/payroll hotfix smoke: not run in browser; covered by `ManualTimekeepingTest`.
- Debt smoke: not run in browser; covered by `SapoDebtParityTest`.
- Evidence path: terminal test output in this Codex session; no production or staging UI evidence collected.

## 9. GO/NO-GO

- Ready for BA review: yes, Draft PR only.
- Ready to merge main: not yet; BA review required.
- Ready for production deploy: no.
- Ready for production migration: no.
- Need BA/Owner approval: yes, mandatory for merge, deploy, migration, backfill, permission rollout, and any legacy opening balance action.

## 10. Remaining Risks

1. Production migration touches existing high-value tables including `cash_flows`, `paysheets`, `payslips`, `paysheet_payments`, and `employees`; run on restored copy first.
2. Legacy payroll balances are not automatically converted. `employees.balance` stays untouched until BA approves opening balance strategy.
3. Write-capable artisan commands exist and must be restricted operationally; especially `payroll:rebuild-salary-balances` writes unless `--dry-run`.
4. Permission rollout is code-only; no production role assignment was performed.
5. Manual UI UAT was not performed in this task.
