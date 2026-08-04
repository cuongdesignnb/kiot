# P1 — Return sales-attribution override and P0 resold-serial cancel guard

## Scope and root cause

Historically, every sales return was assigned to the seller of its original
invoice. `SellerResolver` rebuilt that association from `returns.invoice_id` in
several report paths. A return could therefore not be assigned to the employee
who should own the sales reduction in the exceptional case where a returned
serial was later sold again by another employee.

Cancelling such a return was also unsafe: the cancellation workflow could
restore a serial to the original invoice even after that serial had been sold on
a different invoice.

This change adds reporting-only return attribution and a fail-closed
pre-mutation serial guard. It does not alter invoice ownership, document time,
stock, costing, debt, cash flow, serial ownership, customer totals, or company
total revenue.

## Data and API contract

Migration `2026_08_04_000000_add_sales_attribution_to_returns_table.php` adds
only nullable columns to `returns`:

| Field | Purpose |
| --- | --- |
| `sales_attribution_employee_id` | employee responsible for the return's sales reduction |
| `sales_attribution_name` | immutable display snapshot when the employee is later removed |
| `sales_attribution_reason` | latest required business reason |
| `sales_attribution_updated_by` | acting user |
| `sales_attribution_updated_at` | audit time |

`PATCH /returns/{return}/sales-attribution` is protected by the new explicit
permission `returns.sales_attribution.edit`. Its payload is:

```json
{
  "sales_attribution_employee_id": 7,
  "reason": "Khách đồng ý chuyển doanh số trả hàng cho nhân viên phụ trách"
}
```

`sales_attribution_employee_id: null` resets attribution to the original
invoice seller while preserving the reason and adjustment audit metadata.
Validation messages are Vietnamese; reason length is 5–500 characters.

`ReturnSalesAttributionService` uses a transaction plus row locks for the
return and selected employee. It writes exactly the five attribution metadata
fields, keeps `returns.updated_at` unchanged, and creates an ActivityLog only
when the payload makes a real change. The log includes original/old/new seller,
reason, business time, and explicit `financial_mutation=false` and
`inventory_mutation=false` flags.

## Resolver and report contract

`SellerResolver::returnSellerMap()` is now the single effective-seller rule:

1. a valid override is `employee:<id>`;
2. a removed employee with a name snapshot is `snapshot:<name>`;
3. otherwise the original-invoice seller is retained;
4. returns without either source are `unknown`.

Return revenue, returned items, return COGS, employee filters, report export,
and employee daily drill-down use this same map. A return with an override is
therefore included only for the override employee, while reports without an
override preserve their former result and return business date.

## P0 cancellation guard

Before any cancellation mutation, `OrderReturnController::cancel()` locks the
return, its return items, and every stored serial. Each serial must be exactly
`in_stock` with `invoice_id = null`. A resold serial is rejected before stock,
costing, debt, cash-flow, serial, status, StockMovement, or cancellation-log
changes. The specific resold message names both serial and resale invoice and
instructs staff to use sales-attribution adjustment instead.

The Return detail page now renders cancellation failures inline, preserving the
three-line backend safety message rather than using a validation alert.

## Changed files

- `app/Services/ReturnSalesAttributionService.php`
- `app/Support/Reports/SellerResolver.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Http/Controllers/EmployeeReportController.php`
- `app/Models/{ActivityLog,OrderReturn,Role,SerialImei}.php`
- `app/Console/Commands/GrantSensitivePermissions.php`
- `routes/web.php`
- `database/migrations/2026_08_04_000000_add_sales_attribution_to_returns_table.php`
- `resources/js/Pages/Returns/{Show,Index}.vue`
- `tests/Feature/OrderReturn/{ReturnSalesAttributionOverrideTest,ReturnCancelResoldSerialGuardTest}.php`

## Test evidence

### Automated

| Check | Result |
| --- | --- |
| attribution + resold-serial feature suite | PASS — 11 tests / 109 assertions |
| related seller/report/return/debt/serial regression suite | PASS — 51 tests / 213 assertions |
| disposable-MySQL migration rollback then re-apply | PASS |
| frontend production build | PASS |
| Pint on changed PHP | PASS — 13 files |
| PHP syntax lint on changed PHP | PASS |
| `git diff --check`, mojibake, secret, and debug-output scans | PASS |

