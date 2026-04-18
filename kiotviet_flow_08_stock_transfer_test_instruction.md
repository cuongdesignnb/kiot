# FLOW 08 — KIOTVIET STOCK TRANSFER / CHUYỂN HÀNG

## Purpose
This instruction tells the agent to audit, test, and minimally repair the **stock transfer / chuyển hàng** flow so the application behaves as close as possible to KiotViet.

This flow must be tested **independently**. Do not expand into other flows except when a prerequisite from an earlier flow is required to execute this one.

---

## KiotViet reference behavior to match
Use these behaviors as the reference contract:

1. KiotViet supports creating a **stock transfer document** from **Hàng hóa → Chuyển hàng**.
2. The user can add items to the transfer by:
   - searching products,
   - scanning barcodes,
   - importing from an Excel file.
3. The transfer must capture at least:
   - source branch/warehouse,
   - destination branch/warehouse,
   - transfer time,
   - transfer code,
   - note.
4. Clicking **Hoàn thành** creates the transfer in status **Đang chuyển**.
5. Clicking **Lưu tạm** saves the transfer as a draft-like document that is not yet treated as an active in-transit transfer.
6. KiotViet blocks creating the transfer if the transfer date falls inside a **locked accounting/book-closing period**.
7. At the destination branch/warehouse, the user opens a transfer in status **Đang chuyển** and performs **Nhận hàng**.
8. During receipt, the system defaults the actual received quantity to the full transferred quantity, but the user can enter a lower **Thực tế** quantity.
9. If actual received quantity differs from transferred quantity, the user must provide a **receipt note / reason**.
10. The **receipt time cannot be earlier than the transfer time**.
11. Completing receipt automatically updates inventory at both source and destination.
12. Canceling a transfer in status **Đang chuyển** or **Đã nhận** must automatically restore inventory to the source side according to the effective transfer/receipt state.
13. KiotViet supports transfer of:
   - normal products,
   - lot/expiry-managed items,
   - serial/IMEI-managed items.
14. For lot-managed items, the transfer flow must allow selecting lot(s) and quantities by lot.
15. For serial/IMEI-managed items, the transfer flow must allow selecting/scanning exact serials/IMEIs.
16. KiotViet supports list/search/filter for transfer documents and supports viewing/printing transfer details.
17. Transfer behavior is warehouse-aware. KiotViet supports moving items between warehouses inside the same branch and also between branches, depending on store setup.
18. Stock transfer must leave a traceable document with source, destination, quantities transferred, quantities received, status, timestamps, actor, and notes.

---

## Primary references used for this flow
Treat the following KiotViet references as the contract for expected behavior:

- Retail stock transfer guide: `retail-hang-hoa/chuyen-hang`
- Retail warehouse management guide: `retail-thiet-lap/quan-ly-kho-hang`
- Legacy transfer guide: `huong-dan-chuyen-hang/tao-phieu-chuyen-hang`

Do **not** assume your app is correct only because inventory visually moves on the UI. Audit the actual document state machine and inventory updates.

---

## Scope of this flow
You are only testing and repairing:

- create stock transfer document
- draft vs completed transfer creation
- source/destination selection
- item entry methods (search / barcode / import if implemented)
- receipt at destination
- partial receipt behavior
- transfer time vs receipt time validation
- stock movement and inventory effect
- cancellation behavior
- list/search/filter/print if present
- lot / serial / IMEI handling if the product model supports them
- warehouse-aware permissions and visibility if transfer depends on them

Out of scope unless required as prerequisite:

- purchase receipt
- supplier return
- customer return
- stocktake adjustments unrelated to transfer
- full accounting close engine beyond date lock validation

---

## Mandatory working rules for the agent

1. **Read the source first.** Do not patch blindly.
2. **Map the transfer flow** before changing code:
   - routes
   - controller / handler
   - service / use case
   - inventory movement path
   - source inventory deduction moment
   - destination inventory addition moment
   - database tables
   - status values and transitions
   - UI screens involved
