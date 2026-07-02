# Giải thích công thức tính từ Báo cáo kết quả hoạt động kinh doanh KiotViet
**Nguồn dữ liệu:** file PDF xuất từ KiotViet của người dùng  
**Tên báo cáo:** Báo cáo kết quả hoạt động kinh doanh  
**Kỳ báo cáo:** Từ ngày 01/01/2025 đến ngày 31/12/2025

---

## 1) Mục đích tài liệu

Tài liệu này dùng để:

- bóc tách **các chỉ tiêu và công thức đang hiển thị trong file PDF**
- ghi rõ **công thức nào được KiotViet in trực tiếp trên báo cáo**
- ghi rõ **khoản mục chi tiết nào cộng thành tổng**
- tạo thành **checklist rà soát** để đối chiếu lại hệ thống của bạn

> Lưu ý quan trọng:  
> File PDF này **không công bố toàn bộ logic hạch toán nội bộ** của KiotViet cho mọi giao dịch nguồn.  
> Nó chỉ cho thấy:
> - tên chỉ tiêu
> - giá trị
> - một số công thức tổng hợp trực tiếp trên báo cáo
> - danh sách chi tiết của Chi phí khác / Thu nhập khác / Chi phí
>
> Vì vậy tài liệu này chia làm 2 lớp:
> 1. **Công thức hiển thị trực tiếp trên báo cáo**
> 2. **Giải thích nghiệp vụ để bạn rà soát lại nguồn dữ liệu của hệ thống**

---

## 2) Cấu trúc tổng thể của báo cáo

Báo cáo đang đi theo pipeline sau:

1. Doanh thu bán hàng
2. Giảm trừ doanh thu
3. Doanh thu thuần
4. Giá vốn hàng bán
5. Lợi nhuận gộp về bán hàng
6. Chi phí
7. Lợi nhuận từ hoạt động kinh doanh
8. Thu nhập khác
9. Chi phí khác
10. Lợi nhuận thuần

Đây là cấu trúc rất giống một báo cáo P&L dạng quản trị.

---

## 3) Các chỉ tiêu và công thức in trực tiếp trên báo cáo

## 3.1 Doanh thu bán hàng (1)
**Giá trị:** `11,897,319,698`

### Ý nghĩa rà soát
Đây là tổng doanh thu bán hàng trước phần giảm trừ doanh thu.

### Việc cần kiểm tra trong hệ thống
- đang lấy từ hóa đơn bán hoàn thành hay từ tất cả hóa đơn
- có loại trừ hóa đơn hủy chưa
- có bao gồm dịch vụ / phí / shipping hay không
- đang tính gross hay net discount dòng hàng

---

## 3.2 Giảm trừ doanh thu (2 = 2.1 + 2.2)
**Giá trị:** `875,038,000`

Báo cáo in công thức trực tiếp:

```text
(2) = (2.1) + (2.2)
```

### 3.2.1 Chiết khấu hóa đơn (2.1)
**Giá trị:** `11,621,000`

### 3.2.2 Giá trị hàng bán bị trả lại (2.2)
**Giá trị:** `863,417,000`

### Kiểm tra số học
```text
11,621,000 + 863,417,000 = 875,038,000
```

=> Khớp đúng.

### Ý nghĩa rà soát
Giảm trừ doanh thu trong báo cáo này đang gồm **2 nhóm**:
- chiết khấu hóa đơn
- giá trị hàng bán bị trả lại

### Việc cần kiểm tra trong hệ thống
- chiết khấu hóa đơn có đang tách riêng với giảm giá dòng hàng hay không
- trả hàng có đang đi vào “giảm trừ doanh thu” hay đang trừ trực tiếp ở doanh thu
- trả hàng một phần có tính đúng giá trị trả không
- các hóa đơn trả hàng bị hủy có đảo ngược đúng chưa

---

## 3.3 Doanh thu thuần (3 = 1 - 2)
**Giá trị:** `11,022,281,698`

Báo cáo in công thức trực tiếp:

```text
(3) = (1) - (2)
```

### Kiểm tra số học
```text
11,897,319,698 - 875,038,000 = 11,022,281,698
```

=> Khớp đúng.

### Ý nghĩa rà soát
Doanh thu thuần của báo cáo này đang được tính theo:

```text
Doanh thu thuần = Doanh thu bán hàng - Giảm trừ doanh thu
```

hay cụ thể:

```text
Doanh thu thuần = Doanh thu bán hàng - Chiết khấu hóa đơn - Giá trị hàng bán bị trả lại
```

### Việc cần kiểm tra trong hệ thống
- hệ thống của bạn có đang dùng cùng định nghĩa này không
- giảm giá dòng hàng đang nằm trong doanh thu gốc hay đã trừ trước đó
- voucher / coupon / điểm đang vào giảm trừ hay chi phí
- trả hàng có đang đi đúng vào nhóm giảm trừ

