# Sapo to Kiot Debt Parity Final Gap Audit

## Executive Summary

- Da port: signed customer debt, overpayment/credit am, customer payment allocation, CashFlow cancellation guard/idempotency, Order payment summary Option A, partner merge marker 0, merged-source guard, supplier API compatibility aliases, customer export fallback.
- Da deploy/code tren `origin/main`: PR #1 debt parity merge `8d56564`, PR #5 customer/supplier export/API compatibility squash `1603cd93c71bed852107f7063a66ea4d1ba2cf5e`.
- Chua port hoac chua chot: Customer legacy/partner timeline contract con 29 regression failures. Day khong phai gap core Sapo parity; chu yeu la contract hien thi/alias/virtual opening/return-settlement cua Kiot sau document-first migration.
- Test retest sau khi unblock DB local: `SapoDebtParityTest` PASS 12/12, `CustomerDebt` PASS 17/17, `Supplier` PASS 59/59, broad Customer/Supplier debt suite con dung 29 failures (`239 passed, 29 failed, 1 skipped`). Khong co failure moi so voi baseline PR #5.
- Ket luan: core Sapo debt parity da vao Kiot; phan con lai can BA/Senior Auditor chot contract Customer legacy/partner timeline truoc khi code.

## Scope

In scope:

- Audit/report-only tren source Kiot va Sapo.
- Doi chieu source, migrations, models, services, controllers, frontend contracts, docs va tests.
- Phan loai 29 failures con lai sau PR #5.
- De xuat toi da 3 PR tiep theo.

Out of scope:

- Khong sua code nghiep vu.
- Khong merge PR.
- Khong deploy production.
- Khong SSH/pull/build/restart production.
- Khong chay migration/backfill/rebuild/opening balance.
- Khong cleanup MERGE legacy.
- Khong sua payroll, ton kho, gia von, costing, serial/IMEI, warranty, repair.

## Repositories / Commits Checked

| Muc | Gia tri |
|---|---|
| Kiot repo | `D:\Kiot\kiotviet-clone.worktrees\port-sapo-debt-logic-to-kiot` |
| Kiot branch audit | `audit/sapo-to-kiot-debt-parity-final-gap` |
| Kiot base checked | `origin/main` |
| Kiot commit checked | `1603cd93c71bed852107f7063a66ea4d1ba2cf5e` |
| Sapo repo | `D:\Kiot\kiotviet-sapo` |
| Sapo branch checked | `origin/codex/kiotviet-payments-partner-merge` |
| Sapo branch head | `c739645d6f96ff3e6d79a4061889b07c77cc49de` |
| Sapo commits referenced | `2321a3e`, `a08fe9b`, `540019e`, `693b523`, `570f121` |
| Production touched in this audit | Khong |

Sapo source chain observed:

```text
c739645 feat: implement simple A4 order printing layout and services
570f121 test(debt): cover allocation merge and cancellation fixups
693b523 fix(partners): preserve merge snapshots and block merged sources
540019e fix(payments): harden allocations and linked cancellation
a08fe9b fix(debt): guard signed invoice balance effects
3e98d8e fix(pos): show cumulative payments and merge previews
2321a3e fix(debt): preserve overpayments and partner balances
```

## Files Checked

Kiot source/docs/tests checked:

- `app/Services/CustomerDebtService.php`
- `app/Services/CustomerPaymentService.php`
- `app/Services/CustomerDebtDocumentTimelineService.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `app/Services/PartnerMergeService.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/OrderPaymentSummaryService.php`
- `app/Services/PartnerTransactionGuard.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Http/Controllers/OrderController.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/CashFlowController.php`
- `app/Models/CustomerPaymentAllocation.php`, `app/Models/CashFlow.php`, `app/Models/Invoice.php`, `app/Models/Customer.php`, `app/Models/PartnerMerge.php`
- `database/migrations/2026_06_12_120000_create_customer_payment_allocations_table.php`
- `database/migrations/2026_06_12_120100_add_order_deposit_applied_amount_to_invoices.php`
- `database/migrations/2026_06_12_120200_add_partner_merge_provenance.php`
- `tests/Feature/CustomerDebt/SapoDebtParityTest.php`
- `tests/Feature/CustomerDebt`, `tests/Feature/Customers`, `tests/Feature/Supplier`, `tests/Feature/Suppliers`
- `resources/js/Pages/Customers/Index.vue`, `resources/js/Pages/Suppliers/Index.vue`, `resources/js/Pages/Orders/Index.vue`, `resources/js/Pages/POS/Index.vue`
- `docs/audit/PORT-SAPO-DEBT-LOGIC-TO-KIOT.md`
- `docs/audit/CUSTOMER-SUPPLIER-DEBT-REGRESSION-TRIAGE-AFTER-PR4.md`
- `docs/audit/HOTFIX-CUSTOMER-SUPPLIER-DEBT-EXPORT-API-COMPATIBILITY.md`

Sapo source/tests checked:

- `app/Services/CustomerDebtService.php`
- `app/Services/CustomerPaymentService.php`
- `app/Services/OrderPaymentSummaryService.php`
- `app/Services/PartnerMergeService.php`
- `app/Services/PartnerTransactionGuard.php`
- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/PartnerFinancialTimelineService.php`
- `app/Http/Controllers/CustomerController.php`, `SupplierController.php`, `OrderController.php`, `PosController.php`
- `app/Models/CustomerPaymentAllocation.php`, `app/Models/PartnerMerge.php`, `CashFlow.php`, `Invoice.php`, `Customer.php`
- `database/migrations/2026_06_11_000001_add_customer_payment_allocations_and_partner_merge_provenance.php`
- `database/migrations/2026_06_10_202941_add_partial_order_fulfillment_fields.php`
- `tests/Feature/Customers/PartnerMergeBalanceNeutralTest.php`
- `tests/Feature/Customers/MergedPartnerTransactionGuardTest.php`
- `tests/Feature/Customers/*Debt*`, `tests/Feature/Supplier*`, `tests/Feature/CustomerDebt/*`

## Sapo Source Logic

The Sapo branch provides the source behavior used for Kiot PR #1:

- `CustomerDebtService::recordSale()` rejects negative amount and redirects signed invoice effects to `recordInvoiceBalanceEffect()`.
- `CustomerPaymentService` records full `CashFlow.amount`, allocates only receivable invoice amounts, and leaves overpayment as credit by applying full negative debt effect.
- `CustomerPaymentAllocation` records structured allocation provenance.
- `CustomerPaymentService::cancel()` locks CashFlow rows and returns `already_cancelled` on repeat cancellation.
- `OrderPaymentSummaryService` implements Option A: `orders.amount_paid` is original deposit only; paid after deposit comes from active invoices after subtracting `order_deposit_applied_amount`.
- `PartnerMergeService` row-locks partners, writes `MERGE-PARTNER-{source}-TO-{target}` marker amount `0`, snapshots source/target balances, inactivates source, and does not create financial adjustment.
- `PartnerTransactionGuard` blocks transactions using merged sources.
- Partner/customer/supplier timelines expose dual-role orientation and compatibility fields around `customer_effect`, `supplier_effect`, `debt_remain`, `partner_effect`, and read-only virtual opening rows.

## Kiot Current Logic

Kiot `origin/main` at `1603cd93` contains the core port:

- `CustomerDebtService::recordSale()` calls `assertNonNegative()`, and signed helpers exist: `recordInvoiceBalanceEffect()` and `recordInvoiceBalanceReversal()`.
- `CustomerPaymentService` uses `CustomerPaymentAllocation`, locks customer/invoice/cashflow rows, returns `allocated_amount`, `unallocated_amount`, `debt_before`, `debt_after`, and applies full receipt amount to debt.
- `CustomerPaymentService::cancel()` handles `cancelled`/`already_cancelled`, reverses allocations and invoice `customer_paid`, and soft-deletes the CashFlow after setting status `cancelled`.
- `OrderPaymentSummaryService` exposes `original_deposit`, `deposit_applied`, `deposit_remaining`, `paid_after_deposit`, `order_paid_total`, `order_remaining_debt`, `order_credit_total`, and `payment_status`.
- `OrderController`, POS payload, order list/export, and frontend order table use `order_paid_total`/`order_remaining_debt` rather than treating `orders.amount_paid` as cumulative payment.
- `PartnerMergeService`, `PartnerMerge` model, `customers.merged_into_id`, `customers.merged_at`, and `partner_merges` provenance exist.
- `PartnerTransactionGuard` is wired into invoice, order, POS, customer payment, purchase, purchase order, purchase return, supplier payment, cashflow and related dropdown availability.
- PR #5 fixed Customer export mapper and Supplier document API aliases (`summary.net`, `supplier_effect`, `debt_remain`, `type_label`, `partner_effect`, `supplier_partner_effect`, `source_ledger`, `partner_running_balance`).

## Parity Matrix

| STT | Logic nghiep vu | Sapo source file/commit | Kiot source file/commit | Da port vao main chua | Da co test chua | Test pass chua | Da deploy production chua | Rui ro du lieu | Ket luan | Buoc tiep theo |
|---:|---|---|---|---|---|---|---|---|---|---|
| 1 | Signed debt / credit am | `CustomerDebtService`, `a08fe9b` | `CustomerDebtService`, `1603cd93` | Co | `SapoDebtParityTest` | Retest PASS: `SapoDebtParityTest` 12/12 | Theo brief: PR #5 da deploy; audit nay khong verify prod | Low | Dat parity source | Regression only |
| 2 | Invoice/POS/order signed balance effect | `Invoice/Order/POS`, `2321a3e`, `a08fe9b` | `InvoiceSaleService`, `InvoiceUpdateService`, `OrderController`, `PosController` | Co | `SapoDebtParityTest`, POS/Orders tests | Retest PASS via `SapoDebtParityTest` 12/12 | Da tren main | Medium neu regression sau nay | Dat parity source | Regression only |
| 3 | Customer payment allocation | `CustomerPaymentService`, `540019e` | `CustomerPaymentService`, `CustomerPaymentAllocation` | Co | `SapoDebtParityTest`, customer payment tests | Retest PASS via `SapoDebtParityTest`; `CustomerDebt` suite PASS 17/17 | Da tren main | Low/Medium | Dat parity source | Regression only |
| 4 | CashFlow cancellation idempotent | `CustomerPaymentService`, `540019e` | `CustomerPaymentService::cancel()` | Co | `SapoDebtParityTest` | Retest PASS via `SapoDebtParityTest` | Da tren main | Medium | Dat parity source | Regression only |
| 5 | Order payment summary Option A | `OrderPaymentSummaryService`, `3e98d8e`, `570f121` | `OrderPaymentSummaryService`, `OrderController`, POS | Co | `SapoDebtParityTest`, `Orders`, `POS` | Retest PASS via `SapoDebtParityTest`; broader Orders/POS not rerun in this report-only update | Da tren main | Medium legacy data audit only | Dat parity source | Manual review legacy cumulative `amount_paid` before remediation |
| 6 | Partner merge marker 0 | `PartnerMergeService`, `693b523` | `PartnerMergeService`, `PartnerMerge` | Co | `SapoDebtParityTest` | Retest PASS via `SapoDebtParityTest` | Da tren main | Medium if legacy MERGE data exists | Dat parity for new merges | Do not cleanup legacy without BA |
| 7 | Partner guard | `PartnerTransactionGuard`, `693b523` | `PartnerTransactionGuard` wired broadly | Co | `SapoDebtParityTest` | Retest PASS via `SapoDebtParityTest` | Da tren main | Low | Dat parity source | Regression only |
| 8 | Dual-role net orientation | `PartnerDebtLedgerService`, Sapo dual-role commits | `CustomerController`, `SupplierController`, `PartnerDebtLedgerService` | Co mot phan | Many Customers/Suppliers tests | 29 remaining failures | Da tren main | Medium display/API contract | Core formula exists; customer partner timeline compatibility not closed | BA contract decision |
| 9 | Customer document-first timeline | Sapo/Kiot timeline work | `CustomerDebtDocumentTimelineService`, `PartnerDebtLedgerService` | Co mot phan | Customers tests | 29 remaining failures include this | Da tren main | Medium/High display trust | Not missing core Sapo parity; contract mismatch | Dedicated compatibility PR |
| 10 | Supplier document-first timeline/API aliases | Sapo ledger/timeline | `SupplierDebtDocumentTimelineService`, `SupplierController`, PR #5 | Co | Supplier/Suppliers tests | Retest Supplier suite PASS 59/59; Suppliers suite still has 2 dual-role display failures | Da tren main | Medium | Mostly fixed by PR #5 | Include dual-role display in next PR |
| 11 | Frontend customer debt | Sapo/Kiot customer UI | `resources/js/Pages/Customers/Index.vue` | Co mot phan | UI/UAT prior evidence | Prior UAT PASS core; remaining contract tests | Da tren main | Medium | Core credit/payment UI OK; timeline fields still contract-sensitive | Audit after compatibility PR |
| 12 | Frontend supplier debt | Sapo/Kiot supplier UI | `resources/js/Pages/Suppliers/Index.vue` | Co mot phan | Supplier tests | Supplier default API fixed, dual-role display remains | Da tren main | Medium | Mostly OK | Keep aliases while frontend migrates |
| 13 | POS/order UI | `3e98d8e` | POS/Orders frontend/controllers | Co | POS/Orders tests | Prior PASS | Da tren main | Low | Dat parity | Regression only |
| 14 | Exports | Sapo/Kiot export hotfix | Customer/Supplier export services/controllers | Co for PR #5 scope | Customer export/Supplier export tests | Prior PASS for fixed scope | Da tren main | Low/Medium | Customer export and supplier API fixed | Supplier/customer timeline exports should follow compatibility PR |
| 15 | Remaining 29 failures | N/A | Customer legacy/partner tests | Chua chot | Co | Retest broad suite still 29 failures, no new failures | Da tren main but not clean | Medium | BA decision required | Split next PRs |

