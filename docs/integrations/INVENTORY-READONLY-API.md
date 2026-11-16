# API Kiot (chỉ đọc + 2 chức năng ghi có nhật ký)

Tài liệu dành cho đối tác / Google Sheet của Laptopplus (anh Tiến) muốn:

- tự động lấy tồn kho, serial/IMEI và trạng thái sửa chữa (mục 4, quyền `inventory:read`);
- đọc dữ liệu kinh doanh: hóa đơn, trả hàng, nhập hàng, phiếu sửa, công việc, công nợ khách, sổ quỹ, báo cáo tổng quan (mục 8, quyền `data:read`);
- dùng **đúng 2 chức năng ghi**: sửa *Giá bán thợ* của một mã hàng và tạo **một hóa đơn bán hàng mới** từ một dòng Sheet "Doanh số" (mục 9, mỗi chức năng một quyền riêng). Không có chức năng sửa hay xóa hóa đơn.

- **Base URL:** `https://kiot.cuongdesign.net/api/public/v1` — tồn kho ở `/inventory/*`, dữ liệu ở `/data/*`, ghi ở `/write/*`.
- **Định dạng:** JSON, UTF-8. Thời gian theo ISO 8601 có múi giờ (VD `2026-11-05T14:30:00+07:00`). Tiền là **số nguyên VND**.

## 1. Lấy token và chọn quyền

1. Quản trị viên KIOT vào **Cài đặt → API Kiot (chỉ đọc + 2 chức năng ghi có nhật ký)** (`/settings/integrations/inventory-api`). Cần quyền `integrations.manage`.
2. Bấm **Tạo token mới**, đặt tên (VD `Laptop Plus - Google Sheet`), tài khoản/đối tác sử dụng và **tích quyền**:

| Quyền (ability) | Mặc định | Cho phép |
|---|---|---|
| `inventory:read` | ✔ tích sẵn | `GET /inventory/products`, `GET /inventory/serials` |
| `data:read` | ✔ tích sẵn | Tất cả `GET /data/*` (mục 8) |
| `products:technician_price:write` | ✘ (chữ đỏ) | `PATCH /write/products/{sku}/technician-price` |
| `invoices:create` | ✘ (chữ đỏ) | `POST /write/invoices` |

   Hai quyền ghi chỉ tích khi thật sự cần — nên tạo **token riêng** cho việc ghi, tách khỏi token chỉ đọc.
3. Token (dạng `kinv_…`) **chỉ hiển thị một lần**. Sao chép và lưu vào nơi an toàn (VD Script Properties của Google Apps Script). KIOT chỉ lưu bản băm, không xem lại được.
4. Mất token hoặc nghi lộ: bấm **Thu hồi** rồi tạo token mới. Token bị thu hồi bị từ chối ngay (401).

Token tạo trước Đợt 2 giữ nguyên quyền cũ (`inventory:read`) và vẫn chạy bình thường. Trang quản trị hiển thị quyền của từng token, lần dùng gần nhất, IP, số request, nhật ký 50 lượt gọi gần nhất và **nhật ký GHI 100 lượt gần nhất** (giá trị trước/sau, mã lỗi, Idempotency-Key).

> Không gửi token qua chat/email công khai, không đặt token trong URL.

## 2. Xác thực

Mọi request phải có header:

```
Authorization: Bearer kinv_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

## 3. Giới hạn

- **Đọc: 60 request/phút cho mỗi token** (`INVENTORY_API_RATE_LIMIT`). Response có header `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Vượt giới hạn → `429` kèm `Retry-After` (giây).
- **Ghi: 30 request/phút cho mỗi token** (`INVENTORY_API_WRITE_RATE_LIMIT`), đếm riêng, không ăn vào giới hạn đọc.
- Sai token nhiều lần từ cùng IP (mặc định 20 lần/phút) → `429 TOO_MANY_FAILED_ATTEMPTS`.
- `limit` tối đa **100** dòng/trang cho `/inventory/*`, **200** dòng/trang cho `/data/*` (mặc định 50).
- Đọc chỉ nhận `GET`/`HEAD`; mỗi chức năng ghi chỉ nhận đúng 1 phương thức (`PATCH` hoặc `POST`). Phương thức khác → `405 METHOD_NOT_ALLOWED`.