---

## 3.4 Giá vốn hàng bán (4)
**Giá trị:** `8,772,495,450`

### Ý nghĩa rà soát
Đây là tổng giá vốn tương ứng với phần hàng đã bán ra trong kỳ.

### Việc cần kiểm tra trong hệ thống
- giá vốn lấy theo phương pháp nào:
  - giá vốn tức thời
  - bình quân
  - FIFO
  - giá vốn lưu tại dòng hóa đơn
- trả hàng có hoàn lại giá vốn chưa
- hủy hóa đơn có đảo giá vốn không
- hàng combo / khuyến mại / quà tặng có đi giá vốn như thế nào

---

## 3.5 Lợi nhuận gộp về bán hàng (5 = 3 - 4)
**Giá trị:** `2,249,786,248`

Báo cáo in công thức trực tiếp:

```text
(5) = (3) - (4)
```

### Kiểm tra số học
```text
11,022,281,698 - 8,772,495,450 = 2,249,786,248
```

=> Khớp đúng.

### Ý nghĩa rà soát
Lợi nhuận gộp đang được tính rất rõ:

```text
Lợi nhuận gộp = Doanh thu thuần - Giá vốn hàng bán
```

### Việc cần kiểm tra trong hệ thống
- lợi nhuận gộp từng hóa đơn cộng lại có ra đúng tổng này không
- trả hàng có làm giảm lợi nhuận gộp đúng không
- hàng giá vốn = 0 hoặc thiếu giá vốn có bị méo chỉ tiêu không

---

## 3.6 Chi phí (6)
**Giá trị:** `1,368,413,588`

Đây là tổng của rất nhiều khoản chi phí chi tiết được liệt kê ở trang 1-3.

### Danh sách chi phí chi tiết trong báo cáo
- Chi phí voucher: `0`
- Phí trả ĐTGH: `930,480`
- Hoàn tiền cho khách: `0`
- Xuất hủy hàng hóa: `13,977,295`
- Giá trị thanh toán bằng điểm: `0`
- Chi phí điện: `3,712,694`
- Chi phí nước: `111,550`
- Bảo hành: `420,000`
- Bảo hiểm: `45,877,764`
- Đồ bọc hàng: `13,458,000`
- Đồ cửa hàng: `18,915,000`
- Đồ vệ sinh laptop: `22,784,000`
- Internet: `641,710`
- Kế toán: `5,000,000`
- Lãi ngân hàng: `175,293,588`
- Làm màn: `3,050,000`
- Làm web: `10,000,000`
- Liên hoan: `4,708,000`
- Lương Partime: `1,450,000`
- Mua đồ thắp hương: `80,000`
- Nạp tiền chợ tốt: `16,740,000`
- Phần mềm: `700,000`
- Phí giao hàng: `1,297,450`
- Quảng cáo: `114,401,097`
- Ship hàng: `5,827,000`
- Tiền ăn: `3,862,000`
- Tiền điện: `21,714,556`
- Tiền nước: `1,596,660`
- Tiền nhà: `149,000,000`
- Tiền sinh hoạt: `354,000`
- Thưởng doanh số: `3,350,000`
- Thưởng làm thêm: `200,000`
- Thưởng sinh nhật: `200,000`
- Thưởng tết: `12,900,000`
- Trích CTV: `5,000,000`
- Xốp bọc hàng: `1,080,000`
- Chiết khấu thanh toán cho khách: `1,050,000`
- Chi trả lương NV: `708,730,744`
- Chênh lệch làm tròn nhập hàng: `0`
- Chênh lệch làm tròn bán hàng: `0`

### Kiểm tra số học
Tổng cộng các khoản trên đúng bằng:

```text
1,368,413,588
```

=> Khớp với chỉ tiêu (6).

### Ý nghĩa rà soát
Báo cáo này đang coi các khoản trên là **chi phí hoạt động / chi phí kinh doanh** để trừ sau lợi nhuận gộp.

### Việc cần kiểm tra trong hệ thống
- mỗi phiếu chi / bút toán chi phí đang map vào nhóm nào
- có danh mục nhóm chi phí chuẩn chưa
- chi phí nào đang bị bỏ sót không lên báo cáo
- có khoản nào đáng ra là giảm trừ doanh thu nhưng đang hạch toán vào chi phí không
- có khoản nào đáng ra không phải chi phí kinh doanh nhưng vẫn bị trừ vào lợi nhuận không

---

## 3.7 Lợi nhuận từ hoạt động kinh doanh (7 = 5 - 6)
**Giá trị:** `881,372,660`

Báo cáo in công thức trực tiếp:

```text
(7) = (5) - (6)
```

