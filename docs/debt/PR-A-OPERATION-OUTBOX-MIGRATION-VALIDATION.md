# PR A Operation, Participant, and Outbox Migration Validation

## Scope and revision

- Branch: `feat/debt-integrity-pr-a-operation-outbox-schema`
- Base branch: `production-customer-group`
- Base SHA: `6a058abf051ea01b239b2af2fd1c949fde0c7517`
- Initial implementation commit: `947d1ce1a4f0a68b8760d4522b771941b6a54609`
- Corrected implementation commit: `4b5e36745c6eebdd0670f288bd112a5904a85593`
- Authoritative design: `docs/debt/PHASE-2-DEBT-INTEGRITY-SCHEMA-AND-WRITE-PATH-DESIGN.md`
- Scope: three additive tables, MySQL schema tests, design clarification, and this report
- Application code, models, services, controllers, commands, jobs, workers, schedules, and feature flags changed: no

## Environment

- Database: local Docker production-like snapshot; not a production database
- MySQL: `8.0.44`
- Server charset/collation: `utf8mb4` / `utf8mb4_unicode_ci`
- JSON support: yes
- CHECK constraints enforced: yes
- PHP: `8.2.29`
- Fresh database logical name: `kiot_pr_a_fresh_<redacted-suffix>`
- Clone database logical name: `kiot_pr_a_clone_<redacted-suffix>`
- Production accessed: no

Local PHP emits startup warnings for unavailable Oracle and Firebird extensions.
Neither extension is used by this Laravel/MySQL validation. The executed commands
and PHPUnit processes returned exit code zero after the worktree-local dependency
and environment harness was configured.

## Migration inventory

1. `2026_07_15_150000_create_partner_debt_operations_table.php`
2. `2026_07_15_150100_create_partner_debt_operation_participants_table.php`
3. `2026_07_15_150200_create_partner_debt_outbox_events_table.php`

Migration status after the final remigrate showed all three migrations as `Ran`
in batch 60 on the temporary clone. Each new table contained zero rows.

### `partner_debt_operations`

- Columns: 22, including bigint auto-increment primary key, UUID and request
  identity, source pair, reversal reference, JSON result/metadata, audit actor,
  UTC application datetime fields, and precision-6 timestamps.
- Unique keys: `pdo_operation_uuid_uq`, `pdo_type_idempotency_uq`,
  `pdo_reverses_uq`.
- Lookup indexes: `pdo_partner_initiated_idx`, `pdo_source_idx`.
- Foreign keys: `pdo_partner_fk` RESTRICT, `pdo_reverses_fk` RESTRICT,
  `pdo_initiated_by_fk` SET NULL.
- Enforced CHECK constraints: `pdo_status_chk`, `pdo_source_pair_chk`,
  `pdo_attempt_chk`.

### `partner_debt_operation_participants`

- Columns: 9, including operation/partner references, participant/effect roles,
  signed decimal customer/supplier deltas, and precision-6 timestamps.
- Unique key: `pdop_op_partner_role_uq`.
- Lookup indexes: `pdop_partner_operation_idx`, `pdop_operation_effect_idx`.
- Foreign keys: `pdop_operation_fk` RESTRICT, `pdop_partner_fk` RESTRICT.
- Enforced CHECK constraint: `pdop_effect_shape_chk`.
- Every non-null effect role explicitly enforces its required null shape. In
  particular, `effect_role = 'none'` requires both deltas to be non-null and
  exactly `0.00`, so SQL `UNKNOWN` cannot satisfy the CHECK.

`PARTICIPANT_CHECK_THREE_VALUED_LOGIC_REVIEW=PASS`

`NONE_EFFECT_NULL_DELTA_REJECTED=yes`

The initial validation hardened the outer null-role branch but did not explicitly
guard null deltas inside the `none` branch. Senior review identified this P1 gap
before acceptance. Commit `4b5e36745c6eebdd0670f288bd112a5904a85593`
added both `IS NOT NULL` predicates, exact `CHECK_CLAUSE` verification, and direct
MySQL probes for `(NULL, 0.00)`, `(0.00, NULL)`, and `(NULL, NULL)`.

### `partner_debt_outbox_events`

- Columns: 25, including event/operation identity, versioned JSON payload,
  retry/lease state, bounded error text, dead-letter resolution, and precision-6
  timestamps.
