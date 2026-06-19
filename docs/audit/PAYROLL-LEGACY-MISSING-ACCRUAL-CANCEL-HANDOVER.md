# PAYROLL LEGACY MISSING ACCRUAL CANCEL HANDOVER

## 1. Muc tieu

Cho phep huy an toan bang luong legacy da `locked` nhung thieu ledger `payroll_accrual`.

Truong hop nay khong du dieu kien huy theo luong chuan vi khong co dong `payroll_accrual` goc de dao. Giai phap da trien khai la legacy zero-net reversal:

```text
legacy payroll_accrual  +total_salary
cancel_reverse          -total_salary
net                      0
```

Muc tieu la huy duoc paysheet cu, co audit trail ro rang, khong lam thay doi net cong no nhan vien va khong tao CashFlow moi.

## 2. Case production

Case can ho tro sau khi deploy:

```text
paysheet_code          BL000008
status                 locked
payment_status         unpaid
total_salary           29,654,710
total_paid             0
total_remaining        29,654,710
payslip_count          4
active_payment_count   0
payroll_accrual_count  0
salary_payment_count   1 reversed
```

Ly do truoc fix:

```text
missing_payroll_accrual
```

Loi UI/API truoc fix:

```text
Khong tim thay payroll_accrual de dao. Can doi soat ledger truoc khi huy bang luong.
```

## 3. Nguyen nhan

Du lieu bang luong cu bi lech lich su: paysheet da chot nhung khong co ledger `payroll_accrual` lien ket voi cac payslip.

Luong huy chuan yeu cau phai co `payroll_accrual` goc de tao `cancel_reverse`. Neu chi doi `status = cancelled` thi mat lich su ke toan; neu chi tao mot dong am thi khong co dong goc doi ung va lam ledger kho doi soat.

## 4. Business rules

Da trien khai cac rule chinh:

```text
BR01. Khong cho huy legacy paysheet neu con active payment.
BR02. Khong cho huy legacy paysheet neu paysheet da cancelled.
BR03. Khong cho huy legacy paysheet neu paysheet chua locked.
BR04. Khong cho huy legacy paysheet neu khong co payslip.
BR05. Locked paysheet thieu payroll_accrual khong duoc chi doi status sang cancelled.
BR06. Legacy cancel phai tao cap ledger zero-net: payroll_accrual duong va cancel_reverse am.
BR07. Net theo tung payslip sau legacy cancel bang 0.
BR08. Ledger moi phai tham chieu ro den payslip/paysheet.
BR09. Legacy payroll_accrual co marker ro qua code/note/idempotency_key.
BR10. Khong sua employees.balance.
BR11. Khong sua tay salary_balance_cache.
BR12. Balance/cache chi thay doi qua EmployeeSalaryLedgerService.
BR13. salary_balance_cache khong tang sai sau legacy cancel.
BR14. Paysheet sau huy co status = cancelled.
BR15. Paysheet cancelled khong tinh vao P&L payroll expense.
BR16. Paysheet cancelled khong tinh vao default list/summary hien tai.
BR17. Legacy cancel idempotent, retry khong tao trung ledger.
BR18. Co ActivityLog khi huy legacy paysheet.
BR19. Audit command nhan dien ro legacy mode.
BR20. Khong stage/commit file dirty ngoai scope.
```

## 5. Logic legacy cancel

Dieu kien ap dung:

```text
- paysheet.status = locked
- payslip_count > 0
- active_payment_count = 0
- payroll_accrual_count effective = 0
- chua co legacy accrual idempotency_key cho paysheet/payslip do
```

Voi moi payslip co `total_salary > 0`, service tao:

```text
type             payroll_accrual
amount           +payslip.total_salary
status           reversed sau khi goi reverse()
is_effective     true
reference_type   payslip
reference_id     payslip.id
code             LEGACY-{payslip.code}
idempotency_key  legacy:paysheet_cancel:accrual:{paysheet_id}:{payslip_id}
```

Sau do tao dong dao:

```text
type             cancel_reverse
amount           -payslip.total_salary
status           valid
is_effective     true
code             HLEGACY-{payslip.code}
idempotency_key  legacy:paysheet_cancel:reverse:{paysheet_id}:{payslip_id}
```

Net effect:

```text
SUM(amount WHERE is_effective = true) theo tung payslip = 0
```

Sau khi huy:

