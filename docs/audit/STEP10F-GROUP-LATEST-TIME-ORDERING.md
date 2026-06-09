# STEP 10F — Group latest time ordering

## Phạm vi
- **Customer debt tab**: Hiển thị dòng thời gian công nợ theo chứng từ (document-first).
- **Invoice/payment grouping**: Hóa đơn (HD) và các phiếu thu thanh toán liên quan (PT/TTHD) phải đi liền nhau (HD đứng trước, PT đứng sau).
- **Group latest time**: Thứ tự các nhóm chứng từ được sắp xếp dựa trên hoạt động mới nhất trong nhóm (latest activity time của nhóm).
- **Technical ledger exclusion**: Loại bỏ hoàn toàn các ledger kỹ thuật (`MERGE-CUSTOMER-*`, `OPENING-BALANCE-*`) khỏi bảng chính công nợ mặc định.
- **Reconcile**: Đối soát khớp hoàn hảo 0đ lệch sau khi loại bỏ ledger kỹ thuật.

## Root cause
- **Vì sao sort theo invoice time làm nhóm tụt xuống**: Khi hóa đơn có hoạt động thanh toán mới (ví dụ PT lúc 16:12), nếu sắp xếp theo thời gian hóa đơn ban đầu (ví dụ 08:53), nhóm HD/PT sẽ bị đẩy xuống dưới các chứng từ khác phát sinh muộn hơn (ví dụ PN lúc 15:54), làm mất đi tính "mới nhất" của lịch sử giao dịch.
- **Vì sao sort theo row time làm PT tách khỏi HD**: Nếu sắp xếp từng dòng riêng lẻ thuần túy theo thời gian giảm dần, phiếu thu PT (16:12) sẽ bị tách khỏi hóa đơn HD cha (08:53) bởi các chứng từ trung gian khác như PN (15:54).
- **Vì sao cần group_latest_time**: Giúp gom nhóm HD/PT lại với nhau và đưa toàn bộ nhóm lên trên theo thời gian hoạt động mới nhất của bất kỳ chứng từ nào trong nhóm đó, đồng thời vẫn hiển thị HD trước PT trong nội bộ nhóm.
- **Vì sao MERGE bị cộng sai**: Bản chất `MERGE-CUSTOMER-239` là dòng ledger gộp công nợ kỹ thuật từ hệ thống cũ, nếu cộng vào timeline document-first của các chứng từ thực tế sẽ gây lệch số với số dư thực tế lưu trong DB (stored debt).

## Source đã kiểm tra
- **CustomerDebtDocumentTimelineService**: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- **CustomerController**: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- **Customers/Index.vue**: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- **Tests**: [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php), [AnhThanhThienPhuDebtReconcileTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php)

## Fix đã làm
- **document_group_key**: Đồng bộ key nhóm chứng từ cho hóa đơn và tất cả các thanh toán liên quan.
- **sort_group_latest_time**: Tính toán thời gian hoạt động mới nhất (`max(time)`) cho mỗi nhóm chứng từ trước khi thực hiện sắp xếp.
- **parent-first child-after sequence**: Sắp xếp trong nội bộ nhóm theo `sort_group_sequence` tăng dần (HD sequence 10, PT/TTHD sequence 20+).
- **display sort by group latest DESC**: Sắp xếp hiển thị các nhóm theo `sort_group_latest_time` giảm dần.
- **running balance**: Tính toán running balance lũy tiến theo thứ tự hiển thị thực tế (ASC chronological group sorting) để khớp số dư tương ứng.
- **MERGE/OPENING exclusion**: Luôn loại bỏ các ledger kỹ thuật khởi danh sách timeline mặc định và đưa vào mục `reconcile.excluded_ledger_entries`. Chỉ hiển thị nếu có `include_technical=1` hoặc `audit=1`.
- **frontend no-sort**: Frontend giữ nguyên thứ tự sắp xếp trả về từ API phân trang của backend, không tự ý sắp xếp lại.

## Data safety
- **Migration**: Không có.
- **Backfill**: Không có.
- **Update DB**: Không có.
- **Delete**: Không có.
- **Recalculate**: Chỉ tính toán động runtime trên query/collection.
- **DB writes**: 100% Read-only, không ghi hoặc thay đổi DB.
- **migrate:fresh**: Không chạy.

## API evidence sau fix
Với khách hàng `NCC178090885683 / Test Phần Mềm`:
- **HD178090993527**: Hiển thị đúng `+800.000đ`, `sort_group_latest_time = 2026-06-08 16:12:16`, `sequence = 10`.
- **PT2026060816121654**: Hiển thị đúng `-500.000đ`, `sort_group_latest_time = 2026-06-08 16:12:16`, `sequence = 20`.
- **PN20260608155433**: Hiển thị đúng `-40.000đ`, `sort_group_latest_time = 2026-06-08 15:54:00`, `sequence = 10`.
- **order**: HD178090993527 ở trên, PT2026060816121654 ngay bên dưới, và cả hai đứng trên PN20260608155433.
- **sort_group_latest_time**: Hoạt động chính xác (16:12 > 15:54).
- **MERGE-CUSTOMER-239**: Đã bị loại khỏi `entries` chính và trả về trong `reconcile.excluded_ledger_entries`.
- **document_final_balance**: Khớp đúng `1.300.000đ`.
- **stored_net**: `1.300.000đ`.
- **reconcile severity**: `ok`.

## UI evidence sau fix
- **Test Phần Mềm**: Khớp đúng `1.300.000đ`.
- **HD/PT liền nhau**: Đạt, HD đứng trước PT.
- **Nhóm HD/PT nằm trên PN 15:54**: Đạt, nhóm đưa lên đầu bảng vì hoạt động lúc 16:12.
- **MERGE còn không**: Đã biến mất hoàn toàn khỏi bảng chính.
- **Warning còn không**: Cảnh báo vàng biến mất vì lệch đối soát = 0đ.
- **Final balance**: `1.300.000đ`.
- **Nguyễn Đình Hoan**: Dư nợ 0đ, HD/PT liền nhau, reconcile severity: `ok`.
- **Anh Bẩy**: Dư nợ 0đ, HD/PT liền nhau, reconcile severity: `ok`.

## Tests
- **CustomerDebtDocumentTimelineTest**: Hoàn thành thêm 4 test cases nâng cao về group latest time sorting và MERGE exclusion. Tổng 27/27 tests PASS.
- **Regression**: Tất cả các tests KiotStyle, Supplier, DebtAdjustment, AnhThanhThienPhu đều PASS.
- **npm run build**: Compile thành công 100%.

## Kết luận
- **Đạt/chưa đạt**: Đạt 100% yêu cầu.
- **HD/PT đã liền nhau chưa**: Rồi.
- **Nhóm HD/PT đã sort theo latest activity chưa**: Rồi.
- **MERGE còn phá công nợ không**: Không, đã được loại bỏ an toàn.
- **Có thể test local tiếp chưa**: Rồi.
- **Có thể deploy code-only chưa**: Rồi, code hoàn toàn tương thích và không sửa DB.
- **Có cần đồng bộ dữ liệu cũ không**: Không.
