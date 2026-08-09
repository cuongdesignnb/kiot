# P0 — Đồng bộ thanh toán lương giữa Nhân viên và Bảng lương

## Phạm vi và an toàn

Branch này được tạo từ `production-customer-group` tại:

```text
BASE_SHA=7c2b7adf012e6f02252799c1e700a090d8812086
```

Không truy cập, không chạy lệnh và không ghi dữ liệu production trong quá
trình triển khai hotfix. Không có migration, backfill production hay thay đổi
công thức công nợ/tạm ứng trong PR này.

## Root cause / incident evidence

Hai màn hình đều đã có đường dẫn thanh toán, nhưng trước hotfix việc kiểm tra
tổng allocation, kiểm tra semantic identity của ledger và đối soát theo chứng
từ chưa dùng cùng một contract đủ chặt. Legacy document backfill còn dựa vào
`idempotency_key`, nên một key cũ khác vẫn có thể làm mất nhận diện ledger
accrual/payment theo đúng chứng từ.

Operator audit production đã ghi nhận 15 payslips đã chốt, trong đó 4
`payroll_accrual` bị thiếu:

| Nhân viên | Bảng lương | Phiếu lương | Số tiền |
|---|---|---|---:|
| NV000026 | BL000006 | PL000040 | 12,200,538 |
| NV000026 | BL000007 | PL000043 | 13,132,580 |
| NV000024 | BL000006 | PL000041 | 8,738,077 |
| NV000024 | BL000007 | PL000044 | 8,981,800 |
| **Tổng** |  |  | **43,052,995** |

Production repair is required, but was not run by this task:

```text
PRODUCTION_REPAIR_REQUIRED=YES
PRODUCTION_REPAIR_RUN=NO
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
```

## Contract sau hotfix

`SalaryPaymentService::pay(...)` là persistence engine dùng chung cho:

```text
POST /api/paysheets/{paysheet}/pay
POST /api/employees/{employee}/salary-payments
```

Mỗi request chạy trong transaction và khóa paysheet/payslip trước khi ghi
payment, cashflow, `salary_payment` ledger, payslip settlement, paysheet totals
và cache số dư qua `EmployeeSalaryLedgerService`.

Employee payment phải có danh sách `payments[]`, mỗi dòng có `payslip_id` và
`amount`. Tổng các dòng phải bằng `amount` của request. Không cho phép
employee-level payment không gắn payslip. UI phân bổ mặc định FIFO theo
`paysheet.period_start ASC, payslip.id ASC`; manual allocation phải dương và
không vượt remaining của từng payslip.

Request bị từ chối với HTTP 422 và không mutation nếu:

- tổng amount không khớp allocation;
- vượt remaining;
- paysheet chưa locked hoặc đã cancelled;
- payslip thuộc employee khác;
- trùng payslip trong cùng request.

Sau thành công, màn hình Employee refresh ledger/advances và danh sách
employee; màn hình Paysheet refresh list/detail/history/summary. Không dùng
stored salary projection để thay thế canonical ledger.

## Semantic ledger backfill

`payroll:migrate-salary-ledger --backfill-documents` mặc định là dry-run.
Nhận diện ledger theo semantic identity, không theo idempotency key:

```text
accrual:
employee_id + paysheet_id + payslip_id + payroll_accrual
+ reference_type=payslip + reference_id=payslip.id + is_effective=true

salary payment:
employee_id + paysheet_id + payslip_id + salary_payment
+ reference_type=paysheet_payment + reference_id=payment.id + is_effective=true
```

Classification gồm `EXACT`, `MISSING`, `AMOUNT_MISMATCH`, `DUPLICATE` và
`ZERO_SALARY` cho accrual. Chỉ `MISSING` được tạo khi `--apply`; mismatch hoặc
duplicate làm apply fail-closed trước khi ghi bất kỳ row nào. Accrual lịch sử
dùng `paysheet.locked_at`, fallback `payslip.updated_at`, tuyệt đối không dùng
`now()`. Repair chỉ append ledger qua service, không tạo payment/cashflow và
không cập nhật payslip/paysheet.

## Reconciliation

GET `/api/payroll/reconciliation` vẫn read-only và bổ sung semantic document
issues:

```text
MISSING_PAYROLL_ACCRUAL
PAYROLL_ACCRUAL_AMOUNT_MISMATCH
DUPLICATE_PAYROLL_ACCRUAL
MISSING_SALARY_PAYMENT_LEDGER
SALARY_PAYMENT_LEDGER_MISMATCH
DUPLICATE_SALARY_PAYMENT_LEDGER
PAYSLIP_PAID_AMOUNT_MISMATCH
PAYSLIP_REMAINING_MISMATCH
PAYSHEET_TOTAL_PAID_MISMATCH
PAYSHEET_TOTAL_REMAINING_MISMATCH
```

Đối soát không rebuild cache, không sửa ledger và không sửa chứng từ.

## Files changed

```text
app/Console/Commands/MigrateSalaryLedger.php
app/Http/Controllers/EmployeeSalaryPaymentController.php
app/Http/Controllers/PaysheetController.php
app/Services/PayrollDocumentParityService.php
app/Services/PayrollReconciliationService.php
app/Services/SalaryPaymentService.php
resources/js/Pages/Employees/Index.vue
resources/js/Pages/Employees/Paysheets.vue
tests/Feature/Payroll/PayrollCrossScreenSettlementParityTest.php
tests/Feature/Payroll/PayrollSemanticBackfillTest.php
tests/Feature/Payroll/PayrollReconciliationDocumentParityTest.php
```

