# Local Payroll Gate A UAT With Latest Dump

## Executive Summary

- Date: 2026-06-20 Asia/Saigon.
- Source worktree: `D:\Kiot\kiotviet-clone.worktrees\port-sapo-debt-logic-to-kiot`.
- Branch: `main`.
- HEAD before UAT: `24298cf39017e59e6ed7f55ef9a12d48b16eb994`.
- Latest production sync target referenced by BA: `b6136a801891a8f122b506f608ed6515547d3f56`.
- UAT database dump: `D:\Kiot\kiotviet-clone\kiotdb.zip`.
- Local imported database: Docker MariaDB container `kiot_payroll_audit_mariadb`, database `kiot_local_payroll_uat`.
- Production touched: No.
- Production command run: No.
- Production migration/build/cache/restart: No.
- Overall gate result: **PARTIAL / NO-GO FOR FINAL PRODUCTION ROLLOUT UNTIL FINDINGS ARE TRIAGED**.

Core payroll write flow on latest local dump passed the scripted UAT checks. However, the run found one payroll reconciliation issue for a cancelled payment cashflow, and the broad Customer/Supplier debt regression command still has 50 failures. Payroll-specific automated tests, CashFlow/Orders/POS/Report(s), build, diff check, and migration rollback safety passed.

## Local Environment

