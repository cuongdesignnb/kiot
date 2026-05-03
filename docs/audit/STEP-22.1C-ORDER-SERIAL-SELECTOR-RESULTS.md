# Step 22.1C — Order Serial Selector (UI + Schema + Validation)

## 1. Mục tiêu

Bổ sung khả năng **chọn Serial/IMEI ngay khi tạo/sửa Order** cho sản phẩm `has_serial`, để khi `processOrder` chuyển Order → Invoice, hệ thống đánh dấu đúng những Serial đã chọn sang `sold` thay vì rơi vào nhánh fail-safe của RR-13.

Ràng buộc:

- Không tự ý chọn đại Serial (FIFO/random) — phải để user chọn rõ ràng trên UI.
- Không sửa core service (`MovingAvgCostingService`, `StockMovementService`, `InvoiceSaleService`).
- Không phá audit backend hiện tại (RR-02/06/08/09/13).
- Tuyệt đối không commit/push trong bước này.

## 2. Discovery — trạng thái codebase trước khi sửa

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| `order_items.serial_ids` | ❌ chưa có cột | Cần migration mới |
| `OrderItem` model | `protected $guarded = ['id']` duy nhất | Thiếu cast cho `serial_ids` |
| `OrderController@store` | Lưu items không có `serial_ids` | Phải bổ sung validation + lưu |
| `OrderController@update` | Tương tự `store` | Phải bổ sung validation + lưu |
| `OrderController@processOrder` | Đã đọc `$orderItem->serial_ids` (RR-13 fail-safe) | Reuse, không sửa logic chính. Có 1 lỗi format thông báo `({$serialIds[0]})` đã chỉnh thành `count()`. |
| Route `/api/products/{product}/serials` | ✅ đã tồn tại — `PosController@getProductSerials` | Reuse cho selector, không tạo route mới |
| `ProductController@apiSearch` | Đã trả `has_serial` qua `$product->toArray()` | Không sửa |
| `Orders/Create.vue` `selectProduct()` | Tạo item phẳng không có serial fields | Phải mở rộng |
| `Orders/Index.vue` | Render danh sách item trong Order | Bổ sung hiển thị Serial/IMEI đã chọn |
| `RR13OrderConvertStockTest` | 4 test (TC-01..04) — TC-04 đã khẳng định fail-safe khi không có `serial_ids` | Bổ sung TC-05 happy path |

## 3. Files đã sửa / thêm

| # | File | Loại | Mục đích |
| --- | --- | --- | --- |
| 1 | `database/migrations/2026_05_03_120000_add_serial_ids_to_order_items_table.php` | NEW | Thêm `order_items.serial_ids` JSON nullable |
| 2 | `app/Models/OrderItem.php` | EDIT | Cast `serial_ids => array` |
| 3 | `app/Http/Controllers/OrderController.php` | EDIT | (a) Validation `items.*.serial_ids.*`, (b) lưu vào `serial_ids`, (c) validate count == qty + status `in_stock` ở `store/update`, (d) fix format thông báo trong `processOrder`, (e) read-only enrich `selected_serials` cho `index` |
| 4 | `resources/js/Pages/Orders/Create.vue` | EDIT | (a) `selectProduct` mở rộng item có `has_serial/serial_ids/available_serials/serialLoading/serialError`, (b) hàm `loadAvailableSerials()` gọi `/api/products/{id}/serials`, (c) `toggleSerial(item, id)` chặn vượt qty, (d) UI checkbox Serial trong cell tên sản phẩm với indicator "Đã chọn x/y", (e) `itemsComputed` cắt `serial_ids` khi user giảm qty |
| 5 | `resources/js/Pages/Orders/Index.vue` | EDIT | Hiển thị badge Serial/IMEI đã chọn dưới tên sản phẩm trong panel chi tiết Order |
| 6 | `database/migrations/2026_03_11_100001_create_roles_table.php` | EDIT (sửa lỗi pre-existing) | Bỏ `default('[]')` trên cột JSON — MySQL 8 không cho default literal trên JSON. Đây là blocker khiến `migrate:fresh --env=testing` fail; không liên quan tới logic test. |
| 7 | `tests/Feature/Orders/RR13OrderConvertStockTest.php` | EDIT (thêm TC-05) | Test happy path: lưu `serial_ids = [serialA->id]` vào `OrderItem`, gọi `processOrder`, assert serial A → `sold` + `invoice_id`, serial B vẫn `in_stock` (không bị chọn đại), `InvoiceItemSerial` đúng `invoice_item_id`, `StockMovement out_invoice` được ghi, order `completed`, stock giảm 1. |

## 4. Migration

```php
Schema::table('order_items', function (Blueprint $table) {
    if (!Schema::hasColumn('order_items', 'serial_ids')) {
        $table->json('serial_ids')->nullable()->after('subtotal');
    }
});
```

Có guard `hasColumn` ở cả `up()` và `down()`. Pattern đồng nhất với
`return_items.serial_ids` (RR-08) và `damage_items.serial_ids` (RR-09).

