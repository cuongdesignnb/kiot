# PAYROLL KIOTVIET OPENING BALANCE APPROVAL

Quyết định go-live/cutoff: `docs/audit/PAYROLL-GO-LIVE-CUTOFF-DECISION.md`.

## 1. Mục tiêu

Ghi nhận quyết định BA/Owner về khoản số dư lương/tạm ứng cũ chuyển từ hệ thống
KiotViet sang hệ thống mới.

## 2. Quyết định

| Hạng mục | Giá trị |
|---|---|
| Employee code | `NV000012` |
| Employee name | Masked |
| Legacy field | `employees.balance` |
| Legacy amount | 50,000,000 |
| Source system | KiotViet |
| Decision | `OPENING_BALANCE_APPROVED` |
| Migration option | Option A - Opening balance |
| Ledger type | `opening_balance` |
| Ledger amount | 50,000,000 |
| Is effective | `true` |
| Production apply | Chưa được phép |

## 3. Lý do

Khoản 50,000,000 đã được BA/Owner xác nhận là số dư thật cũ từ KiotViet.

Đối chiếu kỹ thuật cho thấy locked payslip hiện tại không giải thích số dư:

| Chỉ số | Giá trị |
|---|---:|
| Locked payslip count | 1 |
| Locked total salary | 0 |
| Locked remaining | 0 |
| Ledger balance hiện tại | 0 |
| Payment/CashFlow/Advance liên quan | 0 |

Do đó, khoản này phải được đưa vào ledger bằng `opening_balance`, không xử lý
bằng backfill payslip.

## 4. Business rules

```text
1. Không sửa trực tiếp employees.balance.
2. Không sửa trực tiếp salary_balance_cache.
3. Không tạo payment/CashFlow giả.
4. Không dùng backfill payslip để tạo khoản 50,000,000.
5. Chỉ tạo opening_balance sau khi có go-live date/cutoff được duyệt.
6. Sau migration, cache phải được rebuild từ ledger bằng command chuẩn nếu có.
7. Timeline phải hiển thị opening_balance với note chuyển đổi từ KiotViet.
```

## 5. Note đề xuất cho ledger

```text
Số dư lương chuyển đổi từ hệ thống KiotViet
```

## 6. Thông tin cần chốt trước production apply

| Hạng mục | Trạng thái |
|---|---|
| Go-live date | Chờ duyệt |
| Cutoff chứng từ | Chờ duyệt |
| Người duyệt chính thức | Chờ điền |
| Ngày duyệt chính thức | Chờ điền |
| UAT timeline/số dư | Chưa chạy |
| Backup/restore | Chưa chạy |
| Rollback rehearsal | Chưa chạy |

## 7. Dry-run đã xác minh

Ngày mô phỏng: `2026-06-14` vì go-live date chính thức chưa được duyệt.

```text
Mode: DRY-RUN
Employee code: NV000012
Opening entries: 1
Amount: 50,000,000
Idempotency key: opening_balance:7:2026-06-14
Ledger count trước/sau: 0/0
Data migration --apply: Không chạy
```

Output: `storage/app/audit/payroll-opening-balance-approved-dry-run.txt`.

## 8. Kết luận

```text
Khoản 50,000,000 được duyệt về mặt nghiệp vụ để migrate bằng opening_balance.
Chưa được apply production cho tới khi hoàn tất go-live/cutoff, UAT, permission,
backup/restore và rollback rehearsal.
```
