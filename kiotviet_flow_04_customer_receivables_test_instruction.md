# KiotViet Flow 04 — Quản lý công nợ khách hàng / Thu nợ khách hàng

## Mục tiêu
Luồng này dùng để kiểm tra hệ thống có vận hành đúng **quản lý công nợ phải thu của khách hàng sau bán hàng** theo hành vi của KiotViet hay không.

Agent phải kiểm tra cả 3 lớp:
1. **Luồng nghiệp vụ trên UI/API** có đi đúng trình tự không.
2. **Dữ liệu kế toán/nghiệp vụ** có cập nhật đúng không.
3. **Tác động liên quan** đến hóa đơn, phiếu thu, lịch sử khách hàng và số dư công nợ.

Luồng này chỉ tập trung vào **công nợ khách hàng sau bán hàng**. Không mở rộng sang trả hàng, hủy hóa đơn, công nợ nhà cung cấp hay sổ quỹ tổng hợp nếu chưa thật sự cần cho case hiện tại.

---

## Nguồn chuẩn tham chiếu của KiotViet
Agent phải coi các hành vi sau là chuẩn tham chiếu:

1. Trong chi tiết khách hàng có tab **Nợ cần thu từ khách** để xem chi tiết các giao dịch tạo công nợ và các phiếu thanh toán.
2. Khi thanh toán công nợ, người dùng có thể nhập vào ô **Thu từ khách** để hệ thống **tự động phân bổ vào các hóa đơn cũ trước**, hoặc nhập trực tiếp vào từng hóa đơn.
3. Sau khi xác nhận, hệ thống tạo **phiếu thu**.
4. Có thể **điều chỉnh công nợ** ngay trong tab Nợ cần thu từ khách; hệ thống tạo phiếu điều chỉnh theo thời gian điều chỉnh.
5. Có thể tạo **chiết khấu thanh toán** như một khoản giảm trừ công nợ.
6. Nếu có bật quản lý khách hàng theo chi nhánh thì khi bán hàng/tạo phiếu thu chỉ tìm được khách hàng thuộc chi nhánh đang giao dịch.
7. Có thể xem lại **lịch sử bán/trả hàng** của khách trong hồ sơ khách hàng.

Agent không được tự bịa hành vi khác với các điểm trên nếu chưa chứng minh được bằng source hoặc tài liệu.

---

## Phạm vi kiểm tra của Flow 04
Luồng này gồm các nhánh sau:

- 04A. Hóa đơn bán hàng phát sinh công nợ khách hàng.
- 04B. Xem công nợ trong hồ sơ khách hàng.
- 04C. Thu nợ theo kiểu **tự động phân bổ**.
- 04D. Thu nợ theo kiểu **phân bổ trực tiếp vào từng hóa đơn**.
- 04E. Điều chỉnh công nợ khách hàng.
- 04F. Chiết khấu thanh toán như khoản giảm trừ công nợ.
- 04G. Kiểm tra lịch sử giao dịch sau khi thu nợ.
- 04H. Kiểm tra phân quyền/chi nhánh nếu source có hỗ trợ.

Nếu source chưa hỗ trợ một nhánh, agent phải đánh dấu rõ là **Missing Feature** hoặc **Not Implemented**, không được đánh đồng với bug logic.

---

## Bộ dữ liệu test cố định
Agent phải dùng bộ dữ liệu test cố định để bảo đảm có thể lặp lại.

### Khách hàng
- `KH001` — Nguyễn Văn A — SĐT `0900000001`

### Hàng hóa
- `SP001` — Nước suối 500ml — Giá bán 7.000 — tồn đủ lớn
- `SP002` — Bánh quy hộp — Giá bán 30.000 — tồn đủ lớn

### Hóa đơn nợ mẫu cần tạo trước
Agent phải tạo ít nhất 3 hóa đơn cho cùng một khách `KH001`:

#### Invoice A
- Ngày giao dịch: T1
- Hàng: `SP001` x 10
- Tổng tiền: 70.000
- Khách trả: 0
- Công nợ còn lại: 70.000

