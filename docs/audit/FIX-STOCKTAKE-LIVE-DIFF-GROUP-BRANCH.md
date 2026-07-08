# YÊU CẦU AGENT SỬA NGHIỆP VỤ KIỂM KHO: LIVE CHÊNH LỆCH, CHỌN NHÓM HÀNG, CHI NHÁNH KIỂM

## 1. Bối cảnh

Module **Kiểm kho** hiện đang mô phỏng giao diện giống KiotViet nhưng còn thiếu và sai một số điểm nghiệp vụ quan trọng:

1. Nhập số lượng **Thực tế** trên màn hình tạo phiếu nhưng cột **SL lệch / Giá trị lệch** không cập nhật đúng theo số vừa nhập.
2. Chưa có chức năng **chọn hàng theo nhóm hàng** như KiotViet.
3. Trường **Chi nhánh kiểm** đang có trên UI nhưng chưa được lưu vào phiếu kiểm kho và chưa ảnh hưởng tới tồn kho.
4. Tab **Chưa kiểm** đang hard-code bằng `0`, chưa phản ánh đúng trạng thái kiểm thực tế.
5. Giá trị lệch đang có rủi ro tính theo giá vốn hiện tại thay vì giá vốn snapshot tại thời điểm kiểm.
6. Danh sách phiếu kiểm kho có filter chi nhánh nhưng chưa bind đủ UI/backend/database.

Mục tiêu của task này là sửa module kiểm kho để dùng được thực tế theo chuẩn phần mềm bán hàng như KiotViet/Sapo: kiểm theo chi nhánh, thêm hàng bằng tìm kiếm hoặc chọn nhóm hàng, nhập thực tế tự nhảy chênh lệch, lưu tạm không ảnh hưởng kho, hoàn thành mới cân bằng kho và ghi lịch sử phát sinh tồn.

---

## 2. Các file cần kiểm tra trước khi sửa

Agent cần đọc và rà soát các file sau:

```txt
app/Http/Controllers/StockTakeController.php
app/Http/Controllers/ProductController.php
app/Models/StockTake.php
app/Models/StockTakeItem.php
app/Services/StockMovementService.php
resources/js/Pages/StockTakes/Create.vue
resources/js/Pages/StockTakes/Index.vue
routes/web.php
database/migrations/2026_03_01_042323_create_stock_takes_table.php
database/migrations/2026_03_01_042324_create_stock_take_items_table.php
```

Nếu hệ thống đã có các migration bổ sung sau thời điểm tạo bảng ban đầu thì phải kiểm tra toàn bộ schema thực tế trước khi tạo migration mới, tránh thêm cột trùng.

---

## 3. Vấn đề hiện tại cần sửa

### 3.1. Lỗi không nhảy SL lệch / Giá trị lệch khi nhập Thực tế

Hiện tại `Create.vue` đang tính `itemsWithDiffs` bằng computed và trả về object copy:

```js
const itemsWithDiffs = computed(() => {
    return items.value.map(item => {
        const actual = parseInt(item.actual_stock) || 0;
        const system = parseInt(item.system_stock) || 0;
        const diffQty = actual - system;
        const costPrice = props.products.find(p => p.id === item.product_id)?.cost_price || 0;
        return {
            ...item,
            diff_qty: diffQty,
            diff_value: diffQty * costPrice
        };
    });
});
```

Nhưng template lại render bảng từ `itemsWithDiffs` và `v-model` trực tiếp vào `item.actual_stock`. Vì `item` lúc này là object clone, không phải object gốc trong `items`, nên khi user sửa số thực tế, diff có thể không cập nhật đúng.

Yêu cầu sửa:

- Không bind input vào object clone từ computed.
- Render bảng trực tiếp từ `items`.
- Tạo function tính realtime:

```js
const toNumber = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
const diffQty = (item) => toNumber(item.actual_stock) - toNumber(item.system_stock);
const diffValue = (item) => diffQty(item) * toNumber(item.cost_price_snapshot ?? item.cost_price);
```

