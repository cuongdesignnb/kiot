# TÀI LIỆU RÀ SOÁT GIÁ VỐN BÌNH QUÂN VÀ GIÁ VỐN SERIAL/IMEI

## 1. Mục tiêu

Tài liệu này dùng để rà soát lại hệ thống quản lý bán hàng của bạn đã xử lý đúng **giá vốn** hay chưa, đặc biệt với 2 nhóm sản phẩm:

1. **Sản phẩm thường**  
   Dùng phương pháp **giá vốn bình quân**.

2. **Sản phẩm có Serial/IMEI**  
   Nên dùng **giá vốn đích danh theo từng Serial/IMEI** khi bán, trả, chuyển, xuất hủy.

Mục tiêu không phải chỉ kiểm xem hệ thống có hiển thị “giá vốn” hay không, mà phải kiểm:

- nhập hàng có cập nhật giá vốn đúng không
- bán hàng có ghi nhận giá vốn đúng không
- trả hàng bán có hoàn lại đúng giá vốn không
- trả hàng nhập có giảm đúng giá trị tồn không
- chuyển kho có giữ nguyên giá vốn không
- kiểm kho / xuất hủy có xử lý đúng giá trị tồn không
- báo cáo lợi nhuận, tồn kho, giá trị kho có khớp không

---

## 2. Nguyên tắc cốt lõi

## 2.1 Với sản phẩm thường

Sản phẩm thường nên tính theo **bình quân gia quyền tức thời**.

Công thức:

```text
Giá vốn bình quân mới
= (Giá trị tồn hiện tại + Giá trị nhập mới)
  / (Số lượng tồn hiện tại + Số lượng nhập mới)
```

Trong đó:

```text
Giá trị tồn hiện tại = Số lượng tồn hiện tại × Giá vốn bình quân hiện tại
```

```text
Giá trị nhập mới = Số lượng nhập × Đơn giá nhập sau phân bổ
```

Nếu có chi phí nhập hàng:

```text
Đơn giá nhập sau phân bổ
= (Tiền hàng sau giảm giá + Chi phí nhập hàng phân bổ) / Số lượng nhập
```

---

## 2.2 Với sản phẩm Serial/IMEI

Sản phẩm có Serial/IMEI không nên lấy giá vốn bình quân để ghi nhận giá vốn bán ra.

Nguyên tắc chuẩn:

```text
Giá vốn bán ra = giá vốn của đúng Serial/IMEI được bán
```

Ví dụ:

```text
IMEI001: vốn 10.000.000
IMEI002: vốn 11.000.000
IMEI003: vốn 12.000.000
```

Nếu bán `IMEI001`, giá vốn của hóa đơn phải là:

```text
10.000.000
```

Không lấy bình quân:

```text
(10.000.000 + 11.000.000 + 12.000.000) / 3 = 11.000.000
```

Vì nếu lấy bình quân thì lợi nhuận từng máy, đổi trả, bảo hành, xuất hủy, báo cáo IMEI sẽ sai.

---

## 3. Data model cần có để kiểm tra

## 3.1 Bảng tồn kho tổng hợp cho sản phẩm thường

Nên có bảng tương đương:

```text
stock_balances
- id
- product_id
- warehouse_id
- quantity
- avg_cost
- stock_value
- updated_at
```

Ý nghĩa:

```text
stock_value = quantity × avg_cost
```

Lưu ý:
- không nên chỉ lưu `quantity`
- bắt buộc phải lưu hoặc tính được `stock_value`
- nếu chỉ có `avg_cost` mà không có `stock_value`, khi làm tròn sẽ dễ lệch

---

## 3.2 Bảng lịch sử kho

Nên có bảng tương đương:

```text
stock_movements
- id
- product_id
- warehouse_id
- movement_type
- quantity_in
- quantity_out
- unit_cost
- total_cost
- reference_type
- reference_id
- created_at
```

Các `movement_type` nên có:

```text
opening_stock
purchase_receipt
sales_invoice
sales_return
purchase_return
stock_transfer_out
stock_transfer_in
stocktake_adjustment
stock_disposal
cancel_reversal
```

---

## 3.3 Bảng Serial/IMEI

