# Phase 2 Debt Integrity Schema and Write-path Design

## 1. Status and decision gate

```text
Document status: SENIOR-REVIEWED DESIGN PROPOSAL
Phase 1 merge SHA: f0c2709cea68218748f573459f56dae32165bf27
Phase 2 branch: feat/debt-integrity-phase2-schema-and-write-paths
Design baseline SHA: 16c26a8843736b34e2e513a5f410614dd652b8c1
Migration created: No
Migration run: No
Backfill: No
Production accessed: No
Production data mutation: No
```

This is a design-only review. It does not make the stored debt cache canonical,
correct current data, enable monitoring, authorize schema deployment, or approve
any production command. Migration implementation requires a separate approval.

> Approval is required before implementation.

## 2. Scope and senior verdict

Phase 2 designs:

- a shared operation/idempotency boundary for debt writes;
- persisted supplier payment allocations and immutable allocation reversals;
- explicit, approved partner debt opening balances;
- persistent integrity incidents with immutable event history;
- additive debt-offset approval and reversal controls;
- a database outbox with at-least-once publishing;
- controlled rollout of the supplier payment write path first.

It excludes stock movement, costing, serial/IMEI, payroll, legacy correction,
legacy allocation inference, production deployment, and production access.

Senior review result:

```text
P0 design blockers: 0
P1 design blockers: 0
P2 follow-up findings: index tuning and payload sizing require clone benchmarks
Ready for Senior acceptance of the design: Yes
Ready to create Migration PR A: No, separate approval required
```

## 3. Discovery evidence

### 3.1 Source and schema inventory

The following was checked in models, migrations, controllers, services, and the
local production-like Docker snapshot on 2026-07-15. This was not a production
preflight.

| Area | Current contract | Review finding |
|---|---|---|
| Partner cache | `customers.debt_amount`, `customers.supplier_debt_amount`, `decimal(15,2)`, default 0 | Compatibility cache with many direct writers; no source version |
| Customer ledger | `customer_debts.amount/debt_total`, `decimal(15,2)` | Signed service exists; legacy FK delete is cascade and needs risk review, not alteration in this design |
| Supplier ledger | `supplier_debt_transactions.amount/debt_remain`, `decimal(15,2)` | Generic supplier payments do not prove purchase allocation; `purchase_id` has no FK |
| Customer allocation | `customer_payment_allocations` | Unique payment/invoice and restrict FKs exist; no immutable reversal row |
| Supplier allocation | No table/model | Manual allocation is currently applied but not persisted as evidence |
| Cash flow | `cash_flows.amount decimal(15,2)`, status/cancellation fields, soft delete | Unique nullable `idempotency_key` exists but is not populated consistently |
| Invoice | `invoices.customer_paid decimal(15,2)` | Mutable total must equal active allocation/document evidence |
| Purchase | `purchases.paid_amount/debt_amount decimal(15,2)` | Mutable totals have no payment-to-purchase evidence for generic payments |
| Debt offset | Legacy amount/before-after/status/cancel columns | No approval, operation id, equal-side fields, or immutable reversal link |
| Opening balance | Virtual timeline residual only | No approved source document or locked cutoff |
| Integrity incident | No table/model | No durable current state, recurrence history, or suppression expiry |
| Outbox | No debt outbox | No durable post-commit event delivery |

Existing delete rules are mixed. Customer payment allocations use restrict, but
some legacy partner-ledger relationships use cascade and invoices/purchases use
set-null partner relationships. New financial evidence tables must use restrict
for business references. Existing foreign keys are not changed in Phase 2
without a separate impact review.

Exact local legacy-column evidence:

| Table/columns | Null/default/type and index evidence |
|---|---|
| `customers.debt_amount`, `supplier_debt_amount` | non-null `decimal(15,2)`, default `0.00` |
| `customer_debts.amount`, `debt_total` | non-null `decimal(15,2)`; indexes on customer/time and customer/type |
| `supplier_debt_transactions.amount`, `debt_remain` | non-null `decimal(15,2)`, default `0.00`; supplier FK cascades; nullable `purchase_id` has no FK |
| `customer_payment_allocations.amount` | non-null `decimal(15,2)`; unique cash-flow/invoice; all three business FKs restrict delete |
| `cash_flows.amount`, `status`, `deleted_at` | amount non-null `decimal(15,2)`; status nullable default `active`; Laravel soft delete timestamp nullable |
| `cash_flows.idempotency_key` | nullable `varchar(255)` with a unique index; existing population is not a universal debt-operation contract |
| `invoices.customer_paid` | non-null `decimal(15,2)`, default `0.00`; customer FK set null |
| `purchases.paid_amount`, `debt_amount` | non-null `decimal(15,2)`, default `0.00`; supplier FK set null |
| `debt_offsets.amount`, `status` | amount non-null `decimal(15,2)`; status non-null default `active`; unique code; customer FK cascades |

Observed local status groups were cash flow `active`/`cancelled`, invoice
localized completed/cancelled values (374/7), purchase
`completed`/`cancelled`/`partial_return`/`returned` (414/11/2/1), and one active
debt offset. Domain status checks must continue through the existing normalized
status helpers rather than introducing another free-text comparison.

No equivalent model or migration was found for:

```text
SupplierPaymentAllocation
SupplierPaymentAllocationReversal
CustomerPaymentAllocationReversal
PartnerDebtOpeningBalance
PartnerDebtIntegrityIncident
PartnerDebtIntegrityIncidentEvent
PartnerDebtOperation
PartnerDebtOutboxEvent
```

Before every migration PR, repeat full migration/model discovery. If a near-
equivalent schema exists with a different name, type, or contract, stop and
escalate rather than create a duplicate.

### 3.2 Local record counts

| Table/case | Rows |
|---|---:|
| `customers` | 322 |
| dual-role partners | 13 |
| `invoices` / active | 381 / 374 |
| `purchases` / active | 428 / 417 |
| `cash_flows` / cancelled or deleted | 745 / 13 |
| `customer_debts` | 83 |
| `supplier_debt_transactions` | 233 |
| supplier ledger `type=payment` | 100 |
| cash flow `reference_type=SupplierPayment` | 23 |
| `customer_payment_allocations` | 3 |
| `debt_offsets` | 1 |