#### Invoice B
- Ngày giao dịch: T2 (sau Invoice A)
- Hàng: `SP002` x 2
- Tổng tiền: 60.000
- Khách trả: 20.000
- Công nợ còn lại: 40.000

#### Invoice C
- Ngày giao dịch: T3 (sau Invoice B)
- Hàng: `SP001` x 5
- Tổng tiền: 35.000
- Khách trả: 35.000
- Công nợ còn lại: 0

### Tổng công nợ kỳ vọng ban đầu của KH001
- Nợ phải thu: **110.000**
  - Invoice A: 70.000
  - Invoice B: 40.000
  - Invoice C: 0

Agent có thể tạo các hóa đơn này qua UI, API hoặc seed script, nhưng phải ghi rõ cách tạo.

---

## Chuẩn đối soát bắt buộc
Sau mỗi thao tác, agent phải đối chiếu ít nhất các điểm sau:

1. Tổng công nợ khách hàng.
2. Số dư nợ trên từng hóa đơn.
3. Phiếu thu hoặc phiếu điều chỉnh được tạo hay chưa.
4. Lịch sử giao dịch của khách có hiển thị đúng hay không.
5. Nếu có bảng ledger / debt entries / receipt allocations thì phải kiểm trực tiếp ở database.

Nếu hệ thống dùng ledger hoặc allocation table riêng, agent phải truy ra bản ghi thật thay vì chỉ nhìn UI.

---

## Quy tắc sửa lỗi
Agent chỉ được sửa khi thỏa đủ điều kiện sau:

1. Đã tái hiện được lỗi bằng test case cụ thể trong Flow 04.
2. Đã xác định được điểm sai nằm ở source hiện tại.
3. Sửa theo hướng **ít thay đổi nhất** để khôi phục hành vi đúng luồng.
4. Sau khi sửa phải chạy lại toàn bộ case liên quan trong Flow 04.

Agent không được:
- đổi cấu trúc lớn ngoài phạm vi Flow 04 nếu chưa thật sự cần;
- refactor diện rộng;
- sửa sang flow trả hàng/hủy hóa đơn nếu chưa bị Flow 04 gọi tới;
- ghi đè dữ liệu test mà không giải thích.

---

## Trình tự bắt buộc agent phải thực hiện

### Bước 1 — Đọc source
Agent phải đọc và ghi lại:
- model/entity của Customer, Invoice/Sale, Receipt/Payment, Debt/Receivable Ledger
- service/use-case xử lý thanh toán công nợ
- routing/controller/UI màn hình chi tiết khách hàng và tab công nợ
- cơ chế phân bổ thanh toán vào hóa đơn
- cơ chế điều chỉnh công nợ
- cơ chế chiết khấu thanh toán nếu có

### Bước 2 — Xác định nguồn sự thật
Agent phải xác định rõ hệ thống lấy công nợ khách hàng từ đâu:
- cột balance trực tiếp trên customer?
- tổng hợp từ invoices chưa thanh toán?
- ledger entries?
- payment allocation table?

Nếu không xác định được nguồn sự thật thì phải ghi rõ đây là rủi ro kiến trúc.

### Bước 3 — Khởi tạo dữ liệu test
Tạo 3 hóa đơn mẫu A/B/C cho KH001 như trên.

### Bước 4 — Chạy từng case 04A → 04H
Ghi nhận kết quả chi tiết.

### Bước 5 — Nếu có lỗi
- ghi bước tái hiện
- chụp log / stack / query / record liên quan
- sửa tối thiểu
- chạy lại case lỗi
- chạy regression nhỏ cho các case liên đới

### Bước 6 — Xuất báo cáo cuối
Báo cáo phải có:
- pass/fail từng case
- hành vi lệch so với KiotViet
- file đã sửa
- nguyên nhân gốc
- case đã re-test
- phần còn thiếu tính năng

---

# Danh sách test case chi tiết

## 04A — Hóa đơn bán hàng phát sinh công nợ khách hàng
### Mục tiêu
Xác minh hóa đơn bán chưa thanh toán đủ sẽ làm phát sinh công nợ phải thu.

