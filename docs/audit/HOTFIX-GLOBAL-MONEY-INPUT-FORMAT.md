# HOTFIX - Global Money Input Format

## Source checked

- `resources/js/Components/MoneyInput.vue`
- `resources/js/utils/money.js`
- `resources/js/Components/QuickCreateProductModal.vue`
- `resources/js/Components/OtherFeeManager.vue`
- `resources/js/Pages/Purchases/Create.vue`
- `resources/js/Pages/Purchases/Edit.vue`
- `resources/js/Pages/PurchaseOrders/Create.vue`
- `resources/js/Pages/PurchaseReturns/Create.vue`
- `resources/js/Pages/PurchaseReturns/CreateQuick.vue`
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/Orders/Create.vue`
- `resources/js/Pages/Orders/Index.vue`
- `resources/js/Pages/CashFlows/Index.vue`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Products/Create.vue`
- `resources/js/Pages/Products/Edit.vue`
- `resources/js/Pages/PriceSettings/Index.vue`
- `resources/js/Pages/Employees/Index.vue`
- `resources/js/Pages/Employees/PayrollSettings.vue`
- `resources/js/Pages/Employees/PaysheetEdit.vue`
- `resources/js/Pages/Employees/Settings.vue`
- `resources/js/Pages/StockTransfers/Create.vue`
- `resources/js/Pages/Tasks/Show.vue`

## Root cause

Some money fields still used raw `type="number"` inputs or manual focus/blur formatting helpers. In the quick-create product modal, `cost_price`, `retail_price`, and `technician_price` only normalized on focus/blur, so typing `14500` displayed `14500` instead of realtime `14.500`.

## Fix summary

- Replaced quick-create product money fields with `MoneyInput`.
- Removed old quick-create product focus/blur money handlers and formatted-string parsing from submit.
- Converted audited money fields to `MoneyInput` where the field represents VND.
- Kept non-money `type="number"` inputs unchanged for quantity, warranty months, stock, conversion rate, duration, percent, and paging fields.
- Normalized frontend payloads with `Number(...) || 0` for money values before submit.

## Files changed

- `resources/js/Components/QuickCreateProductModal.vue`
- `resources/js/Components/OtherFeeManager.vue`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Employees/Index.vue`
- `resources/js/Pages/Employees/PayrollSettings.vue`
- `resources/js/Pages/Employees/PaysheetEdit.vue`
- `resources/js/Pages/Employees/Settings.vue`
- `resources/js/Pages/Orders/Create.vue`
- `resources/js/Pages/Orders/Index.vue`
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/PriceSettings/Index.vue`
- `resources/js/Pages/Products/Edit.vue`
- `resources/js/Pages/PurchaseOrders/Create.vue`
- `resources/js/Pages/PurchaseReturns/Create.vue`
- `resources/js/Pages/PurchaseReturns/CreateQuick.vue`
- `resources/js/Pages/Purchases/Create.vue`
- `resources/js/Pages/Purchases/Edit.vue`
- `resources/js/Pages/StockTransfers/Create.vue`
- `resources/js/Pages/Tasks/Show.vue`
- `docs/audit/HOTFIX-GLOBAL-MONEY-INPUT-FORMAT.md`

## Audited screens

- Nhập hàng
- Sửa phiếu nhập
- Đặt hàng nhập
- Trả hàng nhập
- POS
- Đặt hàng
- Sổ quỹ
- Khách hàng and debt modals
- Sản phẩm create/edit
- Bảng giá
- Cài đặt phụ thu/thu khác
- Lương nhân viên
- Phiếu lương
- Chuyển kho
- Công việc/sửa chữa quick-create and completion forms

## Safety

- Migration: Không.
- Backfill: Không.
- Update dữ liệu cũ: Không.
- Recalculate giá vốn: Không.
- Sửa tồn kho: Không.
- Sửa serial/IMEI: Không.
- Backend/core stock/costing changes: Không.

## Tests and build

- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php`: Failed before assertions because the test database connection was refused: `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it`.
- `php artisan test --filter=Product`: Not run after the required minimum PHP test failed for environment/database connectivity.
- `php artisan test --filter=Purchase`: Not run after the required minimum PHP test failed for environment/database connectivity.
- `php artisan test --filter=CashFlow`: Not run after the required minimum PHP test failed for environment/database connectivity.
- `npm run build`: Passed. Vite built 915 modules successfully.

## Manual QA

Not run in-browser in this environment because the app/test database was not available. Expected manual flow to verify:

- Nhập hàng -> Tạo nhanh hàng hóa.
- Type `14500` in `Giá vốn (giá nhập)` and confirm realtime `14.500`.
- Type `1500000` in `Giá bán` and confirm realtime `1.500.000`.
- Save product and confirm payload/backend value remains numeric.