## Test Results

> Note: The initial local run was blocked by MySQL connection refused. This was later superseded by the "Retest After Testing DB Unblocked" section using disposable Docker MySQL. The authoritative current test result is the retest result below.

Commands required by brief:

```bash
php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php
php artisan test tests/Feature/CustomerDebt
php artisan test tests/Feature/Customers
php artisan test tests/Feature/Supplier
php artisan test tests/Feature/Suppliers
php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers
git diff --check
```

Initial local audit run on this machine, now superseded by the Docker MySQL retest below:

| Command | Result |
|---|---|
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | BLOCKED by local MySQL refused: 12 tests failed with 0 assertions, all `SQLSTATE[HY000] [2002]` |
| Other PHP test groups | Not repeated after DB refused; would use same unavailable MySQL local config |
| `git diff --check` | PASS |

Environment note:

- `phpunit.xml` sets `APP_ENV=testing` but sqlite DB config is commented out.
- `.env.testing` is not present in this worktree.
- PHPUnit therefore reads local MySQL settings; current local MySQL target is not accepting connections.
- No production DB was used. No migration was run.

Prior evidence from PR #5 report on a working testing DB:

| Command | Prior result |
|---|---|
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | PASS: 12 passed, 41 assertions |
| Customer export + business time tests | PASS: 15 passed, 1 skipped |
| `php artisan test tests/Feature/Supplier tests/Feature/Customers/CustomerDebtTimelineBusinessTimeTest.php` | PASS: 64 passed, 1 skipped |
| `php artisan test tests/Feature/Suppliers` | FAIL: 2 failed, 42 passed |
| Broad debt suite | FAIL: 29 failed, 1 skipped, 239 passed |
| `git diff --check` | PASS |

## Retest After Testing DB Unblocked

### Testing DB