- Unique key: `pdoe_event_uuid_uq`.
- Lookup indexes: `pdoe_due_claim_idx`, `pdoe_operation_event_idx`,
  `pdoe_published_idx`.
- Foreign keys: `pdoe_operation_fk` RESTRICT, `pdoe_resolved_by_fk` SET NULL.
- Enforced CHECK constraints: `pdoe_status_chk`,
  `pdoe_schema_version_chk`.

The maximum explicit index/constraint identifier length is 26 characters, below
the MySQL 64-character limit.

## MySQL auto-increment CHECK decision

`MYSQL_AUTO_INCREMENT_CHECK_LIMITATION=CONFIRMED`

MySQL 8.0.44 rejected the original proposed check with error 3818 because a
CHECK constraint cannot reference an `AUTO_INCREMENT` column. The approved
resolution is:

- `PDO_NO_SELF_REVERSE_CHECK=REMOVED`
- `PRIMARY_KEY_STRATEGY=BIGINT_UNSIGNED_AUTO_INCREMENT_UNCHANGED`
- `TRIGGER_USED=no`
- `STORED_PROCEDURE_USED=no`
- self foreign key retained with `ON DELETE RESTRICT`
- `UNIQUE(reverses_operation_id)` retained

`SELF_REVERSAL_DATABASE_ENFORCEMENT=no`

The database still guarantees reference integrity, restricts deletion of a
referenced operation, and permits at most one reversal per original operation.
Before any write path is enabled, the application service must enforce no
self-reversal, no reversal cycle, committed/non-reversed original state, allowed
operation type, row locking, and one outer transaction. That application work is
explicitly deferred to a later write-path PR.

## Fresh database proof

The final migrations were run from zero on a newly created temporary MySQL
database. No `migrate:fresh` command was used.

| Gate | Result |
|---|---|
| Fresh migrate from zero | PASS |
| Schema tests | PASS, 12 tests / 441 assertions |
| Invalid `none` null probes | PASS, MySQL error 3819 from `pdop_effect_shape_chk` |
| Rollback last three migrations | PASS |
| PR A tables after rollback | 0 |
| Remigrate | PASS |
| Schema tests after remigrate | PASS, 12 tests / 441 assertions |
| Second migrate | PASS, `Nothing to migrate` |

## Production-like clone proof

The source was a local Docker snapshot. It was read using a single-transaction
dump and restored to a new temporary schema. The source database was not
altered. The clone baseline contained 105 legacy tables excluding `migrations`
and the PR A tables, and 160 migration records.

- Baseline table-list SHA-256:
  `e2e152ea57e849ee93aa3fc406007a0c917e3487b5ac39b91cf2e56e903be99a`
- Baseline migration-list SHA-256:
  `283b17efd6f37685dcf416a6b45093ddf9f41274ac450cf6c7916ef4018ef00c`

Only the three PR A migrations ran. The three new tables each had an exact row
count of zero. Schema tests passed before rollback. Rollback removed exactly the
three PR A tables, and remigrate recreated them.

| Gate | Result |
|---|---|
| Clone migrate | PASS |
| Clone schema tests | PASS, 12 tests / 441 assertions |
| Invalid `none` null probes | PASS, MySQL error 3819 from `pdop_effect_shape_chk` |
| Legacy row counts unchanged | yes |
| Legacy data hashes unchanged | yes |
| Legacy normalized schema hashes unchanged | yes |
| Clone rollback | PASS |
| PR A tables after rollback | 0 |
| Clone remigrate | PASS |
| Source snapshot mutated | no |

## Legacy deterministic evidence

Data SHA-256 was calculated from `mysqldump` rows using UTF-8, stable table
column order, primary-key order, explicit SQL NULLs, one INSERT per row, and
hex-encoded binary values. Schema SHA-256 used compact `--no-data` output and
normalized only the volatile `AUTO_INCREMENT=<counter>` table option. The same
clone baseline values were observed after migrate, rollback, and remigrate.