- Input số thực tế phải dùng `v-model.number="item.actual_stock"` hoặc xử lý convert number rõ ràng.
- Tổng số lượng thực tế, tổng lệch, tổng lệch tăng/giảm, giá trị lệch phải cập nhật ngay khi user nhập.

---

## 4. Luồng nghiệp vụ chuẩn cần đạt

### 4.1. Tạo phiếu kiểm kho

1. User vào **Hàng hóa → Kiểm kho → + Kiểm kho**.
2. Hệ thống mở màn hình tạo phiếu.
3. User bắt buộc chọn **Chi nhánh kiểm** trước khi hoàn thành phiếu.
4. User thêm hàng hóa vào phiếu bằng một trong các cách:
   - Tìm theo mã hàng / tên hàng / barcode.
   - Chọn theo nhóm hàng.
   - Import Excel nếu hệ thống đã hỗ trợ hoặc sẽ hỗ trợ sau.
5. Với mỗi dòng hàng:
   - `Tồn kho` là tồn hệ thống snapshot tại thời điểm thêm hàng vào phiếu.
   - `Thực tế` là số lượng thực tế user đếm được.
   - `SL lệch = Thực tế - Tồn kho`.
   - `Giá trị lệch = SL lệch × giá vốn snapshot`.
6. User có thể **Lưu tạm** hoặc **Hoàn thành**.
7. Lưu tạm không thay đổi tồn kho.
8. Hoàn thành mới cập nhật tồn kho và ghi stock movement.

### 4.2. Chọn hàng theo nhóm hàng

Luồng giống KiotViet:

1. User bấm icon bộ lọc / chọn nhóm hàng trên thanh tìm kiếm của màn hình kiểm kho.
2. Hệ thống mở modal **Chọn nhóm hàng**.
3. Modal hiển thị cây nhóm hàng cha/con, có ô tìm kiếm nhóm hàng.
4. User tick một hoặc nhiều nhóm hàng.
5. Có các tùy chọn:
   - Bao gồm nhóm con.
   - Chỉ kiểm hàng còn tồn kho.
   - Chỉ kiểm hàng đang kinh doanh.
   - Chỉ kiểm hàng là hàng hóa có quản lý tồn kho, không lấy dịch vụ.
6. User bấm **Xong**.
7. Hệ thống gọi API lấy danh sách sản phẩm thuộc các nhóm đã chọn.
8. Add sản phẩm vào phiếu kiểm kho.
9. Nếu sản phẩm đã có trong phiếu thì không add trùng.
10. Nếu đổi chi nhánh sau khi đã có hàng, phải cảnh báo user vì tồn snapshot có thể thay đổi.

---

## 5. Yêu cầu sửa frontend

### 5.1. File cần sửa chính

```txt
resources/js/Pages/StockTakes/Create.vue
resources/js/Pages/StockTakes/Index.vue
```

### 5.2. Sửa live diff trong `Create.vue`

Yêu cầu:

- Bảng kiểm kho phải render từ `items`, không render từ object clone.
- Thêm field khi add sản phẩm:

```js
items.value.unshift({
    product_id: product.id,
    sku: product.sku,
    barcode: product.barcode,
    name: product.name,
    unit_name: product.unit_name || product.unit || 'Cái',
    category_id: product.category_id,
    system_stock: Number(product.system_stock ?? product.stock_quantity ?? 0),
    actual_stock: null,
    cost_price_snapshot: Number(product.cost_price_snapshot ?? product.cost_price ?? 0),
    checked: false,
    has_serial: Boolean(product.has_serial),
});
```

Lưu ý:

- Không nên mặc định `actual_stock = system_stock` cho hàng được add bằng nhóm hàng, vì như vậy toàn bộ sản phẩm sẽ bị coi là đã kiểm và khớp dù nhân viên chưa đếm thực tế.
- Nếu add bằng tìm kiếm từng sản phẩm, có thể cho option tự fill bằng tồn kho nhưng vẫn nên có trạng thái `checked` rõ ràng.

