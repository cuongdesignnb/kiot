# HOTFIX — Financial report exclude return/debt cashflows from P&L

## Phạm vi

- Module: Phân tích / Báo cáo tài chính
- Màn hình: /reports/financial-report
- Nghiệp vụ: Doanh thu, giảm trừ doanh thu, giá vốn, chi phí, thu nhập khác, chi phí khác, lợi nhuận thuần.
- Rủi ro:
  - Double count trả hàng khách.
  - Tính sai trả hàng NCC thành thu nhập khác.
  - Tính sai đối trừ công nợ thành thu nhập khác.
  - Tính nhầm chữ “khách” thành “khác”.
  - Tính cả cashflow cancelled.

## Source đã kiểm tra

- Route: [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
- Controller: [FinancialReportController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/FinancialReportController.php)
- Service: [MetricService.php](file:///d:/Kiot/kiotviet-clone/app/Support/Reports/MetricService.php)
- Model: [CashFlow.php](file:///d:/Kiot/kiotviet-clone/app/Models/CashFlow.php)
- Migration: [database/migrations/2026_03_01_083647_add_details_to_cash_flows_table.php](file:///d:/Kiot/kiotviet-clone/database/migrations/2026_03_01_083647_add_details_to_cash_flows_table.php)
- Test: [FinancialReportPnlCashFlowExclusionTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php)

## Hiện trạng trước sửa

- Chi phí (6) và Thu nhập khác (8) query trực tiếp từ CashFlow với điều kiện đơn giản (type = payment / type = receipt), dẫn đến các dòng trả hàng khách, trả hàng NCC, đối trừ công nợ bị tính sai vào chi phí/thu nhập khác.
- Chi phí khác (9) query dùng LIKE '%khác%' hoặc LIKE '%Khác%' nên bị match nhầm category "Chi tiền trả hàng khách", gây ra double count.
- Trạng thái CashFlow = cancelled vẫn bị cộng dồn trong báo cáo tài chính.
- `accounting_result` chưa được kiểm tra trong query.

## Root cause

- Chưa loại trừ các loại CashFlow đảo nghịch/điều chỉnh (trả hàng, đối trừ công nợ, hủy) trong P&L.
- Dùng query LIKE '%khác%' không chính xác.
- Không sử dụng scope active() và điều kiện `accounting_result = true`.

## Thay đổi đã làm

- Thêm helper method `pnlCashFlowBaseQuery` trong [FinancialReportController](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/FinancialReportController.php) để:
  - Chỉ lấy CashFlow active (loại bỏ status = cancelled và soft deleted).
  - Loại bỏ các reference_type: `OrderReturn`, `PurchaseReturn`, `DebtOffset`, `DebtOffsetCancel`.
  - Loại bỏ các category liên quan đến trả hàng, đối trừ công nợ, thanh toán NCC.
  - Áp dụng `accounting_result = true` nếu cột tồn tại trong DB.
  - Lọc theo chi nhánh (`branch_id`) nếu cột tồn tại.
- Cập nhật các truy vấn chi phí, thu nhập khác và chi phí khác thông qua helper mới.
- Loại bỏ hoàn toàn truy vấn LIKE '%khác%' và thay bằng exact match cho chi phí khác (`whereIn`).
- Tạo file kiểm thử [FinancialReportPnlCashFlowExclusionTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php) với 7 test cases để đảm bảo tính đúng đắn cho mọi trường hợp.

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không xóa dữ liệu.
- Không recalculate tồn kho/giá vốn/công nợ/cashflow.

## Tests đã chạy

- Lệnh: `php artisan test tests/Feature/Report/FinancialReportPnlCashFlowExclusionTest.php` và `php artisan test tests/Feature/Report/RR01CashFlowCancelledRegressionTest.php`
- Kết quả: PASS toàn bộ các test cases (11/11 tests pass).

## Manual QA

- Kịch bản:
  1. Tạo các phiếu thu/chi với category/reference_type cần loại trừ trên local.
  2. Mở `/reports/financial-report` kiểm tra các chỉ số Chi phí (6), Thu nhập khác (8), Chi phí khác (9).
- Kết quả: Các dòng này không còn bị tính vào P&L. Chi phí thật vẫn được hiển thị và tính toán đúng. Công thức lợi nhuận khớp hoàn toàn.

## Rủi ro còn lại

- Do bảng `cash_flows` hiện tại không có cột `branch_id` (không có migration thêm cột trong hotfix này), nên phần Chi phí/Thu nhập khác từ CashFlows chưa thể lọc theo chi nhánh trên báo cáo tài chính (Doanh thu/Giá vốn thì lọc bình thường). Muốn xử lý triệt để cần một bản migration thêm cột `branch_id` cho cash_flows và backfill dữ liệu cũ.

## Kết luận

- Đạt. Có thể deploy.
