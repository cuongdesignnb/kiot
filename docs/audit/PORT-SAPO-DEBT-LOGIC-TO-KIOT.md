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

## Remote review

- Branch: `codex/port-sapo-debt-logic-to-kiot`
- Remote branch:
  `https://github.com/cuongdesignnb/kiot/tree/codex/port-sapo-debt-logic-to-kiot`
- Draft PR: `https://github.com/cuongdesignnb/kiot/pull/1`
- Base: `main`
- Review snapshot SHA truoc commit report cuoi: `25d5023`
- PR van de Draft; khong mark ready va khong merge main trong task nay.

## File changed thuc te

Snapshot `origin/main...25d5023`: 44 file, 2,321 insertions, 473 deletions.
Commit report/evidence cuoi khong them file ung dung.

Backend service:

- `app/Services/CustomerDebtDocumentTimelineService.php`
- `app/Services/CustomerDebtService.php`
- `app/Services/CustomerPaymentDiscountService.php`
- `app/Services/CustomerPaymentService.php`
- `app/Services/CustomerReceivableInvoiceService.php`
- `app/Services/InvoiceSaleService.php`
- `app/Services/InvoiceUpdateService.php`
- `app/Services/OrderPaymentSummaryService.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/PartnerMergeService.php`
- `app/Services/PartnerTransactionGuard.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `app/Support/Status/BusinessStatus.php`

Controller:

- `app/Http/Controllers/CashFlowController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/OrderController.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/PurchaseOrderController.php`
- `app/Http/Controllers/PurchaseReturnController.php`
- `app/Http/Controllers/SupplierController.php`

Model:

- `app/Models/CashFlow.php`
- `app/Models/Customer.php`
- `app/Models/CustomerPaymentAllocation.php`
- `app/Models/Invoice.php`
- `app/Models/PartnerMerge.php`

Migration:

- `database/migrations/2026_06_12_120000_create_customer_payment_allocations_table.php`
- `database/migrations/2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
- `database/migrations/2026_06_12_120200_add_partner_merge_provenance.php`

Frontend:

