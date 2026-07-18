# All-partner debt parity and zero-drift validation

`DEBT_PARITY_ZERO_DRIFT_STATUS=BLOCKED`

This report records the validation performed from branch
`fix/all-partner-debt-parity-zero-drift`, based directly on
`0c35b3498437dd163f38625fee6df9b6342027ab`. No production system was
accessed or changed. The legacy debt-offset write mode remained enabled and
the workflow/outbox write path was not enabled.

## Result

The code path now has one document-event reducer contract, explicit gross and
net display semantics, deterministic event identities, evidence-only technical
ledger rows, no virtual balance masking, guarded repair plans, and transactional
mutation coordination with idempotency and invariant checks.

The supplied clone is not globally repairable without review. The final audit
still contains material drift and incomplete evidence, so no repair was
applied and completion must not be claimed.

## Immutable source backup

- Source engine: MySQL 8.0.44.
- Compressed dump:
  `backups/debt-parity-zero-drift-20260718-085838/database.sql.gz`.
- Dump SHA-256:
  `6beaece0b64ac66ba889fb9ca3ccd843755a947860724065c2ea4ed31ec26d67`.
- Gzip integrity: PASS.
- Source database container was stopped immediately after backup.
- Validation used clone container `kiot_debt_parity_clone1`; it was stopped at
  the end of validation.

## Final all-partner audit on clone 1

Population was the union of partner and debt/document sources, with special
statuses included. It was not derived from UI pagination.

| Metric | Count |
| --- | ---: |
| Eligible/scanned/exported | 322 |
| `OK` | 282 |
| Material drift | 21 |
| Insufficient evidence | 8 |
| Technical warnings | 11 |
| Audit errors | 0 |
| Critical risk | 8 |
| High risk | 11 |
| Medium risk | 21 |

Primary audit classifications:

| Classification | Count |
| --- | ---: |
| `DUAL_ROLE_NET_MISMATCH` | 3 |
| `DUPLICATE_REAL_AND_FALLBACK` | 5 |
| `INVOICE_RECEIPT_ALLOCATION_MISMATCH` | 2 |
| `PURCHASE_PAYMENT_ALLOCATION_EVIDENCE_MISSING` | 8 |
| `SUPPLIER_STORED_VS_DOCUMENT` | 11 |
| `TARGET_TYPE_ALIAS_SUSPECT` | 8 |
| `TECHNICAL_LEDGER_EXCLUDED` | 3 |
| `OK` | 282 |

Artifacts are intentionally ignored from Git and are available under
`storage/app/audits/debt-parity-zero-drift-final/`:

- `global-audit/audit.json`
- `global-audit/audit.csv`
- `invariants.json`
- `repair-plan.json`
- `repair-plan.csv`

## Material case

Partner `KH177561736414` resolves as customer-only:

| Field | Value |
| --- | ---: |
| Stored customer receivable | 5,870,000 |
| Canonical document receivable | 5,870,000 |
| Raw difference | 0 |
| Classification | `OK` |
| Risk | `OK` |

The current clone does not reproduce a 9,070,000 display value. The reducer
and UI contract both resolve the persisted evidence to 5,870,000 without a
virtual opening or running-balance shift.

## Guarded repair result

- Source audit SHA-256:
  `f5c2df1c00a69928d5df7cc3ace9d8cd408ad3cf4d5d13da92969bc798789da8`.
- Database fingerprint:
  `67b7da5d0a2fd032424f5399431ee0e2fb10ba41a1f92eae676a9a3225a7a68a`.
- Plan hash:
  `020522e17412427fb8e14969e21db85d5ac4a9c4b39daf62c3084346d39a74ea`.
- 322 plan rows: 282 no-action, 12 projection updates, 12 blocked
  uncertain-source rows, and 16 code-review rows.
- Guarded apply dry-run: 12 candidate repairs, 17 manual-review rows,
  `ROWS_CHANGED=0`.
- Real apply was deliberately refused because manual-review/blocking rows
  remain.

Checksums before and after dry-run were identical:

| Table | Checksum |
| --- | ---: |
| `customers` | 1388617867 |
| `cash_flows` | 2597322742 |
| `invoices` | 3308029825 |
| `purchases` | 1646691426 |
| `customer_debts` | 3203569091 |
| `supplier_debt_transactions` | 1872958141 |
| `debt_offsets` | 3236850791 |

## Code validation

- Targeted MySQL regression: PASS — 169 tests, 903 assertions, 1 skipped.
  Coverage includes canonical/audit/invariants, mutation atomicity and
  idempotency, invoice update/cancellation, sales returns, supplier display
  contracts, no-masking behavior, and debt-offset exact-once/failure paths.
- Debt-offset suite: PASS — 41 tests, 297 assertions.
- PHP syntax for all 37 changed PHP files: PASS.
- Pint on all changed PHP files: PASS (37 files).
- `npm run build`: PASS (Vite 5.4.21, 922 modules).
- `git diff --check`: PASS.
- Added-line private-key/token scan: PASS.
- Repository-wide Pint remains a pre-existing gate failure: 794 files checked,
  532 style issues and 2 errors outside this change scope. Changed files pass.
- A final full `php artisan test` run was not used as acceptance evidence;
  the focused 169-test dataset suite is green.

PHP emitted local extension warnings for unavailable OCI/Firebird modules;
these extensions are unrelated to the MySQL debt tests.

## Remaining blockers

- Clone 1 still has 21 material drift rows, 8 insufficient-evidence rows, and
  11 technical warnings.
- A real repair cannot run while 17 plan rows require manual review.
- Clone 2 replay and result-hash comparison were not completed.
- MariaDB 10.11 execution was not completed.
- The final full application test suite was not rerun to green.
- Draft PR publication depends on available GitHub authentication.

Until every blocker above is cleared and all acceptance counters are zero,
the only truthful release status is `BLOCKED`.
