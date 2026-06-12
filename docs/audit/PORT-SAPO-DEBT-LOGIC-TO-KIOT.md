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

## Verification status

Chua chay test/build o giai doan audit. Ket qua se duoc cap nhat sau khi patch.