Nếu hệ thống có bán hàng IMEI, bắt buộc nên có bảng tương đương:

```text
product_serials
- id
- product_id
- serial_imei
- warehouse_id
- status
- cost_price
- purchase_receipt_id
- purchase_receipt_item_id
- sales_invoice_id
- sales_invoice_item_id
- sold_cost_price
- returned_at
- created_at
```

Trạng thái đề xuất:

```text
in_stock
sold
returned
transferred
purchase_returned
disposed
canceled
```

Quan trọng:
- mỗi Serial/IMEI phải có `cost_price`
- khi bán phải lưu `sold_cost_price`
- không được phụ thuộc vào giá vốn bình quân hiện tại sau này

---

## 4. Quy tắc tính cho sản phẩm thường

## 4.1 Nhập tồn đầu

Khi nhập tồn đầu:

```text
quantity = số lượng tồn đầu
avg_cost = giá vốn nhập ban đầu
stock_value = quantity × avg_cost
```

Ví dụ:

```text
Tồn đầu: 10 cái
Giá vốn: 5.000.000
Giá trị tồn: 50.000.000
```

Kỳ vọng hệ thống:

```text
quantity = 10
avg_cost = 5.000.000
stock_value = 50.000.000
```

---

## 4.2 Nhập hàng lần 1

Ví dụ đang có:

```text
Tồn hiện tại: 10
Giá vốn bình quân hiện tại: 5.000.000
Giá trị tồn hiện tại: 50.000.000
```

Nhập thêm:

```text
Số lượng nhập: 5
Đơn giá nhập: 6.000.000
Giá trị nhập: 30.000.000
```

Công thức:

```text
Giá vốn mới
= (50.000.000 + 30.000.000) / (10 + 5)
= 80.000.000 / 15
= 5.333.333,33
```

Kỳ vọng sau nhập:

```text
quantity = 15
stock_value = 80.000.000
avg_cost ≈ 5.333.333,33
```

---

## 4.3 Bán hàng sản phẩm thường

Nếu bán 2 cái sau khi giá vốn bình quân là `5.333.333,33`:

```text
COGS = 2 × 5.333.333,33 = 10.666.666,66
```

Sau bán:

```text
quantity = 15 - 2 = 13
stock_value = 80.000.000 - 10.666.666,66 = 69.333.333,34
avg_cost vẫn ≈ 5.333.333,33
```

### Checklist cần kiểm
- dòng hóa đơn có lưu `cost_price_at_sale` không
- dòng hóa đơn có lưu `total_cost` không
- báo cáo lợi nhuận lấy giá vốn tại thời điểm bán hay lấy giá vốn hiện tại

Bắt buộc:

```text
invoice_item.cost_price_at_sale = avg_cost tại thời điểm bán
invoice_item.total_cost = qty × cost_price_at_sale
```

---

## 4.4 Nhập hàng sau khi đã bán

Tiếp tục ví dụ:

```text
Tồn hiện tại: 13
Giá trị tồn: 69.333.333,34
```

Nhập thêm:

```text
Số lượng nhập: 7
Đơn giá nhập: 7.000.000
Giá trị nhập: 49.000.000
```

Giá vốn mới:

```text
new_avg_cost
= (69.333.333,34 + 49.000.000) / (13 + 7)
= 118.333.333,34 / 20
= 5.916.666,667
```

Kỳ vọng:

```text
quantity = 20
stock_value = 118.333.333,34
avg_cost ≈ 5.916.666,667
```

---

## 4.5 Trả hàng bán đối với sản phẩm thường

Nguyên tắc đúng:

```text
Khi khách trả hàng, phải hoàn lại kho theo giá vốn đã ghi nhận lúc bán.
```

Không được lấy giá vốn hiện tại.

Ví dụ:
- lúc bán, giá vốn ghi trên hóa đơn là `5.333.333,33`
- hiện tại giá vốn mới đã là `5.916.666,667`
- khách trả lại 1 cái từ hóa đơn cũ

Giá trị nhập lại kho phải là:

```text
1 × 5.333.333,33
```

Không phải:

```text
1 × 5.916.666,667
```

Sau trả hàng:

```text
new_qty = old_qty + returned_qty
new_stock_value = old_stock_value + returned_cost
new_avg_cost = new_stock_value / new_qty
```

