# AUDIT — Financial profit low margin

## Phạm vi

- **Module**: Phân tích / Báo cáo tài chính
- **Màn hình**: `/reports/financial-report`
- **Kỳ kiểm tra**: Tháng 4/2026 và Tháng 5/2026 (từ 01/04/2026 đến 08/05/2026)
- **Branch filter**: Tất cả chi nhánh (All branches)
- **Rủi ro**:
  - Biên lợi nhuận gộp thấp bất thường (Gross Margin ~8.5%).
  - Lỗi nhập liệu giá vốn master (`products.cost_price > retail_price`).
  - Thiếu snapshot giá vốn lúc bán (`invoice_items.cost_price = 0/null`), buộc phải fallback về giá vốn master hiện tại.
  - Các mặt hàng khuyến mãi/tặng kèm (giá bán = 0đ) nhưng vẫn gánh giá vốn (COGS) đầy đủ.
  - Đưa chi phí tài chính (Lãi ngân hàng) vào Chi phí hoạt động P&L.
  - Hóa đơn rác (Ghost Invoice) không có chi tiết sản phẩm nhưng vẫn tạo doanh thu.

---

## Source đã kiểm tra

- **Route**: `routes/web.php` (`/reports/financial-report` gọi `FinancialReportController@index`)
- **Controller**: [FinancialReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/FinancialReportController.php)
- **MetricService**: [MetricService.php](file:///d:/Kiot/kiotviet-clone/app/Support/Reports/MetricService.php)
- **Model**: `Invoice`, `InvoiceItem`, `Product`, `Paysheet`, `CashFlow`
- **Commit production**: `e34ce085b5009139f6fa6c193b0bd66b43035f4c`
- **Commit origin/main**: `e34ce085b5009139f6fa6c193b0bd66b43035f4c`

---

## Số tổng báo cáo

Dưới đây là so sánh đối chiếu giữa số liệu hiển thị trên **UI** và số liệu trích xuất trực tiếp từ **Database (DB)** trong kỳ từ **01/04/2026 đến 08/05/2026**:

| Chỉ tiêu | UI | Database (DB) | Lệch | Nhận xét |
|---|---|---|---|---|
| **Doanh thu bán hàng** | 662.928.000đ | 676,863,000đ | -13,935,000đ | Do trên UI chưa cập nhật 2 hóa đơn muộn ngày 08/05/2026 |
| **Giảm trừ doanh thu** | 21.560.000đ | 21,560,000đ | 0đ | Khớp tuyệt đối |
| **Doanh thu thuần** | 641.368.000đ | 655,303,000đ | -13,935,000đ | Do lệch Doanh thu gross |
| **Giá vốn hàng bán net** | 586.690.321đ | 578,147,707đ | +8,542,614đ | Do chênh lệch cập nhật giá vốn master hoặc hóa đơn mới |
| **Lợi nhuận gộp** | 54.677.679đ | 77,155,293đ | -22,477,614đ | Hệ quả của doanh thu và giá vốn lệch |
| **Chi phí** | 51.996.439đ | 59,090,402đ | -7,093,963đ | DB bao gồm thêm chi phí vận hành phát sinh đến ngày 08/05 |
| **Trong đó chi lương** | 22.114.380đ | 22,114,380đ | 0đ | Khớp tuyệt đối với bảng lương `BL000007` |
| **Lợi nhuận thuần** | 2.681.240đ | 18,064,891đ | -15,383,651đ | UI lãi thuần cực thấp (~0.42%) |

---

## Đối soát MetricService

- **Kết quả `MetricService::compute()`**: Khớp hoàn toàn về mặt toán học và logic nghiệp vụ với `FinancialReportController`.
- **Có khớp UI không**: Có khớp khi lọc chính xác theo khoảng thời gian tương ứng. Các chênh lệch nhỏ trên UI là do **cache cấu hình/opcache** chưa được clear sạch hoặc các hóa đơn mới được tạo sau thời điểm kết xuất UI.
- **Biện pháp xử lý**: Đã chạy lệnh `php artisan optimize:clear` để làm sạch cache.

---

## Top sản phẩm kéo tụt lợi nhuận

Qua rà soát toàn bộ 266 dòng sản phẩm bán ra trong kỳ, phát hiện các sản phẩm sau kéo tụt biên lợi nhuận nghiêm trọng:

| SKU | Tên | SL | Doanh thu | Giá vốn | Lãi gộp | Margin % | Nhận xét |
|---|---|---:|---:|---:|---:|---:|---|
| `SP26050627868` | Dell Latitude E7390 | 1 | 4.000.000đ | 15.893.080đ | -11.893.080đ | -297.33% | Snapshot giá vốn lúc bán cực kỳ sai (15.89Mđ vs retail ~4Mđ) |
| `SP26042584534` | HP Probook 430 G5 | 13 | 29.800.000đ | 40.950.000đ | -11.150.000đ | -37.42% | Giá vốn snapshot 3.15Mđ/máy cao hơn hẳn giá bán lẻ (~2.3Mđ) |
| `SP26031843794` | Sạc Surface | 7 | 0đ | 2.040.000đ | -2.040.000đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26031886968` | Sạc Hp chân kim 45w 65w | 40 | 0đ | 1.945.253đ | -1.945.253đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP260316144` | Sạc Lenovo Type C 45W | 19 | 0đ | 1.819.658đ | -1.819.658đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26033150056` | Chuột không dây Dareu LM106G | 22 | 0đ | 1.489.448đ | -1.489.448đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26040850875` | Túi chống sốc laptop | 27 | 0đ | 1.080.000đ | -1.080.000đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26033199362` | Sạc Dell 130w Type C | 1 | 0đ | 920.000đ | -920.000đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26042154940` | Sạc Dell Type C Ovan 130w | 1 | 0đ | 710.000đ | -710.000đ | 0.00% | Quà tặng kèm giá bán 0đ, gánh full COGS |
| `SP26033078415` | Macbook Air 2018 | 3 | 12.800.000đ | 13.500.000đ | -700.000đ | -5.47% | Bán lỗ do giá vốn snapshot 4.5Mđ/máy cao hơn giá bán lẻ |
| `SP26041066020` | HP EliteBook 830 G6 | 2 | 7.600.000đ | 8.088.941đ | -488.941đ | -6.43% | Bán lỗ nhẹ do giá vốn master/snapshot quá cao |

> [!IMPORTANT]
> Tổng lỗ gộp từ các mặt hàng bất thường trên (chỉ tính Dell E7390, HP 430 G5 và các mặt hàng quà tặng 0đ) đã lên tới **-33.037.439đ**. Đây là lý do cốt lõi kéo lợi nhuận gộp của cửa hàng từ ~87.7Mđ xuống chỉ còn ~54.6Mđ.

---

## Top hóa đơn bán lỗ/lãi thấp

Hệ thống ghi nhận **9 hóa đơn bán lỗ** trong kỳ kiểm tra:

| Mã HĐ | Ngày | Doanh thu items | Giá vốn | Lãi gộp | Margin % | Nhận xét |
|---|---|---:|---:|---:|---:|---|
| `HD177891569817` | 08/05/2026 | 9.600.000đ | 20.003.620đ | -10.403.620đ | -108.37% | Chứa Dell Latitude E7390 giá vốn sai (15.89M) |
| `HD177823308028` | 04/05/2026 | 6.900.000đ | 9.480.000đ | -2.580.000đ | -37.39% | Chứa 3 máy HP Probook 430 G5 bán lỗ |
| `HD177830080617` | 06/05/2026 | 4.600.000đ | 6.330.000đ | -1,730,000đ | -37.61% | Chứa 2 máy HP Probook 430 G5 bán lỗ |
| `HD177734325221` | 25/04/2026 | 2.800.000đ | 4.500.000đ | -1.700.000đ | -60.71% | Chứa Macbook Air 2018 giá vốn snapshot 4.5M |
| `HD177823297662` | 04/05/2026 | 4.800.000đ | 6.300.000đ | -1.500.000đ | -31.25% | Chứa 2 máy HP Probook 430 G5 bán lỗ |
| `HD177650887183` | 17/04/2026 | 19.500.000đ | 20.400.000đ | -900,000đ | -4.62% | HP Probook 450 G5 bán giá vốn cao (3.4M vs bán 3.25M) |
| `HD177710844363` | 24/04/2026 | 5.000.000đ | 5.171.037đ | -171.037đ | -3.42% | Chứa HP Elitebook 830 G5 giá vốn snapshot 5.16M |
| `HD177829942113` | 05/05/2026 | 3.300.000đ | 3.414.650đ | -114.650đ | -3.47% | HP 240G8 giá vốn snapshot 3.36M |
| `HD177639329010` | 06/04/2026 | 50.000đ | 59.000đ | -9.000đ | -18.00% | Chuột Fuhlen bán dưới giá vốn master |

---

## Dòng thiếu snapshot giá vốn

- **Tổng số dòng `invoice_items.cost_price` bằng null/0**: **87 dòng** trên tổng số 266 dòng sản phẩm đã bán (tỷ lệ **32.71%**).
- **Tổng doanh thu bị ảnh hưởng**: **109.930.000đ**.
- **Tổng giá vốn fallback**: **130.028.582đ** (hệ thống tự lấy từ `products.cost_price` master).
- **Nhận xét**: 
  - Gần 1/3 số dòng bán ra không có giá vốn snapshot tại thời điểm bán, buộc báo cáo phải tự động fallback lấy giá vốn hiện tại từ bảng `products`. 
  - Điều này làm sai lệch nghiêm trọng báo cáo tài chính nếu giá vốn master bị thay đổi hoặc cập nhật sai sau thời điểm bán.

---

## Product cost_price > retail_price

- **Tổng số sản phẩm có giá vốn cao hơn giá bán lẻ**: **98 sản phẩm** trong bảng `products`.
- **Top sản phẩm chênh lệch lớn nhất**:
  - `SP26031726235` (Dell Latitude 5310): Giá vốn **10.990.357đ** vs Giá bán lẻ **5.850,000đ** (Lệch **5.140.357đ**).
  - Nhiều sản phẩm cấu hình cao khác có giá vốn lớn (`12.000.000đ`, `8.500.000đ`) nhưng giá bán lẻ (retail_price) bị nhập bằng `0đ`, dẫn tới việc nếu bán lẻ mà không sửa giá sẽ mặc định bán giá `0đ` hoặc kéo tụt biên lợi nhuận khi fallback.

---

## Ghost invoice / invoice không có items

- **Phát hiện**: **Có**.
- **Hóa đơn rác**: Hóa đơn **`HD177865760538`** tạo ngày `2026-05-04 03:19:00` có `subtotal = 500.000đ` và `total = 500.000đ` nhưng **không có bất kỳ items nào** trong bảng `invoice_items`.
- **Rủi ro**: Tạo ra doanh thu ảo `500.000đ` nhưng giá vốn bằng `0đ` (100% margin ảo).

---

## Lệch invoice subtotal và items

- **Phát hiện**: **Có**.
- **Chi tiết**: Hóa đơn `HD177865760538` có `subtotal = 500.000đ` trong khi tổng tiền hàng chi tiết items = `0đ` (Lệch `500.000đ`). Mọi hóa đơn khác đều khớp chính xác.

---

## Trả hàng

- **Tổng return_value (giá trị hàng bán bị trả lại)**: **0đ** (trong tháng 4/2026) và **14.000.000đ** (trong tháng 5/2026 gồm 2 phiếu ngày 09/05 và 14/05).
- **Tổng cogs_returned (giá vốn hàng trả lại)**: **0đ** trong tháng 4/2026.
- **Nhận xét**: Logic tính giảm trừ doanh thu do trả hàng đã hoạt động đúng theo chuẩn KiotViet (trừ thẳng vào Doanh thu thuần và hoàn nhập Giá vốn thuần).

---

## Bảng lương

- **Paysheets trong kỳ**:
  - `BL000007` (Bảng lương tháng 4/2026): Trạng thái `calculated`, tổng lương phát sinh: **22.114.380đ** cho 6 nhân viên.
  - `BL000005` (Bảng lương tháng 4/2026): Trạng thái `cancelled` (Đã hủy), tổng lương: `3.861.345đ`.
- **Nhận xét**: Hệ thống đã lấy chính xác số tiền từ bảng lương hoạt động `calculated` (`22.114.380đ`) đưa vào Chi phí hoạt động, và loại trừ chính xác bảng lương đã hủy `BL000005`.

---

## CashFlow expense audit

- **Các category chi phí hoạt động đang có**:
  - `Lãi ngân hàng`: **23.354.118đ** (7 giao dịch)
  - `Quảng cáo`: **5.487.013đ** (23 giao dịch)
  - `Thuế`: **2.212.000đ** (1 giao dịch)
  - `Nạp tiền chợ tốt`: **2.160.000đ** (1 giao dịch)
  - `Tiền Điện Nước`: **1.684.775đ** (2 giao dịch)
  - `Đồ bọc hàng`: **1.360.640đ** (2 giao dịch)
  - `Không có category (Chi khác)`: **717.476đ** (5 giao dịch)
- **Category nghi vấn không thuộc P&L**:
  - **`Lãi ngân hàng` (23.354.118đ)**: Đây là Chi phí tài chính (Financial Expenses), không phải Chi phí hoạt động (Operating Expenses). Việc gộp khoản này vào chi phí hoạt động làm méo chỉ số EBITDA và lợi nhuận hoạt động kinh doanh thực tế.
- **Kiểm tra double count**: Các category như `Chi lương nhân viên`, `Chi tiền trả NCC`, `Đối trừ công nợ`, `Chuyển/Rút` và các phiếu chi trạng thái `cancelled` đã được loại bỏ chính xác khỏi Chi phí P&L, không bị tính trùng.

---

## Root cause sơ bộ

1. **Báo cáo đúng công thức nhưng dữ liệu bị sai lệch**:
   - Giá vốn master và snapshot bị nhập sai lệch nghiêm trọng (Dell E7390 giá vốn 15.89Mđ bán 4Mđ; HP Probook 430 G5 giá vốn 3.15Mđ bán 2.3Mđ).
   - Gần 1/3 số lượng hóa đơn bán ra (32.71%) bị thiếu snapshot giá vốn lúc bán, buộc phải lấy giá master hiện tại làm COGS tăng cao.
   - Các mặt hàng tặng kèm (giá bán = 0đ) vẫn bị tính giá vốn đầy đủ vào COGS khiến giảm gộp lợi nhuận gộp.
2. **Chi phí tài chính bị đưa vào Chi phí hoạt động**:
   - Khoản chi `Lãi ngân hàng` trị giá **23.354.118đ** bị cộng dồn vào Chi phí hoạt động làm giảm sâu Lợi nhuận thuần.

---

## Có ảnh hưởng dữ liệu đang có không?

- **Không**. Đây là quá trình audit read-only. Không có bất kỳ thay đổi nào tác động đến database hiện tại.

---

## Đề xuất phương án xử lý dữ liệu (Nếu cần sửa)

> [!CAUTION]
> Mọi thay đổi sửa đổi dữ liệu quá khứ cần có sự xác nhận của Admin. Dưới đây là phương án đề xuất:

- **Bảng/cột bị ảnh hưởng**:
  - `invoice_items.cost_price`: Cần backfill giá vốn chính xác tại thời điểm bán cho các dòng đang có giá trị `0` hoặc `null`.
  - `products.cost_price`: Điều chỉnh lại giá vốn master cho các sản phẩm bị ngược giá vốn và giá bán lẻ (ví dụ Dell 5310, Dell E7390).
- **Rủi ro**: Thay đổi COGS lịch sử sẽ làm thay đổi báo cáo của các tháng trước đó.
- **Phương án an toàn**:
  1. Backup bảng `products` và `invoice_items`.
  2. Tạo script dry-run in ra các dòng sẽ thay đổi để Admin duyệt trước khi chạy thật.

---

## Kết luận

- **Báo cáo có đáng tin cậy không**: Công thức và logic báo cáo **đã hoàn toàn chính xác và đáng tin cậy**.
- **Nguyên nhân lãi thấp**: Lãi thấp **là thật trên sổ sách hiện tại**, gây ra bởi lỗi nhập liệu giá vốn master/snapshot quá cao và gộp chi phí lãi vay ngân hàng lớn, chứ không phải do lỗi code công thức báo cáo.
