# KIOTVIET FLOW 03 — KIỂM THỬ BÁN HÀNG / TẠO HÓA ĐƠN BÁN

## Mục tiêu
Bạn là AI agent kiểm thử hệ thống quản lý bán hàng nội bộ.
Nhiệm vụ của bạn là kiểm tra **Flow 03: Bán hàng / tạo hóa đơn bán** của hệ thống hiện tại, theo hành vi tham chiếu từ tài liệu KiotViet.

Flow này chỉ tập trung vào **nghiệp vụ bán hàng tại quầy / tạo hóa đơn**:
1. Truy cập màn hình bán hàng và chọn chế độ bán phù hợp
2. Thêm hàng hóa vào hóa đơn
3. Thêm nhanh hàng hóa mới ngay trên màn hình bán (nếu hệ thống hỗ trợ)
4. Tìm và thêm khách hàng vào hóa đơn
5. Thêm nhanh khách hàng mới ngay trên màn hình bán
6. Áp dụng giảm giá trên dòng hàng và/hoặc toàn hóa đơn
7. Thanh toán đủ, thanh toán thiếu, chưa thanh toán
8. Ghi nhận công nợ khách hàng khi khách trả thiếu hoặc chưa trả
9. Thanh toán bằng một hoặc nhiều phương thức (nếu hệ thống hỗ trợ)
10. Hoàn tất hóa đơn và kiểm tra các tác động liên quan
11. Tra cứu lịch sử bán hàng / công nợ từ hồ sơ khách hàng sau khi bán

Không test các flow khác trong lần này.
Không mở rộng sang trả hàng, hủy hóa đơn, giao hàng, đặt hàng, khuyến mại phức tạp, hóa đơn điện tử, sổ quỹ tổng hợp, báo cáo tổng hợp hay các flow ngoài phạm vi trên.
Chỉ được kiểm thử đúng Flow 03.

---

## Nguyên tắc làm việc bắt buộc
1. Chỉ kiểm thử **một flow duy nhất**: Flow 03.
2. Không suy đoán hệ thống “có lẽ đúng”. Phải kiểm bằng thao tác thực tế hoặc bằng source + database + UI/API hiện có.
3. Nếu hệ thống có source, hãy ưu tiên kết hợp:
   - đọc route / controller / service / validation / policy
   - đọc schema database và constraint
   - chạy UI hoặc API test thực tế
4. Nếu phát hiện hệ thống chưa đúng hành vi mong đợi:
   - ghi rõ sai ở đâu
   - giải thích ảnh hưởng
   - đề xuất fix tối thiểu
   - chỉ bổ sung/sửa code khi đã xác định được lỗi thật
5. Không refactor lan rộng.
6. Không sửa các phần ngoài Flow 03.
7. Sau mỗi thay đổi, phải re-test đúng case vừa sửa.
8. Không kết luận “pass” nếu chưa có bằng chứng.
9. Nếu thiếu dữ liệu nền, được phép tạo **dữ liệu tối thiểu cần thiết** đúng theo bộ dữ liệu test ở file này; không được tạo dữ liệu thừa.
10. Nếu hệ thống có khác biệt có chủ đích so với KiotViet, phải ghi rõ đó là **deviation có chủ ý** chứ không tự coi là lỗi.
11. Nếu hệ thống của bạn không có đủ 3 chế độ bán (Bán nhanh / Bán thường / Bán giao hàng), chỉ kiểm chế độ thực tế đang có; ghi rõ phần thiếu là deviation hoặc thiếu tính năng, không tự ý thêm cả module mới.

---

## Chuẩn tham chiếu của Flow 03
Flow 03 bám theo logic bán hàng của KiotViet:
- Màn hình bán hàng hỗ trợ các chế độ như Bán nhanh, Bán thường và Bán giao hàng.
- Có thể thêm hàng hóa bằng tìm kiếm mã/tên, máy quét mã vạch hoặc chọn nhanh theo hình ảnh tùy chế độ.
- Có thể thêm nhanh hàng hóa mới ngay trên màn hình bán hàng nếu có quyền.
- Có thể tìm khách hàng theo tên, mã hoặc số điện thoại; có thể thêm nhanh khách hàng mới ngay trên màn hình bán hàng.
- Có thể giảm giá trên từng sản phẩm và/hoặc giảm giá toàn hóa đơn.
- Có thể thanh toán bằng một hoặc nhiều phương thức thanh toán nếu hệ thống hỗ trợ.
- Khi khách trả thiếu hoặc chưa trả, phần còn lại được ghi nhận vào công nợ khách hàng.
- Sau khi tạo hóa đơn, có thể tra cứu lịch sử bán hàng và công nợ từ hồ sơ khách hàng.

