# Website PC Product and Order Integration V1

## Tổng quan kiến trúc

KIOT là nguồn dữ liệu vận hành cho sản phẩm, giá bán và tồn khả dụng. Website PC gọi API HMAC để đọc sản phẩm và gửi đơn hàng. Luồng nhận đơn riêng biệt với `OrderController::store()`: integration service resolve SKU/customer, tạo `Order` và reservation trong một transaction, nhưng không tạo Invoice, CashFlow, StockMovement, không trừ kho và không chọn Serial/IMEI.

Khi nhân viên xử lý Order bằng POS hiện có, KIOT kiểm tra lại tồn khả dụng, chạy nguyên luồng costing/debt/serial/stock hiện hành, map chi nhánh Order sang Invoice và consume reservation trong cùng transaction. Hủy Order từ API hoặc giao diện KIOT chỉ release reservation; tồn vật lý không tăng.

Các mutation đã qua HMAC được audit trong `integration_events`; `ActivityLog` ghi các thay đổi Order/reservation. Product read và request bị chặn ở middleware chỉ có structured application log tối thiểu, không chứa payload/header/chữ ký.

## Cấu hình

```dotenv
PC_INTEGRATION_ENABLED=false
PC_INTEGRATION_CLIENT_ID=pc-website
PC_INTEGRATION_SECRET=
PC_INTEGRATION_BRANCH_ID=
PC_INTEGRATION_SALES_CHANNEL="Website PC"
PC_INTEGRATION_TIMESTAMP_TOLERANCE=300
PC_INTEGRATION_NONCE_TTL=600
PC_INTEGRATION_RATE_LIMIT=60
PC_INTEGRATION_RESERVATION_TTL=1440
```

Mặc định feature tắt. Mọi route trả `503` khi feature tắt, thiếu client/secret, hoặc `PC_INTEGRATION_BRANCH_ID` không trỏ tới chi nhánh tồn tại và chưa soft-delete. Secret phải là giá trị ngẫu nhiên dài, được quản lý ngoài Git.

Để bật sau khi migration, review và UAT đã hoàn tất, cấu hình đầy đủ các biến trên, đặt `PC_INTEGRATION_ENABLED=true`, rồi refresh config cache theo quy trình deploy của KIOT. Phase 1 không tự bật integration và không đăng ký lịch production cho command expiry.

## HMAC

Mọi endpoint yêu cầu:

```text
X-Integration-Key: <client id>
X-Timestamp: <Unix timestamp seconds>
X-Nonce: <unique nonce, max 128 characters>
X-Signature: <lowercase hex HMAC SHA-256>
```

Mutation còn yêu cầu `Idempotency-Key`. Chữ ký được tính trên raw request body đúng từng byte. Canonical string:

```text
METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_RAW_BODY
```

Ví dụ PHP:

```php
$bodyHash = hash('sha256', $rawBody);
$canonical = implode("\n", [
    strtoupper($method),
    $path,
    (string) $timestamp,
    $nonce,
    $bodyHash,
]);
$signature = hash_hmac('sha256', $canonical, $secret);
```

`PATH` chỉ là path bắt đầu bằng `/`, không gồm domain hoặc query string. Middleware kiểm tra cấu hình, client, timestamp, chữ ký bằng `hash_equals`, rate limit theo client và lưu nonce bằng cache atomic. Timestamp nằm ngoài tolerance trả `401`; nonce đã dùng trả `409`.

## Endpoints

| Method | Path | Chức năng |
| --- | --- | --- |
| GET | `/api/integrations/v1/pc/products` | Danh sách sản phẩm bằng cursor |
| GET | `/api/integrations/v1/pc/products/{sku}` | Chi tiết theo SKU đúng hoa/thường |
| POST | `/api/integrations/v1/pc/orders` | Nhập đơn website |
| GET | `/api/integrations/v1/pc/orders/{external_order_id}` | Trạng thái Order |
| POST | `/api/integrations/v1/pc/orders/{external_order_id}/cancel` | Hủy Order external |
| POST | `/api/integrations/v1/pc/orders/{external_order_id}/deliver` | Web báo đã giao → KIOT chuyển Order thành Invoice |
| POST | `/api/integrations/v1/pc/orders/{external_order_id}/payments` | Web báo đã thanh toán → đặt cọc Order / thu nợ Invoice |

