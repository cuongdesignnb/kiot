# P0 — Hiệu chỉnh giá vốn serial có kiểm soát

## Mục tiêu

Khôi phục các snapshot giá vốn lịch sử chỉ khi hệ thống có đủ bằng chứng độc
lập từ phiếu sửa chữa đã hoàn thành. Công cụ này không suy diễn giá vốn từ giá
vốn bình quân hiện tại, từ lần bán lại, hoặc từ một chứng từ phát sinh sau đó.

Mỗi transaction hiệu chỉnh luôn giới hạn tối đa 25 dòng hóa đơn: hoặc toàn bộ
batch cùng thành công, hoặc không có dòng nào trong batch thay đổi. Chế độ wave
có thể điều phối tối đa 50 dòng, nhưng vẫn chia thành hai transaction độc lập
25 dòng và dừng ngay khi batch tiếp theo không còn đạt precondition.

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

## Chế độ wave — tối đa 50 dòng, hai transaction độc lập

Wave chọn các invoice có mức điều chỉnh tuyệt đối thấp nhất, giữ toàn bộ các
dòng của cùng một invoice trong một batch và nhúng hai approval độc lập vào một
artifact. Không có transaction nào vượt quá 25 dòng.

### 1. Chuẩn bị wave chỉ đọc

```bash
php artisan costing:prepare-serial-remediation-wave \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --limit=50 \
  --approved-by='Người duyệt được ủy quyền' \
  --approval-reference='COGS-WAVE-01' \
  > storage/app/audit/serial-cost-wave.json
```

### 2. Preview và lấy mã xác nhận

```bash
php artisan costing:apply-serial-remediation-wave \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --wave-json=storage/app/audit/serial-cost-wave.json
```

Preview luôn trả `database_mutation: NO`, số dòng/serial/movement của từng
batch và `confirmation_code` của toàn wave.

### 3. Áp dụng sau một backup đã kiểm tra

```bash
php artisan costing:apply-serial-remediation-wave \
  --plan-json=storage/app/audit/serial-cost-plan.json \
  --wave-json=storage/app/audit/serial-cost-wave.json \
  --apply \
  --operator='Người vận hành' \
  --backup-confirmed \
  --backup-reference='AAPANEL:backup.sql.zip|sha256=...' \
  --confirm-wave-hash='APPLY-SERIAL-COGS-WAVE-...'
```

Mỗi batch commit riêng. Nếu batch sau thất bại, wave trả
`PARTIAL_FAILURE`, không chạy các batch còn lại và báo chính xác số batch đã
commit. Chạy lại cùng artifact là an toàn: batch đã hoàn tất trả `REPLAY`, batch
chưa chạy được tiếp tục. Một backup dùng chung cho toàn wave và được ghi vào
mọi activity log.

## Rollback

Không có bulk rollback tự động. Nếu một đợt đã commit cần đảo lại, lập một kế
hoạch/approval mới từ audit hiện tại và dùng giá trị `before` được lưu trong
`activity_logs` làm bằng chứng để thực hiện một đợt hiệu chỉnh ngược có phê
duyệt. Việc này giữ lịch sử kiểm toán đầy đủ thay vì xóa dấu vết.

## Nhóm vòng đời bán → trả → bán lại

Sau khi nhóm tự động thông thường về 0, các dòng bị khóa bởi lịch sử trả hàng
hoặc bán lại phải đi qua bộ lệnh vòng đời riêng. Công cụ này chỉ dùng giá vốn
từ phiếu sửa chữa đã hoàn thành trước từng lần bán và dựng đầy đủ chuỗi sự
kiện của từng serial. Nó không lấy giá vốn của lần bán sau để ghi ngược cho
lần bán trước.

Một kế hoạch vòng đời có thể đồng bộ đồng thời:

1. snapshot giá vốn của lần bán gốc;
2. giá vốn trên từng liên kết serial của lần bán;
3. giá vốn phiếu trả hàng và movement nhập trả tương ứng;
4. snapshot hiện tại của serial theo lần bán cuối cùng còn hiệu lực.

