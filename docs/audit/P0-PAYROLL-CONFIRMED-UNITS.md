# Payroll: use recorded attendance units

## Problem and behavior

The salary calculator reclassified recorded attendance from minutes. With a
600-minute standard and a 480-minute half-day ceiling, a recorded full day of
568 minutes became zero payroll units. Payroll now consumes `work_units` from
attendance instead of rebuilding attendance or reclassifying minutes.

The same pure `PayrollPayslipCalculator::preview` is used for creation,
recalculation and audit. Daily units, paid leave and holiday multipliers are
reported separately; leave is not multiplied. Multiple shifts are added once;
overlapping work intervals, more than one work/leave unit per day, inconsistent
day types and records requiring review block posting. Hourly salary continues
to use regular minutes and the existing overtime calculation. Fixed salary
continues to use the configured amount.

Inline monetary overrides and popup adjustments survive recalculation. Editing
a popup clears the corresponding older inline override. Remaining payable is
total minus payments and applied advances, floored at zero.

## API and operator workflow

No migration is required. Calculation metadata is stored in existing payslip
`details`: `calculation_version`, `input_fingerprint`, `attendance_days`, and
`validation` (`ready`, `blocked`, `zero_requires_reason` with readable issues).

The existing `PUT /api/paysheets/{id}/payslips/{slipId}` accepts
`zero_salary_reason` (5–500 characters). Its confirmation records actor, time
and the input fingerprint. It cannot override missing configuration or invalid
attendance, and expires when calculation inputs change. `direct_overrides`
stores explicit inline amount edits. Existing adjustment semantics are retained.

GET details is read-only, including when `needs_recalc` is true. The operator
uses the existing recalculation button. Locking rejects old calculations,
changed sources, unresolved issues and unconfirmed zero base salary before
posting salary ledger entries. Existing locked/cancelled periods are never
recalculated by this workflow. Locking an already locked sheet remains idempotent.

## Read-only production audit after code deployment

Run from the application directory. Output is concise JSON; the private file
contains all rows, before/preview components and reasons. Exit 0 means the audit
executed successfully, not that all employees are clear to post. Check
`summary.actions`. Exit 2 reports input/audit failures.

```bash
(
  cd /www/wwwroot/kiot.cuongdesign.net || exit 1
  php artisan payroll:audit-confirmed-units --save --no-ansi
)
```

To narrow to a reviewed sheet, use `--paysheet=ID`. `--details` also prints rows.
The command uses a read-only database transaction. Its only output write with
`--save` is a private JSON file under `storage/app/audits`, mode 0600.

## Rollout and historical handling

1. Review and deploy this code plus the frontend build through the normal
   pinned-commit deployment. No migration or automatic data backfill.
2. Run the audit above and retain its file/hash. Match the reviewed period and
   resolve missing settings, missing attendance, review flags and overlaps.
   An active employee without attendance is not automatically assumed absent.
3. Take the normal database backup. For an unlocked period, explicitly
   recalculate, inspect every component, then repeat audit. Confirm valid zero
   salaries with an explanation before posting. Existing locked periods stay
   frozen; do not unlock them to apply this hotfix.
4. For a locked-period discrepancy, collect its saved calculation, contemporary
   salary agreement/settings, attendance, manual adjustments, advances and
   payment evidence. The audit uses current settings; its delta is not proof of
   historical underpayment. Only after evidence establishes the amount should
   a separate salary-ledger adjustment be prepared, referencing the original
   payslip, reason and backup. Preserve original payments and never replace a
   stored balance directly. Record a zero-change conclusion when no error is
   established.
5. Re-run audit after recalculation and verify totals, advances, payments,
   remaining balances and export. Subsequent locks validate current inputs.

## Verification

Synthetic fixtures cover 480/481/568/599/600 minutes with recorded full units,
half units, multiple shifts, paid leave, holiday/rest multipliers, fixed/hourly
salary, missing data, overlapping/unreviewed shifts, standard-day overrides,
inline and popup adjustments, read-only views, source edits bypassing observers,
zero confirmation, idempotent posting, creation/recalculation/export parity and
locked-period protection. Existing standard-day, manual adjustment, manual
attendance and split-shift regression suites are also required.

On the private Docker backup, compare all sheets and exercise recalculation in
a transaction that is always rolled back. Hash payroll, attendance, employee,
cash-flow and payment/ledger tables before/after to verify preservation. Keep
production-derived reports outside Git; repository fixtures are synthetic.
