# KiotViet Flow 11 — User Management & Permissions Test Instruction

## Purpose
This instruction is for an autonomous coding agent (Claude, Antigravity, or similar) to audit, test, and minimally repair the application's **user management and permissions** behavior so it matches the target operational behavior of KiotViet as closely as possible.

This flow must be executed **independently**. Do not expand scope into unrelated flows unless a defect in this flow cannot be fixed without a tiny supporting change elsewhere.

---

## Source-of-truth behaviors to match
Use KiotViet behavior as the reference for this flow:

1. A user can be assigned a **role per branch/store scope**.
2. Existing roles can be selected, then individual permissions can be toggled on/off.
3. Saving updated permissions should force the affected user to **re-authenticate** or otherwise ensure the new permission set is applied immediately.
4. A user can be **deactivated** so they cannot access the system, then reactivated later.
5. A user can be **deleted**, but historical transactions associated with that user must remain intact.
6. If the application supports advanced product-group/category scoping, users restricted to a subset of groups should only see and operate on permitted products and related transactions.

If the target application does not support a specific KiotViet capability, mark it clearly as:
- `NA - not implemented in target system`
- or `Deviation - intentionally simplified`

Do not hide deviations.

---

## Agent operating rules

1. Read the current source code before making any change.
2. Identify the current auth / RBAC model used by the app:
   - framework guards / middleware
   - role-permission tables
   - policy/gate implementation
   - branch/tenant scoping
   - category/group visibility rules
3. Prefer to **write or run tests first** when feasible.
4. Only apply the **smallest safe change** needed to fix a confirmed mismatch.
5. After any change, rerun only the impacted test set first, then rerun all Flow 11 tests.
6. Never rewrite broad architecture for this flow unless required to unblock a confirmed core permission defect.
7. Record every mismatch in the report, even if not fixed.

---

## Expected deliverables

The agent must output:

1. A short audit summary of the current permission model.
2. A pass/fail table for every test case in this flow.
3. A defect list with root-cause hypotheses.
4. The exact files changed.
5. Retest results after changes.
6. Remaining deviations from KiotViet behavior.

---

## Preconditions

Before executing Flow 11, ensure these earlier flows are already stable enough to support permission checks:
- Flow 01 foundation data
- Flow 02 purchase receipt
- Flow 03 sales invoice
- Flow 08 stock transfer
- Flow 09 stocktake
- Flow 10 cashbook

If these are unstable, do not fix them here. Only use them as dependency flows to verify access control.

---

## Fixed test dataset

Use or seed this minimal dataset:

### Branches / stores
- `BR_A` — Branch A
- `BR_B` — Branch B

### Warehouses
- `KHO_A` mapped to `BR_A`
- `KHO_B` mapped to `BR_B`

### Product groups / categories
- `GRP_FOOD`
- `GRP_BEAUTY`

### Products
- `SP_F01` — group `GRP_FOOD`
- `SP_F02` — group `GRP_FOOD`
- `SP_B01` — group `GRP_BEAUTY`

### Partners
- `KH001` customer
- `NCC001` supplier

### Users to create

#### `admin01`
- full access

#### `sale_a01`
- branch-scoped to `BR_A`
- can create sales invoices
- can view customers
- cannot access system settings
- cannot manage users
- cannot access stocktake completion unless explicitly granted

#### `warehouse_a01`
- branch-scoped to `BR_A`
- can receive purchase receipts / stocktake / transfer operations
- cannot create sales invoices
- cannot view sensitive financial reports unless explicitly granted

#### `cashier_a01`
- branch-scoped to `BR_A`
- can create invoices and receive customer payments
- cannot edit user permissions
- cannot view supplier payable details unless granted

#### `viewer_b01`
- branch-scoped to `BR_B`
- read-only access for allowed screens
- no mutation rights

### Optional advanced user
#### `beauty_mgr01`
- if product-group restriction exists
- only allowed `GRP_BEAUTY`

---

## Required code inspection checklist

Before testing UI behavior, inspect and document:

- auth entry points and login flow
- user table structure
- role table / permission table structure
- branch/store scoping model
- middleware or policy classes used for route protection
- API authorization checks
- menu rendering conditions in frontend
- server-side enforcement for dangerous actions
- whether deleted/deactivated users preserve historical references

---

## Test case execution format