### Kiểm tra số học
```text
2,249,786,248 - 1,368,413,588 = 881,372,660
```

=> Khớp đúng.

### Ý nghĩa rà soát
Chỉ tiêu này đang được tính như sau:

```text
Lợi nhuận từ hoạt động kinh doanh = Lợi nhuận gộp - Chi phí
```

---

## 3.8 Thu nhập khác (8)
**Giá trị:** `17,720,000`

Các khoản chi tiết đang được liệt kê dưới Thu nhập khác là:
- Phí trả hàng: `17,370,000`
- Chênh lệch làm tròn nhập hàng: `0`
- Chênh lệch làm tròn bán hàng: `0`
- Chiết khấu thanh toán từ NCC: `0`
- Phí mua máy: `350,000`

### Kiểm tra số học
```text
17,370,000 + 0 + 0 + 0 + 350,000 = 17,720,000
```

=> Khớp đúng.

### Ý nghĩa rà soát
Báo cáo này đang gom các khoản trên vào nhóm **Thu nhập khác**.

### Việc cần kiểm tra trong hệ thống
- “Phí trả hàng” và “Phí mua máy” đang được map vào đúng nhóm “thu nhập khác” chưa
- chiết khấu thanh toán từ NCC có đang được ghi nhận như khoản thu nhập khác hay giảm giá mua
- các khoản chênh lệch làm tròn có được xử lý nhất quán không

---

## 3.9 Chi phí khác (9)
**Giá trị:** `3,300,000`

### Ý nghĩa rà soát
Đây là nhóm chi phí ngoài phần chi phí hoạt động chính.

### Việc cần kiểm tra trong hệ thống
- các phiếu chi / bút toán nào đang vào nhóm này
- phân loại giữa “Chi phí” và “Chi phí khác” có rõ không
- có đang dồn sai khoản vào nhóm khác không

---

## 3.10 Lợi nhuận thuần (10 = (7 + 8) - 9)
**Giá trị:** `895,792,660`

Báo cáo in công thức trực tiếp:

```text
(10) = (7 + 8) - (9)
```

### Kiểm tra số học
```text
(881,372,660 + 17,720,000) - 3,300,000 = 895,792,660
```

=> Khớp đúng.

### Ý nghĩa rà soát
Lợi nhuận thuần trong file này đang tính theo:

```text
Lợi nhuận thuần = Lợi nhuận từ hoạt động kinh doanh + Thu nhập khác - Chi phí khác
```

---

## 4) Tóm tắt toàn bộ công thức đang thể hiện trong báo cáo

## 4.1 Công thức cấp 1
```text
(2)  = (2.1) + (2.2)
(3)  = (1) - (2)
(5)  = (3) - (4)
(7)  = (5) - (6)
(10) = (7 + 8) - (9)
```

## 4.2 Diễn giải đầy đủ
```text
Giảm trừ doanh thu
= Chiết khấu hóa đơn + Giá trị hàng bán bị trả lại

Doanh thu thuần
= Doanh thu bán hàng - Giảm trừ doanh thu

Lợi nhuận gộp về bán hàng
= Doanh thu thuần - Giá vốn hàng bán

Lợi nhuận từ hoạt động kinh doanh
= Lợi nhuận gộp về bán hàng - Chi phí

Lợi nhuận thuần
= Lợi nhuận từ hoạt động kinh doanh + Thu nhập khác - Chi phí khác
```

---

## 5) Checklist rà soát hệ thống của bạn theo từng chỉ tiêu

## 5.1 Doanh thu bán hàng
Phải kiểm:
- lấy từ hóa đơn hoàn thành hay chưa
- có loại hóa đơn nào bị tính sai không
- có cộng cả hóa đơn hủy không
- có tính cả phí giao hàng / dịch vụ không
- thời gian lấy theo ngày chứng từ hay ngày tạo

## 5.2 Chiết khấu hóa đơn
Phải kiểm:
- chỉ là discount ở header hay gồm cả discount dòng
- khi sửa hóa đơn có cập nhật lại không
- khi hủy hóa đơn có đảo đúng không

## 5.3 Giá trị hàng bán bị trả lại
Phải kiểm:
- lấy từ phiếu trả hàng nào
- chỉ lấy phiếu hoàn tất hay cả phiếu nháp
- hủy phiếu trả có đảo lại không
- trả hàng một phần có tính đúng không

## 5.4 Doanh thu thuần
Phải kiểm:
- có đúng bằng `Doanh thu bán hàng - Chiết khấu hóa đơn - Giá trị hàng bán bị trả lại` không
- có bị trừ thêm các khoản khác ngoài 2 khoản trên không

## 5.5 Giá vốn hàng bán
Phải kiểm:
- lấy nguồn giá vốn nào
- trả hàng có hoàn lại giá vốn không
- hóa đơn hủy có đảo giá vốn không
- hàng thiếu giá vốn xử lý thế nào

