# CODEX TASK — Chuẩn hóa tìm kiếm hàng hóa trong Nhập hàng theo POS

> **Mã công việc:** PURCHASE-SEARCH-01  
> **Phạm vi:** chỉ luồng tìm hàng hóa tại Nhập hàng (tạo mới và sửa phiếu).  
> **Mức rủi ro dữ liệu:** thấp — chỉ sửa luồng tìm kiếm read-only.  
> **Không được làm:** migration, thay đổi tồn kho, giá vốn, serial status, công nợ, cashflow, hoặc thay đổi logic lưu phiếu nhập.

---

## 1. Vấn đề cần sửa

Hiện tại cơ chế tìm sản phẩm trong Nhập hàng chưa đồng nhất với POS:

- `resources/js/Pages/Purchases/Create.vue` nhận toàn bộ danh sách hàng hóa active qua props, sau đó lọc **local** bằng `name` hoặc `sku`, chỉ lấy 10 kết quả.
- Vì vậy khi nhập **barcode**, **serial/IMEI**, hoặc chuỗi nhiều từ rời rạc như `man 13.3 dom`, kết quả ở Tạo phiếu nhập có thể không ra hoặc kém chính xác hơn POS.
- `resources/js/Pages/Purchases/Edit.vue` đã gọi `/api/products/search`, nhưng chưa truyền điều kiện chỉ chọn hàng đang kinh doanh; do đó Tạo và Sửa phiếu nhập đang dùng hai hành vi khác nhau.
- POS đã dùng `ProductSearchService`, hỗ trợ tên, SKU, barcode, serial/IMEI, chuẩn hóa ký tự phân tách, AND giữa các token và ưu tiên khớp chính xác/prefix.

### Cách tái hiện

1. Tạo sản phẩm active có barcode `BC-ABC-001`, SKU/tên không chứa `ABC`.
2. Vào `/purchases/create`, nhập `BC-ABC-001` vào ô tìm hàng.
3. **Hiện tại:** không tìm thấy do Create chỉ lọc name/SKU tại trình duyệt.
4. Vào POS, nhập cùng giá trị.
5. **Kỳ vọng tham chiếu:** POS tìm thấy sản phẩm.

---

## 2. Mục tiêu bắt buộc

Chuẩn hóa tìm hàng hóa của **Nhập hàng > Tạo phiếu** và **Nhập hàng > Sửa phiếu** để có cùng năng lực nhận diện với POS:

1. Tìm theo **tên**, **SKU/mã hàng**, **barcode**, và **serial/IMEI đã tồn tại**.
2. Hỗ trợ truy vấn nhiều token rời rạc; mọi token phải khớp, mỗi token có thể khớp ở bất kỳ trường nào như POS.
3. Giữ thứ tự ưu tiên của `ProductSearchService`: exact SKU/barcode/tên trước, sau đó prefix, rồi contains.
4. Chỉ cho phép **thêm mới vào phiếu** các sản phẩm `is_active = true`.
5. Không preload toàn bộ catalog sản phẩm vào payload của trang tạo/sửa phiếu; tìm kiếm phải gọi API có debounce.
6. Không thay đổi nghiệp vụ chọn hàng hiện có: giá nhập mặc định vẫn là `cost_price`; hàng serial vẫn nhập Serial/IMEI mới thủ công; không áp dụng chặn tồn/sẵn bán/đang sửa của POS.

---

## 3. Phạm vi code được phép sửa

### In scope

- `resources/js/Pages/Purchases/Create.vue`
- `resources/js/Pages/Purchases/Edit.vue`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/ProductController.php`
- Test liên quan dưới `tests/Feature/Products/` và/hoặc `tests/Feature/Purchases/`
- Có thể tạo **một composable Vue nhỏ** chỉ dành cho tìm kiếm sản phẩm nếu dùng chung cho Create và Edit giúp tránh copy-paste.

### Out of scope

- Không sửa `ProductSearchService` nếu không phát hiện lỗi thực tế trong service.
- Không sửa endpoint POS `/api/pos/products`.
- Không tạo endpoint public mới, không đổi middleware/quyền hiện có.
- Không migration, không thêm index/FULLTEXT, không sửa schema.
- Không đụng `PurchaseController@store`, `update` ngoài phần payload Inertia cần thiết.
- Không đổi cách tăng số lượng khi chọn lại hàng hoặc cách thêm Serial/IMEI hiện tại.

---

## 4. Thiết kế kỹ thuật yêu cầu

### 4.1 Dùng một API tìm kiếm chuẩn, không copy logic query vào Vue

Tái sử dụng endpoint hiện có:

```text
GET /api/products/search?search=<keyword>&active_only=1
```

Endpoint này phải tiếp tục dùng `ProductSearchService` để có cùng tập luật tìm kiếm với POS.

Trong `ProductController@apiSearch`:

```php
$query = Product::query();

