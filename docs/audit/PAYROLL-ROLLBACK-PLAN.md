# PAYROLL ROLLBACK PLAN

## 1. Nguyên tắc

- Không xóa ledger phát sinh thật sau go-live.
- Không rollback bằng cách sửa số dư/cache trực tiếp.
- Rollback schema chỉ áp dụng nếu chưa có giao dịch thật.
- Sau go-live, ưu tiên khóa thao tác, giữ dữ liệu và đối soát.

## 2. Trước rollout

```text
[ ] Backup DB có timestamp/checksum.
[ ] Restore backup trên môi trường cô lập.
[ ] Ghi migration batch/version.
[ ] Lưu schema diff và --pretend output.
[ ] Lưu anomaly JSON/CSV baseline.
[ ] Export permission state baseline.
[ ] Chốt người ra quyết định rollback.
```

## 3. Rollback trong ngày rollout, chưa có giao dịch thật

1. Đóng maintenance/change window.
2. Xác nhận không có ledger/payment/advance mới.
3. Rollback đúng migration batch hoặc restore backup.
4. Rollback deployment/UI.
5. Xác minh payroll cũ, CashFlow và reports.
6. Lập incident record.

## 4. Rollback sau khi có giao dịch thật

1. Không chạy migration rollback phá bảng.
2. Khóa tạo/chốt/pay/advance/cancel mới.
3. Export ledger, payment, advance, CashFlow và activity logs.
4. Đối soát theo employee/branch.
5. Dùng reversal/cancel flow nếu nghiệp vụ cần đảo.
6. Triển khai hotfix qua staging/UAT.
7. Re-enable sau khi kế toán xác nhận.

## 5. Người chịu trách nhiệm

| Vai trò | Trách nhiệm | Người được chỉ định |
|---|---|---|
| Tech Lead | Quyết định kỹ thuật, migration/deployment | Chờ chỉ định |
| BA | Xác nhận nghiệp vụ và phạm vi ảnh hưởng | Chờ chỉ định |
| Kế toán trưởng/Owner | Xác nhận số liệu tài chính | Chờ chỉ định |
| DevOps/DBA | Backup, restore, deployment rollback | Chờ chỉ định |

## 6. Rollback rehearsal

```text
[ ] Restore backup thành công.
[ ] Migration apply trên copy thành công.
[ ] Rollback trước giao dịch thành công.
[ ] App phiên bản cũ đọc được dữ liệu còn lại.
[ ] Feature lock procedure được thử.
[ ] Export incident data được thử.
[ ] Reconciliation sau restore/rollback khớp.
[ ] Thời gian rollback nằm trong RTO được duyệt.
[ ] Owner ký biên bản rehearsal.
```

## 7. Rollback Decision Matrix

| Tình huống | Mức độ | Có rollback không? | Người quyết định | Hành động |
|---|---|---|---|---|
| Migration lỗi trước khi có giao dịch thật | Critical | Có thể rollback schema/restore | Tech Lead + DevOps + BA | Dừng rollout, rollback batch hoặc restore backup |
| Lỗi UI nhưng API và dữ liệu đúng | Medium | Không rollback DB | Tech Lead + BA | Ẩn UI hoặc hotfix frontend |
| Sai số dư ledger sau migration | Critical | Không tự sửa trực tiếp | Tech Lead + BA + Kế toán trưởng | Khóa thao tác, đối soát; rollback nếu chưa có giao dịch thật hoặc xử lý reversal nếu đã go-live |
| Sai CashFlow/P&L | Critical | Có thể tạm dừng feature | Tech Lead + Kế toán trưởng | Khóa payment/advance, đối soát CashFlow, hotfix |
| Sai permission production | High | Không rollback DB | Owner + Tech Lead | Thu hồi quyền, kiểm tra ActivityLog |
| Người dùng đã tạo payment/advance thật | Critical | Không rollback bằng xóa ledger | Tech Lead + BA + Kế toán trưởng | Tạm khóa thao tác, export phát sinh, xử lý bằng reversal/cancel flow |
| Audit phát hiện CRITICAL anomaly trước apply | Critical | Không apply | BA + Owner | Dừng rollout, xử lý anomaly trước |

## 8. RTO/RPO đề xuất

| Chỉ số | Đề xuất | Owner duyệt |
|---|---|---|
| RTO | Chờ Owner/Tech Lead xác nhận | Chờ duyệt |
| RPO | Chờ Owner/Tech Lead xác nhận | Chờ duyệt |
| Thời gian tối đa restore DB | Chờ DevOps xác nhận | Chờ duyệt |
| Thời gian tối đa rollback rehearsal | Chờ DevOps xác nhận | Chờ duyệt |

Gợi ý ban đầu để review:

```text
RTO đề xuất: 2 giờ
RPO đề xuất: backup ngay trước change window
Rollback rehearsal phải hoàn thành trước rollout thật
```

## 9. Người chịu trách nhiệm cần chỉ định

| Vai trò | Người được chỉ định | Trạng thái |
|---|---|---|
| Tech Lead | Chờ chỉ định | Chưa duyệt |
| BA | Chờ chỉ định | Chưa duyệt |
| Kế toán trưởng/Owner | Chờ chỉ định | Chưa duyệt |
| DevOps/DBA | Chờ chỉ định | Chưa duyệt |
