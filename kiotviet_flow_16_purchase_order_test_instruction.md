# KiotViet Flow 16 — Purchase Order / Đặt hàng nhập
**Purpose:** Agent instruction to verify that the target system behaves like KiotViet for the **Đặt hàng nhập** flow, and to apply **minimal fixes only when a proven deviation is found**.

---

## 0) Scope and non-goals

This flow covers only the **purchase order / đặt hàng nhập** lifecycle:

- enable the feature
- create a purchase order
- add items manually / via import / quick-create item
- supplier selection and quick-create supplier
- discounts, landed cost / chi phí nhập hàng, supplier deposit
- expected receipt date
- save order and optional send email flow
- convert purchase order to one or many purchase receipts
- partial receipt / full receipt
- order status transitions
- payment history / receipt history on the purchase order
- search / filter / export / copy / cancel / finish
- permissions related to purchase order

This flow does **not** retest generic purchase receipt behavior already covered in Flow 02 except where it affects purchase-order conversion.

---

## 1) Ground truth reference behavior from KiotViet

Use KiotViet as the behavioral reference for this flow:

1. The feature **Đặt hàng nhập** must first be enabled in settings.
2. Users can create a purchase order from **Hàng hóa → Đặt hàng nhập → + Đặt hàng nhập**.
3. Items can be added by:
   - searching by code/name or barcode,
   - importing from Excel template,
   - quick-creating a new item from the order screen.
4. The order supports:
   - supplier selection or quick-create supplier,
   - discount,
   - landed cost / `Chi phí nhập hàng`,
   - `Tiền trả nhà cung cấp` as a deposit/payment,
   - expected receipt date / `Dự kiến ngày nhập hàng`.
5. Saving the order can be:
   - regular save (`Đặt hàng nhập`),
   - save and email supplier (`Đặt và gửi email`).
6. A single purchase order may generate **multiple purchase receipts**.
7. When generating a purchase receipt from a purchase order:
   - the system pre-fills supplier and order items,
   - user may adjust actual received quantities,
   - order status updates to **partial received** or **completed** depending on receipt progress.
8. The purchase order detail should expose:
   - receipt history (`Lịch sử nhập hàng`),
   - payment history (`Lịch sử thanh toán`).
9. The module supports:
   - search/filter/sort/export,
   - update some common fields,
   - copy / print / finish / cancel,
   - role-based permissions.

---

## 2) Agent operating rules

You must follow these rules strictly:

1. **Read source before changing anything.**
   - Find all purchase-order related routes, controllers, services, jobs, queries, UI screens, models, migrations.
   - Build a short dependency map before test execution.

2. **Do not refactor broadly.**
   - Only apply the smallest fix that corrects a proven deviation from the flow.
   - Do not rename tables, routes, or large modules unless the flow cannot work otherwise.

3. **Do not change unrelated flows.**
   - If a fix impacts receipt, supplier payable, stock, reporting, or permissions, re-test only the linked cases in this flow and note the ripple effect.

4. **Log every deviation with evidence.**
   - Include reproduction steps, expected behavior, actual behavior, impacted source files, and whether the issue is UI-only, API-only, or domain logic.

5. **Retest after every fix.**
   - Re-run only the failed cases first, then run the linked regression mini-set.

6. **Never mark PASS by visual guess.**
   - Confirm both:
     - UI/result behavior
     - persisted database state and status fields

---

## 3) Preconditions

Before executing this flow, confirm these are already working:

- Flow 01: foundation data
- Flow 02: purchase receipt
- Flow 06: supplier payables
- Flow 11: permissions
- Flow 14: lock period

If any prerequisite is broken, stop and mark this flow as **BLOCKED**.

---

## 4) Fixed test dataset

Use a deterministic dataset. Do not improvise random names.

### Warehouses
- `KHO_TONG` — Kho tổng
- `KHO_CHI_NHANH_A` — Kho chi nhánh A

### Suppliers
- `NCC001` — Công ty Minh Phát — phone `0900000002`
- `NCC002` — Công ty An Khang — phone `0900000003`

