# KiotViet partner debt business contract

Task: `KIOTVIET-PARTNER-DEBT-TIMELINE-CONTRACT-01`

## Economic partner and debt sides

One `customers` row is the stable economic partner identity. Persisted role flags decide list membership:

- `is_customer=true`: present in customer list.
- `is_supplier=true`: present in supplier list.
- both flags: dual-role partner.

The two accounting sides remain independent:

- `R = customers.debt_amount`: customer receivable. Positive means the customer owes the store.
- `P = customers.supplier_debt_amount`: supplier payable. Positive means the store owes the supplier.

Neither stored column contains the net of the other side. Stored projections are comparison targets and are never canonical event sources.

## Screen orientations

- Customer orientation: `R - P`.
- Supplier orientation: `P - R`.

For every canonical event:

```text
customer_display_delta = customer_delta - supplier_delta
supplier_display_delta = supplier_delta - customer_delta
supplier_display_delta = -customer_display_delta
```

Customer-only timelines select customer events and target `R`. Supplier-only timelines select supplier events and target `P`. Both dual-role endpoints select the complete partner stream and differ only by orientation.

## Canonical event contract

`CanonicalPartnerDebtEventService::build(Customer $partner)` is the single stream consumed by domain reduction, customer timeline, supplier timeline, audit and compatibility facade.

Every event carries stable identity, partner row identifiers, domain, persisted source, business time, ordering, both deltas, balance/reference flags, mirror/reversal links, detail link and presentation metadata.

Identity is source-based, never code-based:

```text
domain|source_type|source_id|event_kind|effect_side
```

Cash-flow allocation identity includes its allocation/document identity. Voucher code collisions across tables remain separate events.

Source precedence is business document, active cash flow, persisted cancellation/reversal, persisted audited adjustment, persisted opening/import/merge, then technical reference evidence. Fallback is used only for an uncovered amount. Technical ledgers and mirrors are reference-only when a document event exists.

## Event matrix

| Business event | `customer_delta` | `supplier_delta` |
|---|---:|---:|
| Sale | `+A` | `0` |
| Customer receipt/payment | `-A` | `0` |
| Sales return | `-A` | `0` |
| Customer refund | `+A` | `0` |
| Customer debt increase/decrease | signed | `0` |
| Customer payment discount | `-A` | `0` |
| Purchase | `0` | `+A` |
| Supplier payment | `0` | `-A` |
| Purchase return | `0` | `-A` |
| Supplier refund/credit | `0` | `+A` |
| Supplier debt increase/decrease | `0` | signed |
| Supplier payment discount | `0` | `-A` |
| Debt offset | `-A` | `-A` |
| Debt offset reversal | `+A` | `+A` |
| Any cancellation/reversal | negative of original | negative of original |

Debt offset is one canonical partner event. Its net display effect is zero in both orientations. Cash-flow and technical-ledger mirrors of that voucher are reference-only.

## Reversal contract

A reversal keeps the original document and references its canonical identity:

```text
reversal.customer_delta = -original.customer_delta
reversal.supplier_delta = -original.supplier_delta
original + reversal = 0 on both sides
```

Cancelled/soft-deleted cash flows are not active financial events. Stored balance must never be used to manufacture a missing reversal.

## Mutation contract

Every debt-affecting HTTP mutation uses one coordinator boundary: lock the
persisted partner row, validate its persisted role and idempotency payload,
write business document and cash/ledger evidence, update `R`/`P`, reduce the
fresh canonical stream, then commit only if both stored sides match it. An
injected failure at document, evidence, projection or pre-commit stage rolls
back the whole mutation. Replaying the same key and payload returns the stored
result; reusing a key with a different payload is a controlled conflict.

Partner-targeted standalone cash flows are financial documents: a customer
receipt/payment changes `R` by `-A/+A`; a supplier payment/receipt changes `P`
by `-A/+A`. They cannot be edited in place. Cancellation keeps the voucher as
cancelled evidence and reverses the exact stored side atomically.

## Role integrity

Persisted role, evidence role and owner-confirmed role are separate facts. Runtime list/timeline applicability uses persisted role only. Evidence without its persisted flag produces `ROLE_FLAG_EVIDENCE_MISMATCH`. The sole owner-confirmed dual-role code is `NCC177950763826`; `NCC177621742868` is persisted supplier-only and must never be role-repaired or exposed through a customer route.

Role repair may change only `is_customer` and `is_supplier`, records an ActivityLog and is idempotent. It never changes debt projections or financial documents.

## List scopes

Customer index, pagination, summary, search, filters, sorting, export and selectors use `is_customer=true`. Supplier equivalents use `is_supplier=true`. A supplier-only row cannot leak into customer list and a customer-only row cannot leak into supplier list.

## Ordering, running balance and pagination

Canonical chronological order is:

1. `business_time ASC`
2. `event_order ASC`
3. `event_identity ASC`

Precedence is opening `10`, sale/purchase `20`, return `30`, payment/refund `40`, adjustment/discount `50`, cancellation/reversal `60`.

Reference-only events do not change running balance. Running balances are computed over the full chronological stream, then the stream is reversed for display, and pagination is applied last. Dual-role pages therefore have identical identities, totals and ordering; every displayed delta and running balance is the opposite sign.

## Warning contract

Financial warning is exactly `raw_final_balance != target_balance` within the configured monetary tolerance. No virtual opening, display alignment or forced final balance may suppress it.

Role-integrity warning is rendered separately and never changes the financial warning. Frontend code renders backend `display_delta`, `running_balance`, `has_mismatch`, domain and detail metadata without deriving signs or document types.

## Audit layers

An applicable partner is `OK` only when all layers pass:

1. stored `R/P` equals canonical domain `R/P`;
2. customer list scope equals persisted customer flag;
3. supplier list scope equals persisted supplier flag;
4. each applicable UI raw final equals its target;
5. dual-role identity set, order, per-event signs, running balances and final balances are symmetric.
