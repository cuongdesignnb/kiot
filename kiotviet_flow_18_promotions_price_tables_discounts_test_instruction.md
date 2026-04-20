# KiotViet Flow 18 — Promotions / Price Tables / Discounts
**Purpose:** Agent instruction to verify that the target system behaves like KiotViet for the combined flow of **Khuyến mại / Thiết lập giá / Chiết khấu**, and to apply **minimal fixes only when a proven deviation is found**.

---

## 0) Scope and non-goals

This flow covers only the combined commercial-pricing layer:

- activation and configuration of the promotion feature
- creation and lifecycle of promotion programs
- automatic vs manual application of promotions on invoice/order
- promotion stacking behavior
- keeping promotion from sales order when converting to invoice
- return-value recalculation for invoices that had promotion
- creation and lifecycle of price tables
- price-table formulas, auto-update from base price, rounding
- scope of price tables by branch / customer group / transaction creator
- restriction on selling items not present in a price table
- manual invoice discount (line-level / order-level) and its interaction with price tables and promotion
- search/filter/export/history around promotion usage and price-table usage
- permission checks for promotion management and operational use

This flow does **not** retest:
- basic sales invoice flow (Flow 03)
- sales order flow (Flow 15)
- return posting (Flow 05)
- reports and final reconciliation (Flow 12)

Only verify their interaction points that affect promotion/price/discount behavior.

---

## 1) Ground truth reference behavior from KiotViet

Use KiotViet as the behavioral reference for this flow:

### Promotion behavior
1. Promotion feature is enabled in settings. Store can also configure:
   - allow stacking multiple promotions on one order,
   - allow promotion on sales orders,
   - auto-apply promotion on invoices when conditions are met.
2. Promotion programs support:
   - effective date range,
   - schedule by month/day/week/hour,
   - scope by branch / seller / customer group,
   - limit on number of times applied per customer,
   - multiple promotion forms such as invoice discount, product discount, gift item, voucher, points, quantity-based sale pricing.
3. On sales screen, when the invoice or line qualifies, the system displays a gift-box indicator and can auto-apply promotion if configured.
4. If a promotion program already has transactions:
   - only some metadata can still be edited,
   - deletion is allowed only when there are no transactions yet.
5. When converting a sales order that already had promotion:
   - the system keeps the promotion from order time,
   - and can offer to cancel old promotion and re-select based on current programs.
6. When processing a return from an invoice that had promotion:
   - return value is recalculated from the discounted item price,
   - and invoice-wide promotion is prorated accordingly.
7. Promotion permissions can be controlled separately for View / Create / Edit / Delete.

### Price-table behavior
8. Price tables can be created from **Hàng hóa → Thiết lập giá**.
9. When creating a price table, the store can set:
   - name,
   - effective time,
   - status,
   - formula from another base price table,
   - auto-update from base price,
   - copy items from base price table,
   - automatic rounding.
10. A price table can define whether cashier:
   - may add items not in that table (with warning), or
   - may only add items existing in that table.
11. Scope can be configured by branch, customer group, and transaction creator.
12. Products can be added manually, by all items, by category/group, or import from Excel.
13. Price tables can be updated, deleted, compared side-by-side, and exported.
14. KiotViet supports comparing selling price against cost / last purchase price.
15. From other flows like product creation, purchase receipt, purchase order, price values can also be updated through the price-table utility path.

### Manual discount behavior
16. Manual discount can be applied:
   - per line item, or
   - per whole invoice.
17. For invoice-level discount, KiotViet distributes discount proportionally to lines for downstream calculations in e-invoice flow.
18. Discount selection starts from “No discount”, then user chooses line-level or invoice-level mode.

---

## 2) Agent operating rules

You must follow these rules strictly:

1. **Read source before changing anything.**
   - Find all promotion-related routes, controllers, services, models, settings, scopes, UI screens.
   - Find all price-table-related routes, controllers, services, models, formulas, schedulers, UI screens.
   - Find invoice discount logic and line repricing logic.

2. **Do not refactor broadly.**
   - Only apply the smallest fix that corrects a proven deviation from the flow.

3. **Do not change unrelated flows.**
   - If a fix touches sales posting, return logic, reports, or customer groups, note the ripple effect and re-test the linked mini-set only.

4. **Log every deviation with evidence.**
   - Include reproduction, expected behavior, actual behavior, affected files, and whether the issue is config, UI, API, state, or domain-calculation level.

5. **Retest after every fix.**
   - Re-run failed cases first, then the regression mini-set.

