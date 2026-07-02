# HOTFIX: Invoice transaction datetime update

## Mục tiêu

- Sửa luồng cập nhật hóa đơn để phát hiện thay đổi `transaction_date` theo đủ ngày, giờ, phút.
- Khi chỉ đổi ngày giờ bán, chỉ cập nhật `invoices.transaction_date`; không sửa `invoices.created_at`.
- Đồng bộ thời gian nghiệp vụ của phiếu thu liên quan qua `cash_flows.time`; không sửa `cash_flows.created_at`.
- Màn danh sách hóa đơn và phần mở rộng chi tiết hiển thị `transaction_date ?? created_at`.
- Sort cột "Thời gian" dùng trường nghiệp vụ `transaction_date` với fallback `created_at`.

## Phạm vi thay đổi

- `app/Services/InvoiceUpdateService.php`
  - Bỏ so sánh `startOfDay()` khi lập change plan.
  - So sánh thời điểm đến cấp phút để bắt được case cùng ngày nhưng khác giờ/phút.
  - `applyDateOnlyUpdate()` không còn ghi đè `created_at`.
  - Cập nhật `cash_flows.time` với điều kiện loại trừ `cancelled`, đồng thời giữ NULL-safe cho dữ liệu legacy.
- `app/Http/Controllers/InvoiceController.php`
  - Cho phép sort theo `transaction_date`.
  - Sort `created_at` và `transaction_date` qua `COALESCE(invoices.transaction_date, invoices.created_at)`.
  - Export/show/detail trả và hiển thị theo thời gian bán nghiệp vụ khi có.
- `resources/js/Pages/Invoices/Index.vue`
  - Cột "Thời gian" sort bằng `transaction_date`.
  - Danh sách và "Ngày bán" trong expanded detail hiển thị `invoice.transaction_date ?? invoice.created_at`.
- `tests/Feature/Invoice/InvoiceTransactionDateTimeUpdateTest.php`
  - Bổ sung test đổi giờ trong cùng ngày.
  - Bổ sung test đổi khác ngày.
  - Bổ sung test cashflow chỉ cập nhật `time`, giữ nguyên `created_at`, và không cập nhật phiếu thu `cancelled`.
  - Bổ sung test change plan bỏ qua khác biệt giây, chỉ xét đến phút.

## Data safety

- Không chạy migration.
- Không chạy `migrate:fresh`.
- Không backfill.
- Không bulk update hóa đơn cũ.
- Không recalculation công nợ.
- Không thay đổi stock, cost, serial, warranty ngoài luồng đã có sẵn.
- Không stage file dữ liệu untracked `kiot_db.sql.zip`.

## Kết quả kiểm thử

- `php artisan test tests/Feature/Invoice/InvoiceTransactionDateTimeUpdateTest.php`: PASS, 5 tests, 13 assertions.
- `php artisan test tests/Feature/Invoice/InvoiceEditRouteTest.php`: PASS, 11 tests, 99 assertions.
- `php artisan test tests/Feature/Customers/CustomerDebtTimelineBusinessTimeTest.php`: PASS, 5 passed, 1 skipped do schema test thiếu `returns.return_date`.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS, 1 test, 36 assertions.
- `npm run build`: PASS.
- `docker compose build`: PASS, image `kiotviet-clone-app` built.

Ghi chú: các lệnh PHP vẫn in cảnh báo startup do thiếu extension tùy chọn `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`. Cảnh báo này đã có ở môi trường và không làm test fail.

## Manual QA

- Chưa chạy manual QA trên trình duyệt vì yêu cầu không cung cấp mã hóa đơn/customer fixture cụ thể để đối chiếu UI thực tế.
- Coverage tự động đã kiểm tra các behavior chính: đổi giờ cùng ngày, đổi khác ngày, không sửa `created_at`, cập nhật `cash_flows.time`, không cập nhật cashflow `cancelled`, và regression công nợ.

## Kết luận

Hotfix giữ nguyên thời điểm tạo bản ghi (`created_at`) và chuyển toàn bộ hiển thị/sort nghiệp vụ của hóa đơn sang ưu tiên `transaction_date`. Thay đổi không thao tác dữ liệu cũ và không chạy migration/backfill.
