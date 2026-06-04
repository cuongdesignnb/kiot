# STEP - Debt ledger drill-down top risks

## Pham vi audit

- Module: cong no khach hang, nha cung cap, dual-role partner.
- Man hinh: customer debt history, supplier debt transactions, supplier partner view.
- Nghiep vu: stored balance, ledger, document references, cashflow linkage, virtual opening display.
- Rui ro chinh: ledger/chung tu lech, co chung tu nhung thieu ledger, virtual opening chi giai thich hien thi, dual-role can manual review.

## Source da kiem tra

- AuditDebtLedgerCommand: `app/Console/Commands/AuditDebtLedgerCommand.php`.
- InspectDebtPartnerCommand: `app/Console/Commands/InspectDebtPartnerCommand.php`.
- InspectTopDebtRisksCommand: `app/Console/Commands/InspectTopDebtRisksCommand.php`.
- DebtPartnerInspectionService: `app/Services/DebtPartnerInspectionService.php`.
- PartnerDebtLedgerService: `buildCustomerNetLedger`, `buildSupplierPayableLedger`, `buildSupplierDualRolePartnerTimeline`.
- Models: `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `CashFlow`, `Invoice`, `OrderReturn`, `Purchase`, `PurchaseReturn`, `DebtOffset`.
- Tests: `tests/Feature/Console/InspectDebtPartnerCommandTest.php`, `tests/Feature/Console/AuditDebtLedgerCommandTest.php`.
- Commit: pending in this report.

## Data safety

- Migration: khong chay.
- Backfill: khong chay.
- Update du lieu cu: khong chay.
- Delete: khong chay.
- Recalculate: khong chay.
- Ghi DB: khong. Inspect commands bat buoc `--dry-run`.
- Export JSON: co, chi ghi local vao `storage/app/audits/debt-inspections`.
- Co chay migrate:fresh khong: khong.
- JSON/CSV audit output: khong commit.

## Commands da chay

- `git status; git branch --show-current; git log --oneline -15`: PASS.
- `Get-ChildItem storage/app/audits` va `Get-Content ... -TotalCount 5`: PASS, CSV audit co san.
- `rg -n "debt:audit-ledger|class AuditDebtLedgerCommand" app tests docs`: PASS.
- `rg -n "CustomerDebt|SupplierDebtTransaction|buildCustomerNetLedger|buildSupplierPayableLedger|buildSupplierDualRolePartnerTimeline" app/Models app/Services app/Console tests`: PASS.
- `php -l app/Services/DebtPartnerInspectionService.php`: PASS.
- `php -l app/Console/Commands/InspectDebtPartnerCommand.php`: PASS.
- `php -l app/Console/Commands/InspectTopDebtRisksCommand.php`: PASS.
- `php -l tests/Feature/Console/InspectDebtPartnerCommandTest.php`: PASS.
- `rg -n "save\(|update\(|delete\(|insert\(|create\(|DB::statement" app/Console/Commands/InspectDebtPartnerCommand.php app/Console/Commands/InspectTopDebtRisksCommand.php`: PASS, no matches.
- `php artisan test tests/Feature/Console/InspectDebtPartnerCommandTest.php`: PASS.
- `php artisan test tests/Feature/Console/AuditDebtLedgerCommandTest.php`: PASS.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`: PASS.
- `npm run build`: PASS.

## Test results

| Test | Result | Notes |
|---|---|---|
| InspectDebtPartnerCommandTest | PASS | 5 tests, 13 assertions |
| AuditDebtLedgerCommandTest | PASS | 6 tests, 18 assertions |
| AnhThanhThienPhuDebtReconcileTest | PASS | 1 test, 36 assertions |
| SupplierDualRoleTimelineNoDashTest | PASS | 1 test, 55 assertions |
| npm run build | PASS | Vite build completed |