```text
paysheet.status = cancelled
paysheet.payment_status = unpaid
paysheet.total_remaining = 0 sau recalculateTotals()
payslip.remaining = 0
payslip.payment_status = unpaid theo convention hien tai
```

## 6. API/UI thay doi

Cancel API tra them:

```json
{
  "mode": "legacy_missing_payroll_accrual"
}
```

Neu la luong chuan, `mode = standard`.

Audit command:

```bash
php artisan payroll:audit-paysheet-cancel BL000008 --format=json
```

Ky vong voi case legacy du dieu kien:

```json
{
  "can_cancel": "yes",
  "mode": "legacy_missing_payroll_accrual",
  "reason": "legacy_can_cancel_no_active_payment",
  "requires_legacy_zero_net_reversal": true
}
```

Neu con active payment:

```json
{
  "can_cancel": "no",
  "mode": "blocked",
  "reason": "has_active_payment"
}
```

Neu khong du dieu kien legacy:

```json
{
  "can_cancel": "no",
  "mode": "manual_audit_required",
  "reason": "legacy_missing_accrual_requires_manual_audit"
}
```

Thong diep loi backend khi thieu accrual nhung khong du dieu kien huy tu dong:

```text
Bang luong thieu payroll_accrual va khong du dieu kien huy tu dong. Vui long chay audit doi soat.
```

## 7. Financial impact

Ket qua tai chinh mong muon:

```text
- Khong lam tang salary_balance_cache net.
- Khong sua employees.balance.
- Khong phat sinh CashFlow khi huy legacy paysheet.
- Paysheet cancelled khong tinh vao P&L payroll expense.
- Ledger van giu dung bat bien: Balance = SUM(amount WHERE is_effective = true).
```

Dong goc legacy accrual va dong dao deu `is_effective = true`; net tri tieu nhau bang amount, khong dua `status = valid` lam dieu kien tinh so du.

## 8. Test results

Da chay va PASS:

```text
php artisan test tests/Feature/Payroll/LegacyPaysheetMissingAccrualCancelTest.php
Result: PASS, 7 tests, 47 assertions

php artisan test tests/Feature/Payroll
Result: PASS, 114 tests, 610 assertions

php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php
Result: PASS, 9 tests, 120 assertions

php artisan test tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php
Result: PASS, 7 tests, 90 assertions

npm run build
Result: PASS
```

Co mot lan chay song song hai report test bi fail do hai process cung dung DB test `kiot_payroll_e2e_local` va tranh chap schema. Da rerun tung suite rieng, deu PASS. Day la artifact moi truong test song song, khong phai loi logic payroll.

Full suite chua duoc bao PASS trong report nay vi phase P0 chi chay cac suite bat buoc theo BA request.

## 9. File dirty ngoai scope

Tai thoi diem dong goi, cac file sau dang dirty ngoai scope va khong duoc stage/commit trong P0 nay:

```text
app/Http/Controllers/CustomerController.php
app/Http/Controllers/SupplierController.php
app/Services/CustomerDebtDocumentTimelineService.php
app/Services/PartnerDebtLedgerService.php
app/Services/SupplierDebtDocumentTimelineService.php
tests/Feature/ExampleTest.php
app/Services/DebtTimelineCompatibilityService.php
docs/audit/FINANCIAL-DEBT-REGRESSION-HANDOVER.md
scratch/
```

## 10. Production note

Agent khong thao tac production.

Sau khi User pull code moi tren production/staging:

```bash
php artisan payroll:audit-paysheet-cancel BL000008 --format=json
```

Neu output:

```text
can_cancel = yes
mode = legacy_missing_payroll_accrual
reason = legacy_can_cancel_no_active_payment
requires_legacy_zero_net_reversal = true
```

thi User co the huy qua UI/API.

Sau khi huy, can kiem tra:

```text
BL000008.status = cancelled
BL000008.total_remaining = 0
Co legacy payroll_accrual + cancel_reverse cho 4 payslip
Net cua 4 payslip = 0
salary_balance_cache khong tang sai
Khong co CashFlow moi tu thao tac huy legacy paysheet
```

## 11. Ket luan

PASS.

Co the de User xu ly production sau khi pull code va audit tra ve `legacy_missing_payroll_accrual` dung nhu ky vong. Khong duoc huy bang SQL tay, khong chi doi status, khong tao dong am don le.
