# RR-PAYROLL-EMPTY-SLOT-GUARD

## Reproduction

Hourly payroll with a valid worked interval and empty generated slots was blocked
solely because the empty slots lacked both punches, although they contributed
zero hours and zero units. A synthetic failing test reproduces this regression.

## Fix

Narrow the hourly missing-punch guard: a slot with no punches, zero worked minutes,
zero units and no review flag does not require attendance confirmation. This is
not a conversion to leave and does not write attendance or add payable hours.
Partial punches, explicit review flags, inconsistent minutes/units, real overlap
and day-based allowance checks remain enforced. Empty manually submitted work
still receives its review flag from timekeeping. No salary formula is changed.

## Verification and deployment

Synthetic tests cover empty weekend and weekday slots, unchanged pay and hours,
actual payroll posting without attendance mutation, review flags, partial punches
and nonzero units without hours. Run the existing confirmed-unit, split-shift,
manual attendance, reviewed recalculation, retirement, cancellation/report,
standard-day and adjustment regression suites.

Backend-only deployment; no migration, frontend rebuild or backfill required.
Run `payroll:audit-confirmed-units --save --no-ansi` after deployment. Recalculate
an unposted sheet after resolving any remaining genuine review flag. Never bypass
review or alter locked payroll/payments to force posting. Production-derived audit
reports remain private and are not committed.

Local QA: PASS, 115 tests / 1070 assertions across the ten regression suites.
Changed PHP style and diff whitespace checks pass. Production execution remains
operator-controlled after code deployment and a fresh read-only audit.
