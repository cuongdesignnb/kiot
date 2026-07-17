# PR D Debt Release Runner Final Validation

## Scope And Revision

```text
REPOSITORY=cuongdesignnb/kiot
PR_URL=https://github.com/cuongdesignnb/kiot/pull/24
BRANCH=feat/debt-release-runner-pr-d
BASE_BRANCH=production-customer-group
BASE_SHA=8de02595bf7b22ac5490f9f44a43602af85a7848
HEAD_BEFORE_STEP_36=5184be8086568ad5da03b85b35671678267d185e
VALIDATED_IMPLEMENTATION_SHA=09031b436e061f6eafd642b541115efe97adba66
RELEASE_ID=debt-pr-d
```

STEP 36 changes release automation only. It does not modify a migration,
model, service, controller, route, frontend file, scheduler or production
configuration. It does not enable a debt write path, backfill data, create an
operation/outbox row or correct a current balance.

Changed implementation files remain restricted to:

```text
scripts/debt-release/pr-d-release.sh
scripts/debt-release/debt-release.php
scripts/debt-release/releases/pr-d.php
scripts/debt-release/README.md
tests/Feature/Release/DebtReleaseRunnerTest.php
docs/debt/PR-D-DEBT-RELEASE-RUNNER-VALIDATION.md
```

## P1 Closure

### Process deadlines

`NativeProcessExecutor` uses `proc_open(..., bypass_shell=true)`, non-blocking
stdout/stderr drains and `hrtime()` deadlines. Timeout sends `SIGTERM`, waits a
two-second grace period, then sends `SIGKILL` if the child remains alive. Pipes
are closed and the child is reaped in `finally`.

```text
PROCESS_TIMEOUT_IMPLEMENTED=yes
GIT_PHP_TIMEOUT_SECONDS=30
CURL_OUTER_TIMEOUT_SECONDS=30
GZIP_TIMEOUT_SECONDS=60
BACKUP_TIMEOUT_SECONDS=1800
RESTORE_TIMEOUT_SECONDS=1800
MIGRATION_TIMEOUT_SECONDS=180
OPTIMIZE_CLEAR_TIMEOUT_SECONDS=120
MAINTENANCE_TIMEOUT_SECONDS=60
MIGRATION_TIMEOUT_BLOCKER=MIGRATION_<N>_TIMEOUT
MIGRATION_TIMEOUT_EXIT_CODE=90
AUTOMATIC_DDL_ROLLBACK=no
```

Linux tests prove child termination, `timedOut`, duration/signal evidence,
maintenance recovery, partial report creation and lock release.

### Fail-closed DDL visibility

The DDL gate no longer converts visibility errors to zero activity. It requires
global `PROCESS` visibility, readable `information_schema.innodb_trx`, readable
`information_schema.processlist`, enabled Performance Schema and enabled
`wait/lock/metadata/sql/mdl` instrumentation.

The metadata query targets the current database and `debt_offsets`, excludes
the runner's current thread, and counts granted and pending locks. The gate
blocks on incomplete visibility, granted blockers, pending waiters, open/in-use
tables, long target queries or old transactions.

```text
INNODB_TRX_VISIBILITY=required
PROCESSLIST_VISIBILITY=required
METADATA_LOCK_VISIBILITY=required
DDL_VISIBILITY_FAIL_CLOSED=yes
VISIBILITY_BLOCKER=DDL_RISK_VISIBILITY_INSUFFICIENT
ACTIVITY_BLOCKER=DDL_ACTIVITY_DETECTED
AUTOMATIC_SESSION_KILL=no
```

The MariaDB disposable engine initially had the metadata instrument disabled.
The runner returned the visibility blocker. The test then enabled the
instrument on that disposable server and reran the gate. A second connection
held a transaction/metadata lock and both exact engines blocked DDL until that
transaction was rolled back.

### Consistent read-only snapshots

