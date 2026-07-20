# POS Idempotency-Key Lifecycle Hotfix Validation

## Release metadata

```text
STATUS=PASS
VALIDATION_DATE=2026-07-20 (Asia/Ho_Chi_Minh)
REPOSITORY=cuongdesignnb/kiot
BASE_BRANCH=production-customer-group
BASE_SHA=0e68f13807aaa69b97e5c90b45b2131944b9c196
WORK_BRANCH=hotfix/pos-idempotency-key-lifecycle
PREVIOUS_HEAD=a8f4f4b303b499cb8a95e8ef1fbe4ac3e71cf283
VALIDATED_CODE_SHA=873b06133da74c389a86c94a5c668dd77a2a0109
WORKTREE_PATH=C:\tmp\kiot-pr33-uat
DRAFT_PR=33
PR_URL=https://github.com/cuongdesignnb/kiot/pull/33
MIGRATION_CREATED=no
BACKFILL_CREATED=no
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
PR_MERGED=no
PC_INTEGRATION_FILES_CHANGED=0
READY_FOR_OWNER_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
```

`VALIDATED_CODE_SHA` is the exact application-code HEAD exercised by the final automated gates. The immutable report commit SHA is recorded as `FINAL_HEAD` in the final handoff because a commit cannot contain its own SHA.

## Scope and root cause

The backend invariant remains: one idempotency key may only replay the same request hash. The POS previously persisted a tab-lifetime key and did not clear it after success, so a later sale from the same tab could reuse a committed key with a changed payload.

The hotfix binds each checkout attempt to the canonical endpoint and normalized payload, reuses the key only for an exact retry, rotates it when the payload changes, clears it after known success, and excludes quick orders. Legacy LocalStorage keys are removed while cart/customer data is preserved. Structured payload mismatch responses remain HTTP 409 without leaking key or payload data.

Browser UAT also reproduced two multi-tab defects within this PR's scope: asynchronous success of an earlier background tab changed the active tab by index, and the add-tab click handler could persist a `MouseEvent` as the tab type. Commit `873b06133da74c389a86c94a5c668dd77a2a0109` preserves active-tab identity when removing the completed tab and normalizes tab type, with regression coverage.

## Changed files

- `app/Http/Controllers/PosController.php`
- `resources/js/Pages/POS/Index.vue`
- `resources/js/Pages/POS/posIdempotency.js`
- `resources/js/tests/posIdempotency.test.mjs`
- `tests/Feature/POS/PosCheckoutIdempotencyTest.php`
- `docs/audit/POS-IDEMPOTENCY-KEY-LIFECYCLE-HOTFIX-VALIDATION.md`

No PC Integration, schema, migration, backfill, dependency, deployment, or production-data file changed.

## Browser and failure-injection UAT

All tests used local application ports `8133`/`8134` and the dedicated MySQL database `sales_test` exposed on local port `3319`. Production was not accessed.

| UAT | Result | Evidence |
| --- | --- | --- |
| UAT-01 consecutive invoices, same tab | PASS | Without refresh, invoice A `HD178454288092` (qty 1) and invoice B `HD178454297296` (qty 2, different payload) both succeeded. Each has one item, cash flow, and stock movement; stock changed 100 to 97. No Idempotency-Key error. |
| UAT-02 refresh after success | PASS | After refresh the prior successful tab was empty. Invoice `HD178454303230` succeeded using debt-operation key `5b67c0e3-a9b9-42c5-8e29-c8c52fd0a9af`, distinct from the prior committed key `1d54089e-39c1-4ec2-965a-a414917a6bfa`. |
| UAT-03 legacy LocalStorage | PASS | Seeded a legacy draft with key `stale-legacy-key-must-be-removed`. POS preserved cart, customer, and note; upgraded to schema 2 with `checkoutAttempt=null` and removed the legacy key. Invoice `HD178454347411` succeeded with new key `37464c88-d466-4a2e-858c-726fc119410e`; stale-key DB occurrences were zero. |
| UAT-04 commit then lost response, exact retry | PASS | Proxy forwarded the first request, received HTTP 200/invoice `HD178454444097`, then dropped the client response. Retry used the same key `37122908-d8ab-4c2b-9fb6-1866cf041849` and same SHA-256 body hash `a78382569f8e04103e47e3d17cdc1fe52a4c20ad2baf64e65a7712f38dc7d75e`; backend returned the same invoice. Exactly one invoice/item/cash flow/stock movement/debt operation/participant remained. |
| UAT-05 edit after uncertain result | PASS | Dropped request used key `23ae15ba-2003-44b2-9343-3bb8f0e6e1ef`, body hash `161e509bcd1df9f031da889e4e4ec6c2230960fb29829747983d0de0ed6d4c7f`, and committed invoice `HD178454457072`. Editing the note caused no auto-submit and no changed-payload invoice before the manual click. Manual submit used new key `a2dbde15-9735-4352-b06a-2f6cbfa22854`, new body hash, and created invoice `HD178454460094`. |
| UAT-06 three POS tabs | PASS | With three tabs and a 2.5-second delayed response for tab A, switching to tab B preserved tab B as the active logical tab after A succeeded. Tab C cart/note remained unchanged. Initial reproduction exposed the active-index and tab-type bugs; the scoped fix and regression tests passed. |
| UAT-07 process order | PASS | Quick order ID 1 was processed once into invoice `HD178454411487`, key `c613ee13-e56a-4b90-bed5-eda4c9d5d1d9`. After success, source order, payment summary, delivery state, cart/customer fields, and checkout attempt were reset. Reopening the completed order returned 422/alert and DB still contained exactly one invoice for the order. |
| UAT-08 quick order then normal sale | PASS | Quick order `DH178454402676` sent no Idempotency-Key and created no POS checkout attempt. The subsequent normal sale succeeded as invoice `HD178454406056` with fresh key `045cb0f2-045b-4704-a316-5f3d4523e60d`. |

