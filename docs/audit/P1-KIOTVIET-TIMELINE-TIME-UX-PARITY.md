# P1 — KiotViet timeline time UX parity

## Decision and reference contract

The KiotViet reference contract has one `Thời gian` column, newest-first display,
and business-time ordering. A payment may render above an invoice at the same
business timestamp, while running balance remains calculated in business-time
ascending order. Dual-role screens share the event stream but retain their own
customer/supplier sign orientation.

This change deliberately does **not** add `Thời điểm ghi nhận` to a timeline row,
does not add a `Nhập sau` badge, and does not introduce local sorting. The column
help explains that timeline/debt use transaction time and that system recording
can occur later.

## Clone contract after this change

- Customer and supplier timelines still have one time column.
- `entryDisplayTime()` and `supplierEntryDisplayTime()` prefer `display_time`,
  `time`, and `business_time` before document transaction fields. `recorded_at`
  is not a display-time fallback.
- No change was made to canonical event construction, event identity, sorting,
  reversal matching, pagination, running-balance calculation, or debt deltas.
- Invoice, return, purchase, and purchase-return detail views present business
  time and system-recorded time only from known fields. `updated_at` is never
  shown as a recorded time.

## Detail metadata contract

| Document | Business time | Recorded time |
| --- | --- | --- |
| Invoice | `transaction_date → sale_time → created_at` | `lock_started_at → created_at` |
| Sales return | `return_date → created_at` | `created_at` |
| Purchase | `purchase_date → created_at` | `created_at` |
| Purchase return | `return_date → created_at` | `created_at` |

Detail API additions are read-only and detail-only: `business_time`,
`recorded_at`, `business_time_source`, and `recorded_time_source`. Canonical
timeline responses (`entries`, `summary`, `reconcile`, pagination) are unchanged.

The sales-return detail also keeps the explanatory roles separate: source invoice,
source-invoice seller, return creator, and return receiver. None participates in
sales attribution or financial calculation.

## Financial snapshot baseline and result

The P1 test normalizes event identity/kind/source, business time/order, customer
and supplier deltas, affects-balance/reference-only flags, display effects,
running balances, target/final/reconcile values, source identity hash, and entry
count. The before/after values are byte-for-byte identical.

| Orientation | SHA256 before | SHA256 after |
| --- | --- | --- |
| Customer-only | `93d2aeddf302f60452f9952f79d631a88f1dcc8f5588a4a5e60b2f4dc6d9dcc5` | `93d2aeddf302f60452f9952f79d631a88f1dcc8f5588a4a5e60b2f4dc6d9dcc5` |
| Supplier-only | `506fd59246362996be862e1d5bd0af56ba9b2934b76e56877fd6bd57f26a69f9` | `506fd59246362996be862e1d5bd0af56ba9b2934b76e56877fd6bd57f26a69f9` |
| Dual-role customer | `c814cf0cdcaee6839da1959f84a4b95a97ee3dbcf4a7ecf2d731184d1b2893cc` | `c814cf0cdcaee6839da1959f84a4b95a97ee3dbcf4a7ecf2d731184d1b2893cc` |
| Dual-role supplier | `e6c26b1f55d7a3d189599fa14a6cf93276cd13bbd8b6509044683f10c8523058` | `e6c26b1f55d7a3d189599fa14a6cf93276cd13bbd8b6509044683f10c8523058` |

Result: event identity, event order, business time, customer/supplier delta,
customer/supplier running balance, customer/supplier final balance, reconcile,
source identity hash, entry count, and pagination contract are unchanged.

## Regression fixture

The characterization test uses the production-shaped invoice time pair:

- business transaction time: `11/07/2026 09:49`
- system record time: `11/07/2026 10:02`

It verifies those values through invoice detail, customer voucher detail, purchase
detail, and supplier voucher detail while asserting all four normalized financial
snapshots remain identical before/after each read-only request.

Fresh-schema finding: `returns.return_date` is not present in this repository's
complete migration set, while `purchase_returns.return_date` is present. The
sales-return detail therefore demonstrates the specified safe fallback to
`created_at` (and never `updated_at`); it cannot demonstrate a distinct 09:58
business time without a schema/data decision outside this no-migration P1.

## Test evidence

- `P1TimelineTimePresentationContractTest`: **1 passed / 53 assertions**.
- Fresh disposable MySQL QA migrations: PASS (`kiot_p1_timeline_qa`, localhost
  port 3320 only).
