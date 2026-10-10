# RR-PAYROLL-EFFECTIVE-BREAKDOWN

## Scope

Module: payroll allowance/bonus display and popup editing.

Changed files:

- `resources/js/utils/payslipBreakdown.js`
- `resources/js/Pages/Employees/Paysheets.vue`
- `resources/js/Pages/Employees/PaysheetEdit.vue`
- `resources/js/tests/payslipBreakdown.test.mjs`
- `tests/Feature/Payroll/PayslipEffectiveBreakdownContractTest.php`

## Reproduction and root cause

A synthetic payslip has two automatically calculated allowances. The operator
removes one using the adjustment popup. The saved allowance total correctly
uses the remaining adjustment, but the expanded row renders the original
`details.details.allowances` calculation snapshot. Removing all items also
repopulates current employee settings when reopening an empty popup.

Bonus details have the same source-selection problem. A separate `calculated
|| amount` expression can display a positive configured amount when the actual
calculated amount is zero. Inline saving also ignored the server's response,
leaving override metadata and settlement totals stale until reloading.

## Resolution

- Both screens use one pure resolver over the saved payslip, not current
  employee configuration. Direct overrides take precedence, then approved
  adjustment rows, then the stored automatic calculation snapshot.
- Empty overrides and zero totals stay empty. A calculated zero is respected.
- Displayed item sums must equal the saved component total. When legacy
  evidence cannot explain that total, show an explicitly labelled saved-total
  row, without attributing money to a removed/configured item.
- Popup reopening uses the same effective rows as the expanded display.
  Only an explicit reset restores automatic allowances/bonuses.
- Inline saving adopts the returned payslip, including override metadata and
  the remaining amount after payments and applied advances.
- Keep raw calculation snapshots as evidence. No salary formula, accounting
  entry, payment, database schema, or historical payroll data is changed.

## QA

- Before the fix: 7 of 9 initial JavaScript checks failed, reproducing stale
  breakdowns, zero fallback, and empty-popup repopulation.
- After the fix: all 12 new JavaScript checks pass, exercising the actual Vue
  row expressions, popup functions, and inline-save function.
- All 54 repository JavaScript checks pass.
- Six new Docker feature tests pass with 47 assertions: remove one/all,
  recalculate, explicit reset, direct override, paid/advance preservation,
  read-only locked snapshots, CSV and print amounts.
- Focused payroll/timekeeping regression: 128 tests, 1,179 assertions pass.
- Production Vite build, PHP syntax, Pint on the new test, and diff whitespace
  checks pass. This is not a claim that the entire legacy payroll suite passes.
- Fixtures and documentation are synthetic; no production financial records
  or employee identifiers are included.

## Residual limitations and release

This is a presentation correction, not a payroll data repair. Changing employee
salary settings still requires explicit recalculation of an unlocked sheet.
Locked sheets keep their saved historical values. A legacy row with no matching
breakdown stays labelled as a saved aggregate until reviewed explicitly.

No migration or backfill is needed. Production requires the frontend build;
source deployment alone without rebuilding assets is insufficient.