The 100 supplier payment ledger rows versus 23 generic supplier-payment cash
flows demonstrates that code/FIFO matching cannot establish historical purchase
ownership. Production sizing remains a separate read-only preflight.

### 3.3 Local MySQL capability

```text
Server: MySQL 8.0.44 Community
Server charset/collation: utf8mb4 / utf8mb4_unicode_ci
InnoDB page size: 16384
CHECK constraint: enforced (invalid probe failed with error 3819)
JSON: supported
Stored generated columns and generated-column indexes: supported
utf8mb4 varchar(191) unique BTREE: supported
```

New-table indexes are compatible with this local server. This does not prove
production DDL behavior. Nullable column additions may be instant on MySQL 8,
but secondary indexes, unique indexes, and foreign keys can scan, rebuild, or
lock. Each DDL statement must be split, tested with `EXPLAIN ALTER TABLE` where
supported, and exercised on a production-like clone. Do not assume online DDL.

## 4. Architecture decision register

| # | Decision | Verdict | Binding contract |
|---|---|---|---|
| 1 | Shared operation boundary | **APPROVE** | `partner_debt_operations` is the transaction and idempotency boundary for each new debt write |
| 2 | Allocation reversal | **APPROVE FULL-ONLY FOR RELEASE 1** | Original allocations are immutable; one full reversal maximum per allocation |
| 3 | Approval and segregation | **APPROVE WITH PERMISSION-BASED RBAC** | Creator/requester differs from approver; self-approval off by default; checks at API and service |
| 4 | Incident recurrence | **APPROVE REOPEN SAME FINGERPRINT** | Current incident row is reopened; immutable incident events preserve history |
| 5 | Outbox | **APPROVE DATABASE OUTBOX** | Laravel worker, at-least-once delivery, idempotent consumers, lease/claim and dead-letter state |
| 6 | Controlled rollout | **APPROVE SUPPLIER PAYMENT FIRST** | Flags off; owner/admin/user allowlist and one branch where branch context is available |
| 7 | Legacy import/backfill | **REJECT FOR PHASE 2** | No FIFO allocation backfill, virtual opening promotion, or automatic correction |

### 4.1 Decision 1 failure and retry semantics

- The operation row is inserted as `pending`, all domain writes and its
  transition to `committed` occur in the same outer database transaction.
- Concurrent same-key inserts serialize on the unique key. The loser re-reads
  after the winner completes: same hash returns its committed result; a different
  hash returns conflict without domain writes.
- If the process dies before commit, the database rolls back the operation and
  every document/allocation/ledger/cache/outbox write. A retry may execute.
- If the process dies after commit but before the HTTP response, retry returns
  the committed operation result and creates no additional effect.
- A persisted `pending` row should not occur in release 1 because pending is not
  committed separately. If one is observed, do not steal or auto-complete it;
  raise an integrity incident and require evidence-based resolution.
- A rolled-back business operation does not leave a `failed` row. `failed` is
  reserved for a separately recorded, zero-financial-effect terminal failure;
  it must contain no source/result references. Re-execution requires a guarded
  `failed -> pending` transition with the same request hash.
- Retry deadlocks at most three times with the same operation key and normalized
  payload. No network call, queue publish, mail, or filesystem side effect may
  occur before commit.

### 4.2 Decision 3 permissions

Do not invent an `accountant` role. Use existing wildcard admin behavior and
permission checks:

```text
debt-opening.create
debt-opening.approve
debt-offset.request
debt-offset.approve
```

`created_by != approved_by` and `requested_by != approved_by` are mandatory.
Owner/admin may approve through wildcard permission but may not self-approve by
default. Rejection requires a reason. Approved timestamps, actors, amount,
cutoff, and source checksum are immutable. API authorization and domain service
authorization are both required.

### 4.3 Decision 6 rollout gate

All flags default off. The first enabled path is new supplier-payment allocation.
Access requires an owner/admin/user allowlist and, when the cash-flow/user context
has a branch, one configured pilot branch. If no reliable branch context exists,
the user/account allowlist is authoritative; do not infer a branch from partner
profile data.

Observation lasts seven days **or** 100 successful operations, whichever comes
later. Expansion requires:

```text
0 double-apply
0 wrong-supplier allocation
0 over-allocation
0 material drift caused by new writes
0 unresolved outbox backlog beyond SLA
idempotent retries proven
cancel/full reversal proven
timeline/report/export parity proven
```

## 5. Proposed schema

This section is a logical contract, not migration code.

### 5.1 `partner_debt_operations`

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `operation_uuid` | char(36), unique public correlation ID |
| `partner_id` | nullable FK to `customers.id`, restrict delete |
| `operation_type` | varchar(64), controlled application enum/registry |
| `idempotency_key` | varchar(191) |
| `request_hash` | char(64) SHA-256 |
| `request_hash_version` | unsigned smallint default 1 |
| `status` | `pending`, `committed`, `reversed`, `failed` |
| `source_type` | nullable controlled code, never a PHP class name |
| `source_id` | nullable unsigned bigint; assigned once before commit |
| `reverses_operation_id` | nullable self FK, restrict delete |
| `result` | nullable PII-safe JSON of IDs/codes/amounts needed for retry response |
| `attempt_count` | unsigned integer default 1 |
| `initiated_by` | nullable FK to users, null on delete |
| `initiated_at`, `committed_at`, `failed_at` | UTC datetimes |
| `failure_code` | nullable controlled code, no stack trace |
| `metadata` | nullable PII-safe JSON |
| timestamps | audit timestamps |

Required keys:

```text
UNIQUE(operation_uuid)
UNIQUE(operation_type, idempotency_key)
INDEX(partner_id, initiated_at)
INDEX(source_type, source_id)
UNIQUE(reverses_operation_id)
```

MySQL cannot enforce `reverses_operation_id <> id` with a `CHECK`
constraint because `id` is `AUTO_INCREMENT`. The schema therefore retains the
unsigned bigint auto-increment primary key, the self foreign key with
`ON DELETE RESTRICT`, and `UNIQUE(reverses_operation_id)`, without a trigger or
generated-column workaround. Before any write path is enabled, the application
service must prevent self-reversal and reversal cycles, validate committed and
non-reversed state, and perform the reversal under row locks in one outer
transaction.

Controlled operation types include invoice, customer payment/return, purchase,
supplier payment/return, adjustment, opening, offset, merge, repair invoice, and
approved import create/reverse variants. Unknown strings are rejected by the
service. A database enum is avoided so adding an approved type does not require
rewriting the foundation table.

