# KiotViet Flow 12 — Reports & Reconciliation Test Instruction

## Purpose
This instruction is for an autonomous coding agent (Claude, Antigravity, or similar) to audit, test, and minimally repair the application's **reports and reconciliation behavior** so it matches the target operational behavior of KiotViet as closely as possible.

This flow must be executed **independently**. Do not expand scope into unrelated flows unless a defect in this flow cannot be fixed without a tiny supporting change elsewhere.

---

## Source-of-truth behaviors to match
Use KiotViet behavior as the reference for this flow:

1. The report system is available from **Analysis -> Reports** on newer/pro packages, or from **Reports** on some plans.
2. The application should provide a **Daily/End-of-day report** that summarizes the day's sales activity and cash movement.
3. A **Sales report** should support filtering by time and branch, and should expose metrics such as revenue, return value, net revenue, and sales order count.
4. A **Product report** should support views such as sales, profit, inventory value, stock movement / opening-in-out-ending style figures, and grouping/aggregation options.
5. A **Customer report** should include customer sales / customer debt style analysis.
6. A **Supplier report** should include supplier purchases / supplier payable style analysis.
7. A **Financial report** should derive business results from sales revenue, deductions/returns, cost of goods sold, and operating income/expense items to compute final profit.
8. If the target app intentionally implements a reduced report set, each missing capability must be marked clearly as either:
   - `NA - not implemented in target system`
   - or `Deviation - intentionally simplified`

Do not hide deviations.

---

## Agent operating rules

1. Read the current source code before making any change.
2. Identify the current reporting architecture used by the app:
   - SQL/materialized queries/views
   - service layer / repository aggregations
   - cache layers
   - chart DTO builders
   - export pipeline
   - report permission guards
3. Prefer to **write or run tests first** when feasible.
4. Only apply the **smallest safe change** needed to fix a confirmed mismatch.
5. After any change, rerun only the impacted test set first, then rerun all Flow 12 tests.
6. Never rewrite broad reporting architecture in this flow unless required to unblock a confirmed core mismatch.
7. Record every mismatch in the report, even if not fixed.
8. If numbers differ, the agent must determine whether the defect is in:
   - transaction posting,
   - report aggregation,
   - filters/scoping,
   - or UI presentation/rounding.

---

## Expected deliverables

The agent must output:

1. A short audit summary of the current report architecture.
2. A pass/fail table for every test case in this flow.
3. A defect list with root-cause hypotheses.
4. The exact files changed.
5. Retest results after changes.
6. Remaining deviations from KiotViet behavior.
7. A reconciliation table proving whether report totals match source transactions.

---

## Preconditions

Before executing Flow 12, ensure these earlier flows are already stable enough to support reporting:
- Flow 01 foundation data
- Flow 02 purchase receipt
- Flow 03 sales invoice
- Flow 04 customer receivables
- Flow 05 customer returns
- Flow 06 supplier payables
- Flow 07 supplier returns
- Flow 08 stock transfer
- Flow 09 stocktake
- Flow 10 cashbook
- Flow 11 user permissions

If these are unstable, do not fix them here unless the issue is strictly inside report generation or report display.

---

## Fixed test dataset

Use or seed this exact report scenario so results are deterministic.

### Branches / stores
- `BR_A` — Branch A
- `BR_B` — Branch B

### Warehouses
- `KHO_A` mapped to `BR_A`
- `KHO_B` mapped to `BR_B`

### Products
- `SP001` — Water 500ml
- `SP002` — Biscuit Box

### Partners
- `KH001` customer
- `KH002` customer
- `NCC001` supplier

### Cash funds / accounts
- `CASH_MAIN`
- `BANK_MAIN` (optional if the app distinguishes tender accounts)

### Scenario date
- `D1 = 2026-04-01`

### Seeded transactions for BR_A on D1

#### Purchase receipt `PR001`
- Supplier: `NCC001`
- Warehouse: `KHO_A`
- Items:
  - `SP001` qty `10` cost `5,000`
  - `SP002` qty `8` cost `20,000`
- Gross purchase value = `210,000`
- Paid now = `100,000`
- Supplier payable created = `110,000`

