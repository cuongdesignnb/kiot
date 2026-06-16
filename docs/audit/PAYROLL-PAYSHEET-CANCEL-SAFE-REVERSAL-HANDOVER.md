# PAYROLL PAYSHEET CANCEL SAFE REVERSAL HANDOVER

## 1. Muc tieu

Huy bang luong da chot an toan bang chung tu dao, khong xoa du lieu goc, khong lam sai No & Tam ung nhan vien va khong lam sai bao cao tai chinh.

## 2. Business rules da trien khai

- Khong xoa cung bang luong da chot.
- Chi bang luong `locked` moi duoc huy bang reversal.
- Bang luong `cancelled` goi lai se idempotent, khong tao reversal trung.
- Neu con `paysheet_payments.status = active` va `amount > 0` thi chan huy bang luong.
- Huy bang luong tao `cancel_reverse` cho tung `payroll_accrual` effective cua paysheet.
- Ledger goc `payroll_accrual` van giu lai, chuyen `status = reversed`, `is_effective = true`.
- Dong dao `cancel_reverse` cung `is_effective = true`.
- `salary_balance_cache` chi cap nhat qua `EmployeeSalaryLedgerService`, khong sua tay.
- `employees.balance` legacy khong bi sua.
- Bang luong `cancelled` khong duoc tinh vao payroll expense/P&L.
- Ly do, user va thoi gian thao tac duoc luu qua `ActivityLog` va metadata reversal.

## 3. API/UI da them

Endpoint:

```http
POST /api/paysheets/{paysheet}/cancel
PUT /api/paysheets/{paysheet}/cancel
```

Request toi thieu:

```json
{
  "reason": "Huy bang luong tao nham UAT"
}
```

Response thanh cong:

```json
{
  "success": true,
  "message": "Da huy bang luong",
  "paysheet": {},
  "reversed_entries_count": 1
}
```

UI:

- Danh sach bang luong chi hien nut huy khi `status = locked`.
- Chi tiet bang luong co nut `Huy bang luong` khi `status = locked`.
- Modal dung `CancelReasonModal`, reason toi thieu 10 ky tu.
- Modal canh bao day la huy bang chung tu dao, khong xoa du lieu.

## 4. Logic reversal

Khi huy:

```text
payroll_accrual goc: +total_salary, status = reversed, is_effective = true
cancel_reverse:      -total_salary, status = valid,    is_effective = true
```

Invariant:

```text
Balance = SUM(amount WHERE is_effective = true)
```

Idempotency key:

```text
cancel:paysheet:{paysheet_id}:payroll_accrual:{ledger_id}
```

Sau huy:

- `paysheet.status = cancelled`
- `paysheet.payment_status = unpaid`
- `payslip.paid_amount = 0`
- `payslip.applied_advance = 0`
- `payslip.remaining = 0`
- `salary_balance_cache` khop SUM ledger effective

## 5. Case co payment

Neu paysheet con payment active:

```text
can_cancel = no
reason = has_active_payment
```

API tra 422 va khong tao `cancel_reverse`.

User/dev phai huy payment truoc bang flow hop le:

```text
salary_payment: -payment_amount
cancel_reverse payment: +payment_amount
```

Sau khi khong con payment active, moi huy bang luong:

```text
payroll_accrual: +salary
cancel_reverse payroll: -salary
```

## 6. Audit command

Command read-only:

```bash
php artisan payroll:audit-paysheet-cancel BLxxxxx
php artisan payroll:audit-paysheet-cancel BLxxxxx --format=json
```

Output gom:

```text
paysheet_code
paysheet_status
total_salary
paid_amount
remaining_amount
payslip_count
active_payment_count
payroll_accrual_count
salary_payment_count
cancel_reverse_count
employee_count
can_cancel
reason
```

CAN_CANCEL = yes khi:

- paysheet `locked`
- chua `cancelled`
- `active_payment_count = 0`
- co `payroll_accrual_count > 0`

CAN_CANCEL = no khi:

- `already_cancelled`
- `not_locked`
- `has_active_payment`
- `missing_payroll_accrual`

## 7. Test results

Da them:

```text
tests/Feature/Payroll/PaysheetCancelReversalTest.php
```

Ket qua:

```text
php artisan test tests/Feature/Payroll/PaysheetCancelReversalTest.php
PASS: 7 tests, 70 assertions
```

Cases:

- Huy bang luong da chot, chua thanh toan: PASS
- Chan huy bang luong con payment active: PASS
- Huy payment roi huy bang luong: PASS
- Idempotent khong tao reversal trung: PASS
- Bang cancelled khong tinh payroll expense: PASS
- Audit command can_cancel yes: PASS
- Audit command chan active payment: PASS

Payroll regression:

```text
php artisan test tests/Feature/Payroll
PASS: 96 tests, 496 assertions
```

Financial regression:

```text
php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php
PASS: 16 tests, 210 assertions
```

Frontend build:

```text
npm run build
PASS
```

## 8. Financial report

Bang luong `cancelled` da bi loai khoi chi phi luong/P&L.

Khong double-count:

- Payroll expense lay tu paysheet locked.
- Payment CashFlow khong tinh them vao expense.
- Huy bang luong chua thanh toan khong tao CashFlow.

## 9. Production runbook

1. Backup DB truoc khi thao tac production.
2. Chay audit:

```bash
php artisan payroll:audit-paysheet-cancel BLxxxxx --format=json
```

3. Neu `can_cancel = no` va `reason = has_active_payment`:

- khong huy bang luong;
- huy payment truoc bang flow hop le;
- audit lai.

4. Neu `can_cancel = yes`:

- huy qua UI chi tiet/danh sach bang luong; hoac
- goi API:

```http
POST /api/paysheets/{id}/cancel
```

5. Kiem tra sau huy:

- `paysheet.status = cancelled`
- co `cancel_reverse` cho cac `payroll_accrual`
- ledger goc van ton tai
- `salary_balance_cache = SUM ledger effective`
- nhan vien khong con no tu bang luong da huy
- financial report khong tinh bang luong da huy

## 10. Ket luan

PASS.

Co the dung de huy bang luong tao nham production sau khi da backup DB va audit `can_cancel = yes`.