6. **Never mark PASS by visual guess.**
   - Confirm both visible behavior and persisted state / calculation artifacts.

---

## 3) Preconditions

Before executing this flow, confirm these are already working:

- Flow 01: foundation data
- Flow 03: sales invoice
- Flow 05: customer return
- Flow 11: permissions
- Flow 12: reports/reconciliation
- Flow 15: sales order

If any prerequisite is broken, stop and mark this flow as **BLOCKED**.

---

## 4) Fixed test dataset

Use a deterministic dataset. Do not improvise random names.

### Branches
- `CN_A`
- `CN_B`

### Customer groups
- `CG_RETAIL`
- `CG_VIP`

### Customers
1. `KH_PROMO_01` — customer group `CG_RETAIL`
2. `KH_PROMO_02` — customer group `CG_VIP`

### Users
- `admin` — full access
- `sale01` — can create invoices/orders
- `promo_manager01` — can manage promotion and price tables
- `viewer01` — view only

### Items
1. `SP_P01` — Giá gốc 100,000 — stock item
2. `SP_P02` — Giá gốc 200,000 — stock item
3. `SP_P03` — Giá gốc 50,000 — stock item
4. `SP_GIFT_01` — Giá gốc 30,000 — stock item used as gift item
5. `SP_LIMIT_01` — only included in restricted price table scenario

### Base/common price
- Common/base price is treated as default retail price.

---

## 5) Source discovery checklist

Before testing, inspect and document:

### Promotion
- settings / feature flags
- promotion header table
- promotion rule / condition table
- promotion scope tables
- promotion usage history linkage
- promotion application service
- order → invoice promotion preservation logic
- return recalculation logic for promotion-applied invoices
- permissions / policy / middleware

### Price table
- price table header table
- price table item table
- formula / derivation service
- auto-update from base price path
- branch / customer-group / creator scope tables or rules
- restriction flag for items not in price table
- export / compare query layer
- update / delete rules

### Manual discount
- invoice line pricing service
- invoice header discount service
- proportional allocation logic
- final net amount source of truth

Deliver a short map:

- routes:
- controllers:
- services:
- models:
- tables:
- enums/status:
- policies/middleware:
- UI screens:
- jobs/events if any:

Do this before running cases.

---

## 6) Canonical state / truth model to validate

Suggested equivalence mapping:

### Promotion program
- `draft` / `inactive`
- `active`
- `expired`
- `disabled`
- `deleted` (soft or hard)

### Price table
- `applied`
- `inactive`
- `expired`
- `deleted`

Names may differ, but behavior must map closely. Create a mapping table in the report if needed.

---

## 7) Core invariants

Validate these invariants in every relevant case:

1. Promotion eligibility is determined from current invoice/order context, configured schedule, configured scope, and enabled settings.
2. If auto-apply is disabled, eligible promotions should not silently apply without user confirmation.
3. If stacking is disabled, only the allowed single promotion set should remain active.
4. Promotion usage history must be traceable from the promotion program and from the invoice/order.
5. If promotion already has transactions, full edit/delete restrictions must follow the designed rule.
6. Price table selection must deterministically reprice invoice lines according to scope and configuration.
7. If a restricted price table forbids items outside the table, UI and backend must both enforce it.
8. Manual discount must not corrupt price-table-selected prices or promotion-applied prices silently.
9. Return values for promotion-applied invoices must be recalculated from the discounted basis, not the original list price.
10. Order-to-invoice conversion must preserve prior promotion when designed to do so.
11. Reports / list filters using price-table or promotion should match detail truth.
12. Permission denial must hold at both UI and API/backend levels.

---

## 8) Test cases

Run cases in order. Stop only for blockers.

### 18A — Activate promotion feature and base settings
**Goal:** verify promotion feature activation and global options.

Steps:
1. Login as `admin`.
2. Open settings for customer/promotion feature.
3. Enable promotion feature.
4. Toggle and save these options:
   - allow stacking promotions
   - allow promotion on sales orders
   - auto-apply promotion on invoices
5. Disable and re-enable each option to verify persistence.

Expected:
- settings persist correctly
- promotion management UI appears only when enabled
- invoice/order screen behavior changes according to setting
- direct API access is blocked or handled safely when feature is disabled

DB / backend checks:
- feature flag and related options persist
- no stale cache causes wrong behavior after toggle

---

### 18B — Create invoice-level promotion program
**Goal:** verify creation of an invoice-level discount promotion.

