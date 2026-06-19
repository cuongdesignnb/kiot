# PAYROLL PAYMENT CASHFLOW AND CANCELLED VISIBILITY HANDOVER

## 1. Muc tieu

Chuan hoa thanh toan luong sinh phieu chi/so quy va hien thi bang luong da huy dung logic audit.

## 2. Van de BA phat hien

- `BL000008` da tra 6.280.000 nhung khong thay phieu chi so quy.
- Bang luong huy can hien thi/an dung theo filter, khong xoa du lieu, khong tinh vao tong hien tai.

## 3. Moi truong local/Docker

- App: `http://localhost:8081`
- MySQL: `localhost:3319`
- phpMyAdmin: `http://localhost:8088`
- Containers: `kiotviet-app-clone`, `sales_mysql_test`, `sales_phpmyadmin`
- Docker app da rebuild sau khi sua code.
- Local Docker DB da co `cash_flows.branch_id` va `cash_flows.idempotency_key`.

## 4. Ket qua dieu tra logic hien tai

- Endpoint thanh toan luong: `POST /api/paysheets/{id}/pay` trong `PaysheetController::pay`.
- Service tao `paysheet_payment`: `SalaryPaymentService::pay`.
- Service tao salary ledger: `SalaryPaymentService::pay` goi `EmployeeSalaryLedgerService::append`.
- Truoc fix, `SalaryPaymentService::pay` co tao `CashFlow`, nhung logic nam inline, khong dung chung cho retry/backfill.
- `CashFlow` model truoc fix thieu `branch_id` va `idempotency_key` trong `$fillable`.
- Schema migration truoc fix chua dam bao co `cash_flows.branch_id` va `cash_flows.idempotency_key` tren DB moi.
- So quy lay du lieu tu bang `cash_flows` qua `CashFlowController@index`.
- So quy filter qua `FilterableIndex`; da bo sung `branch_id` vao scalar filters.
- Nguyen nhan rui ro khong hien CashFlow: payment moi/legacy co the thieu cash_flow, thieu branch_id, thieu idempotency, hoac retry/backfill tao logic khac nhau.

## 5. Business rules da ap dung

- Thanh toan luong hop le bat buoc co `paysheet_payment`, salary ledger am va `cash_flow` phieu chi.
- Moi `paysheet_payment` chi co toi da mot CashFlow hop le theo `idempotency_key = payroll_payment_cashflow:{payment_id}`.
- Retry payment/ensure khong tao CashFlow trung.
- Backfill bo qua payment cancelled/reversed va amount <= 0.
- CashFlow payroll active van hien trong so quy.
- P&L khong double-count CashFlow payroll; chi phi luong lay tu paysheet locked/payroll accrual.
- Bang luong cancelled khong tinh vao list/summary default, nhung xem lai duoc bang `status=cancelled`.
- Summary API paysheet dung cung filter voi list.
- Bang cancelled co `can_pay=false`, `can_cancel=false`, detail van xem duoc de audit.

## 6. File da sua

- `database/migrations/2026_06_18_000001_add_payroll_cashflow_metadata.php`
- `app/Models/CashFlow.php`
- `app/Services/PayrollPaymentCashFlowService.php`
- `app/Services/SalaryPaymentService.php`
- `app/Console/Commands/AuditPaymentCashFlow.php`
- `app/Console/Commands/BackfillPaymentCashFlow.php`
- `app/Http/Controllers/CashFlowController.php`
- `app/Http/Controllers/PaysheetController.php`
- `resources/js/Pages/Employees/Paysheets.vue`
- `tests/Feature/Payroll/PayrollPaymentCashFlowTest.php`
- `tests/Feature/Payroll/PaysheetCancelledVisibilityTest.php`
- `docs/audit/PAYROLL-PAYMENT-CASHFLOW-AND-CANCELLED-VISIBILITY-HANDOVER.md`

## 7. Logic tao CashFlow

