# KiotViet partner debt timeline validation

```text
TASK_CODE=KIOTVIET-PARTNER-DEBT-TIMELINE-CONTRACT-01
KIOTVIET_PARTNER_LEDGER_STATUS=COMPLETE
PR_NUMBER=31
PR_DRAFT=yes
PR_MERGED=no
BASE_SHA=c2df609571d35738423df313137de94c5108a8c5
PREVIOUS_VALIDATED_SOURCE_SHA=65d302799c146fefb172dfed3ce4521c3be60d1a
VALIDATED_RUNTIME_SHA=6655de2b3286d750f0ea1f2288c5416b32f3af47
FINAL_PR_HEAD_SHA=pending-report-commit
REPORT_COMMIT_SHA=pending
PREVIOUS_TO_RUNTIME_DIFF_CLASSIFICATION=runtime_or_test
PREVIOUS_TO_RUNTIME_FILES_CHANGED=12
POST_VALIDATION_DIFF_CLASSIFICATION=docs_only
POST_VALIDATION_RUNTIME_FILES_CHANGED=0
GITHUB_ACTIONS_STATUS=NO_WORKFLOW_RUN
GITHUB_ACTIONS_HEAD_SHA=none
BRANCH=fix/kiotviet-partner-debt-ledger-contract
DEBT_OFFSET_WRITE_MODE=legacy
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

## Backup and disposable databases

The immutable backup was verified before Docker started and restored independently into the two requested schemas. The existing local database was not overwritten. Both task containers were stopped immediately after database validation.

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
TASK_CONTAINERS_STOPPED=yes
```

`VALIDATED_RUNTIME_SHA` is the exact commit containing every runtime and test file used by the final engine and clone validation. The successor report commit changes only `docs/**`; its exact SHA is resolved in the Draft PR body and final agent output instead of creating a self-referential follow-up commit solely to rewrite its own hash.

The restored role flags are authoritative for runtime UI scope. `NCC177621742868` is supplier-only and is intentionally absent from the repair allowlist. The current dataset has no owner-confirmed mismatch requiring a repair, so the clone-only dry-run plan is empty and no role row was changed.

```text
ROLE_REPAIR_PLAN_HASH=4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945
ROLE_REPAIR_ACTIONS=0
ROLE_REPAIR_ROWS_CHANGED_CLONE1=0
ROLE_REPAIR_ROWS_CHANGED_CLONE2=0
SECOND_ROLE_APPLY_ROWS_CHANGED=0
FINANCIAL_FIELDS_CHANGED_BY_ROLE_REPAIR=0
```

## P0-A — supplier-only UI scope

All customer collection paths apply the persisted `is_customer=true` scope before search, aggregate, pagination, export and autocomplete. Customer route-model binding applies the same scope to detail, debt and timeline endpoints. Frontend filtering is defense-in-depth only; the API/query layer is authoritative.

The restored clone and the fixture regression both passed the exact-code, keyword, pagination, export, autocomplete, aggregate, direct-route and supplier-route checks.

```text
CODE=NCC177621742868
PERSISTED_ROLE=supplier_only
is_customer=false
is_supplier=true

CUSTOMER_EXACT_SEARCH_RESULT_COUNT=0
CUSTOMER_LIST_VISIBLE=no
CUSTOMER_DEBT_CONTRIBUTION=0
CUSTOMER_TOTAL_DEBT_CONTRIBUTION=0
CUSTOMER_DETAIL_ACCESS=DENIED
CUSTOMER_TIMELINE_AVAILABLE=no
CUSTOMER_ACTION_BUTTONS_AVAILABLE=no
DUAL_ROLE_BADGE=no

SUPPLIER_EXACT_SEARCH_RESULT_COUNT=1
SUPPLIER_LIST_VISIBLE=yes
SUPPLIER_DETAIL_ACCESS=ALLOWED
SUPPLIER_TIMELINE_AVAILABLE=yes

NCC177621742868_ROLE_REPAIR_ROWS_CHANGED=0
ROLE_UI_SCOPE_STATUS=PASS
```

The inverse role boundary is also fail-closed: customer-only partners receive 404 from supplier route-model binding and every supplier purchase/debt/detail/export/payment/adjustment API. Runtime UI scope and both badges are derived only from the two persisted role flags.

