# KiotViet Flow 17 — Delivery / Waybill / Return-to-Sender
**Purpose:** Agent instruction to verify that the target system behaves like KiotViet for the **Giao hàng / Vận đơn / Chuyển hoàn** flow, and to apply **minimal fixes only when a proven deviation is found**.

---

## 0) Scope and non-goals

This flow covers only the delivery lifecycle attached to a sales invoice or sales order:

- enable delivery feature
- create a delivery order from checkout
- choose integrated carrier vs self-delivery partner
- recipient / pickup address defaults and address-book behavior
- package information defaults (weight / dimensions)
- carrier suggestion and shipping service selection
- create waybill and bind it to invoice/order
- track waybill list and waybill detail
- manual status update for self-delivery partners
- cancel waybill
- create another waybill for the same invoice after cancellation/failure
- bulk update / export waybills
- print shipping slip
- return-to-sender behavior and the related auto-update setting
- delivery cashflow linkage at a high level

This flow does **not** deeply retest:
- sales invoice basics (Flow 03)
- cashbook posting (Flow 10)
- customer returns accounting (Flow 05)

Only verify the integration points that touch this delivery flow.

---

## 1) Ground truth reference behavior from KiotViet

Use KiotViet as the behavioral reference for this flow:

1. Delivery can be enabled in settings / utility area and can work with either **integrated carriers (Cổng KiotViet)** or **self-delivery partners (Tự giao hàng)**.
2. When creating a delivery invoice/order, if the customer already has enough delivery information, the system auto-fills recipient data.
3. Users can add alternate recipient addresses; KiotViet stores them for reuse, and one customer can have up to 20 saved delivery addresses beyond the main address.
4. Pickup address defaults to the branch address; users may choose another pickup address or add new pickup addresses.
5. Package defaults:
   - weight = item weight × quantity, or 500g if weight is not declared on the item
   - dimensions default to 10 × 10 × 10 cm and can be changed
6. For integrated carriers, the system suggests available carrier services and fees based on shipping info and shows options such as declared value / payer / notes.
7. After payment, the delivery request is sent automatically to the selected integrated carrier.
8. Waybill management exists in **Đơn hàng → Vận đơn**, with filters, detail view, delivery history, and shipping detail.
9. For self-delivery partners, delivery status and fee fields are updated manually by the store.
10. Canceling a waybill behaves differently:
    - self-delivery partner: updates status only inside KiotViet
    - integrated carrier: sends cancellation request to the carrier and usually succeeds only before pickup
11. If a waybill is canceled or failed, KiotViet supports creating another waybill for the same invoice.
12. KiotViet has a setting for return-to-sender behavior:
    - auto-update immediately on status `Đã chuyển hoàn`
    - or wait until the store confirms receipt of the returned parcel
13. Delivery cashflow / COD monitoring is exposed in the delivery module, with summary and flow tabs.

---

## 2) Agent operating rules

You must follow these rules strictly:

1. **Read source before changing anything.**
   - Find all delivery-related routes, controllers, services, jobs, models, settings, carrier adapters, webhook handlers, UI pages, and print templates.

2. **Do not refactor broadly.**
   - Only apply the smallest fix that corrects a proven deviation from the flow.

3. **Do not change unrelated flows.**
   - If a fix touches invoice posting, returns, cashbook, or customer master data, note the ripple effect and re-test only the linked mini-set.

4. **Log every deviation with evidence.**
   - Include reproduction, expected behavior, actual behavior, affected files, and whether the bug is UI-only, API-only, sync-only, or domain-level.

5. **Retest after every fix.**
   - Re-run only failed cases first, then run the regression mini-set.

6. **Never mark PASS by visual guess.**
   - Confirm both UI behavior and persisted data / linked records / status history.

---

## 3) Preconditions

Before executing this flow, confirm these are already working:

- Flow 01: foundation data
- Flow 03: sales invoice
- Flow 10: cashbook (only for linked payment checks)
- Flow 11: permissions
- Flow 14: lock period

If any prerequisite is broken, stop and mark this flow as **BLOCKED**.

---

## 4) Fixed test dataset

Use a deterministic dataset. Do not improvise random names.

### Branches / Warehouses
- `CN_A` — Chi nhánh A — default pickup address present
- `CN_B` — Chi nhánh B — used to verify branch-scoped defaults if needed