Lưu ý:
- Flow này chỉ bám hành vi của nghiệp vụ bán hàng / tạo hóa đơn.
- Không test hủy hóa đơn trong flow này.
- Không test trả hàng trong flow này.
- Không test phát hành hóa đơn điện tử trong flow này.
- Không test chương trình khuyến mại phức tạp ngoài giảm giá cơ bản, trừ khi hệ thống hiện tại đã có sẵn.

---

## Phạm vi kiểm tra
### A. Tạo hóa đơn bán thanh toán đủ
Cần kiểm:
- mở màn hình bán hàng
- thêm 1 hoặc nhiều hàng hóa vào hóa đơn
- chọn khách hàng
- nhập giảm giá nếu có
- khách thanh toán đủ
- hoàn thành hóa đơn
- kiểm tra tồn kho giảm
- kiểm tra công nợ không phát sinh

### B. Tạo hóa đơn khách trả thiếu / chưa trả
Cần kiểm:
- tạo hóa đơn có khách hàng cụ thể
- nhập số tiền khách trả ít hơn tổng hóa đơn hoặc bằng 0
- hoàn thành hóa đơn
- ghi nhận phần còn lại vào công nợ khách hàng

### C. Thêm nhanh trên màn hình bán
Cần kiểm:
- thêm nhanh khách hàng
- thêm nhanh hàng hóa mới (nếu hệ thống hỗ trợ)
- dữ liệu tạo nhanh có đồng bộ về danh mục hay không

### D. Giảm giá và tổng tiền
Cần kiểm:
- giảm giá trên dòng hàng
- giảm giá toàn đơn
- tổng tiền cuối cùng có tính đúng hay không
- tiền khách trả và công nợ có tính theo số sau giảm giá hay không

### E. Thanh toán
Cần kiểm:
- một phương thức thanh toán
- nhiều phương thức thanh toán (nếu hệ thống hỗ trợ)
- thanh toán đủ / thiếu / bằng 0
- số tiền ghi nhận có khớp hóa đơn hay không

### F. Hậu kiểm sau bán
Cần kiểm:
- tồn kho giảm đúng theo số lượng bán
- lịch sử bán hàng của khách có thấy hóa đơn mới hay không
- tab công nợ của khách có ghi nhận đúng khi phát sinh nợ hay không

---

## Dữ liệu nền tối thiểu bắt buộc
Ưu tiên tái sử dụng dữ liệu của Flow 01 và Flow 02. Nếu chưa có, tạo đúng bộ tối thiểu sau:

### Kho
- code: `KHO_TONG`
- name: `Kho tổng`

### Hàng hóa 1
- code: `SP001`
- name: `Nước suối 500ml`
- cost_price mặc định: `5000`
- sale_price mặc định: `7000`
- opening_stock hiện có tối thiểu để bán: `>= 20`

### Hàng hóa 2
- code: `SP002`
- name: `Bánh quy hộp`
- cost_price mặc định: `20000`
- sale_price mặc định: `30000`
- opening_stock hiện có tối thiểu để bán: `>= 10`

### Khách hàng
- code: `KH001`
- name: `Nguyễn Văn A`
- phone: `0900000001`

Không tự đổi bộ dữ liệu trừ khi có lý do kỹ thuật bất khả kháng.
Nếu phải đổi, ghi rõ lý do.

---

## Bộ kịch bản test cố định
Sử dụng đúng các case sau để tránh test ngẫu nhiên.

### CASE 03A — Bán hàng thanh toán đủ
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP001: số lượng `2`, đơn giá bán `7000`
- SP002: số lượng `1`, đơn giá bán `30000`
- Tổng tiền hàng: `44000`
- Giảm giá: `0`
- Khách thanh toán: `44000`

