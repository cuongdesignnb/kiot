# HOTFIX - Supplier dual-role orientation theo KiotViet

## Pham vi audit
- Module: cong no doi tac vua la khach hang vua la nha cung cap.
- Man hinh: `/customers` tab cong no va `/suppliers` tab cong no.
- Nghiep vu: lich su phai thu khach hang, phai tra nha cung cap, va hien thi mirror cho doi tac dual-role.
- Rui ro chinh: supplier tab dung nham customer orientation, lam HD/TTHD/PN/PCPN cung dau voi man khach hang.

## Source da kiem tra
- Route: `routes/api.php`, `routes/web.php`.
- Controller: `app/Http/Controllers/SupplierController.php`, `app/Http/Controllers/CustomerController.php`.
- Service: `app/Services/PartnerDebtLedgerService.php`, `app/Services/PartnerFinancialTimelineService.php`, `app/Services/DebtOffsetService.php`.
- Model: `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `CashFlow`, `DebtOffset`, `Invoice`, `OrderReturn`, `Purchase`, `PurchaseReturn`.
- Frontend: `resources/js/Pages/Suppliers/Index.vue`, `resources/js/Pages/Customers/Index.vue`.
- Tests: supplier/customer dual-role orientation, supplier payable ledger, debt offset, cashflow, purchase, customer debt.
- Commit truoc hotfix trong workspace: `bf5be29`.

## Tai lieu KiotViet da tham khao
- URL: https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-lam-quen-voi-kiotviet/buoc-5-quan-ly-cong-no/
- Ket luan lien quan phai thu/phai tra: cong no khach hang la goc nhin phai thu; cong no nha cung cap la goc nhin phai tra.
- Ket luan lien quan lich su cong no: lich su cong no la dong chung tu lam tang/giam so du theo tung man hinh.
- Ket luan lien quan can tru: can tru chi la chung tu rieng khi co CB/HCB thuc te; khong goi net balance la da can tru.

## Hien trang truoc hotfix
- Customer tab: dung customer orientation, `customer_receivable - supplier_payable`.
- Supplier tab dual-role: da co du HD/TTHD/PN/PCPN nhung van map `partner_effect` tu `customer_effect`.
- Sai/lech: supplier tab hien thi nhu customer tab, cot cuoi co luc bi doi thanh `Vi the rong`.
- Test dang sai: `SupplierDualRolePartnerTimelineTest` assert HD duong, TTHD am, PN am, PCPN duong cho supplier partner view.

## Root cause
- `buildSupplierDualRolePartnerTimeline()` dung output cua `buildCustomerNetLedger()` nhung khong doi orientation cho supplier screen.
- Supplier screen can balance rieng: `supplier_payable - customer_receivable`.

## Quy tac dau chuan
| Chung tu | Customer tab | Supplier tab |
|---|---:|---:|
| CB 0 | 0 | 0 |
| HD | + | - |
| TTHD | - | + |
| TH | - | + |
| CKTT | - | + |
| MERGE/CustomerDebt tang phai thu | + | - |
| PN | - | + |
| PCPN/TTNH | + | - |
| Tra hang nhap | + | - |

## Phuong an sua
- Khong sua `buildSupplierPayableLedger()`: ledger NCC thuan van chi gom nguon supplier-side.
- Sua `buildSupplierDualRolePartnerTimeline()` sang supplier orientation.
- Them `supplier_partner_effect`, `supplier_partner_running_balance`, `supplier_oriented_balance`.
- Backend phat `display_mode=supplier_partner_timeline`, giu `legacy_display_mode=partner_net_timeline` de tuong thich.
- Frontend supplier tab nhan ca mode cu/moi, cot cuoi luon la `No can tra nha cung cap`.
- Customer tab giu nguyen customer orientation.

## Co anh huong du lieu dang co khong?
- Khong migration.
- Khong backfill.
- Khong update/delete/recalculate du lieu cu.
- Khong tao CB/HCB.
- Co reset schema `sales_test` bang `php artisan migrate:fresh --env=testing` de sua trang thai test DB sau khi chay filter song song; thao tac nay chi tren DB test.

## Tests da chay
- `php artisan test tests\Feature\Suppliers\SupplierDualRolePartnerTimelineTest.php tests\Feature\Suppliers\SupplierDualRoleOrientationKiotVietTest.php tests\Feature\Suppliers\SupplierDualRolePartnerRuntimeApiTest.php tests\Feature\Suppliers\SupplierPayableLedgerTest.php tests\Feature\Suppliers\HOTFIXFollowUpSupplierLedgerHardeningTest.php tests\Feature\Customers\DualRolePartnerDebtTimelineTest.php tests\Feature\Customers\AnhThanhThienPhuDebtReconcileTest.php tests\Feature\Customers\ReconcilePartnerLedgerCommandTest.php`
  - Ket qua: 28 passed, 253 assertions.
- `php artisan test --filter=Supplier`
  - Ket qua: 109 passed, 541 assertions.
- `php artisan test --filter=DebtOffset`
  - Ket qua: 5 passed, 23 assertions.
- `php artisan test --filter=CashFlow`
  - Ket qua: 37 passed, 204 assertions.
- `php artisan test --filter=Purchase`
  - Ket qua: 86 passed, 444 assertions.
- `php artisan test --filter=CustomerDebt`
  - Ket qua: 37 passed, 195 assertions.

## Build
- `npm run build`: pass.

## Manual QA
- Case tao moi dual-role: chua thuc hien bang browser trong phien nay; fixture test co `CB000345 = 0`.
- Case ban hang: covered by `HD008236 -7.000.000`, `TTHD008236 +5.000.000` tren supplier tab.
- Case nhap hang: covered by `PN003806 +5.000.000`, `PCPN003806 -3.000.000` tren supplier tab.
- Case Anh Thanh Thien Phu: covered by regression summary customer/supplier/reconcile; browser screenshot chua thuc hien.

## Rui ro con lai
- CB 0 auto-create cho du lieu thuc chua trien khai vi day la thay doi ghi du lieu can xac nhan rieng.
- Production chua kiem vi chua deploy/pull/rebuild tren server production.
- PHP CLI can don warning extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; warning khong lam fail test.

## Ket luan
- Dat local/staging-read-model ve API/service/test/build.
- Co the deploy staging de QA browser.
- Chua ket luan production vi chua co bang chung deploy production, screenshot Network/UI, va rollback plan.
- Can lam tiep: QA browser tren local/staging cho request `view=partner`, entries HD/TTHD/PN/PCPN, va cot cuoi supplier tab.