3. **Reproduce first, then fix.** Every code change must correspond to a reproduced or proven defect.
4. **Patch minimally.** Do not refactor unrelated modules.
5. **Preserve earlier flows.** Re-run the minimum regression set needed for:
   - foundation data,
   - purchase receipt,
   - sales invoice,
   - customer return,
   - supplier return,
   - stock visibility by warehouse.
6. **Never hide a bug with front-end validation only** if the rule must also be enforced by backend/API.
7. **Do not invent KiotViet behavior** when uncertain. If exact parity is impossible, record the deviation explicitly.
8. **Do not flatten the state machine.** A transfer draft, an in-transit transfer, a received transfer, and a canceled transfer must remain distinguishable.

---

## Required preconditions
Before executing this flow, confirm these prerequisites exist and work:

- Foundation data from Flow 01 exists:
  - at least two warehouses or two branch/warehouse endpoints that can transfer stock between each other
  - at least two stock-tracked products
- Purchase receipt flow from Flow 02 can create stock in the source warehouse.
- Sales flow from Flow 03 and return flows from Flow 05 / Flow 07 have not corrupted per-warehouse stock balances.
- Warehouse setup allows transfer or internal movement.

If a prerequisite is missing, create the smallest valid setup and document it.

---

## Fixed seed data for testing
Use deterministic data. Do not use random names or random prices.

### Warehouses / locations
- `KHO_TONG` — Kho tổng (source)
- `KHO_PHU` — Kho phụ / Kho nhận (destination)

### Products
1. `SP001` — Nước suối 500ml
   - transfer stock available at source: 20
2. `SP002` — Bánh quy hộp
   - transfer stock available at source: 10

If the app supports lot/serial inventory, create optional advanced products:

3. `SP_LOT_01` — Sữa hộp theo lô
   - lot-managed
   - two lots available at source:
     - LOTA: qty 5
     - LOTB: qty 7

4. `SP_SERIAL_01` — Điện thoại test serial
   - serial-managed
   - three serials available at source:
     - SER001
     - SER002
     - SER003

### Expected opening stock before Flow 08 tests
At source `KHO_TONG`:
- `SP001`: 20
- `SP002`: 10
- `SP_LOT_01`: LOTA=5, LOTB=7 (if supported)
- `SP_SERIAL_01`: SER001, SER002, SER003 (if supported)

At destination `KHO_PHU`:
- all above quantities = 0 before transfer tests unless explicitly set otherwise.

---

## Read-the-code checklist before testing
The agent must identify and record:

- transfer document table/model
- transfer line table/model
- stock movement table/model if any
- lot allocation table if any
- serial allocation table if any
- draft/in-transit/received/canceled status representation
- transfer number/code generation logic
- lock-date validation path
- receipt update logic
- cancel logic
- print/export/list API endpoints if any

If the app does not separate transfer header and lines, record the actual design.

---

## Expected state model
Map the app’s actual status values to this conceptual model:

- `draft` / `saved-temporarily`  → equivalent to **Lưu tạm**
- `in_transit`                  → equivalent to **Đang chuyển**
- `received`                    → equivalent to **Đã nhận**
- `canceled`                    → equivalent to **Đã hủy**

If your app uses different names, document the mapping clearly.

---

## Test cases

### 08A — Create transfer and save as draft
**Goal:** verify `Lưu tạm` behavior.

Steps:
1. Open stock transfer screen.
2. Create a transfer from `KHO_TONG` to `KHO_PHU`.
3. Add:
   - `SP001` qty 3
   - `SP002` qty 2
4. Save as draft / temporary.

Expected:
- transfer document is created,
- status maps to `draft`,
- source stock is **not** incorrectly treated as fully sent unless your documented implementation intentionally reserves stock,
- destination stock does **not** increase,
- document can be reopened for completion or edit,
- audit trail records creation.

