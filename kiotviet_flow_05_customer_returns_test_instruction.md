# KiotViet Flow 05 — Trả hàng bán / Đổi trả hàng

## Mục tiêu
Luồng này dùng để kiểm tra hệ thống có vận hành đúng **trả hàng bán sau khi đã phát sinh hóa đơn bán hàng** theo hành vi tham chiếu của KiotViet hay không.

Agent phải kiểm tra đồng thời 4 lớp:
1. **Luồng nghiệp vụ trên UI/API** có đi đúng trình tự không.
2. **Tồn kho** có cộng lại đúng khi trả hàng hay không.
3. **Tiền hoàn / tiền thu thêm / phí trả hàng / công nợ** có cập nhật đúng không.
4. **Quản lý phiếu trả hàng** có cho xem, cập nhật, hủy và sao chép đúng hành vi hay không.

Luồng này chỉ tập trung vào **trả hàng bán**. Không mở rộng sang trả hàng nhập, hủy hóa đơn bán, công nợ nhà cung cấp hay báo cáo tổng hợp toàn hệ thống nếu chưa thật sự cần cho case hiện tại.

---

## Nguồn chuẩn tham chiếu của KiotViet
Agent phải coi các hành vi sau là chuẩn tham chiếu:

1. Tính năng Trả hàng giúp cập nhật lại **tồn kho** và **dòng tiền** khi khách trả lại sản phẩm.
2. Có thể **trả hàng theo hóa đơn gốc** bằng cách tìm hóa đơn cũ, nhập số lượng trả, thanh toán và hoàn tất.
3. Có thể nhập **Phí trả hàng**; hệ thống tự trừ phí này vào số tiền cần hoàn cho khách.
4. Có thể **trả hàng nhanh** khi không có hóa đơn gốc.
5. Có thể **đổi trả hàng** trong cùng một giao dịch; hệ thống đối trừ giữa hàng trả và hàng đổi rồi xử lý phần chênh lệch.
6. Trong quản lý phiếu trả hàng có thể **xem danh sách**, **cập nhật một số thông tin**, **hủy phiếu** và **sao chép**; khi hủy phiếu trả hàng thì hệ thống **cộng lại tồn kho và cập nhật lại công nợ**.
7. Nếu đơn giao vận chuyển bị **chuyển hoàn**, KiotViet tự tạo giao dịch trả hàng, cộng lại tồn kho và phiếu trả hàng tự động đó **không thể hủy**.

Agent không được tự bịa hành vi khác với các điểm trên nếu chưa chứng minh được bằng source hoặc tài liệu.

---

## Phạm vi kiểm tra của Flow 05
Luồng này gồm các nhánh sau:

- 05A. Trả hàng theo hóa đơn gốc.
- 05B. Trả hàng theo hóa đơn có **phí trả hàng**.
- 05C. Trả hàng một phần hóa đơn.
- 05D. Trả hàng nhanh (không có hóa đơn gốc).
- 05E. Đổi trả hàng trong cùng một giao dịch.
- 05F. Quản lý phiếu trả hàng: xem, cập nhật thông tin, sao chép.
- 05G. Hủy phiếu trả hàng và đối soát rollback.
- 05H. Xử lý đơn giao bị chuyển hoàn nếu source có hỗ trợ.

Nếu source chưa hỗ trợ một nhánh, agent phải đánh dấu rõ là **Missing Feature** hoặc **Not Implemented**, không được đánh đồng với bug logic.

---

## Bộ dữ liệu test cố định
Agent phải dùng bộ dữ liệu test cố định để bảo đảm có thể lặp lại.

### Khách hàng
- `KH001` — Nguyễn Văn A — SĐT `0900000001`

### Kho
- `KHO_TONG`

### Hàng hóa
- `SP001` — Nước suối 500ml — Giá bán 7.000 — Giá vốn 5.000
- `SP002` — Bánh quy hộp — Giá bán 30.000 — Giá vốn 20.000
- `SP003` — Sữa hộp — Giá bán 80.000 — Giá vốn 60.000

### Tồn kho ban đầu tối thiểu
- `SP001`: 100
- `SP002`: 50
- `SP003`: 30

### Hóa đơn gốc mẫu cần tạo trước
Agent phải tạo ít nhất 2 hóa đơn bán gốc cho khách `KH001`:

#### Invoice S1
- Mã tham chiếu nội bộ: `S1`
- Ngày giao dịch: T1
- Khách: `KH001`
- Hàng:
  - `SP001` x 10 = 70.000
  - `SP002` x 2 = 60.000
