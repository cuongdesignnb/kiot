# FLOW 07 — KIOTVIET SUPPLIER RETURNS / TRẢ HÀNG NHẬP

## Purpose
This instruction tells the agent to audit, test, and minimally repair the **supplier return / purchase return** flow so the application behaves as close as possible to KiotViet.

This flow must be tested **independently**. Do not expand into other flows except when a prerequisite from an earlier flow is required to execute this one.

---

## KiotViet reference behavior to match
Use these behaviors as the reference contract:

1. KiotViet supports **two supplier return modes**:
   - **Quick supplier return** / return without linking to a purchase receipt.
   - **Return based on a specific purchase receipt**.
2. Completing a supplier return must:
   - **decrease inventory** for returned items,
   - **update supplier payable / supplier debt** automatically,
   - record a dedicated **supplier return receipt/document**.
3. In a return created from a purchase receipt:
   - item information and supplier are prefilled from the original purchase receipt,
   - the user enters the actual return quantity,
   - returned quantity must not exceed the quantity previously purchased / available to return.
4. KiotViet supports entering the amount refunded by supplier (`Tiền nhà cung cấp trả`). Any difference is reflected in supplier debt.
5. KiotViet supports **canceling** a completed supplier return. Cancel must:
   - mark the return document as canceled,
   - **add inventory back**,
   - **update supplier payable / supplier debt** again.
6. KiotViet only allows limited field updates on completed supplier return documents, such as note / actor / date-like metadata; the core return lines should not be silently mutated after completion.
7. KiotViet stores supplier returns in a dedicated list with search/filter/sort by code, original purchase receipt, supplier, item, status, creator, note, time.
8. KiotViet supports copying an old supplier return into a new one.
9. KiotViet supports viewing / printing / exporting supplier return documents.
10. When the original purchase receipt had invoice-level discount, KiotViet shows a prompt/explanation when returning by receipt. Your app does not need the exact UI text, but must have a correct and explicit handling strategy for discount allocation and returned value.

---

## Primary references used for this flow
The agent should treat the following KiotViet docs as reference behavior:

- Retail supplier return doc: `retail-giao-dich/tra-hang-nhap`
- Legacy supplier return doc: `quan-ly-giao-dich/tra-hang-nhap`
- KiotViet wiki article about supplier return behavior

Do **not** assume that a pretty UI is enough. Audit the actual state transitions, stock movement, and supplier payable calculation.

---

## Scope of this flow
You are only testing and repairing:

- create supplier return quickly (without original purchase receipt)
- create supplier return from original purchase receipt
- supplier refund allocation / supplier payable adjustment
- return quantity validation
- cancellation behavior
- search/filter/list behavior for supplier returns
- copy behavior
- print/export endpoints if present
- immutability / limited edit policy after completion

Out of scope unless required as prerequisite:

- customer sales
- customer returns
- stocktake
- transfer
- accounting journal engine beyond what this flow touches

---

## Mandatory working rules for the agent

1. **Read the source first.** Do not patch blindly.
2. **Map the flow** before changing code:
   - routes
   - controller / handler
   - service / use case
   - inventory update path
   - supplier payable / ledger update path
   - database tables
   - UI screens involved
3. **Reproduce first, then fix.** Every code change must correspond to a reproduced or proven defect.
4. **Patch minimally.** Do not refactor unrelated modules.
5. **Preserve earlier flows.** Re-run only the minimum regression set needed to confirm no breakage in purchase receipt and supplier payable flows.
6. **Never hide a bug with front-end validation only** if the business rule also needs backend enforcement.
7. **Do not invent KiotViet behavior** when uncertain. If exact parity is impossible, record deviation explicitly.

---

## Required preconditions
Before executing this flow, confirm these prerequisites exist and work:

- Foundation data from Flow 01 exists:
  - at least one warehouse
  - at least one supplier
  - at least two stock-tracked products
- Purchase receipt flow from Flow 02 can create completed purchase receipts.
- Supplier payable flow from Flow 06 can display/update payable values or ledger history.

If any prerequisite is missing, do not improvise random data behavior. Create the smallest valid prerequisite setup and document it.

---

## Fixed seed data for testing
Use deterministic data. Do not use random item names or random prices.

### Warehouse
- `KHO_TONG` — Kho tổng

### Supplier
- `NCC001` — Công ty Minh Phát — phone `0900000002`

### Products
1. `SP001` — Nước suối 500ml
   - purchase price default: 5,000
   - sale price default: 7,000
2. `SP002` — Bánh quy hộp
   - purchase price default: 20,000
   - sale price default: 30,000

### Purchase receipts that must be created before testing this flow
Create or seed the following purchase receipts:

#### Receipt PR-BASE-01
- supplier: `NCC001`
- warehouse: `KHO_TONG`
- lines:
  - `SP001` qty 20 unit cost 5,000
  - `SP002` qty 10 unit cost 20,000
- supplier paid: 0
- status: completed
- resulting supplier debt must increase accordingly

#### Receipt PR-BASE-02
- supplier: `NCC001`
- warehouse: `KHO_TONG`
- lines:
  - `SP001` qty 8 unit cost 6,000
- supplier paid: 48,000
- status: completed

#### Receipt PR-DISCOUNT-01
- supplier: `NCC001`
- warehouse: `KHO_TONG`
- lines:
  - `SP002` qty 10 unit cost 20,000 = 200,000 line total
- invoice-level discount: 20,000
- net purchase value: 180,000
- supplier paid: 0
- status: completed

These receipts are used to verify both normal and discount-related return behavior.

---

## High-level state expectations
The application should have a supplier return state model equivalent to:

- draft / temporary (if supported)
- completed / returned
- canceled

Expected rules:

- completed supplier return decreases stock
- canceled supplier return restores stock
- completed supplier return affects supplier debt / refund value
- canceled supplier return reverses that effect
- completed supplier return should not allow silent mutation of line items or quantities

---

# TEST CASES

## 07A — Quick supplier return without original purchase receipt
### Goal
Verify the app supports creating a supplier return directly, without attaching it to a purchase receipt.

### Steps
1. Open supplier return screen.
2. Create a new supplier return.
3. Add `SP001` quantity `2`.
4. Select supplier `NCC001`.
5. Set unit return price to `5,000`.
6. Enter `Supplier refund amount = 0`.
7. Complete the return.

### Expected result
- a completed supplier return document is created,
- inventory for `SP001` decreases by `2`,
- supplier debt is adjusted by the return value (`10,000`) if no refund is recorded,
- supplier return appears in the supplier return list,
- the document has a stable code/id and status = completed.

### Fail conditions
- stock does not decrease,
- supplier debt is unchanged,
- return requires an original purchase receipt when KiotViet supports quick return,
- return document is not persisted.

---

## 07B — Supplier return from original purchase receipt
### Goal
Verify creation from a specific purchase receipt preloads the right data and records the correct return.

### Steps
1. Open `PR-BASE-01`.
2. Click `Supplier Return` / `Trả hàng nhập` from the purchase receipt.
3. Confirm supplier and items are prefilled.
4. Return:
   - `SP001` qty `3`
   - `SP002` qty `1`
5. Set supplier refund amount = `0`.
6. Complete the return.

### Expected result
- supplier and relevant product info are prefilled from `PR-BASE-01`,
- return quantity is accepted because it does not exceed purchased quantity,
- stock decreases by `3` for `SP001` and `1` for `SP002`,
- supplier payable is adjusted by the returned value,
- the supplier return stores a link/reference to `PR-BASE-01`,
- return document status = completed.

### Fail conditions
- no preload from original receipt,
- wrong supplier is attached,
- stock decreases incorrectly,
- supplier debt changes by the wrong amount,
- original purchase receipt reference is lost.

---

## 07C — Reject over-return quantity
### Goal
Verify the app does not allow returning more than was purchased / available to return.

### Steps
1. Open `PR-BASE-01`.
2. Start a supplier return from the receipt.
3. Try to return `SP001` quantity greater than the purchased quantity remaining.
   - Example: if 20 were purchased and 3 already returned, try 18 or 21 depending on prior state.
4. Submit.

### Expected result
- backend rejects the request,
- UI shows a clear validation error,
- no stock movement is created,
- no supplier debt movement is created,
- no partial corrupted document remains in completed state.

### Fail conditions
- app allows quantity beyond purchased/returnable amount,
- negative available quantity appears later,
- stock/payable changes despite invalid return.

---

## 07D — Multiple purchase prices for the same item
### Goal
Verify the app handles item returns correctly when the same product was purchased at different costs.

### Steps
1. Ensure `SP001` exists in:
   - `PR-BASE-01` with unit cost `5,000`
   - `PR-BASE-02` with unit cost `6,000`
2. Create a supplier return from `PR-BASE-02` for `SP001` qty `2`.
3. Inspect stored line price and total.

### Expected result
- the return should use the correct originating receipt context,
- returned value must be based on the price rules for the selected purchase receipt or explicit return price,
- payable adjustment must match that return value,
- stock decrease is exactly `2`.

### Fail conditions
- app mixes cost layers from unrelated purchase receipts,
- app always uses a global default purchase price and ignores the source receipt,
- payable value is inconsistent with the receipt being returned.

