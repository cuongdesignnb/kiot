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

- Local browser UI render: PASS for login, paysheet list, and paysheet edit on `http://127.0.0.1:8003`.
- Browser automation limitation: clicking `Chốt lương` opened a JavaScript confirm dialog and the in-app browser CDP session became unstable. I did not force the browser further.
- Business UAT execution: PASS via authenticated local HTTP session against the same local app and clone DB, using browser-equivalent headers (`Origin`, `Referer`, `X-Requested-With`) plus session cookies.
- DB verification: PASS via local clone DB readback; `salary_balance_cache = ledger effective sum = 600000`.
- CashFlow/report cancellation evidence: cancelled payroll payment/advance CashFlows have `status=cancelled` and `deleted_at` set, while active payroll CashFlows remain active.
- Debt smoke: PASS via `CustomerDebt/SapoDebtParityTest`.
- Attendance/payroll hotfix smoke: PASS via `ManualTimekeepingTest` and payroll suite.
- Evidence path: this report section plus terminal output from the local clone run; no production or staging UI screenshot committed.

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
5. Full click-by-click browser UAT is partially limited by in-app browser automation around a JavaScript confirm dialog. UI render passed; business actions were verified through authenticated local API calls and clone DB readback.

## 11. Staging/Clone Migration Safety Review

- Environment: local clone/testing DB on Docker MySQL `sales_mysql_test:3319`.
- DB source: base `origin/main` (`031ab576262103675c45b878629196b1ea4b6e64`) migrated first, then PR #3 migrations applied.
- Database: `sales_test_pr3_migration_safety`.
- Branch: `integrate/payroll-employee-debt-with-main-after-pr2`.
- Head before report update: `b051c15a958839543111eda4036220c8258de08a`.
- Production DB used: No.
- Production deploy/migration/audit: No.
- `migrate:fresh`: Not run.

Migration status before PR migrations:

| Migration | Before | After clone migrate |
|---|---|---|
| `2026_06_13_000001_create_employee_salary_ledger_system` | Pending | Ran, batch 2 |
| `2026_06_13_000002_allow_legacy_null_cash_flow_status` | Pending | Ran, batch 2 |
| `2026_06_18_000001_add_payroll_cashflow_metadata` | Pending | Ran, batch 2 |

`migrate --pretend` summary:

- `employees`: add `salary_balance_cache`, `salary_balance_calculated_at`.
- `paysheets`: add `payment_status`.
- `payslips`: add `payment_status`, `applied_advance`.
- `paysheet_payments`: add nullable unique `code`, `status`, nullable `cash_flow_id` FK, cancel metadata, nullable unique `idempotency_key`.
- `cash_flows`: add cancel metadata, make `status` nullable/default active, add nullable `branch_id` FK, nullable unique `idempotency_key`.
- New tables: `salary_advances`, `salary_advance_applications`, `employee_salary_ledger_entries`.
- New indexes/FKs: nullable FKs on payroll/cashflow metadata; unique nullable payroll codes/idempotency; unique advance application pair; unique ledger reversal pair.

Clone migrate result:

- `php artisan migrate --env=testing --force`: PASS on clone DB.
- `php artisan migrate:status --env=testing`: PR migrations shown as `Ran`.

Risk notes:

- Existing-data risk: additive columns default to zero/unpaid; legacy `employees.balance` is not converted and must remain untouched until BA/Owner approval.
- Lock risk: `cash_flows`, `employees`, `paysheets`, `payslips`, and `paysheet_payments` are high-value tables; run during a controlled production window after backup/restore rehearsal.
- Rollback notes: migration rollback drops the new payroll ledger/advance tables and removes added columns; any data created after production deploy would require a BA-approved data runbook before rollback.

Write-capable command review:

| Command | Default behavior | Dry-run evidence | Production risk |
|---|---|---|---|
| `payroll:migrate-salary-ledger` | Dry-run/report unless `--apply` | PASS, `Mode: DRY-RUN`, no candidates | `--apply` can convert/open salary balances; BA approval required |
| `payroll:backfill-paid-payslip-ledger` | Requires dry-run/apply mode | PASS, candidate `0` | `--apply` writes ledger rows |
| `payroll:backfill-payment-cashflow` | Requires dry-run/apply mode | PASS, candidate `0` | `--apply` writes CashFlow rows |
| `payroll:rebuild-salary-balances` | Writes by default unless `--dry-run` | PASS with `--dry-run` | High risk; must never run on production without explicit approval |
| `salary:recalculate` | Legacy write-capable salary command | Not run | High risk; out of PR #3 rollout |

## 12. Browser UAT Evidence

- URL: `http://127.0.0.1:8003`.
- Branch: `integrate/payroll-employee-debt-with-main-after-pr2`.
- Commit tested before report update: `b051c15a958839543111eda4036220c8258de08a`.
- DB: `sales_test_pr3_migration_safety` on `sales_mysql_test:3319`.
- Browser: Codex in-app browser.
- User/role: `uat-pr3@example.test`, admin-compatible user with `role_id = null`.
- Dataset prefix: `UAT-PR3-20260619093937`.
- UI evidence: login page, dashboard, paysheet list, and paysheet edit rendered successfully. Paysheet list showed `PAY-20260619093937`, `ADV-20260619093937`, and `CANCEL-20260619093937`.
- Automation limitation: direct browser click on `Chốt lương` hit an unstable JavaScript confirm/CDP state. Business UAT actions below were executed through authenticated local HTTP session against the same server and clone DB.

