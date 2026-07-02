# PAYROLL LEGACY BALANCE DECISION PACK

## 1. Bối cảnh

`employees.balance` là số dư legacy từng dùng để hiển thị “Nợ và tạm ứng” nhưng không có chứng từ nguồn đầy đủ. Field này không còn phù hợp làm source of truth vì không giải thích được phát sinh, ngày nghiệp vụ, hủy/đảo hoặc đối soát CashFlow.

Sau rollout:

- `employee_salary_ledger_entries` là source of truth.
- Chỉ entry có `is_effective = true` được tính số dư.
- `employees.salary_balance_cache` là cache từ ledger để hiển thị nhanh.
- `employees.balance` chỉ dùng audit và quyết định migration, không ghi mới.

## 2. Kết quả audit legacy

Môi trường audit:

```text
APP_ENV=staging
DB_DATABASE=kiot_prod_copy_payroll_20260614_003011
Database engine: MariaDB 10.11 trong Docker cô lập
Production live: Không
Migration payroll ledger trên copy: Applied
```

| Chỉ số | Kết quả |
|---|---|
| Tổng nhân viên | 7 |
| Nhân viên có `employees.balance != 0` | 1 |
| Tổng số dư dương | 50,000,000 |
| Tổng số dư âm | 0 |
| Số nhân viên có ledger mới | 0 |
| Số nhân viên có payslip/payment/advance liên quan | 7 |
| Số nhân viên đề xuất `OPENING_BALANCE` | 1 |
| Số nhân viên đề xuất `NEED_MANUAL_REVIEW` | 0 |
| Số nhân viên đề xuất `IGNORE_AS_DEMO` | 0, chờ Owner xác nhận |

Command baseline sau khi schema payroll đã được apply trên bản sao:

```bash
php artisan payroll:audit-salary-ledger --section=legacy --format=csv \
  --output=storage/app/audit/payroll-legacy-balance-audit.csv
```

## 3. Option A - Chuyển thành opening_balance

Luồng:

1. Chốt `go_live_date`.
2. Duyệt danh sách nhân viên và số dư.
3. Tạo duy nhất một entry `opening_balance` cho mỗi nhân viên/mốc go-live.
4. Dùng idempotency key `opening_balance:{employee_id}:{go_live_date}`.
5. Rebuild cache từ ledger.

Điều kiện áp dụng:

- Dữ liệu legacy là dữ liệu thật đang được sử dụng.
- Không đủ chứng từ để backfill chi tiết.
- Kế toán xác nhận số dư tại go-live.

Ưu điểm:

- Không mất số dư đang vận hành.
- Có mốc chuyển đổi rõ trong timeline.

Nhược điểm và rủi ro:

- Không có chi tiết nguồn trước go-live.
- Double-count nếu cùng giai đoạn đã backfill payslip/payment/advance.
- Sai số dư nếu danh sách chưa được kế toán duyệt.

Thông tin bắt buộc:

```text
go_live_date:
default_note: Số dư lương chuyển đổi từ hệ thống KiotViet
approved_employee_list:
approved_by:
approved_at:
```

## 4. Option B - Chỉ anomaly report

Luồng:

1. Không tạo ledger từ `employees.balance`.
2. Ledger mới bắt đầu từ 0 hoặc từ chứng từ được backfill.
3. Đưa chênh lệch vào anomaly report để review thủ công.

Điều kiện áp dụng:

- Dữ liệu legacy là demo/test hoặc không đáng tin.
- Owner chấp nhận số dư mới khác màn hình cũ.

Ưu điểm:

- Ledger mới sạch.
- Không kéo số dư không rõ nguồn sang hệ thống mới.

Nhược điểm:

- Có thể thay đổi số dư hiển thị ngay sau rollout.
- Cần truyền thông và xử lý anomaly thủ công.

## 5. Khuyến nghị kỹ thuật

- Dữ liệu demo/test: chọn Option B.
- Dữ liệu thật đang vận hành: chọn Option A sau khi kế toán ký danh sách.
- Không dùng opening balance và backfill documents cho cùng một giai đoạn nếu chưa có cutoff loại trừ rõ ràng.
- Không chạy `--apply` trước khi anomaly report và dry-run được duyệt.

## 6. Quyết định BA/Owner

```text
[x] Option A - Opening balance
[ ] Option B - Anomaly only

Go-live date: Chờ Owner/BA chốt
Cutoff chứng từ: Chờ Owner/BA chốt
Người duyệt: BA/Owner xác nhận nghiệp vụ trong phiên review
Ngày duyệt: 2026-06-14
Ghi chú: 50,000,000 là số dư thật cũ chuyển từ KiotViet sang
```

## Appendix A - Danh sách legacy balance cần review

| Employee code | Employee name | Legacy balance | Ledger balance | Difference | Suggested action | Ghi chú |
|---|---|---:|---:|---:|---|---|
| `NV000012` | `V***` | 50,000,000 | 0 | 50,000,000 | `OPENING_BALANCE_APPROVED` | BA/Owner xác nhận số dư thật từ KiotViet |

Handover đối chiếu:

`docs/audit/PAYROLL-LEGACY-50M-RECONCILIATION-HANDOVER.md`

Kết luận:

- Legacy balance không trùng locked remaining.
- Locked payslip count là 1 nhưng total salary/remaining đều bằng 0.
- Không có payment, CashFlow lương, advance hoặc ledger giải thích số dư.
- BA/Owner xác nhận đây là số dư thật cũ từ KiotViet.
- Suggested action: `OPENING_BALANCE_APPROVED`.
- Tạo opening balance tại cutoff/go-live được duyệt.
- Không backfill documents cho giai đoạn trước hoặc bằng cutoff.

Không backfill documents cho giai đoạn trước hoặc bằng cutoff nếu đã dùng
opening balance để đại diện cho số dư cũ. Nếu cần backfill chứng từ, chỉ backfill
các chứng từ sau cutoff để tránh chồng lấn.

### Rule `suggested_action`

`OPENING_BALANCE`:

- Có legacy balance, chưa có ledger/chứng từ nguồn đáng tin.

`NEED_MANUAL_REVIEW`:

- Đã có ledger hoặc chứng từ liên quan.
- Có chênh lệch cần kế toán xem.

`IGNORE_AS_DEMO`:

- Chỉ dùng khi Owner xác nhận đây là dữ liệu demo/test.
- Không tự suy luận.
