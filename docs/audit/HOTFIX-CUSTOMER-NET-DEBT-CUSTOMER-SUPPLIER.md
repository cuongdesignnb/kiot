# HOTFIX — Customer net debt for customer-supplier dual role

## Phạm vi

- **Module**: Khách hàng, Nhà cung cấp, Hóa đơn bán hàng, Nhập hàng, Công nợ khách/NCC.
- **Màn hình**: `/customers` (Danh sách khách hàng, cột Nợ hiện tại, tổng đầu trang Tổng nợ phải thu, tab Công nợ).
- **Nghiệp vụ**:
  - Đối tượng dual role (kiêm cả Khách hàng & Nhà cung cấp) có Nợ hiện tại hiển thị theo công nợ ròng (net debt):
    `net_debt_amount = debt_amount - supplier_debt_amount`
  - Bán hàng chưa thu: cộng net debt.
  - Khách trả tiền / thu nợ: trừ net debt.
  - Nhập hàng chưa trả NCC: trừ net debt.
  - Trả tiền nhập hàng: cộng net debt.
  - Trả hàng nhập: cộng net debt.
  - Net dương: khách nợ cửa hàng (màu đỏ).
  - Net âm: cửa hàng nợ lại đối tác (màu xanh, hiển thị nhãn "Mình nợ lại").
- **Rủi ro**: Khác biệt logic cũ/mới, sai lệch số liệu summary.

## Source đã kiểm tra

- **CustomerController**: `app/Http/Controllers/CustomerController.php` (sửa index & debtHistory).
- **Customers/Index.vue**: `resources/js/Pages/Customers/Index.vue`.
- **Artisan Command**: `app/Console/Commands/AuditCustomerNetDebt.php`.
- **Tests**: `tests/Feature/Customers/CustomerNetDebtTest.php`.
- **Commit**: `fix(customers): show net debt for customer supplier partners`.

## Hiện trạng trước sửa

- Summary và cột Nợ hiện tại hiển thị theo legacy `debt_amount` (chưa tính trừ đi nợ NCC `supplier_debt_amount`).
- Tab Công nợ không đồng bộ balance và Giá trị (Value) cột biến động do dùng số tuyệt đối.
- Chưa có command audit và cảnh báo đối soát.

## Quy tắc nghiệp vụ xác nhận

- Nợ hiện tại = `debt_amount - supplier_debt_amount`.
- Số dương: Khách nợ mình.
- Số âm: Mình nợ khách/NCC.
- Tổng nợ phải thu chỉ cộng dồn các khách có net dương.

## Audit read-only khách Trọng Hùng (ID: 81)

Dựa trên kết quả chạy tinker audit thực tế:
- **Customer ID**: 81
- **Code**: `NCC177624592772`
- **Name**: `Trọng Hùng`
- **Is Customer / Is Supplier**: True / True (Dual role)
- **Current debt_amount (materialized)**: `2.050.000` (Khớp tổng hóa đơn bán chưa thu: HD177682125064 & HD177682184116).
- **Current supplier_debt_amount (materialized)**: `844.643.150`.
- **Current net**: `2.050.000 - 844.643.150 = -842.593.150` (Mình nợ lại 842.593.150đ).
- **Expected supplier_debt_amount (from documents)**: `422.390.150`.
- **Expected net (from documents)**: `2.050.000 - 422.390.150 = -420.340.150`.
- **Delta**: `422.253.000`
- **Có cần recalculate không**: Có, nếu muốn đồng bộ cột materialized với ledger/transactions, nhưng Phase 1 chỉ thay đổi query/UI để đảm bảo an toàn. Cảnh báo lệch mismatch được trả về qua tab Công nợ.

## Thay đổi đã làm Phase 1

1. **Backend - CustomerController**:
   - Thêm các thuộc tính `net_debt_amount`, `net_debt_direction`, `net_debt_label` vào collection khách hàng trả về.
   - Sửa query aggregate summary: `total_debt` chỉ cộng net dương, bổ sung `total_store_owes` cộng net âm tuyệt đối.
   - Sắp xếp và tính running balance liên tục lịch sử theo `customer_effect` trong `debtHistory()`.
   - Trả về `reconcile` metadata cảnh báo lệch.
2. **Frontend - Vue Component**:
   - Hiển thị cột Nợ hiện tại theo net debt kèm tooltip.
   - Đổi màu cột Nợ hiện tại (dương màu đỏ, âm màu xanh kèm tag "Mình nợ lại").
   - Hiển thị `Tổng mình phải trả` ở Summary Bar và Footer nếu có.
   - Dùng `customer_effect` trong tab Công nợ để hiển thị đúng dấu biến động `+` / `-`.
   - Thêm cảnh báo mismatch trong tab Công nợ.
3. **CLI - Command**:
   - Viết Artisan command `customers:audit-net-debt` hỗ trợ dry-run audit toàn bộ database hoặc khách hàng cụ thể.

## Có ảnh hưởng dữ liệu đang có không?

- **Phase 1**: Hoàn toàn không. Chỉ sửa API trả về, query, và render giao diện.
- **Migration**: Không.
- **Backfill**: Không.
- **Update dữ liệu cũ**: Không.
- **Delete dữ liệu**: Không.
- **Recalculate**: Không tự động apply.

## Tests đã chạy

- **Command**:
  ```bash
  php artisan test tests/Feature/Customers/CustomerNetDebtTest.php
  ```
- **Result**:
  ```
  PASS  Tests\Feature\Customers\CustomerNetDebtTest
  ✓ customer only buys and does not pay
  ✓ dual role partner with purchase unpaid
  ✓ purchase exceeds sale store owes partner
  ✓ partial paid purchase
  ✓ net debt summary totals
  ✓ filter has debt
  ✓ normal customer unaffected

  Tests:    7 passed (28 assertions)
  Duration: 0.89s
  ```

## Rủi ro còn lại

- Một số dữ liệu cũ có thể có lệch (mismatch) giữa materialized và ledger do các transaction cũ chưa backfill đầy đủ. Frontend đã xử lý hiển thị Warning box an toàn để cảnh báo người dùng.

## Kết luận

- **Đạt/chưa đạt**: Đạt.
- **Có thể deploy Phase 1 chưa**: Có.