Request normalization is versioned canonical JSON: keys sorted recursively,
UTF-8 strings normalized, money encoded as fixed two-decimal strings, timestamps
converted to UTC, arrays kept in business-significant order or explicitly sorted
by stable document ID, and transport-only fields omitted. SHA-256 is calculated
over `operation_type + hash_version + canonical_payload`.

After `committed`, operation type, key/hash, partner, source, result, and reverse
link are immutable. A reversal cannot reverse itself, cannot form a cycle, must
target a committed non-reversed operation, and marks the original `reversed`
only inside the reversal transaction.

Because `source_type/source_id` is a controlled polymorphic reference rather than
a database FK, the owning service must lock and validate the source before
commit. A committed operation cannot have a missing source when its operation
type requires one, and source records referenced by financial evidence cannot be
hard-deleted.

### 5.2 `partner_debt_operation_participants`

`partner_debt_operations.partner_id` is only a nullable primary-partner shortcut.
It is not the complete relation for a multi-partner operation. This table is the
authoritative participant set for both single- and multi-partner operations.

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `operation_id` | FK to `partner_debt_operations.id`, restrict delete |
| `partner_id` | FK to `customers.id`, restrict delete |
| `participant_role` | varchar(32), controlled application enum/registry |
| `effect_role` | nullable `customer`, `supplier`, `both`, or `none` |
| `customer_delta` | nullable decimal(15,2) signed cache/ledger effect |
| `supplier_delta` | nullable decimal(15,2) signed cache/ledger effect |
| timestamps | audit timestamps |

Required keys:

```text
UNIQUE(operation_id, partner_id, participant_role)
INDEX(partner_id, operation_id)
INDEX(operation_id, effect_role)
CHECK(effect_role != 'customer' OR (customer_delta IS NOT NULL AND supplier_delta IS NULL))
CHECK(effect_role != 'supplier' OR (supplier_delta IS NOT NULL AND customer_delta IS NULL))
CHECK(effect_role != 'both' OR (customer_delta IS NOT NULL AND supplier_delta IS NOT NULL))
CHECK(effect_role != 'none' OR (customer_delta IS NOT NULL AND supplier_delta IS NOT NULL AND customer_delta = 0.00 AND supplier_delta = 0.00))
```

Controlled participant roles initially include:

```text
primary
source
target
old_supplier
new_supplier
customer
supplier
```

Unknown or PHP-class-derived role strings are rejected. `null` delta means that
the role is not applicable to that participant; zero means a known neutral
effect. For every committed operation, participant deltas must reconcile to the
cache and ledger deltas written by that operation.

Single-partner operations require non-null `partner_debt_operations.partner_id`
and exactly one participant row, whose role is `primary` and partner matches the
shortcut. Multi-partner operations may use the operation-type-defined primary
shortcut or null, but every partner whose document, ledger, allocation, or cache
is affected must have a participant row.
For example, partner merge records `source` and `target`; supplier replacement
records `old_supplier` and `new_supplier`. Metadata JSON cannot substitute for
participant rows.

Participants are inserted and validated inside the operation transaction and
become immutable when it commits. The service validates that a non-null shortcut
matches its `primary` participant. Reversal operations retain every affected
partner, mirror the applicable participant roles, and record the exact negated
deltas. Querying operations/incidents for a partner uses this table, not only the
shortcut column.

### 5.3 `partner_debt_outbox_events`

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `event_uuid` | char(36), unique consumer deduplication key |
| `operation_id` | FK to operation, restrict delete |
| `aggregate_type`, `aggregate_id` | controlled aggregate code and ID |
| `event_type` | versioned controlled event name |
| `schema_version` | unsigned smallint |
| `payload` | PII-minimized JSON |
| `status` | `pending`, `publishing`, `retry`, `published`, `dead_letter`, `resolved` |
| `occurred_at`, `next_attempt_at` | UTC datetimes |
| `attempts` | unsigned integer default 0 |
| `locked_at`, `lease_expires_at` | nullable UTC datetimes |
| `locked_by`, `claim_token` | nullable worker/token strings |
| `published_at` | nullable UTC datetime |
| `last_error_code`, `last_error` | nullable bounded diagnostic fields |
| `dead_lettered_at` | nullable UTC datetime |
| `resolved_by/at`, `resolution_note` | nullable manual-resolution audit fields |
| timestamps | audit timestamps |

Required keys:

```text
UNIQUE(event_uuid)
INDEX(status, next_attempt_at, lease_expires_at, id)
INDEX(operation_id, event_type)
INDEX(published_at, id)
```

The event is inserted in the business transaction. Laravel workers claim a
bounded batch in a short transaction using `FOR UPDATE SKIP LOCKED`. Eligible
rows are due `pending`/`retry` rows or `publishing` rows whose lease expired. The
claim sets a unique token, worker ID, lease, status, and increments attempts,
then publishes outside the claim transaction. Success/fail updates require the
same unexpired claim token. A crashed worker leaves an expiring lease that a
later worker can reclaim. If publish succeeds but marking fails, a duplicate
delivery is allowed and the consumer deduplicates by `event_uuid`.

Retry uses bounded exponential backoff and `next_attempt_at`. Exhaustion moves
the row to `dead_letter`; only an audited manual resolution/replay can proceed.
Unpublished/failed events remain until resolved. Published events remain hot at
least 90 days and archived at least 365 days. Values are configurable and purge
is allowed only through an approved retention job. Events are never deleted
immediately after publish.

### 5.4 `supplier_payment_allocations`

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `payment_id` | FK to `cash_flows.id`, restrict delete |
| `purchase_id` | FK to `purchases.id`, restrict delete |
| `supplier_id` | FK to `customers.id`, restrict delete |
| `amount` | decimal(15,2), positive |
| `allocation_source` | `manual` or `auto` |
| `idempotency_key` | varchar(191), unique |
| `operation_id` | FK to operation, restrict delete |
| `allocated_at` | UTC business event datetime |
| `created_by` | nullable FK to users, null on delete |
| timestamps | audit timestamps |

```text
UNIQUE(payment_id, purchase_id)
UNIQUE(idempotency_key)
INDEX(supplier_id, purchase_id)
INDEX(purchase_id, allocated_at, id)
CHECK(amount > 0)
```

