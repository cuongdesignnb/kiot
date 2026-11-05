# API tồn kho chỉ đọc (Inventory Read-only API)

Tài liệu dành cho đối tác (VD: Laptop Plus) muốn tự động lấy tồn kho, danh sách serial/IMEI và trạng thái sửa chữa từ KIOT để cập nhật bảng "hàng sẵn bán" mỗi 5–10 phút.

- **Base URL:** `https://kiot.cuongdesign.net/api/public/v1/inventory`
- **Chỉ đọc:** token chỉ gọi được 2 endpoint `GET /products` và `GET /serials`. Không tạo/sửa/xóa, không đặt đơn, không xem phiếu thu/chi, không có giá vốn.
- **Định dạng:** JSON, UTF-8. Thời gian theo ISO 8601 có múi giờ (VD `2026-11-05T14:30:00+07:00`).

## 1. Lấy token

1. Quản trị viên KIOT vào **Cài đặt → API tồn kho (chỉ đọc)** (`/settings/integrations/inventory-api`). Cần quyền `integrations.manage`.
2. Bấm **Tạo token mới**, đặt tên (VD `Laptop Plus - Google Sheet`) và tài khoản/đối tác sử dụng.
3. Token (dạng `kinv_…`) **chỉ hiển thị một lần**. Sao chép và lưu vào nơi an toàn (VD Script Properties của Google Apps Script). KIOT chỉ lưu bản băm, không xem lại được.
4. Mất token hoặc nghi lộ: bấm **Thu hồi** rồi tạo token mới. Token bị thu hồi bị từ chối ngay (401).

Trang quản trị hiển thị lần dùng gần nhất, IP, số request và nhật ký 50 lượt gọi gần nhất.

> Không gửi token qua chat/email công khai, không đặt token trong URL.

## 2. Xác thực

Mọi request phải có header:

```
Authorization: Bearer kinv_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

## 3. Giới hạn

- **60 request/phút cho mỗi token.** Response có header `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Vượt giới hạn → `429` kèm `Retry-After` (giây).
- Sai token nhiều lần từ cùng IP (mặc định 20 lần/phút) → `429 TOO_MANY_FAILED_ATTEMPTS`.
- `limit` tối đa **100** dòng/trang.

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
| 403 | `FORBIDDEN` | Token không có quyền đọc tồn kho |
| 405 | — | Dùng phương thức khác GET (API chỉ đọc) |
| 422 | `VALIDATION_FAILED` | Tham số sai (VD `limit` > 100, ngày không hợp lệ); chi tiết trong `error.details` |
| 422 | `INVALID_CURSOR` | `cursor` hỏng — bắt đầu lại từ trang đầu |
| 429 | `RATE_LIMITED` / `TOO_MANY_FAILED_ATTEMPTS` | Vượt giới hạn, xem `Retry-After` |
| 503 | `API_DISABLED` | KIOT tạm tắt API |

## 7. Dữ liệu KHÔNG được trả về

Giá vốn, tổng giá trị tồn, giá nhập, nhà cung cấp, phiếu nhập, hóa đơn, khách hàng, công nợ, mô tả nội bộ. Nếu cần thêm trường, liên hệ quản trị KIOT.
