# External purchase expenses and supplier payable

This change separates acquisition costs from amounts owed to a supplier.
Only explicitly linked external expense vouchers identify external costs;
free-text descriptions alone never determine the payee. Other costs retain
the existing supplier-payable behavior.

The shared calculation is used by canonical events, purchase writes and
legacy inspection. Supplier payment edits exclude external expense vouchers.
Partial updates preserve omitted cost fields, including legacy encoded JSON.
Show/Edit previews use server-derived external-cost evidence.

Cancelled and soft-deleted expense vouchers retain historical payee evidence
so cancellation can reverse the original obligation. Ambiguous histories,
multiple expense vouchers or evidence exceeding purchase costs need audit;
they must not be silently capped or repaired in production.

Tests contain synthetic partners, identifiers, dates and amounts only. They
cover orientation, payment edits, partial updates, cancellation, rollback,
retry, Excel and costs that remain payable to the supplier.

No migration, backfill, automatic financial repair or production deployment
is included. The read-only scope script is run from the application root on
MySQL/MariaDB before rollout. Its output may contain confidential records:
keep it private and never commit or attach it to a public issue or PR.

This targeted correction does not prove correctness of every debt workflow
or replace a full transaction/concurrency audit.
