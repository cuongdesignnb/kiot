# KIOT and PC deployment runbook with integration flags off

## Scope and hard stops

This runbook prepares a future deployment only. It does not authorize merging,
deployment, a production dry run, product apply, production Order creation, or
production enablement. Stop if a frozen SHA changes, a required check fails, a
review thread is unresolved, a backup cannot be verified, or the observed route
and scheduler state differs from this document.

Release order is fixed:

1. Approve and merge KIOT PR #32 into `production-customer-group`.
2. Deploy KIOT with `PC_INTEGRATION_ENABLED=false` and verify it.
3. Only then approve and merge PC PR #1 into `main`.
4. Deploy PC with all three KIOT integration flags false and verify it.
5. Open a separate production dry-run task.
6. Consider controlled enablement only after all dry-run gates pass.

## Checkpoint A: merge KIOT

Required evidence immediately before merge:

```text
KIOT_PR_APPROVED = YES
KIOT_PR_READY_FOR_REVIEW = YES
KIOT_CI_GREEN_OR_ACCEPTED_SCOPED_GATE = YES
KIOT_UNRESOLVED_THREADS = 0
CROSS_REPO_CONTRACT_PASS = YES
EXPECTED_KIOT_HEAD = <current immutable PR head>
```

Re-fetch both refs and reject the merge if the expected head or reviewed base
does not match. Use a repository-supported merge method; do not force-push or
rewrite history. Record `KIOT_PRE_DEPLOY_SHA` and `KIOT_MERGE_SHA`.

## Checkpoint B: deploy KIOT with flag off

Before deployment:

- Verify a restorable database backup and record its identifier, timestamp, and
  restore owner outside Git.
- Record the deploy artifact and rollback target. The current pre-merge base is
  `e807c4f69362d544e047939b5cc82c51ab11c3d1`; recapture the actual production
  pre-deploy SHA at execution time.
- Review the three migrations `2026_07_19_100000` through `100200`.
- Confirm compatible queue workers are available before code activation.

Required configuration:

```dotenv
PC_INTEGRATION_ENABLED=false
```

Credentials may be provisioned through the approved secret store, but must not
be printed in logs or committed. Deploy the immutable KIOT artifact, then run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --path=api/integrations/v1/pc
```

Smoke checklist:

- Exactly five V1 routes exist and an integration request fails closed with
  `503 INTEGRATION_DISABLED`.
- `integrations:expire-pc-reservations` is not on the production schedule.
- Existing Order/POS checkout, Invoice creation, stock, Serial/IMEI, CashFlow,
  customer debt, and warranty flows still work.
- No migration, queue, or sensitive logging error appears.

Set `KIOT_DEPLOY_WITH_FLAGS_OFF_VERIFIED=YES` only when every check passes.

## Checkpoint C: merge PC

Do not merge PC before `KIOT_DEPLOY_WITH_FLAGS_OFF_VERIFIED=YES`. Then require:

```text
PC_PR_APPROVED = YES
PC_PR_READY_FOR_REVIEW = YES
PC_CI_GREEN = YES
PC_UNRESOLVED_THREADS = 0
EXPECTED_PC_HEAD = <current immutable PR head>
```

Record `PC_PRE_DEPLOY_SHA` and `PC_MERGE_SHA`. The current pre-merge main SHA is
`21d02a9b82e8d802e2ad3386381bb6d02ccb1671`; recapture it at execution time.

## Checkpoint D: deploy PC with flags off

Verify a restorable PC database backup and compatible web, queue, and scheduler
artifacts. Required configuration:

```dotenv
KIOT_INTEGRATION_ENABLED=false
KIOT_PRODUCT_SYNC_ENABLED=false
KIOT_ORDER_SYNC_ENABLED=false
```

Deploy backend, Admin, Nuxt storefront, queue worker, and scheduler from the
same immutable release, then run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

Smoke homepage, product list/detail/search, cart, checkout, PC Builder, existing
and guest Order access, Admin Product/Order/Integration, and SePay. Inspect
queue/scheduler logs and require:

```text
OUTBOUND_KIOT_REQUESTS_WHILE_FLAGS_OFF = 0
PC_DEPLOY_WITH_FLAGS_OFF_VERIFIED = YES
```

## Migration compatibility evidence

All six integration migrations are additive and reversible; they contain schema
changes only and do not enable any flag.

| Repository | rows_before | rows_after rollback/up | schema_added | data_mutated | feature_enabled |
| --- | --- | --- | --- | --- | --- |
| KIOT test DB | products/orders/customers `1/1/1`; invoice/stock/cash/debt `0/0/0/0` | identical | external Order columns, events, reservations | NO | NO |
| PC test DB | product/order/user/cart/transaction `0/0/0/0/0`; prior UAT also preserved populated Product/Order counts | identical | KIOT Product/Order columns, sync state, outbox and payment event schema | NO | NO |

Foreign keys and unique indexes were reviewed; KIOT audit references restrict
deletion. Destructive rollback after production integration data exists is not
authorized by this runbook.

## Production dry-run plan

This is a separate, explicitly approved task. It begins with provider access on
and both PC mutation/sync flags off:

```dotenv
PC_INTEGRATION_ENABLED=true
KIOT_INTEGRATION_ENABLED=true
KIOT_PRODUCT_SYNC_ENABLED=false
KIOT_ORDER_SYNC_ENABLED=false
```

Only these read-only commands are initially allowed:

```bash
php artisan kiot:connection-test
php artisan kiot:sync-products --dry-run
```

Do not use `--apply`. Reconcile every unmatched SKU as
`expected_local_only`, `expected_kiot_only`, `service`, `inactive`, `retired`,
`data_error`, or `blocking`, and require:

```text
DUPLICATE_SKU = 0
UNKNOWN_CRITICAL_SKU = 0
CASE_MISMATCH_CRITICAL_SKU = 0
ACCIDENTAL_AUTOCREATE = 0
UNEXPLAINED_PRICE_DRIFT = 0
```

## Rollback

Before production enablement, disable all integration flags, clear config cache,
and roll back the deployed code through the normal release process. Database
rollback is allowed only when there is no integration data that must be kept.

After any production enablement, use flags as the first rollback switch:

```dotenv
KIOT_ORDER_SYNC_ENABLED=false
KIOT_PRODUCT_SYNC_ENABLED=false
KIOT_INTEGRATION_ENABLED=false
PC_INTEGRATION_ENABLED=false
```

Then run `php artisan optimize:clear` in each application. Do not roll back the
six migrations until external Order mappings, idempotency events, outbox rows,
integration events, reservation history, guest access metadata, and payment
events have been exported and their retention has been approved.
