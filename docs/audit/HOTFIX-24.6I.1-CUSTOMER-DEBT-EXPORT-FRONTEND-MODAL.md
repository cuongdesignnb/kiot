# HOTFIX 24.6I.1 — Customer Debt Export Frontend Modal

## Phạm vi audit
- Module: Customers, Customer Debt Export.
- Màn hình: Khách hàng > tab Công nợ.
- Nghiệp vụ: Bấm `Xuất file công nợ` phải mở modal export, chọn thời gian/cột và tải `.xlsx`.
- Rủi ro chính: nút gọi `openCustomerDebtExportModal(customer)` nhưng helper/modal không tồn tại sẽ gây lỗi runtime.

## Source đã kiểm tra
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `app/Http/Controllers/CustomerController.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `tests/Feature/Customers/CustomerDebtExcelExportTest.php`
- `docs/audit/HOTFIX-24.6I-CUSTOMER-DEBT-EXCEL-EXPORT-LIKE-SUPPLIER.md`
- Route: `GET /customers/{customer}/export-debt`, `GET /customers/{customer}/export-sales`
- Commit kiểm tra: `5b5824ae116efd265e3eb1bd2bf39f0ca91b676a`

## Hiện trạng
- Backend: đã có `format=xlsx`, `date_preset`, `date_from`, `date_to`, `include_detail`, `columns[]`.
- Frontend: `origin/main` hiện đã có đầy đủ:
  - `showCustomerDebtExportModal`
  - `debtExportCustomer`
  - `customerDebtExportForm`
  - `openCustomerDebtExportModal(customer)`
  - `confirmCustomerDebtExport()`
  - modal template export công nợ khách hàng
- Database: không thay đổi.
- Permission: giữ nguyên route web hiện có.
- Production/deploy: chưa chạy browser QA trong phiên này.

## Root cause
- Rủi ro cần kiểm tra là commit trước đổi nút sang `openCustomerDebtExportModal(customer)` nhưng nếu helper/state/modal không được push kèm thì frontend sẽ lỗi undefined.
- Audit local và `origin/main` hiện tại xác nhận helper/state/modal đã tồn tại trong `Customers/Index.vue`.

## Có ảnh hưởng dữ liệu đang có không?
- Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không sửa `customers.debt_amount`.
- Không sửa `customer_debts`.
- Không sửa `cash_flows`.
- Không sửa hóa đơn/trả hàng.
- Chỉ xác nhận frontend modal export và read-only export.

## Phương án an toàn
- Giữ nguyên backend export 24.6I.
- Xác nhận frontend customer đã mirror supplier export modal:
  - state modal;
  - date presets;
  - custom date validation;
  - include detail;
  - column selection;
  - URL `.xlsx`.
- Không sửa supplier export.
- Không dùng `window.open` trong template.

## Static grep
- `openCustomerDebtExportModal`: có trong script và template.
- `confirmCustomerDebtExport`: có trong script và modal button.
- `showCustomerDebtExportModal`: có trong script và modal template.
- `customerDebtExportForm`: có trong script và template.
- `window.open`: không còn trong `resources/js/Pages/Customers/Index.vue`.
- `CustomerDebtExcelExportService`: có trong `app` và test.

## Tests bắt buộc
- `php artisan test tests/Feature/Customers/CustomerDebtExcelExportTest.php`
- `php artisan test tests/Feature/Customers`
- `php artisan test tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php`
- `php artisan test tests/Feature/OrderReturn`
- `php artisan test tests/Feature/CashFlows`
- `php artisan test tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- `php artisan test tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php`
- `php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php`
- `npm run build`

## Manual QA
- Chưa chạy browser QA trong phiên này.
- Cần kiểm tra:
  - click `Xuất file công nợ` mở modal, không Vue Error;
  - export toàn thời gian tải `.xlsx`;
  - custom date validate đúng;
  - `Xuất file` sales link vẫn tải được;
  - supplier export vẫn hoạt động.

## Kết luận
- Đạt về static source audit: frontend modal/helper/state đã có thật trên `origin/main`.
- Chưa kết luận production-ready nếu chưa browser QA.
- Không cần sửa backend/data cho hotfix 24.6I.1.
