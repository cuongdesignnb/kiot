# TÀI LIỆU BỔ SUNG / LÀM LẠI BỘ LỌC THEO HƯỚNG KIOTVIET

## 1. Mục tiêu

Tài liệu này dùng để:

- rà soát các bộ lọc đang thiếu trên hệ thống
- chuẩn hóa cách thiết kế bộ lọc theo hướng giống KiotViet
- tránh làm filter chắp vá từng màn hình
- thống nhất cách lọc giữa UI, API, export và báo cáo
- làm cơ sở giao việc cho dev backend, frontend, QA

> Nguyên tắc: không làm mỗi trang một kiểu.  
> Phải có **1 bộ filter engine chuẩn**, sau đó từng màn hình chỉ khai báo thêm các filter nghiệp vụ riêng.

---

## 2. Kết luận kiến trúc

Qua đối chiếu hướng dẫn sử dụng KiotViet, pattern lọc lặp lại ở rất nhiều màn hình là:

1. **Ô tìm kiếm đa trường**
2. **Bộ lọc theo ngữ cảnh nghiệp vụ**
3. **Bộ lọc thời gian**
4. **Bộ lọc chi nhánh / kho / đối tượng liên quan**
5. **Bộ lọc trạng thái**
6. **Sắp xếp**
7. **Xuất file đúng tập dữ liệu đang lọc**
8. Một số màn hình có thêm:
   - so sánh số liệu
   - lọc theo người tạo / người bán / người phụ trách
   - lọc theo loại chứng từ / loại giao dịch
   - lọc theo nhóm hàng / NCC / khách hàng / vận đơn / quỹ

Vì vậy hệ thống cần chia bộ lọc thành 3 tầng:

### 2.1. Tầng lọc chung toàn hệ thống
Áp dụng cho hầu hết list:

- `keyword`
- `date_from`
- `date_to`
- `branch_id`
- `status`
- `created_by`
- `sort_by`
- `sort_dir`
- `page`
- `page_size`
- `export_current_result`

### 2.2. Tầng lọc theo đối tượng
Dùng lại cho nhiều màn hình:

- `customer_id`
- `supplier_id`
- `product_id`
- `category_id`
- `warehouse_id`
- `cashbook_id`
- `carrier_id`
- `seller_id`
- `assignee_id`
- `customer_group_id`

### 2.3. Tầng lọc theo nghiệp vụ riêng của từng màn hình
Ví dụ:

- hóa đơn: `invoice_type`
- vận đơn: `delivery_status`
- nhập hàng: `payment_status`
- đặt hàng nhập: `receipt_progress_status`
- kiểm kho: `stocktake_result_status`
- thiết lập giá: `price_compare_operator`

---

## 3. Quy chuẩn hành vi bắt buộc

## 3.1. Quy chuẩn keyword search

Mỗi list phải có ô `keyword`, nhưng keyword không được chỉ tìm 1 trường duy nhất.

Ví dụ chuẩn:

### Hóa đơn bán
`keyword` phải tìm trên:
- mã hóa đơn
- mã khách hàng
- tên khách hàng
- số điện thoại khách hàng
- mã hàng
- tên hàng
- mã đặt hàng
- mã vận đơn
- ghi chú

### Nhập hàng
`keyword` phải tìm trên:
- mã phiếu nhập
- mã NCC
- tên NCC
- mã hàng
- tên hàng
- ghi chú

### Trả hàng nhập
`keyword` phải tìm trên:
- mã phiếu
- mã / tên hàng
- mã / tên NCC
- ghi chú

### Vận đơn
`keyword` phải tìm trên:
- mã vận đơn
- mã hóa đơn
- tên khách
- số điện thoại
- ghi chú

### Kiểm kho
`keyword` phải tìm trên:
- mã phiếu kiểm
- mã hàng
- tên hàng

> Không được để ô search chỉ tìm mỗi mã chứng từ, vì như vậy khác xa cách dùng thực tế.

---

## 3.2. Quy chuẩn bộ lọc thời gian

Mọi màn hình giao dịch phải có:

- `date_preset`: hôm nay, hôm qua, 7 ngày qua, tháng này, tháng trước, tùy chọn
- `date_field`: ngày chứng từ / ngày tạo / ngày hoàn thành (nếu cần)
- `date_from`
- `date_to`

Quy định:
- nếu người dùng chọn preset thì tự fill `date_from/date_to`
- nếu nhập tay `date_from/date_to` thì ưu tiên tay
- export phải bám theo bộ lọc thời gian hiện tại

