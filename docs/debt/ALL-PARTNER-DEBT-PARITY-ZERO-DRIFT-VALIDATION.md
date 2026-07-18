# All-partner debt parity and zero-drift validation

**DEBT_PARITY_ZERO_DRIFT_STATUS=COMPLETE**

**TASK_CODE=PR30-FOUR-PROJECTION-FINAL-CLOSURE**

**PR=#30**

Validation was performed on branch
fix/all-partner-debt-parity-zero-drift. No production system was accessed or
changed, no deployment or merge was performed, and
DEBT_OFFSET_WRITE_MODE=legacy remained in effect. The debt-offset
workflow/outbox write path was not enabled.

## P0 four-projection final closure (authoritative, 2026-07-18)

This is the current authoritative result. Clean revalidation found four stale
stored projections. Expanded owner approval covered exactly partners 16, 72,
78 and 148. A newly generated guarded plan was applied on two independent
clones restored from the immutable backup. Both clones reached 332/332, the
second apply changed zero rows on each clone, and production remained
untouched. All older `BLOCKED` results below are retained only as investigation
history.

```text
PR30_CLOSURE_STATUS=READY
TASK_CODE=PR30-FOUR-PROJECTION-FINAL-CLOSURE
PR_NUMBER=30
PR_DRAFT=no
PR_MERGED=no
PREVIOUS_HEAD_SHA=71e251c66bec130688579b4703725f95488b2286
FINAL_HEAD_SHA=the Git commit carrying this report; see PR metadata and final handoff

APPROVED_PARTNER_IDS=16,72,78,148
PLANNED_REPAIR_ROWS=4
CLONE_1_FIRST_APPLY_ROWS_CHANGED=4
CLONE_2_FIRST_APPLY_ROWS_CHANGED=4
CLONE_1_SECOND_APPLY_ROWS_CHANGED=0
CLONE_2_SECOND_APPLY_ROWS_CHANGED=0
CLONE_1_PARITY=332/332
CLONE_2_PARITY=332/332

MATERIAL_DRIFT=0
INSUFFICIENT_EVIDENCE=0
TECHNICAL_WARNINGS=0
DUPLICATE_WARNINGS=0
RAW_TIMELINE_MISMATCHES=0
AUDIT_ERRORS=0
UNKNOWN_ROOT_CAUSES=0

PR30_DEBT_IMPLEMENTATION_STATUS=COMPLETE
CLONE_DEBT_REPAIR_VALIDATION=PASS
PRODUCTION_DEBT_REPAIR_STATUS=NOT_APPLIED
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

### Immutable source and independent-clone gate

- Backup: `backups/debt-parity-latest-20260718-151155/database.sql.zip`.
- Backup SHA-256:
  `026c7f510a5202eb7cf205150736c4fb2c9f0fc105a6ee4a274aa81cb91d2784`.
- Engine and population: MariaDB 10.11.10, 332 customers.
- Database fingerprint:
  `3fadb19546b017496664b0f8e9bb4c808ebf2e280b3857460c27b3cea17ccecc`.
- Clean schema hash on both clones:
  `2b8f83c5fb8b7f753f007c83df6acbb559e6b4f80725c0ddc145f02d4e42dbd4`.
- Clean table-count hash on both clones:
  `cb2dddd15b1c311dd37abaf832ff84815ac1d882729828f73bbcb26417b8893e`.
- Clean table-checksum hash on both clones:
  `fabdd228273df4a78b6ee6ea2ac1323695012a33fa910b4c404c5f2cf3d82cc5`.
- Clean projection-row hash on both clones:
  `14745ece081970bb87928f2bcbe0bd7de58e1ed5cace2e917e256e1fb7ee9baf`.
- Reconstructed historical-baseline projection hash on both clones:
  `f96f1b4827e6d040dfb321db65acd190f3ff378dbfe716b4458a21af110eb8c4`.

Clone 2 was restored directly from the immutable SQL, not copied from clone 1.
Before repair, both current-head audits scanned 332 partners and produced 328
`OK` rows plus exactly four material drifts, with no insufficient evidence,
technical warning, duplicate warning, audit error, blocking flag, manual-review
row, or unknown root cause.

### Guarded plans and approved targets

Clone 1 authoritative plan metadata:

```text
NEW_AUDIT_SHA256=52d1ba82b0be7b63023473242a51231154b35d8871ba6f16363fb8ad20db521f
NEW_DATABASE_FINGERPRINT=3fadb19546b017496664b0f8e9bb4c808ebf2e280b3857460c27b3cea17ccecc
NEW_PLAN_HASH=92e16d99fa0d80876cdc9f0bf15b8de897108b72826669250c95ae1230e753ca
NEW_APPROVAL_HASH=a92001df7111752f1a2eb4ffc4514fbcbe33668ad054d0001d3d7ac3232c1707
PLAN_FILE_SHA256=fdaef2a4ae38f612696d460de103502d6dd89a6c79fd255f1e8ccdb7c3adf773
```

Clone 2 independently regenerated its audit and plan. Its source audit SHA is
`d92ae847b0d503934ce1b94d28ee16f00f7ea16420f06f46a3a79a4dbca4ca45`,
plan hash is
`a5aed0a6f34244e2d1551a32d307533cb60e688a26bfec50a8a61f3e4e85399f`,
and approval hash is
`d2acff25ef8cc7de06210a55bc823576cf337c4bde5a4ee2cc21bc02962b750a`.
The expected audit-generation timestamp makes the raw audit and guarded hashes
different. After removing that volatile provenance, the four repair rows have
the same normalized hash on both clones:
`b60f3d3f7040fad7d61dee0b26dc830d71c79940c3eac741f5addc7412775be1`.

| Partner | Role | Customer before | Customer target | Supplier before/target |
| --- | --- | ---: | ---: | ---: |
| 16 `NCC177425584137` | dual-role | 17,200,000 | 8,600,000 | 10,640,000 |
| 72 `KH177561736414` | customer-only | 4,940,000 | 2,370,000 | 0 |
| 78 `KH177598487429` | dual-role | -14,000,000 | 0 | 0 |
| 148 `KH177794725633` | customer-only | -1,010,000 | 0 | 0 |

On each clone, dry-run selected four repairs, changed zero rows, and left the
historical-baseline projection hash unchanged. Apply used the exact four-code
allowlist and the approval hash belonging to that clone's regenerated plan.

### Evidence, transaction, replay, and final values

The financial evidence dossiers contain persisted business documents, active
and cancelled/soft-deleted CashFlows, customer debt mirrors, supplier debt
transactions, offset/reversal evidence, canonical and reference-only event
lists, arithmetic, stored projections, and raw differences.

- Partner 16 includes the persisted cancellation reversal and reduces customer
  receivable to 8,600,000; supplier payable remains 10,640,000.
- Partner 72 includes the persisted cancellation reversal omitted by the stale
  projection and reduces receivable to 2,370,000.
- Partner 78 has the exactly-once stream `+15,000,000` invoice,
  `-1,000,000` real receipt, `-14,000,000` customer and supplier DebtOffset
  effects, and `+14,000,000` purchase. Offset mirrors are reference-only, so
  both canonical sides are zero.
- Partner 148's payment mirror is reference-only because its source CashFlow is
  cancelled and soft-deleted; no replacement payment or adjustment was made.

Clone 1 operation `eb3ee1ca-9bf0-47c1-9922-34374d5cf7e1` and clone 2 operation
`22a7bb40-d7f9-465e-a977-9e9a47bed25b` each committed one transaction with four
participants and four ActivityLog rows. Domain table counts were unchanged:
401 invoices, 442 purchases, 795 CashFlows, 100 customer-debt rows, 236
supplier-debt transactions, one DebtOffset, 32 returns, and nine purchase
returns. No synthetic customer, invoice, purchase, CashFlow, ledger adjustment,
offset, or opening balance was created.

```text
ONE_DATABASE_TRANSACTION=yes
PARTNER_ROWS_LOCKED=yes
BEFORE_SNAPSHOTS=yes
AFTER_SNAPSHOTS=yes
OPERATION_PARTICIPANTS=4
ACTIVITY_LOG=yes
IDEMPOTENCY_KEY=yes
```

| Partner | Customer stored/canonical after | Supplier stored/canonical after | Difference | Warning |
| --- | ---: | ---: | ---: | --- |
| 16 | 8,600,000 / 8,600,000 | 10,640,000 / 10,640,000 | 0 | no |
| 72 | 2,370,000 / 2,370,000 | 0 / 0 | 0 | no |
| 78 | 0 / 0 | 0 / 0 | 0 | no |
| 148 | 0 / 0 | 0 / 0 | 0 | no |

The same plan replayed the original operation UUID on each clone with
`ROWS_CHANGED=0`. Participant, ActivityLog, domain-table, table-count, and
projection hashes were unchanged by replay.

### Deterministic equivalence and orphan 55

```text
CLONE_1_FINAL_HASH=71b11d63a511a034e2bcff0ab89322c2a6d813fe2855c2cd223e16266eabbddb
CLONE_2_FINAL_HASH=71b11d63a511a034e2bcff0ab89322c2a6d813fe2855c2cd223e16266eabbddb
NORMALIZED_FINAL_AUDIT_HASH=cc10e52a7c15981e33513f5a3fc1cfd761c164771fc5ea27fa689f81561a59be
DETERMINISTIC_REPLAY=PASS