Cash-flow convention remains positive `amount` with `type=payment`. Supplier
ledger/cache effect is negative payment amount. Purchase paid/debt totals change
only by the allocated amount; an unallocated remainder creates global supplier
credit and is not assigned to a purchase.

The service locks the operation, supplier, active payment, and target purchases
in deterministic order. It verifies payment target/supplier, active status,
purchase ownership/status, unique purchase IDs, total allocation not exceeding
payment amount, and each allocation not exceeding the purchase collectible
balance. The collectible formula must come from the purchase domain service and
active return evidence, never timeline residuals or supplier cache. The current
system is single-currency; mixed-currency allocation is rejected until a currency
contract exists.

Historical FIFO inference is never inserted into this table.

### 5.5 Allocation reversal tables

Create both:

```text
supplier_payment_allocation_reversals
customer_payment_allocation_reversals
```

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `allocation_id` | FK to its allocation table, restrict delete |
| `amount` | decimal(15,2), positive and equal to original amount |
| `idempotency_key` | varchar(191), unique |
| `operation_id` | FK to reversal operation, restrict delete |
| `reason` | required text |
| `reversed_by` | nullable FK to users |
| `reversed_at` | UTC datetime |
| timestamps | audit timestamps |

```text
UNIQUE(allocation_id)
UNIQUE(idempotency_key)
CHECK(amount > 0)
```

Release 1 is full-only. Equality is checked while allocation/payment/document
rows are locked. Cancellation creates reversal evidence, restores document paid
totals, appends the ledger reversal, updates the cache once, updates payment
status, and inserts outbox events in one transaction. Partial correction means
full reversal followed by a new correct operation. Original allocation rows are
never updated or hard-deleted. Active state is derived from allocation minus its
explicit reversal, not cash-flow status alone.

### 5.6 `partner_debt_opening_balances`

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `partner_id` | FK to customers, restrict delete |
| `role` | `customer` or `supplier` |
| `version` | unsigned integer per partner/role/cutoff |
| `cutoff_at` | UTC datetime |
| `business_timezone` | varchar(64), default configured business timezone |
| `amount` | signed decimal(15,2) |
| `source_document_uri` | varchar(500), approved evidence reference only |
| `source_checksum` | char(64) SHA-256 |
| `status` | `draft`, `rejected`, `approved`, `active`, `reversed`, `void` |
| `active_guard` | stored generated nullable integer: 1 only when active |
| `created_by`, `approved_by`, `activated_by`, `reversed_by` | nullable user FKs |
| lifecycle timestamps | UTC created/approved/activated/reversed/rejected datetimes |
| `approval_operation_id`, `activation_operation_id`, `reversal_operation_id` | nullable operation FKs |
| `rejection_reason`, `note` | nullable text |
| timestamps | audit timestamps |

```text
UNIQUE(partner_id, role, cutoff_at, version)
UNIQUE(partner_id, role, source_checksum)
UNIQUE(partner_id, role, active_guard)
INDEX(status, cutoff_at)
```

The generated nullable guard allows many non-active rows but only one active row
per partner/role. Draft/rejected versions do not block a corrected proposal.
Cutoff input is interpreted in the configured business timezone and persisted in
UTC together with the timezone. The checksum covers normalized source bytes plus
partner, role, cutoff, amount, and checksum-version metadata; the database stores
only the evidence URI/checksum, not an uploaded PII-heavy payload.

Customer positive amount means receivable and negative means customer credit.
Supplier positive amount means payable and negative means supplier credit.
Approval does not affect calculation. Activation is a new operation that locks
the approved row and the partner; only active rows participate. Approved/active
rows are immutable. Reversal is full-only through a new operation. Virtual
timeline residuals are never promoted automatically.

### 5.7 Integrity incidents and immutable events

`partner_debt_integrity_incidents` stores current state:

| Column | Proposed type/contract |
|---|---|
| identity | `id`, partner FK restrict, role |
| state | `open`, `acknowledged`, `resolved`, `suppressed` |
| classification/severity | controlled codes |
| differences | customer/supplier decimal(15,2) |
| fingerprint | char(64) |
| evidence | PII-safe JSON |
| recurrence | first/last detected timestamps, occurrence count, `last_event_sequence` |
| acknowledgment/resolution | actor/timestamp/note |
| suppression | reason, actor, `suppressed_until` |
| baseline | baseline run/cutoff/checksum references |

```text
UNIQUE(partner_id, role, fingerprint)
INDEX(status, classification, last_detected_at)
INDEX(partner_id, status)
INDEX(status, suppressed_until)
```

Fingerprint version 1 uses invariant version, partner ID, role, classification,
two-decimal normalized differences, and sorted stable evidence IDs. It excludes
timestamps, display messages, names, phone/email, and volatile query ordering.
Different fingerprint means a new incident. The same fingerprint increments the
count; after resolution it reopens the same row without erasing resolution
history. Expired suppression creates `unsuppressed` and returns to open if still
detected. Technical/insufficient checks are retained as non-material diagnostic
observations/events and metrics; they do not become material drift alerts.

`partner_debt_integrity_incident_events` is append-only:

| Column | Proposed type/contract |
|---|---|
| `id` | unsigned bigint primary key |
| `incident_id` | FK to incident, restrict delete |
| `event_uuid` | char(36), unique public event identity |
| `dedup_key` | char(64), unique deterministic retry identity |
| `detection_run_id` | nullable char(36), stable controlled scan-run identity |
| `source_operation_id` | nullable FK to operation, restrict delete |
| `event_sequence` | unsigned integer, contiguous per incident |
| `event_type` | `detected`, `redetected`, `acknowledged`, `resolved`, `reopened`, `suppressed`, `unsuppressed` |
| `from_status`, `to_status` | nullable controlled states |
| `classification`, `fingerprint` | immutable snapshot values |
| `snapshot` | PII-safe JSON |
| `actor_id` | nullable FK to users |
| `occurred_at` | UTC datetime |
| `metadata` | PII-safe JSON |
| timestamps | audit timestamps |

```text
UNIQUE(event_uuid)
UNIQUE(dedup_key)
UNIQUE(incident_id, event_sequence)
INDEX(detection_run_id, incident_id)
INDEX(incident_id, occurred_at, id)
INDEX(event_type, occurred_at)
```

