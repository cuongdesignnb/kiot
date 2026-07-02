# HOTFIX 24.6E.2 — Apply Paid Return Refund Debt Settlement Correction

## Phạm vi audit
- **Module**: Returns, Customer Debt Ledger, POS Return Exchange, Cash Flow.
- **Màn hình**: Khách hàng > Công nợ, POS > Trả hàng / Đổi hàng.
- **Nghiệp vụ**: Phiếu trả hàng cũ đã được chi trả hoàn tiền cho khách hàng (`paid_to_customer > 0` và có Cash Flow chi trả tương ứng) nhưng bị thiếu dòng bút toán `customer_debts` kiểu `adjustment` dương để tất toán nợ.
- **Rủi ro chính**: Việc chạy command điều chỉnh trùng lắp hoặc áp dụng sai logic có thể phá vỡ tính nhất quán của lịch sử công nợ của khách hàng.

## Source đã kiểm tra
- `app/Console/Commands/AuditPaidReturnRefundDebt.php`
- `app/Console/Commands/ApplyPaidReturnRefundDebtSettlement.php`
- `app/Services/CustomerDebtService.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Models/OrderReturn.php`
- `app/Models/Customer.php`
- `app/Models/CustomerDebt.php`
- `app/Models/CashFlow.php`
- `tests/Feature/OrderReturn/ApplyPaidReturnRefundDebtSettlementCommandTest.php`
- `tests/Feature/OrderReturn/AuditPaidReturnRefundDebtCommandTest.php`
- `tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php`

## Hiện trạng production
Khớp đúng danh sách dry-run 3 phiếu trên production đang thiếu customer debt adjustment (settlement):

| return_id | code | customer_id | customer | return_total | paid_to_customer | return_ledger | settlement_adjusted | cashflow_paid | suggested_missing |
|---:|---|---:|---|---:|---:|---:|---:|---:|---:|
| 5 | TH2026052109324156 | 62 | An-Lê Đức Thọ | 19200000 | 19200000 | -19200000 | 0 | 19200000 | 19200000 |
| 3 | TH2026051414021949 | 156 | Nguyễn Duy Khánh | 10000000 | 10000000 | -10000000 | 0 | 10000000 | 10000000 |
| 2 | TH2026050912010534 | 157 | DNGUYEN | 3600000 | 3600000 | -3600000 | 0 | 3600000 | 3600000 |

**Tổng số tiền thiếu hụt settlement:** `32.800.000đ`.

## Root cause
- Dữ liệu cũ phát sinh trước HOTFIX 24.6E có cash flow chi trả khách nhưng thiếu bản ghi ledger tất toán công nợ loại `adjustment` dương trong bảng `customer_debts` (dẫn tới công nợ khách bị âm sai lệch).

## Có ảnh hưởng dữ liệu đang có không?
- **Có**.
- **Bảng ảnh hưởng**:
  - `customers.debt_amount` (sẽ được cộng dương khôi phục tương ứng)
  - `customer_debts` (thêm bản ghi kiểu `adjustment` dương với số tiền `suggested_missing`)
- **Không thay đổi**:
  - `returns` (không sửa thuộc tính, trạng thái hay số tiền)
  - `cash_flows` (không sửa và không tạo phiếu chi mới)
  - Không xóa bất cứ dòng dữ liệu nào cũ.
  - Không thay đổi cấu trúc bảng (không migration).

## Phương án an toàn
1. **Lệnh apply độc lập**: Lệnh `php artisan returns:apply-paid-refund-debt-settlement` chạy độc lập, hỗ trợ `--dry-run` xem trước chi tiết điều chỉnh.
2. **Confirm Phrase bắt buộc**: Chặn apply thực tế nếu thiếu `--confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT`.
3. **Kiểm tra chặt chẽ**: Chỉ xử lý các phiếu thỏa mãn 5 điều kiện an toàn khắt khe về dòng nợ âm cũ, giá trị trả, cashflow thực tế và đề xuất còn thiếu.
4. **Giao dịch Database (Locking)**: Thực hiện re-check 100% các điều kiện này bên trong Transaction với dòng Customer bị khóa (`lockForUpdate`), ngăn chặn tình trạng chạy trùng, tranh chấp tài nguyên (Race Condition) và đảm bảo tính idempotent tuyệt đối.
5. **Rollback SQL Helper**: Sau khi chạy apply thành công, in ra câu lệnh SQL rollback cụ thể chứa ID thật vừa được tạo và các câu lệnh trừ nợ chính xác của khách hàng để phục vụ xử lý sự cố.

