# PAYROLL PRODUCTION APPROVAL REQUIRED
## 1. Trạng thái

```text
GO/NO-GO: NO-GO
Execution: PAUSED BEFORE BACKUP/APPLY
Production apply executed: No
```

Tài liệu BA request là checklist triển khai, không phải bằng chứng phê duyệt.
Các trường dưới đây phải có giá trị và người duyệt thực tế.

## 2. Production target

| Hạng mục | Giá trị cần điền |
|---|---|
| Production app/service | |
| Production DB host | |
| Production DB port | |
| Production DB name | |
| DevOps xác nhận đây là production live | Chờ xác nhận |
| Người xác nhận | |
| Ngày xác nhận | |

Không mặc định `sales_mysql_test/kiot_db` là production live.

## 3. Go-live và cutoff

| Hạng mục | Giá trị cần điền |
|---|---|
| Go-live date | `YYYY-MM-DD` |
| Cutoff | Toàn bộ số dư trước go-live date được đại diện bằng opening balance |
| Change window bắt đầu | |
| Change window kết thúc | |
| BA/Owner duyệt | Chờ duyệt |

Ngày mô phỏng UAT `2026-06-14` không tự động trở thành ngày go-live production.

## 4. UAT sign-off

| Vai trò | Họ tên | Kết luận | Ngày |
|---|---|---|---|
| BA | | Chờ ký | |
| Kế toán | | Chờ ký | |
| HR | | Chờ ký | |
| Owner | | Chờ ký | |

## 5. Permission approval

Owner phải ký ma trận tại
`docs/audit/PAYROLL-PERMISSION-MATRIX-PROPOSAL.md`, đặc biệt với:

```text
payroll.pay.cancel
payroll.advance.cancel
payroll.adjust
payroll.rebuild_balance
payroll.override_locked_period
payroll.override_backdate_limit
payroll.ledger.export
payroll.reconciliation.export
```

Không permission nào được tự assign production.

## 6. Backup và rollback

| Hạng mục | Trạng thái |
|---|---|
| Production backup được phép chạy | Chờ duyệt |
| Backup/restore verification | Chưa chạy |
| Rollback rehearsal | Chưa chạy |
| Rollback owner | Chưa chỉ định |
| Tech Lead/DevOps thực hiện | Chưa chỉ định |

## 7. Owner final approval

```text
Tôi xác nhận production target, go-live/cutoff, permission matrix, backup,
rollback rehearsal và change window đã được duyệt. Cho phép Agent thực hiện
PAYROLL-PRODUCTION-APPLY-RUNBOOK.md.
```

| Owner | Ngày duyệt | Trạng thái |
|---|---|---|
| | | Chờ ký |

## 8. Điều kiện chuyển GO

Chỉ chuyển `GO` khi tất cả ô bắt buộc đã có giá trị, backup/restore và rollback
rehearsal đã pass, đồng thời Owner final approval đã ký.