### 5.3. Cách xác định đã kiểm

- Khi user nhập vào ô `Thực tế`, set `item.checked = true`.
- Có thể thêm nút nhỏ **Khớp** trên từng dòng để set:

```js
item.actual_stock = item.system_stock;
item.checked = true;
```

- Có thể thêm nút **Đánh dấu tất cả là khớp** nhưng phải có confirm.

### 5.4. Tab trạng thái trên màn hình tạo phiếu

Thay logic hiện tại:

```txt
Chưa kiểm (0)
```

bằng logic thật:

```js
const checkedItems = computed(() => items.value.filter(i => i.checked));
const uncheckedItems = computed(() => items.value.filter(i => !i.checked));
const matchedItems = computed(() => items.value.filter(i => i.checked && diffQty(i) === 0));
const diffItems = computed(() => items.value.filter(i => i.checked && diffQty(i) !== 0));
```

Hiển thị:

```txt
Tất cả ({items.length})
Khớp ({matchedItems.length})
Lệch ({diffItems.length})
Chưa kiểm ({uncheckedItems.length})
```

Nếu đã có UI lọc theo tab thì khi bấm từng tab phải lọc danh sách tương ứng.

### 5.5. Thêm modal chọn nhóm hàng

Tạo component hoặc viết trực tiếp trong `Create.vue`:

```txt
resources/js/Components/StockTakes/CategorySelectModal.vue
```

Yêu cầu UI modal:

- Tiêu đề: `Chọn nhóm hàng`.
- Ô tìm kiếm nhóm hàng.
- Tree checkbox nhóm hàng cha/con.
- Checkbox:
  - `Tất cả nhóm hàng`.
  - `Bao gồm nhóm con`.
  - `Chỉ kiểm hàng còn tồn kho`.
  - `Chỉ kiểm hàng đang kinh doanh`.
  - `Chỉ kiểm hàng quản lý tồn kho`.
- Nút:
  - `Bỏ qua`.
  - `Xong`.

Sau khi bấm `Xong`, gọi API lấy sản phẩm rồi add vào phiếu.

### 5.6. Chi nhánh kiểm trên frontend

- `selectedBranch` phải được gửi lên backend khi lưu:

```js
await router.post('/stock-takes', {
    code: props.stockTakeCode,
    status,
    branch_id: selectedBranch.value,
    action_date: transactionDate.value,
    note: note.value,
    items: payloadItems.value,
});
```

- Nếu chưa chọn chi nhánh mà bấm `Hoàn thành`, báo lỗi:

```txt
Vui lòng chọn chi nhánh kiểm kho trước khi hoàn thành phiếu.
```

- Nếu chưa chọn chi nhánh mà vẫn cho lưu tạm thì cần lưu `branch_id = null` hoặc bắt buộc chọn tùy theo chính sách. Đề xuất: bắt buộc chọn chi nhánh cả khi lưu tạm để tránh phiếu kiểm không xác định kho.

### 5.7. Payload gửi lên backend

Frontend chỉ gửi dữ liệu cần thiết, backend tự tính lại diff:

```js
const payloadItems = computed(() => items.value.map(item => ({
    product_id: item.product_id,
    actual_stock: item.actual_stock,
    checked: item.checked,
})));
```

Không tin `system_stock`, `diff_qty`, `diff_value` từ frontend khi lưu chính thức.

---

## 6. Yêu cầu sửa backend

### 6.1. Migration bổ sung

Tạo migration mới, không sửa migration cũ nếu dự án đã chạy.

Cần kiểm tra schema trước. Nếu thiếu thì thêm các cột sau.

Bảng `stock_takes` cần có:

```txt
branch_id nullable hoặc required theo thiết kế
created_by nullable
balanced_by nullable
cancelled_by nullable
cancelled_at nullable
cancel_reason nullable
```

Bảng `stock_take_items` cần có hoặc chuẩn hóa:

```txt
system_stock_snapshot integer default 0
actual_stock integer nullable
checked boolean default false
cost_price_snapshot decimal(15,2) default 0
unit_name nullable
category_id nullable
```

Nếu đang dùng cột cũ:

```txt
system_stock
actual_stock
diff_qty
diff_value
```

thì có thể giữ để tránh ảnh hưởng code cũ, nhưng phải thống nhất ý nghĩa:

- `system_stock` = tồn hệ thống snapshot tại thời điểm kiểm.
- `actual_stock` = tồn thực tế user nhập.
- `diff_qty` = actual_stock - system_stock.
- `diff_value` = diff_qty × cost_price_snapshot.

### 6.2. Model

Sửa `app/Models/StockTake.php`:

- Thêm fillable:

```php
'branch_id',
'created_by',
'balanced_by',
'cancelled_by',
'cancelled_at',
'cancel_reason',
```

- Thêm casts:

```php
'balanced_date' => 'datetime',
'cancelled_at' => 'datetime',
```

- Thêm relation:

```php
public function branch()
{
    return $this->belongsTo(Branch::class);
}
```

Sửa `app/Models/StockTakeItem.php`:

- Thêm fillable nếu có cột mới:

```php
'checked',
'cost_price_snapshot',
'unit_name',
'category_id',
```

### 6.3. API lấy sản phẩm cho kiểm kho

Tạo method mới trong `StockTakeController` hoặc service riêng:

```txt
GET /api/stock-takes/products
```

Params:

```txt
search
category_ids[]
include_children=1/0
branch_id
active_only=1/0
inventory_only=1/0
only_in_stock=1/0
limit
```

Response:

```json
[
  {
    "product_id": 1,
    "sku": "SP001",
    "barcode": "893...",
    "name": "Tên hàng",
    "unit_name": "Cái",
    "category_id": 10,
    "system_stock": 15,
    "cost_price_snapshot": 120000,
    "has_serial": false
  }
]
```

Logic lọc nhóm hàng:

- Nếu truyền `category_ids[]`, lấy sản phẩm thuộc các nhóm đó.
- Nếu `include_children = 1`, lấy cả nhóm con nhiều cấp.
- Có thể tái sử dụng logic đang có ở `ProductController@index()` phần lọc `category_id`, nhưng cần hỗ trợ nhiều category và nhiều cấp con.

### 6.4. API search sản phẩm hiện tại

Hiện `ProductController::apiSearch()` đang hỗ trợ:

```txt
active_only
inventory_only
search
product_ids
price_book_id
```

Cần bổ sung nếu tiếp tục dùng endpoint này cho kiểm kho:

```txt
category_id hoặc category_ids[]
include_children
branch_id
only_in_stock
```

Tuy nhiên đề xuất tốt hơn là tạo endpoint riêng cho kiểm kho để response có đúng `system_stock` và `cost_price_snapshot`.

### 6.5. Store phiếu kiểm kho

Sửa `StockTakeController::store()`:

Validate:

```php
$request->validate([
    'branch_id' => 'required|exists:branches,id',
    'items' => 'required|array|min:1',
    'items.*.product_id' => 'required|exists:products,id',
    'items.*.actual_stock' => 'nullable|numeric|min:0',
    'items.*.checked' => 'boolean',
    'status' => 'required|in:draft,balanced',
    'note' => 'nullable|string',
]);
```

Business rule:

- Nếu `status = balanced`, không cho hoàn thành khi còn item chưa kiểm hoặc `actual_stock = null`.
- Chặn duplicate product trong cùng phiếu.
- Không cho kiểm dịch vụ không quản lý tồn kho.
- Backend tự lấy tồn kho hiện tại và giá vốn snapshot từ DB.
- Backend tự tính:

```php
$diffQty = $actualStock - $systemStock;
$diffValue = $diffQty * $costPriceSnapshot;
```

Lưu `branch_id` vào `stock_takes`.

### 6.6. Update phiếu tạm