The former customer-screen value `+6800000` came from applying a dual-role/net display contract to supplier-only data. The role-aware display contract now returns zero for a non-customer, and no supplier balance, canonical net, evidence role, history or code prefix can make that row customer-applicable.

## P0-B — supplier financial timeline

The supplier stream retains all seven persisted economic events. The completed purchase return and the real supplier cash refund are distinct persisted events (`-6,800,000` and `+6,800,000`) and must not be collapsed to manufacture six entries.

```text
CODE=NCC177621742868
SUPPLIER_ENTRY_COUNT=7
SUPPLIER_SOURCE_IDENTITIES_SHA256=da348e7f07504f5afed2b5cd2834dc546f2772b6c158bd8461af1285533d7a1d

01 supplier refund cash_flows:451          +6800000
02 purchase return purchase_returns:6      -6800000
03 supplier payment cash_flows:343        -57800000
04 purchase purchases:242                 +57800000
05 purchase purchases:168                 +13600000
06 supplier payment cash_flows:179        -34000000
07 purchase purchases:167                 +20400000

RETURN_CODE=PTN20260521153018
RETURN_PURCHASE_ID=167
RETURN_AMOUNT=6800000
REFUND_CODE=PT20260521153219
REFUND_REFERENCE_TYPE=PurchaseReturn
REFUND_REFERENCE_CODE=PTN20260521153018

FINAL_CANONICAL_SUPPLIER_BALANCE=0
STORED_SUPPLIER_PROJECTION=0
SUPPLIER_STORED_VS_CANONICAL=0
SUPPLIER_WARNING=no
CUSTOMER_ORIENTATION_CREATED=no
SUPPLIER_TIMELINE_PARITY=PASS
SUPPLIER_FINANCIAL_PARITY_STATUS=PASS
```

## P0-C — genuine dual-role symmetry

Cross-view symmetry was evaluated only for partners whose persisted flags are both true. For `NCC177950763826`, matching adjustment-ledger mirrors remain reference evidence while the real CashFlow documents remain financial events.

```text
CODE=NCC177950763826
PERSISTED_ROLE=dual_role
CUSTOMER_ENTRY_COUNT=21
SUPPLIER_ENTRY_COUNT=21
CUSTOMER_SOURCE_IDENTITIES_SHA256=76009d1e9182a905e2eddbff924d7351c0e943dacfeda34888335027ec83d025
SUPPLIER_SOURCE_IDENTITIES_SHA256=76009d1e9182a905e2eddbff924d7351c0e943dacfeda34888335027ec83d025

CUSTOMER_AND_SUPPLIER_EVENT_IDENTITIES_EQUAL=yes
CUSTOMER_DELTA_EQUALS_NEGATIVE_SUPPLIER_DELTA=yes
CUSTOMER_RUNNING_EQUALS_NEGATIVE_SUPPLIER_RUNNING=yes
EVENT_MISSING_COUNT=0
EVENT_EXTRA_COUNT=0
DUPLICATE_MIRROR_COUNT=0
SIGN_MISMATCH_COUNT=0
ORDER_MISMATCH_COUNT=0
RUNNING_MISMATCH_COUNT=0
CUSTOMER_WARNING=no
SUPPLIER_WARNING=no
NCC177950763826_STATUS=PASS
DUAL_ROLE_TIMELINE_PARITY_STATUS=PASS
```

## P0-D — final 332-partner audit

Both independent restores produced identical normalized artifacts. Financial parity, list scope, applicable UI orientation and genuine dual-role symmetry are all zero-drift.

```text
TOTAL_PARTNERS=332
PERSISTED_CUSTOMERS=280
PERSISTED_SUPPLIERS=66
PERSISTED_DUAL_ROLE=15

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

CLONE_1_NORMALIZED_AUDIT_SHA256=329629721e7ba56cc87077bc6ea5f9071fd4b347bb6fe2e62e8ab6eddfe9bf99
CLONE_2_NORMALIZED_AUDIT_SHA256=329629721e7ba56cc87077bc6ea5f9071fd4b347bb6fe2e62e8ab6eddfe9bf99
CLONE_1_NORMALIZED_SUMMARY_SHA256=133e9d0c8644cf06fa3bb7166326dbcb9d85d2829491addc867f60ae869538f1
CLONE_2_NORMALIZED_SUMMARY_SHA256=133e9d0c8644cf06fa3bb7166326dbcb9d85d2829491addc867f60ae869538f1
CLONE_RESULTS_IDENTICAL=yes
```