## 4. Endpoint

### 4.1. `GET /products` — hàng hóa và tồn kho

Tham số (query string, đều không bắt buộc):

| Tham số | Kiểu | Ý nghĩa |
|---|---|---|
| `limit` | 1–100 | Số dòng mỗi trang (mặc định 50) |
| `cursor` | string | Lấy từ `meta.next_cursor` của trang trước |
| `updated_since` | ISO 8601 | Chỉ trả hàng có thay đổi từ thời điểm này (xem mục 5) |
| `sku` | string | Lọc đúng 1 mã hàng |
| `category_id` | số | Lọc theo nhóm hàng |
| `in_stock` | `1`/`0` | `1` = chỉ hàng còn tồn (`stock_quantity > 0`) |

Ví dụ:

```
GET /api/public/v1/inventory/products?limit=100&in_stock=1
```

```json
{
  "data": [
    {
      "id": 1532,
      "sku": "DELL7490-I7",
      "barcode": "8930000001234",
      "name": "Dell Latitude 7490 i7-8650U 16GB 512GB",
      "category": { "id": 12, "name": "Laptop Dell" },
      "retail_price": 12990000,
      "has_serial": true,
      "stock_quantity": 3,
      "reserved_quantity": 0,
      "available_quantity": 2,
      "ready_serial_quantity": 2,
      "repairing_serial_quantity": 1,
      "inventory_status": "available",
      "is_active": true,
      "sell_directly": true,
      "deleted": false,
      "updated_at": "2026-11-05T14:30:12+07:00"
    }
  ],
  "meta": {
    "next_cursor": "eyJwcm9kdWN0cy51cGRhdGVkX2F0Ijoi...",
    "has_more": true,
    "limit": 100,
    "updated_since": null,
    "sync_watermark": "2026-11-05T14:35:00+07:00"
  }
}
```

Giải thích trường:

| Trường | Ý nghĩa |
|---|---|
| `retail_price` | Giá bán lẻ (VND) |
| `stock_quantity` | Tồn kho thực tế |
| `reserved_quantity` | Đang giữ cho đơn website chưa hoàn tất |
| `available_quantity` | **Số lượng sẵn bán**: hàng có serial = số serial sẵn sàng − đang giữ; hàng thường = tồn − đang giữ. Bằng 0 nếu hàng ngừng kinh doanh/không bán trực tiếp |
| `ready_serial_quantity` | Số serial trong kho đã sẵn bán (`null` nếu hàng không quản lý serial) |
| `repairing_serial_quantity` | Số serial trong kho đang chờ/đang sửa (`null` nếu không quản lý serial) |
| `inventory_status` | `available` (còn bán) · `repairing` (chỉ còn máy đang sửa) · `reserved` (đã giữ hết) · `sold` (hết hàng) · `inactive` (ngừng kinh doanh) · `deleted` |
| `deleted` | `true` = hàng đã bị xóa trong KIOT, hãy gỡ khỏi bảng |

### 4.2. `GET /serials` — từng serial/IMEI

Mỗi dòng là một serial/IMEI.

| Tham số | Kiểu | Ý nghĩa |
|---|---|---|
| `limit`, `cursor`, `updated_since`, `sku` | | Như `/products` |
| `status` | `in_stock`, `sold`, `returning`, `warranty`, `defective`, `returned`, `dismantled`, `all` | Mặc định: `in_stock` khi **không** có `updated_since`; `all` khi có `updated_since` (để biết serial nào đã bán/rời kho) |
| `readiness` | `ready` / `repairing` | Chỉ serial trong kho sẵn bán / đang sửa |

