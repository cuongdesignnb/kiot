# Post-PR3 Payroll Production Readiness

## Context

This report records production readiness after PR #3 was squash-merged into `main`.

| Item | Value |
|---|---|
| Repository | `cuongdesignnb/kiot` |
| PR | `#3` |
| PR URL | https://github.com/cuongdesignnb/kiot/pull/3 |
| Merge method | Squash merge |
| Approved PR head SHA | `fac15ef14fec3f94174d4e58aff63f6598e3f606` |
| PR #3 squash merge commit | `9607d5a438797901341f5b2231aa3545760e063f` |
| Verified `origin/main` after PR #3 merge | `9607d5a438797901341f5b2231aa3545760e063f` |
| Previous base SHA before PR #3 | `031ab576262103675c45b878629196b1ea4b6e64` |

No production deploy, production migration, production Artisan command, production audit command, or production database operation was run while preparing this report.

## Scope Now Present In Main

PR #3 introduces the employee payroll debt ledger package into `main`, including:

- Employee salary ledger entries and balance calculation.
- Salary advance creation, approval, application, and reversal behavior.
- Paysheet payment and cancellation behavior.
- Payroll cash flow metadata and reconciliation support.
- Payroll permission and UI integration.
- Payroll UAT and regression coverage recorded in `docs/audit/INTEGRATE-PAYROLL-EMPLOYEE-DEBT-WITH-MAIN-AUDIT.md`.

This readiness report does not approve production data changes. It only states what remains required before production execution.

## Production Data Impact

Production rollout can affect these existing domains once migrations or write commands are approved and executed:

- `employees`
- `paysheets`
- `payslips`
- `paysheet_payments`
- `cash_flows`

PR #3 also adds payroll debt tables:

- `salary_advances`
- `salary_advance_applications`
- `employee_salary_ledger_entries`

The package is financially sensitive because it links payroll debt, salary payments, and cash flow reporting. Production rollout must therefore use an approved change window, verified backup, and a rehearsed rollback path.

## Evidence Already Collected Before Merge

The PR #3 integration audit recorded the following evidence before merge:

| Check | Result |
|---|---|
| Clone migration safety on isolated test database | PASS |
| Payroll feature tests | PASS |
| Debt, CashFlow, Orders/POS, Reports regressions | PASS |
| `npm run build` | PASS |
| `git diff --check` | PASS |
| UAT business flows via authenticated local HTTP session and DB readback | PASS |
| UI render check | PASS |

Known limitation from UAT: direct click automation for the `Chot luong` browser flow was limited by JavaScript confirm/CDP behavior. The business flow was still verified through authenticated local HTTP calls and database readback.

## Production Command Classification

The following classifications are based on the source currently merged into `main`.

| Command | Production classification | Notes |
|---|---|---|
| `payroll:migrate-salary-ledger` | Blocked until explicit approval | Default is dry-run/report. Writes require `--apply`. Opening balance requires `--legacy-balance=opening` and `--go-live-date`. Do not combine opening balance with document backfill. |
| `payroll:backfill-paid-payslip-ledger` | Blocked until explicit approval | Defaults to dry-run when no mode is passed. Writes require `--apply`. |
| `payroll:backfill-payment-cashflow` | Blocked until explicit approval | Defaults to dry-run when no mode is passed. Writes require `--apply`. |
| `payroll:rebuild-salary-balances` | High-risk, blocked until explicit approval | This command writes by default unless `--dry-run` is passed. It has no `--apply` guard. |
| `salary:recalculate` and other legacy write-capable payroll commands | Blocked until separate approval | Do not run as part of PR #3 rollout unless explicitly approved with exact target and command. |

No command in this table was run against production for this report.

## Go / No-Go Status

| Area | Status | Reason |
|---|---|---|
| Code merged to `main` | GO | PR #3 merged with approved head SHA. |
| Production deploy | NO-GO | Requires owner approval, deployment window, backup confirmation, and rollback owner. |
| Production migration | NO-GO | Requires production migration approval and backup/restore verification. |
| Production payroll write commands | NO-GO | Requires exact command, target, mode, scope, and owner approval. |
| Backfill existing payroll data | NO-GO | Requires separate BA/Owner data strategy approval. |
| Opening balance conversion | NO-GO | Requires go-live date, reconciliation sign-off, and separate approval. |

Current readiness conclusion: source is ready for production planning, but production execution remains blocked until the preflight checklist below is approved.

## Required Preflight Before Any Production Execution

Before production deploy, migration, or payroll write command:

1. Confirm target production host, project path, database, and current production commit.
2. Confirm production backup has completed successfully.
3. Restore that backup to a non-production database and verify it can be queried.
4. Run migration rehearsal on the restored database, not production.
5. Run rollback rehearsal or document the exact restore procedure and owner.
6. Confirm maintenance window and user communication.
7. Confirm payroll permission matrix for production users.
8. Confirm BA/Owner sign-off for whether PR #3 starts with no backfill, document backfill, cashflow metadata backfill, opening balance, or a narrower employee-scoped rollout.
9. Confirm exact commands to run, including every flag, target environment, and expected dry-run output.
10. Confirm monitoring checks after deploy: payroll ledger entries, paysheet payment/cancel, salary advances, cash flows, and payroll reports.

Do not run `migrate:fresh`, destructive schema operations, legacy data cleanup, or any command with `--apply` until explicitly approved.

## Proposed Production Sequence After Approval

This is a proposal only. It was not executed.

1. Put the application into the approved deployment window.
2. Verify backup and rollback owner.
3. Deploy the approved `main` commit.
4. Run approved production migrations only after backup confirmation.
5. Clear and rebuild Laravel caches as approved by the deployment runbook.
6. Run read-only or dry-run payroll checks first.
7. Run any write command only if separately approved with exact command and expected result.
8. Complete smoke checks for payroll, cash flow, and reporting.
9. Record production command output and final commit SHA in the deployment log.

## Rollback Notes

If no production migrations have been run, code rollback can return to the previous main commit:

```text
031ab576262103675c45b878629196b1ea4b6e64
```

If production migrations or payroll write commands have been run, rollback must follow the approved database restore or forward-fix plan. Do not rely on code rollback alone after data-shape or financial ledger changes.

## Open Risks

- Production data distribution is not yet verified in this post-merge step because production commands are intentionally not run.
- `payroll:rebuild-salary-balances` writes by default unless `--dry-run` is passed, so it must be treated as a write command.
- Existing production payroll legacy data strategy is still a BA/Owner decision: no backfill, scoped backfill, document backfill, cashflow metadata backfill, and opening balance are different rollout choices.
- Manual click-by-click UAT in a normal browser can still be requested before production if BA/Owner wants visual confirmation beyond the local authenticated API and DB readback evidence.

## Current Decision

Production execution remains blocked.

Allowed now:

- Review this readiness report.
- Decide production rollout strategy.
- Prepare an approved production runbook.

Not allowed without explicit approval:

- Production deploy.
- Production migration.
- Production Artisan command.
- Production database audit command.
- Any command with `--apply`.
- Opening balance conversion.
- Legacy payroll cleanup or conversion.
