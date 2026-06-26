# Sapo - Kiot Supplier Debt Parity Audit

## Audit Pins

- Audit time: `2026-06-26 16:51:24 +07:00`
- Source repository: `cuongdesignnb/sapo`
- Source branch/SHA: `origin/main` at `884df654191a9113b02a509a3bf4670adb08ae75`
- Target repository: `cuongdesignnb/kiot`
- Target base SHA: `c5b1ffbccf4a6b48fc57ec1ed99d7e9c6fda4b87`
- Working branch: `audit/sapo-kiot-supplier-debt-parity`
- Audit data safety: read-only code/schema/test audit. No production DB, no migration, no backfill, no debt rebuild, no legacy cleanup.

## File And Symbol Inventory

| Group | Sapo source | Kiot target | Runtime call | Status | Conclusion |
|---|---|---|---|---|---|
| Supplier controller payment | `SupplierController::recordPayment()` | `SupplierController::recordPayment()` | `POST /api/suppliers/{id}/payment` | Equivalent write contract | Creates `SupplierDebtTransaction`, creates `CashFlow`, updates `Purchase.paid_amount/debt_amount`, updates cached `customers.supplier_debt_amount`. |
| Supplier debt tab API | `SupplierController::debtTransactions()` | `SupplierController::debtTransactions()` | `GET /api/suppliers/{id}/debt-transactions` | Diverged by runtime mode | Kiot default mode is `document`; production UI uses `SupplierDebtDocumentTimelineService`, not only `PartnerDebtLedgerService`. |
| Supplier payable ledger | `PartnerDebtLedgerService::buildSupplierPayableLedger()` | `PartnerDebtLedgerService::buildSupplierPayableLedger()` | `mode=legacy` | Similar, partly hardened | Has grouping by real payment code and legacy TTNH fallback guards. Not the default UI path for this incident. |
| Supplier document timeline | Not equivalent in Sapo as primary baseline | `SupplierDebtDocumentTimelineService::build()` | default supplier debt tab | Kiot-only adapter | This service reconstructs purchases, real CashFlow payments, fallback TTNH, returns, offsets, and dual-role mirror. It is the incident path. |
| Supplier UI | `resources/js/Pages/Suppliers/Index.vue` | `resources/js/Pages/Suppliers/Index.vue` | supplier tab | Equivalent consumer | UI displays `summary.display_balance_final/current_debt` and row running balance from API. No UI-only fix chosen. |
| Routes | `routes/api.php` | `routes/api.php` | supplier debt/payment APIs | Equivalent | Route is present. |
| Purchase flow | `PurchaseController` | `PurchaseController` | create/update/cancel purchase | Equivalent enough for scope | Direct purchase payments create `CashFlow reference_type=Purchase` and update `Purchase.paid_amount/debt_amount`. |
| CashFlow flow | `CashFlow` model/controller | `CashFlow` model/controller | payment records | Compatible with caveat | `CashFlow::active()` is NULL-safe for `cancelled`; document service must not count cancelled/deleted rows as real payments. |
| Supplier debt transaction | `SupplierDebtTransaction` | `SupplierDebtTransaction` | supplier payment ledger | Compatible schema | No persistent supplier allocation table exists; generic payments allocate by write-path behavior. |
| Tests | Supplier debt/timeline tests | Supplier debt/timeline tests | PHPUnit | Missing P0 fixture | Existing tests cover direct payment and some legacy fallback. Missing production-shaped generic SupplierPayment + fallback residual fixture. |

## Call Graph Payment/Debt

### Create purchase

`PurchaseController` writes:

- `purchases.total_amount`
- `purchases.discount`
- `purchases.paid_amount`
- `purchases.debt_amount`
- direct payment `CashFlow` when payment is entered on the purchase
- cached `customers.supplier_debt_amount`

### Generic supplier payment

`SupplierController::recordPayment()` writes inside a DB transaction:

- `supplier_debt_transactions` row with `type=payment`, `code=PCPN...`, `amount=-payment_amount`
- `cash_flows` row with same `code`, `type=payment`, `target_type=Nha cung cap`, `target_id=supplier_id`, `reference_type=SupplierPayment`, `reference_code=PCPN...`
- `purchases.paid_amount/debt_amount` updated by manual allocation or FIFO auto allocation
- `customers.supplier_debt_amount` decreased by payment amount

No persistent supplier payment allocation table exists in current Kiot schema.

### Supplier debt tab default runtime

`SupplierController::debtTransactions()` defaults to `mode=document`, so the UI calls:

`SupplierDebtDocumentTimelineService::build($supplier, $request->all())`

This service creates:

- purchase rows: `+Purchase.total_amount`
- direct purchase CashFlow rows: `-CashFlow.amount`
- legacy fallback TTNH rows from `Purchase.paid_amount`
- generic supplier CashFlow rows: `-CashFlow.amount`
- supplier debt transaction rows not already represented by same code
- returns/offsets/dual-role mirror rows

## Schema Compatibility Matrix

| Table | Relevant Kiot columns | Compatibility result |
|---|---|---|
| `customers` | `debt_amount`, `supplier_debt_amount`, `is_customer`, `is_supplier`, `status` | Compatible. Cached supplier payable is the list aggregate source. |
| `purchases` | `supplier_id`, `code`, `total_amount`, `discount`, `paid_amount`, `debt_amount`, `status`, `purchase_date`, `created_at` | Compatible. `paid_amount` is projection/snapshot and legacy fallback evidence, not always independent payment evidence. |
| `cash_flows` | `code`, `type`, `amount`, `time`, `target_type`, `target_id`, `reference_type`, `reference_code`, `status`, `deleted_at` | Compatible. Real payment evidence when active and matching supplier or purchase. |
| `supplier_debt_transactions` | `supplier_id`, `code`, `type`, `amount`, `debt_remain`, `purchase_id`, `note`, timestamps | Compatible. No allocation payload. Same-code CashFlow + ledger represent one business payment. |
| `purchase_returns` | `supplier_id`, `code`, `total_amount`, `status`, `return_date`, timestamps | Compatible. Decreases payable. |
| `debt_offsets` | `customer_id`, `code`, `amount`, `status`, cancel fields | Compatible. Decreases/increases payable depending event. |
| `customer_debts`, `invoices` | dual-role mirror fields | Compatible for partner view. Not the P0 incident root. |

No migration is required for the selected hotfix.

## Behavioral Parity Matrix

| Flow | Sapo contract observed | Kiot current behavior at base | Status | Risk |
|---|---|---|---|---|
| Direct purchase payment | CashFlow `reference_type=Purchase` is real payment evidence; do not also synthesize TTNH. | Document timeline suppresses fallback for direct reference. | Equivalent | Low |
| Generic supplier payment | One business payment has CashFlow + SupplierDebtTransaction + purchase `paid_amount` updates. | Document timeline emits generic `PCPN` and still may synthesize full TTNH for purchases updated by that payment. | Diverged | High, double counts payment. |
| Legacy paid purchase without real payment | `Purchase.paid_amount` may synthesize virtual TTNH. | Document timeline does this. | Equivalent | Medium, must remain for legacy. |
| Partial covered paid amount | Fallback should represent only residual uncovered `paid_amount`. | Document timeline currently emits full `paid_amount` if no direct match. | Missing | High |
| Ambiguous generic payment | Do not mutate DB or hide warning by data update. | No diagnostic-specific handling in document service. | Partial | Medium |
| CashFlow + SupplierDebtTransaction same code | One economic payment. | Existing code de-dupes ledger row after CashFlow code exists. | Equivalent | Low |
| Cancelled CashFlow | Must not count as real payment. | `CashFlow::active()` handles `cancelled` and `deleted_at`; accent variants are a known broader hardening area. | Partial | Medium |
| Sorting/running balance | Closing balance must be independent of display order. | Current balance sort is chronological; incident mismatch is not just row order. | Equivalent | Low |

