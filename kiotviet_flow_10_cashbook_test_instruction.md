# KiotViet Flow 10 — Sổ quỹ / Phiếu thu chi / Chuyển quỹ nội bộ

## Mục tiêu

Flow này dùng để kiểm thử mức độ tương đồng của hệ thống với hành vi **Sổ quỹ** của KiotViet trong 4 nhóm nghiệp vụ cốt lõi:

1. Lập **phiếu thu / phiếu chi thủ công**.
2. Hạch toán **tiền mặt / ngân hàng / ví điện tử**.
3. **Chuyển quỹ nội bộ** (ví dụ: gửi tiền mặt vào ngân hàng hoặc chuyển giữa chi nhánh).
4. Quản lý vòng đời phiếu: **xem / cập nhật / hủy / tìm kiếm / lọc / sắp xếp / xuất file**.

Theo tài liệu KiotViet Retail Sổ quỹ, người dùng vào **Sổ quỹ**, chọn tab **Tiền mặt / Ngân hàng / Ví điện tử**, có thể tạo **+ Phiếu thu / + Phiếu chi**, chọn **Loại thu/chi**, **Đối tượng**, **Tên người nộp/nhận**, **Số tiền**, và tùy chọn **Hạch toán vào kết quả kinh doanh**. KiotViet cũng hỗ trợ **chuyển quỹ nội bộ**, trong đó một phiếu bên nguồn sẽ tự sinh phiếu đối ứng bên quỹ đích; đồng thời cho phép **cập nhật, hủy, tìm kiếm, sắp xếp và xuất file** danh sách phiếu. Nguồn chuẩn tham chiếu là tài liệu KiotViet Retail Sổ quỹ. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))

---

## Phạm vi bắt buộc của Flow 10

Agent chỉ test đúng các nội dung sau:

- Quỹ tiền mặt.
- Quỹ ngân hàng.
- Phiếu thu thủ công.
- Phiếu chi thủ công.
- Chuyển quỹ nội bộ.
- Cập nhật phiếu.
- Hủy phiếu.
- Tìm kiếm / lọc / sắp xếp / xuất file.
- Tác động của phiếu tới số dư quỹ và các đối tượng liên quan nếu hệ thống có liên kết.

Không mở rộng sang báo cáo phân tích, kết quả HĐKD nâng cao, kế toán tổng hợp, hoặc các module ngoài Sổ quỹ nếu chưa có bằng chứng tài liệu hoặc source.

---

## Input tham chiếu từ KiotViet

Agent phải lấy các hành vi sau làm chuẩn đối chiếu:

- Sổ quỹ quản lý tập trung các khoản thu/chi tiền mặt và ngân hàng; phiếu thu/chi có thể gắn với lý do và đối tác cụ thể. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Lập phiếu thu/chi thủ công từ màn hình **Sổ quỹ**, chọn tab quỹ tương ứng, sau đó bấm **+ Phiếu thu / + Phiếu chi**. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Trên phiếu có các thông tin như **Loại thu/chi, Đối tượng, Tên người nộp/nhận, Số tiền** và tùy chọn **Hạch toán vào kết quả kinh doanh**. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Khi **gửi tiền mặt vào ngân hàng**, người dùng lập **phiếu chi** ở quỹ tiền mặt với loại **Chuyển/Rút**, chọn tài khoản nhận là tài khoản ngân hàng; hệ thống **tự tạo phiếu thu tương ứng** ở quỹ ngân hàng. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Khi **chuyển tiền giữa các chi nhánh**, hệ thống tạo phiếu đối ứng ở chi nhánh nhận. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Phiếu có thể **cập nhật** và **hủy**; danh sách phiếu hỗ trợ **tìm kiếm, lọc, sắp xếp, xuất file Excel**. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))

---

## Bộ dữ liệu test cố định

Agent phải chuẩn bị hoặc xác nhận các dữ liệu sau trên môi trường test:

### Quỹ
- `CASH_MAIN`: Quỹ tiền mặt chính.
- `BANK_VCB`: Tài khoản ngân hàng VCB.

### Đối tượng
- `KH001`: Nguyễn Văn A.
- `NCC001`: Công ty Minh Phát.
- `DTK001`: Đối tượng khác / Không liên kết công nợ (nếu hệ thống hỗ trợ).

### User
- `admin`: toàn quyền tài chính / sổ quỹ.
- `cashier01`: được lập phiếu thu chi, không được sửa cấu hình hệ thống.
- `viewer01`: chỉ xem, không được lập hoặc hủy phiếu.

### Số dư đầu kỳ khuyến nghị
- Quỹ tiền mặt ban đầu: `10,000,000`.
- Ngân hàng ban đầu: `50,000,000`.

Nếu hệ thống không có trường số dư đầu kỳ hoặc cơ chế khởi tạo quỹ, agent phải ghi rõ khác biệt đó trong báo cáo.

---

## Nguyên tắc test bắt buộc

1. Đọc source trước khi sửa.
2. Chỉ sửa khi đã chứng minh được hành vi hiện tại lệch với KiotViet hoặc tự mâu thuẫn nghiệp vụ.
3. Nếu thiếu tính năng nhưng không thuộc phạm vi source hiện có, không tự ý dựng module mới quá lớn; chỉ ghi **Gap**.
4. Mỗi lỗi phải có:
   - bước tái hiện,
   - kết quả thực tế,
   - kết quả mong đợi,
   - file / function liên quan,
   - patch tối thiểu,
   - kết quả re-test.
5. Không được sửa lan sang module bán hàng / nhập hàng / công nợ nếu chưa chứng minh phụ thuộc trực tiếp.

---

## Checklist đọc source trước khi chạy

Agent phải tìm và liệt kê:

- model / entity của quỹ, cashbook, wallet, bank account,
- model / entity phiếu thu chi,
- bảng lưu số dư quỹ hoặc nhật ký quỹ,
- service tạo phiếu thu,
- service tạo phiếu chi,
- service hủy phiếu,
- service chuyển quỹ nội bộ,
- API / controller / route của Sổ quỹ,
- UI page / component danh sách và form phiếu,
- export excel nếu có,
- policy / permission liên quan.

Nếu source không có khái niệm “quỹ” tách riêng mà chỉ có “payment transactions”, agent vẫn phải map tương đương nghiệp vụ nếu có thể, sau đó ghi rõ điểm lệch kiến trúc.

---

## FLOW 10A — Lập phiếu thu thủ công ở quỹ tiền mặt

### Mục tiêu
Kiểm tra tạo phiếu thu thủ công đúng trường dữ liệu và cộng quỹ đúng.

### Bước test
1. Đăng nhập bằng `cashier01` hoặc `admin`.
2. Vào **Sổ quỹ**.
3. Chọn tab **Tiền mặt**.
4. Bấm **+ Phiếu thu**.
5. Nhập:
   - Loại thu: Thu khác.
   - Đối tượng: `DTK001` hoặc đối tượng tự do.
   - Tên người nộp: `Nguyễn Văn B`.
   - Số tiền: `1,200,000`.
   - Ghi chú: `Thu tiền cho thuê mặt bằng test`.
   - Hạch toán vào KQKD: bật nếu hệ thống có.
6. Lưu phiếu.

### Kết quả mong đợi
- Phiếu được tạo thành công.
- Quỹ tiền mặt tăng đúng `1,200,000`.
- Phiếu xuất hiện trong danh sách sổ quỹ tiền mặt.
- Nếu hệ thống có mã phiếu, mã phiếu là duy nhất.
- Nếu hệ thống có journal / log, có bản ghi phát sinh.

### Sai lệch nghiêm trọng
- Tạo phiếu xong nhưng số dư quỹ không đổi.
- Phiếu hiển thị ở sai quỹ.
- Số tiền lưu sai dấu / sai định dạng.
- Không lưu được vì validation vô lý.

