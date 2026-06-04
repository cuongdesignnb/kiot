# STEP - Debt ledger audit dry-run

## Pham vi audit

- Module: Cong no khach hang, nha cung cap, partner dual-role.
- Man hinh: customer debt timeline, supplier debt timeline, supplier partner view, debt reconciliation.
- Nghiep vu: so du phai thu, phai tra, ledger, chung tu goc, virtual opening balance.
- Rui ro chinh: so du cong no co nhung thieu lich su, chung tu khong co ledger, ledger/chung tu lech nhau, dual-role hien thi sai huong.

## Source da kiem tra

- Existing command: `app/Console/Commands/AuditCustomerNetDebt.php`, `app/Console/Commands/AuditPaidReturnRefundDebt.php`.
- New command: `app/Console/Commands/AuditDebtLedgerCommand.php`.
- PartnerDebtLedgerService: `buildCustomerNetLedger`, `buildSupplierPayableLedger`, `buildSupplierDualRolePartnerTimeline`.
- Models: `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `CashFlow`, `Invoice`, `OrderReturn`, `Purchase`, `PurchaseReturn`, `DebtOffset`.
- Tests: `tests/Feature/Console/AuditDebtLedgerCommandTest.php` plus debt regression tests.
- Commit: pending in this report.

## Data safety

- Migration: khong chay.
- Backfill: khong chay.
- Update du lieu cu: khong chay.
- Delete: khong chay.
- Recalculate: khong chay.
- Ghi DB: khong. Command bat buoc `--dry-run`; grep khong thay `save(`, `update(`, `delete(`, `insert(`, `create(`, `DB::statement` trong command.
- Export CSV: co, chi ghi file local trong `storage/app/audits`.
- Co chay migrate:fresh khong: khong.

## Commands da chay

- `git status; git branch --show-current; git log --oneline -15`: PASS, branch `main`, chi co untracked `kiot_db.sql.zip` truoc khi implement.
- `php artisan list | Select-String -Pattern "debt:audit-ledger|audit.*debt|customers:audit-net-debt|returns:audit-paid-refund-debt"`: truoc implement chua co `debt:audit-ledger`; sau implement co command moi.
- `rg -n "save\(|update\(|delete\(|insert\(|create\(|DB::statement" app/Console/Commands/AuditDebtLedgerCommand.php`: PASS, no matches.
- `php artisan test tests/Feature/Console/AuditDebtLedgerCommandTest.php`: PASS.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`: PASS.
- `php artisan test tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/SupplierDebtVirtualOpeningTimelineTest.php`: PASS.
- `npm run build`: PASS.
- `php artisan debt:audit-ledger --dry-run`: PASS.
- `php artisan debt:audit-ledger --dry-run --only-mismatch --export=storage/app/audits/debt-ledger-audit-mismatch.csv`: PASS.
- `php artisan debt:audit-ledger --dry-run --dual-role-only --export=storage/app/audits/debt-ledger-audit-dual-role.csv`: PASS.
- `php artisan debt:audit-ledger --dry-run --with-virtual-opening --export=storage/app/audits/debt-ledger-audit-virtual-opening.csv`: PASS.

## Test results

| Test | Result | Notes |
|---|---|---|
| AuditDebtLedgerCommandTest | PASS | 6 tests, 18 assertions |
| AnhThanhThienPhuDebtReconcileTest | PASS | 1 test, 36 assertions |
| SupplierDualRoleTimelineNoDashTest | PASS | 1 test, 55 assertions |
| CustomerDebtVirtualOpeningTimelineTest | PASS | 1 test, 19 assertions |
| SupplierDebtVirtualOpeningTimelineTest | PASS | 1 test, 18 assertions |

Ghi chu: PHP van in startup warning do thieu extension tuy chon `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; cac warning nay khong lam test fail.

## Build

- `npm run build`: PASS.

## CSV output

- Mismatch CSV: `storage/app/audits/debt-ledger-audit-mismatch.csv`.
- Dual-role CSV: `storage/app/audits/debt-ledger-audit-dual-role.csv`.
- Virtual opening CSV: `storage/app/audits/debt-ledger-audit-virtual-opening.csv`.
- Khong commit CSV: dung.
- Mismatch rows: 35 data rows, 36 lines including header.
- Dual-role rows: 10 data rows, 11 lines including header.
- Virtual opening rows: 22 data rows, 23 lines including header.

## Classification summary

| Classification | Count |
|---|---:|
| CUSTOMER_ONLY_MISMATCH | 4 |
| DOCUMENT_LEDGER_MISMATCH | 19 |
| HAS_DOCUMENTS_NO_LEDGER | 8 |
| VIRTUAL_OPENING_REQUIRED | 4 |
| OK | 0 in mismatch CSV; full dry-run had 186 OK |

Full dry-run summary: total audited 221, mismatches 35, virtual opening 22.

## Risk summary

| Risk | Count |
|---|---:|
| CRITICAL | 15 |
| HIGH | 16 |
| MEDIUM | 4 |
| LOW | 0 in mismatch CSV; full dry-run had 184 LOW |

## Top 20 risk rows

| Code | Name | Phone | Amount | Classification | Recommended action |
|---|---|---|---:|---|---|
| NCC177624592772 | Trong Hung |  | 880403000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177354084249 | Hung Hoa Mai |  | 94100000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177950763826 | Anh Thanh Thien Phu | 0974321888 | 47420000 | VIRTUAL_OPENING_REQUIRED | Co the giu read-only hoac tao opening balance that sau xac nhan. |
| KH177518347435 | An-Le Duc Tho |  | 26700000 | CUSTOMER_ONLY_MISMATCH | Manual review KH. |
| NCC177379765843 | Laptop Hai Dang | 0589586789 | 16150000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177466782297 | A Tuan Anh - Me Tri | 0972658980 | 15360000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| KH177598487429 | Test |  | 14000000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177365798441 | Cong ty cong nghe thuong mai Son Nam | 0921309999 | 13675000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177459392941 | Anh Nguyen 5S | 0969456558 | 12042200 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177466273054 | Hai Rang Thua | 0988965000 | 11640000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| KH177561736414 | Dung Kieu Mai |  | 11500000 | VIRTUAL_OPENING_REQUIRED | Co the giu read-only hoac tao opening balance that sau xac nhan. |
| NCC177492215790 | Sieu thi cong nghe HLC |  | 11210000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177363196335 | Vu Kien | 0984251991 | 10500000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| KH177829769472 | Nguyen Duy Khanh | 0764317901 | 10000000 | CUSTOMER_ONLY_MISMATCH | Manual review KH. |
| NCC177406096827 | Thuy Son | 0904413313 | 9750000 | HAS_DOCUMENTS_NO_LEDGER | Kiem tra mapping chung tu/ledger truoc, khong backfill voi. |
| NCC177891619859 | Long Pin |  | 9500000 | HAS_DOCUMENTS_NO_LEDGER | Kiem tra mapping chung tu/ledger truoc, khong backfill voi. |
| KH177710923043 | Quan-Cho tot |  | 8200000 | VIRTUAL_OPENING_REQUIRED | Co the giu read-only hoac tao opening balance that sau xac nhan. |
| KH177727641726 | May tinh Ba Vi |  | 6600000 | DOCUMENT_LEDGER_MISMATCH | Manual review truoc khi sua. |
| NCC177708782454 | Cong ty TNHH PC Toan Cau | 0886444222 | 5000000 | HAS_DOCUMENTS_NO_LEDGER | Kiem tra mapping chung tu/ledger truoc, khong backfill voi. |
| KH177829782139 | DNGUYEN | 0382503995 | 4000000 | CUSTOMER_ONLY_MISMATCH | Manual review KH. |

## Manual spot-check

| Code | Name | Stored debt | Display balance | Classification | Nhan xet |
|---|---|---:|---:|---|---|
| KH177561736414 | Dung Kieu Mai | 2570000 | 2570000 | VIRTUAL_OPENING_REQUIRED | Chua backfill; can xac nhan opening balance that neu muon sua du lieu. |
| KH177639649344 | Nam Kieu Mai | 0 | 0 | VIRTUAL_OPENING_REQUIRED | Co virtual opening theo service; can manual review truoc khi xu ly. |
| KH177710923043 | Quan-Cho tot | -1000000 | -1000000 | VIRTUAL_OPENING_REQUIRED | Supplier-side balance tao net customer view am; chua backfill. |
| NCC177950763826 | Anh Thanh Thien Phu | -27600000 | -27600000 | VIRTUAL_OPENING_REQUIRED | Dual-role lon; can xac nhan truoc khi tao opening/ledger that. |
| NCC177362540139 | Nguyen Viet | 0 | 0 | HAS_DOCUMENTS_NO_LEDGER | Co chung tu nhung khong co ledger tuong ung; khong backfill voi. |
| NCC177406096827 | Thuy Son | -9750000 | -9750000 | HAS_DOCUMENTS_NO_LEDGER | Can kiem tra mapping chung tu/ledger. |
| NCC177406353782 | Shopee | 0 | 0 | HAS_DOCUMENTS_NO_LEDGER | Can kiem tra chung tu goc. |
| NCC177486725819 | Doan Ben | 0 | 0 | HAS_DOCUMENTS_NO_LEDGER | Can manual review. |
| NCC177708782454 | Cong ty TNHH PC Toan Cau | -5000000 | -5000000 | HAS_DOCUMENTS_NO_LEDGER | Can kiem tra mapping truoc khi fix. |
| NCC177354084249 | Hung Hoa Mai | -30400000 | -30400000 | DOCUMENT_LEDGER_MISMATCH | Co ledger va documents nhung lech; manual review. |
| NCC177363196335 | Vu Kien | -10500000 | -10500000 | DOCUMENT_LEDGER_MISMATCH | Manual review. |
| NCC177365798441 | Cong ty cong nghe thuong mai Son Nam | -13675000 | -13675000 | DOCUMENT_LEDGER_MISMATCH | Manual review. |
| KH177518347435 | An-Le Duc Tho | 0 | 0 | CUSTOMER_ONLY_MISMATCH | Customer-only mismatch; can doi soat chung tu. |
| KH177829769472 | Nguyen Duy Khanh | 10000000 | 10000000 | CUSTOMER_ONLY_MISMATCH | Customer-only mismatch; can xac nhan truoc khi fix. |

Khong co row `STORED_BALANCE_NO_HISTORY` trong CSV mismatch hien tai; cac so du thieu lich su dang duoc service phan loai/resolve bang virtual opening.

## Ket luan

- Dat/chua dat: Dat cho buoc implement command audit read-only va dry-run.
- Co the deploy command audit chua: Co the dua len staging de Senior Auditor chay lai va review CSV.
- Co the backfill/fix du lieu that chua: Chua.
- Can xac nhan gi tiep: can Senior Auditor xac nhan classification, top risk rows, va phuong an tao opening balance/ledger that truoc khi bat ky backfill nao.

Can xac nhan truoc khi trien khai.
