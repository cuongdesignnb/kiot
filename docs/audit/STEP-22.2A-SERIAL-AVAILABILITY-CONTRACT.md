# Serial/IMEI Availability Contract — Step 22.2A

**Phạm vi:** Định nghĩa thống nhất "Serial/IMEI khả dụng" cho POS, Order Create, Order Update, Order Process, và mọi tầng đọc/lọc serial trong hệ thống.

**Nguyên tắc:** Tương thích cả dữ liệu cũ lẫn dữ liệu mới. Không tự sửa data. Không tự chọn đại serial.

---

## 1. Mục tiêu

Trước đây mỗi controller tự viết filter:
- POS: `status = 'in_stock'` + `repair_status NOT IN (not_started, repairing)`
- Order Validate: `status = 'in_stock'` (cứng).

Khi production có serial cũ với:
- `status = NULL` (default cũ chưa set),
- `status = 'available'` / `'ready'` (legacy variant),
- Hoặc thiếu cột `invoice_id` / `sold_at` (legacy schema),

thì các filter cũ MISS — selector hiển thị rỗng dù serial thực tế chưa bán.

Cần 1 service duy nhất quyết định "sellable" với compatibility layer.

---

## 2. Field tham chiếu

| Field | Bắt buộc? | Ý nghĩa |
|---|---|---|
| `serial_imeis.product_id` | có | Phải khớp product. |
| `serial_imeis.status` | có (default `'in_stock'`) | Trạng thái nghiệp vụ. |
| `serial_imeis.repair_status` | nullable | Trạng thái sửa chữa. |
| `serial_imeis.invoice_id` | NULLABLE & có thể KHÔNG TỒN TẠI trên schema cũ | Đã gán hóa đơn ⇒ đã bán. |
| `serial_imeis.sold_at` | NULLABLE & có thể KHÔNG TỒN TẠI trên schema cũ | Thời điểm bán. |
| `serial_imeis.sold_cost_price` | NULLABLE & có thể KHÔNG TỒN TẠI trên schema cũ | Giá vốn snapshot lúc bán. |
| `serial_imeis.purchase_return_id` | NULLABLE & có thể KHÔNG TỒN TẠI trên schema cũ | Đã trả NCC. |

⇒ Service **bắt buộc** dùng `Schema::hasColumn` để biết cột nào tồn tại trước khi áp filter.

---

## 3. Quy tắc sellable (sau Step 22.2A)

Một serial được coi **sellable** khi tất cả điều kiện sau đúng:

### 3.1. Quan hệ (BẮT BUỘC)
- `product_id` khớp product được hỏi.

### 3.2. Status (mềm — chấp nhận legacy)
**Sellable status set:**
- `'in_stock'` — chuẩn mới.
- `'available'` — legacy alias.
- `'ready'` — legacy alias.
- `NULL` — legacy data chưa set status (xử lý như "unspecified, có thể bán nếu không có dấu hiệu đã bán").

**Blocked status set (KHÔNG sellable):**
- `'sold'`
- `'damaged'`
- `'cancelled'`
- `'returned_to_supplier'`
- `'refunded'`
- `'reserved'` (giữ trước cho future, hiện tại chưa dùng)

⇒ Logic: **WHERE (status IN sellable_set OR status IS NULL) AND status NOT IN blocked_set**.

### 3.3. Repair status (BẮT BUỘC)
- `repair_status` không thuộc `['not_started', 'repairing']`.
- `NULL` được phép (legacy).

### 3.4. Đã bán/đã trả NCC (CHỈ áp khi cột tồn tại)
- Nếu schema có `invoice_id`: `invoice_id IS NULL`.
- Nếu schema có `sold_at`: `sold_at IS NULL`.
- Nếu schema có `purchase_return_id`: `purchase_return_id IS NULL`.

Nếu schema KHÔNG có những cột này (legacy), bỏ filter — chỉ dựa vào status.

### 3.5. Legacy flag
Nếu serial pass quy tắc nhưng `status IS NULL` ⇒ đánh dấu `is_legacy_status = true` để UI hiện badge "Dữ liệu cũ".

---

## 4. Mapping legacy status

**Schema thực tế** (sau khi grep migrations):
```sql
status ENUM('in_stock','sold','returning','warranty','defective','returned')
       NOT NULL DEFAULT 'in_stock'
```
Migrations: `2026_02_27_085836_create_serial_imeis_table.php` + `2026_04_05_002210_add_returned_status_to_serial_imeis_table.php`.

⇒ Ở DB hiện tại: status không bao giờ NULL, không bao giờ là `available`/`ready`. Service vẫn nới lỏng để **defensively tolerant** với migration tương lai mở rộng ENUM.

| Status thực tế | Sellable | Ý nghĩa |
|---|---|---|
| `'in_stock'` | ✅ | Sẵn bán. |
| `'sold'` | ❌ | Đã bán. |
| `'returning'` | ❌ | Đang xử lý trả hàng. |
| `'warranty'` | ❌ | Đang bảo hành. |
| `'defective'` | ❌ | Lỗi. |
| `'returned'` | ❌ | Đã trả lại NCC/khách. |
| `'available'` / `'ready'` (future) | ✅ | Alias dự phòng. |
| `NULL` (future) | ✅ | Legacy chưa set status. |
| Tên khác | ❌ (an toàn) | Báo admin. |

---

## 5. Ngoài phạm vi Step 22.2A

- KHÔNG backfill production.
- KHÔNG normalize status.
- KHÔNG reserve serial.
- KHÔNG sửa core service (MovingAvg, StockMovement, InvoiceSale).

Khi cần chuẩn hóa data, tạo command riêng:
```bash
php artisan serials:audit --dry-run
php artisan serials:normalize --dry-run
php artisan serials:normalize --apply
```
Bước này CHƯA tạo command đó.
