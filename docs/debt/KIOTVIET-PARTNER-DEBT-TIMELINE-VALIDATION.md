# KiotViet partner debt timeline validation

```text
TASK_CODE=KIOTVIET-PARTNER-DEBT-TIMELINE-CONTRACT-01
KIOTVIET_PARTNER_LEDGER_STATUS=BLOCKED
PR_NUMBER=31
PR_DRAFT=yes
PR_MERGED=no
BASE_SHA=c2df609571d35738423df313137de94c5108a8c5
VALIDATED_SOURCE_HEAD_SHA=4989797a44bde251b8fa134b9a2d1e805d54f9c2
BRANCH=fix/kiotviet-partner-debt-ledger-contract
DEBT_OFFSET_WRITE_MODE=legacy
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

## Backup and disposable databases

The immutable backup was verified before Docker started and was restored independently into the two requested schemas. The existing local database was not overwritten.

```text
BACKUP_PATH=D:\Kiot\kiotviet-clone\backups\kiot-db-backup-pr30-20260718-232331.sql.gz
BACKUP_SHA256=e9218b87771d6ea955a53680db7693161c803b7efc4df7a87e36885a025884a7
BACKUP_SHA256_MATCH=yes
BACKUP_SOURCE_ENGINE=MariaDB 10.11.10
CLONE_ENGINE=MariaDB 10.11.18
CLONE_1=kiot_partner_timeline_clone1
CLONE_2=kiot_partner_timeline_clone2
CLONE_1_CUSTOMERS=332
CLONE_2_CUSTOMERS=332
CLONE_MIGRATIONS=PASS (Nothing to migrate)
LOCAL_PRIMARY_DATABASE_OVERWRITTEN=no
```

The clone-only role repair plan contained exactly the two owner-confirmed codes. Both clones produced the same plan hash, changed only `is_customer` on `NCC177621742868`, and replayed idempotently. `NCC177950763826` was already dual-role.

```text
ROLE_REPAIR_PLAN_HASH=d09fb117473e3c6c07bdf60eb10a20cf37b1c3567527e5beb792ba91ff9b65e5
ROLE_REPAIR_ACTIONS=2
ROLE_REPAIR_ROWS_CHANGED_CLONE1=1
ROLE_REPAIR_ROWS_CHANGED_CLONE2=1
SECOND_ROLE_APPLY_ROWS_CHANGED_CLONE1=0
SECOND_ROLE_APPLY_ROWS_CHANGED_CLONE2=0
FINANCIAL_FIELDS_CHANGED_BY_ROLE_REPAIR=0
```

## Final 332-partner audit

The final audits use one canonical persisted-evidence event stream for domain reduction and both UI orientations. Generated timestamps were removed before hashing the artifacts.

```text
TOTAL_PARTNERS=332
DOMAIN_PARITY=332/332
CUSTOMER_VIEW_MISMATCHES=0
SUPPLIER_VIEW_MISMATCHES=0
DUAL_ROLE_CROSS_VIEW_MISMATCHES=0

CUSTOMER_LIST_SCOPE_MISMATCHES=0
SUPPLIER_LIST_SCOPE_MISMATCHES=0

CUSTOMER_VIEW_WARNINGS=0
SUPPLIER_VIEW_WARNINGS=0

CROSS_VIEW_EVENT_MISSING_COUNT=0
CROSS_VIEW_EVENT_EXTRA_COUNT=0
CROSS_VIEW_SIGN_MISMATCH_COUNT=0
CROSS_VIEW_ORDER_MISMATCH_COUNT=0
CROSS_VIEW_RUNNING_MISMATCH_COUNT=0

VIRTUAL_OPENING_USED=0
DISPLAY_ALIGNMENT_USED=0
STORED_BALANCE_EVENTS_USED=0
MIRROR_COUNTED_AS_FINANCIAL_EVENT_COUNT=0
REAL_AND_FALLBACK_DOUBLE_COUNT=0
CANCEL_REVERSAL_ASYMMETRY_COUNT=0