## 5. Backend — `OrderController`

### 5.1 Validation (store + update)

```php
'items.*.serial_ids'   => 'nullable|array',
'items.*.serial_ids.*' => 'integer|exists:serial_imeis,id',
```

### 5.2 Save loop (store + update)

```php
$serialIds = array_values(array_filter($item['serial_ids'] ?? [], fn($v) => $v !== null && $v !== ''));
if (!empty($serialIds)) {
    $product = Product::find($item['product_id']);
    if ($product && $product->has_serial) {
        // 1. count == qty
        // 2. cùng product_id, status = in_stock
        if (count($serialIds) !== (int) $item['qty'])  return back()->withErrors([...]);
        $valid = SerialImei::whereIn('id', $serialIds)
            ->where('product_id', $product->id)
            ->where('status', 'in_stock')
            ->count();
        if ($valid !== count($serialIds))              return back()->withErrors([...]);
    } else {
        $serialIds = []; // product không has_serial — drop ngay
    }
}
$order->items()->create([
    ...,
    'serial_ids' => !empty($serialIds) ? $serialIds : null,
]);
```

### 5.3 `index` — read-only enrich

Sau `paginate()`, gom toàn bộ `serial_ids` của các item, query 1 lần `SerialImei::whereIn('id', ...)`,
gán `$item->setAttribute('selected_serials', [{id, serial_number}, ...])`. Frontend dùng để hiển thị badge.

### 5.4 `processOrder` — KHÔNG sửa logic

Logic mark sold + tạo `InvoiceItemSerial` + `StockMovement` đã đúng từ RR-13.
Chỉ sửa **đúng 1 dòng format** thông báo lỗi: `({$serialIds[0]})` → `(${count($serialIds)})`.

## 6. Frontend — `Orders/Create.vue`

### 6.1 selectProduct — mở rộng item

```js
const newItem = {
    product_id, sku, name, qty: 1, price, discount: 0, stock_quantity,
    has_serial: !!product.has_serial,
    serial_ids: [],
    available_serials: [],
    serialLoading: false,
    serialError: '',
};
if (newItem.has_serial) loadAvailableSerials(newItem);
```

### 6.2 loadAvailableSerials

```js
const loadAvailableSerials = async (item) => {
    item.serialLoading = true;
    try {
        const { data } = await axios.get(`/api/products/${item.product_id}/serials`);
        item.available_serials = Array.isArray(data) ? data : [];
    } catch { item.serialError = 'Không tải được danh sách Serial/IMEI'; }
    finally { item.serialLoading = false; }
};
```

Reuse endpoint `/api/products/{product}/serials` của `PosController@getProductSerials`
— đã filter `status=in_stock` + `repair_status` hợp lệ.

### 6.3 toggleSerial — chặn vượt qty

```js
const toggleSerial = (item, serialId) => {
    const ids = [...(item.serial_ids || [])];
    const idx = ids.indexOf(serialId);
    const qty = parseInt(item.qty) || 0;
    if (idx >= 0) ids.splice(idx, 1);
    else {
        if (ids.length >= qty) { alert(...); return; }
        ids.push(serialId);
    }
    item.serial_ids = ids;
};
```

Lưu ý: gọi `toggleSerial(activeTab.items[index], s.id)` — phải dùng item gốc trong
`activeTab.items` (không phải bản copy spread của `itemsComputed`) để mutation reactive.

### 6.4 UI block — trong `<td>` tên sản phẩm

- Tiêu đề "Serial/IMEI" + indicator `Đã chọn x/y` (xanh khi khớp, cam khi lệch)
- Loading state
- Error state
- Empty state ("Không có Serial/IMEI in_stock")
- Danh sách checkbox (custom rendered as label) — tối đa cao 24, scroll

### 6.5 itemsComputed — auto trim

Khi user giảm `qty` xuống dưới `serial_ids.length` thì cắt: `item.serial_ids.slice(0, qty)`.
Tránh gửi nhiều serial hơn qty.

## 7. Frontend — `Orders/Index.vue`

Trong row item của panel chi tiết Order:

```html
<div v-if="item.selected_serials?.length" class="mt-1 flex flex-wrap gap-1">
    <span class="text-gray-500 text-xs mr-1">Serial/IMEI đã chọn:</span>
    <span v-for="s in item.selected_serials" :key="s.id" class="...badge...">
        {{ s.serial_number || ('#' + s.id) }}
    </span>
</div>
```

Read-only — phục vụ kiểm tra trước/sau khi process.

## 8. Test mới — TC-RR13-05

```php
public function test_order_convert_serial_with_serial_ids_should_mark_selected_serial_as_sold(): void
{
    // Setup: product has_serial, 2 serial in_stock (A, B)
    // Tạo Order qty=1, lưu serial_ids = [serialA->id]
    // callProcessOrder
    // Assert:
    //   - Invoice tạo
    //   - serialA: status=sold, invoice_id=invoice->id, sold_cost_price NOT NULL
    //   - serialB: status=in_stock, invoice_id=null (KHÔNG được chọn đại)
    //   - InvoiceItemSerial(serial_imei_id=A) tồn tại với invoice_item_id đúng
    //   - order.status=completed, product.stock=1
    //   - StockMovement out_invoice được tạo
}
```

