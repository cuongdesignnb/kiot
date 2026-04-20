# KiotViet Flow 19 — Counterparty Appears as Both Customer and Supplier
**Purpose:** Agent instruction to verify that the target system behaves safely and compatibly with KiotViet when the **same real-world counterparty** may appear in the system as **Customer** and **Supplier** at the same time, and to apply **minimal fixes only when a proven deviation is found**.

> Important compatibility note:
> Public KiotViet documentation describes **Customer** and **Supplier** as two separate management modules with separate debt views:
> - Customer: purchase history, returns, receivables, payment collection
> - Supplier: purchase receipt/return history, payables, supplier payments
> - The debt-management guide also describes receivables and payables as separate streams managed centrally.
>
> Therefore, this flow should **not assume an official KiotViet public feature of automatic merged ledger or automatic cross-offset** for one shared party.
> The audit goal is:
> 1. verify the system keeps receivable and payable ledgers distinct unless an explicit offset mechanism exists,
> 2. verify any offset / adjustment / settlement is explicit, traceable, and does not silently net unrelated balances.

---

## 0) Scope and non-goals

This flow covers only the scenario where one real-world business counterpart exists in two roles:

- a customer record
- a supplier record

and the target system must handle that safely.

This flow verifies:

- creation and identification of the two records
- whether the system allows linking them as one “counterparty profile” or not
- separation of customer receivable and supplier payable ledgers
- explicit offset / bù trừ / điều chỉnh if the target system supports it
- behavior in customer detail, supplier detail, cashbook, and reports
- history traceability
- permissions and period lock related to offset or debt adjustment

This flow does **not** retest:
- basic customer creation and receivables (Flow 01, Flow 04)
- basic supplier creation and payables (Flow 01, Flow 06)
- generic cashbook behavior (Flow 10)
- generic reports (Flow 12)

Only verify the interaction points created by “same real-world party in two roles”.

---

## 1) Ground truth reference behavior from KiotViet public documentation

Use these public KiotViet behaviors as the reference baseline:

1. Customer management is a dedicated module used to track customer information, transaction history, returns, and receivables.
2. Supplier management is a dedicated module used to track supplier information, purchase/return history, and payables.
3. Debt management is described as centralized visibility over **customer receivables** and **supplier payables**, but still as two debt directions, not as one auto-netted ledger.
4. Customer payment collection is performed from the customer debt view and creates receipt documents.
5. Supplier payment is performed from the supplier debt view and creates payment documents.
6. Customer and supplier modules each support history and debt tabs independently.
7. Therefore, a compatible target system must at minimum preserve:
   - separate debt truth for receivable vs payable,
   - explicit posting history,
   - no silent netting of balances between modules.

---

## 2) Agent operating rules

You must follow these rules strictly:

1. **Read source before changing anything.**
   - Find customer models, supplier models, debt ledgers, partner tables, party mapping tables if any, cashbook linkage, debt-adjustment logic, offset logic, report queries, and permissions.

2. **Do not refactor broadly.**
   - Only apply the smallest fix that corrects a proven deviation.

3. **Do not invent a merged-ledger feature unless the source already intends one.**
   - If the system currently uses fully separate customer and supplier records, preserve that design and validate it rigorously.
   - If the source already has a shared “party” model, validate that both ledgers remain traceable and do not silently corrupt each other.

4. **Log every deviation with evidence.**
   - Include reproduction, expected behavior, actual behavior, affected files, and whether the issue is identity, ledger, adjustment, report, or permission level.

5. **Retest after every fix.**
   - Re-run failed cases first, then the regression mini-set.

6. **Never mark PASS by visual guess.**
   - Confirm both UI behavior and persisted receivable/payable truth.

---

## 3) Preconditions

Before executing this flow, confirm these are already working:

- Flow 01: foundation data
- Flow 04: customer receivables
- Flow 06: supplier payables
- Flow 10: cashbook
- Flow 11: permissions
- Flow 12: reports/reconciliation
- Flow 14: lock period