CLONE_1_NORMALIZED_ROWS_SHA256=717b844df18b1c6ac21d98ed2af96bcb012138b6cafe8b8fcc6c72813122ddfd
CLONE_2_NORMALIZED_ROWS_SHA256=717b844df18b1c6ac21d98ed2af96bcb012138b6cafe8b8fcc6c72813122ddfd
CLONE_1_NORMALIZED_SUMMARY_SHA256=7e19b0764183a9bc6cc395aa5f8d67d1da6bcfa2029ba50c7d56cd49396a2fa0
CLONE_2_NORMALIZED_SUMMARY_SHA256=7e19b0764183a9bc6cc395aa5f8d67d1da6bcfa2029ba50c7d56cd49396a2fa0
CLONE_RESULTS_IDENTICAL=yes
```

The audit still reports two role-evidence classifications which are outside the approved repair allowlist:

- partner 52, `NCC177466782297`: persisted `supplier_only`, evidence `dual_role`;
- partner 114, `NCC177650418017`: persisted `missing_role`, evidence `dual_role`.

No inference was used to mutate these rows. Consequently, the global command with `--fail-on-mismatch` exits 1 even though every financial, list, view and cross-view metric above passes.

```text
OWNER_CONFIRMED_ROLE_MISMATCHES=0
ROLE_FLAG_EVIDENCE_MISMATCHES=2
GLOBAL_AUDIT_EXIT=1 (role-integrity classifications only)
```

## Financial immutability

The role-repair command snapshots and revalidates both debt columns inside its transaction. After both repairs, the clone projections and all inspected financial-document tables have identical hashes/checksums.

```text
FINANCIAL_PROJECTION_SHA256_CLONE1=383be36f5eff3567937d57f4ff0dc2a703914109dab1013f013cf0066e307b89
FINANCIAL_PROJECTION_SHA256_CLONE2=383be36f5eff3567937d57f4ff0dc2a703914109dab1013f013cf0066e307b89
FINANCIAL_PROJECTION_ROWS_CHANGED=0
FINANCIAL_DOCUMENT_ROWS_CHANGED=0

INVOICES_CHECKSUM=2277981733
CASH_FLOWS_CHECKSUM=716680296
CUSTOMER_DEBTS_CHECKSUM=1935541774
PURCHASES_CHECKSUM=1806491685
PURCHASE_RETURNS_CHECKSUM=69864616
SUPPLIER_DEBT_TRANSACTIONS_CHECKSUM=3431385288
DEBT_OFFSETS_CHECKSUM=3274648040
```

## Exact cases

### `NCC177621742868`

The final coherent contract passes stored parity and warnings. It has seven events because the completed purchase return and its real supplier cash refund are separate persisted economic events (`-6,800,000` and `+6,800,000`).

```text
ROLE_AFTER_CLONE_REPAIR=dual_role
CUSTOMER_ENTRIES=7
SUPPLIER_ENTRIES=7
CUSTOMER_FINAL=0
SUPPLIER_FINAL=0
SOURCE_IDENTITIES_EQUAL=yes
SIGNS_SYMMETRIC=yes
RUNNING_BALANCES_SYMMETRIC=yes
CUSTOMER_WARNING=no
SUPPLIER_WARNING=no
NCC177621742868_STATUS=FAIL (does not match the contradictory requested 6 entries and +/-6,800,000 final)
```

The requested combination cannot coexist with the other mandatory acceptance rules:

- omitting the real refund produces six entries and `+6,800,000/-6,800,000`, but differs from the persisted target `0`, so both warnings must be `yes` under the warning contract;
- including the persisted refund produces domain parity and warnings `no`, but necessarily produces seven entries and final `0`;
- making six entries, final `+/-6,800,000` and warnings `no` simultaneously would require either changing the financial projection (explicitly forbidden) or masking a raw difference (also forbidden).

### `NCC177950763826`

The first divergence was duplicate adjustment evidence: real `DebtAdjustment` CashFlow documents and matching `customer_debts` mirrors were both counted. Exact source-code plus signed-amount matching now keeps the document and excludes its ledger mirror.

```text
ROLE_AFTER_CLONE_REPAIR=dual_role
FIRST_DIVERGENCE_IDENTIFIED=yes
MISSING_EVENT_COUNT_AFTER=0
EXTRA_EVENT_COUNT_AFTER=0
WRONG_SIGN_COUNT_AFTER=0
ORDER_MISMATCH_COUNT_AFTER=0
RUNNING_MISMATCH_COUNT_AFTER=0
CUSTOMER_WARNING_AFTER=no
SUPPLIER_WARNING_AFTER=no
NCC177950763826_STATUS=PASS
```

## Regression and engine gates

The relevant suite was executed against freshly migrated databases on both real engines. SQLite was not used for parity conclusions.

```text
MARIADB_VERSION=10.11.18-MariaDB-ubu2204
MARIADB_MIGRATIONS=PASS
MARIADB_TESTS=PASS (64 tests, 1015 assertions)

MYSQL_VERSION=8.0.44
MYSQL_MIGRATIONS=PASS
MYSQL_TESTS=PASS (64 tests, 1015 assertions)

CANONICAL_EVENT_TESTS=PASS
ORIENTATION_TESTS=PASS
ROLE_INTEGRITY_TESTS=PASS
LIST_SCOPE_TESTS=PASS
PAGINATION_TESTS=PASS
EXPORT_TESTS=PASS
AUDIT_TESTS=PASS

FRONTEND_BUILD=PASS (925 modules)
CHANGED_FILE_PINT=PASS (6 files)
PHP_LINT=PASS (6 files)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
```

## Final status

The code and the two restored clones prove zero financial/timeline drift across all 332 partners. Status remains `BLOCKED`, not `COMPLETE`, because the required exact values for `NCC177621742868` contradict the persisted projection and the non-masking warning contract, and because changing the two additional role-evidence rows was not authorized. The Draft PR may be updated with this evidence; it must not be merged or deployed.

All task containers were stopped after database validation. Their stopped volumes and ignored audit artifacts remain available for review.
