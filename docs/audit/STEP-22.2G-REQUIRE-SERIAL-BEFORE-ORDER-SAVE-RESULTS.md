# STEP 22.2G — Require Serial/IMEI Before Order Save

**Date:** 2026-05-04
**Branch:** main

---

## 1. Lỗi user phát hiện
- Trên Orders/Create, sản phẩm `has_serial=true` không tick Serial/IMEI vẫn lưu Order thành công.
- Lỗi chỉ phát hiện khi processOrder → user phải sửa Order. Sai contract.

## 2. Root cause
- **Backend** (`OrderController@store` & `@update`): vòng kiểm tra serial nằm trong `if (!empty($serialIds)) { ... }`. Khi user gửi `serial_ids: []` cho hàng has_serial → bỏ qua hoàn toàn, lưu `order_items.serial_ids = null`.
- **Frontend** (`Orders/Create.vue`): `save()` / `saveAndPrint()` không validate đếm serial_ids vs qty trước khi submit.
- **Hệ quả phụ ở backend**: ngay cả khi sửa logic vào foreach, Order::create đã chạy trước → fail giữa chừng để lại Order rỗng. Cần pre-flight TRƯỚC khi viết DB.

## 3. File sửa

| File | Nội dung |
|---|---|
| `app/Http/Controllers/OrderController.php` | + private helper `validateItemsSerials(array $items): ?RedirectResponse` chạy pre-flight cho cả store + update; gọi TRƯỚC `Order::create` (store) và TRƯỚC `items()->delete()` (update); foreach trong store/update đơn giản hoá: nếu `product->has_serial` thì luôn enforce `count(serialIds) === qty` + check `SerialAvailabilityService::findBlockedIds`. |
| `resources/js/Pages/Orders/Create.vue` | + computed `orderItemsSerialStatus`, `orderHasSerialMissing`; + `validateOrderSerialSelection()` alert chi tiết theo từng item; gọi trong `save()` và `saveAndPrint()` ngay sau check `items.length`; + cảnh báo cam dưới mỗi selector "Cần chọn đủ Serial/IMEI trước khi lưu đơn." |
| `tests/Feature/Orders/RequireSerialOnOrderSaveTest.php` | NEW — 5 test, 16 assertions. |

## 4. Frontend validation

```js
function validateOrderSerialSelection() {
    const invalid = orderItemsSerialStatus.value.filter((s) => s.has_serial && !s.ok);
    if (invalid.length === 0) return true;
    const message = invalid.map((i) => `• ${i.name}: đã chọn ${i.selected}/${i.qty} Serial/IMEI`).join('\n');
    alert('Vui lòng chọn đủ Serial/IMEI cho các sản phẩm sau trước khi lưu đơn:\n' + message);
    return false;
}
```

Gọi:
- `save()` — sau check items.length, trước `submitRef = true`.
- `saveAndPrint()` — tương tự.

UI (per-item):
- Hiển thị "Đã chọn X/Y" (xanh khi đủ, cam khi thiếu).
- Banner cam dưới selector khi thiếu: "⚠ Cần chọn đủ Serial/IMEI trước khi lưu đơn."
- Nút Lưu vẫn bấm được nhưng sẽ alert (không disable cứng để giữ flexibility).

## 5. Backend validation

### `validateItemsSerials($items)` (helper mới)
- Loop từng item, find `Product`.
- Nếu `product->has_serial`:
  - `count(serialIds) !== qty` → `back()->withErrors(['items' => "Sản phẩm '{$name}' là hàng Serial/IMEI. Vui lòng chọn đủ {$qty}…"])`.
  - `findBlockedIds()` → trả lỗi nếu có serial không khả dụng.
- Trả `null` khi tất cả OK.

### `store()`
1. `$validated = $request->validate(...)`.
2. Lock period check.
3. **`if ($preFlight = $this->validateItemsSerials($validated['items'])) return $preFlight;`** ← chặn TRƯỚC khi `Order::create`.
4. Tạo Order + items.