### Items
1. `SP001` — Nước suối 500ml — stock item
2. `SP002` — Bánh quy hộp — stock item
3. `SP003` — Sữa hộp 1L — stock item
4. `SP_NEW_PO` — item to be quick-created during PO

### Users
- `admin` — full permission
- `purchaser01` — can create/update PO, can create receipt from PO
- `viewer01` — view only
- `sale01` — sales permission only, no PO permission

### Starting stock
Can be zero for this flow. The focus is outstanding ordered quantity and conversion to receipts.

---

## 5) Source discovery checklist

Before testing, inspect and document:

- Purchase order tables and status enums
- Supplier deposit / payment linkage tables
- Purchase order item tables
- Relationship between purchase order and purchase receipt
- Outstanding quantity calculation logic
- Search/filter/export query layer
- Email sending or queued email stub
- Permission gates / policies / middleware
- Lock-period guard location
- Cancel / finish / copy logic
- Audit logging entry points if available

Deliver a short map like:

- route(s):
- controller(s):
- service(s):
- model(s):
- table(s):
- status enum(s):
- related UI page(s):
- jobs/events:
- policy/middleware:

Do this before running cases.

---

## 6) Canonical state model to validate

Your system should expose an equivalent state machine. Names may differ, but behavior must map closely.

Recommended mapping:

- `draft` or `saved`
- `ordered` / `open`
- `partially_received`
- `completed`
- `canceled`
- `finished` (if system separates finish from completed)

If your state names differ, create a mapping table in the report.

---

## 7) Core invariants

Agent must validate these invariants in every relevant case:

1. Creating a purchase order must **not** increase stock.
2. Converting a PO to a purchase receipt increases stock only through the receipt completion logic.
3. Outstanding ordered quantity must equal:
   `ordered_qty - total_received_qty_non_canceled`
4. One PO may link to many receipts.
5. Supplier deposit/payment entered at PO level must be traceable in payment history.
6. Canceling a PO must not silently remove already-linked valid receipts.
7. A completed PO should not allow receiving beyond ordered quantity unless the business explicitly supports over-receipt; if supported, this must be visible and consistent.
8. Search/filter/export must use the same truth source as detail screens.
9. Locked accounting period must block create/update/cancel actions as designed.
10. Permission denial must hold at both UI and API/backend levels.

---

## 8) Test cases

Run cases in order. Stop only for blockers.

### 16A — Feature activation
**Goal:** verify the purchase order feature is controlled by settings if the system supports it.

Steps:
1. Login as `admin`.
2. Open settings area corresponding to item/purchase settings.
3. Locate toggle/setting for `Đặt hàng nhập`.
4. Disable it.
5. Verify purchase-order menu and routes behavior.
6. Enable it again.

Expected:
- When disabled, user should not access PO creation through normal UI.
- Direct route/API access should also be blocked or handled safely.
- When enabled, PO menu and creation are available again.

DB / backend checks:
- feature flag or settings record persists correctly.

Fail examples:
- menu hidden but API still creates PO
- toggle has no real effect
- enabling breaks existing records

---

### 16B — Create basic purchase order
**Goal:** create a PO with supplier, items, quantities, prices.

Steps:
1. Login as `purchaser01`.
2. Open `Hàng hóa → Đặt hàng nhập → + Đặt hàng nhập`.
3. Select supplier `NCC001`.
4. Add:
   - `SP001` qty 50, cost 5000
   - `SP002` qty 20, cost 20000
5. Set expected receipt date to a future date.
6. Save order.

Expected:
- PO created successfully with unique code.
- Supplier and items persist correctly.
- Total, line subtotals, and ordered quantities are correct.
- Status maps to ordered/open state.
- No stock increase yet.

DB checks:
- one PO header row
- two PO line rows
- outstanding qty equals ordered qty for both items
- no stock movement created

---

### 16C — Add items by Excel import
**Goal:** verify import-based item insertion into PO.

Steps:
1. Download template if supported.
2. Fill import file with:
   - `SP001`, qty 30, cost 5100
   - `SP003`, qty 40, cost 18000
