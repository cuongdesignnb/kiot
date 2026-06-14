# PAYROLL LEDGER PACKAGE HANDOVER

## 1. Status

```text
Package implementation: PASS
Automated tests: PASS
Local isolated rehearsal: PASS WITH DATASET LIMITATION
Production database changed: NO
Final status: READY FOR BA REVIEW
```

Required invariant:

```text
Payroll balance = SUM(amount WHERE is_effective = true)
```

`status` represents lifecycle only and is not the balance predicate.

## 2. Repository State

```text
Branch: hotfix/payroll-standard-work-minutes-full-day
Base commit: 891d7071b261948f73d3e9f1b18c7310866fccc3
New commit created by this work: No
```

The worktree already contained the larger payroll QA package. This completion adds the rollout-facing service, commands, tests, and documentation without reverting existing changes.

## 3. Package Components

Core schema and models:

- `database/migrations/2026_06_13_000001_create_employee_salary_ledger_system.php`
- `database/migrations/2026_06_13_000002_allow_legacy_null_cash_flow_status.php`
- `app/Models/EmployeeSalaryLedgerEntry.php`
- `app/Models/SalaryAdvance.php`
- `app/Models/SalaryAdvanceApplication.php`

Services and commands:

- `app/Services/EmployeeSalaryLedgerService.php`
- `app/Services/PayrollLedgerService.php`
- `app/Console/Commands/MigrateSalaryLedger.php`
- `app/Console/Commands/RebuildSalaryBalances.php`

Opening-balance test:

- `tests/Feature/Payroll/PayrollLedgerOpeningBalanceTest.php`

`PayrollLedgerService` exposes:

```text
currentBalance(employeeId)
appendEntry(data)
rebuildEmployeeBalance(employeeId)
rebuildAllBalances()
```

Append and rebuild use the existing transaction and employee row-lock implementation. Rebuild changes only `balance_after`, `salary_balance_cache`, and the cache timestamp. It does not change ledger `amount` or legacy `employees.balance`.

## 4. Migration Command

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD \
  --employee-code=NV000012 \
  [--apply]
```

Stable idempotency key:

```text
payroll-opening-balance:employee:{id}:legacy-balance:{amount}:go-live:{date}
```

The command reports mode, employee, legacy balance, created/skipped result, effective ledger balance, rebuilt cache, duplicate count, and CashFlow/payment deltas.

Opening balance never creates a CashFlow, payment, salary advance, or advance application.

## 5. Rebuild Command

```bash
php artisan payroll:rebuild-salary-balances \
  [--employee-code=NV000012] \
  [--dry-run]
```

Dry-run calculates expected cache values without writing. Apply mode delegates to the transactional ledger service.

## 6. Automated Verification

```text
Opening balance targeted: 10 tests, 16 assertions, PASS
Payroll suite: 78 tests, 292 assertions, PASS
Full PHPUnit: 1214 tests, 6327 assertions, 5 skipped, PASS
Frontend: 920 modules transformed, PASS
```

Full command:

```bash
php -d memory_limit=1G vendor/bin/phpunit
```

The first `artisan test` full-suite attempt inherited a 128 MB child-process limit and stopped for memory. Direct PHPUnit with 1 GB passed completely. Host PHP emits optional OCI/Firebird extension warnings; they did not affect MySQL/MariaDB tests.

## 7. Restore Rehearsal

Local isolated database:

```text
Container: kiot_payroll_audit_mariadb
Database: kiot_payroll_restore_20260615_001835
Engine: MariaDB 10.11
Production database: Not used
```

Local source dump:

```text
File: kiot_db_2026-06-14_00-22-07_mysql_data_PZbx8.sql
Size: 3,375,688 bytes
SHA256: 2CBC51A4E8AAD2EC99A5EF437BE563D93CE54E755B86FB33A51D2ADC883EF598
```

Dataset counts before payroll migration:

```text
employees: 7
users: 5
cash_flows: 543
paysheets: 8
payslips: 37
paysheet_payments: 0
```

This is not the production backup dated June 15, 2026 described by BA, whose expected CashFlow count is 545 and SHA256 is `399a760ae933b147b3039228357a1467bc4817b7712c398ea95b61b88fcc5b71`. This rehearsal proves package behavior against the available production copy but must be repeated on the exact June 15 restore before production approval.

MariaDB required rehearsal-only connection setting:

```text
DB_COLLATION=utf8mb4_unicode_ci
```

Both payroll schema migrations ran successfully.

## 8. Dry-run Result

```text
Mode: DRY-RUN
Employee: NV000012
Legacy balance: 50,000,000
Opening balance: WOULD CREATE
Duplicate count: 0
CashFlow created: 0
Payment created: 0
Database write: No
```

## 9. Apply And Idempotency Result

First apply:

```text
Opening balance: CREATED
Created: 1
Effective balance: 50,000,000
Salary balance cache: 50,000,000
CashFlow created: 0
Payment created: 0
```

Second apply:

```text
Opening balance: SKIPPED
Created: 0
Skipped: 1
Duplicate rows: 0
CashFlow created: 0
Payment created: 0
```

## 10. SQL Verification

```text
NV000012 employees.balance: 50,000,000.00
NV000012 salary_balance_cache: 50,000,000
opening_balance rows: 1
opening_balance amount: 50,000,000
balance_after: 50,000,000
is_effective: 1
duplicate groups: 0
cash_flows before/after: 543 / 543
paysheet_payments before/after: 0 / 0
salary_advances after: 0
salary_advance_applications after: 0
```

Ignored raw evidence is under `storage/app/audit/payroll-restore-20260615-*`.

## 11. Remaining Gate

Before production apply:

1. Restore the exact June 15 production backup into an isolated database.
2. Confirm its SHA256 and expected count of 545 CashFlow rows.
3. Repeat schema migrate, dry-run, apply, second apply, and SQL verification.
4. Obtain BA/Owner approval for go-live date, cutoff, permissions, change window, and rollback owner.

## 12. Conclusion

```text
READY FOR BA REVIEW
NOT READY FOR PRODUCTION APPLY
```