### Checklist cần kiểm
- phiếu trả hàng có tham chiếu hóa đơn gốc không
- dòng trả hàng có lấy lại `cost_price_at_sale` không
- nếu trả không theo hóa đơn thì hệ thống đang lấy giá vốn nào
- báo cáo lợi nhuận có đảo đúng giá vốn không

---

## 4.6 Trả hàng nhập đối với sản phẩm thường

Trả hàng nhập là trả hàng về nhà cung cấp.

Nguyên tắc:

```text
Khi trả hàng nhập, tồn kho giảm.
Giá trị tồn giảm theo giá vốn đang được ghi nhận trong kho hoặc theo dòng nhập gốc nếu trả theo phiếu nhập.
```

Có 2 trường hợp:

### Trả nhanh, không theo phiếu nhập
Có thể lấy:

```text
cost_out = current_avg_cost × quantity_returned
```

### Trả theo phiếu nhập gốc
Nên lấy theo giá vốn của dòng nhập gốc nếu hệ thống có lưu chi tiết:

```text
cost_out = purchase_receipt_item.unit_cost_allocated × quantity_returned
```

Sau khi trừ:

```text
new_qty = old_qty - returned_qty
new_stock_value = old_stock_value - cost_out
new_avg_cost = new_stock_value / new_qty
```

Nếu hệ thống chọn cách luôn dùng bình quân hiện tại cho trả hàng nhập thì phải thống nhất toàn bộ báo cáo theo cách đó, nhưng cần biết rằng cách này có thể lệch khi đối chiếu theo phiếu nhập gốc.

---

## 5. Quy tắc tính cho sản phẩm Serial/IMEI

## 5.1 Nhập hàng Serial/IMEI

Ví dụ nhập 3 máy:

```text
IMEI001: giá nhập 10.000.000
IMEI002: giá nhập 11.000.000
IMEI003: giá nhập 12.000.000
```

Hệ thống phải tạo 3 bản ghi:

```text
IMEI001 | in_stock | cost_price = 10.000.000
IMEI002 | in_stock | cost_price = 11.000.000
IMEI003 | in_stock | cost_price = 12.000.000
```

Tổng tồn:

```text
quantity = 3
stock_value = 33.000.000
avg_display_cost = 11.000.000
```

Lưu ý:

```text
avg_display_cost chỉ dùng để xem tổng quan.
Không dùng avg_display_cost để tính giá vốn khi bán từng IMEI.
```

---

## 5.2 Nhập hàng Serial/IMEI có chi phí nhập hàng

Ví dụ:

```text
IMEI001 giá nhập: 10.000.000
IMEI002 giá nhập: 11.000.000
IMEI003 giá nhập: 12.000.000
Chi phí nhập hàng: 300.000
```

Nếu phân bổ đều:

```text
Chi phí phân bổ mỗi IMEI = 300.000 / 3 = 100.000
```

Giá vốn từng IMEI:

```text
IMEI001 = 10.100.000
IMEI002 = 11.100.000
IMEI003 = 12.100.000
```

Nếu phân bổ theo giá trị hàng:

```text
Chi phí phân bổ từng IMEI
= Tổng chi phí nhập × Giá trị IMEI / Tổng tiền hàng
```

Ví dụ với tổng tiền hàng `33.000.000`:

```text
IMEI001 = 300.000 × 10.000.000 / 33.000.000
IMEI002 = 300.000 × 11.000.000 / 33.000.000
IMEI003 = 300.000 × 12.000.000 / 33.000.000
```

Hệ thống cần chốt rõ đang dùng phương pháp phân bổ nào.

---

## 5.3 Bán hàng Serial/IMEI

Nếu bán `IMEI001`:

```text
Giá vốn bán ra = cost_price của IMEI001
```

Ví dụ:

```text
IMEI001 cost_price = 10.100.000
Bán giá = 13.000.000
Lợi nhuận = 13.000.000 - 10.100.000 = 2.900.000
```

Sau bán:

```text
product_serials.IMEI001.status = sold
product_serials.IMEI001.sales_invoice_id = id hóa đơn
invoice_item.cost_price = 10.100.000
invoice_item.serial_imei = IMEI001
```