### Customers
1. `KH_DEL_01` — Nguyễn Văn A — phone `0900000011`
   - main address fully populated
2. `KH_DEL_02` — Trần Thị B — phone `0900000012`
   - incomplete address to test no-service / missing-address cases

### Delivery partners
- Integrated carrier mode enabled (via KiotViet-style gateway equivalent)
- Self-delivery partner `GH_RIENG_01` — "Đối tác giao hàng riêng A"

### Items
1. `SP_DEL_01` — stock item with declared weight 250g
2. `SP_DEL_02` — stock item with no declared weight
3. `SP_DEL_03` — stock item for multi-line order

### Users
- `admin` — full permission
- `sale_delivery01` — can create delivery invoice/order
- `ops_delivery01` — can view/manage waybills
- `viewer01` — view only

---

## 5) Source discovery checklist

Before testing, inspect and document:

- delivery feature flags / settings
- invoice/order fields related to shipping
- waybill tables
- shipping-address tables
- pickup-address tables
- delivery partner tables
- carrier service quote adapter
- carrier booking adapter
- cancellation adapter
- webhook or polling sync handlers
- delivery status enum / state machine
- print template path for shipping slip
- cashflow / COD linkage source
- permission gates / middleware
- lock-period guards
- batch update / export query layer

Deliver a short map:

- routes:
- controllers:
- services:
- adapter(s):
- jobs/webhooks:
- models:
- tables:
- enums/status:
- policy/middleware:
- UI screens:
- print templates:

Do this before running cases.

---

## 6) Canonical state model to validate

Your system should expose an equivalent state model. Names may differ, but behavior must map closely.

Suggested mapping:

- `pending`
- `waiting_pickup`
- `in_transit`
- `delivered`
- `returning`
- `returned`
- `canceled`
- `failed`

For self-delivery partners, manual updates may drive the state.
For integrated carriers, sync/webhook/polling may drive the state.

If names differ, create a mapping table in the report.

---

## 7) Core invariants

Validate these invariants in every relevant case:

1. Creating a delivery order/waybill must not duplicate the invoice/order.
2. One invoice/order may have zero or one active waybill at a time unless the system explicitly supports multiple active parcels.
3. If a new waybill is created for the same invoice after cancellation, the old waybill must remain historically traceable.
4. Recipient address and pickup address must be stored and re-usable according to the configured UX.
5. Package default values must be deterministic.
6. Integrated carrier cancellation and self-delivery cancellation must not be treated as the same backend operation.
7. Waybill list, waybill detail, and invoice/order detail must agree on status.
8. Return-to-sender updates must follow the configured setting.
9. Delivery cashflow / COD totals must be traceable to delivery records, not guessed in UI.
10. Permission denial must hold in both UI and backend APIs.
11. Locked periods must block actions consistently if the system applies lock rules to delivery creation/update/cancel from financial documents.

---

## 8) Test cases

Run cases in order. Stop only for blockers.

### 17A — Activate delivery feature
**Goal:** verify delivery feature toggle and gateway mode.

Steps:
1. Login as `admin`.
2. Open settings / utility area for delivery.
3. Disable delivery feature or equivalent.
4. Verify checkout no longer exposes delivery actions in normal UI.
5. Re-enable feature.
6. Verify integrated gateway option and self-delivery option visibility.

Expected:
- Disabling hides/blocks normal delivery flow.
- Direct route/API creation must also be blocked or safely rejected.
- Enabling restores access without breaking existing waybills.

DB / backend checks:
- feature flag persists
- gateway activation setting persists

---

### 17B — Create self-delivery order from sales invoice
**Goal:** verify delivery creation using a self-delivery partner.

Steps:
1. Login as `sale_delivery01`.
2. Create a sales invoice with customer `KH_DEL_01`.
3. Switch to delivery mode.
4. Choose `Tự giao hàng`.
5. Select self-delivery partner `GH_RIENG_01`.
6. Complete invoice / payment.

Expected:
- Invoice created successfully.
- Waybill/delivery record created and linked to the invoice.
- Self-delivery partner stored on the delivery record.
- Status begins at pending/waiting stage.
- No external carrier sync job should run unless your system intentionally uses one.

DB checks:
- invoice exists once
- one linked delivery/waybill record exists
- partner type = self-delivery
- current delivery status saved

---

### 17C — Create integrated-carrier waybill from sales invoice
**Goal:** verify delivery booking through integrated carrier flow.

