# HOTFIX — Partner Financial Timeline Debt

## Phạm vi

- Module: Khách hàng, Nhà cung cấp, công nợ khách hàng, công nợ nhà cung cấp, bán hàng, trả hàng bán, nhập hàng, trả hàng nhập, thanh toán khách hàng, thanh toán NCC, sổ quỹ liên quan.
- Màn hình: `/customers`, tab `Công nợ` trong Khách hàng, dữ liệu timeline phục vụ modal/chi tiết chứng từ công nợ.
- Nghiệp vụ: bán hàng, khách thanh toán, thanh toán hóa đơn, trả hàng bán, hủy hóa đơn, nhập hàng, thanh toán NCC, trả hàng nhập, điều chỉnh, gộp công nợ.
- Rủi ro: dữ liệu legacy có thể thiếu ledger thật; hotfix chỉ đối soát/hiển thị, không sửa dữ liệu.

## Ảnh KiotViet đã đối chiếu

- Màn Khách hàng: cột cuối là `Công nợ`; nhập hàng từ đối tác kiêm NCC làm công nợ ròng giảm, thanh toán NCC/trả hàng nhập làm công nợ ròng tăng lại.
- Màn Nhà cung cấp: cột cuối là `Nợ cần trả nhà cung cấp`; nhập hàng làm nợ NCC tăng, thanh toán/trả hàng nhập làm nợ NCC giảm.
- Quy tắc dấu rút ra: tab `Công nợ` phải là dòng thời gian tài chính của đối tác, không chỉ lịch sử công nợ khách hàng một chiều.

## Source đã kiểm tra

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/CustomerDebtService.php`
- `app/Models/Customer.php`
- `app/Models/SupplierDebtTransaction.php`
- `app/Models/Invoice.php`
- `app/Models/OrderReturn.php`
- `app/Models/Purchase.php`
- `app/Models/PurchaseReturn.php`
- `app/Models/CustomerDebt.php`
- `app/Models/CashFlow.php`
- `resources/js/Pages/Customers/Index.vue`
- `tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php`
- `tests/Feature/Customers/CustomerNetDebtTest.php`
- `tests/Feature/Customers/CustomerDebtExcelExportTest.php`

## Root cause

- Tab `Công nợ` cần là timeline tài chính của đối tác, nhưng implementation cũ trộn ledger với chứng từ legacy trong controller và thiếu schema nghiệp vụ thống nhất.
- Hotfix trước đã chống double count giữa `customer_debts` và invoice/purchase legacy, nhưng nhãn nghiệp vụ và dòng tham khảo chưa đủ rõ.
- Một số chứng từ như TTHD, supplier payment, purchase return, order return cần phân loại rõ dòng hạch toán thật và dòng tham khảo.

## Quy tắc nghiệp vụ

- Bán hàng: tăng công nợ khách.
- Khách thanh toán/Thanh toán hóa đơn: giảm công nợ khách.
- Trả hàng bán: giảm công nợ khách.
- Nhập hàng: giảm công nợ ròng trong màn Khách hàng, tăng nợ phải trả trong màn NCC.
- Thanh toán NCC: tăng công nợ ròng trong màn Khách hàng, giảm nợ phải trả trong màn NCC.
- Trả hàng nhập: tăng công nợ ròng trong màn Khách hàng, giảm nợ phải trả trong màn NCC.
- Điều chỉnh: theo dấu `CustomerDebt.amount` hoặc `SupplierDebtTransaction.amount`, có nhãn rõ.
- Gộp công nợ: hiển thị `Số dư đầu kỳ / Gộp công nợ`, là dòng ledger thật.

## Thay đổi đã làm

- Tạo `PartnerFinancialTimelineService` để build timeline thống nhất cho màn Khách hàng.
- Chuẩn hóa schema entry: mã phiếu, thời gian, loại nghiệp vụ, giá trị chứng từ, effect, balance, source, badge, note, reference.
- Classify `CustomerDebt` thành `Bán hàng`, `Khách thanh toán`, `Trả hàng bán`, `Hủy hóa đơn`, `Chiết khấu thanh toán`, `Số dư đầu kỳ / Gộp công nợ`, `Điều chỉnh công nợ`.
- Bổ sung `OrderReturn` vào timeline: nếu đã có ledger thì tham khảo, nếu legacy không có ledger thì ảnh hưởng balance, nếu thiếu ledger trong hệ ledger thì đánh dấu `Cần đối soát`.
- Phân biệt TTHD có ledger là `Tham khảo`; TTHD không có ledger là dòng legacy ảnh hưởng balance.
- Đưa purchase completed, purchase paid amount và purchase return vào timeline khách kiêm NCC với dấu công nợ ròng đúng.
- SupplierDebtTransaction payment độc lập chỉ ảnh hưởng balance khi đủ tín hiệu không nằm trong `purchase.paid_amount`; còn lại là tham khảo để tránh double count.
- Frontend tab `Công nợ` dùng `display_type`, badge backend, tooltip `balance_note`, cột `Công nợ` hiển thị `—` cho dòng không ảnh hưởng balance.
- Cập nhật test export công nợ theo nhãn mới `Trả hàng bán`.

## Có ảnh hưởng dữ liệu không?

- Không.
- Migration: Không.
- Backfill: Không.
- Recalculate: Không.
- Update dữ liệu cũ: Không.
- Delete dữ liệu: Không.

## Tests đã chạy

- `php artisan test tests/Feature/Customers/PartnerFinancialTimelineTest.php` — PASS, 8 tests, 43 assertions.
- `php artisan test tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php` — PASS, 4 tests, 40 assertions.
- `php artisan test tests/Feature/Customers/CustomerNetDebtTest.php` — PASS, 7 tests, 28 assertions.
- `php artisan test --filter=CustomerDebt` — PASS, 37 tests, 195 assertions.
- `php artisan test --filter=OrderReturn` — PASS, 53 tests, 213 assertions.
- `php artisan test --filter=Purchase` — PASS, 67 tests, 298 assertions.
- `npm run build` — PASS, Vite build completed.
- Ghi chú môi trường: PHP CLI vẫn warning thiếu extension `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; các warning này không làm fail test/build.

