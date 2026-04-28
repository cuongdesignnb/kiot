# PHƯƠNG ÁN KIỂM KHO THEO KIOTVIET + MỞ RỘNG CHO SỬA CHỮA / BÓC TÁCH LINH KIỆN

## 1. Mục tiêu tài liệu

Tài liệu này dùng để thiết kế và rà soát lại nghiệp vụ **Kiểm kho** trong hệ thống của bạn theo 2 lớp:

1. **Lớp chuẩn KiotViet**  
   Áp dụng cho kho bán hàng, kho linh kiện, kho hàng hóa thông thường.

2. **Lớp mở rộng riêng cho hệ thống của bạn**  
   Do hệ thống có thêm nghiệp vụ:
   - sửa chữa máy / thiết bị
   - nhập máy lỗi / máy chờ sửa
   - tháo/bóc tách linh kiện
   - thu hồi linh kiện từ máy cũ
   - xuất hủy / hàng hỏng / linh kiện hỏng
   - hàng của khách gửi sửa không thuộc sở hữu cửa hàng

Mục tiêu quan trọng:

> Kiểm kho chỉ dùng để đối chiếu và cân bằng số lượng thực tế với số trên phần mềm.  
> Kiểm kho không được dùng để “làm thay” các nghiệp vụ sửa chữa, bóc tách linh kiện, xuất hủy, chuyển kho, nhập hàng hoặc bán hàng.

---

## 2. Tóm tắt hành vi KiotViet về Kiểm kho

Theo tài liệu KiotViet, kiểm kho có các hành vi chính:

- Tạo phiếu kiểm kho từ menu **Hàng hóa → Kiểm kho → + Kiểm kho**
- Thêm hàng hóa bằng:
  - tìm kiếm thủ công
  - quét mã vạch
  - chọn theo nhóm hàng
  - import Excel
- Nhập **số lượng thực tế**
- Hệ thống tự tính **số lượng chênh lệch**
- Có thể **Lưu tạm** nếu chưa kiểm xong
- Có thể **Hoàn thành** để cân bằng kho
- Khi hoàn thành, hệ thống cập nhật tồn kho phần mềm bằng đúng số lượng thực tế
- Phiếu tạm có thể mở lại để cập nhật
- Phiếu đã cân bằng kho có thể hủy để phục hồi tồn kho trước kiểm
- Có thể sao chép phiếu
- Có thể gộp nhiều phiếu tạm
- Có thể tìm kiếm / lọc / sắp xếp / xuất file
- Khi xem phiếu, danh sách hàng được chia thành:
  - Tất cả
  - Khớp
  - Lệch
  - Chưa kiểm
- Giá trị lệch được tính theo công thức:

```text
Giá trị lệch = Số lượng lệch × Giá vốn tại thời điểm tạo phiếu kiểm kho
```

---

## 3. State machine chuẩn cho phiếu kiểm kho

Hệ thống nên có tối thiểu 3 trạng thái:

```text
draft        = Phiếu tạm
balanced     = Đã cân bằng kho
canceled     = Đã hủy
```

### 3.1 Phiếu tạm

- chưa tác động tồn kho
- chưa sinh stock movement điều chỉnh
- được phép sửa danh sách hàng
- được phép sửa số lượng thực tế
- được phép lưu lại để kiểm tiếp
- được phép gộp với phiếu tạm khác
- được phép xóa/hủy theo quyền

### 3.2 Đã cân bằng kho

- đã ghi nhận chênh lệch
- đã cập nhật tồn kho về số lượng thực tế
- đã sinh bút toán kho điều chỉnh
- không được sửa dòng hàng/số lượng thực tế trực tiếp
- nếu sai thì phải hủy phiếu hoặc tạo phiếu kiểm mới

### 3.3 Đã hủy

- phiếu bị vô hiệu
- nếu trước đó đã cân bằng kho thì phải sinh bút toán đảo để phục hồi tồn trước kiểm
- không được xóa cứng dữ liệu
- phải giữ lịch sử