Fail examples:
- draft already deducts stock without a documented reservation model,
- draft is indistinguishable from completed in-transit transfer,
- destination stock changes on draft save.

---

### 08B — Complete transfer to in-transit state
**Goal:** verify `Hoàn thành` creates an active transfer.

Steps:
1. Create a new transfer from `KHO_TONG` to `KHO_PHU`.
2. Add:
   - `SP001` qty 4
   - `SP002` qty 1
3. Set transfer date/time to a valid open period.
4. Click complete.

Expected:
- status becomes `in_transit`,
- source and destination are stored correctly,
- transfer code is generated/stored,
- source stock effect matches the app’s transfer model and remains internally consistent,
- destination stock is not treated as fully received until actual receipt,
- document is visible in transfer list.

Required parity notes:
- KiotViet explicitly states completed creation lands in **Đang chuyển**.
- Agent must confirm whether source stock deduction happens at completion time, and then ensure cancel/receipt logic is consistent with that model.

---

### 08C — Date lock validation
**Goal:** verify transfer cannot be created in a locked period.

Steps:
1. Identify or configure a locked closing/book period if the app supports it.
2. Attempt to create a transfer with transfer date inside the locked period.

Expected:
- backend/API rejects the request,
- UI shows a clear message,
- no transfer document is created.

If the app has no lock-date engine:
- record `NA — feature not implemented`,
- do not fake parity.

---

### 08D — Receive full quantity at destination
**Goal:** verify standard `Nhận hàng` flow.

Precondition:
- an `in_transit` transfer exists from `KHO_TONG` to `KHO_PHU` with:
  - `SP001` qty 4
  - `SP002` qty 1

Steps:
1. Open the transfer from the destination context.
2. Trigger receive action.
3. Accept default actual quantities equal to transferred quantities.
4. Confirm receipt.

Expected:
- status becomes `received`,
- actual received quantities are stored,
- destination stock increases by the received quantities,
- source stock remains consistent with the chosen stock movement model,
- timestamps and receiving actor are stored,
- document reflects both transfer and receipt information.

Fail examples:
- status remains in transit after successful receipt,
- destination stock not updated,
- duplicate receipt possible and double-adds stock.

---

### 08E — Partial receipt with required note
**Goal:** verify actual received quantity lower than transferred quantity.

Precondition:
- create a new in-transit transfer:
  - `SP001` qty 5

Steps:
1. Open the transfer at destination.
2. Set actual received quantity for `SP001` to 3.
3. Leave receipt note empty and try to confirm.
4. Then enter a reason/note and confirm.

Expected:
- without note, the app blocks confirmation or clearly enforces the rule,
- with note, receipt succeeds,
- received quantity is stored as 3,
- inventory updates reflect only actual received quantity at destination,
- discrepancy is traceable in document details.

Fail examples:
- partial receipt accepted without required reason while claiming KiotViet parity,
- destination receives full 5 although actual is 3,
- discrepancy is lost from the audit trail.

---

### 08F — Receipt time earlier than transfer time
**Goal:** verify time-order validation.

Steps:
1. Create an in-transit transfer with transfer time `T1`.
2. Attempt to receive with receipt time earlier than `T1`.

Expected:
- backend/API rejects receipt,
- no stock update is applied,
- document remains in transit,
- UI shows a clear validation message.

---

### 08G — Cancel an in-transit transfer
**Goal:** verify cancel before receipt.

Precondition:
- an `in_transit` transfer exists.

Steps:
1. Cancel the transfer.
2. Review stock and document state.

Expected:
- status becomes `canceled`,
- source stock is restored/normalized according to the chosen stock model,
- destination stock remains unaffected by receipt,
- the transfer is no longer receivable,
- audit trail records cancellation actor/time.

Fail examples:
- canceled transfer still receivable,
- source stock not restored,
- cancel silently deletes the document instead of preserving history.

