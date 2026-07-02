# HOTFIX - Debt reconcile severity after virtual display timeline

## Pham vi audit
- Module: Cong no khach hang, nha cung cap, doi tac dual-role.
- Man hinh: `/customers`, `/suppliers`.
- Nghiep vu: tach doi soat ledger ky thuat khoi trang thai canh bao nguoi dung khi display timeline da duoc resolve bang virtual opening read-only.
- Rui ro chinh: UI hien canh bao manh cho case ledger thieu lich su cu, du display timeline da khop so du hien tai.

## User feedback
- Nhieu khach/NCC van hien "Lich su cong no dang lech..." du timeline da co virtual opening.
- Can khong lam mat du lieu va khong che loi that.

## Source da kiem tra
- PartnerDebtLedgerService: them `buildDisplayReconcilePayload()`, ap dung cho customer net, supplier payable, supplier dual-role partner timeline.
- PartnerFinancialTimelineService: dong bo reconcile payload neu service cu con duoc goi truc tiep.
- CustomerController: customer debt-history dang dung PartnerDebtLedgerService.
- SupplierController: tra `reconcile` cho ca pure supplier va dual-role partner timeline.
- Customers/Index.vue: warning banner chi hien khi `severity=warning` hoac `user_warning=true`.
- Suppliers/Index.vue: them warning banner co dieu kien va info note nho.
- Tests: bo sung virtual-opening assertions, unresolved mismatch test, Anh Thanh Thien Phu assertions.
- Commit: commit chua report nay, message `fix(debt): downgrade resolved ledger mismatches after virtual opening`.

## Root cause
- UI/backward contract dang dung `has_mismatch` tu ledger mismatch lam canh bao nguoi dung.
- Ledger mismatch khong tinh virtual opening read-only, nen du lieu cu thieu ledger chi tiet bi bao nhu cong no sai.
- Display timeline da resolved nhung van co the hien warning vi `message` cu duoc build theo ledger mismatch.

## Phuong an
- Tach `ledger_mismatch` va `display_mismatch`.
- `has_mismatch` backward-compatible = `display_mismatch`.
- `severity`: `ok`, `info`, `warning`.
- UI chi hien banner warning khi `severity=warning` hoac `user_warning=true`.
- Case ledger mismatch nhung virtual opening da resolve display timeline tra `severity=info`, khong co user warning.

## Co anh huong du lieu khong?
- Khong.
- Khong migration/backfill/update/delete/recalculate.
- Khong tao chung tu that.
- Virtual opening chi la read-only response, khong ghi DB.

## Tests da chay
- PASS: `php artisan test tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php`
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDebtVirtualOpeningTimelineTest.php`
- PASS: `php artisan test tests/Feature/Customers/CustomerDebtUnresolvedMismatchWarningTest.php`
- PASS: `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php tests/Feature/Suppliers/SupplierPayableLedgerTest.php`
- PASS: `php artisan test tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- PASS after filter run: `php artisan test tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php`
- FAIL/environment: `php artisan test --filter=Supplier`
- FAIL/environment: `php artisan test --filter=CustomerDebt`
- FAIL/environment: `php artisan test --filter=Purchase`
- FAIL/environment: `php artisan test --filter=CashFlow`

Filter-wide failures were caused by test DB schema errors such as missing `sales_test.customers`, `sales_test.users`, `sales_test.cash_flows`, and missing `users.role_id` while multiple filter processes were run against the same test database. No destructive migration command was run to repair this.

## Build
- PASS: `npm run build`

## Manual QA
- Chu Ba Lam: covered by customer virtual-opening feature test.
- Anh Thanh customer: covered by Anh Thanh Thien Phu API assertions.
- Anh Thanh supplier: covered by Anh Thanh Thien Phu supplier API assertions.
- True unresolved mismatch: covered by `CustomerDebtUnresolvedMismatchWarningTest`.

## Rui ro con lai
- Ledger mismatch info van can command audit/dry-run neu muon lam sach du lieu that.
- Virtual opening chi la read-only display, khong phai chung tu that.
- Can rerun filter-wide suites sequentially on a clean test database before production deployment.

## Ket luan
- Dat/chua dat: Dat cho contract hotfix va cac regression muc tieu; filter-wide suites bi chan boi schema test DB.
- Co the deploy staging chua: Co the deploy staging sau khi QA local/staging tren data that.
- Co the deploy production chua: Chua nen deploy production truoc khi QA staging va rerun suites rong tren test DB sach.
