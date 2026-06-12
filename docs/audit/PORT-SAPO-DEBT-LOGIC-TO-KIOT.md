# Port/Audit Logic Cong No Sapo Sang Kiot

## Tham chieu

- Repo nguon: `cuongdesignnb/sapo`
- Branch nguon: `codex/kiotviet-payments-partner-merge`
- Chuoi logic nen: `2321a3e`
- Commits bat buoc: `a08fe9b`, `540019e`, `693b523`, `570f121`
- Repo dich: `cuongdesignnb/kiot`
- Base dich: `origin/main` tai `1822437`
- Branch lam viec: `codex/port-sapo-debt-logic-to-kiot`

## Nguyen tac audit

- Khong copy de controller, frontend hoac toan bo repo Sapo.
- Khong tao migration trung. Truoc khi tao migration phai search toan bo
  `database/migrations` cho `customer_payment_allocations`, `partner_merges`,
  `merged_into_id`, `merged_at` va merge snapshots.
- Khong tao model trung. Truoc khi tao model phai search toan bo `app/Models`
  cho `CustomerPaymentAllocation` va `PartnerMerge`.
- Neu schema/model gan giong nhung khac contract thi dung va bao BA.
- Khong backfill, cleanup, update hay xoa du lieu cu.
- Khong sua stock movement, ton kho, costing, gia von, warranty, repair hoac serial/IMEI.

## Ma tran parity ban dau

| Khu vuc | File Kiot | Trang thai | Logic Sapo tuong ung | Ket luan | Can sua |
|---|---|---|---|---|---|
| Signed debt | `app/Services/CustomerDebtService.php` | Xung dot | `a08fe9b` | `recordSale()` va reversal con dung `abs()`, chua co signed invoice helpers | Co |
| Invoice signed effect | `InvoiceSaleService`, `InvoiceUpdateService`, `InvoiceController`, `OrderController` | Co mot phan | `a08fe9b` | Cac call site van dua balance am qua method unsigned hoac adjustment rai rac | Co |
| Payment allocation | `CustomerController::debtPayment()` | Co mot phan | `2321a3e`, `540019e` | Co auto/manual allocation inline, nhung CashFlow chi ghi phan allocated va khong co provenance allocation | Co |
| Payment service/model | `app/Services`, `app/Models` | Thieu | `CustomerPaymentService`, `CustomerReceivableInvoiceService`, `CustomerPaymentAllocation` | Search khong co class/model tuong duong | Co |
| CashFlow cancellation | `CashFlowController::destroy()` | Co mot phan | `540019e` | Soft-delete da set cancelled, nhung chua reverse structured allocation va chua chan sua linked flow | Co |
| Order summary | `OrderController`, `Orders/Index.vue`, `POS/Index.vue` | Thieu | `OrderPaymentSummaryService` | List/filter/sort/export/POS van dung `orders.amount_paid` nhu tong da tra | Co |
| Report/dashboard order payment | `app/Http/Controllers`, `app/Services`, `app/Support` | Can audit call site | `OrderPaymentSummaryService` | Chua thay service dung chung; moi noi doc `amount_paid` phai phan loai coc goc hay tong da tra | Co |
| Partner merge | `CustomerController::merge()` va merge trong update | Xung dot | `PartnerMergeService` | Merge inline, ghi adjustment bang debt source, co nguy co double debt, khong snapshot/provenance | Co |
| Partner merge model/schema | `app/Models`, `database/migrations` | Thieu | `PartnerMerge` va hai migration nguon | Search khong co model, bang, cot provenance hay snapshot tuong duong | Co |
| Partner guard | Controllers/service giao dich | Thieu | `PartnerTransactionGuard` | Chua co `merged_into_id` va chua chan source merged | Co |
| Timeline document-first | Debt timeline services | Da co, kien truc khac Sapo | Contract marker/reference trong `PartnerDebtLedgerService` | Kiot co document-first timeline rieng; khong thay the bang file Sapo | Chi tich hop |
| Timeline dual-role | `PartnerDebtLedgerService`, customer/supplier frontend | Da co | Customer = debt - supplier debt; Supplier = dao dau | Cong thuc va running-balance contract da ton tai | Regression only |
| Legacy MERGE timeline | Customer/supplier document timeline services | Co mot phan | Marker amount `0`, reference-only | Kiot dang loai/phan loai `MERGE-CUSTOMER` legacy; marker moi can contract trung tinh | Co |
| Customer frontend | `resources/js/Pages/Customers/Index.vue` | Co mot phan | Payment result va merge preview Sapo | Co modal payment/merge nhung merge preview dang tu tinh va payment response thieu summary | Co |
| Supplier frontend | `resources/js/Pages/Suppliers/Index.vue` | Da co mot phan | Dual-role mirror | Can regression marker moi va loc source merged | Co nho |
| Orders frontend | `resources/js/Pages/Orders/Index.vue` | Xung dot | `order_paid_total` | Cot/sort van dung `amount_paid` | Co |
| POS order UI | `resources/js/Pages/POS/Index.vue` | Xung dot | cumulative payment hydration | Hydrate coc tu `totals.amount_paid`, chua co breakdown cumulative | Co |
| Export/report | `OrderController::export()` va report search | Xung dot | cumulative order payment | Export dang tinh con no bang `total_payment - amount_paid` | Co |
| Regression tests | `tests/Feature` | Co mot phan | `570f121` | Co test timeline/payment cu, thieu parity overpayment/allocation/merge/guard | Co |

