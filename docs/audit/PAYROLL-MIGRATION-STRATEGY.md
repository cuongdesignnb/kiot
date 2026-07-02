# PAYROLL MIGRATION STRATEGY

Quyết định go-live/cutoff: `docs/audit/PAYROLL-GO-LIVE-CUTOFF-DECISION.md`.

## 1. Phạm vi migration

Schema payroll:

- Cache balance và thời điểm tính trên `employees`.
- Payment status trên paysheet/payslip.
- Quan hệ CashFlow, cancel fields và idempotency trên payment.
- `salary_advances`.
- `salary_advance_applications`.
- `employee_salary_ledger_entries`.
- CashFlow `status` nullable để tương thích dữ liệu legacy.

Dữ liệu có thể tạo sau khi được duyệt:

- `opening_balance`, hoặc
- `payroll_accrual` và `salary_payment` backfill.

Không đụng tới:

- Không xóa/sửa `employees.balance`.
- Không xóa chứng từ paysheet/payment/CashFlow cũ.
- Không tự cấp permission.
- Không sửa ledger phát sinh thật.

## 2. Option 1 - Opening balance

Dùng khi chỉ cần số dư cuối tại go-live.

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD
```

Không có `--apply` là dry-run. Điều kiện:

- Danh sách số dư được duyệt.
- Có cutoff/go-live date.
- Không backfill documents cùng giai đoạn.

## 3. Option 2 - Backfill documents

Dùng khi payslip/payment legacy đủ đầy đủ và đối soát được.

```bash
php artisan payroll:migrate-salary-ledger \
  --backfill-documents \
  --legacy-balance=report
```

Dry-run đếm accrual/payment/anomaly, không ghi DB. Không kết hợp `--backfill-documents` với `--legacy-balance=opening`.

## 4. Dry-run

Schema dry-run đã chạy trên local:

```bash
php artisan migrate --pretend \
  --path=database/migrations/2026_06_13_000001_create_employee_salary_ledger_system.php

php artisan migrate --pretend \
  --path=database/migrations/2026_06_13_000002_allow_legacy_null_cash_flow_status.php
```

Kết quả: Laravel sinh SQL thành công, không apply schema. Các thay đổi gồm tạo ba bảng payroll ledger/advance/application, thêm cache/status/cancel/idempotency fields và đổi `cash_flows.status` nullable.

Data dry-run đã chạy trên production copy cô lập và UAT clone, không dùng
`--apply`. Các lệnh tham chiếu:

```bash
php artisan payroll:migrate-salary-ledger --legacy-balance=report
php artisan payroll:migrate-salary-ledger --backfill-documents --legacy-balance=report
```

Lưu output:

```text
storage/app/audit/payroll-migration-dry-run-report.txt
```

## 5. Apply command

Chỉ mô tả, chưa được phép chạy:

```bash
php artisan payroll:migrate-salary-ledger ... --apply
```

Chỉ chạy khi có đủ BA/Owner approval, backup/restore test, anomaly review, UAT sign-off và change window.

## 6. Rollback strategy

- Trước go-live và chưa có giao dịch thật: rollback migration batch hoặc restore backup.
- Sau go-live: không xóa ledger; khóa thao tác mới, export phát sinh, xử lý reversal và triển khai fix.
- Có thể ẩn UI/route bằng deployment rollback hoặc feature control, nhưng giữ dữ liệu để đối soát.
- Không sửa cache trực tiếp để giả lập rollback.

## 7. Checklist trước apply

```text
[ ] Backup DB.
[ ] Restore test pass.
[x] Schema --pretend pass trên local.
[x] Schema dry-run trên production copy pass.
[x] Data migration dry-run pass.
[ ] Anomaly report reviewed.
[ ] Legacy option approved.
[ ] Go-live date/cutoff approved.
[ ] Permission matrix approved.
[ ] UAT signed off.
[ ] Rollback rehearsal approved.
```

## 8. Kết quả dry-run trên staging/production copy

| Hạng mục | Kết quả |
|---|---|
| Ngày chạy | 2026-06-14 |
| Môi trường | Local Docker MariaDB 10.11, production copy cô lập |
| Database source | `kiotdb.zip`, không phải production live |
| Audit database | `kiot_prod_copy_payroll_20260614_003011` |
| Schema migration dry-run | Pass |
| Payroll schema applied trên copy | Pass, batch 52/53 |
| Data migration dry-run | Pass, không dùng `--apply` |
| Số `opening_balance` dự kiến nếu chọn Option A | 1 |
| Số `payroll_accrual` dự kiến nếu backfill | 12 |
| Số `salary_payment` dự kiến nếu backfill | 0 |
| Số `salary_advance` dự kiến nếu backfill | 0 |
| Số anomaly | 7 nhân viên, 8 issue occurrences |
| File output | `storage/app/audit/payroll-migration-dry-run-*.txt` |
| Kết luận | Chưa đủ điều kiện apply; cần xử lý rủi ro double-count/cutoff |

Legacy balance tồn tại trên một nhân viên đồng thời có payslip thuộc paysheet `locked`.
Do đó Option A và document backfill có rủi ro double-count nếu phạm vi thời gian
chồng lấn. Command đã chặn kết hợp hai mode trong cùng lần chạy, nhưng Owner/BA
vẫn phải chọn một strategy và cutoff rõ trước data apply.

### Lệnh cần chạy trên staging/production copy

```bash
php artisan migrate:status