For each test case, capture:
- Preconditions
- Steps
- Expected result
- Actual result
- Pass / Fail / NA / Pass with deviation
- Evidence: route, API response, UI screenshot path, DB observation, logs

---

# Flow 11 Test Cases

## 11A — Create user account

### Objective
Verify a new user can be created with branch assignment and baseline role configuration.

### Steps
1. Login as `admin01`.
2. Open user management.
3. Create `sale_a01` with branch `BR_A` and the sales role.
4. Save.
5. Locate the new account in the user list.
6. Attempt login with that account.

### Expected
- User is created successfully.
- The assigned branch and role are stored correctly.
- The user can log in.
- The new user appears in user management with correct status = active.

### Fail examples
- User can be created without required fields.
- Branch assignment not persisted.
- Role saved in UI but not enforced after login.

---

## 11B — Role-based menu visibility

### Objective
Verify UI exposure matches assigned permissions, but do not rely on UI alone.

### Steps
1. Login as `sale_a01`.
2. Check visible menu items.
3. Confirm sales screens are visible.
4. Confirm restricted settings/user-management menus are hidden or disabled.
5. Repeat for `warehouse_a01`, `cashier_a01`, `viewer_b01`.

### Expected
- Each user sees only relevant modules for their role.
- Admin-only or finance-only sections are not exposed to unauthorized users.

### Mandatory note
UI hiding alone is insufficient. This test must be paired with API/route authorization cases below.

---

## 11C — Route and API authorization enforcement

### Objective
Ensure unauthorized users cannot bypass the UI by directly calling protected routes or APIs.

### Steps
1. Login as `sale_a01`.
2. Attempt to open restricted routes manually, such as:
   - user management
   - system settings
   - supplier payables admin screen
   - stocktake completion endpoint if not granted
3. Attempt equivalent API calls if the app is API-driven.
4. Repeat for `viewer_b01` attempting write actions.

### Expected
- Unauthorized requests are blocked server-side.
- Response is appropriate: 403 / redirect / policy error according to app design.
- No write-side effect occurs in the database.

### Fail examples
- Hidden menu, but direct URL still works.
- API returns success despite insufficient permission.

---

## 11D — Branch scope isolation

### Objective
Verify a branch-scoped user cannot improperly read or mutate data belonging to another branch.

### Steps
1. Seed or ensure transactions exist in both `BR_A` and `BR_B`.
2. Login as `sale_a01`.
3. Open lists for invoices, customers, stock, purchase receipts, and cashbook entries.
4. Try filtering/searching for branch B records.
5. Try directly opening a branch B record by URL or ID.
6. Repeat with `viewer_b01` against branch A records.

### Expected
- Branch-scoped users only see and operate on allowed branch data.
- Cross-branch records are hidden or denied.
- Totals and reports do not leak other branch data.

### Fail examples
- Lists appear filtered, but detail pages are still accessible.
- Related dropdowns expose forbidden branch entities.

---

## 11E — Permission change application behavior

### Objective
Verify that permission changes take effect immediately and do not leave stale session access.

### Steps
1. Login as `sale_a01` in one browser/session.
2. As `admin01`, remove sales permission from `sale_a01`.
3. Save changes.
4. In the existing `sale_a01` session, try to continue selling or refreshing the page.
5. Re-login and check effective permissions.

### Expected
- Existing session is invalidated, refreshed, or otherwise forced to apply new permissions.
- The user must not retain stale access to removed features.

### Pass with deviation
- If the app uses token refresh or short-session invalidation instead of immediate logout, record it explicitly.

---

## 11F — Deactivate and reactivate user

### Objective
Verify deactivated users cannot access the system but can later be restored.

### Steps
1. As `admin01`, deactivate `warehouse_a01`.
2. Try logging in as `warehouse_a01`.
3. If already logged in, try refreshing or performing an action.
4. Reactivate the account.
5. Login again and verify access is restored.

### Expected
- Deactivated user cannot authenticate or continue operating.
- Historical transactions created by that user remain intact.
- Reactivation restores access without corrupting history.

---

## 11G — Delete user while preserving history

### Objective
Verify deleting a user does not destroy historical transaction authorship.

### Steps
1. Create at least one invoice, purchase receipt, and stock-related action under `cashier_a01` or `warehouse_a01`.
2. Delete that user from admin.
3. Open the historical records created earlier.
4. Inspect author/operator fields in UI and database.