| Table | Rows | Data SHA-256 | Normalized schema SHA-256 |
|---|---:|---|---|
| `customers` | 322 | `cf51cc91cdc2412e8fd8210236e03d42d132873716a3ba7834f2bc4b041979be` | `4c0f0eefa5d22e027829a4155f740e7052026901a3475ef2bcb050ead8a632f2` |
| `users` | 6 | `2149ef4f0e6b4889a0947481f2ffe70ead45703e67795c61710d9a6dc9b9f62d` | `16baa2890e6943d97ba1d64025b8aebc86b1cca314139e3b787e0da1addadae0` |
| `cash_flows` | 745 | `4e252d0f1eb28073c6a5dc01ea540a0a196ace910bcf8953b1c5b70a0ebfbc71` | `cc3f850af67e36c0b56727ea415cc5dc0101665a1cafb664fb5510705e49fe6d` |
| `invoices` | 381 | `c9fb4c919c929fcd6f7ca71ffa99c1e88dfaf081810884a4e9b83823058c1827` | `33c0f3c8f5adfcb7ed29e3328131eebb897c7488af11ec05e513e9a1116bcbc4` |
| `purchases` | 428 | `b83d6beb5d13bea34f27d462c2c3968732024937fc023e02eb83158e5e7a8889` | `f9f7d1b5377bb275466932ea292903ff7fadc965ad5864b8b5e74beae91cd62d` |
| `customer_debts` | 83 | `cf61ccbaf2fe25469f4fc24db2b6590ab17900056fb717e8ddb58da0d76ef991` | `457965234719fea0f1acd2ee548481c0220ef3b3a886b1ed656254d1d0175f19` |
| `supplier_debt_transactions` | 233 | `d99e17a04b2679dd1dda8885baba9688e67be7e666e9551f6475ed44d6ce0e7b` | `fc9979041d960d69e0e9c50a6f09cba51da7b1a5f9b2b9078dde283c51013c81` |
| `customer_payment_allocations` | 3 | `9c650eee7a0135cb9d65741ac448e3e786627ff3e221b41154453e2bcc57d77a` | `7f6edbf16e6e9d3c943e0c580963ecc3c8cb20a2ce58eb058db0de918c90d24c` |
| `debt_offsets` | 1 | `8e18447cb2b404846aad3838b08566e5562d902919c314355a45cdeff94b74ed` | `33277a38129e691a509fdc8f987cdefd246e40c211623672fc42f3285b5186e6` |

## Automated validation

- PHP lint: PASS for all three migrations and the schema test.
- Focused schema tests: PASS, 12 tests / 441 assertions.
- Phase 1 debt regression: PASS, 73 tests / 401 assertions.
- `git diff --check`: PASS.
- Forbidden-file scan: PASS.
- Frontend build: not required; no frontend or application code changed.

The Phase 1 regression covered canonical debt balances, invariant checking,
parity audit classification, raw screen fixtures, the read-only invariant
command, reconciliation report/export, and supplier timeline parity.

## DDL and rollback risk

- Legacy table rebuild expected: no.
- Legacy row scan for modification expected: no.
- Foreign-key metadata locks on `customers` and `users`: expected to be short,
  but production timing still requires a later preflight.
- Atomicity: MySQL DDL has implicit commits; the three migrations are not
  claimed to be one transaction.
- Failure handling: dependency order, empty new tables, independently reversible
  migrations, and tested reverse-order rollback.
- Rollback removes only outbox, participants, then operations. No legacy table or
  row is removed.

## Findings and readiness

- P0 blockers: 0.
- P1 blockers: 0.
- P2 follow-up: application-level self-reversal and cycle prevention is required
  before enabling any operation write path.
- P1 found and resolved before acceptance:
  `PARTICIPANT_NONE_EFFECT_NULL_DELTA_CAN_PASS_CHECK`.
- The exact `pdop_effect_shape_chk` clause is verified through
  `information_schema.CHECK_CONSTRAINTS`; all three invalid `none` null shapes are
  rejected with MySQL error 3819 and the expected constraint name.
- Current data correction readiness: no; this PR does not inspect or mutate
  current debt balances.
- Senior acceptance decision: `ACCEPTED_FOR_MIGRATION_PR_A_SCHEMA_SCOPE`.
- Production deployment is not part of this step; a separate production preflight
  is still required before any migration is run.
- Ready for PR A senior review: yes.
- Ready to mark PR A ready: yes.
- Ready to merge PR A: yes, using the exact reviewed head and a merge commit.
- Ready for write-path application PR: no.

## Data safety declaration

- Migration run on production: no.
- Production accessed: no.
- Production data changed: no.
- Local source snapshot changed: no.
- Backfill/seed: no.
- Existing debt data update/delete: no.
- Deployment: no.