If any prerequisite is broken, stop and mark this flow as **BLOCKED**.

---

## 4) Fixed test dataset

Use a deterministic dataset. Do not improvise random names.

### Core real-world party
- Real-world business name: `CTY_TV_MINH_PHAT`

Create both:

### Customer role
- `KH_DUAL_01`
- Name: `Công ty Minh Phát`
- Phone: `0900999001`
- Tax code if supported: `0101234567`

### Supplier role
- `NCC_DUAL_01`
- Name: `Công ty Minh Phát`
- Phone: `0900999002`
- Tax code if supported: `0101234567`

### Other counterparties
- `KH_OTHER_01`
- `NCC_OTHER_01`

### Items
- `SP_DUAL_01`
- `SP_DUAL_02`

### Users
- `admin`
- `accountant01` — can view debt, collect customer money, pay supplier, perform debt adjustment if allowed
- `sale01`
- `purchaser01`
- `viewer01`

### Base business scenario to create before testing offset logic
1. Create customer-side debt:
   - sell to `KH_DUAL_01`
   - invoice total = 1,000,000
   - customer pays = 200,000
   - receivable outstanding = 800,000

2. Create supplier-side debt:
   - purchase from `NCC_DUAL_01`
   - receipt total = 600,000
   - paid supplier = 100,000
   - payable outstanding = 500,000

These two balances must exist simultaneously before deeper cases.

---

## 5) Source discovery checklist

Before testing, inspect and document:

### Identity layer
- customer tables
- supplier tables
- shared party table if any
- unique constraints on phone / tax code / name
- optional mapping table between customer and supplier profiles

### Ledger layer
- customer receivable ledger tables
- supplier payable ledger tables
- debt adjustment tables
- offset / settlement tables if any
- cash receipt / cash payment tables
- report aggregation source

### UI / domain layer
- customer detail debt tab
- supplier detail debt tab
- debt adjustment screens
- partner merge/link screens if any
- report screens affected
- permission gates / middleware
- lock-period guard

Deliver a short map:

- routes:
- controllers:
- services:
- models:
- tables:
- enums/status:
- policies/middleware:
- UI screens:

Do this before running cases.

---

## 6) Canonical truth model to validate

### Minimum compatible truth model
Even if one real-world company appears in both roles, the target system should keep at least:

- customer receivable truth
- supplier payable truth
- explicit documents that created those balances
- explicit documents that reduced those balances

### If the system supports explicit offset
Then the system must also have:

- an offset/adjustment document or journal
- references to both sides
- before/after amounts
- user / time / note
- report impact traceability

### What must never happen
- customer receivable silently reduced just because supplier payable exists
- supplier payable silently reduced just because customer receipt is collected
- one module overwriting the identity or history of the other module

---

## 7) Core invariants

Validate these invariants in every relevant case:

1. Customer receivable and supplier payable are separate truths unless an explicit offset document exists.
2. Using the same legal name or tax code must not automatically merge historical ledgers without operator confirmation and traceability.
3. If the system supports a shared counterparty profile, detail screens must still distinguish receivable vs payable streams.
4. Customer receipts must reduce receivable only.
5. Supplier payments must reduce payable only.
6. Debt adjustment or offset, if supported, must be explicit, balanced, and traceable.
7. Reports must not double count or incorrectly net balances unless the report is explicitly a “net exposure” report and documents that rule.
8. Search/detail/report values must agree.
9. Permission denial must hold at both UI and backend levels.
10. Locked period must block retroactive offset/adjustment if the system blocks debt changes in locked periods.

---

## 8) Test cases

Run cases in order. Stop only for blockers.

### 19A — Create two records for the same real-world party
**Goal:** verify the system can represent the same legal entity in two roles safely.

Steps:
1. Create `KH_DUAL_01` as a customer.
2. Create `NCC_DUAL_01` as a supplier.
3. Use same business name and same tax code if the system allows it.
4. Observe whether the system:
   - keeps them as separate records,
   - blocks duplicate identity,
   - or offers a controlled link/mapping.

