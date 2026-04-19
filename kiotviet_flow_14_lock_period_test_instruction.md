# KiotViet Flow 14 — Lock Period / Data Lock (`kiotviet_flow_14_lock_period_test_instruction.md`)

## Objective

This flow verifies that the application implements **data locking / closing period** behavior equivalent to KiotViet Retail's **Khóa sổ** feature.

The agent must verify that, once a lock date is set for a branch/store, the system blocks or correctly handles any attempt to:
- create backdated transactions on or before the lock date
- edit past transactions on or before the lock date
- delete/cancel past transactions on or before the lock date
- change transaction timestamps to move them into the locked range
- bypass lock checks via UI, API, background job, import, or direct service path

The agent must also verify:
- future-dated transactions after the lock date still work
- lock can be configured per branch if the app supports multi-branch
- unlocking or moving the lock date forward/backward behaves consistently with system rules
- reports and reconciled totals remain unchanged when forbidden edits are attempted
- audit logs record lock changes and rejected actions where applicable

---

## Reference behavior to emulate from KiotViet

KiotViet Retail documentation describes **Khóa sổ** under store setup / data control as a feature used to **chốt dữ liệu đến một ngày nhất định** and **ngăn chặn mọi thay đổi giao dịch trong quá khứ** to preserve report accuracy.

Equivalent product behavior expected:
1. A lock date exists at least per store/branch (or globally if the app design is single-branch).
2. Transactions dated **on or before** the lock date are considered locked.
3. Users must be prevented from changing historical transactions in the locked period.
4. Transactions **after** the lock date remain editable subject to normal permissions.
5. If the application exposes accounting/period-close concepts, the lock must apply consistently to sales, purchases, stock, receivables/payables, cashbook, stock transfer, stocktake, and returns.
6. Any allowed exception path must be explicit and audited, not accidental.

---

## Scope of this flow

This flow covers lock behavior for:
- sales invoices
- customer payments / receipts
- purchase receipts
- supplier payments / disbursements
- customer returns
- supplier returns
- stock transfers
- stocktakes / adjustments
- cashbook entries
- date edits and cancellation in locked periods
- branch-specific lock scope
- audit trail for lock changes (if available)

This flow does **not** re-test the full business logic of those modules; it only checks their behavior **with a lock date active**.

---

## Required preconditions

The agent must ensure Flows 01–13 are already in a reasonably working state before running this flow:
- foundation data
- purchasing
- sales
- receivables
- returns
- payables
- supplier returns
- stock transfer
- stocktake
- cashbook
- permissions
- reports reconciliation
- audit history

If any prerequisite flow is clearly broken, record `BLOCKED_BY_PREVIOUS_FLOW` and continue only where meaningful.

---

## Mandatory reading before testing

Before executing or fixing anything, the agent must inspect:

1. lock/period-close configuration source
   - settings table / config file
   - branch settings
   - transaction policy service
   - middleware / request validation
   - domain service / repository guards

2. transaction date validation paths for:
   - sales
   - purchases
   - cashbook
   - stock
   - returns
   - payments

3. cancellation / update code paths
   - ensure lock checks are not only applied on create, but also edit, cancel, delete, restore, bulk update, import, and background jobs

4. authorization rules
   - whether admin can bypass or not
   - whether bypass is intentional
   - whether bypass is logged

5. audit logging paths
   - lock setting changes
   - blocked mutation attempts if applicable

The agent must summarize the current design before running tests.

---

## Fixed test dataset

Use a deterministic test dataset in a dedicated test/staging database.

### Branches
- `CN_A` — Branch A
- `CN_B` — Branch B

### Lock dates
- For `CN_A`, set lock date to `2026-03-31 23:59:59`
- For `CN_B`, leave unlocked initially
- If the application only supports global lock, document that deviation and adapt tests

### Core entities
- Customer: `KH_LOCK_01`
- Supplier: `NCC_LOCK_01`
- Warehouse A: `KHO_A`
- Warehouse B: `KHO_B`
- Product 1: `SP_LOCK_01` — physical item
- Product 2: `SP_LOCK_02` — physical item
- Service item: `DV_LOCK_01`

