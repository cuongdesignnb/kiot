# KẾ HOẠCH RÀ SOÁT HỆ THỐNG BÁO CÁO THEO LOGIC KIOTVIET

## 1) Mục tiêu

Tài liệu này dùng để rà soát và chỉnh lại toàn bộ **hệ thống báo cáo / phân tích** sao cho:

- đúng **nguồn dữ liệu**
- đúng **logic tính**
- đúng **công thức tổng hợp**
- đúng **bộ lọc**
- đúng **drill-down từ báo cáo về chứng từ gốc**
- và hạn chế tối đa việc “số ở báo cáo đúng màn hình này nhưng sai khi đối chiếu chứng từ”

Mục tiêu cuối cùng không phải chỉ là “giao diện giống KiotViet”, mà là:
**mỗi chỉ tiêu báo cáo phải truy ngược được về giao dịch nguồn và tính ra đúng như KiotViet**.

---

## 2) Căn cứ đối chiếu từ tài liệu KiotViet

Theo tài liệu báo cáo Retail hiện hành của KiotViet, hệ thống báo cáo được chia thành các nhóm chính:

1. Báo cáo Cuối ngày
2. Báo cáo Bán hàng
3. Báo cáo Hàng hóa
4. Báo cáo Khách hàng
5. Báo cáo Nhà cung cấp
6. Báo cáo Kênh bán hàng
7. Báo cáo Tài chính

Tài liệu cũng nêu rõ mục đích của từng nhóm, ví dụ:
- Báo cáo Cuối ngày dùng để tổng kết nhanh giao dịch bán hàng và thu chi trong ngày.
- Báo cáo Bán hàng theo dõi Doanh thu, Giá trị trả, Doanh thu thuần, số lượng đơn bán.
- Báo cáo Hàng hóa theo dõi Bán hàng, Lợi nhuận, Giá trị kho, Xuất nhập tồn, Hạn sử dụng.
- Báo cáo Khách hàng dùng cho Hàng bán theo khách và Công nợ.
- Báo cáo Nhà cung cấp dùng cho Hàng nhập theo NCC, Công nợ, VAT nhập hàng.
- Báo cáo Kênh bán hàng dùng để so sánh Doanh thu, Lợi nhuận, Hàng bán theo từng kênh.
- Báo cáo Tài chính đi theo luồng Doanh thu → Giảm trừ → Giá vốn → Chi phí → Lợi nhuận.

Nguồn:
- Trang tổng quan báo cáo Retail hiện hành
- Các trang hướng dẫn chi tiết cũ hơn về Báo cáo Bán hàng, Hàng hóa, Khách hàng, Nhà cung cấp, Tài chính, dùng để bổ sung cấu trúc mối quan tâm và bộ lọc.

---

## 3) Điều cần hiểu đúng trước khi làm

### 3.1 Không nên code báo cáo theo kiểu “select sum cho ra số”
Phải định nghĩa trước:

- nguồn dữ liệu gốc của từng chỉ tiêu
- thời điểm ghi nhận chỉ tiêu
- điều kiện tính / không tính
- ảnh hưởng của hủy chứng từ
- ảnh hưởng của trả hàng
- ảnh hưởng của giảm giá, voucher, điểm
- ảnh hưởng của chi nhánh / kho / người bán / kênh bán

### 3.2 Không nên lấy báo cáo làm nguồn sự thật
Nguồn sự thật phải là:

- hóa đơn bán
- phiếu trả hàng
- phiếu nhập
- phiếu trả hàng nhập
- công nợ khách
- công nợ NCC
- bút toán/quỹ
- tồn kho
- bảng giá vốn / giá vốn thực tế nếu có

Báo cáo chỉ là **lớp tổng hợp** từ các nguồn này.

