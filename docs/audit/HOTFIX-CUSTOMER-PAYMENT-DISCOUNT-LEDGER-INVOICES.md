# Audit Log — HOTFIX Khách hàng: Lấy đủ hóa đơn bán hàng còn nợ từ Invoice trực tiếp và Ledger, không lấy PN/NCC

## 1. Source đã kiểm tra & Tài liệu KiotViet tham khảo
- **Source code đã kiểm tra**:
  - `routes/web.php`
  - `app/Http/Controllers/CustomerController.php`
  - `app/Http/Controllers/CustomerPaymentDiscountController.php`
  - `app/Services/CustomerPaymentDiscountService.php`
  - `app/Models/CustomerDebt.php`
  - `app/Models/Invoice.php`
  - `resources/js/Pages/Customers/Index.vue`
  - `tests/Feature/Customers/CustomerPaymentDiscountTest.php`

## 2. Root Cause & Giải pháp áp dụng
- **Root Cause**: Modal Chiết khấu thanh toán (CKTT) và chức năng Thu nợ trước đó chỉ truy vấn hóa đơn trực tiếp bằng `customer_id` của khách hàng. Trong thực tế (đặc biệt khi có dữ liệu cũ/gộp khách hàng/nhà cung cấp/merge), công nợ bán hàng có thể chỉ nằm trong ledger `customer_debts` với `ref_code = HD...` chứ không có `invoices.customer_id` khớp trực tiếp. Điều này dẫn đến sự chênh lệch: Tab Công nợ hiển thị còn nợ nhưng modal CKTT/Thanh toán công nợ lại báo `Không có hóa đơn còn nợ`.
- **Giải pháp**:
  - Viết resolver dùng chung `getCustomerReceivableInvoices(Customer $customer)` trong `CustomerPaymentDiscountService.php` để lấy hóa đơn từ cả 2 nguồn: trực tiếp và từ ledger công nợ (chỉ lấy mã `HD%`, bỏ qua `PN%`, `MERGE%`, `TTHD%`, `PT%`, v.v.).
  - Loại bỏ trùng lặp hóa đơn (ưu tiên source `direct_invoice`).
  - Cập nhật modal CKTT, modal Thu nợ (Manual & Auto), và Validation khi tạo CKTT để sử dụng chung resolver này.
  - Sửa logic tự động thu nợ (Auto mode): Nếu không còn hóa đơn phải thu nào hợp lệ (`actualPaid <= 0`), hệ thống sẽ trả về lỗi `422` và chặn không cho tạo phiếu thu/ledger payment làm công nợ âm sai lệch.
  - Hiển thị thêm badge `Ledger` bên cạnh mã hóa đơn trên frontend nếu hóa đơn được lấy từ ledger.
  - Cập nhật thông báo trống trong modal CKTT rõ ràng hơn để hướng dẫn người dùng.

## 3. Danh sách tệp tin thay đổi (Files changed)
- [app/Services/CustomerPaymentDiscountService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerPaymentDiscountService.php)
- [app/Http/Controllers/CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- [resources/js/Pages/Customers/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- [tests/Feature/Customers/CustomerPaymentDiscountTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerPaymentDiscountTest.php)

## 4. Xác nhận các tiêu chí ràng buộc & An toàn dữ liệu
- **Có migration không**: Không.
- **Có backfill / cập nhật dữ liệu cũ không**: Không.
- **Có sửa `invoice.customer_paid` trong CKTT không**: Không (chỉ sửa trong Thu nợ/debtPayment theo đúng luồng cũ).
- **Có tạo CashFlow cho CKTT không**: Không (luồng CKTT chỉ điều chỉnh công nợ qua ledger adjustment).
- **Có lấy PN/NCC không**: Không (chỉ lấy hóa đơn bán hàng có mã `HD%`, loại trừ `PN%` và công nợ NCC).
- **Có sửa tồn kho/serial/giá vốn không**: Không.

## 5. Kết quả kiểm thử & Biên dịch
- **Automated tests**: Bộ kiểm thử `CustomerPaymentDiscountTest` chạy thành công 100% (**PASS** 15 tests, 58 assertions), bao gồm 8 case test mới viết riêng cho hotfix này.
- **Regression tests**:
  - `CustomerDebt` filter: **PASS** (21 tests, 86 assertions).
  - `CancelInvoicePaymentDebtFlowTest` filter: **PASS** (4 tests, 31 assertions).
  - `RR09DamageStockTest`: **PASS** (5 tests, 12 assertions).
- **Biên dịch**: `npm run build` hoàn thành thành công trong **7.57 giây**.

## 6. Rủi ro còn lại
- Không có rủi ro về mặt dữ liệu vì hotfix chỉ thay đổi cách truy vấn/hiển thị danh sách hóa đơn còn nợ và logic phân bổ mới, hoàn toàn không sửa đổi hoặc cập nhật dữ liệu cũ trong DB.
