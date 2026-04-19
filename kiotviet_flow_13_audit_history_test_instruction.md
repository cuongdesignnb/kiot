# KIOTVIET FLOW 13 — OPERATION HISTORY / AUDIT TRAIL TEST INSTRUCTION

## Goal
Verify that the application records, exposes, filters, and protects **operation history / audit trail** in a way that is functionally aligned with KiotViet's **Lịch sử thao tác** behavior for Retail.

This flow is only about **auditability and traceability** after the main transaction flows have already been tested.

---

## Source-of-truth behavior to align with
Use KiotViet behavior as the reference model for this flow:

1. Retail has a **Dữ liệu** section with **Lịch sử thao tác**, used so managers can review employee activity and detect mistakes or fraud.
2. The history screen supports **filtering** via a filter panel.
3. The screen supports **column customization** and **export file**.
4. KiotViet's operation history records items such as **login/logout** and user actions like **selling, purchasing, stock operations, or changing customers/suppliers/products**.
5. The history view contains at least these dimensions: **Nhân viên, Chức năng, Thời gian, Thao tác, Nội dung, Chi nhánh**.
6. Users can click an entry to inspect more detailed information.

Reference docs the agent should consult before concluding deviations:
- Retail setup / store settings / data / operation history
- KiotViet announcement introducing operation history
- Any in-product or source-level documentation already present in the local codebase

---

## Boundaries
This flow tests only these concerns:
- whether actions are logged
- whether log details are sufficient to trace who/when/what/where
- whether logs can be filtered and reviewed correctly
- whether permissions around viewing audit history are enforced
- whether logs remain immutable and survive edits/cancellations/deletions appropriately

This flow does **not** validate accounting totals, inventory math, or permission matrix itself except where those produce or restrict history visibility.

---

## Agent operating rules
The agent must follow these rules strictly:

1. **Do not change business logic immediately.**
   Read source code first to understand how history is currently produced.
2. **Reproduce each defect with evidence** before changing code.
3. **Fix minimally**.
   Prefer focused changes in audit logging hooks, presenters, query filters, permissions, or serializers.
4. **Do not redesign the whole logging architecture** unless the current implementation makes this flow impossible.
5. After each fix, **re-run only the affected case first**, then run the full Flow 13 regression.
6. Preserve existing valid history records; never erase history in the name of fixing the feature.
7. If the system intentionally differs from KiotViet, mark it as `PASS WITH DEVIATION` and explain the product decision.

---

## Preconditions
Flow 01–12 should already be available in the environment, at least enough to create:
- 1 admin user
- 1 sales user
- 1 warehouse user
- 2 branches or 2 warehouses if branch-scoped logs exist
- products, customers, suppliers, invoices, receipts, purchase receipts, stock transfers, stocktakes, cashbook vouchers

If those are absent, seed the minimum fixtures required.

---

## Required users
Create or use these accounts:

### U_ADMIN
- Full admin rights
- Can access audit history screen / API

### U_SALE
- Can create and edit sales-related transactions
- Cannot view global audit history unless product explicitly allows it

### U_WAREHOUSE
- Can perform stock transfer / stocktake operations
- Cannot view global audit history unless product explicitly allows it

---

## Expected minimum audit record fields
Every visible history item should be traceable with at least the following fields or direct equivalents:
- actor / user
- function / module
- action type
- timestamp
- content / message / diff summary
- branch or scope indicator if the product supports branch segmentation
- target entity reference when applicable (invoice code, product code, supplier, customer, transfer code, etc.)

If the UI labels differ, map them to the above and note the mapping.

---

## Read the source first
Before testing, inspect the source and document findings:

1. Where audit logs are created
   - model observer / event listener / service layer / middleware / activity logger
2. Which actions are covered
   - create / update / cancel / delete / login / logout / payment / inventory adjustment
3. Where logs are stored
   - database table(s), append-only files, queue-backed event store, external service
4. How logs are queried
   - repository / controller / API endpoint / query builder
5. How permissions are enforced
   - route middleware / policy / role permissions / frontend guards
6. Whether there is diff tracking
   - before/after snapshot, changed fields only, or summary text

Document these findings at the beginning of the report.

---

## Canonical seed data for this flow
Use deterministic fixture values:

### Branches
- BR01 = Main Branch
- BR02 = Annex Branch

### Product
- SP001 = Water 500ml
- SP002 = Biscuit Box

### Customer
- KH001 = Nguyen Van A

### Supplier
- NCC001 = Minh Phat Co.

### Existing transactions to create during setup
- 1 sales invoice at BR01 by U_SALE
- 1 purchase receipt by U_ADMIN or U_WAREHOUSE
- 1 stock transfer BR01 -> BR02 by U_WAREHOUSE
- 1 stocktake at BR02 by U_WAREHOUSE
- 1 cash receipt / payment by U_ADMIN

