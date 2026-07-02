# PAYROLL P0 - PAID SALARY MUST NOT REMAIN EMPLOYEE DEBT

## 1. Mo ta loi

Bang luong da thanh toan du nhung cot "No va tam ung" cua nhan vien van con cong khoan luong da tra.

Case BA chi ra:

- `BL000007`
- `PL000043` - Nguyen Xuan Thanh - 13,132,580
- `PL000044` - Sa Dinh Cuong - 8,981,800

Truoc fix, ca hai payslip tren da co `paid_amount = total_salary` va `remaining = 0`, nhung salary ledger chi co `payroll_accrual` duong, thieu `salary_payment` am. Vi vay `salary_balance_cache` dang bang gross accrual thay vi net remaining.

## 2. Chuan nghiep vu

Chi phan con can tra moi duoc tinh vao "No va tam ung" hien tai.

Theo ledger:

```text
Chot phieu luong: +total_salary
Thanh toan luong: -payment_amount
So du hien tai = SUM(amount WHERE is_effective = true)
```

Neu payslip da tra du:

```text
+ payroll_accrual
- salary_payment
= 0
```

Dong luong da tra du duoc phep xuat hien trong timeline lich su, nhung khong duoc lam tang so du hien tai.

## 3. Case thuc te

### BL000007

| Payslip | Employee | Total | Paid | Remaining | Ket luan |
| --- | --- | ---: | ---: | ---: | --- |
| PL000043 | NV000026 - Nguyen Xuan Thanh | 13,132,580 | 13,132,580 | 0 | Da tra du, net thang 4 phai = 0 |
| PL000044 | NV000024 - Sa Dinh Cuong | 8,981,800 | 8,981,800 | 0 | Da tra du, net thang 4 phai = 0 |

### BL000008

| Payslip | Employee | Total | Paid | Remaining | Ket luan |
| --- | --- | ---: | ---: | ---: | --- |
| PL000049 | NV000026 - Nguyen Xuan Thanh | 4,257,865 | 0 | 4,257,865 | Con no, duoc hien tren cot no/tam ung |
| PL000050 | NV000024 - Sa Dinh Cuong | 3,181,654 | 0 | 3,181,654 | Con no, duoc hien tren cot no/tam ung |

## 4. Doi soat du lieu

Audit truoc backfill:

```json
{
  "payment_count": 2,
  "issue_count": 2,
  "missing_total": 22114380
}
```

Chi tiet:

| Payslip | Payment | Payment sum | Salary payment ledger | Missing |
| --- | ---: | ---: | ---: | ---: |
| PL000043 | 1 | 13,132,580 | 0 | 13,132,580 |
| PL000044 | 2 | 8,981,800 | 0 | 8,981,800 |

CashFlow:

```text
cash_flow_status = no_cash_flow_link
```

Day la du lieu payment cu co truoc khi ledger package hoan tat. P0 nay chi backfill salary ledger am de so du payroll net dung; khong tao cashflow moi.

## 5. Nguyen nhan goc

Case A - Backfill/migration cu chi tao `payroll_accrual` duong, khong tao `salary_payment` am.

Dau hieu tren Docker DB:

- `payslip.paid_amount > 0`
- `payslip.remaining = 0`
- `paysheet_payment.status = active`
- `employee_salary_ledger_entries` co `payroll_accrual`
- Thieu `employee_salary_ledger_entries.type = salary_payment`

Flow payment moi qua `SalaryPaymentService::pay()` da tao `salary_payment` am. Regression test xac nhan flow moi dung. Loi P0 nam o du lieu cu da co payment truoc ledger/backfill.

## 6. Sua gi

Them command audit read-only:

```bash
php artisan payroll:audit-paid-payslip-ledger
```

Them command backfill:

```bash
php artisan payroll:backfill-paid-payslip-ledger --dry-run
php artisan payroll:backfill-paid-payslip-ledger --apply
```

File da sua/them:

- `app/Console/Commands/AuditPaidPayslipLedger.php`
- `app/Console/Commands/BackfillPaidPayslipLedger.php`
- `tests/Feature/Payroll/PaidPaysheetShouldNotRemainEmployeeDebtTest.php`
- `docs/audit/PAYROLL-P0-PAID-SALARY-NOT-DEBT-FIX.md`

Backfill rule:

- Audit/backfill tinh theo `payslip.paid_amount`, khong chi theo payment document.
- Uu tien tao ledger theo `paysheet_payments.status = active`.
- Tao ledger `type = salary_payment`.
- `amount` la so am, toi da bang phan `paid_amount` con thieu ledger.
- Neu co payment: `reference_type = paysheet_payment`, `reference_id = paysheet_payment.id`, `idempotency_key = legacy:salary_payment:{payment_id}`.
- Neu legacy chi co `paid_amount` ma khong co payment document: dung fallback `reference_type = payslip`, `reference_id = payslip.id`, `idempotency_key = legacy:salary_payment:payslip:{payslip_id}`.
- Chay lai khong tao trung.
- Rebuild `salary_balance_cache` thong qua ledger service, khong sua cache tay.

## 7. Ket qua audit/backfill tren Docker DB

Dry-run:

```text
BL000007 / PL000043 / NV000026 / missing 13,132,580 / WOULD_CREATE
BL000007 / PL000044 / NV000024 / missing 8,981,800 / WOULD_CREATE
missing_total = 22,114,380
```

Apply:

```text
BL000007 / PL000043 / NV000026 / CREATED / legacy:salary_payment:1
BL000007 / PL000044 / NV000024 / CREATED / legacy:salary_payment:2
created_count = 2
```

Apply lan 2:

```text
candidate_count = 0
created_count = 0
missing_total = 0
```

Audit sau apply:

```json
{
  "payment_count": 2,
  "issue_count": 0,
  "missing_total": 0
}
```

## 8. So du sau fix

### Nguyen Xuan Thanh - NV000026

| Source | Amount | Net |
| --- | ---: | ---: |
| PL000043 payroll_accrual | +13,132,580 |  |
| PL000043 salary_payment | -13,132,580 | 0 |
| PL000049 payroll_accrual | +4,257,865 | 4,257,865 |

Ket qua:

```text
salary_balance_cache = 4,257,865
SUM ledger effective = 4,257,865
UI /employees?search=NV000026 = 4.257.865d
```

### Sa Dinh Cuong - NV000024

| Source | Amount | Net |
| --- | ---: | ---: |
| PL000044 payroll_accrual | +8,981,800 |  |
| PL000044 salary_payment | -8,981,800 | 0 |
| PL000050 payroll_accrual | +3,181,654 | 3,181,654 |

Ket qua:

```text
salary_balance_cache = 3,181,654
SUM ledger effective = 3,181,654
UI /employees?search=NV000024 = 3.181.654d
```

## 9. Ket qua test

P0 regression:

```text
php artisan test tests/Feature/Payroll/PaidPaysheetShouldNotRemainEmployeeDebtTest.php
PASS: 7 tests, 74 assertions
```

Payroll regression:

```text
php artisan test tests/Feature/Payroll
PASS: 89 tests, 426 assertions
```

Financial report/P&L regression:

```text
php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php
PASS: 16 tests, 210 assertions
```

Frontend build:

```text
npm run build
PASS
```

Ghi chu moi truong:

```text
PHP local co warning missing extension oci8_12c, oci8_19, pdo_firebird, pdo_oci.
Warning nay xuat hien o startup PHP va khong lam fail test/build.
```

## 10. Bao cao tai chinh

Khong double-count chi phi luong.

Rule hien tai:

- P&L/Financial Report lay chi phi luong tu `paysheets.status = locked` va `paysheets.total_salary`.
- CashFlow thanh toan luong bi loai khoi expense qua payroll classifier / excluded reference/category.
- Thanh toan luong la dong tien ra, khong phai chi phi luong lan hai.

Regression `test_financial_report_does_not_count_salary_payment_as_salary_expense_again` xac nhan:

```text
Chot luong 1,000,000
Thanh toan 1,000,000
P&L totalExpenses = 1,000,000
Khong thanh 2,000,000
```

## 11. Ket luan

```text
PASS
```

Co the tiep tuc UAT.

Dieu can nho khi UAT:

- BL000007 da tra du nen net contribution vao "No va tam ung" = 0.
- Nguyen Xuan Thanh con 4,257,865 la tu PL000049/BL000008 chua thanh toan.
- Sa Dinh Cuong con 3,181,654 la tu PL000050/BL000008 chua thanh toan.
- Nguon dung van la `SUM(amount WHERE is_effective = true)`, khong phai tong payroll_accrual gross.
