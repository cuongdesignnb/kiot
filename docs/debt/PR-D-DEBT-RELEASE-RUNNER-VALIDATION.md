# PR D Debt Release Runner Validation

## Scope

```text
REPOSITORY=cuongdesignnb/kiot
BRANCH=feat/debt-release-runner-pr-d
BASE_SHA=8de02595bf7b22ac5490f9f44a43602af85a7848
HEAD_BEFORE=8de02595bf7b22ac5490f9f44a43602af85a7848
HEAD_AFTER=reported by the Draft PR head after commit
RELEASE_ID=debt-pr-d
PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
```

STEP 35 adds release automation only. It does not change a model, service,
controller, route, frontend file, scheduler, production configuration or
migration. It does not enable a debt workflow or create an operation/outbox
record.

Changed files are restricted to:

```text
scripts/debt-release/pr-d-release.sh
scripts/debt-release/debt-release.php
scripts/debt-release/releases/pr-d.php
scripts/debt-release/README.md
tests/Feature/Release/DebtReleaseRunnerTest.php
docs/debt/PR-D-DEBT-RELEASE-RUNNER-VALIDATION.md
```

## Architecture

The Bash wrapper has strict mode and only resolves the repository before
executing the PHP core. The PHP core bootstraps Laravel so the runner uses the
deployed database/config contract. Release-specific values live in the PR D
manifest rather than procedural branches.

Supported subcommands:

| Command | Contract |
|---|---|
| `doctor` | Read-only runtime, Git, dependency and DB connectivity report |
| `preflight` | Read-only live checks plus backup and allowlisted temp restore |
| `deploy` | Only mode allowed to migrate; exact manifest paths only |
| `status` | Read-only migration/checkpoint state |
| `cleanup` | Only allowlisted temp DBs and a stale filesystem lock |

The exit contract is stable: `0`, `10`, `20`, `30`, `40`, `50`, `60`, `70`,
`80`, and `90` for success, argument, Git, DB, dependency, lock, schema, data,
smoke and partial-release outcomes respectively.

## Safety Gates

```text
STRICT_SHELL_MODE=yes
FILESYSTEM_LOCK=yes (non-blocking flock)
DATABASE_ADVISORY_LOCK=yes (GET_LOCK/RELEASE_LOCK)
TEMP_DB_ALLOWLIST=yes
REPORT_PATH_ALLOWLIST=yes
BACKUP_PATH_ALLOWLIST=yes
EXACT_MIGRATION_ALLOWLIST=yes (3 paths)
SUBCOMMAND_HELP=yes (global and doctor/preflight/deploy forms)
FILESYSTEM_LOCK_PATH=storage/app/audits/debt-release/.runner.lock
STATUS_SAFE_RERUN_COMMAND=yes (token placeholder only)
CLEANUP_STALE_CREDENTIALS=yes (strict filename allowlist)
PREFLIGHT_EVIDENCE_COLLISION_GUARD=yes
APPROVAL_TOKEN_BINDING=yes
PREFLIGHT_TTL=360 minutes
MAINTENANCE_MODE_RECOVERY=yes
CHECKPOINT_RESUME=yes
AUTOMATIC_DDL_ROLLBACK=no
AUTOMATIC_SESSION_KILL=no
```

The approval token is random and printed once. Only a SHA-256 token hash and a
binding hash over release ID, expected SHA, DB fingerprint, report hash and TTL
are persisted. DB credentials use an ephemeral mode-`0600` client defaults
file, are absent from process arguments and are redacted from JSON.

The production manifest is fail-closed to:

```text
branch=production-customer-group
previous production SHA=6ed1c198a38a2c8d31e2d67d6cc39e6662485700
database-name SHA-256=f274e3270e7608fa98dfd67614230224914f1efd7f468b496cfe0c7376d11288
engine=MariaDB 10.11.x
FOREIGN_KEY_CHECKS=1
CHECK_CONSTRAINT_CHECKS=1
```

No plain database name is included in runner output or reports.

## Backup And Restore

The runner inspects table engines before choosing consistency mode. An all-
InnoDB database uses `--single-transaction`; otherwise the dump uses a global
table lock. Dumps include schema/data, routines, events and triggers. Required
proof is non-empty output, verified mode `0600`, SHA-256 and `gzip -t`.

Restore uses an allowlisted temporary database and verifies table count,
migration rows, customer/debt-offset counts, PR A/B/C table presence and PR D
absence. The temp DB is dropped in `finally` and absence is queried afterward.
Backups are retained; cleanup never removes a backup or audit report.

## Baseline And Postflight

The authoritative baseline is captured after maintenance mode begins, not from
preflight. Data hashing is streaming, primary-key ordered, canonical JSON with
stable columns and preserved string/decimal values. Schema hashes normalize
only whitespace and the volatile `AUTO_INCREMENT=<counter>` value.

