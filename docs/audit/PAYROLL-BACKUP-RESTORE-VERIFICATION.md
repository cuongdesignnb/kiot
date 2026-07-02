# PAYROLL BACKUP & RESTORE VERIFICATION

## 1. Mục tiêu

Xác minh production DB có thể backup và restore trước khi apply payroll migration.

## 2. Backup checklist

| Hạng mục | Kết quả |
|---|---|
| Backup file name | Chờ chạy |
| Backup timestamp | Chờ chạy |
| Backup size | Chờ chạy |
| Checksum | Chờ chạy |
| Người thực hiện | Chờ chỉ định |
| Production downtime required | Chờ xác nhận |

## 3. Restore checklist

| Hạng mục | Kết quả |
|---|---|
| Restore environment | Chờ chạy |
| Restore DB name | Chờ chạy |
| Restore success | Chờ chạy |
| App kết nối được DB restore | Chờ chạy |
| `migrate:status` chạy được | Chờ chạy |
| Audit command chạy được | Chờ chạy |

## 4. Commands đề xuất

```bash
mysqldump -h <host> -P <port> -u <user> -p \
  --single-transaction --routines --triggers --events \
  <production_db> > backup_before_payroll_YYYYMMDD_HHMMSS.sql

sha256sum backup_before_payroll_YYYYMMDD_HHMMSS.sql \
  > backup_before_payroll_YYYYMMDD_HHMMSS.sql.sha256

mysql -h <restore_host> -P <port> -u <user> -p \
  -e "CREATE DATABASE payroll_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

mysql -h <restore_host> -P <port> -u <user> -p \
  payroll_restore_test < backup_before_payroll_YYYYMMDD_HHMMSS.sql
```

Không commit dump, checksum có đường dẫn nội bộ hoặc dữ liệu PII lên Git.

## 5. Kết luận

```text
[ ] Backup/restore test pass.
[ ] Backup/restore test fail.
[x] Chưa chạy.
```

Không production apply nếu restore test chưa pass.

Production apply bị chặn cho tới khi backup thật và restore test pass.

Blocker hiện tại: chưa có phê duyệt/chỉ định để chạy backup production thật và
restore verification, do đó không thực hiện trong phase Docker UAT này.

Final gate audit xác nhận chưa định danh được production live database. Không
được dùng backup của `sales_mysql_test/kiot_db` để thay thế bằng chứng backup
production thật.