---

## 4. Data model đề xuất

### 4.1 Bảng phiếu kiểm kho

```text
stocktakes
- id
- code
- branch_id
- warehouse_id
- stock_area_id
- status                     // draft / balanced / canceled
- stocktake_type             // normal / repair / dismantle / customer_owned / mixed
- counted_at
- balanced_at
- canceled_at
- created_by
- balanced_by
- canceled_by
- note
- total_system_qty
- total_actual_qty
- total_diff_qty
- total_diff_value
- created_at
- updated_at
```

### 4.2 Bảng dòng kiểm kho

```text
stocktake_items
- id
- stocktake_id
- product_id
- variant_id
- unit_id
- serial_imei
- batch_id
- lot_number
- warehouse_id
- stock_status              // sellable / repair_pending / in_repair / salvage / defective / scrap / customer_owned
- system_qty
- actual_qty
- diff_qty                  // actual_qty - system_qty
- cost_at_stocktake
- diff_value                // diff_qty × cost_at_stocktake
- note
```

### 4.3 Bảng lịch sử nhập số thực tế

```text
stocktake_count_logs
- id
- stocktake_id
- stocktake_item_id
- product_id
- serial_imei
- action_type               // add / update / delete / overwrite / increment
- old_actual_qty
- new_actual_qty
- counted_by
- counted_at
- device_id
- note
```

### 4.4 Bảng stock movement

```text
stock_movements
- id
- product_id
- warehouse_id
- stock_status
- movement_type             // stocktake_adjustment_in / stocktake_adjustment_out / stocktake_cancel_reversal
- qty_in
- qty_out
- unit_cost
- total_cost
- reference_type            // stocktake
- reference_id
- created_at
```

---

## 5. Công thức kiểm kho chuẩn

### 5.1 Số lượng lệch

```text
Số lượng lệch = Số lượng thực tế - Số lượng trên phần mềm
```

Ví dụ:

```text
Tồn phần mềm: 10
Thực tế kiểm: 8
Số lượng lệch = 8 - 10 = -2
```

### 5.2 Giá trị lệch

```text
Giá trị lệch = Số lượng lệch × Giá vốn tại thời điểm tạo phiếu kiểm kho
```

Ví dụ:

```text
Số lượng lệch = -2
Giá vốn tại thời điểm tạo phiếu = 5.000.000
Giá trị lệch = -10.000.000
```

### 5.3 Khi hoàn thành phiếu

Nếu `actual_qty > system_qty`:

```text
Chênh lệch dương = actual_qty - system_qty
Sinh stock_movement điều chỉnh tăng
```

Nếu `actual_qty < system_qty`:

```text
Chênh lệch âm = system_qty - actual_qty
Sinh stock_movement điều chỉnh giảm
```

Sau khi hoàn thành:

```text
Tồn mới trên phần mềm = Số lượng thực tế đã kiểm
```

---

## 6. Quy tắc giá vốn khi kiểm kho

### 6.1 Sản phẩm thường

Dùng:

```text
cost_at_stocktake = avg_cost tại thời điểm tạo phiếu kiểm kho
```

Không nên lấy giá vốn tại thời điểm hoàn thành nếu trong thời gian lưu tạm có phát sinh nhập/bán làm thay đổi giá vốn.

### 6.2 Sản phẩm Serial/IMEI

Không dùng bình quân chung cho từng IMEI.

Với mỗi serial:

```text
cost_at_stocktake = cost_price của chính serial_imei đó
```

Nếu thiếu IMEI:

```text
Giá trị lệch âm = cost_price của IMEI bị thiếu
```

Nếu phát hiện IMEI thừa mà hệ thống chưa có:

- không được tự nhập kho bán được ngay
- phải đưa vào trạng thái chờ xác minh
- cần tạo quy trình xử lý riêng:
  - nhập bổ sung
  - xác nhận máy/linh kiện thu hồi
  - điều chỉnh serial
  - hoặc ghi nhận hàng ngoài hệ thống

