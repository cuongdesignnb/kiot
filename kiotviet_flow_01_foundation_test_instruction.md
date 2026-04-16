# KIOTVIET FLOW 01 — KIỂM THỬ DỮ LIỆU NỀN

## Mục tiêu
Bạn là AI agent kiểm thử hệ thống quản lý bán hàng nội bộ.
Nhiệm vụ của bạn là kiểm tra **Flow 01: Dữ liệu nền** của hệ thống hiện tại, theo hành vi tham chiếu từ tài liệu KiotViet.

Flow này chỉ tập trung vào 4 nhóm dữ liệu nền:
1. Kho hàng
2. Hàng hóa
3. Khách hàng
4. Nhà cung cấp

Không test các flow khác trong lần này.
Không tự mở rộng sang nhập hàng, bán hàng, công nợ, kiểm kho, báo cáo.
Chỉ được kiểm thử đúng Flow 01.

---

## Nguyên tắc làm việc bắt buộc
1. Chỉ kiểm thử **một flow duy nhất**: Flow 01.
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
6. Không sửa các phần ngoài Flow 01.
7. Sau mỗi thay đổi, phải re-test đúng case vừa sửa.
8. Không kết luận “pass” nếu chưa có bằng chứng.

---

## Chuẩn tham chiếu của Flow 01
Flow 01 bám theo logic dùng trước giao dịch của KiotViet:
- Có thể quản lý kho hàng.
- Có thể thêm hàng hóa với mã, tên, giá vốn, giá bán, tồn đầu.
- Có thể thêm khách hàng từ danh mục hoặc thêm nhanh khi bán hàng.
- Có thể thêm nhà cung cấp từ danh mục hoặc thêm nhanh khi nhập hàng.
- Có logic ngừng hoạt động / xóa mà không làm mất lịch sử cũ.

Lưu ý:
- Tên khách hàng là bắt buộc.
- Tên nhà cung cấp là bắt buộc.
- Số điện thoại khách hàng không được trùng với khách hàng khác nếu hệ thống chọn bám sát KiotViet.
- Số điện thoại nhà cung cấp không được trùng với nhà cung cấp khác nếu hệ thống chọn bám sát KiotViet.
- Kho ngừng hoạt động hoặc xóa không được làm vỡ dữ liệu cũ.
- Hàng hóa có tồn đầu phải ghi nhận đúng tồn nền.

---

## Phạm vi kiểm tra
### A. Kho hàng
Cần kiểm:
- tạo kho mới
- sửa kho
- ngừng hoạt động kho
- hoạt động lại kho
- xóa kho
- kiểm tra kho có dùng được ở dropdown nghiệp vụ hay không

### B. Hàng hóa
Cần kiểm:
- tạo hàng hóa mới
- có mã, tên, giá vốn, giá bán, tồn đầu
- tìm lại theo mã / tên
- sửa thông tin hàng hóa
- kiểm tra tồn đầu có ghi đúng không
- kiểm tra hàng tạo xong có dùng được ở luồng nghiệp vụ sau không

### C. Khách hàng
Cần kiểm:
- tạo từ danh mục
- thêm nhanh trong màn hình bán hàng
- tìm lại theo tên / SĐT
- ngừng hoạt động
- hoạt động lại
- xóa
- giữ lịch sử cũ

### D. Nhà cung cấp
Cần kiểm:
- tạo từ danh mục
- thêm nhanh trong màn hình nhập hàng
- tìm lại
- ngừng hoạt động
- hoạt động lại
- xóa
- giữ lịch sử cũ

---

## Bộ dữ liệu test cố định
Sử dụng đúng bộ dữ liệu sau để tránh test ngẫu nhiên:

### Kho
- code: `KHO_TONG`
- name: `Kho tổng`

### Hàng hóa 1
- code: `SP001`
- name: `Nước suối 500ml`
- cost_price: `5000`
- sale_price: `7000`
- opening_stock: `20`

