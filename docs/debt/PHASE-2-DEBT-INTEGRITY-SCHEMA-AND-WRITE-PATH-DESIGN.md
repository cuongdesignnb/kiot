# Phase 2 Debt Integrity Schema and Write-path Design

## 1. Status and decision gate

```text
Document status: PROPOSAL FOR DESIGN REVIEW
Phase 1 merge SHA: f0c2709cea68218748f573459f56dae32165bf27
Phase 2 branch: feat/debt-integrity-phase2-schema-and-write-paths
Migration created: No
Migration run: No
Backfill: No
Production data mutation: No
```

This document starts Phase 2 design only. It does not declare the stored debt
cache canonical, correct current data, enable scheduled monitoring, or authorize
schema deployment.

> Cần xác nhận trước khi triển khai.

Approval is required before creating migrations, enabling new write paths,
running a baseline, or changing existing debt data.

## 2. Scope

Phase 2 covers:

- persisted supplier payment allocations;
- explicit partner debt opening balances;
- persistent integrity incidents and baseline/dedup behavior;
- debt-offset approval and idempotency;
- allocation and reversal constraints;
- transaction, idempotency, and outbox behavior across debt write paths.

It does not include stock movement, costing, serial/IMEI, payroll, legacy data
correction, or production deployment.

## 3. Current schema inventory

The inventory below was checked in migrations/models and against the local
production-like Docker snapshot on 2026-07-15. It is not a production preflight.

| Area | Current contract | Main gap |
|---|---|---|
| Stored partner balances | `customers.debt_amount`, `customers.supplier_debt_amount` | Compatibility caches; no freshness/version relation to source documents |
| Customer ledger | `customer_debts` with signed `amount`, running `debt_total`, optional document references | Legacy coverage is incomplete; no operation-level idempotency key |
| Supplier ledger | `supplier_debt_transactions` with `amount`, `debt_remain`, optional `purchase_id` | Generic payments can exist without exact persisted purchase allocation |
| Customer payment allocation | `customer_payment_allocations` links `cash_flow_id`, `customer_id`, `invoice_id`, `amount` | No allocation-level idempotency/reversal record; aggregate limits are application-enforced |
| Supplier payment allocation | No table or model exists | Manual allocation cannot be reconstructed as actual from legacy rows |
| Cash flow | `cash_flows` has unique `code`, status/cancellation fields, soft delete and nullable unique `idempotency_key` | Idempotency is not consistently populated by all debt flows |
| Customer document | `invoices.customer_paid` stores accumulated paid amount | Must stay synchronized with active allocation/reversal evidence |
| Supplier document | `purchases.paid_amount`, `purchases.debt_amount` store mutable totals | No durable evidence identifying which payment changed each purchase |
| Debt offset | `debt_offsets` stores amount, before/after snapshots and cancellation state | No approval state, idempotency key, balanced-side fields, or immutable reversal link |
| Opening balance | Timeline services can emit a virtual opening row | No approved, locked opening-balance source exists |
| Integrity incidents | No table or model exists | No baseline, deduplication, acknowledgment, resolution, or recurrence history |
| Outbox | No debt outbox table/model exists | No durable post-commit event delivery contract |

Existing exact constraints worth preserving:

- `customer_payment_allocations`: unique (`cash_flow_id`, `invoice_id`), foreign
  keys use `restrictOnDelete`;
- `cash_flows`: unique `code`, nullable unique `idempotency_key`;
- `debt_offsets`: unique `code`;
- invoices and purchases have unique document codes and partner foreign keys.

No migration or model with an equivalent contract was found for:

```text
SupplierPaymentAllocation
PartnerDebtOpeningBalance
PartnerDebtIntegrityIncident
PartnerDebtOperation
DebtOutboxEvent
```

## 4. Local record-count estimates

Exact local Docker counts:

| Table/case | Rows |
|---|---:|
| `customers` | 322 |
| dual-role partners | 13 |
| `invoices` | 381 |
| active invoices | 374 |
| `purchases` | 428 |
| active purchases | 417 |
| `cash_flows` | 745 |
| cancelled/deleted cash flows | 13 |
| `customer_debts` | 83 |
| `supplier_debt_transactions` | 233 |
| supplier ledger rows with `type=payment` | 100 |
| cash flows with `reference_type=SupplierPayment` | 23 |
| `customer_payment_allocations` | 3 |
| `debt_offsets` | 1 |

The difference between 100 supplier payment ledger rows and 23 generic supplier
payment cash flows reinforces that row matching cannot be inferred from code or
FIFO alone. Production sizing must be collected read-only before migration.

Planning assumptions, not production facts:

- one payment normally creates 1-5 allocation rows;
- one reversal creates one reversal row per affected allocation;
- one open incident exists per partner/role/fingerprint;
- outbox retention and archival must be sized from actual daily write volume.

## 5. Proposed schema

The following is a logical contract, not executable migration code.

### 5.1 `supplier_payment_allocations`

Purpose: persist the actual allocation selected by the user or applied by the
new auto-allocation service. Historical FIFO inference is never inserted here.

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `payment_id` | bigint FK to `cash_flows.id`, restrict delete |
| `purchase_id` | bigint FK to `purchases.id`, restrict delete |
| `supplier_id` | bigint FK to `customers.id`, restrict delete |
| `amount` | decimal(15,2), `amount > 0` |
| `allocation_source` | enum/string: `manual`, `auto` |
| `idempotency_key` | varchar(191), unique |
| `operation_id` | FK to `partner_debt_operations.id`, required |
| `allocated_at` | datetime, business event time |
| `created_by` | nullable FK to `users.id`, null on delete |
| timestamps | audit timestamps |

Required keys and indexes:

```text
UNIQUE(payment_id, purchase_id)
UNIQUE(idempotency_key)
INDEX(supplier_id, purchase_id)
INDEX(purchase_id, allocated_at)
CHECK(amount > 0)
```

Ownership and aggregate constraints cannot be expressed by ordinary foreign
keys alone. `SupplierPaymentService` must lock payment, supplier, and all target
purchases in deterministic ID order, then validate:

```text
payment is active SupplierPayment for supplier_id
purchase belongs to supplier_id and is active
sum(new allocations) <= payment.amount
sum(active allocations for purchase) <= purchase collectible balance
no duplicate purchase_id in one request
```

### 5.2 `supplier_payment_allocation_reversals`

Allocations remain immutable. Cancellation creates explicit reversal evidence.

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `allocation_id` | bigint FK to supplier allocation, restrict delete |
| `amount` | decimal(15,2), positive and equal to original allocation |
| `idempotency_key` | varchar(191), unique |
| `operation_id` | FK to `partner_debt_operations.id` |
| `reason` | text |
| `reversed_by` | nullable FK to `users.id` |
| `reversed_at` | datetime |
| timestamps | audit timestamps |

Required constraints:

```text
UNIQUE(allocation_id)
UNIQUE(idempotency_key)
CHECK(amount > 0)
```

Equality to the original amount is validated while both records are locked.
A partial reversal is not allowed in the first implementation; a correction is
a new payment/allocation operation, not an update to immutable evidence.

### 5.3 `customer_payment_allocation_reversals`

Existing customer allocations also remain immutable. This table mirrors the
supplier reversal contract without replacing `customer_payment_allocations`.

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `allocation_id` | bigint FK to `customer_payment_allocations.id`, restrict delete |
| `amount` | decimal(15,2), positive and equal to original allocation |
| `idempotency_key` | varchar(191), unique |
| `operation_id` | FK to `partner_debt_operations.id` |
| `reason` | text |
| `reversed_by` | nullable FK to `users.id` |
| `reversed_at` | datetime |
| timestamps | audit timestamps |

Required constraints:

```text
UNIQUE(allocation_id)
UNIQUE(idempotency_key)
CHECK(amount > 0)
```

This removes the need to infer whether an existing customer allocation is still
active from cash-flow status alone.

### 5.4 `partner_debt_opening_balances`

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `partner_id` | bigint FK to `customers.id`, restrict delete |
| `role` | enum/string: `customer`, `supplier` |
| `cutoff_date` | datetime |
| `amount` | signed decimal(15,2) |
| `source_document` | varchar(255), required |
| `source_checksum` | char(64), required |
| `approval_status` | `draft`, `approved`, `rejected`, `void` |
| `created_by` | FK to users |
| `approved_by` | nullable FK to users |
| `approved_at` | nullable datetime |
| `locked_at` | nullable datetime |
| `note` | text nullable |
| timestamps | audit timestamps |

Keys:

```text
UNIQUE(partner_id, role, cutoff_date)
INDEX(approval_status, cutoff_date)
```

Only an approved and locked row participates in calculation. It is never
created from a timeline residual or a guessed reconciliation difference.

### 5.5 `partner_debt_integrity_incidents`

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `partner_id` | bigint FK to customers, restrict delete |
| `role` | `customer`, `supplier`, `dual` |
| `status` | `open`, `acknowledged`, `resolved`, `suppressed` |
| `classification` | invariant classification code |
| `customer_difference` | decimal(15,2) |
| `supplier_difference` | decimal(15,2) |
| `fingerprint` | char(64) |
| `evidence` | JSON without PII-heavy payloads |
| `first_detected_at` | datetime |
| `last_detected_at` | datetime |
| `occurrence_count` | unsigned integer |
| `acknowledged_by/at` | nullable user FK/datetime |
| `resolved_by/at` | nullable user FK/datetime |
| `resolution_note` | text nullable |
| timestamps | audit timestamps |

Keys:

```text
UNIQUE(partner_id, role, fingerprint)
INDEX(status, classification, last_detected_at)
INDEX(partner_id, status)
```

The same fingerprint updates `last_detected_at` and `occurrence_count`. A
resolved incident that reappears is reopened with an audit event. Scheduled
monitoring remains disabled until a reviewed baseline exists.

### 5.6 `partner_debt_operations`

This table gives all debt write paths one operation and idempotency boundary.

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `operation_uuid` | UUID/char(36), unique |
| `partner_id` | nullable FK to customers, restrict delete |
| `operation_type` | controlled string |
| `idempotency_key` | varchar(191) |
| `request_hash` | char(64) |
| `status` | `pending`, `committed`, `reversed`, `failed` |
| `source_type` | immutable source model/type |
| `source_id` | immutable source primary key |
| `reverses_operation_id` | nullable self FK, restrict delete |
| `initiated_by` | nullable FK to users |
| `initiated_at/committed_at` | datetime |
| `metadata` | JSON, no secrets/PII dumps |
| timestamps | audit timestamps |

Keys:

```text
UNIQUE(operation_type, idempotency_key)
INDEX(partner_id, initiated_at)
INDEX(source_type, source_id)
UNIQUE(reverses_operation_id, operation_type)
```

A retry with the same key and request hash returns the committed result. The
same key with a different hash returns conflict and performs no write.

### 5.7 `partner_debt_outbox_events`

| Column | Proposed type/contract |
|---|---|
| `id` | bigint primary key |
| `event_uuid` | UUID/char(36), unique |
| `operation_id` | FK to partner debt operation, restrict delete |
| `aggregate_type` | event aggregate type |
| `aggregate_id` | event aggregate primary key |
| `event_type` | versioned event name |
| `payload` | JSON |
| `occurred_at` | datetime |
| `published_at` | nullable datetime |
| `attempts` | unsigned integer default 0 |
| `last_error` | text nullable |
| timestamps | audit timestamps |

Keys:

```text
UNIQUE(event_uuid)
INDEX(published_at, occurred_at)
INDEX(operation_id, event_type)
```

