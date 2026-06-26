<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePermissionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function roleWith(array $permissions): Role
    {
        return Role::create([
            'name' => 'role-' . uniqid(),
            'display_name' => 'Role ' . uniqid(),
            'permissions' => $permissions,
            'is_system' => false,
        ]);
    }

    private function userWithEmployee(array $permissions, bool $active = true): array
    {
        $user = User::factory()->create([
            'role_id' => $this->roleWith($permissions)->id,
        ]);

        $employee = Employee::create([
            'name' => 'Employee ' . uniqid(),
            'phone' => '09' . random_int(10000000, 99999999),
            'user_id' => $user->id,
            'is_active' => $active,
        ]);

        return [$user, $employee];
    }

    private function assignedTask(Employee $employee, ?User $creator = null): Task
    {
        $task = Task::create([
            'code' => 'TASK-' . uniqid(),
            'type' => Task::TYPE_GENERAL,
            'title' => 'Assigned task ' . uniqid(),
            'status' => Task::STATUS_PENDING,
            'priority' => Task::PRIORITY_NORMAL,
            'assigned_employee_id' => $employee->id,
            'assigned_at' => now(),
            'created_by' => $creator?->id,
        ]);

        TaskAssignment::create([
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'assigned_by' => $creator?->id,
            'status' => TaskAssignment::STATUS_PENDING,
            'assigned_at' => now(),
        ]);

        return $task;
    }

    public function test_self_service_employee_only_sees_assigned_tasks_and_not_global_task_modules(): void
    {
        [$user, $employee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.create',
            'tasks.self.progress',
        ]);
        [$otherUser, $otherEmployee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.progress',
        ]);

        $ownTask = $this->assignedTask($employee, $user);
        $otherTask = $this->assignedTask($otherEmployee, $otherUser);

        $this->actingAs($user)->getJson('/api/tasks')->assertForbidden();
        $this->actingAs($user)->getJson('/api/tasks/performance?month=6&year=2026')->assertForbidden();
        $this->actingAs($user)->getJson('/api/tasks/search-products?q=test')->assertForbidden();
        $this->actingAs($user)->getJson('/api/tasks/search-serials?q=test')->assertForbidden();
        $this->actingAs($user)->postJson("/api/tasks/{$ownTask->id}/assign", [
            'employee_ids' => [$otherEmployee->id],
        ])->assertForbidden();
        $this->actingAs($user)->postJson("/api/tasks/{$ownTask->id}/complete")->assertForbidden();

        $this->actingAs($user)->getJson("/api/tasks/{$ownTask->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/tasks/{$otherTask->id}")->assertForbidden();

        $myTasks = $this->actingAs($user)->getJson('/api/my-tasks');
        $myTasks->assertOk();
        $this->assertTrue(collect($myTasks->json('data'))->contains('id', $ownTask->id));
        $this->assertFalse(collect($myTasks->json('data'))->contains('id', $otherTask->id));

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$ownTask->id}/progress", ['progress' => 35])
            ->assertOk();
        $this->assertSame(35, $ownTask->fresh()->progress);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$otherTask->id}/progress", ['progress' => 35])
            ->assertForbidden();

        $this->actingAs($user)->get('/tasks')->assertForbidden();
        $this->actingAs($user)->get('/tasks/performance')->assertForbidden();
        $this->actingAs($user)->get('/my-tasks')->assertOk();
    }

    public function test_self_service_create_endpoint_creates_only_own_pending_assignment(): void
    {
        [$user, $employee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.create',
            'tasks.self.progress',
        ]);
        $otherEmployee = Employee::create([
            'name' => 'Other Employee ' . uniqid(),
            'phone' => '09' . random_int(10000000, 99999999),
            'is_active' => true,
        ]);
        $category = TaskCategory::create([
            'name' => 'Self category',
            'type' => Task::TYPE_GENERAL,
            'color' => '#0ea5e9',
            'is_active' => true,
        ]);

        $this->actingAs($user)->getJson('/api/my-tasks/categories')->assertOk();

        $response = $this->actingAs($user)->postJson('/api/my-tasks', [
            'title' => 'Self created task',
            'description' => 'Created from my tasks',
            'category_id' => $category->id,
            'priority' => Task::PRIORITY_HIGH,
            'created_by' => 999,
            'creator_employee_id' => $otherEmployee->id,
            'assigned_employee_id' => $otherEmployee->id,
            'employee_ids' => [$otherEmployee->id],
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));
        $this->assertSame($user->id, $task->created_by);
        $this->assertSame($employee->id, $task->assigned_employee_id);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);
        $this->assertDatabaseMissing('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $otherEmployee->id,
        ]);

        $myTasks = $this->actingAs($user)->getJson('/api/my-tasks');
        $myTasks->assertOk();
        $this->assertTrue(collect($myTasks->json('data'))->contains('id', $task->id));
    }

    public function test_legacy_api_self_create_requires_active_linked_employee_and_general_task_only(): void
    {
        [$user, $employee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.create',
            'tasks.self.progress',
        ]);

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_GENERAL,
            'title' => 'Self created task through legacy endpoint',
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);

        $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_name' => 'Walk-in',
            'issue_description' => 'Repair should be blocked',
        ])->assertForbidden();

        $unlinkedUser = User::factory()->create([
            'role_id' => $this->roleWith(['tasks.self.create'])->id,
        ]);
        $this->actingAs($unlinkedUser)->postJson('/api/tasks', [
            'type' => Task::TYPE_GENERAL,
            'title' => 'Unlinked self task',
        ])->assertForbidden();

        [$inactiveUser] = $this->userWithEmployee(['tasks.self.create'], false);
        $this->actingAs($inactiveUser)->postJson('/api/tasks', [
            'type' => Task::TYPE_GENERAL,
            'title' => 'Inactive self task',
        ])->assertForbidden();
    }

    public function test_self_create_read_only_helpers_are_scoped_to_self_create_permission(): void
    {
        [$user] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.create',
            'tasks.self.progress',
        ]);
        $product = Product::create([
            'name' => 'Searchable device',
            'sku' => 'SELF-' . uniqid(),
            'cost_price' => 100000,
            'retail_price' => 120000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 100000,
            'has_serial' => true,
        ]);
        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SELF-SN-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 100000,
        ]);

        $this->actingAs($user)->getJson('/api/my-tasks/products/search?q=SELF')->assertOk();
        $this->actingAs($user)->getJson('/api/my-tasks/serials/search?q=SELF')->assertOk();

        [$viewOnlyUser] = $this->userWithEmployee(['tasks.self.view']);
        $this->actingAs($viewOnlyUser)->getJson('/api/my-tasks/products/search?q=SELF')->assertForbidden();
        $this->actingAs($viewOnlyUser)->getJson('/api/my-tasks/serials/search?q=SELF')->assertForbidden();
    }

    public function test_employee_without_global_permissions_cannot_access_reports_revenue_or_back_office_modules(): void
    {
        [$user, $employee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.progress',
        ]);

        $this->actingAs($user)->get('/')->assertForbidden();
        $this->actingAs($user)->get('/reports/business')->assertForbidden();
        $this->actingAs($user)->get("/reports/employees?employee_id={$employee->id}&concern=profit")->assertForbidden();
        $this->actingAs($user)->get('/invoices')->assertForbidden();
        $this->actingAs($user)->get('/returns')->assertForbidden();
        $this->actingAs($user)->get('/cash-flows')->assertForbidden();
        $this->actingAs($user)->get('/employees')->assertForbidden();
    }

    public function test_manager_global_permissions_still_access_global_task_routes(): void
    {
        $manager = User::factory()->create([
            'role_id' => $this->roleWith([
                'tasks.view',
                'tasks.create',
                'tasks.performance',
            ])->id,
        ]);
        [, $employee] = $this->userWithEmployee(['tasks.self.view']);
        $task = $this->assignedTask($employee, $manager);

        $this->actingAs($manager)->getJson('/api/tasks')->assertOk();
        $this->actingAs($manager)->getJson('/api/tasks/performance?month=6&year=2026')->assertOk();
        $this->actingAs($manager)->getJson("/api/tasks/{$task->id}")->assertOk();
    }

    public function test_employee_scope_audit_command_is_read_only(): void
    {
        $role = $this->roleWith([
            'tasks.self.view',
            'tasks.view',
            'reports.view',
            'dashboard.view',
        ]);
        $before = $role->fresh()->permissions;

        $this->artisan('permissions:audit-employee-scope', ['--role' => $role->name])
            ->expectsOutputToContain('Read-only audit. Database was not changed.')
            ->assertExitCode(0);

        $this->assertSame($before, $role->fresh()->permissions);
    }

    public function test_employee_self_service_system_role_exists_without_global_permissions(): void
    {
        $role = Role::where('name', 'employee_self_service')->first();

        $this->assertNotNull($role);
        $this->assertSame('Nhân viên tự phục vụ', $role->display_name);
        $this->assertContains('tasks.self.view', $role->permissions);
        $this->assertContains('tasks.self.create', $role->permissions);
        $this->assertContains('tasks.self.progress', $role->permissions);
        $this->assertNotContains('tasks.view', $role->permissions);
        $this->assertNotContains('tasks.assign', $role->permissions);
        $this->assertNotContains('tasks.complete', $role->permissions);
        $this->assertNotContains('tasks.performance', $role->permissions);
        $this->assertNotContains('reports.view', $role->permissions);
        $this->assertNotContains('dashboard.view', $role->permissions);
    }
}