## Không được làm
- Không update trực tiếp `customers` bằng SQL tay.
- Không insert trực tiếp `customer_debts` bằng SQL tay.
- Không tạo thêm cash flow.
- Không xóa ledger/cashflow cũ.
- Không nói rollback "guarantee" tuyệt đối (vẫn phải backup DB).
- Không migrate:fresh.
- Không commit các file nhạy cảm (`.env`, logs, dumps).

## Tests bắt buộc
- Bộ test feature mới `ApplyPaidReturnRefundDebtSettlementCommandTest` (9 tests, 42 assertions) chạy **PASS 100%**.
- Bộ test regression `ReturnDebtAfterPaidRefundTest` (10 tests, 60 assertions) chạy **PASS 100%**.
- Bộ test regression `AuditPaidReturnRefundDebtCommandTest` (4 tests, 10 assertions) chạy **PASS 100%**.
- Bộ test regression `Step246BPosReturnExchangeTest` (28 tests, 156 assertions) chạy **PASS 100%**.
- `npm run build` hoàn thành không lỗi.

---

## Production Execution (Quy trình áp dụng Production)

> [!IMPORTANT]
> **Không tự động chạy apply thực tế khi chưa có backup cơ sở dữ liệu và xác nhận đồng ý từ owner.**

### Bước 1 — Backup DB
Backup cơ sở dữ liệu production bằng `mysqldump` hoặc công cụ của server (aaPanel):
```bash
# Ví dụ backup an toàn không lưu password trong lịch sử shell
mysqldump -u <db_user> -p <db_name> > /root/backup_kiot_before_settlement_correction.sql
```

### Bước 2 — Cập nhật code mới
```bash
cd /www/wwwroot/kiot.cuongdesign.net

git status
git pull origin main
composer dump-autoload
php artisan optimize:clear
```

### Bước 3 — Chạy thử nghiệm (Verify Dry-Run)
Kiểm tra lại xem khớp đúng 3 phiếu và số tiền `32.800.000đ` trước khi áp dụng:
```bash
php artisan returns:audit-paid-refund-debt --dry-run
php artisan returns:apply-paid-refund-debt-settlement --dry-run
```

### Bước 4 — Áp dụng điều chỉnh thực tế
Nếu muốn sửa cả 3 phiếu cùng lúc sau khi owner xác nhận:
```bash
php artisan returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT
```
Nếu chỉ muốn áp dụng từng phiếu để kiểm soát rủi ro:
```bash
php artisan returns:apply-paid-refund-debt-settlement --code=TH2026052109324156 --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT
```

### Bước 5 — Xác thực kết quả
Chạy lại lệnh audit để xác nhận không còn phiếu nào bị thiếu dòng tất toán:
```bash
php artisan returns:audit-paid-refund-debt --dry-run
```
Kỳ vọng output: `Rows: 0`, `Suggested missing adjustment total: 0`.

### Bước 6 — Kiểm tra giao diện (Browser QA)
- Vào tab **Khách hàng** > Tìm khách **An-Lê Đức Thọ** (ID: 62).
- Mở tab **Công nợ**.
- Xác nhận phiếu trả hàng **TH2026052109324156** không còn làm công nợ hiện tại bị âm sai lệch.
- Làm tương tự với 2 khách còn lại:
  - Nguyễn Duy Khánh (ID: 156)
  - DNGUYEN (ID: 157)

---

## Rollback Plan (Kế hoạch Rollback)

> [!WARNING]
> Rollback SQL chỉ là helper hỗ trợ, không thể đảm bảo tuyệt đối thay thế cho việc backup DB đầy đủ trước khi thực hiện correction.

Nếu xảy ra sự cố cần khôi phục trạng thái cũ, thực hiện câu lệnh SQL helper được in ra trong log command apply (sau khi thay thế bằng các ID thật):

```sql
-- Rollback helper. Verify backup before executing.
DELETE FROM customer_debts WHERE id IN (<danh_sách_id_ledger_mới_tạo>);

UPDATE customers SET debt_amount = debt_amount - 19200000 WHERE id = 62;
UPDATE customers SET debt_amount = debt_amount - 10000000 WHERE id = 156;
UPDATE customers SET debt_amount = debt_amount - 3600000 WHERE id = 157;
```

## Kết luận
- **Trạng thái**: ĐẠT yêu cầu nghiệp vụ. Lệnh sửa đổi đã được cài đặt và bao bọc toàn bộ bằng transaction + lock an toàn, idempotent.
- **Deploy**: Sẵn sàng deploy code lệnh apply mới lên production.
- **Apply Production**: Sẵn sàng áp dụng thực tế sau khi owner phê duyệt và hoàn tất backup DB.