Ví dụ:

```
GET /api/public/v1/inventory/serials?limit=100&readiness=ready
```

```json
{
  "data": [
    {
      "id": 88120,
      "serial": "5CG8301XYZ",
      "product": { "id": 1532, "sku": "DELL7490-I7", "name": "Dell Latitude 7490 i7-8650U 16GB 512GB", "deleted": false },
      "status": "in_stock",
      "repair_status": "ready",
      "repair_status_label": "Sẵn bán",
      "readiness": "ready",
      "created_at": "2026-10-28T10:05:11+07:00",
      "imported_at": "2026-10-28T09:30:00+07:00",
      "updated_at": "2026-11-05T09:12:40+07:00"
    }
  ],
  "meta": { "next_cursor": null, "has_more": false, "limit": 100, "updated_since": null, "sync_watermark": "2026-11-05T14:35:00+07:00" }
}
```

| Trường | Ý nghĩa |
|---|---|
| `status` | Trạng thái kho: `in_stock` (trong kho), `sold` (đã bán), `returning`, `warranty`, `defective`, `returned`, `dismantled` (đã tháo linh kiện) |
| `repair_status` | `ready` (Sẵn bán), `not_started` (Chưa làm), `repairing` (Đang xử lý), hoặc `null` (không qua sửa chữa = sẵn bán) |
| `readiness` | Kết luận cuối: `ready` (trong kho và sẵn bán), `repairing` (trong kho nhưng chưa sửa xong), `not_in_stock` (không còn trong kho) |
| `created_at` | Thời điểm serial được tạo trong hệ thống (ISO 8601) |
| `imported_at` | Ngày nhập hàng của phiếu nhập chứa serial (ISO 8601); `null` nếu serial không gắn phiếu nhập (tạo tay, tồn đầu kỳ...) |

KIOT hiện quản lý một kho chung nên serial không có trường kho/chi nhánh.

## 5. Đồng bộ định kỳ (khuyến nghị cho polling 5–10 phút)

1. **Lần đầu (full sync):** gọi `/products` (và/hoặc `/serials`) không có `updated_since`, lặp theo `cursor` đến khi `has_more = false`. Lưu `meta.sync_watermark` của **trang đầu tiên**.
2. **Các lần sau (incremental):** gọi với `updated_since = watermark đã lưu trừ 2 phút` (chồng lấn để không sót giao dịch ghi chậm), lặp hết các trang, cập nhật/ghi đè theo `id` (hoặc `sku`/`serial`), rồi lưu watermark mới từ trang đầu.
3. Có `updated_since`:
   - `/products` trả cả hàng có **serial hoặc đơn giữ hàng thay đổi** (VD một máy chuyển từ "Đang xử lý" sang "Sẵn bán", hoặc vừa bán), kể cả khi thông tin hàng không đổi; hàng đã xóa trả về với `deleted: true`.
   - `/serials` trả mọi trạng thái, nên serial vừa bán sẽ xuất hiện với `status: "sold"`, `readiness: "not_in_stock"` → xóa khỏi bảng sẵn bán.
4. Nhớ URL-encode `updated_since` (dấu `+` của múi giờ phải thành `%2B`, VD `2026-11-05T14%3A33%3A00%2B07%3A00`), hoặc dùng dạng UTC `2026-11-05T07:33:00Z`.
5. Dữ liệu trùng do chồng lấn là bình thường: luôn **ghi đè theo khóa**, không cộng dồn.
6. Mỗi ngày nên chạy lại full sync một lần để tự sửa sai lệch.
7. Gặp `429`: chờ đúng số giây trong `Retry-After` rồi thử lại. Gặp `5xx`/mất mạng: thử lại sau 1–2 phút, không lặp liên tục.

Ví dụ Google Apps Script (rút gọn):