### Hàng hóa 2
- code: `SP002`
- name: `Bánh quy hộp`
- cost_price: `20000`
- sale_price: `30000`
- opening_stock: `10`

### Khách hàng
- code: `KH001`
- name: `Nguyễn Văn A`
- phone: `0900000001`

### Nhà cung cấp
- code: `NCC001`
- name: `Công ty Minh Phát`
- phone: `0900000002`

Không tự đổi bộ dữ liệu trừ khi có lý do kỹ thuật bất khả kháng.
Nếu phải đổi, ghi rõ lý do.

---

## Trình tự kiểm thử bắt buộc

### Bước 1 — Đọc source để xác định nơi cần kiểm
Tìm và liệt kê:
- model / entity của kho, hàng hóa, khách hàng, nhà cung cấp
- migration / schema / constraint liên quan
- route / controller / service / repository
- validation rule
- màn hình UI hoặc endpoint API tạo/sửa/xóa/ngừng hoạt động

Kết quả cần ghi ra:
- file nào điều khiển từng đối tượng
- rule nào đang áp dụng
- điểm nào nghi ngờ lệch với hành vi tham chiếu

### Bước 2 — Kiểm tra database/schema
Kiểm tra tối thiểu:
- có unique index cho mã hay không
- có unique cho phone khách hàng / phone nhà cung cấp hay không
- có cột trạng thái hoặc soft delete hay không
- có cột opening_stock, initial_stock hoặc cơ chế thay thế hay không
- có ràng buộc khóa ngoại nào dễ làm vỡ dữ liệu khi xóa không

Nếu không có constraint nhưng code đang validate bằng app layer, phải ghi rõ.

### Bước 3 — Chạy test tạo kho
Thao tác:
1. Tạo kho `KHO_TONG`
2. Sửa tên hoặc mô tả
3. Ngừng hoạt động
4. Hoạt động lại
5. Xóa thử trên môi trường test

Cần kiểm tra:
- tạo thành công
- hiển thị trong danh sách
- không vỡ dữ liệu liên quan
- nếu có cơ chế chọn kho cho nghiệp vụ, kho phải dùng được

### Bước 4 — Chạy test tạo hàng hóa
Thao tác:
1. Tạo `SP001`
2. Tạo `SP002`
3. Gán giá vốn, giá bán, tồn đầu
4. Tìm lại theo mã và tên
5. Mở lại để sửa

Cần kiểm:
- lưu thành công
- dữ liệu hiển thị đúng
- tồn đầu ghi đúng
- không lỗi format số
- dùng được cho nghiệp vụ sau

### Bước 5 — Chạy test tạo khách hàng
Thao tác:
1. Tạo `KH001` từ danh mục
2. Tìm lại theo tên và SĐT
3. Nếu có màn hình bán hàng: thêm nhanh một khách mới ngay trên màn hình bán
4. Ngừng hoạt động khách hàng
5. Hoạt động lại
6. Xóa thử trên môi trường test

Cần kiểm:
- tên bắt buộc
- phone trùng bị chặn nếu target behavior là bám KiotViet
- thêm nhanh phải đồng bộ về danh mục
- ngừng hoạt động không mất lịch sử
- xóa không làm vỡ dữ liệu cũ

### Bước 6 — Chạy test tạo nhà cung cấp
Thao tác:
1. Tạo `NCC001` từ danh mục
2. Tìm lại
3. Nếu có màn hình nhập hàng: thêm nhanh một NCC mới ngay trên phiếu nhập
4. Ngừng hoạt động
5. Hoạt động lại
6. Xóa thử trên môi trường test

Cần kiểm:
- tên bắt buộc
- phone trùng bị chặn nếu target behavior là bám KiotViet
- thêm nhanh phải đồng bộ về danh mục
- ngừng hoạt động không mất lịch sử
- xóa không làm vỡ dữ liệu cũ