## 5.6 Chi phí
Phải kiểm:
- mọi phiếu chi đã map đúng nhóm chưa
- có khoản nào chưa lên báo cáo không
- có khoản nào bị map trùng nhóm không
- tổng danh sách chi tiết có luôn bằng tổng chỉ tiêu (6) không

## 5.7 Thu nhập khác
Phải kiểm:
- khoản nào được đưa vào thu nhập khác
- có khoản nào lẽ ra là giảm giá mua nhưng đang đẩy sang thu nhập khác không
- chi tiết cộng lại có đúng bằng chỉ tiêu (8) không

## 5.8 Chi phí khác
Phải kiểm:
- tiêu chí để vào nhóm “chi phí khác”
- có bị trùng với nhóm “chi phí” không
- tổng nhóm này có đối chiếu được về chứng từ gốc không

## 5.9 Lợi nhuận thuần
Phải kiểm:
- có đúng bằng `(Lợi nhuận hoạt động + Thu nhập khác - Chi phí khác)` không
- mọi báo cáo summary / dashboard / export có đang dùng cùng công thức này không

---

## 6) Bộ công thức nội bộ nên khóa lại trong hệ thống

Để tránh mỗi nơi tính một kiểu, nên tạo `metric dictionary` nội bộ như sau:

### metric: revenue_gross
- Tên hiển thị: Doanh thu bán hàng
- Công thức: tổng doanh thu hóa đơn hợp lệ
- Loại trừ: draft, canceled
- Scope thời gian: ngày chứng từ

### metric: revenue_deduction
- Tên hiển thị: Giảm trừ doanh thu
- Công thức: chiết khấu hóa đơn + giá trị hàng bán bị trả lại

### metric: revenue_net
- Tên hiển thị: Doanh thu thuần
- Công thức: revenue_gross - revenue_deduction

### metric: cogs
- Tên hiển thị: Giá vốn hàng bán
- Công thức: tổng giá vốn của hàng đã bán hợp lệ

### metric: gross_profit
- Tên hiển thị: Lợi nhuận gộp về bán hàng
- Công thức: revenue_net - cogs

### metric: operating_expense
- Tên hiển thị: Chi phí
- Công thức: tổng chi phí thuộc nhóm chi phí hoạt động

### metric: operating_profit
- Tên hiển thị: Lợi nhuận từ hoạt động kinh doanh
- Công thức: gross_profit - operating_expense

### metric: other_income
- Tên hiển thị: Thu nhập khác
- Công thức: tổng khoản thu nhập khác

### metric: other_expense
- Tên hiển thị: Chi phí khác
- Công thức: tổng khoản chi phí khác

### metric: net_profit
- Tên hiển thị: Lợi nhuận thuần
- Công thức: operating_profit + other_income - other_expense

---

## 7) Kế hoạch rà soát thực tế

## Bước 1
Lấy toàn bộ source đang tính báo cáo tài chính của hệ thống bạn.

## Bước 2
Map từng chỉ tiêu trên UI với:
- query
- service
- bảng dữ liệu
- điều kiện lọc
- trạng thái loại trừ

## Bước 3
So từng chỉ tiêu với file này:
- doanh thu bán hàng
- giảm trừ doanh thu
- doanh thu thuần
- giá vốn
- lợi nhuận gộp
- chi phí
- lợi nhuận hoạt động
- thu nhập khác
- chi phí khác
- lợi nhuận thuần

## Bước 4
Kiểm tra danh sách chi tiết của:
- Chi phí
- Thu nhập khác
- Chi phí khác

xem có cộng lại đúng tổng như báo cáo hay không.

## Bước 5
Kiểm tra drill-down:
- bấm vào chỉ tiêu phải truy được về chứng từ nguồn
- tổng list chi tiết phải đúng bằng số summary

## Bước 6
Khóa lại 1 metric service dùng chung cho mọi dashboard / export / API.

---

## 8) Kết luận

Từ file PDF này, các công thức **được thể hiện trực tiếp và chắc chắn** là:

```text
Giảm trừ doanh thu = Chiết khấu hóa đơn + Giá trị hàng bán bị trả lại
Doanh thu thuần = Doanh thu bán hàng - Giảm trừ doanh thu
Lợi nhuận gộp = Doanh thu thuần - Giá vốn hàng bán
Lợi nhuận từ hoạt động kinh doanh = Lợi nhuận gộp - Chi phí
Lợi nhuận thuần = Lợi nhuận từ hoạt động kinh doanh + Thu nhập khác - Chi phí khác
```

Đây là bộ công thức bạn nên dùng làm **chuẩn đối chiếu đầu tiên** khi rà lại hệ thống báo cáo tài chính của mình.
