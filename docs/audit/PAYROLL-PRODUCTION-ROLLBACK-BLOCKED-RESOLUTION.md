# PAYROLL PRODUCTION ROLLBACK BLOCKED RESOLUTION

## 1. Original Blocker

The production backup and restore process passed, but rehearsal stopped because the deployed code did not contain:

```text
payroll:migrate-salary-ledger
payroll:rebuild-salary-balances
payroll ledger schema/models/services
```

No production migration or opening-balance apply was executed.

## 2. Resolution In Repository

The repository now contains:

- Payroll ledger, advance, application, cache, payment-status, and CashFlow cancellation schema.
- Effective-ledger service with transaction and employee row locking.
- Idempotent opening-balance migration command.
- Read/rebuild command with dry-run support.
- Automated opening-balance and regression coverage.

The balance predicate remains:

```text
SUM(amount WHERE is_effective = true)
```

## 3. Rehearsal Outcome

The package passed an isolated rehearsal against the production copy available locally:

```text
Migration: PASS
Dry-run: PASS
Apply: PASS
Second apply/idempotency: PASS
Cache verification: PASS
Fake CashFlow/payment check: PASS
```

The available copy is dated June 14, 2026 and contains 543 CashFlow rows. It is not byte-identical to the declared June 15 production backup with 545 CashFlow rows.

## 4. Blocker Status

```text
Missing-code blocker: RESOLVED
Exact latest-restore rehearsal gate: OPEN
Production apply: BLOCKED
Production data changed: NO
```

The exact June 15 restore must run the same rehearsal before BA can consider production approval.