Kỳ vọng:
- hóa đơn lưu thành công
- trạng thái hóa đơn là hoàn tất / đã thanh toán / tương đương
- tồn SP001 giảm `2`
- tồn SP002 giảm `1`
- công nợ khách hàng tăng `0`
- nếu có phiếu thu / thanh toán thì số tiền là `44000`
- hóa đơn xuất hiện trong lịch sử giao dịch của khách hàng

### CASE 03B — Bán hàng khách trả thiếu
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP001: số lượng `3`, đơn giá bán `7000`
- Tổng tiền hàng: `21000`
- Giảm giá: `0`
- Khách thanh toán: `10000`

Kỳ vọng:
- hóa đơn lưu thành công
- tồn SP001 giảm `3`
- công nợ khách hàng tăng `11000`
- nếu có phiếu thu / thanh toán thì số tiền ghi nhận là `10000`
- tab công nợ của khách hoặc sổ nợ khách hiển thị khoản nợ `11000`

### CASE 03C — Bán hàng chưa thanh toán
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP002: số lượng `2`, đơn giá bán `30000`
- Tổng tiền hàng: `60000`
- Giảm giá: `0`
- Khách thanh toán: `0`

Kỳ vọng:
- hóa đơn lưu thành công
- tồn SP002 giảm `2`
- công nợ khách hàng tăng `60000`
- hệ thống không tự ghi nhận thanh toán ảo
- lịch sử công nợ của khách có thêm hóa đơn này

### CASE 03D — Giảm giá trên dòng hàng
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP001: số lượng `2`, đơn giá gốc `7000`
- Giảm giá dòng: `2000` tổng trên dòng hoặc giá trị tương đương theo UI
- Khách thanh toán: `12000`

Kỳ vọng:
- tổng tiền sau giảm giá phải là `12000`
- tồn SP001 giảm `2`
- công nợ bằng `0`
- nếu hệ thống lưu giảm giá theo dòng, dữ liệu chi tiết hóa đơn phản ánh đúng giá trị giảm

### CASE 03E — Giảm giá toàn hóa đơn
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP001: số lượng `1`, đơn giá `7000`
- SP002: số lượng `1`, đơn giá `30000`
- Tổng tiền trước giảm: `37000`
- Giảm giá hóa đơn: `7000`
- Khách thanh toán: `30000`

Kỳ vọng:
- tổng tiền phải thu sau giảm là `30000`
- tồn SP001 giảm `1`
- tồn SP002 giảm `1`
- công nợ bằng `0`
- dữ liệu hóa đơn phải phản ánh được phần giảm giá toàn đơn

### CASE 03F — Thêm nhanh khách hàng trên màn hình bán
Dữ liệu:
- KH mới: `KH_FLOW03`
- name: `Khách hàng Flow 03`
- phone: `0900000303`

Kỳ vọng:
- tạo được ngay trên màn hình bán
- khách được gán vào hóa đơn hiện tại
- ra danh mục khách hàng tìm thấy bản ghi tương ứng
- không sinh ra bản ghi “ảo” chỉ tồn tại trên hóa đơn

### CASE 03G — Thêm nhanh hàng hóa trên màn hình bán (nếu hệ thống hỗ trợ)
Dữ liệu:
- code: `SP_NEW_03`
- name: `Hàng mới Flow 03`
- sale_price: `15000`
- cost_price: `10000`
- opening_stock: tùy thiết kế; nếu hệ thống không cho nhập tồn ở màn hình bán thì chỉ tạo hàng rồi dùng theo rule hiện có

Kỳ vọng:
- tạo được ngay trên màn hình bán nếu có quyền
- hàng mới xuất hiện ngay trong hóa đơn hiện tại hoặc có thể chọn ngay sau khi tạo
- ra danh mục hàng hóa tìm thấy bản ghi tương ứng
- không sinh ra bản ghi “ảo”

Nếu hệ thống không hỗ trợ thêm nhanh hàng trên màn hình bán, ghi `NA` và giải thích rõ.

### CASE 03H — Thanh toán đa phương thức (nếu hệ thống hỗ trợ)
Dữ liệu:
- Khách hàng: `KH001`
- Kho: `KHO_TONG`
- SP001: số lượng `1`, đơn giá `7000`
- SP002: số lượng `1`, đơn giá `30000`
- Tổng phải thu: `37000`
- Thanh toán tiền mặt: `20000`
- Thanh toán chuyển khoản/thẻ: `17000`