### 3.3 Tài liệu công khai của KiotViet không công bố hết mọi công thức chi tiết
Tài liệu public có nêu:
- nhóm báo cáo
- mục tiêu từng báo cáo
- nhiều chỉ tiêu hiển thị
- nhiều bộ lọc
- luồng tài chính tổng quát
- các “mối quan tâm” trong báo cáo bán hàng / hàng hóa / khách hàng / nhà cung cấp

Nhưng tài liệu public **không công bố đầy đủ toàn bộ công thức chi tiết từng cột**.

Vì vậy kế hoạch rà soát phải làm theo 2 lớp:

1. **Lớp A – bám đúng những gì tài liệu công khai khẳng định**
2. **Lớp B – dùng test song song trên KiotViet để suy ra công thức thực tế của từng chỉ tiêu chưa được mô tả hết**

---

## 4) Bản đồ nhóm báo cáo cần rà soát

## 4.1 Báo cáo Cuối ngày
Theo KiotViet, báo cáo này tổng kết giao dịch bán hàng, thu chi trong ngày và có thể lọc theo ngày và phương thức thanh toán.

### Phải rà:
- tổng số hóa đơn bán
- tổng tiền hàng
- tổng giảm giá
- tổng khách cần trả
- tổng khách đã trả
- thu/chi trong ngày
- tách theo phương thức thanh toán
- dữ liệu theo chi nhánh / ca / nhân viên nếu hệ thống có

### Nguồn dữ liệu gốc:
- sales_invoices
- invoice_payments
- cashbook_entries
- return_invoices
- canceled_documents

### Rủi ro hay sai:
- hủy hóa đơn không loại khỏi cuối ngày
- phiếu trả hàng làm lệch tổng thu
- một hóa đơn nhiều phương thức thanh toán bị cộng sai
- ngày chứng từ và ngày tạo chứng từ bị lẫn nhau

---

## 4.2 Báo cáo Bán hàng
KiotViet công khai các chỉ tiêu như:
- Doanh thu
- Giá trị trả
- Doanh thu thuần
- Số lượng đơn bán

Ở tài liệu chi tiết cũ hơn, báo cáo bán hàng còn có 6 “mối quan tâm”:
- Thời gian
- Lợi nhuận
- Giảm giá hóa đơn
- Trả hàng
- Nhân viên
- Chi nhánh

### Phải rà:
- định nghĩa Doanh thu
- định nghĩa Giá trị trả
- định nghĩa Doanh thu thuần
- cách tính số lượng đơn bán
- lợi nhuận gộp theo hóa đơn
- tổng giảm giá hóa đơn
- hiệu quả theo nhân viên
- hiệu quả theo chi nhánh
- lọc thời gian / chi nhánh

### Gợi ý công thức làm chuẩn nội bộ
Do tài liệu public không công bố công thức chi tiết 100%, nên nên chuẩn hóa như sau:

- `Tổng tiền hàng`: tổng tiền trước giảm giá ở các dòng hàng hợp lệ
- `Giảm giá hóa đơn`: phần discount ở header
- `Giá trị trả`: tổng giá trị từ phiếu trả hàng liên quan
- `Doanh thu`: cần chốt rõ bạn dùng gross hay net trong từng widget
- `Doanh thu thuần`: nên được tính từ một pipeline rõ ràng và cố định, không được mỗi report mỗi kiểu

### Việc bắt buộc
Phải lập **data dictionary** riêng cho 4 chỉ tiêu:
- tổng tiền hàng
- doanh thu
- giá trị trả
- doanh thu thuần

Nếu không định nghĩa rõ 4 chỉ tiêu này, mọi báo cáo sau sẽ sai dây chuyền.

---

## 4.3 Báo cáo Hàng hóa
KiotViet công khai báo cáo hàng hóa gồm các mối quan tâm:
- Bán hàng
- Lợi nhuận
- Xuất nhập tồn
- Xuất nhập tồn chi tiết
- Nhân viên theo hàng bán
- Khách hàng theo hàng bán
- NCC theo hàng nhập

