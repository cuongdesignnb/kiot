# Debt Release Runner

This directory contains the release-specific runner for the three additive PR D
`debt_offsets` migrations. It is release automation, not an application write
path. It never backfills debt data and cannot run migrations outside the
manifest in `releases/pr-d.php`.

## Architecture

- `pr-d-release.sh` is the strict Bash entrypoint.
- `debt-release.php` bootstraps Laravel and implements locks, backup/restore,
  deterministic evidence, migration execution, postflight checks and resume.
- `releases/pr-d.php` is the immutable release allowlist and schema contract.

The runner requires both a non-blocking filesystem lock and a MariaDB advisory
lock. Reports are restricted to `storage/app/audits`; backups are restricted to
`/root` on Linux. Temporary database deletion is restricted to names matching
`test_kiot_pr_d_restore_YYYYMMDD_HHMMSS_<random>`.

## Commands

```bash
bash scripts/debt-release/pr-d-release.sh doctor
bash scripts/debt-release/pr-d-release.sh preflight --expected-sha <RUNNER_MERGE_SHA>
bash scripts/debt-release/pr-d-release.sh status
bash scripts/debt-release/pr-d-release.sh cleanup
```

Only `deploy` can alter the live schema. A fresh deploy requires a passing,
unexpired preflight report, the same expected SHA, an explicit maintenance
acknowledgement and the one-time token entered at the hidden `/dev/tty` prompt:

```bash
bash scripts/debt-release/pr-d-release.sh deploy \
  --preflight-report <PATH> \
  --expected-sha <RUNNER_MERGE_SHA> \
  --maintenance-window-ack
```

`doctor`, `status` and live portions of `preflight` are read-only. Preflight may
write an audit report and backup, restore that backup into an allowlisted local
temporary database, and drop only that temporary database.

## Production Template

After the runner PR is merged, production staging and preflight are one operator
command block. Replace placeholders only; do not paste a database password or
approval token into a tracked file.

```bash
cd /www/wwwroot/kiot.cuongdesign.net
unset DB_DATABASE APP_CONFIG_CACHE DEBT_RELEASE_APPROVAL_TOKEN
git fetch origin production-customer-group
git checkout production-customer-group
git merge --ff-only origin/production-customer-group
bash scripts/debt-release/pr-d-release.sh preflight \
  --expected-sha <RUNNER_MERGE_SHA>
```

The controlled deploy is the second and final operator command block:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
unset DB_DATABASE APP_CONFIG_CACHE DEBT_RELEASE_APPROVAL_TOKEN
bash scripts/debt-release/pr-d-release.sh deploy \
  --preflight-report <PREFLIGHT_REPORT_PATH> \
  --expected-sha <RUNNER_MERGE_SHA> \
  --maintenance-window-ack
```

## Preflight And Secrets

Preflight verifies branch, exact SHA, clean worktree, production ancestry,
database-name fingerprint, MariaDB 10.11, constraint settings, tools, free disk,
DDL activity, backup and restore. The plain approval token is printed once by
preflight. Deploy reads it without echo from `/dev/tty`; `--approval-token` is
rejected so it cannot leak through shell history or process arguments.
Non-interactive tests may use `DEBT_RELEASE_APPROVAL_TOKEN`; the runner unsets
it immediately after reading. Only its SHA-256 and binding are stored.

Database client credentials are written to an ephemeral mode-`0600` defaults
file. They are never passed as command arguments or written to reports. Reports
contain no plain database name, credentials, PII or row dumps.

## Maintenance And Resume

Deploy acquires both locks, runs `artisan down --retry=60`, passes a fail-closed
DDL visibility gate, and captures a fresh baseline in an InnoDB consistent
read-only snapshot. The token is consumed only after baseline capture, at the
DDL boundary immediately before migration 1. Each verified stage is atomically
checkpointed. A `finally` recovery runs `artisan up` after ordinary failures
and controlled `SIGINT`, `SIGTERM` or `SIGHUP` interruption.

There is no automatic DDL rollback. If at least one stage ran or was verified,
the runner exits `90`, preserves the exact last verified stage and requires:

```bash
bash scripts/debt-release/pr-d-release.sh deploy \
  --preflight-report <PREFLIGHT_REPORT_PATH> \
  --expected-sha <RUNNER_MERGE_SHA> \
  --maintenance-window-ack \
  --resume-partial-ack
```

A bound partial resume may use an expired preflight and does not reuse the old
token, but it revalidates Git, DB, backup, DDL visibility, checkpoint/schema
state and every completed stage. Tokenless fresh deploys, resume without a
checkpoint, schema/checkpoint mismatch and resume after successful closeout are
blocked.

## Evidence

Preflight evidence:

```text
storage/app/audits/debt-pr-d-preflight-*/
  preflight-report.json
  preflight-summary.txt
  approval-token.sha256
```

Deployment evidence:

```text
storage/app/audits/debt-pr-d-production-deploy-*/
  checkpoint.json
  deploy-start-baseline.json
  postflight.json
  deployment-report.json
  deployment-summary.txt
```

Raw SQL exists only in unique allowlisted mode-`0600` temporary files and is
removed after success, failure or a controlled signal. `cleanup` removes only
allowlisted temporary restore databases, stale raw SQL/client credential files
and a stale filesystem lock. It never removes reports or final gzip backups.

## Exit Codes

| Code | Meaning |
|---:|---|
| 0 | Success |
| 10 | Invalid arguments or approval evidence |
| 20 | Git gate blocked |
| 30 | Database identity/version gate blocked |
| 40 | Dependency, backup or report-write failure |
| 50 | Filesystem/database lock or DDL-risk gate blocked |
| 60 | Schema contract mismatch |
| 70 | Data-invariance mismatch |
| 80 | HTTP or Laravel log smoke failure |
| 90 | Partial release; inspect checkpoint and safely resume |

## Recovery

For any non-zero exit, inspect `BLOCKER` and `checkpoint.json`. Do not run broad
`artisan migrate`, do not manually edit migration rows, and do not create debt
adjustments. Confirm the application is up, preserve backup/evidence and obtain
Senior/BA review before resuming a partial release.
