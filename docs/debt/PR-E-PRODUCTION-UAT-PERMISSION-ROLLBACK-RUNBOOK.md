# PR E Production UAT, Permission and Rollback Runbook

## Document control

```text
TASK_CODE=RR-DEBT-OFFSET-PRODUCTION-UAT-01
REPOSITORY=cuongdesignnb/kiot
BASE_BRANCH=production-customer-group
AUDITED_SHA=f47ef3a1419c9291faf72b730ee8d54b85ddec96
DOCUMENT_SCOPE=production UAT permission backup emergency freeze reversal monitoring and rollback runbook
PRODUCTION_SECURITY_DEPLOYMENT_VERIFIED=yes
PRODUCTION_ACCESSED=no
PRODUCTION_COMMAND_EXECUTED=no
```

This revision incorporates the supplied production deployment evidence for the
exact SHA above; the Agent did not access production to re-check it. This document
does not itself authorize workflow enablement, migration, database correction, or
financial UAT. Every production command below is for a future operator to run one
at a time after its stated approval gate has been satisfied.

## A. Executive status

```text
PRODUCTION_CODE_DEPLOYED=yes
PRODUCTION_SHA=f47ef3a1419c9291faf72b730ee8d54b85ddec96

DEBT_OFFSET_WORKFLOW_CODE_DEPLOYED=yes
ROLE_USER_API_SECURITY_DEPLOYED=yes

WORKFLOW_CURRENTLY_ENABLED=no
EFFECTIVE_WRITE_MODE=legacy

MIGRATION_REQUIRED=no
SCHEMA_READY=yes
FRONTEND_READY=yes
ROUTES_READY=yes

SAFE_PERMISSION_PATH_IDENTIFIED=yes
PERMISSION_CONFIGURATION_STATUS=READY

READY_FOR_PERMISSION_CONFIGURATION=yes
READY_FOR_NON_FINANCIAL_UAT=no
READY_FOR_FINANCIAL_WRITE_UAT=no
READY_FOR_FULL_PRODUCTION_ENABLEMENT=no
```

The remaining operational gates are not complete:

```text
UAT_ROLES_CONFIGURED=no
UAT_ACTORS_SELECTED=no
UAT_PARTNER_SELECTED=no
UAT_AMOUNT_APPROVED=no
FRESH_UAT_BACKUP_CREATED=no
NON_FINANCIAL_UAT_COMPLETED=no
```

## Closed security blocker

```text
PREVIOUS_BLOCKER=ROLE_API_MISSING_AUTHENTICATION_AND_AUTHORIZATION
SECURITY_PR_NUMBER=29
SECURITY_PR_HEAD=8381ce3c122f6b08c925cb9c992ea752fe607064
SECURITY_PR_MERGE_SHA=f47ef3a1419c9291faf72b730ee8d54b85ddec96
SECURITY_PR_MERGED=yes
SECURITY_CODE_DEPLOYED=yes
PRODUCTION_ACCEPTANCE=PASS
BLOCKER_STATUS=CLOSED
```

The protected Role/User administration APIs now require `auth:sanctum` and exact
`roles.view/create/edit/delete` or `users.view/create/edit/delete` permission
middleware. Unknown permission keys, wildcard or `role_id=null` escalation,
non-admin management of Admin accounts, self-lock and self-delete are rejected.
Stateful browser-session Sanctum remains supported. The supplied production smoke
evidence reports HTTP `401` for unauthenticated Role and User API requests.

Supplied production deployment evidence:

```text
PRODUCTION_SHA=f47ef3a1419c9291faf72b730ee8d54b85ddec96
PRODUCTION_BRANCH=production-customer-group
WORKTREE_CLEAN=yes
ROLE_API_SECURITY=PASS
USER_API_SECURITY=PASS
ROLE_API_UNAUTHENTICATED_HTTP=401
USER_API_UNAUTHENTICATED_HTTP=401
ROLE_PERMISSION_MIDDLEWARE=PASS
USER_PERMISSION_MIDDLEWARE=PASS
PHP_LINT=PASS
ROOT_HTTP=302
LOGIN_HTTP=200
MYSQL_SERVICE=active
TMP_STATE=1777 root:root
MIGRATION_EXECUTED=no
DATABASE_CHANGED=no
PERMISSION_CHANGED=no
EFFECTIVE_WRITE_MODE=legacy
WORKFLOW_ENABLED=no
```

Non-blocking, tracked finding:

```text
OUTBOX_PUBLISHER_IMPLEMENTED=no
OUTBOX_WORKER_ENABLED=no
```

The workflow writes durable pending outbox rows, but no publisher, scheduled
command, job, or external dispatch path exists at this revision.

## Audit evidence

| Contract | Source evidence |
|---|---|
| Mode env/config/default | `config/debt.php`: `DEBT_OFFSET_WRITE_MODE`, `debt.offsets.write_mode`, default `legacy` |
| Mode guards | `DebtOffsetWriteMode::current`, `assertLegacyAllowed`, `assertWorkflowAllowed` |
| Legacy writes | `CustomerController::debtOffset`, `cancelDebtOffset`; all public methods in `DebtOffsetService` call `assertLegacyAllowed` |
| Workflow writes | `DebtOffsetWorkflowService` calls `assertWorkflowAllowed` before every workflow command |
| State machine | `DebtOffsetStateMachine::TRANSITIONS` |
| Route RBAC | `routes/web.php` debt-offset routes and `CheckPermission` middleware |
| Request validation | `app/Http/Requests/DebtOffsets/*`; `authorize()` returns `true`, so RBAC is not in FormRequest |
| Four-eyes | `DebtOffsetWorkflowService::approve`; `SELF_APPROVAL_FORBIDDEN` |
| Branch scope | `DebtOffsetWorkflowController::applyReadBranchScope`, `assertReadBranchScope`; `DebtOffsetWorkflowService::assertPartnerEligible` |
| UI mode/RBAC | `HandleInertiaRequests`, `AppLayout.vue`, `Customers/Index.vue`, `DebtOffsetActionPanel.vue` |
| Role wildcard | `Role::hasPermission`, `User::isAdmin`, `User::hasPermission` |
| Outbox status | `DebtOffsetWorkflowService::commitEvidence` creates `status=pending`, `attempts=0` |
| Publisher absence | No publisher/worker reference outside workflow row creation, model, migration, tests, and design documents; no outbox schedule in `routes/console.php` |
| Prior validation | `docs/debt/PR-E-DEBT-OFFSET-WORKFLOW-WRITE-PATH-VALIDATION.md` |

## B. Mode behavior matrix