The outbox row is inserted in the same database transaction as the documents,
allocations, ledger rows, and stored-cache update. Publishing is asynchronous;
business success does not depend on an external broker call inside the request.

### 5.8 Additive hardening for `debt_offsets`

Keep all legacy columns. Add nullable columns first:

```text
approval_status
requested_by
approved_by
approved_at
idempotency_key
operation_id
customer_amount
supplier_amount
source_references JSON
```

Proposed constraints for new records:

```text
UNIQUE(idempotency_key)
CHECK(customer_amount > 0 AND supplier_amount > 0)
CHECK(customer_amount = supplier_amount)
CHECK(customer_amount = amount AND supplier_amount = amount)
```

Legacy rows remain readable with new fields null. No legacy value is generated
or changed by the schema migration. Enforcement of non-null approval fields is
deferred until all new writes use the Phase 2 service.

## 6. Existing-data compatibility and backfill decision

### Compatibility rules

1. Phase 1 read contract remains `STORED_COMPATIBILITY_CACHE`, not canonical.
2. New tables start empty and are used only by new writes after feature enablement.
3. Existing customer allocation rows remain authoritative for their payments.
4. Existing generic supplier payments without persisted allocation remain
   `inferred` or `ambiguous`; presentation may explain inference but cannot claim
   actual purchase ownership.
5. Existing virtual opening rows remain diagnostics only.
6. Existing debt offsets remain readable through the legacy contract.

### Backfill decision

```text
Automatic FIFO supplier-allocation backfill: REJECTED
Virtual opening-balance backfill: REJECTED
Automatic debt correction: REJECTED
Automatic incident baseline persistence: DEFERRED FOR APPROVAL
```

No Phase 2 migration may derive allocation ownership from FIFO, reference-code
similarity, running balances, or current `purchases.paid_amount`. If accounting
later supplies source evidence, a separately approved import must be dry-run,
reviewable, idempotent, and reversible.

## 7. Write-path transaction design

Every flow must use one outer transaction and one `partner_debt_operations` row.
Nested services may join the transaction but must not commit independently.

| Flow | Locked state and atomic writes | Idempotency and rollback |
|---|---|---|
| Invoice create | Lock customer; write invoice, receipt cash flow/allocation, signed customer ledger, stored cache, outbox | Key per create request; any failure rolls back all rows |
| Invoice update | Lock invoice, customer, existing allocations; calculate signed delta; append operation/ledger/outbox | Request hash prevents changed retry payload; no in-place ledger rewrite |
| Invoice cancel | Lock invoice and active cash flows/allocations; append exact reversals; update cache once | One reversal operation per source; repeated cancel returns existing result |
| Customer payment | Existing `CustomerPaymentService` pattern; lock customer/invoices; persist allocations before cache effect | Add operation key and immutable allocation reversal evidence |
| Customer refund/return | Lock return, invoice, customer, linked cash flow; append signed reversal evidence | Source-document idempotency; rollback document and debt together |
| Purchase create/update | Lock supplier and purchase; write supplier ledger and cache in same boundary | Operation key per create/update version; no stock/cost changes in this design |
| Supplier payment | New service locks supplier, payment, purchases; writes cash flow, ledger, exact allocations, document totals, cache, outbox | Manual and auto allocations persist actual selected result; retry returns same result |
| Supplier payment cancel | Lock payment and allocation rows; create one reversal per allocation; restore purchase totals and cache | Repeated cancel is no-op success; no hard delete |
| Purchase return | Lock supplier, purchase/return and allocation evidence; append document/ledger/cache deltas | Idempotent by return source; reverse only active effect |
| Manual adjustment | Require reason, role, approval permission and operation key; append ledger and update one role cache | No direct controller update to cache columns |
| Opening balance | Approved/locked opening row becomes explicit calculation input; append activation audit/outbox | Unique partner/role/cutoff; cannot edit after lock |
| Debt offset | Lock dual-role partner and offset; require approval; write balanced customer/supplier amounts and immutable reversal | Equal side amounts and one reversal operation enforced |
| Cash-flow cancel/delete | Financially linked flows route to owning domain service; generic CRUD cannot change amount/target | Domain reversal first; hard delete prohibited |
| Data import | Stage, validate, preview and assign deterministic keys before chunked transactions | Resume by idempotency key; failed chunk does not partially update a partner |