---

## 7. Luồng nghiệp vụ Kiểm kho chuẩn

### 7.1 Tạo phiếu kiểm kho

#### Bước 1: Chọn phạm vi kiểm

Người dùng chọn:

- Chi nhánh
- Kho
- Khu vực kho nếu có
- Loại kiểm kho:
  - Kho bán hàng
  - Kho sửa chữa
  - Kho linh kiện tháo máy
  - Kho hàng hỏng / xuất hủy
  - Kho hàng khách gửi sửa

#### Bước 2: Thêm hàng vào phiếu

Hệ thống cần hỗ trợ:

- tìm kiếm mã/tên hàng
- quét mã vạch
- chọn theo nhóm hàng
- import Excel
- với Serial/IMEI: nhập danh sách IMEI thực tế
- với linh kiện: có thể chọn nhóm linh kiện / loại linh kiện

#### Bước 3: Ghi nhận số lượng thực tế

Với hàng thường:

```text
Nhập actual_qty
```

Với hàng Serial/IMEI:

```text
Quét hoặc nhập danh sách serial thực tế
Hệ thống tự suy ra actual_qty = số serial hợp lệ
```

Với hàng lô/hạn dùng:

```text
Nhập actual_qty theo từng lô
```

#### Bước 4: Hệ thống tính chênh lệch

Phân loại:

- Khớp
- Lệch dương
- Lệch âm
- Chưa kiểm
- Serial thừa
- Serial thiếu
- Serial sai kho
- Hàng sai trạng thái

#### Bước 5: Lưu tạm hoặc Hoàn thành

- Lưu tạm: chưa thay đổi tồn
- Hoàn thành: cân bằng kho và sinh stock movements

---

## 8. Luồng gộp phiếu tạm

KiotViet hỗ trợ gộp nhiều phiếu tạm khi nhiều nhân viên cùng kiểm kho các khu vực khác nhau.

### 8.1 Điều kiện gộp

Chỉ được gộp nếu:

- tất cả phiếu đều ở trạng thái `draft`
- cùng chi nhánh
- cùng kho hoặc cùng nhóm kho được phép gộp
- cùng loại kiểm kho
- không có phiếu đã cân bằng
- không có serial xung đột chưa xử lý

### 8.2 Cách gộp

Hệ thống tạo một phiếu tạm mới.

Với hàng thường:

```text
actual_qty mới = tổng actual_qty từ các phiếu tạm được gộp
```

Với serial/IMEI:

```text
danh sách serial mới = union danh sách serial từ các phiếu
```

Nếu trùng serial:

- nếu trùng do 2 người cùng kiểm một máy: phải cảnh báo
- không cộng số lượng 2 lần

### 8.3 Sau khi gộp

Khuyến nghị cho hệ thống của bạn:

```text
Không xóa cứng phiếu cũ.
Nên chuyển sang trạng thái merged để giữ audit.
```

---

## 9. Luồng hủy phiếu kiểm kho

### 9.1 Hủy phiếu tạm

Phiếu tạm chưa tác động tồn, nên có thể:

- xóa mềm
- hoặc chuyển trạng thái `canceled`

### 9.2 Hủy phiếu đã cân bằng

Phải làm đủ:

1. Xác định toàn bộ dòng chênh lệch đã ghi nhận
2. Sinh bút toán đảo kho
3. Khôi phục tồn kho về trước kiểm
4. Đổi trạng thái phiếu sang `canceled`
5. Ghi audit log

Ví dụ:

```text
Phiếu kiểm làm giảm SP01 từ 10 xuống 8
Chênh lệch = -2
Khi hủy phiếu, phải tăng lại +2
```

---

## 10. Điểm mở rộng riêng cho hệ thống có sửa chữa

