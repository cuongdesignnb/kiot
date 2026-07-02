# HOTFIX — Restore Supplier dual-role partner timeline

## Phạm vi audit

- Module: Khách hàng, Nhà cung cấp, công nợ đối tác dual-role.
- Màn hình: `/suppliers`, tab `Công nợ` của nhà cung cấp kiêm khách hàng.
- Nghiệp vụ: payable NCC thuần, receivable khách hàng, timeline vị thế ròng đối tác.
- Rủi ro chính: đưa `HD/TTHD` vào ledger phải trả NCC thuần sẽ làm sai cột `Nợ cần trả hiện tại`, export, và thanh toán NCC.

## Source đã kiểm tra

- File: `app/Http/Controllers/SupplierController.php`, `app/Services/PartnerDebtLedgerService.php`, `resources/js/Pages/Suppliers/Index.vue`.
- Route: `GET /api/suppliers/{id}/debt-transactions`.
- Controller: `SupplierController::debtTransactions()`.
- Service: `PartnerDebtLedgerService::buildSupplierPayableLedger()`, `buildCustomerNetLedger()`, `buildSupplierDualRolePartnerTimeline()`.
- Model: `Customer`, `Invoice`, `Purchase`, `CashFlow`, `CustomerDebt`, `SupplierDebtTransaction`, `DebtOffset`, `OrderReturn`, `PurchaseReturn`.
- Test: `SupplierDualRolePartnerTimelineTest`, `SupplierPayableLedgerTest`, supplier hardening, customer dual-role/reconcile suites.
- Commit: compared `7fb035c329df10a996348faaeb96dd6665ee525f` and parent commit.

## Commit gây mất logic

- Commit: `7fb035c329df10a996348faaeb96dd6665ee525f` (`fix(debt): align customer and supplier debt tabs with Kiot standard`).
- Block bị remove: `Cross-role: Invoices from customer side (dual-role only)` in `SupplierController::debtTransactions()`.
- Tác động: tab NCC dual-role không còn thấy `HD...`, `TTHD...`, standalone customer receipt trong supplier tab.

## Hiện trạng trước hotfix

- Supplier payable ledger: đã đúng hướng, chỉ chứa supplier-side `PN`, `PCPN/TTNH`, trả hàng nhập, adjustment/discount/CB.
- Partner timeline: có sẵn ở customer net ledger, nhưng supplier tab chưa opt-in dùng.
- Frontend: supplier tab luôn gọi endpoint mặc định, nên chỉ nhận `supplier_payable`.
- Sai/lệch: dual-role supplier tab thiếu customer-side entries, dù cột chính NCC vẫn đang đúng gross payable.

## Root cause

- Sau `7fb035c`, logic customer-side được loại khỏi supplier payable để tránh trộn nghiệp vụ.
- Cần thêm một lớp view riêng cho tab NCC dual-role thay vì nhét invoice vào `buildSupplierPayableLedger()`.

## Phương án

- Không revert `7fb035c`.
- Giữ `buildSupplierPayableLedger()` là source of truth thuần NCC.
- Thêm `PartnerDebtLedgerService::buildSupplierDualRolePartnerTimeline()` để compose từ `buildCustomerNetLedger()`.
- `SupplierController::debtTransactions()` hỗ trợ `view=partner` cho dual-role, mặc định vẫn `supplier_payable`.
- Frontend supplier dual-role gọi `view=partner`.
- Cột chính danh sách NCC giữ gross payable từ `supplier_debt_amount`.

## Output contract

- Summary partner mode:
  - `display_mode=partner_net_timeline`
  - `customer_receivable_balance`
  - `supplier_payable_balance`
  - `partner_net_position`
  - `is_supplier_tab_partner_timeline=true`
- Entry partner mode:
  - `domain`: `customer`, `supplier`, hoặc `offset`
  - `source_ledger`: `customer_receivable`, `supplier_payable`, hoặc `debt_offset`
  - `partner_effect`
  - `partner_running_balance`
  - `affects_partner_net`
  - `affects_customer_receivable`
  - `affects_supplier_payable`

## Có ảnh hưởng dữ liệu đang có không?

- Không.
- Migration: Không.
- Backfill: Không.
- Update dữ liệu cũ: Không.
- Delete: Không.
- Recalculate: Không.
- Tạo phiếu CB/HCB: Không.

## Tests đã chạy

- `php artisan test tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/SupplierPayableLedgerTest.php`: PASS.
- `php artisan test tests/Feature/Suppliers/HOTFIXFollowUpSupplierLedgerHardeningTest.php`: PASS.
- `php artisan test tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`: PASS.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS.
- `php artisan test tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php`: PASS.
- `php artisan test --filter=Supplier`: PASS.
- `php artisan test --filter=DebtOffset`: PASS.
- `php artisan test --filter=CashFlow`: PASS.
- `php artisan test --filter=Purchase`: PASS.
- `php artisan test --filter=CustomerDebt`: PASS.
- Note: PHP CLI prints startup warnings for missing optional `oci8_*`, `pdo_oci`, and `pdo_firebird` extensions in local environment. The commands exited successfully.

## Build

- `npm run build`: PASS.

## Manual QA

- Anh Thanh Thiên Phú: Chưa QA trên staging/production.
- Supplier thường: Chưa QA trên staging/production.
- Screenshot/log: Chưa có.

## Rủi ro còn lại

- Dữ liệu legacy thiếu ledger thật vẫn cần đối soát riêng nếu phát hiện mismatch.
- Hotfix này chỉ thay đổi API/UI read path và test, không chuẩn hóa dữ liệu cũ.

## Kết luận

- Đạt code/test/build local.
- Có thể deploy staging để QA.
- Chưa kết luận production cho đến khi có xác nhận deploy/check production và bằng chứng read-only trên dữ liệu thật.
