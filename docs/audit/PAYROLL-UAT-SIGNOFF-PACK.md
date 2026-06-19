# PAYROLL UAT SIGN-OFF PACK

## 1. Mục tiêu

Ghi nhận kết quả UAT module Payroll/Nợ lương/Tạm ứng trước production rollout.

## 2. Phạm vi UAT

| Nhóm | Trạng thái |
|---|---|
| Opening balance KiotViet 50M | Business UI/API Pass trên Docker UAT |
| Payroll lifecycle | Chưa business UAT |
| Salary advance | Chưa business UAT |
| Payment/cancel payment | Chưa business UAT |
| Permission/branch scope | Chưa business UAT |
| CashFlow/P&L | Regression test Pass; chờ kế toán sign-off |
| Report/export | Opening balance report/export Pass |

## 3. Môi trường

```text
App container: kiot-payroll-uat-app
App URL: http://localhost:8082
DB container: kiot_payroll_audit_mariadb
DB_DATABASE: kiot_payroll_uat_20260614_165946
Production live: Không
Migration/apply khi chạy UI UAT: Không
```

App container được khởi động bằng entrypoint override để không tự chạy
`migrate --force`.

## 4. Bằng chứng UAT opening balance

| Case | Kết quả | Bằng chứng | Ghi chú |
|---|---|---|---|
| UAT-OB-01 | Pass | `payroll-uat-ledger-tab-docker-pass.png` | UI timeline có đúng 1 opening balance 50M và note KiotViet |
| UAT-OB-02 | Pass | `payroll-uat-opening-balance-verify.json` | Cache 50M khớp ledger |
| UAT-OB-03 | Pass | `payroll-uat-opening-balance-verify.json` | SUM effective ledger = 50M |
| UAT-OB-04 | Pass | DB verify và detail UI | Payment 0, CashFlow giữ nguyên 543 |
| UAT-OB-05 | Pass | `payroll-uat-reconciliation-nv000012-docker.png` | UI/API report: cache=ledger=50M, difference=0 |

Các file bằng chứng nằm tại `storage/app/audit` và bị Git ignore vì có thể chứa
dữ liệu UAT/PII.

## 5. Export

```text
Ledger export: HTTP 200, UTF-8 BOM, đúng code/type/amount/note.
Reconciliation export: HTTP 200, UTF-8 BOM, đúng NV000012/cache/difference/issue.
```

## 6. Điều kiện sign-off

```text
[x] Không phát hiện bug Critical/High trong scope opening balance.
[ ] Kế toán xác nhận số dư 50,000,000 đúng.
[ ] BA xác nhận timeline/report đúng.
[ ] HR xác nhận không ảnh hưởng lifecycle nhân viên.
[ ] Owner chấp nhận phạm vi chưa business UAT nếu có.
```

## 7. Sign-off

| Vai trò | Họ tên | Kết luận | Ngày | Ghi chú |
|---|---|---|---|---|
| BA | | Chờ ký | | |
| Kế toán | | Chờ ký | | |
| HR | | Chờ ký | | |
| Owner | | Chờ ký | | |

## 8. Kết luận

```text
[ ] UAT sign-off pass.
[ ] UAT sign-off fail.
[x] Chưa sign-off.
```

Business UI/API UAT cho opening balance đã pass. Đây không phải Owner final
approval và không cho phép production apply.

Agent không thay thế chữ ký BA, kế toán, HR hoặc Owner. Các ô sign-off chỉ được
cập nhật khi có người duyệt và ngày duyệt thực tế.