## Manual QA

- Thiên Phú: Chưa chạy browser/manual QA trên dữ liệu thật trong phiên này; test feature đã dựng case Thiên Phú và assert balance `-27.600.000`.
- Khách trả hàng: Chưa chạy browser/manual QA; test đã assert `Trả hàng bán` làm giảm công nợ và có detail.
- Khách thanh toán: Chưa chạy browser/manual QA; test đã assert ledger payment là `Khách thanh toán`, TTHD có ledger là tham khảo, TTHD không ledger ảnh hưởng balance.
- Khách kiêm NCC: Chưa chạy browser/manual QA; test đã assert nhập hàng giảm net, thanh toán NCC tăng net, trả hàng nhập tăng net.
- Màn NCC: Chưa chạy browser/manual QA; đã audit logic dấu hiện tại trong `SupplierController`, chưa thay đổi màn NCC ở phase này.
- Build: PASS.

## Rủi ro còn lại

- Một số dữ liệu legacy production có thể thiếu ledger thật; hotfix chỉ hiển thị `Cần đối soát`, không tự sửa DB.
- Supplier payment độc lập cần đủ tín hiệu chứng từ/cashflow để được tính; nếu dữ liệu cũ thiếu liên kết thì nên xử lý ở Phase 2 bằng dry-run audit riêng.
- Nếu muốn chuẩn hóa dữ liệu cũ, cần Phase 2 backfill/dry-run có xác nhận riêng.

## Follow-up audit `_forced_balance`

- Rủi ro phát hiện:
  - `customer_debts.debt_total` là số dư công nợ khách, không phải net debt tổng hợp với supplier side.
  - Nếu dùng `_forced_balance` trong combined timeline, CustomerDebt ledger có thể reset running balance và làm mất tác động của `Nhập hàng`, `Thanh toán NCC`, `Trả hàng nhập` xảy ra trước hoặc xen giữa ledger.
- Test đã bổ sung:
  - `test_purchase_before_customer_ledger_does_not_reset_net_balance_with_debt_total`
  - `test_supplier_entry_between_customer_ledgers_keeps_net_running_balance`
  - `test_ledger_only_customer_keeps_debt_total_metadata`
- Thay đổi:
  - Bỏ cơ chế `_forced_balance` khỏi combined net timeline.
  - `computeRunningBalance()` chỉ cộng dồn `customer_effect` cho dòng `affects_debt_balance=true`.
  - Giữ `debt_total` và bổ sung `ledger_debt_total` làm metadata audit của CustomerDebt.
  - Với return settlement đã merge, `customer_effect` là net effect sau settlement để balance đúng, còn `amount` vẫn giữ giá trị chứng từ trả hàng gốc.
  - Excel export dùng display effect riêng cho dòng return settlement để vẫn hiển thị giá trị trả hàng, trong khi tổng kết vẫn dùng net effect.
- Kết quả:
  - Purchase trước CustomerDebt ledger không còn reset sai net debt.
  - Supplier entry xen giữa nhiều CustomerDebt ledger vẫn giữ running balance ròng.
  - Thiên Phú vẫn computed balance = `-27.600.000`.
  - `computed_balance` khớp `current_net_debt` trong các test follow-up.
  - Không update DB.
- Tests đã chạy:
  - `php artisan test tests/Feature/Customers/PartnerFinancialTimelineTest.php` — PASS, 11 tests, 62 assertions.
  - `php artisan test tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php` — PASS, 4 tests, 40 assertions.
  - `php artisan test tests/Feature/Customers/CustomerNetDebtTest.php` — PASS, 7 tests, 28 assertions.
  - `php artisan test --filter=CustomerDebt` — PASS, 37 tests, 195 assertions.
  - `php artisan test --filter=OrderReturn` — PASS, 53 tests, 213 assertions.
  - `php artisan test --filter=Purchase` — PASS, 68 tests, 306 assertions.
  - `npm run build` — PASS, Vite build completed.
- Data safety:
  - Migration: Không.
  - Backfill: Không.
  - Recalculate: Không.
  - Update dữ liệu cũ: Không.
  - Delete dữ liệu: Không.

## Kết luận

- Đạt: Backend timeline khách hàng đã phản ánh công nợ ròng đối tác, tránh double count và phân biệt dòng hạch toán thật/dòng tham khảo.
- Có thể deploy: Có, sau khi senior auditor xác nhận và manual QA production/staging.
- Cần Phase 2 dữ liệu: Có thể cần nếu production có ledger legacy thiếu liên kết hoặc mismatch đối soát.