Với return nhiều serial, `return_items.cost_price` là đơn giá bình quân đã làm
tròn, còn `stock_movements.total_cost` giữ đúng tổng giá vốn từng serial. Hai
giá trị có thể lệch đúng 1 đồng do làm tròn và đây không phải sai dữ liệu.

Dữ liệu một phần chỉ được sửa theo serial có bằng chứng độc lập. Công cụ không
suy diễn giá vốn dòng hóa đơn hoặc phiếu trả có chứa serial chưa đủ bằng chứng.

### 1. Tạo kế hoạch vòng đời chỉ đọc

```bash
php artisan costing:plan-serial-lifecycle-remediation \
  --json > storage/app/audit/serial-cost-lifecycle-plan.json
```

Có thể thu hẹp bằng `--product=SKU`. Kế hoạch chỉ được duyệt khi
`blocked_lines=0` và tối đa 100 dòng.

### 2. Tạo approval bất biến cho toàn bộ kế hoạch

```bash
php artisan costing:approve-serial-lifecycle-remediation \
  --plan-json=storage/app/audit/serial-cost-lifecycle-plan.json \
  --approved-by='Người duyệt được ủy quyền' \
  --approval-reference='COGS-LIFECYCLE-01' \
  > storage/app/audit/serial-cost-lifecycle-approval.json
```

Approval bắt buộc chứa đúng toàn bộ `repair_lines`; không thể bỏ chọn một dòng
phụ thuộc trong cùng kế hoạch.

### 3. Preview và lấy mã xác nhận

```bash
php artisan costing:apply-serial-lifecycle-remediation \
  --plan-json=storage/app/audit/serial-cost-lifecycle-plan.json \
  --approval-json=storage/app/audit/serial-cost-lifecycle-approval.json
```

Preview luôn trả `database_mutation: NO` và in riêng delta COGS bán, COGS trả
và delta COGS ròng của báo cáo.

### 4. Áp dụng sau backup mới nhất

```bash
php artisan costing:apply-serial-lifecycle-remediation \
  --plan-json=storage/app/audit/serial-cost-lifecycle-plan.json \
  --approval-json=storage/app/audit/serial-cost-lifecycle-approval.json \
  --apply \
  --operator='Người vận hành' \
  --backup-confirmed \
  --backup-reference='AAPANEL:backup.sql.zip|sha256=...' \
  --confirm-approval-hash='APPLY-SERIAL-LIFECYCLE-COGS-...'
```

Toàn bộ kế hoạch vòng đời commit trong một transaction. Nếu precondition,
quan hệ return, movement, serial hiện tại hoặc post-condition không khớp thì
toàn bộ rollback. Chạy lại artifact đã áp dụng phải trả `REPLAY` và 0 thay đổi.

### Bằng chứng QA trên dữ liệu production sao lưu ngày 02/09/2026

- Nguồn: `kiot_db_2026-09-02_08-39-16_mysql_data_Yajki.sql.zip`.
- SHA-256: `bc84228acee61eb2428b770a84eb92042839e06f4c2a8a6f023a485f2ebe5c0c`.
- Kế hoạch trước apply: 62 dòng sửa, 0 dòng bị chặn, 164 liên kết serial,
  31 return item, 129 snapshot serial hiện tại và 84 stock movement.
- Delta dự kiến: COGS bán `+10.347.035`, COGS trả `+1.900.877`, COGS ròng
  báo cáo `+8.446.158`.
- Apply trong một transaction: PASS; chạy lại trả `REPLAY` cho đủ 62 dòng và
  không ghi thêm.
- Audit sau apply: 0 dòng sửa, 0 dòng bị chặn, 403 dòng đã xác minh và mọi
  delta còn 0.
- Bộ hồi quy MariaDB: 30 test, 287 assertion, PASS.

## Ràng buộc rollout

- Không migration, không backfill tự chạy và không có lệnh `--apply` trong deploy.
- Production chỉ chạy bước 1–3 trước; bước 4 cần backup, kế toán duyệt và output
  dry-run khớp đúng kế hoạch.
- Sau mỗi batch, chạy lại `costing:audit-serial-snapshots` và audit lợi nhuận để
  xác nhận số chênh giảm đúng các dòng đã được duyệt.
