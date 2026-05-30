# HOTFIX — Kiot Standard Customer/Supplier Debt Tabs

## Phạm vi

- Module: Khách hàng, Nhà cung cấp, công nợ khách hàng, công nợ nhà cung cấp.
- Màn hình: `/customers`, tab Công nợ khách hàng; `/suppliers`, tab Công nợ nhà cung cấp.
- Nghiệp vụ: bán hàng, thanh toán khách, chứng từ gốc đã hạch toán, nhập hàng, thanh toán NCC, trả hàng nhập, điều chỉnh NCC.
- Rủi ro: trộn customer-side vào supplier-side làm sai cột `Nợ cần trả nhà cung cấp`; nhãn `Tham khảo` gây hiểu nhầm; UI hiển thị timestamp khác field backend dùng để sort/tính balance.

## Ảnh KiotViet đã đối chiếu

- Màn Khách hàng: cột cuối là `Công nợ`, khách nợ mình là dương, mình nợ lại đối tác là âm.
- Màn Nhà cung cấp: cột cuối là `Nợ cần trả nhà cung cấp`, mình còn phải trả NCC là dương.
- Quy tắc dấu: Nhập hàng tăng nợ NCC ở màn NCC nhưng giảm net debt ở màn Khách hàng; thanh toán NCC/trả hàng nhập đảo dấu tương ứng.
- Quy tắc tách màn: tab Công nợ NCC chỉ hiển thị supplier-side, không hiển thị HD/TTHD/customer payment/order return phía khách.

## Source đã kiểm tra

- `app/Http/Controllers/SupplierController.php`
- `app/Services/PartnerFinancialTimelineService.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `tests/Feature/Customers/PartnerFinancialTimelineTest.php`
- `tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php`
- `tests/Feature/Supplier/*`

## Root cause

- Màn Nhà cung cấp đang trộn cross-role invoice/customer payment vào timeline NCC, làm sổ NCC không còn là `Nợ cần trả nhà cung cấp` thuần.
- Nhãn `Tham khảo` đúng về kỹ thuật nhưng không rõ nghiệp vụ với chứng từ đã nằm trong ledger/gộp công nợ.
- Frontend tab Công nợ Khách hàng/Supplier có chỗ hiển thị `created_at` thay vì timestamp chính backend dùng để sort.

## Thay đổi đã làm

- Supplier tab chỉ còn supplier-side: nhập hàng, thanh toán NCC, trả hàng nhập, adjustment/discount/payment NCC.
- Bỏ HD/TTHD/customer-side cashflow khỏi API `SupplierController::debtTransactions`.
- Supplier table chính hiển thị pure `supplier_debt_amount`, không trừ `debt_amount` khách hàng.
- Badge reference bên customer đổi từ `Tham khảo` sang `Đã hạch toán`.
- Tooltip/balance note giải thích chứng từ đã phản ánh trong Số dư đầu kỳ/Gộp công nợ hoặc ledger, không cộng lại công nợ.
- Frontend customer dùng `entry.time || entry.recorded_at || entry.created_at`.
- Frontend supplier dùng `entry.time || entry.recorded_at || entry.created_at || entry.date`.
- Cập nhật tests supplier/customer và regression export supplier để không quay lại trộn customer-side vào sổ NCC.

## Có ảnh hưởng dữ liệu không?

- Không.
- Migration: Không.
- Backfill: Không.
- Recalculate: Không.
- Update dữ liệu cũ: Không.
- Delete dữ liệu: Không.

## Tests đã chạy

- `php artisan test tests/Feature/Supplier/SupplierDebtTimelineKiotStandardTest.php` — PASS, 4 tests, 20 assertions.
- `php artisan test tests/Feature/Customers/PartnerFinancialTimelineTest.php` — PASS, 12 tests, 80 assertions.
- `php artisan test tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php` — PASS, 4 tests, 40 assertions.
- `php artisan test tests/Feature/Customers/CustomerNetDebtTest.php` — PASS, 7 tests, 28 assertions.
- `php artisan test --filter=Supplier` — PASS, 86 tests, 372 assertions.
- `php artisan test --filter=CustomerDebt` — PASS, 37 tests, 195 assertions.
- `php artisan test --filter=Purchase` — PASS, 69 tests, 311 assertions.
- `npm run build` — PASS.

Ghi chú môi trường test: PHP CLI có warning extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci` không load được; không làm fail test.

## Manual QA

- Khách hàng Thiên Phú: Chưa chạy trên production/staging trong lượt code này; cần kiểm tra sau khi deploy.
- Nhà cung cấp Thiên Phú: Chưa chạy trên production/staging trong lượt code này; cần xác nhận không còn HD/TTHD trong tab Công nợ NCC.
- Khách trả hàng: Chưa chạy UI thủ công; regression CustomerDebt/PartnerTimeline pass.
- NCC có thanh toán: Chưa chạy UI thủ công; test supplier payment pass.
- Time display: Chưa chạy UI thủ công; build pass và frontend đã dùng field `time` trước `created_at`.

## Rủi ro còn lại

- Dữ liệu legacy thiếu ledger vẫn có thể cần Phase 2 đối soát riêng.
- Supplier debt export test cũ từng kỳ vọng dòng bán hàng customer-side; đã đổi theo chuẩn KiotViet mới để không trộn nghiệp vụ.
- Không tự backfill/recalculate trong hotfix này.

## Kết luận

- Đạt về code/test/build.
- Có thể deploy sau khi Senior Auditor xác nhận.
- Cần Phase 2 dữ liệu nếu production phát hiện legacy thiếu ledger hoặc lệch đối soát, nhưng không xử lý trong hotfix này.
