# RR-PAYROLL-HOURLY-ABSENCE

## Scope and reproduced failures

Payroll/timekeeping only. Synthetic tests reproduce: hidden reversed work times
reject an explicit leave selection; empty manual work loses its review flag;
hourly payroll is blocked by day-unit totals even when hours do not overlap;
the next slot's exact start punch is consumed as the previous slot's orphan exit.

## Changes

- Explicit leave normalizes hidden work times, intervals and overtime to zero.
  Existing clear-time/downgrade confirmation remains required. No absence is
  inferred from missing punches, and device recalculation preserves manual leave.
- A punch exactly at the next slot's start belongs to that next slot.
- Hourly validation does not apply the daily unit cap. Real overlap, reversed
  times, incomplete work and regular-plus-OT exceeding worked minutes still block.
- Errors identify the date and slot and explain how to confirm leave or work.
  Payslip status displays stored regular hours and overtime hours separately.
- No migration, backfill, automatic production attendance edit, payroll posting,
  or payment mutation. Recalculation and posting still use the existing guarded workflow.

## Formula and remaining boundaries

Hourly base remains recorded regular minutes / 60 times the configured hourly
rate; configured overtime is paid separately. Work outside scheduled boundaries
is not automatically converted into payable regular time. This patch does not
change grace/rounding, paid-leave entitlement, holiday rates, bonuses or deductions.
Paid leave never fabricates worked/OT hours; any hourly paid-leave entitlement
must follow the agreed payroll policy, not be inferred by this fix.
Day-based salary and its unit validation remain unchanged.

Legacy missing punches need operator confirmation; a code deploy cannot prove
absence or reconstruct working time. Locked sheets and payments are not recalculated.

## Verification

Run SplitShiftTimekeepingTest, ManualTimekeepingTest, ConfirmedWorkUnitsPayrollTest,
ReviewedPayrollRecalculationTest, standard-day/manual-adjustment suites and payroll
cancellation/retirement regression suites. Synthetic morning unpaid leave plus
afternoon work must calculate and actually lock with one accrual entry. Preserve
manual leave across device recalculation. Test empty/incomplete work, true overlap,
excess OT, paid-leave normalization, hourly-vs-day-unit validation and existing
overnight/multiple-shift/OT cases. Build the frontend.

After code deployment: run `php artisan payroll:audit-confirmed-units --save --no-ansi`.
This is read-only for business data and saves a private report. The accountant
confirms the correct leave type for the affected slot, checks other slots, then
recalculates the unposted sheet, reviews hours/amounts and locks only when ready.
Do not publish real audit reports, names, identifiers or amounts in Git.

## QA result

- PASS: 113 tests / 1061 assertions across the ten regression suites listed above.
- PASS: frontend production build.
- PASS: read-only private local-backup audit completed without exceptions.
  That backup is historical, not proof of current production attendance.
- Pending on production: deploy, explicit attendance confirmation by the operator,
  recalculation and review of the affected unposted sheet. No automatic lock.
