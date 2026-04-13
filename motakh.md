# Đặc tả nghiệp vụ: Khách hàng đồng thời là nhà cung cấp

## 1. Mục tiêu chức năng

Chức năng này dùng cho **một đối tượng có thể đồng thời đóng 2 vai trò**:

- **Khách hàng**: mua hàng của cửa hàng/doanh nghiệp
- **Nhà cung cấp**: bán hàng cho cửa hàng/doanh nghiệp

Thay vì quản lý tách biệt hoàn toàn 2 sổ công nợ, hệ thống sẽ cho phép **bù trừ công nợ hai chiều** để tính ra **công nợ thuần cuối cùng**.

---

## 2. Bản chất nghiệp vụ

Một đối tượng có thể phát sinh đồng thời:

- **Khoản phải thu**: đối tượng đang nợ doanh nghiệp
- **Khoản phải trả**: doanh nghiệp đang nợ đối tượng

Hệ thống sẽ không chỉ nhìn theo từng vai trò riêng lẻ, mà sẽ tính:

> **Công nợ thuần = Phải trả - Phải thu**

Từ đó xác định:

- nếu kết quả **dương**: doanh nghiệp còn phải trả đối tượng
- nếu kết quả **âm**: đối tượng còn phải trả doanh nghiệp
- nếu bằng **0**: hai bên đã cân bằng công nợ

---

## 3. Quy ước hiển thị theo từng vai trò

### 3.1. Ở màn hình Khách hàng

Màn hình khách hàng hiển thị theo tư duy **doanh nghiệp đang theo dõi khoản phải thu từ khách**.

Quy ước dấu:

- **Số dương**: khách đang nợ doanh nghiệp
- **Số âm**: doanh nghiệp đang nợ lại khách

Ví dụ:

- `2.000.000` -> khách còn nợ doanh nghiệp 2 triệu
- `-3.000.000` -> doanh nghiệp đang nợ khách 3 triệu

### 3.2. Ở màn hình Nhà cung cấp

Màn hình nhà cung cấp hiển thị theo tư duy **doanh nghiệp đang theo dõi khoản phải trả cho nhà cung cấp**.

Quy ước dấu:

- **Số dương**: doanh nghiệp đang nợ nhà cung cấp
- **Số âm**: nhà cung cấp đang nợ lại doanh nghiệp

Ví dụ:

- `3.000.000` -> doanh nghiệp đang nợ nhà cung cấp 3 triệu
- `-2.000.000` -> nhà cung cấp đang nợ lại doanh nghiệp 2 triệu

### 3.3. Quan hệ giữa hai màn hình

Khi cùng một đối tượng vừa là khách hàng vừa là nhà cung cấp:

> **Số dư công nợ ở màn hình khách hàng = âm của số dư công nợ ở màn hình nhà cung cấp**

Tức là:

- Khách hàng: `-3.000.000`
- Nhà cung cấp: `3.000.000`

Hai số này thực chất là **cùng một bản chất công nợ**, chỉ khác góc nhìn hiển thị.

---

## 4. Ánh xạ nghiệp vụ từng loại chứng từ

Để lập trình lại chức năng, cần chuẩn hóa cách mỗi loại giao dịch làm tăng/giảm công nợ.

### 4.1. Theo góc nhìn màn hình Khách hàng

| Loại chứng từ | Tác động công nợ | Giải thích |
|---|---:|---|
| Bán hàng | `+` | Khách mua hàng => khách nợ doanh nghiệp tăng |
| Thu tiền / Thanh toán từ khách | `-` | Khách trả tiền => giảm khoản phải thu |
| Nhập hàng từ chính đối tượng này | `-` | Doanh nghiệp mua của họ => phát sinh khoản phải trả, làm công nợ khách hàng chuyển âm |
| Trả hàng bán / giảm doanh thu | `-` | Giảm số tiền khách phải trả |
| Phiếu điều chỉnh tăng phải thu | `+` | Tăng khoản khách nợ |
| Phiếu điều chỉnh giảm phải thu | `-` | Giảm khoản khách nợ |

### 4.2. Theo góc nhìn màn hình Nhà cung cấp

| Loại chứng từ | Tác động công nợ | Giải thích |
|---|---:|---|
| Nhập hàng | `+` | Doanh nghiệp nhận hàng => tăng khoản phải trả NCC |
| Thanh toán cho NCC | `-` | Doanh nghiệp trả tiền => giảm khoản phải trả |
| Bán hàng cho chính đối tượng này | `-` | Đối tượng trở thành khách hàng => phát sinh khoản phải thu, làm giảm công nợ phải trả |
| Trả hàng nhập / giảm mua | `-` | Giảm nghĩa vụ phải trả NCC |
| Phiếu điều chỉnh tăng phải trả | `+` | Tăng số nợ phải trả |
| Phiếu điều chỉnh giảm phải trả | `-` | Giảm số nợ phải trả |