Các bộ lọc lặp lại nhiều nhất là:
- Chi nhánh
- Thời gian
- Hàng hóa
- Loại hàng
- Thuộc tính
- Nhóm hàng

### Phải rà:
- số lượng bán theo hàng
- giá trị bán theo hàng
- doanh thu thuần theo hàng
- lợi nhuận theo hàng
- tồn đầu / nhập / xuất / tồn cuối
- giá trị đầu kỳ / nhập / xuất / cuối kỳ
- nhóm hàng / thuộc tính / loại hàng
- top hàng bán chạy / hàng tồn lâu / giá trị kho

### Cực kỳ quan trọng
Phải chốt rõ:
- giá trị xuất dùng giá nào
- giá vốn dùng giá nào
- tồn cuối dựa trên stock ledger nào
- trả hàng bán có cộng lại tồn không
- trả hàng nhập có trừ lại nhập không
- kiểm kho điều chỉnh ảnh hưởng thế nào

### Công thức đối soát bắt buộc
Cho mỗi sản phẩm, mỗi kho / chi nhánh:

`Tồn cuối = Tồn đầu + Nhập - Xuất +/- Điều chỉnh`

Nếu công thức này không cân, mọi báo cáo hàng hóa đều vô nghĩa.

---

## 4.4 Báo cáo Khách hàng
KiotViet công khai báo cáo khách hàng gồm:
- Bán hàng
- Hàng bán theo khách
- Công nợ
- Lợi nhuận

Bộ lọc chi tiết gồm:
- Chi nhánh
- Thời gian
- Nhóm khách hàng
- Khách hàng
- riêng công nợ còn có lọc Nợ cuối kỳ
- riêng lợi nhuận còn có thêm lọc hàng hóa / loại hàng / thuộc tính / nhóm hàng

### Phải rà:
- top khách hàng
- tổng giá trị bán theo khách
- số lượng hàng mua theo khách
- công nợ đầu kỳ / phát sinh / cuối kỳ
- lợi nhuận theo khách
- nhóm khách hàng

### Công thức công nợ khách bắt buộc
`Nợ cuối = Nợ đầu + Bán chịu / phát sinh nợ - Thu nợ - Giảm trừ - Trả hàng`

Nếu hệ thống bạn không thể tính được công thức này ở cấp khách hàng và đối chiếu lại với ledger chi tiết, báo cáo khách hàng chưa đạt.

---

## 4.5 Báo cáo Nhà cung cấp
Theo tài liệu KiotViet:
- Hàng nhập theo NCC
- Công nợ
- VAT nhập hàng
Ở tài liệu chi tiết cũ hơn:
- mối quan tâm Nhập hàng
- Hàng nhập theo NCC
- Công nợ

Bộ lọc:
- Chi nhánh
- Thời gian
- Nhà cung cấp
- công nợ nhà cung cấp

### Phải rà:
- số lượng phiếu nhập theo NCC
- tổng tiền hàng nhập
- giảm giá nhập
- giá trị nhập
- số lượng / giá trị trả hàng nhập
- công nợ đầu kỳ / phát sinh / cuối kỳ
- VAT nhập hàng nếu hệ thống có

### Công thức công nợ NCC bắt buộc
`Nợ cuối NCC = Nợ đầu + Nhập chịu / phát sinh phải trả - Thanh toán NCC - Trả hàng nhập`

Nếu báo cáo công nợ NCC không đối chiếu được với lịch sử thanh toán và phiếu nhập / trả nhập, phải sửa trước khi làm các dashboard khác.

---

## 4.6 Báo cáo Kênh bán hàng
Tài liệu hiện hành của KiotViet nêu nhóm này dùng để xem:
- Doanh thu
- Lợi nhuận
- Hàng bán chi tiết theo từng kênh

### Phải rà:
- mapping kênh bán vào chứng từ
- hóa đơn nào thuộc kênh nào
- đổi kênh có ảnh hưởng dữ liệu cũ không
- doanh thu / lợi nhuận theo kênh có khớp với tổng doanh thu chung không