## Database evidence

Before browser UAT, all required transactional side-effect tables were empty and the selected product stock was 100. After UAT (automated tests use rollback), the snapshot was:

```text
invoices=11
invoice_items=11
invoice_item_serials=0
cash_flows=11
stock_movements=11
products.stock_quantity=88
serial_imeis=0
customers.debt_amount=0
customers.total_spent=164500000
partner_debt_operations=6
partner_debt_operation_participants=6
warranties=0
```

Cross-checks grouped invoices by idempotency source/order, cash flows and stock movements by invoice, debt effects by operation/participant, and sold serials by Serial/IMEI. Results:

```text
DUPLICATE_INVOICES=0
DUPLICATE_CASH_FLOWS=0
DUPLICATE_STOCK_MOVEMENTS=0
DUPLICATE_DEBT_EFFECTS=0
SERIAL_DOUBLE_SALE=0
ORDER_DUPLICATE_INVOICES=0
```

## Exact validation commands

Database setup and local runtime (test database only):

```text
docker compose up -d sales_mysql_test
php artisan migrate:fresh --force
php artisan db:seed --class=DatabaseSeeder --force
php artisan db:seed --class=GeneralSeeder --force
php artisan db:seed --class=AdminUserSeeder --force
php -S 127.0.0.1:8133 vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
node C:\tmp\pr33-uat-proxy.mjs
```

The broad seeders encountered the existing customer-type enum mismatch, so the UAT used only the dedicated `sales_test` database and explicit minimal fixtures for admin, branch, product, and customer. No migration or backfill file was created and no non-test database was touched.

Automated regression commands:

```text
php artisan test tests/Feature/POS/PosCheckoutIdempotencyTest.php
php artisan test tests/Feature/Debt/PartnerDebtMutationCoordinatorTest.php tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php tests/Feature/Sales/RequireSerialOnSaleTest.php tests/Feature/Orders/ProcessOrderViaPosTest.php tests/Feature/POS/Step246CPosNoteAndDateFormatTest.php tests/Feature/POS/Step246DPosMoneyFormatTest.php
node --test resources/js/tests/posIdempotency.test.mjs resources/js/tests/moneyInput.test.mjs
npm run build
php -l app/Http/Controllers/PosController.php
php -l tests/Feature/POS/PosCheckoutIdempotencyTest.php
php vendor/bin/pint --test app/Http/Controllers/PosController.php tests/Feature/POS/PosCheckoutIdempotencyTest.php
git diff --check
git diff --name-only 0e68f13807aaa69b97e5c90b45b2131944b9c196...HEAD
$files = @('app/Http/Controllers/PosController.php','resources/js/Pages/POS/Index.vue','resources/js/Pages/POS/posIdempotency.js','resources/js/tests/posIdempotency.test.mjs','tests/Feature/POS/PosCheckoutIdempotencyTest.php','docs/audit/POS-IDEMPOTENCY-KEY-LIFECYCLE-HOTFIX-VALIDATION.md'); $pattern = '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9]{20,}'; Select-String -Path $files -Pattern $pattern -AllMatches
```

Results:

```text
FOCUSED_TESTS=PASS (3 tests, 29 assertions)
REGRESSION_TESTS=PASS (32 tests, 159 assertions)
FRONTEND_TESTS=PASS (19 tests)
NPM_BUILD=PASS (923 modules, 9.10s)
PHP_LINT=PASS
PINT=PASS (2 files)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
```

Local PHP emitted baseline startup warnings for missing optional Oracle/Firebird extensions (`oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`). They did not affect MySQL tests or syntax validation.

## Docker lifecycle

Only task-owned services were stopped. No unrelated container or program was stopped.

```text
php_uat_port_8133=stopped
proxy_uat_port_8134=stopped
docker stop sales_mysql_test=success
running_containers_after_stop=0
DockerCli.exe -Shutdown=success
docker_runtime_processes_after_shutdown=0
docker_daemon_after_shutdown=not_running
```

## Final gate summary

```text
BROWSER_UAT_01=PASS
BROWSER_UAT_02=PASS
BROWSER_UAT_03=PASS
FAILURE_INJECTION_UAT_04=PASS
FAILURE_INJECTION_UAT_05=PASS
MULTI_TAB_UAT_06=PASS
PROCESS_ORDER_UAT_07=PASS
QUICK_ORDER_UAT_08=PASS
DUPLICATE_INVOICES=0
DUPLICATE_CASH_FLOWS=0
DUPLICATE_STOCK_MOVEMENTS=0
DUPLICATE_DEBT_EFFECTS=0
SERIAL_DOUBLE_SALE=0
FOCUSED_TESTS=PASS
REGRESSION_TESTS=PASS
FRONTEND_TESTS=PASS
NPM_BUILD=PASS
PHP_LINT=PASS
PINT=PASS
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
PC_INTEGRATION_FILES_CHANGED=0
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
PR_MERGED=no
READY_FOR_OWNER_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
```

The PR remains Draft. Owner review is appropriate, but merge and production deployment remain explicitly disallowed by this validation.