3. Import into a new PO.
4. Save.

Expected:
- Valid rows are added correctly.
- Quantities and costs match imported values.
- Invalid rows, if any, show explicit validation.
- Import must not silently duplicate lines unless the system explicitly merges by item.

DB checks:
- imported lines persist exactly as expected
- error rows do not partially create invalid lines unless system documents partial import behavior

---

### 16D — Quick-create item from PO
**Goal:** verify item quick-create from PO screen.

Steps:
1. Start a new PO.
2. In item search, attempt to add unknown code/name `SP_NEW_PO`.
3. Use quick-create action.
4. Fill minimal valid item data and save.
5. Add the new item to the PO.
6. Save PO.

Expected:
- Item is created in master data.
- Item is immediately usable in the current PO.
- Item becomes searchable from item list after save.

DB checks:
- item master record exists
- PO line references the newly created item id
- no duplicate item records

---

### 16E — Quick-create supplier from PO
**Goal:** verify supplier quick-create from PO screen.

Steps:
1. Start a new PO.
2. Use supplier quick-create to add `NCC_PO_NEW`.
3. Fill valid data and save.
4. Complete PO creation.

Expected:
- Supplier is created in master data.
- Newly created supplier attaches to current PO.
- Supplier is searchable from supplier list afterward.

DB checks:
- supplier record exists once
- PO header references correct supplier id

---

### 16F — Discount, landed cost, supplier deposit
**Goal:** validate commercial fields on PO.

Steps:
1. Create a PO for `NCC001` with:
   - `SP001` qty 100 @ 5000
2. Enter:
   - discount = 100000
   - landed cost / chi phí nhập hàng = 50000
   - supplier deposit / `Tiền trả nhà cung cấp` = 200000
3. Save.

Expected:
- Commercial totals are computed consistently.
- Deposit/payment is recorded and visible in payment history.
- Outstanding payable reflects deposit correctly according to the system model.
- Stock remains unchanged.

DB checks:
- PO header stores discount/extra cost/deposit
- linked payment/transaction record exists if system uses separate cash/payable record
- history tab or equivalent can display it

Important:
- If your system intentionally defers payable posting until receipt, note the design and verify history still preserves the PO deposit correctly.

---

### 16G — Save and send email
**Goal:** verify the `save and email` variant.

Steps:
1. Create a new PO with valid supplier email.
2. Use action equivalent to `Đặt và gửi email`.
3. Observe result.

Expected:
- PO is created normally.
- Email workflow is invoked.
- Attachment or order detail export is generated if supported.
- If email infrastructure is stubbed locally, the system must still log or queue the email action clearly.

Checks:
- mail queue/job/log entry exists
- PO state remains valid even if email fails
- email failure must not corrupt PO creation

---

### 16H — Create first receipt from PO (partial receipt)
**Goal:** verify a PO can generate a first partial receipt.

Steps:
1. Open an existing PO created in 16B.
2. Choose `Tạo phiếu nhập`.
3. On the receipt screen, keep:
   - `SP001` actual received 30 out of 50
   - `SP002` actual received 20 out of 20
4. Complete receipt.

Expected:
- Receipt is created and linked to the PO.
- Stock increases only for actual received quantities.
- PO status becomes equivalent to `partially_received`.
- Outstanding quantities become:
   - `SP001`: 20
   - `SP002`: 0

DB checks:
- receipt record linked to PO
- stock movement created for actual received qty
- receipt history includes this receipt
- PO line outstanding quantities recalculate correctly

---

### 16I — Create second receipt from same PO (complete remaining)
**Goal:** verify one PO supports multiple receipts.

Steps:
1. Reopen the same PO from 16H.
2. Create another receipt.
3. Receive remaining:
   - `SP001` qty 20
4. Complete receipt.

Expected:
- Second receipt links to the same PO.
- PO status becomes equivalent to `completed`.
- Outstanding quantities become zero.
- Receipt history now lists both receipts.

DB checks:
- two receipt records linked to one PO
- cumulative received qty matches ordered qty
- no negative outstanding qty

---