---

## 3.3. Quy chuẩn trạng thái

Mọi giao dịch có vòng đời phải có filter `status`.

Ví dụ:
- hóa đơn: nháp / hoàn thành / hủy / giao hàng / trả hàng...
- nhập hàng: tạm / hoàn thành / hủy
- đặt hàng nhập: mở / nhập một phần / hoàn thành / hủy
- vận đơn: chờ lấy / đang giao / giao thành công / chuyển hoàn / hủy
- kiểm kho: tạm / đã cân bằng / đã hủy

Không được:
- hiển thị nhiều trạng thái ở list nhưng API không lọc được
- mỗi màn hình dùng tên trạng thái khác nhau cho cùng một logic
- list lọc được nhưng export lại không đúng trạng thái

---

## 3.4. Quy chuẩn lọc chi nhánh / kho

Mọi màn hình có dữ liệu vận hành theo chi nhánh hoặc kho phải có:

- `branch_id` hoặc `branch_ids`
- `warehouse_id` hoặc `warehouse_ids`

Quy định:
- nếu user chỉ có quyền 1 chi nhánh thì mặc định khóa đúng chi nhánh đó
- nếu user có nhiều chi nhánh thì cho chọn một hoặc nhiều
- mọi list / export / tổng số lượng / phân trang phải cùng dùng một source lọc

---

## 3.5. Quy chuẩn export

Export phải bám đúng:
- filter hiện tại
- sort hiện tại nếu có
- phân loại dữ liệu hiện tại

Không được:
- list đang lọc 20 dòng nhưng export ra toàn bộ
- list hiển thị đúng, export sai status
- list lọc theo chi nhánh A nhưng export lẫn chi nhánh B

---

## 4. Ma trận bộ lọc cần có theo từng màn hình

## 4.1. Danh sách hàng hóa

### Bộ lọc bắt buộc
- `keyword` (mã hàng, tên hàng, mã vạch)
- `category_id`
- `status` (đang kinh doanh / ngừng kinh doanh)
- `branch_id` nếu dữ liệu hàng hóa phân chi nhánh
- `stock_status`:
  - còn hàng
  - sắp hết
  - hết hàng
  - dưới định mức tồn
- `expected_out_of_stock_date`
- `price_compare_field`:
  - giá bán
  - giá vốn
  - giá nhập lần cuối
- `price_compare_operator`:
  - >
  - >=
  - =
  - <=
  - <
- `price_compare_value`

### Nên có thêm
- `has_batch_expiry`
- `has_serial_imei`
- `is_service`
- `created_by`
- `updated_at`

### Việc cần bổ sung nếu thiếu
- lọc ngừng kinh doanh
- lọc theo ngày dự kiến hết hàng
- lọc so sánh giá bán với giá vốn / giá nhập lần cuối
- export theo tập dữ liệu đang lọc

---

## 4.2. Thiết lập giá / bảng giá

### Bộ lọc bắt buộc
- `keyword`
- `price_table_id`
- `status` (áp dụng / ngừng / hết hiệu lực)
- `branch_id`
- `customer_group_id`
- `creator_scope`
- `product_scope_type`
- `category_id`

### Lọc trong danh sách item của bảng giá
- `keyword`
- `category_id`
- `price_compare_operator`
- `price_compare_field`
- `rounding_mode`
- `is_in_price_table`

### Việc cần bổ sung nếu thiếu
- lọc theo bảng giá
- lọc theo phạm vi áp dụng
- lọc item có / không có trong bảng giá
- compare các bảng giá

---

## 4.3. Hóa đơn bán hàng

### Bộ lọc bắt buộc
- `keyword` (hóa đơn, hàng hóa, khách hàng, đặt hàng, vận đơn, ghi chú)
- `date_from`
- `date_to`
- `branch_id`
- `invoice_type`
- `status`
- `seller_id`
- `customer_id`
- `payment_status`
- `delivery_status`
- `channel_id` nếu có kênh bán
- `sort_by`
- `sort_dir`

### Nên có thêm
- `has_return`
- `has_discount`
- `has_debt`
- `source_type` (bán trực tiếp / từ đặt hàng)
- `carrier_id`

### Việc cần bổ sung nếu thiếu
- ô search đa trường đúng nghĩa
- filter loại hóa đơn
- filter người bán
- filter trạng thái giao hàng
- export đúng tập lọc

---

## 4.4. Đặt hàng bán