PARTNER_55_STATUS=legacy_orphan_excluded
PARTNER_55_AFFECTS_CANONICAL_BALANCE=no
PARTNER_55_SYNTHETIC_CUSTOMER_CREATED=no
```

The legacy orphan evidence is still only partner 55 from `cash_flows`, remains
reference-only, and has the same normalized hash on both clones:
`257da28d489ae33da4dbe8557db9184e1a8c999c8324e0b6fb867a8d3f115b6c`.

### Final regression and lifecycle gates

| Gate | Result |
| --- | --- |
| MariaDB 10.11.10 acceptance | 232 tests, 1,187 assertions, PASS |
| MySQL 8.0.44 acceptance | 232 tests, 1,187 assertions, PASS |
| Repair plan/apply/replay | Included on both engines, PASS |
| DebtOffset exactly-once and failure injection | Included on both engines, PASS |
| Cancellation/reversal | Included on both engines, PASS |
| Cancelled/soft-deleted CashFlow exclusion | Included on both engines plus real-data dossier, PASS |
| All changed PHP lint | 52 files, PASS |
| All changed PHP Pint | 52 files, PASS |
| Frontend build | Vite 5.4.21, 925 modules, PASS |
| Git diff check | PASS |
| Secret scan | PASS |

The acceptance sets report zero new error, failure, or style error. Broader
repository runs retained pre-existing or deliberately disabled workflow-route,
old virtual-opening, worktree-worker, and unrelated UI assumptions; the
targeted cross-engine set above covers the current reducer, audit, repair,
replay, cancellation, offset exactly-once, mutation rollback, and CashFlow
invalidation contracts. `DEBT_OFFSET_WRITE_MODE=legacy` remained unchanged.

Both independent clone containers and the MySQL 8 regression container were
stopped immediately after their DB-dependent phases. Volumes and ignored audit
artifacts remain on disk for repeatable review. No merge or deployment was
performed.

```text
PARTNER_16_STORED_AFTER=8600000
PARTNER_16_CANONICAL_AFTER=8600000
PARTNER_16_DIFFERENCE_AFTER=0