### 16J — Prevent over-receipt or handle it explicitly
**Goal:** verify behavior when actual received exceeds ordered quantity.

Steps:
1. Create PO for `SP003` qty 10.
2. Try to create receipt with qty 12.

Expected:
- Either system blocks the operation,
- or system supports over-receipt explicitly with clear UX and consistent persistence.

Pass criteria:
- no silent inconsistency
- outstanding qty and PO completion logic remain coherent

Fail examples:
- receipt saves 12 but outstanding becomes negative
- PO still says completed but totals are inconsistent

---

### 16K — Update editable common fields
**Goal:** verify detail edit rules on an existing PO.

Steps:
1. Open an existing PO not yet fully received.
2. Update fields such as:
   - purchaser / người đặt
   - order date
   - expected receipt date
   - note
   - status if business allows
3. Save.

Expected:
- allowed fields update successfully
- protected fields cannot be changed if linked receipts already exist and business rules forbid it
- audit/history should reflect edit if supported

DB checks:
- edited fields persist exactly once
- no accidental reset of lines, totals, or received history

---

### 16L — Cancel purchase order
**Goal:** verify cancellation rules.

Steps:
1. Create a fresh PO with no receipts and cancel it.
2. Create another PO with partial receipt and attempt cancellation.

Expected:
- For PO without receipts: cancel succeeds; state becomes canceled.
- For PO with linked receipts: system should either block cancellation or enforce documented safe behavior.
- Cancel must not delete records silently.
- Payment/deposit relations must remain traceable.

DB checks:
- canceled state persisted
- no stock movement on cancel of PO-only record
- linked receipts remain intact if cancellation is blocked or handled separately

---

### 16M — Copy / duplicate purchase order
**Goal:** verify copy behavior.

Steps:
1. Open an existing PO.
2. Use copy/duplicate action.
3. Save copied PO.

Expected:
- New PO gets new code/id.
- Lines and commercial fields are copied correctly.
- Histories and linked receipts are **not** copied as active relations.
- New PO starts as a fresh independent document.

DB checks:
- new PO header and lines exist
- no reuse of original receipt/payment linkage ids

---

### 16N — Finish / close order manually
**Goal:** verify explicit `finish` or close behavior if the system supports it.

Steps:
1. Create PO with remaining outstanding qty.
2. Use finish/close action.

Expected:
- PO becomes finished/closed.
- It should no longer be receivable unless reopened by design.
- Remaining outstanding qty is preserved for historical visibility or resolved according to design.
- Reports and search should reflect the terminal status accurately.

If the target system does not support separate finish state:
- mark as `NA` with explanation.

---

### 16O — Search / filter / sort / export
**Goal:** verify list integrity.

Steps:
1. Create several POs with different suppliers, dates, statuses.
2. Use list filters:
   - PO code
   - receipt code
   - item code/name
   - supplier
   - status
   - time range
   - creator
3. Export list.

Expected:
- list query returns records consistent with detail screens
- export row counts and statuses match filtered list
- sorting is stable and deterministic enough for user use

Checks:
- exported file includes expected records only
- no status mismatch between list and detail

---

### 16P — Payment history and receipt history
**Goal:** verify the PO detail exposes linked histories.

Steps:
1. Open PO from 16F/16H/16I.
2. Inspect:
   - receipt history tab
   - payment history tab
3. Drill into linked documents if possible.

Expected:
- receipt history lists all linked receipts with correct amounts/statuses
- payment history lists deposits/payments tied to the PO
- totals reconcile with header/outstanding information

Fail examples:
- linked history missing despite existing linked records
- history totals disagree with detail totals

---

### 16Q — Permissions
**Goal:** verify access control.

Steps:
1. Login as `viewer01`.
2. Verify view-only access.
3. Login as `sale01`.
4. Verify PO menu/create API is blocked.
5. Login as `purchaser01`.
6. Verify create/update/convert access works within permission scope.

Expected:
- UI permissions and API/backend permissions match
- hidden buttons alone are not enough; backend must reject unauthorized actions
- export/cancel/copy/finish must also respect permission granularity if implemented

