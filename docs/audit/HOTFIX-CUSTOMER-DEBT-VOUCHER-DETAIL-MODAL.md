# Audit Report — Hotfix: Customer Debt Voucher Detail Modal (Read-only)

## Context & Objectives
- **Target**: Allow users to click transaction/voucher codes (`HD...`, `PN...`, `PT...`, `TTHD...`, `CKTT...`, `MERGE...`) in the **Công nợ** (Debt History) tab of the Customer detail screen (`/customers`) to view read-only detail popups.
- **Constraints**:
  - Strictly read-only; no editing, deleting, or updating.
  - Enforce ownership validation (direct ownership or presence of ledger record in `customer_debts` associated with the customer ID).
  - No database migration or backfilling of historical records.
  - Do not modify cash flows, invoice balances (`customer_paid`), or outstanding debt summaries (`debt_amount`).

## Source Files Audited
- `routes/web.php`
- `app/Http/Controllers/CustomerController.php`
- `resources/js/Pages/Customers/Index.vue`
- `tests/Feature/Customers/CustomerDebtVoucherDetailTest.php`

## Root Cause & Current State
Previously, only voucher codes starting with `CB` or `DTCN` (offset adjustments) were clickable in the frontend table. Clicking them opened the local offset detail popup. Other voucher codes (`HD`, `PN`, `PT`, `TTHD`, `CKTT`, `MERGE-CUSTOMER`) had no controller route/resolver or interactive details modal, preventing users from reviewing specific transactions directly from the customer's ledger sheet.

## Implementation Details

### 1. Backend Route & Resolver
A common route was registered:
```php
Route::get('/customers/{customer}/debt-voucher-detail', [CustomerController::class, 'debtVoucherDetail'])
    ->name('customers.debt-voucher-detail')
    ->middleware('permission:customers.view');
```
The endpoint:
- Validates the `code` query parameter.
- Enforces an ownership check helper:
  ```php
  private function customerHasDebtRef(Customer $customer, string $code): bool
  ```
- Separates parsing logic for each voucher type:
  - `HD...` -> returns details and invoice items.
  - `PN...` -> returns details and purchase items.
  - `PT...` / `TTHD...` -> returns cash flow receipt details.
  - `CKTT...` -> returns discount details and related invoice allocations.
  - `MERGE-CUSTOMER...` or custom ledger adjustments -> returns entries list.
- Returns a standard `404` error code when a voucher is missing or unauthorized.

### 2. Frontend Interface
In `resources/js/Pages/Customers/Index.vue`:
- Replaced the cell's `span` with a general clickable hook calling `openDebtVoucherDetail(entry, customer.id)`.
- Added reactive state `debtVoucherDetailModal` and helper `openDebtVoucherDetail`.
- Structured a responsive Vue dialog using Tailwind styled templates to cleanly present dates, currencies, and item grids.

---

## Verification & Tests
A dedicated PHPUnit feature test suite `tests/Feature/Customers/CustomerDebtVoucherDetailTest.php` was created covering 10 scenarios:
1. **Invoice Directly Associated** (Passed)
2. **Invoice Associated via Ledger** (Passed)
3. **Rejection of Foreign Invoice** (Passed)
4. **Purchase Directly Associated** (Passed)
5. **Rejection of Foreign Purchase** (Passed)
6. **Cash Flow Directly Associated** (Passed)
7. **Rejection of Foreign Cash Flow** (Passed)
8. **Payment Discount Details** (Passed)
9. **Ledger Merge Details** (Passed)
10. **Zero Side Effects** (Passed)

### Test Command Outcomes
- `php artisan test tests/Feature/Customers/CustomerDebtVoucherDetailTest.php` -> Passed
- `php artisan test tests/Feature/Customers/CustomerPaymentDiscountTest.php` -> Passed
- `php artisan test --filter=CustomerDebt` -> Passed
- `php artisan test --filter=CancelInvoicePaymentDebtFlowTest` -> Passed
- `php artisan test tests/Feature/Damage/RR09DamageStockTest.php` -> Passed

### Frontend Asset Compilation
Vite asset bundler completed successfully:
```bash
npm run build
```
Built in 7.04s.

## Data Safety & Integrity Checks
- **Schema Migrations**: None.
- **Data Mutations**: Verified through testing that retrieving details does not execute updates or inserts on invoices, purchases, cash flows, or customers.
- **Safety**: Safe. Rollback is a simple git revert.

## Remaining Risks & Next Steps
- **Editing Payments**: Editing/deleting cash flows or receipts is not implemented in this hotfix to protect historical data integrity. Any editing feature in a future phase must undergo a complete transaction lifecycle audit.