Không được:

```text
invoice_item.cost_price = avg_cost của toàn sản phẩm
```

---

## 5.4 Bán nhiều IMEI trên cùng một dòng sản phẩm

Ví dụ bán 2 máy:

```text
IMEI001 cost = 10.100.000
IMEI003 cost = 12.100.000
```

Tổng giá vốn dòng hóa đơn:

```text
total_cost = 10.100.000 + 12.100.000 = 22.200.000
```

Nếu UI hiển thị 1 dòng sản phẩm số lượng 2, backend vẫn phải lưu chi tiết IMEI:

```text
invoice_item_serials
- invoice_item_id
- serial_imei
- cost_price
```

Không được chỉ lưu:

```text
qty = 2
cost_price = avg_cost
```

vì sẽ mất dấu giá vốn từng máy.

---

## 5.5 Trả hàng bán Serial/IMEI

Nếu khách trả lại đúng `IMEI001`:

```text
Nhập lại kho đúng IMEI001
Giá vốn nhập lại = sold_cost_price của IMEI001
```

Ví dụ lúc bán:

```text
IMEI001 sold_cost_price = 10.100.000
```

Khi trả:

```text
stock_value tăng lại 10.100.000
IMEI001.status = in_stock hoặc returned_in_stock
```

Không được lấy giá vốn hiện tại của sản phẩm.

---

## 5.6 Trả hàng nhập Serial/IMEI

Nếu trả `IMEI002` về nhà cung cấp:

```text
Giảm tồn đúng IMEI002
Giảm giá trị tồn = cost_price của IMEI002
IMEI002.status = purchase_returned
```

Nếu giá NCC hoàn lại khác giá vốn:

Ví dụ:

```text
IMEI002 cost_price = 11.100.000
Giá NCC hoàn lại = 11.500.000
```

Khi đó:
- tồn kho giảm theo `11.100.000`
- công nợ / tiền NCC trả ghi theo `11.500.000`
- phần chênh lệch cần đi vào thu nhập khác / điều chỉnh công nợ / rule kế toán nội bộ

Điểm quan trọng:

```text
Giá trị tồn kho không nên lấy theo tiền NCC hoàn lại.
Giá trị tồn kho nên lấy theo giá vốn của IMEI.
```

---

## 5.7 Chuyển kho Serial/IMEI

Khi chuyển `IMEI003` từ kho A sang kho B:

```text
Kho A giảm đúng IMEI003
Kho B tăng đúng IMEI003
cost_price giữ nguyên
```

Không được tính lại giá vốn khi chuyển kho.

---

## 5.8 Xuất hủy Serial/IMEI

Nếu xuất hủy `IMEI003`:

```text
Giá trị xuất hủy = cost_price của IMEI003
IMEI003.status = disposed
```

Kho giảm:

```text
quantity giảm 1
stock_value giảm cost_price của IMEI003
```

---

## 6. Checklist rà soát nhanh

## 6.1 Checklist cho sản phẩm thường

| STT | Nội dung kiểm tra | Đạt / Không |
|---|---|---|
| 1 | Nhập tồn đầu có tạo đúng quantity, avg_cost, stock_value không | |
| 2 | Nhập hàng mới có tính lại avg_cost đúng công thức không | |
| 3 | Chi phí nhập hàng có được phân bổ vào giá vốn không | |
| 4 | Bán hàng có lưu cost_price_at_sale không | |
| 5 | Bán hàng có trừ stock_value theo cost_price_at_sale không | |
| 6 | Bán xong avg_cost có bị thay đổi sai không | |
| 7 | Trả hàng bán có lấy giá vốn lúc bán để nhập lại kho không | |
| 8 | Trả hàng nhập có giảm tồn và giá trị tồn đúng không | |
| 9 | Hủy hóa đơn có đảo tồn và giá vốn đúng không | |
| 10 | Hủy phiếu nhập có đảo giá vốn bình quân đúng không | |
| 11 | Báo cáo lợi nhuận lấy từ cost đã lưu tại chứng từ không | |
| 12 | Xuất nhập tồn có cân theo công thức không | |

---

## 6.2 Checklist cho sản phẩm Serial/IMEI