`detection_run_id` is created once before a scan attempt processes any batch. It
is stable across retries and is never derived from a timestamp alone. Detection
events require it. Their versioned dedup input is:

```text
SHA256(incident_id + event_type + fingerprint + detection_run_id + invariant_version)
```

Administrative events use:

```text
SHA256(incident_id + event_type + source_operation_id_or_explicit_idempotency_key)
```

The incident current-state update and event insert are one transaction: lock or
uniquely create the incident row, calculate/check the dedup key, return the
existing event without changing state/count when it exists, otherwise increment
`last_event_sequence`, insert the next immutable event, update occurrence/status
once, and commit. The unique dedup key serializes concurrent retries. A different
approved detection run may append one `redetected`/`reopened` event and increment
occurrence exactly once. Event rows never change.

Batch upserts process sorted partner IDs in bounded transactions. Daily
scheduling remains disabled until baseline, performance, recurrence,
idempotency, suppression, and event tests are accepted.

### 5.8 Additive hardening for `debt_offsets`

Keep all legacy columns and add nullable fields first:

```text
workflow_status
requested_by / requested_at
approved_by / approved_at
rejected_by / rejected_at / rejection_reason
applied_at
idempotency_key
approval_operation_id / apply_operation_id / reversal_operation_id
customer_amount / supplier_amount
source_references JSON
reverses_debt_offset_id
```

New-write state machine:

```text
draft -> pending_approval -> approved -> applied -> reversed
draft|pending_approval -> rejected
approved -> void (only before apply)
```

Only `applied` affects debt. Apply locks the dual-role partner and approved
offset, validates equal positive side amounts and current source references, then
writes both role effects in one operation. Applied records are immutable. Full
reversal creates a linked operation/offset evidence row; no partial reversal.

```text
UNIQUE(idempotency_key)
UNIQUE(reverses_debt_offset_id)
CHECK(customer_amount > 0 AND supplier_amount > 0)
CHECK(customer_amount = supplier_amount)
CHECK(customer_amount = amount AND supplier_amount = amount)
```

These checks apply only when all new fields are populated by the new service;
the migration must remain compatible with null legacy fields. Existing rows stay
readable and are not rewritten, inferred, approved, or reversed automatically.

## 6. Compatibility and no-backfill decision

```text
Decision 7: NO LEGACY BACKFILL IN PHASE 2
```

1. Phase 1 remains `STORED_COMPATIBILITY_CACHE`, not canonical.
2. New tables start empty and apply only to writes after their feature flag is
   enabled.
3. Existing customer allocation rows remain evidence for existing payments;
   cancellation after rollout can add a reversal only when the domain service
   proves the allocation and operation contract.
4. Existing generic supplier payments remain `inferred` or `ambiguous`; no row
   may claim actual purchase ownership without external evidence.
5. Existing virtual opening rows remain diagnostic only.
6. Existing debt offsets remain readable through the legacy contract.

```text
FIFO supplier-allocation backfill: REJECTED
Virtual opening-balance backfill: REJECTED
Automatic debt correction: REJECTED
Legacy debt-offset rewrite: REJECTED
Automatic incident baseline persistence: REJECTED IN MIGRATION
```

A later evidence-backed import needs a reviewable external source, dry-run,
deterministic keys, independent approval, full reversal plan, and no inferred
allocation ownership. It is not Phase 2.

## 7. Transaction and lock contract

Each enabled debt write has one outer database transaction and one operation
row. Nested services join it and never commit independently.

Deterministic lock order:

```text
1. operation unique key insert/read
2. partner rows by ascending ID
3. source documents by controlled type rank, then ascending ID
4. cash-flow/payment rows by ascending ID
5. allocation rows by ascending ID
6. ledger rows only when a legacy row must be inspected, ascending ID
```

Validation queries that affect an aggregate must run after the owning partner,
payment, and document rows are locked. Cache updates apply the signed operation
delta exactly once. All outbox rows are inserted before commit. Direct external
side effects are prohibited inside the transaction.

Participant rows are written after all affected partner rows are locked and
before domain effects are applied. A commit is rejected unless participant
deltas reconcile to every cache/ledger effect; multi-partner metadata without the
authoritative participant rows is invalid.

## 8. Complete write-path matrix

All feature flags below default off. `op` in lock cells means the operation key
is acquired first; IDs are sorted when multiple rows exist.