### Thao tác
1. Tạo Invoice A với khách KH001, tổng tiền 70.000, khách trả 0.
2. Tạo Invoice B với khách KH001, tổng tiền 60.000, khách trả 20.000.
3. Tạo Invoice C với khách KH001, tổng tiền 35.000, khách trả 35.000.

### Kỳ vọng
- Invoice A còn nợ 70.000.
- Invoice B còn nợ 40.000.
- Invoice C không còn nợ.
- Tổng nợ khách KH001 là 110.000.
- Hồ sơ khách hàng hoặc báo cáo công nợ phải nhìn thấy đúng số 110.000.

### Kiểm DB bắt buộc
- Kiểm invoice remaining balance / paid amount.
- Kiểm customer receivable balance nếu có cột tổng.
- Kiểm ledger entries hoặc debt records đã sinh đúng.

### Fail nghiêm trọng
- Hóa đơn trả thiếu nhưng không sinh công nợ.
- Công nợ tổng không bằng tổng nợ chi tiết hóa đơn.
- Công nợ bị cộng cả hóa đơn đã thanh toán đủ.

---

## 04B — Xem công nợ trong hồ sơ khách hàng
### Mục tiêu
Xác minh tab công nợ hiển thị đúng danh sách giao dịch nợ và phiếu thanh toán.

### Thao tác
1. Mở chi tiết khách hàng KH001.
2. Vào tab tương đương với **Nợ cần thu từ khách**.
3. Kiểm tra danh sách giao dịch đang nợ.

### Kỳ vọng
- Nhìn thấy ít nhất Invoice A và Invoice B trong danh sách nợ.
- Invoice C không xuất hiện ở danh sách nợ hoặc xuất hiện với số dư = 0 theo đúng thiết kế.
- Tổng công nợ hiển thị = 110.000.
- Có thể drill-down vào giao dịch gốc.

### Fail nghiêm trọng
- Danh sách nợ không khớp số dư hóa đơn.
- Không vào được tab công nợ từ hồ sơ khách.
- Hiển thị sai khách hoặc sai chi nhánh.

---

## 04C — Thu nợ theo kiểu tự động phân bổ
### Mục tiêu
Xác minh khi nhập số tiền ở ô tương đương **Thu từ khách**, hệ thống tự động phân bổ vào các hóa đơn cũ trước.

### Thao tác
1. Trong tab công nợ của KH001, chọn Thanh toán.
2. Nhập số tiền khách trả = 80.000 vào ô tổng thu.
3. Không chỉnh tay từng hóa đơn, giữ chế độ auto allocation.
4. Xác nhận tạo phiếu thu.

### Kỳ vọng theo KiotViet
- Hệ thống tự phân bổ vào hóa đơn cũ trước.
- Invoice A được tất toán hết 70.000.
- Invoice B được thu thêm 10.000, còn nợ 30.000.
- Tổng nợ còn lại = 30.000.
- Có 1 phiếu thu mới trị giá 80.000.

### Kiểm DB bắt buộc
- Có receipt/payment record 80.000.
- Có allocation record hoặc equivalent mapping: 70.000 vào Invoice A, 10.000 vào Invoice B.
- Ledger/balance sau thu nợ còn 30.000.

### Fail nghiêm trọng
- Phân bổ sai thứ tự cũ trước mới sau.
- Không sinh phiếu thu.
- Tổng nợ sau thanh toán không còn 30.000.

---

## 04D — Thu nợ theo kiểu phân bổ trực tiếp vào từng hóa đơn
### Mục tiêu
Xác minh người dùng có thể nhập trực tiếp số tiền vào từng hóa đơn thay vì để hệ thống tự phân bổ.

### Điều kiện trước case
Reset lại dữ liệu gốc hoặc tạo lại bộ hóa đơn để công nợ quay về 110.000.

