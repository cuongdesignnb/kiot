<?php

namespace Tests\Feature\Tasks;

use App\Models\Employee;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTasksPermissionParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_manager_with_global_permissions_can_use_only_own_my_tasks_portal(): void
    {
        [$manager, $managerEmployee] = $this->userWithEmployee([
            'tasks.view',
            'tasks.create',
            'tasks.complete',
        ]);
        [$otherUser, $otherEmployee] = $this->userWithEmployee([
            'tasks.view',
            'tasks.create',
            'tasks.complete',
        ]);

        [$ownTask, $ownAssignment] = $this->assignedTask($managerEmployee, $manager);
        [$otherTask, $otherAssignment] = $this->assignedTask($otherEmployee, $otherUser);

        $this->actingAs($manager)->get('/my-tasks')->assertOk();

        $list = $this->actingAs($manager)->getJson('/api/my-tasks');
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownTask->id));
        $this->assertFalse($ids->contains($otherTask->id));

        $this->actingAs($manager)
            ->postJson("/api/my-tasks/{$ownAssignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertOk();
        $this->assertSame(TaskAssignment::STATUS_ACCEPTED, $ownAssignment->fresh()->status);

        $this->actingAs($manager)
            ->postJson("/api/my-tasks/{$otherAssignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertForbidden();
        $this->assertSame(TaskAssignment::STATUS_PENDING, $otherAssignment->fresh()->status);

        $this->actingAs($manager)
            ->postJson("/api/my-tasks/{$ownTask->id}/progress", ['progress' => 45])
            ->assertOk();
        $this->assertSame(45, $ownTask->fresh()->progress);

        $this->actingAs($manager)
            ->postJson("/api/my-tasks/{$otherTask->id}/progress", ['progress' => 70])
            ->assertForbidden();
        $this->assertNotSame(70, $otherTask->fresh()->progress);

        $created = $this->actingAs($manager)->postJson('/api/my-tasks', [
            'title' => 'Manager own task',
            'priority' => Task::PRIORITY_NORMAL,
        ]);
        $created->assertCreated();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $created->json('id'),
            'employee_id' => $managerEmployee->id,
            'status' => TaskAssignment::STATUS_PENDING,
        ]);

        $this->actingAs($manager)->getJson('/api/my-tasks/categories')->assertOk();
    }

    public function test_self_service_employee_can_use_only_self_permissions(): void
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

        [$ownTask, $ownAssignment] = $this->assignedTask($employee, $user);
        [$otherTask, $otherAssignment] = $this->assignedTask($otherEmployee, $otherUser);

        $this->actingAs($user)->get('/my-tasks')->assertOk();
        $this->actingAs($user)->getJson('/api/my-tasks')->assertOk();
        $this->actingAs($user)->getJson('/api/my-tasks/categories')->assertOk();

        $created = $this->actingAs($user)->postJson('/api/my-tasks', [
            'title' => 'Self-service own task',
            'priority' => Task::PRIORITY_HIGH,
        ]);
        $created->assertCreated();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $created->json('id'),
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$ownAssignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$ownTask->id}/progress", ['progress' => 55])
            ->assertOk();
        $this->assertSame(55, $ownTask->fresh()->progress);

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$otherAssignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$otherTask->id}/progress", ['progress' => 80])
            ->assertForbidden();
    }

    public function test_user_without_global_or_self_permissions_is_blocked(): void
    {
        [$user, $employee] = $this->userWithEmployee([]);
        [$task, $assignment] = $this->assignedTask($employee, $user);

        $this->actingAs($user)->get('/my-tasks')->assertForbidden();
        $this->actingAs($user)->getJson('/api/my-tasks')->assertForbidden();
        $this->actingAs($user)->postJson('/api/my-tasks/accept-all')->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$assignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertForbidden();
        $this->actingAs($user)->postJson('/api/my-tasks', ['title' => 'Blocked task'])->assertForbidden();
        $this->actingAs($user)->getJson('/api/my-tasks/categories')->assertForbidden();
        $this->actingAs($user)->getJson('/api/my-tasks/products/search?q=ABC')->assertForbidden();
        $this->actingAs($user)->getJson('/api/my-tasks/serials/search?q=ABC')->assertForbidden();
        $this->actingAs($user)->postJson("/api/my-tasks/{$task->id}/progress", ['progress' => 30])->assertForbidden();
    }

    public function test_user_with_permission_but_without_active_employee_link_gets_api_forbidden_without_mutation(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->roleWith([
                'tasks.view',
                'tasks.create',
                'tasks.complete',
            ])->id,
        ]);
        [$owner, $employee] = $this->userWithEmployee([
            'tasks.view',
            'tasks.create',
            'tasks.complete',
        ]);
        [$task, $assignment] = $this->assignedTask($employee, $owner);

        $beforeAssignmentStatus = $assignment->fresh()->status;
        $beforeProgress = $task->fresh()->progress;
        $beforeTaskCount = Task::count();

        $this->actingAs($user)->getJson('/api/my-tasks')->assertForbidden();
        $this->actingAs($user)->postJson('/api/my-tasks/accept-all')->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/my-tasks/{$assignment->id}/respond", ['status' => TaskAssignment::STATUS_ACCEPTED])
            ->assertForbidden();
        $this->actingAs($user)->postJson('/api/my-tasks', ['title' => 'No employee task'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/my-tasks/{$task->id}/progress", ['progress' => 90])->assertForbidden();

        $this->assertSame($beforeAssignmentStatus, $assignment->fresh()->status);
        $this->assertSame($beforeProgress, $task->fresh()->progress);
        $this->assertSame($beforeTaskCount, Task::count());
    }

    public function test_global_create_permission_allows_my_tasks_form_helpers(): void
    {
        [$user] = $this->userWithEmployee(['tasks.create']);
        TaskCategory::create([
            'name' => 'General',
            'type' => Task::TYPE_GENERAL,
            'color' => '#0ea5e9',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Searchable task product',
            'sku' => 'TASK-PROD-' . uniqid(),
            'cost_price' => 100_000,
            'retail_price' => 120_000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 100_000,
            'has_serial' => true,
            'is_active' => true,
        ]);
        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'TASK-SERIAL-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 100_000,
        ]);

        $this->actingAs($user)->getJson('/api/my-tasks/categories')->assertOk();
        $this->actingAs($user)->getJson('/api/my-tasks/products/search?q=TASK')->assertOk();
        $this->actingAs($user)->getJson('/api/my-tasks/serials/search?q=TASK')->assertOk();
    }

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

    private function assignedTask(Employee $employee, ?User $creator = null): array
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

        $assignment = TaskAssignment::create([
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'assigned_by' => $creator?->id,
            'status' => TaskAssignment::STATUS_PENDING,
            'assigned_at' => now(),
        ]);

        return [$task, $assignment];
    }
}