```javascript
function syncReadyStock() {
  const props = PropertiesService.getScriptProperties();
  const token = props.getProperty('KIOT_TOKEN');
  const base = 'https://kiot.cuongdesign.net/api/public/v1/inventory/products';
  let cursor = null, rows = [];
  do {
    const url = base + '?limit=100&in_stock=1' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');
    const res = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' }, muteHttpExceptions: true });
    if (res.getResponseCode() !== 200) throw new Error(res.getResponseCode() + ' ' + res.getContentText());
    const body = JSON.parse(res.getContentText());
    body.data.filter(p => p.available_quantity > 0).forEach(p => rows.push([p.sku, p.name, p.category ? p.category.name : '', p.retail_price, p.available_quantity, p.repairing_serial_quantity, p.updated_at]));
    cursor = body.meta.has_more ? body.meta.next_cursor : null;
  } while (cursor);
  const sheet = SpreadsheetApp.getActive().getSheetByName('San ban');
  sheet.clearContents();
  sheet.getRange(1, 1, 1, 7).setValues([['SKU', 'Tên', 'Nhóm', 'Giá bán', 'Sẵn bán', 'Đang sửa', 'Cập nhật']]);
  if (rows.length) sheet.getRange(2, 1, rows.length, 7).setValues(rows);
}
```

(Ví dụ trên dùng full sync cho đơn giản; với vài nghìn mã hàng, 1 lần chạy chỉ tốn vài request, nằm trong giới hạn 60 request/phút.)

## 6. Mã lỗi

Mọi lỗi có dạng:

```json
{ "error": { "code": "INVALID_TOKEN", "message": "Token không hợp lệ." } }
```

| HTTP | `code` | Nguyên nhân |
|---|---|---|
| 401 | `MISSING_TOKEN` | Thiếu header `Authorization: Bearer …` |
| 401 | `INVALID_TOKEN` | Token sai |
| 401 | `TOKEN_REVOKED` | Token đã bị thu hồi |
| 403 | `FORBIDDEN` | Token không có quyền (ability) cho endpoint này |
| 404 | `INVOICE_NOT_FOUND` / `PRODUCT_NOT_FOUND` | Không có hóa đơn / mã hàng (endpoint chi tiết, sửa giá thợ) |
| 405 | `METHOD_NOT_ALLOWED` | Sai phương thức (đọc chỉ `GET`; ghi chỉ `PATCH` hoặc `POST` theo endpoint) |
| 409 | `IDEMPOTENCY_CONFLICT` | `Idempotency-Key` đã dùng cho nội dung khác |
| 409 | `IDEMPOTENCY_IN_PROGRESS` | Request cùng key đang được xử lý — thử lại sau vài giây |
| 422 | `VALIDATION_FAILED` | Tham số/body sai (VD `limit` quá lớn, ngày không hợp lệ); chi tiết trong `error.details` |
| 422 | `INVALID_CURSOR` | `cursor` hỏng — bắt đầu lại từ trang đầu |
| 422 | mã nghiệp vụ của chức năng ghi | Xem bảng ở mục 9.4 |
| 429 | `RATE_LIMITED` / `TOO_MANY_FAILED_ATTEMPTS` | Vượt giới hạn, xem `Retry-After` |
| 500 | `INTERNAL_ERROR` | Lỗi hệ thống ở chức năng ghi; chưa ghi dữ liệu, có thể gửi lại cùng `Idempotency-Key` |
| 503 | `API_DISABLED` | KIOT tạm tắt API |

Lỗi của chức năng ghi có thêm `error.log_id` (số dòng trong nhật ký ghi) để đối chiếu với quản trị KIOT.

## 7. Dữ liệu KHÔNG được trả về ở `/inventory/*`

Giá vốn, tổng giá trị tồn, giá nhập, nhà cung cấp, phiếu nhập, hóa đơn, khách hàng, công nợ, mô tả nội bộ. Các dữ liệu kinh doanh (kể cả giá vốn từng dòng hóa đơn) chỉ có ở `/data/*` với quyền `data:read`.