---

## FLOW 10B — Lập phiếu chi thủ công ở quỹ tiền mặt

### Mục tiêu
Kiểm tra tạo phiếu chi thủ công và trừ quỹ đúng.

### Bước test
1. Ở quỹ tiền mặt, bấm **+ Phiếu chi**.
2. Nhập:
   - Loại chi: Chi khác.
   - Đối tượng: `DTK001`.
   - Tên người nhận: `Trần Văn C`.
   - Số tiền: `300,000`.
   - Ghi chú: `Chi mua văn phòng phẩm test`.
3. Lưu.

### Kết quả mong đợi
- Phiếu chi được tạo.
- Quỹ tiền mặt giảm đúng `300,000`.
- Danh sách phiếu có thêm bản ghi mới.
- Nếu hệ thống chặn âm quỹ, hành vi phải rõ ràng và nhất quán.

### Sai lệch nghiêm trọng
- Lưu phiếu chi nhưng quỹ không giảm.
- Cho chi vượt quỹ trái với rule hệ thống đã định mà không cảnh báo.
- Hủy / reload làm số dư thay đổi bất thường.

---

## FLOW 10C — Lập phiếu thu thủ công ở quỹ ngân hàng

### Mục tiêu
Kiểm tra tách đúng quỹ tiền mặt và quỹ ngân hàng.

### Bước test
1. Chọn tab **Ngân hàng**.
2. Bấm **+ Phiếu thu**.
3. Nhập số tiền `5,000,000`, loại thu phù hợp, tên người nộp `Công ty ABC`.
4. Lưu.

### Kết quả mong đợi
- Chỉ số dư **ngân hàng** tăng.
- Quỹ tiền mặt không đổi.
- Phiếu nằm ở tab ngân hàng.

### Sai lệch nghiêm trọng
- Thu ở ngân hàng lại làm tăng tiền mặt.
- Một phiếu xuất hiện đồng thời ở nhiều quỹ mà không có quan hệ đối ứng rõ ràng.

---

## FLOW 10D — Cập nhật phiếu thu/chi

### Mục tiêu
Kiểm tra mở phiếu, sửa thông tin, và quỹ cập nhật lại đúng.

### Bước test
1. Mở phiếu thu ở `10A`.
2. Sửa số tiền từ `1,200,000` thành `1,500,000`.
3. Sửa ghi chú.
4. Lưu.

### Kết quả mong đợi
- Phiếu cập nhật thành công.
- Số dư quỹ được điều chỉnh chênh lệch đúng `+300,000` so với trước cập nhật.
- Không tạo phiếu mới ngoài ý muốn.
- Log cập nhật được ghi nhận nếu hệ thống có audit.

### Sai lệch nghiêm trọng
- Sửa phiếu tạo thêm bản ghi trùng.
- Số dư quỹ bị cộng dồn sai.
- UI cập nhật thành công nhưng DB không đổi, hoặc ngược lại.

---

## FLOW 10E — Hủy phiếu thu/chi

### Mục tiêu
Kiểm tra hủy phiếu và hoàn nguyên số dư.

### Bước test
1. Chọn phiếu chi ở `10B`.
2. Thực hiện **Hủy**.
3. Xác nhận thao tác.

### Kết quả mong đợi
- Phiếu chuyển sang trạng thái hủy, hoặc bị loại khỏi danh sách hoạt động tùy thiết kế.
- Số dư quỹ được hoàn nguyên đúng.
- Phiếu hủy vẫn còn dấu vết truy vết nếu hệ thống có log / trạng thái.

### Sai lệch nghiêm trọng
- Hủy phiếu nhưng quỹ không hoàn nguyên.
- Xóa cứng phiếu làm mất lịch sử không dấu vết.
- Hủy nhiều lần làm lệch số dư.

---

## FLOW 10F — Chuyển quỹ nội bộ: Tiền mặt -> Ngân hàng

