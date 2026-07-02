# Integrate Production Payroll Hotfix With Debt Main

## 1. Source

- Repo: `cuongdesignnb/kiot`
- Base main SHA: `b286cf49fec336280f5555608d9f85dd727074ee`
- Production hotfix branch: `origin/prod-hotfix-attendance-payroll`
- Production hotfix SHA: `ce15714903aa5eb25646672acd74e67bda933420`
- Backup branch: `origin/backup/prod-before-debt-main-sync-20260613-1657`
- Backup tag: `prod-before-debt-main-sync-20260613-1657`
- Backup branch/tag SHA: `ce15714903aa5eb25646672acd74e67bda933420`
- Integration branch: `integrate/prod-payroll-hotfix-with-debt-main`
- Worktree: `D:\Kiot\kiotviet-clone.worktrees\integrate-prod-payroll-hotfix-with-debt-main`

The existing payroll worktree at `D:\Kiot\kiotviet-clone` contained unrelated uncommitted work and was not modified.

## 2. Discovery

- Merge-base: `1cec76c7d9aa6afd6240b8ee12571eb9e25b7d88`
- All expected remote branch and tag SHAs matched before integration.

Commits only on the production branch:

1. `8a590d0` fix(attendance): align timesheet colors with KiotViet UI
2. `18f1a12` fix(payroll): calculate work units for manual timekeeping records
3. `2701c5a` fix(payroll): prevent manual attendance from silently downgrading work units
4. `31d5a15` docs(audit): add manual attendance downgrade guard report
5. `994e337` fix(payroll): use standard work minutes for full-day attendance units
6. `bd1a488` docs(audit): document standard work minutes attendance fix
7. `ce15714` fix(payroll): calculate personal gross profit bonus from invoices

Relevant commits only on main:

- `b286cf4` post-merge staging debt logic UAT report
- `8d56564` Sapo debt/payment/partner merge integration
- `1822437` newer manual timekeeping audit report
- `1ce9056` manual timekeeping work-unit fix
- `ba3745e` attendance color fix
- Document-first debt timeline commits from `0254dd2` through `16f12ef`

Duplicate/equivalent commits detected by `git cherry` patch identity:

- `8a590d0` is equivalent to main commit `ba3745e`.
- `18f1a12` is equivalent to main commit `1ce9056`.

The production copy of `HOTFIX-PAYROLL-MANUAL-TIMEKEEPING-WORK-UNITS.md` is older than the version on main. The newer main report was intentionally preserved.

Production branch files reviewed:

- `app/Console/Commands/AuditManualWorkUnits.php`
- `app/Http/Controllers/TimekeepingRecordController.php`
- `app/Services/SalaryCalculationService.php`
- `app/Services/TimekeepingService.php`
- `resources/js/Pages/Employees/Attendance.vue`
- `tests/Feature/Payroll/ManualTimekeepingTest.php`
- Five payroll/attendance audit reports

## 3. Cherry-pick / integration

| Production commit | Integration action | Result / reason |
|---|---|---|
| `8a590d0` | Skipped | Patch-equivalent content already exists in `ba3745e` on main |
| `18f1a12` | Skipped | Patch-equivalent content already exists in `1ce9056` on main |
| `2701c5a` | Applied as `84729d6` | Manual attendance downgrade guard was missing from main |
| `31d5a15` | Applied as `b65daad` | Audit report was missing from main |
| `994e337` | Applied as `e94baf2` | Standard-work-minute full-day behavior was missing from main |
| `bd1a488` | Applied as `fdf6ed3` | Audit report was missing from main |
| `ce15714` | Applied as `bdb07a8` | Invoice-sourced personal gross profit bonus was missing from main |

Resulting functional diff against `origin/main` is limited to:

- `app/Http/Controllers/TimekeepingRecordController.php`
- `app/Services/SalaryCalculationService.php`
- `app/Services/TimekeepingService.php`
- `resources/js/Pages/Employees/Attendance.vue`
- `tests/Feature/Payroll/ManualTimekeepingTest.php`
- Three production payroll audit reports

## 4. Conflict handling

- Git conflicts: No.
- Empty cherry-picks: No; duplicate commits were identified before cherry-pick and not attempted.
- Semantic conflict review: payroll code files match the production hotfix after integration.
- Intentional difference: main keeps its newer manual-timekeeping audit report.
- Risk: payroll/attendance remains high-risk and still requires BA review before merge or deploy.

An initial test run loaded application classes from another worktree because `vendor` was linked to that worktree. Those failures were invalid environment results. Composer dependencies were installed directly in the integration worktree, after which payroll tests passed.

