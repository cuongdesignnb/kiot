# STEP 10F — Document timeline grouping and technical ledger exclusion

## Phạm vi
- Customer debt tab: Hiển thị lịch sử công nợ theo phương pháp Document-first đúng chuẩn KiotViet.
- Invoice/payment grouping: Phiếu thanh toán (PT/TTHD) được gom nhóm hiển thị ngay dưới hóa đơn cha (HD) tương ứng, giữ nguyên thời gian thật của phiếu thanh toán.
- Technical ledger exclusion: Loại bỏ các dòng ledger kỹ thuật như `MERGE-CUSTOMER-*` và `OPENING-BALANCE-*` khỏi timeline và running balance khi đã có chứng từ thật tương ứng để tránh double-counting.
- Running balance: Tính toán lũy kế cộng dồn theo trình tự thời gian tăng dần (ASC) của các nhóm chứng từ, hiển thị theo trình tự thời gian giảm dần (DESC) của nhóm chứng từ.
- Reconcile: Đối soát tự động giữa số dư cuối cùng của chứng từ và số nợ thực tế lưu trữ trong DB, không hiển thị warning nếu đã khớp.

## Root cause
- Vì sao PT nằm xa HD: Do trước đây hệ thống sắp xếp thuần túy theo thời gian giao dịch thực tế của từng chứng từ độc lập, khiến phiếu thanh toán (vốn được tạo sau hóa đơn cha) bị tách rời lên trên bởi các giao dịch khác tạo xen giữa.
- Vì sao MERGE-CUSTOMER-239 bị cộng sai: Vì đây là dòng gộp nợ kỹ thuật khi chuyển khách hàng `239` sang khách hàng `240` với giá trị +2.000.000đ. Tuy nhiên, hóa đơn gốc của khách hàng 239 là `HD178090878843` (+2.000.000đ) cũng đã được chuyển sang khách hàng 240. Do đó, việc cộng cả hóa đơn gốc lẫn dòng gộp nợ dẫn đến bị tính trùng 2.000.000đ.
- Source cũ lấy CustomerDebt fallback thế nào: Service lấy toàn bộ các bản ghi trong bảng `customer_debts` có mã tham chiếu không trùng với danh sách chứng từ thật đã lấy. Vì mã `MERGE-CUSTOMER-239` không trùng với mã hóa đơn `HD178090878843` nên nó bị lấy làm fallback và cộng vào công nợ.

## Source đã kiểm tra
- CustomerDebtDocumentTimelineService: Chứa logic xây dựng timeline chứng từ, gom nhóm, tính running balance, và đối soát.
- CustomerController: Gọi service để trả payload cho frontend và xuất file Excel.
- Customers/Index.vue: Hiển thị danh sách lịch sử công nợ, đã kiểm tra không có hàm sort local làm thay đổi thứ tự từ backend.
- Tests: Toàn bộ test suite được chạy để đảm bảo tính ổn định và không bị regression.

## Fix đã làm
- document_group_key: Đặt mã khóa nhóm là mã hóa đơn cha (HD) đối với cả hóa đơn và phiếu thanh toán của hóa đơn đó.
- document_group_sequence: Thiết lập thứ tự sắp xếp trong nhóm (10 cho hóa đơn, 20+ cho phiếu thanh toán).
- sort_group_time: Đặt thời gian sắp xếp nhóm theo thời gian của hóa đơn cha.
- sort ASC để tính running: Sắp xếp danh sách ASC theo `sort_group_time`, `sort_group_key`, `sort_group_sequence` trước khi cộng dồn running balance để đảm bảo hóa đơn cha được cộng trước phiếu thanh toán của nó.
- sort DESC theo group để display: Sắp xếp DESC theo `sort_group_time` và `sort_group_key` để đưa các nhóm chứng từ mới nhất lên trên, nhưng giữ sequence ASC để hóa đơn cha luôn nằm ngay trên phiếu thanh toán của nó.
- MERGE/OPENING technical exclusion: Viết method `isTechnicalLedgerCode` kiểm tra tiền tố mã kỹ thuật. Hệ thống sẽ tự động loại trừ dòng gộp nợ/số dư đầu kỳ kỹ thuật ra khỏi timeline chính nếu khách hàng đã có các chứng từ thật tương ứng (nhằm tránh double-counting), đồng thời lưu vết vào mục `excluded_ledger_entries` trong reconcile để đối soát. Nếu khách hàng không có chứng từ thật (như trường hợp test Anh Thanh Thiên Phú), dòng gộp nợ vẫn được giữ để làm số dư đầu kỳ.
- frontend giữ order backend: Loại bỏ mọi logic sort local làm phá vỡ cấu trúc gom nhóm chứng từ từ backend.