---

### 08H — Cancel a received transfer
**Goal:** verify cancel after receipt.

Precondition:
- a `received` transfer exists.

Steps:
1. Cancel the transfer.
2. Review stock in both source and destination.

Expected:
- status becomes `canceled`,
- destination stock added by the receipt is reversed,
- source stock is restored to the pre-transfer logical level,
- the final inventory effect after cancel is as if the transfer never happened,
- the document remains visible in history as canceled.

Fail examples:
- destination stock remains after cancel,
- source stock restores twice,
- document is hard-deleted.

---

### 08I — Transfer quantity cannot exceed available stock
**Goal:** verify negative or over-transfer prevention.

Steps:
1. Attempt to create a transfer from `KHO_TONG` with:
   - `SP001` qty 999 when available is only 20.
2. Also test exact boundary with qty equal to available stock.

Expected:
- over-transfer is blocked by backend/API,
- exact available quantity is allowed if business rules permit,
- no negative stock appears due to transfer unless the app explicitly supports negative stock and documents it.

If the app intentionally allows negative stock:
- record the deviation explicitly,
- make sure it is consistent across receipt/cancel logic.

---

### 08J — Transfer list/search/filter and detail view
**Goal:** verify operational usability and traceability.

Steps:
1. Create several transfer documents in different statuses.
2. Test list view.
3. Search by:
   - transfer code,
   - source/destination,
   - product,
   - note if supported.
4. Filter by status if supported.
5. Open detail view.

Expected:
- transfer list includes created documents,
- search/filter behaves deterministically,
- detail view shows source, destination, lines, statuses, quantities transferred, quantities received, timestamps, notes.

If print/export exists:
- verify the action loads the correct document,
- verify the printed/exported content references the correct source/destination info.

---

### 08K — Lot-managed transfer (optional advanced)
**Run only if lot-managed inventory exists.**

Steps:
1. Create transfer for `SP_LOT_01` from `KHO_TONG` to `KHO_PHU`.
2. Select:
   - LOTA qty 2
   - LOTB qty 3
3. Complete transfer.
4. Receive full quantity.

Expected:
- selected lots are stored per line/allocation,
- destination receives the same lots and quantities,
- source lot balances decrease correctly,
- cancel reverses by lot correctly.

Fail examples:
- lot data collapsed into generic stock,
- receiving changes lot identity,
- cancel restores wrong lot.

---

### 08L — Serial/IMEI transfer (optional advanced)
**Run only if serial-managed inventory exists.**

Steps:
1. Create transfer for `SP_SERIAL_01` from `KHO_TONG` to `KHO_PHU`.
2. Select exact serials:
   - SER001
   - SER002
3. Complete transfer.
4. Receive transfer.
5. Attempt to transfer the same serial again from source if it should no longer be there.

Expected:
- exact serials are recorded,
- destination receives those exact serials,
- source can no longer reuse transferred serials incorrectly,
- cancel restores the exact serial ownership/location.

---

### 08M — Permission guard for transfer flow
**Goal:** verify warehouse/transfer permissions if the app implements them.

Steps:
1. Log in with a user lacking transfer permission.
2. Attempt to create/edit/cancel/receive transfer.
3. Log in with a warehouse-limited user.
4. Attempt transfer involving unauthorized warehouse.

Expected:
- unauthorized user cannot create/edit/cancel/receive,
- warehouse-limited user can only access allowed warehouses,
- backend/API also enforces the restriction.

If permission system is not implemented at this level, record deviation explicitly.

---

## Data checks after each case
After every test case, verify:

1. **Document state**
   - header status
   - line quantities
   - actual received quantities
   - source/destination IDs
   - timestamps
   - notes

2. **Inventory correctness**
   - source on-hand
   - destination on-hand
   - lot balances if applicable
   - serial ownership/location if applicable