Expected:
- behavior must be explicit and stable.
- If the system allows two separate records, both must be independently accessible.
- If the system forces a link, the link must be deliberate and traceable.
- The system must not silently collapse two records into one without clear operator action.

DB checks:
- record count and identity constraints behave exactly as designed
- no silent overwrite of existing role record

---

### 19B — Build customer receivable on the customer role
**Goal:** verify receivable lives only on customer side.

Steps:
1. Sell to `KH_DUAL_01`.
2. Invoice total = 1,000,000.
3. Collect only 200,000.
4. Save invoice.

Expected:
- customer receivable = 800,000
- supplier payable remains unchanged
- customer debt tab shows the receivable correctly

DB checks:
- invoice exists
- customer debt ledger updated
- supplier debt ledger unchanged

---

### 19C — Build supplier payable on the supplier role
**Goal:** verify payable lives only on supplier side.

Steps:
1. Purchase from `NCC_DUAL_01`.
2. Receipt total = 600,000.
3. Pay supplier only 100,000.
4. Complete receipt/payment.

Expected:
- supplier payable = 500,000
- customer receivable remains 800,000 from prior case
- supplier debt tab shows the payable correctly

DB checks:
- purchase receipt exists
- supplier payable ledger updated
- customer receivable ledger unchanged

---

### 19D — View both ledgers side by side
**Goal:** verify operator can inspect both sides without mixing them.

Steps:
1. Open customer detail for `KH_DUAL_01`.
2. Inspect debt / payment history.
3. Open supplier detail for `NCC_DUAL_01`.
4. Inspect debt / payment history.

Expected:
- customer screen shows only customer-side receivable history
- supplier screen shows only supplier-side payable history
- if the system offers a “related party” link, it must still distinguish the two ledgers clearly

Fail examples:
- customer screen shows supplier transactions as if they were customer debt
- totals are netted silently in detail screens

---

### 19E — Customer collection must not touch supplier payable
**Goal:** verify money collected from customer does not reduce supplier payable by mistake.

Steps:
1. Collect 300,000 from `KH_DUAL_01`.
2. Save receipt.

Expected:
- customer receivable reduces from 800,000 to 500,000
- supplier payable remains 500,000
- customer receipt history reflects the collection
- supplier payment history unchanged

DB checks:
- receipt document exists
- customer ledger reduced correctly
- supplier ledger unchanged

---

### 19F — Supplier payment must not touch customer receivable
**Goal:** verify payment to supplier does not reduce customer receivable by mistake.

Steps:
1. Pay 200,000 to `NCC_DUAL_01`.
2. Save payment.

Expected:
- supplier payable reduces from 500,000 to 300,000
- customer receivable remains 500,000
- supplier payment history reflects payment
- customer receipt history unchanged

DB checks:
- payment document exists
- supplier ledger reduced correctly
- customer ledger unchanged

---

### 19G — Explicit offset / bù trừ if the system supports it
**Goal:** verify explicit debt offset, not silent netting.

Steps:
1. Determine whether the source supports direct offset between customer receivable and supplier payable for linked parties.
2. If supported:
   - create an offset for 300,000 between `KH_DUAL_01` and `NCC_DUAL_01`
3. If not supported:
   - mark this case as `NA` and verify the system does not pretend to do implicit offset.

Expected if supported:
- a dedicated offset/adjustment document is created
- receivable decreases by 300,000
- payable decreases by 300,000
- document links both role records and stores note/user/time
- history and reports reflect the offset explicitly

Expected if not supported:
- no automatic netting happens anywhere
- operator must manage each side separately or through explicit accounting workaround outside this module

Fail examples:
- balances change but no traceable document exists
- one side changes and the other does not
- offset can be performed between unrelated parties without validation

---

### 19H — Debt adjustment on one side must stay on that side
**Goal:** verify unilateral adjustment does not leak across roles.

