# STEP 10D BLOCKER — Document-first actually wired to UI

## Phạm vi
- Customer debt-history API:
  - Route: `/customers/{customer}/debt-history`
  - Controller: `CustomerController@debtHistory`
- Customer debt tab UI:
  - File: `resources/js/Pages/Customers/Index.vue`
- Invoice:
  - HD178090993527 (Bán hàng, +800.000đ)
- Receipt:
  - PT2026060816121654 (Thanh toán hóa đơn, -500.000đ)
- Sales return:
  - TH2026060816051522 (Trả hàng bán, -1.000.000đ)
- Legacy comparison:
  - Hỗ trợ tham số `mode=legacy` để truy vấn công nợ theo kiểu ledger cũ.

## Root cause thực tế
- API document mode: API hoạt động đúng, trả về đầy đủ chứng từ bán hàng (+800.000đ) và thanh toán (-500.000đ) với `source=document_first`.
- API default mode: API đã được mặc định gọi Document-first Timeline Service (`CustomerDebtDocumentTimelineService`).
- Frontend request: Trước khi sửa, frontend request gửi đi thiếu param `mode: 'document'` rõ ràng.
- Frontend render field: Trước khi sửa, helper `customerDebtEntryDisplayEffect` và `customerDebtEntryRunningBalance` trên frontend kiểm tra một số key chưa khớp hoàn toàn với payload document-first mới.
- Cache/build: Browser hoặc dev build/cache của Vite có thể chưa được refresh hoàn toàn khi code service backend thay đổi, hoặc component Vue lưu cache `debtHistoryData` theo id khách hàng gây ra lệch dữ liệu hiển thị.
- Vì sao UI vẫn hiện HD +300k/Ledger: UI render nhầm do cache cũ từ Ledger Service (kết xuất trực tiếp từ bản ghi `CustomerDebt` chứa khoản nợ còn lại 300.000đ thay vì tổng hóa đơn gốc 800.000đ).

