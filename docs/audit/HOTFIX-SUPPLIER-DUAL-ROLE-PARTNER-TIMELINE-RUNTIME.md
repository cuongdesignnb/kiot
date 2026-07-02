# HOTFIX — Supplier Dual-role Partner Timeline Runtime

## Phạm vi

- Module: Nhà cung cấp, Khách hàng, công nợ đối tác dual-role.
- Màn hình: `/suppliers`, tab `Công nợ`.
- Case QA: `Anh Thanh Thiên Phú`.
- Mục tiêu: tab NCC dual-role phải gọi partner timeline để thấy cả `PN`, `HD/TTHD`, ledger/gộp công nợ trong dòng thời gian vị thế ròng.

## Source đã kiểm tra

- `app/Http/Controllers/SupplierController.php`
- `app/Services/PartnerDebtLedgerService.php`
- `resources/js/Pages/Suppliers/Index.vue`
- `tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`
- `docs/audit/HOTFIX-SUPPLIER-DUAL-ROLE-PARTNER-TIMELINE.md`

## Runtime debug local

- HEAD trước sửa: `a215211faa27500e2d159b652c809e35286c0fa7`.
- Local DB có đối tác `Anh Thanh Thiên Phú`:
  - `id=210`
  - `is_customer=1`
  - `is_supplier=1`
  - `debt_amount=47.400.000`
  - `supplier_debt_amount=75.000.000`
- Local DB có chứng từ phía khách và NCC:
  - `invoice_count=4`
  - `purchase_count=5`
  - `cashflow_count=1`
- Gọi service `buildSupplierDualRolePartnerTimeline()` cho `id=210` trả:
  - `display_mode=partner_net_timeline`
  - `customer_receivable_balance=47.400.000`
  - `supplier_payable_balance=75.000.000`
  - `partner_net_position=-27.600.000`
  - entries có `PN20260528090703`, `CKTT26052510573737`, `MERGE-CUSTOMER-141`, `HD177933714532`, `HD177933240323`.
- Gọi controller `debtTransactions(210, view=partner, page=1, per_page=10)` trả:
  - `summary.display_mode=partner_net_timeline`
  - `summary.is_supplier_tab_partner_timeline=true`
  - page 1 có `PN...`, `MERGE/CKTT`, `HD...`.

## Root cause

- Backend partner timeline đã tồn tại và trả đúng khi có query `view=partner`.
- Frontend `/suppliers` xác định dual-role bằng điều kiện `supplier.is_customer && supplier.is_supplier`.
- Trong runtime/payload supplier list, màn `/suppliers` đã là danh sách NCC nên không nên phụ thuộc lại vào `is_supplier` ở helper bật partner view.
- Khi helper trả false hoặc cache cũ còn `supplier_payable`, request không gửi `view=partner`, nên API trả NCC thuần và bảng chỉ còn `PN...`, cột cuối vẫn là `Nợ cần trả nhà cung cấp`.

## Thay đổi đã làm

- `resources/js/Pages/Suppliers/Index.vue`
  - Thêm helper `truthyFlag()` để nhận đúng boolean/int/string flags từ Inertia payload.
  - `isDualRoleSupplier(id)` chỉ cần `is_customer` truthy vì `/suppliers` đã là supplier list.
  - Khi mở tab `Công nợ`, nếu dual-role nhưng cache hiện tại chưa phải `partner_net_timeline`, tự reload API với `view=partner`.
- `app/Http/Controllers/SupplierController.php`
  - Endpoint vẫn abort `404` nếu record không phải supplier.
  - Chế độ partner chỉ phụ thuộc `$supplier->is_customer` và query `view=partner`.

## Data safety

- Migration: Không.
- Backfill: Không.
- Recalculate: Không.
- Update dữ liệu cũ: Không.
- Delete dữ liệu: Không.
- Tạo phiếu CB/HCB: Không.
- Các lệnh runtime debug chỉ đọc dữ liệu qua tinker/service/controller.

## Tests đã chạy

- `php artisan test tests/Feature/Suppliers/SupplierDualRolePartnerTimelineTest.php`: PASS, 4 passed, 38 assertions.
- `php artisan test tests/Feature/Suppliers/SupplierPayableLedgerTest.php`: PASS, 5 passed, 27 assertions.
- `php artisan test tests/Feature/Suppliers/HOTFIXFollowUpSupplierLedgerHardeningTest.php`: PASS, 7 passed, 18 assertions.
- `php artisan test tests/Feature/Customers/DualRolePartnerDebtTimelineTest.php`: PASS, 5 passed, 45 assertions.
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`: PASS, 1 passed, 26 assertions.
- `php artisan test tests/Feature/Customers/ReconcilePartnerLedgerCommandTest.php`: PASS, 3 passed, 28 assertions.
- `php artisan test --filter=Supplier`: PASS, 106 passed, 476 assertions.
- `php artisan test --filter=DebtOffset`: PASS, 5 passed, 23 assertions.
- `php artisan test --filter=CashFlow`: PASS, 37 passed, 204 assertions.
- `php artisan test --filter=Purchase`: PASS, 75 passed, 345 assertions.
- `php artisan test --filter=CustomerDebt`: PASS, 37 passed, 195 assertions.
- Note: PHP CLI in local environment prints startup warnings for optional missing `oci8_*`, `pdo_oci`, and `pdo_firebird` extensions. Test exit codes were successful.

## Build

- `npm run build`: PASS, Vite built successfully.

## Manual QA

- Local browser QA: Chưa chạy trực tiếp trong trình duyệt sau commit này.
- Read-only runtime QA bằng DB/service/controller:
  - Thiên Phú partner summary đúng `47.400.000 - 75.000.000 = -27.600.000`.
  - API partner mode có `HD...` trong entries.
  - API partner mode có `display_mode=partner_net_timeline`.

## Kết luận

- Đạt code/test/build local.
- Root cause là frontend không luôn gửi `view=partner` và không reload khi cache cũ còn supplier payable.
- Có thể deploy sau khi push và QA lại `/suppliers` bằng hard refresh/rebuild asset.