#### Sales invoice `SI001`
- Customer: `KH001`
- Warehouse: `KHO_A`
- Items:
  - `SP001` qty `4` unit price `7,000`
- Gross sales value = `28,000`
- Paid now = `20,000`
- Customer receivable created = `8,000`

#### Sales invoice `SI002`
- Customer: `KH002`
- Warehouse: `KHO_A`
- Items:
  - `SP002` qty `2` unit price `30,000`
- Gross sales value = `60,000`
- Paid now = `60,000`
- Customer receivable created = `0`

#### Customer return `RT001`
- Based on invoice: `SI001`
- Items:
  - `SP001` qty `1` return amount `7,000`
- Refund now = `7,000`

#### Supplier return `SRN001`
- Based on purchase receipt: `PR001`
- Items:
  - `SP001` qty `2` cost `5,000`
- Supplier return value = `10,000`
- Refund now = `0`
- Supplier payable should decrease by `10,000`

#### Manual cashbook receipt `CBIN001`
- Amount = `5,000`
- Category = other income
- If the target app supports business-result posting, set it to be included in financial result.

#### Manual cashbook payment `CBOUT001`
- Amount = `3,000`
- Category = office expense
- If the target app supports business-result posting, set it to be included in financial result.

### Seeded transactions for BR_B on D1

#### Purchase receipt `PR002`
- Supplier: `NCC001`
- Warehouse: `KHO_B`
- Items:
  - `SP001` qty `3` cost `5,000`
- Gross purchase value = `15,000`
- Paid now = `15,000`
- Supplier payable created = `0`

#### Sales invoice `SI003`
- Customer: `KH002`
- Warehouse: `KHO_B`
- Items:
  - `SP001` qty `1` unit price `7,000`
- Gross sales value = `7,000`
- Paid now = `7,000`
- Customer receivable created = `0`

---

## Expected reconciliation totals

These totals are the expected results from the dataset above.

### BR_A expected totals
- Sales gross = `88,000`
- Customer return value = `7,000`
- Net sales = `81,000`
- Sales invoice count = `2`
- Customer return count = `1`
- Purchase gross = `210,000`
- Supplier return value = `10,000`
- Net purchased value = `200,000`
- Purchase receipt count = `1`
- Supplier return count = `1`
- Customer receivable ending = `1,000`
- Supplier payable ending = `100,000`
- Ending stock:
  - `SP001` = `5`
  - `SP002` = `6`
- Estimated COGS = `55,000`
- Estimated gross profit = `26,000`
- Estimated final profit = `28,000` **only if** manual cashbook income/expense are configured to hit business result.

### BR_B expected totals
- Sales gross = `7,000`
- Customer return value = `0`
- Net sales = `7,000`
- Sales invoice count = `1`
- Purchase gross = `15,000`
- Supplier return value = `0`
- Net purchased value = `15,000`
- Purchase receipt count = `1`
- Customer receivable ending = `0`
- Supplier payable ending = `0`
- Ending stock:
  - `SP001` = `2`
- Estimated COGS = `5,000`
- Estimated gross profit = `2,000`
- Estimated final profit = `2,000`

### All-branch expected totals
- Sales gross = `95,000`
- Customer return value = `7,000`
- Net sales = `88,000`
- Sales invoice count = `3`
- Customer return count = `1`
- Purchase gross = `225,000`
- Supplier return value = `10,000`
- Net purchased value = `215,000`
- Purchase receipt count = `2`
- Supplier return count = `1`
- Customer receivable ending = `1,000`
- Supplier payable ending = `100,000`
- Ending stock:
  - `SP001` = `7`
  - `SP002` = `6`
- Estimated COGS = `60,000`
- Estimated gross profit = `28,000`
- Estimated final profit = `30,000` **only if** manual cashbook income/expense are configured to hit business result.

### Mandatory note
If the target application computes COGS/profit using a different explicit costing mode than this seeded expectation, the agent must not silently overwrite the expectation. Instead:
- prove the costing method used,
- explain the difference,
- and mark it as `Pass with deviation` or `Fail` depending on whether the app intentionally diverges from the KiotViet-like target behavior.

