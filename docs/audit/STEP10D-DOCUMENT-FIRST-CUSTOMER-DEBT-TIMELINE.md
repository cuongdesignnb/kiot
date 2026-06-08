# STEP 10D — Document-first customer debt timeline

## Phạm vi
- Customer debt tab: Rebuilt using a document-first timeline matching KiotViet's display design.
- Invoice: Maps to `+invoice.total` (not remaining debt) and represents "Bán hàng".
- Receipt: Maps to `-cash_flow.amount` and represents "Thanh toán hóa đơn" (individually, no grouping).
- Sales return: Maps to `-return.total` and represents "Trả hàng bán" (decreases debt).
- Refund: Maps to `+return.paid_to_customer` and represents "Hoàn tiền khách" (increases debt if store refunds money).
- Dual-role supplier mirror: Incorporates supplier purchase (`-purchase.total_amount`), payment (`+cash_flow.amount`), fallback supplier payment (`TTNH`), and supplier return (`+purchase_return.total_amount`).
- Adjustment/opening: Adjustments from the `customer_debts` ledger (e.g. "Gộp công nợ", "Số dư đầu kỳ") display with clean badges and adjust running balance.

## Bối cảnh
- Local DB đã import production: Dữ liệu local DB khớp hoàn toàn với bản sao lưu dữ liệu production thật.
- Local DB khớp production: Hoàn toàn chính xác, không thay đổi hay xóa dữ liệu.
- Lý do dừng STEP 10C: Timeline cũ đang trộn lẫn giữa ledger công nợ và chứng từ gốc, dẫn đến UI bị rối và tính toán sai effect (như hóa đơn 800k thanh toán 500k hiển thị chỉ +300k thay vì hai dòng rõ ràng).
- Root cause cũ: Service cũ dựa vào dòng ledger `customer_debts` ghi nhận phần còn nợ của hóa đơn thay vì dựa trực tiếp trên các chứng từ gốc độc lập.

