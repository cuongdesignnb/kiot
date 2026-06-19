# PAYROLL PRODUCTION READINESS CHECKLIST

## PACKAGE COMPLETION UPDATE 2026-06-15

```text
[x] PayrollLedgerService contract implemented.
[x] payroll:migrate-salary-ledger supports employee filter and stable idempotency.
[x] payroll:rebuild-salary-balances supports dry-run.
[x] Opening balance tests pass: 10 tests / 16 assertions.
[x] Payroll suite passes: 78 tests / 292 assertions.
[x] Full PHPUnit passes: 1214 tests / 6327 assertions / 5 skipped.
[x] Frontend build passes: 920 modules.
[x] Local isolated migration/dry-run/apply/idempotency rehearsal passes.
[x] Legacy employees.balance remains 50,000,000.
[x] salary_balance_cache becomes 50,000,000.
[x] No fake CashFlow/payment/advance/application is created.
[ ] Repeat rehearsal on exact June 15 production restore (545 CashFlow rows).
```

Current decision:

```text
READY FOR BA REVIEW
NOT READY FOR PRODUCTION APPLY
```

Quyết định go-live/cutoff: `docs/audit/PAYROLL-GO-LIVE-CUTOFF-DECISION.md`.

## 1. Trạng thái test

| Hạng mục | Trạng thái hiện tại | Điều kiện chốt |
|---|---|---|
| Payroll targeted | Đạt: 68 tests, 276 assertions | 0 errors, 0 failures |
| Customer/supplier debt regression | Đạt | Không còn blocker tài chính |
| CashFlow/P&L | Đạt | Payroll payment/advance không double-count |
| Financial/debt targeted | Đạt: 389 tests, 2118 assertions, 1 skipped | 0 errors, 0 failures |
| Full suite | Đạt: 1204 tests, 6311 assertions, 5 skipped | 0 errors, 0 failures |
| Frontend build | Đạt: 917 modules transformed | Vite production build pass |

Bất biến bắt buộc:

```text
Payroll balance = SUM(amount WHERE is_effective = true)
```

Không dùng `status = valid` làm điều kiện duy nhất để tính số dư.

## 2. Trạng thái dữ liệu legacy

- `employees.balance` chỉ dùng cho audit/migration, không ghi mới.
- `employees.salary_balance_cache` là cache từ ledger, không phải source of truth.
- Anomaly/reconciliation service và command read-only đã được chuẩn bị.
- Đã chạy anomaly report trên production copy cô lập, chưa được BA/kế toán review.
- Chưa tự động chuyển `employees.balance`.

Quyết định đã chốt cho khoản legacy 50,000,000:

```text
Option A: chuyển thành opening_balance cho NV000012.
Không backfill documents cho cùng khoản và cùng giai đoạn.
```

Còn phải chốt `go_live_date`, cutoff và người ký duyệt chính thức.

## 3. Quyết định migration

Chưa được phép apply migration production.

Owner/BA đã chọn opening balance cho khoản 50,000,000. Không backfill documents
và tạo opening balance trùng cùng giai đoạn.

Go-live date đề xuất: ngày bắt đầu kỳ lương đầu tiên vận hành ledger, sau khi khóa dữ liệu cũ và hoàn tất anomaly review.

Rollback plan bắt buộc:

1. Backup DB và xác minh restore.
2. Ghi nhận migration batch/version.
3. Không xóa bảng/field legacy trong rollout đầu.
4. Có script rollback schema đã dry-run.
5. Feature vẫn có thể đọc dữ liệu cũ nếu rollout bị dừng.
6. Không rollback bằng cách xóa ledger phát sinh thật sau go-live.

## 4. Ma trận quyền production

Không tự cấp các quyền dưới đây cho role thật trước khi Owner duyệt.

| Role đề xuất | Quyền đề xuất |
|---|---|
| `super_admin` | Toàn bộ quyền payroll |
| Kế toán trưởng | View/export ledger, pay/cancel, advance create/cancel, adjust, reconciliation, override |
| Kế toán | View/export ledger, pay, advance create, reconciliation view |
| HR/quản lý nhân sự | Payroll view/create/edit/lock, ledger view |
| `branch_admin` | Payroll/ledger/payment trong branch, không rebuild/adjust/override |
| `cashier` | Không xem lương nếu chưa được cấp riêng |
| `warehouse_staff`, `task_manager` | Không xem lương |