---

## Required code inspection checklist

Before testing UI behavior, inspect and document:

- report menu structure and routing
- report DTO / transformer layer
- SQL or ORM aggregation queries for sales reports
- inventory/stock movement report logic
- customer debt / supplier debt report builders
- financial result aggregation logic
- branch filter implementation
- date-range filter implementation and timezone handling
- export implementation if supported
- chart-vs-table data source consistency
- report permission guards

---

## Test case execution format

For each test case, capture:
- Preconditions
- Steps
- Expected result
- Actual result
- Pass / Fail / NA / Pass with deviation
- Evidence: route, API response, screenshot path, DB observation, aggregation SQL/logs

---

# Flow 12 Test Cases

## 12A — Report menu availability

### Objective
Verify the application exposes reporting in a way equivalent to KiotViet's report area.

### Steps
1. Login as admin/report-capable user.
2. Open the main menu.
3. Locate the report area.
4. Verify whether the target app uses `Reports` or `Analysis -> Reports` or an intentional equivalent.
5. Confirm core report modules are discoverable.

### Expected
- A clear report entry point exists.
- At minimum, the app surfaces equivalent report pages for:
  - end-of-day / daily summary
  - sales
  - products / stock
  - customers / receivables
  - suppliers / payables
  - finance / business result

### Fail examples
- Reports only exist as hidden URLs.
- No equivalent navigation exists.
- Report permissions are broken for admin/report-capable users.

---

## 12B — Daily / end-of-day report

### Objective
Verify the daily report summarizes the day consistently with the source transactions.

### Steps
1. Open the daily/end-of-day report for `D1` and branch `BR_A`.
2. Record invoice count, return count, and visible money movement fields.
3. Repeat for `BR_B`.
4. Repeat for all branches.

### Expected
- BR_A reflects:
  - sales invoice count `2`
  - customer return count `1`
  - purchase receipt count `1` if purchases are included in the daily summary section
- BR_B reflects:
  - sales invoice count `1`
  - customer return count `0`
- All branches reflect:
  - sales invoice count `3`
  - customer return count `1`
- If the report shows receipts/payments, values must be internally consistent with posted source documents and cashbook entries.

### Pass with deviation
If the target app intentionally excludes purchase activity from end-of-day summary while KiotViet exposes broader operational data, mark the difference explicitly.

---

## 12C — Sales report totals

### Objective
Verify the sales report correctly aggregates gross sales, returns, and net sales.

### Steps
1. Open Sales report for `D1`, branch `BR_A`.
2. Capture gross sales, return value, net sales, and invoice count.
3. Repeat for `BR_B`.
4. Repeat for all branches.

### Expected
- BR_A:
  - gross sales `88,000`
  - return value `7,000`
  - net sales `81,000`
  - invoice count `2`
- BR_B:
  - gross sales `7,000`
  - return value `0`
  - net sales `7,000`
  - invoice count `1`
- All branches:
  - gross sales `95,000`
  - return value `7,000`
  - net sales `88,000`
  - invoice count `3`

### Fail examples
- Returns not deducted from net sales.
- Partial-payment invoices excluded from revenue.
- Branch filter mixes data across branches.

---

## 12D — Sales report filters and chart/table consistency

### Objective
Verify report filters work and chart totals do not contradict table totals.

### Steps
1. In Sales report, switch between chart and table views if both exist.
2. Filter by `BR_A`, `BR_B`, all branches.
3. Filter by date = `D1`.
4. If available, filter by seller/user/channel and confirm the app does not miscount.

### Expected
- Filtered totals match the seeded expectations above.
- Chart totals and table totals do not contradict each other.
- Branch filter scopes correctly.
- Date filter includes all D1 transactions only.

### Fail examples
- Table says `88,000`, chart says `95,000` for the same filter.
- Changing filter updates one widget but not others.

---

## 12E — Product / stock report totals

### Objective
Verify the product report correctly reflects sales, inventory movement, and ending stock.

### Steps
1. Open Product report / Inventory report for `D1`, branch `BR_A`.
2. Check product-level sales and stock movement for `SP001` and `SP002`.
3. Repeat for `BR_B`.
4. Repeat for all branches.