- Tổng tiền hàng: **130.000**
- Khách trả: **130.000**
- Công nợ còn lại: **0**

#### Invoice S2
- Mã tham chiếu nội bộ: `S2`
- Ngày giao dịch: T2 (sau S1)
- Khách: `KH001`
- Hàng:
  - `SP003` x 2 = 160.000
- Tổng tiền hàng: **160.000**
- Khách trả: **100.000**
- Công nợ còn lại: **60.000**

### Kỳ vọng tồn kho sau khi tạo hóa đơn gốc
- `SP001`: 90
- `SP002`: 48
- `SP003`: 28

Agent có thể tạo các hóa đơn này qua UI, API hoặc seed script, nhưng phải ghi rõ cách tạo.

---

## Chuẩn đối soát bắt buộc
Sau mỗi thao tác, agent phải đối chiếu ít nhất các điểm sau:

1. Tồn kho của từng SKU liên quan.
2. Số lượng đã bán / đã trả trên hóa đơn gốc nếu hệ thống theo dõi mức dòng.
3. Số tiền hoàn cho khách hoặc tiền thu thêm từ khách.
4. Công nợ khách hàng trước và sau trả hàng.
5. Phiếu trả hàng / payment / ledger / allocation liên quan có được tạo đúng không.
6. Nếu có bảng stock movements, return_items, debt ledgers, payment vouchers thì phải kiểm trực tiếp ở database.

Nếu hệ thống dùng ledger hoặc allocation table riêng, agent phải truy ra bản ghi thật thay vì chỉ nhìn UI.

---

## Quy tắc sửa lỗi
Agent chỉ được sửa khi thỏa đủ điều kiện sau:

1. Đã tái hiện được lỗi bằng test case cụ thể trong Flow 05.
2. Đã xác định được điểm sai nằm ở source hiện tại.
3. Sửa theo hướng **ít thay đổi nhất** để khôi phục hành vi đúng luồng.
4. Sau khi sửa phải chạy lại toàn bộ case liên quan trong Flow 05.

Agent không được:
- đổi cấu trúc lớn ngoài phạm vi Flow 05 nếu chưa thật sự cần;
- refactor diện rộng;
- sửa sang flow nhập hàng / công nợ NCC nếu Flow 05 không gọi tới;
- ghi đè dữ liệu test mà không giải thích.

---

## Trình tự bắt buộc agent phải thực hiện

### Bước 1 — Đọc source
Agent phải đọc và ghi lại:
- model/entity của Sale/Invoice, Return/ReturnItem, Customer, StockMovement, Payment/Receipt, Receivable Ledger
- service/use-case xử lý trả hàng và đổi trả
- routing/controller/UI màn hình trả hàng
- cơ chế hoàn tiền, phí trả hàng, thu thêm tiền, cập nhật công nợ
- cơ chế hủy phiếu trả hàng
- cơ chế tạo phiếu trả hàng tự động từ đơn chuyển hoàn nếu có

### Bước 2 — Xác định nguồn sự thật
Agent phải xác định rõ hệ thống lấy số liệu trả hàng từ đâu:
- bảng returns riêng?
- cờ/field trên invoice items?
- stock movements?
- debt ledgers?
- payment/refund vouchers?

Nếu không xác định được nguồn sự thật thì phải ghi rõ đây là rủi ro kiến trúc.

### Bước 3 — Khởi tạo dữ liệu test
Tạo 2 hóa đơn gốc S1 và S2 như trên.

### Bước 4 — Chạy từng test case 05A → 05H
Mỗi case phải ghi rõ:
- bước thao tác;
- dữ liệu trước khi chạy;
- kết quả thực tế;
- expected result;
- pass/fail.

### Bước 5 — Nếu có lỗi, sửa tối thiểu
Mỗi lỗi phải có:
- cách tái hiện;
- nguyên nhân;
- file sửa;
- diff chính;
- vì sao fix này bám Flow 05.

### Bước 6 — Re-test
Sau khi fix phải chạy lại:
- toàn bộ case thất bại;
- toàn bộ case liên quan trực tiếp;
- ít nhất 1 case hồi quy của Flow 03 và Flow 04 nếu fix đụng invoice/payment/debt logic.

---

## Test cases chi tiết

## 05A — Trả hàng theo hóa đơn gốc
### Mục đích
Xác minh hệ thống cho phép tìm hóa đơn bán cũ, chọn hàng trả, hoàn tất phiếu trả, cộng lại tồn kho và ghi nhận hoàn tiền đúng.