```text
WRITE_MODE_ENV_KEY=DEBT_OFFSET_WRITE_MODE
WRITE_MODE_CONFIG_KEY=debt.offsets.write_mode
WRITE_MODE_DEFAULT=legacy

SUPPORTED_MODE_LEGACY=yes
SUPPORTED_MODE_WORKFLOW=yes
SUPPORTED_MODE_DISABLED=yes
UNKNOWN_MODE_FAILS_CLOSED=yes_for_writes
```

| Mode | Legacy endpoint | Workflow endpoint | UI workflow | Financial write |
|---|---|---|---|---|
| `legacy` | Legacy create/cancel and `DebtOffsetService` writes are allowed, subject to their existing route permission and validation. | Index/show remain readable with `debt_offsets.view`; all workflow mutation commands return HTTP `403`, `DEBT_OFFSET_WORKFLOW_DISABLED`. | Navigation and workflow-create button are hidden. A direct visit to index is read-only and shows the current mode. Action panel is empty. | Legacy financial write remains allowed; workflow apply/reverse is blocked. |
| `workflow` | Legacy create/cancel and service calls return HTTP `409`, `LEGACY_DEBT_OFFSET_WRITE_DISABLED`. | Reads are allowed by view permission and branch scope. Mutations are allowed only by route permission, state, version, idempotency, partner, branch and service guards. | Navigation requires `debt_offsets.view`; create requires `debt_offsets.create`; action buttons require mode, state, and their exact permission. | Only workflow apply/reverse change balances. Draft/update/submit/approve/reject/void write workflow evidence but do not change partner balances. |
| `disabled` | All legacy writes return HTTP `403`, `DEBT_OFFSET_WORKFLOW_DISABLED`. | Workflow writes return HTTP `403`, `DEBT_OFFSET_WORKFLOW_DISABLED`. Index/show remain read-only for authorized users; index reports `disabled`. | Navigation/create/actions are hidden; a direct authorized index visit is read-only. | No debt-offset financial write is permitted. |
| unknown value | `current()` rejects it with HTTP `403`, `DEBT_OFFSET_WORKFLOW_DISABLED`. | All workflow writes and index fail closed through `current()`/`assertWorkflowAllowed`. `show()` is a read-only exception: it does not resolve mode and remains protected by auth, view permission and branch scope. | Shared mode is not `workflow`, so navigation/create/actions are hidden. | No debt-offset financial write is permitted. |

Important interpretation:

- `disabled` is the emergency write freeze. It blocks both legacy and workflow
  writes; it does not remove authorized read access.
- Unknown values fail closed for writes. The runbook never uses an unknown value.
- Workflow routes always exist. Mode enforcement is inside the service, not route
  registration.
- `index()` resolves `DebtOffsetWriteMode::current()`; `show()` does not.

## C. Permission matrix

Exact permission keys from `Role::getPermissionsMap()` and `routes/web.php`:

```text
debt_offsets.view
debt_offsets.create
debt_offsets.submit
debt_offsets.approve
debt_offsets.reject
debt_offsets.apply
debt_offsets.reverse
debt_offsets.void
```

| UAT role | Minimum permissions | Exact capability |
|---|---|---|
| Auditor | `debt_offsets.view` | Index/show only, subject to branch scope. |
| Requester | `debt_offsets.view`, `debt_offsets.create`, `debt_offsets.submit`, `debt_offsets.void` | Create, update draft, submit, and void draft. Update uses `debt_offsets.create`. |
| Approver | `debt_offsets.view`, `debt_offsets.approve`, `debt_offsets.reject` | Approve or reject a pending request. |
| Applier | `debt_offsets.view`, `debt_offsets.apply` | Apply an approved request. |
| Reverser | `debt_offsets.view`, `debt_offsets.reverse` | Reverse an applied workflow or active legacy voucher through workflow mode. |
| Admin | Existing wildcard behavior | `role_id=null` or a role containing `*` bypasses permission and branch restrictions. |

Enforcement layers:

- Authentication: enclosing `Route::middleware('auth')` group in `routes/web.php`.
- Permission: route `permission:*` middleware using `User::hasAnyPermission()`.
- FormRequest: validation and idempotency key only; `authorize()` returns `true`.
- Service: mode, state transition, optimistic version, idempotency, partner
  eligibility, current balances, four-eyes, row locks and branch scope.
- UI: visibility only; it is not an authorization boundary.

Identity requirements:

```text
REQUIRE_DISTINCT_APPROVER_ENV=DEBT_OFFSET_REQUIRE_DISTINCT_APPROVER
REQUIRE_DISTINCT_APPROVER_DEFAULT=true
SAME_REQUESTER_APPROVAL_HTTP=403
SAME_REQUESTER_APPROVAL_ERROR_CODE=SELF_APPROVAL_FORBIDDEN

REQUIRE_DISTINCT_APPLIER_ENV=DEBT_OFFSET_REQUIRE_DISTINCT_APPLIER
REQUIRE_DISTINCT_APPLIER_DEFAULT=false
APPLIER_DIFFERENT_FROM_REQUESTER_OR_APPROVER=not_enforced_by_default

REVERSER_DISTINCT_IDENTITY_GUARD=none
REVERSER_SEPARATE_PERMISSION=yes
```

UAT still requires four separate least-privilege accounts. This is stronger than
the current default applier/reverser identity contract and makes evidence review
unambiguous. Do not grant all workflow permissions to one non-admin UAT account.

Branch behavior when `Setting::get('customer_manage_by_branch', false)` is true:

- Index includes a partner whose `branch_id` is `NULL` or is in
  `User::getAccessibleBranchIds()`.
- Show returns HTTP `403`, `BRANCH_SCOPE_FORBIDDEN` for a foreign non-null branch.
- Every workflow write calls `assertPartnerEligible()` and returns the same HTTP
  status/error code for a foreign non-null branch.
- A partner with `branch_id=NULL` is allowed for compatibility.
- Admin bypasses branch scope. When the setting is false, branch scope is not
  applied.

## D. Safe permission configuration path

| Candidate | Present | Safe at production SHA hiện tại | Decision |
|---|---:|---:|---|
| Admin UI `/settings` -> `Người dùng` -> `Quản lý vai trò` | Yes | Yes | Approved permission configuration path. |
| Protected Role API | Yes | Yes | Backend authorization boundary. |
| Protected User API | Yes | Yes | Backend authorization boundary. |
| Existing Artisan command | Partial | No | Không dùng cho Debt Offset UAT. |
| Seeder | Yes | No | Không dùng production. |
| Direct SQL | Possible | No | Prohibited. |

```text
SAFE_PERMISSION_PATH_IDENTIFIED=yes
SAFE_PERMISSION_PATH=ADMIN_UI_WITH_PROTECTED_ROLE_AND_USER_APIS
MANUAL_SQL_NOT_ALLOWED=yes
PRODUCTION_SEEDER_NOT_ALLOWED=yes
```

