# Hotfix Report — POS Process Order Vue Initialization Error (TDZ)

This hotfix resolves a Vue initialization crash in the POS workspace when loading an order for processing.

## 1. Source Audited & Root Cause
- **Audited File:** [POS/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/POS/Index.vue)
- **Root Cause:**
  - In `POS/Index.vue`, a computed proxy `selectedCustomer` was referenced in the watcher `watch(() => selectedCustomer.value, ...)` prior to the variable's declaration (`const selectedCustomer = computed(...)`).
  - During setup initialization, Vue immediately evaluates the watch source getter to collect dependencies. Since the `selectedCustomer` constant was declared below the watcher (in the Temporal Dead Zone), Javascript threw a `ReferenceError: Cannot access 'selectedCustomer' before initialization`.
  - In production builds, the compiler minifies the variable name to `A`, resulting in the error message `Cannot access 'A' before initialization`.

## 2. Changes Made
- **Variable Order Reorganization:**
  - Moved the entire **Customer Search** state block (`customerQuery`, `customerResults`, `selectedCustomer`, `showCustomerDropdown`, `customerSearching`, `customerTimeout`) up in the script block.
  - Placed the definitions right after other computed proxies (`saleMode`, `orderNote`, etc.) and before any watches, `onMounted()`, or functions referencing them.
- **Improved Error Alerts:**
  - Updated the catch block in `checkAndHydrateOrderFromUrl()` to output a more specific server error message in the alert dialog (e.g., `e.response?.data?.message` or `e.message`) rather than a generic network error message, while printing the full stack trace to the developer console.

---

## 3. Core Safety Confirmations
- **No Database Migrations:** Confirmed.
- **No Backfill/Data Modification:** Confirmed.
- **No Backend Modifications:** Confirmed.
- **Read-Only Loading:** Confirmed. Merely opening `/pos?mode=process_order&order_id=5` only calls `GET /orders/{order}/pos-payload` and does not generate invoices, modify inventory, or create cash flows.

---

## 4. Verification & Build Results
- **Frontend Assets Build:**
  - Successfully ran `npm run build`. Compiles cleanly without any errors.
- **Feature Tests:**
  - Ran `php artisan test tests/Feature/Orders/ProcessOrderViaPosTest.php`. All 6 tests passed successfully.