PARTNER_72_STORED_AFTER=2370000
PARTNER_72_CANONICAL_AFTER=2370000
PARTNER_72_DIFFERENCE_AFTER=0
PARTNER_72_WARNING_AFTER=no

PARTNER_78_CUSTOMER_STORED_AFTER=0
PARTNER_78_CUSTOMER_CANONICAL_AFTER=0
PARTNER_78_SUPPLIER_STORED_AFTER=0
PARTNER_78_SUPPLIER_CANONICAL_AFTER=0
PARTNER_78_DIFFERENCE_AFTER=0

PARTNER_148_STORED_AFTER=0
PARTNER_148_CANONICAL_AFTER=0
PARTNER_148_DIFFERENCE_AFTER=0

MARIADB_TESTS=PASS
MYSQL_TESTS=PASS
FRONTEND_BUILD=PASS
CHANGED_FILE_PINT=PASS
PHP_LINT=PASS
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
WORKTREE_CLEAN=yes-after-publication-commit
```

## P0 final-unblock clean revalidation (superseded history, 2026-07-18)

This was the authoritative result for the earlier final-unblock request. It is
now superseded by the approved four-projection closure above and is retained as
investigation history. Revalidation started from the requested branch input head
`181140387ead7b5d9e63c907cff8450635a73ff6` and the immutable backup, not from
the previously exercised clone volume.

```text
PR30_CLOSURE_STATUS=BLOCKED
PR_NUMBER=30
PR_DRAFT=yes
PR_READY=no
PR_MERGED=no
REVALIDATION_INPUT_HEAD=181140387ead7b5d9e63c907cff8450635a73ff6
CLONE_REPAIR_APPLIED=no
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

### Clean-source provenance and invalid prior premise

- Immutable archive:
  `backups/debt-parity-latest-20260718-151155/database.sql.zip`.
- Archive SHA-256:
  `026c7f510a5202eb7cf205150736c4fb2c9f0fc105a6ee4a274aa81cb91d2784`.
- Extracted SQL SHA-256:
  `71719eee442fbd5cbc75c7caea71732b11ae21bbf4643073fa2db1f8fb9d1942`.
- Engine: MariaDB 10.11.10; restored population: 332 customers.
- Historical accepted projection baseline fingerprint:
  `3fadb19546b017496664b0f8e9bb4c808ebf2e280b3857460c27b3cea17ccecc`.
- Historical accepted projection-row hash:
  `f96f1b4827e6d040dfb321db65acd190f3ff378dbfe716b4458a21af110eb8c4`.

The immutable backup itself stores partner 78 at zero on both sides. The
`-14,000,000` value exists only after reconstructing the previous 17-row
accepted projection baseline on a clone. The earlier clone that produced the
331/332 closure artifact had also been mutated by regression suites: its
invoice, cash-flow, return, purchase, and related business-table checksums do
not equal a clean restore. It is therefore not a valid clean-backup oracle.

### Canonical corrections found during clean revalidation

Two deterministic reducer/audit issues were fixed in code:

- cancellation and reversal events no longer collide with an invoice fallback
  as if they were a second real payment or refund;
- a `CustomerDebt` payment mirror whose same-partner, customer-domain CashFlow
  is cancelled or soft-deleted is reference-only and contributes zero
  canonical delta. Supplier-domain code collisions remain countable customer
  evidence. Invalidated CashFlows are loaded once per partner to avoid an N+1
  query.

After those corrections, the clean historical baseline scans all 332 partners
with no duplicate warning, technical warning, insufficient evidence, unknown
root cause, or audit error:

| Metric | Result |
| --- | ---: |
| Eligible/scanned | 332/332 |
| Matched | 328 |
| Material stored-projection drift | 4 |
| Insufficient evidence | 0 |
| Technical warnings | 0 |
| Duplicate real/fallback warnings | 0 |
| Audit errors | 0 |
| Unknown root causes | 0 |

The regenerated plan has 328 `NO_ACTION` rows and four
`UPDATE_STORED_PROJECTION` rows:

| Partner | Role | Customer projection before | Corrected canonical target | Supplier before/target |
| --- | --- | ---: | ---: | ---: |
| 16 `NCC177425584137` | dual-role | 17,200,000 | 8,600,000 | 10,640,000 |
| 72 `KH177561736414` | customer-only | 4,940,000 | 2,370,000 | 0 |
| 78 `KH177598487429` | dual-role | -14,000,000 | 0 | 0 |
| 148 `KH177794725633` | customer-only | -1,010,000 | 0 | 0 |

Partners 16 and 72 retain persisted cancellation reversals that the previous
accepted targets omitted. Partner 148 retains a payment ledger mirror for a
cancelled, soft-deleted CashFlow; counting that mirror was a reducer defect,
not evidence for a real debt. These are persisted-evidence corrections, not
new adjustments or synthetic openings.

### Partner 78 exact evidence

Partner 78 is persisted as dual-role (`is_customer=1`, `is_supplier=1`), not
customer-only. Its exactly-once event stream is:

- customer invoice `HD177601111594`: `+15,000,000` receivable;
- real receipt `PT26041317201042`: `-1,000,000` receivable;
- DebtOffset `CB000001`: `-14,000,000` receivable and `-14,000,000` payable;
- purchase `PN20260412232555`: `+14,000,000` payable.

The offset CashFlow and supplier-ledger mirrors are reference-only. There are
zero virtual events. Therefore customer canonical, supplier canonical, net,
and display alignment are all exactly zero.

### Regenerated plan, dry-run, and fail-closed decision

- Source audit SHA-256:
  `6c580b6646cd0281451b3562a597b4ea21b0b068f1411c52936cc30ab544eba9`.
- Database fingerprint:
  `3fadb19546b017496664b0f8e9bb4c808ebf2e280b3857460c27b3cea17ccecc`.
- Plan hash:
  `e58958d56113751b504ab8131d81afa006101266e370f6902e4a1fe1482afc81`.
- Approval hash:
  `edda04882e03bf17ddccf584e0d0b6d55d17c939e0e0135c6eb477338857e29c`.
- Plan file SHA-256:
  `180948826b616a794e4f918f78e0c6ac2cd18197c30ebfad5c43c0d571ed428e`.
- Dry-run: 4 repair rows, 0 manual-review rows, 0 blocking flags, and
  `ROWS_CHANGED=0`.
- Projection hash before and after dry-run:
  `f96f1b4827e6d040dfb321db65acd190f3ff378dbfe716b4458a21af110eb8c4`.

The final-unblock authorization permits only partner 78 and explicitly says
that any other accepted target change must block closure. Because the
regenerated plan also changes partners 16, 72, and 148, the plan was not
applied. Clone 2, replay, 332/332 post-apply, and Ready-for-review transition
were intentionally not fabricated or attempted after this gate failed.

### Regression and lifecycle gates for this continuation

| Gate | Result |
| --- | --- |
| MariaDB 10.11.10 targeted reducer/audit suites | 53 tests, 197 assertions, PASS |
| MySQL 8.0.44 targeted reducer/audit suites | 53 tests, 197 assertions, PASS |
| Changed-file PHP lint | 4 files, PASS |
| Changed-file Pint | 4 files, PASS |
| Frontend build | Vite 5.4.21, 923 modules, PASS |
| Git diff check | PASS |

Two discarded MySQL attempts failed database authentication before executing
any assertion; the valid run used the container credential only in a process
variable and passed. Local PHP's unavailable OCI/Firebird extension warnings
remain unrelated to these MySQL/MariaDB gates.

All task containers were stopped immediately after their DB-dependent phase.
At this handoff `docker ps` returns no running container. Task volumes and
ignored audit artifacts remain stopped/on disk for repeatable review.

```text
FINAL_UNBLOCK_POPULATION=332/332
FINAL_UNBLOCK_MATCHED=328
FINAL_UNBLOCK_MISMATCHES=4
FINAL_UNBLOCK_DUPLICATE_WARNINGS=0
FINAL_UNBLOCK_UNKNOWN_ROOT_CAUSES=0
FINAL_UNBLOCK_PLAN_DRY_RUN=PASS
FINAL_UNBLOCK_PLAN_APPLIED=no
FINAL_UNBLOCK_SECOND_APPLY=NOT_RUN
FINAL_UNBLOCK_CLONE_2=NOT_CREATED_AFTER_FAIL_CLOSED_GATE
FINAL_UNBLOCK_OTHER_ACCEPTED_TARGETS_CHANGED=3
FINAL_UNBLOCK_STATUS=BLOCKED
PR_READY=no
DOCKER_RUNNING_CONTAINERS=0
```

## Prior PR #30 closure differential (superseded, 2026-07-18)

This section is retained as investigation history. It is superseded by the
clean-source revalidation above and is not the current closure result.

```text
PR30_CLOSURE_STATUS=BLOCKED
PR_NUMBER=30
PR_DRAFT=yes
PR_MERGED=no
BASE_SHA=0c35b3498437dd163f38625fee6df9b6342027ab
PREVIOUS_PR_HEAD=6a640d48fc743077d649f4525fff019714a502d7

PR30_DEBT_IMPLEMENTATION_STATUS=BLOCKED
CLONE_DEBT_REPAIR_VALIDATION=FAIL
PRODUCTION_DEBT_REPAIR_STATUS=NOT_APPLIED
```

### Full PHPUnit base-versus-PR comparison

The base and previous PR head were run from clean detached worktrees against
separate, freshly migrated MySQL 8.0.44 databases with the same PHP 8.2.29,
PHPUnit 10.5.63, dependency tree, environment, memory limit, and command.
Console counters are authoritative; PHPUnit's nested JUnit files contain only
787 base and 802 PR testcase nodes even though the console completed 1,668 and
1,683 tests respectively.

| Counter | Base | Previous PR head |
| --- | ---: | ---: |
| Tests | 1,668 | 1,683 |
| Assertions | 6,993 | 8,097 |
| Errors | 132 | 52 |
| Failures | 203 | 87 |
| Skipped | 18 | 18 |
| Risky | 1 | 1 |

The exact method-level JUnit differential contained 212 base problem IDs and
95 PR problem IDs:

| Category | IDs | Action |
| --- | ---: | --- |
| PRE_EXISTING_BASELINE_FAILURE | 83 | Do not fix outside debt scope |
| ORDER_DEPENDENT_SCHEMA_MUTATION | 12 | All passed independently on fresh schema |
| NEW_PR30_REGRESSION remaining | 0 | Two independently proven regressions were fixed |
| Base-only problem IDs | 129 | PR did not reproduce them |

