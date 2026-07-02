# PAYROLL ROLLBACK REHEARSAL REPORT

## 1. Mục tiêu

Diễn tập rollback payroll migration trước production apply.

## 2. Nguyên tắc

```text
1. Trước go-live và chưa có giao dịch thật: restore backup hoặc rollback đúng migration batch.
2. Sau go-live: không xóa ledger thật; khóa thao tác mới, export dữ liệu và xử lý bằng reversal/fix.
3. Không sửa trực tiếp salary_balance_cache.
4. Không xóa ledger phát sinh thật sau go-live.
```

## 3. Scenario rehearsal

| Scenario | Cách xử lý | Kết quả |
|---|---|---|
| Schema migration lỗi trước data apply | Restore backup hoặc rollback migration batch | Chưa chạy |
| Opening balance apply lỗi giữa chừng | Dừng, điều tra; restore nếu chưa có giao dịch thật | Chưa chạy |
| Post-apply audit có CRITICAL | Maintenance mode, export log, kích hoạt rollback plan | Chưa chạy |
| UI lỗi sau deploy | Rollback code/deploy, giữ DB để đối soát | Chưa chạy |
| Permission cấp sai | Thu hồi quyền, audit activity log | Chưa chạy |

## 4. Người chịu trách nhiệm

| Vai trò | Họ tên | Trách nhiệm |
|---|---|---|
| Owner | Chờ chỉ định | Quyết định rollback |
| Tech Lead/DevOps | Chờ chỉ định | Thực hiện rollback kỹ thuật |
| BA | Chờ chỉ định | Xác nhận nghiệp vụ |
| Kế toán | Chờ chỉ định | Xác nhận số dư và dòng tiền |

## 5. Kết luận

```text
[ ] Rollback rehearsal pass.
[ ] Rollback rehearsal fail.
[x] Chưa chạy.
```

Không production apply nếu rehearsal chưa pass hoặc chưa có người chịu trách nhiệm rollback.

Blocker hiện tại: chưa có rollback owner và chưa có phê duyệt rehearsal thật.
Production apply tiếp tục bị chặn.

Final gate audit: chưa có production backup đã verify và chưa có rollback owner,
do đó không thể chạy rehearsal hợp lệ.

## 6. Checklist rehearsal bắt buộc

```text
[ ] Restore backup vào DB rehearsal.
[ ] Apply schema payroll trên rehearsal.
[ ] Apply opening_balance trên rehearsal.
[ ] Chạy post-apply audit.
[ ] Thử restore backup hoặc rollback migration batch khi chưa có giao dịch thật.
[ ] Verify DB sau rollback.
[ ] Chỉ định rollback owner.
```
