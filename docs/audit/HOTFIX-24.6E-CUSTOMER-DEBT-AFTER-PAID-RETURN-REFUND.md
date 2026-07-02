# HOTFIX 24.6E - Customer Debt After Paid Return Refund

## Pham vi audit
- Module: returns, customer debt ledger, POS return exchange, cashflow.
- Man hinh: Khach hang > Cong no; POS > Tra hang / Doi hang.
- Nghiep vu: phieu tra hang da hoan tien cho khach khong duoc de cong no am.
- Rui ro chinh: sua sai ledger co the lam double adjustment trong POS exchange.

## Source da kiem tra
- `app/Services/OrderReturnCreationService.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/PosReturnExchangeService.php`
- `app/Services/ReturnTotalCalculator.php`
- `app/Services/InvoiceSaleService.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Models/Customer.php`
- `app/Models/CustomerDebt.php`
- `app/Models/OrderReturn.php`
- `app/Models/ReturnItem.php`
- `app/Models/CashFlow.php`
- `tests/Feature/OrderReturn`
- `tests/Feature/POS/Step246BPosReturnExchangeTest.php`
- `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php`

## Hien trang
- Backend return service truoc hotfix ghi `recordReturn(-returnTotal)` cho toan bo gia tri hang tra.
- Neu `paid_to_customer > 0`, service co tao `CashFlow` payment, nhung CashFlow khong cap nhat `customers.debt_amount`.
- POS exchange truoc hotfix co block adjustment rieng `+refundToCustomer` de bu phan hoan tien, nen khi dua settlement vao return service phai go block nay de tranh bu 2 lan.
- Database local dang dung: Docker container `sales_mysql_test`, local app DB `kiot_db`, test DB `sales_test`, port `3319`.

## Root cause
- `OrderReturnCreationService::recordCustomerImpact()` chi ghi ledger return am toan bo `total`.
- `OrderReturnCreationService::recordCashFlow()` chi tao phieu chi tien mat/chuyen khoan; CashFlow khong lam thay doi cong no khach.
- Vi vay return total 19.200.000 va `paid_to_customer` 19.200.000 co the de lai customer debt `-19.200.000` neu khong co ledger settlement duong.

## Debt convention
- `customers.debt_amount > 0`: khach dang no cua hang.
- `customers.debt_amount = 0`: het cong no.
- `customers.debt_amount < 0`: cua hang dang no/credit khach.
- `CustomerDebtService::recordSale()` ghi amount duong.
- `CustomerDebtService::recordReturn()` ghi amount am.
- `CustomerDebtService::recordPayment()` ghi amount am.
- `CustomerDebtService::recordAdjustment()` ghi signed amount giu nguyen.

## Phuong an an toan
- Chon Option A: dua paid-refund settlement vao `OrderReturnCreationService`.
- Khi tao return:
  - Ghi ledger `return = -returnTotal`.
  - Neu `paid_to_customer > 0`, ghi ledger `adjustment = +paid_to_customer`.
  - Debt cuoi = `-(returnTotal - paid_to_customer)`.
- Go adjustment rieng trong `PosReturnExchangeService` de khong double settle.
- Bo sung rollback khi huy return da tra tien: dao ca ledger return va ledger settlement da tra khach.
- Bo sung retry generate invoice code trong `InvoiceSaleService` vi POS exchange tao invoice moi lien tiep co the trung code theo giay.

## Co anh huong du lieu dang co khong?
- Hotfix code khong migration.
- Khong backfill.
- Khong update du lieu cu.
- Khong xoa debt/cashflow/return cu.
- Neu can sua phieu cu production, can owner xac nhan rieng sau backup va dry-run.

## Dry-run legacy returns
Command:

```bash
php artisan returns:audit-paid-refund-debt --dry-run
php artisan returns:audit-paid-refund-debt --dry-run --code=TH2026052109324156
```

Ket qua local cho `TH2026052109324156`:
- `kiot_db` hien khong co return code nay.
- SQL `returns WHERE code='TH2026052109324156'`: 0 row.
- Command theo code: 0 row, missing adjustment total 0.

Ket qua dry-run toan bo local `kiot_db`:

| Return | Customer | Total | Paid | Settlement hien co | Suggested missing |
|---|---|---:|---:|---:|---:|
| TH2026051414021949 | Nguyen Duy Khanh | 10.000.000 | 10.000.000 | 0 | 10.000.000 |
| TH2026050912010534 | DNGUYEN | 3.600.000 | 3.600.000 | 0 | 3.600.000 |

Tong dry-run local: 2 phieu, suggested missing adjustment 13.600.000. Chua apply.

## Tests
| Lenh | Ket qua |
|---|---|
| `php artisan test tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php` | PASS, 6 tests, 37 assertions |
| `php artisan test tests/Feature/OrderReturn/AuditPaidReturnRefundDebtCommandTest.php` | PASS, 3 tests, 8 assertions |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS, 28 tests, 156 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS, 39 tests, 146 assertions |
| `php artisan test tests/Feature/POS/Step246PosQuickReturnTest.php` | PASS, 15 tests, 39 assertions |
| `php artisan test tests/Feature/Customers` | PASS, 9 tests, 33 assertions |
| `php artisan test tests/Feature/CashFlows` | PASS, 6 tests, 23 assertions |
| `php artisan test tests/Feature/CustomerDebt` | PASS, 5 tests, 14 assertions |
| `php artisan test tests/Feature/Invoice` | PASS, 25 tests, 64 assertions; 1 skipped schema-related einvoice test |
| `php artisan test tests/Feature/Invoices` | PASS, 28 tests, 99 assertions; 1 skipped schema-related einvoice test |
| `npm run build` | PASS |

Ghi chu: PHP CLI tren may local co warning missing extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; cac lenh test/build van hoan tat.

## Manual QA
- Browser UI QA chua chay trong hotfix nay.
- Can QA tren browser truoc khi ket luan deploy production:
  - Return paid full: final debt 0.
  - Return unpaid: debt am bang tien chua hoan.
  - Return partial paid: debt am bang phan chua hoan.
  - Exchange equal value: debt 0, khong double adjustment.
  - Exchange cheaper and refunded: debt 0.

## Production data correction
- Khong duoc tu sua du lieu cu production trong hotfix code.
- Neu phieu `TH2026052109324156` tren production thieu settlement:
  - Can backup DB.
  - Chay dry-run production.
  - Xuat danh sach phieu, cashflow, ledger hien co.
  - Owner xac nhan truoc khi them ledger adjustment.
  - Can rollback SQL/plan rieng.

## Ket luan
- Logic giao dich moi da duoc sua de paid refund khong de lai debt am.
- POS exchange da tranh double adjustment bang cach go settlement rieng trong exchange service.
- Dry-run command da co va chi doc du lieu.
- Chua deploy production va chua sua du lieu cu.
- Chua dat de deploy production neu chua browser QA va chua xac nhan correction cho phieu cu production.
