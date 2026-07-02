# Audit Log — HOTFIX Khách hàng: Chiết khấu thanh toán đúng logic, không làm sai công nợ/sổ quỹ

## 1. Source đã kiểm tra & Tài liệu KiotViet tham khảo
- **Source code đã kiểm tra**:
  - `routes/web.php`
  - `app/Http/Controllers/CustomerController.php`
  - `app/Services/CustomerDebtService.php`
  - `resources/js/Pages/Customers/Index.vue`
- **Tài liệu tham khảo**: Quy trình nghiệp vụ Chiết khấu thanh toán (không liên quan đến dòng tiền, chỉ cấn trừ công nợ) của KiotViet tại:
  `https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-khach-hang/khach-hang/#ii-cac-thao-tac-co-ban`

## 2. Root Cause & Giải pháp áp dụng
- **Root Cause**: Nút "Chiết khấu thanh toán" trong tab Công nợ màn Khách hàng đang là nút tĩnh (chưa có xử lý sự kiện). Không thể tái sử dụng hàm `debtPayment()` vì hàm này trực tiếp tăng `invoice.customer_paid` (lịch sử tiền khách đã trả) - trong khi chiết khấu thanh toán là tiền doanh nghiệp giảm cho khách, không phải tiền thực thu, nếu tăng `customer_paid` sẽ làm sai lệch nghiêm trọng báo cáo doanh thu và dòng tiền/sổ quỹ.
- **Giải pháp**:
  - Thêm luồng nghiệp vụ chiết khấu riêng biệt với mã phiếu dạng `CKTT...`.
  - Phân bổ số tiền chiết khấu vào các hóa đơn thông qua bảng trung gian liên kết thay vì sửa cột của bảng `invoices`.
  - Công nợ thực tế của hóa đơn còn phải thu được tính động theo công thức: `total - customer_paid - active_payment_discount_allocated`.
  - Không tạo `cash_flows`, không ảnh hưởng sổ quỹ và tồn kho/giá vốn.

## 3. Danh sách tệp tin thay đổi (Files changed)
- **Tạo mới**:
  - Migration: [2026_05_24_000000_create_customer_payment_discounts_tables.php](file:///d:/Kiot/kiotviet-clone/database/migrations/2026_05_24_000000_create_customer_payment_discounts_tables.php)
  - Models:
    - [CustomerPaymentDiscount.php](file:///d:/Kiot/kiotviet-clone/app/Models/CustomerPaymentDiscount.php)
    - [CustomerPaymentDiscountAllocation.php](file:///d:/Kiot/kiotviet-clone/app/Models/CustomerPaymentDiscountAllocation.php)
  - Service: [CustomerPaymentDiscountService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerPaymentDiscountService.php)
  - Controller: [CustomerPaymentDiscountController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerPaymentDiscountController.php)
  - Test: [CustomerPaymentDiscountTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerPaymentDiscountTest.php)
- **Sửa đổi**:
  - [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
  - [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
  - [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)

## 4. Xác nhận các tiêu chí ràng buộc
- **Có migration bảng mới**: Có (thêm 2 bảng mới `customer_payment_discounts` và `customer_payment_discount_allocations`).
- **Có sửa schema bảng cũ không**: Không.
- **Có backfill / cập nhật dữ liệu cũ không**: Không.
- **Có sửa `invoice.customer_paid` không**: Không.
- **Có tạo CashFlow không**: Không.
- **Có ảnh hưởng sổ quỹ / doanh thu / tồn kho / giá vốn không**: Không.
- **Có hủy phiếu chiết khấu không**: Có (cập nhật status thành `cancelled` và ghi nhận một dòng adjustment đối ứng dương vào ledger).

## 5. Kết quả kiểm thử & Biên dịch
- **Automated tests**: Bộ kiểm thử `CustomerPaymentDiscountTest` chạy thành công 100% (**PASS** 7 tests, 26 assertions).
- **Regression tests**: Tất cả các bộ test liên quan đến `CustomerDebt`, `CancelInvoicePaymentDebtFlowTest`, và `RR09DamageStockTest` đều vượt qua trọn vẹn.
- **Biên dịch**: `npm run build` hoàn thành thành công trong **6.69 giây**.

## 6. Rủi ro còn lại
- Do không sửa đổi dữ liệu cũ, công nợ và hóa đơn lịch sử vẫn giữ nguyên trạng.
- Nếu có chiết khấu thanh toán cho các hóa đơn cũ, phần tiền phân bổ chỉ bắt đầu có hiệu lực với các giao dịch tạo mới sau khi chạy migration này.