Sửa `StockTakeController::update()`:

Hiện update đang lấy `system_stock` và `diff_value` từ request, không an toàn.

Yêu cầu:

- Chỉ cho update khi `status = draft`.
- Không tin `system_stock`, `diff_qty`, `diff_value` từ frontend.
- Recompute toàn bộ từ DB như `store()`.
- Nếu đã có `system_stock` snapshot trong phiếu cũ, cần quyết định:
  - Giữ snapshot cũ khi sửa số thực tế.
  - Chỉ refresh snapshot khi user bấm chức năng “Cập nhật lại tồn hệ thống”.

Đề xuất nghiệp vụ:

- Khi sửa phiếu tạm, giữ `system_stock` snapshot đã ghi lúc thêm hàng.
- Nếu thêm sản phẩm mới vào phiếu, lấy snapshot tại thời điểm thêm.

### 6.7. Balance phiếu kiểm kho

Sửa `StockTakeController::balance()`:

- Chỉ cho cân bằng phiếu `draft`.
- Không tự âm thầm đổi `system_stock` sang tồn kho hiện tại nếu tồn đã thay đổi sau lúc tạo phiếu.
- Nếu tồn hiện tại khác snapshot, trả cảnh báo rõ:

```txt
Tồn hệ thống của một số hàng đã thay đổi từ lúc tạo phiếu. Vui lòng tải lại tồn hệ thống hoặc xác nhận dùng snapshot cũ trước khi cân bằng.
```

Có thể triển khai bước 1 đơn giản:

- Dùng snapshot đã lưu trên phiếu để tính diff.
- Cập nhật tồn bằng movement chênh lệch.
- Sau khi update phải đảm bảo tồn cuối = actual_stock.

Với hàng serial/IMEI:

- Nếu `diff_qty != 0`, chưa được tự cân bằng nếu không có danh sách serial cụ thể.
- Trả lỗi rõ cho user.

### 6.8. Stock movement

Khi cân bằng kho:

- Nếu lệch tăng: tạo movement `adjust_in`.
- Nếu lệch giảm: tạo movement `adjust_out`.
- Phải truyền `branch_id` vào `StockMovementService::record()` qua opts:

```php
StockMovementService::record(
    $product,
    $diff > 0 ? StockMovementService::TYPE_ADJUST_IN : StockMovementService::TYPE_ADJUST_OUT,
    abs($diff),
    $costPerUnit,
    $stockTake,
    [
        'branch_id' => $stockTake->branch_id,
        'note' => 'Cân bằng kiểm kho',
        'moved_at' => $stockTake->balanced_date ?? now(),
    ]
);
```

### 6.9. Hủy phiếu kiểm kho

Khi hủy phiếu đã cân bằng:

- Phải đảo lại movement đã ghi.
- Cập nhật trạng thái `cancelled`.
- Lưu:

```txt
cancelled_by
cancelled_at
cancel_reason
```

Nếu phiếu mới là `draft`:

- Chỉ chuyển trạng thái `cancelled`, không ảnh hưởng tồn kho.

---

## 7. Yêu cầu sửa danh sách phiếu kiểm kho `Index.vue`

### 7.1. Filter chi nhánh

Dropdown chi nhánh hiện đang hiển thị nhưng cần bind vào filter:

```vue
<select v-model="filters.branch_id">
```

Khi đổi filter phải reload danh sách theo `branch_id` thông qua composable filter hiện có.

### 7.2. Hiển thị tổng

Danh sách phiếu nên ưu tiên hiển thị số liệu đã lưu trên phiếu:

```txt
total_actual_qty
total_diff_qty
total_diff_increase
total_diff_decrease
total_diff_value
```

Không nên tính lại giá trị lệch theo `product.cost_price` hiện tại, vì giá vốn có thể đã thay đổi sau thời điểm kiểm.

### 7.3. Chi tiết phiếu

Trong expanded row:

- Cột `Tồn kho` hiển thị `item.system_stock` hoặc `item.system_stock_snapshot`.
- Cột `Thực tế` hiển thị `item.actual_stock`.
- Cột `SL lệch` hiển thị `item.diff_qty` đã lưu.
- Cột `Giá trị lệch` hiển thị `item.diff_value` đã lưu.
- Không tự tính lại bằng giá vốn hiện tại.

---

## 8. Business rules bắt buộc

1. Một phiếu kiểm kho bắt buộc thuộc một chi nhánh/kho.
2. Một sản phẩm không được xuất hiện trùng trong cùng phiếu.
3. Dịch vụ không quản lý tồn kho không được đưa vào phiếu kiểm kho.
4. `SL lệch = Thực tế - Tồn hệ thống snapshot`.
5. `Giá trị lệch = SL lệch × giá vốn snapshot`.
6. Lưu tạm không làm thay đổi tồn kho.
7. Hoàn thành mới được cập nhật tồn kho.
8. Mọi cập nhật tồn kho do kiểm kho phải có stock movement.
9. Không cho hoàn thành nếu còn sản phẩm chưa kiểm.
10. Không cho hoàn thành nếu có dòng `actual_stock = null`.
11. Hàng serial/IMEI không được tự cân bằng lệch nếu không khai báo serial cụ thể.
12. Hủy phiếu đã cân bằng phải đảo tồn kho bằng movement ngược, không sửa trực tiếp không lịch sử.
13. Mọi thao tác tạo, sửa, hoàn thành, hủy phải ghi log người thao tác và thời gian.
14. Giá vốn hiển thị trong chi tiết phiếu kiểm kho phải là giá vốn snapshot tại thời điểm kiểm, không lấy giá vốn hiện tại.
15. Nếu tồn kho hệ thống thay đổi sau khi tạo phiếu tạm, khi bấm hoàn thành phải có cơ chế cảnh báo hoặc xác nhận rõ ràng.

---

## 9. Checklist test nghiệm thu

### 9.1. Test live diff

- Thêm 1 sản phẩm tồn kho 15.
- Nhập thực tế 10.
- Kỳ vọng:
  - `SL lệch = -5`.
  - `Giá trị lệch = -5 × giá vốn snapshot`.
  - Tab `Lệch` tăng 1.
- Sửa thực tế lại 15.
- Kỳ vọng:
  - `SL lệch = 0`.
  - Sản phẩm chuyển sang tab `Khớp`.
- Sửa thực tế 20.
- Kỳ vọng:
  - `SL lệch = 5`.
  - `Giá trị lệch` dương.

### 9.2. Test chưa kiểm

- Chọn một nhóm hàng có 10 sản phẩm.
- Chưa nhập thực tế dòng nào.
- Kỳ vọng:
  - `Tất cả = 10`.
  - `Chưa kiểm = 10`.
  - `Khớp = 0`.
  - `Lệch = 0`.
- Nhập thực tế cho 2 dòng.
- Kỳ vọng:
  - `Chưa kiểm = 8`.
  - Hai dòng đã nhập được phân loại đúng Khớp hoặc Lệch.

### 9.3. Test chọn nhóm hàng

- Mở modal chọn nhóm hàng.
- Tick 1 nhóm cha, bật `Bao gồm nhóm con`.
- Bấm `Xong`.
- Kỳ vọng:
  - Add đủ sản phẩm thuộc nhóm cha và nhóm con.
  - Không add dịch vụ.
  - Không add sản phẩm ngừng kinh doanh nếu bật `Chỉ hàng đang kinh doanh`.
  - Không add trùng nếu gọi lại lần nữa.

### 9.4. Test chi nhánh

- Chọn chi nhánh A, add sản phẩm X.
- Kỳ vọng tồn kho snapshot lấy theo chi nhánh A nếu hệ thống đã quản lý tồn theo chi nhánh.
- Đổi sang chi nhánh B khi đã có hàng.
- Kỳ vọng có cảnh báo trước khi đổi.
- Lưu phiếu.
- Kỳ vọng database `stock_takes.branch_id` lưu đúng.
- Danh sách phiếu filter theo chi nhánh hiển thị đúng.