## 8. Dữ liệu kinh doanh — `GET /data/*` (quyền `data:read`)

Phân trang giống `/inventory/*`: sắp theo `COALESCE(updated_at, created_at)` + `id`, trả `meta.next_cursor`, `meta.has_more`, `meta.sync_watermark`.

Tham số chung (đều không bắt buộc):

| Tham số | Ý nghĩa |
|---|---|
| `limit` | 1–200, mặc định 50 |
| `cursor` | Lấy từ `meta.next_cursor` của trang trước |
| `updated_since` | ISO 8601 — chỉ trả bản ghi tạo/sửa từ thời điểm này (đồng bộ tăng dần) |
| `from`, `to` | Ngày nghiệp vụ `YYYY-MM-DD` (theo giờ cửa hàng). Hóa đơn: ngày bán; trả hàng: ngày trả; nhập hàng: ngày nhập; sửa chữa: ngày nhận máy; công việc: ngày tạo; sổ quỹ: thời gian phiếu |

Tên người bán/nhân viên (`seller.name`, `created_by_name`) đi qua bảng **alias nhân viên của báo cáo** (VD tài khoản "Claude AI" hiển thị là Trần Văn Tiến). Tên gốc luôn trả kèm ở `seller.raw_name` / `created_by_raw_name`.

| Endpoint | Lọc thêm | Trường chính |
|---|---|---|
| `GET /data/invoices` | `status`, `customer_code` | `code`, `status`, `is_cancelled`, `transaction_at`, `customer{code,name,phone}`, `seller{name,raw_name,employee_id}`, `created_by_name`, `created_by_raw_name`, `sales_channel`, `price_book_name`, `payment_method`, `subtotal`, `discount`, `total`, `customer_paid`, `order_deposit_applied`, `remaining`, `order_code`, `note`, `delivery{is_delivery,delivery_partner,tracking_code,delivery_fee,cod_amount,receiver_*,delivery_note}`, `items[{sku,name,quantity,price,discount,subtotal,cost_price,serials[],warranty_policy}]` |
| `GET /data/invoices/{code}` | — | Một hóa đơn, cùng cấu trúc (`{"data": {...}}`); không có → `404 INVOICE_NOT_FOUND` |
| `GET /data/returns` | `status` | `code`, `status`, `returned_at`, `invoice_code`, `customer`, `seller{name,raw_name}`, `received_by_name`, `created_by_name`, `subtotal`, `discount`, `fee`, `total`, `paid_to_customer`, `note`, `items[{sku,name,quantity,price,discount,cost_price,serials[]}]` |
| `GET /data/purchases` | `status` | `code`, `status`, `purchase_date`, `supplier{code,name,phone}`, `created_by_name`, `employee_name`, `total_amount`, `discount`, `other_costs_total`, `paid_amount`, `debt_amount`, `payment_method`, `note`, `items[{sku,name,quantity,price,discount,subtotal,unit_cost_allocated,warranty_months}]` |
| `GET /data/repairs` | `status` | Phiếu sửa: `code`, `title`, `status`, `status_label`, `sub_status`, `product{sku,name}`, `serial`, `customer`, `issue_description`, `assigned_employee`, `invoice_code`, chi phí (`original_cost`, `parts_cost`, `total_cost`, `labor_fee`, `parts_total`, `total_amount`, `paid_amount`, `debt_amount`, `warranty_covered_amount`, `customer_payable_amount`), `parts[{sku,name,quantity,unit_cost,sale_price,total_cost,direction}]`, `received_at`, `deadline`, `returned_at`, `completed_at`, `cancelled_at` |
| `GET /data/tasks` | `status`, `type=general\|repair` | Công việc: `code`, `type`, `title`, `status`, `status_label`, `priority`, `progress`, `category`, `assigned_employee`, `assignees[{name,status,assigned_at}]`, `deadline`, `created_by_name`, `created_at`, `completed_at`, `cancelled_at` |
| `GET /data/customer-debts` | `customer_code` | Khách đang có công nợ ≠ 0: `code`, `name`, `phone`, `is_supplier`, `receivable_gross` (**Nợ phải thu chưa bù trừ** — như thẻ Tổng quan), `supplier_payable`, `receivable_net` (**Nợ phải thu đã bù trừ công nợ NCC/ứng trước** — như trang Khách hàng), `stored_debt_amount`, `total_spent` |
| `GET /data/cashflows` | `type=receipt\|payment`, `status=active\|cancelled` | Sổ quỹ: `code`, `type`, `amount`, `time`, `category`, `target{type,id,name}`, `reference_type`, `reference_code`, `payment_method`, `accounting_result`, `status`, `is_cancelled`, `description`, `deleted` |
| `GET /data/overview?from=&to=` | `from`, `to` (mặc định đầu tháng → hôm nay) | Báo cáo Tổng quan cho kỳ (cùng nguồn `MetricService` với Tổng quan/Báo cáo kinh doanh): `invoice_count`, `return_count`, `gross_revenue`, `invoice_discount`, `return_value`, `net_revenue`, `cogs_sold`, `cogs_returned`, `cogs_net`, `gross_profit`, `expenses`, `cash_receipts`, `cash_payments`, `purchase_total`, `return_total`, `new_customers`, và số dư hiện tại `customer_receivable_unoffset` (chưa bù trừ), `supplier_payable` |