Only an actual application Admin (`role_id=NULL` or a role containing `*`) may
bootstrap the UAT role matrix. Do not use a non-admin account and do not grant `*`
to any UAT actor.

### D1. Minimum UAT roles

| Role | Exact permissions |
|---|---|
| UAT Debt Offset Auditor | `debt_offsets.view` |
| UAT Debt Offset Requester | `debt_offsets.view`, `debt_offsets.create`, `debt_offsets.submit`, `debt_offsets.void` |
| UAT Debt Offset Approver | `debt_offsets.view`, `debt_offsets.approve`, `debt_offsets.reject` |
| UAT Debt Offset Applier | `debt_offsets.view`, `debt_offsets.apply` |
| UAT Debt Offset Reverser | `debt_offsets.view`, `debt_offsets.reverse` |

Do not assign permissions outside this matrix without separate approval.

### D2. Actor assignment

```text
UAT_REQUESTER_USER_ID=<to_be_selected>
UAT_APPROVER_USER_ID=<to_be_selected>
UAT_APPLIER_USER_ID=<to_be_selected>
UAT_REVERSER_USER_ID=<to_be_selected>

REQUESTER_DIFFERENT_FROM_APPROVER=yes
APPLIER_SEPARATE_ACCOUNT=yes
REVERSER_SEPARATE_ACCOUNT=yes
ALL_ACTORS_ACTIVE=yes
ALL_ACTORS_SAME_UAT_BRANCH=yes
NO_ACTOR_HAS_WILDCARD=yes
LEAST_PRIVILEGE=yes
```

### D3. Safe configuration and verification

1. Confirm the production SHA and clean production worktree using the read-only
   commands below.
2. Sign in using an actual Admin account.
3. Open `/settings` -> `Người dùng` -> `Quản lý vai trò`.
4. Create or update only the approved UAT roles and assign the four selected active
   users.
5. Reload the Role list and reopen each role to verify its exact permissions.
6. Reload the User list and reopen each actor to verify exact role, primary branch,
   branch access and `active` status.
7. Confirm no UAT actor is an Admin and no actor has wildcard permission.

### D4. UAT readiness after permission configuration

Permission configuration is ready, but non-financial production UAT remains
blocked until all of the following are confirmed:

```text
ROLE_MATRIX_CONFIGURED=yes
FOUR_UAT_ACTORS_SELECTED=yes
ACTOR_PERMISSION_REVIEW=PASS
ACTOR_BRANCH_REVIEW=PASS
UAT_PARTNER_SELECTED=yes
UAT_AMOUNT_APPROVED=yes
FRESH_BACKUP_VERIFIED=yes
EMERGENCY_DISABLED_COMMAND_READY=yes
MAINTENANCE_WINDOW_APPROVED=yes
```

Financial UAT additionally requires:

```text
ROUND_1_PERMISSION_UAT=PASS
ROUND_2_NON_FINANCIAL_UAT=PASS
OWNER_FINANCIAL_UAT_APPROVAL=yes
```

## E. Read-only production discovery commands

Do not run these commands in this documentation task. A future operator runs one
command, sends its output for review, and waits before running the next command.
The application path is `/www/wwwroot/kiot.cuongdesign.net`.

### E1. Exact source SHA

Purpose: confirm deployed source. Database write: no.

```bash
git -C /www/wwwroot/kiot.cuongdesign.net rev-parse HEAD
```

Expected: `f47ef3a1419c9291faf72b730ee8d54b85ddec96`.

### E2. Worktree state

Purpose: ensure no local deployment drift. Database write: no.

```bash
git -C /www/wwwroot/kiot.cuongdesign.net status --porcelain
```

Expected: no output.

### E3. Effective write mode

Purpose: read the resolved cached config without printing `.env`. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='echo "EFFECTIVE_WRITE_MODE=".app(App\Services\Debt\DebtOffsetWriteMode::class)->current().PHP_EOL;'
```

Expected before UAT: `EFFECTIVE_WRITE_MODE=legacy`.

### E4. Debt-offset routes

Purpose: confirm route registration and middleware. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan route:list --path=debt-offset
```

Expected: legacy and workflow routes listed; workflow routes show their exact
`debt_offsets.*` permission middleware.

### E5. Roles and debt-offset permissions

Purpose: read current role mappings. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$keys=["debt_offsets.view","debt_offsets.create","debt_offsets.submit","debt_offsets.approve","debt_offsets.reject","debt_offsets.apply","debt_offsets.reverse","debt_offsets.void"]; App\Models\Role::query()->orderBy("id")->get(["id","name","display_name","permissions"])->each(function($r) use($keys){$p=$r->permissions??[]; dump(["id"=>$r->id,"name"=>$r->name,"display_name"=>$r->display_name,"wildcard"=>in_array("*",$p,true),"debt_offset_permissions"=>array_values(array_intersect($keys,$p))]);});'
```

Expected: review-only role inventory; no secret values.

### E6. Selected UAT actors

Purpose: verify active status, role, primary branch and branch access. Database
write: no. Replace the example IDs only after owner selection.

```bash
UAT_USER_IDS='1,2,3,4' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$ids=array_map("intval",explode(",",getenv("UAT_USER_IDS"))); App\Models\User::with(["role:id,name,display_name,permissions","branch:id,name","branchAccess:id,name"])->whereIn("id",$ids)->get()->each(function($u){dump(["id"=>$u->id,"name"=>$u->name,"status"=>$u->status,"role"=>$u->role?->only(["id","name","display_name","permissions"]),"primary_branch"=>$u->branch?->only(["id","name"]),"accessible_branch_ids"=>$u->getAccessibleBranchIds()]);});'
```

Expected: four distinct active users, least privilege, same selected UAT branch,
no wildcard role.

### E7. Selected UAT partner

Purpose: verify dual role, active/not merged, positive balances and branch.
Database write: no.

```bash
UAT_PARTNER_ID='<replace_with_approved_id>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$p=App\Models\Customer::findOrFail((int)getenv("UAT_PARTNER_ID")); dump($p->only(["id","code","name","is_customer","is_supplier","status","merged_into_id","branch_id","debt_amount","supplier_debt_amount"]));'
```

Expected: both role flags true, active, `merged_into_id=null`, both balances
positive, and an approved accessible branch.

### E8. Baseline evidence counts

Purpose: snapshot existing partner evidence before UAT. Database write: no.

```bash
UAT_PARTNER_ID='<replace_with_approved_id>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$id=(int)getenv("UAT_PARTNER_ID"); $ops=App\Models\PartnerDebtOperation::where("partner_id",$id)->pluck("id"); dump(["debt_offsets"=>App\Models\DebtOffset::where("customer_id",$id)->count(),"operations"=>$ops->count(),"participants"=>App\Models\PartnerDebtOperationParticipant::whereIn("operation_id",$ops)->count(),"outbox"=>App\Models\PartnerDebtOutboxEvent::whereIn("operation_id",$ops)->count(),"cash_flows"=>App\Models\CashFlow::where("target_id",$id)->count(),"supplier_transactions"=>App\Models\SupplierDebtTransaction::where("supplier_id",$id)->count()]);'
```

Expected: a timestamped review record saved outside the web root by the operator;
no rows changed.

### E9. Outbox schedule

Purpose: confirm no outbox schedule is enabled. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan schedule:list
```