| FLOW | CURRENT SERVICE/CONTROLLER | CURRENT WRITES | NEW OPERATION TYPE | LOCK ORDER | DOCUMENT WRITE | ALLOCATION WRITE | LEDGER WRITE | CACHE UPDATE | OUTBOX EVENT | IDEMPOTENCY KEY SOURCE | REVERSAL PATH | FEATURE FLAG | ROLLBACK TEST |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Invoice create | `InvoiceController -> InvoiceSaleService` | invoice including `customer_paid`, cash flow, customer ledger/cache | `invoice_create` | op, customer, source order if any | create invoice/receipt | exact customer allocations when applicable | signed customer effect | `customers.debt_amount` once | `debt.invoice.created.v1` | API key or deterministic POS/order request ID | `invoice_cancel` | `debt_invoice_v2` | fail after each row leaves no invoice/payment/ledger/cache/outbox |
| Invoice update | `InvoiceController -> InvoiceUpdateService` | reverse/reapply invoice, cash flow, ledger/cache | `invoice_update` | op, customer(s), invoice, flows, allocations | append version/effect; no ledger rewrite | reverse old full allocations, create new | append delta/reversal | signed delta once | `debt.invoice.updated.v1` | API key + invoice version | compensating update/reversal operation | `debt_invoice_v2` | old document and debt remain intact on injected failure |
| Invoice cancel | `InvoiceController::destroy` | status, cash-flow cancel, customer ledger/cache | `invoice_cancel` | op, customer, invoice, flows, allocations | mark cancelled once | full reversal rows | append invoice/payment reversal | reverse signed effect once | `debt.invoice.cancelled.v1` | `invoice:{id}:cancel:{version}` | idempotent no-op after committed cancel | `debt_invoice_v2` | no partial cancel or double cache reversal |
| Order/POS to invoice | `OrderController` and POS process | invoice `customer_paid`, cash flow, customer ledger/cache | `order_invoice_create` | op, customer, order, invoice inputs | create invoice and complete order | persist payment/deposit evidence | signed invoice effect | customer cache once | `debt.order_invoiced.v1` | order ID + conversion version | invoice cancel through linked source | `debt_order_invoice_v2` | order stays unconverted if any debt write fails |
| Repair completion invoice | `TaskService::completeExternalRepair` | invoice `customer_paid`, cash flow, customer ledger/cache | `repair_invoice_create` | op, customer, task | create stock-neutral repair invoice | persist receipt allocation | signed invoice effect | customer cache once | `debt.repair_invoiced.v1` | task ID + completion version | repair invoice cancel operation | `debt_repair_invoice_v2` | task/invoice/debt all roll back together |
| Customer payment | `CustomerController -> CustomerPaymentService::collect` | cash flow, `invoices.customer_paid`, allocation, ledger/cache | `customer_payment_collect` | op, customer, invoices, payment | increment paid by allocations only | immutable rows | payment effect for full receipt | decrease receivable by full receipt | `debt.customer_payment.collected.v1` | API key | `customer_payment_cancel` | `debt_customer_payment_v2` | no over-allocation/double receipt under concurrency |
| Customer payment cancel | `CashFlowController -> CustomerPaymentService::cancel` | decrements invoice paid, adjustment ledger/cache, flow cancel | `customer_payment_cancel` | op, customer, invoices, payment, allocations | restore paid totals | one full reversal each | append payment reversal | increase receivable once | `debt.customer_payment.cancelled.v1` | payment ID + cancel version | repeated cancel returns committed result | `debt_customer_payment_v2` | failure restores original active payment state |
| Customer refund/return create | `OrderReturnController`, `OrderReturnCreationService`, `PosReturnExchangeService` | return/refund cash flow, invoice/return, ledger/cache | `customer_return_create` | op, customer, invoice/order, return, flows | create active return/refund evidence | reverse/allocate only proven payment evidence | append signed return/refund effect | customer cache once | `debt.customer_return.created.v1` | source return UUID/code | `customer_return_cancel` | `debt_customer_return_v2` | return, refund and debt roll back together |
| Customer return cancel | `OrderReturnController::cancel` | status/cash flow/ledger reversal | `customer_return_cancel` | op, customer, return, invoice, flows, allocations | cancel return once | full reversal where applicable | append reversal | reverse cache once | `debt.customer_return.cancelled.v1` | return ID + cancel version | idempotent committed cancel | `debt_customer_return_v2` | no duplicate refund or debt effect |
| Purchase create | `PurchaseController::store` | purchase paid/debt, cash flow, supplier cache; ledger coverage varies | `purchase_create` | op, supplier, purchase inputs | create purchase with paid/debt | direct purchase payment evidence, not generic allocation | append purchase effect | payable cache once | `debt.purchase.created.v1` | API key | `purchase_cancel` | `debt_purchase_v2` | financial rows rollback; stock assertions remain unchanged |
| Purchase-order receipt | `PurchaseOrderController` | creates purchase paid/debt; current path lacks equivalent supplier debt writes | `purchase_create_from_order` | op, supplier, purchase order | create purchase | direct payment evidence | append purchase effect | payable cache once | `debt.purchase_order.received.v1` | PO ID + receipt request ID | purchase cancel | `debt_purchase_v2` | no purchase without matching debt effect |
| Purchase update | `PurchaseController::update` | rewrites purchase paid/debt, cash flow, supplier cache | `purchase_update` | op, old/new suppliers sorted, purchase, flows | apply versioned amount delta | reverse/recreate direct payment evidence | append signed delta | old/new payable deltas once | `debt.purchase.updated.v1` | API key + purchase version | compensating update/full reversal | `debt_purchase_v2` | old supplier and new supplier remain unchanged on failure |
| Purchase cancel | `PurchaseController::destroy` | purchase status, cash-flow cancel, supplier cache | `purchase_cancel` | op, supplier, purchase, flows, allocations | mark cancelled once | full reversal rows | append purchase/payment reversal | reverse payable once | `debt.purchase.cancelled.v1` | purchase ID + cancel version | repeated cancel returns committed result | `debt_purchase_v2` | no partial financial cancellation |
| Supplier payment | `SupplierController::recordPayment` | cash flow, supplier ledger/cache, purchase paid/debt; no allocation table | `supplier_payment_collect` | op, supplier, purchases, payment | increment paid/decrement debt by allocated amounts | persist exact manual/auto rows | payment effect for full amount | decrease payable by full amount | `debt.supplier_payment.collected.v1` | required API key | `supplier_payment_cancel` | `debt_supplier_payment_v2` | wrong supplier/over-allocation/double apply all roll back |
| Supplier payment cancel | No current dedicated owning service; generic cancel rejects source-linked flow | missing domain reversal | `supplier_payment_cancel` | op, supplier, purchases, payment, allocations | restore paid/debt | one full reversal each | append payment reversal | increase payable once | `debt.supplier_payment.cancelled.v1` | payment ID + cancel version | repeated cancel returns committed result | `debt_supplier_payment_v2` | injected failures leave payment fully active |
| Purchase return create/quick return | `PurchaseReturnController::store/storeQuick` | return, refund cash flow, supplier cache | `purchase_return_create` | op, supplier, purchase if any, return, flows | create return/refund evidence | adjust only proven direct allocation evidence | append return/refund effect | payable cache once | `debt.purchase_return.created.v1` | return request UUID | `purchase_return_cancel` | `debt_purchase_return_v2` | return/refund/debt atomic; stock behavior not redesigned |
| Purchase return cancel | `PurchaseReturnController::destroy` | return status, cash-flow cancel/delete, supplier cache | `purchase_return_cancel` | op, supplier, purchase, return, flows | cancel return once | full reversal where applicable | append reversal | reverse payable once | `debt.purchase_return.cancelled.v1` | return ID + cancel version | repeated cancel returns committed result | `debt_purchase_return_v2` | no duplicate payable restoration |
| Manual customer adjustment | `CustomerController::debtAdjust -> CustomerDebtService` | cash flow, customer ledger/cache; controller is not one outer transaction | `customer_debt_adjust` | op, customer | immutable adjustment document/reference | none | append adjustment | receivable delta once | `debt.customer_adjusted.v1` | required API key | full adjustment reversal | `debt_adjustment_v2` | cash flow and ledger/cache all absent on failure |
| Manual supplier adjustment | `SupplierController::adjustDebt` | supplier ledger and direct supplier cache; no outer transaction | `supplier_debt_adjust` | op, supplier | immutable adjustment document/reference | none | append adjustment | payable delta once | `debt.supplier_adjusted.v1` | required API key | full adjustment reversal | `debt_adjustment_v2` | ledger/cache cannot split |
| Opening approval | No current service | none | `opening_balance_approve` | op, opening row | approve immutable proposal | none | none | none | `debt.opening.approved.v1` | opening ID + version | reject/void before activation | `debt_opening_balance_v2` | approval failure leaves draft unchanged |
| Opening activation | No current service | none | `opening_balance_activate` | op, partner, opening row | activate one guarded row | none | append explicit opening evidence if ledger contract requires | apply signed opening delta once | `debt.opening.activated.v1` | approved opening ID | full `opening_balance_reverse` | `debt_opening_balance_v2` | active guard and debt effect atomic |
| Opening reversal | No current service | none | `opening_balance_reverse` | op, partner, opening row | mark source reversed through linked op | none | append full reversal | reverse signed delta once | `debt.opening.reversed.v1` | active opening ID | no second reversal | `debt_opening_balance_v2` | source stays active if reversal fails |
| Debt-offset approval | `CustomerController -> DebtOffsetService` currently applies directly | legacy path has no approval | `debt_offset_approve` | op, offset proposal | approve immutable proposal | none | none | none | `debt.offset.approved.v1` | proposal ID + version | reject/void before apply | `debt_offset_v2` | no balance write during approval |
| Debt-offset apply | `DebtOffsetService::doOffset` | debt-offset, cash flow, supplier ledger, both cache columns | `debt_offset_apply` | op, dual-role partner, offset | mark applied with equal sides | none | append both role evidence | reduce both caches equally once | `debt.offset.applied.v1` | approved offset ID | `debt_offset_reverse` | `debt_offset_v2` | no one-sided offset under failure/concurrency |
| Debt-offset reversal | `DebtOffsetService::cancelOffset` | direct both-cache restore, cash flow, supplier ledger, status | `debt_offset_reverse` | op, dual-role partner, offset | append full linked reversal | none | append both reversals | restore both caches once | `debt.offset.reversed.v1` | applied offset ID | no second reversal | `debt_offset_v2` | source remains applied if any reversal step fails |
| Financial cash-flow cancellation | `CashFlowController::destroy -> CustomerPaymentService::cancel` | customer payment reversal or generic/source-required status | `financial_cashflow_cancel` delegates to owner | op, partner, source doc, flow, allocations | owning domain only | owning domain only | owning domain only | owning domain only | owning event plus cancel correlation | cash-flow ID + owner version | domain full reversal; no generic mutation | owning domain flag | generic CRUD cannot cancel linked flow partially |
| Partner merge | `PartnerMergeService` | both partner caches, relation transfer, zero marker, source reset | `partner_merge` | op, source/target partners ascending, relation docs | immutable merge snapshot/marker | none | zero reference marker only | transfer exact caches once | `debt.partner.merged.v1` | source-target deterministic key | no automatic unmerge in release 1 | `debt_partner_merge_v2` | no half-transferred partner/cache state |
| Data import | Supplier import is profile-only; cash-flow import creates generic unlinked flows | no approved direct debt import | `debt_import_apply` only in future approved design | op, partner IDs sorted per chunk, docs | staged validated rows only | exact evidence only | append only | signed deltas once | `debt.import.applied.v1` | file checksum + row key | full row operation reversal | `debt_import_v2` permanently off in Phase 2 | failed chunk leaves each partner atomic and resumable |