### Expected
- BR_A ending stock:
  - `SP001` = `5`
  - `SP002` = `6`
- BR_B ending stock:
  - `SP001` = `2`
- All branches ending stock:
  - `SP001` = `7`
  - `SP002` = `6`
- The report should reflect the movement logic:
  - purchase increases stock
  - sales decreases stock
  - customer return increases stock
  - supplier return decreases stock

### Pass with deviation
If the app separates inventory snapshots from product sales views, the result is acceptable as long as numbers reconcile and the separation is explicit.

---

## 12F — Product profitability / COGS report

### Objective
Verify product profitability or equivalent profit metrics are internally consistent.

### Steps
1. Open product profit/loss view for `D1`, all branches.
2. Capture COGS or equivalent cost figure if present.
3. Capture gross profit if present.
4. Compare with expected seeded totals.

### Expected
- Estimated total COGS = `60,000`
- Estimated gross profit = `28,000`
- At product level:
  - `SP001` net units sold = `4` across branches
  - `SP002` net units sold = `2`

### Mandatory note
If the app uses a different costing strategy, the agent must prove it and explain why the product profitability differs.

---

## 12G — Customer report / receivables report

### Objective
Verify customer-facing reports reflect customer revenue and ending receivable correctly.

### Steps
1. Open Customer report for `D1`, branch `BR_A`.
2. Check customer sales totals.
3. Check customer debt / receivable totals.
4. Repeat for all branches.

### Expected
- BR_A customer receivable ending = `1,000`
- All branches customer receivable ending = `1,000`
- `KH001` should remain owing `1,000` after partial payment and return.
- `KH002` should owe `0`.

### Fail examples
- Return does not reduce receivable.
- Fully paid customer still appears in debt report.

---

## 12H — Supplier report / payables report

### Objective
Verify supplier-facing reports reflect purchases, supplier returns, and ending payables correctly.

### Steps
1. Open Supplier report for `D1`, branch `BR_A`.
2. Check gross purchases, supplier return value, and ending payable.
3. Repeat for `BR_B`.
4. Repeat for all branches.

### Expected
- BR_A:
  - gross purchase `210,000`
  - supplier return `10,000`
  - ending supplier payable `100,000`
- BR_B:
  - gross purchase `15,000`
  - supplier return `0`
  - ending supplier payable `0`
- All branches ending supplier payable = `100,000`

### Fail examples
- Supplier return does not reduce payable.
- Fully paid branch still shows payable.

---

## 12I — Financial report / business result

### Objective
Verify the financial report derives a business result from revenue, cost, and expenses/income.

### Steps
1. Open Financial report for `D1`, branch `BR_A`.
2. Capture sales/net-sales, COGS, expense, income, and final profit if exposed.
3. Repeat for all branches.

### Expected
- BR_A:
  - net sales `81,000`
  - estimated COGS `55,000`
  - estimated gross profit `26,000`
  - estimated final profit `28,000` **if** `CBIN001` and `CBOUT001` are included in business result
- All branches:
  - net sales `88,000`
  - estimated COGS `60,000`
  - estimated gross profit `28,000`
  - estimated final profit `30,000` under the same assumption

### Pass with deviation
If the target app does not post generic cashbook entries into financial result by design, mark as `Pass with deviation` provided the behavior is explicit and consistent.

---

## 12J — Branch filter integrity

### Objective
Prove the branch filter isolates data correctly.

### Steps
1. Open each major report module.
2. Apply `BR_A`, `BR_B`, then all-branch filters.
3. Verify totals for sales, inventory, receivables, payables, and finance.

### Expected
- BR_A totals must never include `PR002` or `SI003`.
- BR_B totals must never include `PR001`, `SI001`, `SI002`, `RT001`, `SRN001`, `CBIN001`, `CBOUT001`.
- All-branch totals must equal `BR_A + BR_B` unless the app intentionally excludes certain document types from a given report.

---

## 12K — Report access permissions

### Objective
Verify users without report permissions cannot access restricted report modules.

### Steps
1. Login as report-capable admin user.
2. Confirm report access works.
3. Login as a restricted non-report user from Flow 11.
4. Attempt to access report routes and APIs directly.