### Điều kiện pass bắt buộc
Tổng doanh thu của tất cả kênh (sau khi cùng scope lọc) phải khớp với tổng doanh thu bán hàng toàn hệ thống trong cùng điều kiện lọc.

---

## 4.7 Báo cáo Tài chính
Đây là nhóm phải khóa cuối cùng nhưng quan trọng nhất.

Tài liệu KiotViet hiện hành nêu rõ báo cáo tài chính sẽ hạch toán:
- Doanh thu
- Giá vốn
- Chi phí như voucher, lương, điểm thưởng
- từ đó ra lợi nhuận cuối cùng

Tài liệu cũ hơn nêu thêm luồng:
- Doanh thu bán hàng
- Giảm trừ doanh thu
- Giá trị bán hàng bị trả lại
- Doanh thu thuần

### Phải rà:
- định nghĩa Doanh thu bán hàng
- định nghĩa Giảm trừ doanh thu
- định nghĩa Giá trị hàng bán bị trả lại
- định nghĩa Doanh thu thuần
- Giá vốn
- Chi phí hoạt động
- Voucher / coupon / điểm / lương / chi phí khác
- Lợi nhuận cuối cùng

### Pipeline tài chính cần chuẩn hóa
Khuyến nghị khóa theo pipeline:

1. Doanh thu bán hàng
2. Các khoản giảm trừ doanh thu
3. Giá trị hàng bán bị trả lại
4. Doanh thu thuần
5. Giá vốn hàng bán
6. Lợi nhuận gộp
7. Chi phí hoạt động
8. Lợi nhuận thuần / lợi nhuận cuối cùng

### Cảnh báo
Tài liệu public không công bố chi tiết mapping 100% mọi khoản giảm trừ và chi phí.

Vì vậy đoạn này bắt buộc phải làm thêm **test song song với KiotViet** để chốt:
- voucher được trừ ở đâu
- coupon được trừ ở đâu
- điểm thưởng ảnh hưởng doanh thu hay chi phí
- lương đi vào nhóm chi phí nào
- chiết khấu hóa đơn nằm ở giảm trừ hay đã phản ánh trực tiếp vào doanh thu

---

## 5) Kế hoạch rà soát theo giai đoạn

## Giai đoạn 1 — Khóa “nguồn sự thật”
Mục tiêu: xác định mỗi báo cáo lấy dữ liệu từ đâu.

### Việc phải làm
1. Liệt kê toàn bộ bảng / service đang cấp dữ liệu cho từng báo cáo.
2. Với từng chỉ tiêu, ghi rõ:
   - bảng nguồn
   - field nguồn
   - điều kiện lọc
   - điều kiện loại trừ
   - thời điểm ghi nhận
3. Tách riêng:
   - dữ liệu giao dịch gốc
   - dữ liệu tổng hợp / materialized
   - dữ liệu cache

### Deliverable
- `report_source_map.md`

---

## Giai đoạn 2 — Khóa từ điển chỉ tiêu (metric dictionary)
Mục tiêu: mỗi chỉ tiêu chỉ có **1 định nghĩa duy nhất**.

### Việc phải làm
Lập bảng cho từng metric:

- tên metric
- mô tả nghiệp vụ
- công thức
- source tables
- where conditions
- excluded statuses
- refund/return handling
- branch scope
- timezone rule
- example test case

### Danh sách metric phải khóa trước
- Tổng tiền hàng
- Doanh thu
- Giá trị trả
- Doanh thu thuần
- Giá vốn
- Lợi nhuận gộp
- Chi phí
- Lợi nhuận thuần
- Nợ đầu / phát sinh / nợ cuối khách
- Nợ đầu / phát sinh / nợ cuối NCC
- Tồn đầu / nhập / xuất / tồn cuối
- Giá trị kho

### Deliverable
- `metric_dictionary_reports.md`

---

