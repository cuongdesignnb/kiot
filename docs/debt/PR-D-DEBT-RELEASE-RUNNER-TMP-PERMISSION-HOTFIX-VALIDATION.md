# PR D Release Runner `/tmp` Permission Hotfix Validation

## Decision Record

```text
INCIDENT=P0_PRODUCTION_OUTAGE
ROOT_CAUSE=EXISTING_DIRECTORY_UNCONDITIONAL_CHMOD
ROOT_CAUSE_CONFIRMED=yes
BRANCH=hotfix/debt-release-runner-tmp-permissions
BASE_BRANCH=production-customer-group
BASE_SHA=cfd8588fb0a1b75e4ccdba3220d0b7d03aa43e3a
FINAL_HEAD_SHA=use the exact PR head reported by GitHub and the final handoff
PRODUCTION_ACCESSED=no
PRODUCTION_MIGRATIONS_RUN=no
PRODUCTION_DATA_CHANGED=no
```

`FINAL_HEAD_SHA` cannot be embedded as the hash of the commit containing this
file because that is self-referential. The immutable value is recorded in the
PR body and final BA handoff after the report commit is created.

## Root Cause And Fix

`NativeReleasePlatform::writeClientDefaultsFile()` created its credential file
under `sys_get_temp_dir()` through `ReleaseFiles::atomicText()`. That helper
called `ensureDirectory(dirname($path), 0750)`, and the old implementation
unconditionally called `chmod()` even when the directory already existed. On
production this was equivalent to `chmod 0750 /tmp`.

The hotfix changes the runner only:

- `ensureDirectory()` returns without chmod for every existing directory.
- A path that exists but is not a directory fails with
  `DIRECTORY_PATH_IS_NOT_DIRECTORY`.
- Symlink targets or symlink path components fail with
  `DIRECTORY_SYMLINK_BLOCKED`.
- Only a directory successfully created by the current operation is chmodded
  and its requested mode is verified.
- Atomic report files now use random exclusive `x+b` temporary files, so a
  predictable `.tmp` symlink cannot be followed by `chmod()`.
- Client credentials use an exclusive allowlisted filename, direct `x+b`
  creation, mode `0600`, complete write and flush checks, and failure cleanup.
- Credential creation records and verifies parent mode, owner and group; it
  never calls `ensureDirectory()` or `atomicText()`.
- An existing credential filename collision is not overwritten or deleted.

```text
EXISTING_DIRECTORY_CHMOD_REMOVED=yes
NEWLY_CREATED_DIRECTORY_MODE_ENFORCED=yes
CLIENT_CREDENTIAL_FILE_CREATED_EXCLUSIVELY=yes
CLIENT_CREDENTIAL_FILE_MODE_0600=PASS
CLIENT_CREDENTIAL_PARENT_UNCHANGED=PASS
PARTIAL_CREDENTIAL_REMOVED_ON_FAILURE=PASS
```

## File-write Call-site Audit

| Area | Parent directory | Result |
|---|---|---|
| Filesystem release lock | Audit root | Existing parent is used without chmod; symlinks fail closed |
| Preflight/deploy evidence | `storage/app/audits` descendants | Only newly created leaf directories receive requested mode |
| Atomic JSON/text | Existing audit directory | Parent metadata unchanged; random exclusive temp file replaces predictable `.tmp` |
| MySQL client credential | System temp | Direct exclusive file, `0600`; no directory helper call |
| Raw SQL temp | Backup root | Existing backup parent is not chmodded; raw file remains `0600` |
| Final gzip backup | Backup root | Only runner-created final file is chmodded `0600` |
| Cleanup | System temp and backup root | Deletes allowlisted files only; parent metadata unchanged |

Search covered `ensureDirectory`, `atomicText`, `atomicJson`,
`sys_get_temp_dir`, `mkdir`, `chmod`, `chown` and `chgrp` in the runner,
shell wrapper and runner tests. There are no `chown()` or `chgrp()` calls. The
remaining `chmod()` calls target only a directory just created by the same
operation or files created by the runner.

## System Temp Evidence

Linux metadata proof and both exact-engine integrations capture metadata before
and after credential creation, preflight and deploy. The tests compare all
three fields without attempting to repair `/tmp`.