---

## 5. Công thức chuẩn

### 5.1. Công thức tổng quát

Đặt:

- `customer_receivable`: tổng phải thu từ vai trò khách hàng
- `supplier_payable`: tổng phải trả ở vai trò nhà cung cấp

Khi đó:

```text
net_balance = supplier_payable - customer_receivable
```

Ý nghĩa:

- `net_balance > 0`: doanh nghiệp đang nợ đối tượng
- `net_balance < 0`: đối tượng đang nợ doanh nghiệp
- `net_balance = 0`: đã cân bằng

### 5.2. Hiển thị ra từng màn hình

```text
supplier_screen_balance = net_balance
customer_screen_balance = -net_balance
```

Hoặc viết trực tiếp:

```text
customer_screen_balance = customer_receivable - supplier_payable
supplier_screen_balance = supplier_payable - customer_receivable
```

---

## 6. Phân tích đúng case trong ảnh

Đối tượng: `KH002785 / Test`

Phát sinh giao dịch:

1. **Nhập hàng**: `5.000.000`
2. **Bán hàng**: `7.000.000`
3. **Thanh toán**: `5.000.000`
4. **Điều chỉnh**: `0`

### 6.1. Tính tách riêng theo 2 vai trò

#### Vai trò khách hàng

- Bán hàng: `7.000.000`
- Đã thanh toán: `5.000.000`

=> **Khoản phải thu còn lại = 2.000.000**

#### Vai trò nhà cung cấp

- Nhập hàng: `5.000.000`

=> **Khoản phải trả còn lại = 5.000.000**

### 6.2. Bù trừ công nợ

```text
net_balance = 5.000.000 - 2.000.000 = 3.000.000
```

=> Doanh nghiệp **còn phải trả đối tượng 3.000.000**

### 6.3. Suy ra hiển thị

- Ở màn hình **Nhà cung cấp**: `3.000.000`
- Ở màn hình **Khách hàng**: `-3.000.000`

=> Hai số hoàn toàn khớp logic.

---

## 7. Mô phỏng diễn biến từng dòng như ảnh

### 7.1. Theo màn hình Khách hàng

Bắt đầu từ `0`

1. **Điều chỉnh**: `0`
   - Số dư: `0`

2. **Nhập hàng 5.000.000**
   - Đây là nghiệp vụ làm phát sinh khoản doanh nghiệp phải trả đối tượng
   - Số dư khách hàng: `0 - 5.000.000 = -5.000.000`

3. **Bán hàng 7.000.000**
   - Phát sinh khoản phải thu từ khách
   - Số dư khách hàng: `-5.000.000 + 7.000.000 = 2.000.000`

4. **Thanh toán 5.000.000**
   - Khách trả thêm tiền cho giao dịch bán
   - Số dư khách hàng: `2.000.000 - 5.000.000 = -3.000.000`

Kết quả cuối cùng:

```text
Nợ hiện tại = -3.000.000
```

Ý nghĩa: **doanh nghiệp đang nợ lại khách 3 triệu**.

### 7.2. Theo màn hình Nhà cung cấp

Bắt đầu từ `0`

1. **Điều chỉnh**: `0`
   - Số dư: `0`

2. **Nhập hàng 5.000.000**
   - Tăng khoản phải trả NCC
   - Số dư NCC: `0 + 5.000.000 = 5.000.000`

3. **Bán hàng 7.000.000**
   - Phát sinh khoản phải thu từ chính đối tượng này
   - Số dư NCC: `5.000.000 - 7.000.000 = -2.000.000`

4. **Thanh toán 5.000.000**
   - Theo ảnh đang được phản ánh làm số dư NCC tăng về `3.000.000`
   - Số dư NCC: `-2.000.000 + 5.000.000 = 3.000.000`

Kết quả cuối cùng:

```text
Nợ cần trả = 3.000.000
```

Ý nghĩa: **doanh nghiệp đang nợ nhà cung cấp 3 triệu**.

---

## 8. Mô hình dữ liệu gợi ý khi lập trình lại

Không nên chỉ lưu một số dư cuối cùng. Nên tách rõ dữ liệu nền để dễ kiểm soát.

## 8.1. Bảng đối tượng

```text
partners
- id
- code
- name
- phone
- email
- is_customer
- is_supplier
- ...
```