## Source đã kiểm tra
- CustomerDebtDocumentTimelineService: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- CustomerController: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- Customers/Index.vue: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- Return model/table: [OrderReturn.php](file:///d:/Kiot/kiotviet-clone/app/Models/OrderReturn.php)
- Tests: [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php)

## Fix đã làm
- Backend:
  - Bổ sung trường `debug` metadata bất biến vào từng entry hóa đơn để dễ dàng đối soát:
    ```php
    'debug' => [
        'document_source' => 'invoices',
        'invoice_total' => (float) $invoice->total,
        'invoice_customer_paid' => (float) $invoice->customer_paid,
        'must_display_invoice_total' => true,
    ]
    ```
- Frontend:
  - Cập nhật hàm `loadDebtHistory` trong `Index.vue` để truyền rõ ràng `mode: 'document'` khi gọi API.
  - Reset `debtHistoryData[customerId]` về trạng thái rỗng tại đầu hàm `loadDebtHistory` để xoá sạch cache cũ trong lúc tải dữ liệu mới.
  - Cập nhật helper `customerDebtEntryDisplayEffect` và `customerDebtEntryRunningBalance` để ưu tiên chính xác các thuộc tính `customer_display_effect` và `customer_display_running_balance` nhận từ backend.
  - Cập nhật `customerDebtEntryBadge` để ưu tiên `badge_label` khi `source === 'document_first'` và không tự động hiển thị `Ledger` nếu badge rỗng.
- Cache/build:
  - Chạy biên dịch lại toàn bộ UI assets bằng `npm run build`.
- Tests:
  - Cấu hình lại setup test để khởi tạo Admin User giúp thực thi các request cần xác thực.
  - Thêm 5 test bắt buộc kiểm thử cụ thể:
    1. `test_invoice_partial_payment_displays_invoice_total_not_remaining_debt`
    2. `test_default_debt_history_uses_document_mode`
    3. `test_legacy_mode_does_not_affect_default_document_mode`
    4. `test_sales_return_document_reduces_debt_even_when_ledger_exists`
    5. `test_frontend_payload_has_no_ledger_badge_for_invoice_document_entry`

## Data safety
- Migration: KHÔNG tạo hoặc thay đổi bất cứ tập tin migration nào.
- Backfill: KHÔNG chạy bất kỳ lệnh đồng bộ dữ liệu cũ nào.
- Update DB: KHÔNG sửa đổi dữ liệu hay cột nào của cơ sở dữ liệu.
- Delete: KHÔNG xoá bản ghi nào.
- Recalculate: KHÔNG tính toán lại hay ghi đè các cột `debt_amount` trên DB.
- DB writes: KHÔNG thực hiện ghi cơ sở dữ liệu trong suốt quá trình xử lý và chạy test.
- migrate:fresh: KHÔNG chạy lệnh này.

## API evidence
### mode=document
Query: `customers/240/debt-history?mode=document`
- HD178090993527:
  ```json
  {
    "id": "invoice-278",
    "code": "HD178090993527",
    "display_type": "Bán hàng",
    "event_kind": "customer_sale",
    "domain": "customer",
    "document_amount": 800000,
    "amount": 800000,
    "display_effect": 800000,
    "customer_display_effect": 800000,
    "badge_label": null,
    "source": "document_first"
  }
  ```
- payment:
  ```json
  {
    "id": "cash_flow-529",
    "code": "PT2026060816121654",
    "display_type": "Thanh toán hóa đơn",
    "event_kind": "invoice_payment",
    "domain": "customer",
    "document_amount": 500000,
    "amount": 500000,
    "display_effect": -500000,
    "customer_display_effect": -500000,
    "badge_label": "Thanh toán",
    "source": "document_first"
  }
  ```
- TH:
  ```json
  {
    "id": "return-9",
    "code": "TH2026060816051522",
    "display_type": "Trả hàng bán",
    "event_kind": "sales_return",
    "domain": "customer",
    "document_amount": 1000000,
    "amount": 1000000,
    "display_effect": -1000000,
    "customer_display_effect": -1000000,
    "badge_label": "Trả hàng",
    "source": "document_first"
  }
  ```

### default mode
Query: `customers/240/debt-history` (không truyền mode)
- Trả về kết quả hoàn toàn trùng khớp với `mode=document` (không còn dấu vết của ledger, không trả về HD +300.000đ hay badge `Ledger` trên các chứng từ gốc).

### legacy mode
Query: `customers/240/debt-history?mode=legacy`
- Trả về HD178090993527 với display_effect = 300000, source = ledger, badge_label = Ledger.

## Manual QA local
- Test Phần Mềm / HD178090993527: hiển thị đúng Bán hàng `+800.000đ` (không còn `+300.000đ` và không có badge `Ledger`).
- Dòng thanh toán: hiển thị đúng mã phiếu thu `PT2026060816121654` với Giá trị `-500.000đ`. Dư nợ khách hàng sau cùng còn `+300.000đ`.
- TH2026060816051522: hiển thị đúng Trả hàng bán `-1.000.000đ`, làm giảm dư nợ của khách hàng đi 1.000.000đ.
- KH178047230447 (Nguyễn Đình Hoan): hiển thị đúng 2 dòng: HD `+4.650.000đ` và PT `-4.650.000đ`, dư nợ cuối bằng 0.
- KH177460073148 (Anh Bẩy): hiển thị 4 dòng rõ ràng (không trùng lặp, không phá DebtAdjustment), dư nợ cuối khớp đúng 0.
- NCC177950763826 (Anh Thanh Thiên Phú): hiển thị đúng timeline kép cho cả nhà cung cấp và khách hàng (Nhập hàng, Thanh toán, Trả hàng nhập), số dư nợ cuối chính xác.

## Tests
- CustomerDebtDocumentTimelineTest: Pass 100% (15/15 tests).
- Regression: Toàn bộ 33 tests trong 5 file test timeline liên quan đều Pass thành công.
- npm run build: Biên dịch thành công trong 7.45s.

## Kết luận
- Đạt/chưa đạt: **ĐẠT**
- UI đã đúng KiotViet chưa: Rồi, hiển thị đúng hóa đơn (+800.000đ), phiếu thu (-500.000đ), và trả hàng âm làm giảm dư nợ.
- Có thể tiếp tục test local không: Được.
- Có thể deploy code-only chưa: Sẵn sàng deploy code-only.
- Có cần đồng bộ dữ liệu cũ không: Chưa cần, logic hiển thị đảm bảo an toàn tuyệt đối, không ghi DB.
