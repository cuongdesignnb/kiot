# P0 hotfix: sửa thông tin thương mại hóa đơn không xuất kho lại

## Root cause

Luồng `PUT /invoices/{invoice}` trước đây luôn dùng cơ chế reverse/re-apply cho
mọi thay đổi. Vì vậy một thay đổi chỉ về đơn giá, thanh toán, khách hàng, người
bán hoặc ghi chú vẫn có thể đi qua các tác vụ tồn kho, giá vốn, serial/IMEI và
bảo hành. Đây là rủi ro P0 đối với hóa đơn đã hoàn thành, đặc biệt khi hàng đã
hết tồn hoặc serial đang ở trạng thái `sold`.

## Contract sau hotfix

`InvoiceUpdateService` lập change plan từ trạng thái đã persist và payload:

| Nhóm thay đổi | Persistence contract |
| --- | --- |
| Chỉ thương mại: đơn giá, chiết khấu, ghi chú, khách hàng, người bán, thanh toán, metadata | Cập nhật in-place invoice/invoice_items và điều chỉnh delta công nợ/cashflow. Không gọi reverse/re-apply tồn kho. |
| Chỉ ngày chứng từ | Dùng date-only path hiện có. |
| Identity tồn kho: sản phẩm, số lượng hoặc tập serial/IMEI | Giữ inventory replay path, có lock và restore/re-apply theo contract cũ. |

Identity tồn kho được canonical hóa từ `invoice_item_id`, `product_id`, số lượng
và danh sách `InvoiceItemSerial`; không bao gồm đơn giá, chiết khấu, khách hàng,
người bán, thanh toán hoặc ghi chú. Payload thương mại phải giữ nguyên identity
này, nếu không service từ chối thay vì tự suy diễn replay.

Với commercial-only path:

- không tạo/xóa `StockMovement`, không đổi `Product.stock_quantity`;
- không đổi trạng thái/liên kết/cost của `SerialImei`, không đổi warranty;
- không gọi cập nhật giá vốn hay allocation giá vốn;
- chỉ áp dụng delta `debt_amount`/`total_spent` của partner và cập nhật cashflow
  thanh toán của chính hóa đơn in-place;
- khi đổi customer, chuyển ledger `sale` của cùng `ref_code` sang customer mới,
  không tái tạo chứng từ;
- creator snapshot và lịch sử chứng từ không bị thay đổi.

Hóa đơn có trạng thái `Đã hủy`, `cancelled`, `canceled` hoặc `void` bị chặn ở
cả route mở chỉnh sửa và API update, trả thông báo tiếng Việt.

## UI behavior

- Danh sách hóa đơn không hiện CTA chỉnh sửa cho hóa đơn đã hủy.
- POS/order edit giữ `invoice_item_id`, ghi chú và `serial_ids` trong payload.
- Lỗi xác thực khi chỉnh sửa hiển thị inline cho dòng hàng, ghi chú, khách hàng
  và tiền thanh toán; không dùng browser alert trong edit flow.

## Files changed

- `app/Services/InvoiceUpdateService.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/OrderController.php`
- `resources/js/Pages/Orders/Create.vue`
- `resources/js/Pages/Invoices/Index.vue`
- `tests/Feature/Invoice/InvoiceCommercialOnlyUpdateTest.php`
- `docs/audit/HOTFIX-INVOICE-COMMERCIAL-EDIT-NO-INVENTORY-REPLAY.md`

## QA evidence

### Automated, local MySQL Docker QA only