### Product list

Query hỗ trợ `cursor`, `limit` (mặc định 50, tối đa 100), `updated_since`, `sku`, `include_inactive`. Cursor ổn định theo `(updated_at,id)`. Mặc định chỉ trả product active, `sell_directly` và chưa xóa. `include_inactive=true` hoặc có `updated_since` bao gồm inactive và soft-deleted tombstone. Product `type=service` nằm ngoài V1 nên luôn bị loại khỏi list/detail, kể cả khi dùng `include_inactive` hoặc `updated_since`.

`updated_since` nhận datetime có timezone và được đổi về timezone cấu hình của ứng dụng trước khi so với timestamp MySQL. Biên là inclusive (`updated_at >= updated_since`); cursor dùng ID làm tie-break khi nhiều Product có cùng `updated_at`.

SKU chỉ được `trim`, không đổi hoa/thường. `available_quantity = max(0, stock_quantity - active reservations)`. Hàng Serial/IMEI tiếp tục dùng `products.stock_quantity`. Response không chứa cost, inventory total cost, supplier, mô tả marketing, ảnh hoặc category data.

```json
{
  "success": true,
  "data": [{
    "id": 1001,
    "sku": "CPU-I5-14600K",
    "barcode": "893000001001",
    "name": "Intel Core i5-14600K",
    "retail_price": 7990000,
    "stock_quantity": 10,
    "reserved_quantity": 2,
    "available_quantity": 8,
    "has_serial": true,
    "is_active": true,
    "sell_directly": true,
    "weight": 500,
    "warranty_months": 36,
    "sync_status": "active",
    "updated_at": "2026-07-19T07:00:00Z"
  }],
  "meta": {"next_cursor": "...", "has_more": true}
}
```

### Import Order

```json
{
  "event_id": "5a0bb45b-3881-4c25-a889-e510bc6e9954",
  "external_order_id": "12589",
  "external_order_code": "DH202607190001",
  "ordered_at": "2026-07-19T14:30:00+07:00",
  "customer": {
    "name": "Nguyễn Văn A",
    "phone": "+84 987 654 321",
    "email": "customer@example.com"
  },
  "delivery": {
    "is_delivery": true,
    "receiver_name": "Nguyễn Văn A",
    "receiver_phone": "0987654321",
    "receiver_address": "123 Đường ABC",
    "receiver_ward": "Phường 1",
    "receiver_district": "Quận 1",
    "receiver_city": "TP. Hồ Chí Minh",
    "weight": 500,
    "shipping_fee": 30000
  },
  "payment": {"method": "sepay", "status": "paid"},
  "totals": {
    "subtotal": 7990000,
    "discount": 0,
    "shipping_fee": 30000,
    "total": 8020000
  },
  "items": [{
    "sku": "CPU-I5-14600K",
    "product_name": "Intel Core i5-14600K",
    "quantity": 1,
    "unit_price": 7990000,
    "discount": 0,
    "line_total": 7990000,
    "bundle_ref": "BUILD-001"
  }],
  "note": "Khách yêu cầu lắp ráp"
}
```

Tiền được đối chiếu theo cent với tolerance tối đa `0.01`. Giá website được giữ làm snapshot OrderItem; chênh với retail price chỉ được audit. `bundle_ref` chỉ nằm trong audit payload. Customer được resolve theo số điện thoại Việt Nam chuẩn hóa, sau đó email lowercase. Mapping mơ hồ, customer inactive hoặc đã merge bị từ chối. Customer dual-role được giữ nguyên vai trò supplier.

Thành công lần đầu trả `201`:

```json
{
  "success": true,
  "duplicate": false,
  "data": {
    "kiot_order_id": 989,
    "kiot_order_code": "DH2607191430001234",
    "external_order_id": "12589",
    "status": "confirmed"
  }
}
```

### Idempotency

- Cùng external order và raw payload hash: trả `200`, `duplicate=true`, không tạo lại dữ liệu.
- Cùng external order nhưng payload khác: `409 EXTERNAL_ORDER_CONFLICT`.
- `event_id` hoặc `Idempotency-Key` dùng lại cho nội dung khác: `409 IDEMPOTENCY_KEY_CONFLICT`.
- Mỗi cancel có `event_id` UUID riêng. Retry đúng event/body/key trả `200`, `duplicate=true`; event mới cho Order đã hủy trả `409 ORDER_ALREADY_CANCELLED`.