```text
SYSTEM_TEMP_MODE_BEFORE=1777
SYSTEM_TEMP_MODE_AFTER=1777
SYSTEM_TEMP_OWNER_BEFORE=0
SYSTEM_TEMP_OWNER_AFTER=0
SYSTEM_TEMP_GROUP_BEFORE=0
SYSTEM_TEMP_GROUP_AFTER=0
SYSTEM_TEMP_METADATA_UNCHANGED=PASS
CLIENT_CREDENTIAL_FILES_REMAINING=0
RAW_SQL_TEMP_FILES_REMAINING=0
```

The exact integration also proves the existing audit and backup parent metadata
is unchanged. Unit coverage separately uses an existing `0711` directory and
proves that `ensureDirectory(..., 0750)` and atomic report writes leave it
`0711`.

## Failure-path Evidence

Covered failures:

```text
CLIENT_CREDENTIAL_CREATE_FAILED=PASS
CLIENT_CREDENTIAL_MODE_FAILED=PASS
CLIENT_CREDENTIAL_WRITE_FAILED=PASS
CLIENT_CREDENTIAL_FLUSH_FAILED=PASS
MYSQLDUMP_FAILED=PASS
BACKUP_GZIP_FAILED=PASS
RESTORE_IMPORT_FAILED=PASS
DDL_ACTIVITY_DETECTED=PASS
MIGRATION_1_FAILED=PASS
MIGRATION_2_FAILED=PASS
MIGRATION_3_FAILED=PASS
```

Credential, mysqldump, gzip, restore and DDL failures are exercised inside the
exact disposable-engine integration. Migration stage failures use the runner's
deterministic fake platform and assert maintenance recovery, database lock
release, no credential/raw-temp residue and unchanged system-temp metadata.

## Validation Results

```text
PHP_LINT=PASS
PINT=PASS
BASH_SYNTAX=PASS
GIT_DIFF_CHECK=PASS
DEBT_RELEASE_UNIT_TESTS=PASS (46 tests, 230 assertions)
DEBT_RELEASE_FAILURE_PATH_TESTS=PASS
MYSQL_VERSION=8.0.44
MYSQL_8_EXACT_ENGINE_RUNNER=PASS (1 test, 77 assertions)
MARIADB_VERSION=10.11.10-MariaDB-ubu2204
MARIADB_10_11_EXACT_ENGINE_RUNNER=PASS (1 test, 77 assertions)
PR_A_B_C_D_SCHEMA_AND_RUNNER_REGRESSION=PASS (92 tests, 1987 assertions)
PHASE_1_DEBT_REGRESSION=PASS (73 tests, 401 assertions)
FORBIDDEN_FILE_SCAN=PASS
SECRET_SCAN=PASS
SYSTEM_DIRECTORY_CHMOD_SCAN=PASS
SYSTEM_TEMP_METADATA_UNCHANGED=PASS
GITHUB_ACTIONS=NOT_RUN_NOT_CLAIMED
```

Exact-engine tests used only disposable local Docker databases:

- MySQL `8.0.44`: `test_kiot_pr_d_runner_mysql`
- MariaDB `10.11.10`: `test_kiot_pr_d_runner_mariadb`

The MariaDB disposable reset exposed a pre-existing migration-down portability
issue: the connection is configured with Laravel's `mysql` driver, so the PR D
check rollback chooses MySQL `DROP CHECK` instead of MariaDB `DROP CONSTRAINT`.
The disposable test constraints were removed with the MariaDB-equivalent DDL
before rerunning rollback. This hotfix does not modify PR D migrations and the
issue does not affect the forward migration or this runner permission fix.

## Changed Files

```text
scripts/debt-release/debt-release.php
tests/Feature/Release/DebtReleaseRunnerTest.php
docs/debt/PR-D-DEBT-RELEASE-RUNNER-TMP-PERMISSION-HOTFIX-VALIDATION.md
```

No migration, model, controller, service, route, frontend, scheduler, queue,
financial write path or data file changed.

## Safety Boundary

```text
BACKFILL=no
WORKFLOW_ENABLED=no
OPERATIONS_CREATED=no
OUTBOX_CREATED=no
CURRENT_DEBT_DATA_CHANGED=no
PRODUCTION_ACCESSED=no
PRODUCTION_MIGRATIONS_RUN=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_PREFLIGHT_RUN=no
PRODUCTION_DEPLOYED=no
```

## Acceptance

```text
P0_BLOCKERS=0
P1_BLOCKERS=0
READY_FOR_HOTFIX_REVIEW=yes
READY_FOR_HOTFIX_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
READY_FOR_PR_D_PREFLIGHT=no
READY_FOR_PR_D_DEPLOY=no
```