## 8.2. Bảng giao dịch công nợ thống nhất

```text
partner_ledger_entries
- id
- partner_id
- document_type
- document_code
- document_date
- amount
- balance_effect_customer
- balance_effect_supplier
- reference_id
- note
- created_at
- updated_at
```

Trong đó:

- `balance_effect_customer`: giá trị cộng/trừ vào số dư màn hình khách hàng
- `balance_effect_supplier`: giá trị cộng/trừ vào số dư màn hình nhà cung cấp

Ví dụ:

| document_type | amount | balance_effect_customer | balance_effect_supplier |
|---|---:|---:|---:|
| sales_invoice | 7.000.000 | +7.000.000 | -7.000.000 |
| purchase_invoice | 5.000.000 | -5.000.000 | +5.000.000 |
| customer_payment | 5.000.000 | -5.000.000 | +5.000.000 |
| supplier_payment | 5.000.000 | +5.000.000 | -5.000.000 |
| adjustment_up_receivable | 1.000.000 | +1.000.000 | -1.000.000 |
| adjustment_up_payable | 1.000.000 | -1.000.000 | +1.000.000 |
```

---

## 9. Quy tắc lập trình khuyến nghị

## 9.1. Nguyên tắc cốt lõi

Luôn đảm bảo:

```text
customer_balance = -supplier_balance
```

Nếu không đảm bảo được điều này, dữ liệu đang lệch logic bù trừ.

## 9.2. Không lưu số dư bằng cách nhập tay nhiều nơi

Nên:

- lưu **ledger entries** cho từng giao dịch
- cộng dồn từ lịch sử để ra số dư
- hoặc nếu cần tối ưu, lưu thêm bảng snapshot nhưng vẫn phải có ledger gốc để đối soát

## 9.3. Mỗi chứng từ phải định nghĩa rõ tác động lên 2 chiều

Ví dụ:

```text
sales_invoice:
  customer_effect = +amount
  supplier_effect = -amount

purchase_invoice:
  customer_effect = -amount
  supplier_effect = +amount
```

Không nên xử lý cảm tính theo từng màn hình riêng rẽ vì rất dễ lệch dấu.

---

## 10. Pseudocode tính số dư

```pseudo
function calculatePartnerBalance(partnerId):
    entries = getLedgerEntriesByPartner(partnerId)

    customerBalance = 0
    supplierBalance = 0

    for entry in entries:
        customerBalance += entry.balance_effect_customer
        supplierBalance += entry.balance_effect_supplier

    return {
        customer_balance: customerBalance,
        supplier_balance: supplierBalance,
        net_balance: supplierBalance,
    }
```

Hoặc nếu chỉ lưu phải thu và phải trả riêng:

```pseudo
function calculatePartnerBalance(partnerId):
    receivable = sumCustomerReceivable(partnerId)
    payable = sumSupplierPayable(partnerId)

    supplierBalance = payable - receivable
    customerBalance = receivable - payable

    return {
        receivable: receivable,
        payable: payable,
        customer_balance: customerBalance,
        supplier_balance: supplierBalance,
    }