| Item | Value |
|---|---|
| PHP CLI | 8.2.29, with startup warnings for missing optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci` |
| App env used for UAT script | Inline env only: `APP_ENV=local`, `DB_HOST=127.0.0.1`, `DB_PORT=3320`, `DB_DATABASE=kiot_local_payroll_uat` |
| App env used for automated tests | Inline env only: `APP_ENV=testing`, `DB_HOST=127.0.0.1`, `DB_PORT=3319`, `DB_DATABASE=sales_test` |
| Build command | `npm run build` |
| `.env` usage | Not used for UAT/test commands because it contains production-looking values |

No `.env.localuat` or `.env.testing` file was created. Runtime evidence files were kept under ignored `storage/app/uat-evidence`.

## Database Dump Import

| Item | Value |
|---|---|
| Zip file | `D:\Kiot\kiotviet-clone\kiotdb.zip` |
| Zip modified time | 2026-06-20 09:22:20 |
| SQL inside zip | `kiot_db_2026-06-20_08-54-36_mysql_data_omNmN.sql` |
| Extracted local path | `D:\Kiot\_local_payroll_uat_import\kiot_db_2026-06-20_08-54-36_mysql_data_omNmN.sql` |
| SQL size | 3,456,792 bytes |
| Dump source engine | MariaDB dump 10.19, server `10.11.10-MariaDB-log` |
| Local import DB | `kiot_local_payroll_uat` |
| Import result | PASS |

Import target was dropped/recreated locally only. No production database was touched.

## Migration Status On Imported Dump

`php artisan migrate:status` was run against the imported local DB with `DB_COLLATION=utf8mb4_unicode_ci` because the dump was loaded into MariaDB and the app default MySQL collation `utf8mb4_0900_ai_ci` is unsupported by MariaDB.

Relevant migrations were already `Ran`, including:

- `2026_06_12_120000_create_customer_payment_allocations_table`
- `2026_06_12_120100_add_order_deposit_applied_amount_to_invoices`
- `2026_06_12_120200_add_partner_merge_provenance`
- `2026_06_13_000001_create_employee_salary_ledger_system`
- `2026_06_13_000002_allow_legacy_null_cash_flow_status`
- `2026_06_18_000001_add_payroll_cashflow_metadata`

## Baseline Counts From Imported Dump

| Table | Count before UAT |
|---|---:|
| `employees` | 7 |
| `paysheets` | 10 |
| `payslips` | 48 |
| `paysheet_payments` | 5 |
| `salary_advances` | 0 |
| `salary_advance_applications` | 0 |
| `employee_salary_ledger_entries` | 33 |
| `cash_flows` | 556 |

## Local UAT Test Data

| Entity | Value |
|---|---|
| Employee ID | `12` |
| Employee code | `NV-UAT-PAYROLL-LOCAL-20260620110440` |
| Employee name | `TEST-UAT-PAYROLL-LOCAL 20260620110440` |
| Branch ID | `2` |
| Branch name | `UAT Payroll Local Branch 20260620110440` |
| Base salary | `30,000` |
| Paysheet ID/code | `11` / `BL000011` |
| Paysheet name | `UAT-PAYROLL-LOCAL 20260620110440` |
| Payslip ID/code | `64` / `PL000063` |
| Salary advance ID/code | `1` / `TU000001` |
| Payment 1 ID/code | `6` / `TTPL000006` |
| Payment 2 ID/code | `7` / `TTPL000007` |

Note: source code applies salary advances during paysheet lock, so the local UAT created the salary advance before locking the paysheet.

## UAT Flow Result

| Step | Expected | Result |
|---|---|---|
| Create test employee with fixed salary | Employee active, salary balance starts at `0` | PASS |
| Create custom paysheet for only test employee | One payslip, salary `30,000`, no ledger before lock | PASS |
| Recalculate paysheet | Salary stays `30,000`, still no ledger before lock | PASS |
| Create salary advance before lock | Advance `10,000`, cashflow active, balance `-10,000` | PASS |
| Lock paysheet | One accrual `30,000`, advance applied `10,000`, remaining `20,000`, balance `20,000` | PASS |
| Pay first `10,000` | Payment/cashflow active, payslip remaining `10,000`, balance `10,000` | PASS |
| Attempt overpayment | Rejected, no mutation | PASS |
| Pay remaining `10,000` | Remaining `0`, payment status `paid`, balance `0` | PASS |
| Cancel first payment | Payment cancelled, reversal `+10,000`, cashflow cancelled, final balance `10,000` | PASS |

Script overall checks: `PASS`.

## Ledger Verification

| Code | Type | Amount | Status | Balance After |
|---|---|---:|---|---:|
| `TU000001` | `salary_advance` | `-10,000` | `valid` | `-10,000` |
| `TTPL000006` | `salary_payment` | `-10,000` | `reversed` | `-20,000` |
| `PL000063` | `payroll_accrual` | `30,000` | `valid` | `10,000` |
| `TTPL000007` | `salary_payment` | `-10,000` | `valid` | `0` |
| `HTTPL000006` | `cancel_reverse` | `10,000` | `valid` | `10,000` |

Employee `salary_balance_cache = 10,000` and ledger balance = `10,000`.

## CashFlow Verification

| CashFlow ID | Code | Reference | Amount | Status | Deleted At |
|---|---|---|---:|---|---|
| `560` | `PCTU1` | `SalaryAdvance TU000001` | `10,000` | `active` | null |
| `561` | `PCPL000006` | `PaysheetPayment TTPL000006` | `10,000` | `cancelled` | `2026-06-20 11:04:42` |
| `562` | `PCPL000007` | `PaysheetPayment TTPL000007` | `10,000` | `active` | null |

Financial report proxy for these test cashflows:

- Active payroll-related payment amount: `20,000`.
- Active non-payroll expense amount: `0`.
- Result: payroll cashflows are not double-counted as normal expense.

## Payroll Reconciliation Finding

`PayrollReconciliationService::audit(['employee' => 12])` returned employee row OK:

| Field | Value |
|---|---:|
| salary balance cache | `10,000` |
| ledger balance | `10,000` |
| difference | `0` |
| primary status | `OK` |

But `document_issues` contains one critical issue:

| Group | Issue | Document | Employee |
|---|---|---|---|
| `payment` | `PAYMENT_WITHOUT_CASHFLOW` | `TTPL000006` / ID `6` | `NV-UAT-PAYROLL-LOCAL-20260620110440` |

Interpretation: the cancelled payment does have a cashflow when queried with trashed rows (`cash_flows.id = 561`, status `cancelled`, soft-deleted), but reconciliation currently treats it as missing through the normal `cashFlow` relation. This should be triaged before using reconciliation as a final production gate after payment cancellation scenarios.

Proposed hotfix direction, not applied in this step:

- Make payroll reconciliation cashflow checks cancellation-aware, e.g. load related cashflow with trashed rows or explicitly treat cancelled payment/cancelled cashflow pairs as linked audit records.
- Add a regression test for cancelled paysheet payment reconciliation.

## Automated Tests

All test commands used inline testing env vars pointed to local Docker MySQL `127.0.0.1:3319`. No production DB was touched.

| Command | Result |
|---|---|
| `php artisan test tests\Feature\Payroll` | PASS, 114 tests / 610 assertions |
| `php artisan test tests\Feature\CustomerDebt tests\Feature\Customers tests\Feature\Supplier tests\Feature\Suppliers` | FAIL, 218 passed / 50 failed / 1 skipped / 1124 assertions |
| `php artisan test tests\Feature\CashFlow tests\Feature\CashFlows tests\Feature\Orders tests\Feature\POS tests\Feature\Report tests\Feature\Reports` | PASS, 211 tests / 1074 assertions |

Important detail: `tests\Feature\CustomerDebt\SapoDebtParityTest.php` passed all 12 scenarios inside the failing Customer/Supplier command.

Representative Customer/Supplier failures:

- `Tests\Feature\Customers\AnhThanhThienPhuDebtReconcileTest`: expected supplier net `75,000,000`, actual `0`.
- `Tests\Feature\Customers\CustomerDebtExcelExportTest`: return row label/export and legacy CSV 500 at `CustomerController.php:918` (`Undefined array key "type"`).
- `Tests\Feature\Customers\CustomerDebtHistoryDoubleCountTest`: several legacy fallback/reference-only assertions failing.
- `Tests\Feature\Customers\DualRolePartnerDebtTimelineTest`: dual-role mirror/offset assertions failing.
- `Tests\Feature\Customers\PartnerFinancialTimelineTest`: multiple timeline/partner effect assertions failing.
- `Tests\Feature\Supplier\SupplierDebtTimelineKiotStandardTest`: supplier net/effect/type label fields failing or missing.
- `Tests\Feature\Suppliers\SupplierDualRole*`: missing effect fields and net mismatch.
- `Tests\Feature\Suppliers\SupplierPayableLedgerTest`: supplier effect/payment fallback assertions failing.

These are outside payroll core, but they are within the requested broad regression scope, so the final gate should remain partial until BA decides whether these are known debt-regression failures or must block production.

## Build And Diff Check

| Command | Result |
|---|---|
| `npm run build` | PASS |
| `git diff --check` | PASS |

Build generated ignored `public/build` artifacts only; they were not committed.

## Migration And Rollback Safety

A separate local testing database was created in Docker MySQL:

- Container: `sales_mysql_test`
- Database: `kiot_migration_safety`
- User: `test_user`

Commands:

```bash
php artisan migrate --force
php artisan migrate:rollback --step=1 --force
php artisan migrate --force
php artisan migrate:status
```

Result: PASS.

Rollback/migrate specifically verified:

- Rolled back `2026_06_18_000001_add_payroll_cashflow_metadata`.
- Re-ran `2026_06_18_000001_add_payroll_cashflow_metadata`.
- Final `migrate:status`: all migrations ran.

No `migrate:fresh` was used.

## Data Safety

| Question | Answer |
|---|---|
| Production DB touched? | No |
| Production SSH used? | No |
| Production migration run? | No |
| Production build/cache/restart run? | No |
| Backfill/apply command run? | No |
| `payroll:rebuild-salary-balances` production run? | No |
| `employees.balance` converted? | No |
| Opening balance created in production? | No |
| Permission assigned in production? | No |
| Local DB writes? | Yes, only local imported DB and local testing DBs |
| Dump/SQL committed? | No |
| `.env`, logs, vendor, node_modules, public/build committed? | No |

## Manual QA Status

Manual browser click-by-click UAT was not executed in this step. The current UAT was backend/controller-service flow on the latest imported dump plus automated tests/build.

Previous known limitation remains: browser click automation around JavaScript confirm/CDP for `Chốt lương` was limited. If BA requires human browser verification, run it in a normal browser against staging/local after the reconciliation finding is triaged.

## Bugs / Findings

1. **Payroll reconciliation false critical on cancelled payment cashflow**
   - Evidence: `TTPL000006` is cancelled and has a soft-deleted/cancelled cashflow `PCPL000006`, but reconciliation reports `PAYMENT_WITHOUT_CASHFLOW`.
   - Impact: production reconciliation may show false critical issues for legitimate cancelled payroll payments.
   - Suggested next action: hotfix reconciliation to include/correctly interpret cancelled cashflows and add regression test.

2. **Broad Customer/Supplier debt regression suite has 50 failures**
   - Evidence: Customer/Supplier command failed while Sapo debt parity 12/12 passed.
   - Impact: not payroll-specific, but blocks a fully green regression gate if BA requires the complete debt suite.
   - Suggested next action: separate triage into known/current-main failures vs new blockers before final production rollout.

## Go / No-Go Recommendation

| Area | Recommendation |
|---|---|
| Payroll core write flow on latest local dump | GO for logic confidence |
| Payroll automated regression | GO |
| Payroll reconciliation as final production gate | NO-GO until cancelled payment cashflow audit finding is fixed or accepted by BA |
| Broad debt Customer/Supplier regression | NO-GO until 50 failures are triaged |
| Production deploy/migrate/commands | NOT APPROVED by this report |

## Final Conclusion

Local Payroll Gate A with latest dump is **PARTIAL PASS**:

- Payroll core UAT: PASS.
- Payroll tests: PASS.
- Migration/rollback testing: PASS.
- Build/diff: PASS.
- Production safety: PASS, no production touched.
- Remaining blockers: payroll reconciliation cancelled-cashflow finding and broad Customer/Supplier regression failures.