## Data safety
- Migration: Không tạo/chạy migration.
- Backfill: Không thực hiện backfill dữ liệu cũ.
- Update DB: Không cập nhật thủ công cơ sở dữ liệu.
- Delete: Không xóa bản ghi.
- Recalculate: Không chạy lệnh tính toán lại số dư trong DB.
- DB writes: Đảm bảo read-only, không ghi bất kỳ dữ liệu nào vào cơ sở dữ liệu.
- migrate:fresh: Không chạy migrate:fresh làm mất mát dữ liệu local khớp với production.

## API evidence sau fix
- HD178090993527: `display_effect` = +800.000, `customer_display_running_balance` = 2.800.000 (nằm ngay trên phiếu thanh toán).
- PT2026060816121654: `display_effect` = -500.000, `customer_display_running_balance` = 2.300.000 (nằm ngay dưới hóa đơn cha).
- MERGE-CUSTOMER-239: Không xuất hiện trong entries chính, được đưa vào danh sách loại trừ.
- document_final_balance: 1.300.000
- stored_net: 1.300.000
- reconcile severity: ok (khớp hoàn toàn, không có cảnh báo).
- excluded ledger entries: Chứa `MERGE-CUSTOMER-239` giá trị 2.000.000đ.

## Manual QA local
- Test Phần Mềm (NCC178090885683):
  - HD178090878843: +2.000.000đ | Dư nợ: 2.000.000đ
  - HD178090993527: +800.000đ | Dư nợ: 2.800.000đ
  - PT2026060816121654: -500.000đ | Dư nợ: 2.300.000đ (nằm ngay dưới hóa đơn gốc)
  - TH2026060816051522: -1.000.000đ | Dư nợ: 1.300.000đ
  - Số dư cuối cùng: 1.300.000đ (khớp hoàn toàn với Nợ hiện tại).
  - Không xuất hiện MERGE-CUSTOMER-239 trong bảng chính.
- Nguyễn Đình Hoan (KH178047230447): HD +4.650.000đ nằm trên, PT -4.650.000đ nằm ngay dưới, số dư cuối cùng là 0đ.
- Anh Bẩy (KH177460073148): Các điều chỉnh công nợ hiển thị chính xác, không bị trùng lặp hay sai lệch số dư.
- Return case: Các phiếu trả hàng bán (TH) giữ nguyên giá trị âm và giảm trừ công nợ chính xác.

## Tests
- CustomerDebtDocumentTimelineTest: 23/23 tests PASS (đã thêm các test case mới về gom nhóm thanh toán liền kề hóa đơn cha, sắp xếp nhiều phiếu thanh toán, loại trừ MERGE, và cô lập số dư đầu kỳ kỹ thuật).
- Regression: KiotStyleCustomerDebtTimelineTest, KiotStyleSupplierDebtTimelineTest, DebtAdjustmentTimelineDisplayTest, AnhThanhThienPhuDebtReconcileTest đều PASS.
- npm run build: Thành công, không có lỗi biên dịch tài nguyên frontend.

## Kết luận
- Đạt/chưa đạt: ĐẠT 100% mục tiêu của STEP 10F.
- PT đã nằm ngay sau HD chưa: Rồi, phiếu thanh toán hiển thị liền sau hóa đơn cha trong cả API và UI.
- MERGE đã không còn cộng sai công nợ chưa: Rồi, dòng MERGE kỹ thuật bị loại bỏ hoàn toàn khỏi timeline chính và running balance khi đã có chứng từ thật.
- Final đã khớp Nợ hiện tại ở case Test Phần Mềm chưa: Rồi, khớp hoàn toàn ở mức 1.300.000đ và không còn hiển thị warning banner.
- Có thể tiếp tục test local không: Có.
- Có thể deploy code-only chưa: Có thể deploy code-only an toàn.
- Có cần đồng bộ dữ liệu cũ không: Không cần ở bước này.