These are not the test result; they are the actions that should themselves generate history records.

---

## Test case format
For each case:
- perform the action
- capture UI evidence and/or API evidence
- inspect database audit rows if available
- compare against expected KiotViet-like behavior
- classify as PASS / FAIL / PASS WITH DEVIATION / NA

---

# CASES

## 13A — Access to operation history by admin
### Steps
1. Login as U_ADMIN.
2. Open the menu equivalent to **Data / Operation History**.
3. Confirm the screen or endpoint loads successfully.

### Expected
- Admin can access the history screen.
- The screen shows a list view of recorded actions.
- Filter controls are available.

### Fail examples
- Admin cannot access the page.
- Page loads but no data despite prior actions existing.
- Filters are missing entirely though the product claims to support them.

---

## 13B — Login / logout events are recorded
### Steps
1. Logout all test users.
2. Login as U_SALE.
3. Logout U_SALE.
4. Login as U_WAREHOUSE.
5. Logout U_WAREHOUSE.
6. As U_ADMIN, open audit history and search/filter for these events.

### Expected
- At minimum, login and logout events or equivalent session-entry/session-exit events are traceable.
- Each record identifies the acting user and time.
- The function/category is meaningful, e.g. Authentication / Login / Session.

### Fail examples
- Login/logout not logged at all.
- Records exist but cannot identify which user performed them.
- Time is missing or obviously wrong.

---

## 13C — Create sales invoice generates audit trail
### Steps
1. Login as U_SALE.
2. Create a sales invoice for KH001 with SP001 qty 2.
3. Save/complete the invoice.
4. Login as U_ADMIN and review operation history.

### Expected
- A history record exists for invoice creation/completion.
- The content identifies the invoice or enough information to trace it.
- Actor = U_SALE.
- Function/module maps to Sales / Invoice / Bán hàng.
- Time is close to execution time.

### Fail examples
- Invoice exists but no audit record exists.
- Log exists but points to wrong actor.
- Log text is too generic to trace the target transaction.

---

## 13D — Update master data generates audit trail with changed content
### Steps
1. Login as U_ADMIN.
2. Edit product SP001 price.
3. Edit customer KH001 phone or note.
4. Edit supplier NCC001 note.
5. Review operation history.

### Expected
- Product/customer/supplier edits are logged.
- Content should indicate the target entity and preferably the changed field(s) or a meaningful summary.
- The order of logs follows the actual operation times.

### Fail examples
- Master data updates are not logged.
- Only creation is logged but edits are silent.
- Logs show wrong entity or unhelpful message like “updated successfully” with no trace target.

---

## 13E — Stock operations generate audit trail
### Steps
1. Login as U_WAREHOUSE.
2. Create/complete a stock transfer BR01 -> BR02.
3. Receive the transfer if your system uses two-step receiving.
4. Create/complete a stocktake at BR02.
5. Review operation history as U_ADMIN.

### Expected
- Transfer creation is logged.
- Transfer receiving/completion is logged if the product has that state transition.
- Stocktake create/complete/cancel events are logged.
- Function/module identifies stock or inventory operations.

### Fail examples
- High-risk stock operations are not logged.
- Only create is logged; complete/cancel is not.
- Logs cannot identify warehouse/branch context when the system supports it.

---

## 13F — Cashbook / payment operations generate audit trail
### Steps
1. Login as U_ADMIN.
2. Create a cash receipt.
3. Create a cash payment.
4. If supported, cancel one of them.
5. Review operation history.

### Expected
- Receipt/payment creation is logged.
- Cancellation or reversal is also logged.
- The content lets the reviewer trace the voucher code and amount context, directly or indirectly.

### Fail examples
- Payment operations are missing from audit history.
- Canceled vouchers leave no cancellation history.

---

## 13G — Filtering works by user, function, action, keyword, time, branch
### Steps
1. Generate mixed activity from U_ADMIN, U_SALE, U_WAREHOUSE.
2. In the history screen, test filters one by one:
   - actor/user
   - function/module
   - action type
   - keyword/content
   - date/time range
   - branch/scope if supported
3. Optionally combine filters.

### Expected
- Filter results are accurate and stable.
- Combining filters narrows results logically.
- Clearing filters restores broader results.

### Fail examples
- Filters ignore one or more fields.
- Results are incomplete or inconsistent.
- Branch filter leaks records from another branch.

---

## 13H — Entry detail view exposes sufficient context
### Steps
1. Open a single history row.
2. Inspect the detail panel / modal / expanded view.
3. Compare detail with the actual target transaction or record.