## Canonical Supplier Debt Contract

1. Economic event evidence sources:
   - Purchase: `purchases.total_amount` increases payable.
   - Direct payment: active `cash_flows` with `reference_type=Purchase` and purchase `reference_code`.
   - Generic supplier payment: active `cash_flows` with supplier target or `reference_type=SupplierPayment`, grouped with same-code `supplier_debt_transactions`.
   - Legacy fallback: residual `purchases.paid_amount` not explained by real payment evidence.
2. Business payment identity:
   - Primary key: `cash_flows.code` / `supplier_debt_transactions.code` when both exist.
   - Direct allocation key: `reference_type=Purchase` + `reference_code=PN...`.
   - Generic allocation key: supplier id + active generic payment amount + write-path FIFO/manual purchase projection.
3. CashFlow and SupplierDebtTransaction with same payment code are one business payment. Timeline may display one financial row; balance must apply it once.
4. `Purchase.paid_amount` is a projection/snapshot for current purchase state and a fallback legacy evidence only for the uncovered residual.
5. TTNH fallback may be generated only for `uncovered_paid_amount > 0.01`.
6. Fallback must be suppressed for the covered part explained by direct Purchase CashFlow or generic supplier payment allocation.
7. When ambiguity cannot be resolved, do not update DB, do not delete rows, keep reconcile diagnostics/warning. The selected P0 hotfix uses the existing write-path FIFO contract; it does not run subset-sum guessing.
8. Closing payable balance formula for supplier tab: sum purchase totals minus real payments minus residual fallback payments minus returns/offsets/discounts/adjustments.
9. Running balance is calculated oldest to newest; display can be newest first, but closing balance must not change with pagination or display order.
10. Reconcile compares computed document balance to `customers.supplier_debt_amount`; code must fix document reconstruction, not overwrite stored balance.

## Incident Reconstruction

Production read-only numbers supplied:

- Supplier: `NCC177466273054` / `Hai Rang Thua`
- Total purchases: `45,530,000`
- Total purchase paid amount: `42,630,000`
- Stored supplier debt: `2,900,000`
- Expected: `45,530,000 - 42,630,000 = 2,900,000`
- Timeline before hotfix: `document_final_balance=-11,980,000`
- Difference: `-14,880,000`

The difference equals the two generic payment amounts:

- `PCPN260422188 = 11,640,000`
- `PCPN260626688 = 3,240,000`

Classification: mixed cause, mostly incorrect/partial Kiot port in default document timeline. The payment write path updates `Purchase.paid_amount`, then document timeline also emits generic `PCPN` and full TTNH fallback for the same paid amount. This is not a stored balance bug and must not be fixed by changing `customers.supplier_debt_amount`.

## Proposed Fix Options

| Option | Description | Trade-off | Decision |
|---|---|---|---|
| Update stored supplier debt | Force DB to match timeline | Unsafe, hides duplicate event, forbidden | Reject |
| UI hide warning or force display balance | Makes screen look aligned | Hides accounting error, forbidden | Reject |
| Remove all TTNH fallback when any generic payment exists | May undercount legitimate legacy paid purchases | Too broad | Reject |
| Residual fallback by real payment coverage | Compute direct/generic real payment coverage and synthesize only uncovered `paid_amount` | Correct scope; no DB write; needs tests | Chosen |

## Chosen Fix

Patch `SupplierDebtDocumentTimelineService` only:

- Calculate real payment coverage per purchase.
- Count direct Purchase CashFlow against its purchase.
- Allocate generic supplier payments over purchase paid residual using the same FIFO order used by `SupplierController::recordPayment()` auto mode.
- Do not allocate generic supplier payments into purchases whose purchase time is after the payment time.
- Generate TTNH fallback only for residual uncovered paid amount.
- Keep generic PCPN payment visible as a real payment row once.
- Do not mutate `customers`, `purchases`, `cash_flows`, or `supplier_debt_transactions`.

