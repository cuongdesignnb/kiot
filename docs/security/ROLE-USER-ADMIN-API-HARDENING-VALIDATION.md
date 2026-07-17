# Role/User Administration API Hardening Validation

## 1. Document control

```text
TASK_CODE=SECURE-ROLE-USER-ADMIN-API-01
REPOSITORY=cuongdesignnb/kiot
BASE_BRANCH=production-customer-group
BASE_SHA=a026415717a66fe8ebcd0697981b0b28c43d3fe6
BRANCH=fix/secure-role-user-admin-api

FINAL_HEAD_SHA=reported in Draft PR metadata and the final Agent handoff
FINAL_HEAD_SHA_EMBEDDED_IN_SAME_COMMIT=no
FINAL_HEAD_SHA_REASON=a commit cannot cryptographically contain its own final SHA

PRODUCTION_ACCESSED=no
PRODUCTION_DATA_CHANGED=no
PRODUCTION_PERMISSION_CHANGED=no
PRODUCTION_DEPLOYED=no
```

This report validates the security patch against the exact base revision above.
It does not authorize merge, deployment, production permission changes, or Debt
Offset production UAT.

## 2. Acceptance status

```text
ROLE_API_AUTHENTICATED=yes
USER_API_AUTHENTICATED=yes
EXACT_PERMISSION_MIDDLEWARE=yes
UNKNOWN_PERMISSION_REJECTED=yes
ADMIN_ESCALATION_PROTECTED=yes
SYSTEM_ROLE_PROTECTED=yes
SELF_LOCK_DELETE_PROTECTED=yes

MYSQL_VALIDATION=PASS
MARIADB_VALIDATION=PASS
SECURITY_REGRESSION=PASS

P0_BLOCKERS=0
P1_BLOCKERS=0
P2_FINDINGS=1

SAFE_PERMISSION_PATH_READY_FOR_SENIOR_REVIEW=yes
READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
READY_FOR_DEBT_OFFSET_UAT=no
```

P2 is a UI-only least-privilege usability finding: the existing Settings page
does not hide every role/user action independently by its exact permission. The
API is now the authoritative authorization boundary and returns `403` for every
forbidden action. UI visibility refinement can be handled separately and is not
an authorization bypass.

## 3. Changed scope

Expected changed files:

```text
routes/api.php
app/Http/Controllers/Api/RoleController.php
app/Http/Controllers/Api/UserController.php
app/Services/Security/RoleUserAdministrationGuard.php
tests/Feature/Api/RoleAuthorizationTest.php
tests/Feature/Api/UserAuthorizationTest.php
docs/security/ROLE-USER-ADMIN-API-HARDENING-VALIDATION.md
```

Excluded scope:

```text
MIGRATION_CHANGES=0
DEBT_WORKFLOW_LOGIC_CHANGED=no
FRONTEND_DEBT_WORKFLOW_CHANGED=no
CONFIG_DEBT_CHANGED=no
DATABASE_SCHEMA_CHANGED=no
```

## 4. Authentication and exact middleware matrix

Both route groups are enclosed by `auth:sanctum`. Laravel's existing
`statefulApi()` middleware remains unchanged, so the Settings browser session
continues to authenticate through the web session and CSRF contract. No bearer
token was added to the frontend.

| Method | Endpoint | Permission |
|---|---|---|
| `GET` | `/api/roles/permissions-map` | `roles.view` |
| `GET` | `/api/roles` | `roles.view` |
| `GET` | `/api/roles/{role}` | `roles.view` |
| `POST` | `/api/roles` | `roles.create` |
| `POST` | `/api/roles/{role}/duplicate` | `roles.create` |
| `PUT` | `/api/roles/{role}` | `roles.edit` |
| `DELETE` | `/api/roles/{role}` | `roles.delete` |
| `GET` | `/api/users` | `users.view` |
| `POST` | `/api/users` | `users.create` |
| `PUT` | `/api/users/{user}` | `users.edit` |
| `DELETE` | `/api/users/{user}` | `users.delete` |

Unauthenticated requests return HTTP `401`. An authenticated actor without the
exact route permission receives HTTP `403`. `roles.view`, `users.view`,
`settings.view`, and `settings.manage` do not imply any other role/user API
permission.

## 5. Permission input contract

Role create/update validates every submitted permission with:

```text
Role::getAllPermissionKeys() plus the explicit Admin wildcard "*"
```

Unknown keys and duplicates are rejected with HTTP `422`; no unknown value is
silently filtered or persisted. The wildcard remains a valid schema value only
so an existing Admin can manage Admin roles. The security guard rejects it for
every non-admin actor.

Request input cannot change `is_system`. Role update also does not accept `name`,
so an existing system-role name remains unchanged.

## 6. Admin and escalation guards

`User::isAdmin()` remains the single Admin definition:

```text
role_id IS NULL
OR assigned role contains permission "*"
```

Only an Admin may:

- create, retain, update, duplicate, or delete a role containing `*`;
- update a system role;
- create or assign a user with `role_id=null`;
- assign a user to a wildcard role;
- update, lock, or delete another Admin account;
- assign a role containing permissions above the actor's own effective
  permissions.

Non-admin role managers are additionally limited to roles whose current and
requested permissions are within the actor's own effective permission set.
Non-admin user managers cannot manage users whose role has permissions above the
actor's own effective permission set. These checks prevent indirect escalation
through password reset or reassignment of a more privileged account.

Denied escalation returns:

```text
HTTP_STATUS=403
ERROR_CODE=ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN
```

