# STEP 10D FINAL GATE — Document-first wiring fixed

## Phạm vi
- Customer debt-history API: `/customers/{customer}/debt-history`
- Customer debt tab UI: `resources/js/Pages/Customers/Index.vue`
- Invoice: `HD178090993527` (Bán hàng, 800.000đ)
- Receipt: `PT2026060816121654` (Thanh toán hóa đơn, -500.000đ)
- Sales return: `TH2026060816051522` (Trả hàng bán, -1.000.000đ)
- Frontend rendering: Cập nhật các helper `customerDebtEntryDisplayEffect`, `customerDebtEntryRunningBalance`, và `customerDebtEntryBadge` trong `Index.vue` để liên kết đúng trường dữ liệu.
- Cache/build: Build lại Docker container và reload asset để khắc phục việc container chạy source cũ.

## Root cause thực tế
- API mode=document trước fix: Đúng (Trực tiếp trả về hóa đơn 800.000đ, phiếu thu -500.000đ).
- API default trước fix: Đúng (Mặc định trả về document-first mode).
- UI trước fix: Hiển thị dòng hóa đơn `HD178090993527 Bán hàng +300.000đ Ledger`.
- Vì sao HD vẫn +300k: Do container Docker `kiotviet-app-clone` không tự động đồng bộ hóa các file source/asset thay đổi ở thư mục host (chỉ mount `./storage` thay vì toàn bộ code). Nên container chạy code cũ lúc build ban đầu.
- Vì sao badge Ledger vẫn hiện: Do render nhầm bản ghi ledger cũ do container chưa được rebuild.

## Source đã kiểm tra
- CustomerDebtDocumentTimelineService: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- CustomerController: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- Customers/Index.vue: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- Debug command: [DebugDocumentTimelineCommand.php](file:///d:/Kiot/kiotviet-clone/app/Console/Commands/DebugDocumentTimelineCommand.php)
- Tests: [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php)

## Fix đã làm
- Backend: Tạo command Artisan `debt:debug-document-timeline` để kiểm định API tự động, đảm bảo logic luôn khớp.
- Controller default: Giữ nguyên mặc định `mode=document` ở route `/debt-history`.
- Frontend request: Truyền rõ tham số `mode=document` từ `loadDebtHistory`.
- Frontend render field: Thay đổi helper để lấy chính xác `customer_display_effect` và `customer_display_running_balance`.
- Badge logic: Nếu `source === 'document_first'` và `badge_label` null thì ẩn badge, loại trừ fallback Ledger đè.
- Cache/build: Chạy `docker compose build app` để compile mã nguồn mới vào image, sau đó `docker compose up -d app` để chạy container mới.

## Data safety
- Migration: Không
- Backfill: Không
- Update DB: Không
- Delete: Không
- Recalculate: Không
- DB writes: Không
- migrate:fresh: Không

## API evidence sau fix

### mode=document
```json
{
  "code": "HD178090993527",
  "display_type": "Bán hàng",
  "event_kind": "customer_sale",
  "source": "document_first",
  "badge_label": null,
  "document_amount": 800000,
  "display_effect": 800000,
  "customer_display_effect": 800000
}
{
  "code": "PT2026060816121654",
  "display_type": "Thanh toán hóa đơn",
  "event_kind": "invoice_payment",
  "source": "document_first",
  "badge_label": "Thanh toán",
  "document_amount": 500000,
  "display_effect": -500000,
  "customer_display_effect": -500000
}
{
  "code": "TH2026060816051522",
  "display_type": "Trả hàng bán",
  "event_kind": "sales_return",
  "source": "document_first",
  "badge_label": "Trả hàng",
  "document_amount": 1000000,
  "display_effect": -1000000,
  "customer_display_effect": -1000000
}
```

### default mode
- Kết quả trả về giống hệt `mode=document`.

### legacy mode
- HD178090993527 có `display_effect` = 300000, `source` = "ledger", `badge_label` = "Ledger".

## Debug command evidence
- Command: `php artisan debt:debug-document-timeline --customer-code=NCC178090885683 --document-code=HD178090993527`
- Output:
```text
customer_code: NCC178090885683
document_code: HD178090993527
api_mode_document_effect: 800000
api_default_effect: 800000
source: document_first
badge_label: null
invoice_total: 800000
invoice_customer_paid: 500000
cashflow_total: 500000
expected_invoice_effect: 800000
pass/fail: PASS
HD178090993527 expected +800000 actual +800000 PASS
```
- PASS/FAIL: **PASS**

## Manual QA local
- Test Phần Mềm / HD178090993527: hiển thị đúng Bán hàng `+800.000đ`, không badge Ledger.
- TH2026060816051522: hiển thị đúng Trả hàng bán `-1.000.000đ`, làm giảm dư nợ khách hàng đi 1.000.000đ.
- KH178047230447: HD +4.650.000đ, PT -4.650.000đ, dư nợ cuối bằng 0.
- KH177460073148: Không bị nhân bản, cấn trừ hiển thị hoàn hảo, dư nợ cuối về 0.

## Tests
- CustomerDebtDocumentTimelineTest: Pass (15/15 tests)
- Regression: Pass (33/33 tests)
- npm run build: Pass

## Kết luận
- Đạt/chưa đạt: **ĐẠT**
- UI đã đúng KiotViet chưa: Rồi.
- Có còn HD +300k không: Không.
- Có còn Ledger badge ở dòng HD document không: Không.
- Trả hàng đã giảm công nợ chưa: Rồi.
- Có thể tiếp tục test local không: Được.
- Có thể deploy code-only chưa: Sẵn sàng deploy code-only.
- Có cần đồng bộ dữ liệu cũ không: Chưa cần.