## Giai đoạn 3 — Chốt bộ dữ liệu vàng để test
Mục tiêu: có bộ dữ liệu nhỏ nhưng đủ phủ hết logic.

### Bộ test data đề xuất
Phải có tối thiểu:

- 2 chi nhánh
- 2 kho
- 10 sản phẩm
- 2 nhóm hàng
- 5 khách hàng
- 3 NCC
- 2 kênh bán
- 3 nhân viên bán hàng

### Phải có nghiệp vụ mẫu
- bán đủ tiền
- bán nợ
- bán có giảm giá dòng
- bán có giảm giá hóa đơn
- bán có voucher / coupon / điểm nếu hệ thống hỗ trợ
- trả hàng một phần
- trả hàng toàn phần
- nhập hàng đủ tiền
- nhập hàng công nợ
- trả hàng nhập
- thu nợ khách
- trả nợ NCC
- kiểm kho điều chỉnh
- chuyển kho
- chi phí hoạt động
- chi lương nếu hệ thống có

### Deliverable
- `report_test_dataset_v1.sql`
- `report_test_cases_v1.md`

---

## Giai đoạn 4 — So sánh song song với KiotViet
Mục tiêu: suy ra logic thực tế ở chỗ tài liệu public chưa đủ.

### Cách làm
1. Dùng cùng một bộ dữ liệu đầu vào
2. Tạo cùng các chứng từ trên:
   - KiotViet
   - hệ thống của bạn
3. So từng report:
   - tổng số
   - số theo từng chiều
   - số theo drill-down
4. Ghi lại sai lệch

### Deliverable
- `kiotviet_parallel_report_comparison.xlsx`

### Ghi chú
Giai đoạn này đặc biệt cần cho:
- Doanh thu thuần
- Lợi nhuận
- Chi phí voucher/coupon/điểm
- Giá vốn
- Profit theo khách / hàng / kênh

---

## Giai đoạn 5 — Audit theo từng nhóm báo cáo

## 5.1 Audit Báo cáo Cuối ngày
Checklist:
- theo ngày đúng chưa
- theo phương thức thanh toán đúng chưa
- tổng thu/chi đúng chưa
- hủy và trả hàng có loại trừ/đảo đúng chưa
- chi nhánh đúng chưa
- drill-down đúng chưa

## 5.2 Audit Báo cáo Bán hàng
Checklist:
- doanh thu
- giá trị trả
- doanh thu thuần
- số lượng đơn bán
- theo thời gian
- theo nhân viên
- theo chi nhánh
- theo trả hàng
- theo giảm giá hóa đơn

## 5.3 Audit Báo cáo Hàng hóa
Checklist:
- số lượng bán theo hàng
- doanh thu theo hàng
- lợi nhuận theo hàng
- xuất nhập tồn
- xuất nhập tồn chi tiết
- khách theo hàng bán
- NCC theo hàng nhập
- group by nhóm hàng / hàng cùng loại

## 5.4 Audit Báo cáo Khách hàng
Checklist:
- top khách hàng
- hàng bán theo khách
- công nợ khách
- lợi nhuận theo khách
- nhóm khách hàng

## 5.5 Audit Báo cáo Nhà cung cấp
Checklist:
- nhập hàng theo NCC
- hàng nhập theo NCC
- công nợ NCC
- VAT nhập hàng nếu có

## 5.6 Audit Báo cáo Kênh bán hàng
Checklist:
- doanh thu theo kênh
- lợi nhuận theo kênh
- hàng bán theo kênh
- tổng kênh = tổng bán hàng chung

## 5.7 Audit Báo cáo Tài chính
Checklist:
- doanh thu bán hàng
- giảm trừ doanh thu
- hàng bán bị trả lại
- doanh thu thuần
- giá vốn
- lợi nhuận gộp
- chi phí hoạt động
- lợi nhuận cuối cùng

---

## Giai đoạn 6 — Thứ tự fix để tránh sửa dây chuyền sai

Không được sửa báo cáo tài chính trước khi khóa xong bán hàng / hàng hóa / công nợ.

