# Audit Report — Order Processing via POS Delivery Flow (Phase 1)

This audit report documents the details, reference material, implemented scope, roadmap details, and validation results for Phase 1 of the Order-to-POS processing and delivery integration.

## 1. Source Audited & Reference Documents
- **Source Code Inspected:**
  - [routes/web.php](file:///d:/Kiot/kiotviet-clone/routes/web.php)
  - [OrderController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/OrderController.php)
  - [POS/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/POS/Index.vue)
  - [Orders/Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Orders/Index.vue)
- **KiotViet Reference Material:**
  - **Ordering / Đặt hàng:** `https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-dat-hang/dat-hang/`
  - **Shipping / Giao hàng:** `https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-giao-van/giao-hang/`
  - **Shipping Settlement / Thanh toán với ĐTGH:** `https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-giao-van/giao-hang/#4-thanh-toan-voi-doi-tac-giao-hang-4-2`

## 2. Root Cause & Scope

### Root Cause / Bối cảnh
Previously, clicking the **Xử lý đơn hàng** (Process Order) button on the `/orders` screen opened a small pop-up modal directly on the orders list page. This modal immediately completed the order and created an invoice. There was no way to adjust shipping details, select delivery partners (Grab, Ahamove, GHTK, GHN, Viettel Post, etc.), or use the full cashier screen (POS) layout to complete the order with customized payment methods and delivery parameters.

### Scope Implemented (Phase 1)
- **No Direct Invoice on click:** Clicking "Xử lý đơn hàng" in `/orders` does not perform any database modification. Instead, it redirects the user to `/pos?order_id={id}&mode=process_order`.
- **POS Hydration:** The POS workspace intercepts the query parameters, makes a read-only request to `/orders/{id}/pos-payload` (which verifies order status is not completed/ended/cancelled), and initializes a new POS workspace tab showing the Order Code.
- **Data Prefill:** Automatically hydrates the cart items (with quantities, prices, discounts, and pre-selected serial numbers), customer information, order notes, existing delivery details, and prior deposit amount (`orderDepositAmount`).
- **POS Constraints:** Cart item quantities, prices, discounts, item deletions, and catalog search additions are locked to ensure the cashier processes the order *as-is* without discrepancies (Phase 1 does not support partial fulfillment).
- **Shipping UI Panel:** Developed a delivery setup panel in the right column of the POS screen under "Bán giao hàng". Cashiers can select:
  - *Không giao hàng/Bán tại quầy*
  - *Tự giao hàng*
  - *Đối tác giao hàng* (Grab, Ahamove, GHTK, GHN, Viettel Post, Giao hàng ngoài, Khác) with manual fields for Tracking Code, Weight, Shipping Fee, and Cash on Delivery (COD).
- **Correct Ledger Calculations:** Displays prior deposit amount, additional payment requested at POS, and remaining customer debt.
- **Conversion Checkout:** Clicking "Tạo hóa đơn" (Checkout) sends a POST request to `/orders/{id}/process` containing the updated delivery settings and the additional paid amount. The backend commits invoice creation, updates order status to `completed`, deducts warehouse stock, changes selected serial status to `sold`, records customer debt ledger entries, and generates a CashFlow receipt *only* for the additional payment amount.

### Scope Deferred (Roadmaps)

#### Phase 2 — Partial Fulfillment (Roadmap)
- **Goal:** Allow customers to retrieve items in multiple segments and pay partially.
- **Impact:** Requires a schema modification (e.g., tracking `fulfilled_qty` on order items or creating a separate `order_fulfillments` log table).
- **Status:** Deferred. Not implemented in Phase 1 to protect database schema stability.

#### Phase 3 — Independent Shipments / Vouchers (Roadmap)
- **Goal:** Track independent delivery logs separate from invoices (e.g., multiple shipments per invoice, independent shipment status flow).
- **Status:** Deferred. No shipping database tables or status logs were added.

#### Phase 4 — Shipping Settlement (Roadmap)
- **Goal:** Pay or settle COD amounts and delivery fees with shipping partners.
- **Status:** Deferred. No partner payout ledger entries or cash flow offsets are created in Phase 1.

---

## 3. Core Safety Confirmations
- **No Database Migrations:** Checked. No new tables or column alterations were made.
- **No Backfill/Data Modification:** Checked. Historical database records remain unchanged.
- **Read-Only Loading:** Loading the order details into POS is strictly a read operation (`GET /orders/{order}/pos-payload`). No stock deductions or status changes happen until **Tạo hóa đơn** is executed.
- **Whole-Order constraint:** Attempts to alter quantities or modify items during checkout are blocked with a `422` validation code.
- **Deposit Cash Flows:** CashFlow receipts are only created for the *additional* payment amount paid at checkout. The prior deposit is not double-counted.

---

## 4. Verification Results

### Automated Tests
Created automated feature test [ProcessOrderViaPosTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Orders/ProcessOrderViaPosTest.php). All 6 test cases passed:
1. `pos payload returns correct data and does not modify db` (Pass)
2. `pos payload rejects completed or invalid orders` (Pass)
3. `pos processing creates invoice completes order correctly` (Pass)
4. `pos processing rejects quantity mismatch in phase 1` (Pass)
5. `pos processing serial override` (Pass)
6. `pos processing deposits cash flow limit` (Pass)

### Compilation
Assets compiled successfully via Vite:
```bash
npm run build
# Built successfully in 7.46s
```

### Manual QA Checklist Done
1. **Redirect:** Confirmed that clicking "Xử lý đơn hàng" on order detail page redirects to `/pos?order_id={id}&mode=process_order`.
2. **Tab Hydration:** Checked that POS loads the tab with the order code, sets customer details, locks cart items, and displays prior deposit.
3. **Delivery Settings:** Tested switching to "Bán giao hàng", selecting shipping mode (Self vs Partner), filling out receiver details, and submitting the invoice.
4. **Checkout:** Verified that order status correctly switches to `completed`, stock decreases, and cash flows are created accurately for the new payment amount.