### `update()`
1. `$validated = ...`.
2. `if ($request->has('items')) { if ($preFlight = $this->validateItemsSerials(...)) return $preFlight; $order->items()->delete(); ... }` ← chặn TRƯỚC khi `delete()`.

### `processOrder()` — GIỮ NGUYÊN fail-safe
Order cũ trước Step 22.1C (chưa có cột `serial_ids` đầy) hoặc nhập manual vẫn được processOrder catch. Bức tường cuối cùng còn nguyên.

## 6. Tests

| Test | Kết quả |
|---|---|
| `test_order_store_serial_product_without_serial_ids_should_fail` | ✅ PASS |
| `test_order_store_serial_product_with_partial_serial_ids_should_fail` | ✅ PASS |
| `test_order_store_serial_product_with_full_serial_ids_should_succeed` | ✅ PASS |
| `test_order_update_serial_product_without_serial_ids_should_fail` | ✅ PASS — items cũ giữ nguyên (controller return back trước khi `delete()`) |
| `test_order_store_normal_product_without_serial_ids_should_succeed` | ✅ PASS |
| `test_order_process_normal_product_without_serial_ids_should_succeed` | ✅ PASS — stock giảm đúng, không tạo `InvoiceItemSerial` |

### Regression suite
```
php artisan test --env=testing --filter="RR13|SerialAvailability|RequireSerial|Order"
Tests: 2 skipped, 25 passed (96 assertions), 2.38s
```

Bao gồm:
- `RR13OrderConvertStockTest` — 5 tests pass (3 success cases + 2 schema-skip cho serial flow cũ): **processOrder fail-safe vẫn xanh**.
- `SerialAvailabilityServiceTest` — 5 pass, 2 skip (schema-tolerant).
- `RR06`, `RR08`, `RR10`, `RR11` — không bị ảnh hưởng.

### Build
```
npm run build  →  ✓ built in 6.05s
```

## 7. Manual QA cần user test

1. **Hàng has_serial qty=1, KHÔNG tick serial:**
   - Bấm Lưu → alert "Vui lòng chọn đủ Serial/IMEI cho các sản phẩm sau…"
   - DB: không có Order mới.
2. **Hàng has_serial qty=2 chỉ tick 1 serial:**
   - Bấm Lưu → alert "đã chọn 1/2 Serial/IMEI".
   - Banner cam hiển thị dưới selector.
3. **Hàng has_serial qty=2 tick đủ 2 serial:**
   - Lưu OK. Vào Orders/Index thấy serial labels.
   - Serial vẫn `in_stock` (chưa sold).
4. **Process Order serial → Invoice:**
   - Click Xử lý → Invoice tạo, serial chuyển `sold`, stock giảm đúng.
5. **Hàng thường (has_serial=false), không serial_ids:**
   - Lưu bình thường, không alert, không banner.
6. **Edit Order draft đang có serial → bỏ tick serial → Lưu:**
   - Backend chặn, items cũ KHÔNG bị xoá.

## 8. Kết luận
- ✅ Hàng thường (`has_serial=false`) **không** yêu cầu Serial/IMEI ở bất kỳ điểm nào:
  - Frontend: selector bị `v-if="item.has_serial"` gạt, `validateOrderSerialSelection()` chỉ filter `s.has_serial && !s.ok`.
  - Backend store/update: `validateItemsSerials()` skip `if (!$product->has_serial) continue;`.
  - processOrder: tạo Invoice + trừ stock theo qty, không tạo `InvoiceItemSerial` (test cover).
- ✅ Hàng serial (`has_serial=true`) bắt buộc chọn đủ (frontend + backend cùng chặn).
- ✅ Update Order với items thiếu serial KHÔNG làm mất items cũ (pre-flight chặn trước delete).
- ✅ processOrder fail-safe giữ nguyên — bức tường cuối cùng cho dữ liệu cũ.
