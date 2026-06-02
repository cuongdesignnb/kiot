# HOTFIX — Dual-role debt display running balance

## Phạm vi audit

- Module: công nợ khách hàng, nhà cung cấp, đối tác dual-role.
- Màn hình: Customers tab Công nợ, Suppliers tab Công nợ.
- Nghiệp vụ: timeline tài chính theo orientation khách hàng và nhà cung cấp.
- Rủi ro chính: dữ liệu cũ chỉ có số dư cuối nhưng thiếu ledger chi tiết làm timeline trống hoặc cột số dư hiển thị `---`.

## User feedback

- Nhiều dòng timeline hiện `---`.
- Nhiều khách có nợ nhưng không có lịch sử.
- Timeline cần rõ như KiotViet.

## Source đã kiểm tra

- PartnerDebtLedgerService: source chính cho customer debt history và supplier debt transactions.
- PartnerFinancialTimelineService: service cũ, đã đồng bộ logic display running.
- CustomerController: trả trực tiếp ledger từ PartnerDebtLedgerService.
- SupplierController: bổ sung summary display timeline/virtual opening.
- Customers/Index.vue: ưu tiên display running balance.
- Suppliers/Index.vue: ưu tiên display running balance.
- Tests: thêm virtual opening/no-dash tests.
- Commit: sẽ ghi ở commit hotfix sau khi test pass.

## Root cause

- Reference-only entries bị set running balance null để tránh double count ledger.
- UI hiển thị null thành `---`.
- Dữ liệu cũ có thể chỉ còn số dư hiện tại, không có chứng từ chi tiết để dựng timeline.
- Trước hotfix chưa tách ledger running và display running.

## Phương án

- Tách ledger effect và display effect.
- Tạo display running balance cho mọi dòng tài chính.
- Inject virtual opening balance read-only nếu tổng display effects không khớp số dư hiện tại.
- Không ghi DB.
- Không render `Đã hạch toán`.

## Có ảnh hưởng dữ liệu không?

Không. Virtual opening balance chỉ là dòng response read-only.

## Tests đã chạy

- `php artisan test tests/Feature/Customers/CustomerDebtVirtualOpeningTimelineTest.php tests/Feature/Suppliers/SupplierDebtVirtualOpeningTimelineTest.php tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php tests/Feature/Suppliers/SupplierDualRoleTimelineFinancialDisplayTest.php`: PASS, 4 tests / 111 assertions.
- `php artisan test tests/Feature/Suppliers/SupplierDualRoleListDebtColumnTest.php tests/Feature/Suppliers/SupplierDualRoleOrientationKiotVietTest.php tests/Feature/Suppliers/SupplierPayableLedgerTest.php tests/Feature/Customers/CustomerDualRoleListDebtColumnTest.php tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`: PASS, 20 tests / 204 assertions.
- `php artisan test --filter=Supplier`: PASS, 114 tests / 653 assertions.
- `php artisan test --filter=CustomerDebt`: PASS, 38 tests / 212 assertions.
- `php artisan test --filter=Purchase`: PASS, 86 tests / 444 assertions.
- `php artisan test --filter=CashFlow`: PASS, 37 tests / 204 assertions.

## Build

- `npm run build`: PASS.

## Manual QA

- Chu Bá Lâm: cần kiểm tra local bằng dữ liệu thật.
- Anh Thanh customer tab: cần kiểm tra local bằng dữ liệu thật.
- Anh Thanh supplier tab: cần kiểm tra local bằng dữ liệu thật.
- ABCD KiotViet-like: cần kiểm tra local bằng dữ liệu thật.

## Rủi ro còn lại

- Virtual opening là read-only display, chưa phải chứng từ thật.
- Nếu user muốn chứng từ thật phải có xác nhận/backfill riêng.

## Kết luận

- Đạt/chưa đạt: đạt test/build tự động.
- Có thể deploy staging chưa: có thể deploy staging để QA dữ liệu thật.
- Có thể deploy production chưa: chưa, cần QA local/staging theo yêu cầu trong paste.