| Case | Expected | Actual | Result |
| --- | --- | --- | --- |
| Price-only, sold-out serial invoice | No stock/cost/serial/warranty mutation; financial delta only | 5-test commercial suite verified all invariants | PASS |
| Payment-only update | No duplicate cashflow or debt | Idempotent update asserted | PASS |
| Customer + seller transfer | Inventory and creator snapshot retained; ledger ownership follows customer | Asserted, including financial history preservation | PASS |
| Quantity/serial change | Classified as inventory replay, not commercial-only | Asserted | PASS |
| Cancelled invoice | Rejected without mutation | Asserted | PASS |
| Existing invoice impact regression | Existing replay behavior remains valid | `Step243InvoiceUpdateEngineImpactTest`: 15 passed, 1 schema-dependent skip | PASS |
| Transaction date regression | Existing date-only contract remains valid | `InvoiceTransactionDateTimeUpdateTest`: 5 passed | PASS |
| Browser smoke | Edit form opens for a completed invoice with persisted lines, payment and update CTA | Local `orders/create?action=edit&invoice_id=388` rendered correctly | PASS |
| Frontend production build | Vite build succeeds | 924 modules transformed | PASS |

### Review-blocker closure evidence (local MySQL QA only)

| Case | Expected | Actual | Result |
| --- | --- | --- | --- |
| Duplicate product/quantity lines | Update only the line selected by `invoice_item_id` | Target line price changed; the other line and stock stayed unchanged | PASS |
| Foreign or duplicate item ID | Validation error with no mutation | Rejected before persistence | PASS |
| Legacy request without item ID | Fallback only for exactly one candidate | Unique candidate passes; ambiguous duplicate lines are rejected | PASS |
| Legacy sold Serial/IMEI | Edit route provides canonical serials and price-only update preserves serial/cost/stock | `edit_serials` hydrated; serial status, invoice link, sold cost and stock asserted unchanged | PASS |
| Ambiguous legacy serial | No guessed assignment and no mutation | Vietnamese validation error is returned | PASS |

`InvoiceCommercialOnlyUpdateTest`: 10 passed / 77 assertions. The regression fixture
uses a new serial SKU with equivalent characteristics; no production invoice (including
cancelled invoice 500) was read or changed.

Edit UI now sends `invoice_item_id` with each current line, uses canonical `edit_serials`
before legacy `serials`, and hydrates the seller from `created_by`. The backend rejects a
foreign/duplicate item ID, an item product/quantity/serial mismatch, and ambiguous legacy
line or serial matching before mutation.

Browser smoke on local QA (`127.0.0.1:8082`, completed local invoice 388): the edit form
rendered its seller selector and `CẬP NHẬT HÓA ĐƠN` CTA with persisted lines and a sold
serial. No browser validation alert was used. This local fixture is not production data.

`InvoiceEditRouteTest` had 10 passing tests and one pre-existing local-policy
expectation mismatch: the standard HTML permission request returned 403 while
the legacy test expects a redirect. The route permission middleware is unchanged
from the base SHA; this is not caused by the commercial-update path.

Final lint, formatter, diff-check and PR CI evidence are recorded in the PR.

## Manual QA checklist

Run against a disposable/local QA database:

1. Create a completed invoice with serial stock, capture product stock, serial
   status/cost, warranty, invoice items and cashflow.
2. Edit only price/discount/note; verify those commercial values change and all
   captured inventory identity/cost/warranty values remain unchanged.
3. Edit only paid amount; verify one invoice cashflow and one debt timeline,
   without inventory mutation.
4. Change customer and seller; verify one invoice row and preserved creator
   snapshot/history, with customer debt moved by delta only.
5. Change quantity or serial deliberately; verify the replay path is selected.
6. Attempt to edit a cancelled invoice through list, direct edit URL and API;
   verify rejection and no mutation.
7. Trigger an edit validation error; verify inline Vietnamese error and no
   browser alert.

## Data safety

`MIGRATION=NO`. `BACKFILL=NO`. No production database, command, connection or
mutation was used. The hotfix neither deletes nor merges records and does not
change checkout, purchase, return, invoice cancellation or debt-calculation
contracts beyond the scoped invoice commercial delta.

## Rollback

Rollback is a normal code revert of the hotfix commit/PR from
`production-customer-group`. No migration or backfill rollback is required. Do
not alter existing invoice, inventory, serial, warranty, cashflow or debt data
as part of rollback.
