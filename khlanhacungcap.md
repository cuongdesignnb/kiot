# Mô tả nghiệp vụ: Khách hàng đồng thời là nhà cung cấp

## 1. Mục tiêu nghiệp vụ
Một đối tác có thể đồng thời đóng 2 vai trò:
- **Khách hàng**: mua hàng của cửa hàng, phát sinh **công nợ phải thu**.
- **Nhà cung cấp**: bán hàng cho cửa hàng, phát sinh **công nợ phải trả**.

Mục tiêu của chức năng là cho phép **liên kết 2 vai trò này về cùng một đối tượng nghiệp vụ** để:
- tránh tạo trùng hồ sơ,
- theo dõi toàn bộ giao dịch mua/bán của cùng một đối tác,
- xem công nợ hai chiều trên cùng một màn hình,
- hỗ trợ **cấn bằng công nợ** giữa số phải thu và số phải trả.

---

## 2. Bản chất nghiệp vụ
Trong mô hình thông thường:
- Công nợ khách hàng được theo dõi riêng ở nhóm **nợ cần thu**.
- Công nợ nhà cung cấp được theo dõi riêng ở nhóm **nợ cần trả**.

Trong mô hình “khách hàng đồng thời là nhà cung cấp”:
- hệ thống vẫn cần giữ **2 loại giao dịch riêng biệt**,
- nhưng cho phép **liên kết chung về một đối tác**,
- và sinh ra một lớp tổng hợp để đối chiếu công nợ ròng.

Nói ngắn gọn:
- **Bán hàng cho đối tác** → tăng số tiền đối tác nợ cửa hàng.
- **Nhập hàng từ đối tác** → tăng số tiền cửa hàng nợ đối tác.
- **Thu tiền từ đối tác** → giảm nợ phải thu.
- **Chi tiền cho đối tác** → giảm nợ phải trả.
- **Cấn bằng công nợ** → bù trừ 2 chiều công nợ để ra số ròng còn phải thu hoặc còn phải trả.

---

## 3. Điều kiện áp dụng
Chức năng này chỉ áp dụng khi:
- cùng một pháp nhân/cá nhân thực sự là cùng một đối tượng giao dịch,
- doanh nghiệp muốn quản lý công nợ hai chiều trên cùng một hồ sơ,
- việc bù trừ công nợ giữa hai bên được chấp nhận theo quy trình nội bộ hoặc theo thỏa thuận thương mại.

Không nên gộp nếu:
- chỉ trùng tên nhưng là 2 đơn vị pháp lý khác nhau,
- cần hạch toán độc lập hoàn toàn giữa bên mua và bên bán,
- mỗi vai trò do các bộ phận khác nhau quản lý mà không được phép cấn trừ.

---

## 4. Luồng nghiệp vụ cốt lõi

### 4.1. Kích hoạt chức năng
Quản trị hệ thống bật tính năng cho phép một khách hàng có thể đồng thời là nhà cung cấp.

### 4.2. Liên kết hồ sơ
Khi tạo mới hoặc chỉnh sửa khách hàng, người dùng có thể:
- đánh dấu đối tượng này cũng là nhà cung cấp,
- chọn liên kết với nhà cung cấp đã tồn tại,
- hoặc tạo mới nhà cung cấp và liên kết ngay.

Sau khi liên kết:
- hệ thống phải hiểu đây là **một đối tác chung**,
- nhưng vẫn giữ lịch sử giao dịch bán hàng và nhập hàng theo đúng bản chất nghiệp vụ gốc.

### 4.3. Ghi nhận giao dịch
Các giao dịch phát sinh vẫn đi theo nghiệp vụ riêng:
- hóa đơn bán hàng,
- trả hàng bán,
- phiếu thu công nợ khách hàng,
- phiếu nhập hàng,
- trả hàng nhập,
- phiếu chi thanh toán nhà cung cấp,
- điều chỉnh tăng/giảm công nợ nếu có.