The attribution suite covers default behavior, override, reset, duplicate
payload/no duplicate log, inactive and cancelled rejections, RBAC, snapshot
fallback, report/filter/daily/profit/COGS transfer, and unchanged financial and
inventory state. Its non-mutation snapshot explicitly covers invoice,
return_items, customer debt, customer total spent, stock, inventory total cost,
serial, and cash flow. It also proves an override remains in the report month
of the return document and that reset puts the return deduction back on the
original invoice seller. The guard suite covers one or multiple serials with a
resold serial, exact Vietnamese error text, full no-mutation snapshots, the
safe serial cancellation baseline, and retrying the same Idempotency-Key after
a blocked cancellation once the serial is safe again.

### Final independent review gate

The final review covered all 16 PR files. It corrected a duplicate/misplaced
`SellerResolver` PHPDoc and removed the explicit
`returns_sales_attribution_employee_id_index`: MySQL already creates the
single FK-supporting `returns_sales_attribution_employee_id_foreign` index for
`foreignId()->constrained()`. A fresh disposable MySQL migration run confirmed
exactly that one index. The migration rolled back cleanly (all attribution
columns absent) and re-applied cleanly.

| Check | Result |
| --- | --- |
| `ReturnSalesAttributionOverrideTest` | PASS — 7 tests / 76 assertions |
| `ReturnCancelResoldSerialGuardTest` | PASS — 4 tests / 33 assertions |
| `SellerResolver`, `EmployeeReport`, `OrderReturn`, `RR08`, `SerialImei` filters | PASS |
| `CustomerDebt` filter | BASELINE — 21 failed, 1 skipped, 79 passed / 396 assertions at both base `84707bc` and PR head; debt/timeline core files are not changed by this PR |
| full PHP lint for every changed PHP file | PASS |
| `git diff --check` | PASS |
| `npm run build` | PASS |
| Pint on dirty PR files | PASS |
| full-repository `vendor/bin/pint --test` | pre-existing baseline failure — 899 files, 2 errors, 514 style issues |

### Final isolated browser cancellation QA

The final resold-serial cancellation QA ran on 2026-08-04 with
Playwright-managed Chromium in a disposable `%TEMP%` directory. It used a
fresh, local MySQL 8 container (`kiot_pr42_browser_mysql`) and a Laravel server
bound only to `http://127.0.0.1:8892`. No user Chrome window, user browser
profile, production server, production credentials, or production database was
accessed.

The fixture contained original invoice A, a non-cancelled return with its
stored `ReturnItem.serial_ids`, and the same serial already sold on invoice B.
The serial was `sold` and linked to invoice B before either cancellation
request. The browser signed in through the normal login UI: `GET /login` was
200, the XSRF and session cookies were issued, the login POST succeeded, and
the authenticated return detail was 200. This application deliberately has no
`meta[name="csrf-token"]` in `resources/views/app.blade.php`; the same-origin
browser fetch used Laravel's issued `XSRF-TOKEN` cookie in the standard
`X-XSRF-TOKEN` header. CSRF middleware remained enabled and no cookie was
injected or hard-coded.

The direct JSON `POST /returns/1/cancel` returned HTTP 422 with
`errors.serial_ids`. Its Vietnamese message identified the resold serial and
invoice, instructed the user to use “Điều chỉnh người chịu doanh số trả hàng”,
and stated that stock, debt, and serial were unchanged. The UI then opened the
same detail route, accepted only its native confirmation dialog, and rendered
the same three-line Vietnamese message in `[role="alert"]`. No validation alert
was used. The screenshot and response evidence are kept outside the source
tree in `C:\Users\cuong\AppData\Local\Temp\kiot-pr42-browser-qa`.

Canonical pre- and post-request database snapshots covered the return, return
items, serial, product stock/cost, customer debt/spend, and counts for return
cash flows, stock movements, cancellation logs, and
`customer_return_cancel` partner-debt operations. The SHA-256 values are
identical: `829ed8e91b20807bbb02c614417d68c0700522bf92adcdd437143b91091d9621`.