### Thao tác
1. Mở tab công nợ của KH001.
2. Chọn Thanh toán.
3. Không nhập tổng vào ô auto hoặc nhập theo thiết kế tương đương.
4. Gán trực tiếp:
   - Invoice A: 20.000
   - Invoice B: 15.000
5. Tạo phiếu thu.

### Kỳ vọng
- Invoice A còn nợ 50.000.
- Invoice B còn nợ 25.000.
- Tổng nợ còn 75.000.
- Có phiếu thu mới trị giá 35.000.
- Hệ thống không tự ý phân bổ sang hóa đơn khác ngoài khoản người dùng đã chọn.

### Fail nghiêm trọng
- Hệ thống bỏ qua phân bổ tay và vẫn auto allocate.
- Số dư từng hóa đơn không khớp số tiền phân bổ tay.
- Phiếu thu tổng không khớp 35.000.

---

## 04E — Điều chỉnh công nợ khách hàng
### Mục tiêu
Xác minh hệ thống hỗ trợ điều chỉnh công nợ và sinh phiếu điều chỉnh.

### Điều kiện trước case
Khôi phục KH001 về trạng thái còn nợ 110.000 hoặc một trạng thái xác định rõ.

### Thao tác
1. Vào tab công nợ của KH001.
2. Chọn Điều chỉnh.
3. Nhập giá trị điều chỉnh giảm 10.000.
4. Ghi mô tả: `Điều chỉnh công nợ test Flow04`.
5. Xác nhận.

### Kỳ vọng
- Tổng công nợ giảm đúng 10.000.
- Có phiếu/bản ghi điều chỉnh được tạo với thời gian điều chỉnh.
- Lịch sử công nợ thể hiện rõ đây là giao dịch điều chỉnh chứ không phải thanh toán thực nhận.

### Kiểm DB bắt buộc
- Có debt adjustment entry hoặc equivalent.
- Không tạo nhầm phiếu thu khi chỉ điều chỉnh công nợ.

### Fail nghiêm trọng
- Điều chỉnh không làm đổi số dư.
- Điều chỉnh lại sinh phiếu thu sai bản chất.
- Không lưu được mô tả/thời gian điều chỉnh.

---

## 04F — Chiết khấu thanh toán như khoản giảm trừ công nợ
### Mục tiêu
Xác minh nếu source có hỗ trợ chiết khấu thanh toán, khoản này phải làm giảm công nợ đúng bản chất.

### Điều kiện
Chỉ chạy case này nếu source có tính năng tương đương với **Chiết khấu thanh toán**.

### Thao tác
1. Mở tab công nợ của KH001.
2. Chọn Chiết khấu thanh toán.
3. Nhập số tiền 5.000.
4. Nếu có tùy chọn phân bổ vào hóa đơn thì bật theo thiết kế mặc định.
5. Xác nhận.

### Kỳ vọng
- Công nợ giảm 5.000.
- Có phiếu hoặc record chiết khấu tách biệt với phiếu thu.
- Nếu có allocation thì phải thấy khoản giảm trừ được gán vào hóa đơn nợ.

### Fail nghiêm trọng
- Chiết khấu bị ghi như tiền khách thật trả.
- Công nợ không giảm.
- Không phân biệt được phiếu thu và phiếu chiết khấu.

### Nếu không có tính năng
Đánh dấu: `Missing Feature - payment discount on customer receivables`.

---

## 04G — Kiểm tra lịch sử giao dịch sau khi thu nợ
### Mục tiêu
Xác minh hồ sơ khách hàng lưu được lịch sử bán/trả hàng và giao dịch công nợ liên quan một cách truy vết được.

### Thao tác
1. Mở chi tiết KH001.
2. Xem tab Lịch sử bán/trả hàng hoặc phần tương đương.
3. Xem tab công nợ / phiếu thu / lịch sử thanh toán nếu có.

### Kỳ vọng
- Truy được từ khách hàng tới hóa đơn gốc.
- Truy được tới phiếu thu vừa tạo.
- Trạng thái nợ sau thanh toán hiển thị nhất quán giữa lịch sử giao dịch và tab công nợ.

