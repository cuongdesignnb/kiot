# RR-08 — Hủy phiếu trả hàng khách phải rollback đúng Serial/IMEI

> **Mã rủi ro:** RR-08
> **Mức độ:** P1 — Sai serial/IMEI, sai truy vết hàng hóa, sai lịch sử hóa đơn
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Khi hủy phiếu trả hàng (`OrderReturnController@cancel`), hệ thống phải rollback **đúng** Serial/IMEI đã được trả trong phiếu đó (gán lại vào invoice gốc). Không được chọn nhầm serial khác đang in_stock.

---

## Quy ước nghiệp vụ kỳ vọng

1. **Khi tạo phiếu trả hàng có Serial/IMEI**, phải biết và lưu chính xác serial đã được trả (ví dụ: cột `serial_ids` trên `return_items`, hoặc bảng trung gian `return_item_serials`, hoặc dùng `invoice_item_serials.serial_imei_id` link gián tiếp qua `return_item.invoice_item_id`).
2. **Khi hủy phiếu trả hàng:**
   - Chỉ rollback đúng serial đã thuộc phiếu trả đó.
   - Không được chọn đại serial khác đang `in_stock`.
   - Serial được rollback phải `status = 'sold'`, `invoice_id = invoice_id` ban đầu, `sold_at` set lại, `sold_cost_price` set lại.
3. **Hủy lặp idempotent:** lần 2 không đổi serial thêm.

---

## Bug hiện tại (đã đọc code)

`OrderReturnController@cancel` dòng 397-407:

```php
SerialImei::where('product_id', $item->product_id)
    ->where('status', 'in_stock')
    ->whereNull('invoice_id')
    ->limit($item->quantity)
    ->update([
        'status' => 'sold',
        'sold_at' => now(),
        'invoice_id' => $return->invoice_id,
    ]);
```

**Vấn đề:**
- Query lấy bất kỳ serial nào đang `status='in_stock'` và `invoice_id IS NULL` của product đó.
- Không biết serial nào thực sự đã được trả vì `return_items` không lưu serial reference.
- `LIMIT $qty` không deterministic (theo MySQL): có thể lấy nhầm serial khác.
- **Hậu quả:**
  - Serial khác (chưa từng thuộc invoice) bị gán `invoice_id`, `status='sold'`.
  - Serial thực sự đã trả vẫn `invoice_id=null, status='in_stock'`.
  - Lịch sử serial sai vĩnh viễn.

---

## Schema hiện tại (discovery)

| Bảng | Cột serial-related | Đánh giá |
|---|---|---|
| `returns` | invoice_id, customer_id, branch_id, status, ... | Không có serial |
| `return_items` | id, return_id, product_id, quantity, price, discount, import_price, **`cost_price`**, **`invoice_item_id`**, timestamps | **Không có** `serial_id` / `serial_ids` / `serials` JSON |
| Bảng trung gian `return_item_serials` | — | **Không tồn tại** |
| `serial_imeis` | id, product_id, serial_number, status, **purchase_id**, **purchase_return_id**, repair_status, cost_price, original_cost, sold_cost_price, sold_at, **invoice_id**, ... | Không có `order_return_id` / `return_id` |
| `invoice_item_serials` | id, invoice_item_id, serial_imei_id, serial_number, cost_price | Có thể dùng JOIN qua `return_items.invoice_item_id` để biết serial gốc, nhưng controller hiện không dùng |

→ **Schema thiếu cột/bảng để lưu serial đã trả.** Bước 13.1B sẽ phải bổ sung migration (cột `serial_ids` JSON trên `return_items` hoặc tạo `return_item_serials`).

---

## Test cases

### TC-RR08-01: Hủy phiếu trả hàng phải rollback đúng serial đã trả

**Setup:**
- Product `has_serial = true`.
- Serial B: `status = 'in_stock'`, `invoice_id = null` (chưa từng bán).
- Serial A: bán qua invoice → `status = 'sold'`, `invoice_id = invoice.id`.
- OrderReturn trả Serial A → A `status = 'in_stock'`, `invoice_id = null`.

**Action:** Gọi `OrderReturnController@cancel($return)`.

**Kỳ vọng:**
- Serial A: `invoice_id = invoice.id`, `status = 'sold'`.
- Serial B: `invoice_id = null`, `status = 'in_stock'` (KHÔNG bị động tới).

### TC-RR08-02: Cancel không được gán bất kỳ serial khác chưa thuộc invoice vào invoice

**Setup:**
- Product `has_serial = true`.
- Serial B (id nhỏ hơn, in_stock từ đầu, chưa thuộc invoice).
- Serial A (id lớn hơn, đã bán qua invoice).
- Trả Serial A → cả A và B đều in_stock, invoice_id = null.

**Action:** Cancel phiếu trả.

**Kỳ vọng:**
- Số serial có `invoice_id = invoice.id` sau cancel = **1** (chỉ Serial A).
- Serial B `invoice_id` vẫn null.

### TC-RR08-03: Hủy lặp phải idempotent — không đổi serial thêm

**Setup:** sau TC-01.

**Action:** Cancel lần 2.

**Kỳ vọng:**
- Serial A và Serial B trạng thái không đổi từ sau lần cancel 1.
- `OrderReturn::status = 'Đã hủy'` (giữ nguyên).

### TC-RR08-04: ReturnItem schema phải lưu serial reference đã trả

**Action:** Inspect schema `return_items`.

**Kỳ vọng:** Có ít nhất một trong các sau:
- `return_items.serial_ids` (JSON / TEXT) hoặc
- Bảng `return_item_serials` (return_item_id ↔ serial_imei_id), hoặc
- `serial_imeis.return_id` / `serial_imeis.order_return_id`.

**Hiện tại:** None of these → test **FAIL** chứng minh limitation schema.

---

## Lưu ý route

- `OrderReturnController@cancel` đã có method nhưng **route chưa được đăng ký** trong `routes/web.php` (đã ghi backlog từ RR-11). Test gọi controller method **trực tiếp** qua `app(OrderReturnController::class)->cancel($return)`, không qua route.
- Bước 13.1B nên kèm việc đăng ký route `returns.cancel` (P1 backlog đã có sẵn).

---

## Phạm vi sửa (Bước 13.1B)

1. Migration: thêm cột `serial_ids` (JSON) trên `return_items` để lưu danh sách `serial_imei_id` đã trả khi tạo phiếu (`store`).
2. `OrderReturnController@store`: ghi `serial_ids` vào ReturnItem mới tạo.
3. `OrderReturnController@cancel`: thay query `whereNull('invoice_id')->limit($qty)` bằng `whereIn('id', $returnItem->serial_ids)` để rollback đúng serial.
4. Đăng ký route `returns.cancel`.

(Phạm vi cụ thể quyết định ở Bước 13.1B.)
