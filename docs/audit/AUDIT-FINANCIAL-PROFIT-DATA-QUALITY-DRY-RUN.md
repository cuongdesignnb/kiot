# AUDIT — Financial Profit Data Quality Dry-run

## Phạm vi

- **Module**: Phân tích / Báo cáo tài chính
- **Màn hình**: `/reports/financial-report`
- **Kỳ kiểm tra**: Mặc định từ 01/04/2026 đến 31/05/2026
- **Branch filter**: Tất cả chi nhánh (All branches)
- **Rủi ro**: Rất cao nếu sửa dữ liệu giá vốn/snapshot/hóa đơn trực tiếp trên production.

## Source đã kiểm tra

- **Route**: `routes/web.php`
- **Controller**: [FinancialReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/FinancialReportController.php)
- **MetricService**: [MetricService.php](file:///d:/Kiot/kiotviet-clone/app/Support/Reports/MetricService.php)
- **Model**: `Invoice`, `InvoiceItem`, `Product`, `OrderReturn`, `ReturnItem`, `CashFlow`, `Paysheet`
- **Migration**: Không có migration mới được thực thi.
- **Test**: [FinancialProfitDataAuditCommandTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Report/FinancialProfitDataAuditCommandTest.php)
- **Commit production nếu có**: dc7bc315b363a005a1813f4a61a3dc8a0fedac53
- **Commit origin/main**: dc7bc315b363a005a1813f4a61a3dc8a0fedac53

## Command đã chạy

- **Command**: `php artisan audit:financial-profit-data --from=2026-04-01 --to=2026-05-31 --limit=15`
- **Có export CSV không**: Có (khi chạy kèm `--export-csv`)
- **CSV path nếu có**: `storage/app/audit/financial-profit-data/YYYYMMDD-HHMMSS/`

## Tổng số liệu

- **Doanh thu bán hàng**: 690,863,000đ
- **Chiết khấu hóa đơn**: 21,560,000đ
- **Giá trị hàng bán bị trả lại**: 14,000,000đ
- **Doanh thu thuần**: 655,303,000đ
- **COGS sold**: 587,730,568đ
- **COGS returned**: 14,000,000đ
- **Giá vốn hàng bán net**: 573,730,568đ
- **Lợi nhuận gộp**: 81,572,433đ
- **Gross margin %**: 12.45%
- **Chi phí**: 69,462,441đ
- **Trong đó lương**: 22,114,380đ
- **Thu nhập khác**: 0đ
- **Chi phí khác**: 0đ
- **Lợi nhuận thuần**: 12,109,992đ
- **Net margin %**: 1.85%

## Phát hiện chính

- **Missing cost snapshot**: 89 dòng (ảnh hưởng 46,270,000đ doanh thu).
- **Product cost > retail**: 95 sản phẩm trong danh mục master.
- **Loss invoice**: 14 hóa đơn bán lỗ/lãi thấp.
- **Loss item**: Các dòng sản phẩm có giá vốn snapshot/fallback cao hơn giá bán lẻ.
- **Ghost invoice**: 111 hóa đơn rác (có total/subtotal > 0 nhưng không có items).
- **Subtotal mismatch**: 1 hóa đơn (`HD177891569817` lệch 20,000đ).
- **Zero price gift items**: 184 dòng quà tặng gánh tổng COGS 280,556,977đ.
- **CashFlow category issue**: `Lãi ngân hàng` trị giá 23,354,118đ nằm trong Chi phí hoạt động, nên tách thành Chi phí tài chính.
- **Payroll issue**: Chi phí lương 22,114,380đ được tính đúng từ bảng lương hoạt động (`BL000007`), loại trừ đúng bảng lương đã hủy (`BL000005`).

## Top sản phẩm kéo tụt lợi nhuận

| SKU | Tên | SL | Doanh thu | Giá vốn | Lãi gộp | Margin % | Lý do nghi vấn |
|---|---|---:|---:|---:|---:|---:|---|
| `SP26050627868` | Dell Latitude E7390 | 1 | 4,000,000 | 15,893,080 | -11,893,080 | -297.33% | Snapshot giá vốn cực kỳ sai lúc bán (15.89M vs bán 4M) |
| `SP26042584534` | HP Probook 430 G5 | 13 | 29,800,000 | 40,950,000 | -11,150,000 | -37.42% | Giá vốn master/snapshot 3.15M cao hơn giá bán lẻ |
| `SP26031843794` | Sạc Surface | 7 | 0 | 2,040,000 | -2,040,000 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26031886968` | Sạc Hp chân kim | 40 | 0 | 1,945,253 | -1,945,253 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP260316144` | Sạc Lenovo Type C | 19 | 0 | 1,819,658 | -1,819,658 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26033150056` | Chuột Dareu LM106G | 22 | 0 | 1,489,448 | -1,489,448 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26040850875` | Túi chống sốc laptop | 27 | 0 | 1,080,000 | -1,080,000 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26033199362` | Sạc Dell 130w Type C | 1 | 0 | 920,000 | -920,000 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26042154940` | Sạc Dell Type C Ovan | 1 | 0 | 710,000 | -710,000 | 0.00% | Quà tặng 0đ gánh full COGS |
| `SP26033078415` | Macbook Air 2018 | 3 | 12,800,000 | 13,500,000 | -700,000 | -5.47% | Bán lỗ do giá vốn snapshot cao |

## Top hóa đơn bán lỗ/lãi thấp

| Mã HĐ | Ngày | Doanh thu items | Giá vốn | Lãi gộp | Margin % | Nhận xét |
|---|---|---:|---:|---:|---:|---|
| `HD177891569817` | 08/05/2026 | 9,600,000 | 20,003,620 | -10,403,620 | -108.37% | Chứa Dell Latitude E7390 giá vốn sai |
| `HD177823308028` | 04/05/2026 | 6,900,000 | 9,480,000 | -2,580,000 | -37.39% | Chứa HP Probook 430 G5 bán lỗ |
| `HD177727627723` | 25/04/2026 | 6,900,000 | 9,468,000 | -2,568,000 | -37.22% | Chứa 3 máy HP Probook 430 G5 bán lỗ |
| `HD177830080617` | 06/05/2026 | 4,600,000 | 6,330,000 | -1,730,000 | -37.61% | Chứa HP Probook 430 G5 bán lỗ |
| `HD177734325221` | 25/04/2026 | 2,800,000 | 4,500,000 | -1,700,000 | -60.71% | Chứa Macbook Air 2018 bán lỗ |
| `HD177823297662` | 04/05/2026 | 4,800,000 | 6,300,000 | -1,500,000 | -31.25% | Chứa HP Probook 430 G5 bán lỗ |
| `HD177650887183` | 17/04/2026 | 19,500,000 | 20,691,241 | -1,191,241 | -6.11% | Bán lỗ do giá vốn snapshot cao |

## Missing cost snapshot

- **Tổng số dòng**: 89 dòng.
- **Tỷ lệ**: ~33% số dòng bán ra.
- **Doanh thu bị ảnh hưởng**: 46,270,000đ.
- **Fallback COGS**: 130,028,582đ.
- **Top dòng nghi vấn**: Các dòng sạc, túi tặng kèm và một số laptop thiếu snapshot giá vốn lúc bán, buộc phải lấy từ products master.

## Product cost_price > retail_price

- **Tổng số sản phẩm**: 95 sản phẩm.
- **Top sản phẩm**:
  - `SP26032720839` (Acer Nitro ANV15-51): Giá vốn 12,000,000đ vs Giá bán lẻ 0đ (Lệch 12M).
  - `SP26042118257` (Lenovo Ideapad Gaming 13): Giá vốn 8,500,000đ vs Giá bán lẻ 0đ.
  - `SP26031726235` (Dell Latitude 5310): Giá vốn 10,990,357đ vs Giá bán lẻ 5,850,000đ.
- **Rủi ro**: Bán lẻ mặc định giá 0đ hoặc bán lỗ nếu không sửa giá bán khi lên đơn hàng.

## Ghost invoice

- **Có/Không**: Có.
- **Danh sách**: 111 hóa đơn có subtotal hoặc total > 0 nhưng không có detail items nào trong `invoice_items`.
- **Rủi ro**: Tạo doanh thu khống không có giá vốn thực tế.

## Subtotal mismatch

- **Có/Không**: Có.
- **Danh sách**: 1 hóa đơn `HD177891569817` có subtotal = 9,600,000đ nhưng sum of item_revenue = 9,620,000đ (Lệch 20,000đ).
- **Rủi ro**: Sai lệch đối soát doanh thu giữa header hóa đơn và chi tiết mặt hàng bán.

## Zero price gift items

- **Tổng số dòng**: 184 dòng.
- **Tổng COGS**: 280,556,977đ.
- **Top sản phẩm**: Sạc Surface, Sạc Hp, Sạc Lenovo, Chuột Dareu, Túi chống sốc.
- **Nhận xét nghiệp vụ**: Quà tặng kèm 0đ đang gánh 100% chi phí giá vốn (COGS) trong báo cáo, làm giảm sâu biên lợi nhuận gộp.

## CashFlow P&L audit

- **Category đang vào operating expense**:
  - Quảng cáo: 10,850,142đ
  - Tiền Điện Nước: 1,684,775đ
  - Đồ bọc hàng: 2,103,920đ
  - Nạp tiền chợ tốt: 2,160,000đ
  - Thuế: 2,212,000đ
- **Category nên tách financial expense**:
  - `Lãi ngân hàng`: 23,354,118đ.
- **Category nên non-P&L**:
  - Chi tiền trả NCC, Thu nợ khách hàng, Thu tiền khách trả, Đối trừ công nợ, Hủy đối trừ công nợ, Chuyển/Rút.
- **Category cần Admin review**:
  - Null category (Chi khác): 1,858,106đ.

## Payroll audit

- **Paysheets được tính**: `BL000007` (Bảng lương tháng 4/2026) trạng thái `calculated` với tổng lương **22,114,380đ** được tính vào chi phí lương hoạt động.
- **Paysheets bị loại**: `BL000005` (Bảng lương tháng 4/2026) trạng thái `cancelled` (3,861,345đ) được loại trừ chính xác.
- **needs_recalc**: Cả hai bảng lương đều không có yêu cầu tính lại (`needs_recalc = 0`).
- **Nhận xét**: Logic khớp tuyệt đối và tích hợp an toàn.

## Root cause sơ bộ

1. **Dữ liệu đầu vào sai lệch nghiêm trọng**:
   - Nhập sai giá vốn master của sản phẩm (ví dụ Dell Latitude E7390 giá vốn 15.89Mđ bán lẻ 4Mđ).
   - ~33% hóa đơn thiếu snapshot giá vốn lúc bán khiến hệ thống fallback về giá master sai lệch.
   - Quà tặng 0đ gánh full COGS mà không được hạch toán giảm trừ/khuyến mãi hợp lệ.
   - Có hóa đơn rác (ghost invoices) và lệch subtotal.
2. **Chi phí tài chính chưa được tách biệt**:
   - `Lãi ngân hàng` lớn (23.35Mđ) đang hạch toán gộp vào chi phí hoạt động kinh doanh.

## Có ảnh hưởng dữ liệu đang có không?

- **Audit/dry-run**: KHÔNG. Toàn bộ logic chạy audit chỉ thực hiện SELECT dữ liệu và lưu báo cáo/CSV (nếu có), tuyệt đối không làm thay đổi database nghiệp vụ.

## Đề xuất bước tiếp theo

### Option A — Chỉ giữ audit, chưa sửa dữ liệu
- Admin kiểm tra các hóa đơn/sản phẩm sai lệch trên hệ thống qua báo cáo này để tự điều chỉnh thủ công.

### Option B — Tạo màn/CSV duyệt sửa giá vốn
- Xuất danh sách chi tiết các dòng thiếu/sai snapshot giá vốn và cho phép Admin phê duyệt hàng loạt các giá trị sửa đổi trước khi thực thi cập nhật.

### Option C — Backfill invoice_items.cost_price sau khi Admin duyệt
- Sau khi được phê duyệt, chạy script an toàn để cập nhật snapshot `cost_price` từ giá vốn lịch sử chính xác hoặc theo giá vốn bình quân di động tương ứng của sản phẩm đó.

### Option E — Tách Lãi ngân hàng khỏi Chi phí hoạt động
- Sửa đổi cách hiển thị trên báo cáo tài chính bằng cách tách nhóm Chi phí tài chính (Financial Expenses) riêng để không làm méo chỉ số EBITDA/Lợi nhuận hoạt động kinh doanh.

## Kết luận

- **Báo cáo hiện tại đúng/chưa đúng**: Công thức báo cáo **ĐÚNG** và chạy chính xác theo quy chuẩn.
- **Dữ liệu hiện tại đáng tin/chưa đáng tin**: Dữ liệu đầu vào **CHƯA ĐÁNG TIN** do các lỗi nhập liệu giá vốn master/snapshot và hóa đơn rác.
- **Có cần sửa code không**: Không cần sửa code P&L hiện tại, chỉ cần xem xét cấu trúc báo cáo tách chi phí tài chính (Option E).
- **Có cần sửa dữ liệu không**: Rất cần thiết (sửa giá vốn sản phẩm master và backfill snapshot giá vốn bị thiếu/sai).
- **Có cần xác nhận trước không**: BẮT BUỘC cần Admin xác nhận trước khi thay đổi dữ liệu quá khứ.