Before every baseline, migration invariance check and postflight capture, all
invariant tables plus `migrations` must exist as InnoDB. The same PDO connection
runs:

```sql
SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;
-- all counts, hashes and financial aggregates
ROLLBACK;
```

Rollback is in `finally`. Baseline and postflight reports record:

```text
DATA_SNAPSHOT_CONSISTENT=yes
SNAPSHOT_READ_ONLY=yes
SNAPSHOT_ROLLED_BACK=yes
NON_TRANSACTIONAL_INVARIANT_TABLE=blocking
```

Two-connection tests on MySQL and MariaDB commit a fixture after the snapshot
starts and prove that the runner retains the original view. The fixture is
removed after the assertion.

### Token, TTL and partial resume

`--approval-token` is rejected. Fresh deploy reads the token without echo from
`/dev/tty`. Tests may use `DEBT_RELEASE_APPROVAL_TOKEN`; the value is removed
from the process environment immediately after read.

Fresh deploy enforces TTL and a valid unconsumed token. The sidecar is marked
consumed only after filesystem/database locks, Git/DB/doctor revalidation,
maintenance entry, DDL visibility and baseline capture, immediately before
migration 1.

Partial resume requires `--resume-partial-ack`, a bound checkpoint and at least
one migration that ran or one `migration_N_verified` stage. It may use an
expired preflight and does not read/reuse the old token. It revalidates SHA,
branch, worktree, DB fingerprint, report/backup hashes, DDL visibility,
checkpoint/schema state and every completed stage.

```text
APPROVAL_TOKEN_CLI_ARGUMENT_REMOVED=yes
APPROVAL_TOKEN_HIDDEN_INPUT=yes
TOKEN_CONSUMPTION_STAGE=ddl_boundary_before_migration_1
FRESH_DEPLOY_TTL_ENFORCED=yes
TOKENLESS_FRESH_DEPLOY_BLOCKED=yes
PARTIAL_RESUME_AFTER_TTL=yes
PARTIAL_RESUME_WITHOUT_OLD_TOKEN=yes
PARTIAL_RESUME_REQUIRES_ACK=yes
PARTIAL_RESUME_SCHEMA_MISMATCH_BLOCKED=yes
PARTIAL_RESUME_WITHOUT_CHECKPOINT_BLOCKED=yes
SUCCESSFUL_CLOSEOUT_CANNOT_BE_RESUMED=yes
```

Final exact-engine validation exposed one follow-up defect: the blocked retry
after a successful closeout overwrote the SUCCESS report with a PARTIAL report.
Commit `09031b436e061f6eafd642b541115efe97adba66` fixes this by returning the
closeout blocker without mutating the completed checkpoint/report. A dedicated
unit assertion and both exact-engine runs prove preservation.

### Raw SQL and controlled signals

Linux startup uses `umask(0077)`. Backup and restore pre-create unique,
allowlisted raw SQL files at mode `0600`, verify permissions before use and
remove them in `finally`. Cleanup removes only strict
`kiot-pr-d-raw-*.sql.tmp` matches. Final gzip backups and audit reports are not
cleanup targets. Success and injected gzip failure are covered.

The CLI installs `pcntl` handlers for `SIGINT`, `SIGTERM` and `SIGHUP`. A
controlled signal terminates the active child, enters the same checkpoint and
maintenance recovery path, releases both locks and exits `90`. No recovery is
claimed for `SIGKILL`, host loss or power loss. Missing `pcntl` blocks doctor,
preflight and deploy under this release policy.

```text
RAW_SQL_TEMP_MODE_0600=yes
RAW_SQL_TEMP_SUCCESS_CLEANUP=yes
RAW_SQL_TEMP_FAILURE_CLEANUP=yes
STALE_RAW_TEMP_CLEANUP_ALLOWLIST=yes
FINAL_BACKUP_RETAINED=yes
AUDIT_REPORT_RETAINED=yes
PCNTL_REQUIRED=yes
SIGINT_TEST=PASS
SIGTERM_TEST=PASS
SIGHUP_TEST=PASS
LOCKS_RELEASED_AFTER_CONTROLLED_SIGNAL=PASS
```

