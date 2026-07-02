# HOTFIX 24.6E.1 - Cancel Legacy Paid Return Debt Settlement Guard

## Pham vi audit
- Module: order returns, customer debt ledger, cashflow.
- Man hinh: Khach hang > Cong no; phieu tra hang.
- Nghiep vu: huy phieu tra hang da tra tien cho khach.
- Rui ro chinh: phieu legacy truoc HOTFIX 24.6E co `paid_to_customer` nhung thieu ledger settlement duong; neu huy dua theo field nay se tao cong no am sai.

## Source da kiem tra
- `app/Http/Controllers/OrderReturnController.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/PosReturnExchangeService.php`
- `app/Services/ReturnTotalCalculator.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Models/Customer.php`
- `app/Models/CustomerDebt.php`
- `app/Models/OrderReturn.php`
- `app/Models/CashFlow.php`
- `tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php`
- `tests/Feature/OrderReturn/AuditPaidReturnRefundDebtCommandTest.php`
- `tests/Feature/POS/Step246BPosReturnExchangeTest.php`
- `docs/audit/HOTFIX-24.6E-CUSTOMER-DEBT-AFTER-PAID-RETURN-REFUND.md`

## Hien trang
- HOTFIX 24.6E da sua luong tao moi: return ghi `-returnTotal`, refund da tra khach ghi `+paid_to_customer`.
- `PosReturnExchangeService` khong con adjustment rieng `+refundToCustomer`, tranh double settlement.
- `OrderReturnController@cancel` truoc 24.6E.1 van dao settlement bang field `paid_to_customer`.
- Cach huy cu dung voi phieu moi co settlement ledger, nhung sai voi phieu legacy thieu settlement ledger.

## Root cause bo sung
- Khi huy return, source truth cua settlement da tra khach phai la ledger `customer_debts` type `adjustment` amount duong da ton tai theo `order_return_id` hoac `ref_code`.
- Field `returns.paid_to_customer` chi noi so tien da nhap tren phieu, khong chung minh da co ledger settlement duong.
- Neu phieu legacy co `paid_to_customer > 0` nhung thieu settlement ledger, huy theo field se tao adjustment am khong co entry duong tuong ung de dao.

## Debt convention
- `customers.debt_amount > 0`: khach dang no cua hang.
- `customers.debt_amount = 0`: het cong no.
- `customers.debt_amount < 0`: cua hang dang no/credit khach.
- `CustomerDebtService::recordSale()` ghi amount duong.
- `CustomerDebtService::recordReturn()` ghi amount am.
- `CustomerDebtService::recordPayment()` ghi amount am.
- `CustomerDebtService::recordAdjustment()` ghi signed amount giu nguyen.

## Fix
- Them guard chan huy lai cac return da co status cancelled/canceled/void/deleted.
- Truoc khi ghi ledger huy, tinh tong settlement duong da ton tai:
  - match `order_return_id = return.id` hoac `ref_code = return.code`;
  - `type = adjustment`;
  - `amount > 0`.
- Huy return luon ghi `+return.total` de dao ledger return am.
- Chi ghi settlement reversal am neu tong settlement duong da ton tai lon hon 0.
- Phieu legacy thieu settlement duong se khong bi ghi `-paid_to_customer` khi huy.
- Phieu moi hoan tien day du hoac mot phan se chi dao dung so settlement ledger da co.

## Co anh huong du lieu dang co khong?
- Khong migration.
- Khong backfill.
- Khong update du lieu cu.
- Khong xoa debt/cashflow/return cu.
- Code moi chi anh huong giao dich huy return tu sau khi deploy.
- Neu can sua phieu cu production, can backup, dry-run, owner xac nhan va hotfix correction rieng.

## Tests da chay
| Lenh | Ket qua |
|---|---|
| `php artisan test tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php` | PASS - 10 tests, 60 assertions |
| `php artisan test tests/Feature/OrderReturn/AuditPaidReturnRefundDebtCommandTest.php` | PASS - 4 tests, 10 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS - 44 tests, 171 assertions |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 28 tests, 156 assertions |
| `php artisan test tests/Feature/POS/Step246PosQuickReturnTest.php` | PASS - 15 tests, 39 assertions |
| `php artisan test tests/Feature/Customers` | PASS - 9 tests, 33 assertions |
| `php artisan test tests/Feature/CashFlows` | PASS - 6 tests, 23 assertions |
| `php artisan test tests/Feature/CustomerDebt` | PASS - 5 tests, 14 assertions |
| `php artisan test tests/Feature/Invoice` | PASS - 25 passed, 1 skipped schema-related einvoice test |
| `php artisan test tests/Feature/Invoices` | PASS - 28 passed, 1 skipped schema-related einvoice test |
| `npm run build` | PASS - Vite built successfully |

Note: PHP CLI tren may local van bao startup warning thieu cac extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; cac test tren van pass.

## Dry-run
Lenh local da chay:

```bash
php artisan returns:audit-paid-refund-debt --dry-run --code=TH2026052109324156
```

Ket qua local:
- Khong tim thay paid return thieu settlement cho code nay.
- Rows: 0.
- Suggested missing adjustment total: 0.

Chua chay dry-run production cho `TH2026052109324156` trong hotfix nay.

## Manual QA
- Browser QA chua chay.
- Cac case can QA tren staging/local browser:
  - Huy phieu moi da hoan du tien.
  - Huy phieu moi hoan mot phan.
  - Huy phieu legacy thieu settlement bang du lieu mo phong.
  - POS exchange khong double adjustment.

## Production readiness
- Code co the deploy sau khi owner chap nhan rollout hotfix logic.
- Chua du dieu kien ket luan data correction production.
- Phieu cu nhu `TH2026052109324156` neu can sua ledger cu van can owner xac nhan rieng sau dry-run production va backup DB.

## Ket luan
- Dat muc tieu code/test cho guard huy return legacy.
- Khong migration, khong backfill, khong update du lieu cu.
- Khi huy return, khong con dao settlement theo field `paid_to_customer` mot cach mu quang.
