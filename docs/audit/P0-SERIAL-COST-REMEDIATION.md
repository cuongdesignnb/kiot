# P0 — Hiệu chỉnh giá vốn serial có kiểm soát

## Mục tiêu

Khôi phục các snapshot giá vốn lịch sử chỉ khi hệ thống có đủ bằng chứng độc
lập từ phiếu sửa chữa đã hoàn thành. Công cụ này không suy diễn giá vốn từ giá
vốn bình quân hiện tại, từ lần bán lại, hoặc từ một chứng từ phát sinh sau đó.

Mỗi đợt hiệu chỉnh luôn giới hạn tối đa 25 dòng hóa đơn và thực hiện trong một
transaction: hoặc toàn bộ đợt cùng thành công, hoặc không có dòng nào thay đổi.

## Dữ liệu được phép thay đổi

Với một dòng đủ điều kiện, cùng một giá vốn đã được chứng minh sẽ được đồng bộ
vào bốn snapshot của chính lần bán đó:

1. `invoice_items.cost_price`
2. `invoice_item_serials.cost_price`
3. `serial_imeis.sold_cost_price`
4. `stock_movements.unit_cost` và `stock_movements.total_cost`

Không thay đổi doanh thu, số lượng tồn, giá vốn hiện tại của hàng tồn, giá vốn
bình quân của sản phẩm, chứng từ gốc, hoặc phiếu sửa chữa. Mỗi lần áp dụng tạo
một `activity_log` lưu giá trị trước/sau, chứng cứ, người duyệt, người thao tác
và mã backup.

## Điều kiện tự động đủ an toàn

Một dòng chỉ được đưa vào `repair_lines` khi đồng thời có:

- hóa đơn hoàn thành;
- đủ serial khớp đúng số lượng trên dòng hóa đơn;
- đúng serial hiện đang giữ snapshot của lần bán đó;
- một phiếu sửa chữa hoàn thành trước thời điểm bán cho từng serial;
- một stock movement xuất bán khớp duy nhất với hóa đơn, hàng hóa và số lượng;
- ít nhất một chênh lệch thực sự ở chứng từ hoặc giá vốn hàng bán;
- không có trả hàng hoàn thành;
- không có lịch sử bán lại serial.

Các dòng còn lại chỉ nằm trong `manual_review_lines`; lệnh áp dụng không thể
chọn chúng.

## Các trạng thái bị khóa

- `MISSING_INDEPENDENT_REPAIR_EVIDENCE`: thiếu chứng cứ độc lập.
- `INCOMPLETE_SERIAL_EVIDENCE`: thiếu serial hoặc không đủ bằng chứng cho cả dòng.
- `SERIAL_NOT_CURRENT_SALE`: snapshot serial không còn thuộc lần bán đang xét.
- `RESALE_HISTORY`: serial đã bán lại.
- `COMPLETED_RETURN_HISTORY`: hóa đơn đã có trả hàng hoàn thành.
- `MISSING_OR_AMBIGUOUS_STOCK_MOVEMENT`: không có hoặc có nhiều movement phù hợp.
- `INVOICE_NOT_COMPLETED`: chứng từ không ở trạng thái hoàn thành.
- `STOCK_MOVEMENT_IDENTITY_MISMATCH`: movement không khớp chính xác dòng bán.

## Quy trình vận hành

### 1. Tạo kế hoạch chỉ đọc

```bash
php artisan costing:plan-serial-remediation --json > storage/app/audit/serial-cost-plan.json
```

Có thể thu hẹp theo sản phẩm hoặc hóa đơn để kế toán xem trước:

```bash
php artisan costing:plan-serial-remediation --invoice=HD... --json
```

Kế hoạch có `plan_hash`, fingerprint cơ sở dữ liệu và `precondition_hash` của
từng dòng. Không được chỉnh sửa file JSON bằng tay.

### 2. Kế toán duyệt một đợt nhỏ

```bash
php artisan costing:approve-serial-remediation \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --invoice=HD... \
  --approved-by='Họ tên kế toán duyệt' \
  --approval-reference='Mã biên bản hoặc ticket' \
  > storage/app/audit/serial-cost-approval.json
```

Nếu chọn bằng `--limit`, bắt buộc là số dương. Một approval không thể quá 25
dòng; người duyệt và mã biên bản là trường bắt buộc.

### 3. Xem trước lần cuối, không ghi dữ liệu

```bash
php artisan costing:apply-serial-remediation \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --approval-json=storage/app/audit/serial-cost-approval.json
```

Lệnh này in `confirmation_code`, số dòng và số snapshot sẽ thay đổi, nhưng luôn
trả `database_mutation: NO`.

### 4. Chỉ áp dụng sau backup và đối chiếu lại

```bash
php artisan costing:apply-serial-remediation \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --approval-json=storage/app/audit/serial-cost-approval.json \
  --apply \
  --operator='Họ tên người thực hiện' \
  --backup-confirmed \
  --backup-reference='Mã backup đã kiểm tra khôi phục' \
  --confirm-approval-hash='APPLY-SERIAL-COGS-...'
```

Ngay trước khi ghi, lệnh khóa các hóa đơn, dòng hóa đơn, liên kết serial,
serial, stock movement, phiếu sửa chữa và return item liên quan; sau đó audit
lại toàn bộ. Nếu một điều kiện thay đổi, toàn bộ batch rollback. Chạy lại một
approval đã hoàn thành chỉ trả `REPLAY` khi cả bốn snapshot vẫn khớp; nếu bất kỳ
snapshot nào lại lệch, lệnh từ chối thay vì âm thầm coi là đã xong.

## Rollback

Không có bulk rollback tự động. Nếu một đợt đã commit cần đảo lại, lập một kế
hoạch/approval mới từ audit hiện tại và dùng giá trị `before` được lưu trong
`activity_logs` làm bằng chứng để thực hiện một đợt hiệu chỉnh ngược có phê
duyệt. Việc này giữ lịch sử kiểm toán đầy đủ thay vì xóa dấu vết.

## Ràng buộc rollout

- Không migration, không backfill tự chạy và không có lệnh `--apply` trong deploy.
- Production chỉ chạy bước 1–3 trước; bước 4 cần backup, kế toán duyệt và output
  dry-run khớp đúng kế hoạch.
- Sau mỗi batch, chạy lại `costing:audit-serial-snapshots` và audit lợi nhuận để
  xác nhận số chênh giảm đúng các dòng đã được duyệt.