Hệ thống của bạn khác KiotViet ở chỗ có nghiệp vụ **sửa chữa** và **bóc tách linh kiện**.

Vì vậy cần chia hàng tồn thành nhiều loại trạng thái.

### 10.1 Stock status đề xuất

```text
sellable          = Hàng bán được
repair_pending    = Hàng chờ sửa
in_repair         = Đang sửa
repaired          = Đã sửa xong, chờ nhập lại bán hoặc trả khách
defective         = Hàng lỗi
salvage           = Linh kiện thu hồi / linh kiện tháo máy
scrap             = Phế liệu / bỏ đi
customer_owned    = Hàng của khách gửi sửa, không thuộc tồn tài sản cửa hàng
warranty_hold     = Hàng giữ bảo hành
```

### 10.2 Kiểm kho phải lọc theo stock_status

Không được chỉ kiểm theo `product_id`.

Ví dụ cùng một sản phẩm:

```text
Laptop Dell A
- 3 máy sellable
- 2 máy repair_pending
- 1 máy in_repair
- 4 linh kiện salvage
```

Nếu kiểm kho bán hàng thì chỉ kiểm:

```text
stock_status = sellable
```

Nếu kiểm kho sửa chữa thì kiểm:

```text
stock_status IN (repair_pending, in_repair, repaired)
```

Nếu kiểm linh kiện tháo máy:

```text
stock_status = salvage
```

---

## 11. Nguyên tắc quan trọng: sửa chữa không được xử lý bằng kiểm kho

Kiểm kho không được dùng để:

- tự tạo linh kiện tháo ra
- tự biến máy lỗi thành linh kiện
- tự biến linh kiện thành máy hoàn chỉnh
- tự ghi nhận tiêu hao linh kiện sửa chữa
- tự ghi nhận hàng khách gửi sửa thành tài sản của cửa hàng

Các việc đó phải đi qua chứng từ nghiệp vụ riêng:

```text
repair_orders
repair_consumptions
repair_outputs
dismantle_orders
dismantle_output_items
scrap_disposals
```

Kiểm kho chỉ kiểm tra xem sau các chứng từ đó, số thực tế có khớp không.

---

## 12. Luồng sửa chữa chuẩn đề xuất

### 12.1 Trường hợp máy thuộc sở hữu cửa hàng

Ví dụ cửa hàng có 1 laptop lỗi cần sửa để bán lại.

#### Bước 1: Chuyển trạng thái

```text
sellable hoặc defective -> repair_pending
```

Sinh stock movement:

```text
movement_type = repair_status_transfer
```

#### Bước 2: Tạo phiếu sửa chữa

```text
repair_order
- code
- source_product_id
- source_serial_imei
- warehouse_id
- status = in_progress
```

#### Bước 3: Xuất linh kiện sửa chữa

Nếu dùng linh kiện thay thế:

```text
repair_consumption
- repair_order_id
- part_product_id
- serial/batch nếu có
- qty
- cost
```

Tồn linh kiện giảm.

#### Bước 4: Hoàn thành sửa

Có các kết quả:

##### Kết quả A: Máy sửa xong bán lại

```text
status = repaired hoặc sellable
```

Giá vốn máy sau sửa:

```text
new_cost = old_cost + cost_parts_used + repair_labor_allocated_if_capitalized
```

Nếu lương/công sửa không cộng vào giá vốn thì đưa vào chi phí.

##### Kết quả B: Không sửa được, chuyển sang bóc linh kiện

```text
status = defective
Chuyển sang dismantle_order
```

##### Kết quả C: Xuất hủy

```text
status = scrap
Sinh phiếu xuất hủy
```

---

## 13. Luồng sửa chữa hàng của khách

Hàng khách gửi sửa **không phải tài sản của cửa hàng**.

Vì vậy:

```text
customer_owned không được tính vào giá trị tồn kho tài sản
```

### 13.1 Khi nhận máy khách gửi sửa

Tạo:

```text
repair_order
ownership = customer_owned
```

