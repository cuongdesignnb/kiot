# KIOT and PC final cross-repository sign-off

## Frozen release pair

This document is canonical for KIOT PR #32 and PC PR #1. The application pair,
base SHAs, and release order are frozen in
`PC-KIOT-RELEASE-MANIFEST.md`. Sign-off/CI commits after the frozen application
SHAs are evidence-only and do not alter runtime behavior.

| Repository | Base | Reviewed application head | Pull request |
| --- | --- | --- | --- |
| KIOT | `production-customer-group` at `e807c4f69362d544e047939b5cc82c51ab11c3d1` | `691a7c6932978a10d7cce8937257574ad60dfc42` | #32 |
| PC | `main` at `21d02a9b82e8d802e2ad3386381bb6d02ccb1671` | `bcb7a82c22dcbccacddc9c00d9e966f3418aba47` | #1 |

The KIOT base advanced after provider UAT. It was merged without history rewrite.
The added base commit rotates POS idempotency keys per checkout intent and does
not change the provider API. All affected POS tests pass and the related JUnit
name-set comparison contains zero new defects.

## Contract matrix

| Contract item | KIOT provider | PC consumer | Status |
| --- | --- | --- | --- |
| Base path | `/api/integrations/v1/pc` | same configured base | PASS |
| Product list | `GET /products` | same | PASS |
| Product detail | `GET /products/{sku}` | same, raw URL encoding | PASS |
| Order create | `POST /orders` | same | PASS |
| Order status | `GET /orders/{external_order_id}` | same | PASS |
| Order cancel | `POST /orders/{external_order_id}/cancel` | same | PASS |
| HMAC canonical | method/path/timestamp/nonce/body hash | identical | PASS |
| Raw body | exact request bytes | frozen exact bytes | PASS |
| Query exclusion | path only | query excluded | PASS |
| URL encoding | decoded exact-case resolver | `rawurlencode` segments | PASS |
| Timestamp | Unix seconds, bounded skew | fresh on each attempt | PASS |
| Nonce | atomic one-time claim | fresh on each attempt | PASS |
| Idempotency-Key | required for mutations | stable per frozen event | PASS |
| Exact-case SKU | trim, case preserved | case preserved | PASS |
| Service exclusion | never returned/not sellable | never synced/sold | PASS |
| Cursor | `(updated_at,id)` | fully paginated | PASS |
| `updated_since` | application-timezone comparison | RFC 3339 with overlap | PASS |
| Tombstone | inactive/deleted when requested | lifecycle applied safely | PASS |
| Duplicate success | `200 duplicate=true` | terminal success | PASS |
| Business errors | stable 4xx codes | terminal/rejected | PASS |
| Retryable errors | 429/5xx/transport | bounded retry/backoff | PASS |
| Cancel errors | terminal/order-state codes | KIOT-first reconciliation | PASS |

Overall contract result: `CROSS_REPO_CONTRACT_VERIFIED=YES`.

## Review and validation status

- KIOT provider: HMAC/fail-closed controls, product visibility, exact SKU,
  customer resolution, cent-accurate totals, idempotency, reservations,
  cancellation, expiry, POS conversion, manual Serial/IMEI, lock period, and
  no-side-effects guarantees reviewed and covered.
- PC consumer: signature bytes, frozen outbox event, retry classification,
  dry-run/full/incremental product sync, protected KIOT-owned fields, atomic
  checkout, hash-only guest token, cancellation, SePay gating, storefront,
  Admin authorization, queue and scheduler gates reviewed and covered.
- KIOT focused provider suite: 36 tests, 261 assertions, PASS.
- KIOT Order/POS release gate: 11 tests, 76 assertions, PASS.
- KIOT broader related suite: 633 tests, 2,823 assertions, 27 failures and 5
  errors matching the known baseline defect set; zero newly introduced defects.
  The previously recorded full legacy suite remains red at 99 failures and 19
  errors, with zero PcIntegration defects; it is not a blocking workflow gate.
- PC KIOT-focused suite: 33 tests, 174 assertions, PASS.
- PC full backend suite: 35 tests, 177 assertions, PASS.
- Both three-migration rollback/up cycles, changed-file Pint/PHP lint, frontend
  clean installs/builds, diff checks, and source credential/debug scans pass.
- KIOT exposes exactly five protected routes and does not schedule reservation
  expiry. PC schedules are protected by flags, overlap locks, and one-server
  locks. All feature flags default false.
- GitHub had no pre-existing workflow. Minimal PR workflows were added for the
  exact provider/POS and full consumer gates. The live result must be read from
  the final PR head; a failed/cancelled/missing run blocks merge.

## Security and migration sign-off

No credential, signature, raw authentication header, guest token/hash, or public
raw integration payload is exposed by the reviewed diff. Integration logs mask
phone/email and do not log signature/secret. Guest reads and mutations require
the matching high-entropy token (hash only at rest); authenticated reads require
ownership. No debug helper or mojibake is present in the changed source.

All integration migrations are additive/reversible and default-off. KIOT test
counts remained `products/orders/customers=1/1/1` through rollback/up; invoice,
stock, CashFlow, and debt counts remained zero. PC base-table counts remained
unchanged in this run, and prior populated UAT preserved Product/Order counts.
No migration mutates business data or enables integration.

## Release decisions and remaining human gates

- Technical cross-repository review is complete when both final-head workflows
  succeed and remain attached to the current PR heads.
- Both PRs may then be marked ready for review, but this task does not change
  their Draft state.
- Neither PR is safe to merge until the required human approvals are present.
- KIOT must merge and deploy with its flag off before PC may merge.
- Both deployments are technically prepared for flags-off operation, subject to
  the backup and smoke checkpoints in the runbook.
- Production dry run and production enablement are not authorized.

References:

- `PC-KIOT-RELEASE-MANIFEST.md`
- `PC-KIOT-DEPLOY-WITH-FLAGS-OFF.md`
- `PC-KIOT-PROVIDER-VERIFICATION.md`
- `PC-WEBSITE-PRODUCT-ORDER-INTEGRATION-V1.md`
- `PC-WEBSITE-INTEGRATION-UAT.md`

Final policy values:

```text
MERGE_ORDER = KIOT_THEN_PC
FEATURE_FLAGS_DEFAULT_OFF = YES
PRODUCTION_ENABLE_AUTHORIZED = NO
SAFE_TO_ENABLE_PRODUCTION = NO
```