php artisan payroll:migrate-salary-ledger --legacy-balance=report

php artisan payroll:migrate-salary-ledger \
  --backfill-documents \
  --legacy-balance=report
```

### Nguyên tắc

- Không thêm `--apply`.
- Không chạy trên production thật.
- Không tạo `opening_balance` thật nếu chưa có Owner/BA duyệt.
- Không backfill documents thật nếu chưa có Owner/BA duyệt.

## 9. Đối chiếu legacy balance 50,000,000

- Handover:
  `docs/audit/PAYROLL-LEGACY-50M-RECONCILIATION-HANDOVER.md`
- Kết luận: `OPENING_BALANCE_APPROVED`.
- Locked payslip count: 1.
- Locked total salary/remaining: 0/0.
- Legacy balance: 50,000,000.
- Chênh lệch legacy so với locked remaining: 50,000,000.

BA/Owner xác nhận 50,000,000 là số dư thật cũ từ KiotViet. Khoản này sẽ được
migrate bằng `opening_balance`. Không dùng backfill documents để tạo số dư
50,000,000 vì payslip locked hiện tại có total salary/remaining bằng 0.

Ảnh hưởng đến Option A:

- Option A được duyệt cho khoản 50,000,000.
- Trước apply phải có go-live date, cutoff chứng từ, note chuyển đổi,
  backup/restore, UAT và rollback rehearsal.

Ảnh hưởng đến backfill documents:

- Backfill documents không được dùng để giải thích khoản 50,000,000.
- Nếu vẫn cần backfill chứng từ khác, chỉ backfill sau cutoff hoặc phải chứng
  minh không chồng lấn với opening balance.

Recommended migration strategy:

- Dùng opening balance cho legacy balance từ KiotViet.
- Không dùng Option B cho khoản 50,000,000.
- Không backfill documents cùng giai đoạn với opening balance.
- Sau migration, rebuild cache từ ledger bằng command chuẩn khi được duyệt.

Dry-run:

```bash
php artisan payroll:migrate-salary-ledger \
  --legacy-balance=opening \
  --go-live-date=YYYY-MM-DD
```

Không thêm `--apply` cho tới khi production apply được duyệt.

Dry-run ngày mô phỏng `2026-06-14` đã xác minh:

```text
Employee: NV000012
Opening balance count: 1
Amount: 50,000,000
Idempotency key: opening_balance:7:2026-06-14
Ledger count trước/sau: 0/0
```

Còn cần Owner/BA duyệt:

- Go-live date và cutoff.
- Note chuyển đổi chính thức.
- UAT, backup/restore và rollback rehearsal.
