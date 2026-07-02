# HOTFIX 24.6G - Customer Debt Hide Auto Return Settlement

## Pham vi audit
- Module: customers, customer debt ledger, order returns.
- Man hinh: Khach hang > Cong no.
- Nghiep vu: hien thi cong no khi phieu tra hang da tra tien lai cho khach.
- Rui ro chinh: core ledger dung ve so du nhung UI hien adjustment noi bo thanh dong `Dieu chinh`, gay hieu nham.

## Source da kiem tra
- `app/Http/Controllers/CustomerController.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Models/CustomerDebt.php`
- `app/Models/Customer.php`
- `app/Models/OrderReturn.php`
- `resources/js/Pages/Customers/Index.vue`
- `tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php`
- `tests/Feature/OrderReturn/ApplyPaidReturnRefundDebtSettlementCommandTest.php`
- `tests/Feature/Customers/CustomerDebtHistoryReturnSettlementDisplayTest.php`

## Hien trang
- Backend ghi ledger 2 buoc cho return da tra tien khach:
  - `return = -returnTotal`
  - `adjustment = +paid_to_customer`
- `customers.debt_amount` dung sau settlement.
- `CustomerController@debtHistory` map tung row `customer_debts` thanh UI row, nen adjustment noi bo bi hien la `Dieu chinh`.
- Frontend `Customers/Index.vue` dang render `debtHistoryData[customer.id].entries`.

## Root cause
- Core ledger khong sai: adjustment duong la entry noi bo de tat toan so tien da tra khach va giu cong no dung.
- Loi nam o presentation: `entries` dang hien raw adjustment nhu mot giao dich user-facing.
- Kiot-style debt history can hien 1 dong `Tra hang`, voi balance sau khi da tinh settlement.

## Co anh huong du lieu dang co khong?
- Khong migration.
- Khong backfill.
- Khong update du lieu cu.
- Khong xoa ledger adjustment.
- Khong doi `customers.debt_amount`.
- Khong sua cashflow.
- Chi sua presentation/API response cua debt history.

## Phuong an an toan
- Giu `ledger_entries` raw cho audit/debug.
- Sua `entries` presentation-safe vi frontend dang render `entries`.
- Nhan dien auto return settlement adjustment khi:
  - `type = adjustment`
  - `amount > 0`
  - co `order_return_id` hoac `ref_code`
  - note chua `Tat toan tien da tra khach cho phieu tra` hoac `Bo sung tat toan tien da tra khach cho phieu tra`
- Chi hide adjustment neu co return ledger matching cung `order_return_id` hoac `ref_code`.
- Dung balance/debt_total cua settlement cuoi cung de hien tren dong return.
- Manual adjustment va settlement khong match return van hien binh thuong.

## Khong duoc lam
- Khong sua/xoa `customer_debts`.
- Khong rollback settlement adjustment.
- Khong sua `OrderReturnCreationService` de bo adjustment.
- Khong sua `CustomerDebtService` convention.
- Khong tao cashflow.
- Khong sua ton kho, serial, gia von, stock movement.

## Tests
| Lenh | Ket qua |
|---|---|
| `php artisan test tests/Feature/Customers/CustomerDebtHistoryReturnSettlementDisplayTest.php` | PASS - 6 tests, 31 assertions |
| `php artisan test tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php` | PASS - 10 tests, 60 assertions |
| `php artisan test tests/Feature/OrderReturn/ApplyPaidReturnRefundDebtSettlementCommandTest.php` | PASS - 9 tests, 42 assertions |
| `php artisan test tests/Feature/OrderReturn` | PASS - 53 tests, 213 assertions |
| `php artisan test tests/Feature/Customers` | PASS - 15 tests, 64 assertions |
| `php artisan test tests/Feature/POS/Step246BPosReturnExchangeTest.php` | PASS - 28 tests, 156 assertions |
| `php artisan test tests/Feature/CashFlows` | PASS - 6 tests, 23 assertions |
| `npm run build` | PASS - Vite built successfully |

Note: PHP CLI tren local van co startup warning thieu extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; day la warning moi truong, khong phai loi test hotfix.

## Manual QA
- Chua chay browser QA.
- Can kiem tra:
  - Return da tra tien day du chi hien 1 dong `Tra hang`, balance 0.
  - Return chua tra tien van hien credit am.
  - Return tra tien mot phan hien balance phan con lai.
  - Manual adjustment van hien `Dieu chinh`.
  - Cac phieu production: `TH2026052109324156`, `TH2026051414021949`, `TH2026050912010534`.

## Ket luan
- Dat ve logic presentation/API cho test muc tieu.
- Chua co migration/backfill/update du lieu.
- Chua ket luan production QA neu chua chay browser QA.