For all invariant tables the runner records counts and schema/data hashes. For
`debt_offsets`, the pre-PR-D columns receive a separate legacy-only data hash.
Financial aggregates cover customers, cash flows, invoices, purchases,
customer/supplier ledgers and offsets.

Each migration is run through its exact `--path` and verified before checkpoint:

1. 17 nullable/default-NULL columns, no backfill, legacy customer FK retained.
2. Two unique keys and seven FKs with exact columns/targets/delete rules.
3. Six named CHECK constraints with required clause tokens and checks enabled.

Postflight requires non-target count/data/schema hashes unchanged, legacy
offset count/hash unchanged, financial aggregates unchanged and exactly three
new migration rows. No invalid write probe runs against the live database.

## Checkpoint And Failure Evidence

Atomic checkpoint stages are:

```text
initialized
maintenance_entered
baseline_captured
migration_1_verified
migration_2_verified
migration_3_verified
postflight_verified
application_recovered
smoke_verified
closeout_written
```

A rerun reacquires both locks, revalidates Git/DB/report/backup, verifies every
already-run stage and continues from the first incomplete stage. A checkpoint
and database mismatch exits `90`. DDL is never automatically rolled back.
Blocked preflight and partial deployment reports include the exact blocker;
partial reports also record the last verified stage and maintenance recovery.

## Automated Tests

Runner unit/failure injection covers manifest and argument validation, required
flags, SHA/branch/worktree/DB gates, dependencies, TTL, token and report binding,
path/temp-DB allowlists, exact migration set, checkpoint progression/mismatch,
resume, redaction, summary contract and cleanup. It also proves that migration
paths cannot be substituted while retaining an allowlisted migration name, and
that DDL risk is rechecked after maintenance mode starts and before baseline or
migration work begins.

Failure injection results:

```text
FAILURE_BACKUP=PASS (fail-fast)
FAILURE_RESTORE=PASS (fail-fast)
FAILURE_TOKEN=PASS
FAILURE_STALE_PREFLIGHT=PASS
FAILURE_FILESYSTEM_LOCK=PASS
FAILURE_DATABASE_LOCK=PASS
FAILURE_DEPLOY_START_DDL_RISK=PASS (maintenance recovered; no migration)
FAILURE_PARTIAL_MIGRATION=PASS (stage 1 checkpoint; maintenance recovered)
FAILURE_POSTFLIGHT=PASS
FAILURE_SMOKE=PASS
```

Disposable full-run integration:

| Engine | Version | Driver | Doctor | Preflight/backup/restore | Deploy | Second deploy | Invariance |
|---|---|---|---|---|---|---|---|
| MySQL | 8.0.44 | `mysql` | PASS | PASS | PASS | PASS safe resume/no-op | PASS |
| MariaDB | 10.11.10 | `mariadb` | PASS | PASS | PASS | PASS safe resume/no-op | PASS |

Both runs used test-prefixed local Docker databases and a test-injected DB
fingerprint/engine contract. The production manifest was not relaxed. MySQL had
one legacy `debt_offsets` row; all 17 new fields remained NULL. MariaDB had zero
offset rows. Both had exactly three PR D migration rows after deploy.

Final regression evidence:

```text
RUNNER_UNIT=PASS (22 tests, 88 assertions; integration group excluded)
MYSQL_RUNNER_INTEGRATION=PASS (1 test, 9 assertions)
MARIADB_RUNNER_INTEGRATION=PASS (1 test, 9 assertions)
MYSQL_PR_A_B_C_D_PLUS_RUNNER=PASS (69 tests, 1845 assertions)
PHASE1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
```

An initial Windows-host schema invocation failed because its configured MySQL
port refused connections (`SQLSTATE[HY000] [2002]`). The same exact suite was
rerun in the Docker MySQL 8 environment. The schema portion passed 47/47, and
the final combined schema/runner suite passed 69/69. This was an execution
environment failure, not a schema assertion failure.

## Production Commands

After merge there are exactly two operator command blocks: stage/preflight and
controlled deploy. The templates are in `scripts/debt-release/README.md`. The
runner itself does not pull/reset code and does not accept arbitrary migration
paths.

```text
PRODUCTION_COMMAND_COUNT_AFTER_MERGE=2
BACKFILL=no
WORKFLOW_STATUS_BACKFILLED=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=no
OUTBOX_CREATED=no
CURRENT_DEBT_DATA_CHANGED=no
```

## Findings And Readiness

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=remote artifact signing; external object backup; deployment-environment approval; notifications; cross-host coordination

READY_FOR_RUNNER_SENIOR_REVIEW=yes
READY_TO_MARK_RUNNER_PR=no
READY_TO_MERGE_RUNNER_PR=no
READY_FOR_PR_D_PRODUCTION_PREFLIGHT=no (requires merged runner SHA and owner approval)
READY_FOR_PR_D_PRODUCTION_DEPLOY=no
READY_FOR_DEBT_OFFSET_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

No production host, production database, production migration or deployment was
used in STEP 35.