## Exact-engine Validation

Disposable local databases only were used.

| Engine | Version | Laravel driver | Full flow | Assertions | DDL visibility | Consistent snapshot | Invariance |
|---|---|---|---|---:|---|---|---|
| MySQL | 8.0.44 | `mysql` | PASS | 24 | PASS | PASS | PASS |
| MariaDB | 10.11.10 | `mariadb` | PASS | 24 | PASS after test-only instrument enablement | PASS | PASS |

Each full flow includes doctor, preflight, mode-`0600` backup, disposable
restore, held-lock blocker, fresh deploy, all three exact migration paths,
postflight, HTTP/log smoke, token consumption, successful-closeout retry block,
raw-temp cleanup and retained final backup.

## Regression Evidence

```text
RUNNER_LINUX_UNIT=PASS (35 tests, 148 assertions)
MYSQL_RUNNER_FULL_FLOW=PASS (1 test, 24 assertions)
MARIADB_RUNNER_FULL_FLOW=PASS (1 test, 24 assertions)
MYSQL_PR_A_B_C_D_PLUS_RUNNER=PASS (82 tests, 1905 assertions)
PHASE1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
PHP_LINT=PASS
PINT=PASS
BASH_SYNTAX=PASS
SHELLCHECK=not available in validation image
GIT_DIFF_CHECK=PASS
FORBIDDEN_FILE_SCAN=PASS
SECRET_SCAN=PASS (no literal secret; client password is written only to ephemeral mode-0600 defaults file)
FRONTEND_BUILD_REQUIRED=no
GITHUB_ACTIONS_STATUS=not available
```

The Phase 1 set covers canonical balances, invariant checking, parity audit
classification, raw screen fixtures, the read-only invariant command,
reconciliation report/export and supplier timeline parity.

## Operator Contract

README contains the approved stage/preflight block and controlled deploy block.
The production deploy command has no token argument. Partial resume is a
separate recovery form with `--resume-partial-ack` and is valid only after the
runner proves a partial migration state.

```text
PRODUCTION_COMMAND_COUNT_AFTER_MERGE=2
STRICT_SHELL_MODE=yes
FILESYSTEM_LOCK=yes
DATABASE_ADVISORY_LOCK=yes
TEMP_DB_ALLOWLIST=yes
REPORT_PATH_ALLOWLIST=yes
BACKUP_PATH_ALLOWLIST=yes
EXACT_MIGRATION_ALLOWLIST=yes
MAINTENANCE_MODE_RECOVERY=yes
CHECKPOINT_RESUME=yes
AUTOMATIC_DDL_ROLLBACK=no
AUTOMATIC_SESSION_KILL=no
```

## Safety And Readiness

```text
P0_BLOCKERS=0
P1_BLOCKERS_BEFORE=4
P1_BLOCKERS_AFTER=0
P2_FINDINGS=production preflight must prove PROCESS privilege, Performance Schema metadata-lock instrumentation and pcntl; external artifact signing/object backup/notifications remain future hardening

PRODUCTION_ACCESSED=no
MIGRATIONS_RUN_ON_PRODUCTION=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_DEPLOYED=no
BACKFILL=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=no
OUTBOX_CREATED=no
CURRENT_DEBT_DATA_CHANGED=no

SENIOR_ACCEPTANCE_DECISION=ACCEPTED_FOR_CONTROLLED_RELEASE_RUNNER_SCOPE
READY_FOR_PR_D_PRODUCTION_PREFLIGHT=yes after PR merge and Owner approval
READY_FOR_PR_D_PRODUCTION_DEPLOY=no
READY_FOR_DEBT_OFFSET_APPLICATION_PR=no
READY_FOR_CURRENT_DATA_CORRECTION=no
```

No production host, production database, production command, migration or
deployment was used in STEP 36.
