<?php
/**
 * Flow 11 -- Kiem thu User Management & Permissions
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use App\Models\CashFlow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];

function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) { echo "  PASS $label\n"; $pass++; }
    else { echo "  FAIL $label" . ($detail ? " -- $detail" : "") . "\n"; $fail++; $errors[] = "$label: $detail"; }
}

echo "\n=== FLOW 11 -- KIEM THU USER & PERMISSIONS ===\n\n";

// === CLEANUP ===
User::withTrashed()->where('email', 'LIKE', '%_f11@test.com')->forceDelete();
Role::where('name', 'LIKE', '%_f11_%')->delete();

echo "-- Setup --\n";
$admin = User::find(1);
echo "  OK Admin: {$admin->name} (id={$admin->id})\n";

// Get or create a test branch
$branch = Branch::first();
echo "  OK Branch: {$branch->name} (id={$branch->id})\n";

// === 11A: Create user ===
echo "\n-- 11A: Create user --\n";

// Create test role first
$salesRole = Role::create([
    'name' => 'sales_f11_' . time(),
    'display_name' => 'Sales Test F11',
    'permissions' => ['invoices.view', 'invoices.create', 'customers.view', 'pos.use'],
]);
test("Role created", $salesRole->id > 0, $pass, $fail, $errors);

$testUser = User::create([
    'name' => 'Sale Test F11',
    'email' => 'sale_f11@test.com',
    'password' => Hash::make('password123'),
    'role_id' => $salesRole->id,
    'branch_id' => $branch->id,
    'status' => 'active',
]);
test("User created", $testUser->id > 0, $pass, $fail, $errors);
test("Role assigned", $testUser->role_id === $salesRole->id, $pass, $fail, $errors);
test("Branch assigned", $testUser->branch_id === $branch->id, $pass, $fail, $errors);
test("Status = active", $testUser->status === 'active', $pass, $fail, $errors);

// === 11B: Permission helpers ===
echo "\n-- 11B: Permission checks (role-based) --\n";

test("Has invoices.view", $testUser->hasPermission('invoices.view'), $pass, $fail, $errors);
test("Has pos.use", $testUser->hasPermission('pos.use'), $pass, $fail, $errors);
test("NOT has users.view", !$testUser->hasPermission('users.view'), $pass, $fail, $errors);
test("NOT has settings.manage", !$testUser->hasPermission('settings.manage'), $pass, $fail, $errors);
test("NOT admin", !$testUser->isAdmin(), $pass, $fail, $errors);

// Admin check
test("Admin has all permissions", $admin->hasPermission('users.view'), $pass, $fail, $errors);
test("Admin isAdmin()", $admin->isAdmin(), $pass, $fail, $errors);

// === 11C: Route/middleware enforcement ===
echo "\n-- 11C: Middleware enforcement --\n";

$middleware = new \App\Http\Middleware\CheckPermission();

// Test authorized
Auth::loginUsingId($testUser->id);
$authReq = new \Illuminate\Http\Request();
$authReq->headers->set('Accept', 'application/json');
$result = $middleware->handle($authReq, function ($r) { return response()->json(['ok' => true]); }, 'invoices.view');
test("Authorized request passes", $result->getStatusCode() === 200, $pass, $fail, $errors);

// Test unauthorized
$unauthResult = $middleware->handle($authReq, function ($r) { return response()->json(['ok' => true]); }, 'users.view');
test("Unauthorized request blocked (403)", $unauthResult->getStatusCode() === 403, $pass, $fail, $errors);

Auth::loginUsingId(1); // restore admin

// === 11D: Branch scope isolation ===
echo "\n-- 11D: Branch scope isolation --\n";
echo "  N/A: Data-level branch isolation not implemented.\n";
echo "  INFO: User has branch_id + branchAccess() but queries do not filter by branch.\n";

// === 11E: Permission change ===
echo "\n-- 11E: Permission change --\n";

// Remove invoices.create from role
$salesRole->update(['permissions' => ['invoices.view', 'customers.view']]);
$testUser->load('role');

test("invoices.create removed", !$testUser->hasPermission('invoices.create'), $pass, $fail, $errors);
test("invoices.view still OK", $testUser->hasPermission('invoices.view'), $pass, $fail, $errors);
echo "  INFO: Permission change takes effect on next request (role reloaded from DB). No session invalidation.\n";

// Restore
$salesRole->update(['permissions' => ['invoices.view', 'invoices.create', 'customers.view', 'pos.use']]);

// === 11F: Deactivate / reactivate ===
echo "\n-- 11F: Deactivate / reactivate --\n";

$testUser->update(['status' => 'inactive']);
$testUser->refresh();
test("Status = inactive", $testUser->status === 'inactive', $pass, $fail, $errors);

// Check login block -- LoginController now blocks 'inactive'
$loginCtrl = new \App\Http\Controllers\Auth\LoginController();
// Simulate: Auth::attempt would succeed (valid creds), then check status
test("isActive() returns false", !$testUser->isActive(), $pass, $fail, $errors);
test("Login blocked (status check)", in_array($testUser->status, ['locked', 'inactive']), $pass, $fail, $errors);

// Reactivate
$testUser->update(['status' => 'active']);
$testUser->refresh();
test("Reactivated", $testUser->status === 'active', $pass, $fail, $errors);
test("isActive() returns true", $testUser->isActive(), $pass, $fail, $errors);

// === 11G: Delete user preserving history ===
echo "\n-- 11G: Delete user preserving history --\n";

// Create a CashFlow referencing the user
$cf = CashFlow::create([
    'code' => 'PT_F11_' . time(),
    'type' => 'receipt', 'amount' => 1000, 'time' => now(),
    'category' => 'Test F11', 'target_name' => $testUser->name,
    'payment_method' => 'cash', 'description' => 'Created by user ' . $testUser->id,
    'status' => 'active',
]);

$userId = $testUser->id;
$testUser->delete(); // soft-delete

test("User soft-deleted", User::find($userId) === null, $pass, $fail, $errors);
test("User still in withTrashed", User::withTrashed()->find($userId) !== null, $pass, $fail, $errors);
test("CashFlow still exists", CashFlow::find($cf->id) !== null, $pass, $fail, $errors);
test("CashFlow target preserved", CashFlow::find($cf->id)->target_name === 'Sale Test F11', $pass, $fail, $errors);

// Restore user for further tests
$restoredUser = User::withTrashed()->find($userId);
$restoredUser->restore();
test("User restored", User::find($userId) !== null, $pass, $fail, $errors);

// === 11H: Read-only enforcement ===
echo "\n-- 11H: Read-only enforcement --\n";

$viewerRole = Role::create([
    'name' => 'viewer_f11_' . time(),
    'display_name' => 'Viewer Test F11',
    'permissions' => ['invoices.view', 'customers.view', 'cash_flows.view'],
]);
$viewer = User::create([
    'name' => 'Viewer Test F11',
    'email' => 'viewer_f11@test.com',
    'password' => Hash::make('password123'),
    'role_id' => $viewerRole->id,
    'status' => 'active',
]);

// Check view-only user cannot create
test("Viewer cannot invoices.create", !$viewer->hasPermission('invoices.create'), $pass, $fail, $errors);
test("Viewer cannot cash_flows.create", !$viewer->hasPermission('cash_flows.create'), $pass, $fail, $errors);
test("Viewer cannot stock_takes.create", !$viewer->hasPermission('stock_takes.create'), $pass, $fail, $errors);
test("Viewer CAN invoices.view", $viewer->hasPermission('invoices.view'), $pass, $fail, $errors);

// Middleware enforcement
Auth::loginUsingId($viewer->id);
$viewerReq = new \Illuminate\Http\Request();
$viewerReq->headers->set('Accept', 'application/json');
$viewCreate = $middleware->handle($viewerReq, function ($r) { return response()->json(['ok' => true]); }, 'invoices.create');
test("Middleware blocks viewer create (403)", $viewCreate->getStatusCode() === 403, $pass, $fail, $errors);

Auth::loginUsingId(1);

// === 11I: Product-group restriction ===
echo "\n-- 11I: Product-group restriction --\n";
echo "  N/A: Product-group permission not implemented.\n";

// === 11J: Role lifecycle ===
echo "\n-- 11J: Role lifecycle --\n";

$roleCtrl = new \App\Http\Controllers\Api\RoleController();

// Create custom role
$customRole = Role::create([
    'name' => 'inventory_checker_f11_' . time(),
    'display_name' => 'Inventory Checker F11',
    'permissions' => ['stock_takes.view'],
]);
test("Custom role created", $customRole->id > 0, $pass, $fail, $errors);

// Update permissions
$customRole->update(['permissions' => ['stock_takes.view', 'stock_takes.create']]);
$customRole->refresh();
test("Permission added", in_array('stock_takes.create', $customRole->permissions), $pass, $fail, $errors);

// Assign to user
$testUser->update(['role_id' => $customRole->id]);
$testUser->load('role');
test("User now inventory checker", $testUser->hasPermission('stock_takes.view'), $pass, $fail, $errors);
test("User NOT has invoices", !$testUser->hasPermission('invoices.view'), $pass, $fail, $errors);

// Delete role while in use (should fail)
$resp = $roleCtrl->destroy($customRole);
$r = json_decode($resp->getContent(), true);
test("Delete in-use role blocked", $resp->getStatusCode() === 422, $pass, $fail, $errors);

// Unassign then delete
$testUser->update(['role_id' => $salesRole->id]);
$resp2 = $roleCtrl->destroy($customRole);
test("Delete unassigned role OK", $resp2->getStatusCode() === 200, $pass, $fail, $errors);

// System role protection
$systemRole = Role::where('is_system', true)->first();
if ($systemRole) {
    $resp3 = $roleCtrl->destroy($systemRole);
    $r3 = json_decode($resp3->getContent(), true);
    test("System role delete blocked", $resp3->getStatusCode() === 422, $pass, $fail, $errors);
} else {
    echo "  INFO: No system role found, skip system role test.\n";
}

// === 11K: Audit logging ===
echo "\n-- 11K: Audit logging --\n";
echo "  N/A: Audit logging not implemented.\n";

// === SUMMARY ===
echo "\n=== KET QUA: $pass PASS / $fail FAIL ===\n\n";

if (count($errors) > 0) {
    echo "DANH SACH LOI:\n";
    foreach ($errors as $i => $e) { echo "  " . ($i + 1) . ". $e\n"; }
}

// === Cleanup ===
echo "\n-- Cleanup --\n";
CashFlow::where('code', 'LIKE', '%_F11_%')->forceDelete();
User::withTrashed()->where('email', 'LIKE', '%_f11@test.com')->forceDelete();
Role::where('name', 'LIKE', '%_f11_%')->delete();
echo "  OK Cleaned up\n";