Steps:
1. In customer debt tab, create debt adjustment reducing receivable by 50,000.
2. In supplier debt tab, create adjustment reducing payable by 20,000.

Expected:
- customer receivable changes only by the customer adjustment
- supplier payable changes only by the supplier adjustment
- both adjustments are independently traceable

DB checks:
- separate adjustment documents or ledger entries exist
- no unintended cross-ledger effect

---

### 19I — Cashbook linkage and partner type
**Goal:** verify cashbook entries preserve partner role correctly.

Steps:
1. Review receipts created in 19E and payments created in 19F in the cashbook.
2. Check:
   - document type
   - partner type
   - linked source document

Expected:
- customer collection appears as customer-type incoming cash
- supplier payment appears as supplier-type outgoing cash
- if the same real-world name is shown, partner role must still be distinguishable

Fail examples:
- both entries collapse under one generic partner record with ambiguous role
- clicking from cashbook opens the wrong module/detail

---

### 19J — Reports: separate vs net exposure
**Goal:** verify reports do not silently net unless explicitly intended.

Steps:
1. Open receivable-related reports.
2. Open payable-related reports.
3. If there is a combined debt or partner exposure report, inspect its logic.
4. Compare totals with detail screens.

Expected:
- receivable report shows customer-side debt only
- payable report shows supplier-side debt only
- any combined/net report must document its logic and remain drillable to the original documents
- totals must reconcile with detail tabs and posted documents

Fail examples:
- report silently nets 500,000 receivable against 300,000 payable and shows only 200,000 without telling the user
- combined report cannot be drilled back to source documents

---

### 19K — Search and identity collisions
**Goal:** verify search UX remains safe when same name exists in both roles.

Steps:
1. Search “Công ty Minh Phát” in:
   - customer module
   - supplier module
   - invoice partner search
   - purchase partner search
   - cashbook partner selection
2. Observe result lists.

Expected:
- searches return the correct role dataset
- invoice flow must choose customer role only
- purchase flow must choose supplier role only
- generic partner selectors, if any, must show role labels clearly

Fail examples:
- sales invoice can select supplier role by mistake
- purchase receipt can select customer role by mistake
- ambiguous search result with no role indicator

---

### 19L — Permissions
**Goal:** verify access control.

Steps:
1. Login as `sale01`.
2. Verify sales-side operations only.
3. Login as `purchaser01`.
4. Verify purchase-side operations only.
5. Login as `accountant01`.
6. Verify debt viewing/collection/payment/adjustment according to rights.
7. Attempt restricted API calls directly.

Expected:
- role boundaries remain correct even for shared real-world counterparties
- backend rejects unauthorized offset/adjustment/payment/collection actions
- visibility of opposite-side ledger follows configured permissions

---

### 19M — Locked period
**Goal:** verify period lock handling for dual-role debt updates.

Steps:
1. Set lock date covering existing debt documents.
2. Attempt:
   - customer receipt backdated into locked period
   - supplier payment backdated into locked period
   - offset/adjustment backdated into locked period if supported
3. Retry outside lock range.

Expected:
- locked-period actions are blocked consistently
- no partial ledger updates occur
- outside lock range works normally

---

### 19N — Delete / deactivate one role record
**Goal:** verify deleting or deactivating one role does not corrupt the other role.

Steps:
1. Deactivate or delete `KH_DUAL_01` in test-safe manner.
2. Inspect `NCC_DUAL_01`.
3. Repeat inverse on a reset dataset.

Expected:
- deactivating/deleting customer role does not erase supplier history
- deactivating/deleting supplier role does not erase customer history
- if the system maintains a shared party link, the surviving role remains functional and history remains intact

Fail examples:
- deleting customer role breaks supplier detail
- one role’s lifecycle mutates the other role’s debt unexpectedly

---

### 19O — Optional shared-party view if the system supports it
**Goal:** verify the combined party screen, if present.