### Bộ lọc bắt buộc
- `keyword` (mã đơn, khách hàng, hàng hóa, ghi chú)
- `date_from`
- `date_to`
- `branch_id`
- `status`
- `seller_id`
- `customer_id`
- `delivery_status`
- `deposit_status`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `source_type`
- `channel_id`
- `is_overdue`
- `has_invoice_generated`

### Việc cần bổ sung nếu thiếu
- filter theo trạng thái xử lý đặt hàng
- filter theo đặt cọc
- filter theo đã xuất hóa đơn hay chưa

---

## 4.5. Nhập hàng

### Bộ lọc bắt buộc
- `keyword` (mã phiếu, NCC, hàng hóa, ghi chú)
- `date_from`
- `date_to`
- `branch_id`
- `warehouse_id`
- `status`
- `supplier_id`
- `created_by`
- `payment_status`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `has_discount`
- `has_debt`
- `from_purchase_order`
- `stock_posted`

### Việc cần bổ sung nếu thiếu
- filter NCC
- filter trạng thái thanh toán
- filter theo kho nhập
- export lịch sử thanh toán / danh sách phiếu

---

## 4.6. Đặt hàng nhập

### Bộ lọc bắt buộc ở list phiếu
- `keyword` (mã phiếu đặt hàng nhập, mã phiếu nhập, mã/tên hàng, mã/tên NCC)
- `date_from`
- `date_to`
- `branch_id`
- `supplier_id`
- `status`
- `created_by`
- `ordered_by`
- `payment_status`
- `receipt_progress_status`
- `sort_by`
- `sort_dir`

### Bộ lọc bắt buộc ở popup đề xuất hàng nhập
- `branch_ids`
- `supplier_id`
- `category_ids`
- `stock_condition`:
  - dưới định mức tồn
  - hết hàng
  - không xét tồn

### Nên có thêm
- `expected_receipt_date_from`
- `expected_receipt_date_to`
- `has_partial_receipt`
- `has_landed_cost`

### Việc cần bổ sung nếu thiếu
- đây là một trong những trang cần làm đầy nhất
- nếu chưa có filter đề xuất hàng nhập theo tồn thì nên ưu tiên bổ sung sớm

---

## 4.7. Trả hàng nhập

### Bộ lọc bắt buộc
- `keyword` (mã phiếu, hàng hóa, NCC, ghi chú)
- `date_from`
- `date_to`
- `branch_id`
- `status`
- `supplier_id`
- `created_by`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `from_purchase_receipt_id`
- `payment_return_status`
- `has_note`
- `warehouse_id`

### Việc cần bổ sung nếu thiếu
- filter trạng thái
- filter người tạo
- search theo hàng hóa
- sort theo thời gian / tổng tiền / mã phiếu

---

## 4.8. Khách hàng

### Bộ lọc bắt buộc
- `keyword` (mã KH, tên, điện thoại, email, mã số thuế nếu có)
- `branch_id` nếu bật quản lý theo chi nhánh
- `customer_group_id`
- `status`
- `created_by`
- `has_receivable`
- `has_transaction`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `is_supplier_linked`
- `last_transaction_from`
- `last_transaction_to`
- `debt_range_min`
- `debt_range_max`

### Việc cần bổ sung nếu thiếu
- lọc theo chi nhánh
- lọc theo nhóm khách hàng
- lọc khách có nợ / không nợ
- export đúng danh sách đang hiển thị

---

## 4.9. Nhà cung cấp

### Bộ lọc bắt buộc
- `keyword` (mã NCC, tên, điện thoại, email, MST nếu có)
- `branch_id` nếu bật quản lý theo chi nhánh
- `status`
- `created_by`
- `has_payable`
- `has_transaction`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `linked_customer_flag`
- `last_purchase_from`
- `last_purchase_to`
- `debt_range_min`
- `debt_range_max`

### Việc cần bổ sung nếu thiếu
- filter nợ phải trả
- filter theo chi nhánh
- export đúng tập hiển thị

---

## 4.10. Chuyển hàng

### Bộ lọc bắt buộc
- `keyword` (mã phiếu, mã/tên hàng, ghi chú)
- `date_from`
- `date_to`
- `from_warehouse_id`
- `to_warehouse_id`
- `branch_id`
- `status`
- `created_by`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `received_by`
- `has_short_receive`
- `transfer_type`

### Việc cần bổ sung nếu thiếu
- filter trạng thái đang chuyển / đã nhận / hủy
- filter kho đi / kho đến

