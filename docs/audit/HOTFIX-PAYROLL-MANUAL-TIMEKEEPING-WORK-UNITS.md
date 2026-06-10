# Audit Report — HOTFIX Payroll Manual Timekeeping Work Units

Report on the backend calculation fix implemented to correctly compute work units, overtime, worked minutes, and holiday status for manually-entered timekeeping records.

---

## 1. Issue Scope & Root Cause
- **Issue**: Manual timekeeping records (punched via the attendance UI for shifts/leaves) were successfully stored, but they were missing the `work_units` (ngày công) value, keeping it at `0.0`. Consequently, when the monthly payslips were generated, these manual workdays contributed `0` to the employee's work unit sum, resulting in `0đ` base salary.
- **Root Cause**: In [TimekeepingRecordController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/TimekeepingRecordController.php), the `store()` method was calculating check-in/out differentials directly and saving them, but omitted the calculations for `work_units`, `is_holiday`, and `holiday_multiplier`.
- **Skip recalculations**: The range recalculator `TimekeepingService::recalculateForRange()` skips manual records where `manual_override = true` to preserve user edits, preventing these records from being corrected automatically.

---

## 2. Implemented Resolution

### Service Refactoring
- Refactored calculation logic out of [TimekeepingRecordController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/TimekeepingRecordController.php) into a reusable method on the [TimekeepingService.php](file:///d:/Kiot/kiotviet-clone/app/Services/TimekeepingService.php):
  `buildManualRecordAttributes(EmployeeWorkSchedule $schedule, string $attendanceType, ?string $checkInTime, ?string $checkOutTime, int $otMinutes = 0, ?string $notes = null): array`
- The service calculates all necessary parameters:
  - `worked_minutes`
  - `work_units` (fully day `1.0`, half-day `0.5`, paid leave `1.0`, unpaid leave `0.0`)
  - `late_minutes` / `early_minutes`
  - `is_holiday` / `holiday_multiplier` (supports weekends and holiday tables)

### Controller Refactoring
- Updated the controller's `store()` method to call `TimekeepingService::buildManualRecordAttributes()` and forward the calculated parameters to `TimekeepingRecord::updateOrCreate()`.

### Legacy Records Auditing Command
- Created a new console command: `php artisan timekeeping:audit-manual-work-units`
- Runs in **DRY-RUN** mode by default to prevent accidental database updates on production.
- Use `--apply` to update matches.

---

## 3. Files Changed
- [TimekeepingService.php](file:///d:/Kiot/kiotviet-clone/app/Services/TimekeepingService.php) (Added calculation method)
- [TimekeepingRecordController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/TimekeepingRecordController.php) (Replaced controller logic with service call)
- [AuditManualWorkUnits.php](file:///d:/Kiot/kiotviet-clone/app/Console/Commands/AuditManualWorkUnits.php) (Artisan audit command)
- [ManualTimekeepingTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Payroll/ManualTimekeepingTest.php) (Added feature tests)

- **Database Migrations**: None.
- **Database Backfill**: No default backfill is triggered during code deployment.

---

## 4. Test Verification Results
- Ran 6 manual timekeeping feature tests:
  ```powershell
  php artisan test --filter=ManualTimekeepingTest
  ```
  - **Status**: `PASS` (6 passed, 32 assertions)
- Ran all payroll and costing feature tests:
  ```powershell
  php artisan test --filter=Payroll
  ```
  - **Status**: `PASS` (45 passed, 254 assertions)
- Asset Build compilation:
  ```powershell
  npm run build
  ```
  - **Status**: `Success` (`✓ built in 7.27s`)

---

## 5. Instructions for Recovering Khuất Trung Hiếu's Data (May 2026)

### Option A — Dry-Run Audit (Verification Only)
To check the missing work units for May 2026 without making any database changes, run:
```powershell
php artisan timekeeping:audit-manual-work-units --from=2026-05-25 --to=2026-05-31 --employee="Khuất Trung Hiếu"
```

### Option B — Apply DB Update
If the dry-run output is correct, apply the updates directly using:
```powershell
php artisan timekeeping:audit-manual-work-units --from=2026-05-25 --to=2026-05-31 --employee="Khuất Trung Hiếu" --apply
```
After running this, go to the Paysheets UI for May 2026, and click **Tính lại** (Recalculate) to update his base salary and net earnings.
