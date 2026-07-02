# PAYROLL PRODUCTION COPY IMPORT AND TEST HANDOVER

## 1. Thông tin database import

| Hạng mục | Giá trị |
|---|---|
| File zip | `kiotdb.zip` |
| SQL file extracted | `kiot_db_2026-06-14_00-22-07_mysql_data_PZbx8.sql` |
| SQL file size | 3,375,688 bytes |
| Audit DB | `kiot_prod_copy_payroll_20260614_003011` |
| Container | `kiot_payroll_audit_mariadb` |
| Import time | 2026-06-14 00:30:14 +07:00 |
| APP_ENV khi audit | `staging` |
| Có phải production live không | Không |

Dump nguồn là MariaDB 10.11. Import thử vào MySQL 8 bị chặn bởi khác biệt DDL
TEXT default; database copy import dở đã được xóa. Dump nguyên bản sau đó được
import thành công vào MariaDB 10.11 cô lập, không chỉnh sửa nội dung dump.

## 2. Kiểm tra dữ liệu sau import

| Bảng | Số dòng |
|---|---:|
| `employees` | 7 |
| `users` | 5 |
| `cash_flows` | 543 |
| `paysheets` | 8 |
| `payslips` | 37 |
| `paysheet_payments` | 0 |
| `salary_advances` | 0 |
| `employee_salary_ledger_entries` | 0 |

## 3. Schema status

- Trước schema: chỉ hai migration payroll `000001` và `000002` pending.
- Không có pending migration ngoài payroll.
- Schema `--pretend`: pass cho cả hai migration.
- Sau schema: payroll migration batch 52 và 53 đã chạy trên database copy.
- Payroll schema applied on copy: Yes.

## 4. Audit/anomaly result

| Chỉ số | Kết quả |
|---|---:|
| `total_employees` | 7 |
| `ok_count` | 0 |
| `issue_count` | 7 |
| `critical_issue_count` | 0 |
| `high_issue_count` | 7 |
| `medium_issue_count` | 1 issue occurrence |
| `low_issue_count` | 0 |
| `legacy_balance_count` | 1 |
| `cache_mismatch_count` | 0 |
| `missing_cache_count` | 7 |
| `negative_balance_count` | 0 |
| `outstanding_advance_count` | 0 |
| `payment_cashflow_issue_count` | 0 |
| `advance_issue_count` | 0 |

Issue types:

```text
MISSING_CACHE: 7
LEGACY_BALANCE_EXISTS: 1
```

Không rebuild cache và không sửa anomaly.

## 5. Migration dry-run result

| Hạng mục | Kết quả |
|---|---|
| Legacy-balance report | 1 anomaly |
| Backfill documents dry-run | 12 payroll accrual, 0 payment |
| Opening balance simulation | 1 opening balance tại ngày giả định 2026-06-14 |
| Approved opening balance dry-run | `NV000012`, 1 entry, 50,000,000, ledger trước/sau 0/0 |
| Dữ liệu ghi trước/sau dry-run | Ledger/advance/application đều `0,0,0` |
| Double-count risk | Potential theo phạm vi/cutoff; không phát sinh số học từ locked payslip 50M vì amount = 0 |
| Output files | `storage/app/audit/payroll-migration-dry-run-*.txt` |

Đối chiếu chi tiết xác nhận locked payslip của nhân viên legacy có total salary
và remaining bằng 0. BA/Owner sau đó xác nhận 50,000,000 là số dư thật cũ từ
KiotViet và duyệt Option A opening balance. Go-live date/cutoff vẫn chờ chốt.

## 6. Automated tests

Test chạy trên database riêng `kiot_test_payroll_20260614_003347`, không chạy
trên audit DB.

| Nhóm test | Kết quả |
|---|---|
| Payroll targeted | Pass: 67 tests, 270 assertions |
| Financial targeted | Pass: 132 tests, 718 assertions |
| Customers targeted | Pass: 154 tests, 819 assertions, 1 skipped |
| Suppliers targeted | Pass: 103 tests, 581 assertions |
| Full suite | Pass: 1203 tests, 6305 assertions, 5 skipped |
| Frontend build | Pass: 920 modules transformed |

Test DB mới cần chạy `migrate:fresh` trước vì một số test cũ dùng
`DatabaseTransactions` và giả định schema đã tồn tại. Việc này chỉ diễn ra trên
test DB đã xác minh.

## 7. BA risk assessment

| Rủi ro | Mức độ | Ghi chú |
|---|---|---|
| Có CRITICAL anomaly | Không | Audit không phát hiện CRITICAL document issue |
| Có double-count risk | Decision risk | Không quan sát double-count số học ở locked payslip 50M; vẫn phải tránh chồng cutoff |
| Có legacy balance chưa xử lý | Controlled | BA/Owner duyệt migrate 50,000,000 bằng opening balance tại cutoff/go-live |
| Có cache mismatch | High | 7 missing cache do schema mới, chưa rebuild |
| Có payment/CashFlow mismatch | Không | Không có legacy payment trong copy |
| Có advance mismatch | Không | Không có advance trong copy |

## 8. Khuyến nghị BA

```text
[x] Đề xuất tiếp tục UAT sau khi có go-live/cutoff.
[x] Đề xuất Owner chọn Option A opening_balance.
[ ] Đề xuất Owner chọn Option B anomaly only.
[ ] Đề xuất xử lý/quyết định anomaly và cutoff trước.
[x] Đề xuất chưa production.
[ ] Đủ điều kiện đề xuất production apply runbook.
```

Option A đã được duyệt cho khoản 50,000,000. UAT vẫn chờ go-live date/cutoff;
production apply vẫn chờ permission approval, backup/restore và rollback
rehearsal.

## 9. Kết luận

- Có đủ điều kiện production chưa: Không.
- Lý do: chưa có go-live/cutoff, UAT, permission approval, backup/restore test và
  rollback rehearsal.
- Việc tiếp theo: chốt go-live/cutoff và chạy UAT opening balance trên staging.
- Data migration `--apply`: Không chạy.
- Production live: Không truy cập và không thay đổi.
