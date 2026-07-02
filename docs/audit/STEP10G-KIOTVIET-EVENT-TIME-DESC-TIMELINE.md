# STEP 10G — KiotViet event-time DESC debt timeline

## Phạm vi
- **Customer debt tab**: Hiển thị timeline công nợ dạng chứng từ (document-first mode).
- **Event-time sorting**: Sắp xếp timeline theo thời gian phát sinh của chứng từ (`event_sort_time`) giảm dần (DESC - mới nhất lên trên), không ép group hóa đơn cha và phiếu thanh toán liền kề nhau nếu có chứng từ khác chen giữa.
- **Running balance**: Tính toán lũy kế công nợ (running balance) theo dòng thời gian tăng dần (ASC - chronological order).
- **Invoice/payment reference**: Giữ liên kết từ phiếu thanh toán tới hóa đơn cha bằng metadata (`payment_for_code`, `parent_document_code`) và hiển thị thông tin này trên UI frontend.
- **Technical ledger exclusion**: Loại bỏ các dòng kỹ thuật dạng `MERGE-CUSTOMER-*` và `OPENING-BALANCE-*` khỏi timeline chính để tránh làm lệch số dư công nợ.

## Root cause
- **Vì sao ép group HD/PT là sai**: Theo thực tế UI KiotViet, timeline được sắp xếp thuần túy theo thời gian phát sinh thực tế của chứng từ. Nếu một phiếu thanh toán được tạo sau hóa đơn (và sau các chứng từ khác), nó phải nằm trên hóa đơn và các chứng từ trung gian đó, thay vì bị cưỡng ép đứng ngay dưới hóa đơn cha.
- **KiotViet sort theo event time DESC**: Cho phép các chứng từ tự do sắp xếp theo thời gian mới nhất lên trên. Phiếu thanh toán có thể nằm trên hóa đơn nếu thời gian của phiếu thanh toán mới hơn.
- **Cách tách display order và balance order**: 
  - Tính running balance theo thứ tự thời gian tăng dần (`event_sort_time` ASC), dùng `balance_order` làm tie-breaker (HD trước PT) và `code` ASC để đảm bảo tính toán ổn định.
  - Hiển thị timeline theo thứ tự thời gian giảm dần (`event_sort_time` DESC), dùng `display_order` làm tie-breaker (PT trước HD khi cùng timestamp để PT nằm trên HD khi hiển thị DESC) và `code` DESC.
- **MERGE technical ledger xử lý thế nào**: Exclude hoàn toàn khỏi danh sách chứng từ hiển thị mặc định và không cộng dồn vào `customer_display_running_balance` để số dư cuối cùng khớp chính xác với số nợ hiện tại trên hệ thống. Đưa các bản ghi này vào phần `reconcile.excluded_ledger_entries` để phục vụ audit/debug.

## Source đã kiểm tra
- [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php): Trực tiếp thực hiện gom dữ liệu chứng từ, chuẩn hóa thời gian phát sinh, tính toán running balance ASC và sắp xếp DESC để trả về cho client.
- [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php): Chuyển tiếp request tới service.
- [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue): Hiển thị timeline công nợ, hiển thị link reference "Cho HD..." dưới tên loại chứng từ thanh toán.
- [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php): Kiểm tra thứ tự hiển thị, liên kết chứng từ, running balance và loại trừ technical ledger.

## Fix đã làm
- **Removed group_latest_time display sorting**: Gỡ bỏ hoàn toàn logic ép nhóm theo `sort_group_latest_time` và `applyDocumentGroupLatestTime`.
- **event_time/event_sort_time**: Chuẩn hóa thời gian phát sinh cho tất cả chứng từ dựa trên trường nghiệp vụ thực tế hoặc `created_at` dự phòng.
- **balance_order**: Định nghĩa thứ tự ưu tiên tính nợ ASC (Hóa đơn/Bán hàng: 10, Thanh toán: 30).
- **display_order**: Định nghĩa thứ tự ưu tiên hiển thị DESC (Thanh toán: 90, Hóa đơn/Bán hàng: 50) để đảm bảo tie-breaker cùng phút thì PT hiển thị trên HD.
- **payment_for_code/reference**: Gán reference mã hóa đơn cha cho các phiếu thanh toán.
- **MERGE/OPENING exclusion**: Lọc bỏ các dòng kỹ thuật khỏi timeline chính và đẩy vào danh sách excluded.
- **frontend no-sort**: Đảm bảo frontend giữ nguyên thứ tự sắp xếp được backend tính toán sẵn, không tự ý sắp xếp lại.