Quyền cần Owner xác nhận:

```text
payroll.ledger.view
payroll.ledger.export
payroll.pay
payroll.pay.cancel
payroll.advance.create
payroll.advance.cancel
payroll.adjust
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
payroll.reconciliation.view
payroll.reconciliation.export
```

## 5. Rule vận hành cần chốt

### Hạn mức tạm ứng

Owner chọn không giới hạn, giới hạn số tiền, giới hạn phần trăm hoặc cho override có lý do.

Config đề xuất để review:

```text
salary_advance_max_percent = 50
salary_advance_max_amount = 5000000
```

### Quyền hủy payment/advance

Mặc định an toàn khi chưa chốt: chỉ kế toán trưởng/admin được hủy.

### Nhân viên nghỉ việc còn số dư

Đề xuất BA: bắt buộc xác nhận nếu số dư khác 0, không chặn hoàn toàn việc nghỉ.

### Backdate và kỳ khóa

- Nếu có kỳ khóa: chặn nghiệp vụ trong kỳ khóa, trừ quyền override và có lý do.
- Nếu chưa có kỳ khóa: giới hạn lùi ngày 30 ngày.
- Override yêu cầu quyền riêng và ActivityLog.

### Rebuild balance

- Chỉ rebuild cache và `balance_after`, không sửa `amount`.
- Chạy transaction, lock employee và có audit log.
- Audit/reconciliation command luôn read-only.

## 6. Checklist trước rollout

```text
[x] Full suite 0 errors, 0 failures.
[x] Frontend production build pass.
[ ] Backup DB hoàn chỉnh và restore test pass.
[x] Migration dry-run trên bản sao production.
[ ] Anomaly report theo branch/employee đã review.
[x] Legacy 50M reconciliation reviewed by BA/kế toán.
[x] Migration strategy selected after 50M reconciliation.
[x] Owner chọn Option A cho employees.balance.
[x] Owner chọn opening balance cho legacy balance 50,000,000.
[ ] Go-live date được duyệt.
[ ] Cutoff chứng từ được duyệt.
[x] UAT opening balance từ KiotViet pass technical + Docker UI/API.
[ ] Permission matrix được duyệt.
[ ] Backup DB hoàn chỉnh và restore test pass.
[ ] Rollback rehearsal pass.
[ ] Người chịu trách nhiệm rollback được chỉ định.
[ ] Production Apply Runbook được duyệt.
[ ] Go/No-Go report được Owner ký.
[ ] Không quyền payroll nhạy cảm nào được cấp ngầm.
[ ] Hạn mức advance và quyền override được duyệt.
[ ] Rule nghỉ việc còn số dư được duyệt.
[ ] Rule hủy payment/advance tại branch được duyệt.
[ ] CashFlow/P&L reconciliation được kế toán xác nhận.
[ ] Rollback plan và người chịu trách nhiệm được duyệt.
[ ] UAT sign-off của BA/kế toán/HR.
```

## 7. Kết luận

Trạng thái hiện tại:

```text
SẴN SÀNG CHUẨN BỊ PRODUCTION ROLLOUT
CHƯA ĐƯỢC PHÉP APPLY PRODUCTION
```

Các điều kiện còn thiếu trước production:

- BA/kế toán review anomaly report từ production copy.
- Owner/BA chốt go-live date và cutoff.
- Owner duyệt permission matrix và các rule vận hành.
- Hoàn tất migration dry-run, rollback rehearsal và UAT sign-off.

## 8. Rollout package

