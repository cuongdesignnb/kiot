# PAYROLL PRODUCTION APPLY RUNBOOK

## PACKAGE UPDATE 2026-06-15

The repository now includes both rollout commands:

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD \
  --employee-code=NV000012

php artisan payroll:rebuild-salary-balances \
  --employee-code=NV000012 \
  --dry-run
```

After BA approves the dry-run, add `--apply` only to the migration command. Run it a second time and require `SKIPPED` with no duplicate row.

The local isolated rehearsal passed on the June 14 production copy. It does not replace rehearsal on the exact June 15 backup identified by SHA256:

```text
399a760ae933b147b3039228357a1467bc4817b7712c398ea95b61b88fcc5b71
```

Production remains blocked until the exact restore passes and BA/Owner approve the change window.

## 1. Trạng thái

```text
Production apply: CHƯA ĐƯỢC PHÉP
```

Runbook chỉ dùng để chuẩn bị. Không chạy nếu chưa có Go/No-Go approval riêng.

```text
Không chạy production nếu UAT apply chưa pass.
Không chạy production nếu UAT chỉ mới dry-run.
Không chạy production nếu cache/timeline/report chưa được xác nhận hoặc chưa
được Owner chấp nhận trạng thái partial.
```

## 2. Điều kiện trước apply

| Điều kiện | Trạng thái |
|---|---|
| Go-live date/cutoff approved | Chưa |
| UAT sign-off | Chưa |
| Permission matrix approved | Chưa |
| Backup/restore test pass | Chưa |
| Rollback rehearsal pass | Chưa |
| Rollback owner assigned | Chưa |
| Change window approved | Chưa |
| Owner final approval | Chưa |
| Production target verified by Owner/DevOps | Chưa |

Bất kỳ dòng nào chưa đạt đều dẫn tới `NO-GO`.

Các chặn này áp dụng bắt buộc:

```text
[x] Không chạy nếu Go/No-Go vẫn NO-GO.
[x] Không chạy nếu UAT sign-off chưa pass.
[x] Không chạy nếu backup/restore chưa pass.
[x] Không chạy nếu rollback rehearsal chưa pass.
[x] Không chạy nếu permission matrix chưa duyệt.
[x] Không chạy nếu go-live/cutoff chưa duyệt.
[x] Có post-apply verification.
[x] Có query kiểm tra idempotency trùng.
```

## 3. Migration đã được duyệt về nghiệp vụ

| Hạng mục | Giá trị |
|---|---|
| Migration option | Option A - Opening balance |
| Employee code | `NV000012` |
| Amount | 50,000,000 |
| Source | KiotViet |
| Ledger type | `opening_balance` |
| Note | Số dư lương chuyển đổi từ hệ thống KiotViet |
| Go-live date | Chờ duyệt |
| Cutoff | Chờ duyệt |

## 4. Pre-apply

```bash
php artisan down
php artisan migrate:status
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

Trước khi maintenance mode phải có backup đã kiểm tra checksum và restore test pass.

## 5. Schema migration

Chỉ chạy các migration payroll còn pending và chỉ sau approval:

```bash
php artisan migrate --force \
  --path=database/migrations/2026_06_13_000001_create_employee_salary_ledger_system.php

php artisan migrate --force \
  --path=database/migrations/2026_06_13_000002_allow_legacy_null_cash_flow_status.php
```

## 6. Data migration Option A

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD \
  --apply
```

Không chạy backfill documents cho cùng giai đoạn.

## 7. Rebuild cache

Project hiện không có Artisan command rebuild salary balance. Các thao tác thật đang có là:

```http
POST /api/employees/{employee}/rebuild-salary-balance
POST /api/payroll/rebuild-salary-balances
```

Hai API yêu cầu quyền `payroll.rebuild_balance`. Production chỉ được gọi bởi người đã duyệt, trong change window và sau khi kiểm tra ledger. Rebuild chỉ cập nhật cache và `balance_after`; không sửa `amount`, không tạo ledger ngoài opening balance đã duyệt.

## 8. Post-apply verification

```text
[ ] NV000012 có đúng 1 opening_balance.
[ ] Amount = 50,000,000.
[ ] is_effective = true.
[ ] Effective ledger balance đúng.
[ ] salary_balance_cache khớp ledger sau rebuild.
[ ] Không có payment giả.
[ ] Không có CashFlow giả.
[ ] Timeline hiển thị note KiotViet.
[ ] Báo cáo nợ lương đúng.
[ ] Audit không có CRITICAL.
```

Kiểm tra trùng idempotency:

```sql
SELECT employee_id, type, amount, idempotency_key, COUNT(*) AS cnt
FROM employee_salary_ledger_entries
WHERE type = 'opening_balance'
GROUP BY employee_id, type, amount, idempotency_key
HAVING cnt > 1;
```

Kỳ vọng: không có dòng trùng.

## 9. Bring app online

```bash
php artisan up
```

## 10. Rollback trigger

- Migration lỗi hoặc apply không hoàn tất.
- Opening balance sai tiền hoặc bị tạo trùng.
- Effective ledger balance lệch cache.
- Audit post-apply có CRITICAL.
- CashFlow/P&L hoặc timeline sai nghiêm trọng.
- Permission nhạy cảm bị cấp sai.

## 11. Kết luận

```text
[ ] Apply success.
[ ] Apply failed and rollback triggered.
[x] Apply paused for manual review.
[ ] Chưa chạy.
```

Lý do pause ngày `2026-06-14`:

```text
Docker app hiện có APP_ENV=production nhưng trỏ DB service local
sales_mysql_test/kiot_db. Chưa có xác nhận production live target.
Các approval vận hành bắt buộc vẫn chưa được ký.
```