## Data safety
- **Migration**: Không.
- **Backfill**: Không.
- **Update DB**: Không.
- **Delete**: Không.
- **Recalculate**: Không.
- **DB writes**: Không (Có kiểm thử no-db-writes bảo vệ).
- **migrate:fresh**: Không chạy.

## API evidence sau fix
Customer ID 240 (`NCC178090885683`):
- **Entries order Test Phần Mềm**:
  1. `PT2026060816121654` (2026-06-08 16:12:16)
  2. `PN20260608155433` (2026-06-08 15:54:00)
  3. `PTN20260608160555` (2026-06-08 09:06:19)
  4. `TH2026060816051522` (2026-06-08 09:05:15)
  5. `HD178090993527` (2026-06-08 08:53:00)
  6. `HD178090878843` (2026-05-28 08:51:00)
- **PT reference to HD**: `PT2026060816121654` liên kết đến `HD178090993527` (`payment_for_code` = `"HD178090993527"`).
- **HD running balance**: 2,800,000đ.
- **PT running balance**: 1,300,000đ.
- **MERGE-CUSTOMER-239 trong entries chính không**: Không, đã bị loại bỏ khỏi entries chính.
- **document_final_balance**: 1,300,000đ.
- **stored_net**: 1,300,000đ.
- **reconcile severity**: `ok` (Không còn warning).

## UI evidence sau fix
- **Test Phần Mềm**: Hiển thị 6 dòng đúng thứ tự thời gian giảm dần, dòng thanh toán hiển thị thêm "Cho HD178090993527".
- **Sort thời gian DESC**: Cột Thời gian hiển thị mới nhất lên trên cùng.
- **PT có thể nằm trên HD**: Xác nhận PT (16:12) nằm trên HD (08:53) và có các chứng từ khác xen giữa.
- **Reference PT -> HD**: Hiển thị rõ ràng trên UI dưới tên loại chứng từ.
- **MERGE còn không**: Không xuất hiện dòng `MERGE-CUSTOMER-239`.
- **Warning còn không**: Khớp hoàn toàn nên banner cảnh báo tự động ẩn đi.
- **Nguyễn Đình Hoan**: Timeline sắp xếp chuẩn DESC, running balance đúng về 0đ.
- **Anh Bẩy**: Không trùng lặp chứng từ và giữ nguyên các dòng điều chỉnh.

## Tests
- **CustomerDebtDocumentTimelineTest**: Đã cập nhật 3 test cũ bị lỗi do đổi logic và thêm 4 test mới (`test_document_timeline_displays_by_event_time_desc_like_kiotviet`, `test_payment_keeps_parent_invoice_reference`, `test_running_balance_uses_chronological_asc`, `test_same_timestamp_tie_breaker_resembles_kiotviet`). Toàn bộ 28 tests PASS.
- **Regression**: Tất cả các test liên quan đều PASS.
- **npm run build**: Chạy thành công.

## Kết luận
- **Đạt/chưa đạt**: Đạt yêu cầu.
- **Timeline đã sort theo thời gian mới nhất chưa**: Rồi (DESC theo `event_sort_time`).
- **Running balance còn đúng không**: Đúng (được tính toán tuần tự theo ASC).
- **PT có biết thanh toán cho HD nào không**: Có (hiển thị reference liên kết chứng từ rõ ràng).
- **MERGE còn phá công nợ không**: Không, đã bị exclude khỏi timeline chính.
- **Có thể test local tiếp chưa**: Có.
- **Có thể deploy code-only chưa**: Có.
- **Có cần đồng bộ dữ liệu cũ không**: Không cần.