### Dữ liệu đầu vào
Dùng hóa đơn `S1`.
Trả lại:
- `SP001` x 2

### Bước thực hiện
1. Vào màn hình Trả hàng.
2. Tìm hóa đơn gốc `S1` theo mã hóa đơn, tên khách hoặc SĐT.
3. Chọn hóa đơn gốc.
4. Chọn dòng `SP001`, nhập số lượng trả = 2.
5. Chuyển sang bước thanh toán / xác nhận.
6. Chọn phương thức hoàn tiền mặc định nếu source có.
7. Hoàn tất phiếu trả hàng.

### Kỳ vọng
- Tạo được **phiếu trả hàng** mới, liên kết với `S1`.
- Tồn kho `SP001` tăng từ 90 lên **92**.
- Giá trị hàng trả = 2 x 7.000 = **14.000**.
- Nếu S1 đã thanh toán đủ thì số tiền hoàn cho khách = **14.000** trừ các khoản điều chỉnh khác nếu có.
- Công nợ KH001 không tăng thêm theo hướng bất thường.
- Lịch sử giao dịch khách hàng có thêm 1 giao dịch trả hàng.

### Fail điển hình
- Không tìm thấy hóa đơn gốc từ màn hình trả hàng.
- Trả hàng xong nhưng không cộng lại tồn kho.
- Tạo phiếu trả nhưng không liên kết với hóa đơn gốc.
- Hoàn tiền sai số.

---

## 05B — Trả hàng theo hóa đơn có phí trả hàng
### Mục đích
Xác minh hệ thống xử lý đúng **phí trả hàng** và trừ phí này khỏi khoản tiền hoàn cho khách.

### Dữ liệu đầu vào
Dùng hóa đơn `S1`.
Trả lại:
- `SP002` x 1 = 30.000
Phí trả hàng:
- **5.000**

### Bước thực hiện
1. Tạo phiếu trả từ `S1`.
2. Chọn `SP002`, số lượng trả = 1.
3. Nhập `Phí trả hàng = 5.000` nếu source có field tương đương.
4. Hoàn tất.

### Kỳ vọng
- Giá trị hàng trả thô = **30.000**.
- Số tiền thực hoàn cho khách = **25.000**.
- Tồn kho `SP002` tăng từ 48 lên **49**.
- Phiếu trả hàng lưu được phí trả hàng để truy xuất lại.
- Báo cáo / ledger / payment phải thể hiện đúng phần phí và phần tiền hoàn, hoặc phải có cấu trúc dữ liệu tương đương.

### Nếu source không hỗ trợ phí trả hàng
- Đánh dấu **Missing Feature**.
- Không coi là bug nếu toàn bộ logic khác vẫn đúng.

---

## 05C — Trả hàng một phần hóa đơn
### Mục đích
Xác minh hệ thống hỗ trợ trả một phần số lượng đã bán thay vì buộc trả toàn bộ.

### Dữ liệu đầu vào
Dùng hóa đơn `S1`.
Hóa đơn này đã bán `SP001 x 10`.
Thực hiện trả tiếp:
- `SP001` x 3

### Bước thực hiện
1. Vào trả hàng theo hóa đơn `S1`.
2. Chọn `SP001`.
3. Nhập số lượng trả = 3.
4. Hoàn tất.

### Kỳ vọng
- Hệ thống không cho phép trả vượt quá số lượng còn có thể trả.
- Tồn kho `SP001` tăng thêm đúng **3**.
- Nếu có theo dõi returned quantity tại dòng hóa đơn, số đã trả tích lũy phải cập nhật đúng.
- Nếu đã từng trả trước đó, số lượng còn có thể trả phải giảm tương ứng.

### Fail điển hình
- Cho trả vượt số đã bán.
- Không nhớ số lượng đã trả trước đó.
- Mỗi lần trả lại ghi đè thay vì cộng dồn.

---

## 05D — Trả hàng nhanh (không có hóa đơn gốc)
### Mục đích
Xác minh hệ thống hỗ trợ trả hàng không cần hóa đơn gốc.

### Dữ liệu đầu vào
Khách `KH001` trả nhanh:
- `SP003` x 1
- Giá trả: **80.000** (hoặc theo rule source hỗ trợ)

### Bước thực hiện
1. Vào màn hình Trả hàng.
2. Chọn **Trả nhanh**.
3. Tìm và thêm `SP003` x 1.
4. Nhập giá trả nếu source yêu cầu.
5. Hoàn tất.

