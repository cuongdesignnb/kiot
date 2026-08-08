# P0: Eliminate global Inertia `app_settings` N+1 queries

## Scope and source boundary

```text
BASE_BRANCH=production-customer-group
BASE_SHA=d0b448cd1ec34d40567fbc4b4c6ad30a9c756cdb
BRANCH=codex/p0-inertia-app-settings-n-plus-one
REVIEWED_CODE_HEAD=3984374bc84fc48f748ef97f720dae84bbee4a69
```

This change is limited to the setting value resolver, the global Inertia
share middleware, and performance contract coverage. It does not change
setting keys, persisted values, business semantics, caching, migrations,
indexes, or database configuration.

## Root cause

`HandleInertiaRequests` shared `app_settings` through a lazy closure. For each
row returned by `Setting::all()`, it called `Setting::get($key)`. `Setting::get`
performed a new `where('key', ...)` query, so a request sharing 25 settings did
one schema query, one full settings query, and 25 point lookups: 27 settings
related queries in total.

The fix keeps `Setting::get($key, $default)` as the backward-compatible
single-key API and extracts the exact persisted-value conversion into
`Setting::resolvedValue()`. The Inertia middleware now checks that the table
exists, fetches only `key`, `value`, and `type` once, and resolves each loaded
model in memory. No persistent cache or new storage layer was introduced.

The conversion contract remains unchanged:

| Type | Resolution |
| --- | --- |
| `boolean` | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` |
| `number` | `(float) $value` |
| `json` | `json_decode($value, true)` |
| other/string | raw persisted value |
| missing key | supplied default, or `null` |

## Request/response contract

The `app_settings` shared prop remains a complete key-to-resolved-value map.
Existing consumers receive the same PHP values as before. The change is only
how the map is populated: one projection query plus the existing schema guard,
with no call to `Setting::get` inside the loop.

For an Inertia partial request whose data is
`purchases,summary,filters`, `app_settings` is not evaluated and the measured
settings query count is zero.

## Performance evidence

QA used a disposable MariaDB `10.11.18-MariaDB-ubu2204` container and a fresh
schema. The fixture contained exactly 25 settings, including string,
true/false boolean, integer/decimal number, JSON object, JSON array, and
string filler values. Baseline and head used the same fixture and request
contract. Four warm-up samples were collected per side; the first sample was
discarded and the median of samples 2-4 is reported.

| Metric | Before | After | Result |
| --- | ---: | ---: | --- |
| `APP_SETTINGS_QUERY_COUNT` | 27 | 2 | N+1 removed |
| `APP_SETTINGS_ELAPSED_MS` | 263.520 | 233.802 | improved |
| `APP_SETTINGS_DB_MS` | 31.170 | 2.330 | improved |
| `FULL_INERTIA_REQUEST_MS` | 263.520 | 233.802 | no regression |
| `FULL_INERTIA_QUERY_COUNT` | 175 | 150 | reduced by eliminated lookups |
| partial purchases settings queries | n/a | 0 | pass |

The timing values are local QA measurements, not a correctness gate. The
reduction in settings database time is approximately 92.5%; the full request
was approximately 11.3% faster in this fixture.

The following operator-provided, anonymized production comparison is recorded
separately from agent-run QA. The agent did not access production:

```text
PRODUCTION_SETTING_COUNT=25
PRODUCTION_SETTINGS_QUERY_COUNT_BEFORE=27
PRODUCTION_SETTINGS_QUERY_COUNT_SIMULATED=2
PRODUCTION_SETTINGS_TIME_BEFORE_MS=76.546
PRODUCTION_SETTINGS_TIME_SIMULATED_MS=3.566
```

## Test evidence

The added contract test verifies:

- all persisted types and missing-key defaults through both `Setting::get` and
  `resolvedValue`;
- a complete 25-entry map through the actual authenticated Inertia stack;
- at most two settings-related queries for a full request;
- zero settings queries for the specified purchases partial request; and
- identical settings props on `/` (dashboard), `/purchases`, `/suppliers`,
  `/pos`, and `/orders`.

```text
TARGETED_TESTS=22 tests / 163 assertions PASS
SETTINGS_CONTRACT=4 tests / 68 assertions PASS
SETTINGS_QUERY_COUNT_AFTER=2
PARTIAL_SETTINGS_QUERY_COUNT=0
APP_SETTINGS_VALUE_PARITY=PASS
SETTING_GET_BACKWARD_COMPATIBILITY=PASS
N_PLUS_ONE_REMOVED=PASS
```

## Browser QA

Authenticated local browser QA used the Codex in-app Browser against
`http://127.0.0.1:8899` and the disposable MariaDB-backed Laravel server.
Login was performed through the normal `/login` UI after isolating the server
to a fresh loopback port. No user Chrome session, persistent browser profile,
manual cookie injection, or production endpoint was used.

```text
LOGIN_UI=PASS
AUTHENTICATED_SESSION=PASS
DASHBOARD_RENDER=PASS
PURCHASES_RENDER=PASS
SUPPLIERS_RENDER=PASS
POS_RENDER=PASS
ORDERS_RENDER=PASS
BROWSER_CONSOLE_ERRORS=0
BROWSER_PAGE_ERROR_TEXT=NO
BROWSER_QA=PASS
```

The earlier 419 was caused by multiple stale local PHP dev servers sharing the
same QA port, not by a source change. The final run used one isolated server,
`SESSION_DRIVER=file`, an empty `SESSION_DOMAIN`, and
`SESSION_SECURE_COOKIE=false` on the single `127.0.0.1:8899` origin.

## Quality and data safety

```text
MARIADB_GATE=PASS
PINT=PASS
PHP_LINT=PASS
FRONTEND_BUILD=PASS
DIFF_CHECK=PASS
SECRET_SCAN=PASS
DEBUG_OUTPUT_SCAN=PASS
MIGRATION_ADDED=NO
BACKFILL=NO
QA_DATABASE_CREATED=YES_DISPOSABLE_ONLY
QA_DATABASE_MUTATED=YES_DISPOSABLE_ONLY
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
MERGE_RUN=NO
DEPLOY_RUN=NO
```

The disposable QA database was created only for this validation, was seeded
with the minimum settings/admin fixture, and is not a production copy. No
production database command was run. The QA server and database container are
stopped and removed after evidence collection.

## Changed files

```text
app/Http/Middleware/HandleInertiaRequests.php
app/Models/Setting.php
tests/Feature/Settings/InertiaAppSettingsPerformanceContractTest.php
docs/performance/P0-INERTIA-APP-SETTINGS-NPLUS1.md
```

## Rollback

Rollback is a normal branch/commit rollback of the three implementation/test
files. No migration rollback is required because this change adds no schema,
index, cache, or data transformation. Restoring the previous middleware and
model implementation restores the former request behavior, including its
per-setting lookup pattern.