Steps:
1. Login as `promo_manager01`.
2. Create promotion:
   - name: `PROMO_INV_10PCT`
   - effective: active now
   - scope: invoice
   - form: invoice discount
   - condition: invoice from 500,000
   - discount: 10%
   - apply scope: branch `CN_A`, customer group `CG_RETAIL`
3. Save.

Expected:
- promotion saves successfully
- rule, effective period, and scope persist correctly
- program is visible in promotion list as active

DB checks:
- promotion header exists
- one or more condition/reward records exist
- scope records for branch/group persist correctly

---

### 18C — Create product-level gift / discount promotion
**Goal:** verify product-linked promotion behavior.

Steps:
1. Create a promotion:
   - product scope
   - target item `SP_P01`
   - reward: gift item `SP_GIFT_01` or discount on target item depending on system support
   - active now
2. Save.

Expected:
- program saves successfully
- target item and reward item linkage persist
- promotion appears in management list

Note:
- If your system supports multiple specific product-promotion forms, test the most KiotViet-like mapping and document unsupported variants as `NA`, not `PASS`.

---

### 18D — Auto-apply eligible promotion on invoice
**Goal:** verify auto-apply behavior.

Steps:
1. Ensure auto-apply promotion on invoices is enabled.
2. Login as `sale01`.
3. Create invoice in `CN_A` for customer `KH_PROMO_01`.
4. Add items so subtotal exceeds 500,000 and meets `PROMO_INV_10PCT`.
5. Observe sales screen.

Expected:
- gift-box/eligible-promotion indicator appears
- promotion is auto-applied if setting is enabled
- invoice net total reflects correct discount
- promotion linkage is stored on invoice

DB checks:
- invoice total / discount fields match UI
- promotion usage link/history exists
- price basis remains internally consistent

---

### 18E — Manual apply promotion when auto-apply is off
**Goal:** verify operator-driven application.

Steps:
1. Disable auto-apply promotion on invoices.
2. Recreate an eligible invoice.
3. Observe indicator for available promotion.
4. Manually open promotion chooser and apply the valid promotion.

Expected:
- promotion does not silently apply before user action
- eligible promotion list only includes valid promotions
- applying one recalculates invoice exactly once
- invoice stores selected promotion correctly

---

### 18F — Stacking vs non-stacking behavior
**Goal:** verify promotion stacking setting.

Steps:
1. Create two eligible promotions for the same invoice context.
2. With stacking disabled:
   - attempt to apply both
3. With stacking enabled:
   - attempt again

Expected:
- disabled mode enforces non-stacking logic
- enabled mode allows combined application only where the promotion types are compatible by design
- totals are deterministic and traceable

Fail examples:
- both apply even when stacking disabled
- stacking order produces unstable totals on refresh
- stored total differs from recomputed total

---

### 18G — Promotion scope by branch / seller / customer group
**Goal:** verify scoped eligibility.

Steps:
1. Use promotion scoped to:
   - branch `CN_A`
   - customer group `CG_RETAIL`
2. Test 4 combinations:
   - `CN_A` + `CG_RETAIL`
   - `CN_A` + `CG_VIP`
   - `CN_B` + `CG_RETAIL`
   - different seller if your system scopes by creator/seller
3. Create eligible invoices in each combination.

Expected:
- only matching scope receives the promotion
- non-matching scope does not show or apply it
- UI and backend use the same scope truth

---

### 18H — Sales order keeps prior promotion when converting to invoice
**Goal:** verify order-time promotion preservation.

Steps:
1. Ensure promotion-on-sales-order setting is enabled.
2. Create a sales order that qualifies for promotion.
3. Apply promotion on the order.
4. Convert order to invoice later.
5. Observe system behavior.

Expected:
- existing promotion is preserved from order time
- system may prompt to cancel old promotion and re-select according to current programs
- final invoice stores a traceable decision path

Checks:
- order promotion link exists
- converted invoice promotion link reflects preserved or reselected choice
- no duplicate double-discount

---

### 18I — Return from invoice that had promotion
**Goal:** verify return-value recalculation.

Steps:
1. Create and complete an invoice with promotion applied.
2. Perform a partial return on one item.
3. Perform full return on another promo-applied invoice if needed.

Expected:
- return value is based on discounted item value
- for invoice-wide promotion, return amount is reduced proportionally
- stock and receivable posting follow the adjusted return value, not original list price

DB checks:
- return document values match prorated discount logic
- no mismatch between displayed refund and persisted refund