## Discovery schema va model

Ket qua search tren `origin/main`:

- Khong co migration tao `customer_payment_allocations`.
- Khong co migration tao `partner_merges`.
- Khong co cot `customers.merged_into_id` hoac `customers.merged_at`.
- Khong co migration snapshot merge.
- Khong co model `CustomerPaymentAllocation`.
- Khong co model `PartnerMerge`.

Ket luan audit hien tai: co the them schema/model moi theo contract Sapo, nhung
migration phai additive va khong duoc backfill. Truoc khi viet migration van phai
kiem tra lai ten bang/cot tai branch lam viec de tranh trung do thay doi song song.

## Xung dot can ghep thu cong

1. `CustomerController` cua Kiot chua logic debt timeline/audit moi hon Sapo.
   Chi thay payment/merge handlers, khong copy de file.
2. `PartnerDebtLedgerService` va cac document timeline service cua Kiot co contract
   document-first, virtual opening va dual-role rieng. Chi them structured allocation
   va marker reference-only vao kien truc hien co.
3. `OrderController` cua Kiot co cac thay doi POS, serial va costing sau khi tach repo.
   Chi ghep payment summary/guard; khong thay doi stock, costing hay serial.
4. Frontend Kiot co nhieu thay doi timeline va modal rieng. Chi patch bindings va
   response contract can thiet.

## Pham vi patch da xac nhan

- Signed customer debt effects va reversal.
- Structured customer payment allocation/cancellation.
- Option A cumulative order payment summary.
- Safe partner merge provenance/snapshot va merged-source guard.
- UI contract cho credit, payment summary, order summary va merge preview.
- Regression tests cho 12 kich ban BA yeu cau.

## Data safety

- Migration production: khong chay trong task.
- Backfill: khong.
- Update/xoa du lieu cu: khong.
- Cleanup MERGE legacy: khong.
- Neu phat hien MERGE legacy sai: chi ghi report. Neu can command dry-run thi lap
  proposal rieng de BA duyet.
- Migration moi se chi duoc verify tren database testing rieng va co rollback.

## Ma tran parity sau patch

| Khu vuc | Trang thai cuoi | Ket qua |
|---|---|---|
| Signed debt | Dat parity | `recordSale()` va reversal tu choi so am; invoice effect/reversal giu signed amount |
| Invoice/POS/order debt effect | Dat parity | Cac luong tao/sua/huy hoa don dung signed helper; overpayment tao credit am |
| Payment allocation | Dat parity | CashFlow giu full payment; invoice chi tang allocated amount; co provenance allocation |
| CashFlow cancellation | Dat parity | Lock row, reverse allocation/debt mot lan, retry tra `already_cancelled` |
| Order summary Option A | Dat parity | List/detail/filter/sort/export/print/POS payload dung cumulative summary |
| Report/dashboard order payment | Khong co gap con lai | Repo khong co report/dashboard doc `orders.amount_paid` nhu tong da tra |
| Partner merge | Dat parity | Transaction, ordered row lock, snapshot, marker `0`, source inactive, khong hard-delete |
| Partner guard | Dat parity toi thieu | Invoice/order/POS/payment/purchase/return/supplier payment/CashFlow da guard |
| Timeline marker | Dat parity | `MERGE-PARTNER-*` la reference-only, khong doi running balance |
| Timeline dual-role | Bao toan | Customer = receivable - payable; Supplier = payable - receivable |
| Customer frontend | Dat parity | Hien credit am, nhan `Khach dang du tien`, payment result summary |
| Supplier frontend | Dat parity | Bao toan dau supplier view, merge preview lay backend |
| Orders/POS frontend | Dat parity | Cumulative paid/debt/credit va breakdown coc/thu sau coc/thu lan nay |
| Legacy MERGE | Khong remediate | Chi nhan dien legacy marker; khong cleanup/backfill/command |

## File va model

File moi:

- `app/Models/CustomerPaymentAllocation.php`
- `app/Models/PartnerMerge.php`
- `app/Services/CustomerPaymentService.php`
- `app/Services/CustomerReceivableInvoiceService.php`
- `app/Services/OrderPaymentSummaryService.php`
- `app/Services/PartnerMergeService.php`
- `app/Services/PartnerTransactionGuard.php`
- `tests/Feature/CustomerDebt/SapoDebtParityTest.php`

File Kiot hien co duoc patch, khong copy de:

- Controllers: CashFlow, Customer, Invoice, Order, POS, Purchase, PurchaseOrder,
  PurchaseReturn va Supplier.