Ví dụ:

```bash
curl -H "Authorization: Bearer $KIOT_TOKEN" \
  "https://kiot.cuongdesign.net/api/public/v1/data/invoices?from=2026-11-01&to=2026-11-15&limit=200"
curl -H "Authorization: Bearer $KIOT_TOKEN" \
  "https://kiot.cuongdesign.net/api/public/v1/data/overview?from=2026-11-01&to=2026-11-30"
```

## 9. Hai chức năng ghi (`/write/*`)

Mọi lần gọi (thành công hay bị từ chối) được ghi vào **nhật ký ghi** (`public_api_write_logs`): token, chức năng, đối tượng (SKU / mã hóa đơn), giá trị trước/sau, `Idempotency-Key`, mã HTTP, mã lỗi, IP. Thành công còn ghi thêm Nhật ký hoạt động của KIOT.

### 9.1. Idempotency-Key

- Header `Idempotency-Key`: 8–191 ký tự `A-Z a-z 0-9 . _ : -`, **duy nhất theo token**. Gợi ý: `sheet-doanhso-<số dòng>-<ngày>`.
- **Bắt buộc** với `POST /write/invoices`; không bắt buộc với sửa giá thợ.
- Gửi lại **cùng key + cùng body** → KIOT trả lại đúng kết quả lần đầu, HTTP `200`, `"duplicate": true` — không tạo thêm hóa đơn.
- **Cùng key + body khác** → `409 IDEMPOTENCY_CONFLICT`. Request cùng key đang chạy → `409 IDEMPOTENCY_IN_PROGRESS`.
- Lần đầu bị lỗi nghiệp vụ (422) thì gửi lại cùng key sẽ nhận lại đúng lỗi đó; hãy sửa dữ liệu và dùng **key mới**. Lỗi hệ thống (500) thì được gửi lại cùng key.

### 9.2. `PATCH /write/products/{sku}/technician-price` (quyền `products:technician_price:write`)

Chỉ sửa **Giá bán thợ** (`products.technician_price`) của mã hàng `{sku}`. Body chỉ được có:

| Trường | Bắt buộc | Kiểu |
|---|---|---|
| `technician_price` | có | số nguyên VND, 0 – 10.000.000.000 |
| `note` | không | ghi chú ≤ 500 ký tự (lưu vào nhật ký) |

