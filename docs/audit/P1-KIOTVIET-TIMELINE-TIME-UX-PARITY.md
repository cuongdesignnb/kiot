# P1 — KiotViet timeline-time UX parity

## Scope and mandatory boundary

PR #41 is a presentation-only clarification. It retains one `Thời gian`
column, business-time newest-first display, existing pagination, and the
customer/supplier orientation. It does not render `recorded_at` in a timeline
row, add a `Nhập sau` badge, add local sorting, or change exports.

No timeline-core boundary file changed: `CanonicalPartnerDebtEventService`,
`PartnerDebtTimelineOrientationService`, `CustomerDebtDomainEventSource`,
`SupplierDebtDomainEventSource`, `CustomerDebtDocumentTimelineService`,
`SupplierDebtDocumentTimelineService`, `SellerResolver`, and
`EmployeeReportController`.

## Presentation contract

- Customer display time is exactly:

  ```js
  entry?.display_time || entry?.time || entry?.business_time ||
  entry?.transaction_date || entry?.purchase_date || entry?.return_date ||
  entry?.created_at || ""
  ```

  `recorded_at` is not a timeline fallback; `purchase_date` remains
  available for legacy dual-role purchase events.
- The supplier helper remains business-time-first and was not otherwise changed.
- Timeline help says: “Thời gian giao dịch của chứng từ. Timeline và công nợ
  được sắp xếp theo thời gian này. Thời điểm chứng từ được ghi nhận vào hệ
  thống có thể muộn hơn.”
- Detail views say: “Thời gian giao dịch được dùng trong báo cáo và công nợ.
  Thời điểm ghi nhận là lúc chứng từ được nhập hoặc ghi nhận vào hệ thống.”
  They do not describe recorded time as workflow completion.
- An empty/invalid recorded time renders as `—`, never `Invalid Date`,
  `undefined`, or `null`.

## Fresh-schema fixture decision

The complete migration set has no `returns.return_date`. The P1 fixture
creates a return with `created_at = $returnRecordedAt` and adds
`return_date = $returnBusinessTime` only when
`Schema::hasColumn('returns', 'return_date')` is true. Fresh-schema
sales-return business time is therefore `created_at`; `updated_at` is never
used. No migration or schema workaround was added.

## IN_HEAD_READ_ONLY_REQUEST_NON_MUTATION

`P1TimelineTimePresentationContractTest::test_detail_time_requests_do_not_mutate_customer_supplier_or_dual_role_timelines`
takes normalized customer-only, supplier-only, dual-customer, and dual-supplier
snapshots, calls read-only detail endpoints, then takes snapshots again.

Result: **PASS — 1 test / 53 assertions** on the disposable MySQL QA database.
Detail requests do not mutate financial timeline output within the PR head.
This in-head non-mutation check is not presented as base-to-head proof.

## BASE_TO_HEAD_RUNTIME_PARITY

Two independent worktrees used the same PHP/MySQL versions, `APP_ENV=testing`,
migration set, deterministic fixture, and disposable databases:

| Worktree | Commit | Database |
| --- | --- | --- |
| Base | `9e145264abf42a1eb03e7d3af4be191f1962953c` | `kiot_pr41_base_qa` |
| Head runtime | `3ca9632a630b91883fcae552a3df65f5ab6f731a` | `kiot_pr41_head_qa` |

Both worktrees ran `SupplierDebtTimelineParityTest`,
`DualRolePartnerDebtTimelineTest`, `SupplierDualRoleOrientationKiotVietTest`,
`CustomerDebtDocumentTimelineTest`, `SupplierDebtDocumentTimelineTest`,
`SupplierDualRoleTimelineFinancialDisplayTest`, and
`SupplierDualRoleTimelineNoDashTest`.

Each result had 53 tests, 212 assertions, 31 passes, 5 errors, 17 failures, and
22 normalized failure signatures. Paths, timestamps, and object IDs were
normalized; the signature SHA256 is
`7bbcd32132fd9aa59e7e1babd2840dc1eeafadeeff82537bb5115fff1c07c3cb`.

| Check | Result |
| --- | --- |
| `FAILURES_IDENTICAL` | YES |
| `NEW_HEAD_ONLY_FAILURES` | 0 |
| `RESOLVED_HEAD_ONLY_FAILURES` | 0 |
| `MESSAGE_DRIFT_COUNT` | 0 |
| `BASELINE_FAILURES_REPRODUCED` | YES |
| `PR_INTRODUCED_TIMELINE_FAILURES` | 0 |

The 22 failures are existing baseline failures; this PR neither masks nor fixes
them. Machine-readable JUnit and comparison artifacts were retained only in the
task temporary directory.

Separate base-vs-head normalized financial snapshots match exactly:

| Orientation | SHA256 on base and head |
| --- | --- |
| Customer-only | `5109dda9068068dfe82567bab5926544865347dd0dd2c7a180ae09ea79d76210` |
| Supplier-only | `908ff65ec7247572d923a98334ee3d92d89dc6f9e4214c42e6d2e64d2c5ea7b2` |
| Dual-role, customer | `2509158a27429958c7ceb5213612ae6ef2ecc5fa718c68f31f4b013fbc0e1234` |
| Dual-role, supplier | `770dfeb28c9d2614e7cdddae1d7b0851109945d2563e88a5f33d6ea2298e0b4c` |