Ghi chu: PHP van in startup warning do thieu optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`. Warning moi truong nay khong lam test fail.

## Inspection files

| Code | Name | JSON path | Classification | Diagnosis | Confidence |
|---|---|---|---|---|---|
| NCC177624592772 | Trong Hung | `storage/app/audits/debt-inspections/NCC177624592772-Trong-Hung.json` | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | high |
| NCC177354084249 | Hung Hoa Mai | `storage/app/audits/debt-inspections/NCC177354084249-Hung-Hoa-Mai.json` | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | high |
| NCC177950763826 | Anh Thanh Thien Phu | `storage/app/audits/debt-inspections/NCC177950763826-Anh-Thanh-Thien-Phu.json` | VIRTUAL_OPENING_REQUIRED | virtual_opening_display_resolved | medium |
| KH177518347435 | An-Le Duc Tho | `storage/app/audits/debt-inspections/KH177518347435-An-Le-Duc-Tho.json` | CUSTOMER_ONLY_MISMATCH | needs_manual_review | low |
| NCC177379765843 | Laptop Hai Dang | `storage/app/audits/debt-inspections/NCC177379765843-Laptop-Hai-Dang.json` | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | high |

Batch top-20 exported 20 JSON files under `storage/app/audits/debt-inspections/top-20`.

Top-20 diagnosis summary:

| Diagnosis | Count |
|---|---:|
| ledger_and_documents_mismatch | 15 |
| virtual_opening_display_resolved | 2 |
| needs_manual_review | 2 |
| documents_exist_but_no_ledger | 1 |

## Top risk diagnosis

### NCC177624592772 - Trong Hung

- Classification: DOCUMENT_LEDGER_MISMATCH.
- Stored balance: customer view `-880403000`, supplier view `880403000`.
- Ledger balance/source: `ledger_count=12`.
- Documents: `document_count=24`.
- CashFlow: `cash_flow_count=7`.
- Virtual opening: customer `false`, supplier `false`.
- Timeline entries: customer `30`, supplier `30`.
- Primary cause: `ledger_and_documents_mismatch`.
- Evidence: documents and ledger both exist, but reconciliation still flags mismatch.
- Recommended action: manual review each document/ledger row; identify duplicate or missing rows before any fix.
- Co can xac nhan truoc khi fix khong: co.

### NCC177354084249 - Hung Hoa Mai

- Classification: DOCUMENT_LEDGER_MISMATCH.
- Stored balance: customer view `-30400000`, supplier view `30400000`.
- Ledger balance/source: `ledger_count=15`.
- Documents: `document_count=40`.
- CashFlow: `cash_flow_count=19`.
- Virtual opening: customer `true`, supplier `true`.
- Timeline entries: customer `1`, supplier `47`.
- Primary cause: `ledger_and_documents_mismatch`.
- Evidence: many supplier documents/cashflows and supplier debt transactions exist; virtual opening is also present, so old history is not fully represented by true ledger.
- Recommended action: manual review mapping between purchase/payment documents and supplier ledger before generating any opening balance.
- Co can xac nhan truoc khi fix khong: co.

### NCC177950763826 - Anh Thanh Thien Phu

- Classification: VIRTUAL_OPENING_REQUIRED.
- Stored balance: customer view `-27600000`, supplier view `27600000`.
- Ledger balance/source: `ledger_count=4`.
- Documents: `document_count=11`.
- CashFlow: `cash_flow_count=1`.
- Virtual opening: customer `true`, supplier `true`.
- Timeline entries: customer `14`, supplier `14`.
- Primary cause: `virtual_opening_display_resolved`.
- Evidence: display is resolved by virtual opening, not by full true ledger coverage.
- Recommended action: read-only view can remain; if a true voucher is required, create real opening balance only after backup and explicit confirmation.
- Co can xac nhan truoc khi fix khong: co.

### KH177518347435 - An-Le Duc Tho

- Classification: CUSTOMER_ONLY_MISMATCH.
- Stored balance: customer view `0`, supplier view `0`.
- Ledger balance/source: `ledger_count=2`.
- Documents: `document_count=6`.
- CashFlow: `cash_flow_count=3`.
- Virtual opening: customer `true`, supplier `false`.
- Timeline entries: customer `7`, supplier `0`.
- Primary cause: `needs_manual_review`.
- Evidence: customer-only case has documents, ledger rows, cashflows, and virtual opening signal; current heuristics cannot safely decide whether source ledger or source documents are authoritative.
- Recommended action: manual review before any data fix.
- Co can xac nhan truoc khi fix khong: co.

### NCC177379765843 - Laptop Hai Dang

- Classification: DOCUMENT_LEDGER_MISMATCH.
- Stored balance: customer view `-1050000`, supplier view `1050000`.
- Ledger balance/source: `ledger_count=5`.
- Documents: `document_count=9`.
- CashFlow: `cash_flow_count=3`.
- Virtual opening: customer `true`, supplier `true`.
- Timeline entries: customer `2`, supplier `11`.
- Primary cause: `ledger_and_documents_mismatch`.
- Evidence: supplier documents, supplier ledger transactions, and cashflows all exist but still require virtual opening and mismatch review.
- Recommended action: manual review each purchase/payment/ledger pair; do not backfill until duplicate/missing mapping is known.
- Co can xac nhan truoc khi fix khong: co.

## Nhom phuong an fix sau audit

### Nhom A - Chi thieu so du dau ky

- Dieu kien: stored balance or display balance is resolved by virtual opening, with no reliable full historical ledger.
- Cach fix de xuat: keep read-only virtual opening, or create a real opening balance only after backup and approval.
- Rui ro: duplicate opening balance if old ledger/documents already encode part of the balance.
- Can backup: co.
- Can xac nhan: co.

### Nhom B - Co chung tu nhung thieu ledger

- Dieu kien: `document_count > 0`, `ledger_count = 0`.
- Cach fix de xuat: verify mapping rules first, then prepare a dry-run backfill plan per document type.
- Rui ro: backfill wrong sign/orientation or duplicate virtual payment rows.
- Can backup: co.
- Can xac nhan: co.

### Nhom C - Co ledger va chung tu nhung lech

- Dieu kien: `document_count > 0`, `ledger_count > 0`, reconciliation mismatch.
- Cach fix de xuat: compare document code, cashflow code, ledger code, status, and amount; decide whether document is duplicate, ledger is missing, or status is wrong.
- Rui ro: highest risk; blindly adding ledger can double-count old debt.
- Can backup: co.
- Can xac nhan: co.

### Nhom D - Dual-role rui ro

- Dieu kien: partner is both customer and supplier, customer/supplier views need strict opposite orientation and supplier final must match supplier view.
- Cach fix de xuat: manual review dual-role orientation first; no automatic offset.
- Rui ro: wrong customer-vs-supplier sign can invert debt.
- Can backup: co.
- Can xac nhan: co.

## Ket luan

- Dat/chua dat: dat cho buoc drill-down read-only.
- Co the deploy command inspect chua: co the deploy len staging de Senior Auditor inspect tung partner.
- Co the backfill/fix du lieu that chua: chua.
- Can xac nhan gi tiep theo: Senior Auditor can review JSON top risks, chon authority per case (stored balance, ledger, document, cashflow), and approve a separate dry-run fix plan before any production data change.

Can xac nhan truoc khi trien khai.
