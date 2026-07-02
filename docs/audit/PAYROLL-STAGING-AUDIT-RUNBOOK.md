# PAYROLL STAGING AUDIT RUNBOOK

## 1. Điều kiện trước khi chạy

```text
[ ] Có staging hoặc bản sao production.
[ ] Đã backup DB.
[ ] Đã xác định database source.
[ ] Không chạy trên production thật.
[ ] Payroll schema đã apply trên staging copy.
[ ] Không chạy --apply data migration.
[ ] Có quyền ghi storage/app/audit.
```

## 2. Kiểm tra schema

```bash
php artisan migrate:status
```

Ghi lại:

```text
Database:
APP_ENV:
Migration payroll status:
Người chạy:
Thời gian chạy:
```

## 3. Audit all

```bash
php artisan payroll:audit-salary-ledger \
  --section=all \
  --format=csv \
  --output=storage/app/audit/payroll-ledger-anomaly-all.csv

php artisan payroll:audit-salary-ledger \
  --section=all \
  --format=json \
  --output=storage/app/audit/payroll-ledger-anomaly-all.json
```

## 4. Section audit

```bash
php artisan payroll:audit-salary-ledger \
  --section=cache \
  --format=csv \
  --output=storage/app/audit/payroll-cache-audit.csv

php artisan payroll:audit-salary-ledger \
  --section=payments \
  --format=csv \
  --output=storage/app/audit/payroll-payment-cashflow-audit.csv

php artisan payroll:audit-salary-ledger \
  --section=advances \
  --format=csv \
  --output=storage/app/audit/payroll-advance-audit.csv

php artisan payroll:audit-salary-ledger \
  --section=legacy \
  --format=csv \
  --output=storage/app/audit/payroll-legacy-balance-audit.csv
```

## 5. Dry-run migration

```bash
php artisan payroll:migrate-salary-ledger --legacy-balance=report

php artisan payroll:migrate-salary-ledger \
  --backfill-documents \
  --legacy-balance=report
```

Nếu muốn mô phỏng opening balance, chỉ chạy không có `--apply`:

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD
```

## 6. File output cần nộp BA

```text
storage/app/audit/payroll-ledger-anomaly-all.csv
storage/app/audit/payroll-ledger-anomaly-all.json
storage/app/audit/payroll-cache-audit.csv
storage/app/audit/payroll-payment-cashflow-audit.csv
storage/app/audit/payroll-advance-audit.csv
storage/app/audit/payroll-legacy-balance-audit.csv
storage/app/audit/payroll-migration-dry-run-report.txt
```

## 7. Summary cần tổng hợp

| Chỉ số | Kết quả |
|---|---|
| `total_employees` | Chờ chạy |
| `ok_count` | Chờ chạy |
| `issue_count` | Chờ chạy |
| `cache_mismatch_count` | Chờ chạy |
| `missing_cache_count` | Chờ chạy |
| `legacy_balance_count` | Chờ chạy |
| `negative_balance_count` | Chờ chạy |
| `outstanding_advance_count` | Chờ chạy |
| `payment_cashflow_issue_count` | Chờ chạy |
| `advance_issue_count` | Chờ chạy |

## 8. Điều kiện dừng rollout

Dừng nếu có:

```text
CRITICAL issue
PAYMENT_WITHOUT_CASHFLOW
PAYMENT_WITHOUT_LEDGER
CASHFLOW_AMOUNT_MISMATCH
ADVANCE_WITHOUT_LEDGER
ADVANCE_WITHOUT_CASHFLOW
ADVANCE_APPLICATION_MISMATCH
Migration dry-run báo double-count risk
DB/schema không đúng version
```

Audit và dry-run chỉ đọc/mô phỏng. Không tự rebuild cache, sửa anomaly hoặc chạy data migration `--apply`.

## 9. Kết luận sau khi chạy

| Hạng mục | Kết quả |
|---|---|
| Audit all | Chờ chạy |
| Legacy audit | Chờ chạy |
| Payment/CashFlow audit | Chờ chạy |
| Advance audit | Chờ chạy |
| Migration dry-run | Chờ chạy |
| Kết luận | Chưa đủ dữ liệu |