### Expected
- Report-capable users can access reports normally.
- Restricted users are denied both in UI and on protected routes/APIs.

### Fail examples
- Report page hidden in menu but directly accessible by URL.
- API returns sensitive totals to unauthorized users.

---

## 12L — Export integrity (if supported)

### Objective
Verify exported report data matches on-screen filtered data.

### Steps
1. Open Sales report for `D1`, `BR_A`.
2. Export CSV/Excel if the feature exists.
3. Compare exported totals to on-screen totals.
4. Repeat for Product and Customer/Supplier reports if export exists.

### Expected
- Exported row count and totals match the filtered on-screen data.
- Branch and date filters are preserved in the export output.

### Pass with deviation
If export is not implemented in the target app, mark `NA - not implemented in target system`.

---

## 12M — Cross-report reconciliation

### Objective
Verify report modules agree with each other and with source transactions.

### Steps
1. Build a reconciliation matrix using source documents.
2. Compare:
   - Sales report net sales
   - Financial report net sales
   - Product report sales totals
   - Customer report receivables
   - Supplier report payables
   - Daily report invoice counts
3. Document every mismatch.

### Expected
The following should reconcile unless the app intentionally defines a metric differently:
- Sales report net sales = Financial report sales basis
- Customer receivable report = source open invoices after returns/payments
- Supplier payable report = source open purchase receipts after returns/payments
- Product stock totals = source inventory ledger ending quantities

### Fail examples
- Sales report says `88,000` net sales, Financial says `95,000` for the same scope.
- Receivable report says `8,000` while customer balance from source docs is `1,000`.

---

## Minimal fix policy

If a case fails, the agent must classify the defect before touching code:

1. **Posting defect**
   - transaction created wrong ledger/stock entries
2. **Aggregation defect**
   - report SQL/service sums wrong documents or ignores returns/payments
3. **Filter/scope defect**
   - date/branch/user/category filters leak or omit data
4. **Presentation defect**
   - UI labels, formatting, chart/table mismatch, rounding only

Apply the smallest safe fix only after classification.

---

## Reporting template the agent must return

```md
# Flow 12 Execution Report

## Audit Summary
- Report entry points:
- Aggregation architecture:
- Branch/date filter implementation:
- Costing/profit method detected:

## Test Case Results
| Case | Status | Notes |
|------|--------|-------|
| 12A | PASS/FAIL/NA | ... |
| 12B | PASS/FAIL/NA | ... |
| 12C | PASS/FAIL/NA | ... |
| 12D | PASS/FAIL/NA | ... |
| 12E | PASS/FAIL/NA | ... |
| 12F | PASS/FAIL/NA | ... |
| 12G | PASS/FAIL/NA | ... |
| 12H | PASS/FAIL/NA | ... |
| 12I | PASS/FAIL/NA | ... |
| 12J | PASS/FAIL/NA | ... |
| 12K | PASS/FAIL/NA | ... |
| 12L | PASS/FAIL/NA | ... |
| 12M | PASS/FAIL/NA | ... |

## Reconciliation Table
| Metric | Expected | Actual | Status | Evidence |
|--------|----------|--------|--------|----------|
| BR_A net sales | 81,000 | ... | ... | ... |
| BR_B net sales | 7,000 | ... | ... | ... |
| All-branch net sales | 88,000 | ... | ... | ... |
| BR_A supplier payable | 100,000 | ... | ... | ... |
| All-branch customer receivable | 1,000 | ... | ... | ... |
| Ending stock SP001 all branches | 7 | ... | ... | ... |
| Ending stock SP002 all branches | 6 | ... | ... | ... |
| Estimated gross profit all branches | 28,000 | ... | ... | ... |

## Defects Found
1. ...
2. ...

## Files Changed
- ...

## Retest Results
- ...

## Remaining Deviations from KiotViet
- ...
```

---

## Stop condition

Stop when one of the following is true:

1. All Flow 12 cases pass.
2. Remaining failures are documented as explicit unsupported features or intentional deviations.
3. A blocking defect outside report scope is identified and documented, but not repaired here.