## 9. Kết quả test

```
Tests\Feature\Orders\RR13OrderConvertStockTest
  ✓ order convert should decrease stock and inventory total cost   0.26s
  ✓ order convert should create stock movement                     0.07s
  ✓ order convert should not allow quantity greater than stock     0.07s
  ✓ order convert serial without serial ids should fail safely     0.08s
  ✓ order convert serial with serial ids should mark selected ...  0.11s   ← NEW
```

Chạy mở rộng `RR02|RR06|RR08|RR09|RR13`:

```
Tests:    24 passed (121 assertions)
Duration: 2.53s
```

Không có regression. Audit backend vẫn xanh.

## 10. Build kiểm chứng

```
npm run build
✓ built in 7.17s
public/build/assets/Create-CzfZ81MX.js  38.77 kB │ gzip: 10.96 kB  (Orders/Create.vue)
public/build/assets/Index-CMJ6vWu-.js   61.52 kB │ gzip: 15.05 kB  (Orders/Index.vue)
```

Không lỗi compile/lint. Không cảnh báo Vue.

## 11. Manual checklist (đề nghị QA chạy)

1. Vào màn Đặt hàng (`Orders/Create`) — tìm sản phẩm `has_serial`, chọn vào giỏ.
   - [ ] Xuất hiện block "Serial/IMEI" dưới tên sản phẩm
   - [ ] Indicator hiển thị `Đã chọn 0/1` màu cam
   - [ ] Danh sách Serial in_stock hiển thị
2. Tick 1 Serial → indicator `1/1` màu xanh, badge xanh đậm.
3. Tick Serial thứ 2 → alert "Đã chọn đủ 1 Serial/IMEI…", không tick được.
4. Tăng qty lên 2 → tick được Serial thứ 2.
5. Giảm qty về 1 → `serial_ids` tự cắt còn 1 phần tử (số đầu tiên).
6. Lưu Order — không có lỗi validation, Order lưu thành công.
7. Mở `Orders/Index`, expand Order vừa tạo → thấy badge "Serial/IMEI đã chọn: SN-xxx".
8. Bấm "Process" Order → Invoice tạo, Serial A `sold`, stock giảm 1 (kiểm tra trong Tồn kho).
9. Edge: tạo Order has_serial NHƯNG bỏ trống Serial → "Process" sẽ fail an toàn (RR-13 TC-04, đã có sẵn).
10. Edge: tick 2 Serial cho qty=2 nhưng 1 Serial bị bán giữa chừng (đổi status thủ công ở DB) → store sẽ chặn nếu validate lại; processOrder sẽ throw exception nếu trượt qua.

## 12. Rủi ro / lưu ý

- **Race condition**: User chọn Serial X lúc 10:00, tới 10:05 X bị bán ở POS. ProcessOrder sẽ throw exception khi validate `status != in_stock` ở `OrderController@processOrder` (logic RR-13 đã có). UI sẽ hiển thị flash error theo Step 22.1B.
- **Order đã tạo trước đó (không có serial_ids)**: Vẫn hợp lệ vì cột nullable. Khi process sẽ rơi vào nhánh fail-safe (RR-13 TC-04). User cần edit Order, chọn lại Serial rồi process.
- **Route reuse**: `/api/products/{product}/serials` được dùng chung với POS. Nếu sau này POS thay đổi format response, Order selector sẽ ảnh hưởng. Đã bao bọc bằng `Array.isArray(data)` để fail mềm.
- **Migration roles** (`2026_03_11_100001_create_roles_table.php`): đã sửa `->default('[]')` thành `->nullable()` cho cột JSON `permissions` để chạy được trên MySQL 8 strict mode. Không thay đổi semantics: seed mặc định vẫn set `json_encode([...])`. Nếu code có chỗ giả định non-null thì cast `'permissions' => 'array'` trong model `Role` sẽ trả về `null` thay vì `[]` — cần kiểm tra. (Pre-existing bug, không thuộc 22.1C nhưng phải xử lý để chạy được suite test.)

## 13. Kết luận

22.1C đã hoàn tất:

- Schema: `order_items.serial_ids JSON NULL`.
- Backend: validation 2-tầng (count + status), lưu thẳng vào cột mới, không sửa core service.
- Frontend: UI selector chuẩn, indicator rõ ràng, chống vượt qty, auto trim khi giảm qty, hiển thị badge ở Index.
- Test: thêm 1 happy path TC-RR13-05; toàn bộ 24 test audit (RR02/06/08/09/13) pass.
- Build: 7.17s OK.

Đề xuất tiếp theo: gộp 22.1A/B/C thành 1 commit duy nhất sau khi user QA tay xong.
Tuân thủ ràng buộc — **chưa commit, chưa push.**