Snapshots normalize event identity/kind/source, business time/order, deltas,
effects/running balances, reconcile fields, source hash, entry count, and
pagination. All four hashes match.

## Browser-session 419 audit

The earlier 419 was local/disposable only: the testing configuration used the
non-persistent `array` session driver. No application code or CSRF middleware
was changed. QA used one origin and a file session:

| Item | Evidence |
| --- | --- |
| Browser URL and `APP_URL` | `http://127.0.0.1:8083` |
| `SESSION_DRIVER` | `file` |
| `SESSION_DOMAIN` | empty / config `null` |
| `SESSION_SECURE_COOKIE` | `false` |
| `SANCTUM_STATEFUL_DOMAINS` | `127.0.0.1:8083` (configured; no bypass) |
| Proxy headers | none; local PHP server only |
| `GET /login` | 200 with `XSRF-TOKEN` and `laravel_session` Set-Cookie |
| Cookies | `SameSite=Lax`; no `Secure` or `Domain` attribute |
| Login POST | normal UI, same origin/referer, dashboard success |

`php artisan optimize:clear` preceded the local-only restart. No production
session/server/database was used. Result: `LOGIN_HTTP_419=NO` and
`AUTHENTICATED_BROWSER_SESSION=PASS`.

## Manual browser QA evidence

All evidence uses the disposable `kiot_pr41_head_qa` database and normal UI
login.

| Case | Expected | Actual | Status |
| --- | --- | --- | --- |
| Customer timeline | One time column, newest business time first, no recorded row/badge | `TH-PR41-BROWSER` 10:02, `PT-PR41-BROWSER` 09:55, `HD-PR41-BROWSER` 09:49; balances 3.2m/4.2m/4.7m | PASS |
| Supplier timeline | One time column and supplier orientation | `THN-PR41-BROWSER` and `PN-PR41-BROWSER` show 09:49 with supplier signs | PASS |
| Dual Customer | Purchase retains `purchase_date`; IDs/order/customer signs stay intact | `PT-PR41-DUAL-BROWSER` 09:53 -100k, `PN-PR41-DUAL-BROWSER` 09:50 -900k, `HD-PR41-DUAL-BROWSER` 09:49 +700k | PASS |
| Dual Supplier | Same IDs/order, opposite supplier effects | Same items/times; +100k/+900k/-700k with 300k/200k/-700k running balances | PASS |
| Tooltip/accessibility | Correct wording, hover/focus support, accessible name | `tabindex="0"`, `aria-label="Giải thích thời gian giao dịch"`, `role="tooltip"`, `group-hover:block group-focus:block`; accessible label present in browser DOM | PASS |
| Responsive access | Tablet/mobile remain usable | Fixture row rendered at 768×1024 and 390×844; viewport reset after QA | PASS |
| Detail metadata | Business and recorded times remain distinct/read-only | P1 endpoint characterization passes the 09:49/10:02 invoice pair and supported document-detail paths | PASS |

The in-app QA browser does not expose hover automation; hover/focus was checked
from the rendered component contract and browser accessibility DOM. Timeline
snapshots contain no recorded-time value or `Nhập sau` badge, and retain the
existing ten-rows-per-page behavior.

## Validation, safety, and rollback

- `P1TimelineTimePresentationContractTest`: PASS — 1 / 53 assertions.
- Base/head targeted debt comparison: PASS — 22 baseline signatures reproduced,
  no head-only failure.
- CI #30 (`PC integration gate`) passed the original
  `9cfae80ee5c64834dac12ffb056cc001ab063778`; a new CI run is required for the
  final pushed head.
- `npm run build`: PASS.
- `vendor/bin/pint --dirty --test`: PASS. The final documentation-only change
  leaves no dirty PHP file; the changed PHP passed Pint before the code commit.
- PHP lint: PASS for all five changed controllers and the P1 test.
- `git diff --check`: PASS. Mojibake, secret, and debug-output scans: PASS.
- No migration, backfill, production command, production-server access, or
  production-database access/mutation. Database writes were limited to
  disposable local QA migrations, fixtures, and tests.
- No checkout, stock, invoice, purchase, return, cashflow, debt calculation, or
  partner financial aggregate is changed.
- Rollback is a code-only PR revert; it needs no data restoration,
  recalculation, or migration rollback.

## Files changed in PR #41

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/SupplierController.php`
- `resources/js/Components/TransactionTimeHelpTooltip.vue`
- `resources/js/Pages/Customers/Index.vue`
- `resources/js/Pages/Suppliers/Index.vue`
- `resources/js/Pages/Returns/Show.vue`
- `resources/js/Pages/Purchases/Show.vue`
- `resources/js/Pages/PurchaseReturns/Show.vue`
- `tests/Feature/Debt/P1TimelineTimePresentationContractTest.php`
- `docs/audit/P1-KIOTVIET-TIMELINE-TIME-UX-PARITY.md`
