# Hotfix POS đổi hàng giá 0đ

## Phạm vi

Cho phép mọi hàng hóa được chọn trong màn hình POS Đổi hàng có đơn giá `0đ`.
Giá âm vẫn bị từ chối. Không thêm migration, không backfill và không sửa dữ
liệu production.

## Quy tắc nghiệp vụ

- Giá trị hàng đổi được tính theo `số lượng × đơn giá - giảm giá`, vì vậy dòng
  0đ có thành tiền 0đ.
- Hàng 0đ vẫn phải đủ tồn kho; sản phẩm quản lý Serial/IMEI vẫn phải chọn đúng
  Serial còn bán được.
- Hàng 0đ vẫn tạo dòng hàng trên hóa đơn đổi, trừ tồn kho và cập nhật Serial/
  giá vốn như một lần xuất hàng bình thường.
- Hàng 0đ không tạo phiếu thu cho phần hàng đổi. Chênh lệch đổi hàng vẫn được
  tính theo tổng giá trị hàng trả và tổng giá trị hàng đổi; nếu hàng đổi rẻ hơn,
  hệ thống thực hiện khoản trả khách theo quy tắc hiện tại.
- Ghi chú lý do 0đ (nếu client gửi) chỉ được lưu ở dòng hàng đổi để truy vết;
  không bắt buộc và không ảnh hưởng công thức công nợ.

## Thay đổi

- `PosReturnExchangeService` chấp nhận giá 0đ, giữ chặn giá âm, tồn kho,
  Serial/IMEI, giảm giá và transaction rollback.
- `PosController` nhận metadata tùy chọn `is_zero_price` và
  `zero_price_reason` để giữ contract mở rộng; giá 0đ không bị chặn nếu thiếu
  metadata này.
- POS hiển thị trạng thái “Hàng 0đ — vẫn xuất kho khi hoàn tất đổi hàng” khi
  đơn giá dòng bằng 0 và không dùng browser alert để chặn thao tác.

## Bằng chứng kiểm thử

Môi trường: MySQL 8 disposable, schema fresh, không dùng production.

```text
P0/POS return-exchange targeted: PASS
30 tests / 177 assertions
```

Các case bao gồm:

- đổi hàng giá bình thường: thu thêm, trả chênh lệch, không phát sinh công nợ
  âm;
- hàng thường giá 0đ: hóa đơn đổi tổng 0đ, tồn kho giảm, trả lại giá trị hàng
  trả, không tạo CashFlow thu cho hóa đơn đổi;
- Serial/IMEI giá 0đ: Serial hàng trả về `in_stock`, Serial hàng đổi sang
  `sold` và gắn đúng hóa đơn;
- giá âm và giảm giá vượt thành tiền bị từ chối trước mutation;
- lỗi khi tạo hóa đơn đổi rollback toàn bộ phiếu trả, tồn kho, công nợ và
  CashFlow.

## Manual QA checklist

- POS → Trả hàng/Đổi hàng → chọn hàng đổi bất kỳ → nhập đơn giá `0`.
- Kiểm tra thành tiền hàng đổi bằng `0đ` và nút `ĐỔI HÀNG` được phép bấm.
- Với hàng thường: hoàn tất, kiểm tra tồn kho giảm đúng số lượng.
- Với Serial/IMEI: chọn Serial, hoàn tất, kiểm tra Serial được chuyển sang
  `sold` và gắn vào hóa đơn đổi.
- Kiểm tra chênh lệch thu/trả, công nợ khách hàng và CashFlow không bị nhân đôi.
- Tải lại/đóng mở lại chứng từ để xác nhận hóa đơn đổi tổng `0đ` vẫn hiển thị.

## An toàn và rollback

- Không có migration/backfill và không có thao tác production trong thay đổi
  này.
- Rollback code bằng cách revert commit/PR sẽ khôi phục việc chặn giá 0đ; các
  chứng từ đã tạo vẫn là dữ liệu nghiệp vụ hợp lệ, không tự động xóa.