Expected at audited revision: no debt outbox publisher command.

### E10. Runtime outbox/queue process discovery

Purpose: inspect process command lines without printing environment or secrets.
Database write: no.

```bash
ps -eo pid,user,cmd | grep -Ei '[o]utbox|[q]ueue:work|[h]orizon'
```

Expected: no debt-outbox publisher/worker. A generic queue worker is not evidence
that debt outbox publishing is implemented.

### E11. Database connectivity and engine

Purpose: verify that Laravel can read from the configured database and record the
engine version without exposing connection settings. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$row=Illuminate\Support\Facades\DB::selectOne("SELECT VERSION() AS version"); echo "DATABASE_ACTIVE=yes ENGINE_VERSION=".$row->version.PHP_EOL;'
```

Expected: `DATABASE_ACTIVE=yes` and a MariaDB/MySQL version.

### E12. `/tmp` ownership and mode

Purpose: ensure the release environment has not regressed. Database write: no;
filesystem write: no.

```bash
stat -c 'TMP_OWNER=%U:%G TMP_MODE=%a' /tmp
```

Expected: `TMP_OWNER=root:root TMP_MODE=1777`.

### E13. Protected Role API middleware

Purpose: verify Role API authentication and exact endpoint permissions. Database
write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan route:list --path=api/roles -vv
```

Expected: every Role route includes `auth:sanctum`; endpoints show the exact
`roles.view`, `roles.create`, `roles.edit`, or `roles.delete` middleware.

### E14. Protected User API middleware

Purpose: verify User API authentication and exact endpoint permissions. Database
write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan route:list --path=api/users -vv
```

Expected: every User route includes `auth:sanctum`; endpoints show the exact
`users.view`, `users.create`, `users.edit`, or `users.delete` middleware.

### E15. Unauthenticated Role API smoke test

Purpose: verify fail-closed authentication without a session. Database write: no.

```bash
curl -k -sS --resolve 'kiot.cuongdesign.net:443:127.0.0.1' -H 'Accept: application/json' -o /dev/null -w 'ROLE_API_UNAUTHENTICATED_HTTP=%{http_code}\n' 'https://kiot.cuongdesign.net/api/roles'
```

Expected: `ROLE_API_UNAUTHENTICATED_HTTP=401`.

### E16. Unauthenticated User API smoke test

Purpose: verify fail-closed authentication without a session. Database write: no.

```bash
curl -k -sS --resolve 'kiot.cuongdesign.net:443:127.0.0.1' -H 'Accept: application/json' -o /dev/null -w 'USER_API_UNAUTHENTICATED_HTTP=%{http_code}\n' 'https://kiot.cuongdesign.net/api/users'
```

Expected: `USER_API_UNAUTHENTICATED_HTTP=401`.

## F. Production UAT actor plan

```text
UAT_REQUESTER_USER_ID=<to_be_selected>
UAT_APPROVER_USER_ID=<to_be_selected>
UAT_APPLIER_USER_ID=<to_be_selected>
UAT_REVERSER_USER_ID=<to_be_selected>

UAT_BRANCH_ID=<to_be_selected>
UAT_PARTNER_ID=<to_be_selected>
UAT_AMOUNT=<business_approved_small_amount>

REQUESTER_DIFFERENT_FROM_APPROVER=yes
APPLIER_SEPARATE_ACCOUNT=yes
REVERSER_SEPARATE_ACCOUNT=yes
ALL_ACTORS_ACTIVE=yes
ALL_ACTORS_SAME_UAT_BRANCH=yes
NO_ACTOR_HAS_WILDCARD=yes
LEAST_PRIVILEGE=yes
```

Do not hard-code production IDs in Git. Owner/BA selects the actors and approves
the exact least-privilege role matrix before configuration. Capture a
screenshot/export of each role's exact permissions and the users' branches before
UAT.

## G. Production UAT partner plan

```text
PARTNER_IS_CUSTOMER=yes
PARTNER_IS_SUPPLIER=yes
PARTNER_ACTIVE=yes
PARTNER_MERGED=no
CUSTOMER_DEBT_POSITIVE=yes
SUPPLIER_DEBT_POSITIVE=yes
PARTNER_BRANCH_ACCESSIBLE=yes
NO_CONCURRENT_DEBT_OPERATION=yes