- Service dung chung: `PayrollPaymentCashFlowService::ensureForPayment(PaysheetPayment $payment)`.
- Duoc goi khi tao payment moi va khi retry idempotency gap payment da ton tai.
- Duoc dung lai boi command backfill legacy.
- CashFlow payroll:
  - `type = payment`
  - `amount > 0`
  - `status = active`
  - `branch_id = paysheet.branch_id` neu schema co cot
  - `target_type = employee`
  - `reference_type = PaysheetPayment`
  - `reference_code = payment.code`
  - `idempotency_key = payroll_payment_cashflow:{payment_id}` neu schema co cot
- Huy payment goi `PayrollPaymentCashFlowService::cancelForPayment`, set CashFlow status cancelled va soft-delete de so quy active khong tinh.

## 8. Command audit/backfill

- `php artisan payroll:audit-payment-cashflow`
- `php artisan payroll:audit-payment-cashflow --paysheet=BL000008 --format=json`
- `php artisan payroll:backfill-payment-cashflow --dry-run`
- `php artisan payroll:backfill-payment-cashflow --apply`
- `php artisan payroll:backfill-payment-cashflow --paysheet=BL000008 --dry-run`
- `php artisan payroll:backfill-payment-cashflow --paysheet=BL000008 --apply`

Audit phan loai:

- `ok`
- `missing_cash_flow`
- `missing_ledger_and_cash_flow`
- `skipped_cancelled`
- `invalid_amount`

## 9. Ket qua audit/backfill local

Da verify bang automated tests:

- Dry-run: khong tao CashFlow.
- Apply: tao CashFlow cho payment thieu.
- Apply lan 2: khong tao trung.
- Audit sau backfill: `issue_count = 0`.

## 10. Cancelled paysheet visibility

- Default list: neu khong truyen `status`, API loai `cancelled`.
- Filter cancelled: `GET /api/paysheets?status=cancelled` hien bang da huy.
- Summary: clone cung query filter voi list, khong dung query rieng thieu filter.
- Action flags:
  - cancelled: `can_pay=false`, `can_cancel=false`, `status_label=Da huy`
  - locked chua co active payment: `can_pay=true`, `can_cancel=true`
  - locked co active payment: `can_pay=true`, `can_cancel=false`
- Detail audit: `GET /api/paysheets/{id}` van tra paysheet cancelled va payslips.

## 11. Financial report

- P&L khong double-count payment luong.
- CashFlow payroll bi loai khoi expense lan 2 qua `CashFlow::active()->nonPayrollForExpense()`.
- CashFlow payroll active van nam trong `CashFlow::active()->payrollRelated()` va so quy.
- `CashFlow::active()` tinh legacy `status = NULL` neu chua deleted, loai `cancelled` va `deleted_at != NULL`.

## 12. Test results

- `php artisan test tests/Feature/Payroll/PayrollPaymentCashFlowTest.php`: PASS, 6 tests, 34 assertions.
- `php artisan test tests/Feature/Payroll/PaysheetCancelledVisibilityTest.php`: PASS, 5 tests, 33 assertions.
- `php artisan test tests/Feature/Payroll`: PASS, 107 tests, 563 assertions.
- `php artisan test tests/Feature/Report/FinancialReportPayrollExpenseTest.php`: PASS, 9 tests, 120 assertions.
- `php artisan test tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php`: PASS, 7 tests, 90 assertions.
- `npm run build`: PASS.
- Docker app rebuild: PASS.
- `http://localhost:8081`: HTTP 200.

Ghi chu: PHP local co warning extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci` khong load duoc. Warning nay khong lam fail test.

## 13. File dirty ngoai scope

Khong stage/commit cac file dirty ngoai scope sau:

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/CustomerDebtDocumentTimelineService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `tests/Feature/ExampleTest.php`
- `app/Services/DebtTimelineCompatibilityService.php`
- `docs/audit/FINANCIAL-DEBT-REGRESSION-HANDOVER.md`
- `scratch/`

## 14. Production note cho User

- Agent khong chay production.
- User can pull code moi tren production/server.
- User tu chay `php artisan payroll:audit-payment-cashflow --format=json` truoc.
- Neu audit co issue va dry-run dung, User moi chay `php artisan payroll:backfill-payment-cashflow --apply`.
- Khong chay backfill production neu chua xem output dry-run.

## 15. Ket luan

PASS cho P0 local/Docker.

Co the de User tu trien khai production sau khi pull code, chay audit va dry-run backfill theo note tren.