if ($request->boolean('active_only')) {
    $query->where('is_active', true);
}

// Giữ ProductSearchService::apply(...) và applyScore(...) hiện có.
```

Yêu cầu quan trọng:

- `active_only` phải là **opt-in**. Không đổi hành vi mặc định của các caller khác đang dùng `/api/products/search`.
- Khi `active_only=1`, không trả về hàng inactive trong kết quả tìm để thêm mới vào phiếu nhập.
- Giữ limit hiện có là 20 khi không có `product_ids`.
- Không thêm query riêng theo name/SKU/barcode/serial ở controller hoặc frontend; phải tiếp tục đi qua `ProductSearchService`.

### 4.2 Đồng bộ Create và Edit

Cả hai file `Purchases/Create.vue` và `Purchases/Edit.vue` phải gọi cùng endpoint với params:

```js
{
  search: keyword.trim(),
  active_only: 1,
}
```

Hành vi frontend bắt buộc:

- Debounce **300–400 ms**.
- Không gọi API nếu `keyword.trim()` rỗng; xóa danh sách gợi ý và trạng thái loading.
- Tối đa hiển thị số kết quả API trả về (API đã giới hạn 20).
- Bảo vệ race condition: nếu request cũ trả về sau request mới, không được ghi đè kết quả của keyword mới. Dùng request sequence/token hoặc `AbortController` phù hợp phiên bản axios hiện có.
- Khi blur, giữ delay ngắn hiện có để click vào gợi ý vẫn hoạt động.
- Có trạng thái loading nhỏ và trạng thái “Không tìm thấy hàng hóa” khi keyword không rỗng nhưng API trả về mảng rỗng.
- Không log lỗi nhạy cảm ra UI; khi request lỗi, dọn danh sách kết quả và hiển thị thông báo gọn, không phá form đang nhập.

### 4.3 Bỏ preload catalog không cần thiết

Sau khi Create chuyển sang API search:

- Bỏ `products` prop và `allProducts` local list ở `Purchases/Create.vue` nếu không còn dependency.
- Bỏ truy vấn `Product::where('is_active', true)->get()` và prop `products` từ `PurchaseController@create`.
- Với `Purchases/Edit.vue`, sau khi dùng API search thống nhất, bỏ prop/list `products` nếu không còn được template hoặc logic khác sử dụng.
- Không được bỏ dữ liệu của các dòng đang có trong phiếu sửa: các item hiện có phải tiếp tục render từ `purchase.items.product`, kể cả khi sản phẩm đó hiện inactive. Hàng inactive chỉ không được xuất hiện trong **kết quả tìm để thêm mới**.

### 4.4 Giữ nguyên nghiệp vụ Nhập hàng khi chọn kết quả

Khi `selectProduct(product)`:

- Giữ `price = product.cost_price || 0`.
- Với hàng không serial: nếu chưa có dòng thì thêm quantity = 1; nếu đã có thì tăng quantity như hành vi hiện tại.
- Với hàng serial: giữ quantity = 0, hiển thị vùng nhập Serial/IMEI và không tự lấy serial đang có từ kho.
- Không kiểm tra `sellable_quantity`, `repairing_count`, oversell, hoặc chặn tồn kho bằng logic POS.
- Không thay đổi format payload submit của Create/Edit.

### 4.5 Quick Create Product

Callback tạo nhanh sản phẩm ở Create hiện đang thêm sản phẩm vào `allProducts` rồi chọn sản phẩm. Sau khi bỏ preload list:

```js
@created="(product) => selectProduct(product)"
```

Sản phẩm vừa tạo phải được thêm ngay vào phiếu nhập, không cần gọi lại API search.

---

## 5. Tiêu chí nghiệm thu (Acceptance Criteria)

| ID | Tình huống | Kết quả bắt buộc |
|---|---|---|
| AC-01 | Tìm theo tên | Create và Edit trả về đúng sản phẩm active. |
| AC-02 | Tìm theo SKU | Create và Edit trả về đúng sản phẩm active. |
| AC-03 | Tìm theo barcode | Create và Edit trả về đúng sản phẩm active. |
| AC-04 | Tìm theo serial/IMEI đã có | Create và Edit trả về đúng sản phẩm sở hữu serial đó. |
| AC-05 | Tìm `màn 13.3 đốm` hoặc chuỗi có ký tự `-`, `_`, `/` | Kết quả theo token giống logic `ProductSearchService`/POS. |
| AC-06 | Hàng inactive có tên/SKU/barcode khớp | Không xuất hiện khi request có `active_only=1`. |
| AC-07 | Hàng inactive đã tồn tại trong phiếu đang sửa | Dòng cũ vẫn hiển thị bình thường; không được mất dữ liệu. |
| AC-08 | Gõ nhanh `a` → `ab` → `abc` | Chỉ kết quả của keyword mới nhất được hiển thị. |
| AC-09 | Chọn hàng không serial | Giá vốn mặc định, quantity tăng đúng như hiện tại. |
| AC-10 | Chọn hàng serial | Không tự chọn serial tồn kho; người dùng vẫn nhập serial mới. |
| AC-11 | Tạo nhanh hàng hóa | Hàng mới được thêm trực tiếp vào phiếu nhập. |
| AC-12 | Form Create lớn | Payload Inertia không còn chứa toàn bộ `products`; catalog chỉ được lấy khi tìm. |

---

## 6. Test bắt buộc

Mở rộng hoặc tạo test feature cho API `/api/products/search`:

1. `active_only=1` loại hàng inactive.
2. Không truyền `active_only` không được vô tình đổi contract hiện có của endpoint.
3. Tìm theo barcode trả về sản phẩm đúng.
4. Tìm theo serial/IMEI trả về sản phẩm đúng.
5. Tìm nhiều token không liên tiếp trả về kết quả như POS.
6. Exact SKU/barcode có thứ tự ưu tiên tốt hơn kết quả contains khi có dữ liệu cạnh tranh.
7. Request search không được thay đổi `stock_quantity`, `inventory_total_cost`, `cost_price`, serial status, hoặc bất kỳ chứng từ nào.

Tái sử dụng/làm giàu test hiện có khi phù hợp:

```bash
php artisan test tests/Feature/Products/Step247AdvancedProductSearchTest.php
php artisan test tests/Feature/Purchases
npm run build
```

Chạy thêm test mới tạo cho Nhập hàng. Không báo PASS nếu chưa thực sự chạy lệnh và đọc kết quả.

---

## 7. Manual QA bắt buộc trên browser/staging

1. Vào `/purchases/create`, kiểm tra Network: khi mở trang không có response props chứa full catalog sản phẩm.
2. Tìm lần lượt: tên, SKU, barcode, serial/IMEI, multi-token có dấu gạch nối.
3. Chọn hàng thường 2 lần, xác nhận quantity tăng đúng.
4. Chọn hàng serial, xác nhận quantity vẫn phụ thuộc serial được nhập mới.
5. Tạo nhanh hàng hóa, xác nhận hàng được thêm vào dòng phiếu.
6. Vào `/purchases/{id}/edit` với một phiếu có hàng inactive cũ; xác nhận dòng cũ còn hiển thị và không thể tìm thêm hàng inactive.
7. Gõ nhanh nhiều ký tự, xác nhận không xuất hiện kết quả cũ.
8. Lưu phiếu nhập thử với hàng thường và hàng serial; xác nhận không có thay đổi ngoài phạm vi tìm kiếm.

---

## 8. Deliverables khi hoàn tất

Codex phải trả về:

1. Danh sách file thay đổi và lý do từng file.
2. Mô tả ngắn các điểm đảm bảo parity với POS và các điểm **cố ý khác** vì nghiệp vụ Nhập hàng.
3. Kết quả test đã chạy, kèm lệnh và số test/assertion nếu tool hiển thị.
4. Kết quả Manual QA hoặc ghi rõ chưa chạy được browser QA.
5. Danh sách rủi ro còn lại. Không được tuyên bố deploy production nếu chưa qua review và staging QA.

---

## 9. Definition of Done

- Create và Edit dùng cùng cơ chế API search có `active_only=1`.
- Tìm được name/SKU/barcode/serial/IMEI/multi-token với ranking của `ProductSearchService`.
- Không còn local filtering dựa trên full `products` list ở Create.
- Không thay đổi tồn kho, giá vốn, serial, công nợ, cashflow hay submit payload.
- Test và build bắt buộc pass.
- Diff chỉ chứa file thuộc phạm vi công việc này.