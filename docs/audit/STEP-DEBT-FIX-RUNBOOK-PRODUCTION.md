# STEP - Debt fix production runbook

## Phạm vi

- Chỉ fix nhóm được Senior Auditor duyệt sau dry-run.
- Số lượng case hiện trong plan snapshot `20260604-113305`: `20`.
- Tổng tiền ảnh hưởng: phải tính lại từ `debt-fix-plan.json` ngay trước khi apply.
- Không bao gồm: tồn kho, giá vốn, serial/IMEI, hóa đơn, nhập hàng, trả hàng, purchase returns, order returns nếu chưa có xác nhận riêng.

## Điều kiện tiên quyết

- Backup DB: bắt buộc có file backup kiểm tra được.
- Maintenance window: bắt buộc đặt trước.
- Người duyệt: Senior Auditor hoặc owner nghiệp vụ được chỉ định.
- Người chạy: engineer được chỉ định.
- Commit SHA: this commit, `fix(debt): add consistency checks and production debt fix runbook`.
- Snapshot ID: `20260604-113305`.
- Không có `PLAN_INPUT_MISMATCH`: required.
- Tests PASS: required.
- Build PASS: required.
- Confirmation code: required before any apply command.

## Backup bắt buộc

Command backup production:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
mkdir -p storage/app/backups/debt-fix
mysqldump -u <DB_USER> -p --single-transaction --routines --triggers <DB_NAME> > storage/app/backups/debt-fix/kiot-before-debt-fix-$(date +"%Y%m%d-%H%M%S").sql
```

## Verify backup

```bash
ls -lh storage/app/backups/debt-fix/
tail -5 storage/app/backups/debt-fix/<file>.sql
```

## Dry-run trên production trước

```bash
php artisan debt:audit-ledger --dry-run --only-mismatch --export=storage/app/audits/prod-debt-ledger-audit-mismatch.csv
php artisan debt:inspect-top-risks --dry-run --csv=storage/app/audits/prod-debt-ledger-audit-mismatch.csv --limit=20 --export-dir=storage/app/audits/prod-inspections
php artisan debt:plan-fix --dry-run --csv=storage/app/audits/prod-debt-ledger-audit-mismatch.csv --inspect-dir=storage/app/audits/prod-inspections --limit=20 --export-md=docs/audit/PROD-DEBT-FIX-PLAN-DRY-RUN.md
```

## Apply strategy

- Batch size: 1-5 partners per batch.
- Group allowed first: only groups explicitly approved after manual review, usually `A_OPENING_BALANCE_REVIEW` or `B_DOCUMENTS_NO_LEDGER` after evidence mapping.
- Group blocked by default: `C_LEDGER_DOCUMENT_MISMATCH`, `D_CUSTOMER_ONLY_REVIEW`, `E_DUAL_ROLE_ORIENTATION_REVIEW`, `F_STORED_BALANCE_OPENING_CANDIDATE`, `X_PLAN_INPUT_MISMATCH`.
- Confirmation required: yes, per group and per batch.
- Command apply dự kiến: not implemented in this step. Do not run a real apply until a guarded apply command is reviewed and tested.

## Data sẽ ghi nếu được duyệt sau này

| Group | Possible writes | Tables/columns | Current status |
|---|---|---|---|
| `A_OPENING_BALANCE_REVIEW` | opening ledger only if approved | `customer_debts`, `supplier_debt_transactions`, maybe `customers` | blocked until approval |
| `B_DOCUMENTS_NO_LEDGER` | ledger rows mapped from verified documents | `customer_debts`, `supplier_debt_transactions` | blocked until mapping proof |
| `C_LEDGER_DOCUMENT_MISMATCH` | no automatic write | none until manual diff is proven | blocked |
| `D_CUSTOMER_ONLY_REVIEW` | no automatic write | none until manual review is proven | blocked |
| `E_DUAL_ROLE_ORIENTATION_REVIEW` | no automatic write | none until orientation proof | blocked |
| `F_STORED_BALANCE_OPENING_CANDIDATE` | opening ledger only if source history absent | `customer_debts`, `supplier_debt_transactions`, maybe `customers` | blocked |
| `X_PLAN_INPUT_MISMATCH` | no write allowed | none | hard blocked |

## Rollback plan

- Nếu insert ledger: mọi row phải có `fix_run_id` hoặc batch marker; rollback bằng cách xóa đúng marker sau khi verify không có giao dịch phụ thuộc.
- Nếu update balance: phải export old values trước vào rollback JSON/table, rollback bằng restore old values.
- Nếu tạo opening balance: rollback xóa opening balance theo `fix_run_id` và rerun audit.
- Nếu lỗi nghiêm trọng: restore DB backup đã verify.

## Verification sau chạy

- Rerun audit:
  ```bash
  php artisan debt:audit-ledger --dry-run --only-mismatch --export=storage/app/audits/post-debt-fix-audit.csv
  ```
- Check top affected partners bằng `debt:inspect-partner --dry-run --include-raw --include-timeline`.
- Check customer screen.
- Check supplier screen.
- Check debt reports.
- Check no duplicated ledger.
- Compare before/after plan JSON for every approved partner.

## Stop conditions

- Có `PLAN_INPUT_MISMATCH`.
- Số mismatch tăng.
- Số dư đối tác lớn thay đổi ngoài plan.
- Test fail.
- Build fail.
- Backup fail hoặc backup không verify được.
- Không có xác nhận bằng văn bản.
- Bất kỳ partner nào thuộc group blocked nhưng vẫn xuất hiện trong apply batch.

## Kết luận

- Runbook này chỉ chuẩn bị phương án production.
- Có thể chạy fix thật chưa: chưa.
- Cần xác nhận trước khi triển khai.
