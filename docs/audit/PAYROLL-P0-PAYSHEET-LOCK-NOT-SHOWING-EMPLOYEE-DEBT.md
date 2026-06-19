# PAYROLL P0 - PAYSHEET LOCK NOT SHOWING EMPLOYEE DEBT

## 1. Mo ta loi
User/BA chot bang luong tren UI nhung khi quay ve Danh sach nhan vien thi cot "No va tam ung" khong thay tang nhu ky vong.

P0 nay chi duoc ket luan PASS khi du 4 tang:

- Endpoint UI that su `PUT /api/paysheets/{id}/lock` tao ledger `payroll_accrual`.
- `employees.salary_balance_cache` duoc cap nhat tu ledger effective.
- Danh sach nhan vien tra dung field so du luong moi.
- Frontend bind cot "No va tam ung" vao `salary_balance_cache`, khong dung `employees.balance`.

## 2. Ky vong KiotViet
Chot bang luong phai phat sinh khoan cong ty phai tra nhan vien. Khoan nay phai xuat hien ngay o "No va tam ung" cua nhan vien. Thanh toan luong chi la buoc lam giam khoan phai tra nay.

Cong thuc bat bien:

```text
No va tam ung = SUM(amount WHERE employee_salary_ledger_entries.is_effective = true)
Cache hien thi = employees.salary_balance_cache
Legacy employees.balance khong duoc dung lam nguon hien thi cot moi.
```

## 3. Du lieu debug Docker

Database Docker: `sales_mysql_test`, database `kiot_db`.

Bang luong gan nhat:

```text
paysheet_id: 7
paysheet_code: BL000007
paysheet_status: locked
locked_at: 2026-06-16 09:55:40
```

Payslip trong bang luong:

| payslip_id | code | employee_id | total_salary | paid_amount | remaining | payment_status |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| 43 | PL000042 | 1 | 0 | 0 | 0 | paid |
| 44 | PL000043 | 3 | 13,132,580 | 13,132,580 | 0 | paid |
| 45 | PL000044 | 4 | 8,981,800 | 8,981,800 | 0 | paid |
| 46 | PL000045 | 6 | 0 | 0 | 0 | paid |
| 47 | PL000046 | 7 | 0 | 0 | 0 | paid |
| 48 | PL000047 | 8 | 0 | 0 | 0 | paid |

Employee/cache sau khi chot:

| employee_id | code | employees.balance | salary_balance_cache | Ghi chu |
| ---: | --- | ---: | ---: | --- |
| 3 | NV000026 | 0 | 13,132,580 | Co phat sinh luong, cot UI hien dung |
| 4 | NV000024 | 0 | 8,981,800 | Co phat sinh luong, cache tang dung |
| 7 | NV000012 | 50,000,000 | 0 | Legacy balance co du lieu, nhung payslip ky nay total_salary = 0 nen khong tang no luong moi |

Ledger:

| ledger_id | employee_id | code | type | amount | balance_after | is_effective | status |
| ---: | ---: | --- | --- | ---: | ---: | ---: | --- |
| 2 | 3 | PL000043 | payroll_accrual | 13,132,580 | 13,132,580 | 1 | valid |
| 3 | 4 | PL000044 | payroll_accrual | 8,981,800 | 8,981,800 | 1 | valid |

## 4. Snapshot truoc/sau

Regression test tao employee co:

```text
employees.balance legacy truoc lock: 777,777
salary_balance_cache truoc lock: 0
payslip.total_salary: 1,000,000
```

Sau khi goi endpoint UI that:

```text
PUT /api/paysheets/{id}/lock
paysheet.status: locked
ledger payroll_accrual.amount: +1,000,000
ledger.balance_after: 1,000,000
ledger.is_effective: true
employees.salary_balance_cache: 1,000,000
employees.balance legacy: 777,777, khong bi sua
Inertia /employees salary_balance_cache: 1,000,000
Inertia /employees salary_balance: 1,000,000
Inertia /employees salary_debt_amount: 1,000,000
```

## 5. Nguyen nhan goc

Ket qua debug cho thay core backend lock va cache da tao dung ledger/cache tren du lieu Docker. Diem can khoa lai la tang API/UI:

- API danh sach nhan vien truoc do chi dua `salary_balance_cache`, de UI/BA de nham voi legacy `employees.balance` khi doi soat.
- Fix da them alias ro nghia `salary_balance` va `salary_debt_amount`, deu tinh tu `salary_balance_cache`.
- Frontend cot "No va tam ung" da bind dung `employee.salary_balance_cache || 0`, khong dung `employee.balance`.
- Truong hop NV000012 co `employees.balance = 50,000,000` nhung payslip trong paysheet 7 la `total_salary = 0`, nen dung nghiep vu la khong tang `salary_balance_cache`.

Phan loai root cause:

```text
API khong tra field alias ro nghia cho cot no luong moi.
Khong phai backend khong tao ledger.
Khong phai cache khong cap nhat.
Khong phai frontend bind employees.balance.
```

## 6. File da sua

- `app/Http/Controllers/EmployeeController.php`
  - Them `salary_balance` va `salary_debt_amount` vao response danh sach nhan vien, lay tu `salary_balance_cache`.
  - Khi user khong co quyen `employee.view_salary_balance`, hide ca legacy/cache/alias.
- `tests/Feature/Payroll/PaysheetLockEmployeeListBalanceRegressionTest.php`
  - Them regression test endpoint lock that, cache, Inertia response, legacy balance, idempotency, va frontend binding.

## 7. Test regression

Da chay tren Docker MySQL `sales_mysql_test:3319`, database `kiot_payroll_e2e_local`.

```text
php artisan test tests/Feature/Payroll/PaysheetLockEmployeeListBalanceRegressionTest.php
PASS: 4 tests, 60 assertions
```

Regression payroll:

```text
php artisan test \
  tests/Feature/Payroll/PayrollQaApiTest.php \
  tests/Feature/Payroll/PayrollLedgerKiotVietFlowTest.php \
  tests/Feature/Payroll/EmployeeSalaryPaymentFromProfileTest.php \
  tests/Feature/Payroll/EmployeeSalaryPaymentRealScenarioTest.php \
  tests/Feature/Payroll/PaysheetLockEmployeeListBalanceRegressionTest.php

PASS: 29 tests, 416 assertions
```

Frontend:

```text
npm run build
PASS: vite built successfully
```

Ghi chu moi truong: PHP local co warning missing extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; warning nay xuat hien o startup PHP va khong lam fail test/build.

## 8. Bang chung sau sua

DB ledger payroll_accrual:

```text
employee_id=3, payslip=PL000043, amount=13,132,580, balance_after=13,132,580, is_effective=1
employee_id=4, payslip=PL000044, amount=8,981,800, balance_after=8,981,800, is_effective=1
```

Cache:

```text
employee_id=3, salary_balance_cache=13,132,580
employee_id=4, salary_balance_cache=8,981,800
employee_id=7, salary_balance_cache=0 vi payslip total_salary=0
```

API/Inertia regression:

```text
/employees?search=NV-P0-LOCK-001
employees.data.0.salary_balance_cache = 1,000,000
employees.data.0.salary_balance = 1,000,000
employees.data.0.salary_debt_amount = 1,000,000
employees.data.0.balance = 777,777 legacy, khong dung cho cot moi
```

UI Docker:

```text
URL: http://localhost:8081/employees?search=NV000026
Row: NV000026 - Nguyen Xuan Thanh
Cot "No va tam ung": 13.132.580d
Sau refresh trang: van 13.132.580d
```

Frontend binding:

```text
resources/js/Pages/Employees/Index.vue
Cot "No va tam ung" hien thi: formatCurrency(employee.salary_balance_cache || 0)
Khong co binding: formatCurrency(employee.balance ...)
```

## 9. Ket luan

```text
PASS
```

Co the tiep tuc test thanh toan/tam ung sau P0 nay.

Dieu can luu y khi BA test lai:

- Hay chon nhan vien co `payslip.total_salary > 0` trong bang luong vua chot.
- Neu nhan vien co `employees.balance` legacy nhung payslip ky vua chot bang 0, cot "No va tam ung" moi dung la khong tang.
- Nguon dung van la ledger effective va `salary_balance_cache`, khong phai `employees.balance`.