Kỳ vọng:
- hóa đơn lưu thành công
- tổng thanh toán ghi nhận đúng `37000`
- công nợ bằng `0`
- nếu hệ thống có lưu chi tiết phương thức thanh toán, các dòng thanh toán phải đúng giá trị

Nếu hệ thống không hỗ trợ đa phương thức, ghi `NA` và giải thích rõ.

### CASE 03I — Kiểm tra lịch sử bán hàng và công nợ trên hồ sơ khách
Tiền đề:
- đã có ít nhất 1 hóa đơn thanh toán đủ và 1 hóa đơn còn nợ từ các case trước

Kỳ vọng:
- trong hồ sơ khách hàng thấy được lịch sử bán hàng / hóa đơn vừa tạo
- trong tab công nợ hoặc nợ cần thu thấy đúng các hóa đơn nợ
- tổng nợ của khách khớp với các case còn nợ đã tạo

---

## Trình tự thực hiện ưu tiên
Thực hiện đúng thứ tự sau, không đảo lộn nếu không cần thiết:
1. Kiểm tra dữ liệu nền có đủ để bán hay chưa
2. Kiểm tra màn hình bán có tìm được SP001, SP002, KH001 hay không
3. Chạy CASE 03A
4. Chạy CASE 03B
5. Chạy CASE 03C
6. Chạy CASE 03D
7. Chạy CASE 03E
8. Chạy CASE 03F
9. Chạy CASE 03G nếu có hỗ trợ
10. Chạy CASE 03H nếu có hỗ trợ
11. Chạy CASE 03I
12. Tổng hợp sai lệch
13. Chỉ fix tối thiểu những lỗi đã được chứng minh
14. Re-test đúng case bị lỗi

---

## Các điểm phải kiểm tra bằng source hoặc database
Ngoài kiểm UI/API, bắt buộc kiểm các điểm sau trong source/database nếu có quyền truy cập:

### 1. Logic giảm tồn kho
Kiểm tra xem khi hóa đơn hoàn tất:
- tồn kho có thực sự giảm ở bảng tồn / movement / ledger hay không
- nếu lưu nháp hoặc chưa hoàn tất thì có giảm tồn sớm sai thời điểm không

### 2. Logic công nợ khách hàng
Kiểm tra xem khi khách trả thiếu hoặc không trả:
- phần còn lại có được ghi nhận đúng vào ledger công nợ không
- số dư nợ của khách có tăng đúng không
- có sinh phiếu thu ảo ngoài ý muốn hay không

### 3. Logic giảm giá
Kiểm tra xem:
- giảm giá dòng hàng được trừ đúng ở chi tiết hóa đơn
- giảm giá hóa đơn được trừ đúng ở tổng hóa đơn
- công nợ được tính trên số sau giảm giá, không phải số trước giảm giá

### 4. Logic thêm nhanh
Kiểm tra xem:
- khách hàng tạo nhanh có thật sự lưu vào bảng khách hàng không
- hàng hóa tạo nhanh có thật sự lưu vào bảng hàng hóa không
- hóa đơn hiện tại có liên kết đúng tới bản ghi mới tạo không

### 5. Logic thanh toán
Kiểm tra xem:
- tổng thanh toán không vượt / thiếu sai logic nếu hệ thống có ràng buộc
- đa phương thức nếu có được phân bổ đúng
- trường hợp trả 0 không sinh phiếu thu không hợp lệ

---

## Quy tắc đánh giá Pass / Fail
### Pass
Chỉ được đánh dấu `Pass` khi:
- đã thao tác thực tế hoặc chạy test được chứng minh
- dữ liệu sau thao tác khớp kỳ vọng
- không có sai lệch về tồn kho / công nợ / tổng tiền

### Fail
Đánh `Fail` khi gặp một trong các lỗi sau:
- lưu hóa đơn thành công nhưng tồn kho không giảm hoặc giảm sai
- khách trả thiếu nhưng không phát sinh công nợ đúng
- khách chưa trả nhưng hệ thống vẫn coi là đã thanh toán
- giảm giá tính sai tổng tiền
- thêm nhanh khách/hàng chỉ hiển thị tạm mà không lưu danh mục
- lịch sử khách hàng hoặc công nợ không phản ánh đúng hóa đơn vừa tạo

