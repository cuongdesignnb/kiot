# PAYROLL KIOTVIET EMPLOYEE PAYMENT REAL SCENARIO REPORT

## 1. Moi truong test

| Hang muc | Gia tri |
| --- | --- |
| Branch | `feature/employee-debt-advance-expand-ui` |
| Commit luc chay test | `5d7551290ca20ec918acbe3200b627052089acfd` |
| APP_ENV | `testing` |
| Database | `kiot_payroll_e2e_local` |
| Database server | MySQL 8 Docker `sales_mysql_test`, port `3319` |
| Thoi gian chay | `2026-06-16 10:35:59 +07:00` |
| PHP | `8.2.29` |
| Nguon nghiep vu tham chieu | KiotViet - Quan ly tinh luong: https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-nhan-vien/quan-ly-tinh-luong/ |

Lenh test chinh:

```bash
php artisan test tests/Feature/Payroll/EmployeeSalaryPaymentRealScenarioTest.php
```

Ket qua:

```text
PASS - 1 test, 103 assertions
```

Lenh regression payroll:

```bash
php artisan test tests/Feature/Payroll/PayrollQaApiTest.php \
  tests/Feature/Payroll/PayrollLedgerKiotVietFlowTest.php \
  tests/Feature/Payroll/EmployeeSalaryPaymentFromProfileTest.php \
  tests/Feature/Payroll/EmployeeSalaryPaymentRealScenarioTest.php
```

Ket qua:

```text
PASS - 25 tests, 356 assertions
```

Frontend:

```bash
npm run build
PASS - 921 modules transformed
```

## 2. Tom tat nghiep vu

Kich ban nay chung minh bang code thuc te, khong dua vao UI:

```text
Chot bang luong lam cong ty no nhan vien.
Thanh toan lam giam no.
Tra du thi so du ve 0.
Chi them khi khong con no thi thanh tam ung luong.
```

Nguon su that ve so du khong doi:

```text
No va tam ung = SUM(employee_salary_ledger_entries.amount WHERE is_effective = true)
```

`employees.balance` la legacy balance va khong bi sua truc tiep trong flow nay.

## 3. Du lieu test

| Truong | Gia tri |
| --- | --- |
| Ma nhan vien | `NV-REAL-SCENARIO-001` |
| Ten nhan vien | `Test thuc te No Tam Ung` |
| employees.balance legacy | `777.777` |
| salary_balance_cache ban dau | `0` |
| Ma bang luong | `BL-REAL-001` |
| Ma phieu luong | `PL-REAL-001` |
| Tong luong | `1.000.000` |
| Trang thai bang luong ban dau | `calculated` |

Dieu kien ban dau da assert:

```text
ledger_rows = 0
paysheet_payments = 0
salary_advances = 0
cash_flows = 0
salary_balance_cache = 0
employees.balance = 777.777
```

## 4. Bang 6 buoc

| Buoc | Thao tac | Mode | Phieu luong | Phat sinh ledger | Da tra | Con can tra | No & Tam ung | Ket qua |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | --- |
| 1 | Chot bang luong | payroll_accrual | PL-REAL-001 | +1.000.000 | 0 | 1.000.000 | +1.000.000 | PASS |
| 2 | Tra 400k tu bang luong | salary_payment | PL-REAL-001 | -400.000 | 400.000 | 600.000 | +600.000 | PASS |
| 3 | Preview tu nhan vien | salary_payment | PL-REAL-001 | 0 | 400.000 | 600.000 | +600.000 | PASS |
| 4 | Tra 600k tu nhan vien | salary_payment | PL-REAL-001 | -600.000 | 1.000.000 | 0 | 0 | PASS |
| 5 | Preview sau tra du | salary_advance | Khong con | 0 | 1.000.000 | 0 | 0 | PASS |
| 6 | Chi them 500k | salary_advance | Khong con | -500.000 | 1.000.000 | 0 | -500.000 | PASS |