Deterministic lock order:

```text
operation idempotency key first
partner IDs ascending
document IDs ascending
payment IDs ascending
allocation IDs ascending
```

The operation row lookup/insert occurs before domain locks and writes, using
duplicate-key handling to serialize concurrent retries. All callers then follow
the remaining partner/document/payment/allocation order.

## 8. Idempotency and reversal policy

- Accept `Idempotency-Key` at API boundaries and generate deterministic keys for
  system-generated reversals/import rows.
- Scope uniqueness by `operation_type` so unrelated domains do not collide.
- Persist a normalized request hash; a key/payload mismatch is HTTP 409.
- Never update original allocation amount, ledger amount, or source reference.
- Cancellation appends a linked reversal and changes source status once.
- Retry after timeout reads the committed operation and returns its result.
- Cache updates use the signed operation delta exactly once under partner lock.
- Outbox consumers deduplicate by `event_uuid`.

## 9. Integrity incident baseline and schedule

The current manual checker remains read-only. Enabling persistence requires:

1. review classifications against a fresh read-only production artifact;
2. generate a proposed baseline file without inserts;
3. owner/accountant approve which findings are known/acknowledged;
4. persist the approved baseline through a separately reviewed command;
5. verify dedup, recurrence, and resolution behavior;
6. enable schedule behind a default-off configuration flag in a later PR.

The Phase 1 benchmark was 17,819 queries for 321 eligible partners (55.51 per
partner, 28.09 seconds). Before scheduling, the checker must batch preload source
evidence and demonstrate bounded queries and runtime on production scale.

## 10. Migration order

No migration is created by this proposal. After approval, proposed order is:

1. Re-run schema-name/model discovery and production read-only table counts.
2. Backup database and record restore point.
3. Create `partner_debt_operations` and `partner_debt_outbox_events`.
4. Create supplier allocation plus supplier/customer allocation reversal tables.
5. Create opening balance table.
6. Create integrity incident table.
7. Add nullable debt-offset hardening columns and safe indexes.
8. Deploy models/services with all new feature flags default off.
9. Enable new supplier-payment writes for a controlled role/branch.
10. Observe reconciliation and outbox health; expand only after acceptance.
11. Prepare incident baseline separately; do not enable schedule automatically.

Each migration PR must verify no equivalent table/model already exists. A near-
equivalent but incompatible schema must be escalated before migration creation.

## 11. Rollback strategy

Before any new writes:

- disable feature flags;
- roll back additive migrations on a test clone;
- production rollback may drop new empty tables only with explicit approval.

After new writes exist:

- disable new entry points and keep new tables readable;
- do not drop allocation, operation, opening, incident, or outbox evidence;
- deploy a forward fix;
- rebuild only derived caches from approved immutable evidence after a separate
  dry-run and owner approval;
- restore from backup only for a declared full deployment rollback.

No rollback updates legacy debt values automatically.

## 12. Deploy sequencing

```text
Design approval
-> migration PR and clone migration/rollback proof
-> application PR with feature flags off
-> production backup and schema preflight
-> additive migration
-> code deploy
-> controlled new-write enablement
-> reconciliation observation
-> approved incident baseline
-> later schedule enablement
```

Schema deploy, code deploy, baseline persistence, and schedule enablement are
separate owner-approved gates.

## 13. Outbox and audit strategy

- Operation and allocation/reversal records are append-only business evidence.
- Outbox insertion is atomic with the operation.
- Publisher marks `published_at` only after successful delivery.
- Failed events retain error/attempt metadata and are retryable.
- Audit payload records IDs, amounts, classifications and hashes, not full user
  forms, credentials, uploaded files, or production PII exports.