### Pass with deviation
Chỉ dùng khi:
- hệ thống có khác biệt có chủ đích
- khác biệt đó không làm sai dữ liệu lõi
- đã mô tả rõ và được coi là chấp nhận được

### NA
Chỉ dùng cho:
- tính năng không tồn tại trong phạm vi hệ thống hiện tại như thêm nhanh hàng hóa hoặc đa phương thức thanh toán
- phải ghi rõ vì sao không áp dụng

---

## Hướng dẫn fix tối thiểu nếu phát hiện lỗi
Nếu thấy lỗi, chỉ được sửa theo nguyên tắc tối thiểu:

### Ví dụ lỗi 1
Hiện tượng:
- Khách trả `10000` trên hóa đơn `21000` nhưng công nợ không tăng `11000`

Cách xử lý mong muốn:
- xác định tầng đang sai: UI tính sai / API validate sai / service ghi ledger sai / database rounding sai
- sửa đúng tầng gây lỗi
- không sửa toàn bộ module công nợ nếu chưa cần
- re-test lại CASE 03B và CASE 03C

### Ví dụ lỗi 2
Hiện tượng:
- Giảm giá hóa đơn `7000` nhưng tổng phải thu vẫn là `37000`

Cách xử lý mong muốn:
- kiểm tra calculator của invoice totals
- kiểm tra mapping discount_invoice / discount_amount / final_total
- sửa tối thiểu ở hàm tính tổng
- re-test CASE 03E

### Ví dụ lỗi 3
Hiện tượng:
- Thêm khách nhanh ở POS xong nhưng không tìm thấy trong danh mục khách hàng

Cách xử lý mong muốn:
- kiểm tra luồng persist sau khi create nhanh
- kiểm tra transaction save giữa popup thêm nhanh và invoice hiện tại
- sửa tối thiểu tại luồng create customer quick-add
- re-test CASE 03F

---

## Mẫu báo cáo đầu ra bắt buộc
Sau khi kiểm xong, agent phải xuất đúng cấu trúc báo cáo sau:

### 1. Tóm tắt
- môi trường kiểm
- commit/hash hoặc nhánh kiểm tra
- dữ liệu test đã dùng

### 2. Bảng kết quả
Dùng đúng cấu trúc:

| Hạng mục | Case | Kết quả | Ghi chú |
|---|---|---|---|
| Bán hàng | CASE 03A thanh toán đủ | Pass/Fail | ... |
| Bán hàng | CASE 03B trả thiếu | Pass/Fail | ... |
| Bán hàng | CASE 03C chưa thanh toán | Pass/Fail | ... |
| Bán hàng | CASE 03D giảm giá dòng | Pass/Fail | ... |
| Bán hàng | CASE 03E giảm giá hóa đơn | Pass/Fail | ... |
| Bán hàng | CASE 03F thêm nhanh khách | Pass/Fail | ... |
| Bán hàng | CASE 03G thêm nhanh hàng | Pass/Fail/NA | ... |
| Bán hàng | CASE 03H đa phương thức | Pass/Fail/NA | ... |
| Bán hàng | CASE 03I lịch sử bán & công nợ | Pass/Fail | ... |

### 3. Danh sách lỗi
Mỗi lỗi ghi ngắn gọn nhưng đủ tái hiện.

### 4. Danh sách fix đã áp dụng (nếu có)
- file sửa
- lý do sửa
- cách kiểm lại

### 5. Kết luận cuối
- Pass / Fail / Pass with deviation
- nêu rõ có nên sang Flow 04 hay chưa

---

## Quy tắc an toàn khi sửa
- Không xóa dữ liệu production.
- Không chạy destructive migration trên môi trường thật.
- Không đổi dữ liệu thật nếu chưa có backup.
- Nếu không chắc, dừng ở mức báo lỗi + đề xuất fix.

---

## Chế độ làm việc mong muốn
Hãy làm việc như một QA + BA + Developer hỗn hợp:
- đọc hành vi hiện tại
- đối chiếu với hành vi mong đợi
- chứng minh bằng test
- sửa tối thiểu nếu sai
- re-test
- báo cáo rõ ràng

Khi xong Flow 03, dừng lại.
Không tự động sang Flow 04 cho đến khi được yêu cầu.