---

## 4.11. Kiểm kho

### Bộ lọc bắt buộc
- `keyword` (mã phiếu, mã hàng, tên hàng)
- `date_from`
- `date_to`
- `warehouse_id`
- `branch_id`
- `status`
- `created_by`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `difference_type`:
  - lệch dương
  - lệch âm
  - không lệch
- `category_id`
- `balanced_flag`

### Việc cần bổ sung nếu thiếu
- filter trạng thái phiếu kiểm
- filter theo kho
- filter theo loại lệch

---

## 4.12. Sổ quỹ

### Bộ lọc bắt buộc
- `keyword` (mã phiếu, đối tượng, nội dung)
- `date_from`
- `date_to`
- `cashbook_type`:
  - tiền mặt
  - ngân hàng
  - ví điện tử
  - tổng quỹ
- `receipt_payment_type`
- `partner_type`
- `partner_id`
- `created_by`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `accounting_impact_flag`
- `payment_method`
- `branch_id`
- `amount_min`
- `amount_max`

### Việc cần bổ sung nếu thiếu
- filter theo quỹ
- filter loại thu/chi
- filter đối tượng nộp/nhận
- sort theo mã phiếu / thời gian / số tiền

---

## 4.13. Vận đơn / giao hàng

### Bộ lọc bắt buộc
- `keyword` (mã vận đơn, mã hóa đơn, khách hàng, điện thoại)
- `date_from`
- `date_to`
- `branch_id`
- `delivery_status`
- `carrier_id`
- `payment_status`
- `created_by`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `is_cod`
- `return_to_sender_flag`
- `pickup_status`
- `delivery_partner_type` (tích hợp / tự giao)

### Việc cần bổ sung nếu thiếu
- filter trạng thái giao hàng
- filter đối tác giao hàng
- filter chuyển hoàn
- export danh sách vận đơn

---

## 4.14. Báo cáo / lịch sử / audit

### Bộ lọc bắt buộc
- `date_from`
- `date_to`
- `branch_id`
- `created_by` hoặc `actor_id`
- `action_type`
- `module`
- `keyword`
- `sort_by`
- `sort_dir`

### Nên có thêm
- `entity_type`
- `entity_code`
- `severity`
- `old_value_contains`
- `new_value_contains`

### Việc cần bổ sung nếu thiếu
- filter theo người thao tác
- filter theo module
- filter theo loại thao tác
- export theo bộ lọc

---

## 5. Checklist làm lại backend

## 5.1. Chuẩn hóa query filter

Mỗi list phải có 1 lớp filter riêng, ví dụ:

- `ProductFilter`
- `InvoiceFilter`
- `PurchaseReceiptFilter`
- `PurchaseOrderFilter`
- `CustomerFilter`
- `SupplierFilter`

Mỗi filter class cần:
- validate input
- map param sang query condition
- hỗ trợ multi-select
- hỗ trợ date range
- hỗ trợ keyword multi-field
- hỗ trợ sort whitelist

---

## 5.2. Không viết filter rải trong controller

Sai:
- controller if else dài hàng trăm dòng
- mỗi dev viết 1 kiểu param
- có trang dùng `from_date`, trang khác dùng `date_from`

Đúng:
- chuẩn hóa input:
  - `keyword`
  - `date_from`
  - `date_to`
  - `branch_id`
  - `status`
  - `sort_by`
  - `sort_dir`

---

## 5.3. Sort phải whitelist

Không được cho frontend sort mọi field tự do.

Mỗi màn hình cần khai báo:

```php
allowedSorts = [
  'created_at',
  'code',
  'total_amount',
  'status',
];
```

Nếu field không nằm trong whitelist thì fallback mặc định.

---

## 5.4. Export phải dùng lại đúng query filter

Sai phổ biến:
- list query 1 kiểu
- export query 1 kiểu khác

Đúng:
- `filter -> buildQuery()`
- list dùng query đó
- export cũng dùng query đó
- chỉ khác phân trang / format output

---

## 5.5. Index database cần có

Các bảng giao dịch lớn nên có index tối thiểu:

### Hóa đơn
- `(branch_id, created_at)`
- `(status, created_at)`
- `(customer_id, created_at)`
- `(seller_id, created_at)`
- `(code)`
- fulltext hoặc index hỗ trợ search keyword nếu cần

### Nhập hàng
- `(supplier_id, created_at)`
- `(status, created_at)`
- `(warehouse_id, created_at)`
- `(branch_id, created_at)`