### Bước 7 — Kiểm tra khả năng dùng lại ở màn hình nghiệp vụ
Không test flow nghiệp vụ đầy đủ, chỉ test khả năng chọn dữ liệu nền:
- ở màn hình bán hàng: có tìm thấy hàng hóa và khách hàng vừa tạo không
- ở màn hình nhập hàng: có tìm thấy hàng hóa, NCC và kho vừa tạo không

Nếu không có UI, hãy kiểm bằng API hoặc query relation tương ứng.

### Bước 8 — Ghi nhận sai lệch
Mỗi lỗi phải ghi theo mẫu:
- ID lỗi
- Hạng mục
- Bước tái hiện
- Kết quả hiện tại
- Kết quả mong đợi
- Mức độ ảnh hưởng: Low / Medium / High / Critical
- Nguyên nhân nghi ngờ
- File liên quan
- Đề xuất fix tối thiểu

### Bước 9 — Chỉ sửa lỗi đã xác định
Nếu được phép sửa code:
- sửa nhỏ nhất có thể
- không đổi kiến trúc toàn hệ thống
- không đụng flow khác
- thêm test hoặc script tái hiện lỗi

### Bước 10 — Re-test và kết luận
Sau khi sửa:
- chạy lại đúng case lỗi
- chạy lại toàn bộ Flow 01
- kết luận từng hạng mục: Pass / Fail / Pass with deviation

---

## Điều kiện PASS cho Flow 01
Chỉ được kết luận PASS khi đồng thời đúng cả các điều kiện sau:
1. Tạo kho, hàng hóa, khách hàng, NCC đều thành công.
2. Dữ liệu tìm lại được theo cách hợp lý.
3. Tồn đầu hàng hóa ghi đúng.
4. Dữ liệu nền dùng lại được ở màn hình nghiệp vụ liên quan.
5. Ngừng hoạt động không làm mất lịch sử.
6. Xóa không làm vỡ dữ liệu cũ.
7. Không có lỗi nghiêm trọng về trùng dữ liệu, mất liên kết, sai trạng thái.

---

## Điều kiện FAIL nặng
Nếu có một trong các lỗi sau, kết luận Flow 01 chưa đạt:
- tạo xong nhưng không dùng lại được ở màn hình nghiệp vụ
- tồn đầu không ghi đúng
- thêm nhanh trong giao dịch không đồng bộ về danh mục
- xóa làm mất hoặc gãy dữ liệu cũ
- ngừng hoạt động nhưng vẫn phát sinh giao dịch mới trái rule mong muốn
- unique phone / unique code bị vỡ dẫn tới dữ liệu trùng gây rối

---

## Đầu ra bắt buộc
Agent phải trả về đủ 4 phần sau:

### 1. Tóm tắt kiểm thử
- Flow đang test
- phạm vi đã kiểm
- môi trường kiểm

### 2. Bảng kết quả
Dùng đúng cấu trúc:

| Hạng mục | Case | Kết quả | Ghi chú |
|---|---|---|---|
| Kho hàng | Tạo kho | Pass/Fail | ... |
| Kho hàng | Ngừng hoạt động | Pass/Fail | ... |
| Hàng hóa | Tạo SP001 | Pass/Fail | ... |
| Hàng hóa | Ghi tồn đầu | Pass/Fail | ... |
| Khách hàng | Thêm từ danh mục | Pass/Fail | ... |
| Khách hàng | Thêm nhanh khi bán | Pass/Fail | ... |
| NCC | Thêm từ danh mục | Pass/Fail | ... |
| NCC | Thêm nhanh khi nhập | Pass/Fail | ... |

### 3. Danh sách lỗi
Mỗi lỗi ghi ngắn gọn nhưng đủ tái hiện.

### 4. Danh sách fix đã áp dụng (nếu có)
- file sửa
- lý do sửa
- cách kiểm lại

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

Khi xong Flow 01, dừng lại.
Không tự động sang Flow 02 cho đến khi được yêu cầu.