UAT_AMOUNT>0
UAT_AMOUNT<=current_customer_debt
UAT_AMOUNT<=current_supplier_debt
```

Owner/BA approves a small amount. Snapshot both balances and all evidence counts
immediately before UAT. Do not use a partner under concurrent debt activity.
Announce a short maintenance window and pause debt-offset operations for that
partner. Do not use SQL to manufacture eligibility.

## H. Three-round UAT

No round may begin until the UAT roles, actors, branch access, partner, amount,
backup and maintenance-window gates are confirmed. Round 3 needs separate owner
approval after Rounds 1 and 2 pass.

### Round 1: permission and negative access, no financial transaction

Validate in both UI and browser Network/HTTP response:

```text
AUDITOR_CAN_VIEW=yes
AUDITOR_CANNOT_CREATE=yes
REQUESTER_CAN_CREATE=yes
REQUESTER_CAN_SUBMIT=yes
REQUESTER_CANNOT_APPROVE=yes
REQUESTER_CANNOT_APPLY=yes
APPROVER_CAN_APPROVE=yes
APPROVER_CAN_REJECT=yes
APPROVER_CANNOT_CREATE_UNLESS_EXPLICITLY_GRANTED=yes
APPLIER_CAN_APPLY=yes
REVERSER_CAN_REVERSE=yes
CROSS_BRANCH_LIST_FILTERED=yes
CROSS_BRANCH_SHOW_403=yes
CROSS_BRANCH_WRITE_403=yes
```

Permission middleware denial is HTTP `403` with a message but no stable
`error_code`. Cross-branch show/write denial is HTTP `403` with
`BRANCH_SCOPE_FORBIDDEN`.

### Round 2: non-financial workflow

Use separate vouchers for each scenario. Successful non-financial commands still
create committed operation, participant, pending outbox and activity evidence;
they must not create CashFlow/SupplierDebtTransaction or change balances.

#### Scenario 2A: draft, update and void

```text
FLOW=create draft -> update draft -> void draft
FINAL_STATUS=void
CUSTOMER_BALANCE_CHANGED=no
SUPPLIER_BALANCE_CHANGED=no
CASH_FLOW_CREATED=no
SUPPLIER_TRANSACTION_CREATED=no
```

#### Scenario 2B: submit and reject

```text
FLOW=create draft -> submit -> reject by separate authorized user
FINAL_STATUS=rejected
CUSTOMER_BALANCE_CHANGED=no
SUPPLIER_BALANCE_CHANGED=no
CASH_FLOW_CREATED=no
SUPPLIER_TRANSACTION_CREATED=no
```

#### Scenario 2C: four-eyes denial

```text
FLOW=requester creates and submits -> same requester attempts approve
APPROVAL_DENIED=yes
HTTP_STATUS=403
ERROR_CODE=SELF_APPROVAL_FORBIDDEN
FINANCIAL_EFFECT=no
```

The denied approval must not add operation, participant, outbox, activity,
CashFlow, supplier transaction, or balance changes.

### Round 3: controlled financial UAT

Only start after Rounds 1 and 2 pass, backup is verified, owner approves the
amount, and emergency `disabled` commands are ready.

```text
snapshot balances and evidence counts
create draft
submit
approve by separate approver
apply by authorized applier
verify financial evidence
reverse by authorized reverser
verify full restoration
```

After apply:

```text
WORKFLOW_STATUS=applied
CUSTOMER_DEBT_DELTA=-UAT_AMOUNT
SUPPLIER_DEBT_DELTA=-UAT_AMOUNT
CASH_FLOW_CREATED_EXACTLY_ONCE=yes
SUPPLIER_DEBT_TRANSACTION_CREATED_EXACTLY_ONCE=yes
PARTNER_DEBT_OPERATION_CREATED_EXACTLY_ONCE=yes
PARTICIPANT_CREATED_EXACTLY_ONCE=yes
OUTBOX_EVENT_CREATED_EXACTLY_ONCE=yes
ACTIVITY_LOG_CREATED=yes
CUSTOMER_TIMELINE_OCCURRENCE=1
SUPPLIER_TIMELINE_OCCURRENCE=1
```

After reverse:

```text
ORIGINAL_WORKFLOW_STATUS=reversed
REVERSAL_VOUCHER_CREATED=yes
CUSTOMER_DEBT_RESTORED_TO_BEFORE=yes
SUPPLIER_DEBT_RESTORED_TO_BEFORE=yes
REVERSAL_CASH_FLOW_CREATED_EXACTLY_ONCE=yes
REVERSAL_SUPPLIER_TRANSACTION_CREATED_EXACTLY_ONCE=yes
REVERSAL_OPERATION_CREATED_EXACTLY_ONCE=yes
REVERSAL_OUTBOX_CREATED_EXACTLY_ONCE=yes
SECOND_REVERSAL_REJECTED=yes
SECOND_REVERSAL_HTTP_STATUS=409
SECOND_REVERSAL_ERROR_CODE=OFFSET_ALREADY_REVERSED
```

Do not use SQL to repair or normalize balances.

## I. Outbox behavior

```text
OUTBOX_TABLE_PRESENT=yes
OUTBOX_PUBLISHER_IMPLEMENTED=no
OUTBOX_WORKER_ENABLED=no
EXPECTED_UAT_OUTBOX_STATUS=pending
EXPECTED_UAT_OUTBOX_ATTEMPTS=0
EXTERNAL_DISPATCH_EXPECTED=no
```

`commitEvidence()` creates one pending outbox event for every successful workflow
command, including non-financial state changes. With no publisher, pending is the
expected terminal observation for this UAT, not a failure. Do not enable a worker
or implement a publisher during UAT.

Monitoring rule:

- Pending delta must equal the count of newly committed successful workflow
  operations.
- Each operation must have exactly one outbox row.
- Retries/idempotent replays must not add rows.
- No UAT row may become `processing`, `published`, `failed` or `dead_letter`
  because no publisher exists.
- Record the pending backlog before and after every UAT round. Escalate any
  mismatch; never delete outbox rows.

Read-only pending count:

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\PartnerDebtOutboxEvent::query()->selectRaw("status, COUNT(*) AS total")->groupBy("status")->orderBy("status")->get()->toArray());'
```

Expected: UAT-created rows remain `pending`, attempts `0`.

## J. Backup gate

Run only after the role matrix and actors are approved and the owner authorizes the
UAT preparation window. Commands are separate. They do not print resolved database
credentials. Backup is outside the web root and no existing backup is deleted.

### J1. Set a timestamp in the operator shell

Purpose: stable names for one backup set. Database write: no.

```bash
export PR27_UAT_TS="$(date +%Y%m%d-%H%M%S)"
```

Expected: a non-empty shell variable in `YYYYMMDD-HHMMSS` format.

### J2. Create temporary mode-600 client material from resolved Laravel config