---

### 16R — Locked period
**Goal:** verify lock-period enforcement.

Steps:
1. Set lock date that covers the intended PO date.
2. Attempt:
   - create PO in locked period
   - update PO in locked period
   - cancel PO in locked period
   - create receipt from PO dated in locked period if applicable
3. Repeat with unlocked future date.

Expected:
- locked period actions are blocked consistently
- unblock works outside lock range
- no partial saves or background bypass

---

## 9) Minimal regression set after any fix

After fixing any failed case, rerun:

- 16B basic creation
- 16F discount/deposit
- 16H partial receipt
- 16I second receipt / completion
- 16L cancel
- 16O search/export
- 16Q permission check for impacted action

If the fix touched receipt conversion, also re-run key cases from Flow 02.

If the fix touched supplier payment linkage, also re-run key cases from Flow 06.

---

## 10) Defect classification

Tag each defect with one primary label:

- `PO_CREATE`
- `PO_LINE_IMPORT`
- `PO_QUICK_CREATE_ITEM`
- `PO_QUICK_CREATE_SUPPLIER`
- `PO_PRICING`
- `PO_DEPOSIT_LINKAGE`
- `PO_EMAIL`
- `PO_TO_RECEIPT`
- `PO_OUTSTANDING_QTY`
- `PO_STATUS_MACHINE`
- `PO_CANCEL`
- `PO_COPY`
- `PO_FINISH`
- `PO_SEARCH_EXPORT`
- `PO_PERMISSION`
- `PO_LOCK_PERIOD`
- `PO_HISTORY_LINKAGE`

Severity:
- `S1` blocks core flow or corrupts data
- `S2` wrong status/totals/history but workaround exists
- `S3` UI mismatch or minor inconvenience
- `S4` cosmetic only

---

## 11) Report format required from the agent

Use this exact structure.

### A. Summary
- Flow: 16 — Purchase Order / Đặt hàng nhập
- Result: PASS / PASS WITH DEVIATIONS / FAIL / BLOCKED
- Tested commit:
- Environment:
- Tester agent:
- Date/time:

### B. Source map
- routes:
- controllers:
- services:
- models:
- tables:
- enums/status:
- policies/middleware:
- jobs/events:
- UI screens:

### C. Case results
For each case 16A → 16R provide:
- status: PASS / FAIL / NA / BLOCKED
- notes:
- screenshots/logs/query evidence if relevant

### D. Defects
For each defect:
- defect id
- title
- label
- severity
- reproduction
- expected
- actual
- evidence
- suspected root cause
- changed files
- fix summary

### E. Data verification
- PO headers created:
- PO lines created:
- linked receipts:
- linked payments:
- outstanding qty snapshots:
- stock movement snapshots:
- exported file checks:

### F. Regression results
- cases rerun:
- results:
- remaining risks:

### G. Final verdict
- Is the target flow behaviorally equivalent to KiotViet for normal operation?
- What deviations remain?
- Are any deviations accepted intentionally?

---

## 12) Stop conditions

Stop execution and mark **BLOCKED** if:

- foundational supplier/item creation is broken
- purchase receipt creation is broken globally
- permission system blocks all test access
- lock-period configuration cannot be controlled in test env
- database state is too inconsistent to trust subsequent results

---

## 13) Fix policy

When fixing:
1. Prefer domain/service-level fixes over UI-only masking.
2. Preserve existing IDs and records.
3. Add or update automated tests if test infrastructure exists.
4. If no test harness exists, add at least one reproducible scripted check for the defect.
5. Do not “fake” history tabs with derived UI-only data if linked records are missing in persistence.

---

## 14) Completion criteria

Flow 16 is considered complete only if:

- core cases 16B, 16F, 16H, 16I, 16L, 16P, 16Q all PASS
- no unresolved S1 or S2 defect remains
- outstanding quantity and PO status remain consistent across all linked receipts
- payment/deposit history is traceable
- search/export reflects the same truth as detail pages
- permission and lock-period checks pass

If any of the above fails, this flow is not complete.