### Historical transactions in `CN_A`
Create or seed these transactions with dates **before or on** the lock date:
- Sales invoice `INV_LOCK_OLD_01` dated `2026-03-25`
- Customer payment `RCPT_LOCK_OLD_01` dated `2026-03-26`
- Purchase receipt `PUR_LOCK_OLD_01` dated `2026-03-20`
- Supplier payment `PAY_LOCK_OLD_01` dated `2026-03-21`
- Customer return `RET_LOCK_OLD_01` dated `2026-03-27`
- Supplier return `SRET_LOCK_OLD_01` dated `2026-03-28`
- Stock transfer `TRF_LOCK_OLD_01` dated `2026-03-29`
- Stocktake `STK_LOCK_OLD_01` dated `2026-03-30`
- Cashbook income `CBR_LOCK_OLD_01` dated `2026-03-24`
- Cashbook expense `CBP_LOCK_OLD_01` dated `2026-03-24`

### Future transactions in `CN_A`
Create or seed transactions **after** the lock date:
- Sales invoice `INV_LOCK_NEW_01` dated `2026-04-02`
- Purchase receipt `PUR_LOCK_NEW_01` dated `2026-04-02`
- Cashbook entry `CB_LOCK_NEW_01` dated `2026-04-03`

### Transactions in `CN_B`
Create one sale and one purchase dated `2026-03-25` to verify branch scope when `CN_B` is unlocked.

---

## Global pass criteria

This flow passes only if all are true:
1. Locked-period transactions cannot be changed by any normal mutation path.
2. Future transactions remain functional.
3. Lock scope is correctly applied by branch or globally as designed.
4. Backdating new transactions into the locked range is blocked.
5. Changing an unlocked transaction's date into the locked range is blocked.
6. Reports/reconciliations are unchanged by rejected actions.
7. Lock configuration changes behave consistently and are persisted.
8. Any intentional override path is explicit, permission-based, and audited.

If any mutation succeeds silently in a locked period, classify it as **critical**.

---

## Test cases

### 14A — Configure lock date
**Goal:** Verify lock date can be configured and persisted.

Steps:
1. Open lock/closing-period settings.
2. Enable lock.
3. Set `CN_A` lock date to `2026-03-31`.
4. Save.
5. Reload page and re-fetch settings by API/DB.
6. Confirm effective lock date persists.

Expected:
- Save succeeds.
- Lock date is stored exactly once in the correct settings scope.
- UI/API reload shows the same lock date.
- No unrelated settings are modified.

Fail if:
- date not persisted
- wrong branch affected
- value stored but not enforced later
- timezone conversion shifts the effective date unexpectedly

---

### 14B — Create new backdated sales invoice in locked period
**Goal:** Prevent creation of a new historical transaction inside locked period.

Steps:
1. Attempt to create a sales invoice in `CN_A` dated `2026-03-15`.
2. Use both UI and API if available.

Expected:
- Action is blocked.
- No invoice, stock movement, customer ledger entry, payment, or report posting is created.
- User receives a clear lock-period message.

Fail if any posting occurs partially.

Severity: `CRITICAL`

---

### 14C — Edit locked sales invoice
**Goal:** Prevent modifying an existing invoice dated inside locked period.

Steps:
1. Open `INV_LOCK_OLD_01`.
2. Attempt to modify:
   - quantity
   - unit price
   - discount
   - customer
   - note
   - transaction date
3. Save.

Expected:
- Mutation is blocked according to policy.
- If the application allows harmless metadata changes, that exception must be explicit and documented.
- No stock, ledger, receipt allocation, report total, or audit payload is mutated unexpectedly.

Severity:
- `CRITICAL` if monetary/stock fields can change
- `MAJOR` if forbidden metadata changes pass unintentionally

---

### 14D — Cancel or delete locked invoice
**Goal:** Prevent rollback operations inside locked period.

Steps:
1. Attempt to cancel `INV_LOCK_OLD_01`.
2. Attempt soft-delete or delete if supported.
3. Attempt restore if the record can be soft-deleted elsewhere.

Expected:
- Cancel/delete is blocked.
- No stock reversal, receivable reversal, or report reversal occurs.

Severity: `CRITICAL`

---

### 14E — Edit/create purchase receipt in locked period
**Goal:** Prevent purchase-side mutations inside locked period.

Steps:
1. Attempt to create a new purchase dated `2026-03-18` in `CN_A`.
2. Attempt to edit `PUR_LOCK_OLD_01`.
3. Attempt to cancel it.

Expected:
- All blocked.
- No inventory or supplier ledger mutation occurs.

Severity: `CRITICAL`

---