Steps:
1. Create a new sales invoice for `KH_DEL_01`.
2. Enable delivery.
3. Choose integrated carrier mode.
4. Ensure recipient has complete address.
5. Choose one suggested service.
6. Complete payment.

Expected:
- System shows available services and fees.
- Selected service is saved.
- After payment, booking/request is sent to carrier integration layer.
- Waybill code / external reference is stored if booking succeeds.
- Invoice remains valid even if carrier booking fails; failure must be explicit and recoverable.

DB / backend checks:
- one linked delivery record exists
- carrier service id / label persisted
- external booking request log / job / response trace exists
- no duplicate booking on refresh/retry unless explicitly retried

---

### 17D — Recipient address auto-fill and address book
**Goal:** verify recipient address behavior.

Steps:
1. Use customer `KH_DEL_01` with full address.
2. Start a delivery invoice.
3. Confirm recipient info auto-fills.
4. Add a new alternate delivery address.
5. Save and complete the order.
6. Start another delivery order for the same customer.
7. Verify saved alternate address is reusable.

Expected:
- Full customer info auto-fills recipient section.
- Alternate address is saved in the customer delivery address book.
- Reuse works on later orders.
- Existing address links do not overwrite the main customer address incorrectly.

Checks:
- saved alternate address exists in related address table / child collection
- invoice references the chosen recipient address snapshot
- main customer record remains consistent

---

### 17E — Pickup address defaults and maintenance
**Goal:** verify pickup-address defaults and additional pickup addresses.

Steps:
1. Start a delivery order from branch `CN_A`.
2. Verify pickup address defaults to branch info.
3. Add a new pickup address.
4. Save order.
5. Start another delivery order from the same branch.
6. Verify new pickup address is reusable.

Expected:
- branch address is default pickup address
- additional pickup addresses can be created and reused
- deletion of pickup address should be blocked if linked data exists, or handled safely

Checks:
- pickup address collection persists
- order stores chosen pickup snapshot
- branch master data is not unintentionally modified by editing alternate pickup address

---

### 17F — Package default values
**Goal:** verify deterministic package defaults.

Steps:
1. Create delivery order with:
   - `SP_DEL_01` qty 2 (declared weight 250g)
   - `SP_DEL_02` qty 1 (no declared weight)
2. Observe package weight and dimensions defaults.

Expected:
- For weighted item scenario, default weight logic is consistent with the system design and traceable.
- For items with no declared weight, default fallback behaves consistently.
- Default dimensions are prefilled and editable.

Pass criteria:
- defaults are deterministic and editable
- saved package info matches chosen values
- later carrier quote uses the saved package info, not stale defaults

---

### 17G — No service until address is complete
**Goal:** verify shipping service availability depends on complete address info.

Steps:
1. Use `KH_DEL_02` with incomplete address.
2. Start integrated-carrier delivery flow.
3. Observe service quote area.
4. Complete the address.
5. Requote.

Expected:
- before full address, system should not falsely show valid carrier services
- after full address, service list can be quoted
- error/help text should be explicit enough for operator correction

---

### 17H — Manual status update for self-delivery partner
**Goal:** verify manual status editing for non-integrated/self-delivery.

Steps:
1. Create self-delivery waybill from 17B.
2. Open invoice or delivery detail.
3. Update:
   - waybill code
   - delivery fee
   - delivery status
4. Save.

Expected:
- manual fields are editable for self-delivery partner
- status history or last-updated info is traceable
- invoice/detail/list reflect the same status

DB checks:
- editable fields persist
- if status history table exists, an entry is appended
- list and detail use same source of truth

---

### 17I — Waybill list, filtering, detail, and export
**Goal:** verify centralized waybill management.

Steps:
1. Open `Đơn hàng → Vận đơn`.
2. Search/filter by:
   - invoice code
   - customer
   - status
   - time range
   - partner/carrier
3. Open a waybill detail.
4. Export filtered list.

Expected:
- list returns records consistent with invoice/detail pages
- detail shows shipping status/history and shipping detail
- export row count and fields match current filters

Checks:
- list query source equals detail truth source
- no status mismatch between invoice and waybill list
- export does not include records outside filter scope

---

### 17J — Create another waybill for the same invoice
**Goal:** verify re-book / create another waybill after cancel or failure.

