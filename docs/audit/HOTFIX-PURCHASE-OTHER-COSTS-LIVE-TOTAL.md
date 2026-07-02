# HOTFIX — Purchase other costs live total and allocation

## Phạm vi

- Module: Nhập hàng
- Màn hình: `/purchases/create`
- Nghiệp vụ: Chi phí nhập khác, Tổng cần trả NCC, Công nợ NCC, Giá vốn nhập phân bổ, CashFlow chi tiền NCC, Stock movement nhập hàng.
- Rủi ro: Có nguy cơ tính sai giá vốn hoặc công nợ NCC nếu chi phí nhập khác không được gửi đúng hoặc định dạng tiền sai.

## Source đã kiểm tra

- Frontend: `resources/js/Pages/Purchases/Create.vue`
- Backend: `app/Http/Controllers/PurchaseController.php`
- Model: `app/Models/Purchase.php`
- Migration: `database/migrations/2026_03_21_040000_add_other_costs_to_purchases_table.php`
- Service: `app/Services/MovingAvgCostingService.php`
- Test: `tests/Feature/Purchase/PurchaseOtherCostsTest.php`
- Commit: To be updated in the final response

## Hiện trạng trước sửa

- UI có mục Chi phí nhập khác.
- Dòng đang nhập chưa được tính vào tổng cho tới khi bấm `+`.
- User dễ hiểu nhầm đã nhập chi phí nhưng phiếu vẫn chưa cộng.
- Backend đã có logic cộng `other_costs` vào `pay_amount` và phân bổ vào giá vốn, nhưng validation chưa rõ.

## Root cause

- `totalOtherCosts` chỉ tính `otherCosts`, không tính pending row `newCostName/newCostAmount`.
- Payload chỉ gửi `otherCosts`, nếu user chưa bấm `+` thì mất chi phí.
- Backend chưa validate/normalize `other_costs` rõ ràng.

## Thay đổi đã làm

- Thêm `normalizedOtherCosts`.
- Thêm pending cost preview.
- `totalOtherCosts` tính cả pending hợp lệ.
- `totalPayment` cộng chi phí nhập khác live.
- Payload save dùng `normalizedOtherCosts`.
- Draft lưu chi phí nhập khác an toàn.
- Backend validate và normalize `other_costs`.
- Giữ nguyên phân bổ phí nhập vào `unit_cost_allocated`.

## Có ảnh hưởng dữ liệu đang có không?

- Không sửa dữ liệu cũ.
- Không migration.
- Không backfill.
- Không update dữ liệu production.
- Không xóa dữ liệu.
- Không recalculate tồn kho/giá vốn/công nợ.

## Ảnh hưởng nghiệp vụ sau hotfix

- Phiếu nhập mới có chi phí nhập khác sẽ cộng vào:
  - Cần trả NCC.
  - Công nợ NCC.
  - Giá vốn phân bổ.
  - Stock movement unit cost.
  - Serial cost nếu có.

## Tests đã chạy

- Command:
  ```bash
  php artisan test tests/Feature/Purchase
  php artisan test tests/Feature/Purchases
  npm run build
  ```
- Result: All tests passed successfully. The Vue frontend compiled successfully without errors.

## Manual QA

- Cộng phí live: Khi gõ tên và số tiền ở phần Chi phí nhập khác, tổng chi phí và tổng cần trả NCC được cập nhật ngay lập tức mà không cần nhấn "+".
- Bấm +: Chuyển dòng chi phí đang gõ thành dòng cố định trong danh sách chi phí, reset ô nhập mới, và giữ nguyên tổng.
- Lưu phiếu: Payload gửi `other_costs` đầy đủ kể cả khi chưa nhấn "+". Dữ liệu DB lưu đúng `other_costs_total`, `other_costs`, `debt_amount`.
- Công nợ: Công nợ NCC được tính chính xác (bao gồm chi phí nhập khác).
- Giá vốn phân bổ: Phí nhập được phân bổ tỷ lệ chính xác vào giá vốn của từng mặt hàng (`unit_cost_allocated`).

## Rủi ro còn lại

- Nếu cần sửa phiếu nhập cũ thiếu chi phí nhập khác thì cần task riêng, backup và xác nhận trước.
- Nếu màn edit phiếu nhập cũng có lỗi tương tự thì cần audit riêng vì sửa edit có thể ảnh hưởng tồn kho/giá vốn cũ.

## Kết luận

- Đạt/chưa đạt: Đạt.
- Có thể deploy chưa: Sẵn sàng deploy.
- Cần làm tiếp: Không.
