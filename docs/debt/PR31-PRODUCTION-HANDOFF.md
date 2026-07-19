# PR #31 production handoff plan

This document is a plan for owner review. No production access, merge, deployment, SSH session, database mutation or migration was performed while preparing it.

```text
PR_NUMBER=31
PR_BRANCH=fix/kiotviet-partner-debt-ledger-contract
BASE_BRANCH=production-customer-group
BASE_PRODUCTION_SHA=c2df609571d35738423df313137de94c5108a8c5
VALIDATED_RUNTIME_SHA=6655de2b3286d750f0ea1f2288c5416b32f3af47
FINAL_PR_HEAD_SHA=pending-report-commit
MERGE_SHA=not-yet-created
ROLLBACK_CODE_TARGET=c2df609571d35738423df313137de94c5108a8c5
DEBT_OFFSET_WRITE_MODE=legacy
```

The exact report commit/final PR head is recorded in the Draft PR body and final agent gate output after this document is committed. No further commit should be created solely to make a report contain its own cryptographic identifier.

## Release prerequisites

- Owner explicitly approves PR #31 and separately authorizes merge and production deployment.
- Create and integrity-check a fresh production backup through the approved release process before deployment. The validation source backup is not a substitute for that production safety backup.
- No new dependency or dependency-lock change exists. The normal approved dependency installation step remains unchanged; this PR adds no package-specific install step.
- A production frontend build is required because tracked Vue sources changed. Use the existing approved release pipeline.
- Maintenance mode is not required by this PR because there is no migration or data repair. The release owner may still require it under the standard operational policy.
- Refresh application, route/config and opcode caches through the standard release pipeline after switching the release artifact.

```text
PRODUCTION_BACKUP_REQUIRED=yes
PRODUCTION_MIGRATION_REQUIRED=no
PRODUCTION_DATABASE_REPAIR_REQUIRED=no
PRODUCTION_ROLE_REPAIR_REQUIRED=no
PRODUCTION_FINANCIAL_REPAIR_REQUIRED=no
PRODUCTION_NEW_DEPENDENCY_INSTALL_REQUIRED=no
PRODUCTION_FRONTEND_BUILD_REQUIRED=yes
PRODUCTION_MAINTENANCE_MODE_REQUIRED=no
PRODUCTION_CACHE_REFRESH_REQUIRED=yes
DATABASE_ROLLBACK_REQUIRED=no
```

## Read-only smoke-test plan

After an owner-authorized deployment, the release operator should verify these application routes through the normal authenticated UI/API test account:

- customer exact search for `NCC177621742868` returns zero rows and contributes zero to customer totals;
- customer detail, debt and timeline URLs for `NCC177621742868` return 404;
- supplier exact search for `NCC177621742868` returns one row and its supplier timeline returns seven events, stored-versus-canonical difference zero and no warning;
- supplier detail, purchase, debt, export, payment and adjustment URLs return 404 for a known customer-only partner;
- both orientations for `NCC177950763826` have identical event identities, opposite deltas/running balances and no warning;
- the read-only parity audit reports 332/332 domain parity and only the two documented role-integrity review classifications.

No role or financial repair is part of the smoke test.

## Known review-only items

The following partners remain read-only owner review items. Deployment must not infer or mutate their roles, projections or documents:

- `NCC177466782297`: persisted supplier-only, evidence dual-role;
- `NCC177650418017`: persisted missing-role, evidence dual-role.

```text
ROLE_INTEGRITY_REVIEW_STATUS=REVIEW_REQUIRED
ROLE_INTEGRITY_REVIEW_ITEMS=2
```

## Rollback plan

If the application smoke test fails, switch the application release back to the pre-PR code target `c2df609571d35738423df313137de94c5108a8c5` using the approved release mechanism, refresh the normal caches and repeat the read-only smoke test. No database rollback, role rollback or financial rollback is expected because this PR creates no migration and authorizes no production data repair.