---

### 18J — Edit / copy / delete promotion after transactions
**Goal:** verify lifecycle rules.

Steps:
1. Open a promotion that already has transactions.
2. Try:
   - full edit of business conditions
   - metadata edit (name, effective date, status, schedule, scope)
   - delete
   - copy
3. Open another promotion with no transactions and repeat.

Expected:
- after transactions exist, only allowed metadata can be edited
- delete is blocked when transactions exist
- copy produces a fresh independent promotion
- for no-transaction promotion, delete can succeed safely

DB checks:
- business rule restrictions enforced in backend
- copied program has new id/code and no usage history

---

### 18K — Create price table with formula from base price
**Goal:** verify price-table creation and formula behavior.

Steps:
1. Login as `promo_manager01`.
2. Create price table:
   - name: `BG_SI_10`
   - effective now
   - status applied
   - formula from common/base price = minus 10%
   - auto-update from base price = ON
   - copy items from base price = ON
   - rounding = nearest 1,000 or configured supported option
3. Save.

Expected:
- table saves successfully
- items are copied if option enabled
- repriced values follow formula and rounding
- active state is visible in list

DB checks:
- price table header exists
- line items copied/generated
- derived prices persist correctly

---

### 18L — Add/update items in price table manually / import / by group
**Goal:** verify population and maintenance of price-table items.

Steps:
1. In `BG_SI_10`, add products by:
   - manual single item
   - add by group/category
   - import Excel if supported
2. Update one line price directly.
3. Apply batch formula to all items.

Expected:
- all supported add methods persist correctly
- batch formula updates values consistently
- direct override is stored exactly once and not lost unexpectedly

Checks:
- item count after each method is correct
- no silent duplicate rows
- import validation behaves explicitly

---

### 18M — Price-table scope by branch / customer group / transaction creator
**Goal:** verify runtime price-table selection.

Steps:
1. Set `BG_SI_10` scope to:
   - branch `CN_A`
   - customer group `CG_VIP`
2. Create invoices with different combinations of branch/customer group/creator.
3. Observe chosen unit price.

Expected:
- only matching scope receives `BG_SI_10`
- non-matching scope falls back to default/common price or another eligible table by design
- detail/list screens reflect the actual price table used

Checks:
- invoice references resolved price table id/name if your system stores it
- line prices equal expected resolved table values

---

### 18N — Restrict cashier to items in price table only
**Goal:** verify enforcement of restricted tables.

Steps:
1. Create a restricted price table where cashier may only add items in that table.
2. Include `SP_LIMIT_01`, exclude `SP_P03`.
3. Start invoice under that price-table scope.
4. Add `SP_LIMIT_01` then try `SP_P03`.

Expected:
- allowed item is added normally
- excluded item is blocked, or warning/denial appears according to config
- backend must also reject excluded item if UI is bypassed

Fail examples:
- UI warns but API still saves excluded item
- excluded item gets priced from default table even though restriction is enabled

---

### 18O — Manual invoice discount with price table selected
**Goal:** verify interaction between price tables and manual discount.

Steps:
1. Create invoice where `BG_SI_10` applies.
2. Add items and confirm table price is used.
3. Apply manual discount:
   - per line on one invoice
   - per order on another invoice
4. Compare line totals and invoice total.

Expected:
- manual discount starts from the already resolved table price
- line-level discount affects only target line
- invoice-level discount is allocated consistently
- repricing is stable after save/reopen

Checks:
- persisted net unit price / line discount / invoice discount remain coherent
- no double-application of base formula

---

### 18P — Promotion + price table interaction
**Goal:** verify combined commercial behavior.

Steps:
1. Create invoice where a scoped price table applies.
2. Add items that also qualify for a promotion.
3. Apply the promotion.
4. Optionally add a manual order discount if business allows it.

Expected:
- system follows a deterministic order of operations
- resolved unit price from price table is not randomly replaced by base/common price during promotion
- final invoice values are stable after save/reopen
- if certain combinations are not allowed by design, system blocks them explicitly

Important:
Document the actual calculation order your system uses, for example:
1. resolve price table
2. apply line discounts / product promotions
3. apply invoice-wide promotion
4. apply manual invoice discount
Only mark PASS if the behavior is stable, explicit, and traceable.

---

### 18Q — Search / history / export for promotions and price tables
**Goal:** verify management and traceability.

Steps:
1. Open promotion management list.
2. Search/filter for created promotion.
3. Open its detail and inspect:
   - information tab
   - orders with promotion
   - invoices with promotion