### Mục tiêu
Kiểm tra đúng hành vi đối ứng tự động giống KiotViet.

### Bước test
1. Ở quỹ tiền mặt, lập **Phiếu chi**.
2. Loại chi: **Chuyển/Rút** hoặc tương đương.
3. Chọn tài khoản nhận: `BANK_VCB`.
4. Số tiền: `2,000,000`.
5. Lưu.

### Kết quả mong đợi
- Quỹ tiền mặt giảm `2,000,000`.
- Hệ thống **tự tạo phiếu thu đối ứng** ở quỹ ngân hàng với cùng số tiền hoặc liên kết tương đương. Đây là hành vi KiotViet mô tả rõ. ([KiotViet Retail Sổ quỹ](https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-so-quy/so-quy/))
- Hai phiếu có thể truy ngược quan hệ với nhau nếu hệ thống hỗ trợ reference.

### Sai lệch nghiêm trọng
- Chỉ trừ tiền mặt mà không tăng ngân hàng.
- Tạo hai phiếu nhưng số tiền không khớp.
- Hủy phiếu nguồn mà phiếu đối ứng không cập nhật.

---

## FLOW 10G — Hủy chuyển quỹ nội bộ

### Mục tiêu
Kiểm tra rollback đầy đủ cặp phiếu đối ứng.

### Bước test
1. Mở giao dịch chuyển quỹ ở `10F`.
2. Hủy phiếu nguồn hoặc giao dịch chuyển quỹ.

### Kết quả mong đợi
- Tiền mặt trở về trước khi chuyển.
- Ngân hàng trở về trước khi nhận.
- Cặp phiếu đối ứng được hủy / rollback nhất quán.

### Sai lệch nghiêm trọng
- Hủy một bên nhưng bên còn lại vẫn giữ số dư.
- Số dư cuối không quay về đúng mốc trước giao dịch.

---

## FLOW 10H — Tìm kiếm, lọc, sắp xếp danh sách phiếu

### Mục tiêu
Kiểm tra khả năng truy vết sổ quỹ.

### Bước test
1. Tìm theo mã phiếu của `10A` hoặc `10B`.
2. Lọc theo khoảng thời gian hôm nay.
3. Lọc theo trạng thái hoạt động / đã hủy nếu có.
4. Lọc theo loại thu/chi.
5. Sắp xếp theo thời gian tăng/giảm.

### Kết quả mong đợi
- Kết quả lọc đúng.
- Không bị lẫn quỹ khác nếu đang lọc theo quỹ.
- Sắp xếp không làm sai dữ liệu hiển thị.

### Sai lệch nghiêm trọng
- Tìm kiếm không ra phiếu vừa tạo.
- Bộ lọc trả kết quả sai quỹ / sai trạng thái.
- Sắp xếp chỉ đổi UI nhưng dữ liệu chi tiết mở ra bị lệch hàng.

---

## FLOW 10I — Xuất file danh sách sổ quỹ

### Mục tiêu
Kiểm tra export để đối soát.

### Bước test
1. Ở danh sách Sổ quỹ, áp bộ lọc nhỏ (ví dụ trong ngày).
2. Bấm **Xuất file**.
3. Mở file xuất ra nếu có thể.

### Kết quả mong đợi
- File được tạo thành công.
- Dòng dữ liệu khớp với danh sách đang lọc.
- Tổng tiền và trạng thái không lệch.

### Sai lệch nghiêm trọng
- File export thiếu phiếu vừa tạo.
- Số liệu trên file khác màn hình.
- Export lỗi encoding / sai cột cơ bản.

---

## FLOW 10J — Phân quyền Sổ quỹ

### Mục tiêu
Kiểm tra user chỉ được làm đúng quyền.

### Bước test
1. Đăng nhập bằng `viewer01`.
2. Vào Sổ quỹ.
3. Thử tạo phiếu thu / chi.
4. Thử hủy phiếu.
5. Thử export nếu quyền có giới hạn.