- Testing DB method: Docker MySQL local, container `sales_mysql_test` (`mysql:8.0`), port `3319`.
- DB_CONNECTION: `mysql`
- DB_HOST: `127.0.0.1`
- DB_PORT: `3319`
- DB_DATABASE: `kiot_debt_test_audit`
- DB_USERNAME: `test_user`
- DB_COLLATION: `utf8mb4_0900_ai_ci`
- Production DB used: No.

`test_user` could connect to the existing `sales_test` database but did not have permission to create a new database. For an isolated disposable retest DB, root credentials from the local Docker container env (`MYSQL_ROOT_PASSWORD=root`) were used only inside the local container to create `kiot_debt_test_audit` and grant access to `test_user`.

### Migration On Testing DB

Command:

```bash
php artisan migrate --env=testing
```

Result: PASS on disposable DB `kiot_debt_test_audit`. No production migration was run.

### Commands

```bash
php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php
php artisan test tests/Feature/CustomerDebt
php artisan test tests/Feature/Customers
php artisan test tests/Feature/Supplier
php artisan test tests/Feature/Suppliers
php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers
git diff --check
```

### Results

| Command | Result |
|---|---|
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | PASS: 12 passed, 41 assertions |
| `php artisan test tests/Feature/CustomerDebt` | PASS: 17 passed, 55 assertions |
| `php artisan test tests/Feature/Customers` | FAIL: 27 failed, 1 skipped, 121 passed, 662 assertions |
| `php artisan test tests/Feature/Supplier` | PASS: 59 passed, 243 assertions |
| `php artisan test tests/Feature/Suppliers` | FAIL: 2 failed, 42 passed, 279 assertions |
| `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers` | FAIL: 29 failed, 1 skipped, 239 passed, 1239 assertions |
| `git diff --check` | PASS |

PHP local still emits optional-extension startup warnings for `oci8_12c`, `oci8_19`, `pdo_firebird`, and `pdo_oci`. They did not block MySQL tests.

### Delta vs Previous Report

- Previous current-run status: blocked by MySQL refused before Docker testing DB was confirmed ready.
- Current result: DB unblocked using disposable local Docker MySQL database `kiot_debt_test_audit`.
- SapoDebtParity: PASS 12/12.
- Broad Customer/Supplier: still 29 failures, matching PR #5 baseline.
- Remaining failures: same categories as report classification.
- New failures: none identified.
- Fixed/changed failures: none from source changes; this step did not change product code.

### Remaining Failure Names

Customer failures, 27:

- `AnhThanhThienPhuDebtReconcileTest::test_anh_thanh_thien_phu_reconciliation_calculations_and_api`
- `CustomerDebtHistoryDoubleCountTest` x4
- `CustomerDebtHistoryReturnSettlementDisplayTest` x6
- `CustomerDebtUnresolvedMismatchWarningTest::test_unresolved_display_mismatch_still_returns_warning`
- `CustomerDebtVirtualOpeningTimelineTest::test_customer_with_balance_but_no_history_gets_read_only_virtual_opening_row`
- `DualRolePartnerDebtTimelineTest` x4
- `HOTFIXFollowUpDebtHistoryPaginationTest::test_summary_reflects_full_ledger_not_page`
- `HOTFIXFollowUpDebtOffsetMirrorTest::test_customer_net_view_mirrors_cb_to_positive_effect`
- `PartnerFinancialTimelineTest` x8

Supplier failures, 2:

- `SupplierDualRoleTimelineFinancialDisplayTest::test_dual_role_reference_documents_keep_financial_values_on_both_screens`
- `SupplierDualRoleTimelineNoDashTest::test_dual_role_financial_entries_have_display_running_balance_on_both_orientations`

### Conclusion

Retest confirms the prior conclusion: core Sapo parity is intact and the remaining 29 failures are Customer legacy/partner timeline and dual-role display contract issues. This report update is documentation-only; no product code, migration, backfill, production command, or production DB access was used.

## Remaining 29 Failures Classification