### 4.4. Tổng hợp công nợ
Tại màn hình công nợ của đối tác liên kết, hệ thống hiển thị:
- tổng nợ phải thu từ vai trò khách hàng,
- tổng nợ phải trả ở vai trò nhà cung cấp,
- số công nợ ròng sau khi đối chiếu,
- lịch sử cấn bằng công nợ nếu có.

---

## 5. Quy tắc công nợ

### 5.1. Công nợ phải thu
Là số tiền đối tác còn nợ cửa hàng từ các giao dịch bán hàng.

Tăng khi:
- bán hàng chưa thu đủ,
- điều chỉnh tăng nợ khách hàng.

Giảm khi:
- khách thanh toán,
- trả hàng bán,
- điều chỉnh giảm nợ khách hàng,
- thực hiện cấn bằng sang công nợ phải trả.

### 5.2. Công nợ phải trả
Là số tiền cửa hàng còn nợ đối tác từ các giao dịch nhập hàng.

Tăng khi:
- nhập hàng chưa thanh toán đủ,
- điều chỉnh tăng nợ nhà cung cấp.

Giảm khi:
- chi tiền thanh toán,
- trả hàng nhập,
- chiết khấu/giảm trừ công nợ nhà cung cấp,
- thực hiện cấn bằng sang công nợ phải thu.

---

## 6. Nghiệp vụ cấn bằng công nợ

### 6.1. Khái niệm
**Cấn bằng công nợ** là việc bù trừ giữa:
- khoản **đối tác đang nợ cửa hàng**,
- và khoản **cửa hàng đang nợ chính đối tác đó**.

Sau khi cấn bằng, hệ thống không làm mất lịch sử gốc mà chỉ tạo thêm một giao dịch bù trừ để giảm đồng thời hai bên công nợ.

### 6.2. Điều kiện để cấn bằng
Chỉ cho cấn bằng khi:
- khách hàng và nhà cung cấp đã được liên kết thành cùng một đối tác,
- cả hai bên đều đang có số dư công nợ,
- số tiền cấn bằng lớn hơn 0,
- có người dùng đủ quyền thực hiện nghiệp vụ này.

### 6.3. Nguyên tắc cấn bằng
Số tiền cấn bằng hợp lệ = **min(nợ phải thu, nợ phải trả)** hoặc nhỏ hơn nếu người dùng nhập tay.

Sau khi cấn bằng:
- **nợ phải thu giảm** đúng bằng số cấn,
- **nợ phải trả giảm** đúng bằng số cấn,
- hệ thống lưu lại chứng từ cấn bằng để tra soát.

### 6.4. Kết quả sau cấn bằng
Có 3 trường hợp:

#### Trường hợp 1: Nợ phải thu lớn hơn nợ phải trả
Ví dụ:
- phải thu: 20.000.000
- phải trả: 12.000.000
- cấn bằng: 12.000.000

Kết quả:
- phải thu còn: 8.000.000
- phải trả còn: 0
- đối tác vẫn là **người còn nợ cửa hàng**.

#### Trường hợp 2: Nợ phải trả lớn hơn nợ phải thu
Ví dụ:
- phải thu: 15.000.000
- phải trả: 22.000.000
- cấn bằng: 15.000.000

Kết quả:
- phải thu còn: 0
- phải trả còn: 7.000.000
- cửa hàng vẫn là **bên còn nợ đối tác**.

#### Trường hợp 3: Hai bên bằng nhau
Ví dụ:
- phải thu: 10.000.000
- phải trả: 10.000.000
- cấn bằng: 10.000.000

Kết quả:
- phải thu còn: 0
- phải trả còn: 0
- công nợ hai chiều được tất toán bằng bù trừ.

### 6.5. Yêu cầu kiểm soát
Mỗi giao dịch cấn bằng nên có:
- mã chứng từ,
- thời gian,
- người thực hiện,
- số tiền cấn,
- ghi chú lý do,
- tham chiếu tới các khoản công nợ liên quan.

Không nên cho phép xóa cứng chứng từ cấn bằng sau khi đã khóa sổ; thay vào đó nên dùng cơ chế hủy hoặc tạo bút toán đảo.

---

## 7. Cách hiển thị nghiệp vụ trên giao diện
Màn hình chi tiết đối tác nên có các khối thông tin sau:

### 7.1. Thông tin chung
- mã đối tác,
- tên đối tác,
- số điện thoại,
- mã số thuế,
- trạng thái liên kết khách hàng/nhà cung cấp.

### 7.2. Công nợ hai chiều
- nợ phải thu,
- nợ phải trả,
- công nợ ròng,
- trạng thái hiện tại: còn phải thu / còn phải trả / đã cân bằng.

### 7.3. Lịch sử giao dịch
- lịch sử bán hàng,
- lịch sử nhập hàng,
- lịch sử thanh toán thu,
- lịch sử thanh toán chi,
- lịch sử điều chỉnh,
- lịch sử cấn bằng.

---

## 8. Quy tắc tính công nợ ròng
Có thể dùng quy ước:

**Công nợ ròng = Nợ phải thu - Nợ phải trả**

Diễn giải:
- nếu công nợ ròng > 0 → đối tác còn nợ cửa hàng,
- nếu công nợ ròng < 0 → cửa hàng còn nợ đối tác,
- nếu công nợ ròng = 0 → hai bên đang cân bằng.

Lưu ý:
- công nợ ròng chỉ là **chỉ số tổng hợp để nhìn nhanh**,
- còn chi tiết vận hành vẫn phải giữ riêng 2 sổ: phải thu và phải trả.

---

## 9. Các tình huống nghiệp vụ cần hỗ trợ

### 9.1. Liên kết mới
Một khách hàng cũ bắt đầu trở thành nhà cung cấp. Hệ thống cho phép tạo liên kết mà không làm mất dữ liệu giao dịch cũ.

### 9.2. Gộp với hồ sơ đã tồn tại
Đã có sẵn cả hồ sơ khách hàng và hồ sơ nhà cung cấp. Hệ thống cho phép xác nhận gộp để tránh trùng dữ liệu.

### 9.3. Không cho liên kết sai đối tượng
Nếu thông tin pháp lý không khớp hoặc người dùng chọn nhầm hồ sơ, hệ thống phải cảnh báo trước khi gộp.

### 9.4. Cấn bằng một phần
Người dùng có thể cấn một phần thay vì cấn toàn bộ số nhỏ hơn.

### 9.5. Hủy cấn bằng
Nếu thao tác cấn bằng sai, hệ thống phải có nghiệp vụ đảo cấn để trả số dư về trạng thái trước đó, đồng thời lưu vết đầy đủ.

### 9.6. Đối soát cuối kỳ
Kế toán có thể lọc riêng:
- tổng phải thu,
- tổng phải trả,
- các chứng từ đã cấn bằng,
- số ròng còn lại theo từng đối tác.

---

## 10. Kết luận nghiệp vụ
Chức năng “khách hàng đồng thời là nhà cung cấp” thực chất là bài toán:
- **quản lý một đối tác có hai vai trò**,
- **giữ riêng giao dịch theo từng vai trò**,
- **tổng hợp công nợ hai chiều để đối chiếu**,
- và **hỗ trợ cấn bằng công nợ** khi đủ điều kiện.

Nếu bạn lập trình lại, hãy coi đây là 3 lớp nghiệp vụ:
1. **Hồ sơ đối tác**: định danh một thực thể chung.
2. **Vai trò giao dịch**: khách hàng / nhà cung cấp.
3. **Sổ công nợ tổng hợp**: dùng để xem nhanh, đối chiếu và cấn bằng.

---

## 11. Ghi chú nguồn tham chiếu
Phần mô tả trên bám theo hướng dẫn công khai của KiotViet về:
- quản lý khách hàng,
- quản lý nhà cung cấp,
- quản lý công nợ,
- và tính năng “quản lý khách hàng đồng thời là nhà cung cấp”.

Riêng phần **cấn bằng công nợ**, tài liệu KiotViet công khai thể hiện ý “gộp công nợ” và “tính toán tổng hợp”; phần quy tắc cấn bằng chi tiết trong tài liệu này là mô hình hóa nghiệp vụ để phục vụ việc bạn lập trình lại.
