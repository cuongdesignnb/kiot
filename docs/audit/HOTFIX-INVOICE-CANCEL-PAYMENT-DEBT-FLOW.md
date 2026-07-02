# Audit Log — HOTFIX Invoice Cancellation & Debt Flow

## Background Context
- **Repository**: `cuongdesignnb/kiot`
- **Issue**: Cancelled invoices kept their `customer_paid` values in reports and UI summary, misleading users into thinking payments were active. Related `CashFlow` records were not cancelled consistently, debt adjustments were labeled generic "Điều chỉnh" instead of "Hủy hóa đơn", and cancelled invoices were not excluded from debt collections.
- **Production Safety Requirements**:
  - No database schema migrations.
  - No bulk backfills or data modifications.
  - Do not change `invoice.customer_paid` values (must preserve snapshot).
  - Do not alter `customer_debts.type` column schema.
  - Do not physical delete or soft-delete `CashFlow` records (keep records, set `status = 'cancelled'`).

## Implemented Solutions

### 1. Backend Changes
- **[InvoiceController@destroy](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/InvoiceController.php#L327)**:
  - Updated cashflow cancellations query:
    ```php
    $cancelledCashFlowCount = CashFlow::where('reference_type', 'Invoice')
        ->where('reference_code', $invoice->code)
        ->where('status', '!=', 'cancelled')
        ->update(['status' => 'cancelled']);
    ```
    This avoids duplicate cancellation logic and correctly captures the count.
- **[InvoiceController@paymentHistory](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/InvoiceController.php#L484)**:
  - Retrieved payments using `CashFlow::withTrashed()` where `reference_type = 'Invoice'` and `reference_code = $invoice->code`.
  - Structured response to return `is_cancelled = ($cf->status === 'cancelled')`.
  - Added legacy mock payment generator only if the invoice status is **not** `Đã hủy` and `customer_paid > 0` for backwards compatibility.
  - Enriched the payload with `customer_paid_snapshot` and `effective_paid` (forced to `0.0` for cancelled invoices).
- **[CustomerController@debtHistory](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php#L515)**:
  - Defined helper `$isInvoiceCancelDebt` matching `type = 'adjustment'` and ref code / notes starting with invoice-cancel indicators.
  - Dynamically mapped ledger types to display `Hủy hóa đơn` with raw type `invoice_cancel_reversal` on the fly.
  - Excluded synthetic invoice/payment entries from legacy lists if the invoice is cancelled (`status === 'Đã hủy'`).
- **[CustomerController@outstandingInvoices](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php#L955)**:
  - Excluded cancelled invoices: `where('status', '!=', 'Đã hủy')`.
- **[CustomerController@debtPayment](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php#L796)**:
  - Prevented allocations to cancelled invoices in both auto and manual modes.
  - Added validation warning if all requested allocations target cancelled invoices.
- **[routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php#L267)**:
  - Added missing `/customers/{customer}/outstanding-invoices` GET route.

### 2. Frontend Changes
- **[Invoices/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Invoices/Index.vue)**:
  - Integrated status helpers `isInvoiceCancelled` and `effectiveCustomerPaid`.
  - Summary section now displays "Khách đã trả hiệu lực" at `0đ` and "Đã trả trước hủy" snapshot when an invoice is cancelled.
  - Tab "Lịch sử thanh toán" renders a yellow banner warning about snapshot limitations.
  - Cancelled cashflow items are formatted with red `Đã hủy` badges and line-through text.

---

## Verification Results
- All target unit and feature tests passed successfully:
  - `CancelInvoiceTest` (10 tests, 30 assertions) — **PASS**
  - `CancelInvoicePaymentDebtFlowTest` (4 tests, 21 assertions) — **PASS**
  - `RR06CustomerDebtLedgerTest` & `CustomerDebtHistoryReturnSettlementDisplayTest` (21 tests, 86 assertions) — **PASS**
  - `RR09DamageStockTest` (5 tests, 12 assertions) — **PASS**
- Assets build completed successfully with Vite compiler.