| Failure group | Count | Classification | Missing Sapo logic? | Kiot contract issue? | BA decision needed? | Notes |
|---|---:|---|---|---|---|---|
| `AnhThanhThienPhuDebtReconcileTest` | 1 | Legacy/customer partner timeline compatibility | Not core parity | Yes | Yes | Expected legacy ledger/net display values conflict with current document-first/reference handling |
| `CustomerDebtHistoryDoubleCountTest` | 4 | Customer legacy compatibility | Possibly adapter gap, not write-logic gap | Yes | Yes | Tests expect legacy affects/reference semantics around invoices, TTHD, supplier transaction mirror |
| `CustomerDebtHistoryReturnSettlementDisplayTest` | 6 | Return settlement display contract | Adapter/display gap | Yes | Yes | Needs decision whether auto settlement rows merge into a single return row in new document-first response |
| `CustomerDebtUnresolvedMismatchWarningTest` | 1 | Test expectation old wording | No | Minor | Yes | Wording changed from legacy debt-history warning to document timeline warning |
| `CustomerDebtVirtualOpeningTimelineTest` | 1 | Virtual opening policy | No | Yes | Yes | Read-only virtual opening must be policy-stabilized before more code |
| `DualRolePartnerDebtTimelineTest` | 4 | Dual-role partner timeline display | Adapter/display gap | Yes | Yes | Customer-side mirror/orientation fields and reference flags need a stable contract |
| `HOTFIXFollowUpDebtHistoryPaginationTest` | 1 | Pagination summary compatibility | No core gap | Yes | Yes | Full-ledger summary vs paginated slice contract |
| `HOTFIXFollowUpDebtOffsetMirrorTest` | 1 | Debt offset mirror/dedup contract | Adapter/display gap | Yes | Yes | Customer net view mirror of CB/HCB needs one canonical effect policy |
| `PartnerFinancialTimelineTest` | 8 | Partner financial timeline legacy contract | Adapter/display gap | Yes | Yes | Tests assert old affects flags and running balance behavior |
| `SupplierDualRoleTimelineFinancialDisplayTest` | 1 | Supplier dual-role display | Adapter/display gap | Yes | Yes | Reference documents keep/zero financial values depending orientation |
| `SupplierDualRoleTimelineNoDashTest` | 1 | Supplier dual-role running display | Adapter/display gap | Yes | Yes | No-dash/running-balance compatibility aliases |

Summary:

- Real core Sapo parity missing: 0 confirmed.
- Kiot legacy/document-first compatibility unresolved: 27.
- Test wording/policy decision: 2.
- BA/Senior Auditor confirmation required before code: all 29.

## Root Cause Groups

### Group 1: Customer legacy timeline vs document-first canonical response

- Tests fail: `CustomerDebtHistoryDoubleCountTest`, `PartnerFinancialTimelineTest`, `AnhThanhThienPhuDebtReconcileTest`.
- Source related: `PartnerDebtLedgerService`, `CustomerDebtDocumentTimelineService`, `CustomerController::debtHistory`.
- Expected by tests: legacy fields such as `customer_effect`, `supplier_effect`, `debt_total`, `balance`, `affects_debt_balance` keep old meaning.
- Actual current behavior: document-first/reference rows may zero financial effect, move values to display fields, or mark rows reference-only.
- Sapo behavior: contains compatibility ledger adapters and dual-role fields; Kiot has most code but current contract differs in some legacy tests.
- Missing port or Kiot contract: mostly Kiot contract/adapter gap, not missing write-path Sapo logic.
- Production risk: medium, because UI/export users can mistrust running balances if frontend reads old fields.
- Data impact: none if fixed as read-only adapter; high if fixed by DB backfill, which is not recommended in this scope.
- Proposal: add read-only compatibility adapter, do not write ledger or alter balances.

### Group 2: Return settlement display merge

