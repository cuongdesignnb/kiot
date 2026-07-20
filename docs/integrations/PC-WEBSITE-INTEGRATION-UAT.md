# Website PC Integration V1 — UAT Checklist

## Chuẩn bị

- [ ] Đã deploy migration trên môi trường UAT và xác nhận ba migration `2026_07_19_100000` đến `100200` ở trạng thái Ran.
- [ ] `PC_INTEGRATION_ENABLED=false` trả `503 INTEGRATION_DISABLED` cho cả 5 endpoint.
- [ ] Cấu hình client, secret và branch UAT; branch tồn tại, chưa soft-delete.
- [ ] Bật feature chỉ trên UAT; không bật production schedule cho reservation expiry.
- [ ] Website/client UAT tính HMAC trên đúng raw body và path không có domain/query.

## Bảo mật và hợp đồng API

- [ ] Client sai, signature sai, thiếu header trả lỗi JSON `401` và không trả stack trace.
- [ ] Timestamp quá cũ và quá xa trong tương lai bị từ chối.
- [ ] Hai request đồng thời cùng nonce cho đúng một request đi tiếp; request còn lại trả `409 REPLAYED_NONCE`.
- [ ] Vượt rate limit trả `429 RATE_LIMITED` với thời gian retry.
- [ ] Kiểm tra log không chứa secret, signature, raw header hoặc raw payload; không có phone/email rõ nếu log text có liên quan.
- [ ] Mọi lỗi có schema `{success:false,error:{code,message,details}}`.

## Product sync

- [ ] GET product mặc định chỉ trả active, sell-directly, chưa xóa, không phải service.
- [ ] `limit` mặc định 50, giới hạn 100; đi hết nhiều cursor không thiếu/trùng `(updated_at,id)`.
- [ ] Filter SKU giữ nguyên hoa/thường; SKU sai case không tự map.
- [ ] `updated_since` trả cả inactive và tombstone đã xóa mềm.
- [ ] `updated_since` đổi đúng timezone ứng dụng; record đúng biên được trả và record trước biên một giây bị loại.
- [ ] `include_inactive=true` trả product inactive với `sync_status=inactive`.
- [ ] Soft-deleted product trả `sync_status=deleted`.
- [ ] Product service bị loại khỏi list/detail kể cả với `include_inactive` hoặc `updated_since`.
- [ ] Active reservation làm giảm `available_quantity`; released/consumed/expired không làm giảm.
- [ ] Product serial dùng đúng `products.stock_quantity` hiện hành.
- [ ] Response không có `cost_price`, `last_purchase_price`, `inventory_total_cost` hoặc supplier data.

## Nhập Order

- [ ] Đơn hợp lệ tạo đúng một Order `confirmed`, đúng branch, `amount_paid=0`, `created_at=ordered_at`.
- [ ] OrderItem giữ đúng giá website, `serial_ids=null`; `bundle_ref` không được thêm vào order_items.
- [ ] Mỗi OrderItem có đúng một active reservation với TTL cấu hình.
- [ ] Không tạo Invoice, CashFlow, StockMovement, debt hoặc thay đổi stock/serial khi import.
- [ ] Phone Việt Nam được chuẩn hóa; reuse customer theo phone, sau đó email lowercase.
- [ ] Customer có vai trò supplier được giữ vai trò supplier và được bổ sung customer role nếu cần.
- [ ] Customer inactive, merged hoặc mapping mơ hồ bị fail closed.
- [ ] Unknown/duplicate SKU, product inactive, không sell-directly và service đều bị từ chối đúng mã.
- [ ] Thiếu tồn khả dụng trả `INSUFFICIENT_AVAILABLE_STOCK` và không để Order/reservation/customer mồ côi.
- [ ] Sai line total, subtotal, shipping fee hai vùng hoặc grand total trên `0.01` trả `ORDER_TOTAL_MISMATCH`.
- [ ] Hai sender process cùng đặt đơn vị tồn cuối: đúng một thành công và một thiếu tồn.

## Idempotency và audit

- [ ] Retry cùng external order/event/key/raw body trả `200 duplicate=true`; không thêm Order/customer/reservation.
- [ ] Cùng external order nhưng payload khác trả `EXTERNAL_ORDER_CONFLICT` và không update Order cũ.
- [ ] Tái sử dụng event ID hoặc Idempotency-Key với nội dung khác trả `IDEMPOTENCY_KEY_CONFLICT`.
- [ ] `integration_events` có payload hash, trạng thái, attempt/error timestamps và không có HMAC/secret.
- [ ] ActivityLog có event tạo/duplicate/conflict và vòng đời reservation tương ứng.

## Status, cancel và expiry

- [ ] Status trả đúng Order, Invoice code, reservation và serial allocation; không lộ customer/payment payload.
- [ ] Cancel external Order hợp lệ chuyển cancelled và active reservation sang released trong một transaction.
- [ ] Retry cùng cancel event trả `duplicate=true`; cancel event mới trên Order đã hủy trả `ORDER_ALREADY_CANCELLED`.
- [ ] Không cancel được completed/invoiced/ended/returned hoặc Order không thuộc `pc_website`.
- [ ] Cancel từ giao diện KIOT cũng release reservation, không tăng stock và không tạo StockMovement.
- [ ] Command expiry chuyển đúng active row quá hạn sang expired, idempotent, không hủy Order.

## Chuyển Order sang Invoice

- [ ] External Order thường chuyển thành Invoice đúng branch; stock và StockMovement chỉ ghi một lần.
- [ ] Reservation của chính Order được loại khỏi phần tồn đã giữ khi kiểm tra process.
- [ ] Order serial thiếu serial rollback; Invoice không tồn tại, stock/serial không đổi, reservation còn active.
- [ ] Chọn đủ serial khả dụng ở POS chuyển Invoice thành công và serial thành sold.
- [ ] Thành công chuyển reservation active sang consumed trong cùng transaction.
- [ ] Inject failure sau consume làm rollback Invoice, stock, StockMovement và giữ reservation active.
- [ ] Không double CashFlow; payment metadata website không sinh dòng tiền.
- [ ] External Order bị chặn khi chuyển Invoice trong kỳ khóa sổ; Invoice/stock/reservation rollback toàn bộ.
- [ ] Order nội bộ hiện có vẫn đi qua costing, debt, serial và stock regression bình thường.

## Rollback và sign-off

- [ ] Trên database UAT sạch: migrate up → rollback 3 migration V1 → migrate up thành công.
- [ ] Focused PcIntegration, Order, Invoice, Stock, Serial, Debt và full suite đều pass; không có skipped test chưa giải thích.
- [ ] Pint, PHP lint, frontend build, route review và secret scan pass.
- [ ] Đã thử tắt feature sau UAT và xác nhận toàn bộ endpoint lại trả `503`.
- [ ] Product owner, backend reviewer và vận hành ký xác nhận riêng trước merge/deploy.
- [ ] Draft PR chưa merge; `SAFE_TO_MERGE=NO`, `SAFE_TO_DEPLOY=NO` cho tới sign-off.
