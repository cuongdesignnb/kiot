# AUDIT REPORT: HOTFIX - Payroll Bonus Personal Gross Profit calculation source

## Bối cảnh & Hiện trạng
Nhân viên Sa Đình Cường tháng 05/2026 có báo cáo lợi nhuận theo nhân viên:
- Doanh thu thuần: 5.200.000đ
- Giá vốn: 3.471.606đ
- Lợi nhuận gộp: 1.728.394đ

Cấu hình lương:
- `has_bonus = true`
- `bonus_type = personal_gross_profit`
- `custom_bonuses` = 20%
- `has_commission = false`

Tuy nhiên, `SalaryCalculationService::calculateForEmployee()` tính toán ra:
- `personal_revenue = 5.200.000`
- `bonus = 0`
- `details.bonus[0].calculated = 0`

## Root Cause (Nguyên nhân gốc rễ)
1. **Sai nguồn dữ liệu**: Logic cũ trong `getPersonalGrossProfit()` và `getPersonalRevenue()` sử dụng `Order` model làm nguồn chính để tính toán doanh thu và lợi nhuận gộp cá nhân. Trong khi đó, module báo cáo lợi nhuận nhân viên (Employee Profit Report) và thực tế bán hàng sử dụng `Invoice` và `OrderReturn` để ghi nhận doanh thu thực tế và giá vốn hàng bán. Khi `orders_total = 0` và `invoices_total = 5.200.000` (đơn hàng bán trực tiếp qua POS sinh thẳng hóa đơn hoặc đã xóa/không qua Order), payroll không tính được bonus.
2. **Không đồng bộ công thức & người bán**: Báo cáo lợi nhuận nhân viên sử dụng `SellerResolver` để giải quyết các trường hợp "Chưa xác định người bán" hoặc map chính xác người bán theo snapshot tên (`seller_name`) hoặc id nhân viên (`created_by`). Logic cũ của Payroll tự query trực tiếp theo trường `created_by` trên `Order` hoặc `Invoice` mà không qua `SellerResolver`, dẫn tới sai lệch lớn về số liệu.

## Giải pháp & Các thay đổi đã thực hiện
1. **Đồng bộ hóa nguồn dữ liệu và logic**:
   - Sử dụng `Invoice` và `OrderReturn` làm nguồn chính thay cho `Order`.
   - Sử dụng `SellerResolver` để thực hiện query và tính toán doanh thu, giảm trừ trả hàng, giá vốn hàng bán và giá vốn hàng trả lại.

2. **Cập nhật `SalaryCalculationService.php`**:
   - Viết lại `getPersonalRevenue()` sử dụng `SellerResolver` để tính toán doanh thu thuần từ hóa đơn trừ đi giá trị trả hàng trong kỳ.
   - Viết lại `getPersonalGrossProfit()` sử dụng các hàm của `SellerResolver` như `aggregateBySeller`, `aggregateReturnsBySeller`, `cogsSoldBySeller`, và `cogsReturnedBySeller` nhằm đảm bảo đồng bộ 100% công thức tính toán với báo cáo lợi nhuận:
     `Lợi nhuận gộp = Doanh thu thuần (Hóa đơn - Chiết khấu hóa đơn - Trả hàng) - Giá vốn thuần (Giá vốn xuất - Giá vốn trả lại)`
   - Cập nhật các hàm `calculateBonus` và `calculateBonusFromList` để bổ sung thêm key `revenue` vào details phục vụ cho việc hiển thị chi tiết doanh thu/lợi nhuận tính thưởng trên frontend.

3. **Cập nhật & Viết thêm Unit Tests**:
   - Thêm 5 test cases ở cuối `tests/Feature/Payroll/ManualTimekeepingTest.php` để đảm bảo:
     1. Tính toán chính xác lợi nhuận gộp cá nhân với hóa đơn thông thường.
     2. Khấu trừ đúng giá trị và giá vốn hàng trả lại đối với các chứng từ trả hàng (`OrderReturn`).
     3. Tính toán đúng bonus từ Invoice kể cả khi `orders_total = 0`.
     4. Commission không bị ảnh hưởng và giữ nguyên 0đ khi `has_commission = false`.
     5. Đảm bảo parity (khớp số hoàn toàn) giữa logic tính của Payroll và logic của Employee Profit Report.

## Kết quả kiểm tra & Verification
- **Unit tests**: Tất cả 21 tests trong `ManualTimekeepingTest.php` và 60 tests liên quan đến Payroll đều PASS.
- **Vite build**: Chạy thành công `npm run build` không có lỗi.
- **Data safety**: Không thay đổi cấu trúc DB (no migration), không backfill/update trực tiếp dữ liệu cũ. Việc cập nhật chỉ áp dụng khi tính lại bảng lương.

---
*Báo cáo được thực hiện bởi Antigravity AI Agent.*
