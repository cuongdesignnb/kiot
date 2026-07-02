# PAYROLL EMPLOYEE DEBT UI REDESIGN HANDOVER

## 1. Muc tieu

Lam gon UI "No va tam ung" va popup "Thanh toan luong" theo huong gan voi KiotViet: tab chi hien thi so du hien tai, action chinh va lich su phat sinh don gian; thao tac thanh toan/tam ung mo modal rieng.

## 2. Van de cu

```text
- Tab No va tam ung hien qua nhieu chi so ky thuat.
- Form tao tam ung nam truc tiep trong tab, chiem dien tich.
- Ledger table hien type ky thuat nhu payroll_accrual, cancel_reverse.
- Thanh toan luong chua co popup rieng theo nhan vien.
```

## 3. UI moi

Da dieu chinh trong man hinh nhan vien:

```text
- Card tong quan No hien tai/Tam ung vuot/Da tat toan.
- Dien giai so du duong, bang 0, am.
- Action ro: Thanh toan luong, Tao tam ung, Dieu chinh, Xuat file no luong.
- Chi tiet doi soat dua vao collapse.
- Form tao tam ung chuyen sang modal rieng.
- Bang lich su phat sinh dung nhan nghiep vu thay cho type tho.
- Trang thai ledger hien badge Hop le/Da dao/Dong dao.
```

Popup thanh toan luong:

```text
- Tieu de: Thanh toan luong cho {ten} ({ma}).
- Hien No hien tai, So tien, No sau.
- Hien Thoi gian, Phuong thuc, Ghi chu.
- Co bang chi tiet phieu luong con can tra.
- Nhap tong so tien thi tu phan bo FIFO vao phieu luong cu truoc.
- Cho sua tien tra tung phieu.
- Co nut Tao phieu chi va Tao phieu chi & in.
```

## 4. Business rules giu nguyen

```text
- Khong thay doi cong thuc salary_balance_cache.
- Khong sua employees.balance.
- Khong sua truc tiep ledger amount/balance.
- Thanh toan luong van tao salary_payment am qua SalaryPaymentService.
- Backend van validate paysheet locked, payslip thuoc nhan vien, amount khong vuot remaining.
- Tam ung van di qua SalaryAdvanceController/SalaryAdvanceService.
- Khong thay doi bao cao tai chinh/P&L/CashFlow.
```

## 5. API/data

Bo sung API ho tro UI thanh toan theo nhan vien:

```http
GET /api/employees/{employee}/salary-payment-preview
POST /api/employees/{employee}/salary-payments
```

Preview tra:

```text
- employee
- salary_balance
- open_payslips
- payment_methods
```

Submit payment gom cac payslip theo paysheet va goi lai `SalaryPaymentService::pay()`, nen van dung chung logic ledger/CashFlow/idempotency hien co.

## 6. Test results

Da chay trong qua trinh fix:

```text
php -l app/Http/Controllers/EmployeeSalaryPaymentController.php
Result: PASS

npm run build
Result: PASS
```

```text
php artisan test tests/Feature/Payroll
Result: PASS, 114 tests, 610 assertions

php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php
Result: PASS, 9 tests, 120 assertions

php artisan test tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php
Result: PASS, 7 tests, 90 assertions

npm run build
Result: PASS
```

Luu y: lan dau chay cac suite report song song voi payroll lam DB test chung bi tranh chap schema (`sales_test.users`/`role_id`). Da rerun tuan tu tung suite va tat ca PASS.

## 7. File thay doi

```text
app/Http/Controllers/EmployeeSalaryPaymentController.php
routes/api.php
resources/js/Pages/Employees/Index.vue
docs/audit/PAYROLL-EMPLOYEE-DEBT-UI-REDESIGN-HANDOVER.md
```

## 8. Production note

User pull code moi, build lai assets va clear cache neu server dang cache route/config/view:

```bash
npm run build
php artisan optimize:clear
```

Neu chay trong Docker dev, can dam bao container web dang dung build moi hoac restart/rebuild frontend asset theo cach deploy hien tai.

## 9. Ket luan

PASS.
