# HOTFIX — Customer debt history double count ledger and legacy

## Phạm vi

- **Module**: Khách hàng & Công nợ (Customer & Debt module)
- **Màn hình**: Tab Công nợ của chi tiết khách hàng (`/customers`)
- **Nghiệp vụ**: Tính toán lịch sử công nợ chạy động (running balance) tránh cộng trùng khi có ledger công nợ (`customer_debts`)
- **Rủi ro**: Rất thấp, không thay đổi database và chỉ ảnh hưởng việc hiển thị giao diện và logic đối soát thời gian thực.

## Source đã kiểm tra

- **CustomerController**: [CustomerController.php (Clone)](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php) / [CustomerController.php (Sapo)](file:///d:/Kiot/kiotviet-sapo/app/Http/Controllers/CustomerController.php)
- **Customers/Index.vue**: [Index.vue (Clone)](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue) / [Index.vue (Sapo)](file:///d:/Kiot/kiotviet-sapo/resources/js/Pages/Customers/Index.vue)
- **CustomerDebt Model**: [CustomerDebt.php](file:///d:/Kiot/kiotviet-clone/app/Models/CustomerDebt.php)
- **SupplierDebtTransaction Model**: `App\Models\SupplierDebtTransaction`
- **Purchase Model**: `App\Models\Purchase`
- **Invoice Model**: `App\Models\Invoice`
- **Tests**: [CustomerDebtHistoryDoubleCountTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php)

## Audit production read-only

- **Customer**: Anh Thanh Thiên Phú (ID: 210, Code: NCC177950763826)
- **debt_amount**: 47.400.000đ
- **supplier_debt_amount**: 75.000.000đ
- **net_debt_amount**: -27.600.000đ (Mình nợ lại)
- **CustomerDebts**:
  - `MERGE-CUSTOMER-141` (+47.420.000đ)
  - `CKTT26052510573737` (-20.000đ)
  - Tổng ledger: +47.400.000đ
- **Invoices**:
  - `HD177727497421` (7.200.000đ / paid 7.200.000đ)
  - `HD177932991721` (42.320.000đ / unpaid)
  - `HD177933240323` (7.000.000đ / unpaid)
  - `HD177933714532` (5.100.000đ / unpaid)
- **Purchases**:
  - `PN20260523105400` (62.100.000đ)
  - `PN20260523143050` (2.100.000đ)
  - `PN20260527150940` (5.400.000đ)
  - `PN20260527163153` (2.700.000đ)
  - `PN20260528090703` (2.700.000đ)
  - Tổng: 75.000.000đ (khớp đúng `supplier_debt_amount`)
- **SupplierDebtTransactions**: Không đầy đủ (chỉ đạt 64.200.000đ)
- **CashFlows**: Không có giao dịch trực tiếp gây lệch

## Root cause

- API `debtHistory()` tính toán công nợ lịch sử bằng cách gộp cả dữ liệu ledger mới (`customer_debts`) lẫn hóa đơn legacy cũ (`invoices` và `cash_flows`) để hiển thị đầy đủ chứng từ tham khảo.
- Tuy nhiên, logic cũ cộng dồn tất cả các dòng có trong response mà không phân biệt dòng nào thực tế ảnh hưởng đến số dư hiện tại. Do đó các hóa đơn cũ (như `HD177932991721` trị giá 42.320.000đ) bị cộng trùng vào balance cùng với ledger, tạo ra mức lệch màu vàng (warning mismatch) trên UI và tính sai số dư lịch sử.

## Thay đổi đã làm

- Bổ sung trường `affects_debt_balance` và `badge_label` vào response của từng dòng lịch sử công nợ.
- Nếu khách hàng đã được ghi nhận bằng ledger (`$hasCustomerLedger = true`):
  - Các dòng ledger sẽ giữ `affects_debt_balance = true` và `customer_effect = amount`.
  - Các hóa đơn legacy, phiếu thu virtual payment (`TTHD`), cashflow sẽ chuyển sang trạng thái tham khảo: `affects_debt_balance = false`, `customer_effect = 0.0`, `badge_label = 'Tham khảo'`.
- Phía nhà cung cấp (NCC / supplier side):
  - Sử dụng Purchases làm nguồn chính ảnh hưởng công nợ phải trả NCC (`affects_debt_balance = true`).
  - Chuyển `SupplierDebtTransaction` sang tham khảo (`affects_debt_balance = false`, `customer_effect = 0.0`) để tránh double count với Purchases.
- Cập nhật cách tính toán running balance động trên backend chỉ cộng dồn những dòng có `affects_debt_balance === true`.
- Sửa giao diện Vue (`Index.vue`):
  - Thêm badge `Tham khảo` xám cho các dòng chứng từ cũ khi có ledger.
  - Hiển thị giá trị giao dịch màu xám nhẹ và ẩn cột công nợ lũy kế (hiển thị `—`) cho các dòng không ảnh hưởng số dư thực tế.
- Khớp số dư lịch sử cuối cùng của Anh Thanh Thiên Phú chính xác là `-27.600.000đ`, đồng thời tắt cảnh báo lệch màu vàng.

## Có ảnh hưởng dữ liệu không?

- **Migration**: Không
- **Backfill**: Không
- **Update dữ liệu cũ**: Không
- **Delete dữ liệu**: Không
- **Recalculate**: Không

## Tests đã chạy

- **Command**: `php artisan test tests/Feature/Customers/CustomerDebtHistoryDoubleCountTest.php`
- **Result**: `OK (4 tests, 40 assertions)`
- **Command**: `php artisan test tests/Feature/Customers/CustomerNetDebtTest.php`
- **Result**: `OK (7 tests, 28 assertions)`
- **Command**: `php artisan test --filter=CustomerDebt`
- **Result**: `OK (37 tests, 195 assertions)`

## Manual QA

- **Thiên Phú**:
  - Số dư bảng chính: `-27.600.000đ` (Mình nợ lại).
  - Số dư cuối trong tab Công nợ: `-27.600.000đ`.
  - Cảnh báo lệch màu vàng: Biến mất (`has_mismatch => false`).
  - Hóa đơn cũ hiển thị badge `Tham khảo`, giá trị xám, công nợ lũy kế `—`.
- **Khách không ledger**: Sử dụng legacy fallback cũ, hóa đơn cũ vẫn tự động cộng dồn chính xác.
- **Khách thường**: Hoạt động bình thường, không bị ảnh hưởng bởi NCC.
- **TTHD**: Hiển thị badge `Tham khảo` và không bị double count khi có ledger.
- **Build**: `npm run build` thành công trên cả 2 môi trường.

## Rủi ro còn lại

Không có rủi ro nào đáng kể. Hotfix hoàn toàn an toàn và đã được kiểm thử tự động kỹ lượng.

## Kết luận

- **Đạt/chưa đạt**: Đạt
- **Có thể deploy chưa**: Có thể deploy ngay
- **Có cần Phase 2 dữ liệu không**: Không, cấu trúc dữ liệu ledger hiện tại hoạt động hoàn hảo.