Purpose: avoid credentials in the command line/process list. Database write: no;
filesystem write: yes, `/root` only.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$c=config("database.connections.".config("database.default")); $base="/root/.kiot-pr27-uat-".getenv("PR27_UAT_TS"); file_put_contents($base.".cnf","[client]\nhost=".($c["host"]??"127.0.0.1")."\nport=".($c["port"]??3306)."\nuser=".$c["username"]."\npassword=".$c["password"]."\n",LOCK_EX); file_put_contents($base.".database",$c["database"],LOCK_EX); chmod($base.".cnf",0600); chmod($base.".database",0600); echo "CLIENT_FILES_CREATED=yes".PHP_EOL;'
```

Expected: `CLIENT_FILES_CREATED=yes`; no credential value printed.

### J3. Create compressed full database backup

Purpose: full transactional database backup. Database write: no; backup file
write: yes.

```bash
bash -o pipefail -c 'umask 077; DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump)"; test -n "$DUMP_BIN"; DB_NAME="$(cat "/root/.kiot-pr27-uat-${PR27_UAT_TS}.database")"; "$DUMP_BIN" --defaults-extra-file="/root/.kiot-pr27-uat-${PR27_UAT_TS}.cnf" --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "/root/kiot-db-backup-pr27-uat-${PR27_UAT_TS}.sql.gz"'
```

Expected: exit `0` and a non-empty mode-600 gzip file.

### J4. Enforce backup mode

Purpose: ensure owner-only backup access. Database write: no.

```bash
chmod 600 "/root/kiot-db-backup-pr27-uat-${PR27_UAT_TS}.sql.gz"
```

Expected: exit `0`.

### J5. Test gzip integrity

Purpose: prove the compressed stream is readable. Database write: no.

```bash
gzip -t "/root/kiot-db-backup-pr27-uat-${PR27_UAT_TS}.sql.gz"
```

Expected: exit `0`, no output; record `GZIP_TEST=PASS`.

### J6. Capture size and mode

Purpose: backup evidence. Database write: no.

```bash
stat -c 'BACKUP_FILE=%n BACKUP_SIZE=%s BACKUP_MODE=%a' "/root/kiot-db-backup-pr27-uat-${PR27_UAT_TS}.sql.gz"
```

Expected: correct path, size greater than zero, mode `600`.

### J7. Capture SHA-256

Purpose: immutable backup evidence. Database write: no.

```bash
sha256sum "/root/kiot-db-backup-pr27-uat-${PR27_UAT_TS}.sql.gz"
```

Expected: 64-hex digest and exact backup path.

### J8. Remove only temporary client material

Purpose: remove the short-lived credential files after backup proof. Database
write: no. The backup itself must not be removed.

```bash
rm -f "/root/.kiot-pr27-uat-${PR27_UAT_TS}.cnf" "/root/.kiot-pr27-uat-${PR27_UAT_TS}.database"
```

Expected backup report:

```text
BACKUP_FILE=/root/kiot-db-backup-pr27-uat-YYYYMMDD-HHMMSS.sql.gz
BACKUP_SIZE=<bytes_greater_than_zero>
BACKUP_SHA256=<64_hex>
BACKUP_MODE=600
GZIP_TEST=PASS
```

## K. Enablement plan

Blocked until permission configuration is complete, all prerequisite checks pass,
and the owner explicitly opens the UAT window. Run one command at a time and review
output. No migration or database restart is part of enablement.

### K1. Back up `.env` without displaying it

Purpose: configuration rollback copy. Database write: no; filesystem write: yes.

```bash
install -m 600 /www/wwwroot/kiot.cuongdesign.net/.env "/root/kiot-env-backup-pr27-uat-${PR27_UAT_TS}"
```

Expected: exit `0`; no `.env` content printed.

### K2. Set explicit workflow mode

Purpose: change only `DEBT_OFFSET_WRITE_MODE`. Database write: no; configuration
write: yes.

```bash
php -r '$p="/www/wwwroot/kiot.cuongdesign.net/.env"; $s=file_get_contents($p); $line="DEBT_OFFSET_WRITE_MODE=workflow"; $s=preg_match("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$s)?preg_replace("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$line,$s):rtrim($s).PHP_EOL.$line.PHP_EOL; exit(file_put_contents($p,$s,LOCK_EX)===false?1:0);'
```

Expected: exit `0`, no file content printed.

### K3. Clear config cache

Purpose: remove stale Laravel config only. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan config:clear
```

Expected: configuration cache cleared.

### K4. Rebuild config cache

Purpose: activate explicit mode. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan config:cache
```

Expected: configuration cached successfully.

### K5. Verify effective workflow mode

Purpose: fail the gate if cache/env does not agree. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='echo "EFFECTIVE_WRITE_MODE=".app(App\Services\Debt\DebtOffsetWriteMode::class)->current().PHP_EOL;'
```

Expected: `EFFECTIVE_WRITE_MODE=workflow`.

### K6. Root smoke test

Purpose: HTTP reachability. Database write: no.

```bash
curl -sS -o /dev/null -w 'ROOT_HTTP=%{http_code}\n' http://127.0.0.1/
```

Expected: `ROOT_HTTP=302`.

### K7. Login smoke test

Purpose: login page reachability, no credential submission. Database write: no.

```bash
curl -sS -o /dev/null -w 'LOGIN_HTTP=%{http_code}\n' http://127.0.0.1/login
```

Expected: `LOGIN_HTTP=200`.

### K8. Legacy service guard probe

Purpose: verify the exact guard used by legacy endpoints without selecting a
partner or creating a transaction. Database write: no.

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='try{app(App\Services\Debt\DebtOffsetWriteMode::class)->assertLegacyAllowed(); echo "LEGACY_WRITE_BLOCKED=no".PHP_EOL;}catch(App\Exceptions\DebtOffsetWorkflowException $e){echo "HTTP_STATUS=".$e->httpStatus.PHP_EOL."ERROR_CODE=".$e->errorCode.PHP_EOL;}'
```

Expected: HTTP `409`, `LEGACY_DEBT_OFFSET_WRITE_DISABLED`.

### K9. Authenticated legacy endpoint guard

Purpose: use the Requester browser session to test the actual HTTP route safely.
Run in browser DevTools Console with an approved eligible partner ID. Amount `0`
ensures that even an unexpected legacy mode reaches validation without financial
write. Database write expected: no.

```javascript
axios.post('/customers/<UAT_PARTNER_ID>/debt-offset', { amount: 0 }).then(r => console.log(r.status, r.data)).catch(e => console.log(e.response?.status, e.response?.data?.error_code));
```

Expected: `409 LEGACY_DEBT_OFFSET_WRITE_DISABLED`. Any other result is No-Go.

### K10. Authenticated workflow read route

Purpose: verify authorized workflow route and branch filtering. Database write: no.

```javascript
axios.get('/debt-offsets', { headers: { Accept: 'application/json' } }).then(r => console.log(r.status, r.data.write_mode)).catch(e => console.log(e.response?.status, e.response?.data));
```

Expected for authorized Auditor: `200 workflow`.

Do not restart MariaDB/MySQL, PHP-FPM or Nginx. Do not run migration, release
runner, scheduler, worker or publisher.

## L. Emergency freeze plan

Trigger on balance mismatch, duplicate evidence, apply/reverse HTTP 500,
permission bypass, cross-branch leak, timeline duplicate, failed reversal, or
inconsistent workflow state.

```text
STEP_1=stop all debt-offset operations and announce freeze
STEP_2=set write mode disabled
STEP_3=refresh config cache
STEP_4=verify effective disabled
STEP_5=capture logs and evidence
STEP_6=do not manually edit financial data
```

### L1. Set disabled mode

Purpose: emergency stop for both write paths. Database write: no.

```bash
php -r '$p="/www/wwwroot/kiot.cuongdesign.net/.env"; $s=file_get_contents($p); $line="DEBT_OFFSET_WRITE_MODE=disabled"; $s=preg_match("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$s)?preg_replace("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$line,$s):rtrim($s).PHP_EOL.$line.PHP_EOL; exit(file_put_contents($p,$s,LOCK_EX)===false?1:0);'
```

Expected: exit `0`.

### L2. Clear config cache

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan config:clear
```

Expected: success. Database write: no.

### L3. Rebuild config cache

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan config:cache
```

Expected: success. Database write: no.

### L4. Verify disabled mode

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='echo "EMERGENCY_WRITE_MODE=".app(App\Services\Debt\DebtOffsetWriteMode::class)->current().PHP_EOL;'
```

Expected: `EMERGENCY_WRITE_MODE=disabled`. Database write: no.