### Đặt hàng nhập
- `(supplier_id, status)`
- `(branch_id, expected_receipt_date)`
- `(created_by, created_at)`

### Vận đơn
- `(delivery_status, created_at)`
- `(carrier_id, created_at)`
- `(invoice_id)`
- `(waybill_code)`

### Khách hàng / NCC
- `(phone)`
- `(code)`
- `(name)`
- `(branch_id, status)`

---

## 6. Checklist làm lại frontend

## 6.1. Tất cả list dùng chung 1 pattern UI

UI filter nên có 3 vùng:

### Vùng 1 — Search nhanh
- ô keyword
- nút clear
- enter để search

### Vùng 2 — Filter chính
- thời gian
- chi nhánh
- trạng thái
- đối tượng liên quan

### Vùng 3 — Filter nâng cao
- số tiền
- người tạo
- loại giao dịch
- cờ đặc biệt

---

## 6.2. Hiển thị số filter đang bật

Ví dụ:
- “Bộ lọc (3)”

Người dùng biết đang lọc theo:
- chi nhánh A
- tháng này
- trạng thái hoàn thành

---

## 6.3. Có nút reset toàn bộ filter

Bắt buộc phải có:
- reset về mặc định
- không giữ filter rác từ trang trước

---

## 6.4. Persist filter state hợp lý

Nên lưu:
- query string trên URL
- hoặc local state theo từng module

Để:
- reload trang không mất filter
- copy link cho người khác vẫn ra đúng màn hình đã lọc

---

## 6.5. Export phải nhìn thấy rõ đang export cái gì

Nút export nên thể hiện:
- Export tất cả
- hoặc Export theo bộ lọc hiện tại

Mặc định nên là:
- export theo bộ lọc hiện tại

---

## 7. Bộ API param chuẩn đề xuất

Ví dụ chuẩn cho mọi list:

```json
{
  "keyword": "",
  "date_from": "2026-04-01",
  "date_to": "2026-04-21",
  "branch_id": 1,
  "status": ["completed", "partial"],
  "customer_id": 12,
  "supplier_id": null,
  "warehouse_id": null,
  "created_by": null,
  "sort_by": "created_at",
  "sort_dir": "desc",
  "page": 1,
  "page_size": 20
}
```

Quy định:
- multi-select dùng array
- sort_dir chỉ nhận `asc|desc`
- date phải normalize timezone
- param rỗng thì bỏ qua

---

## 8. Ưu tiên triển khai

## Ưu tiên 1 — làm ngay
1. Hóa đơn bán
2. Nhập hàng
3. Đặt hàng nhập
4. Trả hàng nhập
5. Vận đơn

## Ưu tiên 2
6. Khách hàng
7. Nhà cung cấp
8. Sổ quỹ
9. Kiểm kho
10. Chuyển hàng

## Ưu tiên 3
11. Hàng hóa
12. Thiết lập giá
13. Audit / lịch sử thao tác
14. Các module mở rộng khác

---

## 9. Định nghĩa hoàn thành

Một màn hình chỉ được coi là hoàn thành filter khi đủ:

1. Có keyword search đa trường
2. Có date range đúng
3. Có status đúng nghiệp vụ
4. Có branch / warehouse / partner filter nếu liên quan
5. Có sort đúng whitelist
6. Export đúng tập dữ liệu đang lọc
7. API và UI dùng cùng một truth source
8. Có test case QA cho từng filter
9. Có kiểm tra kết hợp nhiều filter cùng lúc
10. Không có mismatch giữa list và export

---

## 10. Checklist QA cho mỗi màn hình

Ví dụ checklist QA chung:

- lọc theo 1 điều kiện có đúng không
- lọc theo 2 điều kiện kết hợp có đúng không
- keyword + date range có đúng không
- status + branch có đúng không
- reset filter có sạch không
- reload trang có giữ filter đúng không
- export có đúng tập đang lọc không
- pagination có đúng sau khi lọc không
- total count có đúng không
- sort asc/desc có đúng không

---

## 11. Kết luận

Hệ thống hiện tại không nên vá từng filter lẻ nữa.

Cần làm lại theo hướng:

- 1 engine filter chung
- 1 chuẩn param chung
- 1 chuẩn export chung
- 1 ma trận filter theo màn hình
- 1 checklist QA thống nhất

Nếu làm đúng như vậy, hệ thống sẽ tiến gần cách vận hành của KiotViet hơn rất nhiều và cũng dễ mở rộng về sau.