## 5. Payroll/attendance verification

Files checked:

- `app/Console/Commands/AuditManualWorkUnits.php`
- `app/Http/Controllers/TimekeepingRecordController.php`
- `app/Services/SalaryCalculationService.php`
- `app/Services/TimekeepingService.php`
- `resources/js/Pages/Employees/Attendance.vue`
- `tests/Feature/Payroll/ManualTimekeepingTest.php`

Logic preserved:

- Manual timekeeping calculates work units.
- A manual edit that lowers work units requires explicit confirmation.
- A full day uses `attendance_standard_work_minutes`.
- Device recalculation preserves correct full-day units.
- Manual override records are not overwritten by device recalculation.
- Personal gross profit bonus uses invoices and returns through `SellerResolver`.
- No old payroll or timekeeping records were recalculated.

Tests:

| Test group | Result |
|---|---|
| `ManualTimekeepingTest` | PASS, 21 tests, 95 assertions |
| `tests/Feature/Payroll` | PASS, 51 tests, 193 assertions |
| `tests/Feature/Attendance` | Folder does not exist |

Browser UAT on local integration source:

- URL: `http://127.0.0.1:8094`
- Database: local testing DB `sales_test_payroll_debt_integration`
- Attendance page rendered with the expected KiotViet status colors.
- Full-day manual attendance `08:30-16:30`: HTTP 200, 480 minutes, `work_units = 1.00`.
- Downgrade attempt `08:30-12:30`: HTTP 422, `requires_confirmation = true`, old unit 1.0 and new unit 0.5.
- Unexpected browser console errors: none.
- Evidence stored outside the repo at `D:\Kiot\integration-payroll-uat-evidence`.
- Gross-profit bonus UI was not exercised through a complete paysheet workflow; five invoice/return bonus regression cases passed in `ManualTimekeepingTest`.

## 6. Debt verification

The following were compared directly with `origin/main` and remained unchanged:

- Customer payment allocation and partner merge models
- Signed debt and customer payment services
- Order payment summary
- Partner merge and merged-source guard
- Debt-related controllers and frontend pages
- All three `2026_06_12` debt migrations

Tests:

| Test group | Result |
|---|---|
| `SapoDebtParityTest` | PASS, 12 tests, 41 assertions |
| `Orders` | PASS, 23 tests, 117 assertions |
| `POS` | PASS, 65 tests, 273 assertions |
| `CashFlow` | PASS, 5 tests, 12 assertions |
| `CashFlows` | PASS, 9 tests, 48 assertions |
| `Report` | PASS, 36 tests, 257 assertions |
| `Reports` | PASS, 73 tests, 371 assertions |
| Total required debt regression | PASS, 223 tests, 1119 assertions |

Debt six-case browser UAT was not repeated because the integration branch changes no debt file. The post-merge staging UAT at main debt commit `8d56564` passed all six cases, and this integration revalidated the same behavior through the complete 223-test regression.

## 7. Build

- `composer install --prefer-dist --optimize-autoloader --no-interaction`: PASS.
- `npm run build`: PASS.
- Vite: `5.4.21`.
- Modules transformed: `918`.
- Node: `20.15.1`.
- npm: `10.7.0`.
- `git diff --check`: PASS.

Non-blocking environment warnings:

- PHP CLI reports missing Oracle/Firebird extensions; MySQL testing is unaffected.
- Composer reports two existing PSR-4 warnings under `App\View\Components\layouts`.

## 8. Data safety

| Item | Result |
|---|---|
| New migration in integration branch | No |
| Backfill | No |
| Update/delete old production data | No |
| Recalculate old payroll | No |
| Production deploy | No |
| Production migration | No |
| Production DB touched | No |
| Local testing DB created/migrated | Yes, isolated `sales_test_payroll_debt_integration` |

No stock movement, costing, serial/IMEI, warranty, repair, customer debt, supplier debt or legacy order data was changed.

## 9. Remaining risks

1. Production payroll/attendance is high-risk; merge and deployment require BA review of this PR.
2. Browser UAT used an isolated fixture, not a recent production database clone.
3. The complete personal gross-profit paysheet UI flow was covered by automated invoice/return tests rather than manual paysheet browser QA.
4. Production still needs a separate deployment plan that preserves the existing production hotfix branch and backup refs.
5. Debt production migration and audit remain separate from this payroll integration and are not approved by this report.

## 10. Recommendation

- Ready for BA review: Yes.
- Ready to merge main: Yes after PR review and CI confirmation.
- Ready for production deploy: No.
- Need BA approval before production deploy/migration: Yes.