- Models: CashFlow, Customer va Invoice.
- Services: CustomerDebt, InvoiceSale, InvoiceUpdate, OrderReturn,
  CustomerPaymentDiscount va cac timeline customer/supplier/partner.
- Frontend: CashFlows, Customers, Suppliers, Orders, POS va print order.
- Route: them merge preview.

Khong sua file stock movement, moving-average costing, warranty, repair hoac serial/IMEI.

## Migration

Da search toan bo `database/migrations` va `app/Models` truoc khi tao. Khong co
schema/model tuong duong, khong co contract gan giong can BA quyet dinh.

Migration moi:

1. `2026_06_12_120000_create_customer_payment_allocations_table.php`
2. `2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
3. `2026_06_12_120200_add_partner_merge_provenance.php`

Chi co unique index tren bang moi:

- `customer_payment_allocations(cash_flow_id, invoice_id)`
- `partner_merges.ref_code`

Khong them unique index vao bang legacy. Khong backfill, update, cleanup hoac xoa
du lieu cu.

## Verification

Database test rieng: `storage/testing.sqlite` (local, khong commit).

- Full migration: pass sau khi dang ky runtime compatibility cho hai migration
  legacy dung MySQL `NOW()` va `information_schema`.
- Rollback ba migration moi bang `migrate:rollback --step=3`: pass.
- Migrate lai ba migration moi: pass.
- `tests/Feature/CustomerDebt/SapoDebtParityTest.php`: 12 passed,
  41 assertions.
- `npm run build`: pass, 915 modules transformed.
- `php artisan route:clear`: pass.
- `php artisan optimize:clear`: pass voi SQLite testing va `CACHE_STORE=array`.
  Lan chay dau theo cau hinh local mac dinh that bai vi MySQL
  `127.0.0.1` khong san sang; day la loi moi truong, khong phai loi ung dung.
- `git diff --check`: pass.

Regression rong da thu:

```text
Orders, POS, CustomerDebt, Customers, Supplier, Suppliers,
CashFlow, CashFlows, Report, Reports
```

Ket qua: 18 pass, 1 skip, 457 fail do test legacy thay doi/rollback schema SQLite
dung chung va de lai schema thieu cac cot da migrate, vi du
`products.inventory_total_cost`, `customer_debts.order_return_id`. Acceptance suite
duoc tao lai DB sach va chay lai thanh cong. Khong sua migration ton kho/payroll/
serial legacy trong task nay.

Repo khong co Node test script; chi co `dev` va `build`, nen khong co Node unit test
de chay.

Canh bao moi truong:

- PHP CLI thieu `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`.
- Node `20.15.1` thap hon engine khuyen nghi cua `@vitejs/plugin-vue@6.0.4`,
  nhung build van pass.
- `npm audit` bao 8 dependency issue: 5 moderate va 3 high; khong nang dependency
  trong task cong no nay.

## Manual QA

Chua thuc hien UAT UI vi worktree khong co database seed/tai khoan QA trinh duyet.
Sau deploy staging can chay du 6 luong:

1. Don 1.500.000, tra 1.200.000.
2. No 1.300.000, thu 1.500.000.
3. Credit -200.000 mua tiep 1.500.000.
4. Merge debt 300.000, marker 0.
5. Dual-role 200.000/200.000, hai man deu 0.
6. Source merged bi chan o cac luong giao dich.

## Legacy MERGE findings

Source code va test hien co co cac ref legacy `MERGE-CUSTOMER-*` va
`MERGE-SUPPLIER-*`. Task khong doc hay sua du lieu production, nen khong ket luan
so dong legacy sai. Khong tao command audit/apply/remediate. Neu can dry-run,
lap proposal rieng de BA duyet.

## Rui ro con lai

1. Don legacy co the da tung ghi de `orders.amount_paid` thanh tong da tra.
   Task khong backfill nen Option A se coi gia tri hien tai la coc goc.
2. Invoice legacy khong co provenance coc; cot moi nullable va khong backfill.
3. Guard da phu cac entry point hien tai duoc audit; module giao dich moi trong
   tuong lai phai goi `PartnerTransactionGuard`.
4. UAT browser va regression day du tren MySQL staging van con pending.

## Rollback va deploy de xuat

Backup DB production truoc deploy. Khong chay migration khi chua co BA phe duyet.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --pretend
php artisan migrate --force
php artisan optimize:clear
php artisan route:clear
```

Rollback migration `--step=3` chi phu hop truoc khi co giao dich moi ghi vao schema
nay, hoac sau khi restore backup da xac nhan. Sau khi da co allocation/merge moi,
uu tien forward fix thay vi drop bang/cot.

## Data safety ket luan

- Co dung du lieu cu: Khong.
- Co cleanup legacy: Khong.
- Co backfill: Khong.
- Co xoa du lieu: Khong.
- Co can production migration: Co, ba migration additive neu BA phe duyet deploy.
- Co chay production migration trong task: Khong.