Không tăng tồn kho tài sản.

Chỉ tăng số lượng quản lý vật lý ở khu vực sửa chữa nếu cần:

```text
custody_inventory
```

### 13.2 Khi dùng linh kiện của cửa hàng để sửa máy khách

Linh kiện cửa hàng bị xuất tiêu hao:

```text
repair_consumption
qty_out
cost_out
```

Nếu tính phí cho khách:

```text
sales_invoice hoặc repair_invoice
```

Doanh thu sửa chữa đi qua bán hàng/dịch vụ.

### 13.3 Khi trả máy cho khách

Giảm custody inventory.

Không ảnh hưởng hàng tồn bán được.

---

## 14. Luồng bóc tách linh kiện chuẩn đề xuất

Bóc tách linh kiện là nghiệp vụ riêng, không phải kiểm kho.

### 14.1 Khi nào dùng

- máy hỏng không sửa được
- máy cũ mua về để lấy linh kiện
- máy lỗi cần tháo lấy RAM, SSD, màn hình, main...
- xác chết máy cần chuyển thành phế liệu / linh kiện thu hồi

### 14.2 Phiếu bóc tách

```text
dismantle_orders
- id
- code
- source_product_id
- source_serial_imei
- warehouse_id
- source_cost
- status
- created_by
- completed_by
- note
```

### 14.3 Dòng linh kiện đầu ra

```text
dismantle_output_items
- dismantle_order_id
- product_id
- serial_imei
- qty
- allocated_cost
- condition_status       // usable / repair_needed / defective / scrap
- stock_status           // salvage / sellable / defective / scrap
```

### 14.4 Công thức phân bổ giá vốn khi bóc tách

Tổng giá vốn phân bổ cho linh kiện không nên vượt quá:

```text
Giá vốn máy nguồn + chi phí tháo/làm lại được vốn hóa
```

#### Cách 1: Phân bổ thủ công

Người dùng nhập giá vốn cho từng linh kiện.

Rule:

```text
Tổng allocated_cost của linh kiện <= source_cost + capitalized_cost
```

#### Cách 2: Phân bổ theo giá trị ước tính

```text
allocated_cost_i
= total_source_cost × estimated_value_i / total_estimated_value
```

### 14.5 Khi hoàn thành bóc tách

Hệ thống phải:

1. Giảm tồn máy nguồn
2. Đổi trạng thái serial máy nguồn sang `dismantled`
3. Tăng tồn linh kiện đầu ra
4. Ghi giá vốn từng linh kiện đầu ra
5. Sinh stock movements đầy đủ
6. Không dùng kiểm kho để tự tạo linh kiện

---

## 15. Kiểm kho trong môi trường có sửa chữa/bóc tách

Cần có 4 loại phiếu kiểm kho riêng.

### 15.1 Kiểm kho bán hàng

```text
stock_status = sellable
```

### 15.2 Kiểm kho sửa chữa

```text
stock_status IN (repair_pending, in_repair, repaired, warranty_hold)
```

### 15.3 Kiểm kho linh kiện tháo máy

```text
stock_status = salvage
```

### 15.4 Kiểm hàng khách gửi sửa

```text
ownership = customer_owned
```

---

## 16. Rule không được phép khi kiểm kho

### 16.1 Không được biến hàng khách thành hàng cửa hàng

Nếu kiểm thấy một máy trong khu vực sửa chữa:

```text
customer_owned
```

thì không được tự tăng tồn `sellable`.

Phải có nghiệp vụ riêng nếu khách bán lại máy cho cửa hàng:

```text
purchase_from_customer
```

### 16.2 Không được tự tạo linh kiện từ kiểm kho

Nếu kiểm thấy có thêm RAM/SSD tháo máy chưa có trên hệ thống:

Không được chỉ kiểm kho tăng tồn linh kiện.

Phải tạo:

```text
dismantle_order
```

hoặc:

