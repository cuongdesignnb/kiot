# STEP 23.9 — VND Currency Format Audit Report

## Objective
Standardize all monetary displays throughout the system to use the `1.000.000đ` format.

## Standard
| Value | Display |
|-------|---------|
| 1000000 | 1.000.000đ |
| 0 | 0đ |
| 50000 | 50.000đ |
| -1000000 | -1.000.000đ |

## Changes Made

### 1. Centralized Helpers

**JavaScript (Frontend)**
- `resources/js/utils/money.js` — Source of truth
  - `formatVND(value)` — Display formatter (e.g. `1000000` → `"1.000.000đ"`)
  - `parseVND(value)` — Input sanitizer (e.g. `"1.000.000đ"` → `1000000`)

**PHP (Backend/Blade)**
- `app/Helpers/money.php` — `format_vnd($value)` for Blade views
- Registered in `composer.json` autoload files

### 2. Vue Pages Updated (40+ files)

All local `formatCurrency`, `fmt`, and inline `toLocaleString()` calls replaced with centralized import:
```js
import { formatVND as formatCurrency } from '@/utils/money';
```

**Files updated:**
- Welcome.vue (Products list)
- Dashboard/Index.vue
- POS/Index.vue
- Invoices/Index.vue, Show.vue
- Orders/Index.vue, Create.vue
- Purchases/Index.vue, Create.vue, Edit.vue, Show.vue
- PurchaseOrders/Index.vue, Create.vue
- PurchaseReturns/Index.vue, Create.vue, CreateQuick.vue, Show.vue
- Returns/Index.vue, Show.vue
- Customers/Index.vue
- Suppliers/Index.vue
- Employees/Index.vue
- CashFlows/Index.vue
- StockTakes/Index.vue, Create.vue
- StockTransfers/Index.vue, Create.vue
- Damages/Index.vue, Create.vue
- PriceSettings/Index.vue
- Tasks/Index.vue, Show.vue
- Repairs/Index.vue, Show.vue
- Reports/* (SalesReport, FinancialReport, CostAnalysis, CostProfit, EndOfDayReport, CustomerDebt, CustomerReport, DebtReconciliation, EmployeeReport, Inventory, OrderReport, ProductReport, SerialCostHistory, StockCard, SupplierReport)

### 3. Blade Print Views Updated (9 files)

All `number_format($val, 0, ',', '.')` calls replaced with `format_vnd($val)`:
- cashflow.blade.php
- damage.blade.php
- invoice.blade.php
- order.blade.php
- purchase.blade.php
- purchase_order.blade.php
- return.blade.php
- stock_take.blade.php
- stock_transfer.blade.php
- paysheet.blade.php

### 4. Double-đ Bug Fixes

Removed trailing `đ` / `₫` suffixes that would create double-đ display after switching to formatVND:
- Tasks/Show.vue — 23 instances
- Tasks/Index.vue — 1 instance
- Repairs/Show.vue — 6 instances
- Repairs/Index.vue — 1 instance
- Dashboard/Index.vue — 3 instances
- Customers/Index.vue — 4 instances (₫ symbol)
- POS/Index.vue — 3 instances (₫ symbol)
- CashFlows/Index.vue — 3 instances
- Suppliers/Index.vue — 2 instances (₫ symbol + alert)
- Orders/Index.vue — 1 instance
- Invoices/Show.vue — 1 instance
- Returns/Show.vue — 1 instance

### 5. What Was NOT Changed (by design)

| Item | Reason |
|------|--------|
| `formatCurrencyInput()` in Purchases Create/Edit | Different function — formats input values without `đ` suffix |
| `<span>₫</span>` as input field labels | Visual hint, not a money value |
| `toLocaleString("vi-VN")` for dates | Date formatting, not money |
| Database schemas | Only presentation layer changed |
| API payloads | Raw numbers preserved |
| CSV exports | Raw numeric data for re-import |
| Quantity fields | Not money |
| Serial/IMEI numbers | Not money |
| Phone numbers | Not money |

## Test Results

```
Tests: 2 skipped, 245 passed (1524 assertions)
Duration: 40.43s
```

Build: `✓ built in 6.19s` — no errors.

## File Inventory

| Category | Count |
|----------|-------|
| New files | 2 (money.js, money.php) |
| Vue pages updated | 40+ |
| Blade prints updated | 10 |
| Composer.json | 1 (autoload files) |
| Total assertions passing | 1524 |