### Deliver (web báo đã giao)

`POST /orders/{external_order_id}/deliver`, cùng HMAC + header `Idempotency-Key` bắt buộc, body:

```json
{ "event_id": "uuid", "delivered_at": "2026-11-16T15:00:00+07:00", "tracking_code": "GHN123456", "carrier": "GHN" }
```

- KIOT chuyển Order thành Invoice **đúng như nút "Xử lý đơn"** (dùng chung `OrderInvoiceConversionService`): tiền cọc `orders.amount_paid` được trừ như hiện tại, không thu thêm tiền, trừ tồn, consume reservation, tạo bảo hành, khóa sổ (`pc_order_convert_to_invoice`). `tracking_code`/`carrier` lưu vào `invoices.tracking_code`/`delivery_partner`; ghi chú hóa đơn có "Web báo đã giao …".
- Order đã chuyển hóa đơn (status `completed` hoặc đã có Invoice) → `200`, `duplicate=true`, trả mã hóa đơn cũ.
- Hàng Serial/IMEI chưa được chọn serial trong KIOT → `422 SERIAL_NOT_ALLOCATED`, message "Cần chọn Serial/IMEI trong KIOT cho: …". KIOT ghi **một** dòng vào ghi chú Order và Nhật ký hoạt động (không lặp khi web gửi lại) để nhân viên chọn serial; sau đó web gửi lại event deliver mới.
- Hàng web tạo chưa có giá vốn → `422 COST_PENDING`. Đơn đã hủy/kết thúc → `422 ORDER_NOT_PROCESSABLE`. Không có Order → `404 ORDER_NOT_FOUND`. Kỳ khóa sổ → `422 PERIOD_LOCKED`. Lỗi bán hàng khác (thiếu tồn…) → `422 ORDER_NOT_PROCESSABLE`.
- Response: `{"success":true,"duplicate":false,"data":{"kiot_order_id":1,"kiot_order_code":"DH…","external_order_id":"…","status":"completed","kiot_invoice_code":"HD…"}}`.

### Payments (web báo đã thanh toán)

`POST /orders/{external_order_id}/payments`, cùng HMAC + `Idempotency-Key`, body:

```json
{ "event_id": "uuid", "amount": 300000, "paid_at": "2026-11-16T10:00:00+07:00", "method": "bank_transfer", "reference": "FT2611160001" }
```

`method`: `bank_transfer` | `cash` | `cod` | `sepay` | `other` (`bank_transfer`, `sepay` ghi sổ quỹ là chuyển khoản; còn lại là tiền mặt).

- **Order chưa chuyển hóa đơn:** cộng vào `orders.amount_paid` (tiền cọc) và tạo phiếu thu `reference_type=Order`, `reference_code=<mã đơn>`, đối tượng = khách của Order, nội dung "Web báo đã thanh toán …". Công nợ khách được ghi giảm ngay; khi chuyển hóa đơn, phần cọc đã có phiếu thu này không bị trừ lần nữa. Vượt tổng đơn → `422 OVERPAYMENT`.
- **Order đã có hóa đơn:** thu nợ đúng hóa đơn đó (`CustomerPaymentService`, phân bổ thủ công). Vượt số còn nợ → `422 OVERPAYMENT`; hóa đơn khách lẻ → `422 CUSTOMER_REQUIRED`.
- Đơn đã hủy/kết thúc hoặc hóa đơn đã hủy → `422 ORDER_NOT_PROCESSABLE`.
- Response `201` (lần đầu) / `200` + `duplicate=true` (gửi lại): `{"data":{"kiot_order_code":"DH…","external_order_id":"…","kiot_invoice_code":null,"cash_flow_code":"PT…","amount_paid_total":300000}}`.

Idempotency của deliver/payments giống cancel: cùng `event_id` + `Idempotency-Key` + body → replay `duplicate=true`; dùng lại key/event cho body khác → `409 IDEMPOTENCY_KEY_CONFLICT`. Website nên coi mọi `422` là từ chối nghiệp vụ (không tự retry), `5xx` thì retry cùng key.

### Reservation và Serial/IMEI