| Deliverable | Trạng thái | Người phụ trách | Điều kiện hoàn thành | Link/File output |
|---|---|---|---|---|
| `PAYROLL-LEGACY-BALANCE-DECISION-PACK.md` | Approved | BA/Owner | Option A đã duyệt; go-live/cutoff còn chờ | `docs/audit/PAYROLL-LEGACY-BALANCE-DECISION-PACK.md` |
| `PAYROLL-MIGRATION-STRATEGY.md` | Waiting Owner Decision | Dev/BA | Option A đã chọn; go-live/cutoff cần duyệt | `docs/audit/PAYROLL-MIGRATION-STRATEGY.md` |
| `PAYROLL-PERMISSION-MATRIX-PROPOSAL.md` | Waiting Owner Decision | Owner/BA | Owner duyệt quyền theo role | `docs/audit/PAYROLL-PERMISSION-MATRIX-PROPOSAL.md` |
| `PAYROLL-UAT-CHECKLIST.md` | Ready for BA Review | BA/Kế toán/HR | UAT pass và có sign-off | `docs/audit/PAYROLL-UAT-CHECKLIST.md` |
| `PAYROLL-UAT-SIGNOFF-PACK.md` | Ready for Sign-off | BA/Kế toán/HR/Owner | Đủ chữ ký UAT | `docs/audit/PAYROLL-UAT-SIGNOFF-PACK.md` |
| `PAYROLL-ROLLBACK-PLAN.md` | Ready for BA Review | Tech Lead/DevOps/BA | Có người chịu trách nhiệm và rehearsal pass | `docs/audit/PAYROLL-ROLLBACK-PLAN.md` |
| `PAYROLL-STAGING-AUDIT-RUNBOOK.md` | Done | Dev/BA | Audit/dry-run production copy đã có output | `docs/audit/PAYROLL-STAGING-AUDIT-RUNBOOK.md` |
| `PAYROLL-PRODUCTION-COPY-IMPORT-AND-TEST-HANDOVER.md` | Ready for BA Review | Dev/BA | BA/kế toán review anomaly và migration risk | `docs/audit/PAYROLL-PRODUCTION-COPY-IMPORT-AND-TEST-HANDOVER.md` |
| `PAYROLL-GO-LIVE-CUTOFF-DECISION.md` | Waiting Owner Decision | BA/Owner | Go-live và cutoff được ký duyệt | `docs/audit/PAYROLL-GO-LIVE-CUTOFF-DECISION.md` |
| `PAYROLL-BACKUP-RESTORE-VERIFICATION.md` | Draft | DevOps/DBA | Backup thật và restore test pass | `docs/audit/PAYROLL-BACKUP-RESTORE-VERIFICATION.md` |
| `PAYROLL-ROLLBACK-REHEARSAL-REPORT.md` | Draft | Tech Lead/DevOps | Rehearsal pass và có người chịu trách nhiệm | `docs/audit/PAYROLL-ROLLBACK-REHEARSAL-REPORT.md` |
| `PAYROLL-PRODUCTION-APPLY-RUNBOOK.md` | Ready for BA Review | Tech Lead/DevOps/BA | Runbook và change window được duyệt | `docs/audit/PAYROLL-PRODUCTION-APPLY-RUNBOOK.md` |
| `PAYROLL-GO-NO-GO-PRODUCTION-REPORT.md` | Ready for BA Review | BA/Owner | Owner ký quyết định cuối | `docs/audit/PAYROLL-GO-NO-GO-PRODUCTION-REPORT.md` |
| `PAYROLL-PRODUCTION-APPROVAL-REQUIRED.md` | Waiting Owner Decision | Owner/DevOps/BA | Điền production target, ngày và chữ ký | `docs/audit/PAYROLL-PRODUCTION-APPROVAL-REQUIRED.md` |

Trạng thái hợp lệ:

```text
Draft
Ready for BA Review
Waiting Owner Decision
Approved
Done
Blocked
```

Trạng thái vận hành hiện tại:

```text
UAT: Opening balance technical và Docker UI/API pass; chưa sign-off con người
Audit production copy: Đã chạy, chờ review
Migration dry-run production copy: Pass, không dùng --apply
Backup/restore: Chưa xác nhận
Rollback rehearsal: Chưa chạy
```

## 9. Audit và dry-run

Môi trường audit là production copy cô lập trong Docker, không phải production live.