### Expected
- Historical records still exist.
- Historical authorship remains traceable through stored identifiers, display name snapshots, or equivalent preserved linkage.
- The system does not orphan critical transaction history.

### Fail examples
- Deleting the user breaks relational integrity.
- Historical records lose operator information entirely.

---

## 11H — Read-only user enforcement

### Objective
Verify read-only users cannot mutate data anywhere, even when screens are visible.

### Steps
1. Login as `viewer_b01`.
2. Attempt to:
   - create invoice
   - edit invoice
   - cancel invoice
   - create purchase receipt
   - create stocktake
   - create cashbook entry
3. Attempt same actions through direct requests if possible.

### Expected
- All mutation attempts are denied.
- View-only actions remain allowed where intended.
- No database write occurs.

---

## 11I — Product-group/category restriction (optional advanced case)

### Objective
If the target app supports group/category scoping similar to KiotViet product-group permission, verify that restriction is enforced consistently.

### Steps
1. Create or configure `beauty_mgr01` with access only to `GRP_BEAUTY`.
2. Login as `beauty_mgr01`.
3. Open product list.
4. Search for `SP_B01` and `SP_F01`.
5. Create sales/purchase/stock transactions using allowed and disallowed products.
6. Open partner records and historical transactions linked to mixed product groups if your app supports that distinction.

### Expected
- Only allowed products are visible/selectable.
- Disallowed products cannot be found by search, barcode, or direct API call.
- Related transaction visibility follows the implemented scoping rules.

### If unsupported
Mark `NA - product-group permission not implemented`.
Do not invent this feature inside Flow 11 unless the project explicitly intends to support it.

---

## 11J — Role lifecycle management

### Objective
Verify the app can create, edit, and delete custom roles safely.

### Steps
1. As `admin01`, create a custom role `inventory_checker`.
2. Grant only stock-related view/count permissions.
3. Assign it to a test user.
4. Verify access.
5. Modify the role to add one extra permission.
6. Verify access updates correctly.
7. Attempt to delete the role while still assigned.
8. Remove assignment, then delete again.

### Expected
- Role creation and editing succeed.
- Permission updates propagate correctly.
- Role deletion is blocked while in use, or handled safely according to app rules.

---

## 11K — Permission-sensitive audit logging

### Objective
Verify administrative changes to users/roles/permissions are logged or at least attributable.

### Steps
1. As `admin01`, create/edit/deactivate/reactivate/delete test users and adjust permissions.
2. Inspect activity logs / audit logs / DB trail.

### Expected
- Sensitive admin actions are attributable to the acting admin.
- Timestamp and target user are preserved if the system supports audit logs.

### If not implemented
Mark as deviation, not silent omission.

---

# Validation queries / assertions (adapt to target schema)

The agent should perform DB-level checks where possible:

- user exists with correct role mapping
- branch assignment row exists and matches expected branch
- deactivated user has active flag / status updated correctly
- deleted user does not destroy historical invoice/purchase/stock rows
- unauthorized mutation attempts create **no** write-side changes
- permission updates invalidate stale access as implemented

---

# Fix policy

Only fix defects that are clearly within Flow 11 scope:
- broken permission middleware
- missing policy checks
- incorrect frontend permission gating
- missing branch filters for secured lists/details
- stale permission cache/session invalidation issues
- unsafe delete/deactivate handling for users
- improper role assignment persistence

Do **not** rewrite the full auth system unless there is no smaller safe fix.

---

# Retest order after a fix

After every confirmed fix, rerun in this order:
1. the exact failing case
2. all authorization enforcement cases (11C, 11D, 11H)
3. all lifecycle cases (11E, 11F, 11G, 11J)
4. full Flow 11 suite

---

# Final report template

## Flow 11 Audit Summary
- Auth model:
- Permission model:
- Branch scoping model:
- Product-group scoping:
- Key deviations from KiotViet:

## Test Results
| Case | Result | Notes | Evidence |
|---|---|---|---|
| 11A |  |  |  |
| 11B |  |  |  |
| 11C |  |  |  |
| 11D |  |  |  |
| 11E |  |  |  |
| 11F |  |  |  |
| 11G |  |  |  |
| 11H |  |  |  |
| 11I |  |  |  |
| 11J |  |  |  |
| 11K |  |  |  |

## Defects
- D11-01:
- D11-02:

## Files Changed
- path/to/file

## Retest Results
- 

## Remaining Deviations
- 

