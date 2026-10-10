# RR-ATTENDANCE-PUNCH-DIRECTION

## Scope and reproduced failure

Timekeeping only. Synthetic tests reproduce a single undirected end-of-shift
punch being saved as an entry, explicit device exits being treated as entries,
and two exits being paired as worked time. The same first/last assumption existed
on unscheduled rest/holiday days. No production records are included here.

## Root cause and changes

`TimekeepingService` paired punches by array position without consulting
`AttendanceLog.event_type`. Every unmatched final punch became an entry.

- Device `in`/`out` directions now take precedence. An exit without an entry
  remains an incomplete exit; repeated entries/exits cannot become a valid pair.
- Undirected complete pairs retain the existing sequence convention. For an
  isolated undirected punch, the nearest **absolute** shift boundary provides
  only a direction hint. Near shift end it is shown as an exit, not an entry.
  The interval remains reviewable and contributes **zero minutes, units and OT**.
- A missing side is never filled from shift start/end or another punch. Later
  arrival of a genuine earlier entry from device sync can complete the pair.
- Multiple slots share the resolver. Optional two-punch cross-slot inference
  cannot use an explicit exit as an entry or an explicit entry as an exit.
- Rest/holiday days share direction resolution and persist interval evidence
  for new/interval-based records. Existing rest-day OT/grace formulas are retained
  for complete pairs. Only complete intervals contribute worked minutes.
- Exact duplicates remain raw evidence and still require review. Unmatched
  IDs include incomplete intervals as well as duplicate evidence.
- The modal explains which actual time is missing and does not prefill a shift
  boundary when the operator edits a record needing review. Its first editable
  row is the first actual interval, not an aggregate spanning later intervals.

## Files

- `app/Services/TimekeepingService.php`: shared direction resolution, rest-day
  handling and read-only preview.
- `app/Console/Commands/AuditAttendancePunchDirections.php`: bounded-date audit.
- `resources/js/Pages/Employees/Attendance.vue`: missing-time explanation.
- Unit, missing-clock-in feature, existing split-shift and JS attendance tests.

## Safety and operational limits

Raw device logs and manually overridden attendance are preserved. This change
does not post/recalculate payroll, cancel payments or modify locked payslips.
The existing observer may mark an **unlocked** sheet for recalculation when its
attendance changes. Review attendance and then recalculate that sheet normally.
No migration, backfill, bulk attendance repair or automatic payroll lock occurs.

A directionless timestamp cannot prove whether somebody arrived late or left
early. The boundary hint is not evidence of actual working hours. An operator
must verify a missing time; do not fill a scheduled time merely to pass payroll.
Legacy attendance without interval rows/current punches keeps its old evidence.
The audit protects those rows and manual overrides instead of suggesting erasure.

## QA

- Before the fix: 9 of 13 direction tests failed, reproducing the bug.
- After the fix: 13 unit tests / 85 assertions PASS.
- Focused payroll/timekeeping regression: 122 tests / 1132 assertions PASS,
  including sync replay, delayed entry, overnight, split slots, duplicates,
  manual preservation, locked paid payroll preservation and read-only audit.
- Eleven JS attendance tests PASS; frontend production build PASS.
- Pint and PHP syntax checks are scoped to changed PHP files.
- Full payroll baseline: 197 tests / 1295 assertions, 40 pre-existing failures.
  Patched tree: 204 tests / 1357 assertions, exactly the same 40 failing test names,
  zero new failures/errors. Old lock/payment fixtures fail existing guards;
  this is **not** a fully green payroll suite or a fix for those old failures.
  Both runs used the same Docker DB/config and compiled frontend assets.

## Production workflow

Deploy code/frontend through `production-customer-group` after review. Use local
HTTPS vhost resolution when the server cannot loop back through its public DNS.
The deploy itself changes no business data. Then run the short, read-only audit:

```sh
php artisan attendance:audit-punch-directions --date=2030-05-06 --employee=QA-EMP-001 --no-ansi
```

Use the real date/code only in the private production terminal. The command has
no apply mode and prints before/preview for up to ten findings (`--details` prints
all). It covers scheduled records; unscheduled rest/holiday direction handling
is verified by feature tests, not represented as covered by this audit.

Existing incorrectly derived attendance is not silently rewritten by deployment.
After checking its device evidence, recalculate the appropriate attendance scope
using the existing workflow, or explicitly save the verified missing actual time.
Do not rewrite a paid historical payroll period or invent hours to clear a warning.