## Test evidence

Test evidence is recorded after running on disposable MySQL 8 and MariaDB
10.11 databases. The required fixture is 15 locked payslips, 11 exact accruals
and 4 missing accruals totaling 43,052,995. Expected dry-run/apply evidence:

```text
DRY_RUN_MISSING_ACCRUAL_COUNT=4
DRY_RUN_MISSING_ACCRUAL_TOTAL=43052995
DRY_RUN_MISSING_SALARY_PAYMENT_COUNT=0
FIRST_APPLY_ACCRUAL_ROWS=4
FIRST_APPLY_PAYMENT_ROWS=0
SECOND_APPLY_ACCRUAL_ROWS=0
SECOND_APPLY_PAYMENT_ROWS=0
PAYMENT_DOCUMENTS_CREATED=0
CASHFLOW_ROWS_CREATED=0
```

The automated regression coverage is:

```text
PayrollCrossScreenSettlementParityTest
PayrollSemanticBackfillTest
PayrollReconciliationDocumentParityTest
PaidPaysheetShouldNotRemainEmployeeDebtTest
PaysheetLockEmployeeListBalanceRegressionTest
PayrollQaApiTest
SalaryLedgerFlowTest
PayrollPaymentCashFlowTest
```

Final disposable QA evidence:

```text
MYSQL8_MIGRATE_FRESH=PASS
MYSQL8_PAYROLL_SUITE=127 tests / 697 assertions PASS
MYSQL8_TARGETED_PARITY_SUITE=37 tests / 257 assertions PASS
MARIADB1011_TARGETED_PARITY_SUITE=37 tests / 257 assertions PASS
MARIADB1011_PAYROLL_SUITE=127 tests / 697 assertions PASS
MYSQL8_AND_MARIADB_FIXTURE=PASS
PAYROLL_LEDGER_KIOTVIET_FLOW_TEST=NOT_PRESENT_IN_REPOSITORY
PINT_CHANGED_PHP=PASS
PHP_LINT=PASS
FRONTEND_BUILD=PASS
DIFF_CHECK=PASS
SECRET_SCAN=PASS
DEBUG_OUTPUT_SCAN=PASS
```

The four-missing-accrual semantic fixture passed dry-run/apply/idempotency
coverage with no payment or cashflow creation. The isolated browser fixture
also passed the cross-screen settlement flow below.

## Manual QA checklist

Authenticated local browser QA only, using a disposable database and a
managed/headless browser; no user Chrome and no production session.

- [ ] Employee detail → Thanh toán lương creates the selected FIFO allocations.
- [ ] Paysheet → Thanh toán lương shows the same payment, ledger and remaining.
- [ ] Paysheet payment reduces employee salary debt after refresh.
- [ ] Employee payment refreshes ledger, advances and employee list.
- [ ] Amount/allocation mismatch and overpayment show Vietnamese validation and
      preserve payment, cashflow, payslip and paysheet state.
- [ ] Calculated/cancelled sheet, wrong employee and duplicate payslip are
      rejected without partial mutation.
- [ ] Reconciliation page reports semantic document issues and remains
      read-only.

## Browser evidence

Browser engine: Playwright-managed Chromium through the isolated local browser
session. User Chrome and production were not accessed.

```text
BROWSER_ENGINE=PLAYWRIGHT_MANAGED_CHROMIUM
AUTHENTICATED_LOCAL_LOGIN=PASS
EMPLOYEE_PAYMENT_UI=PASS
EMPLOYEE_DEBT_AFTER_PAYMENT=0
PAYSLIP_PAYMENT_HISTORY_MATCH=PASS
PAYSHEET_PAID_TOTAL_MATCH=PASS
PAYSHEET_REMAINING_AFTER_PAYMENT=0
EMPLOYEE_LEDGER_REFRESH=PASS
PAYSHEET_LIST_DETAIL_HISTORY_REFRESH=PASS
USER_CHROME_ACCESSED=NO
PRODUCTION_ACCESSED=NO
```

Expected: paying the locked `PL-BROWSER` payslip for 1,500,000 from the
employee detail screen creates one payment and leaves employee debt at 0; the
paysheet screen shows the same employee, payslip and amount.

Actual: the authenticated UI showed `NV-BROWSER` debt changing from 1,500,000
to 0, listed `TTPL000003` and `PL-BROWSER` in the employee ledger, and showed
`BL-BROWSER` as 1,500,000 paid and 0 remaining. Its payment history showed
`PL-BROWSER`, 1,500,000, and `Hợp lệ`.

Result: PASS. The managed browser session was closed after evidence capture.

## Data safety statement

No migration, schema change, backfill, production command, production database
connection or production data mutation is part of this work. The only repair
path is an explicit operator-run command on an approved disposable/restore
database, after dry-run evidence and mismatch/duplicate review. It appends
historical ledger rows only and uses service-driven balance rebuilds.

## Rollback plan

Before release, keep the branch and PR available for rollback. If the hotfix is
not accepted, revert the PR commit(s) through the normal reviewed Git workflow;
do not delete payroll documents or reverse production ledger rows blindly.
For any production repair, stop before apply if the semantic audit finds a
mismatch or duplicate, preserve the evidence, and perform a separately
approved ledger correction. Production deployment and repair remain operator
controlled and are outside this PR task.