### L5. Verify both guards fail closed

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$m=app(App\Services\Debt\DebtOffsetWriteMode::class); foreach(["legacy"=>"assertLegacyAllowed","workflow"=>"assertWorkflowAllowed"] as $name=>$method){try{$m->{$method}(); echo strtoupper($name)."_WRITE_BLOCKED=no".PHP_EOL;}catch(App\Exceptions\DebtOffsetWorkflowException $e){echo strtoupper($name)."_WRITE_BLOCKED=yes HTTP=".$e->httpStatus." CODE=".$e->errorCode.PHP_EOL;}}'
```

Expected:

```text
LEGACY_WRITE_BLOCKED=yes HTTP=403 CODE=DEBT_OFFSET_WORKFLOW_DISABLED
WORKFLOW_WRITE_BLOCKED=yes HTTP=403 CODE=DEBT_OFFSET_WORKFLOW_DISABLED
DATABASE_MANUAL_CORRECTION=no
```

## M. Rollback levels

### Level 0: before financial apply

If UAT reached only draft, update, void, submit, approve or reject, switch back to
legacy; no balance repair is required. Preserve evidence.

Set legacy mode:

```bash
php -r '$p="/www/wwwroot/kiot.cuongdesign.net/.env"; $s=file_get_contents($p); $line="DEBT_OFFSET_WRITE_MODE=legacy"; $s=preg_match("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$s)?preg_replace("/^DEBT_OFFSET_WRITE_MODE=.*$/m",$line,$s):rtrim($s).PHP_EOL.$line.PHP_EOL; exit(file_put_contents($p,$s,LOCK_EX)===false?1:0);'
```

Then run `config:clear`, `config:cache`, and the effective-mode read command as
separate reviewed steps. Expected final mode: `legacy`.

### Level 1: apply succeeded and workflow is stable

1. Keep mode `workflow`.
2. Reverser opens the exact applied voucher in `/debt-offsets` and chooses
   `Đảo phiếu` with an incident/UAT reason.
3. Confirm original status `reversed`, one reversal voucher, restored balances,
   exactly-once reversal evidence, and pending outbox row.
4. Only after restoration, set mode `legacy`, clear/cache config, and verify.

```text
USE_WORKFLOW_REVERSE=yes
SWITCH_TO_LEGACY_BEFORE_REQUIRED_REVERSE=no
MANUAL_BALANCE_SQL_ALLOWED=no
```

### Level 2: suspected balance error or unstable workflow

1. Set mode `disabled` immediately using Section L.
2. Stop all debt-offset writes.
3. Capture balances, offset, operation, participant, outbox, activity, CashFlow,
   supplier transaction and timeline evidence.
4. Do not delete rows or update balances/statuses.
5. Open an incident for Senior review.
6. Return to `legacy` only after Senior confirms data safety.

### Level 3: code rollback

Before code rollback, production mode must be `disabled`. Do not reset, detach,
force pull, or delete files on production. Rollback goes through GitHub:

```text
ROLLBACK_MERGE_COMMIT=a026415717a66fe8ebcd0697981b0b28c43d3fe6
ROLLBACK_MAINLINE_PARENT=1
```

This SHA intentionally identifies the PR #27 workflow-code merge to revert; it is
not the current production SHA.

Create the branch in a clean non-production checkout:

```bash
git switch -c rollback/pr27-debt-offset-workflow origin/production-customer-group
```

Create the merge revert:

```bash
git revert -m 1 a026415717a66fe8ebcd0697981b0b28c43d3fe6
```

Push and open a reviewed rollback PR:

```bash
git push -u origin rollback/pr27-debt-offset-workflow
```

After normal merge, fast-forward production to the rollback merge SHA, build
frontend, refresh config cache and smoke-test. Only then may owner/Senior consider
changing mode from `disabled` to `legacy`.

Forbidden production rollback actions:

```text
git reset --hard 2ca4408...=forbidden
detached checkout=forbidden
force pull=forbidden
manual file deletion=forbidden
```

## N. Database correction policy

```text
MANUAL_BALANCE_SQL_ALLOWED=no
DELETE_OPERATION_EVIDENCE_ALLOWED=no
DELETE_OUTBOX_ALLOWED=no
DELETE_ACTIVITY_LOG_ALLOWED=no
UPDATE_WORKFLOW_STATUS_BY_SQL_ALLOWED=no
USE_WORKFLOW_REVERSE=yes
```

If reverse cannot run:

```text
EMERGENCY_MODE=disabled
INCIDENT_REVIEW_REQUIRED=yes
```

No SQL correction command belongs in this runbook.

## O. Monitoring after UAT

Run each command separately and replace placeholders with the approved UAT IDs or
voucher codes. All commands are read-only.

### O1. Recent debt-offset HTTP 500 evidence

```bash
tail -n 20000 /www/wwwroot/kiot.cuongdesign.net/storage/logs/laravel.log | grep -Ei 'debt[-_ ]?offset|SELF_APPROVAL|BRANCH_SCOPE|OFFSET_' | grep -E 'ERROR|500|exception'
```

Expected: no UAT-related HTTP 500/exception lines.

### O2. Workflow status counts

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\DebtOffset::query()->selectRaw("COALESCE(workflow_status, \"legacy\") AS workflow_status, COUNT(*) AS total")->groupBy("workflow_status")->orderBy("workflow_status")->get()->toArray());'
```