## 9. Direct financial column write inventory

The matrix above must replace or wrap every current direct writer before its flag
is enabled. Discovery found these concrete owners:

| Column | Current direct writers |
|---|---|
| `customers.debt_amount` | `CustomerDebtService`; `DebtOffsetService`; `PartnerMergeService` |
| `customers.supplier_debt_amount` | `PurchaseController`; `PurchaseReturnController`; `SupplierController::recordPayment/adjustDebt`; `DebtOffsetService`; `PartnerMergeService` |
| `invoices.customer_paid` | `InvoiceSaleService`; `InvoiceUpdateService`; `OrderController`; `TaskService`; `CustomerPaymentService` |
| `purchases.paid_amount` | `PurchaseController`; `PurchaseOrderController`; `SupplierController::recordPayment` |
| `purchases.debt_amount` | `PurchaseController`; `PurchaseOrderController`; `SupplierController::recordPayment` |

Additional current gaps that implementation must not hide:

- `PurchaseOrderController` creates completed purchases with paid/debt fields but
  does not mirror the normal purchase supplier-debt write contract.
- Customer and supplier manual adjustments are not currently one complete outer
  operation transaction.
- Supplier payment uses a debt value read before the transaction and does not
  lock the supplier/purchases deterministically.
- Generic cash-flow cancellation intentionally rejects `SupplierPayment`; a new
  supplier payment owner service is required before that flag can be enabled.
- Cash-flow CSV import creates generic flows only. Phase 2 must not reinterpret
  those rows as debt evidence.

## 10. Migration PR split and sequencing

This design review creates no migration. After separate approval, split
implementation:

### PR A - operation/outbox foundation

```text
partner_debt_operations
partner_debt_operation_participants
partner_debt_outbox_events
```

### PR B - allocation evidence

```text
supplier_payment_allocations
supplier_payment_allocation_reversals
customer_payment_allocation_reversals
```

### PR C - opening balances and incidents

```text
partner_debt_opening_balances
partner_debt_integrity_incidents
partner_debt_integrity_incident_events
```

### PR D - debt-offset additive hardening

```text
nullable columns and safe indexes only
```

Every PR must:

1. repeat duplicate migration/model discovery;
2. run migrate/rollback on a fresh test database;
3. run migrate/rollback on a production-like clone without `migrate:fresh`;
4. prove existing row counts/checksums unchanged;
5. inspect actual DDL plan/lock risk and split expensive indexes/FKs;
6. keep all application feature flags off;
7. perform no backfill, cleanup, correction, or baseline persistence;
8. document forward and rollback paths independently.

PR B depends on PR A. PR C event FKs are internal to PR C and operation links
depend on PR A. PR D operation links depend on PR A. Schema, application rollout,
baseline persistence, and schedule enablement remain separate approval gates.