- Administrative acknowledgment/approval/resolution records actor and timestamp.
- Retention/archive policy must be approved before deleting published outbox rows.

## 14. Performance risks and mitigations

| Risk | Mitigation/gate |
|---|---|
| Allocation sum queries under concurrency | Composite indexes plus locked payment/purchase rows; aggregate once per batch |
| Full invariant scan N+1 | Batch preload documents/ledger/allocations; query-count regression gate |
| Incident upsert contention | Unique fingerprint key and bounded batch transactions |
| Outbox table growth | Published index, monitored lag, approved archival policy |
| Cache/source divergence | One transaction, signed delta, post-commit invariant sample |
| Deadlocks across dual-role flows | Deterministic lock ordering and bounded retry |
| Large historical imports | Staging, dry-run, chunked idempotent apply; no FIFO inference |

Target acceptance before daily scheduling:

```text
query count grows approximately O(batches), not O(partners * source queries)
no audit errors on full production-like scan
bounded memory and runtime documented
incident dedup/reopen behavior proven
```

## 15. Test plan

### Schema and migration

- migrate and rollback on a fresh test database and a production-clone database;
- verify existing rows and checksums unchanged;
- verify foreign keys, unique keys, positive-amount checks and nullable legacy
  compatibility;
- verify migration fails safely when a near-equivalent schema is detected.

### Supplier allocation

- manual allocation against FIFO persists the selected purchase;
- auto allocation persists each actual purchase allocation;
- wrong supplier, cancelled purchase, duplicate purchase, over-allocation and
  payment over-allocation are rejected;
- concurrent requests cannot exceed payment or purchase balance;
- cancellation creates one reversal per allocation and is idempotent;
- historical generic payment remains inferred/ambiguous and creates no row.

### Opening balance

- draft/rejected rows do not affect debt;
- approved and locked row affects only matching partner/role/cutoff;
- duplicate cutoff is rejected;
- locked row cannot be edited/deleted;
- no virtual residual can be auto-promoted.

### Integrity incidents

- same fingerprint deduplicates and increments occurrence count;
- changed material difference creates a distinct fingerprint;
- resolved recurrence reopens with audit evidence;
- technical/insufficient classifications do not become material incidents;
- schedule remains disabled until baseline flag and approval exist.

### Debt offset and all write paths

- approval and equal-side constraints;
- retry returns same operation without double cache/ledger/cash-flow effects;
- payload mismatch returns conflict;
- source cancellation reverses exactly once;
- injected failure after each write step rolls back the entire operation;
- customer/supplier timeline, report and CSV remain contract-compatible;
- stock movement, costing and serial/IMEI assertions remain unchanged.

### Performance

- query-count and runtime regression for 10, 100 and full partner scans;
- concurrent supplier payment and cancellation tests;
- outbox backlog/retry tests;
- explain-plan verification for allocation and incident indexes.

## 16. Review decisions required

Before implementation, BA/Owner/Senior Auditor must decide:

1. Whether `partner_debt_operations` is the shared idempotency boundary.
2. Whether supplier allocation reversal is full-only in the first release.
3. Required approval roles for opening balances and debt offsets.
4. Whether incident recurrence reopens the same fingerprint row.
5. Outbox consumer, retention and operational ownership.
6. Controlled rollout scope and observation period.
7. Whether any evidence-backed legacy import is needed later.

## 17. Final proposal status

```text
PHASE2_MIGRATION_REQUIRED=yes
PHASE2_MIGRATION_CREATED=no
PHASE2_BACKFILL_REQUIRED=no
PHASE2_DATA_MUTATION=no
READY_FOR_PHASE2_DESIGN_REVIEW=yes
READY_FOR_PHASE2_MIGRATION=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

> Cần xác nhận trước khi triển khai.