```text
Audit DB: kiot_prod_copy_payroll_20260614_003011
Engine: MariaDB 10.11
Payroll schema migrations trên copy: APPLIED
Schema migration --pretend: PASS
Data audit/anomaly: PASS, 0 CRITICAL, 7 HIGH, 1 MEDIUM issue occurrence
Data migration dry-run: PASS, không dùng --apply
Double-count risk: CÓ nếu opening balance và backfill chồng lấn
```

Đối chiếu riêng legacy 50M đã hoàn tất về mặt kỹ thuật:

```text
Legacy balance: 50,000,000
Locked payslip count: 1
Locked total salary/remaining: 0/0
Difference: 50,000,000
Suggested action: OPENING_BALANCE_APPROVED
```

Handover:
`docs/audit/PAYROLL-LEGACY-50M-RECONCILIATION-HANDOVER.md`.

Trạng thái quyết định:

```text
Legacy 50M: Đã xác nhận là số dư thật từ KiotViet
Migration decision cho 50M: Opening balance approved
Opening balance approved dry-run: Pass, 1 entry / 50,000,000 / no DB write
Production apply: Chưa được phép
```

Kết quả xác minh tooling ngày `2026-06-14`:

```text
PHP lint: PASS
git diff --check: PASS (có cảnh báo line ending, không có whitespace error)
Payroll targeted: 68 tests, 276 assertions, 0 errors, 0 failures
Full suite: 1204 tests, 6311 assertions, 5 skipped, 0 errors, 0 failures
Frontend build: PASS, 917 modules transformed
```

Các file anomaly cần tạo trên staging:

```text
storage/app/audit/payroll-ledger-anomaly-all.csv
storage/app/audit/payroll-ledger-anomaly-all.json
storage/app/audit/payroll-cache-audit.csv
storage/app/audit/payroll-payment-cashflow-audit.csv
storage/app/audit/payroll-advance-audit.csv
storage/app/audit/payroll-legacy-balance-audit.csv
```

Các file trên đã được tạo từ production copy và bị loại khỏi Git để không phát
tán dữ liệu. Chi tiết tổng hợp nằm trong handover, không đưa PII vào tài liệu Git.

## 10. Quyết định còn chờ

```text
[x] Option A opening balance cho employees.balance.
[x] Không dùng backfill documents để tạo khoản legacy 50,000,000.
[ ] Go-live date và cutoff.
[ ] Permission matrix.
[ ] Hạn mức advance và override.
[ ] Rule inactive employee còn số dư.
[ ] Quyền cancel payment/advance tại branch.
[ ] Người chịu trách nhiệm rollback.
[ ] UAT sign-off.
```

## 11. Trạng thái Go/No-Go ngày 2026-06-14

```text
NO-GO
CHƯA ĐỦ ĐIỀU KIỆN PRODUCTION APPLY
```

UAT DB `kiot_payroll_uat_20260614_165946` đã technical apply và Docker UI/API
verify thành công 5/5 case opening balance. Checklist production chưa tick UAT
pass vì BA/kế toán/HR/Owner chưa ký sign-off. Backup/restore production và
rollback rehearsal chưa chạy thật.

## 12. Final Production Gate Audit

Kiểm tra ngày `2026-06-14`:

```text
Container đang chạy: kiotviet-app-clone
APP_ENV: production
DB_HOST: mysql
DB_DATABASE: kiot_db
Docker DB service: sales_mysql_test
Production live được Owner/DevOps xác nhận: Chưa
```

Tên `APP_ENV=production` không đủ để chứng minh đây là production live. Không
backup, maintenance mode, migrate hoặc apply vào target này cho tới khi
Owner/DevOps xác nhận rõ host/database/change window bằng văn bản.

Final blockers:

```text
[ ] Production target thật được định danh và xác nhận.
[ ] UAT sign-off BA/kế toán/HR/Owner.
[ ] Go-live date/cutoff approved.
[ ] Permission matrix approved.
[ ] Backup production thật và restore test pass.
[ ] Rollback rehearsal pass.
[ ] Rollback owner assigned.
[ ] Change window approved.
[ ] Owner final approval.
```