```text
adjustment_with_reason = found_unrecorded_salvage
```

và cần quyền quản lý.

### 16.3 Không được xóa mất trách nhiệm sửa chữa

Nếu kiểm thiếu máy khách gửi sửa, đây là sự cố nghiêm trọng:

- không được chỉ điều chỉnh giảm
- phải tạo biên bản mất hàng / sự cố
- phải giữ dấu vết với repair_order gốc

---

## 17. Checklist rà soát hệ thống hiện tại

### 17.1 Theo chuẩn KiotViet

| STT | Nội dung | Đạt / Không |
|---|---|---|
| 1 | Có tạo phiếu kiểm kho từ danh sách không | |
| 2 | Có thêm hàng bằng tìm kiếm không | |
| 3 | Có quét mã vạch không | |
| 4 | Có chọn theo nhóm hàng không | |
| 5 | Có import Excel không | |
| 6 | Có nhập số lượng thực tế không | |
| 7 | Có tự tính số lượng lệch không | |
| 8 | Có tính giá trị lệch theo giá vốn tại thời điểm tạo phiếu không | |
| 9 | Có tab Tất cả / Khớp / Lệch / Chưa kiểm không | |
| 10 | Có Lưu tạm không | |
| 11 | Có Hoàn thành cân bằng kho không | |
| 12 | Phiếu tạm có cập nhật tiếp được không | |
| 13 | Phiếu đã cân bằng có hủy để phục hồi tồn không | |
| 14 | Có sao chép phiếu không | |
| 15 | Có gộp phiếu tạm không | |
| 16 | Có tìm kiếm/lọc theo mã phiếu, mã/tên hàng, thời gian, trạng thái không | |
| 17 | Có xuất file tổng quan / chi tiết không | |

### 17.2 Theo nghiệp vụ sửa chữa/bóc tách

| STT | Nội dung | Đạt / Không |
|---|---|---|
| 1 | Có phân biệt sellable / repair / salvage / customer_owned không | |
| 2 | Hàng khách gửi sửa không tính vào giá trị tồn kho tài sản | |
| 3 | Có phiếu sửa chữa riêng không | |
| 4 | Có phiếu tiêu hao linh kiện sửa chữa không | |
| 5 | Có phiếu bóc tách linh kiện riêng không | |
| 6 | Bóc tách có giảm máy nguồn và tăng linh kiện đầu ra không | |
| 7 | Giá vốn linh kiện tháo ra có rule phân bổ rõ không | |
| 8 | Kiểm kho không tự tạo linh kiện tháo máy trái quy trình | |
| 9 | Kiểm thiếu hàng khách gửi sửa có xử lý sự cố riêng không | |
| 10 | Báo cáo tồn kho có tách hàng bán được và hàng đang sửa không | |

---

## 18. Bộ test case bắt buộc

### Test case 1 — Kiểm kho hàng thường lệch âm

```text
SP01 tồn phần mềm: 10
Giá vốn tại thời điểm tạo phiếu: 100.000
Thực tế kiểm: 8
```

Kỳ vọng:

```text
diff_qty = -2
diff_value = -200.000
Sau hoàn thành: tồn SP01 = 8
Sinh stock_movement adjustment_out = 2 × 100.000
```

### Test case 2 — Kiểm kho hàng thường lệch dương

```text
SP02 tồn phần mềm: 5
Giá vốn: 200.000
Thực tế kiểm: 7
```

Kỳ vọng:

```text
diff_qty = +2
diff_value = +400.000
Sau hoàn thành: tồn SP02 = 7
Sinh stock_movement adjustment_in = 2 × 200.000
```

### Test case 3 — Hủy phiếu đã cân bằng

Từ test case 1:

```text
SP01 sau kiểm = 8
```

Khi hủy phiếu:

```text
SP01 phải quay lại 10
Sinh movement đảo +2
Phiếu trạng thái canceled
```

### Test case 4 — Serial/IMEI thiếu