3. **Auditability**
   - actor / user
   - created at / updated at
   - canceled at / canceled by if applicable

4. **Idempotency / duplicate action safety**
   - repeated receive should not double-add stock,
   - repeated cancel should not double-reverse stock.

---

## Stock consistency expectations
The agent must determine which of these models the app uses and ensure consistency:

### Model A — deduct source on transfer completion, add destination on receipt
If the app uses this model:
- completing transfer reduces source,
- receiving adds destination,
- cancel of in-transit restores source,
- cancel of received subtracts destination and restores source.

### Model B — no actual stock movement until receipt
If the app uses this model:
- in-transit may only reserve/flag goods,
- receiving performs the actual stock move,
- cancel just clears the reservation/flag.

KiotViet documentation strongly suggests transfer creation and receipt are distinct states and that cancellation of both in-transit and received transfers must correctly restore stock consistency. The agent must preserve a coherent model and document which one the app uses.

---

## Allowed fixes
The agent may minimally change:

- transfer status transition logic
- transfer validation rules
- receipt validation rules
- stock movement posting logic
- cancel reversal logic
- lot/serial allocation persistence
- list/search/filter bugs
- print data binding bugs
- permission enforcement for transfer actions

The agent must **not**:

- redesign the whole warehouse engine,
- rewrite unrelated purchase/sales modules,
- change pricing or debt logic unless transfer flow directly depends on it,
- silently remove draft/in-transit/received distinctions.

---

## Suggested technical assertions
Wherever possible, add or update tests for:

- create transfer draft
- complete transfer to in-transit
- reject transfer in locked date
- receive full quantity
- receive partial quantity with required note
- reject receipt earlier than transfer time
- cancel in-transit transfer restores stock
- cancel received transfer restores both sides correctly
- reject over-transfer
- lot transfer roundtrip
- serial transfer roundtrip
- unauthorized user blocked

Prefer backend/integration tests for stock correctness and a small number of E2E tests for UI behavior.

---

## Defect reporting format
For each failed case, record:

- Case ID
- Title
- Steps to reproduce
- Expected result
- Actual result
- Root cause in source
- Files changed
- Minimal patch summary
- Retest result
- Any remaining deviation vs KiotViet

---

## Final report template
The agent must end with a report in this structure:

### Flow audited
- Flow 08 — Stock Transfer / Chuyển hàng

### Source map
- routes:
- controllers/handlers:
- services/use cases:
- models/tables:
- stock movement path:
- status mapping:

### Preconditions used
- warehouses:
- products:
- opening stock:
- users:

### Case results
- 08A: PASS / FAIL / NA
- 08B: PASS / FAIL / NA
- 08C: PASS / FAIL / NA
- 08D: PASS / FAIL / NA
- 08E: PASS / FAIL / NA
- 08F: PASS / FAIL / NA
- 08G: PASS / FAIL / NA
- 08H: PASS / FAIL / NA
- 08I: PASS / FAIL / NA
- 08J: PASS / FAIL / NA
- 08K: PASS / FAIL / NA
- 08L: PASS / FAIL / NA
- 08M: PASS / FAIL / NA

### Defects found
- DEF-08-01:
- DEF-08-02:
- ...

### Files changed
- path/file1
- path/file2

### Retest summary
- cases re-run:
- results:

### Remaining deviations vs KiotViet
- deviation 1
- deviation 2

### Overall verdict
- PASS WITH NO DEVIATION
- PASS WITH MINOR DEVIATION
- FAIL — REQUIRES MORE WORK

---

## Execution order for the agent
Follow this exact order:

1. Read and map source.
2. Verify prerequisites.
3. Seed deterministic stock data.
4. Run 08A → 08J first.
5. Run 08K / 08L only if lot/serial support exists.
6. Run 08M if permission system exists.
7. Patch only proven defects.
8. Re-run failed cases and the minimum regression set.
9. Produce the final report.