### Kết quả mong đợi
- `viewer01` chỉ xem nếu đúng policy.
- Không thể tạo / sửa / hủy nếu không có quyền.
- `cashier01` được tạo nhưng có thể không được hủy nếu hệ thống phân tách quyền.

### Sai lệch nghiêm trọng
- User không quyền vẫn tạo hoặc hủy phiếu.
- Chỉ chặn UI nhưng gọi API trực tiếp vẫn thao tác được.

---

## FLOW 10K — Kiểm tra nhất quán số dư cuối

### Mục tiêu
Đối soát lại sau khi chạy toàn bộ flow.

### Công thức đối soát
Số dư cuối của từng quỹ phải bằng:

`Số dư đầu kỳ + Tổng phiếu thu hợp lệ - Tổng phiếu chi hợp lệ ± giao dịch đối ứng nội bộ còn hiệu lực`

Agent phải tính lại ít nhất cho:
- `CASH_MAIN`
- `BANK_VCB`

Nếu hệ thống có lưu ledger / entries, phải đối chiếu cả bảng số dư tổng hợp và bảng nhật ký.

---

## Quy tắc sửa lỗi

Agent chỉ được sửa theo thứ tự:

1. Validation sai.
2. Mapping sai quỹ / sai loại phiếu.
3. Sai cập nhật số dư.
4. Sai rollback khi hủy.
5. Sai tạo phiếu đối ứng chuyển quỹ.
6. Sai phân quyền.
7. Sai export / filter / sort.

Không được tái cấu trúc lớn nếu chỉ một bug cục bộ có thể sửa bằng patch nhỏ hơn.

---

## Mẫu báo cáo bắt buộc

```md
# Flow 10 Report — Cashbook / Receipt / Payment / Internal Transfer

## 1. Source inspected
- Models:
- Services:
- Controllers / Routes:
- UI screens:
- Permissions:

## 2. Test results by case
- 10A: PASS / FAIL / PASS WITH DEVIATION
- 10B: PASS / FAIL / PASS WITH DEVIATION
- 10C: PASS / FAIL / PASS WITH DEVIATION
- 10D: PASS / FAIL / PASS WITH DEVIATION
- 10E: PASS / FAIL / PASS WITH DEVIATION
- 10F: PASS / FAIL / PASS WITH DEVIATION
- 10G: PASS / FAIL / PASS WITH DEVIATION
- 10H: PASS / FAIL / PASS WITH DEVIATION
- 10I: PASS / FAIL / PASS WITH DEVIATION
- 10J: PASS / FAIL / PASS WITH DEVIATION
- 10K: PASS / FAIL / PASS WITH DEVIATION

## 3. Defects found
For each defect:
- Case:
- Reproduction steps:
- Actual result:
- Expected result:
- Root cause:
- Files changed:
- Patch summary:

## 4. Retest results
- Case:
- Before:
- After:

## 5. Remaining gaps vs KiotViet
- Gap 1:
- Gap 2:

## 6. Final verdict
- Equivalent enough / Not equivalent
- Blocking issues:
```

---

## Tiêu chí kết luận Flow 10 đạt

Flow 10 chỉ được coi là đạt khi:

- Phiếu thu/chi thủ công tạo được đúng quỹ.
- Số dư quỹ tăng/giảm chính xác.
- Cập nhật phiếu điều chỉnh đúng số dư.
- Hủy phiếu hoàn nguyên chính xác.
- Chuyển quỹ nội bộ sinh đúng bút toán / phiếu đối ứng.
- Tìm kiếm, lọc, sắp xếp, export hoạt động nhất quán.
- Phân quyền không hở ở UI lẫn API.
- Số dư cuối cùng cân đúng với nhật ký phát sinh.

Nếu còn sai ở bất kỳ điểm nào trên, agent phải đánh dấu **Flow 10 = FAIL** hoặc **PASS WITH DEVIATION**, không được ghi PASS toàn phần.
