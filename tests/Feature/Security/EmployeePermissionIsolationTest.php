<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
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

    public function test_self_service_create_requires_active_linked_employee(): void
    {
        [$user, $employee] = $this->userWithEmployee([
            'tasks.self.view',
            'tasks.self.create',
            'tasks.self.progress',
        ]);

        $response = $this->actingAs($user)->postJson('/api/tasks', [
            'type' => Task::TYPE_GENERAL,
            'title' => 'Self created task',
        ]);

        $response->assertCreated();
        $task = Task::findOrFail($response->json('id'));
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);

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
}