```text
BROWSER_CANCEL_422_QA=PASS
DIRECT_JSON_HTTP_422=PASS
UI_INLINE_VALIDATION=PASS
VIETNAMESE_MESSAGE=PASS
NO_PARTIAL_MUTATION=PASS
BROWSER_ENGINE=PLAYWRIGHT_MANAGED_CHROMIUM
USER_CHROME_ACCESSED=NO
RETURN_STATUS_UNCHANGED=YES
SERIAL_STATE_UNCHANGED=YES
SERIAL_RESALE_INVOICE_UNCHANGED=YES
PRODUCT_STOCK_UNCHANGED=YES
INVENTORY_TOTAL_COST_UNCHANGED=YES
CUSTOMER_DEBT_UNCHANGED=YES
CUSTOMER_TOTAL_SPENT_UNCHANGED=YES
CASHFLOW_UNCHANGED=YES
STOCK_MOVEMENT_UNCHANGED=YES
CANCEL_LOG_UNCHANGED=YES
PARTNER_DEBT_OPERATION_UNCHANGED=YES
```

An exploratory broad PHPUnit regex sweep also matched 890 unrelated
customer/supplier debt-timeline suites. A pre-reset exploratory run reported 63
failures and 9 errors in those timeline/report contracts; a subsequent fresh
schema single-process run reached the 10-minute QA command limit before it
completed. Neither result is attributed to this change because none of the
affected timeline-core files are changed here. The scoped
report/return/debt/serial suites above are the release regression gate for this
PR and pass.

### Browser QA (disposable local fixture)

| Case | Expected | Actual | Result |
| --- | --- | --- | --- |
| Default detail | Original seller, effective attribution, and receiver are distinct | `Browser Seller A`, default attribution, and receiver shown separately | PASS |
| Permissioned modal | Explicit-permission user can open a clear modal | Button and employee dropdown rendered; first option is original seller | PASS |
| Validation | Short reason becomes inline Vietnamese field error without browser alert | `Lý do điều chỉnh phải có ít nhất 5 ký tự.` rendered under reason; no alert dialog | PASS |
| Override | Select `Browser Seller B` and save | Detail refreshed with adjusted state, reason, actor and timestamp | PASS |
| Index read-only view | Original and effective seller are distinguishable | List showed original A and adjusted B in separate columns | PASS |
| Reset | Select original-seller option and save | Detail returned to default attribution while retaining audit reason/time | PASS |
| Resold serial guard | Cancellation must return 422 and not mutate a resold serial return | Direct JSON returned `422/errors.serial_ids`; UI confirmation rendered the same inline Vietnamese `[role="alert"]`; canonical snapshots were identical | PASS |

The local QA server used an isolated MySQL container and a dedicated test
database. No production data, credentials, server, or database was used.

## Manual release checklist

1. Deploy migration before application code; confirm all five nullable columns
   and foreign keys exist.
2. Open a non-cancelled return as an authorized manager; choose an active
   employee, enter a business reason, save, and reload.
3. Confirm employee A no longer receives that return deduction and employee B
   does, including filters, export, daily drill-down, profit, and COGS views.
4. Reset to the original seller and confirm the original allocation returns.
5. Confirm customer debt, customer total spent, stock, inventory total cost,
   serial, cash flow, invoice link, return total, and business date are
   unchanged by attribution edits.
6. Try a return whose serial has been sold again. Confirm the cancellation is
   blocked and the inline three-line safety message appears with no mutation.

## Data safety, migration, and rollback

`MIGRATION=YES`. It is additive, reversible, and has been rollback/re-applied
against a fresh disposable MySQL QA database. `BACKFILL=NO`: existing rows use
the nullable fallback and keep their original report allocation. There is no
production database access or mutation in this work.

For rollback, first stop use of attribution edits, then deploy the migration's
`down()` with the compatible application version. It removes only the new
metadata columns and the explicit `branch_admin` permission. Do not manually
rewrite invoices, returns, serials, debt, or reports as part of rollback.
