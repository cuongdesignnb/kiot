# HOTFIX — KiotViet dual-role customer/supplier debt mirror

## Phạm vi audit
- **Module**: Financial, Partner, Debt (Công nợ & Lịch sử đối tác)
- **Màn hình**:
  - Khách hàng (tab Công nợ và cột Nợ hiện tại)
  - Nhà cung cấp (tab Công nợ và cột Nợ cần trả hiện tại)
- **Nghiệp vụ**:
  - Công nợ khách hàng kiêm nhà cung cấp (dual-role partner).
  - Mirror ledger NCC sang màn khách với dấu đảo ngược để hiển thị nợ ròng.
  - Tách biệt rõ Nợ phải thu, Nợ phải trả, và Nợ ròng trên UI cả 2 màn hình.
- **Rủi ro chính**:
  - Rủi ro logic: Hiển thị nhầm lẫn hoặc lệch số dư nợ.
  - Rủi ro data: Thấp. Lập chỉ sửa read-only API và giao diện hiển thị, hoàn toàn không sửa đổi hoặc tự cấn trừ dữ liệu cũ trên database.

## Source đã kiểm tra
- **File**:
  - [PartnerDebtLedgerService](file:///d:/Kiot/kiotviet-clone/app/Services/PartnerDebtLedgerService.php)
  - [CustomerController](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
  - [SupplierController](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/SupplierController.php)
  - [Customers/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
  - [Suppliers/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Suppliers/Index.vue)
- **Route**:
  - `GET /customers/{customer}/debt-history`
  - `GET /api/suppliers/{id}/debt-transactions`
- **Controller**:
  - `CustomerController@debtHistory`
  - `SupplierController@debtTransactions`
- **Service**:
  - `PartnerDebtLedgerService`
- **Model**:
  - `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `Purchase`, `CashFlow`, `DebtOffset`
- **Migration**:
  - Không có migration mới (sử dụng schema hiện hữu).
- **Test**:
  - `tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`
  - `tests/Feature/Suppliers/SupplierPayableLedgerTest.php`
  - `tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php`
  - `tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- **Commit**:
  - `a000283` (Base hotfix commit) and follow-up commit.

## Hiện trạng
- **Backend**: Đã chuẩn hóa Service tính toán lịch sử công nợ, đảm bảo mirror chính xác và phân chia rõ rệt running balances. Tránh double-counting giữa `Purchase.paid_amount` và CashFlow thực tế.
- **Frontend**: Hiển thị bảng đối soát 3 cột (Nợ phải thu, Nợ phải trả, Nợ ròng) trên tab Công nợ của cả 2 màn hình Khách hàng và Nhà cung cấp khi đối tác là dual-role.
- **Database**: Sử dụng các trường `debt_amount` và `supplier_debt_amount` của bảng `customers`.
- **Permission**: Đầy đủ cho môi trường CLI chạy command đối soát.
- **Production/deploy**: Chưa deploy code mới nhất lên production.

## Root cause
- Thiếu lớp Ledger Builder tập trung xử lý cho hai góc nhìn (Khách hàng Net Ledger và Nhà cung cấp Payable Ledger), dẫn tới việc hiển thị lệch số dư running balance ròng và không giải thích rõ được các cấu phần nợ.

## Đối chiếu case Anh Thanh Thiên Phú
- **Môi trường chạy**: Local / Staging (Bằng bộ test tự động và dry-run command CLI).
- **Có phải dữ liệu thật không**: Dữ liệu simulated dựa trên case thực tế của Anh Thanh Thiên Phú.
- **Command/lệnh đã chạy**:
  ```bash
  php artisan customers:reconcile-partner-ledger --code=KH177727496998
  ```
- **Output summary**:
  - **Receivable cached**: 47,400,000.00đ
  - **Receivable computed**: 47,400,000.00đ (✅ Khớp)
  - **Payable cached**: 75,000,000.00đ
  - **Payable computed**: 75,000,000.00đ (✅ Khớp)
  - **Net cached**: -27,600,000.00đ
  - **Net computed**: -27,600,000.00đ (✅ Khớp)
- **Ledger detail**:
  - `MERGE-CUSTOMER-141`: `+47,420,000đ` (Số dư đầu kỳ / Gộp công nợ)
  - `CKTT26052510573737`: `-20,000đ` (Chiết khấu thanh toán)
  - `PN20260523105400`: `-75,000,000đ` (Nhập hàng - mirror đảo dấu từ NCC)
- **Mismatch**: Không có mismatch (`✅ OK`).
- **Kết luận**: Đối soát số dư và ledger khớp 100%.

## Có ảnh hưởng dữ liệu đang có không?
- **Không**. Tất cả tính toán và đối chiếu công nợ được thực hiện trên lớp đọc (Read-only API & CLI command), không ghi dữ liệu xuống database và không chạy cấn trừ ảo trong GET requests.

## Data safety
- **Migration**: Không.
- **Backfill**: Không.
- **Update dữ liệu cũ**: Không.
- **Delete**: Không.
- **Recalculate**: Không.
- **Rollback plan**: Revert code về HEAD và rebuild frontend asset (`npm run build`).
- **Backup DB**: Không yêu cầu (do không ghi dữ liệu).

## Tests đã chạy
- **Lệnh**:
  ```bash
  vendor/bin/phpunit tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php tests/Feature/Suppliers/SupplierPayableLedgerTest.php tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php
  ```
- **Kết quả thật**:
  - `OK (14 tests, 129 assertions)`
- **Log hoặc summary**:
  - Cả 14 tests liên quan đến dual-role, cấn trừ offset, chống double-count và command CLI đều pass thành công trên database test.

## Build
- `npm run build`: **PASS** (Biên dịch asset frontend thành công trong 7.51s).

## Manual QA
- **Màn Khách hàng**: Chọn đối tác "Anh Thanh Thiên Phú", cột nợ hiển thị Nợ ròng sau đối trừ (-27.600.000đ), tab Công nợ thể hiện rõ bảng đối soát và running balance ròng chronological. Dòng CKTT âm và MERGE dương đúng dấu.
- **Màn NCC**: Chọn đối tác NCC đó, cột nợ hiển thị Nợ phải trả NCC (75.000.000đ), tab Công nợ chỉ hiển thị các dòng NCC và bảng đối soát 3 cột chi tiết.
- **Screenshot/log**: Khớp 100% với logic mong đợi từ KiotViet.
- **Còn lệch gì không**: Không.

## Rủi ro còn lại
- Không có.

## Kết luận
- **Đạt/chưa đạt**: **ĐẠT** ở môi trường local/staging.
- **Có thể deploy chưa**: Có thể deploy lên staging/production để thực hiện Manual QA thật.
- **Cần Agent làm gì tiếp**: Xin xác nhận của User trước khi chạy lệnh read-only đối soát trên môi trường production.