Hệ thống có:

```text
IMEI001 cost 10.000.000
IMEI002 cost 11.000.000
IMEI003 cost 12.000.000
```

Thực tế kiểm chỉ thấy:

```text
IMEI001
IMEI003
```

Kỳ vọng:

```text
IMEI002 thiếu
diff_qty = -1
diff_value = -11.000.000
Sau hoàn thành: IMEI002 chuyển trạng thái missing/lost hoặc adjustment_out theo rule
```

### Test case 5 — Serial/IMEI thừa

Thực tế quét thấy:

```text
IMEI999
```

Nhưng hệ thống không có.

Kỳ vọng:

```text
Không tự nhập vào tồn sellable ngay
Đưa vào danh sách serial thừa cần xác minh
Yêu cầu xử lý bằng chứng từ nhập/bóc tách/điều chỉnh có quyền cao
```

### Test case 6 — Kiểm kho khu sửa chữa

Dữ liệu:

```text
Máy A status = repair_pending
Máy B status = in_repair
Máy C status = sellable
```

Khi tạo phiếu kiểm kho sửa chữa:

```text
Chỉ lấy Máy A và Máy B
Không lấy Máy C
```

### Test case 7 — Bóc tách linh kiện không được đi qua kiểm kho

Dữ liệu:

```text
Laptop lỗi LAP001 cost 10.000.000
Thực tế thấy có SSD tháo ra
```

Kỳ vọng:

```text
Không dùng kiểm kho để tăng SSD
Phải tạo dismantle_order:
- giảm LAP001
- tăng SSD
- phân bổ giá vốn SSD
```

---

## 19. Kế hoạch triển khai

### Giai đoạn 1 — Làm chuẩn KiotViet trước

Ưu tiên:

1. state machine phiếu kiểm
2. thêm hàng / import / nhóm hàng / barcode
3. actual qty / diff qty / diff value
4. lưu tạm
5. hoàn thành cân bằng kho
6. hủy phục hồi tồn
7. copy
8. gộp phiếu tạm
9. filter / export

### Giai đoạn 2 — Mở rộng stock_status

Thêm:

```text
stock_status
ownership_type
repair_order_id
dismantle_order_id
```

Vào các bảng liên quan:

- stock_balances
- stock_movements
- product_serials
- stocktake_items

### Giai đoạn 3 — Tách nghiệp vụ sửa chữa

Làm riêng:

- repair_orders
- repair_order_items
- repair_consumptions
- repair_outputs
- repair_status_logs

### Giai đoạn 4 — Tách nghiệp vụ bóc tách

Làm riêng:

- dismantle_orders
- dismantle_source_items
- dismantle_output_items
- cost_allocation_rules

### Giai đoạn 5 — Kết nối kiểm kho với sửa chữa

Sau khi có sửa chữa/bóc tách:

- kiểm kho được lọc theo stock_status
- kiểm kho khu sửa chữa
- kiểm kho linh kiện salvage
- kiểm hàng khách gửi sửa
- không cho kiểm kho thay thế phiếu nghiệp vụ

---

## 20. Kết luận

Hệ thống kiểm kho nên được thiết kế theo 2 nguyên tắc:

### Nguyên tắc 1: Bám KiotViet ở phần kiểm kho chuẩn

```text
Tạo phiếu -> nhập thực tế -> tính lệch -> lưu tạm/hoàn thành -> cân bằng kho -> có thể hủy phục hồi tồn
```

### Nguyên tắc 2: Mở rộng riêng cho sửa chữa/bóc tách

```text
Sửa chữa và bóc tách phải có chứng từ riêng.
Kiểm kho chỉ xác nhận thực tế, không tự tạo nghiệp vụ.
```

Nếu làm đúng, hệ thống sẽ vừa giống KiotViet ở phần quản lý kho chuẩn, vừa phù hợp với nghiệp vụ riêng của bạn về sửa chữa và linh kiện tháo máy.