### Expected
- Detail view shows enough information to understand what changed.
- If the product supports drill-down, the target entity can be opened or traced reliably.
- The detail view should not fabricate or mutate history after the fact.

### Fail examples
- Clicking a log does nothing.
- Detail view omits critical identifying information.
- Detail view shows current values only and loses what action happened.

---

## 13I — Permission restriction for non-admin viewers
### Steps
1. Login as U_SALE.
2. Attempt to access audit history page/API directly.
3. Login as U_WAREHOUSE.
4. Attempt the same.

### Expected
- Access is denied unless explicitly granted by the product.
- Frontend hiding alone is not enough; API/route access must also be protected.
- If branch-limited audit viewing exists, the user should only see allowed scope.

### Fail examples
- Non-admin can access all logs by typing URL directly.
- API leaks full history although the UI hides the menu.

---

## 13J — Audit records are immutable enough for forensic review
### Steps
1. Pick a transaction that already generated logs.
2. Edit the target transaction if product rules allow.
3. Cancel it if product rules allow.
4. Delete or archive related master data if allowed.
5. Review the earlier history rows again.

### Expected
- Prior history rows remain visible and unchanged as historical evidence.
- Later actions generate additional rows; they do not overwrite the old ones.
- Deleting or inactivating the target record should not erase its history trail.

### Fail examples
- New updates overwrite older history entries.
- Deleting a target row removes or breaks historical logs.
- Log content silently changes to current state rather than preserving historical action context.

---

## 13K — Column customization and export
### Steps
1. As U_ADMIN, open the history screen.
2. Change visible columns using the UI options if supported.
3. Verify the selected columns persist within session or according to product behavior.
4. Export the filtered result set.
5. Inspect the exported file.

### Expected
- Column chooser works and affects the grid.
- Export respects current filter scope or follows documented product behavior.
- Exported rows correspond to the on-screen dataset and preserve key audit fields.

### Fail examples
- Column customization does nothing.
- Export ignores filters.
- Export omits key identifying fields.

---

## 13L — High-volume sanity test
### Steps
1. Generate at least 100 mixed auditable actions through seed scripts or automated interaction.
2. Open the history screen with default sort.
3. Apply a common filter (e.g. actor = U_SALE within today).

### Expected
- Screen remains usable.
- Query latency is acceptable for your environment.
- Pagination / infinite scroll behaves correctly.
- No duplicate or missing rows due to pagination.

### Fail examples
- Severe lag or timeouts at low volume.
- Duplicate rows across pages.
- Missing newest events despite successful writes.

---

# Cross-check requirements
For every FAIL, agent must identify the defect category:
- `WRITE_MISSING` — action not recorded
- `WRITE_INCORRECT` — wrong actor/module/action/timestamp/content
- `READ_FILTER` — filter/query bug
- `READ_PERMISSION` — access control bug
- `READ_PRESENTATION` — details/columns/export issue
- `IMMUTABILITY` — prior logs overwritten or lost
- `PERFORMANCE` — unacceptable retrieval behavior

---

# Implementation hints for agent
When debugging, inspect likely areas such as:
- auth login/logout listeners
- transaction service classes (sales, purchase, stock, payment)
- model observers / domain events
- audit log persistence table and indexes
- query scopes for date range, actor, module, action, branch
- policy/middleware guarding the history screen and export endpoint

Typical minimal fixes may include:
- missing event dispatch after successful commit
- wrong actor resolution in async jobs
- missing branch_id propagation into audit record
- incorrect filter field mapping
- export endpoint not applying the same query conditions as list endpoint
- log message builder omitting target entity reference

---

# Reporting format the agent must output
## 1. Source reading summary
- where logs are written
- where logs are read
- where permissions are enforced
- known intentional deviations

## 2. Execution result table
| Case | Result | Evidence | Notes |
|---|---|---|---|
| 13A | PASS/FAIL/... | screenshot/api/db | ... |

## 3. Defect list
For each defect:
- defect id
- failing case(s)
- root cause
- files changed
- minimal patch summary

## 4. Retest result
- re-run affected case(s)
- run full Flow 13 regression
- summarize remaining deviations

## 5. Final verdict
Choose one:
- `FLOW 13 PASSED`
- `FLOW 13 PASSED WITH DEVIATIONS`
- `FLOW 13 FAILED`

---

# Non-negotiable acceptance standard
Mark Flow 13 as passed only if all of the following are true:
1. Admin can inspect operation history.
2. Core high-risk actions are actually logged.
3. Filters return correct scoped results.
4. Non-admin access is correctly restricted unless intentionally configured otherwise.
5. Historical records survive later edits/cancellations/deletions.
6. The audit view is useful enough to trace responsibility for a real-world issue.

If one of those is false, Flow 13 must not be marked passed.
