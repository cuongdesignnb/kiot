# KiotViet partner debt timeline validation

```text
TASK_CODE=KIOTVIET-PARTNER-DEBT-TIMELINE-CONTRACT-01
STATUS=BLOCKED
BLOCKER=LATEST_PRODUCTION_BACKUP_COPY_REQUIRED
BASE_SHA=c2df609571d35738423df313137de94c5108a8c5
BRANCH=fix/kiotviet-partner-debt-ledger-contract
EXPECTED_SOURCE_BACKUP_SHA256=e9218b87771d6ea955a53680db7693161c803b7efc4df7a87e36885a025884a7
PRODUCTION_ACCESSED=no
PRODUCTION_DATABASE_CHANGED=no
PRODUCTION_DEPLOYED=no
```

## Backup gate

No local SQL archive inspected at task start matched the mandatory source SHA-256. In particular, the newest visible archive `D:\Kiot\kiotviet-clone\kiot.sql.zip` hashed to `026c7f510a5202eb7cf205150736c4fb2c9f0fc105a6ee4a274aa81cb91d2784`.

Per the P0 contract, no older snapshot may substitute for the immutable backup and no 332-partner conclusion may be published. Docker has not been started for this task while this gate is unresolved.

## Code validation completed without database containers

- Base and worktree start were verified at the required SHA.
- `CanonicalPartnerDebtEventService` is the only combined event stream.
- Customer, supplier and partner financial timeline public services are adapters.
- Stored projections are absent from the event reducer.
- Dual-role orientations use the same event identities and compute opposite signs/running balances.
- Customer list and export root queries are constrained by `is_customer=true`; supplier paths retain `is_supplier=true`.
- Financial and role-integrity warnings are separate.
- Invoice/POS sale, invoice cancellation, order conversion, returns/exchanges,
  customer payments/adjustments/discounts, supplier purchases/returns and
  partner-targeted standalone cash flows now use the shared mutation
  coordinator. Persisted roles are validated under the partner row lock;
  document/evidence/projection writes and the canonical invariant share one
  transaction; retryable HTTP submissions send a stable `Idempotency-Key`.
- Standalone supplier receipts are explicit persisted refund/credit events;
  targeted cash flows cannot be edited in place and cancellation reverses the
  exact stored side inside the coordinator.
- Clone-only role-repair command accepts only the two owner-confirmed codes and only the two disposable database names.
- No migration was added.

Current database-free test gate:

```text
CANONICAL_UNIT_TESTS=PASS
TESTS=13
ASSERTIONS=756
CHANGED_PHP_LINT=PASS (33 files)
CHANGED_PHP_PINT=PASS (33 files)
FRONTEND_BUILD=PASS (925 modules)
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
DOCKER_RUNNING_CONTAINERS=0
DB_FEATURE_TESTS_DISCOVERED=6
DB_FEATURE_TESTS_EXECUTED=0 (mandatory backup gate)
GOLDEN_KIOTVIET_FIXTURE_ROWS=232
GOLDEN_FIXTURE_PAGE_13_IDENTITIES_EQUAL=yes
GOLDEN_FIXTURE_SIGNS_SYMMETRIC=yes
GOLDEN_FIXTURE_RUNNING_SYMMETRIC=yes
```

The report remains `BLOCKED` until the exact backup is supplied, both disposable clones are restored, the owner-confirmed role plan is dry-run/applied/replayed, all 332 partners pass five-layer audit, and MariaDB/MySQL/full frontend/static gates run successfully.
