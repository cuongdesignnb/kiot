# All-partner debt parity and zero-drift validation

**DEBT_PARITY_ZERO_DRIFT_STATUS=BLOCKED**

**TASK_CODE=DEBT-PARITY-LATEST-BACKUP-CLOSURE-01**

**PR=#30**

Validation was performed on branch
fix/all-partner-debt-parity-zero-drift. No production system was accessed or
changed, no deployment or merge was performed, and
DEBT_OFFSET_WRITE_MODE=legacy remained in effect. The debt-offset
workflow/outbox write path was not enabled.

## Outcome

The latest supplied backup now reaches debt parity on two independent
MariaDB restores:

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

## Final status

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