### Kỳ vọng
- Tạo được phiếu trả hàng **không cần liên kết hóa đơn gốc**.
- Tồn kho `SP003` tăng thêm **1**.
- Phiếu trả phải được phân biệt với phiếu trả theo hóa đơn nếu hệ thống có loại chứng từ.
- Hệ thống vẫn phải ghi nhận đúng hoàn tiền hoặc công nợ liên quan.

### Fail điển hình
- Trả nhanh bắt buộc phải có hóa đơn gốc.
- Trả nhanh không làm tăng tồn kho.
- Phiếu tạo ra nhưng không thể tra cứu lại.

---

## 05E — Đổi trả hàng trong cùng một giao dịch
### Mục đích
Xác minh hệ thống cho phép vừa nhận hàng trả, vừa bán hàng mới trong cùng một giao dịch và đối trừ chênh lệch.

### Dữ liệu đầu vào
Xuất phát từ trả hàng của `S1`:
- Hàng trả: `SP001` x 2 = 14.000
- Hàng đổi mới: `SP002` x 1 = 30.000

### Bước thực hiện
1. Bắt đầu tạo phiếu trả hàng từ `S1`.
2. Chọn trả `SP001` x 2.
3. Thêm hàng mua/đổi `SP002` x 1 vào cùng giao dịch nếu source hỗ trợ.
4. Xác nhận thanh toán chênh lệch.
5. Hoàn tất.

### Kỳ vọng
- Hệ thống tạo giao dịch đổi trả hoặc cấu trúc tương đương.
- Tiền hàng trả = **14.000**.
- Tiền hàng đổi = **30.000**.
- Khách phải trả thêm **16.000** nếu không có giảm giá/phí khác.
- Tồn kho `SP001` tăng 2, `SP002` giảm 1.
- Công nợ hoặc phiếu thu phát sinh đúng phần chênh lệch.

### Nếu source chưa hỗ trợ đổi trả trong cùng giao dịch
- Đánh dấu **Missing Feature**.
- Không coi là bug nếu hệ thống chỉ hỗ trợ quy trình tách 2 bước độc lập.

---

## 05F — Quản lý phiếu trả hàng: xem, cập nhật thông tin, sao chép
### Mục đích
Xác minh danh sách và chi tiết phiếu trả hàng hoạt động đúng.

### Bước thực hiện
1. Vào danh sách Trả hàng.
2. Tìm phiếu trả vừa tạo ở 05A hoặc 05B.
3. Mở chi tiết phiếu.
4. Cập nhật các thông tin được phép như người nhận trả, ngày trả, ghi chú nếu source có.
5. Lưu.
6. Thử dùng chức năng sao chép nếu có.

### Kỳ vọng
- Danh sách hiển thị được phiếu trả hàng vừa tạo.
- Mở chi tiết không lỗi.
- Chỉ những trường được phép mới được cập nhật; các trường làm sai nghiệp vụ lõi không nên cho sửa tùy tiện sau khi hoàn tất.
- Sao chép tạo ra phiếu mới với dữ liệu khởi tạo hợp lý, không làm thay đổi phiếu gốc.

### Fail điển hình
- Không xem được phiếu sau khi tạo.
- Mở ra nhưng dữ liệu hàng hóa sai so với lúc trả.
- Cập nhật ghi chú làm ảnh hưởng số liệu kho/tiền.
- Sao chép đè lên phiếu gốc.

---

## 05G — Hủy phiếu trả hàng và rollback
### Mục đích
Xác minh khi hủy phiếu trả hàng, hệ thống rollback đúng tồn kho và công nợ.

### Dữ liệu đầu vào
Dùng một phiếu trả hàng đã tạo thành công ở 05A.

### Bước thực hiện
1. Mở phiếu trả hàng.
2. Chọn Hủy.
3. Xác nhận hủy.
4. Kiểm tra lại tồn kho, tiền và công nợ.

### Kỳ vọng
- Phiếu trả chuyển sang trạng thái hủy.
- Tồn kho rollback đúng: nếu 05A đã cộng `SP001` +2 thì sau khi hủy phải trừ lại 2 về mức trước hủy.
- Công nợ khách hàng cập nhật lại đúng.
- Phiếu hoàn tiền / phiếu thu / ledger allocation liên quan cũng phải được rollback hoặc đánh dấu hủy theo thiết kế.
- Lịch sử vẫn giữ dấu vết là phiếu đã bị hủy.

### Fail điển hình
- Hủy phiếu nhưng tồn kho không rollback.
- Tồn rollback nhưng công nợ không rollback.
- Hủy xong vẫn còn phiếu hoàn tiền có hiệu lực.
- Xóa cứng làm mất lịch sử.