### Fail nghiêm trọng
- Thu nợ thành công nhưng lịch sử khách không cập nhật.
- Có phiếu thu nhưng không truy được nguồn hóa đơn đã thu.

---

## 04H — Kiểm tra phân quyền / chi nhánh (nếu source có hỗ trợ)
### Mục tiêu
Xác minh việc tìm khách hàng để thu nợ hoặc xem công nợ tuân thủ rule chi nhánh/quyền hạn.

### Điều kiện
Chỉ chạy nếu source có multi-branch hoặc phân quyền theo chi nhánh.

### Thao tác
1. Tạo hoặc dùng user của chi nhánh A.
2. Tạo thêm 1 khách thuộc chi nhánh B.
3. Đăng nhập user chi nhánh A.
4. Mở màn hình thu nợ / phiếu thu / chọn khách hàng.

### Kỳ vọng
- User chi nhánh A chỉ tìm thấy khách được phép theo rule của hệ thống.
- Không được thu nợ nhầm cho khách chi nhánh khác nếu rule tương đương KiotViet đang bật.

### Nếu không hỗ trợ
Đánh dấu: `NA - branch-scoped customer management not enabled`.

---

# Regression tối thiểu sau khi sửa lỗi
Nếu agent phải sửa bất kỳ lỗi nào ở Flow 04, bắt buộc chạy lại ít nhất các case:
- 04A
- 04C
- 04D
- 04E
- 04G

Nếu sửa vào service thanh toán hoặc allocation engine, phải chạy thêm Flow 03 case bán hàng trả thiếu nếu đã có sẵn bộ test.

---

# Mẫu báo cáo kết quả bắt buộc
Agent phải xuất báo cáo theo mẫu sau:

## 1. Tóm tắt
- Flow: 04 — Quản lý công nợ khách hàng / Thu nợ khách hàng
- Commit/branch tested:
- Environment:
- Data setup method:

## 2. Kết quả từng case
| Case | Kết quả | Ghi chú |
|---|---|---|
| 04A | Pass/Fail/NA | ... |
| 04B | Pass/Fail/NA | ... |
| 04C | Pass/Fail/NA | ... |
| 04D | Pass/Fail/NA | ... |
| 04E | Pass/Fail/NA | ... |
| 04F | Pass/Fail/NA | ... |
| 04G | Pass/Fail/NA | ... |
| 04H | Pass/Fail/NA | ... |

## 3. Sai lệch so với KiotViet
Liệt kê ngắn gọn từng sai lệch, ví dụ:
- Auto allocation đang phân bổ theo hóa đơn mới nhất, không phải cũ trước.
- Tạo phiếu thu xong nhưng công nợ tổng không cập nhật.
- Điều chỉnh công nợ đang bị ghi nhầm như phiếu thu.

## 4. File/code đã sửa
- file path
- thay đổi chính
- lý do sửa

## 5. Kết quả sau sửa
- case nào đã pass lại
- case nào vẫn fail
- case nào là missing feature

## 6. Rủi ro còn lại
- chưa có allocation table rõ ràng
- balance đang lưu trùng nhiều nơi
- UI đúng nhưng DB ledger sai

---

# Tiêu chí hoàn thành Flow 04
Flow 04 chỉ được coi là hoàn thành khi:

1. Công nợ phát sinh đúng từ hóa đơn chưa thanh toán đủ.
2. Hồ sơ khách hàng xem được danh sách nợ cần thu.
3. Thu nợ auto allocate hoạt động đúng theo thứ tự hóa đơn cũ trước.
4. Thu nợ phân bổ tay hoạt động đúng số tiền trên từng hóa đơn.
5. Điều chỉnh công nợ tạo đúng record và làm đổi đúng số dư.
6. Nếu có chiết khấu thanh toán thì bản chất ghi nhận đúng là giảm trừ công nợ.
7. Lịch sử khách hàng và phiếu thu truy vết được với nhau.

Nếu còn sai ở 1 trong 7 điểm trên thì không được đánh dấu Flow 04 là hoàn tất.