`SupplierController::debtTransactions()` also adds the read-only alias `summary.current_debt` to the API response so the endpoint exposes the same contract as the document timeline service. This is outside the default P0 file list, but it is response-only and required by the MASTER TASK API assertion. No write-path or permission behavior is changed.

## Test Plan

Add `tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php` with:

- Production-shaped fixture proving `45,530,000 - 42,630,000 = 2,900,000`.
- Assert generic PCPN appears once and TTNH fallback is not emitted for purchases covered by real generic payments.
- Assert direct Purchase CashFlow still appears.
- Assert read-only service/API call does not mutate stored supplier debt, purchase paid/debt totals, cashflow count, or supplier debt transaction count.
- Add residual fallback test for partial uncovered `paid_amount`.
- Add ambiguity/future-purchase test proving a generic payment cannot be allocated to a purchase that occurs after the payment and that the service keeps reconcile warning without DB mutation.

Required verification:

- `php artisan test tests/Feature/Suppliers`
- `php artisan test tests/Feature/SupplierDebt` if directory exists
- `php artisan test tests/Feature/Customers`
- `php artisan test tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php`
- `git diff --check`

No frontend change is planned, so `npm run build` is not required unless Vue changes are introduced.

## Test Evidence

Baseline red test before hotfix:

- `php artisan test tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php`: FAIL.
- Production-shaped fixture computed `document_final_balance=-11,980,000` instead of expected `2,900,000`.
- Partial fallback fixture computed `document_final_balance=500,000` instead of expected `300,000`.

After final P0 hotfix:

- `php artisan test tests/Feature/Suppliers/SupplierDebtTimelineParityTest.php`: PASS, 3 passed, 30 assertions.
- `php artisan test tests/Feature/Customers`: PASS, 148 passed, 1 skipped, 799 assertions.
- `php -l app/Services/SupplierDebtDocumentTimelineService.php`: PASS.
- `php -l app/Http/Controllers/SupplierController.php`: PASS.
- `git diff --check`: PASS.

Regression suites with unrelated pre-existing/out-of-scope failures:

- `php artisan test tests/Feature/Suppliers`: 47 passed, 1 failed, 376 assertions.
  - Failing test: `Tests\Feature\Suppliers\Step248SupplierActionsTest::user_without_suppliers_edit_permission_is_blocked`.
  - Failure: expected redirect `/`, actual `403`.
  - Classification: permission behavior, outside supplier debt/timeline scope. Not fixed in this P0 hotfix.
  - Baseline proof: running the same filtered test at `c5b1ffbccf4a6b48fc57ec1ed99d7e9c6fda4b87` also FAILS with the same expected redirect `/`, actual `403`.
- `php artisan test tests/Feature/Supplier`: 58 passed, 1 failed, 241 assertions.
  - Failing test: `Tests\Feature\Supplier\HOTFIX2414SupplierTabExportTest::supplier_purchase_history_export_downloads_csv`.
  - Failure: expected CSV body to contain `Mã phiếu nhập`, actual header contains `Mã phiếu`.
  - Classification: supplier purchase history export header, outside supplier debt/timeline scope. Not fixed in this P0 hotfix.
- `tests/Feature/SupplierDebt` directory does not exist in this repository.

Environment note: local PHP emits startup warnings for missing optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`. These warnings did not block the executed tests.

## Production Dry-run Plan

Later, after review/merge and before deploy:

1. Backup DB.
2. Read-only pre-deploy debt diff for supplier `NCC177466273054`.
3. Deploy code only.
4. Read-only post-deploy debt diff.
5. Confirm the supplier debt tab reports `2,900,000`.
6. Roll back code if timeline still mismatches.
7. Do not update production debt data.

## Rollback Plan

- Code rollback only to the previous production SHA.
- No data rollback expected because this hotfix does not write data or run migrations.

## Explicit Data Safety Statement

This audit and selected hotfix do not perform DB writes, production commands, migrations, backfills, debt rebuilds, opening balance creation, or MERGE cleanup.
