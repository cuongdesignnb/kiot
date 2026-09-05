# Purchase external-cost debt correction

Base: production `b5362bd62fa81a697d8147a649da501482a6bfeb`.

## Evidence and rule

BIGBEN's old purchase has goods 1,630,000, paid 1,630,000, debt 0,
acquisition costs 41,000, and two separate cash vouchers: supplier payment
1,630,000 and external expense 41,000. The remaining purchase owes 780,000.
The previous canonical reducer added the fee to supplier payable (821,000).
The legacy ledger instead treated the expense as supplier payment (739,000).
Neither is correct for these documents.

`PurchasePayableService` computes goods minus discount plus costs **less
separately evidenced external costs**. Matching requires the same purchase
code, `reference_type=Purchase`, a positive payment, and the explicit expense
target type (`Chi phí`/`Chi phi`). Names such as "shipping" alone are never
used to infer the recipient. Costs without such evidence retain the existing
supplier-payable behavior. Acquisition costs and stock valuation are not
removed. Historical cancelled/soft-deleted expense vouchers retain their
payee evidence so a cancellation reverses the original obligation.

If external expense evidence exceeds purchase costs, calculation fails closed;
it does not cap the discrepancy, change stored debt or synthesize an adjustment.
Ambiguous/duplicate/replacement expense histories need review before edits.
This is not a new UI for selecting an external payee or paying third parties.

## Write and read paths

- Canonical purchase, payment coverage and cancellation share the payable rule.
- Legacy supplier ledger and inspection exclude expense vouchers from supplier
  payments. Inspection exposes persisted debt, cost and external-cost evidence.
- Create/update use the shared amount rule. Payment edits may change only
  supplier payment vouchers, never merge/cancel expense vouchers as duplicates.
- Partial updates preserve costs when the field is omitted; legacy repeatedly
  encoded JSON is decoded for editing without a read-time rewrite.
- Show/Edit previews subtract server-derived external cost evidence. A client
  cannot supply the authoritative external-cost amount.

## QA and rollout boundaries

Tests use a separate empty MySQL Docker schema with synthetic fixtures, not
production mutation. The exact BIGBEN regression and next 1,750,000 purchase
fail against the unmodified production base and pass with the fix.
Tests also cover fee amounts still payable to NCC, mixed fees, edit preservation,
legacy JSON, cancellation, rejected-edit rollback, retry and Excel.

`SupplierPayableLedgerTest` has four existing failures on both the unmodified
base and this branch (old source/type expectations). Do not call the entire
suite green or weaken those assertions to hide them.

No migration, backfill, role repair, balance adjustment or production command
is included. After normal review/build/deploy, rerun `debt:audit-parity` for
all partners and `debt:inspect-partner --dry-run --customer-id=56 --include-raw
--include-timeline --pretty`. BIGBEN should have canonical and stored payable
780,000 before new business transactions. Repeat a staging purchase and verify
2,530,000 after a 1,750,000 unpaid purchase. Do not create that test transaction
on production merely to verify the deployment.

Role anomalies (52/114), orphan references (55), unrelated historical debt
discrepancies, concurrency across every debt workflow, and a full immutable
journal rollout are outside this targeted correction. Future correctness for
all workflows cannot be inferred from this fixture alone.
