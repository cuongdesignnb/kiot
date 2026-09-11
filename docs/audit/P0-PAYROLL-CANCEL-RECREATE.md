# Payroll cancellation before recreation

Risk: payroll history loss or duplicate settlement when replacing a paysheet.

## Behavior

The existing cancel endpoint now supports unposted draft/calculated sheets without
requiring them to be posted first. It preserves payslips and manual adjustments,
zeros outstanding amounts on the cancelled document and records an audit snapshot.
Drafts with financial postings, payments or applied advances are blocked for audit.

The UI retrieves a read-only cancellation preview and displays related payment
codes and totals. A confirmation hash rejects changed sheet/slip/payment inputs.
Locked sheets may cancel their linked salary payments atomically with the sheet;
this requires both payroll.cancel and payroll.pay.cancel. Legacy or ambiguous paid
records are blocked rather than guessed. Existing separate-payment cancellation
remains available. The old audit-paysheet-cancel command describes the old direct
posting-service path, not this new reviewed cascade.

Only linked salary payment cashflows are cancelled. Independent cashflows and
original advances remain intact; applied advances are released by the existing
posting service. Cancellation does not return money already transferred in real
life. Do not pay a replacement sheet again without reconciling actual money paid.
There is no automatic transfer of settlements or manual adjustments to the new sheet.

Cancelled posted documents remain searchable with the cancelled filter. A clean
unposted cancellation identified by its reviewed audit mode is technical history
only: hidden from all payroll lists/totals/exports and normal detail/edit/print
routes. This also recognizes prior reviewed unposted cancellations without a
backfill. Unknown legacy cancellations are not guessed or hidden. Retire employees
before creating a replacement so only active employees enter the new sheet.

Payroll list and CSV share branch, status, period and search filters. The UI sends
the same filters for both. Draft discard refuses even historical payment or advance
application rows, not only active ones. No financial-history rows are deleted.

## Financial report QA

Synthetic HTTP integration exercises the actual financial report, cashbook metrics,
payroll CSV and cashbook CSV before/after draft discard and locked cancellation
(unpaid, partial, full, next-month cancellation, advance plus payment). It checks
net profit, expense, fund balance, original advance retention, independent payment
retention, repeat safety and cancelled visibility. The current report recomputes
the original payroll period using current status; next-month cancellation does not
create a separately dated P&L adjustment. This policy is not changed by this patch.

## Verification

Synthetic integration tests cover draft cancellation, retirement and recreation,
partial/full and multiple payments, stale preview, independent cashflows,
permission checks, invalid links, released advances, repeat cancellation, and
complete rollback on audit failure. Local backup QA is transactionally rolled back
and never committed to source control. Frontend must be rebuilt on deployment.
No schema migration, backfill, or automatic production cancellation is required.