---

## 05H — Đơn giao bị chuyển hoàn (nếu source có hỗ trợ)
### Mục đích
Xác minh nếu hệ thống có tích hợp vận chuyển và trạng thái đơn hoàn về, việc trả hàng tự động được xử lý đúng.

### Bước thực hiện
1. Tìm 1 hóa đơn/đơn giao có trạng thái vận chuyển liên quan.
2. Mô phỏng cập nhật trạng thái sang `Đã chuyển hoàn` hoặc tương đương.
3. Kiểm tra xem có tự sinh giao dịch trả hàng hay không.
4. Thử hủy phiếu trả hàng tự động nếu UI cho phép.

### Kỳ vọng
- Nếu source có hỗ trợ chuyển hoàn, hệ thống phải tự tạo phiếu trả hàng tương ứng.
- Hàng hóa được cộng lại tồn kho.
- Hóa đơn gốc được chuyển về trạng thái hậu xử lý phù hợp theo thiết kế.
- Phiếu trả tự động do chuyển hoàn **không được hủy** nếu source bám KiotViet.

### Nếu source không có tích hợp vận chuyển
- Đánh dấu **NA** hoặc **Not Implemented**.

---

## Kiểm tra database bắt buộc sau mỗi case
Agent phải kiểm ít nhất các bảng hoặc thực thể tương đương:
- `sales_invoices` / `orders`
- `sales_invoice_items`
- `sale_returns` / `return_orders`
- `sale_return_items`
- `stock_movements`
- `customer_ledgers` / `receivable_entries`
- `payments` / `refund_vouchers` / `allocations`

Nếu tên bảng khác, agent phải map rõ sang thực thể tương đương.

---

## Tiêu chí chấm kết quả
Mỗi case chỉ được chấm một trong các trạng thái:
- **PASS** — bám đúng hành vi tham chiếu của KiotViet.
- **PASS WITH DEVIATION** — chạy được nhưng lệch nhỏ, không phá vỡ lõi nghiệp vụ.
- **FAIL** — sai luồng, sai tồn, sai tiền, sai công nợ, sai rollback.
- **MISSING FEATURE** — tài liệu KiotViet có, source chưa hỗ trợ.
- **NA** — không áp dụng vì source không có module liên quan.

Nếu có bất kỳ lỗi nào làm sai tồn kho hoặc sai công nợ, toàn bộ Flow 05 phải coi là **chưa đạt**.

---

## Mẫu báo cáo đầu ra bắt buộc của agent
Agent phải xuất báo cáo cuối cùng theo mẫu sau:

### 1. Tóm tắt
- Source/framework/module đã đọc
- Flow 05 có bao nhiêu case PASS / FAIL / MISSING FEATURE / NA
- Đánh giá tổng quát: đạt hay chưa đạt

### 2. Bảng kết quả chi tiết
| Case | Mục tiêu | Kết quả thực tế | Kỳ vọng | Trạng thái |
|------|----------|-----------------|---------|------------|
| 05A | Trả hàng theo hóa đơn | ... | ... | PASS/FAIL |
| 05B | Phí trả hàng | ... | ... | PASS/FAIL/MISSING FEATURE |
| 05C | Trả một phần | ... | ... | ... |
| 05D | Trả nhanh | ... | ... | ... |
| 05E | Đổi trả | ... | ... | ... |
| 05F | Quản lý phiếu trả | ... | ... | ... |
| 05G | Hủy phiếu trả | ... | ... | ... |
| 05H | Chuyển hoàn | ... | ... | ... |

### 3. Danh sách lỗi
Mỗi lỗi phải có:
- Mã lỗi
- Case phát hiện
- Cách tái hiện
- Nguyên nhân gốc
- File cần sửa
- Mức độ ảnh hưởng (Critical/High/Medium/Low)

### 4. Danh sách file đã sửa
- Đường dẫn file
- Mục đích sửa
- Tóm tắt diff

### 5. Kết quả re-test
- Case nào đã pass sau fix
- Case nào còn fail
- Rủi ro còn lại

---

## Lệnh kết thúc bắt buộc
Sau khi hoàn thành, agent phải kết luận rõ một câu:

- `FLOW 05 PASSED` nếu không còn lỗi làm sai tồn kho / sai hoàn tiền / sai công nợ.
- `FLOW 05 FAILED` nếu còn ít nhất một lỗi lõi chưa xử lý xong.

Agent không được kết luận mơ hồ.