| STT | Nội dung kiểm tra | Đạt / Không |
|---|---|---|
| 1 | Mỗi IMEI có bản ghi riêng không | |
| 2 | Mỗi IMEI có cost_price riêng không | |
| 3 | Nhập hàng có lưu cost theo từng IMEI không | |
| 4 | Chi phí nhập hàng có phân bổ được vào từng IMEI không | |
| 5 | Bán hàng có bắt chọn đúng IMEI không | |
| 6 | Giá vốn hóa đơn có lấy theo IMEI bán ra không | |
| 7 | Bán nhiều IMEI có lưu chi tiết từng IMEI không | |
| 8 | Trả hàng bán có nhập lại đúng IMEI và đúng giá vốn lúc bán không | |
| 9 | Trả hàng nhập có giảm đúng IMEI và đúng giá vốn IMEI không | |
| 10 | Chuyển kho có giữ nguyên cost_price của IMEI không | |
| 11 | Xuất hủy có lấy đúng cost_price của IMEI không | |
| 12 | Báo cáo lợi nhuận theo IMEI có khớp không | |

---

## 7. Bộ test case bắt buộc

## Test case 1 — Sản phẩm thường nhập 2 lần, bán 1 lần

### Dữ liệu

```text
Tồn đầu: 10 cái × 5.000.000 = 50.000.000
Nhập thêm: 5 cái × 6.000.000 = 30.000.000
```

### Kỳ vọng

```text
Tồn sau nhập = 15
Giá trị tồn = 80.000.000
Giá vốn bình quân = 5.333.333,33
```

Bán 2 cái:

```text
COGS = 2 × 5.333.333,33 = 10.666.666,66
```

Sau bán:

```text
Tồn = 13
Giá trị tồn = 69.333.333,34
```

---

## Test case 2 — Sản phẩm thường trả hàng bán

Tiếp test case 1.

Khách trả lại 1 cái từ hóa đơn đã bán.

### Kỳ vọng

```text
Giá vốn nhập lại = 5.333.333,33
Tồn tăng thêm 1
Giá trị tồn tăng thêm 5.333.333,33
```

Không được lấy giá vốn hiện tại nếu giá vốn đã thay đổi sau đó.

---

## Test case 3 — Sản phẩm thường nhập sau bán rồi trả hàng cũ

Sau test case 1, nhập thêm:

```text
7 cái × 7.000.000 = 49.000.000
```

Giá vốn bình quân mới khoảng:

```text
5.916.666,667
```

Khách trả lại 1 cái từ hóa đơn cũ có giá vốn:

```text
5.333.333,33
```

### Kỳ vọng

Khi trả hàng:

```text
Giá trị nhập lại = 5.333.333,33
```

Không phải:

```text
5.916.666,667
```

---

## Test case 4 — Serial/IMEI nhập nhiều giá

Nhập 3 IMEI:

```text
IMEI001 = 10.000.000
IMEI002 = 11.000.000
IMEI003 = 12.000.000
```

### Kỳ vọng

Mỗi IMEI có cost riêng:

```text
IMEI001.cost_price = 10.000.000
IMEI002.cost_price = 11.000.000
IMEI003.cost_price = 12.000.000
```

Hiển thị tổng:

```text
quantity = 3
stock_value = 33.000.000
avg_display_cost = 11.000.000
```

---

## Test case 5 — Serial/IMEI bán đúng giá vốn đích danh

Bán:

```text
IMEI001
```

### Kỳ vọng

```text
COGS = 10.000.000
IMEI001.status = sold
invoice_item_serial.cost_price = 10.000.000
```

Không được lấy:

```text
11.000.000
```

là giá bình quân hiển thị.

---

## Test case 6 — Serial/IMEI trả hàng bán

Khách trả lại:

```text
IMEI001
```

### Kỳ vọng

```text
IMEI001.status = in_stock hoặc returned_in_stock
Giá vốn nhập lại = 10.000.000
```

---

## Test case 7 — Serial/IMEI trả hàng nhập

Trả về NCC:

```text
IMEI002
```

### Kỳ vọng

```text
IMEI002.status = purchase_returned
Tồn giảm 1
Giá trị tồn giảm 11.000.000
```

Nếu NCC hoàn tiền `11.500.000`:

```text
Tồn kho vẫn giảm theo 11.000.000
Công nợ / tiền NCC trả ghi theo 11.500.000
Chênh lệch 500.000 xử lý theo rule kế toán riêng
```

---

## 8. Những lỗi nghiêm trọng cần sửa ngay

## 8.1 Lỗi nghiêm trọng với sản phẩm thường
- không lưu `cost_price_at_sale`
- báo cáo lợi nhuận lấy giá vốn hiện tại thay vì giá vốn lúc bán
- trả hàng bán lấy giá vốn hiện tại
- hủy hóa đơn không đảo giá vốn
- hủy nhập hàng không tính lại bình quân
- stock_value không khớp với stock_movements

## 8.2 Lỗi nghiêm trọng với Serial/IMEI
- không lưu giá vốn riêng từng IMEI
- bán IMEI nhưng lấy giá vốn bình quân
- trả hàng IMEI nhưng không biết giá vốn lúc bán
- chuyển kho làm đổi giá vốn IMEI
- xuất hủy lấy sai giá vốn
- nhiều IMEI gộp vào 1 dòng không lưu chi tiết từng IMEI

---

## 9. Cấu trúc service nên có

## 9.1 CostingService

Nên có service riêng:

```text
CostingService
- recalculateAverageCostOnPurchase()
- recordCostOnSale()
- restoreCostOnSalesReturn()
- removeCostOnPurchaseReturn()
- reverseCostOnCancel()
- getCurrentAverageCost()
- getSerialCost()
```

Không nên để logic giá vốn rải trong controller.

---

## 9.2 StockMovementService

```text
StockMovementService
- createOpeningStockMovement()
- createPurchaseReceiptMovement()
- createSalesIssueMovement()
- createSalesReturnMovement()
- createPurchaseReturnMovement()
- createTransferMovement()
- createStocktakeAdjustmentMovement()
- createDisposalMovement()
- createCancelReversalMovement()
```

---

## 9.3 SerialInventoryService

```text
SerialInventoryService
- receiveSerial()
- sellSerial()
- returnSoldSerial()
- returnSerialToSupplier()
- transferSerial()
- disposeSerial()
- validateSerialAvailable()
- validateSerialBelongsToWarehouse()
```

---

## 10. Công thức đối soát cuối cùng

## 10.1 Sản phẩm thường

Theo từng sản phẩm + kho:

```text
Tồn cuối = Tồn đầu + Nhập - Xuất + Trả bán - Trả nhập +/- Điều chỉnh
```

Theo giá trị:

```text
Giá trị tồn cuối
= Giá trị tồn đầu
+ Giá trị nhập
- Giá vốn xuất bán
+ Giá vốn hàng bán trả lại
- Giá vốn trả hàng nhập
+/- Giá trị điều chỉnh
```

## 10.2 Serial/IMEI

Theo từng IMEI:

```text
Mỗi IMEI chỉ được ở một trạng thái hợp lệ tại một thời điểm.
```

Theo sản phẩm:

```text
Tồn số lượng = count(IMEI có status in_stock)
```

```text
Giá trị tồn = sum(cost_price của IMEI có status in_stock)
```

---

## 11. Kết luận

Hệ thống được coi là đúng khi:

### Với sản phẩm thường
- giá vốn bình quân được tính lại đúng sau mỗi lần nhập
- bán hàng lưu giá vốn tại thời điểm bán
- trả hàng bán đảo theo giá vốn lúc bán
- báo cáo lợi nhuận không bị thay đổi khi giá vốn sau này thay đổi

### Với Serial/IMEI
- mỗi IMEI có giá vốn riêng
- bán IMEI nào lấy đúng giá vốn IMEI đó
- trả hàng / trả nhập / chuyển kho / xuất hủy đều bám đúng IMEI
- báo cáo tồn và lợi nhuận theo IMEI khớp với chứng từ gốc

Câu chốt:

```text
Hàng thường dùng giá vốn bình quân.
Hàng Serial/IMEI dùng giá vốn đích danh theo từng mã.
```

Nếu hệ thống đang dùng bình quân cho cả IMEI thì cần sửa, vì sẽ sai lợi nhuận và sai truy vết từng máy.