### O3. Operation type/status counts

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\PartnerDebtOperation::query()->where("operation_type","like","debt_offset.%")->selectRaw("operation_type, status, COUNT(*) AS total")->groupBy("operation_type","status")->orderBy("operation_type")->orderBy("status")->get()->toArray());'
```

### O4. Pending outbox counts

```bash
php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\PartnerDebtOutboxEvent::query()->selectRaw("status, attempts, COUNT(*) AS total")->groupBy("status","attempts")->orderBy("status")->orderBy("attempts")->get()->toArray());'
```

### O5. Activity for one UAT offset

```bash
UAT_OFFSET_ID='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\ActivityLog::where("subject_type",App\Models\DebtOffset::class)->where("subject_id",(int)getenv("UAT_OFFSET_ID"))->orderBy("id")->get(["id","user_id","action","description","subject_id","properties","created_at"])->toArray());'
```

### O6. CashFlow evidence by voucher code

```bash
UAT_VOUCHER_CODE='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\CashFlow::where("reference_code",getenv("UAT_VOUCHER_CODE"))->orWhere("code",getenv("UAT_VOUCHER_CODE"))->orderBy("id")->get(["id","code","type","amount","target_id","reference_type","reference_code","idempotency_key","created_at"])->toArray());'
```

### O7. Supplier transaction evidence by voucher code

```bash
UAT_VOUCHER_CODE='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\SupplierDebtTransaction::where("code",getenv("UAT_VOUCHER_CODE"))->orderBy("id")->get(["id","supplier_id","code","type","amount","debt_remain","user_id","created_at"])->toArray());'
```

### O8. Partner balance after apply/reverse

```bash
UAT_PARTNER_ID='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='dump(App\Models\Customer::findOrFail((int)getenv("UAT_PARTNER_ID"))->only(["id","code","debt_amount","supplier_debt_amount","branch_id","updated_at"]));'
```

### O9. Timeline occurrence count

```bash
UAT_PARTNER_ID='<replace>' UAT_VOUCHER_CODE='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$p=App\Models\Customer::findOrFail((int)getenv("UAT_PARTNER_ID")); $code=getenv("UAT_VOUCHER_CODE"); $c=collect(app(App\Services\CustomerDebtDocumentTimelineService::class)->build($p)["entries"]??[])->where("code",$code)->count(); $s=collect(app(App\Services\SupplierDebtDocumentTimelineService::class)->build($p)["entries"]??[])->where("code",$code)->count(); dump(["code"=>$code,"customer_timeline_occurrence"=>$c,"supplier_timeline_occurrence"=>$s]);'
```

### O10. Duplicate evidence count for an offset

```bash
UAT_OFFSET_ID='<replace>' php /www/wwwroot/kiot.cuongdesign.net/artisan tinker --execute='$id=(int)getenv("UAT_OFFSET_ID"); $o=App\Models\DebtOffset::findOrFail($id); $ops=App\Models\PartnerDebtOperation::query()->where(function($q) use($id){$q->where(function($s) use($id){$s->where("source_type","DebtOffset")->where("source_id",$id);})->orWhereHas("outboxEvents",function($e) use($id){$e->where("aggregate_type","DebtOffset")->where("aggregate_id",$id);});})->withCount(["participants","outboxEvents"])->orderBy("id")->get(["id","operation_type","status","idempotency_key"]); dump(["offset_code"=>$o->code,"cash_flows"=>App\Models\CashFlow::where("reference_code",$o->code)->count(),"supplier_transactions"=>App\Models\SupplierDebtTransaction::where("code",$o->code)->count(),"operations"=>$ops->toArray(),"operation_type_counts"=>$ops->countBy("operation_type")->toArray()]);'
```

Expected: each successful operation type occurs once for its intended command;
every listed operation has `participants_count=1` and `outbox_events_count=1`.
The applied voucher has exactly one matching CashFlow and supplier transaction.

Expected final monitoring result:

```text
HTTP_500_DEBT_OFFSET=0
NEGATIVE_BALANCE=no
DUPLICATE_CASH_FLOW=no
DUPLICATE_SUPPLIER_TRANSACTION=no
DUPLICATE_OPERATION=no
DUPLICATE_OUTBOX=no
TIMELINE_DUPLICATE=no
OUTBOX_STATUS=pending_attempts_0
```

## P. Go/No-Go checklist

```text
[x] Debt Offset workflow code deployed
[x] Role/User API security merged
[x] Role/User API security deployed
[x] Role/User unauthenticated access returns 401
[x] Exact Role/User middleware verified
[x] Production remains legacy
[x] MariaDB/MySQL active
[x] /tmp remains 1777 root:root
[ ] Fresh database backup verified
[ ] UAT roles configured
[ ] Four UAT actors selected
[ ] Exact role permissions re-read and confirmed
[ ] Actor branches confirmed
[ ] UAT partner confirmed
[ ] UAT amount approved
[ ] No concurrent debt operation
[ ] Maintenance window started
[ ] Emergency disabled command ready
[ ] Rollback commands ready
[ ] Non-financial UAT passed
[ ] Financial apply passed
[ ] Financial reverse passed
[ ] Balances fully restored
[ ] Evidence exactly once
[ ] Timeline exactly once
[ ] Outbox status expected
[ ] Final mode explicitly selected
```

Go only if every mandatory checkbox is PASS. No-Go if any permission, branch
scope, balance, evidence, timeline or reverse check fails.

## Q. Final mode decision

The operator and owner must record exactly one result. The Agent must not choose B.

### Result A: UAT passes, no rollout

```text
FINAL_MODE=legacy
WORKFLOW_UAT=PASS
FULL_ROLLOUT=no
```

### Result B: UAT passes and rollout is explicitly approved

```text
FINAL_MODE=workflow
WORKFLOW_UAT=PASS
FULL_ROLLOUT=yes
BUSINESS_APPROVAL_RECORDED=yes
```

### Result C: UAT fails

```text
FINAL_MODE=disabled_or_legacy_according_to_incident_level
WORKFLOW_UAT=FAIL
INCIDENT_OPENED=yes
```

Owner approval is mandatory before retaining `workflow`. The security blocker is
closed, but production remains in `legacy` until permission configuration and all
subsequent UAT gates are completed and approved.

## R. Final readiness and findings

```text
MODE_BEHAVIOR_AUDITED=yes
PERMISSION_MATRIX_COMPLETE=yes

ROLE_USER_API_SECURITY_MERGED=yes
ROLE_USER_API_SECURITY_DEPLOYED=yes
SAFE_PERMISSION_PATH_IDENTIFIED=yes

NON_FINANCIAL_UAT_PLAN_COMPLETE=yes
FINANCIAL_UAT_PLAN_COMPLETE=yes
BACKUP_PLAN_COMPLETE=yes
EMERGENCY_DISABLED_PLAN_COMPLETE=yes
FINANCIAL_REVERSAL_PLAN_COMPLETE=yes
CODE_ROLLBACK_PLAN_COMPLETE=yes
MONITORING_PLAN_COMPLETE=yes

OUTBOX_PUBLISHER_IMPLEMENTED=no
OUTBOX_UAT_EXPECTATION_DOCUMENTED=yes

P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=3

P2_1=OUTBOX_PUBLISHER_NOT_IMPLEMENTED
P2_2=DISTINCT_APPLIER_AND_REVERSER_NOT_ENFORCED_BY_DEFAULT
P2_3=ROLE_USER_ADMIN_UI_ACTION_VISIBILITY_NOT_FULLY_PERMISSION_GATED

READY_FOR_PERMISSION_CONFIGURATION=yes
READY_FOR_NON_FINANCIAL_PRODUCTION_UAT=no
READY_FOR_FINANCIAL_PRODUCTION_UAT=no
READY_FOR_FULL_WORKFLOW_ENABLEMENT=no
```

P2 findings are non-blocking for permission configuration. Outbox events are
expected to remain `pending` with `attempts=0`; no publisher is enabled. UAT uses
separate Applier and Reverser accounts even though the application does not enforce
that distinction by default. Settings UI action visibility may not mirror every
exact permission, but the protected backend API remains the authoritative boundary
and returns HTTP `403`; initial role configuration therefore uses an Admin account.

No production command, data mutation, migration, permission change, mode change,
worker enablement or deployment was performed while updating this runbook.