### 9.5. Test lưu tạm

- Tạo phiếu có lệch.
- Bấm `Lưu tạm`.
- Kỳ vọng:
  - Phiếu status `draft`.
  - Tồn kho sản phẩm chưa thay đổi.
  - Không phát sinh movement điều chỉnh kho.

### 9.6. Test hoàn thành

- Tạo phiếu kiểm sản phẩm tồn 15, thực tế 10.
- Bấm `Hoàn thành`.
- Kỳ vọng:
  - Phiếu status `balanced`.
  - Tồn kho sản phẩm còn 10.
  - Có stock movement `adjust_out` qty 5.
  - Movement có ref tới phiếu kiểm kho.
  - Movement có branch_id.

### 9.7. Test hủy phiếu đã cân bằng

- Hủy phiếu đã hoàn thành ở test trên.
- Kỳ vọng:
  - Phiếu status `cancelled`.
  - Tồn kho được đảo lại đúng.
  - Có movement ngược.
  - Có log hủy, người hủy, thời gian hủy.

### 9.8. Test hàng serial/IMEI

- Add sản phẩm có quản lý serial/IMEI.
- Nhập thực tế khác tồn hệ thống.
- Bấm hoàn thành.
- Kỳ vọng hệ thống chặn và báo:

```txt
Sản phẩm có quản lý Serial/IMEI — chưa hỗ trợ cân bằng chênh lệch nếu không khai báo serial cụ thể.
```

### 9.9. Test giá vốn snapshot

- Tạo phiếu kiểm sản phẩm giá vốn 100.000, thực tế lệch -2.
- Hoàn thành phiếu.
- Sau đó sửa giá vốn sản phẩm thành 150.000.
- Mở lại chi tiết phiếu.
- Kỳ vọng:
  - Giá trị lệch vẫn là `-200.000`.
  - Không bị đổi thành `-300.000`.

---

## 10. Kết quả mong đợi sau khi sửa

Sau khi hoàn thành task này:

1. Nhập số thực tế trên màn hình kiểm kho phải nhảy ngay số lượng lệch và giá trị lệch.
2. Có thể chọn hàng hóa theo nhóm hàng giống KiotViet.
3. Có phân biệt `Tất cả / Khớp / Lệch / Chưa kiểm` đúng nghiệp vụ.
4. Phiếu kiểm kho lưu đúng chi nhánh kiểm.
5. Lưu tạm không ảnh hưởng tồn kho.
6. Hoàn thành mới cân bằng tồn kho.
7. Hủy phiếu đã cân bằng có rollback tồn kho bằng movement ngược.
8. Chi tiết phiếu hiển thị dữ liệu snapshot, không bị thay đổi theo giá vốn hiện tại.
9. Danh sách phiếu kiểm kho lọc được theo chi nhánh.
10. Hệ thống có log và chứng từ cho mọi thay đổi tồn kho.

---

## 11. Ghi chú triển khai

- Không được sửa tồn kho trực tiếp mà không ghi stock movement.
- Không được tin dữ liệu `diff_qty`, `diff_value`, `system_stock` từ frontend khi lưu/chốt phiếu.
- Nếu hệ thống hiện tại chưa có tồn kho theo chi nhánh ở bảng riêng, vẫn phải lưu `branch_id` trên phiếu kiểm kho để không mất nghiệp vụ và chuẩn bị cho bước nâng cấp tồn kho theo chi nhánh.
- Nếu chưa triển khai được tồn theo chi nhánh ngay, cần ghi rõ trong comment/code TODO: `Current system uses global product.stock_quantity; branch_id is stored for document ownership but stock calculation is still global until branch stock ledger is implemented.`
- Ưu tiên sửa lỗi live diff trước, sau đó làm chọn nhóm hàng, sau đó hoàn thiện branch_id và snapshot.