- Expanded targeted debt command: **49 passed / 1 skipped / 20 failed**.
  The failures are in pre-existing core-timeline test classes
  (`SupplierDebtTimelineParityTest`, `DualRolePartnerDebtTimelineTest`,
  `SupplierDualRoleOrientationKiotVietTest`, `CustomerDebtDocumentTimelineTest`,
  and `SupplierDebtDocumentTimelineTest`). No timeline core service, event
  source, orientation service, delta calculation, ordering, or pagination file
  is modified by this P1. This is explicitly **not** treated as a passing gate;
  it blocks Ready-for-Review until separately reproduced against the base.
- `npm run build`: PASS.
- `vendor/bin/pint --dirty --test`: PASS (6 PHP files).
- PHP lint for changed PHP: PASS (host PHP emits unrelated unavailable OCI and
  Firebird extension startup warnings).
- `git diff --check`, mojibake, secret, and debug-output scans: PASS.
- Additional direct timeline-contract command: **18 passed / 1 skipped / 2
  failed**. The failures are `SupplierDualRoleTimelineFinancialDisplayTest`
  (expects the absent `customer_balance_effect` key) and
  `SupplierDualRoleTimelineNoDashTest` (expects a different final display
  balance). They are not masked or changed by this P1 and are blockers until
  reproduced against the base.

## Browser QA evidence

| Case | Expected | Actual | Status |
| --- | --- | --- | --- |
| Authenticate QA user | Local disposable login creates a session | Both available browser surfaces received HTTP 419 on Inertia login POST | BLOCKED |
| Customer timeline tooltip | One time column; explanatory tooltip; unchanged order/balance | Not reachable without authenticated session | BLOCKED |
| Supplier timeline tooltip | One time column; explanatory tooltip; supplier orientation intact | Not reachable without authenticated session | BLOCKED |
| Invoice detail | Transaction `09:49`; recorded `10:02` | API characterization PASS; browser visual check blocked | PARTIAL |
| Return detail roles | Source invoice/seller, creator, receiver remain explanatory only | Controller/test characterization PASS; browser visual check blocked | PARTIAL |

The production-shaped visual order `09:58` sales return above `09:49` invoice is
not reproducible on the fresh schema because `returns.return_date` is absent.
This is a separate schema/data-contract decision; this PR neither adds a
migration nor writes a substitute timestamp, and does not use `updated_at`.

The blocker is confined to disposable local browser QA: the Inertia login POST
returns 419 in both in-app Browser and Chrome after a fresh `/login` response.
No production server or production database was contacted. This is a release gate
blocker for marking the PR Ready, not evidence of a financial regression.

## Manual QA checklist (after local CSRF/session recovery)

1. Sign in to the disposable QA environment.
2. Open customer and supplier debt timelines. Confirm one `Thời gian` column,
   newest-first ordering, tooltip text, and no recorded-time badge.
3. Where `returns.return_date` exists in an approved QA schema, confirm return
   `09:58` remains above invoice `09:49`, not an invoice record time of `10:02`.
   On the repository's fresh schema, confirm the documented `created_at` fallback
   instead; do not infer a replacement from `updated_at`.
4. Open an invoice detail and check both labels/values: transaction `09:49`,
   system record `10:02`.
5. Open sales-return, purchase, and purchase-return details; confirm their known
   business/recorded-time fallback and that no `updated_at` is presented.
6. Repeat on a dual-role partner and confirm identical event identities/order,
   opposite supplier signs, and unchanged running/final balances.
7. Confirm pagination remains ten rows per page with unchanged boundaries and
   exports contain no new recorded-time column.

## Data safety

- No migration, backfill, recalculation, merge, deletion, or production command.
- No writes to invoices, returns, purchase returns, stock, serials, cash flow,
  debt records, or partner financial aggregates.
- QA database was newly created only for this task and is disposable.
- No production server/database access or mutation occurred.

## Files changed

- `resources/js/Components/TransactionTimeHelpTooltip.vue`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `resources/js/Pages/Returns/Show.vue`
- `resources/js/Pages/Purchases/Show.vue`
- `resources/js/Pages/PurchaseReturns/Show.vue`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/OrderReturnController.php`
- `tests/Feature/Debt/P1TimelineTimePresentationContractTest.php`

## Rollback

Revert this single P1 commit. The change has no migration, data rewrite, or
backfill, so rollback is code-only and does not require restoration or financial
recalculation.