---

## 07E — Supplier refund amount and payable difference
### Goal
Verify the app correctly separates immediate supplier refund from remaining payable adjustment.

### Steps
1. Create a supplier return worth `20,000`.
2. Set `Supplier refund amount = 5,000`.
3. Complete the return.
4. Inspect payable/ledger records.

### Expected result
- return document total = `20,000`,
- supplier refund recorded now = `5,000`,
- the remaining `15,000` is handled consistently in supplier payable/ledger,
- cash/bank movement is created only if your app models it explicitly,
- supplier ledger is internally balanced and traceable.

### Fail conditions
- full `20,000` is both refunded and also deducted from payable again,
- refund amount is ignored,
- payable movement direction is wrong.

---

## 07F — Return against purchase receipt with invoice-level discount
### Goal
Verify explicit and correct handling of returns when the original purchase receipt had an invoice discount.

### Steps
1. Open `PR-DISCOUNT-01`.
2. Create a return from that receipt.
3. Return `SP002` qty `5` out of `10`.
4. Complete with supplier refund amount `0`.
5. Inspect how return value is computed.

### Expected result
- the app has an explicit handling rule for purchase receipt discount,
- return value is not silently wrong,
- if your app prorates invoice discount across lines, the math must be consistent and documented,
- supplier payable adjustment must align with the return value rule,
- if your app cannot fully match KiotViet’s exact prompt, the deviation must be recorded in the report.

### Fail conditions
- invoice discount is ignored and return value is obviously overstated,
- undocumented arbitrary numbers appear,
- supplier payable becomes inconsistent with the purchase receipt economics.

---

## 07G — Cancel completed supplier return
### Goal
Verify cancellation reverses inventory and supplier payable effects.

### Steps
1. Open a completed supplier return created in 07A or 07B.
2. Click cancel.
3. If the app asks whether to cancel related payment/refund documents, test both branches if supported:
   - confirm cancel related payment/refund documents,
   - skip cancel related payment/refund documents.
4. Finish cancellation.

### Expected result
- status becomes canceled,
- stock is added back for all returned items,
- supplier payable / supplier debt is recalculated consistently,
- canceled document becomes immutable except allowed display actions,
- if related refund/payment docs exist, behavior is explicit and traceable.

### Fail conditions
- stock is not restored,
- supplier debt is not restored correctly,
- canceled doc can still alter stock/payable when edited,
- duplicate reversal entries appear.

---

## 07H — Limited edit after completion
### Goal
Verify the app only allows safe metadata edits on completed supplier return documents.

### Steps
1. Open a completed supplier return.
2. Try changing:
   - note,
   - actor / creator-like field if supported,
   - date/time if supported.
3. Try changing forbidden core fields:
   - line quantities,
   - line values,
   - warehouse,
   - supplier (unless your app explicitly allows it under a narrow rule).

### Expected result
- metadata-only edits may be allowed,
- core commercial/inventory fields should not silently mutate a completed return,
- if supplier can only be changed when previously blank, enforce that rule explicitly,
- no hidden stock/payable recalculation should occur from forbidden edits.

### Fail conditions
- completed return lines can be freely changed,
- stock/payable changes without audit trail,
- supplier can be swapped in a way that corrupts history.

---

## 07I — Search / filter / sort supplier returns
### Goal
Verify completed and canceled supplier returns can be found and distinguished reliably.

### Steps
1. Open supplier return list.
2. Search/filter by:
   - supplier return code,
   - original purchase receipt code,
   - supplier name/code,
   - item name/code,
   - note,
   - creator,
   - status (`completed`, `canceled`),
   - time range.
3. Sort by common columns if supported.

### Expected result
- list reflects correct statuses,
- search results are accurate,
- completed and canceled returns are distinguishable,
- filters do not hide valid records unexpectedly.

### Fail conditions
- canceled returns still show as active,
- search by original receipt code fails even when linkage exists,
- list counts/status summaries are wrong.

---

## 07J — Copy supplier return
### Goal
Verify copying an old return creates a new return draft/new document without mutating the original.

### Steps
1. Open an existing supplier return.
2. Click copy.
3. Verify a new return form is created with copied information.
4. Change one quantity.
5. Complete the new return.

### Expected result
- original return remains unchanged,
- new return gets a new id/code,
- stock/payable effect occurs only for the new return after completion,
- linkage/audit trail remains clear.

### Fail conditions
- original document is mutated,
- copied document reuses original code/id,
- stock/payable duplicated unintentionally before completion.

---

## 07K — Print / export / view supplier return
### Goal
Verify retrieval and document output behave correctly if implemented.