```

---

## 11. Các case biên cần xử lý

### Case 1: Chỉ là khách hàng, không có giao dịch NCC

- `supplier_payable = 0`
- `customer_balance = receivable`
- `supplier_balance = -receivable`

Trong UI nhà cung cấp có thể ẩn vai trò hoặc không cho hiển thị nếu đối tượng không bật vai trò NCC.

### Case 2: Chỉ là nhà cung cấp, không có giao dịch khách hàng

- `customer_receivable = 0`
- `supplier_balance = payable`
- `customer_balance = -payable`

Trong UI khách hàng có thể ẩn vai trò hoặc không cho hiển thị nếu chưa bật vai trò khách hàng.

### Case 3: Thanh toán vượt quá phần phải thu

Ví dụ:

- bán hàng: `7.000.000`
- khách trả: `10.000.000`

=> phải thu bị âm `-3.000.000`

Ý nghĩa: doanh nghiệp đang nợ lại khách 3 triệu hoặc giữ tiền dư của khách.

### Case 4: Bù trừ hoàn toàn

- phải thu: `5.000.000`
- phải trả: `5.000.000`

=> số dư thuần `0`

### Case 5: Có trả hàng / hoàn hàng

Cần tách rõ:

- trả hàng bán -> giảm phải thu
- trả hàng nhập -> giảm phải trả

### Case 6: Có phiếu điều chỉnh

Phiếu điều chỉnh phải chỉ rõ:

- điều chỉnh tăng/giảm phải thu
- điều chỉnh tăng/giảm phải trả

Không nên chỉ lưu một con số adjustment chung chung.

---

## 12. Đề xuất UI/UX để người dùng dễ hiểu hơn

Vì kiểu bù trừ này rất dễ gây nhầm, nên UI nên hiển thị đồng thời 3 lớp thông tin:

### 12.1. Lớp tổng quan

- **Phải thu khách hàng**
- **Phải trả nhà cung cấp**
- **Công nợ thuần sau bù trừ**

Ví dụ:

```text
Phải thu khách hàng: 2.000.000
Phải trả nhà cung cấp: 5.000.000
Công nợ thuần: phải trả 3.000.000
```

### 12.2. Lớp lịch sử giao dịch

Nên có cột:

- Mã phiếu
- Thời gian
- Loại chứng từ
- Giá trị chứng từ
- Ảnh hưởng công nợ khách hàng
- Ảnh hưởng công nợ nhà cung cấp
- Số dư sau giao dịch

### 12.3. Lớp diễn giải bằng chữ

Ví dụ:

- `Nợ hiện tại: -3.000.000` -> kèm chú thích: **Doanh nghiệp đang nợ đối tượng 3.000.000**
- `Nợ cần trả: 3.000.000` -> kèm chú thích: **Sau bù trừ, doanh nghiệp còn phải trả 3.000.000**

---

## 13. Rủi ro nếu lập trình không chặt

1. **Lệch dấu giữa màn khách hàng và nhà cung cấp**
2. **Thanh toán bị ghi sai chiều**
3. **Một chứng từ tác động 1 chiều nhưng không phản ánh chiều đối ứng**
4. **Số dư tổng hợp khác số dư chi tiết**
5. **Không phân biệt thanh toán cho khách hay thanh toán cho NCC**
6. **Case trả dư / ứng trước không được xử lý âm đúng cách**
7. **Không có ledger chi tiết để kiểm tra lại khi sai số**

---

## 14. Bộ rule thực thi đề xuất

Có thể chuẩn hóa rule engine như sau:

```text
Rule 1:
Nếu phát sinh bán hàng cho partner
=> customer += amount
=> supplier -= amount

Rule 2:
Nếu partner thanh toán tiền mua hàng cho doanh nghiệp
=> customer -= amount
=> supplier += amount

Rule 3:
Nếu phát sinh nhập hàng từ partner
=> customer -= amount
=> supplier += amount

Rule 4:
Nếu doanh nghiệp thanh toán tiền nhập hàng cho partner
=> customer += amount
=> supplier -= amount

Rule 5:
Luôn kiểm tra customer_balance + supplier_balance = 0
```

Lưu ý: rule thanh toán phải phân biệt rõ bản chất nghiệp vụ. Nếu một màn dùng từ “thanh toán” nhưng thực tế là thu tiền từ khách, cần map chính xác vào engine.

---

## 15. Kết luận nghiệp vụ

Trong chức năng **khách hàng đồng thời là nhà cung cấp**, hệ thống đang hoạt động theo nguyên tắc:

> **Gộp toàn bộ giao dịch mua, bán, thanh toán, điều chỉnh của cùng một đối tượng vào một quan hệ công nợ hai chiều, sau đó bù trừ để ra công nợ thuần.**

Nói dễ hiểu hơn:

- nếu doanh nghiệp bán cho họ thì họ nợ doanh nghiệp
- nếu doanh nghiệp mua từ họ thì doanh nghiệp nợ họ
- cuối cùng không nhìn tách rời từng vế mà **cấn trừ hai vế với nhau**

Vì vậy:

- màn **Khách hàng** và màn **Nhà cung cấp** sẽ luôn là 2 cách nhìn của cùng một số dư
- chỉ khác nhau ở **quy ước dấu và ngôn ngữ hiển thị**

---

## 16. Tóm tắt ngắn cho dev

```text
customer_balance = total_sales - total_customer_payments - total_purchases + other_customer_adjustments
supplier_balance = -customer_balance
```

Hoặc chuẩn hơn:

```text
receivable = tổng phải thu từ vai trò khách hàng
payable = tổng phải trả từ vai trò nhà cung cấp

customer_balance = receivable - payable
supplier_balance = payable - receivable
```

Case ảnh mẫu:

```text
receivable = 7.000.000 - 5.000.000 = 2.000.000
payable = 5.000.000

customer_balance = 2.000.000 - 5.000.000 = -3.000.000
supplier_balance = 5.000.000 - 2.000.000 = 3.000.000
```

=> Kết quả khớp đúng với ảnh.