### Thứ tự fix khuyến nghị
1. Dữ liệu nguồn giao dịch
2. Báo cáo Cuối ngày
3. Báo cáo Bán hàng
4. Báo cáo Hàng hóa
5. Báo cáo Khách hàng
6. Báo cáo Nhà cung cấp
7. Báo cáo Kênh bán hàng
8. Báo cáo Tài chính

Lý do:
- Tài chính phụ thuộc bán hàng + hàng hóa + công nợ + chi phí
- nếu chưa khóa xong dưới mà sửa tài chính trước thì chỉ là vá số

---

## 6) Quy chuẩn công thức nội bộ cần chốt

## 6.1 Mỗi chỉ tiêu phải có 1 file giải thích công thức
Ví dụ:

```text
Metric: net_revenue
Tên hiển thị: Doanh thu thuần
Nguồn: sales_invoices + sales_returns + invoice_discounts
Công thức: ...
Loại trừ trạng thái: canceled, draft
Scope thời gian: invoice_date
Scope chi nhánh: branch_id
Xử lý trả hàng: ...
Ghi chú: ...
```

## 6.2 Không được có 2 công thức cho cùng một metric
Ví dụ sai:
- dashboard tổng dùng công thức A
- báo cáo chi tiết dùng công thức B
- export dùng query C

Phải thống nhất:
- 1 metric service / query builder
- nhiều màn hình gọi lại

---

## 7) Bộ test bắt buộc sau khi sửa

## 7.1 Test số tổng
- tổng doanh thu
- tổng giá trị trả
- tổng công nợ khách
- tổng công nợ NCC
- tổng tồn cuối
- tổng lợi nhuận

## 7.2 Test chi tiết theo chiều
- theo chi nhánh
- theo nhân viên
- theo kênh
- theo khách
- theo NCC
- theo hàng hóa
- theo nhóm hàng

## 7.3 Test drill-down
Ví dụ:
- từ Doanh thu theo nhân viên -> bấm vào phải ra đúng list hóa đơn
- từ Công nợ khách -> bấm vào phải ra đúng ledger khách
- từ Xuất nhập tồn -> bấm vào phải ra đúng stock ledger

## 7.4 Test export
- export phải đúng số như list
- export theo filter thời gian / chi nhánh / trạng thái
- export detail khớp với drill-down

---

## 8) Tiêu chí pass / fail

### PASS
- số tổng đúng
- số theo chiều đúng
- drill-down đúng
- export đúng
- đối soát với nguồn gốc cân
- test song song với KiotViet không còn lệch đáng kể

### FAIL
- tổng đúng nhưng chi tiết sai
- list đúng nhưng export sai
- cùng một chỉ tiêu nhưng hai màn hình cho ra hai số khác nhau
- drill-down không truy ra được chứng từ gốc
- đổi filter là lệch số bất thường
- hủy / trả hàng / giảm giá làm số không đảo đúng

---

## 9) Danh sách deliverable nên yêu cầu team làm

1. `report_source_map.md`
2. `metric_dictionary_reports.md`
3. `report_test_dataset_v1.sql`
4. `report_parallel_comparison.xlsx`
5. `report_gap_list.xlsx`
6. `report_fix_priority.md`
7. `report_regression_checklist.md`

---

## 10) Kết luận

Nếu muốn làm “đúng như KiotViet”, bạn không nên bắt đầu từ UI báo cáo.

Phải đi theo thứ tự:

1. khóa nguồn dữ liệu
2. khóa định nghĩa chỉ tiêu
3. tạo dữ liệu test chuẩn
4. test song song với KiotViet
5. sửa từ báo cáo nguồn lên báo cáo tài chính
6. khóa regression

### Câu chốt quan trọng
**Báo cáo đúng không phải là nhìn giống KiotViet, mà là mọi chỉ tiêu đều truy ngược được về chứng từ gốc và tính ra cùng logic.**