### Steps
1. Open an existing completed supplier return.
2. Use print/view/export actions if present.
3. Validate generated payload/file or UI preview.

### Expected result
- return document can be viewed,
- export or print action reflects correct supplier, items, totals, status,
- canceled documents display canceled status clearly if printed/exported.

### Fail conditions
- wrong totals in exported file,
- missing supplier or original receipt reference,
- canceled doc printed as active.

---

# DATA CHECKS AFTER EACH TEST
After every case, the agent must verify not only UI outcome but also persistence and business effects.

## Inventory checks
For each affected SKU, verify:

`opening stock + purchases - sales - supplier_returns + customer_returns ± adjustments = closing stock`

For this flow specifically:
- completed supplier return decreases stock,
- canceled supplier return restores stock.

## Supplier payable checks
Verify supplier payable / ledger reflects:

`opening payable + purchases - supplier_payments - supplier_returns ± payable_adjustments = closing payable`

If the app models supplier refund as a separate cash/bank movement, that movement must reconcile with the return document.

## Document linkage checks
Verify:
- supplier return links to supplier,
- if created from purchase receipt, supplier return links to original receipt,
- line items are persisted with stable ids and values,
- canceled docs stay queryable.

---

# SOURCE CODE AUDIT CHECKLIST
Before patching, inspect and document:

- routes for supplier return create/view/update/cancel/list/copy/export
- controller or endpoint handlers
- validation rules for quantity and supplier
- service/use case for stock movement on supplier return
- service/use case for supplier payable / ledger movement
- data model / tables for supplier return header and lines
- status enum / constants
- audit log / activity log behavior if present
- print/export query path if implemented

Look specifically for these bug classes:

1. Missing DB transaction around stock + payable updates
2. Double ledger posting on complete or cancel
3. Allowing edits to completed lines
4. Failing to cap return quantity
5. Using wrong purchase price basis
6. Ignoring invoice-level purchase discount
7. Cancel restoring stock but not payable, or vice versa
8. Copy reusing original ids/state

---

# MINIMAL FIX POLICY
If a defect is confirmed, fix with the smallest safe patch.

Preferred order:
1. validation fix
2. service-layer business rule fix
3. persistence / transaction fix
4. UI correction if needed to expose backend behavior properly

Do not:
- rewrite unrelated purchase logic,
- change naming conventions globally,
- redesign screens unless absolutely necessary for functional parity.

---

# REQUIRED AUTOMATED TESTS TO ADD OR UPDATE
If the project has a test framework, add or update tests for at least:

1. quick supplier return creates stock decrease and payable change
2. return by original purchase receipt preloads data and links back
3. over-return quantity is rejected
4. supplier refund amount is allocated correctly
5. return with purchase invoice discount uses explicit consistent math
6. cancel supplier return restores stock and payable
7. completed return cannot freely mutate line items
8. copy creates new document without changing original

If no automated tests exist, create the smallest possible regression coverage in the existing stack.

---

# REPORT FORMAT THE AGENT MUST OUTPUT
At the end, output a report in Markdown with these sections:

## 1. Flow summary
- flow name
- tested environment
- commit hash / branch
- date/time

## 2. Mapping found in source
- routes
- modules/files touched
- tables involved

## 3. Test results table
| Case | Result | Notes |
|---|---|---|
| 07A | Pass/Fail/NA/Deviation | ... |
| 07B | Pass/Fail/NA/Deviation | ... |
| 07C | Pass/Fail/NA/Deviation | ... |
| 07D | Pass/Fail/NA/Deviation | ... |
| 07E | Pass/Fail/NA/Deviation | ... |
| 07F | Pass/Fail/NA/Deviation | ... |
| 07G | Pass/Fail/NA/Deviation | ... |
| 07H | Pass/Fail/NA/Deviation | ... |
| 07I | Pass/Fail/NA/Deviation | ... |
| 07J | Pass/Fail/NA/Deviation | ... |
| 07K | Pass/Fail/NA/Deviation | ... |

## 4. Confirmed defects
For each defect include:
- title
- reproduction steps
- expected behavior
- actual behavior
- root cause in source
- severity

## 5. Changes made
List exact files changed and why.

## 6. Re-test results
Show which failed cases now pass.

## 7. Remaining deviations from KiotViet
If exact parity is not reached, explain clearly.

---

# COMPLETION CRITERIA
This flow is considered acceptable only when all core cases below pass or have an explicitly justified deviation:

- 07A quick supplier return
- 07B return by purchase receipt
- 07C quantity cap enforcement
- 07E supplier refund vs payable allocation
- 07G cancel reversal
- 07H completed return immutability

If these are not solid, do not claim the supplier return flow matches KiotViet.
