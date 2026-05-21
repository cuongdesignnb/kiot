# HOTFIX 24.6I — Customer Debt Excel Export Like Supplier

## Phạm vi audit
- Module: Customers, Customer Debt, Export.
- Màn hình: Khách hàng > tab Công nợ.
- Nghiệp vụ: Xuất file công nợ khách hàng theo mẫu công nợ nhà cung cấp.
- Rủi ro chính: Vue crash khi bấm export, CSV thô thiếu định dạng, và auto settlement adjustment bị lộ ra file công nợ.

## Source đã kiểm tra
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/Exports/SupplierDebtExcelExportService.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `app/Services/CsvService.php`
- `app/Models/Customer.php`
- `app/Models/CustomerDebt.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceItem.php`
- `app/Models/OrderReturn.php`
- `app/Models/ReturnItem.php`
- `routes/web.php`
- `routes/api.php`
- `tests/Feature/Customers/CustomerDebtExcelExportTest.php`
- `tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`
- Commit: pending at report creation.

## Hiện trạng
- Backend: `/customers/{customer}/export-debt` chỉ xuất CSV legacy khi chưa có query; nay hỗ trợ `format=xlsx`.
- Frontend: nút công nợ khách hàng trước đó gọi `window.open` trực tiếp trong template, gây Vue scope error.
- Database: không thay đổi schema hoặc dữ liệu.
- Permission: giữ nguyên route web hiện có.
- Production/deploy: chưa deploy trong bước code này.

## Root cause
- Vue template không resolve `window` như global component scope nên `@click="window.open(...)"` bị lỗi.
- Customer debt export còn là CSV đơn giản, chưa có modal chọn thời gian/cột và chưa có workbook định dạng KiotViet giống supplier debt export.
- Supplier debt export đã có modal và `PhpSpreadsheet` service chuẩn, cần mirror sang customer.

## Có ảnh hưởng dữ liệu đang có không?
- Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không sửa `customers.debt_amount`, `customer_debts`, `cash_flows`, `invoices`, `returns`.
- Chỉ đọc dữ liệu và render response export.

## Phương án an toàn
- Thay nút `Xuất file công nợ` bằng modal export giống supplier debt export.
- Thay nút `Xuất file` lịch sử bán/trả bằng link an toàn, không dùng `window.open` trong template.
- Giữ CSV legacy khi gọi `/customers/{customer}/export-debt` không query.
- Khi `format=xlsx`, render bằng `CustomerDebtExcelExportService`.
- Service Excel dùng `debtHistory().entries` presentation-safe, nên auto return settlement adjustment đã được gộp vào dòng `Trả hàng` và không xuất thành dòng `Điều chỉnh`.

## Không được làm
- Không sửa công nợ/ledger/cashflow.
- Không phá supplier export.
- Không query/update dữ liệu thủ công.
- Không migration hoặc backfill.

## Code fix
- Frontend:
  - `openCustomerDebtExportModal(customer)`
  - `closeCustomerDebtExportModal()`
  - `confirmCustomerDebtExport()`
  - date presets, custom `dd/mm/yyyy`, include detail, chọn cột detail.
- Backend:
  - `CustomerController::exportDebtHistory(Customer $customer, Request $request)` hỗ trợ `format=xlsx`.
  - `CustomerDebtExcelExportService` render sheet `CNCT`, title `CÔNG NỢ CHI TIẾT KHÁCH HÀNG`, bảng 12 cột.

## Tests bắt buộc
- `php artisan test tests/Feature/Customers/CustomerDebtExcelExportTest.php`: PASS, 10 tests / 41 assertions.
- `php artisan test tests/Feature/Customers`: PASS, 25 tests / 105 assertions.
- `php artisan test tests/Feature/OrderReturn/ReturnDebtAfterPaidRefundTest.php`: PASS, 10 tests / 60 assertions.
- `php artisan test tests/Feature/OrderReturn`: PASS, 53 tests / 213 assertions.
- `php artisan test tests/Feature/CashFlows`: PASS, 6 tests / 23 assertions.
- `php artisan test tests/Feature/Supplier/HOTFIX2417BSupplierDebtExcelFormatTest.php`: PASS, 9 tests / 39 assertions.
- `php artisan test tests/Feature/Supplier/HOTFIX2417SupplierDebtExportOptionsTest.php`: PASS, 8 tests / 30 assertions.
- `php artisan test tests/Feature/Supplier/HOTFIX2414SupplierTabExportTest.php`: PASS, 6 tests / 23 assertions.
- `npm run build`: PASS.
- Ghi chú môi trường: PHP CLI vẫn cảnh báo thiếu extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; các suite trên vẫn chạy xong.

## Static checks
- `rg -n "window.open" resources/js/Pages/Customers/Index.vue`: no output.
- `rg -n "openCustomerDebtExportModal|confirmCustomerDebtExport" resources/js/Pages/Customers/Index.vue`: found helpers and template bindings.
- `rg -n "CustomerDebtExcelExportService" app tests`: found service and controller usage.
- `rg -n "format.*xlsx" app/Http/Controllers/CustomerController.php tests/Feature/Customers`: found controller validation/branch and tests.

## Manual QA
- Browser UI QA chưa chạy trong phiên này.
- Cần kiểm tra:
  - Bấm `Xuất file công nợ` mở modal, không còn Vue Error.
  - Export mặc định tải `.xlsx`, sheet `CNCT`.
  - Hóa đơn có dòng hàng xuất detail dưới dòng chứng từ.
  - Thanh toán vào cột `Ghi có`.
  - Trả hàng đã settlement không hiện dòng `Điều chỉnh` tự động.
  - Custom date lọc đúng.
  - Nút `Xuất file` lịch sử bán/trả vẫn tải được.

## Kết luận
- Đạt về code, automated tests và build.
- Chưa kết luận production-ready hoàn toàn nếu chưa browser QA.
- Có thể deploy sau khi owner/chủ hệ thống chấp nhận kết quả test và chạy manual QA trên staging/production-like.
