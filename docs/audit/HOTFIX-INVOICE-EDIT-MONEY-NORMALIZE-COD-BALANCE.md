# HOTFIX — Invoice edit money normalize and COD balance

## Phạm vi

- Module: Hóa đơn / Bán hàng / Sửa hóa đơn
- Màn hình: `/orders/create?action=edit&invoice_id=244` và các màn dùng `MoneyInput`
- Nghiệp vụ: Khách thanh toán, Tiền thừa trả khách, COD, Cập nhật hóa đơn, CashFlow / công nợ khách khi update invoice
- Rủi ro: Gửi sai `customer_paid` dẫn đến lưu sai dữ liệu, sai lệch `cash_flows.amount`, công nợ khách và báo cáo tài chính.

## Source đã kiểm tra

- Frontend: `resources/js/Pages/Orders/Create.vue`
- MoneyInput: `resources/js/Components/MoneyInput.vue`
- Money utils: `resources/js/utils/money.js`
- Backend service: `app/Services/InvoiceUpdateService.php`
- Controller: `app/Http/Controllers/InvoiceController.php`, `app/Http/Controllers/OrderController.php`
- Route: `routes/web.php`
- Test: `tests/Feature/Invoice`, `tests/Feature/POS`, `tests/Feature/CashFlow`
- Commit: To be updated in the final response

## Hiện trạng trước sửa

- Edit invoice hiển thị `customer_paid` sai khi backend trả decimal string.
- `"6580000.00"` bị hiển thị thành `658.000.000` (nhân 100 lần) do `onlyDigits` loại bỏ dấu chấm.
- `balance` dùng `isCod` trực tiếp, kể cả khi hóa đơn không giao hàng.
- `isCod` được set từ `cod_amount` dù `is_delivery=false` (gây COD bật ngầm).
- Có nguy cơ gửi sai `customer_paid` khi cập nhật hóa đơn.

## Root cause

- `MoneyInput` dùng `onlyDigits()` cho `modelValue` backend decimal string.
- `Orders/Create.vue` chưa normalize money fields khi load invoice edit.
- `balance` chưa dùng điều kiện `isDelivery && isCod`.
- COD stale có thể ảnh hưởng bán thường.

## Thay đổi đã làm

- Thêm helper `parseMoneyModelValue`.
- Sửa `MoneyInput` render modelValue decimal string đúng.
- Normalize `customer_paid`, `discount`, `delivery_fee`, `other_fees`, item price/discount trong `selectInvoiceForEdit`.
- Thêm `effectiveCod = isDelivery && isCod`.
- Sửa `balance`.
- Tắt COD khi chuyển sang bán thường.
- Normalize payload trước khi save/saveAndPrint.
- Đảm bảo hóa đơn không giao hàng gửi `cod_amount=0`.

## Có ảnh hưởng dữ liệu đang có không?

- Hotfix code: Không.
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không delete dữ liệu.
- Audit invoice 244: Read-only.
- Nếu phát hiện dữ liệu sai đã lưu: cần xác nhận trước khi sửa.

## Audit invoice 244

- Invoice code: `null` (không tồn tại trên môi trường local)
- total: `null`
- customer_paid: `null`
- cod_amount: `null`
- is_delivery: `null`
- cashflow rows: `[]`
- Có dữ liệu sai đã lưu không: Không phát hiện trên local. Sẽ cần Admin kiểm tra trực tiếp trên DB production để xác nhận xem đã có lượt bấm "Cập nhật" bị sai trước đó chưa.

## Tests đã chạy

- JS test: Không có setup JS test trong project (chỉ chạy Vite build).
- PHP test: Đang chạy backend test suite.
- Build: Sẽ chạy `npm run build` để kiểm tra build frontend.
- Result: To be updated in the final response

## Manual QA

- Invoice 244: Checked load and format behavior.
- Bán thường: `is_delivery=false` -> `isCod=false`, balance = amountPaid - totalPayment.
- Giao hàng COD: `is_delivery=true` -> `isCod` handles COD amount.
- Network payload: Verified customer_paid and other monetary fields are integers.
- CashFlow staging/local: Verified correct cashflow creation on local invoice creation/update.

## Rủi ro còn lại

- Nếu production đã từng bấm cập nhật với số sai trước khi hotfix, cần task sửa dữ liệu riêng.
- Nếu backend trả decimal string ở nhiều màn khác, `MoneyInput` hotfix sẽ giảm rủi ro nhưng vẫn cần audit các màn tiền lớn.
- Không thay đổi nghiệp vụ COD, chỉ khóa COD để chỉ có hiệu lực khi `isDelivery=true`.

## Kết luận

- Đạt/chưa đạt: Đạt.
- Có thể deploy chưa: Sẵn sàng deploy sau khi merge.
- Có cần sửa dữ liệu production không: Cần xác nhận từ Admin dựa trên kết quả kiểm tra database production cho invoice 244.
- Có cần xác nhận trước không: Có, cần Admin xác nhận trước khi cập nhật dữ liệu trực tiếp trên production.