- Tests fail: `CustomerDebtHistoryReturnSettlementDisplayTest` x6.
- Source related: `PartnerDebtLedgerService::buildReturnSettlementMeta()`, `mapCustomerDebt()`.
- Expected by tests: paid return settlement appears as one return row with merged settlement balance/effect; unmatched manual adjustment remains visible.
- Actual current behavior: document-first and legacy ledger/reference layers do not fully match test contract.
- Sapo behavior: settlement meta merge exists; current Kiot source has similar logic, but remaining tests imply output contract mismatch.
- Missing port or Kiot contract: adapter/display gap.
- Production risk: medium. Wrong display can look like double count or hidden settlement.
- Data impact: no DB write needed.
- Proposal: narrow PR for return settlement output contract after BA approves single-row display.

### Group 3: Virtual opening and warning policy

- Tests fail: `CustomerDebtVirtualOpeningTimelineTest`, `CustomerDebtUnresolvedMismatchWarningTest`.
- Source related: `PartnerDebtLedgerService` virtual opening helpers and reconcile payload.
- Expected by tests: read-only virtual opening row and exact legacy warning text.
- Actual current behavior: virtual opening policy varies by document/financial entries; warning wording changed.
- Sapo behavior: read-only virtual opening exists.
- Missing port or Kiot contract: BA policy and test expectation mismatch.
- Production risk: medium, because virtual opening can mask incomplete historical ledger if misunderstood.
- Data impact: no DB write for display-only opening. Creating real opening balance would require a separate approved remediation.
- Proposal: BA decide whether virtual opening remains display-only; then update tests/adapters.

### Group 4: Dual-role customer/supplier orientation and mirror aliases

- Tests fail: `DualRolePartnerDebtTimelineTest`, `HOTFIXFollowUpDebtOffsetMirrorTest`, `SupplierDualRoleTimelineFinancialDisplayTest`, `SupplierDualRoleTimelineNoDashTest`.
- Source related: `PartnerDebtLedgerService::buildCustomerNetLedger()`, `buildSupplierPayableLedger()`, `buildSupplierDualRolePartnerTimeline()`, Supplier/Customer controllers.
- Expected by tests: customer screen = receivable - payable; supplier screen = payable - receivable; mirror entries expose compatible aliases/running balances and no dash display.
- Actual current behavior: core formula exists, but some reference-only/financial field aliases differ.
- Sapo behavior: dual-role mirror aliases and read-only reference flags are exposed more compatibly.
- Missing port or Kiot contract: read-only adapter gap.
- Production risk: medium/high for dual-role operators.
- Data impact: none if implemented as response alias/display adapter.
- Proposal: separate dual-role timeline compatibility PR; no debt writes.

### Group 5: Pagination summary full-ledger contract

- Tests fail: `HOTFIXFollowUpDebtHistoryPaginationTest`.
- Source related: Customer debt history response pagination/meta.
- Expected by tests: paginated entries but summary/running balance computed from full ledger.
- Actual current behavior: remaining fail after PR #5 indicates some summary contract still mismatches.
- Sapo behavior: full-ledger summary was preserved.
- Missing port or Kiot contract: compatibility contract gap.
- Production risk: medium in paginated timeline pages.
- Data impact: none.
- Proposal: include in customer legacy timeline adapter PR.

## Data Safety

| Checklist | Answer |
|---|---|
| Co migration khong | Khong |
| Co backfill khong | Khong |
| Co update du lieu cu khong | Khong |
| Co xoa du lieu khong | Khong |
| Co rebuild cong no khong | Khong |
| Co tao opening balance khong | Khong |
| Co cleanup MERGE legacy khong | Khong |
| Co dung production DB khong | Khong |
| Co can backup DB khong | Khong cho audit/report-only; co neu sau nay remediation/backfill |
| Co can rollback plan khong | Co: revert report commit; code PR sau nay phai co rollback rieng |
| Co can hoi xac nhan truoc khong | Co, truoc bat ky code thay doi contract 29 failures hoac bat ky command ghi DB |

## Production Status

- PR #5 is on `origin/main` at `1603cd93c71bed852107f7063a66ea4d1ba2cf5e`.
- Brief states production Kiot has deployed PR #5 at this commit.
- This audit did not SSH/pull/build/restart production and did not verify production runtime.
- No production command was run.
- No production migration/backfill/rebuild/opening balance was run.