- `resources/js/Pages/CashFlows/Index.vue`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Orders/Index.vue`
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `resources/views/prints/order.blade.php`
- `routes/web.php`

Tests:

- `tests/Feature/CustomerDebt/SapoDebtParityTest.php`
- `tests/Feature/Customers/CustomerPaymentDiscountTest.php`
- `tests/Feature/Orders/ProcessOrderViaPosTest.php`
- `tests/Feature/POS/Hotfix246CPosQuickCreateCustomerGroupDropdownTest.php`
- `tests/Feature/POS/Step246CPosNoteAndDateFormatTest.php`

Docs:

- `docs/audit/PORT-SAPO-DEBT-LOGIC-TO-KIOT.md`
- `docs/audit/evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/*.png`

Khong co file stock movement, costing, serial/IMEI, payroll, warranty hay repair
duoc sua.

## Migration diff summary

### 1. Customer payment allocations

- File: `2026_06_12_120000_create_customer_payment_allocations_table.php`
- Bang moi: `customer_payment_allocations`.
- Cot: `id`, `cash_flow_id`, `customer_id`, `invoice_id`, `amount(15,2)`,
  timestamps.
- Nullable/default: cac FK va `amount` bat buoc; khong co default tai chinh.
- FK: CashFlow, Customer, Invoice; `restrictOnDelete`.
- Index: `(customer_id, invoice_id)`.
- Unique: `(cash_flow_id, invoice_id)` tren bang moi.
- Backfill/update du lieu cu: khong.
- `down()`: drop bang moi.
- Contract: CashFlow giu full payment; Invoice chi tang allocated amount; phan
  credit unallocated khong tao allocation.

### 2. Order deposit provenance

- File: `2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
- Cot moi tren bang legacy `invoices`:
  `order_deposit_applied_amount decimal(15,2) nullable`.
- Default: `NULL`; khong ep invoice legacy co provenance gia.
- Backfill/update du lieu cu: khong.
- `down()`: drop cot.

### 3. Partner merge provenance

- File: `2026_06_12_120200_add_partner_merge_provenance.php`
- Bang moi `partner_merges`: ref code, source/target partner, balance snapshot,
  aggregate snapshot, actor va merged timestamp.
- Unique: chi co `partner_merges.ref_code` tren bang moi.
- Cot moi tren `customers`: `merged_into_id nullable`, `merged_at nullable`.
- Cac balance snapshot bat buoc co default `0`; aggregate snapshot nullable;
  `merged_by` nullable va `nullOnDelete`.
- Backfill/update/xoa du lieu cu: khong.
- `down()`: drop FK/cot moi tren customers, sau do drop bang `partner_merges`.
- Marker `amount = 0` la contract cua `PartnerMergeService`; migration khong tao
  adjustment tai chinh va khong hard-delete source.

## Acceptance test

Environment:

```text
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test_port_migration_review
CACHE_STORE=array
SESSION_DRIVER=array
```

Command:

```bash
php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php
```

Result: `12 passed (41 assertions)`.

## Migration testing

Database rieng: MySQL `sales_test_port_migration_review`.

```text
php artisan migrate --env=testing                         PASS
php artisan migrate:rollback --step=3 --env=testing      PASS
php artisan migrate --env=testing                         PASS
```

Rollback step 3 go dung:

1. Partner merge provenance.
2. Invoice order-deposit provenance.
3. Customer payment allocations.

Khong co loi migration legacy tren MySQL va khong can runtime compatibility
workaround. Khong dung `migrate:fresh`.

## Regression classification

Tat ca suite duoc chay tren cac MySQL database testing rieng. Hai nhom con fail
duoc chay lai tren detached worktree `origin/main` tai `1822437` voi cung DB/env.

| Nhom test | Pass | Fail | Skip | Nguyen nhan chinh | Lien quan code moi | Can sua scope nay |
|---|---:|---:|---:|---|---|---|
| Orders | 19 | 0 | 0 | Option A va POS order regression pass | Co, da pass | Khong |
| POS | 65 | 0 | 0 | POS regression pass; test khong goi `migrate:fresh` noi bo | Co, da pass | Khong |
| CustomerDebt | 17 | 0 | 0 | Gom 12 parity case | Co, da pass | Khong |
| SupplierDebt | - | - | - | Folder `tests/Feature/SupplierDebt` khong ton tai | Khong xac dinh | Khong |
| Customers | 118 | 30 | 1 | Timeline/export legacy contract; `origin/main` cung 118/30/1, 644 assertions | Khong phat sinh them | Khong |
| Suppliers | 31 | 13 | 0 | Timeline/virtual opening legacy contract; `origin/main` cung 31/13, 200 assertions | Khong phat sinh them | Khong |
| CashFlow + CashFlows | 14 | 0 | 0 | Allocation/cancellation regression pass | Co, da pass | Khong |
| Report + Reports | 109 | 0 | 0 | Report regression pass | Co, da pass | Khong |

Bon fail moi phat hien luc review da duoc xu ly:

- Hai test order cu coi `orders.amount_paid` la cumulative paid; cap nhat assertion
  theo Option A va xac minh cumulative qua `OrderPaymentSummaryService`.
- Ba contract trong `CustomerPaymentDiscountTest` cu coi legacy debt `ref_code`
  la quyen so huu invoice; cap nhat de reject cross-customer allocation.
- Auto payment khong co invoice receivable nay ghi full CashFlow va unallocated
  credit theo contract moi, thay vi reject.
- Hai POS test dung `RefreshDatabase` noi bo co the goi `migrate:fresh`; chuyen
  sang transaction-only.

Ba fail mau Customers, cung tai `origin/main`:

1. `AnhThanhThienPhuDebtReconcileTest`: expected `75,000,000`, actual `0`;
   mismatch timeline/reconciliation legacy, khong do migration moi.
2. `CustomerDebtExcelExportTest`: expected return label `Tra hang ban`, actual
   `null`; export timeline legacy, khong do code payment/order moi.
3. `CustomerDebtVirtualOpeningTimelineTest`: expected virtual opening `true`,
   actual `null`; virtual opening contract cu, cung fail tren main.

Ba fail mau Suppliers, cung tai `origin/main`:

1. `HOTFIXFollowUpSupplierDebtPaginationTest`: expected summary `32,500,000`,
   actual `0`; full-ledger summary legacy.
2. `SupplierDebtVirtualOpeningTimelineTest`: expected virtual opening `true`,
   actual `false`; virtual opening legacy.
3. `SupplierDualRoleOrientationKiotVietTest`: thieu key
   `supplier_partner_effect`; response contract legacy.

Ket luan regression: khong con fail moi lien quan signed debt, payment allocation,
order summary, POS, CashFlow hoac Reports. 43 fail con lai la baseline debt
timeline/export legacy va duoc chung minh bang ket qua trung khop tren
`origin/main`.

## Frontend build va cache

```text
Node: v20.15.1
npm: 10.7.0
npm run build: PASS
Vite: 5.4.21
Modules transformed: 918
Vite/Vue error: khong
```

Warning: Node thap hon engine khuyen nghi cua
`@vitejs/plugin-vue@6.0.4`; build van pass. Khong nang dependency trong task.
PHP CLI canh bao thieu `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; MySQL
test khong dung cac extension nay.
`npm ci` audit bao 8 issue hien huu (4 moderate, 4 high); khong nang dependency
ngoai scope.

```text
php artisan route:clear      PASS
php artisan optimize:clear   PASS
php artisan config:clear     PASS
php artisan view:clear       PASS
git diff --check             PASS
```

Repo khong co Node unit-test script; `package.json` chi co `dev` va `build`.

## UAT UI

Environment:

```text
URL: http://127.0.0.1:8092
DB: MySQL sales_test_port_uat
Account: uat-admin@kiot.local
Role: admin compatibility (role_id NULL)
Runner: Playwright 1.60.0, Chrome, headless
Result: 6 business cases PASS; extended guard entry points PASS
```

1. Order partial payment:
   `UAT-DH-1500`, total `1,500,000`, `orders.amount_paid=0`, invoice paid
   `1,200,000`. UI hien paid `1,200,000`, remaining `300,000`.
   Evidence: `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/01-order-partial-payment.png`.
2. Debt overpayment:
   `UAT-OVERPAY` thu `1,500,000` cho invoice no `1,300,000`. UI hien payment,
   allocated, unallocated va debt after lan luot `1,500,000`, `1,300,000`,
   `200,000`, `-200,000`; label credit am hien dung.
   Evidence: `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/02-overpayment-summary.png`.
3. Future credit offset:
   `UAT-CREDIT-NEXT` co ledger credit `-200,000`, invoice moi `1,500,000`;
   UI hien current debt `1,300,000`.
   Evidence: `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/03-credit-used-next-sale.png`.
4. Merge marker zero:
   merge `UAT-MERGE-SOURCE` debt `300,000` vao `UAT-MERGE-TARGET`.
   Preview backend hien target debt `300,000`, marker `0`; SQL sau merge xac
   nhan `MERGE-PARTNER-18-TO-19`, source inactive, marker `merge_marker=0`,
   target debt `300,000`, khong double.
   Evidence: `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/04-merge-preview-marker-zero.png`.
5. Dual-role net zero:
   `UAT-DUAL` co receivable/payable cung `200,000`; man Customer va Supplier
   deu hien `0`.
   Evidence:
   `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/05-dual-role-customer-zero.png` va
   `05-dual-role-supplier-zero.png`.
6. Merged-source guard:
   POS lookup khong tra source. Browser POST bang source merged cho invoice,
   order, customer payment, purchase, supplier payment va CashFlow deu tra
   `422` voi message chua `UAT-MERGE-TARGET`. SQL xac nhan khong co order,
   invoice, purchase, supplier transaction hay CashFlow ngoai du lieu seed va
   phieu thu hop le cua case 2.
   Evidence: `evidence/PORT-SAPO-DEBT-LOGIC-TO-KIOT/06-merged-source-guard.png`.

SQL hau kiem case 2:

```text
CashFlow.amount=1,500,000
allocated_amount=1,300,000
unallocated_amount=200,000
invoice.customer_paid=1,300,000
customer.debt_amount=-200,000
```

## Legacy MERGE findings

Source code/test co ref legacy `MERGE-CUSTOMER-*` va `MERGE-SUPPLIER-*`.
Khong doc/sua production data, khong cleanup, khong backfill, khong tao command
apply/remediate. Neu can dry-run phai lap proposal rieng de BA duyet.

## Production migration checklist

Khong chay cac lenh production trong task. Truoc production migration:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git status
git rev-parse HEAD
git fetch origin main
git log --oneline -5
php artisan migrate:status
php artisan migrate --pretend
```

Chi sau khi backup DB va BA xac nhan rieng:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
php artisan migrate --force
rm -rf public/build
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
# Restart PHP-FPM qua aaPanel neu can.
```

Rollback `--step=3` chi an toan truoc khi co giao dich moi ghi vao schema nay
hoac sau khi restore backup da xac nhan. Sau khi co allocation/merge moi, uu tien
forward fix thay vi drop bang/cot.

## Rui ro con lai

1. Don legacy co the da tung ghi de `orders.amount_paid` thanh cumulative paid.
   Khong backfill; Option A se coi gia tri hien tai la original deposit.
2. Invoice legacy khong co provenance coc; cot moi nullable va khong backfill.
3. 43 fail baseline Customers/Suppliers van ton tai trong debt timeline/export
   legacy. Chung khong phat sinh tu branch nay, nhung nen co task rieng.
4. Node local thap hon engine khuyen nghi; build pass nhung CI/staging nen dung
   Node phu hop engine.
5. Can UAT lai tren staging voi du lieu clone/fixture gan production truoc deploy.

## Data safety va quyet dinh

- Co backfill: Khong.
- Co cleanup legacy: Khong.
- Co update/xoa du lieu cu: Khong.
- Co chay production migration: Khong.
- Co dung stock/costing/serial/payroll/warranty/repair: Khong.
- Co can production migration: Co, 3 migration additive.
- Co can BA xac nhan rieng truoc production migration: Co.
- Ready for BA review: Co.
- Ready for merge main: Khong; cho BA review/approve.
- Ready for production deploy: Khong; cho merge approval, backup va migration
  approval rieng.