Steps:
1. Find an invoice with delivery status `pending`, `waiting`, or `canceled`.
2. Trigger `+ Tạo vận đơn khác` or equivalent.
3. Confirm old waybill cancelation/archival if prompted.
4. Create new waybill.

Expected:
- a new waybill is linked to the same invoice
- the old waybill remains historically traceable
- only the intended active waybill remains active
- invoice still points to the correct current delivery context

DB checks:
- multiple waybill records linked historically to one invoice if supported
- active/current pointer is correct
- old record status is not silently overwritten without history

---

### 17K — Cancel self-delivery waybill
**Goal:** verify self-delivery cancel behavior.

Steps:
1. Open a self-delivery waybill.
2. Cancel it.

Expected:
- system updates status on the local delivery record
- no external carrier cancellation call is fired
- invoice remains valid and historically linked to canceled waybill

Checks:
- local status becomes canceled
- no external API log generated
- cancel action is audited if audit log exists

---

### 17L — Cancel integrated-carrier waybill
**Goal:** verify integrated cancel behavior.

Steps:
1. Open an integrated carrier waybill that has not yet been picked up.
2. Cancel it.

Expected:
- system sends cancel request to carrier adapter
- local status updates only according to adapter response/pending sync logic
- if cancellation is too late, system should surface failure clearly

Checks:
- outbound cancel request log exists
- local status and external sync result are traceable
- repeated cancel does not create duplicate requests unless explicitly retried

---

### 17M — Bulk update and bulk payment on waybill list
**Goal:** verify list-level operations.

Steps:
1. On waybill list, select multiple waybills.
2. Use bulk update if supported.
3. For self-delivery partner waybills of the same partner, use bulk payment / settlement action if supported.

Expected:
- bulk status update applies only to eligible records
- integrated-carrier records should not allow manual bulk status mutation if the system relies on automatic sync
- settlement action should respect partner grouping and not mix incompatible records

Note:
- Do not deeply validate cashbook accounting here; only verify linkage and action availability.

---

### 17N — Print shipping slip
**Goal:** verify shipping slip print behavior.

Steps:
1. Open invoice detail and print shipping slip.
2. Open waybill detail and print shipping slip again.
3. If auto-print exists, enable it and create another delivery order.

Expected:
- print action is available from the expected screens
- generated printout references the correct invoice/waybill
- auto-print, if supported, triggers after checkout without creating duplicate documents

Checks:
- print template receives correct payload
- no stale waybill code from prior order
- if multiple waybills exist historically, print uses the active/current one unless user explicitly selects another

---

### 17O — Return-to-sender (RTS) behavior and setting
**Goal:** verify return-to-sender update behavior.

Steps:
1. Enable the setting equivalent to:
   - do not auto-update data when order becomes `Đã chuyển hoàn`
2. Simulate or inject a return-to-sender status from carrier/self-delivery.
3. Observe local data.
4. Confirm store receipt of returned parcel.
5. Observe local updates.
6. Repeat with auto-update setting disabled (meaning auto-update is immediate).

Expected:
- behavior follows setting exactly
- delayed mode waits for store confirmation before updating local business data
- immediate mode updates as soon as returned status is received
- update is idempotent and does not apply twice

Checks:
- setting persisted
- returned-status event log exists
- business-side adjustments are applied once

---

### 17P — Delivery cashflow / COD overview linkage
**Goal:** verify delivery module exposes COD/fee tracking at a high level.

Steps:
1. Open delivery cashflow / statistics area if present.
2. Inspect summary and flow tabs for the waybills created above.

Expected:
- summary counts/totals reference real waybill data
- outstanding COD / fee tracking is traceable
- clicking through should open related delivery/invoice records

Note:
- Do not fully reconcile accounting here; that belongs to Flow 10 and any future logistics settlement flow.
- This case only verifies linkage and consistency of displayed delivery-finance data.

---

### 17Q — Permissions
**Goal:** verify access control.

Steps:
1. Login as `viewer01`.
2. Verify read-only access if allowed.
3. Login as `sale_delivery01`.
4. Verify creation access but limited admin operations if applicable.
5. Login as `ops_delivery01`.
6. Verify list/detail/cancel/update access according to role.
7. Attempt restricted API calls directly.

Expected:
- UI permissions and backend permissions match
- hidden buttons alone are not enough; backend must reject unauthorized actions
- print/export/cancel/bulk update must respect permission scope