## Recommended Next PRs

### PR 1

Name: `fix(debt): stabilize customer legacy timeline compatibility`

Scope:

- Customer debt history adapter only.
- Add/readjust read-only aliases for legacy tests/frontend.
- Preserve document-first canonical fields.
- Fix return settlement display merge if BA confirms single-row contract.
- Fix pagination full-ledger summary contract.

Files:

- `app/Services/PartnerDebtLedgerService.php`
- `app/Services/CustomerDebtDocumentTimelineService.php`
- `app/Http/Controllers/CustomerController.php`
- Focused Customers tests listed in 29 failure inventory.

Khong duoc lam:

- No DB writes, no migration, no opening balance, no cleanup MERGE legacy.
- No payment/order/POS stock/costing/serial changes.

Tests:

- Targeted failing Customer test classes.
- `SapoDebtParityTest`.
- Customer export/business time tests.

Data risk: low if response-only. Medium if BA asks to materialize opening balances.

Can xac nhan truoc khong: Co, BA must approve legacy alias and return-settlement display contract.

### PR 2

Name: `fix(debt): align dual-role partner timeline display aliases`

Scope:

- Customer/supplier dual-role read-only display fields.
- Debt offset mirror/dedup display semantics.
- Supplier dual-role no-dash/running-balance aliases.
- Keep customer screen = receivable - payable and supplier screen = payable - receivable.

Files:

- `app/Services/PartnerDebtLedgerService.php`
- `app/Http/Controllers/SupplierController.php`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- Dual-role focused tests.

Khong duoc lam:

- No ledger write-path changes.
- No supplier payment/customer payment allocation changes.
- No DB remediation.

Tests:

- `DualRolePartnerDebtTimelineTest`
- `HOTFIXFollowUpDebtOffsetMirrorTest`
- `SupplierDualRoleTimelineFinancialDisplayTest`
- `SupplierDualRoleTimelineNoDashTest`
- `SapoDebtParityTest`

Data risk: low if response-only.

Can xac nhan truoc khong: Co, BA/Senior Auditor must approve mirror/reference-only field policy.

### PR 3

Name: `test(debt): update accepted document-first timeline expectations`

Scope:

- If BA decides document-first canonical response should replace old legacy fields, update tests to new contract instead of changing code.
- Pin warning wording, virtual opening policy, and full-ledger/paginated summary behavior.
- Add regression docs explaining old vs new fields.

Files:

- Tests under `tests/Feature/Customers` and `tests/Feature/Suppliers`.
- `docs/audit/*` contract decision report.

Khong duoc lam:

- No product code unless a confirmed adapter bug is found.
- No data command.

Tests:

- Full Customer/Supplier debt regression once DB local/testing is available.

Data risk: none.

Can xac nhan truoc khong: Co, this is entirely BA/Senior Auditor contract-driven.

## No-Go Items

- Do not announce full Customer/Supplier debt regression clean while 29 failures remain.
- Do not create real opening balances from stored balances in this task.
- Do not run `debt:* --apply`, rebuild, backfill, or cleanup MERGE legacy without a separate approved runbook.
- Do not use production DB to unblock tests.
- Do not change stock movement, costing, serial/IMEI, warranty, repair, or payroll under debt compatibility PRs.

## Final Conclusion

Status: Dung huong, but can chot contract.

Core Sapo debt logic has been ported into Kiot main: signed debt, overpayment credit, structured allocation, cancellation idempotency, Option A order summary, safe partner merge marker, and merged-source guard are present in source and were previously proven by `SapoDebtParityTest` on a working testing DB. PR #5 also closed the concrete Customer export and Supplier default API compatibility gaps.

The remaining 29 failures are not evidence that the core Sapo debt write logic is missing. They are concentrated in Customer legacy/partner timeline response contract: legacy aliases, reference-only flags, return settlement display, virtual opening policy, warning wording, pagination summary, and dual-role mirror display. The next work should be a small BA-approved compatibility PR, not broad copy-porting from Sapo and not any production data remediation.
