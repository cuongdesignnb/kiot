# STEP - Debt fix plan dry-run

## Pham vi audit

- Module: cong no khach hang, nha cung cap, dual-role partner.
- Man hinh: customer debt history, supplier debt transactions, supplier partner view.
- Nghiep vu: stored balance, ledger, document references, cashflow linkage, virtual opening read-only.
- Rui ro chinh: authority chua ro, ledger/chung tu lech, thieu ledger, dual-role orientation.

## Source da kiem tra

- Audit command: `app/Console/Commands/AuditDebtLedgerCommand.php`.
- Inspect command: `app/Console/Commands/InspectDebtPartnerCommand.php`, `app/Console/Commands/InspectTopDebtRisksCommand.php`.
- Plan command: `app/Console/Commands/PlanDebtFixCommand.php`.
- Service: `app/Services/DebtPartnerInspectionService.php`, `app/Services/PartnerDebtLedgerService.php`.
- Models: `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `CashFlow`, `Invoice`, `OrderReturn`, `Purchase`, `PurchaseReturn`, `DebtOffset`.
- Tests: `tests/Feature/Console/PlanDebtFixCommandTest.php`.
- Commit: this commit, `fix(debt): add consistency checks and production debt fix runbook`.
- input_snapshot_id: `20260604-113305`.
- audit_csv: `storage/app/audits/20260604-113305/debt-ledger-audit-mismatch.csv`.
- audit_csv_hash: `10ea28538b3d1264e9a3d4f9a5c6e33d6624c1db7c5601186c436704780344d2`.
- inspection_dir: `storage/app/audits/20260604-113305/inspections`.
- plan_json: `storage/app/audits/20260604-113305/debt-fix-plan.json`.
- generated_at: `2026-06-04T11:40:19+07:00`.

## Data safety

- Migration: khong chay.
- Backfill: khong chay.
- Update du lieu cu: khong chay.
- Delete: khong chay.
- Recalculate: khong chay.
- Ghi DB: khong. Command bat buoc `--dry-run`.
- Export CSV/JSON: co, chi ghi local vao `storage/app/audits`.
- Co chay migrate:fresh khong: khong.
- Can xac nhan truoc khi fix that: co.

## Input

- Mismatch CSV: `storage/app/audits/20260604-113305/debt-ledger-audit-mismatch.csv`.
- Inspect dir: `storage/app/audits/20260604-113305/inspections`.
- Limit: `20`.
- Classification filter: `none`.
- Diagnosis filter: `none`.
- PLAN_INPUT_MISMATCH count: `0`.

## Summary

| Fix group | Count | Risk | Next step |
|---|---:|---|---|
| A_OPENING_BALANCE_REVIEW | 1 | MEDIUM | `debt:plan-fix --dry-run --diagnosis=virtual_opening_display_resolved` |
| B_DOCUMENTS_NO_LEDGER | 1 | HIGH | `debt:plan-fix --dry-run --diagnosis=documents_exist_but_no_ledger` |
| C_LEDGER_DOCUMENT_MISMATCH | 16 | CRITICAL | `debt:plan-fix --dry-run --diagnosis=ledger_and_documents_mismatch` |
| D_CUSTOMER_ONLY_REVIEW | 2 | HIGH | `debt:plan-fix --dry-run --classification=CUSTOMER_ONLY_MISMATCH` |

## Authority candidates

| Authority | Count | Meaning |
|---|---:|---|
| document | 1 | Documents are candidate authority; ledger mapping must be dry-run first. |
| manual_review | 18 | Manual review required before choosing authority. |
| virtual_opening_readonly | 1 | Virtual opening explains display only; no real write. |

## Top planned cases

| Code | Name | Classification | Diagnosis | Authority | Fix group | Proposed action |
|---|---|---|---|---|---|---|
| NCC177624592772 | Trọng Hùng | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177354084249 | Hưng Hoa Mai | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177950763826 | Anh Thanh Thiên Phú | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177379765843 | Laptop Hải Đăng | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177466782297 | A Tuấn Anh - Mễ Trì | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| KH177598487429 | Test | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177365798441 | Công ty công nghệ thương mại Sơn Nam | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177459392941 | Anh Nguyễn 5S | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177466273054 | Hải Răng Thưa | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177492215790 | Siêu thị công nghệ HLC | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177363196335 | Vũ Kiên | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177374148585 | Tuấn_Hải Phòng | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177425696675 | Trần Văn Tiến | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| KH177460073148 | Anh Bẩy | HAS_DOCUMENTS_NO_LEDGER | documents_exist_but_no_ledger | document | B_DOCUMENTS_NO_LEDGER | Lap dry-run mapping tu chung tu sang ledger; chua backfill. |
| NCC177494410390 | Thắng LV | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177518554078 | Gia Hữu | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| NCC177621742868 | Nguyễn Quang Luân | DOCUMENT_LEDGER_MISMATCH | ledger_and_documents_mismatch | manual_review | C_LEDGER_DOCUMENT_MISMATCH | So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai. |
| KH177518347435 | An-Lê Đức Thọ | CUSTOMER_ONLY_MISMATCH | needs_manual_review | manual_review | D_CUSTOMER_ONLY_REVIEW | Manual review customer ledger/documents/cashflows truoc khi fix. |
| KH177561736414 | Dũng Kiều Mai | VIRTUAL_OPENING_REQUIRED | virtual_opening_display_resolved | virtual_opening_readonly | A_OPENING_BALANCE_REVIEW | Giu virtual opening read-only. Neu can chung tu that, tao opening balance sau backup/xac nhan. |
| KH177829769472 | Nguyễn Duy Khánh | CUSTOMER_ONLY_MISMATCH | needs_manual_review | manual_review | D_CUSTOMER_ONLY_REVIEW | Manual review customer ledger/documents/cashflows truoc khi fix. |

## Fix group details

### A_OPENING_BALANCE_REVIEW

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 1.
- Action: `debt:plan-fix --dry-run --diagnosis=virtual_opening_display_resolved`.
- Rui ro: MEDIUM.
- Required checks: Verify stored balance and display balance are explained by virtual opening.; Confirm whether a real opening balance document is required..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before any real opening balance is created.

### B_DOCUMENTS_NO_LEDGER

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 1.
- Action: `debt:plan-fix --dry-run --diagnosis=documents_exist_but_no_ledger`.
- Rui ro: HIGH.
- Required checks: Check document status.; Check reference_code mapping.; Check amount sign.; Check paid/refund amount.; Check cashflow linkage..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before any ledger backfill.

### C_LEDGER_DOCUMENT_MISMATCH

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 16.
- Action: `debt:plan-fix --dry-run --diagnosis=ledger_and_documents_mismatch`.
- Rui ro: CRITICAL.
- Required checks: Compare every document with ledger and cashflow rows.; Detect missing rows, duplicate rows, wrong status, or wrong sign..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before any mismatch repair.

### D_CUSTOMER_ONLY_REVIEW

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 2.
- Action: `debt:plan-fix --dry-run --classification=CUSTOMER_ONLY_MISMATCH`.
- Rui ro: HIGH.
- Required checks: Review customer ledger.; Review invoices, returns, and receipt cashflows.; Confirm source authority before any fix..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before customer-only debt repair.

### E_DUAL_ROLE_ORIENTATION_REVIEW

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 0.
- Action: `debt:plan-fix --dry-run --diagnosis=dual_role_orientation_risk`.
- Rui ro: CRITICAL.
- Required checks: Review customer orientation.; Review supplier orientation.; Do not auto-offset customer and supplier views..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before dual-role orientation repair.

### F_STORED_BALANCE_OPENING_CANDIDATE

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 0.
- Action: `debt:plan-fix --dry-run --diagnosis=stored_balance_without_source_history`.
- Rui ro: HIGH.
- Required checks: Confirm no reliable historical ledger exists.; Confirm stored balance source.; Confirm opening date and sign..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before opening balance materialization.

### X_PLAN_INPUT_MISMATCH

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 0.
- Action: `debt:audit-ledger --dry-run --only-mismatch && debt:inspect-top-risks --dry-run && debt:plan-fix --dry-run`.
- Rui ro: CRITICAL.
- Required checks: Rerun audit and inspect from the same DB snapshot.; Do not choose any authority while input files disagree..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Not applicable because no write is allowed.

### Z_NEEDS_MANUAL_REVIEW

- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.
- Case: 0.
- Action: `debt:inspect-partner --dry-run --include-raw --include-timeline`.
- Rui ro: MEDIUM.
- Required checks: Manual review required because authority is not clear..
- Can backup: co.
- Can xac nhan: co.
- Rollback plan: Required before any real fix.

## Forbidden without confirmation

- all DB writes.
- any DB write.
- any backfill.
- any recalculation.
- auto offset.
- backfill ledger.
- choose document as authority automatically.
- choose ledger as authority automatically.
- delete duplicate rows.
- insert customer debt.
- insert opening balance.
- insert supplier debt transaction.
- merge customer and supplier balances.
- recalculate customer debt.
- recalculate debt.
- recalculate historical debt.
- update balances.
- update customer balances.
- update customers.debt_amount.
- update either side without explicit approval.
- update stored balance.

## Ket luan

- Dat/chua dat: dat cho buoc plan dry-run neu tests/build PASS.
- Co the deploy plan command chua: co sau khi tests PASS.
- Co the backfill/fix du lieu that chua: chua.
- Can xac nhan gi tiep theo: Senior Auditor review plan CSV/JSON/Markdown va phe duyet rieng truoc moi data-fix.