## Source đã kiểm tra
- CustomerController: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- PartnerDebtLedgerService: [PartnerDebtLedgerService.php](file:///d:/Kiot/kiotviet-clone/app/Services/PartnerDebtLedgerService.php)
- CustomerDebtDocumentTimelineService: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- InvoiceSaleService: [InvoiceSaleService.php](file:///d:/Kiot/kiotviet-clone/app/Services/InvoiceSaleService.php)
- Return model/table: `returns` table mapped to [OrderReturn.php](file:///d:/Kiot/kiotviet-clone/app/Models/OrderReturn.php)
- CashFlow: [CashFlow.php](file:///d:/Kiot/kiotviet-clone/app/Models/CashFlow.php)
- Frontend: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)

## Công thức KiotViet document-first
- Invoice: `+total` (customer_display_effect = +invoice.total).
- Receipt: `-amount` (customer_display_effect = -cash_flow.amount).
- Fallback: `-customer_paid` (khi không tìm thấy phiếu thu thật, hiển thị dưới dạng tạm tính `TTHD`).
- Sales return: `-total` (customer_display_effect = -return.total).
- Refund: `+paid_to_customer` (khi phiếu trả hàng có chi hoàn tiền, hiển thị dưới dạng tạm tính `PCTH`).
- Purchase dual-role: `-total_amount` (giảm nợ phải thu của khách hàng vì mình nợ họ).
- Supplier payment: `+amount` (tăng nợ phải thu của khách hàng khi trả nợ NCC).
- Adjustment/opening: Cộng/trừ theo giá trị cột `amount` trong `customer_debts`.

## Thay đổi code
- Service mới: Tạo mới [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php) để xử lý logic timeline document-first.
- Controller: Cập nhật `debtHistory` và `exportDebtHistory` trong [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php) để gọi service mới theo mặc định.
- Frontend: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue) hiển thị đúng `customer_display_effect` and `customer_display_running_balance`. Fallback `TTHD` được cấu hình `is_virtual_fallback = true` và `detail_available = false` để chặn click mở modal.
- Tests: Cập nhật `detail_available` của virtual fallback trong [CustomerDebtVoucherDetailTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtVoucherDetailTest.php) và thêm test mới [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php) với 10 Scenario bao quát.
- Legacy service giữ lại: [PartnerDebtLedgerService.php](file:///d:/Kiot/kiotviet-clone/app/Services/PartnerDebtLedgerService.php) được giữ nguyên để hỗ trợ chế độ tương thích (`mode=legacy`) và tránh gây lỗi cho các chức năng khác.

## Data safety
- Migration: Không tạo hay chạy bất kỳ file migration nào.
- Backfill: Không thực hiện backfill hay chạy lệnh sửa dữ liệu cũ.
- Update DB: Không cập nhật dữ liệu.
- Delete: Không xóa bất kỳ bản ghi nào.
- Recalculate: Không chạy lệnh tính lại công nợ hay lưu lại DB.
- migrate:fresh: Không chạy.
- DB writes: Không ghi dữ liệu vào database (ngoại trừ quá trình chạy tests sử dụng database test riêng biệt).
- Export files committed: Không commit các file export, file cache, hay file log tạm thời.

## Local automated tests
- invoice partial payment: Đạt (Test `test_invoice_800k_paid_500k_shows_invoice_total_and_receipt_amount`)
- invoice unpaid: Đạt (Test `test_unpaid_invoice_shows_full_invoice_total`)
- invoice fully paid: Đạt (Test `test_fully_paid_invoice_final_balance_zero`)
- fallback: Đạt (Test `test_fallback_payment_from_customer_paid`)
- sales return: Đạt (Test `test_sales_return_reduces_customer_debt_in_document_timeline`)
- sales return refund: Đạt (Test `test_sales_return_with_refund`)
- not using CustomerDebt for invoice display: Đạt (Test `test_does_not_use_CustomerDebt_sale_amount_as_invoice_display_amount`)
- no DB writes: Đạt (Test `test_no_db_writes`)
- regression: Đạt. Tất cả các test case cũ của KiotStyleCustomerDebtTimelineTest, DebtAdjustmentTimelineDisplayTest, và AnhThanhThienPhuDebtReconcileTest đều chạy ĐẠT 100%.
- npm run build: Đạt (Tập tin build thành công trong 7.64s).

## Local manual QA trên DB production import
- NCC178090885683 / HD178090993527: Đạt. Hóa đơn hiển thị trị giá bán hàng gốc là `+800.000đ` và phiếu thu tương ứng là `-500.000đ`, số dư nợ cuối của khách hàng là `300.000đ`.
- TH202605221641861: Đạt. Phiếu trả hàng bán giảm trừ công nợ đúng `-7.000.000đ` trên timeline.
- KH177460073148: Đạt. Số dư cuối là `0đ`, các điều chỉnh công nợ của Anh Bẩy hiển thị đúng đắn.
- KH178047230447: Đạt. Hóa đơn `HD178047231653` (`+4.650.000đ`) và phiếu thu tương ứng (`-4.650.000đ`) cấn trừ triệt để công nợ về `0đ`.
- NCC177950763826: Đạt. Hiển thị chính xác các giao dịch nhập hàng NCC (`-purchase.total`), thanh toán NCC, và trả hàng nhập của đối tác vai trò kép.

## Reconcile
- document_final vs stored debt: So khớp giữa số dư tính toán từ timeline chứng từ gốc và số dư thực tế trong DB.
- warning cases: Nếu có chênh lệch lớn hơn 1đ, hệ thống sẽ trả về cảnh báo warning.
- không tự sửa DB: Cam kết không sửa hay cập nhật database để ép khớp số dư.

## Kết luận
- Đạt/chưa đạt: Đạt 100% mục tiêu STEP 10D ở local.
- Có thể test tiếp ở local chưa: Có thể test tiếp tục thoải mái.
- Có thể deploy code-only chưa: Có thể deploy code-only an toàn vì không có migration/DB writes nào.
- Có cần đồng bộ dữ liệu cũ không: Giai đoạn hiển thị này không cần, nhưng nếu cần đồng bộ trong tương lai thì cần tạo bước xác nhận trước.
- Nếu cần đồng bộ dữ liệu cũ thì cần xác nhận trước: Có.