Steps:
1. Determine whether the target system has a unified “party/counterparty” profile that links customer and supplier roles.
2. If supported:
   - open the shared profile
   - inspect identity, role tabs, receivable tab, payable tab, combined summary
3. If unsupported:
   - mark `NA`

Expected if supported:
- combined view shows both roles but keeps source ledgers distinct
- summary values are traceable
- drill-down opens the correct customer or supplier documents

Expected if unsupported:
- separate modules remain the only truth
- no hidden silent linking occurs

---

## 9) Minimal regression set after any fix

After fixing any failed case, rerun:

- 19B customer receivable creation
- 19C supplier payable creation
- 19E customer collection isolation
- 19F supplier payment isolation
- 19G explicit offset or non-offset safety
- 19I cashbook linkage
- 19J reports
- 19K search and identity collisions
- 19L permissions

If the fix touched customer debt, rerun key checks from Flow 04.
If the fix touched supplier debt, rerun key checks from Flow 06.
If the fix touched cashbook links, rerun smallest linked checks from Flow 10.
If the fix touched reporting truth, rerun smallest linked checks from Flow 12.

---

## 10) Defect classification

Tag each defect with one primary label:

- `DUAL_IDENTITY_CREATE`
- `DUAL_IDENTITY_LINKAGE`
- `DUAL_RECEIVABLE_ISOLATION`
- `DUAL_PAYABLE_ISOLATION`
- `DUAL_OFFSET_EXPLICIT`
- `DUAL_ADJUSTMENT_LEAK`
- `DUAL_CASHBOOK_ROLE`
- `DUAL_REPORT_NETTING`
- `DUAL_SEARCH_COLLISION`
- `DUAL_PERMISSION`
- `DUAL_LOCK_PERIOD`
- `DUAL_DELETE_DEACTIVATE`
- `DUAL_SHARED_PROFILE`

Severity:
- `S1` corrupts debt truth or silently nets ledgers
- `S2` wrong linkage/history/reporting but workaround exists
- `S3` UX ambiguity or weak search labeling
- `S4` cosmetic only

---

## 11) Report format required from the agent

Use this exact structure.

### A. Summary
- Flow: 19 — Counterparty Appears as Both Customer and Supplier
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
- UI screens:

### C. Case results
For each case 19A → 19O provide:
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
- customer record ids:
- supplier record ids:
- shared-party id if any:
- receivable snapshots:
- payable snapshots:
- offset/adjustment docs:
- cashbook snapshots:
- report totals checked:
- search result samples:

### F. Regression results
- cases rerun:
- results:
- remaining risks:

### G. Final verdict
- Does the target system safely support one real-world counterparty in two roles?
- Are receivable and payable truths preserved?
- Is any offset behavior explicit and traceable?
- What deviations remain?

---

## 12) Stop conditions

Stop execution and mark **BLOCKED** if:

- customer receivable flow is globally broken
- supplier payable flow is globally broken
- cashbook linkage is too inconsistent to trust role verification
- identity model is too unstable to represent the same real-world party in two roles
- permission system blocks all debt access unexpectedly

---

## 13) Fix policy

When fixing:
1. Prefer preserving separate ledger truth over introducing clever hidden netting.
2. Preserve historical documents and ids.
3. Add or update automated tests if infrastructure exists.
4. If no test harness exists, add at least one reproducible scripted check for the defect.
5. Do not fake combined balances in UI if underlying ledgers are not actually reconciled.
6. Do not merge customer and supplier records destructively without an explicit migration design.

---

## 14) Completion criteria

Flow 19 is considered complete only if:

- core cases 19A, 19B, 19C, 19D, 19E, 19F, 19I, 19J, 19K, 19L all PASS
- no unresolved S1 or S2 defect remains
- receivable/payable remain distinct and traceable
- offset, if supported, is explicit and balanced
- report totals do not silently net without explanation
- permissions and lock-period checks pass

If any of the above fails, this flow is not complete.