| Case | Result | Evidence | Notes |
|---|---|---|---|
| Lock paysheet accrual | PASS | `PUT /api/paysheets/1/lock` => `200`, status `locked`; ledger `payroll_accrual` created; no CashFlow created by lock | UI edit page rendered the source calculated sheet before action |
| Partial payment | PASS | `POST /api/paysheets/1/pay` amount `400000` => payment `TTPL000001`, active, CashFlow created | Later cancelled in UAT 5 |
| Full payment | PASS | Remaining `600000` paid via `TTPL000002`; sheet became paid before cancelling first payment | After UAT 5 final state is partial, expected because one payment was cancelled |
| Overpayment blocked | PASS | Attempted `700000` when remaining was `600000` => HTTP `422`, message `So tien tra vuot so con phai tra.` | No extra payment/CashFlow created |
| Cancel payment | PASS | `POST /api/paysheet-payments/1/cancel` => payment `cancelled`; CashFlow `PCPL000001` `status=cancelled`, `deleted_at` set; reversal ledger created | Sheet remaining recalculated to `400000` |
| Create advance | PASS | `POST /api/employees/1/salary-advances` amount `500000` => `TU000001`, active, CashFlow `PCTU1` active, salary advance ledger negative | Used for allocation case |
| Allocate advance | PASS | Locking `ADV-20260619093937` applied `500000`; payslip `applied_advance=500000`, remaining `200000`; application active | No double-count observed |
| Block cancel allocated advance | PASS | Cancel `TU000001` => HTTP `422`, message `Không thể hủy tạm ứng đã được cấn vào phiếu lương.` | CashFlow/ledger unchanged |
| Cancel unallocated advance | PASS | Created `TU000002` amount `200000`, then cancelled; CashFlow `PCTU2` `status=cancelled`, `deleted_at` set; reversal ledger created | Advance status `cancelled` |
| Block locked/cancelled mutation | PASS | Locked sheet recalculate/update/delete returned `422`; cancelled sheet recalculate returned `422` | Delete locked sheet message was payment-related because the sheet already had payment history |
| Reconciliation UI/API | PASS | `GET /api/payroll/reconciliation` => HTTP `200`; browser page route attempted after prior confirm issue but tab automation remained unstable | API evidence accepted; no production data touched |
| CashFlow/report cancelled exclusion | PASS | DB readback: cancelled payment/advance CashFlows have `status=cancelled` and soft-delete timestamp; report regression tests PASS | Active payroll CashFlows remain active |
| Debt smoke | PASS | `CustomerDebt/SapoDebtParityTest` PASS | Not manually rerun in browser in this step |
| Attendance/payroll hotfix smoke | PASS | `ManualTimekeepingTest` and payroll suite PASS | Not manually rerun in browser in this step |

Clone DB readback after UAT:

- Employee `UAT-PR3-20260619093937`: `salary_balance_cache = 600000`.
- Effective ledger sum: `600000`.
- Paysheet `PAY-20260619093937`: `locked`, `total_salary=1000000`, `total_paid=600000`, `total_remaining=400000`, payment status `partial`.
- Paysheet `ADV-20260619093937`: `locked`, `total_salary=700000`, `applied_advance=500000`, `total_remaining=200000`.
- Paysheet `CANCEL-20260619093937`: `cancelled`, no active payment.
- CashFlows: `PCPL000001` cancelled, `PCPL000002` active, `PCTU1` active, `PCTU2` cancelled.

## 13. Permission Matrix

Permission definitions were reviewed in `App\Models\Role::getPermissionsMap()` and route middleware.

| Action | Permission | Result |
|---|---|---|
| View salary balance | `employee.view_salary_balance` | Present in permission map |
| Create advance | `payroll.advance.create` | Present; API route protected |
| Cancel advance | `payroll.advance.cancel` | Present; API route protected |
| View ledger | `payroll.ledger.view` | Present; API route protected |
| Export ledger | `payroll.ledger.export` | Present; API route protected |
| Pay paysheet | `payroll.pay` | Present; API route protected |
| Cancel payment | `payroll.pay.cancel` | Present; API route protected |
| View reconciliation | `payroll.reconciliation.view` | Present; API and page routes protected |
| Export reconciliation | `payroll.reconciliation.export` | Present; API route protected |
| Rebuild balance | `payroll.rebuild_balance` | Present; high-risk UI/API permission |
| Override locked period | `payroll.override_locked_period` | Present; route/service uses date guard |
| Override backdate limit | `payroll.override_backdate_limit` | Present; route/service uses date guard |

Rollout recommendation:

- Do not assign `payroll.rebuild_balance`, `payroll.override_locked_period`, or `payroll.override_backdate_limit` broadly.
- Keep write-capable artisan commands operationally restricted; no production `--apply` or rebuild command without BA/Owner sign-off.
- Production role assignment was not performed in this task.

## 14. Updated GO/NO-GO

- Ready for BA review: Yes, with local clone migration safety, tests/build, permission review, and UAT evidence above.
- Ready to mark PR ready: No, not by agent decision. BA/Owner must approve after reviewing this report.
- Ready to merge main: No.
- Ready for production deploy: No.
- Ready for production migration: No.
- Production DB touched: No.
- Production deploy/migration/audit run: No.
- Backfill/apply command run: No.
- Legacy `employees.balance` conversion/opening balance: Not run.
- Required BA/Owner approvals: mark Ready, merge PR #3, production deploy, production migration, permission rollout, any salary ledger backfill/apply/rebuild/opening-balance action.