---

### 17R — Locked period behavior
**Goal:** verify lock-period handling where delivery changes derive from financial documents.

Steps:
1. Set lock date that covers the invoice/order date.
2. Attempt:
   - create delivery from locked invoice/order
   - update shipping fields on locked record
   - cancel waybill tied to locked record
3. Repeat outside lock range.

Expected:
- locked actions are blocked consistently if the system ties them to locked business documents
- outside lock range works normally
- no partial local changes or orphaned external booking records

If your system intentionally allows some shipping-only edits after lock:
- document the design clearly and verify it is consistently enforced.

---

## 9) Minimal regression set after any fix

After fixing any failed case, rerun:

- 17B self-delivery creation
- 17C integrated creation
- 17H self-delivery manual update
- 17I waybill list/detail/export
- 17J create another waybill
- 17K or 17L depending on affected partner type
- 17O RTS setting behavior
- 17Q permissions

If invoice posting was touched, re-run key cases from Flow 03.
If cashflow linkage was touched, re-run the smallest linked checks from Flow 10.
If return handling touched stock/returns data, re-run the smallest linked checks from Flow 05.

---

## 10) Defect classification

Tag each defect with one primary label:

- `DELIVERY_FEATURE_FLAG`
- `DELIVERY_SELF_CREATE`
- `DELIVERY_INTEGRATED_CREATE`
- `DELIVERY_ADDRESS_AUTOFILL`
- `DELIVERY_PICKUP_ADDRESS`
- `DELIVERY_PACKAGE_DEFAULTS`
- `DELIVERY_SERVICE_QUOTE`
- `DELIVERY_STATUS_SELF`
- `DELIVERY_STATUS_SYNC`
- `DELIVERY_WAYBILL_LIST`
- `DELIVERY_WAYBILL_DETAIL`
- `DELIVERY_REBOOK`
- `DELIVERY_CANCEL_SELF`
- `DELIVERY_CANCEL_INTEGRATED`
- `DELIVERY_PRINT`
- `DELIVERY_RTS_SETTING`
- `DELIVERY_CASHFLOW_LINKAGE`
- `DELIVERY_PERMISSION`
- `DELIVERY_LOCK_PERIOD`

Severity:
- `S1` blocks core shipping flow or corrupts linked data
- `S2` wrong status/history/linkage but workaround exists
- `S3` UI mismatch or weak UX
- `S4` cosmetic only

---

## 11) Report format required from the agent

Use this exact structure.

### A. Summary
- Flow: 17 — Delivery / Waybill / Return-to-Sender
- Result: PASS / PASS WITH DEVIATIONS / FAIL / BLOCKED
- Tested commit:
- Environment:
- Tester agent:
- Date/time:

### B. Source map
- routes:
- controllers:
- services:
- adapters:
- jobs/webhooks:
- models:
- tables:
- enums/status:
- policies/middleware:
- UI screens:
- print templates:

### C. Case results
For each case 17A → 17R provide:
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
- invoices checked:
- orders checked:
- waybills created:
- waybills canceled:
- waybills rebooked:
- status history snapshots:
- address-book snapshots:
- pickup-address snapshots:
- carrier request / response logs:
- export checks:

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

- sales invoice creation is globally broken
- delivery feature cannot be enabled in test env
- integrated adapter cannot be stubbed or observed in any way
- customer/address master data is too inconsistent to trust results
- permission system blocks all test access unexpectedly

---

## 13) Fix policy

When fixing:
1. Prefer domain/service-level fixes over UI-only masking.
2. Preserve existing invoice and waybill ids.
3. Add or update automated tests if infrastructure exists.
4. If no test harness exists, add at least one reproducible scripted check for the defect.
5. Do not fake carrier-sync history in UI if persistence/event logs are actually missing.
6. Do not silently merge old and new waybills; preserve historical traceability.

---

## 14) Completion criteria

Flow 17 is considered complete only if:

- core cases 17B, 17C, 17D, 17H, 17I, 17J, 17K/17L, 17O, 17Q all PASS
- no unresolved S1 or S2 defect remains
- waybill status is consistent between list/detail/invoice
- recipient and pickup address behavior is stable
- re-booking preserves history correctly
- integrated vs self-delivery cancel behavior is distinguishable
- permissions and lock-period checks pass

If any of the above fails, this flow is not complete.
