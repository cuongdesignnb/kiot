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

Only `deploy` can alter the live schema. It requires a passing, unexpired
preflight report, its one-time approval token, the same expected SHA and an
explicit maintenance acknowledgement:

```bash
bash scripts/debt-release/pr-d-release.sh deploy \
  --preflight-report <PATH> \
  --approval-token <TOKEN> \
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
unset DB_DATABASE APP_CONFIG_CACHE
git fetch origin production-customer-group
git checkout production-customer-group
git merge --ff-only origin/production-customer-group
bash scripts/debt-release/pr-d-release.sh preflight \
  --expected-sha <RUNNER_MERGE_SHA>
```

The controlled deploy is the second and final operator command block:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
unset DB_DATABASE APP_CONFIG_CACHE
bash scripts/debt-release/pr-d-release.sh deploy \
  --preflight-report <PREFLIGHT_REPORT_PATH> \
  --approval-token <APPROVAL_TOKEN_PRINTED_BY_PREFLIGHT> \
  --expected-sha <RUNNER_MERGE_SHA> \
  --maintenance-window-ack
```

## Preflight And Secrets

Preflight verifies branch, exact SHA, clean worktree, production ancestry,
database-name fingerprint, MariaDB 10.11, constraint settings, tools, free disk,
DDL activity, backup and restore. The plain approval token is printed once. Only
its SHA-256 and a binding to release, SHA, database fingerprint, report hash and
TTL are stored.

Database client credentials are written to an ephemeral mode-`0600` defaults
file. They are never passed as command arguments or written to reports. Reports
contain no plain database name, credentials, PII or row dumps.

## Maintenance And Resume

Deploy acquires both locks, runs `artisan down --retry=60`, captures a fresh
authoritative baseline, and applies exactly one allowlisted migration path at a
time. Each verified stage is atomically checkpointed. A `finally` recovery runs
`artisan up` after ordinary failures.

There is no automatic DDL rollback. If a stage fails, the runner exits `90`,
preserves the exact last verified stage and requires the same deploy command to
resume. Already-run migrations are skipped only after their schema contract and
business-data invariants pass. A checkpoint/database mismatch is blocked.

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

`cleanup` removes only allowlisted temporary restore databases and a stale
filesystem lock. It never removes reports or backups.

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