### 14F — Customer payment / supplier payment lock enforcement
**Goal:** Ensure receipts and disbursements cannot be inserted/changed in locked period.

Steps:
1. Create customer receipt dated `2026-03-25` in `CN_A`.
2. Edit `RCPT_LOCK_OLD_01`.
3. Cancel `RCPT_LOCK_OLD_01`.
4. Create supplier payment dated `2026-03-25` in `CN_A`.
5. Edit/cancel `PAY_LOCK_OLD_01`.

Expected:
- All blocked inside lock period.
- Customer/supplier balances remain unchanged.
- Cashbook totals remain unchanged.

Severity: `CRITICAL`

---

### 14G — Returns and stock transactions in locked period
**Goal:** Ensure stock-affecting transactions respect lock.

Steps:
1. Attempt customer return dated `2026-03-27`.
2. Attempt supplier return dated `2026-03-28`.
3. Attempt stock transfer dated `2026-03-29`.
4. Attempt stocktake dated `2026-03-30`.
5. Attempt direct stock adjustment/import if such tool exists.

Expected:
- All blocked.
- No stock movement records created or changed.

Severity: `CRITICAL`

---

### 14H — Cashbook entries in locked period
**Goal:** Prevent financial entries in closed period.

Steps:
1. Create income/expense dated `2026-03-24` in `CN_A`.
2. Edit `CBR_LOCK_OLD_01` and `CBP_LOCK_OLD_01`.
3. Cancel them.

Expected:
- All blocked.
- Balances and financial reports unchanged.

Severity: `CRITICAL`

---

### 14I — Future transactions still work
**Goal:** Ensure lock does not break valid current/future operations.

Steps:
1. Edit `INV_LOCK_NEW_01` dated `2026-04-02`.
2. Create a new sale dated `2026-04-05`.
3. Edit `PUR_LOCK_NEW_01`.
4. Create a cashbook entry dated `2026-04-05`.

Expected:
- Valid operations after lock date succeed normally.
- Standard posting occurs.

Fail if lock is too aggressive and blocks allowed dates.

Severity: `MAJOR`

---

### 14J — Attempt to move unlocked transaction into locked period
**Goal:** Prevent date reassignment into a locked period.

Steps:
1. Take `INV_LOCK_NEW_01` dated `2026-04-02`.
2. Edit its date to `2026-03-30`.
3. Save.

Expected:
- Blocked.
- Original transaction remains unchanged at its valid date.

Repeat for:
- purchase
- receipt/payment
- cashbook entry if supported

Severity: `CRITICAL`

---

### 14K — Branch scope of lock
**Goal:** Ensure lock applies only to intended branches when system is multi-branch.

Steps:
1. Keep `CN_A` locked, `CN_B` unlocked.
2. Attempt a backdated sale dated `2026-03-25` in `CN_B`.
3. Attempt edit/cancel of a `CN_B` historical transaction on same date.

Expected:
- If lock is branch-scoped, `CN_B` remains allowed until separately locked.
- If application intentionally uses global lock, document deviation and verify consistent global enforcement.

Severity:
- `CRITICAL` if wrong branch is blocked or unblocked contrary to design
- `PASS_WITH_DEVIATION` only if design is intentionally global and consistent

---

### 14L — Lock change and unlock behavior
**Goal:** Verify changing lock date behaves predictably.

Steps:
1. Move `CN_A` lock date from `2026-03-31` to `2026-04-05`.
2. Confirm previously editable `2026-04-02` items become locked if policy is immediate.
3. Move lock date backward to `2026-03-15` if allowed.
4. Disable lock if feature supports disabling.

Expected:
- Policy is explicit and consistent.
- UI/API/database all reflect same effective date/state.
- Actions become blocked or allowed according to the new rule.
- Lock change is audited.

Document whether:
- moving lock backward is allowed
- disabling lock is allowed
- only super-admin can change lock

Severity:
- `MAJOR` if inconsistent
- `CRITICAL` if hidden stale enforcement remains after setting change

---

### 14M — Import / bulk operations / background jobs
**Goal:** Ensure lock checks are not bypassed outside normal UI.

Steps:
1. Attempt import of sales/purchases/cash entries dated inside locked range.
2. Attempt bulk update / bulk cancel if such actions exist.
3. Run any scheduled posting job or queue replay that touches locked-period items.

Expected:
- Same lock enforcement applies.
- No side postings occur.

Severity: `CRITICAL`

---