## 11. Rollback and deploy design

Before any new write:

- flags remain off;
- each additive migration is rolled back on fresh and clone databases;
- empty new tables may be dropped only through the reviewed rollback.

After new writes exist:

- disable the affected entry flag immediately;
- keep operation/allocation/reversal/opening/incident/outbox evidence readable;
- do not drop evidence tables or rewrite legacy debt;
- deploy a forward fix;
- rebuild a derived cache only after separate dry-run and owner approval;
- use backup restoration only for a declared full deployment rollback.

No rollback automatically updates current debt values.

Proposed deployment gates:

```text
Senior design acceptance
-> Migration PR A approval and clone proof
-> PR B schema approval and clone proof
-> supplier-payment application PR with flag off
-> production backup and read-only schema preflight
-> additive migration approval
-> code deployment approval
-> controlled allowlist enablement
-> 7 days AND at least 100 successes
-> explicit expansion approval
-> later PR C/D gates
-> separately approved baseline
-> separately approved schedule enablement
```

## 12. Incident baseline and performance gates

The Phase 1 checker remains read-only. Before persistence/scheduling:

1. review classifications against a fresh read-only artifact;
2. generate a proposal baseline without inserts;
3. obtain owner approval for known findings;
4. persist only through a separately reviewed operation/import;
5. prove dedup, reopen, event, suppression, and resolution behavior;
6. prove bounded batch query/runtime behavior;
7. enable schedule only in a later default-off configuration PR.

Phase 1 measured 17,819 queries for 321 eligible partners (55.51 per partner,
28.09 seconds). Scheduling is rejected until source evidence is batch-loaded and
query growth is approximately O(batches), not O(partners * source queries).

## 13. Required tests for later implementation

### Schema and migration

- fresh and production-like clone migrate/rollback;
- existing row checksums unchanged;
- CHECK, generated active guard, FK, unique, and null-legacy behavior;
- near-equivalent schema detection fails safely;
- `EXPLAIN ALTER`/lock-risk evidence for each legacy-table change.

### Operations and concurrency

- concurrent same key/same hash returns one committed result;
- same key/different hash conflicts with no effect;
- process death before commit leaves no row/effect;
- retry after commit-before-response returns the existing result;
- injected failure after every write step rolls back all effects;
- bounded deadlock retry creates one effect;
- unexpected stale pending raises an incident and is not stolen;
- a single-partner operation has exactly one matching `primary` participant;
- partner merge records unique `source` and `target` participants;
- supplier replacement records `old_supplier` and `new_supplier` participants;
- duplicate operation/partner/role rows and partner hard-delete are restricted;
- reversal participant evidence mirrors every affected partner and negates the
  applicable deltas;
- operation queries by any affected partner use participant indexes and return
  multi-partner operations even when the shortcut points elsewhere.

### Allocations and reversals

- manual supplier allocation against FIFO persists the selected purchase;
- automatic FIFO persists actual new-write rows;
- wrong supplier, cancelled purchase, duplicate purchase, over-allocation, and
  payment over-allocation are rejected under concurrency;
- unallocated supplier payment creates global credit without purchase mutation;
- each cancellation creates one full reversal per allocation;
- partial reversal and second reversal are rejected;
- document totals, ledger, cache, payment status, and outbox are atomic;
- historical generic payment remains inferred/ambiguous and creates no row.

### Approval, opening, incidents, and offset

- creator/requester cannot self-approve by default at API or service layers;
- rejected drafts can be corrected with a new version;
- one active opening per partner/role under concurrency;
- timezone/cutoff/checksum and signed role semantics;
- approved/active records cannot be edited/deleted;
- same fingerprint reopens the same incident and appends immutable events;
- different fingerprint creates a new incident;
- retrying one detection run inserts one event and increments occurrence once;
- concurrent requests with the same dedup key insert one event;
- a new approved run appends one new `redetected` event;
- a resolved recurrence reopens once for the same run;
- acknowledge/resolve retries are idempotent by operation or explicit key;
- event sequence increases contiguously while the incident row is locked;
- suppression expiry and technical/insufficient non-material behavior;
- debt offset equal-side, idempotency, source, apply, and full reversal rules;
- legacy nullable rows remain readable and unchanged.

### Outbox, parity, and performance

- two workers cannot hold the same valid lease;
- lease expiry, retry schedule, dead-letter, manual resolution, and replay;
- publish-before-mark duplicate is consumer-idempotent by event UUID;
- retention does not purge unresolved events;
- customer/supplier timelines, reports, and CSV remain contract-compatible;
- stock movement, costing, and serial/IMEI assertions remain unchanged;
- query plans and runtime for allocation/incident/outbox indexes are recorded.

## 14. Residual P2 findings

No P0/P1 blocker remains in the design. These P2 items must be resolved with
implementation evidence rather than guessed in this document:

- final composite index order after clone `EXPLAIN` and workload benchmark;
- outbox payload size/SLA and archive storage implementation;
- exact business-timezone configuration source;
- production DDL algorithm/lock behavior;
- exact list of operation/event enum values in application code;
- participant-index selectivity under production-like partner history;
- incident-event retention, archive volume, and write-rate benchmark.

## 15. Final proposal status

```text
SCHEMA_DISCOVERY_COMPLETE=yes
MYSQL_CAPABILITY_REVIEWED=yes
SEVEN_DECISIONS_CLOSED=yes
MULTI_PARTNER_OPERATION_CONTRACT_COMPLETE=yes
INCIDENT_EVENT_IDEMPOTENCY_CONTRACT_COMPLETE=yes
WRITE_PATH_MATRIX_COMPLETE=yes
DIRECT_CACHE_WRITES_IDENTIFIED=yes
MIGRATION_PR_SPLIT=A/B/C/D
PHASE2_MIGRATION_REQUIRED=yes
PHASE2_MIGRATION_CREATED=no
PHASE2_MIGRATION_RUN=no
PHASE2_BACKFILL_REQUIRED=no
PHASE2_DATA_MUTATION=no
APPLICATION_CODE_CHANGED=no
PRODUCTION_ACCESSED=no
READY_FOR_DESIGN_SENIOR_ACCEPTANCE=yes
READY_TO_MARK_DESIGN_PR=yes
READY_TO_MERGE_DESIGN_PR=yes
READY_FOR_MIGRATION_PR_A=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

> Approval is required before implementation.
