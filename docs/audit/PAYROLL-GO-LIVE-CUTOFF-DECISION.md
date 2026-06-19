# PAYROLL GO-LIVE & CUTOFF DECISION

## 1. Mục tiêu

Chốt mốc chuyển đổi dữ liệu payroll, nợ lương và tạm ứng từ KiotViet sang ledger mới.

## 2. Quyết định cần chốt

| Hạng mục | Giá trị |
|---|---|
| Go-live date | Chờ Owner/BA duyệt |
| Cutoff chứng từ legacy | Chờ Owner/BA duyệt |
| Source system | KiotViet |
| Migration option | Option A - Opening balance |
| Employee opening balance | `NV000012` - 50,000,000 |
| Backfill documents cùng giai đoạn | Không |
| Production apply | Chưa được phép |

Ngày `2026-06-14` chỉ được dùng để mô phỏng dry-run kỹ thuật, không phải go-live date chính thức.

## 3. Quy tắc cutoff

```text
1. Số dư trước go_live_date được đại diện bằng opening_balance đã duyệt.
2. Không backfill chứng từ trước hoặc bằng cutoff nếu đã dùng opening_balance cho cùng phạm vi.
3. Chứng từ sau go_live_date phải đi qua ledger mới.
4. Không ghi mới vào employees.balance sau go-live.
5. Không tạo payment hoặc CashFlow cho opening_balance.
6. Balance luôn là SUM(amount WHERE is_effective = true).
```

## 4. Opening balance đã được duyệt về nghiệp vụ

| Employee code | Amount | Source | Ledger type | Note |
|---|---:|---|---|---|
| `NV000012` | 50,000,000 | KiotViet | `opening_balance` | Số dư lương chuyển đổi từ hệ thống KiotViet |

## 5. Người duyệt

| Vai trò | Họ tên | Trạng thái | Ngày duyệt | Ghi chú |
|---|---|---|---|---|
| BA | | Chờ duyệt | | |
| Kế toán | | Chờ duyệt | | |
| Owner | | Chờ duyệt | | |

## 6. Kết luận

```text
[ ] Go-live/cutoff đã được duyệt.
[x] Chưa được duyệt.
```

Production vẫn `NO-GO` vì go-live date và cutoff chưa được người có thẩm quyền
duyệt.

Không sử dụng ngày mô phỏng UAT `2026-06-14` làm go-live date production.