### 14N — Reporting invariance after rejected mutations
**Goal:** Ensure blocked actions do not leak into reports.

Steps:
1. Capture report totals before mutation attempts:
   - sales report
   - purchase report
   - receivable/payable summary
   - stock summary
   - cashbook summary
2. Run all rejected locked-period attempts from above.
3. Re-run reports.

Expected:
- Totals exactly unchanged.
- No phantom entries appear.

Severity: `CRITICAL`

---

### 14O — Audit trail for lock activity
**Goal:** Confirm lock configuration and blocked edits are traceable.

Steps:
1. Change lock setting.
2. Attempt at least one blocked mutation.
3. Open audit/log history.

Expected:
- Lock-setting changes are logged with actor/time/value old→new if audit exists.
- Blocked attempts are either logged or surfaced in application logs.
- At minimum, successful lock setting changes must be traceable.

Severity:
- `MAJOR` if config changes are not auditable
- `MINOR` if blocked attempts are not separately visible but app logs contain them

---

## Database-level verification after each blocked action

After every blocked action, the agent must verify:
- no new transaction row created
- no stock movement created
- no customer ledger created
- no supplier ledger created
- no cashbook movement created
- no receipt allocation / payment allocation mutated
- no report snapshot or aggregate table changed unexpectedly
- timestamps `updated_at` on target documents did not change unless intentionally logged

---

## Required negative tests

The agent must explicitly try these bypass attempts if relevant:
- direct API call without UI
- edit through alternate screen
- duplicate/clone then backdate
- cancel through list view rather than detail view
- update via import
- queue/cron triggered status sync
- branch switch followed by stale form submit
- browser double-submit / retry
- optimistic concurrency edge where first save is allowed and second sneaks through

---

## Fix policy

If the agent finds deviations, it must:
1. identify exact failing rule
2. trace the code path
3. propose the **smallest safe fix**
4. implement only that fix
5. re-run:
   - the failed case
   - at least one adjacent locked case
   - one future-date allowed case
6. confirm no regression in posting logic

The agent must **not** refactor unrelated transaction modules during this flow.

---

## Defect taxonomy

Use one of:
- `LOCK_NOT_ENFORCED_CREATE`
- `LOCK_NOT_ENFORCED_UPDATE`
- `LOCK_NOT_ENFORCED_CANCEL`
- `LOCK_SCOPE_WRONG_BRANCH`
- `LOCK_SCOPE_GLOBAL_UNDOCUMENTED`
- `LOCK_DATE_BOUNDARY_ERROR`
- `LOCK_UI_ONLY_API_BYPASS`
- `LOCK_IMPORT_BYPASS`
- `LOCK_REPORT_SIDE_EFFECT`
- `LOCK_SETTING_NOT_PERSISTED`
- `LOCK_SETTING_NOT_AUDITED`
- `LOCK_TOO_AGGRESSIVE`
- `LOCK_TIMEZONE_ERROR`

Severity:
- `CRITICAL` — historical transaction can still mutate postings
- `MAJOR` — inconsistent enforcement / branch scope / audit failure
- `MINOR` — message, UX, or low-risk metadata discrepancy

---

## Output report format

The agent must produce a final report in Markdown with these sections:

### 1. Summary
- Flow: 14
- Result: PASS / FAIL / PASS WITH DEVIATION
- Scope: branch/global lock
- Lock setting location found in source

### 2. Cases executed
Table:
- Case ID
- Result
- Notes
- Evidence

### 3. Deviations from KiotViet-like behavior
For each deviation:
- ID
- Symptom
- Expected
- Actual
- Root cause
- Severity

### 4. Code changes made
- files changed
- reason
- minimal patch explanation

### 5. Re-test results
- original failed case
- adjacent locked case
- allowed future-date case

### 6. Remaining risks
- untested imports
- cron/queue uncertainty
- global vs branch limitation
- lack of audit logs, if any

---

## Hard stop rules

The agent must stop and report immediately if:
- a locked-period mutation succeeds partially and leaves inconsistent posting
- stock changed but ledger did not
- ledger changed but report did not
- cancel succeeded in locked period
- lock applies inconsistently between UI and API
- branch scope is ambiguous and cannot be proven from code

---

## Final expected outcome

At the end of Flow 14, the application should behave like a properly period-locked retail system:
- historical data is protected
- future transactions continue working
- branch scope is consistent
- no silent bypass exists
- lock settings are traceable
- rejected actions do not alter balances or reports