For the 83 common method-level problem IDs, the PR JUnit types are 39 errors
and 44 failures. The 12 PR-only IDs are 5 errors and 7 failures; all 12 passed
when run independently, so none is classified as a remaining PR regression.
Nested suite aggregation explains why those method-level counts do not equal
the console error/failure totals.

The 12 order-dependent IDs are:

1. `ApplyDebtFixPlanCommandTest::test_guarded_orphan_classification_is_audited_and_replays_without_duplicates`
2. `SapoDebtParityTest::test_dual_role_net_zero_remains_zero_with_reference_only_marker`
3. `SapoDebtParityTest::test_merge_marker_is_zero_and_does_not_double_customer_debt`
4. `CustomerDualRoleListDebtColumnTest::test_customer_list_uses_customer_oriented_net_for_dual_role_partner`
5. `CustomerPaymentDiscountTest::test_auto_debt_payment_without_receivable_invoice_keeps_cash_unallocated`
6. `CustomerPaymentDiscountTest::test_create_discount_with_invoice_allocation`
7. `CustomerPaymentDiscountTest::test_debt_payment_does_not_overcollect_discounted_amounts`
8. `DualRoleDebtAdjustmentKiotStyleTest::test_customer_screen_adjustment_sets_dual_role_display_debt_not_raw_receivable`
9. `DualRoleDebtAdjustmentKiotStyleTest::test_supplier_screen_adjustment_sets_dual_role_display_debt_not_raw_payable`
10. `PartnerDebtMutationCoordinatorTest::test_fault_before_commit_rolls_back_projection_and_operation_log`
11. `PartnerDebtMutationCoordinatorTest::test_same_key_and_payload_replays_without_a_second_write`
12. `PartnerDebtMutationCoordinatorTest::test_same_key_with_a_different_payload_is_rejected`

Every one of the 77 debt-related problem IDs from the full PR run was rerun
independently. The 54 non-migration IDs passed in isolated PHPUnit processes;
the 23 migration tests pass after the closure corrections. The remaining 18
full-run problem IDs are outside debt scope and either reproduce on the base
or are consequences of the repository's shared, order-mutated schema.

Two PR regressions were proven with fresh targeted runs and fixed:

- legacy DebtOffset CashFlow/document evidence and cancellation ledger mirrors
  were counted more than once, making the new invariant coordinator reject a
  valid legacy offset write;
- a persisted sales return was made reference-only merely because an unrelated
  customer ledger row existed, dropping its `-2,000,000` running-balance delta.

Two outdated test assumptions were updated: the legacy offset fixture now has
persisted invoice/purchase evidence, and MariaDB's physical `LONGTEXT` JSON
representation is detected by server version rather than PDO driver name.
The final targeted result is therefore:

```text
PRE_EXISTING_PHPUNIT_ERRORS=39
PRE_EXISTING_PHPUNIT_FAILURES=44
NEW_PR30_ERRORS=0
NEW_PR30_FAILURES=0
OUTDATED_DEBT_TESTS_UPDATED=2
ORDER_DEPENDENT_TESTS=12
```

### Repository-wide Pint differential

| Counter | Base | Previous PR head |
| --- | ---: | ---: |
| Files checked | 791 | 797 |
| Pint errors | 2 | 2 |
| Files with style violations | 549 | 513 |

Normalized file comparison found zero newly failing PR files and 36 files
that no longer fail. All 51 PHP files changed from the base through the
closure head pass Pint and PHP lint.

```text
NEW_PR30_STYLE_ERRORS=0
CHANGED_FILE_PINT=PASS
PHP_LINT=PASS
```

### Closure regression gates

| Engine/gate | Result |
| --- | --- |
| MySQL 8.0.44 PR-changed tests | 134 tests, 725 assertions, PASS |
| MySQL 8.0.44 debt migration tests | 23 tests, 710 assertions, PASS |
| MariaDB 10.11.10 PR-changed tests | 134 tests, 725 assertions, PASS |
| MariaDB 10.11.10 debt migration tests | 23 tests, 698 assertions, PASS |
| Frontend build | Vite 5.4.21, 925 modules, PASS |
| Changed-file Pint | 51 files, PASS |
| PHP lint | PASS |
| Git diff check | PASS |
| Added-line secret scan | PASS |

The first MariaDB migration attempt used MySQL's
`utf8mb4_0900_ai_ci` collation and failed before creating schema. Rerunning
with `utf8mb4_unicode_ci` passed; this is classified `ENVIRONMENT_FAILURE`,
not a PR regression. The first discarded baseline run also used an empty
password environment value that PowerShell removed, producing 1,518 database
authentication errors; the valid base/PR comparison uses a real null password
contract and excludes that environment failure.

### Current clone blocker

The required post-change read-only clone-1 audit proves that the corrected
DebtOffset exactly-once reducer changes one repaired projection:

| Metric | Result |
| --- | ---: |
| Eligible/scanned | 332/332 |
| Matched | 331 |
| Material drift | 1 |
| Insufficient evidence | 0 |
| Technical warnings | 0 |
| Audit errors | 0 |
| Unknown root causes | 0 |

Partner 78 (`KH177598487429`) now has canonical customer receivable `0`,
stored customer projection `-14,000,000`, supplier payable `0`, and a raw
difference of `14,000,000`. The previous 332/332 evidence was produced before
the closure fix removed the DebtOffset document/CashFlow duplicate. Reverting
that fix would restore the accepted clone count but would reintroduce the
independently proven write-path regression. Updating the stored projection
would violate the closure instruction not to change the 17 accepted repaired
rows, so no clone data was changed and no repair was run.