Role integrity is reported separately from runtime scope and financial parity. Two unapproved evidence classifications remain review-only:

- partner 52, `NCC177466782297`: persisted `supplier_only`, evidence `dual_role`;
- partner 114, `NCC177650418017`: persisted `missing_role`, evidence `dual_role`.

No evidence inference was used to mutate either row. The global `--fail-on-mismatch` command therefore exits 1 solely for these review classifications; it does not indicate financial, list, UI-view or timeline drift.

```text
OWNER_CONFIRMED_ROLE_MISMATCHES=0
ROLE_FLAG_EVIDENCE_MISMATCHES=2
GLOBAL_AUDIT_EXIT=1 (role-integrity review classifications only)

ROLE_UI_SCOPE_STATUS=PASS
SUPPLIER_FINANCIAL_PARITY_STATUS=PASS
DUAL_ROLE_TIMELINE_PARITY_STATUS=PASS
ROLE_INTEGRITY_REVIEW_STATUS=REVIEW_REQUIRED
ROLE_INTEGRITY_REVIEW_ITEMS=2
```

## Financial immutability

All audit and exact-case validation was read-only. No financial projection or document row was changed.

```text
FINANCIAL_PROJECTION_ROWS_CHANGED=0
FINANCIAL_DOCUMENT_ROWS_CHANGED=0
ROLE_REPAIR_ROWS_CHANGED_CLONE1=0
ROLE_REPAIR_ROWS_CHANGED_CLONE2=0
```

## Regression and engine gates

The relevant suite was executed on both real engines. SQLite was not used for parity conclusions.

```text
MARIADB_VERSION=10.11.18-MariaDB-ubu2204
MARIADB_MIGRATIONS=PASS
MARIADB_TESTS=PASS (63 tests, 1119 assertions)

MYSQL_VERSION=8.0.44
MYSQL_MIGRATIONS=PASS
MYSQL_TESTS=PASS (63 tests, 1119 assertions)

CANONICAL_EVENT_TESTS=PASS
ORIENTATION_TESTS=PASS
ROLE_INTEGRITY_TESTS=PASS
LIST_SCOPE_TESTS=PASS
PAGINATION_TESTS=PASS
EXPORT_TESTS=PASS
AUDIT_TESTS=PASS

SUPPLIER_ROUTE_SCOPE_TESTS=PASS
FRONTEND_BUILD=PASS (922 modules)
CHANGED_FILE_PINT=PASS (39 files)
PHP_LINT=PASS (39 files)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
DEBUG_OUTPUT_SCAN=PASS
REPORT_CONSISTENCY_CHECK=PASS
```

## Final PR diff gate

The complete diff from `production-customer-group` to the validated runtime commit was reviewed. Customer and supplier collection queries scope persisted roles before search, aggregate and pagination. Direct customer and supplier routes fail closed on the matching persisted flag. Evidence classification remains audit-only. Canonical reduction contains no stored-projection event, virtual opening or display alignment, and the full clone audit reports no mirror/fallback/reversal defect.

```text
PR31_EXACT_HEAD_AUDIT=PASS
WORKTREE_CLEAN_AT_RUNTIME_COMMIT=yes
MIGRATION_CREATED=no
DEPENDENCY_LOCK_CHANGED=no
PRODUCTION_CONFIG_CHANGED=no
PRODUCTION_CREDENTIAL_FOUND=no
HARDCODED_PRODUCTION_PATH_FOUND=no
```

## Final status

The ordered P0-A, P0-B, P0-C and P0-D execution is complete. The supplier-only regression is excluded at the query/API/aggregate layer, its seven-event supplier timeline is financially exact, the genuine dual-role regression is symmetric, and both independent 332-partner restores agree. The two role-evidence classifications are explicitly retained for owner review and were not repaired.

Draft PR #31 may be updated with this evidence. It must not be merged, deployed or used to access production.