## 5. Y nghia tung buoc cho BA

### Buoc 1 - Chot bang luong

Chot bang luong tao ledger `payroll_accrual = +1.000.000`. Day la thoi diem cong ty phat sinh nghia vu phai tra luong cho nhan vien. So du No & Tam ung tang len `+1.000.000`.

### Buoc 2 - Tra 400k tu bang luong

Thanh toan tu bang luong tao `salary_payment = -400.000`. Khoan nay khong tao no moi, chi lam giam khoan phai tra tu `1.000.000` xuong `600.000`.

### Buoc 3 - Preview tu nhan vien

API `GET /api/employees/{employee}/salary-payment-preview` tra:

```text
mode = salary_payment
total_remaining = 600.000
payslips = [PL-REAL-001]
remaining_amount = 600.000
```

Dieu nay chung minh tab nhan vien va bang luong dang dung chung mot so `remaining` cua cung phieu luong.

### Buoc 4 - Tra 600k tu nhan vien

Thanh toan tiep tu chi tiet nhan vien tao `salary_payment = -600.000` va van tru vao `PL-REAL-001`. Sau buoc nay:

```text
paid_amount = 1.000.000
remaining = 0
No & Tam ung = 0
```

### Buoc 5 - Preview sau tra du

Preview sau khi tra du tra:

```text
mode = salary_advance
total_remaining = 0
payslips = []
```

Dieu nay chung minh he thong khong cho tra lai cung phieu luong da het remaining.

### Buoc 6 - Chi them 500k khi khong con no

Khi khong con no luong ma van chi them, he thong tao `salary_advance`, khong tao `salary_payment` moi:

```text
salary_advance = -500.000
No & Tam ung = -500.000
```

So du am the hien nhan vien da ung truoc/con phai thu lai tu nhan vien.

## 6. Snapshot DB cuoi test

| Chi so | Gia tri |
| --- | ---: |
| ledger_rows | 4 |
| paysheet_payments | 2 |
| salary_advances | 1 |
| salary_advance_applications | 0 |
| cash_flows | 3 |
| salary_balance_cache | -500.000 |
| effective_balance | -500.000 |
| employees.balance | 777.777 |
| payslip_paid_amount | 1.000.000 |
| payslip_remaining_amount | 0 |

Ledger cuoi scenario:

| Type | Amount | Y nghia |
| --- | ---: | --- |
| payroll_accrual | +1.000.000 | Chot luong, cong ty phai tra nhan vien |
| salary_payment | -400.000 | Tra mot phan tu bang luong |
| salary_payment | -600.000 | Tra phan con lai tu chi tiet nhan vien |
| salary_advance | -500.000 | Chi them khi het no, thanh tam ung |

## 7. Chung minh khong tao trung

Kich ban cuoi co dung so chung tu:

```text
paysheet_payments = 2
cash_flows = 3
salary_advances = 1
employee_salary_ledger_entries = 4
salary_advance_applications = 0
```

Khong co payment/CashFlow/ledger thua so voi cac nghiep vu that:

```text
1 payroll_accrual
2 salary_payment
1 salary_advance
```

Moi request tao chung tu dung `Idempotency-Key` rieng va test assert so luong idempotency key khong bi lap ngoai y muon.

## 8. Ket luan

PASS.

Kich ban code thuc te da chung minh:

- Chot bang luong lam No & Tam ung tang.
- Tra tu bang luong lam No & Tam ung giam.
- Tra tiep tu nhan vien dung cung phieu luong con can tra.
- Tra du thi No & Tam ung ve `0`.
- Preview sau tra du khong con phieu luong de tra lai.
- Chi tiep khi het no tao tam ung, so du am.
- Khong tao trung payment/CashFlow/ledger.
- Khong sua truc tiep `employees.balance`.
- `salary_balance_cache` khop `SUM(amount WHERE is_effective = true)`.