```text
DEBT_PARTNER_PARITY=331/332
DEBT_MISMATCHES=1
UNKNOWN_ROOT_CAUSES=0
MARIADB_DEBT_SUITE=PASS
MYSQL8_DEBT_SUITE=PASS
HTTP_DISPLAY_CONTRACT=PASS
BROWSER_SCREENSHOT=NOT_AVAILABLE_DUE_TO_TOOL_POLICY
BROWSER_SCREENSHOT_BLOCKER=no
FRONTEND_BUILD=PASS
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

PR #30 must remain Draft. Clearing the blocker requires explicit authority to
regenerate a repair plan for the corrected canonical fingerprint and update
the affected clone projection; it must not be hidden by restoring duplicate
counting. Production remains untouched and requires the separate reviewed,
merged, deployed, freshly backed-up, fingerprint-approved process.

## Historical accepted clone validation before closure fixes

At previous PR head `6a640d48fc743077d649f4525fff019714a502d7`, the latest
supplied backup reached debt parity on two independent MariaDB restores:

| Acceptance metric | Clone 1 | Clone 2 |
| --- | ---: | ---: |
| Scanned partners | 332 | 332 |
| Matched invariants | 332 | 332 |
| Stored/canonical drift | 0 | 0 |
| Insufficient evidence | 0 | 0 |
| Technical warnings | 0 | 0 |
| Audit errors | 0 | 0 |
| Raw timeline mismatches | 0 | 0 |
| Unknown root causes | 0 | 0 |
| Unscannable source rows | 0 | 0 |

The final status is still BLOCKED, not COMPLETE, because the repository-wide
PHPUnit suite and repository-wide Pint gate are not green. The HTTP contracts
passed, but the in-app browser policy blocked loopback navigation, so no
browser screenshot was captured.

## Immutable backup and source gate

- User source: D:\Kiot\kiotviet-clone\kiot.sql.zip.
- Immutable task copy:
  backups/debt-parity-latest-20260718-151155/database.sql.zip.
- Archive SHA-256:
  026c7f510a5202eb7cf205150736c4fb2c9f0fc105a6ee4a274aa81cb91d2784.
- Archive size: 544,344 bytes; integrity check: PASS.
- SQL member: kiot_db_2026-07-18_15-11-55_mysql_data_hszCf.sql,
  4,580,611 bytes.
- Source engine: MariaDB 10.11.10; database: kiot_db.
- Capture timestamp: 2026-07-18 15:11:55.
- Source file modified at: 2026-07-18T15:11:58.1994539+07:00.
- Latest business timestamp: 2026-07-14 10:13:43.
- Latest customer created at: 2026-07-13 03:10:15.
- Latest invoice business time: 2026-07-13 03:11:00.
- Latest purchase business time: 2026-07-13 15:47:00.
- Latest cash-flow time: 2026-07-14 10:13:43.
- Latest customer-debt time: 2026-07-14 10:13:43.
- Latest supplier-debt-transaction time: 2026-07-13 08:41:00.
- Latest debt-offset time: 2026-04-12 16:26:33.
- Schema hash:
  8ea85b81363386f8899ed8cdd8b8a97532fd69f38eed3156a039436ebc25ae49.
- Source database fingerprint:
  7f3b66af15dd20627bb7790c8807aaa3c02d28e6d73f253198d8a177944cff28.
- Both clone pre-repair fingerprints equal the source fingerprint.

Population reconciliation passed:

| Metric | Count |
| --- | ---: |
| Customers | 332 |
| Full financial-source union | 333 |
| Scannable customer partners | 332 |
| Financial-history partners | 332 |
| Unexplained missing customers | 0 |
| Unscannable rows | 0 |
| Classified legacy orphan references | 1 |

Role population:

| Role | Count |
| --- | ---: |
| Customer-only | 265 |
| Supplier-only | 50 |
| Dual-role | 17 |

Source ID 55 has no customer row and has three legacy cash-flow references.
It is deterministically classified as a legacy orphan and recorded by the
guarded repair operation; no synthetic customer, opening balance, adjustment,
or document was created.

## Canonical engine and audit behavior

The canonical debt contract now uses document evidence consistently across
audit, invariants, repair planning, timeline, and UI summaries. The task
changes include:

- one role resolver for customer-only, supplier-only, and dual-role partners;
- explicit customer_receivable, supplier_payable, net_balance,
  supplier_oriented_net, display_contract, raw_timeline_final,
  stored_projection, difference, and has_mismatch fields;
- target-scoped cash-flow matching, so similarly named supplier payments
  cannot be attributed to another partner;
- CustomerDebt mirrors of invoices, receipts, returns, and offsets treated as
  evidence instead of a second financial effect;
- technical and reference-only rows excluded from both canonical and display
  running balances;
- no virtual-opening or running-balance shift may hide a raw mismatch;
- deterministic root-cause taxonomy and full source-union population checks;
- guarded repair-plan application with source/report/database fingerprints,
  approval hash, transactional locking, operation participants, and replay
  detection.

Before deterministic legacy normalization, the audit had 49 non-OK rows. The
code corrections removed alias, allocation, duplicate/mirror, and technical
classification noise. The normalized audit then contained exactly 17 stored
projection drifts:

| Stage | OK | Non-OK | Raw mismatch | Audit errors |
| --- | ---: | ---: | ---: | ---: |
| Initial latest-backup audit | 283 | 49 | 26 | 0 |
| After canonical code fixes | 302 | 30 | 26 | 0 |
| Deterministic legacy normalization | 315 | 17 | 17 | 0 |
| Clone 1 after guarded repair | 332 | 0 | 0 | 0 |
| Clone 2 after guarded repair | 332 | 0 | 0 | 0 |
| Clone 1 after regression tests | 332 | 0 | 0 | 0 |

The 17 final pre-repair root causes were all
STORED_PROJECTION_DRIFT_CONFIRMED with high confidence and persisted document
evidence. There were no unknown root causes or manual-review blockers.

## Guarded repair and deterministic replay

- Source audit SHA-256:
  7a90ed81d58c4184db5f638d121bb6accb8d7567cbce61203ecd32c84fa432bc.
- Population report SHA-256:
  0d5ed4379bc262c8e5c1b310d491909c3a09a716312c5ba3905c6944f77e9f44.
- Plan database fingerprint:
  3fadb19546b017496664b0f8e9bb4c808ebf2e280b3857460c27b3cea17ccecc.
- Plan hash:
  4520c05bd7c8af61de53b0cd1d152e0140fed62f7833ef74e7c8e4ae1630787a.
- Approval hash:
  6fa7ea78faad806080ac2ed2e8bcd2fa9a377d818d4324495c84149e5925b048.
- Plan rows: 315 NO_ACTION, 17 UPDATE_STORED_PROJECTION, and 1
  MARK_LEGACY_ORPHAN_EXCLUDED.
- Clone 1 operation UUID: a061c38c-d5d4-4ce6-8443-3aeeb8249377.
- Clone 2 operation UUID: 41df0350-73a3-4bac-86aa-d7e7b081b5c5.
- Reapplying the identical plan on each clone returned replay with
  ROWS_CHANGED=0.

The source backup and restored source state were never modified. Repair was
applied only to independent clone databases in a single guarded transaction.

Clone equivalence after repair:

| Evidence | Clone 1 | Clone 2 |
| --- | --- | --- |
| Canonical projection hash | c4aa2057ba2a719b256152656b8decafd98a259818f30dedf1dbb5c090fb13c6 | same |
| Post-repair row hash | 674968d560f428db8d25ab3933f1b9009c669ac041e3bab005b115b73e568fb8 | same |
| Pre-repair row hash | 3bc8df1547fdb372becf2d371cba235ac050910b009ff84ec72de3c430ecf626 | same |

## Material partner and UI/API contracts

For KH177561736414 (partner ID 72, customer-only):

| Field | Before | After |
| --- | ---: | ---: |
| Stored receivable | 2,370,000 | 4,940,000 |
| Canonical receivable | 4,940,000 | 4,940,000 |
| Raw difference | 2,570,000 | 0 |

Authenticated HTTP contract checks on MariaDB clone 1 all returned HTTP 200:

| View | Partner | Expected display | Result |
| --- | --- | ---: | --- |
| Customer-only customer view | KH177561736414 | 4,940,000 | PASS |
| Dual-role customer view | NCC177379765843 | -15,870,000 | PASS |
| Dual-role supplier view | NCC177379765843 | 15,870,000 | PASS |
| Supplier-only supplier view | NCC177354084249 | 22,700,000 | PASS |

The old 2,370,000 value is absent from the customer response. Browser
screenshot capture is not claimed: the Codex in-app browser rejected loopback
navigation by URL policy, and no alternate-browser workaround was used.

## Test and quality gates

### Passing gates

- Debt-focused MariaDB 10.11.10 bundle: 199 tests, 1,042 assertions,
  1 skipped, PASS.
- Debt-focused MySQL 8.0.44 bundle: 199 tests, 1,042 assertions,
  1 skipped, PASS.
- Focused failure rerun: 23 tests, 204 assertions, PASS.
- Clone 1 post-regression audit: 332/332 OK.
- Clone 1 post-regression invariant check: 332/332 matched; all warning,
  mismatch, insufficient-evidence, and error counters are zero.
- Changed-file Pint: 25 files, PASS.
- npm run build: Vite 5.4.21, 922 modules, PASS.
- git diff --check: PASS.
- Added-line private-key/token scan: PASS.

MySQL 8.0.44 could not directly import one MariaDB-only default expression
on roles.permissions. A derived, ignored portability copy removed only that
default for the MySQL engine test; the immutable source backup was not
changed. Source SQL SHA-256 is
71719eee442fbd5cbc75c7caea71732b11ae21bf4643073fa2db1f8fb9d1942;
derived SQL SHA-256 is
758d3fc840ed5fe2bfa5adb8b81f699f580bdf0715266e769ec71299efbee632.

### Blocking repository-wide gates

The full PHPUnit suite was run twice with a 1 GB PHP memory limit:

1. on the restored MySQL 8 backup; and
2. on a separate empty kiot_fresh_test database after all 172 migrations.

Both runs produced the same result:

    Tests: 1683
    Assertions: 8097
    Errors: 52
    Failures: 87
    Skipped: 18
    Risky: 1

The repetition on a fully migrated empty database rules out the restored
legacy data as the sole cause. The suite shares a database while migration
tests mutate/drop schema, causing later order-dependent failures such as
missing partner_debt_operations and debt_offsets.reverses_debt_offset_id. It
also contains legacy timeline expectations that require virtual balance
masking, plus unrelated authorization, inventory, damage, and static
Vue-source failures. These failures were not hidden or mass-edited outside
this P0 scope.

Repository-wide php vendor/bin/pint --test also remains red: 797 files
checked, 2 errors, and 516 style issues. The 25 files changed by this task pass
Pint.

Local PHP emits startup warnings for unavailable OCI and Firebird extensions;
those extensions are unrelated to the MySQL/MariaDB debt validation.

## Docker lifecycle

Only task database containers were started, and only while restore/audit/test
work required them. At handoff:

    kiot_debt_parity_latest_clone1  Exited
    kiot_debt_parity_latest_clone2  Exited
    kiot_debt_parity_mysql8         Exited

No unrelated project container was stopped or changed. Backup artifacts and
stopped task volumes are retained for repeatable inspection.

## Historical final status before clean revalidation (superseded)

    LATEST_BACKUP_INTEGRITY=PASS
    BACKUP_CREATED_TODAY=yes
    BACKUP_CAPTURE_TIME=2026-07-18 15:11:55
    LATEST_BACKUP_MODIFIED_AT=2026-07-18T15:11:58.1994539+07:00
    POPULATION_RECONCILIATION=PASS
    CLONE_FINGERPRINT_EQUAL=PASS
    TOTAL_CUSTOMERS=332
    TOTAL_SOURCE_UNION=333
    TOTAL_SCANNABLE_CUSTOMERS=332
    TOTAL_ORPHAN_FINANCIAL_REFERENCES=1
    UNEXPLAINED_MISSING_CUSTOMERS=0
    CUSTOMER_ONLY_COUNT=265
    SUPPLIER_ONLY_COUNT=50
    DUAL_ROLE_COUNT=17
    CUSTOMER_ONLY_MISMATCHES_BEFORE=2
    SUPPLIER_ONLY_MISMATCHES_BEFORE=10
    DUAL_ROLE_CUSTOMER_SIDE_MISMATCHES_BEFORE=2
    DUAL_ROLE_SUPPLIER_SIDE_MISMATCHES_BEFORE=3
    DUAL_ROLE_NET_MISMATCHES_BEFORE=5
    MATERIAL_DRIFT_BEFORE=17
    INSUFFICIENT_EVIDENCE_BEFORE=0
    TECHNICAL_WARNINGS_BEFORE=0
    SAFE_PROJECTION_ROWS_APPLIED=17
    SAFE_EVIDENCE_ROWS_BACKFILLED=0
    CODE_ONLY_ROWS_FIXED=32
    MANUAL_REVIEW_ROWS_AFTER=0
    DEBT_PARTNER_PARITY=332/332
    CUSTOMER_ONLY_MISMATCHES_AFTER=0
    SUPPLIER_ONLY_MISMATCHES_AFTER=0
    DUAL_ROLE_CUSTOMER_SIDE_MISMATCHES_AFTER=0
    DUAL_ROLE_SUPPLIER_SIDE_MISMATCHES_AFTER=0
    DUAL_ROLE_NET_MISMATCHES_AFTER=0
    MATERIAL_DRIFT_AFTER=0
    INSUFFICIENT_EVIDENCE_AFTER=0
    TECHNICAL_WARNINGS_AFTER=0
    TOTAL_TIMELINE_WARNINGS_AFTER=0
    RAW_MISMATCH=0
    UNKNOWN_ROOT_CAUSES=0
    UNSCANNABLE=0
    PARTNER_55_STATUS=legacy_orphan_excluded
    PARTNER_55_AFFECTS_CANONICAL_BALANCE=no
    KH177561736414_STATUS=PASS
    KH177561736414_UI_CURRENT_AFTER=4940000
    KH177561736414_RAW_TIMELINE_FINAL_AFTER=4940000
    KH177561736414_DIFFERENCE_AFTER=0
    KH177561736414_WARNING_AFTER=no
    CLONE_1=kiot_debt_parity_latest_clone1
    CLONE_2=kiot_debt_parity_latest_clone2
    CLONE_1_FINAL_HASH=c4aa2057ba2a719b256152656b8decafd98a259818f30dedf1dbb5c090fb13c6
    CLONE_2_FINAL_HASH=c4aa2057ba2a719b256152656b8decafd98a259818f30dedf1dbb5c090fb13c6
    DETERMINISTIC_REPLAY=PASS
    REPAIR_REPLAY_ROWS_CHANGED=0
    SECOND_APPLY_ROWS_CHANGED=0
    ALL_DEBT_WRITE_PATHS_AUDITED=yes
    ALL_DEBT_WRITE_PATHS_ATOMIC=yes
    ALL_RETRYABLE_WRITES_IDEMPOTENT=yes
    ALL_CANCELLATIONS_SYMMETRIC=yes
    TIMELINE_EXACTLY_ONCE=yes
    MARIADB_DEBT_SUITE=PASS
    MYSQL8_DEBT_SUITE=PASS
    HTTP_DISPLAY_CONTRACT=PASS
    BROWSER_SCREENSHOT=BLOCKED_BY_LOOPBACK_POLICY
    FULL_PHPUNIT_SUITE=FAIL
    REPOSITORY_WIDE_PINT=FAIL
    FRONTEND_BUILD=PASS
    CHANGED_FILE_PINT=PASS
    PHP_LINT=PASS
    GIT_DIFF_CHECK=PASS
    SECRET_SCAN=PASS
    P0_BLOCKERS=2
    P1_BLOCKERS=1
    P2_FINDINGS=1
    PRODUCTION_ACCESSED=no
    PRODUCTION_DATABASE_CHANGED=no
    PRODUCTION_DEPLOYED=no
    DEBT_PARITY_ZERO_DRIFT_STATUS=BLOCKED