System roles remain non-deletable. A non-admin is rejected with `403`; an Admin
reaches the existing system-role contract and receives `422`.

## 7. User safeguards

```text
SELF_DELETE_PROTECTED=yes
SELF_LOCK_PROTECTED=yes
SELF_ROLE_ESCALATION_PROTECTED=yes
ADMIN_USER_PROTECTED=yes
ROLE_EXISTS_VALIDATION=yes
BRANCH_EXISTS_VALIDATION=yes
PASSWORD_HASHED=yes
PASSWORD_RETURNED=no
REMEMBER_TOKEN_RETURNED=no
```

Omitted optional update fields now retain their current values instead of
silently resetting `phone`, `role_id`, `branch_id`, or `status`. Explicit
`branch_ids` continues to synchronize the branch-access pivot, including an
explicit empty array. Invalid branch and role IDs return HTTP `422`.

## 8. Browser-session compatibility

Feature validation used an authenticated `web` guard session against the API
routes and confirmed both `/api/roles` and `/api/users` return HTTP `200` for an
actor with the exact view permissions.

```text
SANCTUM_STATEFUL_BROWSER_AUTH=PASS
AUTHENTICATED_ADMIN_UI_REQUEST=PASS
CSRF_CONTRACT_PRESERVED=yes
TOKEN_ONLY_CONVERSION=no
FRONTEND_BEARER_TOKEN_ADDED=no
```

## 9. Engine validation

### MySQL

```text
ENGINE=MySQL Community Server
VERSION=8.0.44
DATABASE=local disposable test database
ROLE_USER_SECURITY=PASS
SECURITY_REGRESSION=PASS

ROLE_USER_TESTS=25 passed
ROLE_USER_ASSERTIONS=143
REGRESSION_TESTS=17 passed
REGRESSION_ASSERTIONS=124
```

### MariaDB

```text
ENGINE=MariaDB
VERSION=10.11.10-MariaDB-ubu2204
DATABASE=ephemeral container without a persistent volume
TEST_COLLATION=utf8mb4_unicode_ci
ROLE_USER_SECURITY=PASS
SECURITY_REGRESSION=PASS

ROLE_USER_TESTS=25 passed
ROLE_USER_ASSERTIONS=143
REGRESSION_TESTS=17 passed
REGRESSION_ASSERTIONS=124
```

Combined successful validation across both engines:

```text
TEST_EXECUTIONS=84
ASSERTION_EXECUTIONS=534
```

The local PHP CLI emitted unrelated startup warnings for unavailable OCI and
Firebird extensions. MySQL/MariaDB PDO execution and all selected tests passed.

## 10. Exact commands executed

Database credentials were injected only as process environment variables for
local disposable databases and are intentionally not recorded. The executable
test commands were:

```powershell
php vendor/bin/phpunit tests/Feature/Api/RoleAuthorizationTest.php tests/Feature/Api/UserAuthorizationTest.php
```

```powershell
php vendor/bin/phpunit tests/Feature/Security/EmployeePermissionIsolationTest.php tests/Feature/DebtOffsets/DebtOffsetWorkflowPermissionTest.php
```

Route middleware evidence:

```powershell
php artisan route:list --path=api/roles -vv
```

```powershell
php artisan route:list --path=api/users -vv
```

Static validation commands:

```powershell
php -l routes/api.php
php -l app/Http/Controllers/Api/RoleController.php
php -l app/Http/Controllers/Api/UserController.php
php -l app/Models/Role.php
php -l app/Services/Security/RoleUserAdministrationGuard.php
php -l tests/Feature/Api/RoleAuthorizationTest.php
php -l tests/Feature/Api/UserAuthorizationTest.php
```

```powershell
php vendor/bin/pint --test routes/api.php app/Http/Controllers/Api/RoleController.php app/Http/Controllers/Api/UserController.php app/Models/Role.php app/Services/Security/RoleUserAdministrationGuard.php tests/Feature/Api/RoleAuthorizationTest.php tests/Feature/Api/UserAuthorizationTest.php
```

```powershell
git diff --check
```

## 11. Environment notes

The first local test attempt was discarded because a whole-directory `vendor`
junction made Composer resolve `App` classes from another checkout. A local
git-ignored Composer autoload was regenerated and verified by reflection before
the accepted runs. An existing build manifest was linked through a git-ignored
test-only junction for the Employee Inertia assertion. Neither dependency nor
build artifact is part of the diff.

The first MariaDB connection used MySQL's default
`utf8mb4_0900_ai_ci` collation and failed before executing tests. The accepted
MariaDB runs explicitly used `DB_COLLATION=utf8mb4_unicode_ci`; no repository
config was changed.

All database containers were stopped after validation. The ephemeral MariaDB
container created for this task was removed.

## 12. Senior review decision gate

```text
SAFE_PERMISSION_PATH_READY_FOR_SENIOR_REVIEW=yes
SECURITY_PR_DRAFT_REQUIRED=yes
SECURITY_PR_MERGED=no
PRODUCTION_DEPLOYED=no

PR_28_MUST_REMAIN_OPEN=yes
PR_28_MUST_REMAIN_DRAFT=yes
PR_28_MUST_REMAIN_UNCHANGED=yes

READY_FOR_MERGE=no
READY_FOR_PRODUCTION_DEPLOY=no
READY_FOR_DEBT_OFFSET_UAT=no
```

The role/user Admin UI becomes a candidate safe permission path only after this
security PR is independently reviewed, merged, and deployed. PR #28 must not be
updated until that separate gate is complete.