Mỗi OrderItem có một reservation active, hết hạn theo TTL cấu hình. Reservation không phải tồn vật lý và không tạo StockMovement. Cancel/ended chuyển active sang released. Command dưới đây lock/chunk reservation quá hạn và chuyển sang expired nếu Order chưa completed/invoiced; không tự hủy Order:

```bash
php artisan integrations:expire-pc-reservations
```

Reservation expired không cản xử lý Order: POS kiểm tra lại tồn khả dụng. Product service (`type=service`) nằm ngoài V1 và bị từ chối. Order serial được nhập với `serial_ids=null`; nhân viên phải chọn đủ Serial/IMEI ở POS. Chỉ sau khi toàn bộ Invoice transaction thành công reservation active mới thành consumed.

Nhận Order external chỉ tạo phiếu tạm và reservation, không tạo chứng từ kế toán/kho nên không kiểm tra kỳ khóa sổ tại thời điểm import. Khi nhân viên chuyển external Order thành Invoice, transaction hiện tại phải qua `PartnerTransactionGuard`, customer-role/debt coordinator và `LockPeriodService` với context `pc_order_convert_to_invoice`; nếu kỳ hiện tại đã khóa thì toàn bộ Invoice/stock/reservation mutation rollback.

## Error response và mã lỗi

```json
{
  "success": false,
  "error": {
    "code": "UNKNOWN_SKU",
    "message": "Một hoặc nhiều SKU không tồn tại trong KIOT.",
    "details": [{"sku": "INVALID-SKU", "reason": "not_found"}]
  }
}
```

| HTTP | Codes |
| --- | --- |
| 401 | `INVALID_INTEGRATION_CLIENT`, `INVALID_SIGNATURE`, `EXPIRED_TIMESTAMP` |
| 404 | `UNKNOWN_SKU` (detail), `EXTERNAL_ORDER_NOT_FOUND`, `ORDER_NOT_FOUND` (deliver/payments) |
| 409 | `REPLAYED_NONCE`, `DUPLICATE_SKU_IN_KIOT`, `EXTERNAL_ORDER_CONFLICT`, `IDEMPOTENCY_KEY_CONFLICT`, `ORDER_ALREADY_CANCELLED`, `ORDER_ALREADY_INVOICED`, `ORDER_NOT_CANCELLABLE` |
| 422 | `INVALID_PAYLOAD`, `ORDER_TOTAL_MISMATCH`, `UNKNOWN_SKU` (import), `PRODUCT_INACTIVE`, `PRODUCT_NOT_SELLABLE`, `INSUFFICIENT_AVAILABLE_STOCK`, `ORDER_NOT_PROCESSABLE`, `SERIAL_NOT_ALLOCATED`, `COST_PENDING`, `PERIOD_LOCKED`, `OVERPAYMENT`, `CUSTOMER_REQUIRED`, `PAYMENT_REJECTED` |
| 429 | `RATE_LIMITED` |
| 500 | `INTERNAL_INTEGRATION_ERROR` |
| 503 | `INTEGRATION_DISABLED`, `INTEGRATION_NOT_CONFIGURED` |

## Test và migration

Test database phải là database testing đã xác minh trước khi chạy:

```bash
composer install
php artisan migrate:fresh --env=testing --force
php artisan test --filter=PcIntegration
php artisan test
vendor/bin/pint --test
npm ci
npm run build
```

Migration rollback ba file V1, theo thứ tự ngược:

```bash
php artisan migrate:rollback --env=testing --force --step=3
php artisan migrate --env=testing --force
```

Trên production, phải backup và review trạng thái migration trước. Rollback xóa bảng reservation/event và các cột external trên `orders`; vì vậy chỉ thực hiện rollback khi dữ liệu audit V1 đã được xuất/không còn cần thiết và integration đã tắt.

## Giới hạn Phase 1

- Không sửa sender website PC và không gọi API ngoài trong transaction.
- Payment metadata trong đơn nhập chỉ tham khảo; thanh toán chỉ được ghi khi web gọi `POST .../payments`.
- Không tích hợp vận chuyển, refund, ảnh/SEO/category/brand hay PC Builder.
- Không tự chọn Serial/IMEI hoặc tự hủy Order hết reservation. Invoice chỉ được tạo khi web gọi `POST .../deliver`.
- Chưa bật feature, schedule, merge hoặc deploy production.