Gửi thêm bất kỳ trường nào khác → `422 UNKNOWN_FIELDS` (không sửa gì).

```bash
curl -X PATCH "https://kiot.cuongdesign.net/api/public/v1/write/products/LT-DELL-5420/technician-price" \
  -H "Authorization: Bearer $KIOT_WRITE_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Idempotency-Key: giatho-LT-DELL-5420-20261116" \
  -d '{"technician_price": 9500000, "note": "Sheet giá thợ dòng 12"}'
```

```json
{ "duplicate": false, "data": { "sku": "LT-DELL-5420", "name": "Dell Latitude 5420", "technician_price_old": 9000000, "technician_price_new": 9500000, "log_id": 15 } }
```

### 9.3. `POST /write/invoices` (quyền `invoices:create`)

Tạo **một hóa đơn bán hàng MỚI** từ một dòng Sheet "Doanh số". Không bao giờ sửa/xóa hóa đơn đã có. Đi qua đúng luồng tạo hóa đơn của màn hình Hóa đơn (kiểm tra ngày nhập hàng, tồn kho theo cài đặt, serial, giá vốn, khóa sổ, công nợ, sổ quỹ, bảo hành) và **luôn cấm bán âm kho** dù cài đặt cửa hàng cho phép.

| Trường | Bắt buộc | Ghi chú |
|---|---|---|
| `items[]` | có (1–100 dòng) | Mỗi dòng có `sku` **hoặc** `serial` |
| `items[].sku` | | Hàng thường. Hàng Serial/IMEI gửi bằng `sku` → `422 SERIAL_REQUIRED` |
| `items[].serial` | | Serial/IMEI phải đang **trong kho**; KIOT tự lấy mã hàng. Dòng serial có `quantity` = 1. Có thể gửi kèm `sku` để chặn nhầm mã |
| `items[].quantity` | | Số nguyên ≥ 1, mặc định 1 |
| `items[].price` | có | Đơn giá, số nguyên VND |
| `items[].discount` | | Giảm giá của cả dòng (VND), mặc định 0 |
| `items[].warranty_policy` | | Tên gói bảo hành, VD `"3 tháng"` |
| `customer` | | `{"code": "KH000123"}` hoặc `{"phone": "0912345678", "name": "Nguyễn Văn A"}`. SĐT chưa có + có `name` → tạo khách mới; SĐT chưa có, không có `name` → `422 CUSTOMER_NOT_FOUND`. Bỏ trống/`null` = khách lẻ (khi đó phải trả đủ tiền) |
| `seller` | có | `{"user_id": 5}` hoặc `{"name": "Trần Văn Tiến"}` — phải ra đúng **một** tài khoản/nhân viên đang hoạt động |
| `customer_paid` | | Khách đã trả (VND), mặc định 0, không vượt tổng |
| `payment_method` | | `cash` (mặc định) hoặc `transfer` |
| `sold_at` | | ISO 8601, mặc định lúc gọi; không ở tương lai, không trong kỳ khóa sổ |
| `source_note` | | VD `"Sheet Doanh số dòng 123"` → ghi chú hóa đơn `[API <tên token>] Sheet Doanh số dòng 123` |

KIOT **tự tính** tổng tiền: `subtotal = total = Σ(price × quantity − discount)`. Các trường tổng do client gửi bị bỏ qua. Người tạo hóa đơn ghi là `API: <tên token>`, người bán theo `seller`.

```bash
curl -X POST "https://kiot.cuongdesign.net/api/public/v1/write/invoices" \
  -H "Authorization: Bearer $KIOT_WRITE_TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Idempotency-Key: sheet-doanhso-123-20261116" \
  -d '{
    "items": [
      {"serial": "5CD1234XYZ", "price": 12000000, "warranty_policy": "3 tháng"},
      {"sku": "CHUOT-LOGI-M331", "quantity": 1, "price": 250000, "discount": 50000}
    ],
    "customer": {"phone": "0912345678", "name": "Nguyễn Văn A"},
    "seller": {"name": "Trần Văn Tiến"},
    "customer_paid": 12200000,
    "payment_method": "transfer",
    "sold_at": "2026-11-16T10:30:00+07:00",
    "source_note": "Sheet Doanh số dòng 123"
  }'
```