4. Export invoice list with promotion if supported.
5. Open price-table screen.
6. Compare up to multiple price tables side by side.
7. Export selected price table.

Expected:
- promotion usage history matches real documents
- export matches filtered records
- price-table compare view shows consistent prices
- expired/inactive tables are clearly distinguishable if system supports that UI

---

### 18R — Permissions
**Goal:** verify promotion/price permission enforcement.

Steps:
1. Login as `viewer01`.
2. Verify read-only access if allowed.
3. Login as `sale01`.
4. Try to manage promotion programs or price tables directly.
5. Login as `promo_manager01`.
6. Verify create/edit/delete permissions as configured.

Expected:
- promotion permissions can distinguish view/create/edit/delete
- price-table management access is restricted appropriately
- backend rejects unauthorized create/edit/delete even if UI is bypassed
- operational invoice creation still works for `sale01` within allowed scope

---

## 9) Minimal regression set after any fix

After fixing any failed case, rerun:

- 18D auto-apply promotion
- 18F stacking / non-stacking
- 18H order-to-invoice keep promotion
- 18I return recalculation
- 18K create price table from formula
- 18M price-table scope
- 18N restricted table item enforcement
- 18O manual discount with price table
- 18P promotion + price-table interaction
- 18R permissions

If the fix touched invoice recalculation, also rerun key pricing cases from Flow 03.
If the fix touched sales-order conversion, rerun key cases from Flow 15.
If the fix touched return value logic, rerun key cases from Flow 05.
If the fix touched reporting/list truth, rerun smallest linked checks from Flow 12.

---

## 10) Defect classification

Tag each defect with one primary label:

- `PROMO_FEATURE_FLAG`
- `PROMO_CREATE`
- `PROMO_SCOPE`
- `PROMO_AUTO_APPLY`
- `PROMO_STACKING`
- `PROMO_ORDER_CONVERSION`
- `PROMO_RETURN_RECALC`
- `PROMO_EDIT_DELETE_RULE`
- `PROMO_USAGE_HISTORY`
- `PRICE_TABLE_CREATE`
- `PRICE_TABLE_FORMULA`
- `PRICE_TABLE_AUTO_UPDATE`
- `PRICE_TABLE_SCOPE`
- `PRICE_TABLE_ITEM_IMPORT`
- `PRICE_TABLE_RESTRICTED_ITEMS`
- `PRICE_TABLE_COMPARE_EXPORT`
- `DISCOUNT_LINE_LEVEL`
- `DISCOUNT_ORDER_LEVEL`
- `PRICE_PROMO_INTERACTION`
- `PROMO_PERMISSION`
- `PRICE_PERMISSION`

Severity:
- `S1` corrupts totals or commercial truth
- `S2` wrong scope/status/history but workaround exists
- `S3` UI mismatch or weak UX
- `S4` cosmetic only

---

## 11) Report format required from the agent

Use this exact structure.

### A. Summary
- Flow: 18 — Promotions / Price Tables / Discounts
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
- jobs/events:

### C. Case results
For each case 18A → 18R provide:
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
- promotion programs created:
- promotion usages linked:
- affected invoices:
- affected orders:
- returns checked:
- price tables created:
- price table item counts:
- resolved table-price snapshots:
- manual discount snapshots:
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

- invoice creation is globally broken
- sales-order conversion is globally broken
- customer group / branch scope is unusable
- price-table resolution cannot be observed or logged
- promotion feature settings cannot be toggled in test env
- returned-invoice flow is too inconsistent to trust recalculation tests

---

## 13) Fix policy

When fixing:
1. Prefer domain/service-level fixes over UI-only masking.
2. Preserve existing invoice/order/promotion/price-table ids and history.
3. Add or update automated tests if infrastructure exists.
4. If no test harness exists, add at least one reproducible scripted check for the defect.
5. Do not fake promotion usage history in UI if persistence is actually missing.
6. Do not silently recompute totals differently between create and reopen.

---

## 14) Completion criteria

Flow 18 is considered complete only if:

- core cases 18B, 18D, 18F, 18H, 18I, 18K, 18M, 18N, 18O, 18P, 18R all PASS
- no unresolved S1 or S2 defect remains
- promotion history is traceable
- price-table scope is deterministic
- restricted-item rule is enforced in UI and backend
- return recalculation for promotion-applied invoices is correct
- permissions pass

If any of the above fails, this flow is not complete.