```json
{ "duplicate": false, "data": { "invoice_id": 9012, "invoice_code": "HD2026111610300045", "total": 12200000, "customer_paid": 12200000, "remaining": 0, "customer_code": "KHAPI261116103000123", "log_id": 16 } }
```

HTTP `201` khi tạo mới; gửi lại cùng key + body → `200` với `"duplicate": true`.

### 9.4. Mã lỗi nghiệp vụ của chức năng ghi (HTTP 422)

| `code` | Ý nghĩa |
|---|---|
| `VALIDATION_FAILED` | Body sai kiểu/thiếu trường (chi tiết `error.details`) |
| `UNKNOWN_FIELDS` | Sửa giá thợ: gửi trường khác ngoài `technician_price`, `note` |
| `IDEMPOTENCY_KEY_REQUIRED` / `INVALID_IDEMPOTENCY_KEY` | Thiếu / sai định dạng `Idempotency-Key` |
| `PRODUCT_NOT_FOUND` | Không có mã hàng của dòng (sửa giá thợ trả 404) |
| `SERIAL_REQUIRED` | Hàng Serial/IMEI phải gửi `serial` từng máy |
| `SERIAL_NOT_FOUND` / `SERIAL_NOT_AVAILABLE` / `SERIAL_AMBIGUOUS` / `DUPLICATE_SERIAL` | Serial không có / đã bán, đang sửa / thuộc nhiều mã (gửi kèm `sku`) / bị lặp |
| `STOCK_INSUFFICIENT` | Không đủ tồn (API không bao giờ bán âm kho) |
| `COST_PENDING` | Hàng web tạo chưa có phiếu nhập/giá vốn |
| `BEFORE_PURCHASE_DATE` | Ngày bán trước ngày nhập hàng đầu tiên |
| `SOLD_AT_IN_FUTURE` / `PERIOD_LOCKED` | `sold_at` ở tương lai / trong kỳ đã khóa sổ |
| `SELLER_REQUIRED` / `SELLER_NOT_FOUND` / `SELLER_AMBIGUOUS` | Người bán thiếu / không tìm thấy / trùng tên (gửi `user_id`) |
| `CUSTOMER_NOT_FOUND` / `CUSTOMER_PHONE_REQUIRED` / `CUSTOMER_MERGED` / `CUSTOMER_INACTIVE` / `CUSTOMER_NOT_CUSTOMER` / `INVALID_CUSTOMER` / `PARTNER_REJECTED` | Khách hàng không dùng được |
| `CUSTOMER_REQUIRED` | Khách lẻ nhưng chưa trả đủ tiền |
| `OVERPAYMENT` | `customer_paid` lớn hơn tổng hóa đơn |
| `SALE_REJECTED` | Từ chối khác của luồng bán hàng (xem `error.message`) |

Khi bị từ chối, KIOT **không ghi gì** ngoài dòng nhật ký.

## 10. Lưu ý cho Google Apps Script

- Lưu token trong Script Properties, gửi bằng `UrlFetchApp.fetch(url, {method: 'post', contentType: 'application/json', headers: {...}, payload: JSON.stringify(body), muteHttpExceptions: true})`.
- Ghi `invoice_code` / `log_id` trả về vào cột của dòng Sheet; dòng đã có mã hóa đơn thì không gửi lại.
- Nhận `422`: hiển thị `error.message` (tiếng Việt) cho người dùng, sửa dòng rồi gửi lại với **Idempotency-Key mới**. Nhận `429`: chờ `Retry-After` giây. Nhận `5xx`: gửi lại cùng key.
